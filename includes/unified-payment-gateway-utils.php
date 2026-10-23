<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
/**
 * Check the environment for compatibility issues.
 *
 * @return string|false
 */
function unified_check_system_requirements()
{
	if (version_compare(phpversion(), UNIFIED_PAYMENT_GATEWAY_MIN_PHP_VER, '<')) {
		return sprintf(
			// translators: %1$s is the minimum required PHP version, %2$s is the current PHP version
			__('The Voucher Pay plugin requires PHP version %1$s or greater. You are running %2$s.', 'unified-payment-gateway'),
			UNIFIED_PAYMENT_GATEWAY_MIN_PHP_VER,
			phpversion()
		);
	}

	// Get WooCommerce versions
	$wc_db_version = get_option('woocommerce_db_version');
	$wc_plugin_version = defined('WC_VERSION') ? WC_VERSION : null;

	// Check if the WooCommerce database version is outdated
	if (!$wc_db_version || version_compare($wc_db_version, UNIFIED_PAYMENT_GATEWAY_MIN_WC_VER, '<')) {
		return sprintf(
			// translators: %1$s is the minimum required WooCommerce database version, %2$s is the current WooCommerce database version (or "undefined" if not available)
			__('The Voucher Pay plugin requires WooCommerce database version %1$s or greater. You are running %2$s.', 'unified-payment-gateway'),
			UNIFIED_PAYMENT_GATEWAY_MIN_WC_VER,
			$wc_db_version ? $wc_db_version : __('undefined', 'unified-payment-gateway')
		);
	}

	// Check if WooCommerce plugin version is outdated
	if (!$wc_plugin_version || version_compare($wc_plugin_version, UNIFIED_PAYMENT_GATEWAY_MIN_WC_VER, '<')) {
		return sprintf(
			// translators: %1$s is the minimum required WooCommerce plugin version, %2$s is the current WooCommerce plugin version (or "undefined" if not available)
			__('The Voucher Pay plugin requires WooCommerce plugin version %1$s or greater. You are running %2$s.', 'unified-payment-gateway'),
			UNIFIED_PAYMENT_GATEWAY_MIN_WC_VER,
			$wc_plugin_version ? $wc_plugin_version : __('undefined', 'unified-payment-gateway')
		);
	}

	return false;
}

/**
 * Activation check for the plugin.
 */
function unified_activation_check()
{
	$environment_warning = unified_check_system_requirements();
	if ($environment_warning) {
		deactivate_plugins(plugin_basename(UNIFIED_PAYMENT_GATEWAY_FILE));
		wp_die(esc_html($environment_warning)); // Escape the output before calling wp_die
	}
}

if (!function_exists('unified_add_unique_order_note')) {

    function unified_add_unique_order_note($order, $key, $message)
    {
        if (!$order instanceof WC_Order) {
            return false;
        }

        if (empty($message)) {
            return false;
        }

        // Plugin identifier (IMPORTANT for tracking in WP admin)
        $plugin_prefix = '<strong>Voucher Pay</strong>';

        // Unique meta key per note type (scoped to plugin)
        $meta_key = '_unified_order_note_' . sanitize_key($key);

        // Check if already exists
        $existing = $order->get_meta($meta_key, true);

        if (!empty($existing)) {
            return false;
        }

        // Prepend plugin identifier to every note
        $final_message = $plugin_prefix . "\n\n" . wp_kses_post($message);

        // Add WooCommerce order note
        $order->add_order_note($final_message);

        // Store timestamp using WooCommerce timezone-aware time
        $order->update_meta_data($meta_key, current_time('timestamp'));

        $order->save();

        return true;
    }
}

if (!function_exists('unified_get_order_payment_link_table')) {

	/**
	 * Name of the payment-link table.
	 *
	 * Shared with the other gateways in this family, so the columns and the
	 * name are theirs and are not changed here.
	 *
	 * @return string
	 */
	function unified_get_order_payment_link_table()
	{
		global $wpdb;

		return $wpdb->prefix . 'order_payment_link';
	}
}

if (!function_exists('unified_order_payment_link_table_exists')) {

	/**
	 * Whether the payment-link table is present.
	 *
	 * Each gateway in this family creates the table the first time it stores a
	 * payment link. Voucher Pay can be the only one installed, so it creates it
	 * the same way and with exactly the same columns.
	 *
	 * @param bool $create Create the table when it is missing.
	 * @return bool
	 */
	function unified_order_payment_link_table_exists($create = false)
	{
		global $wpdb;

		$table_name  = unified_get_order_payment_link_table();
		$cache_key   = 'unified_table_exists_' . md5($table_name);
		$cache_group = 'unified_payment_gateway';

		// Only a table that is there is remembered: another gateway in this
		// family may create it at any time, so its absence is looked up again.
		if (wp_cache_get($cache_key, $cache_group) === $table_name) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$table_exists = $wpdb->get_var(
			$wpdb->prepare('SHOW TABLES LIKE %s', $table_name)
		);

		if ($table_exists === $table_name) {
			wp_cache_set($cache_key, $table_name, $cache_group, HOUR_IN_SECONDS);

			return true;
		}

		if (!$create) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$create_sql = "CREATE TABLE $table_name (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			order_id BIGINT UNSIGNED NOT NULL,
			uuid VARCHAR(100) NOT NULL,
			payment_link TEXT NOT NULL,
			customer_email VARCHAR(191),
			amount DECIMAL(18,2),
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP
		) $charset_collate;";

		dbDelta($create_sql);

		wp_cache_set($cache_key, $table_name, $cache_group, HOUR_IN_SECONDS);

		Unified_Payment_Gateway_Logger::info(
			"Created missing `{$table_name}` table.",
			[
				'source'  => 'unified-payment-gateway',
				'context' => ['table' => $table_name],
			]
		);

		return true;
	}
}

if (!function_exists('unified_store_order_payment_link')) {

	/**
	 * Record the payment link the application created for a Unified order.
	 *
	 * Checkout creates no payment link: the voucher flow creates the real one
	 * when the customer opens the button in their email, and the store first
	 * sees it when the application reports that payment back. Written the same
	 * way ByteNFT writes it - one row per order, refreshed when a later payment
	 * replaces an earlier one - because a cancellation reads its UUID from here.
	 *
	 * @param WC_Order $order Order the payment belongs to.
	 * @param array    $data  Payment fields: uuid, payment_link, customer_email, amount.
	 * @return bool Whether the order now has a payment-link row.
	 */
	function unified_store_order_payment_link($order, $data = [])
	{
		global $wpdb;

		if (!$order instanceof WC_Order) {
			return false;
		}

		$order_id = (int) $order->get_id();
		$uuid     = sanitize_text_field(is_scalar($data['uuid'] ?? null) ? (string) $data['uuid'] : '');

		/*
		 * The UUID is the whole point of the row: it is what the cancellation
		 * call identifies the payment link by. Nothing is written without it.
		 */
		if ($order_id <= 0 || empty($uuid)) {
			return false;
		}

		if (!unified_order_payment_link_table_exists(true)) {
			Unified_Payment_Gateway_Logger::error(
				'Payment link table missing and could not be created. Payment link not stored.',
				[
					'source'  => 'unified-payment-gateway',
					'context' => ['order_id' => $order_id],
				]
			);

			return false;
		}

		$table_name      = unified_get_order_payment_link_table();
		$safe_table_name = esc_sql($table_name);

		$payment_link = esc_url_raw(
			is_scalar($data['payment_link'] ?? null) ? (string) $data['payment_link'] : ''
		);

		$customer_email = sanitize_email(
			is_scalar($data['customer_email'] ?? null) ? (string) $data['customer_email'] : ''
		);

		if (empty($customer_email)) {
			$customer_email = sanitize_email($order->get_billing_email());
		}

		$amount = isset($data['amount']) && is_numeric($data['amount'])
			? (float) $data['amount']
			: (float) $order->get_total();

		$amount = number_format($amount, 2, '.', '');

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, uuid, payment_link FROM {$safe_table_name} WHERE order_id = %d LIMIT 1",
				$order_id
			),
			ARRAY_A
		);

		$row = [
			'uuid'           => $uuid,
			'payment_link'   => $payment_link,
			'customer_email' => $customer_email,
			'amount'         => $amount,
			'created_at'     => current_time('mysql', 1),
		];

		if (!empty($existing)) {

			/*
			 * The application reports the same payment more than once - a
			 * return to the store and a webhook for it - and the row it already
			 * has is the row it would be given.
			 */
			if (
				$existing['uuid'] === $uuid &&
				(empty($payment_link) || $existing['payment_link'] === $payment_link)
			) {
				return true;
			}

			// A report without the link does not erase the one already recorded.
			if (empty($payment_link)) {
				unset($row['payment_link']);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$written = $wpdb->update(
				$table_name,
				$row,
				['order_id' => $order_id],
				array_fill(0, count($row), '%s'),
				['%d']
			);

		} else {

			$row['order_id'] = $order_id;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$written = $wpdb->insert(
				$table_name,
				$row,
				['%s', '%s', '%s', '%s', '%s', '%d']
			);
		}

		if (false === $written) {
			Unified_Payment_Gateway_Logger::error(
				'Failed to store Unified order payment link.',
				[
					'source'  => 'unified-payment-gateway',
					'context' => [
						'order_id' => $order_id,
						'uuid'     => $uuid,
						'error'    => $wpdb->last_error,
					],
				]
			);

			return false;
		}

		// A cancellation reads this row through the cache.
		wp_cache_delete('unified_payment_row_' . $order_id, 'unified_payment_gateway');

		Unified_Payment_Gateway_Logger::info(
			'Stored Unified order payment link.',
			[
				'source'  => 'unified-payment-gateway',
				'context' => [
					'order_id'     => $order_id,
					'uuid'         => $uuid,
					'payment_link' => $payment_link,
					'amount'       => $amount,
				],
			]
		);

		return true;
	}
}
