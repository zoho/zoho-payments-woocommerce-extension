# Zoho Payments for WooCommerce

Accept payments in WooCommerce using Zoho Payments.

## Download ZIP

Download the latest installable plugin ZIP from the Zoho Payments WooCommerce GitHub Releases page:

```text
https://github.com/zoho/zoho-payments-woocommerce-extension/releases/latest
```

On the release page, download the attached plugin ZIP asset named:

```text
zoho-payments-woocommerce-v1.0.1.zip
```

## Overview

Zoho Payments for WooCommerce adds Zoho Payments as a payment gateway in your WooCommerce store. Customers can pay securely using the Zoho Payments checkout widget, while order payment status is updated through payment callbacks and webhook confirmation.

## Plugin Details

- Version: `1.0.1`
- Requires WordPress: `5.8` or later
- Tested up to WordPress: `6.5`

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

### 1.0.1

Webhook payment amount validation now uses `net_amount` from Zoho Payments.

### 1.0.0

Initial stable release of Zoho Payments for WooCommerce.
