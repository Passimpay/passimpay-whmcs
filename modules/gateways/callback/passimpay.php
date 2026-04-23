<?php
/**
 * PassimPay Webhook Callback Handler for WHMCS
 *
 * Receives deposit notifications from PassimPay when a customer completes payment.
 * PassimPay sends a JSON POST with x-signature header for verification.
 *
 * Flow:
 * 1. Verify the gateway module is active
 * 2. Read and parse the JSON webhook body
 * 3. Verify x-signature (HMAC-SHA256) to confirm authenticity
 * 4. Optionally double-check via /v2/orderstatus API
 * 5. Validate invoice ID and check for duplicate transactions
 * 6. Add payment to the invoice if status is "paid"
 * 7. Return HTTP 200 — required by PassimPay (otherwise it retries 2 more times)
 *
 * @version 1.0.4
 * @see https://passimpay.gitbook.io/passimpay-api/webhook
 * @see https://developers.whmcs.com/payment-gateways/callbacks/
 */

// WHMCS core libraries required for gateway callback processing
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

// PassimPay API client for signature verification and status checks
require_once __DIR__ . '/../passimpay/PassimpayMerchantAPI.php';

// Detect module name from this filename (must match the gateway module file)
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch gateway configuration — returns false if module is not active
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    // Module is deactivated in WHMCS — reject all callbacks
    die("Module Not Activated");
}

// --- Read the raw webhook payload ---
// PassimPay sends JSON body with x-signature header (v2 format)
$rawInput = file_get_contents('php://input');
$webhookData = json_decode($rawInput, true);

if (!is_array($webhookData) || empty($webhookData)) {
    // Invalid or empty payload — nothing to process
    logTransaction($gatewayParams['name'], $rawInput, 'Invalid Payload');
    http_response_code(400);
    die('Invalid payload');
}

// --- Extract webhook fields ---
$platformId   = isset($webhookData['platformId']) ? (int) $webhookData['platformId'] : 0;
$paymentId    = isset($webhookData['paymentId']) ? (int) $webhookData['paymentId'] : 0;
$orderId      = isset($webhookData['orderId']) ? $webhookData['orderId'] : '';
$txhash       = isset($webhookData['txhash']) ? $webhookData['txhash'] : '';

// --- Verify x-signature to ensure the webhook is genuine ---
// Without this check, anyone could forge a payment notification
$receivedSignature = '';
$headers = function_exists('getallheaders') ? getallheaders() : array();

// Headers can be case-insensitive; normalize to find x-signature
foreach ($headers as $headerName => $headerValue) {
    if (strtolower($headerName) === 'x-signature') {
        $receivedSignature = $headerValue;
        break;
    }
}

// Fallback: Apache/nginx sometimes prefix with HTTP_ and uppercase
if (empty($receivedSignature) && isset($_SERVER['HTTP_X_SIGNATURE'])) {
    $receivedSignature = $_SERVER['HTTP_X_SIGNATURE'];
}

$secretKey        = trim($gatewayParams['secretKey']);
$configPlatformId = (int) trim($gatewayParams['platformId']);
$debugMode        = !empty($gatewayParams['debugMode']);

$api = new PassimpayMerchantAPI($configPlatformId, $secretKey);

/**
 * Build safe log context (minimal fields only by default).
 *
 * @param array $payload
 * @param string $signature
 * @param bool $debug
 * @return array
 */
function passimpayBuildLogContext(array $payload, $signature, $debug)
{
    $safeContext = array(
        'eventType'   => isset($payload['type']) ? $payload['type'] : '',
        'platformId'  => isset($payload['platformId']) ? (int) $payload['platformId'] : 0,
        'orderId'     => isset($payload['orderId']) ? (string) $payload['orderId'] : '',
        'paymentId'   => isset($payload['paymentId']) ? (int) $payload['paymentId'] : 0,
        'txhash'      => isset($payload['txhash']) ? (string) $payload['txhash'] : '',
    );

    if (!$debug) {
        return $safeContext;
    }

    // Debug mode: include full payload and masked signature for diagnostics.
    $maskedSignature = '';
    if (!empty($signature)) {
        $len = strlen($signature);
        if ($len <= 12) {
            $maskedSignature = str_repeat('*', $len);
        } else {
            $maskedSignature = substr($signature, 0, 6) . str_repeat('*', $len - 12) . substr($signature, -6);
        }
    }

    $safeContext['webhookData'] = $payload;
    $safeContext['signatureMasked'] = $maskedSignature;

    return $safeContext;
}

if (!$api->verifyWebhookSignature($rawInput, $receivedSignature)) {
    // Signature mismatch — possible tampering or misconfiguration
    logTransaction(
        $gatewayParams['name'],
        passimpayBuildLogContext($webhookData, $receivedSignature, $debugMode),
        'Signature Verification Failed'
    );
    http_response_code(403);
    die('Invalid signature');
}

// Ensure webhook is for this WHMCS gateway configuration
if ($platformId > 0 && $platformId !== $configPlatformId) {
    $logData = passimpayBuildLogContext($webhookData, $receivedSignature, $debugMode);
    $logData['webhookPlatformId'] = $platformId;
    $logData['configPlatformId'] = $configPlatformId;
    logTransaction($gatewayParams['name'], $logData, 'Platform ID Mismatch');
    http_response_code(403);
    die('Invalid platform');
}

// --- Validate the invoice ID in WHMCS ---
// checkCbInvoiceID dies with an error if the invoice doesn't exist
$invoiceId = checkCbInvoiceID($orderId, $gatewayParams['name']);

// --- Double-check payment status via API for extra security ---
// The webhook itself doesn't carry a "status" field — it fires on deposit.
// We call /v2/orderstatus to confirm the actual payment state.
$orderStatus = $api->getOrderStatus((string) $orderId);

if ($orderStatus === PassimpayMerchantAPI::PAYMENT_STATUS_COMPLETED) {
    // Payment is fully confirmed — build a unique transaction ID
    // Use txhash if available (blockchain hash), fall back to paymentId
    $transactionId = !empty($txhash) ? $txhash : 'passimpay-' . $paymentId;

    // Prevent duplicate payment recording — dies if transaction already logged
    checkCbTransID($transactionId);

    // Log the successful transaction for admin audit trail
    $logData = passimpayBuildLogContext($webhookData, $receivedSignature, $debugMode);
    $logData['orderStatus'] = $orderStatus;
    $logData['transactionId'] = $transactionId;
    logTransaction($gatewayParams['name'], $logData, 'Success');

    /**
     * Add payment to the WHMCS invoice.
     *
     * We pass the full invoice amount (empty string = auto-detect from WHMCS)
     * because PassimPay confirms the full invoice has been paid via "paid" status.
     * Fee is empty — PassimPay's fee is deducted on their side, not from the customer.
     */
    addInvoicePayment(
        $invoiceId,
        $transactionId,
        '',    // Amount — empty means full invoice balance
        '',    // Fee — no fee passed to customer
        $gatewayModuleName
    );

} elseif ($orderStatus === PassimpayMerchantAPI::PAYMENT_STATUS_PROCESSING) {
    // Partial payment received — log but don't mark as paid yet.
    // PassimPay will send another webhook when full payment is received.
    $logData = passimpayBuildLogContext($webhookData, $receivedSignature, $debugMode);
    $logData['orderStatus'] = $orderStatus;
    $logData['note'] = 'Partial payment — waiting for full amount';
    logTransaction($gatewayParams['name'], $logData, 'Pending');

} else {
    // Status is 'error', 'request_error', or unknown
    $logData = passimpayBuildLogContext($webhookData, $receivedSignature, $debugMode);
    $logData['orderStatus'] = $orderStatus;
    $logData['apiResponse'] = $api->response;
    logTransaction($gatewayParams['name'], $logData, 'Order Status: ' . $orderStatus);
}

// PassimPay requires HTTP 200 response — otherwise it will retry up to 2 times
http_response_code(200);
echo 'OK';
