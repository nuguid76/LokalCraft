<?php
/**
 * LokalCraft Direct QR Ph PayMongo webhook.
 * Event: payment.paid (not checkout_session.payment.paid).
 * Deploy as your existing HTTPS webhook endpoint and subscribe to payment.paid.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/paymongo.php';
require_once __DIR__ . '/../config/notification_events.php';
require_once __DIR__ . '/../config/paymongo_qr_confirmation.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function lokalcraftWebhookRespond(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['success' => $status >= 200 && $status < 300, 'message' => $message]);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    lokalcraftWebhookRespond(405, 'Method not allowed.');
}
$rawBody = file_get_contents('php://input');
if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > 262144) {
    lokalcraftWebhookRespond(400, 'Invalid webhook request body.');
}
$signature = (string)($_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '');
// The verifier checks the HMAC, matching test/live signature, and ±5-minute timestamp.
if (!paymongoVerifyWebhookSignature($rawBody, $signature)) {
    lokalcraftWebhookRespond(401, 'Invalid PayMongo signature.');
}
$payload = json_decode($rawBody, true);
if (!is_array($payload) || !is_array($payload['data']['attributes'] ?? null)) {
    lokalcraftWebhookRespond(400, 'Invalid PayMongo event.');
}
$event = $payload['data']['attributes'];
$eventType = (string)($event['type'] ?? '');
if ($eventType !== 'payment.paid') {
    lokalcraftWebhookRespond(200, 'Event ignored.');
}
if (!array_key_exists('livemode', $event)
    || (bool)$event['livemode'] === paymongoIsTestMode()) {
    lokalcraftWebhookRespond(400, 'Payment event mode mismatch.');
}
$payment = $event['data'] ?? null;
if (!is_array($payment) || ($payment['type'] ?? '') !== 'payment') {
    lokalcraftWebhookRespond(400, 'Invalid payment resource.');
}
$details = $payment['attributes'] ?? null;
if (!is_array($details)) {
    lokalcraftWebhookRespond(400, 'Missing payment attributes.');
}
$paymongoPaymentId = trim((string)($payment['id'] ?? ''));
$paymentIntentId = trim((string)($details['payment_intent_id'] ?? ''));
$amountCentavos = $details['amount'] ?? null;
if (!preg_match('/^pay_[A-Za-z0-9_-]{6,115}$/', $paymongoPaymentId)
    || !preg_match('/^pi_[A-Za-z0-9_-]{6,115}$/', $paymentIntentId)
    || strtolower((string)($details['status'] ?? '')) !== 'paid'
    || strtoupper((string)($details['currency'] ?? '')) !== 'PHP'
    || !is_numeric($amountCentavos) || (int)$amountCentavos <= 0
    || (isset($details['livemode'])
        && (bool)$details['livemode'] === paymongoIsTestMode())) {
    lokalcraftWebhookRespond(400, 'Invalid paid payment details.');
}
$sourceType = strtolower(trim((string)($details['source']['type'] ?? '')));
if ($sourceType !== '' && $sourceType !== 'qrph') {
    lokalcraftWebhookRespond(200, 'Unrelated payment method ignored.');
}
try {
    // Only process the currently registered LokalCraft QR session; do not guess an order ID.
    $stmt = $conn->prepare('SELECT order_id FROM paymongo_qr_sessions
        WHERE payment_intent_id = ? LIMIT 1');
    $stmt->bind_param('s', $paymentIntentId);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$session) lokalcraftWebhookRespond(200, 'No matching LokalCraft QR session.');
    $orderId = (int)$session['order_id'];
    // Independently verify with PayMongo using the secret API key before recording money.
    $intent = paymongoRetrievePaymentIntent($paymentIntentId);
    if ((string)($intent['id'] ?? '') !== $paymentIntentId
        || ($intent['attributes']['status'] ?? '') !== 'succeeded'
        || (int)($intent['attributes']['amount'] ?? -1) !== (int)$amountCentavos
        || lokalcraftQrPaidReference($intent) !== $paymongoPaymentId) {
        throw new RuntimeException('PayMongo payment is not yet consistent with its Payment Intent.');
    }
    // Shared with buyer polling: ONE database transaction, ONE 95%/5% wallet credit.
    lokalcraftConfirmQrFromIntent($conn, $intent, $orderId);
    lokalcraftWebhookRespond(200, 'QR Ph payment confirmed.');
} catch (Throwable $error) {
    // 503 asks PayMongo to retry transient API/DB failures. Do not expose server data.
    error_log('LokalCraft QR Ph webhook: ' . $error->getMessage());
    lokalcraftWebhookRespond(503, 'Payment verification is temporarily unavailable.');
}
