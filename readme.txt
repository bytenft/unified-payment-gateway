=== Voucher Pay ===
Contributors: Unified
Tags: woocommerce, payment gateway, fiat, Unified
Requires at least: 5.0
Tested up to: 7.1
Stable tag: 1.0.30
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

The Voucher Pay plugin for WooCommerce 8.9+ allows you to accept fiat payments to sell products on your WooCommerce store.

== Description ==

This plugin integrates Voucher Pay with WooCommerce, enabling you to accept fiat payments. 

== Installation ==

1. Download the plugin ZIP file from GitHub.
2. Extract the ZIP file and upload it to the `wp-content/plugins` directory.
3. Activate the plugin through the 'Plugins' menu in WordPress.

== Frequently Asked Questions ==

= How do I obtain API keys? =

Visit the DFin website and log in to your account. Navigate to Developer Settings to generate or retrieve API keys.

== Changelog ==

= 1.0.30 =
* New voucher checkout: placing an order emails the customer a redeemable voucher, and the payment page opens only when they use it. No payment link is opened during checkout.
* The order confirmation shown at checkout now includes the voucher reference and the products ordered.
* Ordered products, with their images, are sent with the voucher so customers can see what they are paying for.
* Fixed payment account settings not being read consistently across the plugin.

== Upgrade Notice ==

= 1.0.30 =
* Switches checkout to the voucher flow: customers receive a voucher by email and complete payment when they open it. Re-check your payment account settings after updating.

== Support ==

For support, visit: [https://rt.app/contact-us](https://rt.app/contact-us)
