# Zoho Payments for WooCommerce

Accept payments in WooCommerce using Zoho Payments.

## Download ZIP

Download the latest installable plugin ZIP from the Zoho Payments WooCommerce GitHub Releases page:

```text
https://github.com/zoho/zoho-payments-woocommerce-extension/releases/latest
```

On the release page, download the attached plugin ZIP asset named:

```text
zoho-payments-woocommerce-v1.0.8.zip
```

## Overview

Zoho Payments for WooCommerce adds Zoho Payments as a payment gateway in your WooCommerce store. Customers can pay securely using the Zoho Payments checkout widget, while order payment status is updated through payment callbacks and webhook confirmation.

## Plugin Details

- Version: `1.0.8`
- Requires WordPress: `5.8` or later
- Tested up to WordPress: `6.5`

## PHP Security Notice (Action Required)

A critical security vulnerability has been fixed in PHP. **Updating your server's PHP to a patched release is mandatory** for any store that accepts payments, whether or not you use this plugin.

Your server must run one of these PHP versions, or a later release in the same branch:

| PHP branch | Minimum safe version |
|---|---|
| 8.2 | `8.2.35` |
| 8.3 | `8.3.36` |
| 8.4 | `8.4.27` |
| 8.5 | `8.5.12` |

- PHP `8.1` and older no longer receive security updates. Upgrade to one of the supported branches above.
- Check your current PHP version in **WordPress Admin > Tools > Site Health > Info > Server**.
- On managed or shared hosting, ask your hosting provider to upgrade PHP. If your provider backports security fixes to an older version number, ask them to confirm that this vulnerability is patched on your server.

This plugin does not block installation on older PHP versions, so stores can still receive this plugin's own security updates. That does not make an unpatched PHP version safe.

## Features

- Accept payments through Zoho Payments.
- Connect your Zoho Payments account using OAuth.
- Configure IN or US data centers.
- Enable sandbox mode for the IN data center.
- Verify payment callbacks using the Zoho Payments signing key.
- Receive webhook updates for asynchronous payment confirmation.
- Store sensitive gateway credentials securely in WordPress options.

## Requirements

- WordPress
- WooCommerce
- A security-patched PHP release (see [PHP Security Notice](#php-security-notice-action-required))
- A Zoho Payments account
- Zoho API Console client credentials
- Zoho Payments API key, signing key, and webhook signing key

## Installation

1. Download the plugin ZIP file from the GitHub Releases page.
2. In WordPress Admin, go to **Plugins > Add New > Upload Plugin**.
3. Upload the plugin ZIP file and activate it.
4. Go to **WooCommerce > Settings > Payments**.
5. Enable **Zoho Payments**.
6. Open the Zoho Payments settings page and complete the configuration.

## Configuration

In **WooCommerce > Settings > Payments > Zoho Payments**, configure the following fields:

- Data Center
- Client ID
- Client Secret
- Account ID
- Sandbox mode, if applicable
- API Key
- Signing Key
- Webhook Signing Key
- Payment method title and description
- Optional business name and business description

After saving the required credentials, use the **Connect** button to authorize the plugin with your Zoho Payments account.

## Webhook Setup

Configure the webhook endpoint in the Zoho Payments Dashboard under **Settings > Developer Space > Webhooks**.

Webhook URL:

```text
https://your-domain.com/wp-json/zpay/v1/webhook
```

Copy the webhook signing key from Zoho Payments and save it in the plugin settings.

## Changelog

### 1.0.8

- Payment sessions are now created only for orders placed with Zoho Payments while the gateway is enabled. Orders placed with other payment methods are no longer sent to Zoho Payments.
- The payment callback and webhook now act only on orders whose payment method is Zoho Payments.

### 1.0.7

- Updated the Zoho OAuth connect callback with a `state` nonce; the callback no longer runs on AJAX requests.
- Restricted the Data Center setting to supported regions (`in`, `com`) so Zoho API hostnames cannot be altered.
- Added a PHP security notice: updating PHP to `8.2.35`, `8.3.36`, `8.4.27`, `8.5.12` or a later release in the same branch is mandatory.

### 1.0.6

- Prevented duplicate payment-session creation.
- Strengthened payment callback and webhook validation with currency, payment-session, order state, and strict identifier checks.
- Added automatic access-token refresh and retry when payment verification encounters an expired or unauthorized token.
- Improved webhook event handling so only explicit success and failure events update order payment status.
- Improved encryption-key handling and automatically migrated credentials.
- Secured payment gateway disconnection.
- Removed the client-accessible payment-session clearing flow to preserve server-side session integrity.

### 1.0.5

- The order acted upon by the payment callback is now resolved from the payment session verified with Zoho, instead of the client-supplied `order_id`.

### 1.0.4

- Updated webhook amount validation.

### 1.0.3

- Added validation before processing Zoho Payments disconnect requests in the WordPress admin.

### 1.0.2

- Fixed webhook signature verification to reject requests when the webhook signing key is missing, empty, or invalidly configured.
- Marked the webhook signing key as required in plugin settings.

### 1.0.1

- Updated webhook payment amount validation to use the payment object's `net_amount`.

### 1.0.0

Initial stable release.

- Added Zoho Payments as a WooCommerce payment gateway.
- Added OAuth-based Zoho account connection.
- Added support for IN and US data centers.
- Added sandbox mode support for the IN data center.
- Added Zoho Payments checkout widget integration.
- Added payment callback verification using signing keys.
- Added webhook handling for payment status updates.
- Added secure storage for sensitive plugin credentials.
- Added configurable checkout title, description, business name, and business description.

## Upgrade Notice

### 1.0.8

Security update: Zoho Payments no longer creates payment sessions for, or updates, orders placed with other payment methods, including when the gateway is disabled. Upgrading is recommended.

### 1.0.7

Security update: hardened the Zoho OAuth connect flow and Data Center validation. Upgrading is strongly recommended. Updating your server's PHP to a patched release is also mandatory; see the PHP Security Notice.

### 1.0.6

Payment-session integrity, callback and webhook validation, credential encryption, token refresh, and WooCommerce order-status handling have been improved. Upgrading is strongly recommended.

### 1.0.5

Security update: The order acted upon by the payment callback is now resolved from the payment session verified with Zoho, instead of the client-supplied `order_id`. Upgrading is strongly recommended.

### 1.0.4

- Updated webhook amount validation.

### 1.0.3

Disconnect requests from the admin settings flow now include an additional validation check before stored Zoho Payments tokens are cleared.

### 1.0.2

Security update: webhook requests are now rejected when the webhook signing key is not configured. After upgrading, confirm that the webhook signing key from Zoho Payments is saved in the plugin settings.

### 1.0.1

Webhook payment amount validation now uses `net_amount` from Zoho Payments.

### 1.0.0

Initial stable release of Zoho Payments for WooCommerce.
