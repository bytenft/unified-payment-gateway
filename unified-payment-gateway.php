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
 * Cancels an unpaid order after a specified timeout.
 *
 * @param int $order_id The ID of the order to cancel.
 */
function unified_cancel_unpaid_order_action($order_id)
{
	global $wpdb;

	if (empty($order_id) || !is_numeric($order_id) || $order_id <= 0) {
		return;
	}

	$order = wc_get_order($order_id);

	// Fallback: try to fetch latest placeholder if order is invalid
	if (!$order) {
		$args = [
			'post_type'      => 'shop_order_placehold',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'fields'         => 'ids',
		];

		$placeholder_orders = get_posts($args);

		if (!empty($placeholder_orders)) {
			$order_id = $placeholder_orders[0];
			$order    = wc_get_order($order_id);

			Unified_Payment_Gateway_Logger::info('Fallback to latest unpaid placeholder order.', [
				'source'  => 'unified-payment-gateway',
				'context' => ['order_id' => $order_id],
			]);
		} else {
			Unified_Payment_Gateway_Logger::error('No unpaid placeholder orders found.', [
				'source' => 'unified-payment-gateway',
			]);
			return;
		}
	}

	if (!$order) {
		Unified_Payment_Gateway_Logger::error('Order not found.', [
			'source'  => 'unified-payment-gateway',
			'context' => ['order_id' => $order_id],
		]);
		return;
	}

	if ($order->get_status() === 'cancelled') {

		// Prevent duplicate cancellation calls for the same order
		if ($order->get_meta('_unified_cancel_synced') || $order->get_meta('_unified_cancel_sent')) {
			Unified_Payment_Gateway_Logger::info("[Order #{$order_id}] Portal cancellation already processed; skipping duplicate request.");
			return;
		}

		$pending_time = get_post_meta($order_id, '_pending_order_time', true);
		$pending_time = is_numeric($pending_time) ? (int) $pending_time : 0;

		if ($order->has_status('pending')) {
			if ((time() - $pending_time) < (30 * 60)) {
				Unified_Payment_Gateway_Logger::info('Order still within pending timeout. Skipping cancel.', [
					'source'  => 'unified-payment-gateway',
					'context' => ['order_id' => $order_id],
				]);
				return;
			}

			$order->update_status('cancelled', 'Order automatically cancelled due to unpaid timeout.');
			wc_reduce_stock_levels($order_id);
			wp_cache_delete('unified_payment_link_uuid_' . $order_id, 'unified_payment_gateway');
			wp_cache_delete('unified_payment_row_' . $order_id, 'unified_payment_gateway'); // Clear row cache

			Unified_Payment_Gateway_Logger::info('Order auto-cancelled due to unpaid timeout.', [
				'source'  => 'unified-payment-gateway',
				'context' => ['order_id' => $order_id],
			]);
		}

		// Fetch UUID and reference stored in WooCommerce order metadata (HPOS compatible)
		$voucher_id   = $order->get_meta('_unified_voucher_id') ?: get_post_meta($order_id, '_unified_voucher_id', true);
		$reference    = $order->get_meta('_unified_voucher_reference') ?: get_post_meta($order_id, '_unified_voucher_reference', true);
		$payment_link = $order->get_meta('_unified_payment_link') ?: get_post_meta($order_id, '_unified_payment_link', true);

		if (empty($voucher_id) && empty($reference)) {
			Unified_Payment_Gateway_Logger::info('No voucher ID or reference found; skipping Portal cancellation.', [
				'source'  => 'unified-payment-gateway',
				'context' => ['order_id' => $order_id],
			]);
			return;
		}

		// Mark order as cancel synced before API call to lock out concurrent hooks
		$order->update_meta_data('_unified_cancel_synced', true);
		$order->update_meta_data('_unified_cancel_sent', true);
		$order->save();

		$cancel_url     = esc_url_raw(set_url_scheme(UNIFIED_BASE_URL . '/api/cancel-order-link', 'https'));
		$cancel_payload = [
			'order_id'            => $order_id,
			'order_uuid'          => sanitize_text_field($voucher_id), // voucher_id is the UUID
			'payment_link'        => !empty($payment_link) ? base64_encode($payment_link) : '',
			'payment_link_raw'    => sanitize_text_field($payment_link),
			'status'              => 'canceled',
		];

		Unified_Payment_Gateway_Logger::info('Sending payment link cancellation to Portal via order meta.', [
			'source'  => 'unified-payment-gateway',
			'context' => [
				'order_id'     => $order_id,
				'order_uuid'   => $voucher_id,
				'reference'    => $reference,
				'payment_link' => $payment_link,
				'url'          => $cancel_url,
			],
		]);

		$cancel_response = wp_remote_post($cancel_url, [
			'method'    => 'POST',
			'timeout'   => 30,
			'body'      => wp_json_encode($cancel_payload),
			'headers'   => ['Content-Type' => 'application/json'],
			'sslverify' => true,
		]);

		$cancel_code = is_wp_error($cancel_response) ? 0 : (int) wp_remote_retrieve_response_code($cancel_response);
		$cancel_body = is_wp_error($cancel_response) ? [] : json_decode(wp_remote_retrieve_body($cancel_response), true);

		Unified_Payment_Gateway_Logger::info('Portal payment link cancellation response.', [
			'source'  => 'unified-payment-gateway',
			'context' => [
				'order_id'  => $order_id,
				'http_code' => $cancel_code,
				'response'  => $cancel_body,
			],
		]);
	}
}
