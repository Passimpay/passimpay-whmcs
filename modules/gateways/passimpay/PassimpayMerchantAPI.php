<?php
/**
 * PassimPay Merchant API Client
 *
 * Handles all communication with the PassimPay v2 API:
 * - Creating invoice links (createorder)
 * - Checking order/invoice status (orderstatus)
 * - Verifying webhook signatures
 *
 * Signature format (v2): HMAC-SHA256 of "{platformId};{jsonBody};{secretKey}"
 * sent as x-signature header on every request.
 *
 * @see https://passimpay.gitbook.io/passimpay-api
 * @version 1.0.4
 */

class PassimpayMerchantAPI
{
    /** @var string Payment fully received */
    const PAYMENT_STATUS_COMPLETED = 'paid';

    /** @var string Invoice cancelled or expired */
    const PAYMENT_STATUS_ERROR = 'error';

    /** @var string Awaiting payment or partial payment received */
    const PAYMENT_STATUS_PROCESSING = 'wait';

    /** @var string Base URL for all PassimPay API endpoints */
    private $_apiUrl;

    /** @var int Platform ID from PassimPay dashboard */
    private $_platformId;

    /** @var string Secret API key from PassimPay dashboard */
    private $_secretKey;

    /** @var string Last error message, empty if no error */
    private $_error;

    /** @var string Raw JSON response from the last API call */
    private $_response;

    /** @var string Payment URL returned by createorder endpoint */
    private $_paymentUrl;

    /**
     * @param int    $platformId  Platform ID from PassimPay dashboard
     * @param string $secretKey   API secret key from PassimPay dashboard
     */
    public function __construct($platformId, $secretKey)
    {
        $this->_apiUrl = 'https://api.passimpay.io';
        $this->_platformId = (int) $platformId;
        $this->_secretKey = $secretKey;
        $this->_error = '';
        $this->_response = '';
        $this->_paymentUrl = '';
    }

    /**
     * Magic getter — provides access to error, paymentUrl, response,
     * and any top-level field from the last JSON response.
     *
     * @param string $name Property name
     * @return mixed|false
     */
    public function __get($name)
    {
        switch ($name) {
            case 'error':
                return $this->_error;
            case 'paymentUrl':
                return $this->_paymentUrl;
            case 'response':
                return $this->_response;
            default:
                // Allow reading any field from the last API response
                if ($this->_response) {
                    $json = json_decode($this->_response, true);
                    if (is_array($json) && isset($json[$name])) {
                        return $json[$name];
                    }
                }
                return false;
        }
    }

    /**
     * Create an invoice link via /v2/createorder.
     *
     * The customer will be redirected to the returned URL to complete payment.
     * On success, $this->paymentUrl contains the redirect URL.
     *
     * @param array $args {
     *     @type string $order_id   Unique order/invoice identifier (max 64 chars)
     *     @type string $amount     Amount with 2 decimal places, e.g. "50.00"
     *     @type string $symbol     ISO 4217 currency code, e.g. "USD" (optional, default USD)
     *     @type int    $type       0=all, 1=crypto only, 2=fiat only (optional)
     *     @type string $currencies Comma-separated currency IDs to display (optional)
     *     @type string $firstName  Client first name (optional)
     *     @type string $lastName   Client last name (optional)
     *     @type string $email      Client email (optional)
     * }
     * @return self For method chaining / property access
     */
    public function createOrder(array $args)
    {
        $url = $this->_apiUrl . '/v2/createorder';

        $body = array(
            'platformId' => $this->_platformId,
            'orderId'    => (string) $args['order_id'],
            'amount'     => (string) $args['amount'],
        );

        // Currency symbol (ISO 4217) — defaults to USD on API side if omitted
        if (!empty($args['symbol'])) {
            $body['symbol'] = strtoupper($args['symbol']);
        }

        // Filter which currency types are shown on the payment page
        if (isset($args['type'])) {
            $body['type'] = (int) $args['type'];
        }

        // Restrict to specific cryptocurrency IDs
        if (!empty($args['currencies'])) {
            $body['currencies'] = $args['currencies'];
        }

        // Optional client information — helps PassimPay with fraud prevention
        if (!empty($args['firstName'])) {
            $body['firstName'] = $args['firstName'];
        }
        if (!empty($args['lastName'])) {
            $body['lastName'] = $args['lastName'];
        }
        if (!empty($args['email'])) {
            $body['email'] = $args['email'];
        }

        return $this->_sendV2Request($url, $body);
    }

    /**
     * Check invoice/order payment status via /v2/orderstatus.
     *
     * @param string $orderId The order ID previously used in createOrder
     * @return string One of: 'paid', 'wait', 'error', or 'request_error'
     */
    public function getOrderStatus($orderId)
    {
        $url = $this->_apiUrl . '/v2/orderstatus';

        $body = array(
            'platformId' => $this->_platformId,
            'orderId'    => (string) $orderId,
        );

        $this->_sendV2Request($url, $body);

        $json = json_decode($this->_response, true);

        if (is_array($json) && isset($json['result']) && (int) $json['result'] === 1) {
            return isset($json['status']) ? $json['status'] : 'error';
        }

        return 'request_error';
    }

    /**
     * Convenience check: returns true only if order is fully paid.
     *
     * @param string $orderId
     * @return bool
     */
    public function orderStatusIsCompleted($orderId)
    {
        return $this->getOrderStatus($orderId) === self::PAYMENT_STATUS_COMPLETED;
    }

    /**
     * Verify webhook signature (v2 format).
     *
     * PassimPay sends JSON body + x-signature header on webhook callbacks.
     * Signature = HMAC-SHA256 of "{platformId};{rawJsonBody};{secretKey}"
     *
     * @param string $rawJsonBody  Raw JSON string from php://input
     * @param string $receivedSignature  Value of x-signature header
     * @return bool True if signature is valid
     */
    public function verifyWebhookSignature($rawJsonBody, $receivedSignature)
    {
        if (empty($receivedSignature) || empty($rawJsonBody)) {
            return false;
        }

        $signatureContract = $this->_platformId . ';' . $rawJsonBody . ';' . $this->_secretKey;
        $calculatedSignature = hash_hmac('sha256', $signatureContract, $this->_secretKey);

        // Timing-safe comparison to prevent timing attacks
        return hash_equals($calculatedSignature, $receivedSignature);
    }

    /**
     * Send a signed request to the PassimPay v2 API.
     *
     * Signature format: HMAC-SHA256("{platformId};{jsonBody};{secretKey}", secretKey)
     * Sent as x-signature header alongside Content-Type: application/json.
     *
     * @param string $url  Full endpoint URL
     * @param array  $body Request body as associative array
     * @return self For method chaining
     */
    private function _sendV2Request($url, array $body)
    {
        $this->_error = '';
        $this->_paymentUrl = '';

        $jsonBody = json_encode($body, JSON_UNESCAPED_SLASHES);

        // v2 signature: "{platformId};{jsonBody};{secretKey}" → HMAC-SHA256
        $signatureContract = $this->_platformId . ';' . $jsonBody . ';' . $this->_secretKey;
        $signature = hash_hmac('sha256', $signatureContract, $this->_secretKey);

        $headers = array(
            'Content-Type: application/json',
            'x-signature: ' . $signature,
        );

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $this->_error = 'cURL Error: ' . curl_error($ch);
            curl_close($ch);
            return $this;
        }

        curl_close($ch);

        $this->_response = $response;
        $json = json_decode($response, true);

        if (!is_array($json)) {
            $this->_error = 'Invalid JSON response from PassimPay API';
            return $this;
        }

        if (isset($json['result']) && (int) $json['result'] === 1) {
            // Success — extract payment URL if present (createorder response)
            if (isset($json['url'])) {
                $this->_paymentUrl = $json['url'];
            }
        } else {
            // API returned an error
            $this->_error = isset($json['message']) ? $json['message'] : 'Unknown PassimPay API error';
        }

        return $this;
    }
}
