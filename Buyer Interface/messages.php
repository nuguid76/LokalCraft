<?php
require_once __DIR__ . '/buyer_auth.php';
require_once __DIR__ . '/../config/notification_events.php';
require_once __DIR__ . '/../config/paymongo_qr_confirmation.php';
date_default_timezone_set('Asia/Manila');
$paymongoConfigFile = __DIR__ . '/../config/paymongo.php';
if (is_file($paymongoConfigFile)) {
    require_once $paymongoConfigFile;
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (empty($_SESSION['buyer_messages_csrf'])) {
    $_SESSION['buyer_messages_csrf'] = bin2hex(random_bytes(32));
}
$activePage = 'messages';
$errorMessage = '';
$successMessage = '';
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function chatStatusKey($value): string
{
    return strtolower(trim((string)$value));
}
function chatNiceStatus($value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    return ucwords(str_replace(['_', '-'], ' ', $value));
}
function buyerPaymentOptionLabel($value): string
{
    $key = chatStatusKey($value);
    if ($key === 'paymongo') {
        return 'QR Ph';
    }
    if ($key === 'cash') {
        return 'Cash';
    }
    return chatNiceStatus($value);
}
function buyerDeliveryOptionLabel($value): string
{
    $key = chatStatusKey($value);
    $labels = [
        'meetup' => 'Meetup',
        'pickup' => 'Pickup',
        'third_party_delivery' => 'Third-Party Delivery',
        'other' => 'Other'
    ];
    return $labels[$key] ?? chatNiceStatus($value);
}
function buyerPaymentStatusLabel($value): string
{
    $key = chatStatusKey($value);
    if ($key === 'paid') {
        return 'Paid';
    }
    if ($key === 'pending') {
        return 'Waiting for Payment';
    }
    if ($key === 'failed') {
        return 'Payment Failed';
    }
    if ($key === 'cancelled' || $key === 'canceled') {
        return 'Payment Cancelled';
    }
    if ($key === 'refunded') {
        return 'Refunded';
    }
    return $key !== '' ? chatNiceStatus($value) : 'Not Paid';
}
/** A terminal order is historical. Its order actions must not be reopened by chat. */
function buyerOrderClosedStatus($status): bool
{
    return in_array(chatStatusKey($status),
        ['cancelled', 'canceled', 'rejected', 'declined', 'expired', 'completed'], true);
}

/** A buyer can continue the ordinary seller chat without attaching a closed order. */
function buyerHasSellerOrder(mysqli $conn, int $buyerId, int $sellerId): bool
{
    if ($buyerId <= 0 || $sellerId <= 0) return false;
    $stmt = $conn->prepare('SELECT order_id FROM orders WHERE buyer_id = ? AND seller_id = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Unable to verify this buyer conversation.');
    $stmt->bind_param('ii', $buyerId, $sellerId);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

function buyerHasCurrentSellerProposal(
    mysqli $conn,
    int $orderId,
    int $buyerId,
    int $sellerId,
    ?string $arrangementUpdatedAt,
    ?string $sellerConfirmedAt = null
): bool {
    if ($orderId <= 0 || $buyerId <= 0 || $sellerId <= 0) return false;
    $prefix = 'Order #' . $orderId . ' details are ready.';
    $sentPattern = $prefix . '%';
    $changePattern = 'Buyer requested changes to Order #' . $orderId . '.%';
    $confirmedPattern = 'Buyer confirmed the order details for Order #' . $orderId . '.%';
    $stmt = $conn->prepare('
        SELECT sender_id, receiver_id, message_text, sent_at
        FROM messages
        WHERE order_id = ?
          AND (
            (sender_id = ? AND receiver_id = ? AND message_text LIKE ?)
            OR
            (sender_id = ? AND receiver_id = ?
             AND (message_text LIKE ? OR message_text LIKE ?))
          )
        ORDER BY message_id DESC
        LIMIT 1
    ');
    if (!$stmt) throw new RuntimeException('Unable to verify the seller-sent details.');
    $stmt->bind_param('iiisiiss',
        $orderId, $sellerId, $buyerId, $sentPattern,
        $buyerId, $sellerId, $changePattern, $confirmedPattern
    );
    $stmt->execute();
    $lastEvent = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$lastEvent || (int)$lastEvent['sender_id'] !== $sellerId
        || (int)$lastEvent['receiver_id'] !== $buyerId
        || !str_starts_with((string)$lastEvent['message_text'], $prefix)) {
        return false;
    }
    $sentAt = strtotime((string)$lastEvent['sent_at']);
    $updatedAt = strtotime((string)($arrangementUpdatedAt ?? ''));
    if ($sentAt === false) return false;
    // seller_confirmed_at is written only on Send to Buyer. It remains the
    // authoritative send marker even if a legacy status was set to 'arranging'.
    $confirmedAt = strtotime((string)($sellerConfirmedAt ?? ''));
    if ($confirmedAt !== false) {
        return $sentAt >= $confirmedAt;
    }
    // Older records without a marker: only trust an actual seller message
    // if no arrangement edit followed it. Never infer a send from filled fields.
    return $updatedAt === false || $sentAt >= $updatedAt;
}

/** Determine whether a pending arrangement is really waiting for this buyer. */
function buyerReviewStage(
    mysqli $conn, int $orderId, int $buyerId, int $sellerId,
    string $orderStatus, string $arrangementStatus, ?string $updatedAt,
    ?string $sellerConfirmedAt = null
): string {
    if (chatStatusKey($orderStatus) !== 'pending') return $arrangementStatus;
    if (chatStatusKey($arrangementStatus) === 'awaiting_confirmation') return $arrangementStatus;
    if (chatStatusKey($arrangementStatus) !== 'arranging') return $arrangementStatus;
    return buyerHasCurrentSellerProposal($conn, $orderId, $buyerId, $sellerId,
        $updatedAt, $sellerConfirmedAt)
        ? 'awaiting_confirmation' : $arrangementStatus;
}

/** Prefer the seller-sent order that is waiting for this buyer's reply. */
function buyerNextConfirmationOrderId(mysqli $conn, int $buyerId, int $sellerId): int
{
    if ($buyerId <= 0 || $sellerId <= 0) return 0;
    $stmt = $conn->prepare('
        SELECT o.order_id
        FROM orders o
        INNER JOIN order_arrangements oa ON oa.order_id = o.order_id
        WHERE o.buyer_id = ? AND o.seller_id = ?
          AND LOWER(o.order_status) = "pending"
          AND oa.arrangement_status = "awaiting_confirmation"
        ORDER BY oa.seller_confirmed_at DESC, oa.updated_at DESC, o.order_id DESC
        LIMIT 1
    ');
    if (!$stmt) throw new RuntimeException('Unable to check orders needing confirmation.');
    $stmt->bind_param('ii', $buyerId, $sellerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) return (int)$row['order_id'];
    // Older seller versions could leave a real sent proposal in 'arranging'.
    // Use the same guarded verification that the details popup uses.
    $stmt = $conn->prepare('
        SELECT o.order_id, o.order_status, oa.arrangement_status, oa.updated_at,
               oa.seller_confirmed_at
        FROM orders o JOIN order_arrangements oa ON oa.order_id = o.order_id
        WHERE o.buyer_id = ? AND o.seller_id = ?
          AND LOWER(o.order_status) = "pending"
          AND oa.arrangement_status = "arranging"
          AND EXISTS (SELECT 1 FROM messages m
              WHERE m.order_id = o.order_id AND m.sender_id = o.seller_id
                AND m.receiver_id = o.buyer_id
                AND m.message_text LIKE CONCAT("Order #", o.order_id, " details are ready.%"))
        ORDER BY oa.updated_at DESC, o.order_id DESC LIMIT 30
    ');
    if (!$stmt) throw new RuntimeException('Unable to load pending buyer confirmations.');
    $stmt->bind_param('ii', $buyerId, $sellerId);
    $stmt->execute();
    $candidates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($candidates as $candidate) {
        $id = (int)$candidate['order_id'];
        if (buyerReviewStage($conn, $id, $buyerId, $sellerId,
            (string)$candidate['order_status'], (string)$candidate['arrangement_status'],
            (string)($candidate['updated_at'] ?? ''),
            (string)($candidate['seller_confirmed_at'] ?? '')) === 'awaiting_confirmation') {
            return $id;
        }
    }
    return 0;
}

function buyerOrderStatus(mysqli $conn, int $buyerId, int $sellerId, int $orderId): ?string
{
    $stmt = $conn->prepare('SELECT order_status FROM orders WHERE order_id = ? AND buyer_id = ? AND seller_id = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Unable to check this order.');
    $stmt->bind_param('iii', $orderId, $buyerId, $sellerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (string)$row['order_status'] : null;
}

function buyerOrderCanPayWithPayMongo(array $order): bool
{
    return
        chatStatusKey($order['order_status'] ?? '') === 'accepted'
        && chatStatusKey($order['arrangement_status'] ?? '') === 'accepted'
        && chatStatusKey($order['payment_option'] ?? '') === 'paymongo'
        && !in_array(
            chatStatusKey($order['payment_status'] ?? ''),
            ['paid', 'refunded', 'cancelled', 'canceled'],
            true
        );
}
function chatDisplayStatus($orderStatus, $paymentStatus, $paymentMethod): string
{
    $orderKey = chatStatusKey($orderStatus);
    $paymentKey = chatStatusKey($paymentStatus);
    $methodKey = chatStatusKey($paymentMethod);
    if (in_array($orderKey, ['cancelled', 'canceled'], true)) {
        return 'Cancelled';
    }
    if (in_array($orderKey, ['rejected', 'declined'], true)) {
        return 'Rejected';
    }
    if (in_array($methodKey, ['qr ph', 'qrph', 'paymongo'], true)) {
        if (in_array($paymentKey, ['cancelled', 'canceled'], true)) {
            return 'Cancelled';
        }
        if ($paymentKey === 'refunded') {
            return 'Refunded';
        }
        if ($paymentKey === 'failed') {
            return 'Payment Failed';
        }
        if ($paymentKey === 'pending' && $orderKey === 'accepted') {
            return 'Waiting for Payment';
        }
    }
    return chatNiceStatus($orderStatus !== '' ? $orderStatus : 'Pending');
}
function getInitials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'S';
    }
    $parts = preg_split('/\s+/', $name);
    if (count($parts) === 1) {
        return strtoupper(substr($parts[0], 0, 2));
    }
    return strtoupper(
        substr($parts[0], 0, 1) .
        substr($parts[count($parts) - 1], 0, 1)
    );
}
function dateKey(?string $dateTime): string
{
    if (!$dateTime) {
        return '';
    }
    $timestamp = strtotime($dateTime);
    return $timestamp ? date('Y-m-d', $timestamp) : '';
}
function dateLabel(?string $dateTime): string
{
    if (!$dateTime) {
        return '';
    }
    $timestamp = strtotime($dateTime);
    if (!$timestamp) {
        return '';
    }
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $date = date('Y-m-d', $timestamp);
    if ($date === $today) {
        return 'Today';
    }
    if ($date === $yesterday) {
        return 'Yesterday';
    }
    return date('F j, Y', $timestamp);
}
function chatTime(?string $dateTime): string
{
    if (!$dateTime) {
        return '';
    }
    $timestamp = strtotime($dateTime);
    return $timestamp ? date('g:i A', $timestamp) : '';
}
function conversationTime(?string $dateTime): string
{
    if (!$dateTime) {
        return '';
    }
    $timestamp = strtotime($dateTime);
    if (!$timestamp) {
        return '';
    }
    $date = date('Y-m-d', $timestamp);
    if ($date === date('Y-m-d')) {
        return date('g:i A', $timestamp);
    }
    if ($timestamp >= strtotime('-6 days')) {
        return date('D', $timestamp);
    }
    return date('M j', $timestamp);
}
function sellerExists(mysqli $conn, int $sellerId): bool
{
    if ($sellerId <= 0) {
        return false;
    }
    $stmt = $conn->prepare('SELECT id FROM users WHERE id = ? AND SellerOrNot = 1 AND account_status = "active" LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $sellerId);
    $stmt->execute();
    $valid = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $valid;
}
function validOrder(mysqli $conn, int $buyerId, int $sellerId, int $orderId): bool
{
    if ($orderId <= 0) {
        return false;
    }
    $stmt = $conn->prepare('
        SELECT order_id
        FROM orders
        WHERE order_id = ? AND buyer_id = ? AND seller_id = ?
        LIMIT 1
    ');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iii', $orderId, $buyerId, $sellerId);
    $stmt->execute();
    $stmt->bind_result($matchedOrderId);
    $valid = (bool)$stmt->fetch();
    $stmt->close();
    return $valid;
}
function buyerChatReviewsAvailable(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $result = $conn->query("SHOW TABLES LIKE 'reviews'");
        $ready = $result && $result->num_rows > 0;
        if ($result) $result->free();
    } catch (Throwable $ignored) {
        $ready = false;
    }
    return $ready;
}

function buyerChatCompletedReviewItems(mysqli $conn, int $buyerId, int $sellerId, int $orderId): array
{
    if (!buyerChatReviewsAvailable($conn)) {
        throw new RuntimeException('Product reviews are not available yet.');
    }
    $stmt = $conn->prepare('SELECT order_status FROM orders WHERE order_id = ? AND buyer_id = ? AND seller_id = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Unable to check this order.');
    $stmt->bind_param('iii', $orderId, $buyerId, $sellerId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order || chatStatusKey($order['order_status']) !== 'completed') {
        throw new RuntimeException('Reviews are available after the order is completed.');
    }
    $stmt = $conn->prepare('SELECT oi.order_item_id, oi.product_id, p.product_name,
        EXISTS (SELECT 1 FROM reviews r WHERE r.order_item_id = oi.order_item_id) AS reviewed
        FROM order_items oi INNER JOIN products p ON p.product_id = oi.product_id
        WHERE oi.order_id = ? ORDER BY oi.order_item_id ASC');
    if (!$stmt) throw new RuntimeException('Unable to load the products for review.');
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(static fn(array $row): array => [
        'order_item_id' => (int)$row['order_item_id'],
        'product_id' => (int)$row['product_id'],
        'product_name' => (string)$row['product_name'],
        'reviewed' => (bool)$row['reviewed']
    ], $rows);
}

function buyerDirectThreadState(mysqli $conn, int $buyerId, int $sellerId): int
{
    if ($buyerId <= 0 || $sellerId <= 0 || $buyerId === $sellerId) {
        return 0;
    }
    $stmt = $conn->prepare('
        SELECT sender_id, receiver_id
        FROM messages
        WHERE order_id IS NULL
          AND (
                (sender_id = ? AND receiver_id = ?)
             OR (sender_id = ? AND receiver_id = ?)
          )
        ORDER BY message_id ASC
        LIMIT 1
    ');
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param(
        'iiii',
        $buyerId,
        $sellerId,
        $sellerId,
        $buyerId
    );
    $stmt->execute();
    $stmt->bind_result($firstSenderId, $firstReceiverId);
    if (!$stmt->fetch()) {
        $stmt->close();
        return 0;
    }
    $stmt->close();
    return (
        (int)$firstSenderId === $buyerId
        && (int)$firstReceiverId === $sellerId
    ) ? 1 : -1;
}
function buyerOrderSource(mysqli $conn, int $orderId): string
{
    if ($orderId <= 0) {
        return 'chat';
    }
    $stmt = $conn->prepare('
        SELECT message_text
        FROM messages
        WHERE order_id = ?
        ORDER BY message_id ASC
        LIMIT 1
    ');
    if ($stmt) {
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $stmt->bind_result($firstMessage);
        if ($stmt->fetch()) {
            $message = trim((string)$firstMessage);
            $stmt->close();
            if (strpos($message, 'New LokalCraft Ready-Stock Order') === 0
                || strpos($message, 'New LokalCraft Order Request') === 0) {
                return 'cart';
            }
            if (strpos($message, 'New LokalCraft Order via Chat Request') === 0 || strpos($message, 'New LokalCraft Product Request') === 0) {
                return 'chat';
            }
        } else {
            $stmt->close();
        }
    }
    $stmt = $conn->prepare('
        SELECT
            COUNT(*),
            SUM(
                CASE
                    WHEN agreed_unit_price IS NULL THEN 1
                    ELSE 0
                END
            )
        FROM order_items
        WHERE order_id = ?
    ');
    if (!$stmt) {
        return 'chat';
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $stmt->bind_result($itemCount, $negotiableCount);
    $stmt->fetch();
    $stmt->close();
    if ((int)$itemCount > 1) {
        return 'cart';
    }
    return (int)$itemCount === 1 && (int)$negotiableCount === 0
        ? 'cart'
        : 'chat';
}
function getConversations(mysqli $conn, int $buyerId): array
{
    $conversations = [];
    $directStates = [];
    $buyerOrderPartners = [];
    $stmt = $conn->prepare('
        SELECT
            m.message_id,
            m.sender_id,
            m.receiver_id,
            m.order_id,
            m.message_text,
            m.attachment,
            m.is_read,
            m.sent_at,
            partner.id AS partner_id,
            partner.firstname,
            partner.lastname,
            b.businessname,
            l.barangay,
            l.city,
            o.buyer_id AS order_buyer_id,
            o.seller_id AS order_seller_id,
            (
                SELECT
                    CASE
                        WHEN COUNT(*) > 1
                            THEN CONCAT(COUNT(*), " items")
                        ELSE MAX(p.product_name)
                    END
                FROM order_items oi
                INNER JOIN products p ON p.product_id = oi.product_id
                WHERE oi.order_id = m.order_id
            ) AS order_product
        FROM messages m
        INNER JOIN users partner
            ON partner.id = CASE
                WHEN m.sender_id = ? THEN m.receiver_id
                ELSE m.sender_id
            END
        LEFT JOIN orders o ON o.order_id = m.order_id
        LEFT JOIN businessinfo b ON b.user_id = partner.id
        LEFT JOIN locationinfo l ON l.user_id = partner.id
        WHERE (m.sender_id = ? OR m.receiver_id = ?)
          AND partner.SellerOrNot = 1
        ORDER BY m.sent_at ASC, m.message_id ASC
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to load conversations.');
    }
    $stmt->bind_param('iii', $buyerId, $buyerId, $buyerId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $partnerId = (int)$row['partner_id'];
        $orderId = (int)($row['order_id'] ?? 0);
        if ($orderId > 0) {
            if (
                (int)($row['order_buyer_id'] ?? 0) !== $buyerId
                || (int)($row['order_seller_id'] ?? 0) !== $partnerId
            ) {
                continue;
            }
        } else {
            if (!array_key_exists($partnerId, $directStates)) {
                $directStates[$partnerId] = buyerDirectThreadState(
                    $conn,
                    $buyerId,
                    $partnerId
                );
            }
            if ($directStates[$partnerId] !== 1) {
                if (!array_key_exists($partnerId, $buyerOrderPartners)) {
                    $buyerOrderPartners[$partnerId] = buyerHasSellerOrder($conn, $buyerId, $partnerId);
                }
                if (!$buyerOrderPartners[$partnerId]) continue;
            }
        }
        $sellerPersonName = trim(
            ($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')
        );
        if ($sellerPersonName === '') {
            $sellerPersonName = 'Seller';
        }
        $businessName = trim((string)($row['businessname'] ?? ''));
        if (!isset($conversations[$partnerId])) {
            $location = implode(', ', array_filter([
                trim((string)($row['barangay'] ?? '')),
                trim((string)($row['city'] ?? ''))
            ]));
            $conversations[$partnerId] = [
                'partner_id' => $partnerId,
                'name' => $businessName !== ''
                    ? $businessName
                    : $sellerPersonName,
                'person_name' => $sellerPersonName,
                'initials' => getInitials(
                    $businessName !== ''
                        ? $businessName
                        : $sellerPersonName
                ),
                'location' => $location,
                'preview' => '',
                'attachment' => '',
                'time' => '',
                'time_value' => '',
                'unread' => 0,
                'product' => '',
                'latest_order_id' => 0
            ];
        }
        $conversations[$partnerId]['preview'] =
            (string)($row['message_text'] ?? '');
        $conversations[$partnerId]['attachment'] =
            (string)($row['attachment'] ?? '');
        $conversations[$partnerId]['time'] =
            conversationTime((string)$row['sent_at']);
        $conversations[$partnerId]['time_value'] =
            (string)$row['sent_at'];
        if (
            (int)$row['receiver_id'] === $buyerId
            && (int)$row['is_read'] === 0
        ) {
            $conversations[$partnerId]['unread']++;
        }
        if (!empty($row['order_product'])) {
            $conversations[$partnerId]['product'] =
                (string)$row['order_product'];
        }
        if ($orderId > 0) {
            $conversations[$partnerId]['latest_order_id'] = $orderId;
        }
    }
    $stmt->close();
    $conversations = array_values($conversations);
    usort($conversations, static function (array $a, array $b): int {
        return strcmp($b['time_value'], $a['time_value']);
    });
    return $conversations;
}
function getMessages(mysqli $conn, int $buyerId, int $sellerId, int $afterId = 0): array
{
    $messages = [];
    $directThreadOwned = buyerDirectThreadState(
        $conn,
        $buyerId,
        $sellerId
    ) === 1 || buyerHasSellerOrder($conn, $buyerId, $sellerId);
    $sql = '
        SELECT
            m.message_id,
            (m.order_id IS NOT NULL AND m.message_id = (
                SELECT MIN(first_m.message_id)
                FROM messages first_m WHERE first_m.order_id = m.order_id
            )) AS is_order_opening,
            m.sender_id,
            m.receiver_id,
            m.order_id,
            m.message_text,
            m.attachment,
            m.is_read,
            m.sent_at,
            o.buyer_id AS order_buyer_id,
            o.seller_id AS order_seller_id,
            o.total_amount,
            (SELECT COALESCE(SUM(oiq.quantity), 0) FROM order_items oiq WHERE oiq.order_id = m.order_id) AS order_quantity,
            (SELECT pf.product_photo FROM order_items oif
             INNER JOIN products pf ON pf.product_id = oif.product_id
             WHERE oif.order_id = m.order_id
             ORDER BY oif.order_item_id ASC LIMIT 1) AS order_photo,
            o.order_status,
            oa.arrangement_status AS order_arrangement_status,
            oa.updated_at AS order_arrangement_updated_at,
            oa.seller_confirmed_at AS order_seller_confirmed_at,
            pay.payment_method,
            pay.payment_status,
            (
                SELECT
                    CASE
                        WHEN COUNT(*) > 1
                            THEN CONCAT(COUNT(*), " items")
                        ELSE MAX(p.product_name)
                    END
                FROM order_items oi
                INNER JOIN products p ON p.product_id = oi.product_id
                WHERE oi.order_id = m.order_id
            ) AS order_product
        FROM messages m
        LEFT JOIN orders o ON o.order_id = m.order_id
        LEFT JOIN order_arrangements oa ON oa.order_id = m.order_id
        LEFT JOIN payments pay ON pay.payment_id = (
            SELECT p2.payment_id
            FROM payments p2
            WHERE p2.order_id = m.order_id
            ORDER BY p2.payment_id DESC
            LIMIT 1
        )
        WHERE (
            (m.sender_id = ? AND m.receiver_id = ?)
            OR
            (m.sender_id = ? AND m.receiver_id = ?)
        )
    ';
    if ($afterId > 0) {
        $sql .= ' AND m.message_id > ?';
    }
    $sql .= ' ORDER BY m.sent_at ASC, m.message_id ASC';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to load chat messages.');
    }
    if ($afterId > 0) {
        $stmt->bind_param(
            'iiiii',
            $buyerId,
            $sellerId,
            $sellerId,
            $buyerId,
            $afterId
        );
    } else {
        $stmt->bind_param(
            'iiii',
            $buyerId,
            $sellerId,
            $sellerId,
            $buyerId
        );
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $orderStageCache = [];
    while ($row = $result->fetch_assoc()) {
        $orderId = (int)($row['order_id'] ?? 0);
        if ($orderId > 0) {
            if (
                (int)($row['order_buyer_id'] ?? 0) !== $buyerId
                || (int)($row['order_seller_id'] ?? 0) !== $sellerId
            ) {
                continue;
            }
        } elseif (!$directThreadOwned) {
            continue;
        }
        // Chat buttons and the detail popup must agree on the same verified stage.
        // Cache per order so a conversation with many messages is not re-queried per bubble.
        if ($orderId > 0 && !isset($orderStageCache[$orderId])) {
            $orderStageCache[$orderId] = buyerReviewStage(
                $conn, $orderId, $buyerId, $sellerId,
                (string)($row['order_status'] ?? ''),
                (string)($row['order_arrangement_status'] ?? 'arranging'),
                (string)($row['order_arrangement_updated_at'] ?? ''),
                (string)($row['order_seller_confirmed_at'] ?? '')
            );
        }
        $messages[] = [
            'message_id' => (int)$row['message_id'],
            'is_order_opening' => (int)($row['is_order_opening'] ?? 0) === 1,
            'sender_id' => (int)$row['sender_id'],
            'receiver_id' => (int)$row['receiver_id'],
            'order_id' => $orderId,
            'message_text' => (string)($row['message_text'] ?? ''),
            'attachment' => (string)($row['attachment'] ?? ''),
            'is_read' => (int)$row['is_read'],
            'sent_at' => (string)$row['sent_at'],
            'time' => chatTime((string)$row['sent_at']),
            'date_key' => dateKey((string)$row['sent_at']),
            'date_label' => dateLabel((string)$row['sent_at']),
            'order_product' => (string)($row['order_product'] ?? ''),
            'order_quantity' => (int)($row['order_quantity'] ?? 0),
            'order_photo' => (string)($row['order_photo'] ?? ''),
            'order_total' => $row['total_amount'] !== null
                ? (float)$row['total_amount']
                : null,
            'payment_method' => (string)($row['payment_method'] ?? ''),
            'payment_status' => (string)($row['payment_status'] ?? ''),
            'order_status' => (string)($row['order_status'] ?? ''),
            'order_closed' => $orderId > 0 && buyerOrderClosedStatus($row['order_status'] ?? ''),
            'order_arrangement_status' => $orderStageCache[$orderId] ?? (string)($row['order_arrangement_status'] ?? ''),
            'display_status' => chatDisplayStatus(
                (string)($row['order_status'] ?? ''),
                (string)($row['payment_status'] ?? ''),
                (string)($row['payment_method'] ?? '')
            )
        ];
    }
    $stmt->close();
    return $messages;
}
function markRead(mysqli $conn, int $buyerId, int $sellerId, int $focusOrderId = 0): void
{
    $includeDirect = (buyerDirectThreadState(
        $conn,
        $buyerId,
        $sellerId
    ) === 1 || buyerHasSellerOrder($conn, $buyerId, $sellerId)) ? 1 : 0;
    $stmt = $conn->prepare('
        UPDATE messages m
        LEFT JOIN orders o ON o.order_id = m.order_id
        SET m.is_read = 1
        WHERE m.receiver_id = ?
          AND m.sender_id = ?
          AND m.is_read = 0
          AND (
                (
                    m.order_id IS NOT NULL
                    AND o.buyer_id = ?
                    AND o.seller_id = ?
                )
                OR
                (? = 1 AND m.order_id IS NULL)
          )
          ' . ($focusOrderId > 0 ? ' AND m.order_id = ?' : '') . '
    ');
    if (!$stmt) {
        return;
    }
    if ($focusOrderId > 0) {
        $stmt->bind_param('iiiiii', $buyerId, $sellerId, $buyerId, $sellerId, $includeDirect, $focusOrderId);
    } else {
        $stmt->bind_param('iiiii', $buyerId, $sellerId, $buyerId, $sellerId, $includeDirect);
    }
    $stmt->execute();
    $stmt->close();
}
$selectedPartnerId = (int)($_GET['seller_id'] ?? $_POST['receiver_id'] ?? 0);
$selectedOrderId = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$selectedProductName = trim((string)($_GET['product_name'] ?? ''));
$selectedProductPrice = trim((string)($_GET['product_price'] ?? ''));
function getBuyerQrSession(
    mysqli $conn,
    int $buyerId,
    int $sellerId,
    int $orderId
): ?array {
    $stmt = $conn->prepare('
        SELECT
            qr_session_id,
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
        FROM paymongo_qr_sessions
        WHERE
            order_id = ?
            AND buyer_id = ?
            AND seller_id = ?
        LIMIT 1
    ');
    if (!$stmt) {
        throw new RuntimeException(
            'Unable to load the QR payment session.'
        );
    }
    $stmt->bind_param(
        'iii',
        $orderId,
        $buyerId,
        $sellerId
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    $expiresTimestamp = strtotime(
        (string)$row['expires_at']
    );
    $generatedTimestamp = strtotime(
        (string)$row['generated_at']
    );
    return [
        'qr_session_id' =>
            (int)$row['qr_session_id'],
        'order_id' =>
            (int)$row['order_id'],
        'buyer_id' =>
            (int)$row['buyer_id'],
        'seller_id' =>
            (int)$row['seller_id'],
        'payment_id' =>
            (int)($row['payment_id'] ?? 0),
        'payment_intent_id' =>
            (string)$row['payment_intent_id'],
        'payment_method_id' =>
            (string)$row['payment_method_id'],
        'qr_image' =>
            (string)$row['qr_image'],
        'test_url' =>
            (string)($row['test_url'] ?? ''),
        'qr_status' =>
            (string)$row['qr_status'],
        'generated_at' =>
            (string)$row['generated_at'],
        'expires_at' =>
            (string)$row['expires_at'],
        'generated_at_unix' =>
            $generatedTimestamp ?: 0,
        'expires_at_unix' =>
            $expiresTimestamp ?: 0,
        'paid_at' =>
            (string)($row['paid_at'] ?? '')
    ];
}
function ensureBuyerPendingPayMongoPayment(
    mysqli $conn,
    int $buyerId,
    int $orderId,
    float $amount
): int {
    $stmt = $conn->prepare('
        SELECT
            payment_id,
            payment_status
        FROM payments
        WHERE order_id = ?
        ORDER BY payment_id DESC
        LIMIT 1
    ');
    if (!$stmt) {
        throw new RuntimeException(
            'Unable to load the payment record.'
        );
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $existing = $stmt
        ->get_result()
        ->fetch_assoc();
    $stmt->close();
    if (
        $existing
        && chatStatusKey(
            $existing['payment_status'] ?? ''
        ) === 'paid'
    ) {
        return (int)$existing['payment_id'];
    }
    $paymentMethod = 'QR Ph';
    $paymentProvider = 'paymongo';
    $pendingStatus = 'pending';
    if ($existing) {
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
            WHERE
                payment_id = ?
                AND order_id = ?
        ');
        if (!$stmt) {
            throw new RuntimeException(
                'Unable to update the payment record.'
            );
        }
        $stmt->bind_param(
            'idsssii',
            $buyerId,
            $amount,
            $paymentMethod,
            $paymentProvider,
            $pendingStatus,
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
        throw new RuntimeException(
            'Unable to create the payment record.'
        );
    }
    $stmt->bind_param(
        'iidsss',
        $orderId,
        $buyerId,
        $amount,
        $paymentMethod,
        $paymentProvider,
        $pendingStatus
    );
    $stmt->execute();
    $paymentId = (int)$stmt->insert_id;
    $stmt->close();
    return $paymentId;
}
/** Render Cart price snapshots without looking up mutable seller prices. */
function messageCartPriceBreakdown(float $finalUnitPrice, int $quantity, string $snapshot): array
{
    $addOns = [];
    $addOnCents = 0;
    foreach (preg_split('/\r?\n/u', $snapshot) ?: [] as $line) {
        // create_order.php saves: - Option name (+₱80.00/unit): optional text
        if (!preg_match('/^\s*-\s*(.+?)\s+\(\+₱([0-9,]+(?:\.[0-9]{2})?)\/unit\)(?::\s*(.*))?\s*$/u', $line, $match)) continue;
        $value = str_replace(',', '', $match[2]);
        if (!is_numeric($value)) continue;
        $cents = (int)round(((float)$value) * 100);
        if ($cents < 0 || $cents > 9999999999) continue;
        $addOnCents += $cents;
        $addOns[] = [
            'name' => trim($match[1]),
            'price_per_unit' => $cents / 100,
            'details' => trim($match[3] ?? ''),
        ];
    }
    $unitCents = (int)round($finalUnitPrice * 100);
    // Never show a fabricated split if an old snapshot is incomplete or malformed.
    $valid = $addOnCents <= $unitCents;
    return [
        'base_unit_price' => $valid ? ($unitCents - $addOnCents) / 100 : $finalUnitPrice,
        'add_on_unit_price' => $valid ? $addOnCents / 100 : 0.0,
        'base_subtotal' => ($valid ? $unitCents - $addOnCents : $unitCents) * $quantity / 100,
        'add_on_subtotal' => ($valid ? $addOnCents : 0) * $quantity / 100,
        'add_ons' => $valid ? $addOns : [],
    ];
}

function getBuyerOrderDetails(
    mysqli $conn,
    int $buyerId,
    int $sellerId,
    int $orderId
): ?array {
    if ($buyerId <= 0 || $sellerId <= 0 || $orderId <= 0) {
        return null;
    }
    $stmt = $conn->prepare('
        SELECT
            o.order_id,
            o.buyer_id,
            o.seller_id,
            o.total_amount,
            o.order_status,
            o.ordered_at,
            pay.payment_id,
            pay.payment_method,
            pay.payment_provider,
            pay.payment_status,
            pay.paid_at,
            oa.fulfillment_method,
            oa.payment_option,
            oa.arrangement_location,
            oa.arrangement_date,
            oa.arrangement_time,
            oa.arrangement_notes,
            oa.buyer_confirmed_at,
            oa.seller_confirmed_at,
            oa.acceptance_deadline,
            oa.seller_accepted_at,
            oa.arrangement_status,
            oa.updated_at AS arrangement_updated_at,
            oa.locked_at,
            oa.expired_at
        FROM orders o
        LEFT JOIN order_arrangements oa
            ON oa.order_id = o.order_id
        LEFT JOIN payments pay
            ON pay.payment_id = (
                SELECT p2.payment_id
                FROM payments p2
                WHERE p2.order_id = o.order_id
                ORDER BY p2.payment_id DESC
                LIMIT 1
            )
        WHERE
            o.order_id = ?
            AND o.buyer_id = ?
            AND o.seller_id = ?
        LIMIT 1
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the order details.');
    }
    $stmt->bind_param(
        'iii',
        $orderId,
        $buyerId,
        $sellerId
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    $itemStmt = $conn->prepare('
        SELECT
            oi.order_item_id,
            oi.product_id,
            oi.quantity,
            oi.unit_price,
            oi.agreed_unit_price,
            oi.special_request,
            oi.subtotal,
            p.product_name,
            p.product_photo
        FROM order_items oi
        INNER JOIN products p
            ON p.product_id = oi.product_id
        WHERE oi.order_id = ?
        ORDER BY oi.order_item_id ASC
    ');
    if (!$itemStmt) {
        throw new RuntimeException('Unable to load the order items.');
    }
    $itemStmt->bind_param('i', $orderId);
    $itemStmt->execute();
    $itemRows = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $itemStmt->close();
    if (!$itemRows) {
        throw new RuntimeException('This order has no product items.');
    }
    $items = [];
    $totalQuantity = 0;
    $listedTotal = 0.0;
    foreach ($itemRows as $itemRow) {
        $quantity = max(1, (int)($itemRow['quantity'] ?? 1));
        $listedPrice = (float)($itemRow['unit_price'] ?? 0);
        $agreedPrice = $itemRow['agreed_unit_price'] !== null
            ? (float)$itemRow['agreed_unit_price']
            : null;
        $subtotal = (float)(
            $itemRow['subtotal']
            ?? ($listedPrice * $quantity)
        );
        $items[] = [
            'order_item_id' => (int)$itemRow['order_item_id'],
            'product_id' => (int)$itemRow['product_id'],
            'product_name' => (string)($itemRow['product_name'] ?? 'Product'),
            'product_photo' => (string)($itemRow['product_photo'] ?? ''),
            'quantity' => $quantity,
            'listed_unit_price' => $listedPrice,
            'agreed_unit_price' => $agreedPrice,
            'special_request' => (string)($itemRow['special_request'] ?? ''),
            'price_breakdown' => messageCartPriceBreakdown($listedPrice, $quantity, (string)($itemRow['special_request'] ?? '')),
            'subtotal' => $subtotal
        ];
        $totalQuantity += $quantity;
        $listedTotal += $listedPrice * $quantity;
    }
    $source = buyerOrderSource($conn, $orderId);
    $fixedPriceOrder = $source === 'cart';
    $firstItem = $items[0];
    $itemCount = count($items);
    $agreedPrice = $itemCount === 1
        ? $firstItem['agreed_unit_price']
        : null;
    $finalTotal = $fixedPriceOrder
        ? (float)$row['total_amount']
        : (
            $agreedPrice !== null
                ? (float)$agreedPrice * (int)$firstItem['quantity']
                : (float)$row['total_amount']
        );
    $qrSession = getBuyerQrSession(
        $conn,
        $buyerId,
        $sellerId,
        $orderId
    );
    $reviewRemaining = null;
    if (chatStatusKey($row['order_status'] ?? '') === 'completed'
        && buyerChatReviewsAvailable($conn)) {
        try {
            $reviewItems = buyerChatCompletedReviewItems($conn, $buyerId, $sellerId, $orderId);
            $reviewRemaining = count(array_filter($reviewItems,
                static fn(array $item): bool => !$item['reviewed']));
        } catch (Throwable $error) {
            error_log('LokalCraft order review lookup: ' . $error->getMessage());
        }
    }
    $reviewStage = buyerReviewStage(
        $conn, $orderId, $buyerId, $sellerId,
        (string)($row['order_status'] ?? ''),
        (string)($row['arrangement_status'] ?? 'arranging'),
        (string)($row['arrangement_updated_at'] ?? ''),
        (string)($row['seller_confirmed_at'] ?? '')
    );
    return [
        'review_remaining' => $reviewRemaining,
        'order_id' => (int)$row['order_id'],
        'buyer_id' => (int)$row['buyer_id'],
        'seller_id' => (int)$row['seller_id'],
        'order_status' => (string)($row['order_status'] ?? ''),
        'next_action' => chatStatusKey($row['order_status'] ?? '') === 'pending'
            && chatStatusKey($reviewStage) === 'awaiting_confirmation'
                ? 'buyer_review'
                : 'none',
        'ordered_at' => (string)($row['ordered_at'] ?? ''),
        'payment_id' => (int)($row['payment_id'] ?? 0),
        'payment_method' => (string)($row['payment_method'] ?? ''),
        'payment_provider' => (string)($row['payment_provider'] ?? ''),
        'payment_status' => (string)($row['payment_status'] ?? ''),
        'paid_at' => (string)($row['paid_at'] ?? ''),
        'order_source' => $source,
        'price_mode' => $fixedPriceOrder ? 'fixed' : 'negotiable',
        'item_count' => $itemCount,
        'items' => $items,
        'product_id' => (int)$firstItem['product_id'],
        'product_name' => $itemCount > 1
            ? $itemCount . ' ready-stock items'
            : (string)$firstItem['product_name'],
        'quantity' => $totalQuantity,
        'listed_unit_price' => $itemCount === 1
            ? (float)$firstItem['listed_unit_price']
            : 0.0,
        'agreed_unit_price' => $agreedPrice,
        'special_request' => $itemCount === 1
            ? (string)$firstItem['special_request']
            : '',
        'estimated_total' => round($listedTotal, 2),
        'final_total' => round($finalTotal, 2),
        'fulfillment_method' => (string)($row['fulfillment_method'] ?? ''),
        'payment_option' => (string)($row['payment_option'] ?? ''),
        'arrangement_location' => (string)($row['arrangement_location'] ?? ''),
        'arrangement_date' => (string)($row['arrangement_date'] ?? ''),
        'arrangement_time' => (string)($row['arrangement_time'] ?? ''),
        'arrangement_notes' => (string)($row['arrangement_notes'] ?? ''),
        'buyer_confirmed_at' => (string)($row['buyer_confirmed_at'] ?? ''),
        'seller_confirmed_at' => (string)($row['seller_confirmed_at'] ?? ''),
        'acceptance_deadline' => (string)($row['acceptance_deadline'] ?? ''),
        'seller_accepted_at' => (string)($row['seller_accepted_at'] ?? ''),
        'arrangement_status' => $reviewStage,
        'locked_at' => (string)($row['locked_at'] ?? ''),
        'expired_at' => (string)($row['expired_at'] ?? ''),
        'qr_session' => $qrSession
    ];
}
function buyerArrangementDateTimeIsPast(
    string $date,
    string $time
): bool {
    if ($date === '' || $time === '') {
        return false;
    }
    $selected = DateTime::createFromFormat(
        'Y-m-d H:i',
        $date . ' ' . substr($time, 0, 5)
    );
    if (!$selected) {
        return false;
    }
    $now = new DateTime('now');
    $now->setTime(
        (int)$now->format('H'),
        (int)$now->format('i'),
        0
    );
    return $selected < $now;
}
/** Store/Product previews in Buyer Messages (read-only listings; no new tables). */
function buyerChatStorePhotoPath(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')
        || preg_match('~^[a-z][a-z0-9+.-]*:~i', $path) || str_contains($path, '?') || str_contains($path, '#')) return '';
    return '../' . $path;
}
function buyerChatStoreSeller(mysqli $conn, int $sellerId): ?array
{
    if ($sellerId <= 0) return null;
    $stmt = $conn->prepare('SELECT u.firstname,u.middlename,u.lastname,COALESCE(b.businessname,\'\') AS businessname,COALESCE(b.craftcategory,\'\') AS craftcategory,COALESCE(b.businessdescription,\'\') AS businessdescription,COALESCE(l.barangay,\'\') AS barangay,COALESCE(l.city,\'\') AS city FROM users u LEFT JOIN businessinfo b ON b.user_id=u.id LEFT JOIN locationinfo l ON l.user_id=u.id WHERE u.id=? AND u.SellerOrNot=1 AND u.AdminOrNot=0 AND LOWER(u.account_status)=\'active\' AND EXISTS (SELECT 1 FROM seller_applications sa WHERE sa.application_id=(SELECT sa2.application_id FROM seller_applications sa2 WHERE sa2.user_id=u.id ORDER BY sa2.submitted_at DESC,sa2.application_id DESC LIMIT 1) AND sa.status=\'approved\') LIMIT 1');
    $stmt->bind_param('i', $sellerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    $name = trim(preg_replace('/\s+/u', ' ', trim((string)$row['firstname'].' '.(string)$row['middlename'].' '.(string)$row['lastname'])) ?? '');
    return ['seller_id'=>$sellerId,'seller_name'=>$name !== '' ? $name : 'Local Seller',
        'businessname'=>(string)$row['businessname'],'craftcategory'=>(string)$row['craftcategory'],
        'description'=>(string)$row['businessdescription'],'barangay'=>(string)$row['barangay'],
        'city'=>(string)$row['city'],'is_active'=>true];
}
function buyerChatStoreProductAllowed(mysqli $conn, int $productId): ?array
{
    if ($productId <= 0) return null;
    $stmt=$conn->prepare('SELECT product_id,seller_id,status,stock,reserved_stock FROM products WHERE product_id=? LIMIT 1');
    $stmt->bind_param('i',$productId);
    $stmt->execute();
    $row=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || !in_array(strtolower(trim((string)$row['status'])),['available','out of stock','out_of_stock'],true)) return null;
    return buyerChatStoreSeller($conn,(int)$row['seller_id']) ? $row : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action !== '') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $csrfToken = (string)($_POST['csrf_token'] ?? '');
            if (
                empty($_SESSION['buyer_messages_csrf'])
                || !hash_equals((string)$_SESSION['buyer_messages_csrf'], $csrfToken)
            ) {
                throw new RuntimeException('Session expired. Refresh the page and try again.');
            }
            if ($buyerId <= 0) {
                throw new RuntimeException('Your buyer account could not be identified.');
            }
            if ($action === 'chat_store_load') {
                $sellerId=(int)($_POST['seller_id'] ?? 0);
                $shop=buyerChatStoreSeller($conn,$sellerId);
                if (!$shop) throw new RuntimeException('This store is currently unavailable.');
                $reviewsReady=buyerChatReviewsAvailable($conn);
                // Show the store's real review rating (weighted across all its products).
                $shop['review_count']=0;
                $shop['avg_rating']=0.0;
                if ($reviewsReady) {
                    try {
                        $shopReviews=$conn->prepare('SELECT COUNT(*),COALESCE(AVG(r.rating),0) FROM reviews r INNER JOIN products p ON p.product_id=r.product_id WHERE p.seller_id=?');
                        if ($shopReviews) {
                            $shopReviews->bind_param('i',$sellerId);
                            $shopReviews->execute();
                            $storeReviewCount=0;
                            $storeAvgRating=0.0;
                            $shopReviews->bind_result($storeReviewCount,$storeAvgRating);
                            $shopReviews->fetch();
                            $shopReviews->close();
                            $shop['review_count']=(int)$storeReviewCount;
                            $shop['avg_rating']=$storeReviewCount>0?round((float)$storeAvgRating,1):0.0;
                        }
                    } catch (Throwable $reviewError) {
                        error_log('LokalCraft Buyer Messages store rating: '.$reviewError->getMessage());
                    }
                }
                $reviewJoin=$reviewsReady ? ' LEFT JOIN (SELECT product_id,AVG(rating) AS avg_rating,COUNT(*) AS review_count FROM reviews GROUP BY product_id) rs ON rs.product_id=p.product_id ' : '';
                $reviewFields=$reviewsReady ? 'COALESCE(rs.avg_rating,0) AS avg_rating,COALESCE(rs.review_count,0) AS review_count' : '0 AS avg_rating,0 AS review_count';
                $sql='SELECT p.product_id,p.product_photo,p.product_name,p.craft_category,p.description,p.cost,p.stock,p.reserved_stock,p.status,GREATEST(p.stock-p.reserved_stock,0) AS available_stock,sp.saved_id,'.$reviewFields
                    .' FROM products p LEFT JOIN saved_products sp ON sp.product_id=p.product_id AND sp.buyer_id=? '.$reviewJoin
                    .' WHERE p.seller_id=? AND LOWER(TRIM(p.status)) IN (\'available\',\'out of stock\',\'out_of_stock\') ORDER BY p.created_at DESC,p.product_id DESC';
                $stmt=$conn->prepare($sql);
                $stmt->bind_param('ii',$buyerId,$sellerId);
                $stmt->execute();
                $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                $items=[];
                foreach ($rows as $r) {
                    $stock=max(0,(int)$r['available_stock']);
                    $available=strtolower(trim((string)$r['status']))==='available' && $stock>0;
                    $items[]=['product_id'=>(int)$r['product_id'],
                        'product_photo'=>buyerChatStorePhotoPath((string)($r['product_photo']??'')),
                        'product_name'=>(string)$r['product_name'],'craft_category'=>(string)$r['craft_category'],
                        'description'=>(string)($r['description']??''),
                        'cost'=>'₱'.number_format((float)$r['cost'],2),
                        'cost_value'=>(float)$r['cost'],'stock'=>$available?$stock:0,
                        'is_out_of_stock'=>!$available,'seller_id'=>$sellerId,
                        'seller_name'=>$shop['businessname']!==''?$shop['businessname']:$shop['seller_name'],
                        'seller_business'=>$shop['businessname'],'seller_person'=>$shop['seller_name'],
                        'barangay'=>$shop['barangay'],'city'=>$shop['city'],
                        'avg_rating'=>(float)$r['avg_rating'],'review_count'=>(int)$r['review_count'],
                        'is_saved'=>!empty($r['saved_id'])];
                }
                echo json_encode(['success'=>true,'shop'=>$shop,'products'=>$items],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            if ($action === 'chat_store_reviews') {
                $productId=(int)($_POST['product_id']??0);
                if (!buyerChatStoreProductAllowed($conn,$productId)) throw new RuntimeException('Product is currently unavailable.');
                $offset=max(0,min(100000,(int)($_POST['offset']??0)));
                $limit=$offset===0?2:10;
                if (!buyerChatReviewsAvailable($conn)) {
                    echo json_encode(['success'=>true,'average_rating'=>0,'review_count'=>0,'reviews'=>[],'next_offset'=>0,'has_more'=>false]);
                    exit;
                }
                $stats=$conn->prepare('SELECT COUNT(*),COALESCE(AVG(rating),0) FROM reviews WHERE product_id=?');
                $stats->bind_param('i',$productId);
                $stats->execute();
                $stats->bind_result($count,$avg);
                $stats->fetch();
                $stats->close();
                $stmt=$conn->prepare('SELECT r.rating,r.review_text,r.created_at,u.firstname,u.lastname FROM reviews r INNER JOIN users u ON u.id=r.buyer_id WHERE r.product_id=? ORDER BY r.created_at DESC,r.review_id DESC LIMIT ? OFFSET ?');
                $stmt->bind_param('iii',$productId,$limit,$offset);
                $stmt->execute();
                $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                $reviews=[];
                foreach ($rows as $r) {
                    $first=trim((string)($r['firstname']??''));$last=trim((string)($r['lastname']??''));
                    $initial=$last!=='' ? (function_exists('mb_substr') ? mb_substr($last,0,1,'UTF-8') : substr($last,0,1)) : '';
                    $reviews[]=['buyer_name'=>($first?:'Buyer').($initial!==''?' '.strtoupper($initial).'.':''),
                        'rating'=>max(1,min(5,(int)$r['rating'])),'review_text'=>(string)($r['review_text']??''),
                        'created_at'=>(string)$r['created_at']];
                }
                $next=$offset+count($reviews);
                echo json_encode(['success'=>true,'average_rating'=>round((float)$avg,1),'review_count'=>(int)$count,
                    'reviews'=>$reviews,'next_offset'=>$next,'has_more'=>$next<(int)$count],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            if ($action === 'chat_store_save') {
                $productId=(int)($_POST['product_id']??0);
                if (!buyerChatStoreProductAllowed($conn,$productId)) throw new RuntimeException('Product is currently unavailable.');
                $stmt=$conn->prepare('SELECT saved_id FROM saved_products WHERE buyer_id=? AND product_id=? LIMIT 1');
                $stmt->bind_param('ii',$buyerId,$productId);$stmt->execute();
                $exists=$stmt->get_result()->fetch_assoc();$stmt->close();
                if ($exists) {
                    $stmt=$conn->prepare('DELETE FROM saved_products WHERE buyer_id=? AND product_id=?');
                    $saved=false;
                } else {
                    $stmt=$conn->prepare('INSERT INTO saved_products (buyer_id,product_id,saved_at) VALUES (?,?,NOW())');
                    $saved=true;
                }
                $stmt->bind_param('ii',$buyerId,$productId);$stmt->execute();$stmt->close();
                echo json_encode(['success'=>true,'saved'=>$saved]);exit;
            }
            if ($action === 'chat_store_cart') {
                $productId=(int)($_POST['product_id']??0);
                if ($productId<=0) throw new RuntimeException('Invalid product.');
                $conn->begin_transaction();
                try {
                    $stmt=$conn->prepare('SELECT p.product_id,p.seller_id,p.stock,p.reserved_stock,p.status FROM products p WHERE p.product_id=? FOR UPDATE');
                    $stmt->bind_param('i',$productId);$stmt->execute();
                    $p=$stmt->get_result()->fetch_assoc();$stmt->close();
                    if (!$p || strtolower(trim((string)$p['status']))!=='available' || (int)$p['seller_id']===$buyerId
                        || !buyerChatStoreSeller($conn,(int)$p['seller_id'])) throw new RuntimeException('This product is currently unavailable.');
                    $stock=max(0,(int)$p['stock']-(int)$p['reserved_stock']);
                    if ($stock<1) throw new RuntimeException('This product is out of stock.');
                    $stmt=$conn->prepare('SELECT cart_id,quantity FROM cart WHERE buyer_id=? AND product_id=? LIMIT 1 FOR UPDATE');
                    $stmt->bind_param('ii',$buyerId,$productId);$stmt->execute();
                    $existing=$stmt->get_result()->fetch_assoc();$stmt->close();
                    $newQty=$existing ? min(999,$stock,max(1,(int)$existing['quantity'])+1) : 1;
                    if ($existing && (int)$existing['quantity'] >= min(999,$stock)) throw new RuntimeException('All available units are already in your cart.');
                    if ($existing) {
                        $cartId=(int)$existing['cart_id'];
                        $stmt=$conn->prepare('UPDATE cart SET quantity=? WHERE cart_id=? AND buyer_id=?');
                        $stmt->bind_param('iii',$newQty,$cartId,$buyerId);
                    } else {
                        $stmt=$conn->prepare('INSERT INTO cart (buyer_id,product_id,quantity,added_at) VALUES (?,?,1,NOW())');
                        $stmt->bind_param('ii',$buyerId,$productId);
                    }
                    $stmt->execute();$stmt->close();$conn->commit();
                } catch (Throwable $e) { $conn->rollback();throw $e; }
                echo json_encode(['success'=>true,'cart_quantity'=>$newQty]);exit;
            }
            if ($action === 'review_products' || $action === 'submit_review') {
                $sellerId = (int)($_POST['seller_id'] ?? 0);
                $orderId = (int)($_POST['order_id'] ?? 0);
                if ($sellerId <= 0 || $orderId <= 0) {
                    throw new RuntimeException('Select a completed order first.');
                }
                if ($action === 'review_products') {
                    $items = buyerChatCompletedReviewItems($conn, $buyerId, $sellerId, $orderId);
                    echo json_encode(['success' => true, 'products' => $items], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $orderItemId = (int)($_POST['order_item_id'] ?? 0);
                $rating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT);
                $reviewText = trim((string)($_POST['review_text'] ?? ''));
                $textLength = function_exists('mb_strlen')
                    ? mb_strlen($reviewText, 'UTF-8')
                    : preg_match_all('/./us', $reviewText);
                if ($textLength === false) {
                    throw new RuntimeException('Enter valid review text.');
                }
                if ($orderItemId <= 0 || $rating === false || $rating < 1 || $rating > 5) {
                    throw new RuntimeException('Select a product and a rating from 1 to 5.');
                }
                if ($textLength > 500 || ($reviewText !== '' && $textLength < 2)) {
                    throw new RuntimeException('Your review must be 2–500 characters, or leave it blank.');
                }
                $conn->begin_transaction();
                try {
                    $lock = $conn->prepare('SELECT order_status FROM orders WHERE order_id = ? AND buyer_id = ? AND seller_id = ? LIMIT 1 FOR UPDATE');
                    if (!$lock) throw new RuntimeException('Unable to check the order.');
                    $lock->bind_param('iii', $orderId, $buyerId, $sellerId);
                    $lock->execute();
                    $row = $lock->get_result()->fetch_assoc();
                    $lock->close();
                    if (!$row || chatStatusKey($row['order_status']) !== 'completed') {
                        throw new RuntimeException('This order is not completed yet.');
                    }
                    $itemCheck = $conn->prepare('SELECT product_id FROM order_items
                        WHERE order_item_id = ? AND order_id = ? LIMIT 1');
                    if (!$itemCheck) throw new RuntimeException('Unable to check this product.');
                    $itemCheck->bind_param('ii', $orderItemId, $orderId);
                    $itemCheck->execute();
                    $item = $itemCheck->get_result()->fetch_assoc();
                    $itemCheck->close();
                    if (!$item) throw new RuntimeException('This product does not belong to the selected order.');
                    if (!buyerChatReviewsAvailable($conn)) throw new RuntimeException('Product reviews are unavailable.');
                    $existing = $conn->prepare('SELECT review_id FROM reviews WHERE order_item_id = ? LIMIT 1');
                    if (!$existing) throw new RuntimeException('Unable to check your existing review.');
                    $existing->bind_param('i', $orderItemId);
                    $existing->execute();
                    $alreadyReviewed = (bool)$existing->get_result()->fetch_assoc();
                    $existing->close();
                    if ($alreadyReviewed) throw new RuntimeException('You already reviewed this order item.');
                    $productId = (int)$item['product_id'];
                    $save = $conn->prepare('INSERT INTO reviews
                        (order_item_id, order_id, product_id, buyer_id, seller_id, rating, review_text, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
                    if (!$save) throw new RuntimeException('Unable to save your review.');
                    $save->bind_param('iiiiiis', $orderItemId, $orderId, $productId, $buyerId, $sellerId, $rating, $reviewText);
                    $save->execute();
                    $save->close();
                    lokalcraftCreateNotification(
                        $conn,
                        $sellerId,
                        'seller_product_review',
                        'New Product Review',
                        'A buyer rated a product in Order #' . $orderId . ' with ' . $rating
                            . ($rating === 1 ? ' star.' : ' stars.'),
                        'order',
                        $orderId,
                        'seller-product-review-order-' . $orderId . '-item-' . $orderItemId
                    );
                    $conn->commit();
                    echo json_encode(['success' => true, 'message' => 'Review submitted.'], JSON_UNESCAPED_UNICODE);
                    exit;
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }
            }
            if ($action === 'fetch_order_details') {
                $sellerId = (int)($_POST['seller_id'] ?? 0);
                $orderId = (int)($_POST['order_id'] ?? 0);
                if (
                    $sellerId <= 0
                    || $orderId <= 0
                    || !sellerExists($conn, $sellerId)
                    || !validOrder($conn, $buyerId, $sellerId, $orderId)
                ) {
                    throw new RuntimeException('Order not found.');
                }
                $order = getBuyerOrderDetails(
                    $conn,
                    $buyerId,
                    $sellerId,
                    $orderId
                );
                echo json_encode([
                    'success' => true,
                    'order' => $order
                ]);
                exit;
            }
            if ($action === 'confirm_arrangement') {
                $sellerId = (int)($_POST['seller_id'] ?? 0);
                $orderId = (int)($_POST['order_id'] ?? 0);
                if (
                    $sellerId <= 0
                    || $orderId <= 0
                    || !sellerExists($conn, $sellerId)
                    || !validOrder($conn, $buyerId, $sellerId, $orderId)
                ) {
                    throw new RuntimeException('This order cannot be confirmed.');
                }
                if (buyerOrderSource($conn, $orderId) !== 'cart') {
                    throw new RuntimeException('Past chat orders are view-only. New orders start in Cart.');
                }
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare('
                        SELECT
                            o.order_id,
                            o.order_status,
                            o.total_amount,
                            oa.arrangement_status,
                            oa.updated_at AS arrangement_updated_at,
                            oa.seller_confirmed_at,
                            oa.fulfillment_method,
                            oa.payment_option,
                            oa.arrangement_location,
                            oa.arrangement_date,
                            oa.arrangement_time,
                            (
                                SELECT oi.agreed_unit_price
                                FROM order_items oi
                                WHERE oi.order_id = o.order_id
                                ORDER BY oi.order_item_id ASC
                                LIMIT 1
                            ) AS agreed_unit_price
                        FROM orders o
                        INNER JOIN order_arrangements oa
                            ON oa.order_id = o.order_id
                        WHERE
                            o.order_id = ?
                            AND o.buyer_id = ?
                            AND o.seller_id = ?
                        LIMIT 1
                        FOR UPDATE
                    ');
                    if (!$stmt) {
                        throw new RuntimeException(
                            'Unable to prepare the confirmation.'
                        );
                    }
                    $stmt->bind_param(
                        'iii',
                        $orderId,
                        $buyerId,
                        $sellerId
                    );
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$row) {
                        throw new RuntimeException('Order details not found.');
                    }
                    if (
                        chatStatusKey($row['order_status'] ?? '') !== 'pending'
                    ) {
                        throw new RuntimeException(
                            'This order request can no longer be confirmed.'
                        );
                    }
                    $reviewStage = buyerReviewStage(
                        $conn, $orderId, $buyerId, $sellerId,
                        (string)$row['order_status'],
                        (string)($row['arrangement_status'] ?? ''),
                        (string)($row['arrangement_updated_at'] ?? ''),
                        (string)($row['seller_confirmed_at'] ?? '')
                    );
                    if (chatStatusKey($reviewStage) !== 'awaiting_confirmation') {
                        throw new RuntimeException(
                            'These order details are not ready for your confirmation.'
                        );
                    }
                    $fixedPriceOrder =
                        buyerOrderSource($conn, $orderId) === 'cart';
                    if (
                        (
                            !$fixedPriceOrder
                            && (float)($row['agreed_unit_price'] ?? 0) <= 0
                        )
                        || (
                            $fixedPriceOrder
                            && (float)($row['total_amount'] ?? 0) <= 0
                        )
                        || trim((string)($row['fulfillment_method'] ?? '')) === ''
                        || trim((string)($row['payment_option'] ?? '')) === ''
                        || trim((string)($row['arrangement_location'] ?? '')) === ''
                        || trim((string)($row['arrangement_date'] ?? '')) === ''
                        || trim((string)($row['arrangement_time'] ?? '')) === ''
                    ) {
                        throw new RuntimeException(
                            'The seller must complete the order details before you can confirm.'
                        );
                    }
                    $selectedDelivery = chatStatusKey($row['fulfillment_method'] ?? '');
                    $selectedPayment = chatStatusKey($row['payment_option'] ?? '');
                    if (
                        !in_array($selectedDelivery, ['meetup', 'pickup', 'third_party_delivery'], true)
                        || !in_array($selectedPayment, ['cash', 'paymongo'], true)
                        || ($selectedPayment === 'cash' && !in_array($selectedDelivery, ['meetup', 'pickup'], true))
                    ) {
                        throw new RuntimeException(
                            'This Delivery and Payment combination is not allowed. Ask the seller to correct it.'
                        );
                    }
                    $arrangementDate = trim(
                        (string)($row['arrangement_date'] ?? '')
                    );
                    $arrangementTime = trim(
                        (string)($row['arrangement_time'] ?? '')
                    );
                    if (
                        buyerArrangementDateTimeIsPast(
                            $arrangementDate,
                            $arrangementTime
                        )
                    ) {
                        throw new RuntimeException(
                            'The Date or Time has already passed. Ask the seller to update the order details.'
                        );
                    }
                    $newStatus = 'awaiting_seller_acceptance';
                    $stmt = $conn->prepare('
                        UPDATE order_arrangements
                        SET
                            buyer_confirmed_at = NOW(),
                            arrangement_status = ?,
                            locked_at = NOW(),
                            updated_at = NOW()
                        WHERE
                            order_id = ?
                            AND arrangement_status = ?
                    ');
                    if (!$stmt) {
                        throw new RuntimeException(
                            'Unable to confirm the order details.'
                        );
                    }
                    $currentStoredStage = (string)$row['arrangement_status'];
                    $stmt->bind_param(
                        'sis',
                        $newStatus,
                        $orderId,
                        $currentStoredStage
                    );
                    $stmt->execute();
                    if ($stmt->affected_rows !== 1) {
                        $stmt->close();
                        throw new RuntimeException(
                            'The order details changed. Please check them again.'
                        );
                    }
                    $stmt->close();
                    $messageText =
                        'Buyer confirmed the order details for Order #'
                        . $orderId
                        . '. The order is now waiting for the seller to accept it.';
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
                    if (!$stmt) {
                        throw new RuntimeException(
                            'Unable to notify the seller.'
                        );
                    }
                    $stmt->bind_param(
                        'iiis',
                        $buyerId,
                        $sellerId,
                        $orderId,
                        $messageText
                    );
                    $stmt->execute();
                    $notificationMessageId = (int)$stmt->insert_id;
                    $stmt->close();
                    if ($notificationMessageId <= 0) {
                        throw new RuntimeException('Unable to save the seller confirmation message.');
                    }
                    lokalcraftCreateNotification(
                        $conn,
                        $sellerId,
                        'seller_order_confirmed',
                        'Order Details Confirmed',
                        'The buyer confirmed the details for Order #' . $orderId
                            . '. You can now review and accept this order.',
                        'order',
                        $orderId,
                        'seller_order_' . $orderId . '_confirmed_message_' . $notificationMessageId
                    );
                    $conn->commit();
                    $updatedOrder = getBuyerOrderDetails(
                        $conn,
                        $buyerId,
                        $sellerId,
                        $orderId
                    );
                    echo json_encode([
                        'success' => true,
                        'message' => 'Order details confirmed. Waiting for the seller to accept your order.',
                        'order' => $updatedOrder
                    ]);
                    exit;
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }
            }
            if ($action === 'request_changes') {
                $sellerId = (int)($_POST['seller_id'] ?? 0);
                $orderId = (int)($_POST['order_id'] ?? 0);
                if ($sellerId <= 0 || $orderId <= 0 || !validOrder($conn, $buyerId, $sellerId, $orderId)) {
                    throw new RuntimeException('Order not found.');
                }
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare('SELECT o.order_status, oa.arrangement_status,
                            oa.updated_at AS arrangement_updated_at, oa.seller_confirmed_at
                        FROM orders o INNER JOIN order_arrangements oa ON oa.order_id = o.order_id
                        WHERE o.order_id = ? AND o.buyer_id = ? AND o.seller_id = ? LIMIT 1 FOR UPDATE');
                    $stmt->bind_param('iii', $orderId, $buyerId, $sellerId);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$row || chatStatusKey($row['order_status']) !== 'pending'
                        || chatStatusKey(buyerReviewStage($conn, $orderId, $buyerId, $sellerId,
                            (string)$row['order_status'], (string)$row['arrangement_status'],
                            (string)($row['arrangement_updated_at'] ?? ''),
                            (string)($row['seller_confirmed_at'] ?? ''))) !== 'awaiting_confirmation') {
                        throw new RuntimeException('The order is no longer waiting for your review. Refresh its details.');
                    }
                    $stmt = $conn->prepare('UPDATE order_arrangements SET arrangement_status = "arranging",
                        buyer_confirmed_at = NULL, seller_confirmed_at = NULL, locked_at = NULL,
                        updated_at = NOW() WHERE order_id = ? AND arrangement_status = ?');
                    $storedStage = (string)$row['arrangement_status'];
                    $stmt->bind_param('is', $orderId, $storedStage);
                    $stmt->execute();
                    $changed = $stmt->affected_rows;
                    $stmt->close();
                    if ($changed !== 1) throw new RuntimeException('The order details changed. Refresh and try again.');
                    $messageText = 'Buyer requested changes to Order #' . $orderId . '. Please update the details and send them again.';
                    $stmt = $conn->prepare('INSERT INTO messages
                        (sender_id, receiver_id, order_id, message_text, attachment, is_read, sent_at)
                        VALUES (?, ?, ?, ?, "", 0, NOW())');
                    $stmt->bind_param('iiis', $buyerId, $sellerId, $orderId, $messageText);
                    $stmt->execute();
                    $notificationMessageId = (int)$stmt->insert_id;
                    $stmt->close();
                    if ($notificationMessageId <= 0) {
                        throw new RuntimeException('Unable to save the seller change request message.');
                    }
                    lokalcraftCreateNotification(
                        $conn,
                        $sellerId,
                        'seller_changes_requested',
                        'Changes Requested',
                        'The buyer requested changes to Order #' . $orderId
                            . '. Update the order details and send them again.',
                        'order',
                        $orderId,
                        'seller_order_' . $orderId . '_changes_requested_message_' . $notificationMessageId
                    );
                    $conn->commit();
                    echo json_encode(['success' => true, 'message' => 'Changes requested. Waiting for the seller.',
                        'order' => getBuyerOrderDetails($conn, $buyerId, $sellerId, $orderId)]);
                    exit;
                } catch (Throwable $exception) {
                    $conn->rollback();
                    throw $exception;
                }
            }
            if ($action === 'generate_paymongo_qr') {
                $sellerId = (int)($_POST['seller_id'] ?? 0);
                $orderId = (int)($_POST['order_id'] ?? 0);
                if (
                    $sellerId <= 0
                    || $orderId <= 0
                    || !sellerExists($conn, $sellerId)
                    || !validOrder(
                        $conn,
                        $buyerId,
                        $sellerId,
                        $orderId
                    )
                ) {
                    throw new RuntimeException(
                        'Invalid QR payment request.'
                    );
                }
                if (
                    !function_exists(
                        'paymongoGenerateQrPh'
                    )
                    || !function_exists(
                        'paymongoRetrievePaymentIntent'
                    )
                ) {
                    throw new RuntimeException(
                        'Direct QR Ph is not configured. '
                        . 'Update config/paymongo.php first.'
                    );
                }
                $order = getBuyerOrderDetails(
                    $conn,
                    $buyerId,
                    $sellerId,
                    $orderId
                );
                if (!$order) {
                    throw new RuntimeException(
                        'Order not found.'
                    );
                }
                if (!buyerOrderCanPayWithPayMongo($order)) {
                    throw new RuntimeException(
                        'This order is not ready for QR Ph payment.'
                    );
                }
                $finalTotal = round(
                    (float)($order['final_total'] ?? 0),
                    2
                );
                if ($finalTotal <= 0) {
                    throw new RuntimeException(
                        'The final order amount is invalid.'
                    );
                }
                $existingQr = getBuyerQrSession(
                    $conn,
                    $buyerId,
                    $sellerId,
                    $orderId
                );
                if ($existingQr) {
                    $existingStatus = chatStatusKey(
                        $existingQr['qr_status'] ?? ''
                    );
                    $existingExpiry = (int)(
                        $existingQr['expires_at_unix'] ?? 0
                    );
                    if (
                        $existingStatus === 'paid'
                        || chatStatusKey(
                            $order['payment_status'] ?? ''
                        ) === 'paid'
                    ) {
                        throw new RuntimeException(
                            'This order is already paid.'
                        );
                    }
                    if (
                        $existingStatus === 'active'
                        && $existingExpiry > time()
                    ) {
                        echo json_encode([
                            'success' => true,
                            'reused' => true,
                            'qr' => $existingQr,
                            'order' => $order,
                            'message' =>
                                'Your current QR is still active.'
                        ]);
                        exit;
                    }
                    /*
                     * Before replacing an expired local QR,
                     * check PayMongo once in case payment completed
                     * just before the countdown ended.
                     */
                    $existingIntentId = trim(
                        (string)(
                            $existingQr['payment_intent_id']
                            ?? ''
                        )
                    );
                    if ($existingIntentId !== '') {
                        $remoteStatus = '';
                        try {
                            $remoteIntent =
                                paymongoRetrievePaymentIntent(
                                    $existingIntentId
                                );
                            $remoteStatus = chatStatusKey(
                                $remoteIntent['attributes']['status']
                                ?? ''
                            );
                            if ($remoteStatus === 'processing') {
                                throw new RuntimeException('Your previous QR payment is still processing. Please wait before generating another QR.');
                            }
                            if ($remoteStatus === 'succeeded') {
                                lokalcraftConfirmQrFromIntent($conn, $remoteIntent, $orderId);
                                $updatedOrder =
                                    getBuyerOrderDetails(
                                        $conn,
                                        $buyerId,
                                        $sellerId,
                                        $orderId
                                    );
                                echo json_encode([
                                    'success' => true,
                                    'paid' => true,
                                    'order' => $updatedOrder,
                                    'message' =>
                                        'Payment already confirmed.'
                                ]);
                                exit;
                            }
                        } catch (Throwable $error) {
                            // Never overwrite a remotely paid QR if the payment
                            // reference has not propagated yet or settlement failed.
                            if (in_array($remoteStatus, ['succeeded', 'processing'], true)) throw $error;
                            // If status could not be checked at all, do not
                            // replace this intent until PayMongo is reachable.
                            if ($remoteStatus === '') throw $error;
                        }
                    }
                }
                $buyerStmt = $conn->prepare('
                    SELECT
                        firstname,
                        lastname,
                        email,
                        phone
                    FROM users
                    WHERE id = ?
                    LIMIT 1
                ');
                if (!$buyerStmt) {
                    throw new RuntimeException(
                        'Unable to load buyer information.'
                    );
                }
                $buyerStmt->bind_param(
                    'i',
                    $buyerId
                );
                $buyerStmt->execute();
                $buyerRow = $buyerStmt
                    ->get_result()
                    ->fetch_assoc() ?: [];
                $buyerStmt->close();
                $buyerName = trim(
                    (string)($buyerRow['firstname'] ?? '')
                    . ' '
                    . (string)($buyerRow['lastname'] ?? '')
                );
                if ($buyerName === '') {
                    $buyerName = 'LokalCraft Buyer';
                }
                $buyerEmail = trim(
                    (string)($buyerRow['email'] ?? '')
                );
                $buyerPhone = trim(
                    (string)($buyerRow['phone'] ?? '')
                );
                $amountCentavos = (int)round(
                    $finalTotal * 100
                );
                $platformShare = round(
                    $finalTotal * 0.05,
                    2
                );
                $sellerShare = round(
                    $finalTotal - $platformShare,
                    2
                );
                $metadata = [
                    'lokalcraft_order_id' =>
                        (string)$orderId,
                    'lokalcraft_buyer_id' =>
                        (string)$buyerId,
                    'lokalcraft_seller_id' =>
                        (string)$sellerId,
                    'platform_fee_percent' => '5',
                    'platform_share_php' =>
                        number_format(
                            $platformShare,
                            2,
                            '.',
                            ''
                        ),
                    'seller_share_php' =>
                        number_format(
                            $sellerShare,
                            2,
                            '.',
                            ''
                        )
                ];
                $billing = [
                    'name' => $buyerName
                ];
                if (
                    filter_var(
                        $buyerEmail,
                        FILTER_VALIDATE_EMAIL
                    ) !== false
                ) {
                    $billing['email'] = $buyerEmail;
                }
                if ($buyerPhone !== '') {
                    $billing['phone'] = $buyerPhone;
                }
                $paymentId =
                    ensureBuyerPendingPayMongoPayment(
                        $conn,
                        $buyerId,
                        $orderId,
                        $finalTotal
                    );
                $qr = paymongoGenerateQrPh(
                    $amountCentavos,
                    'LokalCraft Order #' . $orderId,
                    $metadata,
                    $billing
                );
                $generatedAt = date(
                    'Y-m-d H:i:s',
                    (int)$qr['created_at']
                );
                $expiresAt = date(
                    'Y-m-d H:i:s',
                    (int)$qr['expires_at']
                );
                $paymentIntentId = trim(
                    (string)$qr['payment_intent_id']
                );
                $paymentMethodId = trim(
                    (string)$qr['payment_method_id']
                );
                $qrImage = trim(
                    (string)$qr['qr_image']
                );
                $testUrl = trim(
                    (string)($qr['test_url'] ?? '')
                );
                $qrStatus = 'active';
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
                        payment_intent_id =
                            VALUES(payment_intent_id),
                        payment_method_id =
                            VALUES(payment_method_id),
                        qr_image = VALUES(qr_image),
                        test_url = VALUES(test_url),
                        qr_status = VALUES(qr_status),
                        generated_at =
                            VALUES(generated_at),
                        expires_at =
                            VALUES(expires_at),
                        paid_at = NULL,
                        updated_at = NOW()
                ');
                if (!$stmt) {
                    throw new RuntimeException(
                        'Unable to save the QR payment session.'
                    );
                }
                $stmt->bind_param(
                    'iiiisssssss',
                    $orderId,
                    $buyerId,
                    $sellerId,
                    $paymentId,
                    $paymentIntentId,
                    $paymentMethodId,
                    $qrImage,
                    $testUrl,
                    $qrStatus,
                    $generatedAt,
                    $expiresAt
                );
                $stmt->execute();
                $stmt->close();
                $stmt = $conn->prepare('UPDATE payments
                    SET paymongo_payment_intent_id = ?, paymongo_payment_id = NULL,
                        payment_reference = NULL
                    WHERE payment_id = ? AND order_id = ? AND payment_status <> "paid"');
                $stmt->bind_param('sii', $paymentIntentId, $paymentId, $orderId);
                $stmt->execute();
                $stmt->close();
                $savedQr = getBuyerQrSession(
                    $conn,
                    $buyerId,
                    $sellerId,
                    $orderId
                );
                echo json_encode([
                    'success' => true,
                    'reused' => false,
                    'qr' => $savedQr,
                    'order' => getBuyerOrderDetails(
                        $conn,
                        $buyerId,
                        $sellerId,
                        $orderId
                    ),
                    'message' =>
                        'QR Ph is ready. It is valid for 15 minutes.'
                ]);
                exit;
            }
            if ($action === 'check_paymongo_payment') {
                $sellerId = (int)($_POST['seller_id'] ?? 0);
                $orderId = (int)($_POST['order_id'] ?? 0);
                if (
                    $sellerId <= 0
                    || $orderId <= 0
                    || !sellerExists($conn, $sellerId)
                    || !validOrder(
                        $conn,
                        $buyerId,
                        $sellerId,
                        $orderId
                    )
                ) {
                    throw new RuntimeException(
                        'Invalid payment check.'
                    );
                }
                if (
                    !function_exists(
                        'paymongoRetrievePaymentIntent'
                    )
                ) {
                    throw new RuntimeException(
                        'PayMongo payment checking is not configured.'
                    );
                }
                $currentStatus = buyerOrderStatus($conn, $buyerId, $sellerId, $orderId);
                if ($currentStatus === null || buyerOrderClosedStatus($currentStatus)) {
                    throw new RuntimeException('This order is closed. QR Ph payment is no longer available.');
                }
                $qr = getBuyerQrSession(
                    $conn,
                    $buyerId,
                    $sellerId,
                    $orderId
                );
                if (!$qr) {
                    echo json_encode([
                        'success' => true,
                        'paid' => false,
                        'expired' => false,
                        'qr' => null,
                        'message' =>
                            'No QR payment has been generated yet.'
                    ]);
                    exit;
                }
                if (
                    chatStatusKey(
                        $qr['qr_status'] ?? ''
                    ) === 'paid'
                ) {
                    echo json_encode([
                        'success' => true,
                        'paid' => true,
                        'expired' => false,
                        'qr' => $qr,
                        'order' => getBuyerOrderDetails(
                            $conn,
                            $buyerId,
                            $sellerId,
                            $orderId
                        ),
                        'message' =>
                            'Payment is already confirmed.'
                    ]);
                    exit;
                }
                $remoteIntent =
                    paymongoRetrievePaymentIntent(
                        (string)$qr[
                            'payment_intent_id'
                        ]
                    );
                $remoteStatus = chatStatusKey(
                    $remoteIntent['attributes']['status']
                    ?? ''
                );
                if ($remoteStatus === 'succeeded') {
                    lokalcraftConfirmQrFromIntent($conn, $remoteIntent, $orderId);
                    $updatedOrder =
                        getBuyerOrderDetails(
                            $conn,
                            $buyerId,
                            $sellerId,
                            $orderId
                        );
                    echo json_encode([
                        'success' => true,
                        'paid' => true,
                        'expired' => false,
                        'qr' =>
                            $updatedOrder['qr_session']
                            ?? null,
                        'order' => $updatedOrder,
                        'message' =>
                            'Payment confirmed.'
                    ]);
                    exit;
                }
                // A generated QR already has a Payment Method attached.
                // After the PayMongo Test Mode user selects Fail, its Payment
                // Intent can return to awaiting_payment_method (there is no
                // separate failed Payment Intent status). Only treat that as
                // failed for the CURRENT attached QR; never mark an untouched
                // or still-processing attempt as failed.
                if (
                    $remoteStatus === 'awaiting_payment_method'
                    && trim((string)($qr['payment_method_id'] ?? '')) !== ''
                ) {
                    $conn->begin_transaction();
                    try {
                        // Use the same payment-first lock order as the paid
                        // handler to prevent a failed poll from overwriting Paid.
                        $paymentLock = $conn->prepare('
                            SELECT payment_status FROM payments
                            WHERE payment_id = ? AND order_id = ? AND buyer_id = ?
                            LIMIT 1 FOR UPDATE
                        ');
                        if (!$paymentLock) {
                            throw new RuntimeException('Unable to verify payment status.');
                        }
                        $paymentId = (int)$qr['payment_id'];
                        $paymentLock->bind_param('iii', $paymentId, $orderId, $buyerId);
                        $paymentLock->execute();
                        $paymentRow = $paymentLock->get_result()->fetch_assoc();
                        $paymentLock->close();
                        if (!$paymentRow) {
                            throw new RuntimeException('Payment record not found.');
                        }
                        $qrLock = $conn->prepare('
                            SELECT qr_session_id, payment_intent_id, qr_status
                            FROM paymongo_qr_sessions
                            WHERE order_id = ? AND buyer_id = ? AND seller_id = ?
                            LIMIT 1 FOR UPDATE
                        ');
                        if (!$qrLock) {
                            throw new RuntimeException('Unable to verify the QR session.');
                        }
                        $qrLock->bind_param('iii', $orderId, $buyerId, $sellerId);
                        $qrLock->execute();
                        $lockedQr = $qrLock->get_result()->fetch_assoc();
                        $qrLock->close();
                        $isCurrentQr = $lockedQr
                            && hash_equals(
                                (string)$qr['payment_intent_id'],
                                (string)$lockedQr['payment_intent_id']
                            );
                        $isAlreadyPaid = in_array(
                            chatStatusKey($paymentRow['payment_status']),
                            ['paid', 'refunded'],
                            true
                        );
                        if (
                            $isCurrentQr
                            && !$isAlreadyPaid
                            && chatStatusKey($lockedQr['qr_status']) === 'active'
                        ) {
                            $failedQr = $conn->prepare('
                                UPDATE paymongo_qr_sessions
                                SET qr_status = "failed", updated_at = NOW()
                                WHERE qr_session_id = ?
                                  AND payment_intent_id = ?
                                  AND qr_status = "active"
                            ');
                            if (!$failedQr) {
                                throw new RuntimeException('Unable to record the failed QR attempt.');
                            }
                            $sessionId = (int)$lockedQr['qr_session_id'];
                            $intentId = (string)$qr['payment_intent_id'];
                            $failedQr->bind_param('is', $sessionId, $intentId);
                            $failedQr->execute();
                            $failedQr->close();
                            $failedPayment = $conn->prepare('
                                UPDATE payments SET payment_status = "failed"
                                WHERE payment_id = ? AND order_id = ? AND buyer_id = ?
                                  AND payment_status NOT IN ("paid", "refunded")
                            ');
                            if (!$failedPayment) {
                                throw new RuntimeException('Unable to record the failed payment.');
                            }
                            $failedPayment->bind_param('iii', $paymentId, $orderId, $buyerId);
                            $failedPayment->execute();
                            $failedPayment->close();
                        }
                        $conn->commit();
                    } catch (Throwable $e) {
                        $conn->rollback();
                        throw $e;
                    }
                    $currentQr = getBuyerQrSession($conn, $buyerId, $sellerId, $orderId);
                    $updatedOrder = getBuyerOrderDetails($conn, $buyerId, $sellerId, $orderId);
                    $confirmedPaid = chatStatusKey($updatedOrder['payment_status'] ?? '') === 'paid';
                    $confirmedFailed = chatStatusKey($currentQr['qr_status'] ?? '') === 'failed';
                    echo json_encode([
                        'success' => true,
                        'paid' => $confirmedPaid,
                        'failed' => $confirmedFailed,
                        'expired' => false,
                        'remote_status' => $remoteStatus,
                        'qr' => $currentQr,
                        'order' => $updatedOrder,
                        'message' => $confirmedFailed
                            ? 'Payment failed in PayMongo Test Mode. Generate a new QR to try again.'
                            : ($confirmedPaid ? 'Payment already confirmed.' : 'Checking your latest payment attempt.')
                    ]);
                    exit;
                }
                $expired =
                    (int)($qr['expires_at_unix'] ?? 0)
                        <= time();
                if ($expired) {
                    $stmt = $conn->prepare('
                        UPDATE paymongo_qr_sessions
                        SET
                            qr_status = "expired",
                            updated_at = NOW()
                        WHERE
                            order_id = ?
                            AND buyer_id = ?
                            AND seller_id = ?
                            AND qr_status <> "paid"
                    ');
                    if ($stmt) {
                        $stmt->bind_param(
                            'iii',
                            $orderId,
                            $buyerId,
                            $sellerId
                        );
                        $stmt->execute();
                        $stmt->close();
                    }
                    $qr = getBuyerQrSession(
                        $conn,
                        $buyerId,
                        $sellerId,
                        $orderId
                    );
                }
                echo json_encode([
                    'success' => true,
                    'paid' => false,
                    'expired' => $expired,
                    'remote_status' => $remoteStatus,
                    'qr' => $qr,
                    'order' => getBuyerOrderDetails(
                        $conn,
                        $buyerId,
                        $sellerId,
                        $orderId
                    ),
                    'message' => $expired
                        ? 'This QR has expired. Generate a new QR to continue.'
                        : 'Payment is not confirmed yet.'
                ]);
                exit;
            }
            if ($action === 'send_message') {
                $receiverId = (int)($_POST['receiver_id'] ?? 0);
                $orderId = (int)($_POST['order_id'] ?? 0);
                $messageText = trim((string)($_POST['message_text'] ?? ''));
                if (!sellerExists($conn, $receiverId)) {
                    throw new RuntimeException('That seller account is no longer available.');
                }
                if ($messageText !== '') {
                    $messageLength = function_exists('mb_strlen')
                        ? mb_strlen($messageText, 'UTF-8')
                        : preg_match_all('/./us', $messageText);
                    if ($messageLength === false || $messageLength > 2000) {
                        throw new RuntimeException('Message is too long. Please keep it under 2,000 characters.');
                    }
                }
                if ($orderId > 0) {
                    $orderStatus = buyerOrderStatus($conn, $buyerId, $receiverId, $orderId);
                    if ($orderStatus === null) {
                        throw new RuntimeException('This order does not belong to your Buyer conversation.');
                    }
                    if (buyerOrderClosedStatus($orderStatus)) {
                        // Closed transactions stay closed. The reply becomes a regular
                        // buyer-to-seller message rather than failing or reviving an order.
                        $orderId = 0;
                    }
                } else {
                    $directState = buyerDirectThreadState(
                        $conn,
                        $buyerId,
                        $receiverId
                    );
                    if ($directState < 0 && !buyerHasSellerOrder($conn, $buyerId, $receiverId)) {
                        throw new RuntimeException(
                            'This direct chat belongs to your Seller inbox. '
                            . 'Start a product inquiry from Marketplace to message this seller.'
                        );
                    }
                }
                $filePath = '';
                $attachmentUrl = '';
                if (!empty($_FILES['attachment']['name'])) {
                    if (!isset($_FILES['attachment']['error']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
                        throw new RuntimeException('The attachment could not be uploaded.');
                    }
                    if ((int)$_FILES['attachment']['size'] > 8 * 1024 * 1024) {
                        throw new RuntimeException('Attachments must be 8 MB or smaller.');
                    }
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx'];
                    $extension = strtolower(pathinfo((string)$_FILES['attachment']['name'], PATHINFO_EXTENSION));
                    if (!in_array($extension, $allowedExtensions, true)) {
                        throw new RuntimeException('This attachment type is not supported.');
                    }
                    $uploadDirectory = __DIR__ . '/../uploads/messages';
                    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
                        throw new RuntimeException('Unable to create the attachment folder.');
                    }
                    $safeName = bin2hex(random_bytes(12)) . '.' . $extension;
                    $filePath = $uploadDirectory . '/' . $safeName;
                    if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $filePath)) {
                        throw new RuntimeException('Unable to save the attachment.');
                    }
                    $attachmentUrl = '../uploads/messages/' . $safeName;
                }
                if ($messageText === '' && $attachmentUrl === '') {
                    throw new RuntimeException('Please enter a message or attach a file.');
                }
                $transactionOpen = false;
                try {
                    if ($orderId > 0) {
                        // Lock against a concurrent cancellation while saving this order message.
                        $conn->begin_transaction();
                        $transactionOpen = true;
                        $lock = $conn->prepare('SELECT order_status FROM orders
                            WHERE order_id = ? AND buyer_id = ? AND seller_id = ?
                            LIMIT 1 FOR UPDATE');
                        if (!$lock) throw new RuntimeException('Unable to check this order.');
                        $lock->bind_param('iii', $orderId, $buyerId, $receiverId);
                        $lock->execute();
                        $lockedOrder = $lock->get_result()->fetch_assoc();
                        $lock->close();
                        if (!$lockedOrder) {
                            throw new RuntimeException('This order is no longer available.');
                        }
                        if (buyerOrderClosedStatus($lockedOrder['order_status'])) {
                            // The seller may have rejected the order after the page loaded.
                            // Detach this normal reply atomically before inserting it.
                            $orderId = 0;
                        }
                    }
                    $stmt = $conn->prepare('
                        INSERT INTO messages
                            (sender_id, receiver_id, order_id, message_text, attachment, is_read, sent_at)
                        VALUES (?, ?, NULLIF(?, 0), ?, ?, 0, NOW())
                    ');
                    if (!$stmt) throw new RuntimeException('Unable to prepare the message.');
                    $stmt->bind_param('iiiss', $buyerId, $receiverId, $orderId, $messageText, $attachmentUrl);
                    if (!$stmt->execute()) {
                        $stmt->close();
                        throw new RuntimeException('Unable to send the message.');
                    }
                    $messageId = (int)$stmt->insert_id;
                    $stmt->close();
                    if ($transactionOpen) $conn->commit();
                } catch (Throwable $e) {
                    if ($transactionOpen) $conn->rollback();
                    if ($filePath !== '' && is_file($filePath)) unlink($filePath);
                    throw $e;
                }
                $sentAt = date('Y-m-d H:i:s');
                echo json_encode([
                    'success' => true,
                    'message' => [
                        'message_id' => $messageId,
                        'sender_id' => $buyerId,
                        'receiver_id' => $receiverId,
                        'order_id' => $orderId,
                        'message_text' => $messageText,
                        'attachment' => $attachmentUrl,
                        'is_read' => 0,
                        'sent_at' => $sentAt,
                        'time' => chatTime($sentAt),
                        'date_key' => dateKey($sentAt),
                        'date_label' => dateLabel($sentAt)
                    ]
                ]);
                exit;
            }
            if ($action === 'mark_read') {
                $sellerId = (int)($_POST['seller_id'] ?? 0);
                if (!sellerExists($conn, $sellerId)) {
                    throw new RuntimeException('Seller conversation not found.');
                }
                $focusOrderId = max(0, (int)($_POST['focus_order_id'] ?? 0));
                if ($focusOrderId > 0 && !validOrder($conn, $buyerId, $sellerId, $focusOrderId)) {
                    throw new RuntimeException('Order not found in this buyer conversation.');
                }
                markRead($conn, $buyerId, $sellerId, $focusOrderId);
                echo json_encode(['success' => true]);
                exit;
            }
            if ($action === 'fetch_messages') {
                $sellerId = (int)($_POST['seller_id'] ?? 0);
                $afterId = (int)($_POST['after_id'] ?? 0);
                if (!sellerExists($conn, $sellerId)) {
                    throw new RuntimeException('Seller conversation not found.');
                }
                $messages = getMessages($conn, $buyerId, $sellerId, $afterId);
                echo json_encode([
                    'success' => true,
                    'messages' => $messages,
                    'next_action_order_id' => buyerNextConfirmationOrderId($conn, $buyerId, $sellerId)
                ]);
                exit;
            }
            if ($action === 'fetch_conversations') {
                $conversations = getConversations($conn, $buyerId);
                echo json_encode([
                    'success' => true,
                    'conversations' => $conversations
                ]);
                exit;
            }
            if ($action === 'typing') {
                $sellerId = (int)($_POST['seller_id'] ?? 0);
                if (!sellerExists($conn, $sellerId)) {
                    throw new RuntimeException('Seller conversation not found.');
                }
                $isTyping = (int)($_POST['is_typing'] ?? 0);
                $stmt = $conn->prepare('
                    INSERT INTO chat_typing
                        (sender_id, receiver_id, is_typing, updated_at)
                    VALUES
                        (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        is_typing = VALUES(is_typing),
                        updated_at = NOW()
                ');
                if (!$stmt) {
                    throw new RuntimeException('Unable to update typing status.');
                }
                $stmt->bind_param('iii', $buyerId, $sellerId, $isTyping);
                $stmt->execute();
                $stmt->close();
                echo json_encode(['success' => true]);
                exit;
            }
            if ($action === 'fetch_typing') {
                $sellerId = (int)($_POST['seller_id'] ?? 0);
                if (!sellerExists($conn, $sellerId)) {
                    throw new RuntimeException('Seller conversation not found.');
                }
                $stmt = $conn->prepare('
                    SELECT is_typing, updated_at
                    FROM chat_typing
                    WHERE sender_id = ? AND receiver_id = ?
                    LIMIT 1
                ');
                if (!$stmt) {
                    throw new RuntimeException('Unable to read typing status.');
                }
                $stmt->bind_param('ii', $sellerId, $buyerId);
                $stmt->execute();
                $typing = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $isTyping = false;
                if ($typing) {
                    $updatedAt = strtotime((string)$typing['updated_at']);
                    $isTyping = (bool)$typing['is_typing'] && $updatedAt && (time() - $updatedAt <= 8);
                }
                echo json_encode([
                    'success' => true,
                    'is_typing' => $isTyping
                ]);
                exit;
            }
            throw new RuntimeException('Invalid request.');
        } catch (Throwable $e) {
            $expectedError = $e instanceof RuntimeException
                && !($e instanceof mysqli_sql_exception);
            if (!$expectedError) {
                error_log('LokalCraft Buyer Messages: ' . $e->getMessage());
            }
            http_response_code($expectedError ? 400 : 500);
            echo json_encode([
                'success' => false,
                'message' => $expectedError
                    ? $e->getMessage()
                    : 'Unable to complete this request right now.'
            ]);
            exit;
        }
    }
}
$conversations = [];
$selectedPartner = null;
$selectedOrder = null;
$chatMessages = [];
if ($buyerId > 0) {
    try {
        $conversations = getConversations($conn, $buyerId);
        if ($selectedPartnerId > 0 && sellerExists($conn, $selectedPartnerId)) {
            /*
             * If the buyer opened a normal seller conversation without an
             * explicit order_id, use the latest order linked to that seller.
             * This keeps Review/View Arrangement accessible from Messages.
             */
            if ($selectedOrderId <= 0) {
                $selectedOrderId = buyerNextConfirmationOrderId(
                    $conn, $buyerId, $selectedPartnerId
                );
            }
            if ($selectedOrderId <= 0) {
                foreach ($conversations as $conversation) {
                    if (
                        (int)($conversation['partner_id'] ?? 0) === $selectedPartnerId
                        && (int)($conversation['latest_order_id'] ?? 0) > 0
                    ) {
                        $selectedOrderId = (int)$conversation['latest_order_id'];
                        break;
                    }
                }
            }
            $partnerStmt = $conn->prepare('
                SELECT
                    u.id,
                    u.firstname,
                    u.lastname,
                    b.businessname,
                    l.barangay,
                    l.city
                FROM users u
                LEFT JOIN businessinfo b ON b.user_id = u.id
                LEFT JOIN locationinfo l ON l.user_id = u.id
                WHERE u.id = ? AND u.SellerOrNot = 1
                LIMIT 1
            ');
            if ($partnerStmt) {
                $partnerStmt->bind_param('i', $selectedPartnerId);
                $partnerStmt->execute();
                $selectedPartner = $partnerStmt->get_result()->fetch_assoc() ?: null;
                $partnerStmt->close();
            }
            if ($selectedPartner) {
                $chatMessages = getMessages($conn, $buyerId, $selectedPartnerId);
                // A notification deep link only marks this order's messages as read.
                $focusedReadOrderId = (int)($_GET['order_id'] ?? 0) === $selectedOrderId
                    && $selectedOrderId > 0
                    && validOrder($conn, $buyerId, $selectedPartnerId, $selectedOrderId)
                    ? $selectedOrderId : 0;
                markRead($conn, $buyerId, $selectedPartnerId, $focusedReadOrderId);
                if ($selectedOrderId > 0) {
                    $selectedOrder = getBuyerOrderDetails(
                        $conn,
                        $buyerId,
                        $selectedPartnerId,
                        $selectedOrderId
                    );
                    if ($selectedOrder) {
                        $selectedOrder['items_summary'] =
                            (int)($selectedOrder['item_count'] ?? 1) > 1
                                ? (int)$selectedOrder['item_count']
                                    . ' items · '
                                    . (int)$selectedOrder['quantity']
                                    . ' total units'
                                : (int)$selectedOrder['quantity']
                                    . ' × '
                                    . (string)$selectedOrder['product_name'];
                        $selectedOrder['display_status'] =
                            buyerOrderClosedStatus($selectedOrder['order_status'])
                                ? chatDisplayStatus($selectedOrder['order_status'], '', '')
                                : chatNiceStatus(
                                    $selectedOrder['arrangement_status']
                                    ?: $selectedOrder['order_status']
                                );
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $errorMessage = 'Unable to load your messages right now.';
    }
}
$selectedSellerName = 'Seller';
$selectedSellerLocation = '';
$selectedSellerInitials = 'S';
if ($selectedPartner) {
    $personName = trim(($selectedPartner['firstname'] ?? '') . ' ' . ($selectedPartner['lastname'] ?? ''));
    $selectedSellerName = trim((string)($selectedPartner['businessname'] ?? '')) !== ''
        ? (string)$selectedPartner['businessname']
        : ($personName !== '' ? $personName : 'Seller');
    $selectedSellerLocation = implode(', ', array_filter([
        trim((string)($selectedPartner['barangay'] ?? '')),
        trim((string)($selectedPartner['city'] ?? ''))
    ]));
    $selectedSellerInitials = getInitials($selectedSellerName);
}
$prefillMessage = '';
if ($selectedProductName !== '') {
    $priceText = $selectedProductPrice !== '' ? ' for ' . $selectedProductPrice : '';
    $prefillMessage = "Hi! I'm interested in {$selectedProductName}{$priceText}. Is it still available? I'd also like to ask about the delivery arrangements.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>LokalCraft | Messages</title>
  <style>
    :root {
      --bg: #f3f3f3;
      --text: #2d2d2d;
      --muted: #6e6e73;
      --line: #e2e1de;
      --orange: #ea8a3a;
      --orange-dark: #d97828;
      --incoming: #efefed;
      --white: #fff;
      --soft: #f7f6f4;
      --success: #328052;
      --danger: #c44a4a;
    }
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }
    html,
    body {
      width: 100%;
      height: 100%;
    }
    html {
      -webkit-text-size-adjust: 100%;
      text-size-adjust: 100%;
    }
    :root {
      --app-height: 100dvh;
      --keyboard-inset: 0px;
    }
    body {
      font-family: "Segoe UI", Arial, sans-serif;
      background: var(--bg);
      color: var(--text);
      min-height: 100dvh;
      min-height: var(--app-height);
      overflow: hidden;
    }
    body.chat-open {
      overflow: hidden;
    }
    .page {
      display: block !important;
      width: 100%;
      height: 100dvh;
      height: var(--app-height);
      min-height: 100dvh;
      min-height: var(--app-height);
      overflow: hidden;
      background: #f4f3f1;
    }
    /*
     * Messenger-style layout:
     * - 240px is reserved for buyer_nav.php on desktop.
     * - 74px at the top is reserved for buyer_search.php.
     * - The message interface fills everything else.
     */
    .page > .content {
      position: fixed !important;
      inset: 0 0 0 240px !important;
      width: auto !important;
      max-width: none !important;
      height: 100dvh !important;
      height: var(--app-height) !important;
      min-height: 0 !important;
      margin: 0 !important;
      padding: 74px 0 0 !important;
      overflow: hidden !important;
    }
    .messages-wrap {
      display: flex;
      flex-direction: column;
      width: 100%;
      max-width: none;
      height: 100%;
      min-height: 0;
      margin: 0;
      padding: 0;
      overflow: hidden;
    }
    /* The left conversation header already says Messages/Conversations,
       so these are hidden to give the chat more usable space. */
    .page-title,
    .page-subtitle {
      display: none;
    }
    .notice {
      flex: 0 0 auto;
      margin: 8px 10px;
      padding: 10px 12px;
      border: 1px solid #e7dfd6;
      border-radius: 9px;
      background: #fffaf6;
      color: #755a43;
      font-size: .75rem;
    }
    .notice.success {
      border-color: #cfe6d7;
      background: #f3fbf5;
      color: #2b7044;
    }
    .messages-shell {
      display: grid;
      grid-template-columns: 340px minmax(0, 1fr);
      flex: 1 1 auto;
      width: 100%;
      height: auto;
      min-height: 0;
      overflow: hidden;
      border-top: 1px solid #d9d5d1;
      border-left: 1px solid #d9d5d1;
      background: #fff;
    }
    .conversation-list {
      display: flex;
      flex-direction: column;
      min-width: 0;
      min-height: 0;
      overflow: hidden;
      border-right: 1px solid #d9d5d1;
      background: rgba(255,255,255,.18);
    }
    #conversationItems {
      flex: 1 1 auto;
      min-height: 0;
      overflow-y: auto;
      overflow-x: hidden;
      overscroll-behavior: contain;
      scrollbar-width: thin;
      scrollbar-color: #c7c1bb transparent;
    }
    #conversationItems::-webkit-scrollbar {
      width: 7px;
    }
    #conversationItems::-webkit-scrollbar-track {
      background: transparent;
    }
    #conversationItems::-webkit-scrollbar-thumb {
      background: #c7c1bb;
      border-radius: 999px;
    }
    .conversation-header {
      position: sticky;
      top: 0;
      z-index: 2;
      padding: 17px 18px 12px;
      border-bottom: 1px solid #ebe6e1;
      background: rgba(250,249,248,.96);
      backdrop-filter: blur(8px);
      font-size: .95rem;
      font-weight: 800;
    }
    .conversation-item {
      display: flex;
      width: 100%;
      align-items: center;
      gap: 11px;
      padding: 13px 14px;
      border: 0;
      border-top: 1px solid #e7e2dd;
      background: transparent;
      color: inherit;
      text-align: left;
      cursor: pointer;
      transition: .18s ease;
    }
    .conversation-item:hover {
      background: #f7f3ee;
    }
    .conversation-item.active {
      background: #f1ece7;
    }
    .avatar {
      width: 42px;
      height: 42px;
      flex: 0 0 42px;
      display: grid;
      place-items: center;
      border-radius: 50%;
      background: linear-gradient(135deg, #f7d6a8, #d47c55);
      color: #fff;
      font-size: .77rem;
      font-weight: 800;
    }
    .conversation-main {
      min-width: 0;
      flex: 1;
    }
    .conversation-name-row {
      display: flex;
      align-items: center;
      gap: 7px;
      justify-content: space-between;
    }
    .conversation-name {
      min-width: 0;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      font-size: .8rem;
      font-weight: 800;
    }
    .conversation-time {
      color: #858585;
      font-size: .62rem;
      white-space: nowrap;
    }
    .conversation-sub {
      display: block;
      width: 100%;
      max-width: 100%;
      margin-top: 4px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      color: #6b6b6b;
      font-size: .69rem;
    }
    .conversation-unread {
      min-width: 19px;
      height: 19px;
      display: grid;
      place-items: center;
      margin-left: 5px;
      border-radius: 50%;
      background: var(--orange);
      color: #fff;
      font-size: .61rem;
      font-weight: 800;
    }
    .conversation-empty {
      padding: 36px 20px;
      text-align: center;
      color: #777;
      font-size: .76rem;
      line-height: 1.5;
    }
    .chat-panel {
      display: flex;
      min-width: 0;
      min-height: 0;
      height: 100%;
      overflow: hidden;
      flex-direction: column;
      background: rgba(255,255,255,.12);
    }
    .chat-header {
      flex: 0 0 auto;
      padding: 14px 18px 12px;
      border-bottom: 1px solid #dfd9d4;
      background: rgba(255,255,255,.18);
    }
    .chat-header-top {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .chat-back {
      display: none;
      width: 34px;
      height: 34px;
      flex: 0 0 34px;
      border: 1px solid #ddd8d3;
      border-radius: 8px;
      background: #fff;
      color: #555;
      cursor: pointer;
    }
    .chat-header-copy {
      min-width: 0;
    }
    .chat-header h2 {
      margin: 0 0 3px;
      font-size: 1rem;
      font-weight: 800;
    }
    .chat-header p {
      overflow: hidden;
      margin: 0;
      color: #707070;
      font-size: .71rem;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .typing-indicator {
      min-height: 15px;
      margin-top: 5px;
      color: #8a8a8a;
      font-size: .65rem;
    }
    .order-summary {
      display: none;
      margin-top: 8px;
      padding: 8px 10px;
      border: 1px solid #e4ddd7;
      border-radius: 9px;
      background: rgba(255,255,255,.78);
    }
    .order-summary.show {
      display: block;
    }
    .order-summary-title {
      margin-bottom: 4px;
      color: #434343;
      font-size: .66rem;
      font-weight: 800;
    }
    .order-summary-line {
      display: flex;
      flex-wrap: wrap;
      gap: 8px 13px;
      color: #6a6a6a;
      font-size: .62rem;
    }
    .status-pill {
      display: inline-flex;
      align-items: center;
      min-height: 19px;
      padding: 2px 7px;
      border-radius: 999px;
      background: #f7eadb;
      color: #b26118;
      font-size: .58rem;
      font-weight: 700;
    }
    .chat-body {
      position: relative;
      flex: 1 1 auto;
      min-height: 0;
      height: 0;
      overflow-y: auto;
      overflow-x: hidden;
      overscroll-behavior: contain;
      -webkit-overflow-scrolling: touch;
      padding: 15px 18px 10px;
      scroll-behavior: auto;
      scrollbar-width: thin;
      scrollbar-color: #c7c1bb transparent;
    }
    .chat-body::-webkit-scrollbar {
      width: 7px;
    }
    .chat-body::-webkit-scrollbar-track {
      background: transparent;
    }
    .chat-body::-webkit-scrollbar-thumb {
      background: #c7c1bb;
      border-radius: 999px;
    }
    .chat-body::-webkit-scrollbar-thumb:hover {
      background: #aaa39c;
    }
    .date-separator {
      display: flex;
      align-items: center;
      gap: 10px;
      margin: 6px 0 14px;
      color: #8a8a8a;
      font-size: .61rem;
      font-weight: 700;
    }
    .date-separator::before,
    .date-separator::after {
      content: "";
      height: 1px;
      flex: 1;
      background: #e3ded9;
    }
    .message-row {
      display: flex;
      width: 100%;
      margin-bottom: 10px;
    }
    .message-row.outgoing {
      justify-content: flex-end;
    }
    .message-content {
      max-width: min(72%, 570px);
      min-width: 0;
    }
    .bubble {
      position: relative;
      padding: 10px 12px 8px;
      border-radius: 13px;
      font-size: .79rem;
      line-height: 1.48;
      word-break: break-word;
      white-space: pre-wrap;
    }
    .incoming .bubble {
      border-top-left-radius: 4px;
      background: var(--incoming);
      color: #2f2f2f;
    }
    .outgoing .bubble {
      border-top-right-radius: 4px;
      background: var(--orange);
      color: #fff;
    }
    .attachment {
      display: block;
      margin-bottom: 7px;
      overflow: hidden;
      border-radius: 9px;
      text-decoration: none;
    }
    .attachment img {
      display: block;
      width: min(330px, 100%);
      max-height: 300px;
      object-fit: cover;
      border-radius: 9px;
    }
    .attachment-file {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      padding: 9px 10px;
      border: 1px solid rgba(0,0,0,.08);
      border-radius: 8px;
      background: rgba(255,255,255,.74);
      color: #2f2f2f;
      font-size: .68rem;
      font-weight: 700;
    }
    .message-time {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 5px;
      margin-top: 5px;
      font-size: .6rem;
      opacity: .72;
    }
    .message-status {
      letter-spacing: -1px;
    }
    .message-order-card {
      margin-top: 7px;
      padding: 9px 10px;
      border: 1px solid rgba(0,0,0,.08);
      border-radius: 9px;
      background: rgba(255,255,255,.74);
      color: #3d3d3d;
    }
    .quick-replies {
      display: flex;
      flex: 0 0 auto;
      flex-wrap: wrap;
      gap: 6px;
      margin: 0;
      padding: 8px 18px 6px;
      border-top: 1px solid #eee9e4;
      background: #fff;
    }
    .quick-reply {
      min-height: 29px;
      padding: 6px 9px;
      border: 1px solid #ded9d4;
      border-radius: 8px;
      background: #fff;
      color: #5d5d5d;
      font-size: .63rem;
      cursor: pointer;
    }
    .quick-reply:hover {
      background: #f7f2ed;
    }
    .chat-input-wrap {
      position: relative;
      z-index: 8;
      flex: 0 0 auto;
      padding: 7px 14px max(10px, env(safe-area-inset-bottom));
      border-top: 1px solid #dfd9d4;
      background: #fff;
    }
    .attachment-preview {
      display: none;
      align-items: center;
      gap: 8px;
      margin-bottom: 7px;
      padding: 7px 9px;
      border: 1px solid #ded9d4;
      border-radius: 8px;
      background: #fff;
      color: #5b5b5b;
      font-size: .66rem;
    }
    .attachment-preview.show {
      display: flex;
    }
    .attachment-preview-name {
      min-width: 0;
      flex: 1;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .attachment-remove {
      width: 24px;
      height: 24px;
      border: 0;
      border-radius: 6px;
      background: #f2efeb;
      color: #777;
      cursor: pointer;
    }
    .chat-input {
      display: flex;
      align-items: flex-end;
      gap: 7px;
      padding: 7px 8px 7px 9px;
      border: 1px solid #dfdbd7;
      border-radius: 11px;
      background: #efefee;
    }
    .attach-label {
      width: 30px;
      height: 30px;
      flex: 0 0 30px;
      display: grid;
      place-items: center;
      border-radius: 8px;
      color: #6c6c6c;
      cursor: pointer;
    }
    .attach-label:hover {
      background: #e2dfdb;
    }
    .attach-label input {
      display: none;
    }
    .chat-input textarea {
      flex: 1;
      min-width: 0;
      min-height: 30px;
      max-height: 110px;
      resize: none;
      border: 0;
      outline: 0;
      background: transparent;
      color: #2d2d2d;
      font: inherit;
      font-size: .78rem;
      line-height: 1.4;
    }
    .send-btn {
      width: 34px;
      height: 34px;
      flex: 0 0 34px;
      border: 0;
      border-radius: 8px;
      background: var(--orange);
      color: #fff;
      cursor: pointer;
    }
    .send-btn:disabled {
      opacity: .55;
      cursor: default;
    }
    .chat-empty {
      display: grid;
      place-items: center;
      height: 100%;
      min-height: 280px;
      padding: 30px;
      text-align: center;
      color: #777;
      font-size: .78rem;
      line-height: 1.5;
    }
    .chat-error {
      display: none;
      flex: 0 0 auto;
      margin: 7px 14px;
      padding: 8px 10px;
      border: 1px solid #efc7c7;
      border-radius: 8px;
      background: #fff3f3;
      color: var(--danger);
      font-size: .68rem;
    }
    .chat-error.show {
      display: block;
    }
    /* Laptop / small desktop responsive fix */
    @media (min-width: 841px) and (max-width: 1400px) {
      .messages-shell {
        grid-template-columns: 285px minmax(0, 1fr);
      }
      .conversation-header {
        padding: 13px 14px 10px;
        font-size: .85rem;
      }
      .conversation-item {
        gap: 9px;
        padding: 10px 11px;
      }
      .conversation-item .avatar {
        width: 36px;
        height: 36px;
        flex-basis: 36px;
        font-size: .68rem;
      }
      .conversation-name {
        font-size: .75rem;
      }
      .conversation-sub {
        font-size: .64rem;
      }
      .conversation-time {
        font-size: .58rem;
      }
      .chat-header {
        padding: 11px 14px 9px;
      }
      .chat-header .avatar {
        width: 36px;
        height: 36px;
        flex-basis: 36px;
      }
      .chat-header h2 {
        font-size: .9rem;
      }
      .chat-header p {
        font-size: .66rem;
      }
      .typing-indicator {
        min-height: 12px;
        margin-top: 3px;
        font-size: .61rem;
      }
      .order-summary {
        margin-top: 6px;
        padding: 6px 8px;
      }
      .chat-body {
        padding: 12px 14px 8px;
      }
      .message-content {
        max-width: min(72%, 520px);
      }
      .bubble {
        padding: 8px 10px 7px;
        font-size: .74rem;
        line-height: 1.4;
      }
      .message-row {
        margin-bottom: 8px;
      }
      .quick-replies {
        gap: 5px;
        margin: 0 14px 6px;
      }
      .quick-reply {
        min-height: 26px;
        padding: 5px 8px;
        font-size: .59rem;
      }
      .chat-input-wrap {
        padding: 7px 11px 10px;
      }
      .chat-input {
        padding: 5px 6px 5px 7px;
      }
      .chat-input textarea {
        min-height: 28px;
        max-height: 85px;
        font-size: .74rem;
      }
      .attach-label {
        width: 28px;
        height: 28px;
        flex-basis: 28px;
      }
      .send-btn {
        width: 31px;
        height: 31px;
        flex-basis: 31px;
      }
    }
    @media (min-width: 841px) and (max-width: 1100px) {
      .page > .content {
        inset: 0 0 0 210px !important;
      }
      .messages-shell {
        grid-template-columns: 235px minmax(0, 1fr);
      }
      .conversation-item {
        padding: 9px;
      }
      .conversation-item .avatar {
        width: 33px;
        height: 33px;
        flex-basis: 33px;
      }
      .message-content {
        max-width: 78%;
      }
      .quick-reply {
        padding: 4px 6px;
        font-size: .56rem;
      }
    }
    @media (max-width: 840px) {
      .page > .content {
        inset: 0 !important;
      }
    }
    @media (max-width: 760px) {
      .page > .content {
        position: fixed !important;
        inset: 0 !important;
        width: 100% !important;
        max-width: none !important;
        min-height: 0 !important;
        height: 100dvh !important;
        height: var(--app-height) !important;
        margin: 0 !important;
        padding: 74px 0 0 !important;
        overflow: hidden !important;
      }
      .messages-wrap {
        position: fixed;
        top: 74px;
        right: 0;
        bottom: var(--keyboard-inset);
        left: 0;
        width: auto;
        height: auto;
        min-height: 0;
        overflow: hidden;
      }
      .messages-shell {
        display: block;
        width: 100%;
        height: 100%;
        min-height: 0;
        border: 0;
        border-top: 1px solid #d9d5d1;
      }
      .conversation-list {
        height: 100%;
        min-height: 0;
        overflow: hidden;
        border-right: 0;
      }
      .chat-panel {
        display: none;
        height: 100%;
        min-height: 0;
        overflow: hidden;
      }
      .messages-shell.chat-open .conversation-list {
        display: none;
      }
      .messages-shell.chat-open .chat-panel {
        display: flex;
      }
      .chat-back {
        display: grid;
        place-items: center;
      }
      .message-content {
        max-width: 84%;
      }
      .quick-replies {
        margin-left: 12px;
        margin-right: 12px;
      }
      .chat-input-wrap {
        position: relative;
        z-index: 20;
        flex: 0 0 auto;
        padding-left: max(10px, env(safe-area-inset-left));
        padding-right: max(10px, env(safe-area-inset-right));
        padding-bottom: max(12px, env(safe-area-inset-bottom));
        background: #fff;
      }
      /*
       * iPhone Safari:
       * Keep the top of Messages fixed and only lift the bottom edge
       * when the software keyboard overlaps the page.
       */
      .chat-input textarea {
        font-size: 16px !important;
        line-height: 1.35;
      }
      .chat-input,
      .attach-label,
      .send-btn,
      .quick-reply {
        touch-action: manipulation;
      }
      body.keyboard-open .order-summary,
      body.keyboard-open .quick-replies {
        display: none !important;
      }
      body.keyboard-open .chat-header {
        padding-top: 8px;
        padding-bottom: 7px;
      }
      body.keyboard-open .chat-body {
        min-height: 0 !important;
        height: 0 !important;
        flex: 1 1 auto !important;
        padding-bottom: 8px;
      }
      body.keyboard-open .chat-input-wrap {
        flex: 0 0 auto !important;
        padding-top: 7px;
        padding-bottom: 8px;
        background: #fff;
      }
    }
  </style>
  <style id="messagesModernTheme">
    /* LokalCraft buyer Messages — modern theme matched to buyer Orders */
    :root {
      --msg-bg: #f4f3f1;
      --msg-panel: #ffffff;
      --msg-panel-soft: #fbfaf8;
      --msg-line: #e8e1da;
      --msg-line-soft: #f0ebe6;
      --msg-text: #303030;
      --msg-muted: #777078;
      --msg-accent: #ea8a3a;
      --msg-accent-dark: #d87522;
      --msg-accent-soft: #fff3e7;
      --msg-green: #25834e;
      --msg-blue: #3478b8;
      --msg-shadow: 0 10px 28px rgba(59, 42, 27, .07);
    }
    body {
      background: var(--msg-bg);
      color: var(--msg-text);
    }
    .messages-wrap {
      padding: 12px 14px 14px;
      background: var(--msg-bg);
    }
    .messages-shell {
      border: 1px solid var(--msg-line);
      border-radius: 18px;
      background: var(--msg-panel);
      box-shadow: var(--msg-shadow);
      overflow: hidden;
    }
    /* Conversation column */
    .conversation-list {
      border-right: 1px solid var(--msg-line);
      background:
        linear-gradient(180deg, #fff 0%, #fbfaf8 100%);
      padding: 0;
    }
    .conversation-header {
      position: relative;
      padding: 18px 18px 15px;
      border-bottom: 0;
      background: rgba(255, 255, 255, .96);
      color: var(--msg-text);
      font-size: 1rem;
      font-weight: 800;
      letter-spacing: -.2px;
      box-shadow: 0 1px 0 var(--msg-line-soft);
    }
    .conversation-header::after {
      content: "";
      display: block;
      width: 34px;
      height: 4px;
      margin-top: 9px;
      border-radius: 999px;
      background: var(--msg-accent);
    }
    #conversationItems {
      padding: 7px;
      background: transparent;
    }
    .conversation-item {
      position: relative;
      gap: 11px;
      margin: 4px 0;
      padding: 11px 12px;
      border: 1px solid transparent;
      border-radius: 14px;
      background: transparent;
      transition:
        background .18s ease,
        border-color .18s ease,
        box-shadow .18s ease,
        transform .18s ease;
    }
    .conversation-item:hover {
      border-color: #eee4da;
      background: #fff;
      box-shadow: 0 5px 15px rgba(55, 39, 25, .055);
      transform: translateY(-1px);
    }
    .conversation-item.active {
      border-color: #f2c79f;
      background: var(--msg-accent-soft);
      box-shadow: 0 5px 15px rgba(234, 138, 58, .08);
    }
    .conversation-item.active::before {
      content: "";
      position: absolute;
      top: 11px;
      bottom: 11px;
      left: 4px;
      width: 4px;
      border-radius: 999px;
      background: var(--msg-accent);
    }
    .avatar {
      width: 43px;
      height: 43px;
      flex-basis: 43px;
      border: 2px solid rgba(255,255,255,.9);
      background: linear-gradient(135deg, #f4b06d, #d56f32);
      box-shadow: 0 4px 12px rgba(213, 111, 50, .18);
      font-size: .75rem;
    }
    .conversation-name {
      color: #34302d;
      font-size: .79rem;
    }
    .conversation-time {
      color: #9a918a;
      font-size: .6rem;
    }
    .conversation-sub {
      color: #7d7670;
      font-size: .67rem;
    }
    .conversation-unread {
      min-width: 21px;
      height: 21px;
      padding: 0 6px;
      border: 2px solid #fff;
      background: var(--msg-accent);
      box-shadow: 0 2px 8px rgba(234, 138, 58, .23);
    }
    .conversation-empty {
      margin: 14px 10px;
      padding: 34px 18px;
      border: 1px dashed #e7d8cb;
      border-radius: 14px;
      background: #fffaf5;
      color: #7d736c;
    }
    /* Main chat */
    .chat-panel {
      background:
        radial-gradient(circle at top right, rgba(234,138,58,.07), transparent 28%),
        linear-gradient(180deg, #fff 0%, #fcfbfa 100%);
    }
    .chat-header {
      padding: 13px 18px 11px;
      border-bottom: 1px solid var(--msg-line-soft);
      background: rgba(255,255,255,.94);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      box-shadow: 0 3px 12px rgba(60, 44, 29, .035);
    }
    .chat-header-top {
      gap: 11px;
    }
    .chat-header h2 {
      color: #302d2a;
      font-size: .98rem;
      letter-spacing: -.15px;
    }
    .chat-header p {
      color: #8a827c;
    }
    .typing-indicator {
      color: var(--msg-accent-dark);
      font-weight: 650;
    }
    .chat-back {
      border: 1px solid var(--msg-line);
      border-radius: 50%;
      background: #fff;
      color: #675f59;
      box-shadow: 0 3px 10px rgba(0,0,0,.05);
    }
    .chat-back:hover {
      border-color: #efc79f;
      background: var(--msg-accent-soft);
      color: var(--msg-accent-dark);
    }
    /* Order summary */
    .order-summary {
      margin-top: 10px;
      padding: 10px 12px;
      border: 1px solid #f1d5b7;
      border-radius: 13px;
      background: linear-gradient(135deg, #fffaf4, #fff4e8);
      box-shadow: inset 0 1px 0 rgba(255,255,255,.8);
    }
    .order-summary-title {
      margin-bottom: 6px;
      color: #a25a20;
      font-size: .66rem;
      text-transform: uppercase;
      letter-spacing: .045em;
    }
    .order-summary-line {
      gap: 7px 12px;
      color: #6f6257;
    }
    .status-pill {
      min-height: 22px;
      padding: 3px 9px;
      border: 1px solid #f1cfaa;
      border-radius: 999px;
      background: #fff;
      color: #aa5e20;
      box-shadow: 0 2px 7px rgba(234,138,58,.07);
    }
    /* Chat messages */
    .chat-body {
      padding: 18px 20px 13px;
      background:
        linear-gradient(180deg, rgba(250,249,247,.58), rgba(255,255,255,.82));
    }
    .date-separator {
      margin: 9px 0 17px;
      color: #aaa19a;
      font-size: .59rem;
    }
    .date-separator::before,
    .date-separator::after {
      background: #eee8e2;
    }
    .message-row {
      margin-bottom: 11px;
    }
    .message-content {
      max-width: min(72%, 590px);
    }
    .bubble {
      padding: 10px 13px 8px;
      border-radius: 18px;
      font-size: .78rem;
      line-height: 1.5;
      box-shadow: 0 3px 10px rgba(50, 38, 29, .045);
    }
    .incoming .bubble {
      border: 1px solid #ece6e0;
      border-top-left-radius: 7px;
      background: #fff;
      color: #373330;
    }
    .outgoing .bubble {
      border: 1px solid #df7c2b;
      border-top-right-radius: 7px;
      background: linear-gradient(135deg, #ee9348, #e27d2c);
      color: #fff;
      box-shadow: 0 5px 15px rgba(226, 125, 44, .15);
    }
    .message-time {
      margin-top: 5px;
      font-size: .58rem;
      opacity: .72;
    }
    .message-order-card {
      margin-top: 8px;
      padding: 10px 11px;
      border: 1px solid rgba(255,255,255,.42);
      border-radius: 12px;
      background: rgba(255,255,255,.92);
      color: #3c3733;
      box-shadow: 0 3px 9px rgba(0,0,0,.04);
    }
    .incoming .message-order-card {
      border-color: #eadfd5;
      background: #fffaf5;
    }
    .attachment,
    .attachment img,
    .attachment-file {
      border-radius: 12px;
    }
    /* Quick reply pills */
    .quick-replies {
      gap: 7px;
      padding: 9px 15px 7px;
      border-top: 1px solid var(--msg-line-soft);
      background: rgba(255,255,255,.96);
    }
    .quick-reply {
      min-height: 32px;
      padding: 7px 12px;
      border: 1px solid #eadfd5;
      border-radius: 999px;
      background: #fff;
      color: #675d55;
      font-size: .62rem;
      font-weight: 700;
      box-shadow: 0 2px 6px rgba(42,31,21,.025);
      transition: .17s ease;
    }
    .quick-reply:hover {
      border-color: #efbd8c;
      background: var(--msg-accent-soft);
      color: #a75a1c;
      transform: translateY(-1px);
    }
    /* Modern rounded composer */
    .chat-input-wrap {
      padding: 8px 14px max(11px, env(safe-area-inset-bottom));
      border-top: 1px solid var(--msg-line-soft);
      background: rgba(255,255,255,.98);
      box-shadow: 0 -6px 18px rgba(42,31,21,.035);
    }
    .attachment-preview {
      margin-bottom: 8px;
      padding: 8px 10px;
      border: 1px solid #eadfd5;
      border-radius: 12px;
      background: #fffaf6;
    }
    .attachment-remove {
      border-radius: 50%;
      background: #f4ede7;
    }
    .chat-input {
      min-height: 46px;
      gap: 8px;
      padding: 5px 6px 5px 7px;
      border: 1px solid #e3ddd7;
      border-radius: 24px;
      background: #f8f6f4;
      box-shadow: inset 0 1px 2px rgba(0,0,0,.025);
      transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
    }
    .chat-input:focus-within {
      border-color: #efb47f;
      background: #fff;
      box-shadow: 0 0 0 3px rgba(234,138,58,.10);
    }
    .attach-label {
      width: 36px;
      height: 36px;
      flex-basis: 36px;
      border-radius: 50%;
      background: #fff;
      color: #8b7d72;
      box-shadow: 0 2px 7px rgba(0,0,0,.045);
      transition: .17s ease;
    }
    .attach-label:hover {
      background: var(--msg-accent-soft);
      color: var(--msg-accent-dark);
      transform: translateY(-1px);
    }
    .chat-input textarea {
      min-height: 34px;
      padding: 7px 2px 5px;
      color: #393532;
    }
    .chat-input textarea::placeholder {
      color: #aaa19b;
    }
    .send-btn {
      width: 36px;
      height: 36px;
      flex-basis: 36px;
      border-radius: 50%;
      background: linear-gradient(135deg, #ee9348, #dd7627);
      box-shadow: 0 5px 13px rgba(221,118,39,.22);
      transition: transform .17s ease, box-shadow .17s ease, opacity .17s ease;
    }
    .send-btn:hover:not(:disabled) {
      transform: translateY(-1px) scale(1.03);
      box-shadow: 0 7px 16px rgba(221,118,39,.28);
    }
    .send-btn:disabled {
      background: #d8d2cd;
      box-shadow: none;
      opacity: .72;
    }
    .chat-empty {
      color: #847c75;
    }
    .chat-panel > .chat-empty {
      margin: auto;
      width: min(430px, calc(100% - 40px));
      height: auto;
      min-height: 0;
      padding: 38px 26px;
      border: 1px dashed #e6d9cd;
      border-radius: 18px;
      background: rgba(255,250,245,.8);
    }
    .chat-error {
      border: 1px solid #efc7c7;
      border-radius: 11px;
      background: #fff3f3;
      box-shadow: 0 3px 10px rgba(191,76,76,.06);
    }
    /* Scrollbars */
    #conversationItems::-webkit-scrollbar-thumb,
    .chat-body::-webkit-scrollbar-thumb {
      background: #d6cec7;
    }
    #conversationItems::-webkit-scrollbar-thumb:hover,
    .chat-body::-webkit-scrollbar-thumb:hover {
      background: #bbb1a9;
    }
    @media (max-width: 1100px) and (min-width: 841px) {
      .messages-wrap {
        padding: 9px 10px 11px;
      }
      .messages-shell {
        border-radius: 15px;
      }
      .conversation-item {
        border-radius: 12px;
      }
    }
    @media (max-width: 840px) {
      .messages-wrap {
        padding: 0;
      }
      .messages-shell {
        border-right: 0;
        border-bottom: 0;
        border-left: 0;
        border-radius: 0;
        box-shadow: none;
      }
    }
    @media (max-width: 760px) {
      .conversation-header {
        padding: 15px 14px 12px;
      }
      #conversationItems {
        padding: 6px;
      }
      .conversation-item {
        margin: 3px 0;
        padding: 10px 11px;
        border-radius: 13px;
      }
      .conversation-item.active::before {
        top: 9px;
        bottom: 9px;
      }
      .chat-header {
        padding-left: max(12px, env(safe-area-inset-left));
        padding-right: max(12px, env(safe-area-inset-right));
      }
      .chat-body {
        padding-left: 12px;
        padding-right: 12px;
      }
      .message-content {
        max-width: 86%;
      }
      .bubble {
        border-radius: 17px;
      }
      .incoming .bubble {
        border-top-left-radius: 6px;
      }
      .outgoing .bubble {
        border-top-right-radius: 6px;
      }
      .quick-replies {
        padding-left: 10px;
        padding-right: 10px;
      }
      .chat-input-wrap {
        padding-left: max(10px, env(safe-area-inset-left));
        padding-right: max(10px, env(safe-area-inset-right));
      }
      .chat-input {
        border-radius: 23px;
      }
      /* Preserve Safari anti-zoom behavior from the existing fix. */
      .chat-input textarea {
        font-size: 16px !important;
      }
    }
  </style>
  <style id="buyerArrangementStyles">
    .buyer-order-summary-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }
    .buyer-order-summary-head > div:first-child {
      min-width: 0;
      flex: 1;
    }
    .buyer-order-summary-actions {
      display: flex;
      flex: 0 0 auto;
      align-items: center;
      flex-wrap: wrap;
      justify-content: flex-end;
      gap: 7px;
    }
    .review-arrangement-btn,
    .buyer-pay-now-btn {
      flex: 0 0 auto;
      min-height: 32px;
      padding: 7px 11px;
      border: 1px solid #edb77f;
      border-radius: 999px;
      background: #fff;
      color: #a95c1e;
      font: inherit;
      font-size: .61rem;
      font-weight: 800;
      cursor: pointer;
      transition: .17s ease;
    }
    .review-arrangement-btn:hover {
      border-color: #e99b52;
      background: #fff3e7;
      transform: translateY(-1px);
    }
    .review-arrangement-btn.confirm-ready {
      border-color: #df792b;
      background: linear-gradient(135deg, #ee9348, #df792b);
      color: #fff;
      box-shadow: 0 5px 13px rgba(221,118,39,.18);
    }
    .buyer-pay-now-btn {
      border-color: #2f8758;
      background: linear-gradient(135deg, #43a66d, #2f8758);
      color: #fff;
      box-shadow: 0 5px 13px rgba(47,135,88,.16);
    }
    .buyer-pay-now-btn:hover {
      border-color: #26764c;
      background: linear-gradient(135deg, #39985f, #26764c);
      color: #fff;
      transform: translateY(-1px);
    }
    .buyer-qr-dialog {
      width: min(470px, calc(100vw - 28px));
      max-height: min(92dvh, 760px);
      margin: auto;
      padding: 0;
      border: 0;
      border-radius: 22px;
      background: transparent;
      color: var(--text);
      overflow: visible;
    }
    .buyer-qr-dialog::backdrop {
      background: rgba(32, 27, 23, .48);
      backdrop-filter: blur(3px);
    }
    .buyer-qr-card {
      max-height: min(92dvh, 760px);
      overflow: hidden;
      border: 1px solid #eadfd6;
      border-radius: 22px;
      background: #fff;
      box-shadow: 0 24px 70px rgba(36, 25, 15, .22);
    }
    .buyer-qr-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 14px;
      padding: 18px 20px 15px;
      border-bottom: 1px solid #eee6df;
      background: #fffaf6;
    }
    .buyer-qr-eyebrow {
      display: block;
      margin-bottom: 3px;
      color: #d87522;
      font-size: .61rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .06em;
    }
    .buyer-qr-head h2 {
      margin: 0;
      color: #302b27;
      font-size: 1.08rem;
      font-weight: 800;
    }
    .buyer-qr-close {
      flex: 0 0 36px;
      width: 36px;
      height: 36px;
      border: 1px solid #e1d8d0;
      border-radius: 11px;
      background: #fff;
      color: #6e655e;
      font-size: 1.35rem;
      line-height: 1;
      cursor: pointer;
    }
    .buyer-qr-body {
      padding: 18px 20px 20px;
    }
    .buyer-qr-summary {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 14px;
    }
    .buyer-qr-order {
      min-width: 0;
      color: #625a54;
      font-size: .7rem;
      font-weight: 800;
      overflow-wrap: anywhere;
    }
    .buyer-qr-amount {
      flex-shrink: 0;
      color: #d87522;
      font-size: 1rem;
      font-weight: 800;
    }
    .buyer-qr-image-wrap {
      display: grid;
      place-items: center;
      min-height: 240px;
      padding: 14px;
      border: 1px solid #eee6df;
      border-radius: 16px;
      background: #faf9f7;
    }
    .buyer-qr-image-wrap img {
      display: block;
      width: min(240px, 100%);
      height: auto;
      aspect-ratio: 1 / 1;
      object-fit: contain;
      border-radius: 10px;
    }
    .buyer-qr-loading {
      color: #85807b;
      font-size: .72rem;
      font-weight: 700;
      text-align: center;
    }
    .buyer-qr-timer-shell {
      margin-top: 11px;
      color: #7d756f;
      text-align: center;
      font-size: .66rem;
      font-weight: 800;
    }
    .buyer-qr-timer-shell strong {
      font: inherit;
      font-variant-numeric: tabular-nums;
    }
    .buyer-qr-feedback {
      display: none;
      min-height: 42px;
      margin-top: 12px;
      padding: 10px 11px;
      border: 1px solid #eadfd2;
      border-radius: 12px;
      background: #fffbf6;
      color: #725f4f;
      font-size: .66rem;
      line-height: 1.5;
    }
    .buyer-qr-feedback.show {
      display: block;
    }
    .buyer-qr-feedback.success {
      border-color: #cde5d5;
      background: #f2fbf5;
      color: #287a4b;
    }
    .buyer-qr-feedback.error {
      border-color: #ebcaca;
      background: #fff5f5;
      color: #9d4848;
    }
    .buyer-qr-actions {
      display: flex;
      justify-content: flex-end;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 14px;
    }
    .buyer-qr-action {
      min-height: 34px;
      padding: 7px 11px;
      border: 1px solid #ddd7d1;
      border-radius: 8px;
      background: #fff;
      color: #57514c;
      font: inherit;
      font-size: .66rem;
      font-weight: 800;
      cursor: pointer;
    }
    .buyer-qr-action.primary {
      border-color: #ea8a3a;
      background: #ea8a3a;
      color: #fff;
    }
    .buyer-qr-action.primary:hover {
      background: #d87522;
    }
    .buyer-qr-action:disabled {
      cursor: not-allowed;
      opacity: .58;
    }
    .buyer-qr-image-wrap img[hidden],
    .buyer-qr-loading[hidden],
    .buyer-qr-actions button[hidden] {
      display: none !important;
    }
    .buyer-arrangement-dialog {
      width: min(720px, calc(100% - 28px));
      max-height: min(88dvh, 800px);
      margin: auto;
      padding: 0;
      overflow: hidden;
      border: 0;
      border-radius: 20px;
      background: #fff;
      color: #303030;
      box-shadow: 0 28px 90px rgba(41,28,18,.28);
    }
    .buyer-arrangement-dialog::backdrop {
      background: rgba(32,25,20,.48);
      backdrop-filter: blur(3px);
      -webkit-backdrop-filter: blur(3px);
    }
    .buyer-arrangement-card {
      max-height: min(88dvh, 800px);
      overflow-y: auto;
      overscroll-behavior: contain;
      -webkit-overflow-scrolling: touch;
      padding: 22px;
    }
    .buyer-arrangement-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 16px;
    }

    .buyer-arrangement-head h2 {
      margin: 0;
      color: #302d2a;
      font-size: 1.25rem;
      letter-spacing: -.3px;
    }
    .buyer-arrangement-head p {
      margin: 6px 0 0;
      color: #817970;
      font-size: .74rem;
      line-height: 1.5;
    }
    .buyer-arrangement-close {
      width: 35px;
      height: 35px;
      flex: 0 0 35px;
      border: 1px solid #e8e1da;
      border-radius: 50%;
      background: #faf8f6;
      color: #71675f;
      font-size: 1.2rem;
      cursor: pointer;
    }
    .buyer-arrangement-feedback {
      display: none;
      margin-bottom: 12px;
      padding: 10px 12px;
      border: 1px solid transparent;
      border-radius: 11px;
      font-size: .72rem;
      line-height: 1.45;
    }
    .buyer-arrangement-feedback.show {
      display: block;
    }
    .buyer-arrangement-feedback.success {
      border-color: #c7e5d1;
      background: #effaf3;
      color: #247144;
    }
    .buyer-arrangement-feedback.error {
      border-color: #efc7c7;
      background: #fff3f3;
      color: #a84444;
    }
    .buyer-arrangement-overview {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 12px;
      padding: 13px 14px;
      border: 1px solid #f0dfcf;
      border-radius: 14px;
      background: linear-gradient(135deg, #fffaf5, #fff5ea);
    }
    .buyer-arrangement-overview > div {
      min-width: 0;
    }
    .buyer-arrangement-overview strong {
      display: block;
      overflow: hidden;
      color: #403831;
      font-size: .86rem;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .buyer-arrangement-overview span:not(.buyer-arrangement-status) {
      display: block;
      margin-top: 3px;
      color: #8b7b6d;
      font-size: .65rem;
    }
    .buyer-arrangement-status {
      flex: 0 0 auto;
      padding: 6px 9px;
      border: 1px solid #efc79f;
      border-radius: 999px;
      background: #fff;
      color: #a75b1d;
      font-size: .6rem;
      font-weight: 800;
    }
    .buyer-arrangement-items {
      display: grid;
      gap: 7px;
      margin-bottom: 12px;
    }
    .buyer-arrangement-items[hidden] {
      display: none !important;
    }
    .buyer-arrangement-item {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 10px;
      padding: 9px 10px;
      border: 1px solid #eee7e0;
      border-radius: 11px;
      background: #fbfaf8;
    }
    .buyer-arrangement-item-name {
      color: #4b433d;
      font-size: .68rem;
      font-weight: 800;
    }
    .buyer-arrangement-item-meta {
      margin-top: 2px;
      color: #8b8179;
      font-size: .6rem;
    }

    .buyer-arrangement-actions {
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      margin-top: 16px;
      padding-top: 14px;
      border-top: 1px solid #eee8e2;
    }
    .buyer-arrangement-action {
      min-height: 38px;
      padding: 8px 14px;
      border-radius: 10px;
      font: inherit;
      font-size: .7rem;
      font-weight: 800;
      cursor: pointer;
      transition: .17s ease;
    }
    .buyer-arrangement-action.secondary {
      border: 1px solid #ded7d0;
      background: #fff;
      color: #655d57;
    }
    .buyer-arrangement-action.primary {
      border: 1px solid #df792b;
      background: linear-gradient(135deg, #ee9348, #df792b);
      color: #fff;
      box-shadow: 0 5px 13px rgba(221,118,39,.17);
    }
    .buyer-arrangement-action.pay {
      border: 1px solid #2f8758;
      background: linear-gradient(135deg, #43a66d, #2f8758);
      color: #fff;
      box-shadow: 0 5px 13px rgba(47,135,88,.16);
    }
    .buyer-arrangement-action:hover:not(:disabled) {
      transform: translateY(-1px);
    }
    .buyer-arrangement-action:disabled {
      opacity: .55;
      cursor: not-allowed;
      box-shadow: none;
      transform: none;
    }
    @media (max-width: 760px) {
      .buyer-order-summary-head {
        align-items: flex-start;
      }
      .review-arrangement-btn,
      .buyer-pay-now-btn {
        min-height: 30px;
        padding: 6px 9px;
        font-size: .58rem;
      }
      .buyer-order-summary-actions {
        max-width: 46%;
      }
      .buyer-qr-dialog {
        width: calc(100vw - 18px);
        max-height: calc(
          var(--app-height)
          - env(safe-area-inset-top)
          - env(safe-area-inset-bottom)
          - 16px
        );
      }
      .buyer-qr-image-wrap {
        min-height: 240px;
      }
      .buyer-qr-image-wrap img {
        width: min(100%, 250px);
      }
      .buyer-qr-actions {
        position: sticky;
        bottom: 0;
        padding-bottom: max(
          15px,
          env(safe-area-inset-bottom)
        );
        background: rgba(255,255,255,.97);
      }
      .buyer-arrangement-dialog {
        width: calc(100% - 16px);
        max-height: calc(100dvh - 18px);
        border-radius: 17px;
      }
      .buyer-arrangement-card {
        max-height: calc(100dvh - 18px);
        padding:
          max(16px, env(safe-area-inset-top))
          max(14px, env(safe-area-inset-right))
          max(16px, env(safe-area-inset-bottom))
          max(14px, env(safe-area-inset-left));
      }
      .buyer-arrangement-head h2 {
        font-size: 1.08rem;
      }

      .buyer-arrangement-actions {
        position: sticky;
        bottom: calc(-1 * max(16px, env(safe-area-inset-bottom)));
        z-index: 3;
        margin-right: calc(-1 * max(14px, env(safe-area-inset-right)));
        margin-bottom: calc(-1 * max(16px, env(safe-area-inset-bottom)));
        margin-left: calc(-1 * max(14px, env(safe-area-inset-left)));
        padding:
          12px
          max(14px, env(safe-area-inset-right))
          max(14px, env(safe-area-inset-bottom))
          max(14px, env(safe-area-inset-left));
        background: rgba(255,255,255,.96);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
      }
      .buyer-arrangement-action {
        flex: 1;
        padding-left: 8px;
        padding-right: 8px;
      }
    }

  </style>

  <style id="orderChatRefactor">
    .chat-header .order-summary, .chat-panel > .quick-replies { display:none!important; }
    .message-order-card.order-chat-card { display:flex; gap:12px; width:min(350px,100%); padding:11px; margin-top:4px; border:1px solid rgba(127,127,127,.17); border-radius:15px; background:#fff; color:#332c27; text-align:left; box-shadow:0 3px 13px rgba(45,34,24,.055); }
    .order-chat-photo { width:66px; height:66px; flex:0 0 66px; border-radius:10px; object-fit:cover; background:#eee9e4; }
    .order-chat-photo.blank { display:grid; place-items:center; font-size:23px; }
    .order-chat-info { min-width:0; flex:1; display:flex; flex-direction:column; gap:3px; }
    .order-chat-kicker { font-size:10px; font-weight:800; letter-spacing:.03em; color:#987054; }
    .order-chat-name { font-size:13px; line-height:1.32; font-weight:800; overflow-wrap:anywhere; }
    .order-chat-meta { font-size:11px; color:#74685e; }
    .order-chat-action { margin-top:7px; width:100%; min-height:34px; border:1px solid #edcba9; border-radius:8px; background:#fff5eb; color:#9e5621; font:inherit; font-size:11px; font-weight:800; cursor:pointer; }
    .order-chat-action:hover, .order-chat-action:focus-visible { background:#fce9d4; outline-offset:2px; }
    .order-chat-action:disabled { opacity:.55; cursor:wait; }
    .buyer-arrangement-item { display:flex; gap:10px; align-items:center; }
    .order-modal-photo { flex:0 0 55px; width:55px; height:55px; object-fit:cover; border-radius:10px; background:#ede8e3; }
    .order-modal-photo.blank { display:grid;place-items:center; font-size:22px; }
    .buyer-arrangement-dialog .buyer-arrangement-card { max-height:calc(100dvh - 24px); overflow-y:auto; }
    @media(max-width:500px) { .message-order-card.order-chat-card { width:min(300px,100%); gap:9px; } .order-chat-photo { width:56px;height:56px;flex-basis:56px; } }

    /* Order cards in chat. */
    .message-order-card.order-chat-card { border-color:#eadbd0; border-radius:16px; background:#fffdfb; box-shadow:0 7px 20px rgba(82,47,26,.07); }
    .message-order-card.order-chat-card.is-history { opacity:.8; box-shadow:none; background:#faf9f7; }
    .order-chat-kicker { color:#b96e30; }
    .order-chat-action { display:flex; align-items:center; justify-content:space-between; gap:8px; background:#fff1e4; border-color:#edc7a3; min-height:38px; padding:9px 11px; }
    .order-chat-action::after { content:"›"; font-size:19px; line-height:12px; font-weight:600; }
    .order-chat-action:focus-visible { outline:2px solid #d88641; }
    .buyer-arrangement-dialog { width:min(740px,calc(100% - 20px)); max-height:calc(100dvh - 24px); overflow:hidden; border:1px solid #efdfd1; }
    .buyer-arrangement-card { max-height:calc(100dvh - 24px); overflow-y:auto; overflow-x:hidden; overscroll-behavior:contain; scroll-padding-top:78px; scrollbar-gutter:stable; padding:20px 20px 22px; }
    .buyer-arrangement-head { position:sticky; top:-20px; z-index:20; margin:-20px -20px 15px; padding:18px 20px 15px; background:rgba(255,253,251,.97); border-bottom:1px solid #efe6dd; backdrop-filter:blur(12px); -webkit-backdrop-filter:blur(12px); }
    .buyer-arrangement-close { display:grid; place-items:center; flex:0 0 40px; width:40px; height:40px; background:#fff; border:1px solid #e4cdb9; color:#814b29; box-shadow:0 4px 15px rgba(76,42,21,.08); }
    .buyer-arrangement-close:hover,.buyer-arrangement-close:focus-visible { background:#fff1e4; outline:2px solid #e8b180; outline-offset:2px; }
    .buyer-arrangement-item[hidden] { display:none!important; }

    .buyer-arrangement-actions { position:sticky; bottom:-22px; z-index:19; margin:17px -20px -22px; padding:14px 20px max(16px,env(safe-area-inset-bottom)); border-top:1px solid #efe4db; background:rgba(255,253,251,.98); box-shadow:0 -8px 18px rgba(80,49,30,.035); backdrop-filter:blur(12px); -webkit-backdrop-filter:blur(12px); flex-wrap:wrap; }
    .buyer-arrangement-actions button:not([hidden]) { min-width:125px; }
    .buyer-qr-dialog { overflow:hidden; }
    .buyer-qr-card { max-height:calc(100dvh - 24px); overflow-y:auto; overscroll-behavior:contain; }
    .buyer-qr-head { position:sticky; top:0; z-index:20; background:rgba(255,253,251,.97); backdrop-filter:blur(10px); }
    .buyer-qr-close { display:grid; place-items:center; }
    .buyer-qr-actions { position:sticky; bottom:0; z-index:19; background:rgba(255,255,255,.97); }
    @media(max-width:540px) {
      .buyer-arrangement-dialog { width:calc(100% - 12px); max-height:calc(100dvh - 12px); }
      .buyer-arrangement-card { max-height:calc(100dvh - 12px); padding:14px 14px 18px; scrollbar-gutter:auto; }
      .buyer-arrangement-head { top:-14px; margin:-14px -14px 12px; padding:14px 14px 12px; }
      .buyer-arrangement-actions { bottom:-18px; margin:14px -14px -18px; padding:12px 14px max(14px,env(safe-area-inset-bottom)); }
    }

    /* Ended transactions remain readable but never look actionable. */
    .chat-order-closed:not([hidden]) { display:flex; flex-wrap:wrap; align-items:center; gap:5px 9px; padding:9px 15px; background:#f6f5f3; border-top:1px solid #e6e2dd; color:#625c56; font-size:11px; line-height:1.4; }
    .chat-order-closed strong { color:#a0473d; font-weight:800; }
    .chat-order-closed span { flex:1 1 190px; }
    .message-order-card.is-closed-order { background:#faf8f6; border-color:#e5dcd5; box-shadow:none; }
    .order-chat-status { display:inline-flex; align-items:center; align-self:flex-start; border-radius:30px; padding:4px 8px; font-size:10px; font-weight:800; }
    .order-chat-status.is-closed { color:#a0473d; background:#fcecec; }
    .order-chat-reason { font-size:11px; color:#665c55; line-height:1.45; white-space:pre-wrap; overflow-wrap:anywhere; }
    .message-order-card.is-closed-order .order-chat-action { background:#f4f1ed; color:#635950; border-color:#ded8d1; }
    @media(max-width:760px) { .chat-order-closed:not([hidden]) { padding:7px 12px; font-size:10px; } }
  </style>
  <style id="buyerMessagesSellerMatchedDesign">
    /* Match Seller Profile/Seller Messages typography without changing actions. */
    .chat-body { scroll-behavior:auto !important; }
    /* Notification deep links isolate the requested order without hiding the rest of the inbox permanently. */
    .lc-linked-order-filter[hidden] { display:none !important; }
    .lc-linked-order-filter { display:flex; align-items:center; justify-content:space-between;
      gap:10px; padding:9px 14px; border-bottom:1px solid #eadfd5;
      background:#fff8ed; color:#62564d;
      font:500 12px/1.4 Inter,system-ui,-apple-system,"Segoe UI",sans-serif; }
    .lc-linked-order-filter strong { color:#a55325; font-weight:800; }
    .lc-linked-order-filter button { flex:0 0 auto; min-height:36px;
      border:1px solid #dfc8b5; background:#fffcf8; color:#a55325;
      border-radius:9px; padding:7px 11px; font-family:inherit; font-size:12px; line-height:1.3; font-weight:700; cursor:pointer; }
    .lc-linked-order-filter button:hover { background:#fff2e4; }
    @media(max-width:400px) { .lc-linked-order-filter { padding:8px 10px; gap:7px; font-size:11px; }
      .lc-linked-order-filter button { font-size:11px; padding:6px 9px; } }
    .message-row.focused-order .order-chat-card {
      border-color:#e9b17b; box-shadow:0 0 0 2px rgba(234,138,58,.11),0 5px 18px rgba(52,36,23,.05);
    }
    /* Buyer order popup — the same compact layout used in Seller Messages. */
    #buyerArrangementDialog {
      box-sizing:border-box; width:min(650px,calc(100vw - 24px)); max-height:calc(100dvh - 24px);
      padding:0; margin:auto; border:1px solid #e9ded3; border-radius:18px;
      background:#fff; color:#352f29; box-shadow:0 26px 75px rgba(33,22,13,.23);
      font:400 14px/1.5 Inter,system-ui,-apple-system,"Segoe UI",sans-serif;
    }
    #buyerArrangementDialog *,#buyerArrangementDialog *::before,#buyerArrangementDialog *::after { box-sizing:border-box; }
    #buyerArrangementDialog::backdrop { background:rgba(33,25,20,.52); backdrop-filter:blur(3px); }
    #buyerArrangementDialog .buyer-arrangement-card {
      width:100%; min-width:0; max-height:calc(100dvh - 24px); padding:0 20px 20px;
      overflow-y:auto; overflow-x:hidden; overscroll-behavior:contain;
      -webkit-overflow-scrolling:touch; scroll-padding-top:92px;
    }
    #buyerArrangementDialog .buyer-arrangement-head {
      position:sticky; top:0; z-index:10; margin:0 -20px 16px;
      padding:18px 20px 16px; display:flex; align-items:flex-start; justify-content:space-between;
      gap:15px; background:#fffaf6; border-bottom:1px solid #eee5dc; backdrop-filter:none;
    }
    #buyerArrangementDialog .buyer-arrangement-head h2 {
      margin:0; color:#3d3128; font-size:17px; font-weight:800; line-height:1.35; letter-spacing:-.02em;
    }
    #buyerArrangementDialog .lc-order-heading { display:flex; flex-direction:column; gap:3px; min-width:0; }
    #buyerArrangementDialog .lc-order-heading>span { color:#92755e; font-size:12px; }
    #buyerArrangementDialog .buyer-arrangement-close {
      display:grid; place-items:center; flex:0 0 36px; width:36px; height:36px;
      padding:0; border:1px solid #e5d9ce; border-radius:50%; background:#fff; color:#6e5f52;
      box-shadow:none; cursor:pointer;
    }
    #buyerArrangementDialog .buyer-arrangement-overview {
      margin:0 0 16px; padding:14px 15px; display:flex; align-items:center;
      justify-content:space-between; gap:12px; border:1px solid #eadcca;
      border-radius:13px; background:linear-gradient(110deg,#fffdfa,#fff5e9);
    }
    #buyerArrangementDialog .buyer-arrangement-overview strong {
      color:#40352d; font-size:14px; font-weight:750; line-height:1.4;
      white-space:normal; overflow-wrap:anywhere;
    }
    #buyerArrangementDialog .buyer-arrangement-status {
      flex:0 0 auto; max-width:48%; padding:6px 10px; font-size:11px;
      line-height:1.35; font-weight:700; text-align:center; white-space:normal;
    }
    #buyerArrangementDialog .lc-order-section-title {
      margin:0 0 10px; padding:0; color:#8b5423; font-size:14px;
      font-weight:800; line-height:1.45;
    }
    #buyerArrangementDialog .buyer-arrangement-items { display:grid; gap:9px; margin:0 0 12px; }
    #buyerArrangementDialog .buyer-arrangement-items[hidden] { display:none!important; }
    #buyerArrangementDialog .buyer-arrangement-item {
      display:flex; gap:10px; align-items:flex-start; padding:12px;
      border:1px solid #e9e1d9; border-radius:11px; background:#fff; min-width:0;
    }
    #buyerArrangementDialog .buyer-arrangement-item-name { font-size:13px; font-weight:700; }
    #buyerArrangementDialog .buyer-arrangement-item-meta { font-size:12px; font-weight:400; }
    #buyerArrangementDialog .lc-order-total-row {
      display:flex; align-items:center; justify-content:space-between; gap:12px;
      margin:12px 0 20px; padding:13px 14px; border:1px solid #ecd0ae;
      border-radius:11px; background:#fff6eb;
    }
    #buyerArrangementDialog .lc-order-total-row span { color:#795339; font-size:13px; font-weight:750; }
    #buyerArrangementDialog .lc-order-total-row strong { color:#97531c; font-size:18px; font-weight:800; font-variant-numeric:tabular-nums; }
    #buyerArrangementDialog .lc-order-section-header {
      display:flex; align-items:center; justify-content:space-between; gap:12px; margin:0 0 10px;
    }
    #buyerArrangementDialog .lc-order-section-header .lc-order-section-title { margin:0; }
    #buyerArrangementDialog .lc-order-payment-status {
      padding:5px 9px; border:1px solid #e6ddd4; border-radius:100px;
      background:#faf8f6; color:#6c6157; font-size:11px; font-weight:700;
    }
    /* Match Seller's Delivery & Payment field order and appearance, read-only for Buyer. */
    #buyerArrangementDialog .lc-buyer-order-details { display:grid; gap:11px; min-width:0; }
    #buyerArrangementDialog .lc-buyer-detail-row {
      display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:11px; min-width:0;
    }
    #buyerArrangementDialog .lc-buyer-detail-row-single { grid-template-columns:minmax(0,1fr); }
    #buyerArrangementDialog .lc-buyer-detail-row[hidden] { display:none!important; }
    #buyerArrangementDialog .lc-buyer-detail { display:flex; flex-direction:column; gap:6px; min-width:0; }
    #buyerArrangementDialog .lc-buyer-detail span {
      display:block; color:#6c5f54; font-size:12px; font-weight:700; line-height:1.4;
    }
    #buyerArrangementDialog .lc-buyer-detail strong {
      display:flex; align-items:center; min-width:0; min-height:44px;
      padding:10px 12px; border:1px solid #e1d9d2; border-radius:9px;
      background:#fff; color:#433a34; font-size:15px; font-weight:400; line-height:1.45;
      overflow-wrap:anywhere; white-space:pre-wrap;
    }
    #buyerArrangementDialog .lc-buyer-detail-notes strong { align-items:flex-start; min-height:68px; }
    #buyerArrangementDialog .buyer-arrangement-feedback { margin:13px 0 0; font-size:12px; }
    #buyerArrangementDialog .buyer-arrangement-actions {
      position:sticky; z-index:9; bottom:-20px; display:flex; justify-content:flex-end;
      flex-wrap:wrap; gap:9px; margin:18px -20px -20px;
      padding:13px 20px max(16px,env(safe-area-inset-bottom));
      border-top:1px solid #eee5dd; background:#fffdfa;
    }
    #buyerArrangementDialog .buyer-arrangement-action {
      min-height:42px; padding:10px 16px; border-radius:10px;
      font:700 13px/1.4 Inter,system-ui,-apple-system,"Segoe UI",sans-serif;
      cursor:pointer; transition:background-color .15s ease;
    }
    #buyerArrangementDialog .buyer-arrangement-action[hidden] { display:none!important; }
    #buyerArrangementDialog .buyer-arrangement-action:hover:not(:disabled) { transform:none; }
    @media(max-width:540px) {
      #buyerArrangementDialog { width:calc(100vw - 16px); max-height:calc(100dvh - 16px); border-radius:16px; }
      #buyerArrangementDialog .buyer-arrangement-card { max-height:calc(100dvh - 16px); padding:0 14px 16px; }
      #buyerArrangementDialog .buyer-arrangement-head { margin:0 -14px 14px; padding:15px 14px 13px; }
      /* Same stacked form layout as Seller Messages on narrow screens. */
      #buyerArrangementDialog .lc-buyer-detail-row { grid-template-columns:minmax(0,1fr); gap:11px; }
      #buyerArrangementDialog .buyer-arrangement-actions {
        bottom:-16px; margin:15px -14px -16px; padding:12px 14px max(14px,env(safe-area-inset-bottom));
      }
      #buyerArrangementDialog .buyer-arrangement-action { flex:1 1 125px; }
    }
    .lc-order-progress { display:flex; flex-wrap:wrap; gap:7px; padding:9px 0 3px; }
    .lc-order-progress[hidden] { display:none; }
    .lc-order-progress span { border-radius:999px; border:1px solid #e7e2dd; background:#fff; padding:5px 9px; color:#8d8882; font-size:.68rem; font-weight:700; }
    .lc-order-progress span.done { background:#f2f8f0; border-color:#cfe6d0; color:#407b4b; }
    .lc-order-progress span.current { background:#fff0e2; border-color:#f1c495; color:#bd671c; }
    .lc-review-dialog { border:0; background:transparent; padding:16px; margin:auto; width:min(440px,100%); max-height:calc(100dvh - 32px); overflow-y:auto; }
    .lc-review-dialog::backdrop { background:rgba(19,24,32,.58); }
    .lc-review-panel { border-radius:16px; padding:22px; background:#fff; color:#242424; box-shadow:0 20px 65px rgba(0,0,0,.2); display:flex; flex-direction:column; gap:10px; }
    .lc-review-top { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:7px; }
    .lc-review-top h2 { margin:0; font-size:1.1rem; }
    .lc-review-close { width:32px; height:32px; border:1px solid #ddd; border-radius:50%; background:#fff; font-size:1.3rem; cursor:pointer; }
    .lc-review-panel label { color:#343434; font-size:.79rem; font-weight:700; }
    .lc-review-panel label span { color:#878787; font-weight:400; }
    .lc-review-panel select,.lc-review-panel textarea { width:100%; font:inherit; font-size:.82rem; padding:10px 12px; border:1px solid #d9d9d9; border-radius:9px; background:#fff; color:#222; }
    .lc-review-panel select:focus-visible,.lc-review-panel textarea:focus-visible { outline:2px solid #e9944a; outline-offset:1px; }
    .lc-review-stars { display:flex; gap:7px; margin:2px 0 8px; }
    .lc-review-stars button { font-size:1.75rem; color:#bdaf9d; background:transparent; border:0; cursor:pointer; padding:2px 4px; }
    .lc-review-stars button.active { color:#df8c21; }
    .lc-review-feedback { min-height:1.2em; color:#bd3f3f; font-size:.76rem; }
    .lc-review-actions { display:flex; justify-content:flex-end; gap:10px; }
    .lc-review-actions button { border-radius:9px; padding:9px 13px; font-size:.8rem; font-weight:700; cursor:pointer; }
    .lc-review-cancel { background:#fff; border:1px solid #ddd; color:#3d3d3d; }
    .lc-review-submit { background:#e9851d; border:1px solid #d8791a; color:white; }
    .lc-review-submit:disabled { opacity:.48; cursor:not-allowed; }
      .lc-chat-order-tag { display:inline-block; margin-bottom:4px; font-size:.64rem; font-weight:800; letter-spacing:.01em; opacity:.72; }

    /* Cart-only order detail breakdown, sourced from saved order item snapshots. */
    .lc-cart-order-item{align-items:start;min-width:0;padding:12px;border-radius:11px;background:#fff;border:1px solid #e9e1d9;}
    .lc-order-line-main{min-width:0;flex:1;}
    .lc-price-breakdown{margin-top:9px;padding-top:9px;border-top:1px solid #eee8e1;display:grid;gap:7px;min-width:0;}
    .lc-price-line{display:flex;align-items:baseline;justify-content:space-between;gap:10px;color:#73675d;font-size:12px;line-height:1.4;}
    .lc-price-line>span{min-width:0;overflow-wrap:anywhere;}
    .lc-price-line>strong{flex:none;color:#43372e;font-weight:700;white-space:nowrap;}
    .lc-price-addon>strong{color:#a86932;}
    .lc-price-total{padding-top:9px;border-top:1px solid #eee8e1;color:#332922;font-weight:800;}
    .lc-price-total>strong{font-size:13px;color:#9e571f;}
    .lc-custom-text{font-size:11px;color:#8a7666;white-space:pre-wrap;overflow-wrap:anywhere;margin-top:-3px;padding-left:9px;border-left:2px solid #edcba7;}
    @media(max-width:560px){.lc-cart-order-item{padding:10px;gap:9px}.lc-price-line{font-size:11px}}
</style>
<style id="buyerChatStoreProfileStyles">
.product-details-save.is-saved {
      border-color: #e7a365;
      background: #fff4e9;
      color: #bd6318;
    }
.product-rating-stars,
    .product-details-rating-stars,
    .review-card-stars {
      color: #e3a22e;
      letter-spacing: .04em;
    }
.product-details-modal {
      position: fixed !important;
      inset: 0 !important;
      width: 100vw;
      height: 100vh;
      z-index: 100000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 26px;
    }
.product-details-modal.is-open {
      display: flex;
    }
.product-details-backdrop {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      background: rgba(18, 24, 32, 0.58);
      backdrop-filter: blur(2px);
    }
.product-details-panel {
      position: relative;
      width: min(940px, 100%);
      max-height: calc(100vh - 52px);
      overflow: auto;
      background: #f7f8fa;
      border-radius: 14px;
      box-shadow: 0 24px 70px rgba(0, 0, 0, 0.24);
      padding: 26px;
      z-index: 1;
    }
.product-details-close {
      position: absolute;
      top: 14px;
      right: 14px;
      width: 34px;
      height: 34px;
      border: 1px solid #d9dde2;
      border-radius: 50%;
      background: rgba(255,255,255,0.96);
      color: #4a4f55;
      font-size: 20px;
      line-height: 1;
      cursor: pointer;
      display: grid;
      place-items: center;
      z-index: 2;
    }
.product-details-close:hover {
      background: #ffffff;
    }
.product-details-top {
      display: grid;
      grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
      gap: 24px;
      align-items: start;
    }
.product-details-image-wrap {
      width: 100%;
      aspect-ratio: 1 / 1;
      border-radius: 8px;
      overflow: hidden;
      background: #e7e9ec;
    }
.product-details-image {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
.product-details-placeholder {
      width: 100%;
      height: 100%;
      display: grid;
      place-items: center;
      color: #8a9097;
      font-size: 3rem;
      background: linear-gradient(135deg, #eceff2, #dfe3e8);
    }
.product-details-name {
      margin: 0 0 5px;
      padding-right: 40px;
      font-size: 1.5rem;
      line-height: 1.25;
      font-weight: 800;
      color: #111827;
    }
.product-details-price {
      margin-bottom: 14px;
      font-size: 1.25rem;
      line-height: 1.2;
      font-weight: 800;
      color: #d56d00;
    }
.product-details-meta {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 8px;
      margin-bottom: 20px;
    }
.product-details-location {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      font-size: 0.75rem;
      color: #4f5660;
    }
.product-details-category {
      display: inline-flex;
      align-items: center;
      padding: 5px 9px;
      border-radius: 999px;
      background: #fff4c8;
      color: #c47a00;
      font-size: 0.68rem;
      font-weight: 800;
    }
.product-details-section-title {
      margin-bottom: 7px;
      font-size: 0.86rem;
      line-height: 1.2;
      font-weight: 800;
      color: #111827;
    }
.product-details-description {
      margin-bottom: 16px;
      color: #59616c;
      font-size: 0.74rem;
      line-height: 1.65;
    }
.product-details-stock {
      margin-bottom: 17px;
      color: #46505b;
      font-size: 0.72rem;
    }
.product-details-actions {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 9px;
    }
.product-details-btn {
      min-height: 38px;
      border-radius: 7px;
      padding: 8px 12px;
      font-size: 0.73rem;
      font-weight: 700;
      cursor: pointer;
      transition: 0.18s ease;
    }
.product-details-save {
      border: 1px solid #dadde1;
      background: #ffffff;
      color: #262b30;
    }
.product-details-save:hover {
      background: #f3f5f7;
    }
.product-details-cart {
      border: 1px solid #dd7200;
      background: #e57400;
      color: #ffffff;
    }
.product-details-cart:hover {
      background: #ce6800;
    }
.product-details-rating {
      display: flex;
      align-items: center;
      gap: 7px;
      margin: -6px 0 14px;
      color: #6c645e;
      font-size: .72rem;
      font-weight: 700;
    }
.product-reviews-card {
      margin-top: 16px;
      padding: 18px;
      border: 1px solid #dfe2e6;
      border-radius: 12px;
      background: #fff;
    }
.product-reviews-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 13px;
    }
.product-reviews-title {
      color: #111827;
      font-size: .86rem;
      font-weight: 800;
    }
.product-reviews-summary {
      margin-top: 3px;
      color: #747b82;
      font-size: .68rem;
    }
.product-reviews-list {
      display: grid;
      gap: 9px;
    }
.review-card {
      padding: 11px 12px;
      border: 1px solid #ece8e4;
      border-radius: 10px;
      background: #faf9f7;
    }
.review-card-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
    }
.review-card-buyer {
      color: #34302d;
      font-size: .7rem;
      font-weight: 800;
    }
.review-card-date {
      color: #948c85;
      font-size: .6rem;
    }
.review-card-stars {
      margin-top: 5px;
      font-size: .76rem;
    }
.review-card-text {
      margin-top: 6px;
      color: #5f5954;
      font-size: .68rem;
      line-height: 1.5;
      white-space: pre-wrap;
      overflow-wrap: anywhere;
    }
.reviews-empty {
      padding: 16px 10px;
      color: #88817b;
      text-align: center;
      font-size: .69rem;
    }
.seller-details-card {
      margin-top: 24px;
      padding: 21px 18px;
      border: 1px solid #dfe2e6;
      border-radius: 12px;
      background: #ffffff;
    }
.seller-details-title {
      margin-bottom: 22px;
      font-size: 0.82rem;
      font-weight: 800;
      color: #111827;
    }
.seller-details-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
    }
.seller-details-person {
      display: flex;
      align-items: center;
      gap: 11px;
      min-width: 0;
    }
.seller-avatar {
      width: 35px;
      height: 35px;
      border-radius: 50%;
      display: grid;
      place-items: center;
      flex: 0 0 auto;
      background: #fff0c7;
      color: #ee9200;
      font-size: 0.95rem;
    }
.seller-details-name {
      font-size: 0.76rem;
      font-weight: 700;
      color: #111827;
    }
.seller-details-location {
      margin-top: 2px;
      font-size: 0.68rem;
      color: #69717b;
    }
.seller-details-actions {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-shrink: 0;
    }
.seller-action-btn {
      min-height: 34px;
      padding: 7px 11px;
      border: 1px solid #dce0e5;
      border-radius: 7px;
      background: #ffffff;
      color: #20252a;
      font-size: 0.7rem;
      font-weight: 700;
      cursor: pointer;
    }
.seller-action-btn:hover {
      background: #f4f6f8;
    }
.product-details-chat {
      border: 1px solid #dce0e5;
      background: #ffffff;
      color: #20252a;
    }
.product-details-chat:hover{
      background: #f4f6f8;
    }
.marketplace-toast {
      position: fixed;
      left: 50%;
      bottom: 24px;
      z-index: 120000;
      max-width: min(420px, calc(100vw - 30px));
      padding: 11px 15px;
      border: 1px solid #dedede;
      border-radius: 10px;
      background: #24282d;
      color: #fff;
      font-size: .73rem;
      line-height: 1.4;
      box-shadow: 0 12px 30px rgba(0,0,0,.18);
      transform: translate(-50%, 20px);
      opacity: 0;
      pointer-events: none;
      transition: opacity .2s ease, transform .2s ease;
    }
.marketplace-toast.show {
      opacity: 1;
      transform: translate(-50%, 0);
    }
@media  (max-width: 560px) {.product-details-modal {
        padding: 10px;
      }.product-details-panel {
        max-height: calc(100vh - 20px);
        padding: 18px;
        border-radius: 12px;
      }.product-details-top {
        grid-template-columns: 1fr;
        gap: 17px;
      }.product-details-name {
        font-size: 1.25rem;
      }.product-details-actions {
        grid-template-columns: 1fr;
      }.seller-details-row {
        align-items: flex-start;
        flex-direction: column;
      }.seller-details-actions {
        width: 100%;
      }.seller-action-btn {
        flex: 1;
      }}
.product-details-panel,.marketplace-action-panel{
      border-radius: 24px;
    }
.product-details-image-wrap,.product-reviews-card,.review-card,.seller-details-card,.marketplace-toast{
      border-radius: 16px;
    }
.product-details-btn,.seller-action-btn,.marketplace-secondary-action{
      border-radius: 12px;
    }
@media  (max-width: 560px) {.product-details-panel,.marketplace-action-panel{
        border-radius: 20px;
      }}
.product-details-modal,.lc-store-modal,.marketplace-action-modal{position:fixed!important;inset:0!important;width:100%!important;height:var(--lc-vh,100dvh)!important;min-width:0;box-sizing:border-box}
.product-details-modal{z-index:140000;padding:22px}
.product-details-backdrop,.lc-store-backdrop{background:rgba(35,27,21,.57);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px)}
.product-details-panel{width:min(980px,100%);height:auto;max-height:calc(var(--lc-vh,100dvh) - 44px);border-radius:21px;border:1px solid #ebded2;background:#fffdfa;padding:27px;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;scrollbar-gutter:stable}
.product-details-top{gap:24px}
.product-details-image-wrap{border-radius:17px;background:#f7f1ea}
.product-details-name{color:#362b23;font-size:clamp(1.32rem,2.4vw,1.8rem)}
.product-details-price{color:#bc702f}
.product-details-category{background:#fff0df;color:#a8612b}
.product-details-section-title,.product-reviews-title,.seller-details-title{color:#42352a}
.product-details-description,.product-details-stock{color:#77675c;font-size:.86rem}
.product-details-close,.lc-store-close{display:grid;place-items:center;position:sticky;float:right;top:0;right:0;width:40px;height:40px;z-index:10;border-radius:50%;border:1px solid #ebdacf;background:#fffaf4;color:#795338;font-size:25px;cursor:pointer;box-shadow:0 3px 12px rgba(52,32,17,.12)}
.product-details-actions{grid-template-columns:minmax(0,1fr) minmax(0,1fr) 48px;gap:9px}
.product-details-btn{display:inline-flex;justify-content:center;align-items:center;gap:7px;min-width:0;min-height:47px;padding:8px 12px;border-radius:12px;font-size:.81rem;font-weight:800}
.product-details-btn svg{width:18px;height:18px;flex:0 0 18px}
.product-details-cart{background:#fff6ed;color:#b86c32;border:1px solid #f1d1b2}
.product-details-cart:hover{background:#fdebd8}
.product-details-save{display:grid;place-items:center;padding:0;border:1px solid #ead9c9;background:#fff;color:#947b66;transition:.15s}
.product-details-save:hover,.product-details-save.is-saved{background:#fff0e1;border-color:#e7aa77;color:#ca6c2c}
.product-details-save.is-saved svg{fill:currentColor}
.product-details-btn:disabled{opacity:.65;cursor:default}
.product-details-btn.is-complete{background:#e9f8f1;color:#20744b;border-color:#b7dfc8}
.product-reviews-card,.seller-details-card{border:1px solid #eee4d9;border-radius:16px;background:#fff;box-shadow:0 3px 12px rgba(67,44,22,.035)}
.review-card{background:#fdfaf7;border:1px solid #f1e6da}
.review-card-text{font-size:.82rem}
.review-card-buyer{font-size:.77rem}
.review-card-date{font-size:.7rem}
.lc-see-more{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:42px;margin-top:13px;padding:10px;border:1px solid #ebd8c5;border-radius:11px;background:#fff6ed;color:#a55f2f;font-size:.8rem;font-weight:800;cursor:pointer}
.lc-see-more:hover{background:#ffebd8}
.lc-see-more[hidden]{display:none!important}
.lc-see-more:disabled{opacity:.55;cursor:wait}
.lc-store-name{border:0;padding:0;background:transparent;text-align:left;color:#664529;font-size:.83rem;font-weight:800;cursor:pointer;overflow-wrap:anywhere}
.lc-store-name:hover{text-decoration:underline}
.seller-details-person{min-width:0}
.seller-details-person>div:last-child{min-width:0}
.seller-details-actions{min-width:0}
.seller-action-btn{border-radius:10px;border:1px solid #e6d7ca;background:#fffaf6;color:#9a602f;min-height:38px;font-size:.76rem}
.lc-store-modal{display:none;align-items:center;justify-content:center;padding:22px;z-index:130000}
.lc-store-modal.is-open{display:flex}
.lc-store-backdrop{position:absolute;inset:0}
.lc-store-panel{position:relative;z-index:1;display:flex;flex-direction:column;width:min(850px,100%);max-height:calc(var(--lc-vh,100dvh) - 44px);height:min(830px,100%);min-width:0;border:1px solid #edddcb;border-radius:22px;background:#fffdfa;box-shadow:0 28px 80px rgba(29,19,12,.22);overflow:hidden}
.lc-store-header{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 21px;border-bottom:1px solid #f0e7de;background:#fffaf5}
.lc-store-heading{min-width:0}
.lc-eyebrow{display:block;color:#b47543;font-size:.65rem;font-weight:850;letter-spacing:.14em}
.lc-store-heading h2{font-size:clamp(1.2rem,3vw,1.55rem);margin-top:5px;line-height:1.15;color:#372b21;overflow-wrap:anywhere}
.lc-store-close{float:none;position:relative;flex:0 0 40px}
.lc-store-content{flex:1 1 auto;min-height:0;overflow-y:auto;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;padding:20px}
.lc-store-summary{display:flex;align-items:center;gap:14px;padding:21px;border:1px solid #ead8c7;border-radius:16px;background:linear-gradient(116deg,#fffdfa,#ffeedb)}
.lc-store-avatar{display:grid;place-items:center;flex:0 0 60px;width:60px;height:60px;border-radius:17px;border:3px solid #fff;background:linear-gradient(135deg,#A55325,#C2692E);color:#FFF8ED;font-size:1.4rem;font-weight:800}
.lc-store-summary-name{font-size:1.1rem;font-weight:850;color:#493225;overflow-wrap:anywhere}
.lc-store-summary-meta{font-size:.8rem;color:#8e7764;margin-top:5px}
.lc-store-details-toggle{max-width:190px;margin:12px 0 0}
.lc-store-info{margin:14px 0 0}
.lc-store-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.lc-store-fact{padding:14px;border:1px solid #f0e4da;border-radius:11px;background:#fff}
.lc-store-fact.wide{grid-column:1/-1}
.lc-store-fact small{display:block;margin-bottom:6px;color:#a08369;font-weight:800;font-size:.7rem}
.lc-store-fact strong{display:block;color:#504338;font-size:.84rem;font-weight:700;line-height:1.45;white-space:pre-wrap;overflow-wrap:anywhere}
.lc-store-products-heading{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:25px 0 12px}
.lc-store-products-heading h3{font-size:1rem;color:#413122}
.lc-store-products-heading span{font-size:.75rem;color:#987d67}
.lc-store-products{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}
.lc-store-product{min-width:0;display:flex;gap:12px;align-items:center;text-align:left;border:1px solid #eee2d7;border-radius:13px;padding:10px;background:#fff;cursor:pointer}
.lc-store-product:hover{border-color:#ddaf82;background:#fffaf5}
.lc-store-product-image{flex:0 0 83px;width:83px;height:83px;border-radius:10px;overflow:hidden;background:#f5ede5;display:grid;place-items:center;color:#c08353;font-size:1.5rem}
.lc-store-product-image img{display:block;width:100%;height:100%;object-fit:cover}
.lc-store-product-meta{min-width:0}
.lc-store-product-meta strong{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;font-size:.86rem;color:#473a30}
.lc-store-product-meta span{display:block;margin-top:5px;color:#b56b2d;font-weight:850;font-size:.88rem}
.lc-store-product-meta small{display:block;margin-top:5px;color:#846f5c;font-size:.72rem}
.lc-loading{padding:24px;text-align:center;font-size:.86rem;color:#887a6d;grid-column:1/-1}
.marketplace-toast{z-index:180000;border:1px solid #d7bfaa;background:#3e332b;font-size:.82rem;border-radius:12px}
.marketplace-toast.success{background:#2e6544}
.marketplace-toast.error{background:#883e36}
@media (max-width:560px){.product-details-modal,.lc-store-modal{padding:0!important}.product-details-panel{width:100vw;height:var(--lc-vh,100dvh);max-height:var(--lc-vh,100dvh);border:0;border-radius:0!important;padding:max(18px,env(safe-area-inset-top)) 15px calc(25px + env(safe-area-inset-bottom));overflow-y:auto}.product-details-close{position:sticky;top:0;margin-right:0;width:40px;height:40px}.product-details-top{display:grid;grid-template-columns:1fr;gap:13px}.product-details-image-wrap{aspect-ratio:1/0.8;border-radius:14px}.product-details-name{font-size:1.35rem;padding-right:0;clear:both}.product-details-actions{grid-template-columns:minmax(0,1fr) minmax(0,1fr) 43px;gap:6px}.product-details-btn{font-size:.74rem;padding:8px 6px;min-height:45px;border-radius:10px}.product-details-btn svg{height:15px;width:15px;flex-basis:15px}.seller-details-card{margin-top:17px;padding:15px}.seller-details-row{flex-direction:column;align-items:stretch}.seller-details-actions{width:100%}.seller-action-btn{flex:1}.product-reviews-card{padding:15px}.review-card{padding:11px}.lc-store-panel{height:var(--lc-vh,100dvh);max-height:var(--lc-vh,100dvh);width:100vw;border:0;border-radius:0}.lc-store-header{padding:calc(15px + env(safe-area-inset-top)) 15px 13px}.lc-store-content{padding:15px 14px calc(30px + env(safe-area-inset-bottom))}.lc-store-summary{padding:15px}.lc-store-avatar{flex-basis:52px;width:52px;height:52px}.lc-store-facts{grid-template-columns:1fr}.lc-store-fact.wide{grid-column:auto}.lc-store-products{grid-template-columns:1fr}.lc-store-product-image{flex-basis:75px;width:75px;height:75px}}
@media (max-width:350px){.product-details-btn{font-size:.68rem}.product-details-actions{grid-template-columns:minmax(0,1fr) minmax(0,1fr) 40px}.lc-store-avatar{flex-basis:45px;width:45px;height:45px}.lc-store-summary{gap:9px}}
@media (prefers-reduced-motion:reduce){.product-card,.product-details-btn,.lc-see-more,.marketplace-toast{transition:none!important}}
.product-details-modal,.lc-store-modal,.marketplace-action-modal{top:var(--lc-vtop,0px)!important;bottom:auto!important}
.lc-card-store:focus-visible,.product-card:focus-visible,.lc-store-product:focus-visible,.lc-store-close:focus-visible{outline:3px solid #e4a56e;outline-offset:2px}
.lc-spinner{display:inline-block;width:15px;height:15px;flex:0 0 15px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:lcspin .7s linear infinite}
@media (prefers-reduced-motion:reduce){.lc-spinner{animation-duration:1.4s}}
.lc-store-avatar-button{border:1px solid #f4dec7;cursor:pointer;color:#af6e39;background:#fff1e0}
.lc-store-avatar-button:hover{background:#ffe3c5}
.product-details-panel,.lc-store-panel{background:var(--lc-surface);border:1px solid #ead9c9;border-radius:22px;box-shadow:var(--lc-shadow)}
.lc-floating-close,.product-details-close,.lc-store-close{position:absolute!important;float:none!important;z-index:35!important;top:14px!important;right:14px!important;margin:0!important;display:grid;place-items:center;flex:none;width:40px!important;height:40px!important;padding:0!important;border:1px solid #eddcc9;border-radius:50%!important;background:rgba(255,253,249,.97);color:#835c3d;box-shadow:0 5px 18px rgba(59,35,15,.11);cursor:pointer;transition:transform .14s,background .14s}
.lc-floating-close svg,.product-details-close svg,.lc-store-close svg{width:19px;height:19px;pointer-events:none}
.lc-floating-close:hover{background:#fff0df;transform:scale(1.04)}
.lc-floating-close:focus-visible{outline:3px solid #e9a264;outline-offset:2px}
.product-details-modal{padding:20px}
.product-details-panel{position:relative;display:flex;flex-direction:column;overflow:hidden;width:min(1120px,100%);height:min(820px,calc(var(--lc-vh,100dvh) - 40px));max-height:calc(var(--lc-vh,100dvh) - 40px);padding:0;scrollbar-gutter:auto}
.lc-product-layout{display:grid;grid-template-columns:minmax(0,46%) minmax(0,54%);width:100%;height:100%;min-height:0;min-width:0;overflow:hidden}
.lc-product-media{min-width:0;min-height:0;padding:20px;display:flex;flex-direction:column;align-items:stretch;justify-content:center;gap:12px;background:#f8f1e9;border-right:1px solid #eee1d4}
.product-details-image-wrap{width:100%;aspect-ratio:1/1;max-height:calc(var(--lc-vh,100dvh) - 165px);border:1px solid #ebddd0;border-radius:18px;background:#fffaf5;overflow:hidden;display:grid;place-items:center}
.product-details-image{object-fit:contain;width:100%;height:100%}
.product-details-placeholder{min-height:230px}
.lc-image-caption{display:flex;align-items:center;justify-content:center;gap:7px;color:#987f6a;font-size:12px;font-weight:700}
.lc-image-caption svg{width:16px;height:16px}
.lc-product-info-scroll{height:100%;min-height:0;min-width:0;padding:29px 28px 30px;overflow-y:auto;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;scroll-padding-top:30px;scroll-padding-bottom:50px}
.product-details-top{display:block}
.lc-product-main{min-width:0}
.product-details-name{padding-right:43px;font-size:clamp(1.35rem,2.2vw,1.9rem);color:#3b2d23;line-height:1.28;margin:0 0 8px}
.product-details-price{font-size:1.45rem;color:#ba6829}
.product-details-rating{color:#77675b}
.product-details-description{font-size:.87rem;line-height:1.7;color:#66594e}
.product-details-section-title{font-size:.92rem}
.product-details-actions{grid-template-columns:minmax(0,1fr) minmax(0,1fr) 48px;gap:9px;margin-top:6px}
.product-details-btn{min-height:48px;border-radius:12px;font-size:.79rem;min-width:0}
.seller-details-card,.product-reviews-card{margin-top:20px;padding:17px;border:1px solid #eddfd2;border-radius:16px;background:#fff;box-shadow:none}
.seller-details-title,.product-reviews-title{display:flex;align-items:center;gap:9px;margin:0 0 15px;color:#47372c;font-size:.9rem;font-weight:800}
.seller-details-title svg,.product-reviews-title svg{width:18px;height:18px;color:#bd783c;flex:none}
.seller-details-row{align-items:center;gap:10px}
.seller-details-person{flex:1 1 auto;min-width:0}
.seller-avatar{width:44px;height:44px;border:2px solid #fff;border-radius:13px;background:linear-gradient(135deg,#A55325,#C2692E);box-shadow:0 4px 12px rgba(177,98,34,.14);color:#FFF8ED}
.seller-avatar svg{width:22px;height:22px}
.seller-details-name{font-size:.84rem}
.seller-details-location{font-size:.73rem;line-height:1.5;color:#877262}
.seller-details-actions{flex:0 0 auto;min-width:0;display:flex;gap:8px;flex-wrap:wrap}
.seller-action-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:41px;padding:9px 11px;border:1px solid #e8d8c8;background:#fff9f2;color:#985a29;border-radius:11px;font-weight:750;font-size:.77rem}
.seller-action-btn svg{width:16px;height:16px;flex:none}
.seller-action-btn:hover{background:#ffeddc;border-color:#eac19b}
.lc-view-store{color:#fff;background:#e98b40;border-color:#e98b40}
.lc-view-store:hover{background:#d5792f;color:#fff}
.product-reviews-summary,.product-reviews-title{color:#5b4b3e}
.lc-see-more{border-radius:12px}
.lc-store-modal{padding:20px}
.lc-store-panel{position:relative;width:min(1040px,100%);height:min(860px,calc(var(--lc-vh,100dvh) - 40px));max-height:calc(var(--lc-vh,100dvh) - 40px);overflow:hidden}
.lc-store-header{display:flex;align-items:center;min-height:85px;padding:19px 66px 16px 25px;border-bottom:1px solid #efe3d8;background:#fffdfb}
.lc-store-heading h2{margin-top:5px;font-size:1.35rem;color:#3e3025}
.lc-eyebrow{color:#ac7345;font-size:.68rem;letter-spacing:.12em}
.lc-store-content{padding:21px 25px 30px;scroll-padding-top:15px}
.lc-store-summary{position:relative;display:grid;grid-template-columns:70px minmax(0,1fr) auto;grid-template-areas:"avatar details status";align-items:center;column-gap:16px;row-gap:8px;min-width:0;min-height:147px;padding:24px;border:1px solid #ecdac8;border-radius:19px;background:linear-gradient(115deg,#fffdfa 0%,#fff8ef 52%,#fce9d4 100%);overflow:hidden;box-shadow:0 8px 18px rgba(65,40,18,.045)}
.lc-store-summary::before{position:absolute;z-index:0;content:"";width:220px;height:220px;top:-154px;right:-63px;border:1px solid rgba(215,149,94,.23);border-radius:50%;box-shadow:0 0 0 42px rgba(251,224,190,.18),0 0 0 88px rgba(253,231,207,.17);pointer-events:none}
.lc-store-summary>*{position:relative;z-index:1;min-width:0}
.lc-store-avatar{grid-area:avatar;flex:0 0 70px;width:70px;height:70px;align-self:start;justify-self:start;margin-top:3px;border:4px solid #fff;border-radius:20px;box-shadow:0 6px 18px rgba(171,97,34,.16);font-size:1.3rem}
.lc-store-profile-details{grid-area:details;min-width:0;width:100%;overflow-wrap:anywhere;flex:1 1 auto}
.lc-store-role{display:block;color:#b77946;font-weight:850;font-size:10px;letter-spacing:.13em;margin-bottom:5px}
.lc-store-summary-name{max-width:100%;font-size:clamp(1.12rem,2.3vw,1.55rem);line-height:1.25;color:#4b3525;font-weight:850;overflow-wrap:anywhere;word-break:break-word}
.lc-store-summary-meta{max-width:100%;font-size:.79rem;color:#887465;line-height:1.5;margin-top:6px;overflow-wrap:anywhere;word-break:break-word}
.lc-store-active{grid-area:status;display:inline-flex;align-items:center;justify-content:center;justify-self:end;gap:7px;white-space:nowrap;flex:0 0 auto;padding:9px 12px;border:1px solid #d0e9d9;border-radius:999px;background:rgba(255,255,255,.91);color:#237a4b;font-size:.72rem;font-weight:800}
.lc-store-active-dot{width:8px;height:8px;border-radius:50%;background:#51a071;box-shadow:0 0 0 4px #e5f5eb}
.lc-store-details-toggle{display:flex;width:100%;max-width:none!important;min-height:49px;margin:14px 0 0;align-items:center;justify-content:space-between;padding:12px 16px;border:1px solid #eddfd0;border-radius:12px;background:#fff;color:#805130;font-size:.83rem;font-weight:800;text-align:left}
.lc-store-details-toggle:hover{background:#fff7ef}
.lc-store-details-toggle svg{width:18px;height:18px;transition:transform .2s ease}
.lc-store-details-toggle[aria-expanded="true"] svg{transform:rotate(180deg)}
.lc-store-info{margin:12px 0 0}
.lc-store-fact{padding:16px;display:flex;align-items:flex-start;gap:10px;flex-direction:column;border-radius:12px;background:#fffdfb}
.lc-store-fact small{font-size:.73rem;color:#a08368}
.lc-store-fact strong{font-size:.85rem;color:#5c4b3d}
.lc-store-products-heading{margin:25px 0 14px}
.lc-store-products-heading h3{font-size:1.05rem;color:#433428}
.lc-store-products{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.lc-store-product{min-width:0;display:flex;flex-direction:column;align-items:stretch;gap:0;min-height:0;padding:0;border:1px solid #eadfd5;border-radius:16px;background:#fff;text-align:left;overflow:hidden;box-shadow:0 6px 17px rgba(43,27,15,.045);transition:border-color .16s,transform .16s,box-shadow .16s}
.lc-store-product:hover{border-color:#d9ac85;background:#fff;transform:translateY(-2px);box-shadow:0 12px 26px rgba(43,27,15,.1)}
.lc-store-product-image{flex:none;width:100%;height:auto;aspect-ratio:1/0.9;border-radius:0;display:grid;place-items:center;background:#f5eee6;overflow:hidden}
.lc-store-product-image img{width:100%;height:100%;object-fit:cover}
.lc-store-product-image svg{width:34px;height:34px;color:#bd8d65}
.lc-store-product-meta{flex:1;min-width:0;width:100%;padding:14px 13px 15px;display:flex;flex-direction:column;gap:5px}
.lc-store-item-category{display:inline-flex!important;align-self:flex-start;margin:0 0 3px!important;padding:5px 8px;border-radius:999px;background:#fff0df;color:#ac692e!important;font-size:.68rem!important;font-weight:800}
.lc-store-product-meta strong{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4;font-size:.9rem;color:#433529;font-weight:800;min-height:2.5em}
.lc-store-product-meta span{color:#b86629;font-weight:850;font-size:1rem;margin:1px 0}
.lc-store-item-rating{font-size:.74rem!important;color:#957a63!important;margin:auto 0 0!important;line-height:1.5;overflow-wrap:anywhere}
.lc-store-fact{display:flex;flex-direction:row;align-items:flex-start;gap:12px}
.lc-store-fact-icon{display:grid;place-items:center;flex:0 0 37px;width:37px;height:37px;border-radius:11px;background:#fff2e7;color:#bf7d44}
.lc-store-fact-icon svg{width:18px;height:18px}
.lc-store-fact-copy{display:block;min-width:0;flex:1}
@media (max-width:1050px){.seller-details-row{flex-wrap:wrap}.seller-details-actions{width:100%;justify-content:stretch}.seller-action-btn{flex:1}.lc-store-products{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media (max-width:760px){.lc-product-layout{grid-template-columns:1fr;display:flex;flex-direction:column;overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;height:100%}.lc-product-media{flex:none;padding:15px 15px 6px;background:#fffdfa;border-right:0}.product-details-image-wrap{max-height:none;aspect-ratio:1/0.73;border-radius:15px}.lc-image-caption{display:none}.lc-product-info-scroll{flex:none;min-height:0;height:auto;overflow:visible;padding:16px 17px 28px}.product-details-name{padding-right:0}.seller-details-row{flex-direction:column;align-items:stretch}.seller-details-actions{width:100%}.lc-store-products{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.lc-store-summary{grid-template-columns:58px minmax(0,1fr);grid-template-areas:"avatar details" "avatar status";column-gap:12px;row-gap:8px;align-items:center}.lc-store-active{margin-left:0;justify-self:start;max-width:100%}.lc-store-header{min-height:74px}.lc-store-content{padding:16px}}
@media (max-width:560px){.product-details-modal,.lc-store-modal{padding:0!important}.product-details-panel,.lc-store-panel{width:100vw!important;height:var(--lc-vh,100dvh)!important;max-height:var(--lc-vh,100dvh)!important;border:0!important;border-radius:0!important;padding:0!important}.product-details-panel{overflow:hidden!important}.lc-product-layout{padding-top:max(8px,env(safe-area-inset-top));padding-bottom:env(safe-area-inset-bottom)}.lc-product-media{padding:12px 12px 0}.product-details-image-wrap{aspect-ratio:1/0.76}.lc-product-info-scroll{padding:17px 15px 30px}.lc-floating-close,.product-details-close,.lc-store-close{top:calc(11px + env(safe-area-inset-top))!important;right:12px!important;width:39px!important;height:39px!important;background:rgba(255,253,250,.98)}.product-details-actions{grid-template-columns:minmax(0,1fr) minmax(0,1fr) 44px;gap:7px}.product-details-btn{font-size:.72rem;min-height:45px;padding:8px 7px}.seller-details-card,.product-reviews-card{padding:15px}.seller-details-title{margin-bottom:13px}.seller-details-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.seller-action-btn{min-height:43px;padding:9px 8px}.lc-store-header{padding:calc(15px + env(safe-area-inset-top)) 62px 14px 16px}.lc-store-summary{grid-template-columns:54px minmax(0,1fr);column-gap:12px;row-gap:8px;padding:16px;min-height:0}.lc-store-avatar{flex-basis:54px;width:54px;height:54px;border-radius:17px}.lc-store-active{margin:0;justify-self:start}.lc-store-summary-name{font-size:1.13rem}.lc-store-details-toggle{margin-top:12px;min-height:47px}.lc-store-product-meta{padding:10px}.lc-store-product-meta strong{font-size:.82rem}.lc-store-product-meta span{font-size:.87rem}.lc-store-product-image{aspect-ratio:1/0.83}.lc-store-item-rating{font-size:.66rem!important}}
@media (max-width:360px){.product-details-btn{font-size:.67rem}.lc-store-products{gap:8px}.lc-store-product-meta{padding:9px}.lc-store-item-rating{font-size:.63rem!important}.lc-store-summary{grid-template-columns:45px minmax(0,1fr);column-gap:9px;padding:13px}.lc-store-avatar{width:45px;height:45px;flex-basis:45px;border-radius:14px;font-size:1rem}.lc-store-active{margin:0;padding:7px 9px;font-size:.68rem}}
@media (prefers-reduced-motion:reduce){.lc-store-product,.lc-floating-close{transition:none!important}}
.lc-product-media{min-height:0;min-width:0;display:grid;grid-template-rows:minmax(0,1fr) auto;justify-items:center;align-items:stretch;gap:9px;overflow:hidden}
.product-details-image-wrap.lc-photo-open{display:flex;align-items:center;justify-content:center;width:100%;height:100%;min-width:0;min-height:0;max-height:none;aspect-ratio:auto;padding:12px;border:1px solid #e9dbcf;border-radius:18px;background:#fffaf5;overflow:hidden;cursor:zoom-in;-webkit-appearance:none;appearance:none;touch-action:manipulation}
.product-details-image-wrap.lc-photo-open:focus-visible{outline:3px solid #e7a46e;outline-offset:2px}
.product-details-image-wrap.lc-photo-open:disabled{cursor:default;color:#997e65}
.product-details-image-wrap .product-details-image{display:block;flex:none;max-width:100%;max-height:100%;width:100%;height:100%;object-fit:contain;object-position:center center;align-self:center;margin:auto}
.product-details-image-wrap .product-details-placeholder{min-height:0;display:grid;place-items:center;font-size:.85rem;color:#9c8069}
.lc-store-product-image{aspect-ratio:1 / .82;min-height:0;background:#f5eee6;display:grid;place-items:center}
.lc-store-product-image img{display:block;width:100%;height:100%;max-width:100%;max-height:100%;object-fit:contain;object-position:50% 50%}
.lc-photo-lightbox{display:none;position:fixed;inset:0;top:var(--lc-vtop,0px);height:var(--lc-vh,100dvh);z-index:195000;align-items:center;justify-content:center;padding:18px;background:rgba(28,23,19,.82);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px)}
.lc-photo-lightbox.is-open{display:flex}
.lc-photo-backdrop{position:absolute;inset:0}
.lc-photo-panel{position:relative;display:flex;flex-direction:column;width:min(1200px,100%);height:calc(100% - 4px);min-width:0;min-height:0;max-height:100%;border:1px solid #6a5a4d;border-radius:20px;background:#211e1b;box-shadow:0 26px 90px rgba(0,0,0,.38);overflow:hidden}
.lc-photo-stage{flex:1 1 auto;min-width:0;min-height:0;display:flex;align-items:center;justify-content:center;padding:22px 22px 8px;overflow:hidden}
.lc-photo-stage img{display:block;width:auto;height:auto;max-width:100%;max-height:100%;object-fit:contain;object-position:center center}
.lc-photo-footer{flex:0 0 auto;min-height:54px;padding:14px 25px;display:flex;align-items:center;justify-content:space-between;gap:14px;color:#f2e6d9;font-size:.82rem}
.lc-photo-footer span:first-child{font-weight:750;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lc-photo-footer span:last-child{color:#c7b9aa;flex:none;font-size:.72rem}
.lc-photo-close.lc-floating-close{z-index:10!important;top:14px!important;right:14px!important}
@media (max-width:760px){.lc-product-layout{min-height:0;height:100%;display:flex;flex-direction:column;overflow-x:hidden;overflow-y:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain}.lc-product-media{display:block;flex:0 0 auto;min-height:0;height:auto;overflow:visible;padding:14px 13px 0;background:#fffdfa;border-right:0}.product-details-image-wrap.lc-photo-open{display:flex;width:100%;height:clamp(185px,42svh,385px);max-height:none;min-height:185px;aspect-ratio:auto;padding:9px;border-radius:15px}.lc-product-info-scroll{flex:0 0 auto;height:auto;min-height:0;overflow:visible;padding:18px 16px 30px}.lc-photo-lightbox{padding:0}.lc-photo-panel{height:100%;width:100%;max-height:100%;border:none;border-radius:0}.lc-photo-stage{padding:max(57px,calc(env(safe-area-inset-top) + 55px)) 9px 8px}.lc-photo-footer{min-height:56px;padding:13px 17px calc(14px + env(safe-area-inset-bottom))}.lc-photo-close.lc-floating-close{top:calc(12px + env(safe-area-inset-top))!important;right:13px!important}}
@media (max-width:560px){.lc-product-layout{padding-top:env(safe-area-inset-top);padding-bottom:env(safe-area-inset-bottom)}.lc-product-media{padding:12px 12px 0}.product-details-image-wrap.lc-photo-open{height:clamp(185px,42svh,340px);min-height:185px;aspect-ratio:auto}.lc-product-info-scroll{padding:16px 15px 30px}.lc-store-product-image{aspect-ratio:1 / .82}}
@media (max-width:340px){.product-details-image-wrap.lc-photo-open{height:clamp(175px,39svh,270px);min-height:175px}}
.lc-store-products {
  display:grid;
  grid-template-columns:repeat(3,minmax(0,1fr));
  align-items:stretch;
  gap:14px;
  width:100%;
  min-width:0;
}
.lc-store-product {
  display:flex;
  flex-direction:column;
  align-items:stretch;
  width:100%;
  min-width:0;
  min-height:0;
  height:100%;
  padding:0;
  gap:0;
  overflow:hidden;
  border:1px solid #eadfd5;
  border-radius:16px;
  background:#fff;
  color:#47382e;
  font:inherit;
  text-align:left;
  box-sizing:border-box;
  cursor:pointer;
  -webkit-appearance:none;
  appearance:none;
  -webkit-tap-highlight-color:transparent;
}
.lc-store-product-image {
  position:relative;
  display:flex;
  flex:0 0 auto;
  align-items:center;
  justify-content:center;
  width:100%;
  min-width:0;
  height:auto;
  aspect-ratio:1 / .82;
  margin:0;
  padding:0;
  border-radius:0;
  overflow:hidden;
  background:#f5eee6;
}
.lc-store-product-image img {
  position:absolute;
  inset:0;
  display:block;
  width:100%;
  height:100%;
  min-width:0;
  min-height:0;
  max-width:100%;
  max-height:100%;
  margin:auto;
  object-fit:cover;
  object-position:center center;
}
.lc-store-image-unavailable {
  padding:10px;
  text-align:center;
  color:#977c66;
  font-size:.72rem;
  line-height:1.4;
}
.lc-store-product-meta {
  display:flex;
  flex:1 1 auto;
  flex-direction:column;
  align-items:flex-start;
  gap:7px;
  width:100%;
  min-width:0;
  min-height:0;
  padding:13px 13px 15px;
  overflow-wrap:anywhere;
}
.lc-store-product-meta .lc-store-item-category {
  display:inline-block!important;
  flex:0 0 auto;
  align-self:flex-start;
  width:auto;
  max-width:100%;
  margin:0 0 2px!important;
  padding:5px 9px;
  border-radius:999px;
  background:#fff0e2;
  color:#ad682d!important;
  font-size:.68rem!important;
  font-weight:800;
  line-height:1.35;
  letter-spacing:0;
  text-align:left;
  white-space:normal;
  overflow-wrap:anywhere;
}
.lc-store-product-meta strong {
  display:-webkit-box;
  -webkit-box-orient:vertical;
  -webkit-line-clamp:2;
  width:100%;
  min-width:0;
  min-height:2.7em;
  max-height:2.7em;
  margin:0;
  overflow:hidden;
  color:#433529;
  font-size:.89rem;
  font-weight:800;
  line-height:1.35;
  overflow-wrap:anywhere;
}
.lc-store-product-meta .lc-store-item-price {
  display:block;
  width:100%;
  min-width:0;
  margin:0;
  color:#b86629;
  font-size:.99rem;
  font-weight:850;
  line-height:1.35;
  overflow-wrap:anywhere;
  font-variant-numeric:tabular-nums;
}
.lc-store-product-meta .lc-store-item-rating {
  display:block;
  width:100%;
  min-width:0;
  margin:auto 0 0!important;
  color:#897363!important;
  font-size:.73rem!important;
  line-height:1.4;
  overflow-wrap:anywhere;
  white-space:normal;
}
@media (max-width:760px) {.lc-store-products {grid-template-columns:repeat(2,minmax(0,1fr));gap:11px;}.lc-store-product-image {aspect-ratio:1 / 1;}.lc-store-product-meta {gap:6px;padding:11px 10px 13px;}.lc-store-product-meta .lc-store-item-category {padding:4px 7px;font-size:.62rem!important;}.lc-store-product-meta strong {font-size:.83rem;}.lc-store-product-meta .lc-store-item-price {font-size:.9rem;}.lc-store-product-meta .lc-store-item-rating {font-size:.67rem!important;}}
@media (max-width:360px) {.lc-store-products {gap:8px;}.lc-store-product {border-radius:12px;}.lc-store-product-meta {padding:10px 8px 11px;gap:5px;}.lc-store-product-meta .lc-store-item-category {font-size:.58rem!important;padding:4px 6px;}.lc-store-product-meta strong {font-size:.78rem;}.lc-store-product-meta .lc-store-item-price {font-size:.85rem;}.lc-store-product-meta .lc-store-item-rating {font-size:.62rem!important;}}
@media (max-width:290px) {.lc-store-products {grid-template-columns:minmax(0,1fr);}.lc-store-product-meta {padding:12px;}}
.lc-store-product{position:relative;cursor:default}
.lc-store-product-open{display:flex;flex-direction:column;align-items:stretch;gap:0;width:100%;height:100%;min-width:0;min-height:0;margin:0;padding:0;border:0;border-radius:inherit;background:transparent;color:inherit;text-align:left;font:inherit;cursor:pointer;-webkit-appearance:none;appearance:none}
.lc-store-product-open:focus-visible,.lc-store-save-btn:focus-visible{outline:3px solid #e4a56e;outline-offset:-3px}
.lc-store-save-btn{position:absolute;top:10px;left:10px;z-index:6;display:grid;place-items:center;width:37px;height:37px;padding:0;border:1px solid rgba(255,255,255,.94);border-radius:50%;background:rgba(255,255,255,.96);color:#6f6158;font:700 1.45rem/1 Arial,sans-serif;box-shadow:0 4px 12px rgba(45,27,11,.11);cursor:pointer;-webkit-tap-highlight-color:transparent}
.lc-store-save-btn.is-saved{background:#fff2e5;color:#d7772c}
.lc-store-save-btn:disabled{opacity:.6;cursor:wait}
.lc-store-out-of-stock{position:absolute;top:12px;right:10px;z-index:3;max-width:calc(100% - 65px);padding:6px 9px;border:1px solid #ebd8c6;border-radius:999px;background:rgba(255,251,246,.96);color:#9b693e;font-size:.68rem;font-weight:800;line-height:1.2;text-align:center;box-shadow:0 2px 8px rgba(42,28,15,.06)}
.lc-store-product.is-out-of-stock .lc-store-product-meta .lc-store-item-price{color:#8b7c70}
@media (max-width:760px){.lc-store-save-btn{top:8px;left:8px;width:35px;height:35px}.lc-store-out-of-stock{top:9px;right:7px;font-size:.62rem;padding:5px 7px}}
.product-details-actions{grid-template-columns:minmax(0,1fr) 48px;gap:9px}
.product-details-cart{background:#e98b40;color:#fff;border:1px solid #e98b40}
.product-details-cart:hover:not(:disabled){background:#cf722b;color:#fff;border-color:#cf722b}
.marketplace-toast.show.has-action{pointer-events:auto}
.lc-toast-action{display:inline-block;margin-left:10px;color:#fff;font-weight:800;text-decoration:underline;text-underline-offset:2px;white-space:nowrap}
.lc-toast-action:focus-visible{outline:2px solid #fff;outline-offset:3px;border-radius:3px}
@media (max-width:560px){.product-details-actions{grid-template-columns:minmax(0,1fr) 44px;gap:7px}}
@media (max-width:350px){.product-details-actions{grid-template-columns:minmax(0,1fr) 40px}}
:root{--lc-surface:#fffdfb;--lc-shadow:0 24px 65px rgba(43,28,16,.2);--lc-ink:#382f29;--lc-hairline:#eadfd5;--lc-brand:#ea8a3a;--lc-brand-deep:#b8662c;--lc-cream:#fff5e9}
/* Messages integration — no changes to the underlying conversation layout. */
.lc-chat-store-trigger{cursor:pointer;transition:box-shadow .16s ease,filter .16s ease}
button#chatAvatar{padding:0;display:grid;place-items:center;color:#fff;font:inherit;font-weight:800;border:2px solid rgba(255,255,255,.9)}
.lc-chat-store-name{border:0;background:none;padding:0;margin:0;color:inherit;font:inherit;font-weight:inherit;letter-spacing:inherit;text-align:left;max-width:100%;overflow-wrap:anywhere}
.lc-chat-store-trigger:hover{filter:brightness(.97)}
.lc-chat-store-trigger:focus-visible{outline:3px solid #f1b987;outline-offset:3px;border-radius:5px}
.lc-chat-store-modal-ready{color:inherit}
.lc-store-modal,.product-details-modal,.lc-photo-lightbox{z-index:250000}
.product-details-modal{z-index:250010}
.lc-photo-lightbox{z-index:250020}
body.lc-messages-modal-open{overflow:hidden}
.lc-store-panel,.product-details-panel,.lc-photo-panel{font-family:"Segoe UI",Arial,sans-serif;color:#342d27}
.lc-store-modal [hidden],.product-details-modal [hidden],.lc-photo-lightbox [hidden]{display:none!important}
.lc-store-panel button,.product-details-panel button,.lc-photo-panel button{font-family:inherit}
.lc-store-modal.is-open,.product-details-modal.is-open,.lc-photo-lightbox.is-open{display:flex}
.lc-store-modal[aria-hidden="true"],.product-details-modal[aria-hidden="true"],.lc-photo-lightbox[aria-hidden="true"]{pointer-events:none}
@media(max-width:560px){.lc-store-panel,.product-details-panel,.lc-photo-panel{max-width:100vw}}


/* Smart dialogs: center inside the *visible* viewport, including mobile Safari keyboard. */
.buyer-arrangement-dialog[open],.buyer-qr-dialog[open],.lc-review-dialog[open]{
  position:fixed;inset:auto;left:50%;top:var(--lc-popup-center,50%);
  transform:translate(-50%,-50%);margin:0;
  max-width:calc(100vw - 16px);max-height:calc(var(--lc-popup-height,100dvh) - 16px);
  overscroll-behavior:contain;
}
.buyer-arrangement-dialog[open] .buyer-arrangement-card,
.buyer-qr-dialog[open] .buyer-qr-card{max-height:calc(var(--lc-popup-height,100dvh) - 16px)}
.lc-review-dialog[open]{overflow-y:auto;-webkit-overflow-scrolling:touch}
@media(max-width:560px){
  .buyer-arrangement-dialog[open],.buyer-qr-dialog[open],.lc-review-dialog[open]{max-height:calc(var(--lc-popup-height,100dvh) - 12px)}
  .buyer-arrangement-dialog[open] .buyer-arrangement-card,
  .buyer-qr-dialog[open] .buyer-qr-card{max-height:calc(var(--lc-popup-height,100dvh) - 12px)}
}
/* Consistent line-art file icons, replacing attachment emoji. */
.attach-label svg{width:20px;height:20px;display:block;pointer-events:none}
.attachment-preview-icon{display:inline-flex;align-items:center;justify-content:center;flex:none;color:#ad6e37}
.attachment-preview-icon svg{width:18px;height:18px;display:block}
.attachment-file svg{width:18px;height:18px;flex:0 0 18px;color:#ae713b}
.attachment-file-name{min-width:0;overflow-wrap:anywhere}
/* Clickable web URLs without changing normal chat text or bubble colors. */
.lc-chat-message-text{white-space:pre-wrap;overflow-wrap:anywhere}
.lc-chat-link{color:#9b5523;text-decoration:underline;text-underline-offset:3px;text-decoration-thickness:1px;overflow-wrap:anywhere;word-break:break-word}
.outgoing .lc-chat-link{color:#fff;text-decoration-color:rgba(255,255,255,.85)}
.lc-chat-link:focus-visible{outline:2px solid currentColor;outline-offset:2px;border-radius:2px}
</style>

<style id="buyerMessagesMarketplaceConsistency2026">
/* Buyer Messages: final For You / Saved palette, scoped to this page only. */
:root{
  --bg:#FFF8ED;
  --msg-bg:#FFF8ED;
  --msg-panel:#FFFEFC;
  --msg-panel-soft:#fffaf4;
  --msg-accent:#A55325;
  --msg-accent-dark:#A55325;
  --msg-accent-soft:#fff1e2;
  --lc-brand:#A55325;
  --lc-brand-deep:#A55325;
  --lc-cream:#FFF8ED;
  --lc-msg-header-height:74px;
}
html,body,.page{background:#FFF8ED;color:#342d27}
.page .buyer-header-tools{background:#FFF8ED}
.page>.content{
  padding-top:var(--lc-msg-header-height,74px)!important;
  background:#FFF8ED;
}
/* Remove the unwanted gap between the shared header and the message panels. */
.messages-wrap{padding:0!important;background:#FFF8ED}
.messages-shell{border:0;border-top:1px solid #e8ddd2;background:#FFFEFC}
.conversation-list{background:#FFFEFC}
.conversation-header{background:#fffaf4;border-color:#eaded3}
#conversationItems{background:#FFFEFC}
.conversation-item:hover{background:#fff6ec}
.conversation-item.active{background:#fff0e1}
.conversation-item.active::before{background:#A55325}
.conversation-unread{background:#A55325;color:#fff}
.chat-panel{background:#FFFEFC}
.chat-header{background:#fffaf4;border-color:#eaded3}
.chat-body{background:linear-gradient(180deg,#FFF8ED 0%,#fffbf5 100%)}
.outgoing .bubble{background:linear-gradient(135deg,#A55325,#C2692E);color:#fff}
.chat-input-wrap,.quick-replies{background:#FFFEFC;border-color:#eaded3}
.send-btn:not(:disabled){background:linear-gradient(135deg,#A55325,#C2692E);color:#FFF8ED}
.send-btn:hover:not(:disabled){background:linear-gradient(135deg,#C2692E,#A55325)}
.quick-reply:hover,.chat-back:hover{background:#fff0e1;color:#A55325}
/* Chat avatars match buyer_search.php exactly; store tiles keep their own shapes. */
.conversation-item .avatar,.chat-header .avatar,#chatAvatar{
  border:1px solid rgba(116,46,18,.22);
  border-radius:50%;
  background:linear-gradient(135deg,#A55325,#C2692E);
  color:#FFF8ED;
  font-weight:800;
  text-shadow:none;
  box-shadow:inset 0 1px 1px rgba(255,248,237,.14),0 2px 6px rgba(102,46,19,.16);
}
.conversation-item:hover .avatar,.conversation-item:focus-visible .avatar,#chatAvatar:hover,#chatAvatar:focus-visible{
  box-shadow:inset 0 1px 1px rgba(255,248,237,.18),0 3px 8px rgba(102,46,19,.20);
}
#chatAvatar{border:1px solid rgba(116,46,18,.22)!important}
/* Product Details seller icon: 13px rounded square. Store Profile: 20px desktop,
   17px mobile, 14px narrow. Both use their preexisting tile styling. */
.seller-avatar svg{color:#FFF8ED}
/* Product/Store dialogs keep the warm neutral Marketplace visual language. */
.lc-store-panel,.product-details-panel,.lc-photo-panel{font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:#342d27;background:#FFFEFC}
.lc-store-header,.lc-product-info-scroll{background:#FFFEFC}
.product-details-name{color:#3b2d23}
.product-details-price,.lc-store-product-meta .lc-store-item-price{color:#b86629}
.product-details-category,.lc-store-item-category,.lc-store-product-meta .lc-store-item-category{color:#A55325!important}
.lc-store-role,.lc-eyebrow,.lc-store-heading h2,.lc-store-summary-name{color:#A55325}
.product-details-save.is-saved,.product-details-save:hover,.lc-store-save-btn.is-saved{color:#A55325;background:#fff0e1;border-color:#d2a98a}
.product-details-save.is-saved svg{fill:currentColor}
.lc-store-save-btn.is-saved{background:#fff2e5}
.product-details-cart,.product-details-cart.is-complete,.lc-view-store{
  background:linear-gradient(135deg,#A55325,#C2692E)!important;
  border-color:#A55325!important;
  color:#FFF8ED!important;
}
.product-details-cart:hover:not(:disabled),.lc-view-store:hover{
  background:linear-gradient(135deg,#C2692E,#A55325)!important;
  border-color:#C2692E!important;
  color:#FFF8ED!important;
}
.seller-action-btn:not(.lc-view-store),.lc-see-more,.lc-store-details-toggle{color:#A55325}
.lc-store-fact-icon,.seller-details-title svg,.product-reviews-title svg{color:#A55325}
.lc-store-product{background:#FFFEFC;border-color:#eae0d7}
.lc-store-product:hover{border-color:#d7aa88;background:#fffaf5}
.lc-store-product-meta strong{color:#433529}
.lc-store-product-meta .lc-store-item-rating{color:#897363}
/* Genuine overall reviews directly below the store avatar. */
.lc-store-summary{grid-template-columns:136px minmax(0,1fr) auto}
.lc-store-identity{grid-area:avatar;display:flex;align-items:center;justify-content:center;align-self:center;flex-direction:column;gap:9px;width:136px;min-width:0}
.lc-store-identity .lc-store-avatar{grid-area:auto;align-self:center;justify-self:center;margin:0}
.lc-store-reviews{display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:2px 5px;max-width:100%;color:#624937;text-align:center;font-size:.73rem;font-weight:800;line-height:1.35;font-variant-numeric:tabular-nums}
.lc-store-review-star{color:#e3a22e;font-size:1.03rem;line-height:1}
/* Keep Add to Cart feedback at the BOTTOM of the visible viewport, above popups and keyboards. */
.marketplace-toast{
  position:fixed!important;
  z-index:2147483000!important;
  top:auto!important;
  bottom:calc(var(--lc-toast-bottom-inset,0px) + max(16px,env(safe-area-inset-bottom)))!important;
  left:50%;right:auto;
  width:max-content;max-width:min(440px,calc(100vw - 24px));
  transform:translate(-50%,16px);
  padding:12px 16px;
  border:1px solid #e3c7ad;
  border-radius:14px;
  background:#3e332b;color:#fff;
  box-shadow:0 14px 32px rgba(38,22,11,.25);
  font:700 .79rem/1.45 Inter,system-ui,-apple-system,"Segoe UI",sans-serif;
  text-align:center;
  overflow-wrap:anywhere;
}
.marketplace-toast.show{transform:translate(-50%,0)}
.marketplace-toast.show.has-action{pointer-events:auto}
.marketplace-toast.success{background:#2e6544;border-color:#b9d2c2}
.marketplace-toast.error{background:#883e36;border-color:#e7bcb6}
.lc-toast-action{color:#fff}
/* Keep the message composer above software keyboards on Safari/Chrome/Edge. */
.chat-panel,.messages-shell,.chat-body{min-height:0}
.chat-input-wrap{position:relative;flex:0 0 auto;z-index:25;padding-bottom:max(10px,env(safe-area-inset-bottom));background:#FFFEFC}
.chat-input textarea{font-size:16px!important;line-height:1.45;max-height:110px}
.chat-input,.send-btn,.attach-label{touch-action:manipulation}
@media(max-width:760px){
  .page>.content{padding-top:var(--lc-msg-header-height,74px)!important}
  .messages-wrap{
    position:fixed;
    top:var(--lc-msg-header-height,74px);
    right:0;bottom:var(--keyboard-inset,0px);left:0;
    height:auto;min-height:0;padding:0!important;
  }
  .messages-shell{height:100%}
  .chat-input-wrap{padding-left:max(10px,env(safe-area-inset-left));padding-right:max(10px,env(safe-area-inset-right))}
  body.keyboard-open .chat-body{height:auto!important;min-height:0!important;flex:1 1 auto!important}
  body.keyboard-open .chat-input-wrap{padding-bottom:8px}
  .lc-store-summary{grid-template-columns:100px minmax(0,1fr);grid-template-areas:"avatar details" "avatar status";gap:10px 12px}
  .lc-store-identity{width:100px;justify-self:center;gap:6px}
  .lc-store-reviews{font-size:.67rem}
}
@media(max-width:400px){
  .lc-store-summary{grid-template-columns:78px minmax(0,1fr);column-gap:10px}
  .lc-store-identity{width:78px}
  .lc-store-reviews{font-size:.61rem;gap:1px 3px}
  .lc-store-identity .lc-store-avatar{width:49px;height:49px;flex-basis:49px}
}
@media(prefers-reduced-motion:reduce){.marketplace-toast{transition:none!important}}

/* Messages header label is page-local: buyer_search.php is not modified. */
.buyer-header-tools .lc-messages-mobile-label{display:none}
.conversation-item[hidden],#lcStoreSearchEmpty[hidden]{display:none!important}
#buyerArrangementDialog .lc-order-review-link{
  border:1px solid #A55325;background:linear-gradient(135deg,#A55325,#C2692E);
  color:#FFF8ED;box-shadow:0 3px 10px rgba(122,64,31,.12);
}
#buyerArrangementDialog .lc-order-review-link:hover:not(:disabled){background:linear-gradient(135deg,#C2692E,#A55325)}
@media(max-width:760px){
  .buyer-header-tools .lc-messages-mobile-label{
    display:block;flex:0 0 auto;overflow:hidden;white-space:nowrap;
    max-width:110px;margin:0 2px 0 1px;color:#3b2d23;
    font:800 16px/1.2 Inter,system-ui,-apple-system,"Segoe UI",sans-serif;
    opacity:1;transform:translateX(0);visibility:visible;
    transition:max-width .23s ease,opacity .18s ease,transform .23s ease,margin .23s ease,visibility .23s ease;
  }
  .buyer-header-tools.lc-store-search-expanded .lc-messages-mobile-label{
    max-width:0;margin:0;opacity:0;transform:translateX(-8px);visibility:hidden;
  }
  .buyer-header-tools{gap:6px}
  /* The animated header title is already visible on phones. Keep one Messages title. */
  .conversation-list .conversation-header{display:none!important}
  body.lc-store-searching .messages-shell.chat-open .conversation-list{display:flex}
  body.lc-store-searching .messages-shell.chat-open .chat-panel{display:none}
}
@media(prefers-reduced-motion:reduce){.buyer-header-tools .lc-messages-mobile-label{transition:none}}
</style>
</head>
<body>
  <div class="page">
    <?php require __DIR__ . '/buyer_nav.php'; ?>
    <main class="content">
      <?php $searchPlaceholder = 'Search chats by store name...'; require __DIR__ . '/buyer_search.php'; ?>
      <div class="messages-wrap">
        <h1 class="page-title">Messages</h1>
        <p class="page-subtitle">Buyer inbox · Chat with sellers about products</p>
        <?php if ($errorMessage !== ''): ?>
          <div class="notice"><?php echo h($errorMessage); ?></div>
        <?php endif; ?>
        <div class="messages-shell <?php echo $selectedPartnerId > 0 ? 'has-selection' : ''; ?>" id="messagesShell">
          <aside class="conversation-list" id="conversationList" aria-label="Seller conversations">
            <div class="conversation-header">Messages</div>
            <div id="conversationItems">
              <?php if (empty($conversations)): ?>
                <div class="conversation-empty">
                  No conversations yet.<br>
                  Open a product and tap <strong>Chat</strong> to start a conversation.
                </div>
              <?php else: ?>
                <?php foreach ($conversations as $conversation): ?>
                  <?php
                    $preview = trim($conversation['preview']);
                    if ($preview === '' && $conversation['attachment'] !== '') {
                        $preview = 'Attachment';
                    }
                    $active = (int)$conversation['partner_id'] === $selectedPartnerId;
                  ?>
                  <button
                    type="button"
                    class="conversation-item <?php echo $active ? 'active' : ''; ?>"
                    data-seller-id="<?php echo (int)$conversation['partner_id']; ?>"
                  >
                    <span class="avatar"><?php echo h($conversation['initials']); ?></span>
                    <span class="conversation-main">
                      <span class="conversation-name-row">
                        <span class="conversation-name"><?php echo h($conversation['name']); ?></span>
                        <span class="conversation-time"><?php echo h($conversation['time']); ?></span>
                      </span>
                      <?php if ($conversation['product'] !== ''): ?>
                        <span class="conversation-sub"><?php echo h($conversation['product']); ?></span>
                      <?php endif; ?>
                      <span class="conversation-sub"><?php echo h($preview); ?></span>
                    </span>
                    <?php if ((int)$conversation['unread'] > 0 && !$active): ?>
                      <span class="conversation-unread"><?php echo (int)$conversation['unread']; ?></span>
                    <?php endif; ?>
                  </button>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </aside>
          <section class="chat-panel" id="chatPanel" aria-label="Chat with seller">
            <?php if ($selectedPartner): ?>
              <header class="chat-header" data-seller-id="<?php echo (int)$selectedPartnerId; ?>">
                <div class="chat-header-top">
                  <button class="chat-back" id="chatBack" type="button" aria-label="Back to conversations">‹</button>
                  <button class="avatar lc-chat-store-trigger" id="chatAvatar" type="button" aria-label="View seller store profile"><?php echo h($selectedSellerInitials); ?></button>
                  <div class="chat-header-copy">
                    <h2><button class="lc-chat-store-name lc-chat-store-trigger" id="chatName" type="button" aria-label="View seller store profile"><?php echo h($selectedSellerName); ?></button></h2>
                    <p id="chatProduct">
                      <?php
                        echo $selectedProductName !== ''
                            ? 'Product: ' . h($selectedProductName)
                            : ($selectedSellerLocation !== '' ? h($selectedSellerLocation) : 'Marketplace conversation');
                      ?>
                    </p>
                    <div class="typing-indicator" id="typingIndicator"></div>
                  </div>
                </div>
                <div class="order-summary <?php echo $selectedOrder ? 'show' : ''; ?>" id="orderSummary">
                  <div class="buyer-order-summary-head">
                    <div>
                      <div class="order-summary-title">Order Request</div>
                      <div class="order-summary-line">
                        <span id="orderSummaryId"><?php echo $selectedOrder ? 'Order #' . (int)$selectedOrder['order_id'] : ''; ?></span>
                        <span id="orderSummaryItems"><?php echo $selectedOrder ? h($selectedOrder['items_summary'] ?? '') : ''; ?></span>
                        <span id="orderSummaryTotal">
                          <?php
                            if ($selectedOrder) {
                                $summaryTotal = $selectedOrder['agreed_unit_price'] !== null
                                    ? (float)$selectedOrder['final_total']
                                    : (float)$selectedOrder['estimated_total'];
                                echo ($selectedOrder['agreed_unit_price'] !== null ? 'Agreed: ' : 'Estimated: ')
                                    . '₱'
                                    . number_format($summaryTotal, 2);
                            }
                          ?>
                        </span>
                        <span id="orderSummaryDelivery">
                          <?php
                            if ($selectedOrder && $selectedOrder['fulfillment_method']) {
                                echo 'Delivery: ' . h(
                                    buyerDeliveryOptionLabel(
                                        $selectedOrder['fulfillment_method']
                                    )
                                );
                            }
                          ?>
                        </span>
                        <span id="orderSummaryPayment">
                          <?php
                            if ($selectedOrder && $selectedOrder['payment_option']) {
                                echo 'Payment: ' . h(
                                    buyerPaymentOptionLabel(
                                        $selectedOrder['payment_option']
                                    )
                                );
                                if (!empty($selectedOrder['payment_status'])) {
                                    echo ' • ' . h(
                                        buyerPaymentStatusLabel(
                                            $selectedOrder['payment_status']
                                        )
                                    );
                                }
                            }
                          ?>
                        </span>
                        <span class="status-pill" id="orderSummaryStatus">
                          <?php
                            echo $selectedOrder
                                ? h(chatNiceStatus(
                                    in_array(chatStatusKey($selectedOrder['order_status']), ['processing', 'completed', 'accepted'], true)
                                        ? $selectedOrder['order_status']
                                        : ($selectedOrder['arrangement_status'] ?: $selectedOrder['order_status'])
                                ))
                                : '';
                          ?>
                        </span>
                      </div>
                    </div>
                    <div class="buyer-order-summary-actions">
                      <button
                        class="review-arrangement-btn"
                        id="reviewArrangementButton"
                        type="button"
                        <?php echo $selectedOrder ? '' : 'hidden'; ?>
                      >
                        <?php
                          echo $selectedOrder
                              && chatStatusKey($selectedOrder['order_status']) === 'pending'
                              && chatStatusKey($selectedOrder['arrangement_status']) === 'awaiting_confirmation'
                              ? 'Review & Confirm'
                              : 'View Order Details';
                        ?>
                      </button>
                      <button
                        class="review-arrangement-btn"
                        id="buyerLeaveReview"
                        type="button"
                        hidden
                      >Rate & Review</button>
                      <button
                        class="buyer-pay-now-btn"
                        id="buyerPayNowButton"
                        type="button"
                        <?php
                          echo $selectedOrder
                              && buyerOrderCanPayWithPayMongo($selectedOrder)
                              ? ''
                              : 'hidden';
                        ?>
                      >
                        Pay Now
                      </button>
                    </div>
                  </div>
                  <div class="lc-order-progress" id="buyerOrderProgress" aria-label="Order progress" hidden></div>

                </div>
              </header>
              <div class="lc-linked-order-filter" id="linkedOrderFilter" hidden role="status">
                <span id="linkedOrderFilterLabel">Viewing messages for <strong id="linkedOrderNumber"></strong></span>
                <button type="button" id="linkedOrderToggle" aria-label="Show all messages in this seller conversation">View all messages</button>
              </div>
              <div class="chat-body" id="chatBody">
                <?php if (empty($chatMessages)): ?>
                  <div class="chat-empty" id="chatEmpty">
                    Start the conversation with this seller.
                  </div>
                <?php endif; ?>
              </div>
              <div class="chat-error" id="chatError"></div>
              <div class="chat-order-closed" id="chatOrderClosed" role="status" aria-live="polite" hidden>
                <strong id="chatClosedTitle"></strong>
                <span id="chatClosedMessage">Transaction ended. Seller chat is still available.</span>
              </div>
              <div class="quick-replies">
                <button type="button" class="quick-reply" data-message="Hi! Is this item still available?">Is this available?</button>
                <button type="button" class="quick-reply" data-message="Can you share the status of my order?">Order status</button>
                <button type="button" class="quick-reply" data-message="Can we discuss the delivery arrangements here?">Discuss delivery</button>
                <button type="button" class="quick-reply" data-message="What delivery options can we arrange?">Ask delivery</button>
              </div>
              <div class="chat-input-wrap">
                <div class="attachment-preview" id="attachmentPreview">
                  <span class="attachment-preview-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m20.5 11.5-8.8 8.8a5 5 0 0 1-7.1-7.1l9.2-9.2a3.5 3.5 0 0 1 5 5l-9.3 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5"/></svg></span>
                  <span class="attachment-preview-name" id="attachmentPreviewName"></span>
                  <button class="attachment-remove" id="attachmentRemove" type="button" aria-label="Remove attachment">×</button>
                </div>
                <form class="chat-input" id="messageForm" enctype="multipart/form-data">
                  <label class="attach-label" aria-label="Attach file" title="Attach file">
                    <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m20.5 11.5-8.8 8.8a5 5 0 0 1-7.1-7.1l9.2-9.2a3.5 3.5 0 0 1 5 5l-9.3 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5"/></svg>
                    <input id="attachmentInput" type="file" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx">
                  </label>
                  <textarea
                    id="messageInput"
                    rows="1"
                    maxlength="2000"
                    placeholder="Type a message..."
                    autocomplete="off"
                  ></textarea>
                  <button class="send-btn" id="sendButton" type="submit" aria-label="Send message">➤</button>
                </form>
              </div>
            <?php else: ?>
              <div class="chat-empty">
                Select a conversation from the left to start chatting.
              </div>
            <?php endif; ?>
          </section>
        </div>
      </div>
    </main>
  </div>
  <dialog class="buyer-arrangement-dialog" id="buyerArrangementDialog" aria-labelledby="buyerArrangementTitle">
    <div class="buyer-arrangement-card">
      <div class="buyer-arrangement-head">
        <div class="lc-order-heading">
          <h2 id="buyerArrangementTitle">Order Details</h2>
          <span id="buyerArrangementOrderNumber">Order #</span>
        </div>
        <button class="buyer-arrangement-close" id="buyerArrangementClose" type="button" aria-label="Close order details">
          <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
      </div>
      <div class="buyer-arrangement-overview">
        <strong id="buyerArrangementProduct">Product</strong>
        <span class="buyer-arrangement-status" id="buyerArrangementStatus">Pending</span>
      </div>
      <h3 class="lc-order-section-title">Products</h3>
      <div class="buyer-arrangement-items" id="buyerArrangementItems" hidden></div>
      <div class="lc-order-total-row">
        <span>Order Total</span>
        <strong id="buyerArrangementFinalTotal">₱0.00</strong>
      </div>
      <div class="lc-order-section-header">
        <h3 class="lc-order-section-title">Delivery &amp; Payment</h3>
        <span class="lc-order-payment-status" id="buyerArrangementPaymentStatus">Not Paid</span>
      </div>
      <div class="lc-buyer-order-details">
        <div class="lc-buyer-detail-row">
          <div class="lc-buyer-detail"><span>Delivery</span><strong id="buyerArrangementFulfillment">Not set</strong></div>
          <div class="lc-buyer-detail"><span>Payment</span><strong id="buyerArrangementPayment">Not set</strong></div>
        </div>
        <div class="lc-buyer-detail-row lc-buyer-detail-row-single">
          <div class="lc-buyer-detail"><span id="buyerArrangementLocationLabel">Location</span><strong id="buyerArrangementLocation">Not set</strong></div>
        </div>
        <div class="lc-buyer-detail-row">
          <div class="lc-buyer-detail"><span>Date</span><strong id="buyerArrangementDate">Not set</strong></div>
          <div class="lc-buyer-detail"><span>Time</span><strong id="buyerArrangementTime">Not set</strong></div>
        </div>
        <div class="lc-buyer-detail-row lc-buyer-detail-row-single" id="buyerArrangementNotesRow" hidden>
          <div class="lc-buyer-detail lc-buyer-detail-notes"><span>Delivery Note</span><strong id="buyerArrangementNotes"></strong></div>
        </div>
      </div>
      <div class="buyer-arrangement-feedback" id="buyerArrangementFeedback" role="alert" aria-live="polite"></div>
      <div class="buyer-arrangement-actions">
        <button class="buyer-arrangement-action secondary" id="buyerArrangementNotYet" type="button">Close</button>
        <button class="buyer-arrangement-action primary" id="buyerConfirmArrangement" type="button" hidden>Confirm Details</button>
        <button class="buyer-arrangement-action lc-order-review-link" id="buyerArrangementReview" type="button" hidden>Rate &amp; Review</button>
        <button class="buyer-arrangement-action pay" id="buyerPayNowDialogButton" type="button" hidden>Pay with QR Ph</button>
      </div>
    </div>
  </dialog>
  <dialog class="buyer-qr-dialog" id="buyerQrDialog" aria-labelledby="buyerQrTitle">
    <div class="buyer-qr-card">
      <div class="buyer-qr-head">
        <div>
          <span class="buyer-qr-eyebrow">QR Ph via PayMongo</span>
          <h2 id="buyerQrTitle">Pay Order</h2>
        </div>
        <button class="buyer-qr-close" id="buyerQrClose" type="button" aria-label="Close QR payment">×</button>
      </div>
      <div class="buyer-qr-body">
        <div class="buyer-qr-summary">
          <span class="buyer-qr-order" id="buyerQrOrderLabel">Order</span>
          <strong class="buyer-qr-amount" id="buyerQrAmount">₱0.00</strong>
        </div>
        <div class="buyer-qr-image-wrap">
          <div class="buyer-qr-loading" id="buyerQrLoading">Generating QR...</div>
          <img id="buyerQrImage" alt="QR Ph payment code" hidden>
        </div>
        <div class="buyer-qr-timer-shell" aria-label="QR expiration countdown">
          <strong id="buyerQrTimer">15:00</strong>
        </div>
        <div class="buyer-qr-feedback" id="buyerQrFeedback" role="status" aria-live="polite"></div>
        <div class="buyer-qr-actions">
          <button class="buyer-qr-action primary" id="buyerQrSimulate" type="button" hidden>Simulate Successful Payment</button>
          <button class="buyer-qr-action" id="buyerQrRegenerate" type="button" hidden>Generate New QR</button>
        </div>
      </div>
    </div>
  </dialog>
  <div class="product-details-modal" id="productDetailsModal" aria-hidden="true">
        <div class="product-details-backdrop" id="productDetailsBackdrop"></div>
        <div class="product-details-panel" role="dialog" aria-modal="true" aria-labelledby="productDetailsName">
          <button class="product-details-close lc-floating-close" type="button" id="productDetailsClose" aria-label="Close product details"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
          <div class="lc-product-layout">
            <div class="lc-product-media"><button class="product-details-image-wrap lc-photo-open" type="button" id="productDetailsImageWrap" aria-label="View full product photo"></button><span class="lc-image-caption"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M3 8V5a2 2 0 0 1 2-2h3M16 3h3a2 2 0 0 1 2 2v3M21 16v3a2 2 0 0 1-2 2h-3M8 21H5a2 2 0 0 1-2-2v-3"/><circle cx="12" cy="12" r="3"/></svg>Product photo</span></div>
            <div class="lc-product-info-scroll" id="lcProductInfoScroll"><div class="product-details-top"><div class="lc-product-main">
              <h2 class="product-details-name" id="productDetailsName"></h2>
              <div class="product-details-price" id="productDetailsPrice"></div>
              <div class="product-details-rating" id="productDetailsRating">
                <span class="product-details-rating-stars">☆☆☆☆☆</span>
                <span>No reviews yet</span>
              </div>
              <div class="product-details-meta">
                <span class="product-details-location" id="productDetailsLocation"></span>
                <span class="product-details-category" id="productDetailsCategory"></span>
              </div>
              <div class="product-details-section-title">Description</div>
              <div class="product-details-description" id="productDetailsDescription"></div>
              <div class="product-details-stock" id="productDetailsStock"></div>
              <div class="product-details-actions">
                <button class="product-details-btn product-details-cart" type="button" id="productDetailsCart"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.4 13.1a2 2 0 0 0 2 1.7H20a2 2 0 0 0 2-1.7L23 6H6"/></svg><span>Add to Cart</span></button>
                <button class="product-details-btn product-details-save" type="button" id="productDetailsSave" aria-label="Save product" aria-pressed="false" title="Save product"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78Z"/></svg></button>
              </div>

            </div></div>
          <div class="seller-details-card">
            <div class="seller-details-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg>Seller Information</div>
            <div class="seller-details-row">
              <div class="seller-details-person">
                <div class="seller-avatar" id="lcSellerAvatar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10 5 4h14l2 6v2a3 3 0 0 1-5 2 3 3 0 0 1-4 0 3 3 0 0 1-4 0 3 3 0 0 1-5-2v-2ZM5 16v5h14v-5"/></svg></div>
                <div>
                  <button class="seller-details-name lc-store-name" type="button" id="sellerDetailsName" aria-label="View store profile"></button>
                  <div class="seller-details-location" id="sellerDetailsLocation"></div>
                </div>
              </div>
              <div class="seller-details-actions">
                <a class="seller-action-btn" id="sellerDetailsChat" href="#messageInput"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5Z"/></svg>Message</a>
                <button class="seller-action-btn lc-view-store" type="button" id="sellerDetailsStore"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M3 9 5 3h14l2 6v2a3 3 0 0 1-5 2 3 3 0 0 1-4 0 3 3 0 0 1-4 0 3 3 0 0 1-5-2V9ZM5 15v6h14v-6"/></svg>View Store</button>
              </div>
            </div>
          </div>
          <div class="product-reviews-card">
            <div class="product-reviews-header">
              <div>
                <div class="product-reviews-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="m12 2 3 6 7 .9-5 5 1.2 7-6.2-3.3L5.8 21 7 14l-5-5 7-.9Z"/></svg>Customer Reviews</div>
                <div class="product-reviews-summary" id="productReviewsSummary">No reviews yet</div>
              </div>
            </div>
            <div class="product-reviews-list" id="productReviewsList">
              <div class="reviews-empty">No reviews yet for this product.</div>
            </div>
            <button type="button" class="lc-see-more" id="productReviewsMore" hidden>See more reviews <span aria-hidden="true">↓</span></button>
          </div>
          </div></div>
        </div>
      </div>
  <div class="lc-photo-lightbox" id="lcPhotoLightbox" aria-hidden="true">
    <div class="lc-photo-backdrop" id="lcPhotoBackdrop"></div>
    <section class="lc-photo-panel" role="dialog" aria-modal="true" aria-labelledby="lcPhotoTitle">
      <button class="lc-photo-close lc-floating-close" type="button" id="lcPhotoClose" aria-label="Close full photo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
      <div class="lc-photo-stage"><img id="lcPhotoEnlarged" alt="" decoding="async"></div>
      <div class="lc-photo-footer"><span id="lcPhotoTitle">Product photo</span><span>Full image</span></div>
    </section>
  </div>
  <div class="lc-store-modal" id="lcStoreModal" aria-hidden="true">
    <div class="lc-store-backdrop"></div>
    <section class="lc-store-panel" role="dialog" aria-modal="true" aria-labelledby="lcStoreTitle">
      <button type="button" class="lc-store-close lc-floating-close" id="lcStoreClose" aria-label="Close store"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
      <header class="lc-store-header"><div class="lc-store-heading"><span class="lc-eyebrow">LOCAL ARTISAN</span><h2 id="lcStoreTitle">Store Profile</h2></div></header>
      <div class="lc-store-content" id="lcStoreScroll">
        <div class="lc-store-summary" id="lcStoreSummary"><div class="lc-loading">Loading store...</div></div>
        <button type="button" class="lc-see-more lc-store-details-toggle" id="lcStoreMore" aria-expanded="false"><span>See Business Details</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>
        <section class="lc-store-info" id="lcStoreInfo" hidden>
          <div class="lc-store-facts" id="lcStoreFacts"></div>
        </section>
        <div class="lc-store-products-heading"><h3>Shop Products</h3><span id="lcStoreProductCount"></span></div>
        <div class="lc-store-products" id="lcStoreProducts"><div class="lc-loading">Loading products...</div></div>
      </div>
    </section>
  </div>
  <div class="marketplace-toast" id="marketplaceToast" role="status" aria-live="polite"></div>
  <script>
    let mobileViewportBaseline = Math.max(
      window.innerHeight,
      document.documentElement.clientHeight
    );
    function syncAppViewport() {
      const viewport = window.visualViewport;
      const isMobile = window.matchMedia('(max-width: 760px)').matches;
      if (!isMobile) {
        document.documentElement.style.setProperty(
          '--keyboard-inset',
          '0px'
        );
        document.body.classList.remove('keyboard-open');
        return;
      }
      const input = document.getElementById('messageInput');
      const inputFocused = document.activeElement === input;
      if (!inputFocused) {
        mobileViewportBaseline = Math.max(
          window.innerHeight,
          document.documentElement.clientHeight,
          viewport
            ? viewport.height + viewport.offsetTop
            : 0
        );
      }
      const visibleHeight = viewport
        ? viewport.height
        : window.innerHeight;
      const viewportTop = viewport
        ? viewport.offsetTop
        : 0;
      /*
       * Do not move the whole page. Calculate only the part covered
       * by the software keyboard and use it as a bottom inset.
       */
      const layoutViewportHeight = Math.max(
        window.innerHeight,
        document.documentElement.clientHeight
      );
      // Inset only the area covered *inside* the layout viewport. When Chrome or
      // Edge already shrinks the layout viewport, do not apply the inset twice.
      let keyboardInset = Math.max(
        0,
        layoutViewportHeight - (visibleHeight + viewportTop)
      );
      if (!inputFocused || keyboardInset < 40) keyboardInset = 0;
      document.documentElement.style.setProperty(
        '--keyboard-inset',
        Math.round(keyboardInset) + 'px'
      );
      document.body.classList.toggle(
        'keyboard-open',
        keyboardInset > 0
      );
      if (keyboardInset > 0) {
        requestAnimationFrame(() => {
          const body = document.getElementById('chatBody');
          if (body) {
            body.scrollTop = body.scrollHeight;
          }
        });
      }
    }
    // Match Messages' top edge to the *actual* shared Buyer header height.
    // Search/notification controls can wrap on narrow mobile devices.
    function syncMessagesHeaderHeight() {
      const header = document.querySelector('.buyer-header-tools');
      if (!header) return;
      const height = Math.ceil(header.getBoundingClientRect().height);
      if (height > 0 && height < 200) {
        document.documentElement.style.setProperty('--lc-msg-header-height', height+'px');
      }
    }
    syncMessagesHeaderHeight();
    if (window.ResizeObserver) {
      const buyerHeader = document.querySelector('.buyer-header-tools');
      if (buyerHeader) new ResizeObserver(syncMessagesHeaderHeight).observe(buyerHeader);
    }
    window.addEventListener('resize', syncMessagesHeaderHeight);
    syncAppViewport();
    window.addEventListener('resize', syncAppViewport);
    window.addEventListener('orientationchange', () => {
      document.documentElement.style.setProperty(
        '--keyboard-inset',
        '0px'
      );
      document.body.classList.remove('keyboard-open');
      setTimeout(() => {
        mobileViewportBaseline = Math.max(
          window.innerHeight,
          document.documentElement.clientHeight
        );
        syncAppViewport();
      }, 350);
    });
    if (window.visualViewport) {
      window.visualViewport.addEventListener(
        'resize',
        syncAppViewport
      );
      window.visualViewport.addEventListener(
        'scroll',
        syncAppViewport
      );
    }
    const buyerId = <?php echo (int)$buyerId; ?>;
    let selectedSellerId = <?php echo (int)$selectedPartnerId; ?>;
    let selectedOrderId = <?php echo (int)$selectedOrderId; ?>;
    let manuallySelectedOrder = <?php echo (int)($_GET['order_id'] ?? 0) > 0 ? 'true' : 'false'; ?>;
    // Only Orders/other explicit order links jump to a particular order row.
    // An ordinary conversation click opens the latest chat message instead.
    const linkedOrderId = (() => {
      const raw = new URLSearchParams(window.location.search).get('order_id');
      if (!raw || !/^\d+$/.test(raw)) return 0;
      const value = Number(raw);
      return Number.isSafeInteger(value) && value > 0 && value === selectedOrderId
        ? value : 0;
    })();
    // An explicit order link is focused only if the server confirmed this buyer owns that order.
    const validLinkedOrderId = <?php echo $selectedOrder && (int)$selectedOrder['order_id'] === (int)($_GET['order_id'] ?? 0) ? (int)$selectedOrder['order_id'] : 0; ?>;
    let focusedOrderId = linkedOrderId === validLinkedOrderId ? linkedOrderId : 0;
    let showAllSellerMessages = false;
    let fullConversationMessages = [];
    let activeOrderDetails = <?php echo json_encode($selectedOrder, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
    let selectedSellerName = <?php echo json_encode($selectedSellerName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
    const messagesCsrf = <?php echo json_encode($_SESSION['buyer_messages_csrf'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    let lastMessageId = 0;
    let lastOrderRefreshAt = 0;
    let messagePoller = null;
    let typingPoller = null;
    let typingTimeout = null;
    let lastTypingState = false;
    const prefillMessage = <?php echo json_encode($prefillMessage, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
    const initialMessages = <?php echo json_encode($chatMessages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
    const initialConversations = <?php echo json_encode($conversations, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
    const messagesShell = document.getElementById('messagesShell');
    const conversationItems = document.getElementById('conversationItems');
    const storeSearchInput = document.getElementById('productSearch');
    function filterStoresInConversations() {
      const query = String(storeSearchInput?.value || '').trim().toLocaleLowerCase();
      if (!conversationItems) return;
      let found = 0;
      conversationItems.querySelectorAll('.conversation-item').forEach(item => {
        const storeName = String(item.dataset.storeName || '').toLocaleLowerCase();
        const matches = !query || storeName.includes(query);
        item.hidden = !matches;
        if (matches) found++;
      });
      const empty = document.getElementById('lcStoreSearchEmpty');
      if (empty) empty.hidden = !query || found > 0;
    }
    storeSearchInput?.addEventListener('input', filterStoresInConversations);
    document.getElementById('buyerSearchToggle')?.addEventListener('click', () => queueMicrotask(filterStoresInConversations));
    document.addEventListener('pointerdown', () => queueMicrotask(filterStoresInConversations));
    storeSearchInput?.addEventListener('keydown', event => {
      if (event.key === 'Escape') queueMicrotask(filterStoresInConversations);
    });
    const chatBody = document.getElementById('chatBody');
    const linkedOrderFilter = document.getElementById('linkedOrderFilter');
    const linkedOrderFilterLabel = document.getElementById('linkedOrderFilterLabel');
    const linkedOrderNumber = document.getElementById('linkedOrderNumber');
    const linkedOrderToggle = document.getElementById('linkedOrderToggle');
    function orderFocusActive() { return focusedOrderId > 0 && !showAllSellerMessages; }
    function syncLinkedOrderFilter() {
      if (!linkedOrderFilter) return;
      linkedOrderFilter.hidden = focusedOrderId <= 0;
      if (linkedOrderNumber) linkedOrderNumber.textContent = 'Order #' + focusedOrderId;
      if (linkedOrderFilterLabel) linkedOrderFilterLabel.hidden = showAllSellerMessages;
      if (linkedOrderToggle) {
        linkedOrderToggle.textContent = showAllSellerMessages ? 'Focus on Order #' + focusedOrderId : 'View all messages';
        linkedOrderToggle.setAttribute('aria-label', linkedOrderToggle.textContent);
      }
    }
    linkedOrderToggle?.addEventListener('click', () => {
      showAllSellerMessages = !showAllSellerMessages;
      syncLinkedOrderFilter();
      renderMessages(fullConversationMessages, true, orderFocusActive() ? focusedOrderId : 0);
      const activeConversation = getCachedConversation(selectedSellerId);
      if (activeConversation) updateHeader(activeConversation);
      if (!orderFocusActive()) markConversationRead(selectedSellerId);
    });
    const chatName = document.getElementById('chatName');
    const chatAvatar = document.getElementById('chatAvatar');
    const chatProduct = document.getElementById('chatProduct');
    const typingIndicator = document.getElementById('typingIndicator');
    const chatEmpty = document.getElementById('chatEmpty');
    const chatError = document.getElementById('chatError');
    const chatOrderClosed = document.getElementById('chatOrderClosed');
    const chatClosedTitle = document.getElementById('chatClosedTitle');
    const chatClosedMessage = document.getElementById('chatClosedMessage');
    const orderSummary = document.getElementById('orderSummary');
    const orderSummaryId = document.getElementById('orderSummaryId');
    const orderSummaryItems = document.getElementById('orderSummaryItems');
    const orderSummaryTotal = document.getElementById('orderSummaryTotal');
    const orderSummaryDelivery = document.getElementById('orderSummaryDelivery');
    const orderSummaryPayment = document.getElementById('orderSummaryPayment');
    const orderSummaryStatus = document.getElementById('orderSummaryStatus');
    const buyerOrderProgress = document.getElementById('buyerOrderProgress');
    const buyerLeaveReview = document.getElementById('buyerLeaveReview');
    const reviewDialog = document.getElementById('buyerProductReviewDialog');
    const reviewForm = document.getElementById('buyerProductReviewForm');
    const reviewClose = document.getElementById('buyerProductReviewClose');
    const reviewCancel = document.getElementById('buyerReviewCancel');
    const reviewSelect = document.getElementById('buyerReviewProductSelect');
    const reviewStars = document.getElementById('buyerReviewStars');
    const reviewComment = document.getElementById('buyerReviewComment');
    const reviewFeedback = document.getElementById('buyerReviewFeedback');
    const reviewSubmit = document.getElementById('buyerReviewSubmit');
    const reviewsEnabled = <?php echo buyerChatReviewsAvailable($conn) ? 'true' : 'false'; ?>;
    let reviewRating = 0;
    let reviewBusy = false;
    let reviewOpenGeneration = 0;
    const messageForm = document.getElementById('messageForm');
    const messageInput = document.getElementById('messageInput');
    const sendButton = document.getElementById('sendButton');
    const attachmentInput = document.getElementById('attachmentInput');
    const attachmentPreview = document.getElementById('attachmentPreview');
    const attachmentPreviewName = document.getElementById('attachmentPreviewName');
    const attachmentRemove = document.getElementById('attachmentRemove');
    const chatBack = document.getElementById('chatBack');
    const reviewArrangementButton = document.getElementById('reviewArrangementButton');
    const buyerPayNowButton = document.getElementById('buyerPayNowButton');
    const buyerQrDialog = document.getElementById('buyerQrDialog');
    const buyerQrClose = document.getElementById('buyerQrClose');
    const buyerQrFeedback = document.getElementById('buyerQrFeedback');
    const buyerQrOrderLabel = document.getElementById('buyerQrOrderLabel');
    const buyerQrAmount = document.getElementById('buyerQrAmount');
    const buyerQrLoading = document.getElementById('buyerQrLoading');
    const buyerQrImage = document.getElementById('buyerQrImage');
    const buyerQrTimer = document.getElementById('buyerQrTimer');
    const buyerQrSimulate = document.getElementById('buyerQrSimulate');
    const buyerQrRegenerate = document.getElementById('buyerQrRegenerate');
    const buyerArrangementDialog = document.getElementById('buyerArrangementDialog');
    const buyerArrangementClose = document.getElementById('buyerArrangementClose');
    const buyerArrangementNotYet = document.getElementById('buyerArrangementNotYet');
    const buyerArrangementFeedback = document.getElementById('buyerArrangementFeedback');
    const buyerConfirmArrangement = document.getElementById('buyerConfirmArrangement');
    const buyerArrangementReview = document.getElementById('buyerArrangementReview');
    const buyerPayNowDialogButton = document.getElementById('buyerPayNowDialogButton');
    const buyerArrangementProduct = document.getElementById('buyerArrangementProduct');
    const buyerArrangementOrderNumber = document.getElementById('buyerArrangementOrderNumber');
    const buyerArrangementStatus = document.getElementById('buyerArrangementStatus');
    const buyerArrangementItems = document.getElementById('buyerArrangementItems');
    const buyerArrangementFinalTotal = document.getElementById('buyerArrangementFinalTotal');
    const buyerArrangementFulfillment = document.getElementById('buyerArrangementFulfillment');
    const buyerArrangementPayment = document.getElementById('buyerArrangementPayment');
    const buyerArrangementPaymentStatus = document.getElementById('buyerArrangementPaymentStatus');
    const buyerArrangementLocation = document.getElementById('buyerArrangementLocation');
    const buyerArrangementDate = document.getElementById('buyerArrangementDate');
    const buyerArrangementTime = document.getElementById('buyerArrangementTime');
    const buyerArrangementNotes = document.getElementById('buyerArrangementNotes');
    // Keep the active seller conversation visible when browser Back closes an
    // Order Details or QR popup. A popup gets its own same-URL history entry.
    // Do not change the seller_id/order_id URL or reload the chat page.
    const buyerChatDialogHistoryId = 'lc-buyer-chat-' + Date.now()
      + '-' + Math.random().toString(36).slice(2);
    const buyerChatDialogFrames = [];
    let buyerChatDialogSequence = 0;
    function buyerChatDialogState(state) {
      const entry = state && typeof state === 'object'
        ? state.lcBuyerChatDialog : null;
      return entry && entry.owner === buyerChatDialogHistoryId ? entry : null;
    }
    function openBuyerChatDialog(dialog, kind) {
      if (!dialog || dialog.open || typeof dialog.showModal !== 'function') return;
      dialog.showModal();
      try {
        const current = window.history.state;
        const existing = current && typeof current === 'object' ? current : {};
        const ticket = ++buyerChatDialogSequence;
        window.history.pushState({
          ...existing,
          lcBuyerChatDialog: { owner: buyerChatDialogHistoryId, ticket, kind }
        }, '', window.location.href);
        buyerChatDialogFrames.push({ dialog, ticket });
      } catch (_) {
        // The dialog is still usable if the browser blocks History API changes.
      }
    }
    window.addEventListener('popstate', (event) => {
      const current = buyerChatDialogState(event.state);
      // Back/forward removes only the topmost popup layer, not the conversation.
      while (buyerChatDialogFrames.length) {
        const top = buyerChatDialogFrames[buyerChatDialogFrames.length - 1];
        if (current && top.ticket === current.ticket) break;
        buyerChatDialogFrames.pop();
        if (top.dialog.open) top.dialog.close();
      }
    });
    for (const dialog of [buyerArrangementDialog, buyerQrDialog]) {
      if (!dialog) continue;
      dialog.addEventListener('cancel', (event) => {
        const top = buyerChatDialogFrames[buyerChatDialogFrames.length - 1];
        const state = buyerChatDialogState(window.history.state);
        if (top?.dialog === dialog && state?.ticket === top.ticket) {
          event.preventDefault();
          window.history.back();
        }
      });
      dialog.addEventListener('close', () => {
        const index = buyerChatDialogFrames.findIndex(frame => frame.dialog === dialog);
        if (index < 0) return; // Already closed by browser Back.
        const [closed] = buyerChatDialogFrames.splice(index, 1);
        const state = buyerChatDialogState(window.history.state);
        // X/Close/action buttons must consume their popup entry as well.
        if (state?.ticket === closed.ticket) window.history.back();
      });
    }
    function showChatError(message) {
      if (!chatError) return;
      chatError.textContent = message;
      chatError.classList.toggle('show', Boolean(message));
    }
    function clearChatError() {
      showChatError('');
    }
    function setMobileChatOpen(open) {
      if (!messagesShell) return;
      if (window.matchMedia('(max-width: 760px)').matches) {
        messagesShell.classList.toggle('chat-open', open);
        document.body.classList.toggle('chat-open', open);
      }
    }
    function scrollToBottom(force = false) {
      if (!chatBody) return;
      const distance = chatBody.scrollHeight - chatBody.scrollTop - chatBody.clientHeight;
      if (force || distance < 180) chatBody.scrollTop = chatBody.scrollHeight;
    }
    function jumpToOrderMessage(orderId) {
      if (!chatBody || !Number.isSafeInteger(Number(orderId)) || Number(orderId) <= 0) return false;
      const rows = [...chatBody.querySelectorAll('.message-row[data-order-id]')]
        .filter(row => Number(row.dataset.orderId) === Number(orderId));
      if (!rows.length) return false;
      // Same behavior as Seller Messages: target the latest order event card.
      // If that order has no event card, use its most recent linked message.
      const target = [...rows].reverse().find(row => row.querySelector('.order-chat-card'))
        || rows[rows.length - 1];
      chatBody.querySelectorAll('.message-row.focused-order')
        .forEach(row => row.classList.remove('focused-order'));
      target.classList.add('focused-order');
      const destination = chatBody.scrollTop + target.getBoundingClientRect().top
        - chatBody.getBoundingClientRect().top - 14;
      chatBody.scrollTop = Math.max(0, destination);
      return true;
    }
    function showLatestMessage(orderId = 0) {
      if (orderFocusActive()) {
        if (!jumpToOrderMessage(focusedOrderId)) scrollToBottom(true);
        return;
      }
      if (!jumpToOrderMessage(orderId)) scrollToBottom(true);
    }
    function clearChatBody() {
      if (!chatBody) return;
      chatBody.innerHTML = '';
    }
    function createElement(tag, className, text = '') {
      const element = document.createElement(tag);
      if (className) {
        element.className = className;
      }
      if (text !== '') {
        element.textContent = text;
      }
      return element;
    }
    function attachmentIcon() {
      const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      icon.setAttribute('viewBox', '0 0 24 24');
      icon.setAttribute('fill', 'none');
      icon.setAttribute('stroke', 'currentColor');
      icon.setAttribute('stroke-width', '1.8');
      icon.setAttribute('stroke-linecap', 'round');
      icon.setAttribute('stroke-linejoin', 'round');
      icon.setAttribute('aria-hidden', 'true');
      const outline = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      outline.setAttribute('d', 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z M14 2v6h6 M8 13h8 M8 17h6');
      icon.appendChild(outline);
      return icon;
    }
    function renderAttachment(parent, attachment) {
      if (!attachment) return;
      let safeUrl;
      try {
        safeUrl = new URL(String(attachment), document.baseURI);
        if (!['http:', 'https:'].includes(safeUrl.protocol)
            || safeUrl.origin !== window.location.origin) return;
      } catch (_) { return; }
      const link = document.createElement('a');
      link.className = 'attachment';
      link.href = safeUrl.href;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      const filename = safeUrl.pathname.split('/').pop() || 'Attached file';
      const extension = filename.split('.').pop()?.toLowerCase() || '';
      if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(extension)) {
        const image = document.createElement('img');
        image.src = safeUrl.href;
        image.alt = 'Attached image';
        image.loading = 'lazy';
        image.addEventListener('load', () => scrollToBottom(true), { once: true });
        link.appendChild(image);
      } else {
        const file = createElement('div', 'attachment-file');
        file.appendChild(attachmentIcon());
        file.appendChild(createElement('span', 'attachment-file-name', filename));
        link.appendChild(file);
      }
      parent.appendChild(link);
    }
    // Linkify only HTTP(S) URLs. All other message text remains text nodes (never HTML).
    function renderChatText(messageText) {
      const node = createElement('div', 'lc-chat-message-text');
      const content = String(messageText || '');
      const pattern = /(?:https?:\/\/|www\.)[^\s<>"'`]+/gi;
      let start = 0;
      for (const match of content.matchAll(pattern)) {
        const raw = match[0];
        const visible = raw.replace(/[.,!?;:\])}]+$/g, '');
        if (!visible) continue;
        const index = match.index ?? start;
        if (index > start) node.appendChild(document.createTextNode(content.slice(start, index)));
        let parsed = null;
        try {
          parsed = new URL(/^www\./i.test(visible) ? 'https://' + visible : visible);
        } catch (_) { /* Keep invalid links as plain text. */ }
        if (parsed && ['http:', 'https:'].includes(parsed.protocol) && parsed.hostname) {
          const anchor = document.createElement('a');
          anchor.className = 'lc-chat-link';
          anchor.textContent = visible;
          anchor.href = parsed.href;
          anchor.target = '_blank';
          anchor.rel = 'noopener noreferrer nofollow ugc';
          node.appendChild(anchor);
        } else {
          node.appendChild(document.createTextNode(visible));
        }
        node.appendChild(document.createTextNode(raw.slice(visible.length)));
        start = index + raw.length;
      }
      if (start < content.length) node.appendChild(document.createTextNode(content.slice(start)));
      return node;
    }
    function orderChatEvent(message) {
      if (!Number(message?.order_id)) return '';
      const body = String(message.message_text || '');
      const fromSeller = Number(message.sender_id) === Number(selectedSellerId);
      const fromBuyer = !fromSeller;
      if (fromBuyer && /^Buyer requested changes to Order #\d+\./.test(body)) return 'changes';
      if (fromBuyer && message.is_order_opening) return 'request';
      if (fromSeller && /^Order #\d+ .*details are ready\./.test(body)) return 'details';
      if (fromBuyer && /^Buyer confirmed the order details for Order #\d+\./.test(body)) return 'confirmed';
      if (fromSeller && /^Order #\d+ was accepted by the seller\./.test(body)) return 'accepted';
      if (fromSeller && /started processing Order #\d+/i.test(body)) return 'processing';
      if (fromSeller && /Order #\d+ was marked Completed/i.test(body)) return 'completed';
      if (fromBuyer && /^Payment for Order #\d+ was confirmed/.test(body)) return 'paid';
      if (fromBuyer && /^Order #\d+ cancelled by the buyer\./i.test(body)) return 'cancelled';
      if (fromSeller && /(?:Order #\d+.*(?:rejected|declined)|(?:rejected|declined).*Order #\d+)/i.test(body)) return 'rejected';
      return '';
    }
    function orderPhotoUrl(value) {
      const path = String(value || '').replace(/\\/g, '/');
      return /^uploads\/products\/[a-zA-Z0-9._-]+\.(?:jpg|jpeg|png|webp)$/i.test(path)
        ? '../' + path : '';
    }
    function orderCardPhoto(path, cls = 'order-chat-photo') {
      const url = orderPhotoUrl(path);
      if (!url) return createOrderVisualPlaceholder(cls);
      const image = document.createElement('img');
      image.className = cls;
      image.src = url;
      image.alt = 'Product photo';
      image.loading = 'lazy';
      image.addEventListener('error', () => image.replaceWith(createOrderVisualPlaceholder(cls)), {once:true});
      return image;
    }
    function createOrderVisualPlaceholder(cls) {
      const node = document.createElement('span');
      node.className = cls + ' blank';
      node.textContent = '▦';
      return node;
    }
    function buyerReviewPending(order) {
      if (!order) return false;
      const status = String(order.order_status || '').trim().toLowerCase();
      const stage = String(order.arrangement_status ?? order.order_arrangement_status ?? '').trim().toLowerCase();
      return status === 'pending'
        && (order.next_action === 'buyer_review' || stage === 'awaiting_confirmation');
    }
    function orderCardLabel(kind, isSeller, message) {
      const orderStatus = String(message.order_status || '').toLowerCase();
      const stage = String(message.order_arrangement_status || '').toLowerCase();
      if (isSeller) {
        if (orderStatus === 'pending' && stage === 'awaiting_seller_acceptance') return 'Review & Accept';
        if (orderStatus === 'pending' && stage === 'awaiting_confirmation') return 'View Sent Details';
        if (orderStatus === 'pending' && stage === 'arranging') return 'Set Order Details';
        return 'View Order Details';
      }
      if (orderTerminal(orderStatus)) return 'View Closed Order';
      // The seller has sent the proposal: show the buyer's next step even
      // if an individual field still needs correction before confirmation.
      return buyerReviewPending(message)
        ? 'Review & Confirm' : 'View Order Details';
    }
    function orderEventLabel(kind) {
      return ({request:'Order Requested',details:'Details Sent',changes:'Change Requested',
        confirmed:'Details Confirmed',accepted:'Order Accepted',processing:'Processing',completed:'Completed',paid:'Payment Confirmed',
        cancelled:'Order Cancelled',rejected:'Order Rejected'})[kind] || 'Order';
    }
    function orderTerminal(status) {
      return ['cancelled','canceled','rejected','declined','expired','completed']
        .includes(String(status || '').toLowerCase());
    }
    function orderTerminalLabel(status) {
      const key = String(status || '').toLowerCase();
      return ({canceled:'Cancelled',cancelled:'Cancelled',rejected:'Rejected',
        declined:'Rejected',expired:'Expired',completed:'Completed'})[key] || 'Closed';
    }
    // One view-details action per order, attached to that order’s newest event card.
    function latestOrderEventMessages(messages) {
      const latest = new Map();
      messages.forEach((message) => {
        if (orderChatEvent(message)) {
          const orderId = Number(message.order_id);
          const messageId = Number(message.message_id) || 0;
          if (messageId > (latest.get(orderId) || 0)) latest.set(orderId, messageId);
        }
      });
      return latest;
    }
    function retireEarlierOrderAction(orderId) {
      if (!chatBody || !orderId) return;
      chatBody.querySelectorAll('.order-chat-action[data-order-id="' + orderId + '"]').forEach((button) => {
        const card = button.closest('.order-chat-card');
        if (card) card.classList.add('is-history');
        button.remove();
      });
    }
    function renderOrderCard(parent, message, isLatest = true) {
      const kind = orderChatEvent(message);
      if (!kind) return false;
      const orderId = Number(message.order_id);
      const card = createElement('div', 'message-order-card order-chat-card');
      const closed = orderTerminal(message.order_status);
      if (closed) card.classList.add('is-closed-order');
      card.appendChild(orderCardPhoto(message.order_photo));
      const info = createElement('div', 'order-chat-info');
      info.appendChild(createElement('div', 'order-chat-kicker', 'Order #' + orderId + ' · ' + orderEventLabel(kind)));
      info.appendChild(createElement('div', 'order-chat-name', message.order_product || 'Order items'));
      info.appendChild(createElement('div', 'order-chat-meta',
        'Qty ' + (Number(message.order_quantity) || 1) + ' · ' + money(message.order_total)));
      if (closed) {
        info.appendChild(createElement('span', 'order-chat-status is-closed', orderTerminalLabel(message.order_status)));
      }
      if (kind === 'cancelled') {
        const reason = String(message.message_text || '').split('\n')
          .filter(line => /^(Reason|Details):/i.test(line.trim()))
          .map(line => line.trim()).join(' · ');
        if (reason) info.appendChild(createElement('div', 'order-chat-reason', reason));
      }
      if (kind === 'rejected') {
        // Seller Orders writes: Seller rejected Order #123. Reason: ...
        // Order-event text is intentionally hidden, so expose the reason here.
        const match = String(message.message_text || '').match(/\bReason:\s*([\s\S]*)$/i);
        if (match && match[1].trim()) {
          info.appendChild(createElement('div', 'order-chat-reason', 'Reason: ' + match[1].trim()));
        }
      }
      if (isLatest) {
        const button = createElement('button', 'order-chat-action', orderCardLabel(kind, false, message));
        button.type = 'button';
        button.dataset.orderId = String(orderId);
        button.setAttribute('aria-label', orderCardLabel(kind, false, message) + ', Order #' + orderId);
        info.appendChild(button);
      } else {
        card.classList.add('is-history');
      }
      card.appendChild(info);
      parent.appendChild(card);
      return true;
    }
    function renderMessages(messages, forceScroll = true, focusOrderId = 0) {
      if (!chatBody) return;
      // Keep every message in memory for View all, and advance the polling cursor
      // past unrelated seller messages even while displaying just one order.
      fullConversationMessages = Array.isArray(messages) ? messages.slice() : [];
      clearChatBody();
      lastMessageId = fullConversationMessages.reduce((max, message) =>
        Math.max(max, Number(message.message_id) || 0), 0);
      const visibleMessages = orderFocusActive()
        ? fullConversationMessages.filter(message => Number(message.order_id) === focusedOrderId)
        : fullConversationMessages;
      if (!visibleMessages.length) {
        const empty = createElement('div', 'chat-empty', orderFocusActive()
          ? 'No messages are linked to this order yet. You can view all seller messages for more context.'
          : 'Start the conversation with this seller.');
        chatBody.appendChild(empty);
        return;
      }
      let previousDate = '';
      const latestOrderEvents = latestOrderEventMessages(visibleMessages);
      visibleMessages.forEach((message) => {
        const currentDate = message.date_label || '';
        if (currentDate && currentDate !== previousDate) {
          chatBody.appendChild(
            createElement('div', 'date-separator', currentDate)
          );
          previousDate = currentDate;
        }
        appendMessage(message, false,
          !orderChatEvent(message) || latestOrderEvents.get(Number(message.order_id)) === Number(message.message_id));
      });
      if (forceScroll) showLatestMessage(focusOrderId);
    }
    function appendMessage(message, allowScroll = false, isLatest = true) {
      if (!chatBody || !message) return;
      const outgoing = Number(message.sender_id) === buyerId;
      if (!chatBody.querySelector('.date-separator:last-of-type') ||
          chatBody.querySelector('.date-separator:last-of-type')?.textContent !== (message.date_label || '')) {
        const separators = chatBody.querySelectorAll('.date-separator');
        const lastSeparator = separators[separators.length - 1];
        if (!lastSeparator || lastSeparator.textContent !== (message.date_label || '')) {
          chatBody.appendChild(
            createElement('div', 'date-separator', message.date_label || '')
          );
        }
      }
      const row = createElement(
        'div',
        'message-row ' + (outgoing ? 'outgoing' : 'incoming')
      );
      row.dataset.messageId = String(message.message_id);
      if (Number(message.order_id) > 0) row.dataset.orderId = String(message.order_id);
      const content = createElement('div', 'message-content');
      const bubble = createElement('div', 'bubble');
      renderAttachment(bubble, message.attachment);
      const orderEvent = orderChatEvent(message);
      if (Number(message.order_id) > 0 && !orderEvent) {
        bubble.appendChild(createElement('span', 'lc-chat-order-tag', 'Order #' + Number(message.order_id)));
      }
      if (message.message_text && !orderEvent) {
        bubble.appendChild(renderChatText(message.message_text));
      }
      if (isLatest && orderEvent) retireEarlierOrderAction(Number(message.order_id));
      renderOrderCard(bubble, message, isLatest);
      const meta = createElement('div', 'message-time', message.time || '');
      if (outgoing) {
        const status = createElement(
          'span',
          'message-status',
          Number(message.is_read) === 1 ? '✓✓' : '✓'
        );
        meta.appendChild(status);
      }
      bubble.appendChild(meta);
      content.appendChild(bubble);
      row.appendChild(content);
      chatBody.appendChild(row);
      lastMessageId = Math.max(lastMessageId, Number(message.message_id) || 0);
      if (allowScroll) {
        scrollToBottom(true);
      }
    }
    function updateHeader(conversation) {
      if (!conversation || !chatName) return;
      selectedSellerName = conversation.name || conversation.person_name || 'Seller';
      chatName.textContent = selectedSellerName;
      chatName.closest('.chat-header')?.setAttribute('data-seller-id', String(Number(conversation.partner_id)||0));
      if (chatAvatar) {
        chatAvatar.textContent = conversation.initials || 'S';
      }
      if (chatProduct) {
        chatProduct.textContent = orderFocusActive()
          ? 'Messages for Order #' + focusedOrderId
          : (conversation.product ? 'Product: ' + conversation.product
            : (conversation.location || 'Marketplace conversation'));
      }
    }
    function money(value) {
      return '₱' + Number(value || 0).toLocaleString(
        'en-PH',
        {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2
        }
      );
    }
    function niceArrangementValue(value) {
      const key = String(value || '')
        .trim()
        .toLowerCase();
      const labels = {
        arranging: 'Setting Details',
        awaiting_confirmation: 'Ready to Confirm',
        awaiting_seller_acceptance: 'Waiting for Seller',
        accepted: 'Accepted',
        completed: 'Completed',
        cancellation_requested: 'Cancel Requested',
        cancelled: 'Cancelled',
        canceled: 'Cancelled',
        buyer_no_show: 'Buyer Did Not Show',
        seller_no_show: 'Seller Did Not Show',
        disputed: 'Issue Reported',
        expired: 'Expired'
      };
      if (!key) return 'Not set';
      if (labels[key]) return labels[key];
      const clean = key
        .replaceAll('_', ' ')
        .replaceAll('-', ' ');
      return clean.replace(
        /\b\w/g,
        (letter) => letter.toUpperCase()
      );
    }
    function formatArrangementDate(value) {
      if (!value) return 'Not set';
      const date = new Date(value + 'T00:00:00');
      if (Number.isNaN(date.getTime())) {
        return value;
      }
      return date.toLocaleDateString(
        'en-PH',
        {
          year: 'numeric',
          month: 'short',
          day: 'numeric'
        }
      );
    }
    function formatArrangementTime(value) {
      if (!value) return 'Not set';
      const parts = String(value).slice(0, 5).split(':');
      if (parts.length !== 2) {
        return value;
      }
      const date = new Date();
      date.setHours(
        Number(parts[0]),
        Number(parts[1]),
        0,
        0
      );
      return date.toLocaleTimeString(
        'en-PH',
        {
          hour: 'numeric',
          minute: '2-digit'
        }
      );
    }
    function setBuyerArrangementFeedback(
      message = '',
      type = ''
    ) {
      if (!buyerArrangementFeedback) return;
      buyerArrangementFeedback.textContent = message;
      buyerArrangementFeedback.className =
        'buyer-arrangement-feedback';
      if (message) {
        buyerArrangementFeedback.classList.add('show');
        if (type) {
          buyerArrangementFeedback.classList.add(type);
        }
      }
    }
    function deliveryOptionLabel(value) {
      const key = String(value || '')
        .trim()
        .toLowerCase();
      const labels = {
        meetup: 'Meetup',
        pickup: 'Pickup',
        third_party_delivery: 'Third-Party Delivery',
        other: 'Other'
      };
      return labels[key] || niceArrangementValue(value);
    }
    function paymentOptionLabel(value) {
      const key = String(value || '')
        .trim()
        .toLowerCase();
      if (key === 'paymongo') {
        return 'QR Ph';
      }
      if (key === 'cash') {
        return 'Cash';
      }
      return niceArrangementValue(value);
    }
    function paymentStatusLabel(value) {
      const key = String(value || '')
        .trim()
        .toLowerCase();
      if (key === 'paid') return 'Paid';
      if (key === 'pending') return 'Waiting for Payment';
      if (key === 'failed') return 'Payment Failed';
      if (key === 'refunded') return 'Refunded';
      if (
        key === 'cancelled'
        || key === 'canceled'
      ) {
        return 'Payment Cancelled';
      }
      return key
        ? niceArrangementValue(value)
        : 'Not Paid';
    }
    function paymentIsPaid(order) {
      return Boolean(order)
        && String(
          order.payment_status || ''
        ).toLowerCase() === 'paid';
    }
    function canPayWithPayMongo(order) {
      if (!order) return false;
      return String(
        order.order_status || ''
      ).toLowerCase() === 'accepted'
        && String(
          order.arrangement_status || ''
        ).toLowerCase() === 'accepted'
        && String(
          order.payment_option || ''
        ).toLowerCase() === 'paymongo'
        && !['paid', 'refunded', 'cancelled', 'canceled'].includes(
          String(order.payment_status || '').toLowerCase()
        );
    }
    let activeQrSession = null;
    let qrCountdownTimer = null;
    let qrPaymentPoller = null;
    let qrCheckInProgress = false;
    let paymongoTestWindow = null;
    function setQrFeedback(
      message = '',
      type = ''
    ) {
      if (!buyerQrFeedback) return;
      buyerQrFeedback.textContent = message;
      buyerQrFeedback.className =
        'buyer-qr-feedback';
      if (!message) {
        return;
      }
      buyerQrFeedback.classList.add('show');
      if (type) {
        buyerQrFeedback.classList.add(type);
      }
    }
    function clearQrTimers() {
      if (qrCountdownTimer) {
        clearInterval(qrCountdownTimer);
        qrCountdownTimer = null;
      }
      if (qrPaymentPoller) {
        clearInterval(qrPaymentPoller);
        qrPaymentPoller = null;
      }
    }
    function closePayMongoTestWindow() {
      if (
        paymongoTestWindow
        && !paymongoTestWindow.closed
      ) {
        try {
          paymongoTestWindow.close();
        } catch (error) {
        }
      }
      paymongoTestWindow = null;
      try {
        window.focus();
      } catch (error) {
      }
    }
    function openPayMongoTestWindow(testUrl) {
      if (
        paymongoTestWindow
        && !paymongoTestWindow.closed
      ) {
        try {
          paymongoTestWindow.close();
        } catch (error) {
        }
      }
      const paymentWindow = window.open(
        '',
        'lokalcraftPayMongoTest',
        'popup=yes,width=520,height=760,resizable=yes,scrollbars=yes'
      );
      if (!paymentWindow) {
        return false;
      }
      try {
        paymentWindow.opener = null;
      } catch (error) {
      }
      paymongoTestWindow = paymentWindow;
      try {
        paymentWindow.location.replace(testUrl);
      } catch (error) {
        try {
          paymentWindow.location.href = testUrl;
        } catch (locationError) {
          closePayMongoTestWindow();
          return false;
        }
      }
      try {
        paymentWindow.focus();
      } catch (error) {
      }
      return true;
    }
    function qrSessionIsExpired(qr) {
      if (!qr) return true;
      const expiresAt = Number(
        qr.expires_at_unix || 0
      );
      return !expiresAt
        || Math.floor(Date.now() / 1000)
          >= expiresAt;
    }
    function updateQrCountdown() {
      if (!activeQrSession || !buyerQrTimer) {
        return;
      }
      const expiresAt = Number(
        activeQrSession.expires_at_unix || 0
      );
      const remaining = Math.max(
        0,
        expiresAt
          - Math.floor(Date.now() / 1000)
      );
      const minutes = Math.floor(
        remaining / 60
      );
      const seconds = remaining % 60;
      buyerQrTimer.textContent =
        String(minutes).padStart(2, '0')
        + ':'
        + String(seconds).padStart(2, '0');
      if (remaining <= 0) {
        if (qrCountdownTimer) {
          clearInterval(qrCountdownTimer);
          qrCountdownTimer = null;
        }
        if (buyerQrRegenerate) {
          buyerQrRegenerate.hidden = false;
          buyerQrRegenerate.disabled = false;
        }
        setQrFeedback(
          'QR expired. Generate a new QR.',
          'error'
        );
      }
    }
    function renderQrSession(qr) {
      activeQrSession = qr || null;
      const qrStatus = String(qr?.qr_status || '').toLowerCase();
      const unusableQr = ['failed', 'expired', 'cancelled', 'paid'].includes(qrStatus);
      if (buyerQrSimulate) {
        const testUrl = !unusableQr ? String(qr?.test_url || '').trim() : '';
        buyerQrSimulate.hidden = !testUrl;
        buyerQrSimulate.disabled = !testUrl;
      }
      if (!qr) {
        if (buyerQrImage) {
          buyerQrImage.hidden = true;
          buyerQrImage.removeAttribute('src');
        }
        if (buyerQrLoading) {
          buyerQrLoading.hidden = false;
          buyerQrLoading.textContent =
            'Generate a QR to continue.';
        }
        if (buyerQrTimer) {
          buyerQrTimer.textContent = '15:00';
        }
        if (buyerQrRegenerate) {
          buyerQrRegenerate.hidden = false;
        }
        return;
      }
      const visibleQrImage = !unusableQr ? String(qr.qr_image || '') : '';
      if (buyerQrImage) {
        buyerQrImage.hidden = !visibleQrImage;
        if (visibleQrImage) buyerQrImage.src = visibleQrImage;
        else buyerQrImage.removeAttribute('src');
      }
      if (buyerQrLoading) {
        buyerQrLoading.hidden = Boolean(visibleQrImage);
        if (unusableQr) {
          buyerQrLoading.textContent = qrStatus === 'failed'
            ? 'Payment failed. Generate a new QR.'
            : 'This QR is no longer usable.';
        }
      }
      const expired = qrSessionIsExpired(qr) || unusableQr;
      if (buyerQrRegenerate) {
        buyerQrRegenerate.hidden = !expired;
        buyerQrRegenerate.disabled = false;
      }
      clearQrTimers();
      updateQrCountdown();
      if (!expired) {
        qrCountdownTimer = setInterval(
          updateQrCountdown,
          1000
        );
        /*
         * While the QR dialog is open, check PayMongo every
         * 4 seconds. This lets local development work even
         * before the hosted webhook is connected.
         */
        qrPaymentPoller = setInterval(
          () => {
            if (buyerQrDialog?.open) {
              checkActiveQrPayment();
            }
          },
          4000
        );
      }
    }
    function openQrDialog() {
      if (!buyerQrDialog) return;
      if (buyerQrOrderLabel) {
        buyerQrOrderLabel.textContent =
          'Order #' + selectedOrderId;
      }
      if (buyerQrAmount) {
        buyerQrAmount.textContent =
          money(
            Number(
              activeOrderDetails?.final_total || 0
            )
          );
      }
      if (!buyerQrDialog.open) {
        openBuyerChatDialog(buyerQrDialog, 'qr');
      }
    }
    function localOrderDateString(date = new Date()) {
      const year = date.getFullYear();
      const month = String(date.getMonth() + 1).padStart(2, '0');
      const day = String(date.getDate()).padStart(2, '0');
      return `${year}-${month}-${day}`;
    }
    function localOrderTimeString(date = new Date()) {
      const hour = String(date.getHours()).padStart(2, '0');
      const minute = String(date.getMinutes()).padStart(2, '0');
      return `${hour}:${minute}`;
    }
    function arrangementDateTimeIsPast(order) {
      if (!order) return false;
      const date = String(order.arrangement_date || '').slice(0, 10);
      const time = String(order.arrangement_time || '').slice(0, 5);
      if (!date || !time) return false;
      const now = new Date();
      const today = localOrderDateString(now);
      if (date < today) return true;
      if (date > today) return false;
      return time < localOrderTimeString(now);
    }
    function arrangementCanBeConfirmed(order) {
      if (!order) return false;
      const fixedPriceOrder =
        String(order.price_mode || '').toLowerCase() === 'fixed';
      const priceReady = fixedPriceOrder
        ? Number(order.final_total || 0) > 0
        : Number(order.agreed_unit_price || 0) > 0;
      return String(order.order_status || '').toLowerCase() === 'pending'
        && String(
          order.arrangement_status || ''
        ).toLowerCase() === 'awaiting_confirmation'
        && priceReady
        && ['meetup', 'pickup', 'third_party_delivery'].includes(
          String(order.fulfillment_method || '').toLowerCase()
        )
        && ['cash', 'paymongo'].includes(
          String(order.payment_option || '').toLowerCase()
        )
        && (
          String(order.payment_option || '').toLowerCase() !== 'cash'
          || ['meetup', 'pickup'].includes(
            String(order.fulfillment_method || '').toLowerCase()
          )
        )
        && Boolean(order.arrangement_location)
        && Boolean(order.arrangement_date)
        && Boolean(order.arrangement_time)
        && !arrangementDateTimeIsPast(order);
    }
    function renderBuyerArrangementItems(order) {
      if (!buyerArrangementItems) return;
      buyerArrangementItems.replaceChildren();
      const items = Array.isArray(order?.items) ? order.items : [];
      items.forEach((item) => {
        const qty = Math.max(1, Number(item.quantity) || 1);
        const detail = item.price_breakdown || {};
        const addOns = Array.isArray(detail.add_ons) ? detail.add_ons : [];
        const row = document.createElement('article');
        row.className = 'buyer-arrangement-item lc-cart-order-item';
        row.appendChild(orderCardPhoto(item.product_photo, 'order-modal-photo'));
        const main = document.createElement('div');
        main.className = 'lc-order-line-main';
        main.appendChild(createElement('div', 'buyer-arrangement-item-name', item.product_name || 'Product'));
        main.appendChild(createElement('div', 'buyer-arrangement-item-meta', 'Qty: ' + qty));
        const breakdown = createElement('div', 'lc-price-breakdown');
        const moneyRow = (label, amount, kind = '') => {
          const line = createElement('div', 'lc-price-line ' + kind);
          line.append(createElement('span', '', label), createElement('strong', '', money(amount)));
          breakdown.append(line);
        };
        moneyRow(addOns.length ? 'Base × ' + qty : 'Price × ' + qty,
          addOns.length ? Number(detail.base_subtotal || 0) : Number(item.subtotal || 0));
        addOns.forEach(opt => {
          moneyRow(opt.name + ' × ' + qty, Number(opt.price_per_unit || 0) * qty, 'lc-price-addon');
          if (opt.details) breakdown.append(createElement('div', 'lc-custom-text', opt.details));
        });
        moneyRow('Subtotal', Number(item.subtotal || 0), 'lc-price-total');
        main.append(breakdown);
        row.append(main);
        buyerArrangementItems.append(row);
      });
      buyerArrangementItems.hidden = items.length === 0;
    }
    function populateBuyerArrangement(order) {
      if (!order) return;
      renderBuyerArrangementItems(order);
      buyerArrangementProduct.textContent = order.product_name || 'Product';
      buyerArrangementOrderNumber.textContent = 'Order #' + order.order_id;
      buyerArrangementStatus.textContent = displayOrderStage(order);
      buyerArrangementFinalTotal.textContent = money(Number(order.final_total || 0));
      buyerArrangementFulfillment.textContent = deliveryOptionLabel(order.fulfillment_method) || 'Not set';
      buyerArrangementPayment.textContent = paymentOptionLabel(order.payment_option) || 'Not set';
      buyerArrangementPaymentStatus.textContent = String(order.payment_option || '').toLowerCase() === 'cash'
        && ['', 'pending'].includes(String(order.payment_status || '').toLowerCase())
          ? 'Cash on Handover' : paymentStatusLabel(order.payment_status);
      const locationLabel = document.getElementById('buyerArrangementLocationLabel');
      if (locationLabel) {
        const method = String(order.fulfillment_method || '').toLowerCase();
        locationLabel.textContent = method === 'third_party_delivery' ? 'Delivery Address'
          : method === 'pickup' ? 'Pickup Place' : method === 'meetup' ? 'Meetup Place' : 'Location';
      }
      buyerArrangementLocation.textContent = order.arrangement_location || 'Not set';
      buyerArrangementDate.textContent = formatArrangementDate(order.arrangement_date);
      buyerArrangementTime.textContent = formatArrangementTime(order.arrangement_time);
      const notes = String(order.arrangement_notes || '').trim();
      buyerArrangementNotes.textContent = notes;
      const notesRow = document.getElementById('buyerArrangementNotesRow');
      if (notesRow) notesRow.hidden = !notes;
      const awaitingBuyer = buyerReviewPending(order);
      const canConfirm = arrangementCanBeConfirmed(order);
      // Keep the next action visible when it's the buyer's turn. If any
      // seller detail is invalid, disable confirmation and explain why;
      // the buyer can still request corrections instead of seeing no action.
      buyerConfirmArrangement.hidden = !awaitingBuyer;
      buyerConfirmArrangement.disabled = !canConfirm;
      if (buyerArrangementReview) {
        const canReview = reviewsEnabled && String(order.order_status || '').toLowerCase() === 'completed'
          && Number(order.review_remaining ?? 0) > 0;
        buyerArrangementReview.hidden = !canReview;
        buyerArrangementReview.disabled = !canReview;
      }
      const canPay = canPayWithPayMongo(order);
      buyerPayNowDialogButton.hidden = !canPay;
      buyerPayNowDialogButton.disabled = !canPay;
      const canAskToChange = awaitingBuyer;
      buyerArrangementNotYet.textContent = canAskToChange ? 'Ask to Change' : 'Close';
      buyerArrangementDialog?.classList.toggle('buyer-turn', awaitingBuyer);
      buyerArrangementNotYet.removeAttribute('title');
      // Show feedback only when a change or correction is needed; actions show the next step.
      setBuyerArrangementFeedback();
      if (!canAskToChange && String(order.order_status || '').toLowerCase() === 'pending') {
        const stage = String(order.arrangement_status || '').toLowerCase();
        setBuyerArrangementFeedback(stage === 'awaiting_seller_acceptance'
          ? 'Details confirmed. Waiting for the seller to accept the order.'
          : 'Waiting for the seller to send the delivery and payment details.', '');
      }
      if (canAskToChange && !canConfirm) {
        if (arrangementDateTimeIsPast(order)) {
          setBuyerArrangementFeedback('Schedule has passed. Ask the seller to change it.', 'error');
        } else if (String(order.payment_option || '').toLowerCase() === 'cash'
          && !['meetup', 'pickup'].includes(String(order.fulfillment_method || '').toLowerCase())) {
          setBuyerArrangementFeedback('Cash is for Meetup or Pickup only.', 'error');
        } else {
          setBuyerArrangementFeedback('Some delivery details are missing. Ask the seller to update them.', 'error');
        }
      }
    }
    function displayOrderStage(order) {
      const status = String(order?.order_status || '').toLowerCase();
      if (['processing','completed','accepted','confirmed'].includes(status)) {
        return status === 'confirmed' ? 'Accepted' : status.charAt(0).toUpperCase() + status.slice(1);
      }
      if (orderTerminal(status)) return orderTerminalLabel(status);
      const stage = String(order?.arrangement_status || '').toLowerCase();
      if (status === 'pending' && stage === 'awaiting_confirmation') return 'Review Details';
      if (status === 'pending' && stage === 'awaiting_seller_acceptance') return 'Waiting for Seller';
      if (status === 'pending' && stage === 'arranging') return 'Waiting for Seller';
      return niceArrangementValue(order?.arrangement_status || status || 'pending');
    }
    function renderOrderProgress(order) {
      if (!buyerOrderProgress) return;
      buyerOrderProgress.replaceChildren();
      const status = String(order?.order_status || '').toLowerCase();
      if (!order || ['cancelled','canceled','rejected','declined','expired'].includes(status)) {
        buyerOrderProgress.hidden = true;
        return;
      }
      const keys = ['pending','accepted','processing','completed'];
      const names = ['Requested','Accepted','Processing','Completed'];
      const current = Math.max(0, keys.indexOf(status === 'confirmed' ? 'accepted' : status));
      names.forEach((name,index) => {
        const step = document.createElement('span');
        step.textContent = name;
        step.className = index < current ? 'done' : (index === current ? 'current' : '');
        if (index === current) step.setAttribute('aria-current','step');
        buyerOrderProgress.appendChild(step);
      });
      buyerOrderProgress.hidden = false;
    }
    function setReviewFeedback(message = '', success = false) {
      if (!reviewFeedback) return;
      reviewFeedback.textContent = message;
      reviewFeedback.style.color = success ? '#407b4b' : '#bd3f3f';
    }
    function paintReviewStars() {
      reviewStars?.querySelectorAll('[data-rating]').forEach((star) => {
        const value = Number(star.dataset.rating);
        star.classList.toggle('active', value <= reviewRating);
        star.textContent = value <= reviewRating ? '★' : '☆';
        star.setAttribute('aria-pressed', String(value === reviewRating));
      });
      if (reviewSubmit) reviewSubmit.disabled = reviewBusy || !reviewRating || !reviewSelect?.value;
    }
    async function refreshReviewProducts() {
      const order = activeOrderDetails;
      const reviewSellerId = Number(selectedSellerId);
      if (!order || String(order.order_status).toLowerCase() !== 'completed') return false;
      const form = new FormData();
      form.append('action','review_products');
      form.append('seller_id',String(selectedSellerId));
      form.append('order_id',String(order.order_id));
      const result = await postRequest(form);
      // Ignore an old review response if the buyer switched sellers or orders meanwhile.
      if (Number(selectedSellerId) !== reviewSellerId
          || Number(activeOrderDetails?.order_id) !== Number(order.order_id)) return false;
      reviewSelect.replaceChildren();
      (result.products || []).filter(item => !item.reviewed).forEach(item => {
        const option = document.createElement('option');
        option.value = String(item.order_item_id);
        option.textContent = item.product_name;
        reviewSelect.appendChild(option);
      });
      const available = reviewSelect.options.length > 0;
      if (buyerLeaveReview) {
        buyerLeaveReview.textContent = available ? 'Rate & Review' : 'Reviewed ✓';
        buyerLeaveReview.disabled = !available;
      }
      if (reviewSubmit) reviewSubmit.disabled = !available || !reviewRating;
      if (!available) setReviewFeedback('All products in this order have been reviewed.', true);
      return available;
    }
    buyerLeaveReview?.addEventListener('click', openReviewInOrders);
    reviewStars?.addEventListener('click', (event) => {
      const star = event.target.closest('[data-rating]');
      if (!star) return;
      reviewRating = Number(star.dataset.rating);
      setReviewFeedback();
      paintReviewStars();
    });
    reviewClose?.addEventListener('click', () => reviewDialog?.close());
    reviewCancel?.addEventListener('click', () => reviewDialog?.close());
    reviewForm?.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (reviewBusy || !reviewRating || !reviewSelect?.value || !activeOrderDetails) return;
      reviewBusy = true;
      paintReviewStars();
      setReviewFeedback();
      try {
        const form = new FormData();
        form.append('action','submit_review');
        form.append('seller_id',String(selectedSellerId));
        form.append('order_id',String(activeOrderDetails.order_id));
        form.append('order_item_id',reviewSelect.value);
        form.append('rating',String(reviewRating));
        form.append('review_text',reviewComment?.value.trim() || '');
        const result = await postRequest(form);
        reviewRating = 0;
        if (reviewComment) reviewComment.value = '';
        setReviewFeedback(result.message || 'Review submitted.', true);
        await refreshReviewProducts();
        if (activeOrderDetails) activeOrderDetails.review_remaining = reviewSelect.options.length;
        if (reviewSelect.options.length > 0) paintReviewStars();
      } catch(error) { setReviewFeedback(error.message); }
      finally { reviewBusy = false; paintReviewStars(); }
    });
    function applyOrderDetails(order) {
      activeOrderDetails = order || null;
      const closed = Boolean(order && orderTerminal(order.order_status));
      if (chatOrderClosed) {
        chatOrderClosed.hidden = !closed;
        if (closed) {
          chatClosedTitle.textContent = 'Order #' + order.order_id + ' · ' + orderTerminalLabel(order.order_status);
          chatClosedMessage.textContent = String(order.order_status).toLowerCase() === 'completed'
            ? 'Completed order. You can still message the seller.'
            : 'This order is closed. You can still message the seller.';
        }
      }
      renderOrderProgress(activeOrderDetails);
      if (buyerLeaveReview) {
        const completed = reviewsEnabled && String(order?.order_status || '').toLowerCase() === 'completed';
        buyerLeaveReview.hidden = !completed || order?.review_remaining === null;
        const hasReviewsLeft = Number(order?.review_remaining ?? 0) > 0;
        buyerLeaveReview.disabled = !hasReviewsLeft;
        buyerLeaveReview.textContent = hasReviewsLeft ? 'Rate & Review' : 'Reviewed ✓';
      }
      if (order && chatBody) {
        chatBody.querySelectorAll('.order-chat-action[data-order-id="' + Number(order.order_id) + '"]')
          .forEach((button) => {
            button.textContent = closed ? 'View Closed Order' : orderCardLabel('', false, {
              order_status: order.order_status, order_arrangement_status: order.arrangement_status
            });
            button.closest('.order-chat-card')?.classList.toggle('is-closed-order', closed);
          });
      }
      if (!order) {
        orderSummary?.classList.remove('show');
        if (reviewArrangementButton) {
          reviewArrangementButton.hidden = true;
          reviewArrangementButton.classList.remove(
            'confirm-ready'
          );
        }
        if (buyerPayNowButton) {
          buyerPayNowButton.hidden = true;
          buyerPayNowButton.disabled = true;
        }
        if (buyerArrangementReview) buyerArrangementReview.hidden = true;
        if (buyerPayNowDialogButton) {
          buyerPayNowDialogButton.hidden = true;
          buyerPayNowDialogButton.disabled = true;
        }
        return;
      }
      selectedOrderId = Number(order.order_id) || selectedOrderId;
      orderSummary?.classList.add('show');
      orderSummaryId.textContent =
        'Order #' + order.order_id;
      const fixedPriceOrder =
        String(order.price_mode || '').toLowerCase() === 'fixed';
      orderSummaryItems.textContent =
        Number(order.item_count || 1) > 1
          ? Number(order.item_count)
            + ' items • '
            + Number(order.quantity || 0)
            + ' total units'
          : (order.quantity || 1)
            + ' × '
            + (order.product_name || 'Product');
      const hasAgreed =
        order.agreed_unit_price !== null
        && order.agreed_unit_price !== undefined;
      orderSummaryTotal.textContent =
        fixedPriceOrder
          ? 'Fixed: ' + money(order.final_total)
          : (hasAgreed ? 'Agreed: ' : 'Estimated: ')
            + money(
              hasAgreed
                ? order.final_total
                : order.estimated_total
            );
      if (orderSummaryDelivery) {
        orderSummaryDelivery.textContent =
          order.fulfillment_method
            ? 'Delivery: '
              + deliveryOptionLabel(order.fulfillment_method)
            : '';
      }
      orderSummaryPayment.textContent =
        order.payment_option
          ? 'Payment: '
            + paymentOptionLabel(order.payment_option)
            + (
              order.payment_status
                ? ' • '
                  + paymentStatusLabel(
                    order.payment_status
                  )
                : ''
            )
          : '';
      orderSummaryStatus.textContent = displayOrderStage(order);
      if (buyerPayNowButton) {
        const canPay = canPayWithPayMongo(order);
        buyerPayNowButton.hidden = !canPay;
        buyerPayNowButton.disabled = !canPay;
      }
      if (orderTerminal(order.order_status)) {
        activeQrSession = null;
        clearQrTimers();
        if (buyerQrDialog?.open) buyerQrDialog.close();
      } else if (order.qr_session) {
        activeQrSession = order.qr_session;
      } else if (paymentIsPaid(order)) {
        activeQrSession = null;
      }
      if (reviewArrangementButton) {
        const awaitingBuyer = buyerReviewPending(order);
        reviewArrangementButton.hidden = false;
        reviewArrangementButton.textContent = awaitingBuyer
          ? 'Review & Confirm' : 'View Order Details';
        reviewArrangementButton.title = awaitingBuyer
          ? 'Review the seller-sent details, confirm or ask for changes'
          : 'View the current order details';
        reviewArrangementButton.classList.toggle('confirm-ready', awaitingBuyer);
      }
      populateBuyerArrangement(order);
    }
    async function loadBuyerOrderDetails(
      sellerId,
      orderId
    ) {
      if (!sellerId || !orderId) {
        applyOrderDetails(null);
        return null;
      }
      const formData = new FormData();
      formData.append('action', 'fetch_order_details');
      formData.append('seller_id', String(sellerId));
      formData.append('order_id', String(orderId));
      const data = await postRequest(formData);
      applyOrderDetails(data.order || null);
      return data.order || null;
    }
    function updateOrderSummary(
      messages,
      orderId = selectedOrderId
    ) {
      const activeOrderId = Number(orderId || 0);
      if (activeOrderId <= 0) {
        applyOrderDetails(null);
        return;
      }
      loadBuyerOrderDetails(
        selectedSellerId,
        activeOrderId
      ).catch(() => {
        const orderMessage = [...messages]
          .reverse()
          .find(
            (message) =>
              Number(message.order_id) === activeOrderId
          );
        if (!orderMessage) {
          orderSummary?.classList.remove('show');
        }
      });
    }
    async function confirmBuyerArrangement() {
      if (
        activeOrderDetails
        && arrangementDateTimeIsPast(activeOrderDetails)
      ) {
        setBuyerArrangementFeedback(
          'The Date or Time has already passed. Ask the seller to update the order details.',
          'error'
        );
        return;
      }
      if (
        !activeOrderDetails
        || !selectedSellerId
        || !selectedOrderId
        || !arrangementCanBeConfirmed(activeOrderDetails)
      ) {
        return;
      }
      buyerConfirmArrangement.disabled = true;
      setBuyerArrangementFeedback();
      const formData = new FormData();
      formData.append('action', 'confirm_arrangement');
      formData.append(
        'seller_id',
        String(selectedSellerId)
      );
      formData.append(
        'order_id',
        String(selectedOrderId)
      );
      try {
        const data = await postRequest(formData);
        applyOrderDetails(data.order || null);
        setBuyerArrangementFeedback(
          data.message
            || 'Order details confirmed.',
          'success'
        );
        await openSellerConversation(
          selectedSellerId,
          {
            forceScroll: true,
            prefill: false,
            orderId: selectedOrderId
          }
        );
        setTimeout(() => {
          buyerArrangementDialog?.close();
        }, 650);
      } catch (error) {
        setBuyerArrangementFeedback(
          error.message,
          'error'
        );
        buyerConfirmArrangement.disabled = false;
      }
    }
    async function generatePayMongoQr(
      forceNew = false
    ) {
      if (
        !activeOrderDetails
        || !selectedSellerId
        || !selectedOrderId
        || !canPayWithPayMongo(activeOrderDetails)
      ) {
        showChatError(
          'This order is not ready for QR Ph payment yet.'
        );
        return;
      }
      clearChatError();
      openQrDialog();
      if (buyerQrLoading) {
        buyerQrLoading.hidden = false;
        buyerQrLoading.textContent =
          forceNew
            ? 'Generating a new QR...'
            : 'Generating QR...';
      }
      if (buyerQrImage) {
        buyerQrImage.hidden = true;
      }
      if (buyerQrRegenerate) {
        buyerQrRegenerate.disabled = true;
      }
      setQrFeedback(
        forceNew
          ? 'Generating a new QR...'
          : 'Generating QR...',
        ''
      );
      const formData = new FormData();
      formData.append(
        'action',
        'generate_paymongo_qr'
      );
      formData.append(
        'seller_id',
        String(selectedSellerId)
      );
      formData.append(
        'order_id',
        String(selectedOrderId)
      );
      try {
        const data = await postRequest(formData);
        if (data.paid) {
          if (data.order) {
            applyOrderDetails(data.order);
          }
          setQrFeedback(
            'Payment is already confirmed.',
            'success'
          );
          renderQrSession(
            data.order?.qr_session
            || data.qr
            || null
          );
          return;
        }
        if (data.order) {
          applyOrderDetails(data.order);
        }
        renderQrSession(
          data.qr || null
        );
        setQrFeedback(
          data.reused
            ? 'Waiting for payment...'
            : 'Waiting for payment...',
          ''
        );
      } catch (error) {
        setQrFeedback(
          error.message,
          'error'
        );
        showChatError(error.message);
        if (buyerQrLoading) {
          buyerQrLoading.hidden = false;
          buyerQrLoading.textContent =
            'Unable to generate QR.';
        }
        if (buyerQrRegenerate) {
          buyerQrRegenerate.disabled = false;
        }
      }
    }
    async function checkActiveQrPayment() {
      if (
        qrCheckInProgress
        || !selectedSellerId
        || !selectedOrderId
      ) {
        return;
      }
      qrCheckInProgress = true;
      const formData = new FormData();
      formData.append(
        'action',
        'check_paymongo_payment'
      );
      formData.append(
        'seller_id',
        String(selectedSellerId)
      );
      formData.append(
        'order_id',
        String(selectedOrderId)
      );
      try {
        const data = await postRequest(formData);
        if (data.order) {
          applyOrderDetails(data.order);
        }
        if (data.qr) {
          renderQrSession(data.qr);
        }
        if (data.paid) {
          clearQrTimers();
          closePayMongoTestWindow();
          if (buyerQrImage) {
            buyerQrImage.hidden = true;
            buyerQrImage.removeAttribute('src');
          }
          if (buyerQrLoading) buyerQrLoading.hidden = true;
          setQrFeedback(
            'Payment received.',
            'success'
          );
          if (buyerQrTimer) {
            buyerQrTimer.textContent =
              'PAID';
          }
          if (buyerQrRegenerate) {
            buyerQrRegenerate.hidden = true;
          }
          if (buyerQrSimulate) {
            buyerQrSimulate.hidden = true;
            buyerQrSimulate.disabled = true;
          }
          await refreshConversationList();
          await pollMessages();
          return;
        }
        if (data.failed) {
          clearQrTimers();
          closePayMongoTestWindow();
          renderQrSession(data.qr || {qr_status: 'failed'});
          setQrFeedback(
            data.message || 'Payment failed. Generate a new QR.',
            'error'
          );
          if (buyerQrTimer) buyerQrTimer.textContent = 'FAILED';
          if (buyerQrRegenerate) {
            buyerQrRegenerate.hidden = false;
            buyerQrRegenerate.disabled = false;
          }
          return;
        }
        if (data.expired) {
          clearQrTimers();
          closePayMongoTestWindow();
          setQrFeedback(
            'QR expired. Generate a new QR.',
            'error'
          );
          if (buyerQrTimer) {
            buyerQrTimer.textContent =
              '00:00';
          }
          if (buyerQrRegenerate) {
            buyerQrRegenerate.hidden = false;
            buyerQrRegenerate.disabled = false;
          }
          return;
        }
        if (buyerQrFeedback?.classList.contains('error')) {
          setQrFeedback('Waiting for payment...', '');
        }
      } catch (error) {
        if (buyerQrDialog?.open) {
          setQrFeedback('Payment check unavailable. Retrying...', 'error');
        }
      } finally {
        qrCheckInProgress = false;
      }
    }
    async function startPayMongoPayment() {
      if (
        !activeOrderDetails
        || !canPayWithPayMongo(
          activeOrderDetails
        )
      ) {
        showChatError(
          'This order is not ready for QR Ph payment yet.'
        );
        return;
      }
      openQrDialog();
      const savedQr =
        activeOrderDetails.qr_session
        || null;
      if (
        savedQr
        && String(
          savedQr.qr_status || ''
        ).toLowerCase() === 'active'
        && !qrSessionIsExpired(savedQr)
      ) {
        renderQrSession(savedQr);
        setQrFeedback(
          'Waiting for payment...',
          ''
        );
        checkActiveQrPayment();
        return;
      }
      await generatePayMongoQr(false);
    }
    function getCachedConversation(sellerId) {
      return initialConversations.find(
        (conversation) => Number(conversation.partner_id) === Number(sellerId)
      ) || null;
    }
    function setActiveConversation(sellerId) {
      document.querySelectorAll('.conversation-item').forEach((item) => {
        item.classList.toggle(
          'active',
          Number(item.dataset.sellerId) === Number(sellerId)
        );
      });
    }
    async function postRequest(formData) {
      if (!formData.has('csrf_token')) {
        formData.append('csrf_token', messagesCsrf);
      }
      const response = await fetch(window.location.pathname, {
        method: 'POST',
        body: formData
      });
      let data;
      try {
        data = await response.json();
      } catch (error) {
        throw new Error('The server returned an invalid response.');
      }
      if (!response.ok || !data.success) {
        throw new Error(data.message || 'Something went wrong.');
      }
      return data;
    }
    async function markConversationRead(sellerId) {
      if (!sellerId) return;
      const formData = new FormData();
      formData.append('action', 'mark_read');
      formData.append('seller_id', String(sellerId));
      if (orderFocusActive()) formData.append('focus_order_id', String(focusedOrderId));
      try {
        await postRequest(formData);
      } catch (error) {
      }
    }
    function nextRelevantBuyerOrderId(messages) {
      const orderMessages = (Array.isArray(messages) ? messages : [])
        .filter(message => Number.isSafeInteger(Number(message.order_id))
          && Number(message.order_id) > 0);
      // Prioritize an order waiting for this buyer, even if its message is
      // not the last message in the conversation (e.g. a newer plain chat).
      const reviewMessage = [...orderMessages].reverse()
        .find(message => buyerReviewPending(message));
      const candidate = reviewMessage || orderMessages[orderMessages.length - 1];
      return candidate ? Number(candidate.order_id) : 0;
    }
    async function openSellerConversation(sellerId, options = {}) {
      const reviewGeneration = ++reviewOpenGeneration;
      if (reviewDialog?.open) reviewDialog.close();
      const forceScroll = options.forceScroll !== false;
      const shouldPrefill = options.prefill === true;
      const requestedOrderId = Number(options.orderId || 0);
      selectedSellerId = Number(sellerId);
      selectedOrderId = requestedOrderId;
      manuallySelectedOrder = requestedOrderId > 0 && options.manualSelection === true;
      // Switching to a normal conversation must not carry over a notification's order filter.
      focusedOrderId = options.focusOrderId > 0 && options.focusOnly === true ? Number(options.focusOrderId) : 0;
      showAllSellerMessages = false;
      syncLinkedOrderFilter();
      if (!selectedSellerId) return;
      clearChatError();
      setActiveConversation(selectedSellerId);
      if (selectedOrderId <= 0 && orderSummary) {
        orderSummary.classList.remove('show');
      }
      const cached = getCachedConversation(selectedSellerId);
      if (cached) {
        updateHeader(cached);
      }
      clearChatBody();
      try {
        const formData = new FormData();
        formData.append('action', 'fetch_messages');
        formData.append('seller_id', String(selectedSellerId));
        if (requestedOrderId > 0) formData.append('preferred_order_id', String(requestedOrderId));
        const data = await postRequest(formData);
        if (reviewGeneration !== reviewOpenGeneration || Number(selectedSellerId) !== Number(sellerId)) return;
        const conversationMessages = data.messages || [];
        renderMessages(conversationMessages, forceScroll, Number(options.focusOrderId || 0));
        if (selectedOrderId <= 0) {
          selectedOrderId = Number(data.next_action_order_id || 0)
            || nextRelevantBuyerOrderId(conversationMessages);
        }
        if (selectedOrderId > 0) {
          await loadBuyerOrderDetails(
            selectedSellerId,
            selectedOrderId
          );
        } else {
          applyOrderDetails(null);
        }
        await markConversationRead(selectedSellerId);
        if (
          shouldPrefill &&
          prefillMessage &&
          messageInput &&
          !messageInput.value.trim()
        ) {
          messageInput.value = prefillMessage;
          autoResizeTextarea();
          updateSendButton();
          messageInput.focus();
        }
        setMobileChatOpen(true);
        startPolling();
        startTypingPolling();
      } catch (error) {
        showChatError(error.message);
      }
    }
    async function pollMessages() {
      if (!selectedSellerId) return;
      const formData = new FormData();
      formData.append('action', 'fetch_messages');
      formData.append('seller_id', String(selectedSellerId));
      formData.append('after_id', String(lastMessageId));
      try {
        const data = await postRequest(formData);
        const newMessages = data.messages || [];
        const awaitingOrderId = Number(data.next_action_order_id || 0);
        if (awaitingOrderId > 0 && !manuallySelectedOrder
            && !buyerArrangementDialog?.open && awaitingOrderId !== selectedOrderId) {
          selectedOrderId = awaitingOrderId;
          await loadBuyerOrderDetails(selectedSellerId, awaitingOrderId);
        }
        if (newMessages.length) {
          let visibleNewMessage = false;
          newMessages.forEach((message) => {
            const id = Number(message.message_id) || 0;
            // Track the full conversation so the next poll does not retrieve
            // another order's hidden messages indefinitely.
            if (!fullConversationMessages.some(existing => Number(existing.message_id) === id)) {
              fullConversationMessages.push(message);
            }
            lastMessageId = Math.max(lastMessageId, id);
            if (orderFocusActive() && Number(message.order_id) !== focusedOrderId) return;
            if (!chatBody.querySelector('[data-message-id="' + id + '"]')) {
              if (chatBody.querySelector('.chat-empty')) clearChatBody();
              appendMessage(message, false);
              visibleNewMessage = true;
            }
          });
          const newestOrderMessage = [...newMessages]
            .reverse()
            .find(message => Number(message.order_id) > 0);
          const sellerSentDetails = [...newMessages]
            .reverse()
            .find(message => Number(message.sender_id) === Number(selectedSellerId)
              && orderChatEvent(message) === 'details'
              && String(message.order_status || '').toLowerCase() === 'pending');
          if (newestOrderMessage) {
            const incomingOrderId = Number(
              (sellerSentDetails || newestOrderMessage).order_id
            );
            if (
              !buyerArrangementDialog?.open
              && (!manuallySelectedOrder || incomingOrderId === selectedOrderId)
              && (sellerSentDetails || selectedOrderId <= 0 || incomingOrderId === selectedOrderId)
            ) {
              selectedOrderId = incomingOrderId;
              await loadBuyerOrderDetails(
                selectedSellerId,
                selectedOrderId
              );
            } else if (
              sellerSentDetails
              && buyerArrangementDialog?.open
              && incomingOrderId === selectedOrderId
            ) {
              // If the buyer is already viewing this order, refresh the
              // modal actions without unexpectedly closing their dialog.
              await loadBuyerOrderDetails(selectedSellerId, incomingOrderId);
            }
          } else if (selectedOrderId > 0) {
            /*
             * Keep the current structured order card fresh even when
             * the latest incoming message is not order-linked.
             */
            await loadBuyerOrderDetails(
              selectedSellerId,
              selectedOrderId
            );
          }
          // Do not move away from the requested order when another order gets a message.
          if (visibleNewMessage) {
            if (orderFocusActive()) showLatestMessage(focusedOrderId);
            else scrollToBottom(true);
          }
          await markConversationRead(selectedSellerId);
        }
        if (!newMessages.length && selectedOrderId > 0
            && Date.now() - lastOrderRefreshAt > 9000
            && !buyerArrangementDialog?.open) {
          lastOrderRefreshAt = Date.now();
          await loadBuyerOrderDetails(selectedSellerId, selectedOrderId);
        }
        await refreshConversationList();
      } catch (error) {
      }
    }
    async function refreshConversationList() {
      if (!buyerId) return;
      const formData = new FormData();
      formData.append('action', 'fetch_conversations');
      try {
        const data = await postRequest(formData);
        const conversations = data.conversations || [];
        initialConversations.splice(
          0,
          initialConversations.length,
          ...conversations
        );
        renderConversationList(conversations);
        const activeConversation = conversations.find(
          (conversation) =>
            Number(conversation.partner_id) === Number(selectedSellerId)
        );
        if (activeConversation) {
          updateHeader(activeConversation);
          const latestOrderId = Number(
            activeConversation.latest_order_id || 0
          );
          if (
            selectedOrderId <= 0
            && latestOrderId > 0
          ) {
            selectedOrderId = latestOrderId;
            await loadBuyerOrderDetails(
              selectedSellerId,
              selectedOrderId
            );
          }
        }
      } catch (error) {
      }
    }
    function renderConversationList(conversations) {
      if (!conversationItems) return;
      conversationItems.innerHTML = '';
      if (!conversations.length) {
        conversationItems.appendChild(
          createElement(
            'div',
            'conversation-empty',
            'No conversations yet. Open a product and tap Chat to start a conversation.'
          )
        );
        return;
      }
      conversations.forEach((conversation) => {
        const item = createElement(
          'button',
          'conversation-item' +
            (Number(conversation.partner_id) === Number(selectedSellerId)
              ? ' active'
              : '')
        );
        item.type = 'button';
        item.dataset.sellerId = conversation.partner_id;
        item.dataset.storeName = conversation.name || conversation.person_name || '';
        const avatar = createElement(
          'span',
          'avatar',
          conversation.initials || 'S'
        );
        const main = createElement('span', 'conversation-main');
        const nameRow = createElement('span', 'conversation-name-row');
        nameRow.appendChild(
          createElement('span', 'conversation-name', conversation.name || 'Seller')
        );
        nameRow.appendChild(
          createElement('span', 'conversation-time', conversation.time || '')
        );
        main.appendChild(nameRow);
        if (conversation.product) {
          main.appendChild(
            createElement('span', 'conversation-sub', conversation.product)
          );
        }
        let preview = conversation.preview || '';
        if (!preview && conversation.attachment) {
          preview = 'Attachment';
        }
        main.appendChild(
          createElement('span', 'conversation-sub', preview)
        );
        item.appendChild(avatar);
        item.appendChild(main);
        if (
          Number(conversation.unread) > 0 &&
          Number(conversation.partner_id) !== Number(selectedSellerId)
        ) {
          item.appendChild(
            createElement(
              'span',
              'conversation-unread',
              String(conversation.unread)
            )
          );
        }
        conversationItems.appendChild(item);
      });
      const empty = createElement('div', 'conversation-empty', 'No matching stores found.');
      empty.id = 'lcStoreSearchEmpty';
      empty.hidden = true;
      conversationItems.appendChild(empty);
      filterStoresInConversations();
    }
    function startPolling() {
      if (messagePoller) {
        clearInterval(messagePoller);
      }
      messagePoller = setInterval(pollMessages, 3000);
    }
    async function updateTypingState(isTyping) {
      if (!selectedSellerId || lastTypingState === isTyping) return;
      lastTypingState = isTyping;
      const formData = new FormData();
      formData.append('action', 'typing');
      formData.append('seller_id', String(selectedSellerId));
      formData.append('is_typing', isTyping ? '1' : '0');
      try {
        await postRequest(formData);
      } catch (error) {
      }
    }
    async function pollTyping() {
      if (!selectedSellerId || !typingIndicator) return;
      const formData = new FormData();
      formData.append('action', 'fetch_typing');
      formData.append('seller_id', String(selectedSellerId));
      try {
        const data = await postRequest(formData);
        typingIndicator.textContent = data.is_typing
          ? selectedSellerName + ' is typing...'
          : '';
      } catch (error) {
        typingIndicator.textContent = '';
      }
    }
    function startTypingPolling() {
      if (typingPoller) {
        clearInterval(typingPoller);
      }
      typingPoller = setInterval(pollTyping, 2500);
    }
    function updateSendButton() {
      if (!sendButton) return;
      const hasText = messageInput?.value.trim() !== '';
      const hasAttachment = attachmentInput?.files?.length > 0;
      sendButton.disabled = !hasText && !hasAttachment;
    }
    function autoResizeTextarea() {
      if (!messageInput) return;
      messageInput.style.height = 'auto';
      messageInput.style.height =
        Math.min(messageInput.scrollHeight, 110) + 'px';
    }
    function clearAttachment() {
      if (attachmentInput) {
        attachmentInput.value = '';
      }
      if (attachmentPreview) {
        attachmentPreview.classList.remove('show');
      }
      if (attachmentPreviewName) {
        attachmentPreviewName.textContent = '';
      }
      updateSendButton();
    }
    chatBody?.addEventListener('click', async (event) => {
      const button = event.target.closest('button.order-chat-action[data-order-id]');
      if (!button || !selectedSellerId) return;
      const orderId = Number(button.dataset.orderId);
      if (!Number.isSafeInteger(orderId) || orderId <= 0) return;
      manuallySelectedOrder = true;
      button.disabled = true;
      try {
        const order = await loadBuyerOrderDetails(selectedSellerId, orderId);
        if (!order || Number(order.order_id) !== orderId) throw new Error('Order not found.');
        openBuyerChatDialog(buyerArrangementDialog, 'order');
      } catch (error) {
        showChatError(error.message);
      } finally {
        button.disabled = false;
      }
    });
    reviewArrangementButton?.addEventListener(
      'click',
      async () => {
        if (
          !selectedSellerId
          || !selectedOrderId
        ) {
          return;
        }
        try {
          await loadBuyerOrderDetails(
            selectedSellerId,
            selectedOrderId
          );
          if (!activeOrderDetails) {
            return;
          }
          populateBuyerArrangement(
            activeOrderDetails
          );
          openBuyerChatDialog(buyerArrangementDialog, 'order');
        } catch (error) {
          showChatError(error.message);
        }
      }
    );
    buyerPayNowButton?.addEventListener(
      'click',
      startPayMongoPayment
    );
    buyerPayNowDialogButton?.addEventListener(
      'click',
      startPayMongoPayment
    );
    function openReviewInOrders() {
      const orderId = Number(activeOrderDetails?.order_id || 0);
      if (!Number.isSafeInteger(orderId) || orderId <= 0
          || String(activeOrderDetails?.order_status || '').toLowerCase() !== 'completed'
          || Number(activeOrderDetails?.review_remaining ?? 0) <= 0) return;
      const url = new URL('orders.php', window.location.href);
      url.searchParams.set('order_id', String(orderId));
      url.searchParams.set('open_review', '1');
      window.location.assign(url.href);
    }
    buyerArrangementReview?.addEventListener('click', openReviewInOrders);
    buyerQrClose?.addEventListener(
      'click',
      () => {
        clearQrTimers();
        closePayMongoTestWindow();
        buyerQrDialog?.close();
      }
    );
    buyerQrSimulate?.addEventListener(
      'click',
      () => {
        const testUrl = String(
          activeQrSession?.test_url || ''
        ).trim();
        if (!testUrl) {
          setQrFeedback(
            'The test payment link is not available for this QR.',
            'error'
          );
          return;
        }
        const opened = openPayMongoTestWindow(
          testUrl
        );
        if (!opened) {
          setQrFeedback(
            'The PayMongo test window was blocked. Allow pop-ups for LokalCraft, then try again.',
            'error'
          );
          return;
        }
        setQrFeedback(
          'Complete the payment in the PayMongo window.',
          ''
        );
      }
    );
    buyerQrRegenerate?.addEventListener(
      'click',
      () => {
        closePayMongoTestWindow();
        generatePayMongoQr(true);
      }
    );
    buyerQrDialog?.addEventListener(
      'click',
      (event) => {
        if (event.target === buyerQrDialog) {
          clearQrTimers();
          buyerQrDialog.close();
        }
      }
    );
    buyerQrDialog?.addEventListener(
      'close',
      () => {
        clearQrTimers();
        closePayMongoTestWindow();
      }
    );
    buyerArrangementClose?.addEventListener(
      'click',
      () => buyerArrangementDialog?.close()
    );
    buyerArrangementNotYet?.addEventListener('click', async () => {
      const order = activeOrderDetails;
      const canRequestChanges = order
        && String(order.order_status || '').toLowerCase() === 'pending'
        && String(order.arrangement_status || '').toLowerCase() === 'awaiting_confirmation';
      if (!canRequestChanges) { buyerArrangementDialog?.close(); return; }
      const orderId = Number(order.order_id);
      buyerArrangementNotYet.disabled = true;
      setBuyerArrangementFeedback();
      try {
        const data = new FormData();
        data.append('action', 'request_changes');
        data.append('seller_id', String(selectedSellerId));
        data.append('order_id', String(orderId));
        const result = await postRequest(data);
        applyOrderDetails(result.order || null);
        buyerArrangementDialog?.close();
        await openSellerConversation(selectedSellerId, {
          forceScroll:true, prefill:false, orderId:orderId, focusOrderId:orderId
        });
      } catch (error) {
        setBuyerArrangementFeedback(error.message, 'error');
      } finally {
        buyerArrangementNotYet.disabled = false;
      }
    });
    buyerArrangementDialog?.addEventListener(
      'click',
      (event) => {
        if (event.target === buyerArrangementDialog) {
          buyerArrangementDialog.close();
        }
      }
    );
    buyerConfirmArrangement?.addEventListener(
      'click',
      confirmBuyerArrangement
    );
    if (conversationItems) {
      conversationItems.addEventListener('click', (event) => {
        const item = event.target.closest('.conversation-item');
        if (!item) return;
        const sellerId = Number(item.dataset.sellerId || 0);
        if (!sellerId) return;
        /*
         * Open the seller conversation and, when available, carry the
         * latest linked order so order actions such as Review & Confirm
         * are visible without requiring a special direct URL.
         */
        const url = new URL(window.location.pathname, window.location.href);
        url.search = '';
        url.searchParams.set('seller_id', String(sellerId));
        // Normal inbox opening stays at the newest message, not an earlier order row.
        // Server will still select the latest order for View/Review actions.
        window.location.href = url.toString();
      });
    }
    document.querySelectorAll('.quick-reply').forEach((button) => {
      button.addEventListener('click', () => {
        if (!messageInput) return;
        messageInput.value = button.dataset.message || '';
        autoResizeTextarea();
        updateSendButton();
        messageInput.focus();
        updateTypingState(true);
        clearTimeout(typingTimeout);
        typingTimeout = setTimeout(() => {
          updateTypingState(false);
        }, 1200);
      });
    });
    if (messageInput) {
      messageInput.addEventListener('focus', () => {
        mobileViewportBaseline = Math.max(
          mobileViewportBaseline,
          window.innerHeight,
          document.documentElement.clientHeight,
          window.visualViewport
            ? window.visualViewport.height
                + window.visualViewport.offsetTop
            : 0
        );
        syncAppViewport();
        setTimeout(() => {
          syncAppViewport();
          scrollToBottom(true);
        }, 80);
        setTimeout(() => {
          syncAppViewport();
          scrollToBottom(true);
        }, 220);
        setTimeout(() => {
          syncAppViewport();
          scrollToBottom(true);
        }, 420);
      });
      messageInput.addEventListener('input', () => {
        autoResizeTextarea();
        updateSendButton();
        updateTypingState(true);
        clearTimeout(typingTimeout);
        typingTimeout = setTimeout(() => {
          updateTypingState(false);
        }, 1200);
      });
      messageInput.addEventListener('blur', () => {
        updateTypingState(false);
        document.documentElement.style.setProperty(
          '--keyboard-inset',
          '0px'
        );
        document.body.classList.remove('keyboard-open');
        setTimeout(() => {
          mobileViewportBaseline = Math.max(
            window.innerHeight,
            document.documentElement.clientHeight
          );
          syncAppViewport();
        }, 250);
      });
      messageInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
          event.preventDefault();
          if (!sendButton.disabled) {
            messageForm.requestSubmit();
          }
        }
      });
    }
    if (attachmentInput) {
      attachmentInput.addEventListener('change', () => {
        const file = attachmentInput.files?.[0];
        if (!file) {
          clearAttachment();
          return;
        }
        if (file.size > 8 * 1024 * 1024) {
          clearAttachment();
          showChatError('Attachments must be 8 MB or smaller.');
          return;
        }
        clearChatError();
        if (attachmentPreview) {
          attachmentPreview.classList.add('show');
        }
        if (attachmentPreviewName) {
          attachmentPreviewName.textContent = file.name;
        }
        updateSendButton();
      });
    }
    if (attachmentRemove) {
      attachmentRemove.addEventListener('click', clearAttachment);
    }
    if (messageForm) {
      messageForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!selectedSellerId) {
          showChatError('Please select a seller conversation first.');
          return;
        }
        const text = messageInput.value.trim();
        const file = attachmentInput.files?.[0];
        if (!text && !file) {
          updateSendButton();
          return;
        }
        sendButton.disabled = true;
        clearChatError();
        updateTypingState(false);
        const formData = new FormData();
        formData.append('action', 'send_message');
        formData.append('receiver_id', String(selectedSellerId));
        formData.append('message_text', text);
        if (selectedOrderId > 0 && !orderTerminal(activeOrderDetails?.order_status)
          && Number(activeOrderDetails?.order_id) === selectedOrderId) {
          formData.append('order_id', String(selectedOrderId));
        }
        if (file) {
          formData.append('attachment', file);
        }
        try {
          const data = await postRequest(formData);
          const message = data.message;
          if (!fullConversationMessages.some(existing => Number(existing.message_id) === Number(message.message_id))) {
            fullConversationMessages.push(message);
          }
          lastMessageId = Math.max(lastMessageId, Number(message.message_id) || 0);
          // Rejected/completed orders use ordinary direct replies (no order_id).
          // Show the normal conversation after such a reply so it stays visible.
          if (orderFocusActive() && Number(message.order_id) !== focusedOrderId) {
            showAllSellerMessages = true;
            syncLinkedOrderFilter();
            renderMessages(fullConversationMessages, true);
          } else if (!chatBody.querySelector('[data-message-id="' + message.message_id + '"]')) {
            if (chatBody.querySelector('.chat-empty')) clearChatBody();
            appendMessage(message, true);
          }
          messageInput.value = '';
          clearAttachment();
          autoResizeTextarea();
          await refreshConversationList();
          await markConversationRead(selectedSellerId);
          updateSendButton();
        } catch (error) {
          showChatError(error.message);
          updateSendButton();
        }
      });
    }
    if (chatBack) {
      chatBack.addEventListener('click', () => {
        setMobileChatOpen(false);
      });
    }
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden && selectedSellerId) {
        pollMessages().finally(() => {
          if (!orderFocusActive()) scrollToBottom(true);
        });
        if (
          activeQrSession
          && buyerQrDialog?.open
        ) {
          checkActiveQrPayment();
        }
      }
    });
    window.addEventListener('focus', () => {
      if (selectedSellerId) {
        pollMessages().finally(() => {
          if (!orderFocusActive()) scrollToBottom(true);
        });
        if (
          activeQrSession
          && buyerQrDialog?.open
        ) {
          checkActiveQrPayment();
        }
      }
    });
    window.addEventListener('resize', () => {
      syncAppViewport();
      if (selectedSellerId && !orderFocusActive()) {
        scrollToBottom(true);
      }
    });
    syncLinkedOrderFilter();
    if (orderFocusActive() && chatProduct) chatProduct.textContent = 'Messages for Order #' + focusedOrderId;
    renderConversationList(initialConversations);
    if (initialMessages.length && selectedSellerId) {
      renderMessages(initialMessages, true, linkedOrderId);
      requestAnimationFrame(() => showLatestMessage(linkedOrderId));
    }
    if (activeOrderDetails && selectedSellerId && selectedOrderId) {
      applyOrderDetails(activeOrderDetails);
    } else if (
      initialMessages.length
      && selectedSellerId
      && selectedOrderId
    ) {
      updateOrderSummary(
        initialMessages,
        selectedOrderId
      );
    }
    if (selectedSellerId) {
      setActiveConversation(selectedSellerId);
      startPolling();
      startTypingPolling();
      if (window.matchMedia('(max-width: 760px)').matches) {
        setMobileChatOpen(true);
      }
      if (prefillMessage && messageInput && !initialMessages.length) {
        messageInput.value = prefillMessage;
        autoResizeTextarea();
        updateSendButton();
      }
      ++reviewOpenGeneration;
    }
    window.addEventListener('beforeunload', () => {
      updateTypingState(false);
      if (messagePoller) {
        clearInterval(messagePoller);
      }
      if (typingPoller) {
        clearInterval(typingPoller);
      }
    });
    if (activeOrderDetails) { renderOrderProgress(activeOrderDetails); }
  </script>

  <script id="lcMessagesStoreHeaderEnhancements">
  (() => {
    const header = document.querySelector('.buyer-header-tools');
    const menu = document.getElementById('buyerSidebarToggle');
    const layout = header?.querySelector('.buyer-search-layout');
    if (!header || !menu || !layout) return;
    const label = document.createElement('span');
    label.className = 'lc-messages-mobile-label';
    label.textContent = 'Messages';
    label.setAttribute('aria-hidden', 'true');
    menu.insertAdjacentElement('afterend', label);
    const sync = () => {
      const expanded = layout.classList.contains('is-expanded');
      header.classList.toggle('lc-store-search-expanded', expanded);
      document.body.classList.toggle('lc-store-searching', expanded);
    };
    new MutationObserver(sync).observe(layout, {attributes:true, attributeFilter:['class']});
    sync();
  })();
  </script>
<script id="buyerChatStoreProfileScript">
(() => {
'use strict';
const $ = id => document.getElementById(id);
const productModal=$('productDetailsModal'), productClose=$('productDetailsClose');
const storeModal=$('lcStoreModal'), storeClose=$('lcStoreClose'), storeScroll=$('lcStoreScroll');
const photoModal=$('lcPhotoLightbox'),photoClose=$('lcPhotoClose'),photoEnlarged=$('lcPhotoEnlarged');
const detailCart=$('productDetailsCart'), detailSave=$('productDetailsSave');
const toast=$('marketplaceToast'),csrf=<?php echo json_encode($_SESSION['buyer_messages_csrf'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
// Keep the toast outside transformed/fixed page and popup containers.
// Otherwise position:fixed can anchor to a scrolling container instead of the visible screen.
if (toast && toast.parentElement !== document.body) document.body.appendChild(toast);
function positionMarketplaceToast(){
  if (!toast) return;
  // visualViewport excludes the on-screen keyboard. Raise the bottom toast above it.
  // When the browser resizes the layout viewport as well, this naturally becomes 0.
  const visibleViewport = window.visualViewport;
  const hiddenBelow = visibleViewport
    ? Math.max(0, window.innerHeight - (visibleViewport.offsetTop + visibleViewport.height))
    : 0;
  document.documentElement.style.setProperty('--lc-toast-bottom-inset', Math.round(hiddenBelow) + 'px');
}
window.visualViewport?.addEventListener('resize', positionMarketplaceToast);
window.visualViewport?.addEventListener('scroll', positionMarketplaceToast);
window.addEventListener('resize', positionMarketplaceToast);
let selectedProduct=null,toastTimeout=null;
let selectedStoreId=0,storeFetchToken=0,reviewsFetchToken=0,reviewOffset=0,reviewBusy=false,reviewHasMore=false;
const savedStates=new Map(),storeCache=new Map();
// Each overlay is a navigation level, including nested store and product photo views.
// Using actual browser history means Android/iPhone Back closes one level at a time.
const navId='lc-msg-store-'+Date.now()+'-'+Math.random().toString(36).slice(2);
const frames=[];let depth=0;
const stateAt=n=>({...window.history.state,lcMessagesStore:{navId,depth:n}});
try { window.history.replaceState(stateAt(0),'',window.location.href); }catch(_){ }
function money(amount){return '₱'+Number(amount||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});}
function productLocation(product){return [product?.barangay,product?.city].filter(Boolean).join(', ');}
function stars(value){const n=Math.max(0,Math.min(5,Math.round(Number(value)||0)));return '★'.repeat(n)+'☆'.repeat(5-n);}
function showToast(message,type='',action=null){
  clearTimeout(toastTimeout);
  positionMarketplaceToast();
  toast.replaceChildren(document.createTextNode(message));
  if(action?.href && action?.label){
    const link=document.createElement('a');link.href=action.href;link.className='lc-toast-action';link.textContent=action.label;
    toast.append(link);
  }
  toast.className='marketplace-toast show '+type;
  toast.classList.toggle('has-action',Boolean(action?.href && action?.label));
  toastTimeout=setTimeout(()=>toast.classList.remove('show'),action?5500:2800);
}
function setVisible(node,show,kind='is-open'){if(!node)return;node.classList.toggle(kind,Boolean(show));node.setAttribute('aria-hidden',show?'false':'true');}
function viewportFit(){
  const vv=window.visualViewport;
  const h=vv?vv.height:window.innerHeight;
  const viewportTop=vv?Math.max(0,vv.offsetTop):0;
  document.documentElement.style.setProperty('--lc-vh',Math.max(160,h)+'px');
  document.documentElement.style.setProperty('--lc-vtop',viewportTop+'px');
  document.documentElement.style.setProperty('--lc-popup-height',Math.max(160,h)+'px');
  document.documentElement.style.setProperty('--lc-popup-center',(viewportTop+h/2)+'px');
  // Keep modal inputs above the on-screen keyboard without moving the background page.
  for(const overlay of [productModal,storeModal,photoModal]){
    if(!overlay)continue;
    overlay.style.top=vv?Math.max(0,vv.offsetTop)+'px':'0px';
  }
}
viewportFit();window.visualViewport?.addEventListener('resize',viewportFit);window.visualViewport?.addEventListener('scroll',viewportFit);window.addEventListener('resize',viewportFit);
function focusWithinModal(event){
  if(!event.target.matches('input,textarea,select,[contenteditable="true"]'))return;
  const input=event.target;
  const panel=input.closest('.lc-product-info-scroll,.lc-product-layout,.lc-store-content');
  if(!panel)return;
  setTimeout(()=>{
    if(document.activeElement!==input)return;
    const rect=input.getBoundingClientRect(),viewportHeight=window.visualViewport?.height||window.innerHeight;
    const topEdge=window.visualViewport?.offsetTop||0;
    if(rect.bottom>topEdge+viewportHeight-82||rect.top<topEdge+65){
      const panelRect=panel.getBoundingClientRect();
      const delta=rect.top-panelRect.top-(panel.clientHeight/2)+(rect.height/2);
      panel.scrollBy({top:delta,behavior:'smooth'});
    }
  },240);
}
document.addEventListener('focusin',focusWithinModal);
async function postAction(form,url=window.location.pathname){
  if(!form.has('csrf_token'))form.append('csrf_token',csrf);
  const response=await fetch(url,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'},body:form});
  let data;try{data=await response.json();}catch(_){throw Error('Unable to read server response.');}
  if(!response.ok||!data?.success)throw Error(data?.message||'Request failed. Please try again.');
  return data;
}
function push(type,payload={}){
  frames.splice(depth);
  frames.push({type,...payload});depth=frames.length;
  try{window.history.pushState(stateAt(depth),'',window.location.href);}catch(_){ }
  render();
}
function backOne(){if(depth>0){window.history.back();}}
window.addEventListener('popstate',event=>{
  const st=event.state?.lcMessagesStore;
  const newDepth=st?.navId===navId?Number(st.depth):0;
  depth=Math.max(0,Math.min(frames.length,Number.isFinite(newDepth)?newDepth:0));
  render();
});
function currentFrame(){return frames[depth-1]||null;}
function render(){
  const frame=currentFrame(),stack=frames.slice(0,depth);
  const storeVisible=stack.some(f=>f.type==='store');
  const productVisible=frame?.type==='product'||frame?.type==='photo';
  const modalVisible=depth>0;
  setVisible(storeModal,storeVisible);
  setVisible(productModal,productVisible);
  setVisible(photoModal,frame?.type==='photo');
  if(storeVisible)storeModal.setAttribute('aria-hidden',frame?.type==='store'?'false':'true');
  if(productVisible)productModal.setAttribute('aria-hidden',frame?.type==='product'?'false':'true');
  document.body.classList.toggle('lc-messages-modal-open',modalVisible);

  if(frame?.type==='store' && selectedStoreId!==Number(frame.sellerId))fillStore(frame.sellerId);
  if(frame?.type==='product' && frame.product){
    if(!selectedProduct||Number(selectedProduct.product_id)!==Number(frame.product.product_id)){
      selectedProduct={...frame.product};renderProduct(selectedProduct);
      loadReviews(true);
    }
  }
  if(frame?.type==='product'&&productModal){$('lcProductInfoScroll')?.scrollTo(0,frame.scrollTop||0);}
  viewportFit();
}
function openProduct(product){
  const top=currentFrame();
  if(top?.type==='product'&&selectedProduct&&productModal){top.scrollTop=$('lcProductInfoScroll')?.scrollTop||0;}
  selectedProduct={...product};
  renderProduct(selectedProduct);
  loadReviews(true);
  push('product',{product:{...selectedProduct},scrollTop:0});
  $('lcProductInfoScroll')?.scrollTo(0,0);productModal.querySelector('.lc-product-layout')?.scrollTo(0,0);
}
function closeProduct(){if(currentFrame()?.type==='product')backOne();}
function selectProductPhoto(product){
  const wrap=$('productDetailsImageWrap');wrap.replaceChildren();
  if(product.product_photo){
    const img=document.createElement('img');img.className='product-details-image';
    img.alt=product.product_name||'Product photo';img.loading='eager';img.decoding='async';
    img.src=product.product_photo;
    img.addEventListener('error',()=>{wrap.disabled=true;wrap.setAttribute('aria-label','Photo unavailable');wrap.textContent='Photo unavailable';},{once:true});
    wrap.disabled=false;wrap.setAttribute('aria-label','View full photo of '+(product.product_name||'this product'));
    wrap.append(img);
  }else{
    wrap.disabled=true;wrap.setAttribute('aria-label','Photo unavailable');
    const el=document.createElement('span');el.className='product-details-placeholder';el.textContent='Photo unavailable';wrap.append(el);
  }
}
function openFullPhoto(){
  if(!selectedProduct?.product_photo||currentFrame()?.type!=='product')return;
  photoEnlarged.src=selectedProduct.product_photo;
  photoEnlarged.alt=selectedProduct.product_name||'Product photo';
  $('lcPhotoTitle').textContent=selectedProduct.product_name||'Product photo';
  push('photo',{productId:Number(selectedProduct.product_id)});
  photoClose?.focus({preventScroll:true});
}
$('productDetailsImageWrap')?.addEventListener('click',openFullPhoto);
photoClose?.addEventListener('click',()=>{if(currentFrame()?.type==='photo')backOne();});
$('lcPhotoBackdrop')?.addEventListener('click',()=>{if(currentFrame()?.type==='photo')backOne();});
function renderProduct(product){
  selectProductPhoto(product);
  $('productDetailsName').textContent=product.product_name||'Product';
  $('productDetailsPrice').textContent=product.cost||money(product.cost_value);
  $('productDetailsLocation').textContent=productLocation(product)||'Location unavailable';
  $('productDetailsCategory').textContent=product.craft_category||'Handmade';
  $('productDetailsDescription').textContent=product.description||'No description provided.';
  $('productDetailsStock').textContent=Number(product.stock)>0?'Available: '+Math.max(0,Number(product.stock)||0):'Out of Stock';
  $('sellerDetailsName').textContent=product.seller_business||product.seller_name||product.seller_person||'Local Seller';
  const chatLink=$('sellerDetailsChat'),sellerId=Number(product.seller_id);chatLink.href='#messageInput';
  $('sellerDetailsLocation').textContent=productLocation(product)||'Taguig City';
  const count=Number(product.review_count)||0,avg=Number(product.avg_rating)||0;
  $('productDetailsRating').replaceChildren(document.createTextNode(stars(avg)+'  '+(count?avg.toFixed(1)+' · '+count+' reviews':'No reviews yet')));
  detailCart.disabled=!(Number(product.stock)>0);
  detailCart.classList.remove('is-complete');
  detailCart.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.4 13.1a2 2 0 0 0 2 1.7H20a2 2 0 0 0 2-1.7L23 6H6"/></svg><span>Add to Cart</span>';
  detailSave.disabled=false;
  syncSaveButton(Number(product.product_id),savedStates.has(Number(product.product_id))?savedStates.get(Number(product.product_id)):Boolean(product.is_saved));
}
function setProductSummary(avg,count){
  selectedProduct.avg_rating=Number(avg)||0;selectedProduct.review_count=Number(count)||0;
  $('productReviewsSummary').textContent=count?Number(avg).toFixed(1)+' average · '+count+' reviews':'No reviews yet';
  $('productDetailsRating').textContent=count?stars(avg)+'  '+Number(avg).toFixed(1)+' · '+count+' reviews':'No reviews yet';
}
function reviewDate(value){if(!value)return '';const date=new Date(String(value).replace(' ','T'));return Number.isNaN(+date)?'':date.toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'});}
function makeReview(review){
  const card=document.createElement('article');card.className='review-card';
  const top=document.createElement('div');top.className='review-card-top';
  const name=document.createElement('strong');name.className='review-card-buyer';name.textContent=review.buyer_name||'Buyer';
  const date=document.createElement('span');date.className='review-card-date';date.textContent=reviewDate(review.created_at);top.append(name,date);
  const rating=document.createElement('div');rating.className='review-card-stars';rating.textContent=stars(review.rating);card.append(top,rating);
  if(review.review_text){const txt=document.createElement('p');txt.className='review-card-text';txt.textContent=review.review_text;card.append(txt);}
  return card;
}
async function loadReviews(reset=false){
  if(!selectedProduct)return;
  if(reset){reviewsFetchToken++;reviewOffset=0;reviewHasMore=false;reviewBusy=false;$('productReviewsList').innerHTML='<div class="reviews-empty">Loading reviews...</div>';$('productReviewsMore').hidden=true;}
  if(reviewBusy)return;
  reviewBusy=true;const token=reviewsFetchToken,productId=Number(selectedProduct.product_id),offset=reviewOffset;
  const more=$('productReviewsMore');more.disabled=true;more.textContent='Loading...';
  try{
    const form=new FormData();form.append('action','chat_store_reviews');form.append('product_id',String(productId));form.append('offset',String(offset));
    const result=await postAction(form);
    if(token!==reviewsFetchToken||Number(selectedProduct?.product_id)!==productId)return;
    setProductSummary(result.average_rating,result.review_count);
    if(offset===0)$('productReviewsList').replaceChildren();
    const reviews=Array.isArray(result.reviews)?result.reviews:[];
    if(!reviews.length&&offset===0){const empty=document.createElement('div');empty.className='reviews-empty';empty.textContent='No reviews yet.';$('productReviewsList').append(empty);}
    else reviews.forEach(review=>$('productReviewsList').append(makeReview(review)));
    reviewOffset=Number(result.next_offset)||offset+reviews.length;
    reviewHasMore=Boolean(result.has_more);
    more.hidden=!reviewHasMore;
  }catch(e){if(token===reviewsFetchToken){if(offset===0)$('productReviewsList').textContent='Could not load reviews.';else showToast(e.message,'error');}}
  finally{if(token===reviewsFetchToken){reviewBusy=false;more.disabled=false;more.innerHTML='See more reviews <span aria-hidden="true">↓</span>';}}
}
$('productReviewsMore').addEventListener('click',()=>{if(reviewHasMore)loadReviews(false);});
// Shop drawer: same seller id but uses its entire available inventory, not the current marketplace filter.
function storeCategoryLabel(value){
  const names={woodcraft:'Woodcraft',crochet:'Crochet',accessories:'Accessories',weaving:'Weaving',bamboo:'Bamboo Craft',pottery:'Pottery',handmade:'Handmade',others:'Others'};
  const raw=String(value||'').trim();
  return names[raw.toLowerCase()]||raw.replace(/[_-]+/g,' ').replace(/\b[a-z]/g,letter=>letter.toUpperCase())||'Handmade';
}
function storeFact(key,value,wide=false){
  const div=document.createElement('div');div.className='lc-store-fact'+(wide?' wide':'');
  const glyphs={
    'Seller Name':'<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
    'Craft Category':'<path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z"/>',
    'Business Description':'<path d="M6 3h9l4 4v14H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M15 3v4h4M8 12h8M8 16h8"/>',
    'Business Location':'<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/>'
  };
  const tile=document.createElement('span');tile.className='lc-store-fact-icon';tile.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'+(glyphs[key]||glyphs['Business Description'])+'</svg>';
  const copy=document.createElement('span');copy.className='lc-store-fact-copy';
  const label=document.createElement('small');label.textContent=key;const strong=document.createElement('strong');strong.textContent=value||'Not provided';copy.append(label,strong);div.append(tile,copy);return div;
}
function makeStoreProduct(product){
  const id=Number(product.product_id),outOfStock=Number(product.stock)<=0;
  const card=document.createElement('article');card.className='lc-store-product';
  if(outOfStock)card.classList.add('is-out-of-stock');
  const open=document.createElement('button');open.type='button';open.className='lc-store-product-open';
  open.setAttribute('aria-label','View '+(product.product_name||'product')+' details'+(outOfStock?' (Out of Stock)':''));
  const thumb=document.createElement('div');thumb.className='lc-store-product-image';
  if(product.product_photo){
    const image=document.createElement('img');
    image.loading='eager';image.decoding='async';image.alt=product.product_name||'Handmade product';
    image.addEventListener('error',()=>{
      image.remove();thumb.classList.add('lc-store-image-unavailable');
      const label=document.createElement('span');label.textContent='Photo unavailable';thumb.append(label);
    },{once:true});
    image.src=product.product_photo;thumb.append(image);
  }else{
    thumb.classList.add('lc-store-image-unavailable');
    const label=document.createElement('span');label.textContent='No photo available';thumb.append(label);
  }
  if(outOfStock){
    const badge=document.createElement('span');badge.className='lc-store-out-of-stock';badge.textContent='Out of Stock';thumb.append(badge);
  }
  const meta=document.createElement('div');meta.className='lc-store-product-meta';
  const category=document.createElement('small');category.className='lc-store-item-category';category.textContent=storeCategoryLabel(product.craft_category);
  const name=document.createElement('strong');name.textContent=product.product_name;
  const price=document.createElement('span');price.className='lc-store-item-price';price.textContent=product.cost||money(product.cost_value);
  const reviews=document.createElement('small');reviews.className='lc-store-item-rating';reviews.textContent=product.review_count?stars(product.avg_rating)+'  '+Number(product.avg_rating||0).toFixed(1)+' ('+product.review_count+')':'☆☆☆☆☆  No reviews yet';
  meta.append(category,name,price,reviews);open.append(thumb,meta);
  open.addEventListener('click',()=>openProduct(product));
  const heart=document.createElement('button');heart.type='button';heart.className='lc-store-save-btn';
  heart.dataset.saveProduct=String(id);
  const saved=savedStates.has(id)?savedStates.get(id):Boolean(product.is_saved);
  heart.classList.toggle('is-saved',saved);heart.textContent=saved?'♥':'♡';
  heart.title=saved?'Saved':'Save product';
  heart.setAttribute('aria-label',saved?'Remove from saved':'Save product');
  heart.setAttribute('aria-pressed',saved?'true':'false');
  heart.addEventListener('click',event=>{event.stopPropagation();toggleSaved(id);});
  card.append(open,heart);return card;
}
function fillStore(sellerId){
  selectedStoreId=Number(sellerId);const cache=storeCache.get(selectedStoreId);
  $('lcStoreMore').hidden=true;$('lcStoreInfo').hidden=true;$('lcStoreMore').setAttribute('aria-expanded','false');$('lcStoreMore').querySelector('span').textContent='See Business Details';
  $('lcStoreSummary').innerHTML='<div class="lc-loading">Loading store...</div>';
  $('lcStoreProducts').innerHTML='<div class="lc-loading">Loading products...</div>';
  $('lcStoreProductCount').textContent='';$('lcStoreTitle').textContent='Store Profile';storeScroll.scrollTo(0,0);
  if(cache){renderStore(cache);return;}
  const token=++storeFetchToken;
  (async()=>{
    try{const form=new FormData();form.append('action','chat_store_load');form.append('seller_id',String(sellerId));const data=await postAction(form);
      storeCache.set(Number(sellerId),data);
      if(token!==storeFetchToken||selectedStoreId!==Number(sellerId))return;renderStore(data);
    }catch(e){if(token!==storeFetchToken)return;$('lcStoreSummary').textContent=e.message;$('lcStoreProducts').replaceChildren();}
  })();
}
function renderStore(data){
  const shop=data.shop||{},items=data.products||[],name=shop.businessname||shop.seller_name||'Local Store';
  $('lcStoreTitle').textContent='Store Profile';
  $('lcStoreSummary').replaceChildren();
  const identity=document.createElement('div');identity.className='lc-store-identity';
  const avatar=document.createElement('div');avatar.className='lc-store-avatar';avatar.textContent=String(name).trim().slice(0,2).toUpperCase();
  const reviewInfo=document.createElement('div');reviewInfo.className='lc-store-reviews';
  const reviewCount=Math.max(0,Number(shop.review_count)||0);
  if(reviewCount>0){
    const star=document.createElement('span');star.className='lc-store-review-star';star.textContent='★';
    const score=document.createElement('span');score.textContent=Number(shop.avg_rating||0).toFixed(1);
    const count=document.createElement('span');count.textContent='('+reviewCount+' review'+(reviewCount===1?'':'s')+')';
    reviewInfo.append(star,score,count);
  }else{reviewInfo.textContent='No reviews yet';}
  identity.append(avatar,reviewInfo);
  const meta=document.createElement('div');meta.className='lc-store-profile-details';const role=document.createElement('span');role.className='lc-store-role';role.textContent='VERIFIED LOCAL ARTISAN';const nameEl=document.createElement('div');nameEl.className='lc-store-summary-name';nameEl.textContent=name;
  const sub=document.createElement('div');sub.className='lc-store-summary-meta';sub.textContent=[shop.craftcategory,shop.barangay,shop.city||'Taguig City'].filter(Boolean).join(' · ');meta.append(role,nameEl,sub);const active=document.createElement('span');active.className='lc-store-active';active.innerHTML='<span class="lc-store-active-dot"></span>Active Store';$('lcStoreSummary').append(identity,meta,active);
  const facts=$('lcStoreFacts');facts.replaceChildren(storeFact('Seller Name',shop.seller_name),storeFact('Craft Category',shop.craftcategory),storeFact('Business Description',shop.description,true),storeFact('Business Location',[shop.barangay,shop.city||'Taguig City'].filter(Boolean).join(', '),true));
  $('lcStoreMore').hidden=false;$('lcStoreProductCount').textContent=items.length+' items';
  const listing=$('lcStoreProducts');listing.replaceChildren();
  if(!items.length){const empty=document.createElement('div');empty.className='lc-loading';empty.textContent='No products listed right now.';listing.append(empty);}
  else items.forEach(item=>listing.append(makeStoreProduct(item)));
}
function openStore(sellerId){if(!Number.isSafeInteger(Number(sellerId))||Number(sellerId)<1)return;if(currentFrame()?.type==='product'&&frames[depth-2]?.type==='store'&&Number(frames[depth-2].sellerId)===Number(sellerId)){backOne();return;}selectedStoreId=0;push('store',{sellerId:Number(sellerId)});}
$('lcStoreMore')?.addEventListener('click',()=>{const info=$('lcStoreInfo'),exp=info.hidden;info.hidden=!exp;const btn=$('lcStoreMore');btn.setAttribute('aria-expanded',exp?'true':'false');btn.querySelector('span').textContent=exp?'Hide Business Details':'See Business Details';});
storeClose?.addEventListener('click',()=>{if(currentFrame()?.type==='store')backOne();});
$('sellerDetailsName')?.addEventListener('click',()=>openStore(selectedProduct?.seller_id));
$('lcSellerAvatar')?.addEventListener('click',()=>openStore(selectedProduct?.seller_id));
$('sellerDetailsStore')?.addEventListener('click',()=>openStore(selectedProduct?.seller_id));
// Saved and cart never navigate away automatically.
function syncSaveButton(productId,saved){
  savedStates.set(Number(productId),Boolean(saved));
  document.querySelectorAll('[data-save-product="'+productId+'"]').forEach(btn=>{btn.classList.toggle('is-saved',saved);btn.textContent=saved?'♥':'♡';btn.title=saved?'Saved':'Save product';btn.setAttribute('aria-label',saved?'Remove from saved':'Save product');btn.setAttribute('aria-pressed',saved?'true':'false');});
  if(Number(selectedProduct?.product_id)===Number(productId)){selectedProduct.is_saved=saved;detailSave.classList.toggle('is-saved',saved);detailSave.setAttribute('aria-pressed',saved?'true':'false');detailSave.setAttribute('aria-label',saved?'Remove from saved':'Save product');detailSave.title=saved?'Saved':'Save product';}
}
const busySaveIds=new Set();
async function toggleSaved(productId){if(busySaveIds.has(productId))return;busySaveIds.add(productId);detailSave.disabled=true;
  try{const form=new FormData();form.append('action','chat_store_save');form.append('product_id',String(productId));const data=await postAction(form);syncSaveButton(productId,Boolean(data.saved));showToast(data.saved?'Saved to your list.':'Removed from Saved.','success');}
  catch(e){showToast(e.message,'error');}finally{busySaveIds.delete(productId);detailSave.disabled=false;}
}
detailSave.addEventListener('click',()=>{if(selectedProduct)toggleSaved(Number(selectedProduct.product_id));});
document.querySelectorAll('[data-save-product]').forEach(btn=>btn.addEventListener('click',event=>{event.stopPropagation();event.preventDefault();toggleSaved(Number(btn.dataset.saveProduct));}));
const cartBusyIds=new Set();
detailCart.addEventListener('click',async()=>{
  if(!selectedProduct)return;
  const productId=Number(selectedProduct.product_id);
  if(cartBusyIds.has(productId))return;cartBusyIds.add(productId);
  detailCart.disabled=true;detailCart.innerHTML='<span class="lc-spinner"></span><span>Adding...</span>';
  try{const form=new FormData();form.append('action','chat_store_cart');form.append('product_id',String(productId));form.append('quantity','1');await postAction(form);
    detailCart.classList.add('is-complete');detailCart.innerHTML='<span>✓ Added to Cart</span>';showToast('Added to cart. Review your items when ready.','success',{label:'View Cart',href:'cart.php'});
    setTimeout(()=>{cartBusyIds.delete(productId);if(Number(selectedProduct?.product_id)!==productId)return;detailCart.disabled=false;detailCart.classList.remove('is-complete');detailCart.innerHTML='<span>Add to Cart</span>';},1800);
  }catch(e){cartBusyIds.delete(productId);if(Number(selectedProduct?.product_id)===productId){detailCart.disabled=false;detailCart.textContent='Add to Cart';}showToast(e.message,'error');}
});

for(const triggerId of ['chatAvatar','chatName']){
  $(triggerId)?.addEventListener('click',()=>{
    const sellerId=Number(document.querySelector('.chat-header')?.dataset.sellerId||0);
    if(sellerId>0)openStore(sellerId);
  });
}
$('sellerDetailsChat')?.addEventListener('click',event=>{
  event.preventDefault();
  if(depth>0){try{window.history.go(-depth);}catch(_){depth=0;render();}}
  setTimeout(()=>$('messageInput')?.focus({preventScroll:true}),100);
});

productClose?.addEventListener('click',closeProduct);
document.addEventListener('keydown',event=>{if(event.key==='Escape'&&depth>0){event.preventDefault();event.stopPropagation();backOne();}});
window.addEventListener('pageshow',event=>{if(event.persisted)render();});
})();

</script>
</body>
</html>
