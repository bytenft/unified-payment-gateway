<?php
if (!defined('ABSPATH')) exit;

class Unified_Payment_State_Engine
{
    const LOCK_TTL  = 12;
    const EVENT_TTL = 86400;

    /**
     * Which payment attempt the order is on, and which attempt each payment
     * token was first reported under.
     */
    const ATTEMPT_META       = '_unified_attempt';
    const TOKEN_ATTEMPT_META = '_unified_token_attempt';

    /**
     * States that a superseded attempt is not allowed to apply.
     *
     * 'success' is deliberately absent: a payment that actually happened is
     * applied whichever attempt reported it.
     */
    const SUPERSEDABLE_STATES = ['failed', 'cancelled', 'expired'];

    /* =========================================================
     * ENTRY POINT
     * ========================================================= */
    public static function handle_event($order_id, $event_type, $payload = [])
    {
        $order = wc_get_order($order_id);
        if (!$order) return false;

        $event_id = self::generate_event_id($event_type, $payload);

        if ($event_type === 'api_update' && self::is_duplicate_event($order_id, $event_id)) {
            return self::safe_response($order, 'duplicate_event_ignored', self::get_state($order));
        }

        self::mark_event($order_id, $event_id);

        $lock_key = "unified_lock_{$order_id}";
        if (get_transient($lock_key)) {
            return self::safe_response($order, 'locked_skip');
        }

        set_transient($lock_key, 1, self::LOCK_TTL);

        try {

            $current_state = self::normalize_state(self::get_state($order));
            $new_state     = self::normalize_state(self::resolve_state($payload));

            if (!$new_state) {
                return self::safe_response($order, 'no_state', $current_state);
            }

            /**
             * The customer has started a newer attempt on this order, and this
             * report is about the payment link of an older one - typically that
             * link reaching its own unpaid timeout. It describes a payment the
             * order has already moved on from, so it must not cancel or fail
             * the attempt now in progress.
             */
            if (self::is_superseded_event($order, $new_state, $payload['payment_token'] ?? '')) {

                Unified_Payment_Gateway_Logger::info(
                    sprintf(
                        '[Order #%d] Ignored %s from a superseded payment attempt.',
                        $order->get_id(),
                        $new_state
                    ),
                    [
                        'event_type'      => $event_type,
                        'payment_token'   => $payload['payment_token'] ?? '',
                        'current_attempt' => self::current_attempt($order),
                    ]
                );

                return self::safe_response($order, 'superseded_attempt_ignored', $current_state);
            }

            if ($current_state === 'success') {
                return self::safe_response($order, 'final_success_locked', 'success');
            }

            // ONLY apply state change (NO NOTES HERE)
            $can_transition = self::can_transition($current_state, $new_state);

            /**
             * ALWAYS allow success recovery
             */
            if ($new_state === 'success') {
                $can_transition = true;
            }

            /**
             * BLOCK invalid transitions
             */
            if (!$can_transition) {

                return self::safe_response(
                    $order,
                    'invalid_transition_blocked',
                    $current_state
                );
            }

            /**
             * APPLY STATE
             */
            self::apply($order, $new_state, $event_type, $payload);

            /**
             * PUSH TIMELINE
             */
            if ($new_state === 'failed') {

                $existing = self::timeline_has_state_for_token(
                    $order,
                    'failed',
                    $payload['payment_token'] ?? ''
                );

                if (!$existing) {
                    self::push_timeline_event(
                        $order,
                        $new_state,
                        $event_type,
                        $payload
                    );
                }

            } else {

                self::push_timeline_event(
                    $order,
                    $new_state,
                    $event_type,
                    $payload
                );
            }

            // SINGLE SOURCE OF TRUTH FOR NOTES
            self::sync_order_notes($order);

            return self::safe_response($order, 'updated', $new_state);

        } finally {
            delete_transient($lock_key);
        }
    }

    /* =========================================================
     * PAYMENT ATTEMPT SCOPING
     * ========================================================= */

    /**
     * Record that a new payment attempt has started on this order.
     *
     * Called when a fresh voucher is emailed, which is the only thing that
     * starts a new payment lifecycle for an order. From here on, whatever the
     * previous attempt still reports - including its own expiry - is scoped to
     * the attempt it belongs to and can no longer land on this one.
     *
     * @param WC_Order $order Order being retried.
     * @return int The attempt number now in progress.
     */
    public static function begin_attempt($order)
    {
        if (!$order instanceof WC_Order) {
            return 0;
        }

        $recorded = (int) $order->get_meta(self::ATTEMPT_META);

        if ($recorded > 0) {
            $attempt = $recorded + 1;
        } else {
            /*
             * No attempt recorded yet. An order that already carries a payment
             * token was paid through once before this tracking existed, so that
             * token belongs to the attempt being replaced right now - bind it,
             * and this voucher becomes the second attempt. Otherwise this is
             * simply the order's first.
             */
            $existing_token = trim((string) $order->get_meta('_unified_pay_id'));

            if ($existing_token !== '') {
                self::bind_token($order, $existing_token, 1);
                $attempt = 2;
            } else {
                $attempt = 1;
            }
        }

        $order->update_meta_data(self::ATTEMPT_META, $attempt);
        $order->save();

        return $attempt;
    }

    /**
     * The attempt this order is on. Orders placed before attempts were tracked
     * are on their first, so their existing payment links stay current.
     */
    private static function current_attempt($order)
    {
        $attempt = (int) $order->get_meta(self::ATTEMPT_META);

        return $attempt > 0 ? $attempt : 1;
    }

    /**
     * The attempt a payment token belongs to, bound the first time this store
     * sees that token.
     *
     * A token first seen now belongs to the attempt running now, so an order
     * already in flight when this shipped keeps its current behaviour.
     */
    private static function attempt_for_token($order, $payment_token)
    {
        $payment_token = trim((string) $payment_token);
        $current       = self::current_attempt($order);

        if ($payment_token === '') {
            return $current;
        }

        $map = $order->get_meta(self::TOKEN_ATTEMPT_META);
        $map = is_array($map) ? $map : [];

        if (isset($map[$payment_token])) {
            return (int) $map[$payment_token];
        }

        self::bind_token($order, $payment_token, $current);

        return $current;
    }

    /**
     * Record which attempt a payment token belongs to.
     */
    private static function bind_token($order, $payment_token, $attempt)
    {
        $map = $order->get_meta(self::TOKEN_ATTEMPT_META);
        $map = is_array($map) ? $map : [];

        if (isset($map[$payment_token])) {
            return;
        }

        $map[$payment_token] = (int) $attempt;

        // Bounded by attempts per order; trimmed so a noisy reporter cannot grow it.
        if (count($map) > 50) {
            $map = array_slice($map, -50, null, true);
        }

        $order->update_meta_data(self::TOKEN_ATTEMPT_META, $map);
        $order->save();
    }

    /**
     * Whether a payment token belongs to an attempt the customer has replaced.
     *
     * @param WC_Order $order         Order the report is about.
     * @param string   $payment_token Token the report carries.
     * @return bool
     */
    public static function is_superseded_token($order, $payment_token)
    {
        if (!$order instanceof WC_Order) {
            return false;
        }

        return self::attempt_for_token($order, $payment_token) < self::current_attempt($order);
    }

    /**
     * Whether this report both comes from a superseded attempt and carries a
     * state that attempt is no longer allowed to apply.
     *
     * Every token is bound before the state is weighed, so a token first seen
     * on a success is still recorded against the attempt that produced it.
     *
     * @param WC_Order $order         Order the report is about.
     * @param string   $state         Normalized state the report resolves to.
     * @param string   $payment_token Token the report carries.
     * @return bool
     */
    public static function is_superseded_event($order, $state, $payment_token)
    {
        if (!$order instanceof WC_Order) {
            return false;
        }

        $superseded = self::is_superseded_token($order, $payment_token);

        return $superseded && in_array($state, self::SUPERSEDABLE_STATES, true);
    }

    private static function timeline_has_state_for_token($order, $state, $payment_token)
    {
        if (empty($payment_token)) {
            return false;
        }

        $timeline = self::get_timeline($order);

        foreach ($timeline as $event) {

            if (
                ($event['type'] ?? '') === $state &&
                ($event['payment_token'] ?? '') === $payment_token
            ) {
                return true;
            }
        }

        return false;
    }

    /* =========================================================
     * TIMELINE ENGINE (SOURCE OF TRUTH)
     * ========================================================= */
    private static function push_timeline_event($order, $state, $event_type, $payload)
    {
        $timeline = self::get_timeline($order);

        $timeline[] = [
            'type'          => $state,
            'event_type'    => $event_type,
            'time'          => current_time('mysql'),
            'payment_token' => $payload['payment_token'] ?? null,
        ];

        $order->update_meta_data('_unified_timeline', $timeline);
        $order->save();
    }

    /* =========================================================
     * APPLY STATE (WooCommerce sync only)
     * ========================================================= */
    private static function apply($order, $state, $event_type, $payload)
    {
        if (self::get_state($order) === 'success') {
            return;
        }

        $order_id = $order->get_id();
        $payment_token = $payload['payment_token'] ?? '';

        // stable idempotency key (VERY IMPORTANT)
        $state_lock_key = md5($order_id . '|' . $state . '|' . $payment_token);

        $last_lock = $order->get_meta('_unified_state_lock');

        if ($last_lock === $state_lock_key) {
            return;
        }

        $order->update_meta_data('_unified_state_lock', $state_lock_key);

        $wc_status = match ($state) {
            'success'    => self::get_success_wc_status(),
            'failed'     => 'failed',
            'cancelled'  => 'cancelled',
            'expired'    => 'failed',
            default      => null
        };

        if (!$wc_status) return;

        $order->update_status($wc_status, '');

        $order->update_meta_data('_unified_state', $state);
        $order->update_meta_data('_unified_last_event', $event_type);
        $order->update_meta_data('_unified_last_event_time', current_time('mysql'));

        if (!empty($payment_token)) {
            $order->update_meta_data('_unified_pay_id', $payment_token);
        }

        if ($state === 'success') {
            $order->update_meta_data('_unified_payment_success', 'yes');
        }

        $order->save();
    }

    private static function get_timeline($order)
    {
        $data = $order->get_meta('_unified_timeline', true);
        if (empty($data)) {
            $data = $order->get_meta('_unified_timeline', true);
        }

        if (empty($data)) {
            return [];
        }

        // If corrupted string
        if (!is_array($data)) {
            return [];
        }

        // sanitize each entry
        return array_values(array_filter($data, function ($item) {
            return is_array($item) && isset($item['type']);
        }));
    }

    /* =========================================================
     * TIMELINE → ORDER NOTES SYNC
     * ========================================================= */
    private static function sync_order_notes($order)
    {
        $timeline = self::get_timeline($order);

        if (empty($timeline)) {
            return;
        }

        $failed_events = [];
        $success_event = null;
        $cancel_event  = null;
        $seen_failed   = [];

        /**
         * SINGLE PASS TIMELINE PARSE
         */
        foreach ($timeline as $event) {

            $type  = $event['type'] ?? '';
            $token = trim((string) ($event['payment_token'] ?? ''));

            /**
             * FAILED EVENTS
             */
            if ($type === 'failed') {

                if (
                    !empty($token) &&
                    !isset($seen_failed[$token])
                ) {
                    $seen_failed[$token] = true;

                    $failed_events[] = $event;
                }
            }

            /**
             * SUCCESS EVENT
             */
            if (
                $type === 'success' &&
                !$success_event
            ) {
                $success_event = $event;
            }

            /**
             * CANCEL EVENT
             */
            if (
                $type === 'cancelled' &&
                !$cancel_event
            ) {
                $cancel_event = $event;
            }
        }

        /**
         * FAILED NOTES
         */
        $already_synced = (int) ($order->get_meta('_unified_failed_note_count') ?: $order->get_meta('_unified_failed_note_count'));

        $actual_failed_count = count($failed_events);

        if ($actual_failed_count > $already_synced) {

            for ($i = $already_synced; $i < $actual_failed_count; $i++) {

                $event = $failed_events[$i];

                $attempt_number = $i + 1;

                $order->add_order_note(
                    self::build_order_note(
                        "Payment Failed (Attempt {$attempt_number})",
                        $event['payment_token'] ?? '',
                        $event['event_type'] ?? ''
                    )
                );
            }

            $order->update_meta_data(
                '_unified_failed_note_count',
                $actual_failed_count
            );
        }

        /**
         * SUCCESS NOTE
         */
        if (
            $success_event &&
            !$order->get_meta('_unified_success_note_added') &&
            !$order->get_meta('_unified_success_note_added')
        ) {

            $order->add_order_note(
                self::build_order_note(
                    'Payment completed successfully.',
                    $success_event['payment_token'] ?? '',
                    $success_event['event_type'] ?? ''
                )
            );

            $order->update_meta_data(
                '_unified_success_note_added',
                'yes'
            );
        }

        /**
         * CANCEL NOTE
         */
        if (
            $cancel_event &&
            !$order->get_meta('_unified_cancel_note_added') &&
            !$order->get_meta('_unified_cancel_note_added')
        ) {

            $order->add_order_note(
                self::build_order_note(
                    'Payment was cancelled.',
                    $cancel_event['payment_token'] ?? '',
                    $cancel_event['event_type'] ?? ''
                )
            );

            $order->update_meta_data(
                '_unified_cancel_note_added',
                'yes'
            );
        }

        $order->save();
    }

    private static function build_order_note(
        $title,
        $payment_token,
        $event_type
    ) {

        $source = match ($event_type) {
            'popup_close'    => 'Customer Return from Payment Page',
            'redirect'       => 'Customer Redirect',
            'webhook_update' => 'Webhook',
            default          => 'System'
        };

        // Decode Base64 payment token if possible
        $decoded_payment_id = base64_decode($payment_token, true);

        if (empty($decoded_payment_id)) {
            $decoded_payment_id = $payment_token;
        }

        return sprintf(
            '<strong>Voucher Pay</strong><br><br>
            <strong>%s</strong><br><br>
            <strong>Payment ID:</strong> %s<br>
            <strong>Updated Via:</strong> %s<br>
            <strong>Recorded At:</strong> %s',
            esc_html($title),
            esc_html($decoded_payment_id),
            esc_html($source),
            esc_html(current_time('F j, Y \a\t g:i A'))
        );
    }

    /* =========================================================
     * STATE RESOLUTION
     * ========================================================= */
    private static function resolve_state($payload)
    {
        $status = $payload['status']
            ?? $payload['payment_status']
            ?? $payload['transaction_status']
            ?? $payload['order_status']
            ?? null;

        $status = $status ? strtolower((string)$status) : null;

        return match ($status) {
            'success', 'paid', 'completed'  => 'success',
            'failed'                        => 'failed',
            'cancelled', 'canceled'        => 'cancelled',
            'expired'                      => 'expired',
            'pending', 'processing'        => 'processing',
            default                        => null
        };
    }

    /* =========================================================
     * NORMALIZATION
     * ========================================================= */
    private static function normalize_state($state)
    {
        return match ($state) {
            'completed' => 'success',
            'canceled'  => 'cancelled',
            default     => $state
        };
    }

    /* =========================================================
     * TRANSITIONS
     * ========================================================= */
    private static function can_transition($from, $to)
    {
        if ($from === 'success') return false;
        if ($from === 'processing') return false;

        $map = [
            'pending' => ['cancelled','processing','success','failed'],
            'failed' => ['failed','success','processing','cancelled'],
            'cancelled' => ['success','failed'],
            'expired' => ['failed','cancelled','success'],
        ];

        return in_array($to, $map[$from] ?? [], true);
    }

    /* =========================================================
     * CURRENT STATE
     * ========================================================= */
    private static function get_state($order)
    {
        return $order->get_meta('_unified_state') ?: $order->get_meta('_unified_state') ?: 'pending';
    }

    /* =========================================================
     * SUCCESS STATUS
     * ========================================================= */
    private static function get_success_wc_status()
    {
        $settings = get_option('woocommerce_unified_settings', []);
        if (empty($settings)) {
            $settings = get_option('woocommerce_unified_settings', []);
        }
        $status = $settings['order_status'] ?? 'processing';

        return in_array($status, ['processing','completed'], true)
            ? $status
            : 'processing';
    }

    /* =========================================================
     * EVENT ID (webhook dedupe only)
     * ========================================================= */
    private static function generate_event_id($type, $payload)
    {
        return hash('sha256', implode('|', [
            $type,
            $payload['status'] ?? '',
            $payload['payment_token'] ?? ''
        ]));
    }

    private static function is_duplicate_event($order_id, $event_id)
    {
        return get_transient("unified_event_{$order_id}_{$event_id}") !== false;
    }

    private static function mark_event($order_id, $event_id)
    {
        set_transient("unified_event_{$order_id}_{$event_id}", 1, self::EVENT_TTL);
    }

    /* =========================================================
     * RESPONSE
     * ========================================================= */
    private static function safe_response($order, $reason, $state = null)
    {
        return [
            'ok' => true,
            'reason' => $reason,
            'order_id' => $order->get_id(),
            'state' => $state ?? self::get_state($order)
        ];
    }

    public static function resolve_final_state($order, $api_status = null)
    {
        // 1. PRIMARY: engine state (validated)
        $state = self::get_state($order);

        if (!empty($state) && in_array($state, ['pending','processing','success','failed','cancelled','expired'], true)) {
            return $state;
        }

        // 2. SAFETY: API status (current response)
        if (!empty($api_status)) {
            $mapped = match (strtolower((string)$api_status)) {
                'success', 'paid', 'completed' => 'success',
                'failed' => 'failed',
                'cancelled', 'canceled' => 'cancelled',
                'processing', 'pending' => 'processing',
                default => null
            };

            if ($mapped) {
                return $mapped;
            }
        }

        // 3. BACKUP: WooCommerce status
        if ($order->has_status(['processing', 'completed'])) {
            return 'success';
        }

        if ($order->has_status(['failed'])) {
            return 'failed';
        }

        if ($order->has_status(['cancelled'])) {
            return 'cancelled';
        }

        return null;
    }
}
