<?php
/**
 * PassimPay Payment Gateway Module for WHMCS
 *
 * Third Party Gateway — customer is redirected to the PassimPay payment page
 * to complete payment (Invoice Link method via /v2/createorder API).
 *
 * Supports cryptocurrency and fiat payments through PassimPay.
 * Webhook callback handles automatic invoice payment upon successful deposit.
 *
 * @see https://developers.whmcs.com/payment-gateways/
 * @see https://passimpay.gitbook.io/passimpay-api
 *
 * @copyright PassimPay
 * @version 1.0.4
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Module metadata — tells WHMCS about capabilities and display name.
 *
 * DisableLocalCreditCardInput=true because PassimPay handles all payment
 * collection on its own hosted page (Third Party Gateway pattern).
 *
 * @return array
 */
function passimpay_MetaData()
{
    return array(
        'DisplayName'              => 'PassimPay — Crypto & Fiat Payments',
        'APIVersion'               => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage'         => false,
    );
}

/**
 * Admin configuration fields shown when activating the gateway.
 *
 * Settings defined here are passed into _link and callback via $params.
 * All secrets stay in DB (WHMCS encrypted storage), never hardcoded.
 *
 * @return array
 */
function passimpay_config()
{
    return array(
        'FriendlyName' => array(
            'Type'  => 'System',
            'Value' => 'PassimPay — Crypto & Fiat Payments',
        ),
        'platformId' => array(
            'FriendlyName' => 'Platform ID',
            'Type'         => 'text',
            'Size'         => '25',
            'Default'      => '',
            'Description'  => 'Your Platform ID from the PassimPay dashboard.',
        ),
        'secretKey' => array(
            'FriendlyName' => 'API Key',
            'Type'         => 'password',
            'Size'         => '60',
            'Default'      => '',
            'Description'  => 'Your API Key (Secret Key) from the PassimPay dashboard.',
        ),
        'debugMode' => array(
            'FriendlyName' => 'Debug Logging',
            'Type'         => 'yesno',
            'Description'  => 'Enable verbose gateway logs for troubleshooting. Disable in production to avoid logging full webhook payloads.',
        ),
        'paymentType' => array(
            'FriendlyName' => 'Payment Options',
            'Type'         => 'dropdown',
            'Options'      => array(
                '0' => 'Card and Cryptocurrency',
                '1' => 'Cryptocurrency Only',
                '2' => 'Bank Card Only (Fiat)',
            ),
            'Default'     => '0',
            'Description' => 'What payment methods to display on the PassimPay payment page. Must match your PassimPay platform settings.<br><br><strong>Notification URL:</strong><br><code>{YOUR_WHMCS_URL}/modules/gateways/callback/passimpay.php</code><br>Copy this URL into your PassimPay platform settings.',
        ),
    );
}

/**
 * Payment link — generates the "Pay Now" button on the WHMCS invoice page.
 *
 * Flow:
 * 1. Calls PassimPay /v2/createorder API with invoice details
 * 2. Receives a payment page URL
 * 3. Returns an HTML form/button that redirects the customer to that URL
 *
 * If the API call fails, shows an error message instead of the button
 * so the customer knows something went wrong.
 *
 * @param array $params Gateway config + invoice + client + system parameters
 * @return string HTML to render on the invoice page
 */
function passimpay_link($params)
{
    // --- Gateway configuration (from admin settings) ---
    $platformId  = trim($params['platformId']);
    $secretKey   = trim($params['secretKey']);
    $paymentType = isset($params['paymentType']) ? (int) $params['paymentType'] : 0;

    // --- Invoice parameters ---
    $invoiceId   = $params['invoiceid'];
    $amount      = $params['amount'];        // Format: xxx.xx
    $currencyCode = $params['currency'];      // ISO 4217: USD, EUR, etc.

    // --- Client parameters (optional, helps PassimPay with fraud prevention) ---
    $firstName = $params['clientdetails']['firstname'];
    $lastName  = $params['clientdetails']['lastname'];
    $email     = $params['clientdetails']['email'];

    // --- System parameters ---
    $langPayNow  = $params['langpaynow'];

    // Validate required settings — without these, we can't call the API
    if (empty($platformId) || empty($secretKey)) {
        return '<div style="color:#721c24;background:#f8d7da;padding:12px;border-radius:4px;">'
             . 'PassimPay configuration error: Platform ID or API Key is missing. '
             . 'Please contact the site administrator.'
             . '</div>';
    }

    // Load the API client class
    require_once __DIR__ . '/passimpay/PassimpayMerchantAPI.php';

    $api = new PassimpayMerchantAPI($platformId, $secretKey);

    // Create an invoice on PassimPay — returns a payment page URL
    $api->createOrder(array(
        'order_id'  => (string) $invoiceId,
        'amount'    => number_format((float) $amount, 2, '.', ''),
        'symbol'    => strtoupper($currencyCode),
        'type'      => $paymentType,
        'firstName' => $firstName,
        'lastName'  => $lastName,
        'email'     => $email,
    ));

    // Check for API errors
    if (!empty($api->error)) {
        // Log the error for admin debugging via WHMCS Gateway Log
        logTransaction($params['name'], array(
            'action'    => 'createorder',
            'invoiceId' => $invoiceId,
            'error'     => $api->error,
            'response'  => $api->response,
        ), 'Error');

        return '<div style="color:#721c24;background:#f8d7da;padding:12px;border-radius:4px;">'
             . 'Payment service temporarily unavailable. Please try again later or contact support.'
             . '</div>';
    }

    $paymentUrl = $api->paymentUrl;

    if (empty($paymentUrl)) {
        // API returned success but no URL — unexpected edge case
        logTransaction($params['name'], array(
            'action'    => 'createorder',
            'invoiceId' => $invoiceId,
            'error'     => 'API returned success but no payment URL',
            'response'  => $api->response,
        ), 'Error');

        return '<div style="color:#721c24;background:#f8d7da;padding:12px;border-radius:4px;">'
             . 'Unable to create payment link. Please try again or contact support.'
             . '</div>';
    }

    // Log successful order creation for admin audit trail
    logTransaction($params['name'], array(
        'action'     => 'createorder',
        'invoiceId'  => $invoiceId,
        'paymentUrl' => $paymentUrl,
    ), 'Success');

    // Build a styled "Pay Now" button that redirects to PassimPay payment page.
    // Using a direct link instead of a form POST because PassimPay provides
    // a ready-to-use URL — no need to submit data to it.
    $htmlOutput = '<a href="' . htmlspecialchars($paymentUrl, ENT_QUOTES, 'UTF-8') . '" '
                . 'style="display:inline-block;padding:10px 24px;background:#1843BF;color:#fff;'
                . 'text-decoration:none;border-radius:6px;font-size:15px;font-weight:600;'
                . 'transition:background .2s;" '
                . 'onmouseover="this.style.background=\'#133299\'" '
                . 'onmouseout="this.style.background=\'#1843BF\'" '
                . 'target="_blank" rel="noopener">'
                . htmlspecialchars($langPayNow, ENT_QUOTES, 'UTF-8')
                . '</a>';

    return $htmlOutput;
}
