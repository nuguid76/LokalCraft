<?php

if (!defined('PAYMONGO_SECRET_KEY')) {
    define(
        'PAYMONGO_SECRET_KEY',
        getenv('PAYMONGO_SECRET_KEY') ?: 'sk_test_mjohEsAdWEs44HTsnzfcc2st'
    );
}
if (!defined('PAYMONGO_PUBLIC_KEY')) {
    define(
        'PAYMONGO_PUBLIC_KEY',
        getenv('PAYMONGO_PUBLIC_KEY') ?: 'pk_test_DMTHXSHD2vkjpnBfYMxiiaJ1'
    );
}
if (!defined('PAYMONGO_WEBHOOK_SECRET')) {
    define(
        'PAYMONGO_WEBHOOK_SECRET',
        getenv('PAYMONGO_WEBHOOK_SECRET') ?: 'whsk_REPLACE_ME'
    );
}
if (!defined('PAYMONGO_API_URL')) {
    define(
        'PAYMONGO_API_URL',
        'https://api.paymongo.com'
    );
}
/*
 * LokalCraft QR Ph rule:
 * Dynamic QR is valid for exactly 15 minutes.
 */
if (!defined('PAYMONGO_QRPH_EXPIRY_SECONDS')) {
    define(
        'PAYMONGO_QRPH_EXPIRY_SECONDS',
        900
    );
}
/*
 * ------------------------------------------------------------
 * Basic configuration helpers
 * ------------------------------------------------------------
 */
function paymongoIsTestMode(): bool
{
    return str_starts_with(
        PAYMONGO_SECRET_KEY,
        'sk_test_'
    );
}
function paymongoPublicKeyIsTestMode(): bool
{
    return str_starts_with(
        PAYMONGO_PUBLIC_KEY,
        'pk_test_'
    );
}
function paymongoKeysLookConfigured(): bool
{
    $secretKey = trim(PAYMONGO_SECRET_KEY);
    $publicKey = trim(PAYMONGO_PUBLIC_KEY);
    $secretIsTest = str_starts_with($secretKey, 'sk_test_');
    $secretIsLive = str_starts_with($secretKey, 'sk_live_');
    $publicIsTest = str_starts_with($publicKey, 'pk_test_');
    $publicIsLive = str_starts_with($publicKey, 'pk_live_');
    return
        $secretKey !== ''
        && $publicKey !== ''
        && !str_contains($secretKey, 'REPLACE_ME')
        && !str_contains($publicKey, 'REPLACE_ME')
        && ($secretIsTest || $secretIsLive)
        && ($publicIsTest || $publicIsLive)
        && (
            ($secretIsTest && $publicIsTest)
            || ($secretIsLive && $publicIsLive)
        );
}
/*
 * ------------------------------------------------------------
 * Generic PayMongo request helper
 * ------------------------------------------------------------
 */
function paymongoRequest(
    string $endpoint,
    array $data = [],
    string $method = 'POST',
    ?string $apiKey = null,
    array $extraHeaders = []
): array {
    $key = $apiKey ?? PAYMONGO_SECRET_KEY;
    if (trim($key) === '') {
        return [
            'success' => false,
            'status' => 0,
            'error' => 'PayMongo API key is missing.',
            'data' => null
        ];
    }
    $curl = curl_init();
    if ($curl === false) {
        return [
            'success' => false,
            'status' => 0,
            'error' =>
                'Unable to initialize the PayMongo request.',
            'data' => null
        ];
    }
    $headers = array_merge(
        [
            'Authorization: Basic '
                . base64_encode($key . ':'),
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        $extraHeaders
    );
    curl_setopt_array(
        $curl,
        [
            CURLOPT_URL =>
                rtrim(PAYMONGO_API_URL, '/')
                . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST =>
                strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30
        ]
    );
    if (
        strtoupper($method) !== 'GET'
        && !empty($data)
    ) {
        $encoded = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        );
        if ($encoded === false) {
            curl_close($curl);
            return [
                'success' => false,
                'status' => 0,
                'error' =>
                    'Unable to encode the PayMongo request.',
                'data' => null
            ];
        }
        curl_setopt(
            $curl,
            CURLOPT_POSTFIELDS,
            $encoded
        );
    }
    $response = curl_exec($curl);
    $httpCode = (int)curl_getinfo(
        $curl,
        CURLINFO_HTTP_CODE
    );
    $curlError = curl_error($curl);
    curl_close($curl);
    if (
        $response === false
        || $curlError !== ''
    ) {
        return [
            'success' => false,
            'status' => 0,
            'error' =>
                $curlError !== ''
                    ? $curlError
                    : 'PayMongo did not return a response.',
            'data' => null
        ];
    }
    $decoded = json_decode(
        (string)$response,
        true
    );
    if (!is_array($decoded)) {
        return [
            'success' => false,
            'status' => $httpCode,
            'error' =>
                'PayMongo returned an invalid JSON response.',
            'data' => null
        ];
    }
    $success =
        $httpCode >= 200
        && $httpCode < 300;
    $errorMessage = null;
    if (!$success) {
        $errorMessage = trim(
            (string)(
                $decoded['errors'][0]['detail']
                ?? $decoded['errors'][0]['code']
                ?? 'PayMongo request failed.'
            )
        );
    }
    return [
        'success' => $success,
        'status' => $httpCode,
        'error' => $errorMessage,
        'data' => $decoded
    ];
}
/*
 * ------------------------------------------------------------
 * Direct Dynamic QR Ph flow
 * ------------------------------------------------------------
 *
 * Flow:
 * 1. Create Payment Intent using SECRET key.
 * 2. Create QR Ph Payment Method using PUBLIC key.
 *    expiry_seconds = 900 (15 minutes).
 * 3. Attach Payment Method using PUBLIC key + client_key.
 * 4. Display next_action.code.image_url inside LokalCraft.
 *
 * The QR is single-use.
 */
function paymongoCreateQrPaymentIntent(
    int $amountCentavos,
    string $description,
    array $metadata = [],
    ?string $idempotencyKey = null
): array {
    if ($amountCentavos < 100) {
        throw new InvalidArgumentException(
            'QR Ph payment must be at least PHP 1.00.'
        );
    }
    $attributes = [
        'amount' => $amountCentavos,
        'currency' => 'PHP',
        'payment_method_allowed' => [
            'qrph'
        ],
        'description' => mb_substr(
            trim($description),
            0,
            255
        )
    ];
    if ($metadata) {
        $attributes['metadata'] = $metadata;
    }
    $headers = [];
    if (
        $idempotencyKey !== null
        && trim($idempotencyKey) !== ''
    ) {
        $headers[] =
            'Idempotency-Key: '
            . trim($idempotencyKey);
    }
    $result = paymongoRequest(
        '/v1/payment_intents',
        [
            'data' => [
                'attributes' => $attributes
            ]
        ],
        'POST',
        PAYMONGO_SECRET_KEY,
        $headers
    );
    if (!$result['success']) {
        throw new RuntimeException(
            'Unable to create QR Ph payment: '
            . ($result['error']
                ?: 'Unknown PayMongo error.')
        );
    }
    $resource = $result['data']['data'] ?? null;
    if (!is_array($resource)) {
        throw new RuntimeException(
            'PayMongo returned an invalid Payment Intent.'
        );
    }
    return $resource;
}
function paymongoCreateQrPaymentMethod(
    array $billing = []
): array {
    $attributes = [
        'type' => 'qrph',
        'expiry_seconds' =>
            PAYMONGO_QRPH_EXPIRY_SECONDS
    ];
    /*
     * Billing details are optional for QR Ph.
     * Only send fields that actually contain a value.
     */
    $cleanBilling = [];
    foreach (
        ['name', 'email', 'phone']
        as $field
    ) {
        $value = trim(
            (string)($billing[$field] ?? '')
        );
        if ($value !== '') {
            $cleanBilling[$field] = $value;
        }
    }
    if ($cleanBilling) {
        $attributes['billing'] = $cleanBilling;
    }
    $result = paymongoRequest(
        '/v1/payment_methods',
        [
            'data' => [
                'attributes' => $attributes
            ]
        ],
        'POST',
        PAYMONGO_PUBLIC_KEY
    );
    if (!$result['success']) {
        throw new RuntimeException(
            'Unable to generate the QR Ph payment method: '
            . ($result['error']
                ?: 'Unknown PayMongo error.')
        );
    }
    $resource = $result['data']['data'] ?? null;
    if (!is_array($resource)) {
        throw new RuntimeException(
            'PayMongo returned an invalid Payment Method.'
        );
    }
    return $resource;
}
function paymongoAttachQrPaymentMethod(
    string $paymentIntentId,
    string $paymentMethodId,
    string $clientKey
): array {
    $paymentIntentId = trim(
        $paymentIntentId
    );
    $paymentMethodId = trim(
        $paymentMethodId
    );
    $clientKey = trim(
        $clientKey
    );
    if (
        $paymentIntentId === ''
        || $paymentMethodId === ''
        || $clientKey === ''
    ) {
        throw new InvalidArgumentException(
            'Missing PayMongo QR attachment data.'
        );
    }
    $result = paymongoRequest(
        '/v1/payment_intents/'
            . rawurlencode($paymentIntentId)
            . '/attach',
        [
            'data' => [
                'attributes' => [
                    'payment_method' =>
                        $paymentMethodId,
                    'client_key' =>
                        $clientKey
                ]
            ]
        ],
        'POST',
        PAYMONGO_PUBLIC_KEY
    );
    if (!$result['success']) {
        throw new RuntimeException(
            'Unable to activate the QR Ph code: '
            . ($result['error']
                ?: 'Unknown PayMongo error.')
        );
    }
    $resource = $result['data']['data'] ?? null;
    if (!is_array($resource)) {
        throw new RuntimeException(
            'PayMongo returned an invalid QR response.'
        );
    }
    return $resource;
}
/*
 * Find PayMongo's Test Mode simulator URL without depending on
 * one hard-coded nesting path. PayMongo exposes "test_url" in
 * the QR Ph test response; the exact nested location may vary
 * with the returned resource shape.
 */
function paymongoFindTestUrl(array $resource): string
{
    foreach ($resource as $key => $value) {
        if (
            is_string($key)
            && strtolower($key) === 'test_url'
            && is_string($value)
            && trim($value) !== ''
        ) {
            return trim($value);
        }
        if (is_array($value)) {
            $found = paymongoFindTestUrl($value);
            if ($found !== '') {
                return $found;
            }
        }
    }
    return '';
}
function paymongoGenerateQrPh(
    int $amountCentavos,
    string $description,
    array $metadata = [],
    array $billing = [],
    ?string $idempotencyKey = null
): array {
    if (!paymongoKeysLookConfigured()) {
        throw new RuntimeException(
            'PayMongo API keys are not configured.'
        );
    }
    $intent = paymongoCreateQrPaymentIntent(
        $amountCentavos,
        $description,
        $metadata,
        $idempotencyKey
    );
    $intentId = trim(
        (string)($intent['id'] ?? '')
    );
    $clientKey = trim(
        (string)(
            $intent['attributes']['client_key']
            ?? ''
        )
    );
    if (
        $intentId === ''
        || $clientKey === ''
    ) {
        throw new RuntimeException(
            'PayMongo did not return the Payment Intent credentials.'
        );
    }
    $paymentMethod =
        paymongoCreateQrPaymentMethod(
            $billing
        );
    $paymentMethodId = trim(
        (string)($paymentMethod['id'] ?? '')
    );
    if ($paymentMethodId === '') {
        throw new RuntimeException(
            'PayMongo did not return a Payment Method ID.'
        );
    }
    $attached =
        paymongoAttachQrPaymentMethod(
            $intentId,
            $paymentMethodId,
            $clientKey
        );
    $qrImage = trim(
        (string)(
            $attached['attributes']['next_action']
                ['code']['image_url']
            ?? ''
        )
    );
    /*
     * In PayMongo Test Mode, the QR response may include a
     * test_url that opens PayMongo's payment simulator.
     * Never expose a simulator URL when using live keys.
     */
    $testUrl = '';
    if (paymongoIsTestMode()) {
        $testUrl = paymongoFindTestUrl($attached);
        if ($testUrl === '') {
            $testUrl = paymongoFindTestUrl($paymentMethod);
        }
        if ($testUrl === '') {
            $testUrl = paymongoFindTestUrl($intent);
        }
    }
    if ($qrImage === '') {
        throw new RuntimeException(
            'PayMongo did not return a QR image.'
        );
    }
    /*
     * Use Payment Method created_at when available.
     * Otherwise use the local generation time.
     */
    $createdAt = (int)(
        $paymentMethod['attributes']['created_at']
        ?? time()
    );
    if ($createdAt <= 0) {
        $createdAt = time();
    }
    $expiresAt =
        $createdAt
        + PAYMONGO_QRPH_EXPIRY_SECONDS;
    return [
        'payment_intent_id' => $intentId,
        'payment_method_id' => $paymentMethodId,
        'client_key' => $clientKey,
        'qr_image' => $qrImage,
        'test_url' => $testUrl,
        'test_mode' => paymongoIsTestMode(),
        'status' => (string)(
            $attached['attributes']['status']
            ?? 'awaiting_next_action'
        ),
        'created_at' => $createdAt,
        'expires_at' => $expiresAt,
        'expiry_seconds' =>
            PAYMONGO_QRPH_EXPIRY_SECONDS
    ];
}
/*
 * Retrieve Payment Intent server-side.
 *
 * Use this for:
 * - checking payment state after returning to the page
 * - recovering after refresh/back
 * - backup polling
 *
 * PaymentIntent status is verified server-side; webhook verification remains available when configured.
 */
function paymongoRetrievePaymentIntent(
    string $paymentIntentId
): array {
    $paymentIntentId = trim(
        $paymentIntentId
    );
    if ($paymentIntentId === '') {
        throw new InvalidArgumentException(
            'Payment Intent ID is required.'
        );
    }
    $result = paymongoRequest(
        '/v1/payment_intents/'
            . rawurlencode($paymentIntentId),
        [],
        'GET',
        PAYMONGO_SECRET_KEY
    );
    if (!$result['success']) {
        throw new RuntimeException(
            'Unable to check PayMongo payment: '
            . ($result['error']
                ?: 'Unknown PayMongo error.')
        );
    }
    $resource = $result['data']['data'] ?? null;
    if (!is_array($resource)) {
        throw new RuntimeException(
            'PayMongo returned an invalid Payment Intent.'
        );
    }
    return $resource;
}
/*
 * ------------------------------------------------------------
 * QR expiration helpers
 * ------------------------------------------------------------
 */
function paymongoQrExpiresAt(
    ?int $generatedAt = null
): int {
    $generatedAt =
        $generatedAt !== null
        && $generatedAt > 0
            ? $generatedAt
            : time();
    return
        $generatedAt
        + PAYMONGO_QRPH_EXPIRY_SECONDS;
}
function paymongoQrIsExpired(
    int $expiresAt
): bool {
    return $expiresAt <= time();
}
/*
 * ------------------------------------------------------------
 * Webhook signature verification
 * ------------------------------------------------------------
 *
 * PayMongo header format:
 * t=<timestamp>,te=<test signature>,li=<live signature>
 *
 * Verify the RAW request body before json_decode().
 */
function paymongoVerifyWebhookSignature(
    string $rawBody,
    string $signatureHeader,
    int $toleranceSeconds = 300
): bool {
    $rawBody = (string)$rawBody;
    $signatureHeader = trim(
        $signatureHeader
    );
    if (
        PAYMONGO_WEBHOOK_SECRET === ''
        || str_contains(
            PAYMONGO_WEBHOOK_SECRET,
            'REPLACE_ME'
        )
        || $signatureHeader === ''
    ) {
        return false;
    }
    $parts = [];
    foreach (
        explode(',', $signatureHeader)
        as $piece
    ) {
        $piece = trim($piece);
        if (!str_contains($piece, '=')) {
            continue;
        }
        [$key, $value] = array_map(
            'trim',
            explode('=', $piece, 2)
        );
        $parts[$key] = $value;
    }
    $timestamp = (int)(
        $parts['t'] ?? 0
    );
    if ($timestamp <= 0) {
        return false;
    }
    if (
        $toleranceSeconds > 0
        && abs(time() - $timestamp)
            > $toleranceSeconds
    ) {
        return false;
    }
    $signatureKey =
        paymongoIsTestMode()
            ? 'te'
            : 'li';
    $provided = trim(
        (string)(
            $parts[$signatureKey]
            ?? ''
        )
    );
    if ($provided === '') {
        return false;
    }
    $signedPayload =
        $timestamp
        . '.'
        . $rawBody;
    $expected = hash_hmac(
        'sha256',
        $signedPayload,
        PAYMONGO_WEBHOOK_SECRET
    );
    return hash_equals(
        $expected,
        $provided
    );
}
