# Unified Payment Gateway

The Unified Payment Gateway plugin for WooCommerce 8.9+ allows you to accept fiat payments to sell products on your WooCommerce store.

## Plugin Information

**Contributors:** Unified  
**Tags:** woocommerce, payment gateway, fiat, Unified  
**Requires at least:** 6.2  
**Tested up to:** 6.9  
**Stable tag:** 1.0.2 
**License:** GPLv3 or later  
**License URI:** [GPLv3 License](https://www.gnu.org/licenses/gpl-3.0.html)

## Support

For any issues or enhancement requests with this plugin, please contact the Unified support team. Ensure you provide your plugin, WooCommerce, and WordPress version where applicable to expedite troubleshooting.

## Getting Started

1. Obtain your API keys from your Unified dashboard in your Developer Settings - API Keys.
2. Follow the plugin installation instructions below.
3. You are ready to take payments in your WooCommerce store!

## Installation

### Minimum Requirements

- WooCommerce 8.9 or greater
- PHP version 8.0 or greater

### Steps

## 1. Download Plugin from GitHub

- Visit the GitHub repository for the Unified Payment Gateway plugin at [GitHub Repository URL](https://github.com/Cooraez12/unified-payment-gateway).
- Download the plugin ZIP file to your local machine.

## 2. Install the Plugin in WordPress

- **Extract the Downloaded ZIP file to your Local Machine:**
  Extract the ZIP file containing the plugin files.

- **Upload via FTP or File Manager:**
  - Connect to your WordPress site via FTP or use File Manager in your hosting control panel.
  - Navigate to `wp-content/plugins` directory.
  - Upload the extracted plugin folder to the plugins directory on your server.

## 3. Activate the Plugin

- **Log in to WordPress Admin Dashboard:**
  Log in to your WordPress Admin Dashboard.
- **Navigate to Installed Plugins:**
  Go to `Plugins` > `Installed Plugins`.
- **Activate Unified Payment Gateway:**
  - Locate the Unified Payment Gateway plugin in the list.
  - Click `Activate` to enable the plugin.

## 4. Obtain API Keys from Unified Developer Settings Dashboard

- **Log in to Unified Account:**
  Visit the Unified website and log in to your account.
- **Navigate to Developer Settings to get API Keys:**
  Once logged in, find and access the Developer Settings.
- **Generate or Retrieve API Keys:**
  If API keys are not already generated, you can create new ones.
  Locate the API Keys or Credentials section.
  Generate or retrieve the required API keys (e.g., Public Key, Secret Key) needed for integration with the Unified Payment Gateway plugin.

## 5. Update API Keys in WooCommerce Settings

- **Navigate to WooCommerce Settings:**
  Log in to your WordPress Admin Dashboard.
  Go to `WooCommerce` > `Settings`.
- **Access the Payments Tab:**
  Click on the `Payments` tab at the top of the settings page.
- **Select Unified Payment Gateway:**
  Scroll down to find and select the Unified Payment Gateway among the available payment methods.

- **Add Plugin General Details:**

  - **Title** : Unified Payment Gateway
    Description
  - **Description** : Secure payments with Unified Payment Gateway.
  - **Enable/Disable Sandbox Mode** : Toggle sandbox mode per account.
  - **Payment Accounts (Add Multiple Accounts)** :
    - **Adding a New Account**
      1. Click on **Add Account** to create a new account.
      2. Enter a **unique account title**.
      3. Provide details for each account:
         - _Account Title_
         - _Priority:_ Set an order for the accounts.
         - _Live Mode:_ Public & Secret Keys (mandatory)
         - _Sandbox Mode:_ Public & Secret Keys (optional)
  - **Order Status** : Select Processing or Completed.
  - **Show Consent Checkbox** : Enabling this option will display a consent checkbox on the checkout page.

- **Save Changes:**
  Click `Save changes` at the bottom of the page to update and save your API key settings.

## 6. Place Order via Unified Payment Option

- **Visit Your Store Page and Add Products to Cart:**
  Navigate to your WordPress site's store page.
  Browse and add desired products to the cart.

- **Proceed to Checkout:**
  Go to your WordPress site's checkout page to review your order details.

- **Check Available Payment Methods:**
  Ensure that the Unified Payment Gateway option is visible among the available payment methods listed on the checkout page.

- **Verify Integration:**
  Confirm that customers can select the Unified Payment Gateway as a payment option when placing their orders.

## 7. Popup Window for Payment

- **Secure Payment Processing:**
  Upon selecting Unified, a secure popup window will open for payment processing.

## 8. Complete the Payment Process

- **Follow Instructions:**
  Follow the instructions provided in the popup window to securely complete the payment.

## 9. Redirect to WordPress Website with Order Status

- **After Successful Payment:**
  Once the payment is successfully processed, the popup window will automatically close.
  Customers will be redirected back to your WordPress site.

## 10. Check Orders in WordPress

- **Verify Order Status:**
  Log in to your WordPress Admin Dashboard.
  Navigate to `WooCommerce` > `Orders` to view all orders.
  Check for the latest orders placed using the Unified Payment Gateway to verify their status.

## Documentation

The official documentation for this plugin is available at: [https://rt.app/docs/wordpress-plugin](https://rt.app/docs/wordpress-plugin)

## Changelog

### Version 1.0.2
- Failed Payment Redirect: Improved the failed payment flow by redirecting customers back to the checkout page without displaying a duplicate payment failure notice, preventing redundant error messages.
- PO Box Validation: Expanded PO Box detection to support additional address variations such as `POB`, `Postal Box`, and `Post Office Box`.

### Version 1.0.1

- **Mobile Number Validation:** Resolved issues with mobile number validation during checkout.
- **ZIP/Postal Code Validation:** Fixed ZIP/postal code validation to accept valid customer inputs.
- **PO Box Validation:** Corrected PO Box address validation to improve address verification.
- **Invalid API Key Handling:** Fixed invalid API key validation and improved error handling for better reliability.

### Version 1.0.0 (Initial Release)

- **Initial Release:** Launched the Unified Payment Gateway plugin with core payment integration functionality for WooCommerce.

## Support

For customer support, visit: [https://rt.app/contact-us](https://rt.app/contact-us)

## Why Choose Unified Payment Gateway?

With the Unified Payment Gateway, you can easily transfer fiat payments to sell products. Choose Unified Payment Gateway as your WooCommerce payment gateway to access your funds quickly through a powerful and secure payment engine provided by Unified.
