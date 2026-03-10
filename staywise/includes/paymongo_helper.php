<?php
/**
 * PayMongo API Helper
 * 
 * Handles PayMongo API calls for GCash, GrabPay, and card payments.
 * Documentation: https://developers.paymongo.com/reference
 */

class PayMongoHelper {
    private $secret_key;
    private $public_key;
    private $base_url;
    private $api_base = 'https://api.paymongo.com/v1';

    public function __construct($conn = null) {
        // Load settings from database first, fallback to config file
        $this->secret_key = '';
        $this->public_key = '';
        $this->base_url = 'http://localhost/StayWise';

        if ($conn) {
            $this->loadFromDatabase($conn);
        }

        // Fallback to config file if DB settings are empty
        if (empty($this->secret_key)) {
            $config = $this->loadConfig();
            $this->secret_key = $config['secret_key'] ?? '';
            $this->public_key = $config['public_key'] ?? '';
            $this->base_url   = $config['base_url'] ?? 'http://localhost/StayWise';
        }
    }

    private function loadFromDatabase($conn) {
        try {
            $keys = ['paymongo_secret_key', 'paymongo_public_key', 'base_url'];
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $conn->prepare("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ($placeholders)");
            $stmt->bind_param(str_repeat('s', count($keys)), ...$keys);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                switch ($row['setting_key']) {
                    case 'paymongo_secret_key': $this->secret_key = $row['setting_value']; break;
                    case 'paymongo_public_key': $this->public_key = $row['setting_value']; break;
                    case 'base_url': $this->base_url = rtrim($row['setting_value'], '/'); break;
                }
            }
            $stmt->close();
        } catch (Throwable $e) {
            // Silently fall back to config file
        }
    }

    private function loadConfig() {
        $configFile = __DIR__ . '/../config/paymongo.php';
        if (file_exists($configFile)) {
            return require $configFile;
        }
        return [];
    }

    /**
     * Check if PayMongo is properly configured
     */
    public function isConfigured(): bool {
        return !empty($this->secret_key) && strpos($this->secret_key, 'xxxx') === false;
    }

    /**
     * Create a Payment Link for GCash
     * 
     * Uses the PayMongo Links API (simpler than Checkout Sessions, works great for GCash).
     * 
     * @param array $params [
     *   'amount'      => float (in pesos),
     *   'description' => string,
     *   'remarks'     => string (optional metadata),
     * ]
     * @return array ['success' => bool, 'checkout_url' => string, 'link_id' => string]
     */
    public function createPaymentLink(array $params): array {
        $amount = (int)round(($params['amount'] ?? 0) * 100); // centavos
        if ($amount < 10000) { // Minimum ₱100
            return ['success' => false, 'error' => 'Minimum payment amount is ₱100.00'];
        }

        $description = $params['description'] ?? 'StayWise Rent Payment';
        $remarks     = $params['remarks'] ?? '';

        $payload = [
            'data' => [
                'attributes' => [
                    'amount'      => $amount,
                    'description' => $description,
                    'remarks'     => $remarks,
                ]
            ]
        ];

        $response = $this->apiRequest('POST', '/links', $payload);

        if (isset($response['data']['id'])) {
            return [
                'success'      => true,
                'checkout_url' => $response['data']['attributes']['checkout_url'],
                'link_id'      => $response['data']['id'],
            ];
        }

        $errorMsg = 'Failed to create payment link';
        if (isset($response['errors'][0]['detail'])) {
            $errorMsg = $response['errors'][0]['detail'];
        }
        return ['success' => false, 'error' => $errorMsg];
    }

    /**
     * Retrieve a Payment Link by ID
     */
    public function getPaymentLink(string $linkId): array {
        return $this->apiRequest('GET', "/links/{$linkId}");
    }

    /**
     * Check if a Payment Link has been paid (polling fallback)
     * 
     * @return array ['paid' => bool, 'payment_id' => string|null]
     */
    public function checkPaymentLinkStatus(string $linkId): array {
        $response = $this->getPaymentLink($linkId);
        $status = $response['data']['attributes']['status'] ?? 'unknown';
        $payments = $response['data']['attributes']['payments'] ?? [];

        $paymentId = null;
        if (!empty($payments)) {
            $paymentId = $payments[0]['id'] ?? ($payments[0]['data']['id'] ?? null);
        }

        return [
            'paid'       => ($status === 'paid'),
            'status'     => $status,
            'payment_id' => $paymentId,
        ];
    }

    /**
     * Create a Checkout Session for GCash / GrabPay / Card
     * 
     * @param array $params [
     *   'amount'       => float (in PHP/pesos),
     *   'description'  => string,
     *   'payment_method_types' => array (e.g., ['gcash']),
     *   'metadata'     => array (custom data like tenant_id, for_month),
     *   'success_url'  => string (optional),
     *   'cancel_url'   => string (optional),
     * ]
     * @return array ['success' => bool, 'checkout_url' => string, 'checkout_id' => string]
     */
    public function createCheckoutSession(array $params): array {
        $amount = (int)round(($params['amount'] ?? 0) * 100); // PayMongo uses centavos
        if ($amount < 10000) { // Minimum ₱100
            return ['success' => false, 'error' => 'Minimum payment amount is ₱100.00'];
        }

        $paymentMethodTypes = $params['payment_method_types'] ?? ['gcash'];
        $description = $params['description'] ?? 'StayWise Rent Payment';
        $metadata = $params['metadata'] ?? [];

        $successUrl = $params['success_url'] ?? $this->base_url . '/tenant/payments.php?paymongo=success';
        $cancelUrl  = $params['cancel_url']  ?? $this->base_url . '/tenant/payments.php?paymongo=cancelled';

        $payload = [
            'data' => [
                'attributes' => [
                    'line_items' => [
                        [
                            'name'        => $description,
                            'amount'      => $amount,
                            'currency'    => 'PHP',
                            'quantity'    => 1,
                        ]
                    ],
                    'payment_method_types' => $paymentMethodTypes,
                    'success_url'          => $successUrl,
                    'cancel_url'           => $cancelUrl,
                    'description'          => $description,
                    'metadata'             => $metadata,
                ]
            ]
        ];

        $response = $this->apiRequest('POST', '/checkout_sessions', $payload);

        if (isset($response['data']['id'])) {
            return [
                'success'      => true,
                'checkout_url' => $response['data']['attributes']['checkout_url'],
                'checkout_id'  => $response['data']['id'],
            ];
        }

        $errorMsg = 'Failed to create checkout session';
        if (isset($response['errors'][0]['detail'])) {
            $errorMsg = $response['errors'][0]['detail'];
        }
        return ['success' => false, 'error' => $errorMsg];
    }

    /**
     * Retrieve a Checkout Session by ID
     */
    public function getCheckoutSession(string $checkoutId): array {
        return $this->apiRequest('GET', "/checkout_sessions/{$checkoutId}");
    }

    /**
     * Retrieve a Payment by ID
     */
    public function getPayment(string $paymentId): array {
        return $this->apiRequest('GET', "/payments/{$paymentId}");
    }

    /**
     * Verify webhook signature
     */
    public function verifyWebhookSignature(string $payload, string $sigHeader, string $webhookSecret): bool {
        if (empty($webhookSecret) || empty($sigHeader)) {
            return false;
        }

        // PayMongo sends signature in format: t=timestamp,te=test_signature,li=live_signature
        $parts = [];
        foreach (explode(',', $sigHeader) as $part) {
            [$key, $value] = explode('=', $part, 2);
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        // Use 'te' for test mode, 'li' for live mode
        $signature = $parts['te'] ?? $parts['li'] ?? '';

        $signedPayload = $timestamp . '.' . $payload;
        $expectedSignature = hash_hmac('sha256', $signedPayload, $webhookSecret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Make an API request to PayMongo
     */
    private function apiRequest(string $method, string $endpoint, array $data = null): array {
        $url = $this->api_base . $endpoint;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode($this->secret_key . ':'),
            ],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['errors' => [['detail' => 'cURL error: ' . $curlError]]];
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            return ['errors' => [['detail' => 'Invalid JSON response (HTTP ' . $httpCode . ')']]];
        }

        return $decoded;
    }

    /**
     * Get the public key (for frontend use)
     */
    public function getPublicKey(): string {
        return $this->public_key;
    }
}
