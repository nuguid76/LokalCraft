<?php
/**
 * LokalCraft QR Ph confirmation — common path for buyer polling and PayMongo webhooks.
 * Requires config/database.php ($conn), config/paymongo.php, and notification_events.php.
 * Only an authenticated server-side PayMongo retrieval may be passed here.
 */
function lokalcraftQrStatus(string $value): string
{
    return strtolower(trim($value));
}
function lokalcraftQrPaidReference(array $intent): string
{
    $attributes = $intent['attributes'] ?? [];
    if (($attributes['status'] ?? '') !== 'succeeded') {
        throw new RuntimeException('PayMongo has not confirmed this payment.');
    }
    $payments = $attributes['payments'] ?? [];
    if (!is_array($payments)) return '';
    foreach (array_reverse($payments) as $payment) {
        if (!is_array($payment)) continue;
        $paymentId = trim((string)($payment['id'] ?? ''));
        $details = $payment['attributes'] ?? [];
        if (!preg_match('/^pay_[A-Za-z0-9_-]{6,115}$/', $paymentId)) continue;
        if (!is_array($details)) $details = [];
        if (isset($details['status']) && lokalcraftQrStatus((string)$details['status']) !== 'paid') continue;
        if (isset($details['payment_intent_id'])
            && (string)$details['payment_intent_id'] !== (string)($intent['id'] ?? '')) continue;
        if (isset($details['amount']) && (int)$details['amount'] !== (int)($attributes['amount'] ?? -1)) continue;
        if (isset($details['currency']) && strtoupper((string)$details['currency']) !== 'PHP') continue;
        return $paymentId;
    }
    return '';
}
function lokalcraftQrWalletCredit(
    mysqli $conn, int $userId, string $walletType, int $orderId,
    int $paymentId, string $category, float $amount,
    string $referenceKey, string $description
): void {
    if ($amount <= 0) return;
    if (!in_array($walletType, ['seller', 'admin'], true)) throw new RuntimeException('Invalid wallet type.');
    $stmt = $conn->prepare('INSERT INTO wallet_accounts
        (user_id, wallet_type, balance, status, created_at, updated_at)
        VALUES (?, ?, 0.00, "active", NOW(), NOW())
        ON DUPLICATE KEY UPDATE updated_at = updated_at');
    $stmt->bind_param('is', $userId, $walletType);
    $stmt->execute(); $stmt->close();
    $stmt = $conn->prepare('SELECT wallet_id, balance FROM wallet_accounts
        WHERE user_id = ? AND wallet_type = ? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('is', $userId, $walletType);
    $stmt->execute(); $wallet = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$wallet) throw new RuntimeException('Wallet is unavailable.');
    $walletId = (int)$wallet['wallet_id'];
    $stmt = $conn->prepare('SELECT wallet_transaction_id FROM wallet_transactions
        WHERE reference_key = ? LIMIT 1');
    $stmt->bind_param('s', $referenceKey);
    $stmt->execute(); $exists = (bool)$stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($exists) return;
    $balanceAfter = round((float)$wallet['balance'] + $amount, 2);
    $stmt = $conn->prepare('UPDATE wallet_accounts SET balance = ?, updated_at = NOW() WHERE wallet_id = ?');
    $stmt->bind_param('di', $balanceAfter, $walletId);
    $stmt->execute(); $stmt->close();
    $direction = 'credit';
    $stmt = $conn->prepare('INSERT INTO wallet_transactions
        (wallet_id, order_id, payment_id, transaction_category, direction,
         amount, balance_after, reference_key, description, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->bind_param('iiissddss', $walletId, $orderId, $paymentId,
        $category, $direction, $amount, $balanceAfter, $referenceKey, $description);
    $stmt->execute(); $stmt->close();
}
function lokalcraftQrPlatformShares(
    mysqli $conn, int $sellerId, int $orderId, int $paymentId,
    float $grossAmount, string $paymongoPaymentId
): void {
    $platformAmount = round($grossAmount * 0.05, 2);
    $sellerAmount = round($grossAmount - $platformAmount, 2);
    $rate = 5.00;
    $method = 'split_payment'; // Internal simulation; no PayMongo Split Payments API call.
    $status = 'earned';
    $stmt = $conn->prepare('SELECT commission_id, commission_status FROM platform_commissions
        WHERE order_id = ? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('i', $orderId);
    $stmt->execute(); $commission = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($commission && lokalcraftQrStatus((string)$commission['commission_status']) === 'refunded') {
        throw new RuntimeException('A refunded commission cannot be paid again.');
    }
    if ($commission) {
        $commissionId = (int)$commission['commission_id'];
        $stmt = $conn->prepare('UPDATE platform_commissions SET seller_id = ?, gross_amount = ?,
            commission_rate = ?, commission_amount = ?, seller_net_amount = ?,
            collection_method = ?, commission_status = ?, paymongo_payment_id = ?,
            earned_at = COALESCE(earned_at, NOW()), updated_at = NOW()
            WHERE commission_id = ?');
        $stmt->bind_param('iddddsssi', $sellerId, $grossAmount, $rate, $platformAmount,
            $sellerAmount, $method, $status, $paymongoPaymentId, $commissionId);
    } else {
        $stmt = $conn->prepare('INSERT INTO platform_commissions
            (order_id, seller_id, gross_amount, commission_rate, commission_amount,
             seller_net_amount, collection_method, commission_status, paymongo_payment_id,
             earned_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())');
        $stmt->bind_param('iiddddsss', $orderId, $sellerId, $grossAmount, $rate,
            $platformAmount, $sellerAmount, $method, $status, $paymongoPaymentId);
    }
    $stmt->execute(); $stmt->close();
    $stmt = $conn->prepare('SELECT id FROM users WHERE adminOrNot = 1 AND account_status = "active"
        ORDER BY id ASC LIMIT 1');
    $stmt->execute(); $admin = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$admin) throw new RuntimeException('No active admin wallet is available.');
    lokalcraftQrWalletCredit($conn, $sellerId, 'seller', $orderId, $paymentId,
        'seller_share', $sellerAmount, 'qr-seller-share-order-' . $orderId,
        'Seller share for Order #' . $orderId);
    lokalcraftQrWalletCredit($conn, (int)$admin['id'], 'admin', $orderId, $paymentId,
        'platform_fee', $platformAmount, 'qr-platform-fee-order-' . $orderId,
        '5% platform fee for Order #' . $orderId);
}
/** Atomic and idempotent: called with a PaymentIntent retrieved with the secret key. */
function lokalcraftConfirmQrFromIntent(mysqli $conn, array $intent, int $orderId): array
{
    $intentId = trim((string)($intent['id'] ?? ''));
    $attributes = $intent['attributes'] ?? [];
    if (!preg_match('/^pi_[A-Za-z0-9_-]{6,115}$/', $intentId)
        || !is_array($attributes)
        || ($attributes['status'] ?? '') !== 'succeeded'
        || strtoupper((string)($attributes['currency'] ?? '')) !== 'PHP'
        || !isset($attributes['amount']) || !is_numeric($attributes['amount'])) {
        throw new RuntimeException('PayMongo has not confirmed a valid QR Ph payment.');
    }
    if (array_key_exists('livemode', $attributes)
        && (bool)$attributes['livemode'] === paymongoIsTestMode()) {
        throw new RuntimeException('PayMongo payment mode does not match the API configuration.');
    }
    $paymongoPaymentId = lokalcraftQrPaidReference($intent);
    if ($paymongoPaymentId === '') {
        // Never fabricate a pay_ ID or credit wallets while it is unavailable.
        throw new RuntimeException('PayMongo payment reference is not available yet. Please retry shortly.');
    }
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT
            pay.payment_id, pay.buyer_id, pay.amount, pay.payment_method,
            pay.payment_provider, pay.payment_status, pay.paymongo_payment_id,
            o.seller_id, o.total_amount, o.order_status,
            oa.arrangement_status, oa.payment_option,
            qr.qr_session_id, qr.qr_status, qr.payment_intent_id
            FROM payments pay
            INNER JOIN paymongo_qr_sessions qr ON qr.payment_id = pay.payment_id AND qr.order_id = pay.order_id
            INNER JOIN orders o ON o.order_id = pay.order_id
            LEFT JOIN order_arrangements oa ON oa.order_id = o.order_id
            WHERE pay.order_id = ? AND qr.payment_intent_id = ?
            LIMIT 1 FOR UPDATE');
        $stmt->bind_param('is', $orderId, $intentId);
        $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$row) throw new RuntimeException('The PayMongo reference does not match the current QR session.');
        $buyerId = (int)$row['buyer_id'];
        $sellerId = (int)$row['seller_id'];
        $paymentId = (int)$row['payment_id'];
        $metadata = $attributes['metadata'] ?? [];
        if (!is_array($metadata)
            || (string)($metadata['lokalcraft_order_id'] ?? '') !== (string)$orderId
            || (string)($metadata['lokalcraft_buyer_id'] ?? '') !== (string)$buyerId
            || (string)($metadata['lokalcraft_seller_id'] ?? '') !== (string)$sellerId) {
            throw new RuntimeException('PayMongo payment metadata does not match this order.');
        }
        $gross = round((float)$row['amount'], 2);
        if ($gross <= 0 || (int)round($gross * 100) !== (int)$attributes['amount']) {
            throw new RuntimeException('The PayMongo amount does not match the confirmed order.');
        }
        $existingReference = trim((string)($row['paymongo_payment_id'] ?? ''));
        if ($existingReference !== '' && !hash_equals($existingReference, $paymongoPaymentId)) {
            throw new RuntimeException('This order is linked to a different PayMongo payment.');
        }
        $alreadyPaid = lokalcraftQrStatus((string)$row['payment_status']) === 'paid';
        // A successful webhook may be redelivered after the order is completed.
        if ($alreadyPaid && $existingReference !== ''
            && lokalcraftQrStatus((string)$row['qr_status']) === 'paid') {
            $conn->commit();
            return ['payment_id' => $paymentId, 'paymongo_payment_id' => $paymongoPaymentId,
                'payment_intent_id' => $intentId, 'already_paid' => true];
        }
        if (lokalcraftQrStatus((string)$row['order_status']) !== 'accepted'
            || lokalcraftQrStatus((string)$row['arrangement_status']) !== 'accepted'
            || lokalcraftQrStatus((string)$row['payment_option']) !== 'paymongo'
            || lokalcraftQrStatus((string)$row['payment_provider']) !== 'paymongo'
            || !in_array(lokalcraftQrStatus((string)$row['payment_method']), ['qr ph','qrph'], true)) {
            throw new RuntimeException('This order is not eligible for QR Ph confirmation.');
        }
        if (in_array(lokalcraftQrStatus((string)$row['payment_status']), ['refunded','cancelled'], true)) {
            throw new RuntimeException('This payment has already been cancelled or refunded.');
        }
        $stmt = $conn->prepare('UPDATE payments
            SET payment_status = "paid", paymongo_payment_id = ?,
                paymongo_payment_intent_id = ?, payment_reference = ?,
                paid_at = COALESCE(paid_at, NOW())
            WHERE payment_id = ? AND order_id = ? AND buyer_id = ?');
        $stmt->bind_param('sssiii', $paymongoPaymentId, $intentId, $paymongoPaymentId,
            $paymentId, $orderId, $buyerId);
        $stmt->execute(); $stmt->close();
        lokalcraftQrPlatformShares($conn, $sellerId, $orderId, $paymentId, $gross, $paymongoPaymentId);
        $sessionId = (int)$row['qr_session_id'];
        $stmt = $conn->prepare('UPDATE paymongo_qr_sessions
            SET qr_status = "paid", paid_at = COALESCE(paid_at, NOW()), updated_at = NOW()
            WHERE qr_session_id = ? AND payment_intent_id = ?');
        $stmt->bind_param('is', $sessionId, $intentId);
        $stmt->execute(); $stmt->close();
        if (!$alreadyPaid) {
            $messageText = 'Payment for Order #' . $orderId . ' was confirmed through QR Ph.';
            $stmt = $conn->prepare('INSERT INTO messages
                (sender_id, receiver_id, order_id, message_text, attachment, is_read, sent_at)
                VALUES (?, ?, ?, ?, "", 0, NOW())');
            $stmt->bind_param('iiis', $buyerId, $sellerId, $orderId, $messageText);
            $stmt->execute(); $stmt->close();
            lokalcraftCreateNotification($conn, $buyerId, 'order_payment_confirmed',
                'Payment Confirmed', 'Your QR Ph payment for Order #' . $orderId . ' was confirmed.',
                'order', $orderId, 'buyer_qr_payment_confirmed_order_' . $orderId);
            lokalcraftCreateNotification($conn, $sellerId, 'seller_payment_received',
                'Payment Received', 'QR Ph payment for Order #' . $orderId . ' was confirmed.',
                'order', $orderId, 'seller_qr_payment_received_order_' . $orderId);
        }
        $conn->commit();
        return ['payment_id' => $paymentId, 'paymongo_payment_id' => $paymongoPaymentId,
            'payment_intent_id' => $intentId, 'already_paid' => $alreadyPaid];
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
