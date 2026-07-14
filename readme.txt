=== Unified Payment Gateway ===
Contributors: Unified
Tags: woocommerce, payment gateway, fiat, Unified
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 1.0.2
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

The Unified Payment Gateway plugin for WooCommerce 8.9+ allows you to accept fiat payments to sell products on your WooCommerce store.

== Description ==

This plugin integrates Unified Payment Gateway with WooCommerce, enabling you to accept fiat payments. 

== Installation ==

1. Download the plugin ZIP file from GitHub.
2. Extract the ZIP file and upload it to the `wp-content/plugins` directory.
3. Activate the plugin through the 'Plugins' menu in WordPress.

== Frequently Asked Questions ==

= How do I obtain API keys? =

Visit the DFin website and log in to your account. Navigate to Developer Settings to generate or retrieve API keys.

== Changelog ==

= 1.0.2 =
* Improved failed payment handling by preventing duplicate payment failure notices when redirecting customers back to the checkout page.
* PO Box Validation: Expanded PO Box detection to support additional address variations such as `POB`, `Postal Box`, and `Post Office Box`.

= 1.0.1 =
* Resolved mobile number validation issues during checkout.
* Fixed ZIP/postal code validation to support valid customer inputs.
* Corrected PO Box address validation.
* Fixed invalid API key validation and improved error handling.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.2 =
* This release improves the payment experience by preventing duplicate payment failure notices during failed payment redirects.
* Enhanced PO Box validation by detecting additional address variations, including POB, Postal Box, and Post Office Box, to help prevent unsupported shipping addresses during checkout.

= 1.0.1 =
* This release improves the payment experience by resolving mobile number, ZIP/postal code, and PO Box validation issues. It also fixes invalid API key handling to improve plugin reliability and ensure a smoother checkout experience.

= 1.0.0 =
Initial release.

== Support ==

For support, visit: [https://rt.app/contact-us](https://rt.app/contact-us)
