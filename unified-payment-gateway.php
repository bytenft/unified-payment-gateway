<?php

/**
 * Plugin Name: Voucher Pay
 * Description: Purchase and pay quickly with a voucher
 * Author: Unified
 * Author URI: https://rt.app/
 * Text Domain: unified-payment-gateway
 * Plugin URI: https://github.com/bytenft/unified-payment-gateway
 * Version: 1.0.3
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Copyright (c) 2024 Unified
 */

if (!defined('ABSPATH')) {
	exit;
}

define('UNIFIED_PAYMENT_GATEWAY_MIN_PHP_VER', '8.0');
define('UNIFIED_PAYMENT_GATEWAY_MIN_WC_VER', '6.5.4');
define('UNIFIED_PAYMENT_GATEWAY_FILE', __FILE__);
define('UNIFIED_PAYMENT_GATEWAY_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Include utility functions
require_once UNIFIED_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/unified-payment-gateway-utils.php';

// Migrations functions
include_once plugin_dir_path(__FILE__) . 'migration.php';

// Autoload classes
spl_autoload_register(function ($class) {
	if (strpos($class, 'UNIFIED_PAYMENT_GATEWAY') === 0) {
		$class_file = UNIFIED_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-' . str_replace('_', '-', strtolower($class)) . '.php';
		if (file_exists($class_file)) {
			require_once $class_file;
		}
	}
});

UNIFIED_PAYMENT_GATEWAY_Loader::get_instance();

add_action('woocommerce_cancel_unpaid_order', 'unified_cancel_unpaid_order_action');
add_action('woocommerce_order_status_cancelled', 'unified_cancel_unpaid_order_action');
add_action('woocommerce_order_status_changed', 'unified_cancel_unpaid_order_action', 10, 4);

add_filter('woocommerce_get_checkout_order_received_url', function($url, $order) {

    if (!$order || !is_a($order, 'WC_Order')) {
        return $url;
    }

    // Only police Unified orders
    if ($order->get_payment_method() !== 'unified') {
        return $url;
    }

	$wc_status    = $order->get_status();
	$engine_state = $order->get_meta('_unified_state');
	$success_meta = $order->get_meta('_unified_payment_success');
	
	Unified_Payment_Gateway_Logger::info(	
		sprintf(
			"[Order #%d] ThankYou Filter | WC=%s | Engine=%s | Success=%s",
			$order->get_id(),
			$wc_status,
			$engine_state,
			$success_meta ?: 'EMPTY'
		)
	);

    $is_valid = in_array($wc_status, ['processing', 'completed'], true) || $success_meta === 'yes' || $engine_state === 'success';

	if (!$is_valid) {
		Unified_Payment_Gateway_Logger::info(
			sprintf(
				'[Order #%d] ThankYou Filter BLOCKED -> %s',
				$order->get_id(),
				wc_get_checkout_url()
				)
			);
		return wc_get_checkout_url();
	}

   	Unified_Payment_Gateway_Logger::info(
		sprintf(
			'[Order #%d] ThankYou Filter ALLOWED -> %s',
			$order->get_id(),
			$url
		)
	);

	return $url;

}, 10, 2);

/**
 * Cancels the Unified payment link when a Unified order is cancelled.
 *
 * For voucher orders, the payment link may not exist yet because it is
 * created only after the customer clicks the purchase button in the email.
 *
 * @param int $order_id The ID of the order.
 */
function unified_cancel_unpaid_order_action($order_id)
{
	global $wpdb;

	if (empty($order_id) || !is_numeric($order_id) || $order_id <= 0) {
		return;
	}

	$order = wc_get_order($order_id);

	if (!$order) {
		return;
	}

	/*
	 * Only process Unified Payment Gateway orders.
	 * Ignore DFinSell, ByteNFT, and all other gateways.
	 */
	if ($order->get_payment_method() !== 'unified') {
		return;
	}

	/*
	 * Only process orders that are actually cancelled or failed.
	 */
	if (!$order->has_status(['cancelled', 'failed'])) {
		return;
	}

	/*
	 * The payment-link table is created by whichever gateway in this family
	 * first stores a link in it. Without it there is nothing recorded to cancel,
	 * and it is not queried so no database error is raised for it.
	 */
	if (!unified_order_payment_link_table_exists()) {
		Unified_Payment_Gateway_Logger::info(
			'No payment link table present. Nothing to cancel for this Unified order.',
			[
				'source'  => 'unified-payment-gateway',
				'context' => [
					'order_id' => $order_id,
				],
			]
		);

		return;
	}

	$table_name  = unified_get_order_payment_link_table();
	$cache_key   = 'unified_payment_row_' . intval($order_id);
	$cache_group = 'unified_payment_gateway';

	/*
	 * Try cache first.
	 */
	$payment_row = wp_cache_get($cache_key, $cache_group);

	if (false === $payment_row) {
		$safe_table_name = esc_sql($table_name);

		$sql = "SELECT * FROM {$safe_table_name} WHERE order_id = %d LIMIT 1";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$payment_row = $wpdb->get_row(
			$wpdb->prepare($sql, intval($order_id)),
			ARRAY_A
		);

		if ($payment_row) {
			wp_cache_set(
				$cache_key,
				$payment_row,
				$cache_group,
				5 * MINUTE_IN_SECONDS
			);
		}
	}

	/*
	 * Voucher flow can legitimately have no payment-link row yet.
	 *
	 * The payment link is created only when the customer clicks the
	 * purchase button in the voucher email.
	 */
	if (empty($payment_row)) {
		Unified_Payment_Gateway_Logger::info(
			'Unified order cancelled before payment link was created. No payment link to cancel.',
			[
				'source'  => 'unified-payment-gateway',
				'context' => [
					'order_id' => $order_id,
				],
			]
		);

		return;
	}

	$uuid           = sanitize_text_field($payment_row['uuid'] ?? '');
	$payment_link   = esc_url_raw($payment_row['payment_link'] ?? '');
	$customer_email = sanitize_email($payment_row['customer_email'] ?? '');
	$amount         = number_format(
		(float) ($payment_row['amount'] ?? 0),
		8,
		'.',
		''
	);

	/*
	 * A payment-link row exists but has no UUID.
	 *
	 * Do not call the API with an empty UUID.
	 */
	if (empty($uuid)) {
		Unified_Payment_Gateway_Logger::warning(
			'Unified payment link exists but UUID is missing. Skipping cancellation API call.',
			[
				'source'  => 'unified-payment-gateway',
				'context' => [
					'order_id' => $order_id,
					'uuid'     => $uuid,
				],
			]
		);

		return;
	}

	/*
	 * A single cancellation reaches this function more than once - the unpaid
	 * order hook, the status-changed hook and the cancelled hook all fire for
	 * it - and the link only needs cancelling once. Recorded per UUID, so a
	 * later payment link for the same order is still cancelled on its own.
	 */
	if ($order->get_meta('_unified_cancelled_link_uuid') === $uuid) {
		Unified_Payment_Gateway_Logger::info(
			'Unified payment link already cancelled. Skipping duplicate cancellation API call.',
			[
				'source'  => 'unified-payment-gateway',
				'context' => [
					'order_id' => $order_id,
					'uuid'     => $uuid,
				],
			]
		);

		return;
	}

	$apiPath  = '/api/cancel-order-link';
	$url      = UNIFIED_BASE_URL . $apiPath;
	$cleanUrl = esc_url(
		preg_replace('#(?<!:)//+#', '/', $url)
	);

	$request_payload = [
		'order_id'   => $order_id,
		'order_uuid' => $uuid,
		'status'     => $order->has_status('failed') ? 'failed' : 'canceled',
	];

	$response = wp_remote_post(
		$cleanUrl,
		[
			'method'    => 'POST',
			'timeout'   => 30,
			'body'      => wp_json_encode($request_payload),
			'headers'   => [
				'Content-Type' => 'application/json',
			],
			'sslverify' => true,
		]
	);

	if (is_wp_error($response)) {
		Unified_Payment_Gateway_Logger::error(
			"Cancel API call failed. Order ID: {$order_id}",
			[
				'source'  => 'unified-payment-gateway',
				'context' => [
					'order_id' => $order_id,
					'uuid'     => $uuid,
					'error'    => $response->get_error_message(),
				],
			]
		);

		return;
	}

	$response_body    = wp_remote_retrieve_body($response);
	$decoded_response = json_decode($response_body, true);

	/*
	 * Only a cancellation the application confirmed closes the matter. A refusal
	 * - a link it cannot find, a payment it has already settled - is left open
	 * so a later hook for this order tries it again.
	 */
	if (!empty($decoded_response['status'])) {
		$order->update_meta_data('_unified_cancelled_link_uuid', $uuid);
		$order->save();
	}

	Unified_Payment_Gateway_Logger::info(
		"Cancel API response received for Order ID: {$order_id}.",
		[
			'source'  => 'unified-payment-gateway',
			'context' => [
				'order_id'       => $order_id,
				'uuid'           => $uuid,
				'payment_link'   => $payment_link,
				'customer_email' => $customer_email,
				'amount'         => number_format(
					(float) $amount,
					2,
					'.',
					''
				),
				'response'       => $decoded_response,
			],
		]
	);
}