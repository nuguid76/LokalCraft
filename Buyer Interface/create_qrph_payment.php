<?php
require_once __DIR__ . '/buyer_auth.php';
require_once __DIR__ . '/../config/paymongo.php';
header('Content-Type: application/json; charset=utf-8');
function qrJson(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'message' => $message
            ],
            $extra
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}
function qrKey($value): string
{
    return strtolower(trim((string)$value));
}
function qrLoadOrder(mysqli $conn, int $orderId, int $buyerId): ?array
{
    $stmt = $conn->prepare('
        SELECT
            o.order_id,
            o.buyer_id,
            o.seller_id,
            o.total_amount,
            o.order_status,
            oa.payment_option,
            oa.arrangement_status
        FROM orders o
        LEFT JOIN order_arrangements oa
            ON oa.order_id = o.order_id
        WHERE o.order_id = ?
          AND o.buyer_id = ?
        LIMIT 1
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to load the order.');
    }
    $stmt->bind_param('ii', $orderId, $buyerId);
    $stmt->execute();
    $stmt->bind_result(
        $dbOrderId,
        $dbBuyerId,
        $sellerId,
        $totalAmount,
        $orderStatus,
        $paymentOption,
        $arrangementStatus
    );
    if (!$stmt->fetch()) {
        $stmt->close();
        return null;
    }
    $stmt->close();
    return [
        'order_id' => (int)$dbOrderId,
        'buyer_id' => (int)$dbBuyerId,
        'seller_id' => (int)$sellerId,
        'total_amount' => (float)$totalAmount,
        'order_status' => (string)$orderStatus,
        'payment_option' => (string)($paymentOption ?? ''),
        'arrangement_status' => (string)($arrangementStatus ?? '')
    ];
}
function qrLoadLatestPayment(mysqli $conn, int $orderId): ?array
{
    $stmt = $conn->prepare('
        SELECT
            payment_id,
            buyer_id,
            amount,
            payment_method,
            payment_provider,
            payment_status,
            paid_at,
            paymongo_payment_intent_id,
            paymongo_payment_id
        FROM payments
        WHERE order_id = ?
        ORDER BY payment_id DESC
        LIMIT 1
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to load the payment record.');
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $stmt->bind_result(
        $paymentId,
        $paymentBuyerId,
        $amount,
        $paymentMethod,
        $paymentProvider,
        $paymentStatus,
        $paidAt,
        $paymentIntentId,
        $paymongoPaymentId
    );
    if (!$stmt->fetch()) {
        $stmt->close();
        return null;
    }
    $stmt->close();
    return [
        'payment_id' => (int)$paymentId,
        'buyer_id' => (int)$paymentBuyerId,
        'amount' => (float)$amount,
        'payment_method' => (string)$paymentMethod,
        'payment_provider' => (string)$paymentProvider,
        'payment_status' => (string)$paymentStatus,
        'paid_at' => (string)($paidAt ?? ''),
        'paymongo_payment_intent_id' => (string)($paymentIntentId ?? ''),
        'paymongo_payment_id' => (string)($paymongoPaymentId ?? '')
    ];
}
function qrEnsurePendingPayment(
    mysqli $conn,
    int $orderId,
    int $buyerId,
    float $amount
): int {
    $existing = qrLoadLatestPayment($conn, $orderId);
    $method = 'QR Ph';
    $provider = 'paymongo';
    $status = 'pending';
    if ($existing) {
        if (qrKey($existing['payment_status']) === 'paid') {
            return (int)$existing['payment_id'];
        }
        $paymentId = (int)$existing['payment_id'];
        $stmt = $conn->prepare('
            UPDATE payments
            SET
                buyer_id = ?,
                amount = ?,
                payment_method = ?,
                payment_provider = ?,
                payment_status = ?,
                paid_at = NULL
            WHERE payment_id = ?
              AND order_id = ?
        ');
        if (!$stmt) {
            throw new RuntimeException('Unable to update the payment record.');
        }
        $stmt->bind_param(
            'idsssii',
            $buyerId,
            $amount,
            $method,
            $provider,
            $status,
            $paymentId,
            $orderId
        );
        $stmt->execute();
        $stmt->close();
        return $paymentId;
    }
    $stmt = $conn->prepare('
        INSERT INTO payments
            (
                order_id,
                buyer_id,
                amount,
                payment_method,
                payment_provider,
                payment_status,
                created_at
            )
        VALUES
            (?, ?, ?, ?, ?, ?, NOW())
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to create the payment record.');
    }
    $stmt->bind_param(
        'iidsss',
        $orderId,
        $buyerId,
        $amount,
        $method,
        $provider,
        $status
    );
    $stmt->execute();
    $paymentId = (int)$stmt->insert_id;
    $stmt->close();
    return $paymentId;
}
function qrLoadSession(
    mysqli $conn,
    int $orderId,
    int $buyerId
): ?array {
    $stmt = $conn->prepare('
        SELECT
            qr_session_id,
            seller_id,
            payment_id,
            payment_intent_id,
            payment_method_id,
            qr_image,
            test_url,
            qr_status,
            generated_at,
            expires_at,
            paid_at
        FROM paymongo_qr_sessions
        WHERE order_id = ?
          AND buyer_id = ?
        LIMIT 1
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to load the QR Ph session.');
    }
    $stmt->bind_param('ii', $orderId, $buyerId);
    $stmt->execute();
    $stmt->bind_result(
        $qrSessionId,
        $sellerId,
        $paymentId,
        $paymentIntentId,
        $paymentMethodId,
        $qrImage,
        $testUrl,
        $qrStatus,
        $generatedAt,
        $expiresAt,
        $paidAt
    );
    if (!$stmt->fetch()) {
        $stmt->close();
        return null;
    }
    $stmt->close();
    return [
        'qr_session_id' => (int)$qrSessionId,
        'order_id' => $orderId,
        'buyer_id' => $buyerId,
        'seller_id' => (int)$sellerId,
        'payment_id' => (int)($paymentId ?? 0),
        'payment_intent_id' => (string)$paymentIntentId,
        'payment_method_id' => (string)$paymentMethodId,
        'qr_image' => (string)$qrImage,
        'test_url' => (string)($testUrl ?? ''),
        'qr_status' => (string)$qrStatus,
        'generated_at' => (string)$generatedAt,
        'expires_at' => (string)$expiresAt,
        'paid_at' => (string)($paidAt ?? ''),
        'expires_at_unix' => strtotime((string)$expiresAt) ?: 0
    ];
}
function qrSaveSession(
    mysqli $conn,
    array $order,
    int $paymentId,
    array $qr
): void {
    $generatedAt = date('Y-m-d H:i:s', (int)$qr['created_at']);
    $expiresAt = date('Y-m-d H:i:s', (int)$qr['expires_at']);
    $intentId = trim((string)$qr['payment_intent_id']);
    $methodId = trim((string)$qr['payment_method_id']);
    $qrImage = trim((string)$qr['qr_image']);
    $testUrl = trim((string)($qr['test_url'] ?? ''));
    $status = 'active';
    $stmt = $conn->prepare('
        INSERT INTO paymongo_qr_sessions
            (
                order_id,
                buyer_id,
                seller_id,
                payment_id,
                payment_intent_id,
                payment_method_id,
                qr_image,
                test_url,
                qr_status,
                generated_at,
                expires_at,
                paid_at
            )
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
        ON DUPLICATE KEY UPDATE
            buyer_id = VALUES(buyer_id),
            seller_id = VALUES(seller_id),
            payment_id = VALUES(payment_id),
            payment_intent_id = VALUES(payment_intent_id),
            payment_method_id = VALUES(payment_method_id),
            qr_image = VALUES(qr_image),
            test_url = VALUES(test_url),
            qr_status = VALUES(qr_status),
            generated_at = VALUES(generated_at),
            expires_at = VALUES(expires_at),
            paid_at = NULL,
            updated_at = NOW()
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to save the QR Ph session.');
    }
    $orderId = (int)$order['order_id'];
    $buyerId = (int)$order['buyer_id'];
    $sellerId = (int)$order['seller_id'];
    $stmt->bind_param(
        'iiiisssssss',
        $orderId,
        $buyerId,
        $sellerId,
        $paymentId,
        $intentId,
        $methodId,
        $qrImage,
        $testUrl,
        $status,
        $generatedAt,
        $expiresAt
    );
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare('
        UPDATE payments
        SET paymongo_payment_intent_id = ?
        WHERE payment_id = ?
          AND order_id = ?
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to save the PayMongo reference.');
    }
    $stmt->bind_param('sii', $intentId, $paymentId, $orderId);
    $stmt->execute();
    $stmt->close();
}
function qrSetSessionStatus(
    mysqli $conn,
    int $orderId,
    int $buyerId,
    string $status
): void {
    $stmt = $conn->prepare('
        UPDATE paymongo_qr_sessions
        SET qr_status = ?,
            updated_at = NOW()
        WHERE order_id = ?
          AND buyer_id = ?
          AND qr_status <> "paid"
    ');
    if ($stmt) {
        $stmt->bind_param('sii', $status, $orderId, $buyerId);
        $stmt->execute();
        $stmt->close();
    }
}
function qrGetAdminId(mysqli $conn): int
{
    $stmt = $conn->prepare('
        SELECT id
        FROM users
        WHERE adminOrNot = 1
          AND account_status = "active"
        ORDER BY id ASC
        LIMIT 1
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to find the admin wallet.');
    }
    $stmt->execute();
    $stmt->bind_result($adminId);
    if (!$stmt->fetch()) {
        $stmt->close();
        throw new RuntimeException('No active admin account was found.');
    }
    $stmt->close();
    return (int)$adminId;
}
function qrCreditWallet(
    mysqli $conn,
    int $userId,
    string $walletType,
    int $orderId,
    int $paymentId,
    string $category,
    float $amount,
    string $referenceKey,
    string $description
): void {
    if ($amount <= 0) {
        return;
    }
    $stmt = $conn->prepare('
        INSERT INTO wallet_accounts
            (user_id, wallet_type, balance, status, created_at, updated_at)
        VALUES
            (?, ?, 0.00, "active", NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            updated_at = updated_at
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the internal wallet.');
    }
    $stmt->bind_param('is', $userId, $walletType);
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare('
        SELECT wallet_id, balance
        FROM wallet_accounts
        WHERE user_id = ?
          AND wallet_type = ?
        LIMIT 1
        FOR UPDATE
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to open the internal wallet.');
    }
    $stmt->bind_param('is', $userId, $walletType);
    $stmt->execute();
    $stmt->bind_result($walletId, $balance);
    if (!$stmt->fetch()) {
        $stmt->close();
        throw new RuntimeException('Internal wallet was not found.');
    }
    $stmt->close();
    $stmt = $conn->prepare('
        SELECT wallet_transaction_id
        FROM wallet_transactions
        WHERE reference_key = ?
        LIMIT 1
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to check the wallet transaction.');
    }
    $stmt->bind_param('s', $referenceKey);
    $stmt->execute();
    $stmt->bind_result($existingTransactionId);
    $exists = $stmt->fetch();
    $stmt->close();
    if ($exists) {
        return;
    }
    $newBalance = round((float)$balance + $amount, 2);
    $stmt = $conn->prepare('
        UPDATE wallet_accounts
        SET balance = ?,
            updated_at = NOW()
        WHERE wallet_id = ?
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to update the wallet balance.');
    }
    $walletId = (int)$walletId;
    $stmt->bind_param('di', $newBalance, $walletId);
    $stmt->execute();
    $stmt->close();
    $direction = 'credit';
    $stmt = $conn->prepare('
        INSERT INTO wallet_transactions
            (
                wallet_id,
                order_id,
                payment_id,
                transaction_category,
                direction,
                amount,
                balance_after,
                reference_key,
                description,
                created_at
            )
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to save the wallet transaction.');
    }
    $stmt->bind_param(
        'iiissddss',
        $walletId,
        $orderId,
        $paymentId,
        $category,
        $direction,
        $amount,
        $newBalance,
        $referenceKey,
        $description
    );
    $stmt->execute();
    $stmt->close();
}
function qrRecordShares(
    mysqli $conn,
    int $sellerId,
    int $orderId,
    int $paymentId,
    float $grossAmount,
    string $paymentIntentId
): void {
    $grossAmount = round($grossAmount, 2);
    if ($grossAmount <= 0) {
        throw new RuntimeException('The paid amount is invalid.');
    }
    $commissionRate = 5.00;
    $platformAmount = round($grossAmount * 0.05, 2);
    $sellerAmount = round($grossAmount - $platformAmount, 2);
    $collectionMethod = 'split_payment';
    $commissionStatus = 'earned';
    $stmt = $conn->prepare('
        INSERT INTO platform_commissions
            (
                order_id,
                seller_id,
                gross_amount,
                commission_rate,
                commission_amount,
                seller_net_amount,
                collection_method,
                commission_status,
                paymongo_reference,
                earned_at,
                created_at,
                updated_at
            )
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            seller_id = VALUES(seller_id),
            gross_amount = VALUES(gross_amount),
            commission_rate = VALUES(commission_rate),
            commission_amount = VALUES(commission_amount),
            seller_net_amount = VALUES(seller_net_amount),
            collection_method = VALUES(collection_method),
            commission_status = VALUES(commission_status),
            paymongo_reference = VALUES(paymongo_reference),
            earned_at = COALESCE(earned_at, NOW()),
            updated_at = NOW()
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to save the platform fee.');
    }
    $stmt->bind_param(
        'iiddddsss',
        $orderId,
        $sellerId,
        $grossAmount,
        $commissionRate,
        $platformAmount,
        $sellerAmount,
        $collectionMethod,
        $commissionStatus,
        $paymentIntentId
    );
    $stmt->execute();
    $stmt->close();
    $adminId = qrGetAdminId($conn);
    qrCreditWallet(
        $conn,
        $sellerId,
        'seller',
        $orderId,
        $paymentId,
        'seller_share',
        $sellerAmount,
        'qr-seller-share-order-' . $orderId,
        'Seller share for Order #' . $orderId
    );
    qrCreditWallet(
        $conn,
        $adminId,
        'admin',
        $orderId,
        $paymentId,
        'platform_fee',
        $platformAmount,
        'qr-platform-fee-order-' . $orderId,
        '5% platform fee for Order #' . $orderId
    );
}
function qrFinalizePaid(
    mysqli $conn,
    array $order,
    int $paymentId,
    string $paymentIntentId
): void {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('
            SELECT
                pay.payment_status,
                pay.amount,
                o.seller_id
            FROM payments pay
            INNER JOIN orders o
                ON o.order_id = pay.order_id
            WHERE pay.payment_id = ?
              AND pay.order_id = ?
              AND pay.buyer_id = ?
              AND o.seller_id = ?
            LIMIT 1
            FOR UPDATE
        ');
        if (!$stmt) {
            throw new RuntimeException('Unable to lock the payment record.');
        }
        $orderId = (int)$order['order_id'];
        $buyerId = (int)$order['buyer_id'];
        $sellerId = (int)$order['seller_id'];
        $stmt->bind_param(
            'iiii',
            $paymentId,
            $orderId,
            $buyerId,
            $sellerId
        );
        $stmt->execute();
        $stmt->bind_result(
            $paymentStatus,
            $paymentAmount,
            $lockedSellerId
        );
        if (!$stmt->fetch()) {
            $stmt->close();
            throw new RuntimeException('Payment record not found.');
        }
        $stmt->close();
        $alreadyPaid = qrKey($paymentStatus) === 'paid';
        if (!$alreadyPaid) {
            $stmt = $conn->prepare('
                UPDATE payments
                SET
                    payment_status = "paid",
                    paid_at = COALESCE(paid_at, NOW()),
                    paymongo_payment_intent_id = ?
                WHERE payment_id = ?
                  AND order_id = ?
                  AND buyer_id = ?
            ');
            if (!$stmt) {
                throw new RuntimeException('Unable to confirm the payment.');
            }
            $stmt->bind_param(
                'siii',
                $paymentIntentId,
                $paymentId,
                $orderId,
                $buyerId
            );
            $stmt->execute();
            $stmt->close();
        }
        $grossAmount = round((float)$paymentAmount, 2);
        if ($grossAmount <= 0) {
            $grossAmount = round((float)$order['total_amount'], 2);
        }
        qrRecordShares(
            $conn,
            $sellerId,
            $orderId,
            $paymentId,
            $grossAmount,
            $paymentIntentId
        );
        $stmt = $conn->prepare('
            UPDATE paymongo_qr_sessions
            SET
                qr_status = "paid",
                paid_at = COALESCE(paid_at, NOW()),
                updated_at = NOW()
            WHERE order_id = ?
              AND buyer_id = ?
              AND seller_id = ?
        ');
        if (!$stmt) {
            throw new RuntimeException('Unable to update the QR Ph session.');
        }
        $stmt->bind_param('iii', $orderId, $buyerId, $sellerId);
        $stmt->execute();
        $stmt->close();
        if (!$alreadyPaid) {
            $messageText =
                'Payment for Order #'
                . $orderId
                . ' was confirmed through QR Ph.';
            $stmt = $conn->prepare('
                INSERT INTO messages
                    (
                        sender_id,
                        receiver_id,
                        order_id,
                        message_text,
                        attachment,
                        is_read,
                        sent_at
                    )
                VALUES
                    (?, ?, ?, ?, "", 0, NOW())
            ');
            if ($stmt) {
                $stmt->bind_param(
                    'iiis',
                    $buyerId,
                    $sellerId,
                    $orderId,
                    $messageText
                );
                $stmt->execute();
                $stmt->close();
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
function qrPaymentStatusFromIntent(array $intent): string
{
    return qrKey($intent['attributes']['status'] ?? '');
}
function qrIntentAmount(array $intent): int
{
    return (int)($intent['attributes']['amount'] ?? 0);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    qrJson(false, 'Invalid request method.', [], 405);
}
if (
    empty($_SESSION['cart_csrf'])
    || !hash_equals(
        (string)$_SESSION['cart_csrf'],
        (string)($_POST['csrf_token'] ?? '')
    )
) {
    qrJson(
        false,
        'Session expired. Refresh the page and try again.',
        [],
        403
    );
}
if (
    !paymongoKeysLookConfigured()
    || !paymongoIsTestMode()
    || !paymongoPublicKeyIsTestMode()
) {
    qrJson(
        false,
        'QR Ph is locked to PayMongo Test Mode. Configure matching sk_test_ and pk_test_ credentials.',
        [],
        503
    );
}
$action = trim((string)($_POST['action'] ?? 'create'));
$orderId = (int)($_POST['order_id'] ?? 0);
if ($orderId <= 0) {
    qrJson(false, 'Invalid order.', [], 400);
}
if (!in_array($action, ['create', 'status'], true)) {
    qrJson(false, 'Unsupported QR Ph action.', [], 400);
}
try {
    $order = qrLoadOrder($conn, $orderId, $buyerId);
    if (!$order) {
        qrJson(false, 'Order not found.', [], 404);
    }
    if (qrKey($order['payment_option']) !== 'paymongo') {
        qrJson(false, 'This order is not using QR Ph.', [], 409);
    }
    $payment = qrLoadLatestPayment($conn, $orderId);
    if ($payment && qrKey($payment['payment_status']) === 'paid') {
        qrJson(true, 'Payment is already confirmed.', [
            'order_id' => $orderId,
            'paid' => true,
            'status' => 'succeeded',
            'order_status' => (string)$order['order_status']
        ]);
    }
    if (
        qrKey($order['order_status']) !== 'accepted'
        || qrKey($order['arrangement_status']) !== 'accepted'
    ) {
        qrJson(
            false,
            'The seller must accept the final order arrangement before QR Ph payment.',
            [],
            409
        );
    }
    $amount = round((float)$order['total_amount'], 2);
    $amountCentavos = (int)round($amount * 100);
    if ($amountCentavos < 100) {
        qrJson(false, 'QR Ph requires an order amount of at least ₱1.00.', [], 400);
    }
    $session = qrLoadSession($conn, $orderId, $buyerId);
    if ($action === 'status') {
        if (!$session) {
            qrJson(
                false,
                'No QR Ph payment has been generated for this order yet.',
                [],
                404
            );
        }
        $intent = paymongoRetrievePaymentIntent(
            (string)$session['payment_intent_id']
        );
        if (qrIntentAmount($intent) !== $amountCentavos) {
            qrJson(false, 'The PayMongo amount does not match this order.', [], 409);
        }
        $remoteStatus = qrPaymentStatusFromIntent($intent);
        if ($remoteStatus === 'succeeded') {
            $paymentId = (int)$session['payment_id'];
            if ($paymentId <= 0) {
                $paymentId = qrEnsurePendingPayment(
                    $conn,
                    $orderId,
                    $buyerId,
                    $amount
                );
            }
            qrFinalizePaid(
                $conn,
                $order,
                $paymentId,
                (string)$session['payment_intent_id']
            );
            qrJson(true, 'Payment confirmed.', [
                'order_id' => $orderId,
                'paid' => true,
                'expired' => false,
                'cancelled' => false,
                'status' => 'succeeded',
                'order_status' => (string)$order['order_status']
            ]);
        }
        $expired =
            (int)$session['expires_at_unix'] > 0
            && (int)$session['expires_at_unix'] <= time();
        $failed = in_array(
            $remoteStatus,
            ['failed', 'failure', 'cancelled', 'canceled'],
            true
        );
        if ($expired) {
            qrSetSessionStatus($conn, $orderId, $buyerId, 'expired');
        } elseif ($failed) {
            qrSetSessionStatus($conn, $orderId, $buyerId, 'failed');
            $payment = qrLoadLatestPayment($conn, $orderId);
            if ($payment && qrKey($payment['payment_status']) !== 'paid') {
                $failedPaymentStatus =
                    in_array($remoteStatus, ['cancelled', 'canceled'], true)
                        ? 'cancelled'
                        : 'failed';
                $stmt = $conn->prepare('
                    UPDATE payments
                    SET payment_status = ?
                    WHERE payment_id = ?
                      AND order_id = ?
                      AND payment_status <> "paid"
                ');
                if ($stmt) {
                    $paymentId = (int)$payment['payment_id'];
                    $stmt->bind_param(
                        'sii',
                        $failedPaymentStatus,
                        $paymentId,
                        $orderId
                    );
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
        qrJson(true, $expired
            ? 'This QR Ph code has expired. Generate a new QR to continue.'
            : ($failed
                ? 'The QR Ph attempt was not completed. Generate a new QR to try again.'
                : 'Payment is still pending.'), [
            'order_id' => $orderId,
            'paid' => false,
            'expired' => $expired,
            'cancelled' => false,
            'can_regenerate' => $expired || $failed,
            'status' => $remoteStatus !== '' ? $remoteStatus : 'pending',
            'order_status' => (string)$order['order_status']
        ]);
    }
    $paymentId = qrEnsurePendingPayment(
        $conn,
        $orderId,
        $buyerId,
        $amount
    );
    if ($session) {
        $sessionStatus = qrKey($session['qr_status']);
        $notExpired =
            (int)$session['expires_at_unix'] > time();
        if ($sessionStatus === 'active' && $notExpired) {
            try {
                $intent = paymongoRetrievePaymentIntent(
                    (string)$session['payment_intent_id']
                );
                $remoteStatus = qrPaymentStatusFromIntent($intent);
                if ($remoteStatus === 'succeeded') {
                    qrFinalizePaid(
                        $conn,
                        $order,
                        $paymentId,
                        (string)$session['payment_intent_id']
                    );
                    qrJson(true, 'Payment confirmed.', [
                        'order_id' => $orderId,
                        'paid' => true,
                        'status' => 'succeeded',
                        'order_status' => (string)$order['order_status']
                    ]);
                }
                if (!in_array(
                    $remoteStatus,
                    ['failed', 'failure', 'cancelled', 'canceled'],
                    true
                )) {
                    qrJson(true, 'Your current QR Ph code is still active.', [
                        'order_id' => $orderId,
                        'payment_intent_id' =>
                            (string)$session['payment_intent_id'],
                        'qr_image' => (string)$session['qr_image'],
                        'test_url' => (string)$session['test_url'],
                        'expires_at' => (string)$session['expires_at'],
                        'paid' => false,
                        'status' => $remoteStatus !== ''
                            ? $remoteStatus
                            : 'awaiting_next_action'
                    ]);
                }
            } catch (Throwable $ignored) {
            }
        }
        if (!$notExpired) {
            qrSetSessionStatus($conn, $orderId, $buyerId, 'expired');
        }
    }
    $billing = [];
    $stmt = $conn->prepare('
        SELECT firstname, lastname, email, phone
        FROM users
        WHERE id = ?
        LIMIT 1
    ');
    if ($stmt) {
        $stmt->bind_param('i', $buyerId);
        $stmt->execute();
        $stmt->bind_result(
            $buyerFirstName,
            $buyerLastName,
            $buyerEmail,
            $buyerPhone
        );
        if ($stmt->fetch()) {
            $buyerName = trim(
                (string)$buyerFirstName
                . ' '
                . (string)$buyerLastName
            );
            if ($buyerName !== '') {
                $billing['name'] = $buyerName;
            }
            if (trim((string)$buyerEmail) !== '') {
                $billing['email'] = trim((string)$buyerEmail);
            }
            if (trim((string)$buyerPhone) !== '') {
                $billing['phone'] = trim((string)$buyerPhone);
            }
        }
        $stmt->close();
    }
    $qr = paymongoGenerateQrPh(
        $amountCentavos,
        'LokalCraft Order #' . $orderId,
        [
            'order_id' => (string)$orderId,
            'buyer_id' => (string)$buyerId,
            'seller_id' => (string)$order['seller_id'],
            'platform_fee_rate' => '5',
            'seller_share_rate' => '95'
        ],
        $billing,
        null
    );
    qrSaveSession(
        $conn,
        $order,
        $paymentId,
        $qr
    );
    qrJson(true, 'QR Ph test payment generated.', [
        'order_id' => $orderId,
        'payment_intent_id' => (string)$qr['payment_intent_id'],
        'qr_image' => (string)$qr['qr_image'],
        'test_url' => (string)($qr['test_url'] ?? ''),
        'expires_at' => date(
            'Y-m-d H:i:s',
            (int)$qr['expires_at']
        ),
        'expiry_seconds' => (int)$qr['expiry_seconds'],
        'paid' => false,
        'status' => (string)$qr['status']
    ]);
} catch (Throwable $e) {
    error_log(
        'LokalCraft QR Ph payment error for Order #'
        . $orderId
        . ': '
        . $e->getMessage()
    );
    qrJson(
        false,
        'Unable to process the QR Ph payment right now. Please try again.',
        [],
        500
    );
}
