# PassimPay WHMCS Installation Guide

This guide is for WHMCS admins who install the PassimPay payment gateway from a ZIP downloaded from your website.

## Requirements

- WHMCS 8.0+
- PHP 7.4+
- cURL extension enabled
- HTTPS-enabled WHMCS domain
- PassimPay merchant account with Platform ID and API Key

## Install (3 minutes)

1. Download the latest `passimpay-whmcs-vX.Y.Z.zip`.
2. Extract the archive locally.
3. Upload and merge the `modules` folder into WHMCS root (directory containing `configuration.php`).
4. Verify these files exist:
   - `modules/gateways/passimpay.php`
   - `modules/gateways/passimpay/PassimpayMerchantAPI.php`
   - `modules/gateways/callback/passimpay.php`

## Activate in WHMCS

1. Open WHMCS admin.
2. Go to Payment Gateways (or Apps & Integrations -> Payments).
3. Activate **PassimPay — Crypto & Fiat Payments**.
4. Fill:
   - Platform ID
   - API Key
   - Payment Options
5. Save changes.

## Configure webhook in PassimPay

Set Notification URL in PassimPay platform settings:

`https://your-whmcs-domain.com/modules/gateways/callback/passimpay.php`

## Test payment

1. Create a test invoice in WHMCS.
2. Choose PassimPay and click Pay Now.
3. Confirm redirect to PassimPay payment page.
4. Complete test payment.
5. Verify invoice changes to **Paid**.

## Troubleshooting

- Use WHMCS activity/module/gateway logs.
- Check PHP/web server error logs.
- Upload `install-check.php` to WHMCS root and open it in browser:
  - `https://your-whmcs-domain.com/install-check.php`
- Delete `install-check.php` after diagnostics.
