<?php
require_once __DIR__ . '/seller_auth.php';
date_default_timezone_set('Asia/Manila');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (empty($_SESSION['seller_messages_csrf'])) {
    $_SESSION['seller_messages_csrf'] = bin2hex(random_bytes(32));
}
$activePage = 'messages';
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
function chatStatusKey($value): string
{
    return strtolower(trim((string)$value));
}
function chatTextLength(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}
/** Closed transactions stay visible but cannot be arranged, accepted or reopened by chat. */
function sellerOrderClosedStatus($value): bool
{
    return in_array(chatStatusKey($value),
        ['cancelled', 'canceled', 'rejected', 'declined', 'expired', 'completed'], true);
}

function sellerOrderStatus(mysqli $conn, int $sellerId, int $buyerId, int $orderId): ?string
{
    $stmt = $conn->prepare('SELECT order_status FROM orders
        WHERE order_id = ? AND seller_id = ? AND buyer_id = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Unable to check this order.');
    $stmt->bind_param('iii', $orderId, $sellerId, $buyerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (string)$row['order_status'] : null;
}

function chatNiceStatus($value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    return ucwords(str_replace(['_', '-'], ' ', $value));
}
/** Available fulfillment choices for new Seller order arrangements. */
function sellerFulfillmentMethods(): array
{
    return ['meetup', 'pickup', 'third_party_delivery'];
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
function initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'U';
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
function dateOnly(string $value): string
{
    if ($value === '') {
        return '';
    }
    $timestamp = strtotime($value);
    if (!$timestamp) {
        return '';
    }
    return date('Y-m-d', $timestamp);
}
function dateLabel(string $value): string
{
    if ($value === '') {
        return '';
    }
    $timestamp = strtotime($value);
    if (!$timestamp) {
        return '';
    }
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    if (date('Y-m-d', $timestamp) === $today) {
        return 'Today';
    }
    if (date('Y-m-d', $timestamp) === $yesterday) {
        return 'Yesterday';
    }
    return date('F j, Y', $timestamp);
}
function timeLabel(string $value): string
{
    if ($value === '') {
        return '';
    }
    $timestamp = strtotime($value);
    if (!$timestamp) {
        return '';
    }
    if (date('Y-m-d', $timestamp) === date('Y-m-d')) {
        return date('g:i A', $timestamp);
    }
    return date('M j, g:i A', $timestamp);
}
function relativeLabel(string $value): string
{
    if ($value === '') {
        return '';
    }
    $timestamp = strtotime($value);
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
function sellerDirectThreadState(mysqli $conn, int $sellerId, int $buyerId): int
{
    if ($sellerId <= 0 || $buyerId <= 0 || $sellerId === $buyerId) {
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
function latestSellerOrderId(mysqli $conn, int $sellerId, int $buyerId): int
{
    $stmt = $conn->prepare('
        SELECT order_id
        FROM orders
        WHERE seller_id = ? AND buyer_id = ?
        ORDER BY order_id DESC
        LIMIT 1
    ');
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('ii', $sellerId, $buyerId);
    $stmt->execute();
    $stmt->bind_result($orderId);
    $found = $stmt->fetch();
    $stmt->close();
    return $found ? (int)$orderId : 0;
}
function sellerOrderSource(mysqli $conn, int $orderId): string
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
            if (str_starts_with($message, 'New LokalCraft Ready-Stock Order')
                || str_starts_with($message, 'New LokalCraft Order Request')) {
                return 'cart';
            }
            if (str_starts_with($message, 'New LokalCraft Order via Chat Request') || str_starts_with($message, 'New LokalCraft Product Request')) {
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
function conversationAllowed(
    mysqli $conn,
    int $sellerId,
    int $buyerId,
    int $orderId = 0
): bool {
    if ($sellerId <= 0 || $buyerId <= 0 || $sellerId === $buyerId) {
        return false;
    }
    if ($orderId > 0) {
        $stmt = $conn->prepare('
            SELECT order_id
            FROM orders
            WHERE order_id = ?
              AND seller_id = ?
              AND buyer_id = ?
            LIMIT 1
        ');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iii', $orderId, $sellerId, $buyerId);
        $stmt->execute();
        $stmt->bind_result($matchedOrderId);
        $allowed = (bool)$stmt->fetch();
        $stmt->close();
        return $allowed;
    }
    if (sellerDirectThreadState($conn, $sellerId, $buyerId) === 1) {
        return true;
    }
    return latestSellerOrderId($conn, $sellerId, $buyerId) > 0;
}
function getConversationSummaries(mysqli $conn, int $sellerId): array
{
    $conversations = [];
    $directStates = [];
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
            u.firstname,
            u.lastname,
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
        INNER JOIN users u ON u.id = CASE
            WHEN m.sender_id = ? THEN m.receiver_id
            ELSE m.sender_id
        END
        LEFT JOIN orders o ON o.order_id = m.order_id
        WHERE m.sender_id = ? OR m.receiver_id = ?
        ORDER BY m.sent_at ASC, m.message_id ASC
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to load conversations.');
    }
    $stmt->bind_param('iii', $sellerId, $sellerId, $sellerId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $buyerId = ((int)$row['sender_id'] === $sellerId)
            ? (int)$row['receiver_id']
            : (int)$row['sender_id'];
        $orderId = (int)($row['order_id'] ?? 0);
        if ($buyerId <= 0 || $buyerId === $sellerId) {
            continue;
        }
        if ($orderId > 0) {
            if (
                (int)($row['order_seller_id'] ?? 0) !== $sellerId
                || (int)($row['order_buyer_id'] ?? 0) !== $buyerId
            ) {
                continue;
            }
        } else {
            if (!array_key_exists($buyerId, $directStates)) {
                $directStates[$buyerId] = sellerDirectThreadState(
                    $conn,
                    $sellerId,
                    $buyerId
                );
            }
            if ($directStates[$buyerId] !== 1
                && latestSellerOrderId($conn, $sellerId, $buyerId) <= 0) {
                continue;
            }
        }
        $buyerName = trim(
            ($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')
        );
        if ($buyerName === '') {
            $buyerName = 'Buyer';
        }
        if (!isset($conversations[$buyerId])) {
            $conversations[$buyerId] = [
                'buyer_id' => $buyerId,
                'buyer_name' => $buyerName,
                'initials' => initials($buyerName),
                'latest_message' => '',
                'latest_attachment' => '',
                'latest_time' => '',
                'latest_time_value' => '',
                'unread' => 0,
                'product' => '',
                'latest_order_id' => 0
            ];
        }
        $conversations[$buyerId]['latest_message'] =
            (string)($row['message_text'] ?? '');
        $conversations[$buyerId]['latest_attachment'] =
            (string)($row['attachment'] ?? '');
        $conversations[$buyerId]['latest_time'] =
            relativeLabel((string)$row['sent_at']);
        $conversations[$buyerId]['latest_time_value'] =
            (string)$row['sent_at'];
        if (
            (int)$row['receiver_id'] === $sellerId
            && (int)$row['is_read'] === 0
        ) {
            $conversations[$buyerId]['unread']++;
        }
        if (!empty($row['order_product'])) {
            $conversations[$buyerId]['product'] =
                (string)$row['order_product'];
        }
        if ($orderId > 0) {
            $conversations[$buyerId]['latest_order_id'] = $orderId;
        }
    }
    $stmt->close();
    $conversations = array_values($conversations);
    usort($conversations, static function (array $a, array $b): int {
        return strcmp(
            $b['latest_time_value'],
            $a['latest_time_value']
        );
    });
    return $conversations;
}
function getMessages(
    mysqli $conn,
    int $sellerId,
    int $buyerId,
    int $afterId = 0
): array {
    $messages = [];
    $directThreadOwned = sellerDirectThreadState(
        $conn,
        $sellerId,
        $buyerId
    ) === 1 || latestSellerOrderId($conn, $sellerId, $buyerId) > 0;
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
        $sql .= ' AND m.message_id > ? ';
    }
    $sql .= ' ORDER BY m.sent_at ASC, m.message_id ASC';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to load chat messages.');
    }
    if ($afterId > 0) {
        $stmt->bind_param(
            'iiiii',
            $sellerId,
            $buyerId,
            $buyerId,
            $sellerId,
            $afterId
        );
    } else {
        $stmt->bind_param(
            'iiii',
            $sellerId,
            $buyerId,
            $buyerId,
            $sellerId
        );
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $orderId = (int)($row['order_id'] ?? 0);
        if ($orderId > 0) {
            if (
                (int)($row['order_seller_id'] ?? 0) !== $sellerId
                || (int)($row['order_buyer_id'] ?? 0) !== $buyerId
            ) {
                continue;
            }
        } elseif (!$directThreadOwned) {
            continue;
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
            'time' => timeLabel((string)$row['sent_at']),
            'date_key' => dateOnly((string)$row['sent_at']),
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
            'order_closed' => $orderId > 0 && sellerOrderClosedStatus($row['order_status'] ?? ''),
            'order_arrangement_status' => (string)($row['order_arrangement_status'] ?? ''),
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
function sellerOrderStockIssue(array $items): string
{
    foreach ($items as $item) {
        $name = trim((string)($item['product_name'] ?? 'Product'));
        $status = chatStatusKey($item['product_status'] ?? '');
        $available = max(0, (int)($item['available_stock'] ?? 0));
        $needed = max(1, (int)($item['quantity'] ?? 1));
        if ($status === 'archived' || $status === 'inactive') {
            return $name . ' is archived or inactive. This order cannot be accepted. Review the product in Products.';
        }
        if ($available < $needed) {
            if ($available === 0) {
                return $name . ' is out of stock (0 available; '
                    . $needed . ' needed). Restock it in Products before accepting this order.';
            }
            return $name . ' has only ' . $available . ' available ('
                . $needed . ' needed). Add stock in Products before accepting this order.';
        }
    }
    return '';
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

function getSellerOrderDetails(
    mysqli $conn,
    int $sellerId,
    int $buyerId,
    int $orderId = 0
): ?array {
    if ($sellerId <= 0 || $buyerId <= 0) {
        return null;
    }
    $sql = '
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
            o.seller_id = ?
            AND o.buyer_id = ?
    ';
    if ($orderId > 0) {
        $sql .= ' AND o.order_id = ? ';
    }
    $sql .= '
        ORDER BY
            CASE
                WHEN LOWER(o.order_status) = "pending" THEN 0
                ELSE 1
            END,
            o.order_id DESC
        LIMIT 1
    ';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the order details.');
    }
    if ($orderId > 0) {
        $stmt->bind_param('iii', $sellerId, $buyerId, $orderId);
    } else {
        $stmt->bind_param('ii', $sellerId, $buyerId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    $resolvedOrderId = (int)$row['order_id'];
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
            p.product_photo,
            p.stock,
            COALESCE(p.reserved_stock, 0) AS reserved_stock,
            p.status
        FROM order_items oi
        INNER JOIN products p
            ON p.product_id = oi.product_id
        WHERE oi.order_id = ?
        ORDER BY oi.order_item_id ASC
    ');
    if (!$itemStmt) {
        throw new RuntimeException('Unable to load the order items.');
    }
    $itemStmt->bind_param('i', $resolvedOrderId);
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
        $availableStock = max(
            0,
            (int)($itemRow['stock'] ?? 0)
            - (int)($itemRow['reserved_stock'] ?? 0)
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
            'subtotal' => (float)($itemRow['subtotal'] ?? ($listedPrice * $quantity)),
            'available_stock' => $availableStock,
            'product_status' => (string)($itemRow['status'] ?? '')
        ];
        $totalQuantity += $quantity;
        $listedTotal += $listedPrice * $quantity;
    }
    $source = sellerOrderSource($conn, $resolvedOrderId);
    $isFixedPrice = $source === 'cart';
    $firstItem = $items[0];
    $itemCount = count($items);
    $productName = $itemCount > 1
        ? $itemCount . ' ready-stock items'
        : (string)$firstItem['product_name'];
    return [
        'order_id' => $resolvedOrderId,
        'buyer_id' => (int)$row['buyer_id'],
        'seller_id' => (int)$row['seller_id'],
        'order_status' => (string)($row['order_status'] ?? ''),
        'ordered_at' => (string)($row['ordered_at'] ?? ''),
        'payment_id' => (int)($row['payment_id'] ?? 0),
        'payment_method' => (string)($row['payment_method'] ?? ''),
        'payment_provider' => (string)($row['payment_provider'] ?? ''),
        'payment_status' => (string)($row['payment_status'] ?? ''),
        'paid_at' => (string)($row['paid_at'] ?? ''),
        'order_source' => $source,
        'price_mode' => $isFixedPrice ? 'fixed' : 'negotiable',
        'item_count' => $itemCount,
        'items' => $items,
        'product_id' => (int)$firstItem['product_id'],
        'product_name' => $productName,
        'quantity' => $totalQuantity,
        'listed_unit_price' => $itemCount === 1
            ? (float)$firstItem['listed_unit_price']
            : 0.0,
        'agreed_unit_price' => $itemCount === 1
            ? $firstItem['agreed_unit_price']
            : null,
        'special_request' => $itemCount === 1
            ? (string)$firstItem['special_request']
            : '',
        'estimated_total' => round($listedTotal, 2),
        'current_total' => (float)$row['total_amount'],
        'available_stock' => $itemCount === 1
            ? (int)$firstItem['available_stock']
            : null,
        'acceptance_stock_ready' => sellerOrderStockIssue($items) === '',
        'acceptance_stock_message' => sellerOrderStockIssue($items),
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
        'arrangement_status' => (string)($row['arrangement_status'] ?? 'arranging'),
        'locked_at' => (string)($row['locked_at'] ?? ''),
        'expired_at' => (string)($row['expired_at'] ?? '')
    ];
}
function ensureSellerArrangementRow(mysqli $conn, int $orderId): void
{
    $stmt = $conn->prepare('
        SELECT arrangement_id
        FROM order_arrangements
        WHERE order_id = ?
        LIMIT 1
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to check the order arrangement.');
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($exists) {
        return;
    }
    $stmt = $conn->prepare('
        INSERT INTO order_arrangements
            (order_id, arrangement_status, created_at, updated_at)
        VALUES
            (?, "arranging", NOW(), NOW())
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to create the order arrangement.');
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $stmt->close();
}
function reserveOrderStockForAcceptance(
    mysqli $conn,
    int $orderId
): array {
    $stmt = $conn->prepare('
        SELECT
            oi.order_item_id,
            oi.product_id,
            oi.quantity,
            p.product_name,
            p.stock,
            COALESCE(p.reserved_stock, 0) AS reserved_stock,
            p.status
        FROM order_items oi
        INNER JOIN products p
            ON p.product_id = oi.product_id
        WHERE oi.order_id = ?
        ORDER BY oi.order_item_id ASC
        FOR UPDATE
    ');
    if (!$stmt) {
        throw new RuntimeException(
            'Unable to check product stock.'
        );
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(
        MYSQLI_ASSOC
    );
    $stmt->close();
    if (!$items) {
        throw new RuntimeException(
            'This order has no product items.'
        );
    }
    foreach ($items as $item) {
        $quantity = max(
            1,
            (int)($item['quantity'] ?? 1)
        );
        $physicalStock = max(
            0,
            (int)($item['stock'] ?? 0)
        );
        $reservedStock = max(
            0,
            (int)($item['reserved_stock'] ?? 0)
        );
        $availableStock = max(
            0,
            $physicalStock - $reservedStock
        );
        $productName = trim(
            (string)($item['product_name'] ?? 'Product')
        );
        $productStatus = chatStatusKey(
            $item['status'] ?? ''
        );
        if (
            in_array(
                $productStatus,
                ['archived', 'inactive'],
                true
            )
        ) {
            throw new RuntimeException(
                $productName
                . ' is no longer available for ordering.'
            );
        }
        if ($availableStock < $quantity) {
            throw new RuntimeException(
                'Not enough stock for '
                . $productName
                . '. Available: '
                . $availableStock
                . ', needed: '
                . $quantity
                . '.'
            );
        }
    }
    foreach ($items as $item) {
        $quantity = max(
            1,
            (int)($item['quantity'] ?? 1)
        );
        $productId = (int)$item['product_id'];
        $stmt = $conn->prepare('
            UPDATE products
            SET reserved_stock = COALESCE(reserved_stock, 0) + ?
            WHERE
                product_id = ?
                AND status IN (\'Available\', \'Out of Stock\')
                AND (stock - COALESCE(reserved_stock, 0)) >= ?
        ');
        if (!$stmt) {
            throw new RuntimeException(
                'Unable to reserve product stock.'
            );
        }
        $stmt->bind_param(
            'iii',
            $quantity,
            $productId,
            $quantity
        );
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException(
                'Stock changed while accepting the order. '
                . 'Please review the order and try again.'
            );
        }
        $stmt->close();
        // Keep the stored marketplace status in sync immediately after
        // reserving stock. Both updates share the order-accept transaction.
        $statusStmt = $conn->prepare('
            UPDATE products
            SET status = CASE
                WHEN COALESCE(stock, 0) <= COALESCE(reserved_stock, 0)
                    THEN \'Out of Stock\'
                ELSE \'Available\'
            END
            WHERE product_id = ? AND status <> \'Archived\'
        ');
        if (!$statusStmt) {
            throw new RuntimeException(
                'Unable to update product availability.'
            );
        }
        $statusStmt->bind_param('i', $productId);
        $statusStmt->execute();
        $statusStmt->close();
    }
    return $items;
}
function sellerCashPlatformAdminId(mysqli $conn): int
{
    $stmt = $conn->prepare('
        SELECT id
        FROM users
        WHERE adminOrNot = 1
          AND LOWER(COALESCE(account_status, "active")) = "active"
        ORDER BY id ASC
        LIMIT 1
    ');
    if (!$stmt) {
        throw new RuntimeException(
            'Unable to find the platform wallet.'
        );
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        throw new RuntimeException(
            'No active admin account was found for the platform wallet.'
        );
    }
    return (int)$row['id'];
}
function sellerLockWallet(
    mysqli $conn,
    int $userId,
    string $walletType
): array {
    if (!in_array($walletType, ['seller', 'admin'], true)) {
        throw new RuntimeException('Invalid wallet type.');
    }
    $stmt = $conn->prepare('
        INSERT INTO wallet_accounts
            (
                user_id,
                wallet_type,
                balance,
                status,
                created_at,
                updated_at
            )
        VALUES
            (?, ?, 0.00, "active", NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            updated_at = updated_at
    ');
    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare the internal wallet.'
        );
    }
    $stmt->bind_param('is', $userId, $walletType);
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare('
        SELECT wallet_id, balance, status
        FROM wallet_accounts
        WHERE user_id = ?
          AND wallet_type = ?
        LIMIT 1
        FOR UPDATE
    ');
    if (!$stmt) {
        throw new RuntimeException(
            'Unable to open the internal wallet.'
        );
    }
    $stmt->bind_param('is', $userId, $walletType);
    $stmt->execute();
    $wallet = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$wallet) {
        throw new RuntimeException(
            'Internal wallet was not found.'
        );
    }
    if (
        chatStatusKey($wallet['status'] ?? 'active')
        !== 'active'
    ) {
        throw new RuntimeException(
            ucfirst($walletType)
            . ' wallet is currently unavailable.'
        );
    }
    return [
        'wallet_id' => (int)$wallet['wallet_id'],
        'balance' => round((float)$wallet['balance'], 2)
    ];
}
function sellerWalletReferenceExists(
    mysqli $conn,
    string $referenceKey
): bool {
    $stmt = $conn->prepare('
        SELECT wallet_transaction_id
        FROM wallet_transactions
        WHERE reference_key = ?
        LIMIT 1
    ');
    if (!$stmt) {
        throw new RuntimeException(
            'Unable to check the wallet transaction.'
        );
    }
    $stmt->bind_param('s', $referenceKey);
    $stmt->execute();
    $existing = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $existing;
}
function sellerInsertWalletTransaction(
    mysqli $conn,
    int $walletId,
    int $orderId,
    string $category,
    string $direction,
    float $amount,
    float $balanceAfter,
    string $referenceKey,
    string $description
): void {
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
            (?, ?, NULL, ?, ?, ?, ?, ?, ?, NOW())
    ');
    if (!$stmt) {
        throw new RuntimeException(
            'Unable to save the wallet transaction.'
        );
    }
    $stmt->bind_param(
        'iissddss',
        $walletId,
        $orderId,
        $category,
        $direction,
        $amount,
        $balanceAfter,
        $referenceKey,
        $description
    );
    $stmt->execute();
    $stmt->close();
}
function reserveCashPlatformFeeForAcceptance(
    mysqli $conn,
    int $sellerId,
    int $orderId,
    float $grossAmount
): array {
    $grossAmount = round($grossAmount, 2);
    if ($grossAmount <= 0) {
        throw new RuntimeException(
            'The order total is invalid.'
        );
    }
    $commissionRate = 5.00;
    $platformFee = round($grossAmount * 0.05, 2);
    $sellerNetAmount = round(
        $grossAmount - $platformFee,
        2
    );
    if ($platformFee <= 0) {
        throw new RuntimeException(
            'The platform fee could not be calculated.'
        );
    }
    $stmt = $conn->prepare('
        SELECT
            commission_id,
            collection_method,
            commission_status
        FROM platform_commissions
        WHERE order_id = ?
        LIMIT 1
        FOR UPDATE
    ');
    if (!$stmt) {
        throw new RuntimeException(
            'Unable to check the platform fee record.'
        );
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $existingCommission = $stmt
        ->get_result()
        ->fetch_assoc();
    $stmt->close();
    if ($existingCommission) {
        throw new RuntimeException(
            'A platform fee has already been recorded for this order. '
            . 'Refresh the order before trying again.'
        );
    }
    $adminId = sellerCashPlatformAdminId($conn);
    $sellerWallet = sellerLockWallet(
        $conn,
        $sellerId,
        'seller'
    );
    $sellerReference =
        'cash-platform-fee-seller-order-' . $orderId;
    if (
        sellerWalletReferenceExists(
            $conn,
            $sellerReference
        )
    ) {
        throw new RuntimeException(
            'The cash platform fee was already reserved for this order.'
        );
    }
    if ($sellerWallet['balance'] < $platformFee) {
        throw new RuntimeException(
            'Insufficient Wallet Balance. '
            . 'This cash order requires a 5% platform fee of ₱'
            . number_format($platformFee, 2)
            . ', but your current wallet balance is ₱'
            . number_format($sellerWallet['balance'], 2)
            . '.'
        );
    }
    $adminWallet = sellerLockWallet(
        $conn,
        $adminId,
        'admin'
    );
    $adminReference =
        'cash-platform-fee-admin-order-' . $orderId;
    if (
        sellerWalletReferenceExists(
            $conn,
            $adminReference
        )
    ) {
        throw new RuntimeException(
            'The platform wallet already contains a fee for this cash order.'
        );
    }
    $sellerBalanceAfter = round(
        $sellerWallet['balance'] - $platformFee,
        2
    );
    $adminBalanceAfter = round(
        $adminWallet['balance'] + $platformFee,
        2
    );
    $stmt = $conn->prepare('
        UPDATE wallet_accounts
        SET balance = ?,
            updated_at = NOW()
        WHERE wallet_id = ?
    ');
    if (!$stmt) {
        throw new RuntimeException(
            'Unable to update the seller wallet.'
        );
    }
    $stmt->bind_param(
        'di',
        $sellerBalanceAfter,
        $sellerWallet['wallet_id']
    );
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException(
            'Unable to reserve the platform fee from your wallet.'
        );
    }
    $stmt->close();
    sellerInsertWalletTransaction(
        $conn,
        $sellerWallet['wallet_id'],
        $orderId,
        'cash_fee',
        'debit',
        $platformFee,
        $sellerBalanceAfter,
        $sellerReference,
        '5% platform fee reserved for cash Order #'
            . $orderId
    );
    $stmt = $conn->prepare('
        UPDATE wallet_accounts
        SET balance = ?,
            updated_at = NOW()
        WHERE wallet_id = ?
    ');
    if (!$stmt) {
        throw new RuntimeException(
            'Unable to update the platform wallet.'
        );
    }
    $stmt->bind_param(
        'di',
        $adminBalanceAfter,
        $adminWallet['wallet_id']
    );
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException(
            'Unable to reserve the platform fee.'
        );
    }
    $stmt->close();
    sellerInsertWalletTransaction(
        $conn,
        $adminWallet['wallet_id'],
        $orderId,
        'platform_fee',
        'credit',
        $platformFee,
        $adminBalanceAfter,
        $adminReference,
        '5% platform fee reserved for cash Order #'
            . $orderId
    );
    $collectionMethod = 'seller_wallet';
    $commissionStatus = 'reserved';
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
                created_at,
                updated_at
            )
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ');
    if (!$stmt) {
        throw new RuntimeException(
            'Unable to save the platform fee record.'
        );
    }
    $stmt->bind_param(
        'iiddddss',
        $orderId,
        $sellerId,
        $grossAmount,
        $commissionRate,
        $platformFee,
        $sellerNetAmount,
        $collectionMethod,
        $commissionStatus
    );
    $stmt->execute();
    $stmt->close();
    return [
        'fee' => $platformFee,
        'seller_balance_after' => $sellerBalanceAfter,
        'admin_balance_after' => $adminBalanceAfter
    ];
}
function validArrangementDate(string $value): bool
{
    if ($value === '') {
        return true;
    }
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date
        && $date->format('Y-m-d') === $value;
}
function validArrangementTime(string $value): bool
{
    if ($value === '') {
        return true;
    }
    return (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value);
}
function validateNotPastArrangementDateTime(
    string $date,
    string $time
): void {
    if ($date === '') {
        return;
    }
    $today = date('Y-m-d');
    if ($date < $today) {
        throw new RuntimeException(
            'The selected date has already passed. Choose today or a future date.'
        );
    }
    if ($date !== $today || $time === '') {
        return;
    }
    $selected = DateTime::createFromFormat(
        'Y-m-d H:i',
        $date . ' ' . $time
    );
    if (!$selected) {
        return;
    }
    $now = new DateTime('now');
    $now->setTime(
        (int)$now->format('H'),
        (int)$now->format('i'),
        0
    );
    if ($selected < $now) {
        throw new RuntimeException(
            'The selected time has already passed. Choose a later time.'
        );
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $csrfToken = (string)($_POST['csrf_token'] ?? '');
        if (
            empty($_SESSION['seller_messages_csrf'])
            || !hash_equals((string)$_SESSION['seller_messages_csrf'], $csrfToken)
        ) {
            throw new RuntimeException('Session expired. Refresh the page and try again.');
        }
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'chat_buyer_profile') {
            $buyerId = (int)($_POST['buyer_id'] ?? 0);
            if ($buyerId <= 0 || !conversationAllowed($conn, $sellerId, $buyerId)) {
                throw new RuntimeException('Buyer profile is unavailable for this conversation.');
            }
            // Limit shared identity to what is already displayed in the chat.
            // No email, phone, birthdate, gender, address, account IDs or order details.
            $stmt = $conn->prepare('SELECT firstname, lastname, account_status FROM users WHERE id = ? AND AdminOrNot = 0 LIMIT 1');
            if (!$stmt) throw new RuntimeException('Unable to load this buyer profile.');
            $stmt->bind_param('i', $buyerId);
            $stmt->execute();
            $buyer = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$buyer) throw new RuntimeException('Buyer profile is unavailable.');
            $name = trim((string)$buyer['firstname'] . ' ' . (string)$buyer['lastname']);
            $name = $name !== '' ? $name : 'Buyer';
            $ratingCount = 0;
            $ratingAverage = 0.0;
            $distribution = [1=>0, 2=>0, 3=>0, 4=>0, 5=>0];
            $reviews = [];
            $reviewsReady = false;
            try {
                $check = $conn->query("SHOW TABLES LIKE 'reviews'");
                $reviewsReady = $check && $check->num_rows > 0;
                if ($check) $check->free();
            } catch (Throwable $error) {
                error_log('LokalCraft buyer profile reviews availability: ' . $error->getMessage());
            }
            if ($reviewsReady) {
                $stmt = $conn->prepare('SELECT rating, COUNT(*) AS total FROM reviews WHERE buyer_id = ? GROUP BY rating');
                if (!$stmt) throw new RuntimeException('Unable to load buyer ratings.');
                $stmt->bind_param('i', $buyerId);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                $ratingPoints = 0;
                foreach ($rows as $row) {
                    $stars = (int)$row['rating'];
                    if ($stars < 1 || $stars > 5) continue;
                    $total = (int)$row['total'];
                    $distribution[$stars] = $total;
                    $ratingCount += $total;
                    $ratingPoints += $stars * $total;
                }
                $ratingAverage = $ratingCount > 0 ? round($ratingPoints / $ratingCount, 1) : 0.0;
                // A buyer's submitted product reviews, not ratings received by the buyer.
                // Expose only public-facing shop name, rating, review text and date.
                $stmt = $conn->prepare("SELECT r.rating, r.review_text, r.created_at,
                    COALESCE((SELECT NULLIF(TRIM(b.businessname), '') FROM businessinfo b
                      WHERE b.user_id = r.seller_id ORDER BY b.business_id DESC LIMIT 1), 'Shop') AS shop_name
                    FROM reviews r WHERE r.buyer_id = ?
                    ORDER BY r.created_at DESC, r.review_id DESC LIMIT 20");
                if (!$stmt) throw new RuntimeException('Unable to load buyer reviews.');
                $stmt->bind_param('i', $buyerId);
                $stmt->execute();
                foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                    $reviews[] = [
                        'rating' => max(1, min(5, (int)$row['rating'])),
                        'review_text' => (string)($row['review_text'] ?? ''),
                        'shop_name' => (string)$row['shop_name'],
                        'created_at' => (string)$row['created_at']
                    ];
                }
                $stmt->close();
            }
            header('Cache-Control: private, no-store, max-age=0');
            echo json_encode(['success'=>true, 'profile'=>[
                'name'=>$name, 'is_active'=>strtolower(trim((string)($buyer['account_status'] ?? ''))) === 'active', 'rating_count'=>$ratingCount, 'average_rating'=>$ratingAverage,
                'distribution'=>$distribution, 'reviews'=>$reviews
            ]], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if ($action === 'search_message_orders') {
            // Search only order-linked messages belonging to the signed-in seller.
            $term = trim((string)($_POST['query'] ?? ''));
            if ($term === '' || chatTextLength($term) > 70) {
                echo json_encode(['success' => true, 'orders' => []]);
                exit;
            }
            $candidate = preg_replace('/^(?:order\s*#?\s*|#\s*)/i', '', $term);
            $isOrderId = is_string($candidate) && preg_match('/^\d{1,12}$/D', $candidate) === 1;
            if ($isOrderId) {
                $exactId = (int)$candidate;
                $prefix = $candidate . '%';
                $stmt = $conn->prepare('
                    SELECT o.order_id, o.buyer_id, u.firstname, u.lastname
                    FROM orders o
                    INNER JOIN users u ON u.id = o.buyer_id
                    WHERE o.seller_id = ?
                      AND CAST(o.order_id AS CHAR) LIKE ?
                      AND EXISTS (
                          SELECT 1 FROM messages m
                          WHERE m.order_id = o.order_id
                            AND ((m.sender_id = o.seller_id AND m.receiver_id = o.buyer_id)
                              OR (m.sender_id = o.buyer_id AND m.receiver_id = o.seller_id))
                      )
                    ORDER BY CASE WHEN o.order_id = ? THEN 0 ELSE 1 END, o.order_id DESC
                    LIMIT 8
                ');
                if (!$stmt) throw new RuntimeException('Unable to search order messages.');
                $stmt->bind_param('isi', $sellerId, $prefix, $exactId);
            } else {
                // Treat LIKE wildcards as literal search characters.
                $safeTerm = str_replace(['%', '_'], '', $term);
                if ($safeTerm === '') {
                    echo json_encode(['success' => true, 'orders' => []]);
                    exit;
                }
                $name = '%' . $safeTerm . '%';
                $stmt = $conn->prepare('
                    SELECT o.order_id, o.buyer_id, u.firstname, u.lastname
                    FROM orders o
                    INNER JOIN users u ON u.id = o.buyer_id
                    WHERE o.seller_id = ?
                      AND CONCAT_WS(" ", u.firstname, u.lastname) LIKE ?
                      AND EXISTS (
                          SELECT 1 FROM messages m
                          WHERE m.order_id = o.order_id
                            AND ((m.sender_id = o.seller_id AND m.receiver_id = o.buyer_id)
                              OR (m.sender_id = o.buyer_id AND m.receiver_id = o.seller_id))
                      )
                    ORDER BY o.order_id DESC
                    LIMIT 8
                ');
                if (!$stmt) throw new RuntimeException('Unable to search order messages.');
                $stmt->bind_param('is', $sellerId, $name);
            }
            try {
                $stmt->execute();
                $rows = $stmt->get_result();
                $orders = [];
                while ($row = $rows->fetch_assoc()) {
                    $orders[] = [
                        'order_id' => (int)$row['order_id'],
                        'buyer_id' => (int)$row['buyer_id'],
                        'buyer_name' => trim((string)$row['firstname'] . ' ' . (string)$row['lastname'])
                    ];
                }
            } finally {
                $stmt->close();
            }
            echo json_encode(['success' => true, 'orders' => $orders]);
            exit;
        }
        if ($action === 'fetch_order_details') {
            $buyerId = (int)($_POST['buyer_id'] ?? 0);
            $orderId = (int)($_POST['order_id'] ?? 0);
            if ($buyerId <= 0) {
                throw new RuntimeException('Invalid buyer conversation.');
            }
            if (!conversationAllowed($conn, $sellerId, $buyerId, $orderId)) {
                throw new RuntimeException('This order conversation is not available.');
            }
            $order = getSellerOrderDetails(
                $conn,
                $sellerId,
                $buyerId,
                $orderId
            );
            echo json_encode([
                'success' => true,
                'order' => $order
            ]);
            exit;
        }
        if (
            $action === 'save_arrangement'
            || $action === 'send_arrangement_confirmation'
        ) {
            $buyerId = (int)($_POST['buyer_id'] ?? 0);
            $orderId = (int)($_POST['order_id'] ?? 0);
            if (
                $buyerId <= 0
                || $orderId <= 0
                || !conversationAllowed($conn, $sellerId, $buyerId, $orderId)
            ) {
                throw new RuntimeException('Invalid order details.');
            }
            $orderSource = sellerOrderSource($conn, $orderId);
            if ($orderSource !== 'cart') {
                throw new RuntimeException('This older order is view-only. New requests start in Cart.');
            }
            $fulfillmentMethod = trim((string)($_POST['fulfillment_method'] ?? ''));
            $paymentOption = trim((string)($_POST['payment_option'] ?? ''));
            $location = trim((string)($_POST['arrangement_location'] ?? ''));
            $date = trim((string)($_POST['arrangement_date'] ?? ''));
            $time = trim((string)($_POST['arrangement_time'] ?? ''));
            $notes = trim((string)($_POST['arrangement_notes'] ?? ''));
            if ($location !== '' && chatTextLength($location) > 255) {
                throw new RuntimeException('Arrangement location is too long.');
            }
            if ($notes !== '' && chatTextLength($notes) > 2000) {
                throw new RuntimeException('Arrangement notes are too long.');
            }
            if (!validArrangementDate($date)) {
                throw new RuntimeException('Please choose a valid Date.');
            }
            if (!validArrangementTime($time)) {
                throw new RuntimeException('Please choose a valid Time.');
            }
            validateNotPastArrangementDateTime($date, $time);
            $allowedPayment = [
                '',
                'paymongo',
                'cash'
            ];
            if (
                $fulfillmentMethod !== ''
                && !in_array($fulfillmentMethod, sellerFulfillmentMethods(), true)
            ) {
                throw new RuntimeException('Choose Meetup, Pickup, or Third-Party Delivery.');
            }
            if (!in_array($paymentOption, $allowedPayment, true)) {
                throw new RuntimeException('Invalid Payment Option.');
            }
            if (
                $paymentOption === 'cash'
                && $fulfillmentMethod !== ''
                && !in_array(
                    $fulfillmentMethod,
                    ['meetup', 'pickup'],
                    true
                )
            ) {
                throw new RuntimeException(
                    'Cash is only available for Meetup or Pickup. '
                    . 'Use QR Ph for Third-Party Delivery.'
                );
            }
            $isFinalSend = $action === 'send_arrangement_confirmation';
            if ($isFinalSend) {
                if ($fulfillmentMethod === '') {
                    throw new RuntimeException('Choose delivery.');
                }
                if ($paymentOption === '') {
                    throw new RuntimeException('Choose a payment option.');
                }
                if ($location === '') {
                    throw new RuntimeException('Enter the location.');
                }
                if ($date === '') {
                    throw new RuntimeException('Choose a date.');
                }
                if ($time === '') {
                    throw new RuntimeException('Choose a time.');
                }
            }
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare('
                    SELECT
                        o.order_id,
                        o.order_status,
                        oi.order_item_id,
                        oi.quantity,
                        oi.unit_price
                    FROM orders o
                    INNER JOIN order_items oi
                        ON oi.order_item_id = (
                            SELECT MIN(oi2.order_item_id)
                            FROM order_items oi2
                            WHERE oi2.order_id = o.order_id
                        )
                    WHERE
                        o.order_id = ?
                        AND o.seller_id = ?
                        AND o.buyer_id = ?
                    LIMIT 1
                    FOR UPDATE
                ');
                if (!$stmt) {
                    throw new RuntimeException('Unable to lock the order.');
                }
                $stmt->bind_param(
                    'iii',
                    $orderId,
                    $sellerId,
                    $buyerId
                );
                $stmt->execute();
                $orderRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$orderRow) {
                    throw new RuntimeException('Order not found.');
                }
                if (chatStatusKey($orderRow['order_status']) !== 'pending') {
                    throw new RuntimeException(
                        'Only pending order requests can still be arranged.'
                    );
                }
                ensureSellerArrangementRow($conn, $orderId);
                $stmt = $conn->prepare('
                    SELECT arrangement_status
                    FROM order_arrangements
                    WHERE order_id = ?
                    LIMIT 1
                    FOR UPDATE
                ');
                if (!$stmt) {
                    throw new RuntimeException('Unable to lock the arrangement.');
                }
                $stmt->bind_param('i', $orderId);
                $stmt->execute();
                $arrangementRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $currentArrangementStatus = chatStatusKey(
                    $arrangementRow['arrangement_status'] ?? 'arranging'
                );
                if (
                    !in_array(
                        $currentArrangementStatus,
                        ['arranging', 'awaiting_confirmation'],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'These order details can no longer be changed.'
                    );
                }
                // A Save Draft request (including a late response from another tab)
                // must never undo the seller's already-sent proposal.
                if (!$isFinalSend && $currentArrangementStatus === 'awaiting_confirmation') {
                    throw new RuntimeException(
                        'These details were already sent. Wait for the buyer to respond, '
                        . 'or use Send to Buyer again to submit updated details.'
                    );
                }
                $locationValue = $location !== '' ? $location : null;
                $dateValue = $date !== '' ? $date : null;
                $timeValue = $time !== '' ? $time : null;
                $notesValue = $notes !== '' ? $notes : null;
                $fulfillmentValue = $fulfillmentMethod !== ''
                    ? $fulfillmentMethod
                    : null;
                $paymentValue = $paymentOption !== ''
                    ? $paymentOption
                    : null;
                if ($isFinalSend) {
                    $newArrangementStatus = 'awaiting_confirmation';
                    $stmt = $conn->prepare('
                        UPDATE order_arrangements
                        SET
                            fulfillment_method = ?,
                            payment_option = ?,
                            arrangement_location = ?,
                            arrangement_date = ?,
                            arrangement_time = ?,
                            arrangement_notes = ?,
                            buyer_confirmed_at = NULL,
                            seller_confirmed_at = NOW(),
                            acceptance_deadline = NULL,
                            seller_accepted_at = NULL,
                            arrangement_status = ?,
                            locked_at = NULL,
                            expired_at = NULL,
                            updated_at = NOW()
                        WHERE order_id = ?
                    ');
                    if (!$stmt) {
                        throw new RuntimeException('Unable to finish the order details.');
                    }
                    $stmt->bind_param(
                        'sssssssi',
                        $fulfillmentValue,
                        $paymentValue,
                        $locationValue,
                        $dateValue,
                        $timeValue,
                        $notesValue,
                        $newArrangementStatus,
                        $orderId
                    );
                } else {
                    $newArrangementStatus = 'arranging';
                    $stmt = $conn->prepare('
                        UPDATE order_arrangements
                        SET
                            fulfillment_method = ?,
                            payment_option = ?,
                            arrangement_location = ?,
                            arrangement_date = ?,
                            arrangement_time = ?,
                            arrangement_notes = ?,
                            buyer_confirmed_at = NULL,
                            seller_confirmed_at = NULL,
                            acceptance_deadline = NULL,
                            seller_accepted_at = NULL,
                            arrangement_status = ?,
                            locked_at = NULL,
                            expired_at = NULL,
                            updated_at = NOW()
                        WHERE order_id = ?
                          AND arrangement_status = "arranging"
                    ');
                    if (!$stmt) {
                        throw new RuntimeException('Unable to save the order details.');
                    }
                    $stmt->bind_param(
                        'sssssssi',
                        $fulfillmentValue,
                        $paymentValue,
                        $locationValue,
                        $dateValue,
                        $timeValue,
                        $notesValue,
                        $newArrangementStatus,
                        $orderId
                    );
                }
                $stmt->execute();
                $updatedRows = $stmt->affected_rows;
                $stmt->close();
                if ($updatedRows === 0) {
                    // MySQL may report zero changed rows when an identical draft
                    // is saved twice within a second. Check the actual stage;
                    // never report success after a blocked stale Draft update.
                    $stateStmt = $conn->prepare('SELECT arrangement_status
                        FROM order_arrangements WHERE order_id = ? LIMIT 1');
                    if (!$stateStmt) throw new RuntimeException('Unable to verify order details.');
                    $stateStmt->bind_param('i', $orderId);
                    $stateStmt->execute();
                    $stateRow = $stateStmt->get_result()->fetch_assoc();
                    $stateStmt->close();
                    if (chatStatusKey($stateRow['arrangement_status'] ?? '') !== $newArrangementStatus) {
                        throw new RuntimeException('Order details changed. Refresh before saving or sending again.');
                    }
                }
                if ($isFinalSend) {
                    $verifyStmt = $conn->prepare('
                        SELECT arrangement_status, seller_confirmed_at
                        FROM order_arrangements WHERE order_id = ? LIMIT 1
                    ');
                    if (!$verifyStmt) throw new RuntimeException('Unable to verify sent details.');
                    $verifyStmt->bind_param('i', $orderId);
                    $verifyStmt->execute();
                    $verifiedArrangement = $verifyStmt->get_result()->fetch_assoc();
                    $verifyStmt->close();
                    if (chatStatusKey($verifiedArrangement['arrangement_status'] ?? '') !== 'awaiting_confirmation'
                        || empty($verifiedArrangement['seller_confirmed_at'])) {
                        throw new RuntimeException('Order details were not marked as sent. Please try again.');
                    }
                    $messageText = 'Order #' . $orderId
                        . ' details are ready. Review the delivery, payment, location, date and time before confirming.';
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
                            'Unable to notify the buyer.'
                        );
                    }
                    $stmt->bind_param(
                        'iiis',
                        $sellerId,
                        $buyerId,
                        $orderId,
                        $messageText
                    );
                    $stmt->execute();
                    $stmt->close();
                }
                $conn->commit();
                $updatedOrder = getSellerOrderDetails(
                    $conn,
                    $sellerId,
                    $buyerId,
                    $orderId
                );
                echo json_encode([
                    'success' => true,
                    'message' => $isFinalSend
                        ? 'Details sent. Waiting for the buyer to review and confirm.'
                        : 'Draft saved. Use Send to Buyer when ready.',
                    'next_action' => $isFinalSend ? 'buyer_review' : 'seller_send',
                    'order' => $updatedOrder
                ]);
                exit;
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        }
        if ($action === 'accept_order') {
            $buyerId = (int)($_POST['buyer_id'] ?? 0);
            $orderId = (int)($_POST['order_id'] ?? 0);
            if (
                $buyerId <= 0
                || $orderId <= 0
                || !conversationAllowed(
                    $conn,
                    $sellerId,
                    $buyerId,
                    $orderId
                )
            ) {
                throw new RuntimeException(
                    'Invalid order acceptance.'
                );
            }
            if (sellerOrderSource($conn, $orderId) !== 'cart') {
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
                        oa.fulfillment_method,
                        oa.payment_option,
                        oa.buyer_confirmed_at,
                        oa.seller_accepted_at
                    FROM orders o
                    INNER JOIN order_arrangements oa
                        ON oa.order_id = o.order_id
                    WHERE
                        o.order_id = ?
                        AND o.seller_id = ?
                        AND o.buyer_id = ?
                    LIMIT 1
                    FOR UPDATE
                ');
                if (!$stmt) {
                    throw new RuntimeException(
                        'Unable to prepare order acceptance.'
                    );
                }
                $stmt->bind_param(
                    'iii',
                    $orderId,
                    $sellerId,
                    $buyerId
                );
                $stmt->execute();
                $lockedOrder = $stmt
                    ->get_result()
                    ->fetch_assoc();
                $stmt->close();
                if (!$lockedOrder) {
                    throw new RuntimeException(
                        'Order details not found.'
                    );
                }
                if (
                    chatStatusKey(
                        $lockedOrder['order_status'] ?? ''
                    ) !== 'pending'
                ) {
                    throw new RuntimeException(
                        'Only a pending order can be accepted.'
                    );
                }
                if (
                    chatStatusKey(
                        $lockedOrder['arrangement_status'] ?? ''
                    ) !== 'awaiting_seller_acceptance'
                ) {
                    throw new RuntimeException(
                        'The buyer has not confirmed this '
                        . 'arrangement yet.'
                    );
                }
                if (
                    trim(
                        (string)(
                            $lockedOrder['buyer_confirmed_at']
                            ?? ''
                        )
                    ) === ''
                ) {
                    throw new RuntimeException(
                        'Wait for the buyer to confirm first.'
                    );
                }
                if (
                    trim(
                        (string)(
                            $lockedOrder['seller_accepted_at']
                            ?? ''
                        )
                    ) !== ''
                ) {
                    throw new RuntimeException(
                        'This order has already been accepted.'
                    );
                }
                $paymentOption = chatStatusKey(
                    $lockedOrder['payment_option'] ?? ''
                );
                $deliveryOption = chatStatusKey(
                    $lockedOrder['fulfillment_method'] ?? ''
                );
                $orderTotal = round(
                    (float)($lockedOrder['total_amount'] ?? 0),
                    2
                );
                if (
                    !in_array(
                        $paymentOption,
                        ['paymongo', 'cash'],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'Choose a valid Payment Option before accepting the order.'
                    );
                }
                if (
                    !in_array(
                        $deliveryOption,
                        sellerFulfillmentMethods(),
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'Choose a valid Delivery Option before accepting the order.'
                    );
                }
                if ($orderTotal <= 0) {
                    throw new RuntimeException(
                        'The final order amount is invalid.'
                    );
                }
                $cashFeeResult = null;
                if ($paymentOption === 'cash') {
                    if (
                        !in_array(
                            $deliveryOption,
                            ['meetup', 'pickup'],
                            true
                        )
                    ) {
                        throw new RuntimeException(
                            'Cash is only available for Meetup or Pickup.'
                        );
                    }
                    $cashFeeResult =
                        reserveCashPlatformFeeForAcceptance(
                            $conn,
                            $sellerId,
                            $orderId,
                            $orderTotal
                        );
                }
                reserveOrderStockForAcceptance(
                    $conn,
                    $orderId
                );
                $stmt = $conn->prepare('
                    UPDATE orders
                    SET
                        order_status = "Accepted",
                        updated_at = NOW()
                    WHERE
                        order_id = ?
                        AND seller_id = ?
                        AND buyer_id = ?
                        AND order_status = "Pending"
                ');
                if (!$stmt) {
                    throw new RuntimeException(
                        'Unable to accept the order.'
                    );
                }
                $stmt->bind_param(
                    'iii',
                    $orderId,
                    $sellerId,
                    $buyerId
                );
                $stmt->execute();
                if ($stmt->affected_rows !== 1) {
                    $stmt->close();
                    throw new RuntimeException(
                        'The order status changed. '
                        . 'Refresh and try again.'
                    );
                }
                $stmt->close();
                $acceptedArrangementStatus = 'accepted';
                $stmt = $conn->prepare('
                    UPDATE order_arrangements
                    SET
                        arrangement_status = ?,
                        seller_accepted_at = NOW(),
                        locked_at = COALESCE(
                            locked_at,
                            NOW()
                        ),
                        updated_at = NOW()
                    WHERE
                        order_id = ?
                        AND arrangement_status =
                            "awaiting_seller_acceptance"
                ');
                if (!$stmt) {
                    throw new RuntimeException(
                        'Unable to finish the order details.'
                    );
                }
                $stmt->bind_param(
                    'si',
                    $acceptedArrangementStatus,
                    $orderId
                );
                $stmt->execute();
                if ($stmt->affected_rows !== 1) {
                    $stmt->close();
                    throw new RuntimeException(
                        'The order details changed. '
                        . 'Refresh and try again.'
                    );
                }
                $stmt->close();
                if ($paymentOption === 'paymongo') {
                    $messageText =
                        'Order #'
                        . $orderId
                        . ' was accepted by the seller. '
                        . 'Your item quantity is now reserved. '
                        . 'You can now pay using QR Ph.';
                } elseif ($paymentOption === 'cash') {
                    $messageText =
                        'Order #'
                        . $orderId
                        . ' was accepted by the seller. '
                        . 'Your item quantity is now reserved. '
                        . 'Please prepare cash for the agreed meetup or pickup.';
                } else {
                    $messageText =
                        'Order #'
                        . $orderId
                        . ' was accepted by the seller. '
                        . 'Your item quantity is now reserved.';
                }
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
                        'Unable to notify the buyer.'
                    );
                }
                $stmt->bind_param(
                    'iiis',
                    $sellerId,
                    $buyerId,
                    $orderId,
                    $messageText
                );
                $stmt->execute();
                $stmt->close();
                // Store the Buyer's status notification with the successful acceptance.
                // This runs only after Pending -> Accepted and never during polling.
                require_once __DIR__ . '/../config/notification_events.php';
                if ($paymentOption === 'paymongo') {
                    $notificationType = 'order_payment_required';
                    $notificationTitle = 'Payment Required';
                    $notificationMessage = 'Order #' . $orderId
                        . ' was accepted. Please pay using QR Ph to proceed.';
                } else {
                    $notificationType = 'order_accepted_cash';
                    $notificationTitle = 'Order Accepted';
                    $notificationMessage = 'Order #' . $orderId
                        . ' was accepted. Please prepare cash for the agreed meetup or pickup.';
                }
                lokalcraftCreateNotification(
                    $conn,
                    $buyerId,
                    $notificationType,
                    $notificationTitle,
                    $notificationMessage,
                    'order',
                    $orderId,
                    'order_' . $orderId . '_seller_accepted'
                );
                $conn->commit();
                $updatedOrder = getSellerOrderDetails(
                    $conn,
                    $sellerId,
                    $buyerId,
                    $orderId
                );
                $acceptedPaymentOption = chatStatusKey(
                    $updatedOrder['payment_option'] ?? ''
                );
                $acceptedMessage =
                    'Order accepted. Stock is now held for this order.';
                if ($acceptedPaymentOption === 'paymongo') {
                    $acceptedMessage .=
                        ' Waiting for the buyer to pay using QR Ph via PayMongo.';
                } elseif ($acceptedPaymentOption === 'cash') {
                    $acceptedMessage .=
                        ' Collect cash during the agreed meetup or pickup.';
                    if (is_array($cashFeeResult)) {
                        $acceptedMessage .=
                            ' A 5% platform fee of ₱'
                            . number_format(
                                (float)$cashFeeResult['fee'],
                                2
                            )
                            . ' was reserved from your LokalCraft wallet.'
                            . ' Remaining wallet balance: ₱'
                            . number_format(
                                (float)$cashFeeResult[
                                    'seller_balance_after'
                                ],
                                2
                            )
                            . '.';
                    }
                }
                echo json_encode([
                    'success' => true,
                    'message' => $acceptedMessage,
                    'order' => $updatedOrder,
                    'cash_platform_fee' =>
                        is_array($cashFeeResult)
                            ? (float)$cashFeeResult['fee']
                            : null,
                    'seller_wallet_balance' =>
                        is_array($cashFeeResult)
                            ? (float)$cashFeeResult[
                                'seller_balance_after'
                            ]
                            : null
                ]);
                exit;
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        }
        if ($action === 'send_message') {
            $buyerId = (int)($_POST['buyer_id'] ?? 0);
            $orderId = (int)($_POST['order_id'] ?? 0);
            $messageText = trim((string)($_POST['message_text'] ?? ''));
            $filePath = '';
            $attachmentUrl = '';
            if ($buyerId <= 0 || !conversationAllowed($conn, $sellerId, $buyerId)) {
                throw new RuntimeException('Buyer conversation is unavailable.');
            }
            if ($messageText === '' && empty($_FILES['attachment']['name'])) {
                throw new RuntimeException('Please enter a message or attach a file.');
            }
            if (chatTextLength($messageText) > 2000 || strlen($messageText) > 8000) {
                throw new RuntimeException('Message must be within 2,000 characters.');
            }
            if ($orderId > 0) {
                $orderStatus = sellerOrderStatus($conn, $sellerId, $buyerId, $orderId);
                if ($orderStatus === null) {
                    throw new RuntimeException('This order does not belong to this Buyer conversation.');
                }
                if (sellerOrderClosedStatus($orderStatus)) {
                    throw new RuntimeException('This order is closed. Send a normal Buyer message instead.');
                }
            }
            // A normal message has no order_id, even when an older order exists.
            // Never silently link it to the latest (possibly cancelled) transaction.
            if (!empty($_FILES['attachment']['name'])) {
                if (!isset($_FILES['attachment']['error']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('The attachment could not be uploaded.');
                }
                $attachmentSize = (int)($_FILES['attachment']['size'] ?? 0);
                if ($attachmentSize < 1 || $attachmentSize > 8 * 1024 * 1024) {
                    throw new RuntimeException('Attachments must be between 1 byte and 8 MB.');
                }
                $temporaryFile = (string)($_FILES['attachment']['tmp_name'] ?? '');
                if ($temporaryFile === '' || !is_uploaded_file($temporaryFile)) {
                    throw new RuntimeException('Invalid attachment upload.');
                }
                $allowedMimeTypes = [
                    'jpg' => ['image/jpeg'],
                    'jpeg' => ['image/jpeg'],
                    'png' => ['image/png'],
                    'gif' => ['image/gif'],
                    'webp' => ['image/webp'],
                    'pdf' => ['application/pdf'],
                    'doc' => ['application/msword', 'application/octet-stream'],
                    'docx' => [
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/zip',
                        'application/octet-stream'
                    ]
                ];
                $originalName = (string)$_FILES['attachment']['name'];
                $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                if (!isset($allowedMimeTypes[$extension])) {
                    throw new RuntimeException('This attachment type is not supported.');
                }
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = (string)$finfo->file($temporaryFile);
                if (!in_array($mimeType, $allowedMimeTypes[$extension], true)) {
                    throw new RuntimeException('The attachment file does not match its file type.');
                }
                $uploadDirectory = __DIR__ . '/../uploads/messages';
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                    throw new RuntimeException('Unable to create the attachment folder.');
                }
                $safeName = bin2hex(random_bytes(16)) . '.' . $extension;
                $filePath = $uploadDirectory . DIRECTORY_SEPARATOR . $safeName;
                if (!move_uploaded_file($temporaryFile, $filePath)) {
                    throw new RuntimeException('Unable to save the attachment.');
                }
                @chmod($filePath, 0644);
                $attachmentUrl = '../uploads/messages/' . $safeName;
            }
            $transactionOpen = false;
            try {
                if ($orderId > 0) {
                    // Serialize order-linked messages with Buyer cancellation / Seller rejection.
                    $conn->begin_transaction();
                    $transactionOpen = true;
                    $lock = $conn->prepare('SELECT order_status FROM orders
                        WHERE order_id = ? AND seller_id = ? AND buyer_id = ?
                        LIMIT 1 FOR UPDATE');
                    if (!$lock) throw new RuntimeException('Unable to check this order.');
                    $lock->bind_param('iii', $orderId, $sellerId, $buyerId);
                    $lock->execute();
                    $locked = $lock->get_result()->fetch_assoc();
                    $lock->close();
                    if (!$locked || sellerOrderClosedStatus($locked['order_status'])) {
                        throw new RuntimeException('This order was closed. Send your reply as a normal Buyer message.');
                    }
                }
                $insert = $conn->prepare('INSERT INTO messages
                    (sender_id, receiver_id, order_id, message_text, attachment, is_read, sent_at)
                    VALUES (?, ?, NULLIF(?, 0), ?, ?, 0, NOW())');
                if (!$insert) throw new RuntimeException('Unable to prepare the message.');
                $insert->bind_param('iiiss', $sellerId, $buyerId, $orderId, $messageText, $attachmentUrl);
                if (!$insert->execute()) {
                    $insert->close();
                    throw new RuntimeException('Unable to send the message.');
                }
                $messageId = (int)$insert->insert_id;
                $insert->close();
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
                    'sender_id' => $sellerId,
                    'receiver_id' => $buyerId,
                    'order_id' => $orderId,
                    'message_text' => $messageText,
                    'attachment' => $attachmentUrl,
                    'is_read' => 0,
                    'sent_at' => $sentAt,
                    'time' => timeLabel($sentAt),
                    'date_key' => dateOnly($sentAt),
                    'date_label' => dateLabel($sentAt)
                ]
            ]);
            exit;
        }
        if ($action === 'mark_read') {
            $buyerId = (int)($_POST['buyer_id'] ?? 0);
            if ($buyerId <= 0) {
                throw new RuntimeException('Invalid conversation.');
            }
            $includeDirect = sellerDirectThreadState(
                $conn,
                $sellerId,
                $buyerId
            ) === 1 || latestSellerOrderId($conn, $sellerId, $buyerId) > 0 ? 1 : 0;
            $stmt = $conn->prepare('
                UPDATE messages m
                LEFT JOIN orders o ON o.order_id = m.order_id
                SET m.is_read = 1
                WHERE m.sender_id = ?
                  AND m.receiver_id = ?
                  AND m.is_read = 0
                  AND (
                        (
                            m.order_id IS NOT NULL
                            AND o.seller_id = ?
                            AND o.buyer_id = ?
                        )
                        OR
                        (? = 1 AND m.order_id IS NULL)
                  )
            ');
            if (!$stmt) {
                throw new RuntimeException(
                    'Unable to update message status.'
                );
            }
            $stmt->bind_param(
                'iiiii',
                $buyerId,
                $sellerId,
                $sellerId,
                $buyerId,
                $includeDirect
            );
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true]);
            exit;
        }
        if ($action === 'fetch_messages') {
            $buyerId = (int)($_POST['buyer_id'] ?? 0);
            $afterId = (int)($_POST['after_id'] ?? 0);
            if ($buyerId <= 0) {
                throw new RuntimeException('Invalid conversation.');
            }
            if (!conversationAllowed($conn, $sellerId, $buyerId)) {
                throw new RuntimeException('Conversation not found.');
            }
            $messages = getMessages($conn, $sellerId, $buyerId, $afterId);
            echo json_encode([
                'success' => true,
                'messages' => $messages
            ]);
            exit;
        }
        if ($action === 'fetch_conversations') {
            $conversationList = getConversationSummaries($conn, $sellerId);
            echo json_encode([
                'success' => true,
                'conversations' => $conversationList
            ]);
            exit;
        }
        if ($action === 'typing') {
            $buyerId = (int)($_POST['buyer_id'] ?? 0);
            if ($buyerId <= 0) {
                throw new RuntimeException('Invalid conversation.');
            }
            if (!conversationAllowed($conn, $sellerId, $buyerId)) {
                throw new RuntimeException('Conversation not found.');
            }
$isTyping = (int)($_POST['is_typing'] ?? 0);
            $stmt = $conn->prepare('
                INSERT INTO chat_typing (sender_id, receiver_id, is_typing, updated_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    is_typing = VALUES(is_typing),
                    updated_at = NOW()
            ');
            if (!$stmt) {
                throw new RuntimeException('Unable to update typing status.');
            }
            $stmt->bind_param('iii', $sellerId, $buyerId, $isTyping);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true]);
            exit;
        }
        if ($action === 'fetch_typing') {
            $buyerId = (int)($_POST['buyer_id'] ?? 0);
            if ($buyerId <= 0) {
                throw new RuntimeException('Invalid conversation.');
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
            $stmt->bind_param('ii', $buyerId, $sellerId);
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
            error_log('LokalCraft seller messages request error: ' . $e->getMessage());
        }
        http_response_code($expectedError ? 400 : 500);
        echo json_encode([
            'success' => false,
            'message' => $expectedError
                ? $e->getMessage()
                : 'Unable to process the request right now. Please try again.'
        ]);
        exit;
    }
}
$errorMessage = '';
$requestedBuyerId = (int)($_GET['buyer_id'] ?? 0);
$requestedOrderId = (int)($_GET['order_id'] ?? 0);
// A Seller Orders deep-link targets the exact order, not whichever buyer
// conversation happens to have the latest activity.
if ($requestedOrderId > 0) {
    try {
        $linkedOrder = $conn->prepare('SELECT buyer_id FROM orders WHERE order_id = ? AND seller_id = ? LIMIT 1');
        if ($linkedOrder) {
            $linkedOrder->bind_param('ii', $requestedOrderId, $sellerId);
            $linkedOrder->execute();
            $linkedBuyer = $linkedOrder->get_result()->fetch_assoc();
            $linkedOrder->close();
            if ($linkedBuyer) {
                $requestedBuyerId = (int)$linkedBuyer['buyer_id'];
            } else {
                $requestedOrderId = 0;
            }
        } else {
            $requestedOrderId = 0;
        }
    } catch (Throwable $e) {
        error_log('LokalCraft Seller Messages order-link lookup: ' . $e->getMessage());
        $requestedOrderId = 0;
    }
}
try {
    $conversationList = getConversationSummaries($conn, $sellerId);
} catch (Throwable $e) {
    error_log(
        'LokalCraft seller messages load error: '
        . $e->getMessage()
    );
    $conversationList = [];
    $errorMessage = 'Unable to load customer conversations right now.';
}
$selectedBuyerId = 0;
if ($requestedBuyerId > 0) {
    foreach ($conversationList as $conversation) {
        if ((int)$conversation['buyer_id'] === $requestedBuyerId) {
            $selectedBuyerId = $requestedBuyerId;
            break;
        }
    }
}
$selectedBuyer = null;
foreach ($conversationList as $conversation) {
    if ((int)$conversation['buyer_id'] === $selectedBuyerId) {
        $selectedBuyer = $conversation;
        break;
    }
}
$initialMessages = [];
$selectedOrderId = 0;
$initialOrderDetails = null;
if ($selectedBuyerId > 0) {
    try {
        $initialMessages = getMessages($conn, $sellerId, $selectedBuyerId);
        if (
            $requestedOrderId > 0
            && conversationAllowed(
                $conn,
                $sellerId,
                $selectedBuyerId,
                $requestedOrderId
            )
        ) {
            $selectedOrderId = $requestedOrderId;
        } elseif ($selectedBuyer) {
            $selectedOrderId = (int)($selectedBuyer['latest_order_id'] ?? 0);
        }
        $initialOrderDetails = getSellerOrderDetails(
            $conn,
            $sellerId,
            $selectedBuyerId,
            $selectedOrderId
        );
        if ($initialOrderDetails) {
            $selectedOrderId = (int)$initialOrderDetails['order_id'];
        }
    } catch (Throwable $e) {
        error_log('LokalCraft seller messages conversation error: ' . $e->getMessage());
        $initialMessages = [];
        $initialOrderDetails = null;
        $errorMessage = 'Unable to load this conversation right now. Please refresh and try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>LokalCraft | Seller Messages</title>
  <style>
    :root {
      --bg: #f3f3f3;
      --text: #2d2d2d;
      --muted: #6e6e73;
      --line: #e2e1de;
      --orange: #ea8500;
      --orange-dark: #d87500;
      --incoming: #f1f1f1;
      --panel: #fff;
      --soft: #f7f6f4;
      --danger: #d64d4d;
    }
    * {
      box-sizing: border-box;
    }
    body {
      margin: 0;
      font-family: "Segoe UI", Arial, sans-serif;
      background: var(--bg);
      color: var(--text);
    }
    body.chat-mobile-open {
      overflow: hidden;
    }
    .page {
      display: block !important;
      min-height: 100vh;
      background: #f5f3f1;
    }
    .page > .content {
      position: relative !important;
      top: auto !important;
      right: auto !important;
      bottom: auto !important;
      left: auto !important;
      width: auto !important;
      max-width: none !important;
      min-height: 100vh !important;
      height: auto !important;
      margin: 0 0 0 240px !important;
      padding: 74px 26px 30px !important;
      overflow: visible !important;
      overflow-x: hidden !important;
    }
    .messages-wrap {
      width: 100%;
      max-width: 1200px;
      margin: 0 auto;
    }
    .page-title {
      margin: 0 0 5px;
      font-size: 2.05rem;
      font-weight: 800;
      letter-spacing: -.7px;
    }
    .messages-shell {
      display: grid;
      grid-template-columns: 330px minmax(0, 1fr);
      height: calc(100vh - 175px);
      min-height: 560px;
      overflow: hidden;
      border: 1px solid #d9d5d1;
      border-radius: 14px;
      background: rgba(255,255,255,.32);
    }
    .conversation-list {
      min-width: 0;
      overflow-y: auto;
      border-right: 1px solid #d9d5d1;
      background: rgba(255,255,255,.16);
    }
    .list-heading {
      position: sticky;
      top: 0;
      z-index: 2;
      padding: 17px 18px 12px;
      border-bottom: 1px solid #ebe6e1;
      background: rgba(250,249,248,.96);
      backdrop-filter: blur(8px);
      font-size: 1.05rem;
      font-weight: 800;
    }
    .conversation {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 13px 14px;
      border-top: 1px solid #e7e2dd;
      cursor: pointer;
      transition: .18s ease;
    }
    .conversation:hover,
    .conversation.active {
      background: #fff2c9;
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
      font-size: .82rem;
      font-weight: 700;
    }
    .conversation-main {
      min-width: 0;
      flex: 1;
    }
    .conversation-name {
      margin-bottom: 3px;
      font-size: .9rem;
      font-weight: 700;
    }
    .conversation-product,
    .conversation-preview {
      overflow: hidden;
      color: #6b6b6b;
      font-size: .72rem;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .conversation-preview {
      margin-top: 3px;
    }
    .conversation-time {
      color: #7b7b7b;
      font-size: .66rem;
      white-space: nowrap;
    }
    .badge {
      min-width: 20px;
      height: 20px;
      display: grid;
      place-items: center;
      border-radius: 50%;
      background: var(--orange);
      color: #fff;
      font-size: .66rem;
      font-weight: 700;
    }
    .chat-panel {
      display: flex;
      min-width: 0;
      min-height: 0;
      flex-direction: column;
      overflow: hidden;
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
      flex: 1;
    }
    .chat-header h2 {
      margin: 0 0 3px;
      font-size: 1.02rem;
      font-weight: 800;
    }
    .chat-header p {
      margin: 0;
      overflow: hidden;
      color: #707070;
      font-size: .74rem;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .typing-indicator {
      display: block;
      width: 100%;
      min-height: 16px;
      margin-top: 4px;
      color: #7a7a7a;
      font-size: .68rem;
      line-height: 16px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .order-summary {
      display: none;
      margin-top: 9px;
      padding: 9px 11px;
      border: 1px solid #e4ddd7;
      border-radius: 9px;
      background: rgba(255,255,255,.78);
    }
    .order-summary.show {
      display: block;
    }
    .order-summary-title {
      margin-bottom: 4px;
      font-size: .69rem;
      font-weight: 800;
      color: #434343;
    }
    .order-summary-line {
      display: flex;
      flex-wrap: wrap;
      gap: 8px 14px;
      color: #6a6a6a;
      font-size: .66rem;
    }
    .status-pill {
      display: inline-flex;
      align-items: center;
      min-height: 20px;
      padding: 2px 7px;
      border-radius: 999px;
      background: #f7eadb;
      color: #b26118;
      font-size: .62rem;
      font-weight: 700;
    }
    .chat-body {
      position: relative;
      flex: 1 1 auto;
      min-height: 0;
      overflow-y: auto;
      overflow-x: hidden;
      overscroll-behavior: contain;
      padding: 15px 18px 10px;
      scroll-behavior: smooth;
      scrollbar-gutter: stable;
    }
    .chat-body::-webkit-scrollbar {
      width: 8px;
    }
    .chat-body::-webkit-scrollbar-track {
      background: transparent;
    }
    .chat-body::-webkit-scrollbar-thumb {
      background: #d4d0cc;
      border-radius: 999px;
    }
    .chat-body::-webkit-scrollbar-thumb:hover {
      background: #bbb6b1;
    }
    .date-separator {
      display: flex;
      align-items: center;
      gap: 10px;
      margin: 6px 0 14px;
      color: #8a8a8a;
      font-size: .64rem;
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
      font-size: .8rem;
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
      background: rgba(255,255,255,.7);
      color: #2f2f2f;
      font-size: .7rem;
      font-weight: 700;
    }
    .outgoing .attachment-file {
      background: rgba(255,255,255,.9);
    }
    .message-time {
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: 5px;
      margin-top: 5px;
      font-size: .61rem;
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
      background: rgba(255,255,255,.7);
      color: #3d3d3d;
    }
    .outgoing .message-order-card {
      color: #333;
    }
    .chat-input-wrap {
      flex: 0 0 auto;
      padding: 9px 14px 13px;
      border-top: 1px solid #dfd9d4;
      background: rgba(255,255,255,.1);
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
      font-size: .67rem;
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
      max-height: 110px;
      min-height: 30px;
      resize: none;
      border: 0;
      outline: 0;
      background: transparent;
      color: #2d2d2d;
      font: inherit;
      font-size: .79rem;
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
    .empty-chat,
    .empty-conversations,
    .chat-error {
      display: none;
      place-items: center;
      text-align: center;
      color: #777;
      font-size: .8rem;
    }
    .empty-chat {
      height: 100%;
      min-height: 250px;
    }
    .empty-conversations {
      min-height: 180px;
      padding: 20px;
    }
    .empty-conversations.show,
    .empty-chat.show,
    .chat-error.show {
      display: grid;
    }
    .chat-error {
      margin: 10px 18px;
      min-height: 38px;
      padding: 8px 10px;
      border: 1px solid #efc7c7;
      border-radius: 8px;
      background: #fff3f3;
      color: var(--danger);
    }
    .no-selection {
      display: grid;
      place-items: center;
      height: 100%;
      min-height: 400px;
      padding: 30px;
      text-align: center;
      color: #777;
      font-size: .82rem;
    }
    @media (min-width: 761px) and (max-width: 1400px) {
      .page > main.content.messages-main {
        padding: 68px 20px 22px !important;
      }
      .messages-wrap {
        max-width: 1050px;
      }
      .page-title {
        font-size: 1.75rem;
      }
      .messages-shell {
        grid-template-columns: 270px minmax(0, 1fr);
        height: calc(100vh - 155px);
        min-height: 480px;
        border-radius: 12px;
      }
      .list-heading {
        padding: 13px 14px 10px;
        font-size: .85rem;
      }
      .conversation {
        gap: 9px;
        padding: 10px 11px;
      }
      .conversation .avatar,
      .chat-header .avatar {
        width: 36px;
        height: 36px;
        flex: 0 0 36px;
        font-size: .68rem;
      }
      .conversation-name {
        font-size: .75rem;
      }
      .conversation-product,
      .conversation-preview {
        font-size: .64rem;
      }
      .conversation-time {
        font-size: .58rem;
      }
      .chat-header {
        padding: 11px 14px 9px;
      }
      .chat-header h2 {
        font-size: .9rem;
      }
      .chat-header p {
        font-size: .66rem;
      }
      .typing-indicator {
        min-height: 16px;
        margin-top: 3px;
        font-size: .64rem;
        line-height: 16px;
      }
      .order-summary {
        margin-top: 6px;
        padding: 6px 8px;
      }
      .chat-body {
        padding: 12px 14px 8px;
      }
      .message-content {
        max-width: min(70%, 500px);
      }
      .bubble {
        padding: 8px 10px 7px;
        font-size: .74rem;
        line-height: 1.4;
      }
      .message-row {
        margin-bottom: 8px;
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
        flex: 0 0 28px;
      }
      .send-btn {
        width: 31px;
        height: 31px;
        flex: 0 0 31px;
      }
    }
    @media (min-width: 841px) and (max-width: 1100px) {
      .page > main.content.messages-main {
        margin-left: 210px !important;
        padding-left: 14px !important;
        padding-right: 14px !important;
      }
      .messages-wrap {
        max-width: 900px;
      }
      .messages-shell {
        grid-template-columns: 230px minmax(0, 1fr);
      }
      .conversation {
        padding: 9px;
      }
      .conversation .avatar {
        width: 33px;
        height: 33px;
        flex: 0 0 33px;
      }
      .message-content {
        max-width: 76%;
      }
    }
    @media (max-width: 760px) {
      .page > main.content.messages-main {
        position: relative !important;
        inset: auto !important;
        width: 100% !important;
        max-width: none !important;
        min-height: 100vh !important;
        height: auto !important;
        margin: 0 !important;
        padding: 74px 12px 18px !important;
        overflow: visible !important;
      }
      .messages-wrap {
        max-width: none;
      }
      .page-title {
        font-size: 1.6rem;
      }
      .messages-shell {
        display: block;
        height: calc(100vh - 205px);
        min-height: 0;
        border-radius: 12px;
      }
      .conversation-list {
        height: 100%;
        border-right: 0;
      }
      .chat-panel {
        display: none;
        height: 100%;
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
      .chat-input-wrap {
        padding-left: 10px;
        padding-right: 10px;
      }
    }
  

</style>
  <style id="sellerMessagesModernTheme">
    :root {
      --seller-msg-bg: #f4f3f1;
      --seller-msg-panel: #ffffff;
      --seller-msg-soft: #fbfaf8;
      --seller-msg-line: #e8e1da;
      --seller-msg-line-soft: #f0ebe6;
      --seller-msg-text: #303030;
      --seller-msg-muted: #777078;
      --seller-msg-accent: #ea8a3a;
      --seller-msg-accent-dark: #d87522;
      --seller-msg-accent-soft: #fff3e7;
      --seller-msg-danger: #bf4c4c;
      --seller-msg-shadow: 0 10px 28px rgba(59, 42, 27, .07);
      --keyboard-inset: 0px;
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
    body {
      background: var(--seller-msg-bg);
      color: var(--seller-msg-text);
      overflow: hidden;
    }
    .page {
      width: 100%;
      height: 100vh;
      height: 100dvh;
      min-height: 0 !important;
      overflow: hidden;
      background: var(--seller-msg-bg);
    }
    .page > main.content.messages-main {
      position: fixed !important;
      inset: 0 0 0 250px !important;
      width: auto !important;
      max-width: none !important;
      height: 100vh !important;
      height: 100dvh !important;
      min-height: 0 !important;
      margin: 0 !important;
      padding: 78px 0 0 !important;
      overflow: hidden !important;
    }
    .messages-wrap {
      width: 100%;
      max-width: none;
      height: 100%;
      min-height: 0;
      margin: 0;
      padding: 12px 14px 14px;
      overflow: hidden;
      background: var(--seller-msg-bg);
    }
    .page-title,
    .page-subtitle {
      display: none;
    }
    .messages-shell {
      display: grid;
      grid-template-columns: 340px minmax(0, 1fr);
      width: 100%;
      height: 100%;
      min-height: 0;
      overflow: hidden;
      border: 1px solid var(--seller-msg-line);
      border-radius: 18px;
      background: var(--seller-msg-panel);
      box-shadow: var(--seller-msg-shadow);
    }
    .conversation-list {
      display: flex;
      min-width: 0;
      min-height: 0;
      flex-direction: column;
      overflow: hidden;
      border-right: 1px solid var(--seller-msg-line);
      background: linear-gradient(180deg, #fff 0%, #fbfaf8 100%);
    }
    .list-heading {
      position: relative;
      top: auto;
      z-index: 3;
      flex: 0 0 auto;
      padding: 18px 18px 15px;
      border-bottom: 1px solid var(--seller-msg-line-soft);
      background: rgba(255,255,255,.97);
      color: var(--seller-msg-text);
      font-size: 1rem;
      font-weight: 800;
      letter-spacing: -.2px;
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
    }
    .list-heading::after {
      content: "";
      display: block;
      width: 34px;
      height: 4px;
      margin-top: 9px;
      border-radius: 999px;
      background: var(--seller-msg-accent);
    }
    #conversationItems {
      flex: 1 1 auto;
      min-height: 0;
      overflow-y: auto;
      overflow-x: hidden;
      padding: 7px;
      overscroll-behavior: contain;
      -webkit-overflow-scrolling: touch;
      scrollbar-width: thin;
      scrollbar-color: #d6cec7 transparent;
    }
    #conversationItems::-webkit-scrollbar {
      width: 7px;
    }
    #conversationItems::-webkit-scrollbar-track {
      background: transparent;
    }
    #conversationItems::-webkit-scrollbar-thumb {
      border-radius: 999px;
      background: #d6cec7;
    }
    .conversation {
      position: relative;
      display: flex;
      align-items: center;
      gap: 11px;
      margin: 4px 0;
      padding: 11px 12px;
      border: 1px solid transparent;
      border-radius: 14px;
      background: transparent;
      cursor: pointer;
      transition:
        background .18s ease,
        border-color .18s ease,
        box-shadow .18s ease,
        transform .18s ease;
    }
    .conversation:hover {
      border-color: #eee4da;
      background: #fff;
      box-shadow: 0 5px 15px rgba(55,39,25,.055);
      transform: translateY(-1px);
    }
    .conversation.active {
      border-color: #f2c79f;
      background: var(--seller-msg-accent-soft);
      box-shadow: 0 5px 15px rgba(234,138,58,.08);
    }
    .conversation.active::before {
      content: "";
      position: absolute;
      top: 11px;
      bottom: 11px;
      left: 4px;
      width: 4px;
      border-radius: 999px;
      background: var(--seller-msg-accent);
    }
    .avatar {
      width: 43px;
      height: 43px;
      flex: 0 0 43px;
      border: 2px solid rgba(255,255,255,.92);
      border-radius: 50%;
      background: linear-gradient(135deg, #f4b06d, #d56f32);
      color: #fff;
      box-shadow: 0 4px 12px rgba(213,111,50,.18);
      font-size: .75rem;
      font-weight: 800;
    }
    .conversation-name {
      margin-bottom: 3px;
      color: #34302d;
      font-size: .79rem;
      font-weight: 800;
    }
    .conversation-product,
    .conversation-preview {
      color: #7d7670;
      font-size: .67rem;
    }
    .conversation-time {
      color: #9a918a;
      font-size: .6rem;
    }
    .badge {
      min-width: 21px;
      height: 21px;
      padding: 0 6px;
      border: 2px solid #fff;
      border-radius: 999px;
      background: var(--seller-msg-accent);
      color: #fff;
      box-shadow: 0 2px 8px rgba(234,138,58,.23);
      font-size: .6rem;
      font-weight: 800;
    }
    .empty-conversations.show {
      display: grid;
      margin: 14px 10px;
      min-height: 150px;
      padding: 30px 18px;
      border: 1px dashed #e7d8cb;
      border-radius: 14px;
      background: #fffaf5;
      color: #7d736c;
    }
    .chat-panel {
      display: flex;
      min-width: 0;
      min-height: 0;
      flex-direction: column;
      overflow: hidden;
      background:
        radial-gradient(circle at top right, rgba(234,138,58,.07), transparent 28%),
        linear-gradient(180deg, #fff 0%, #fcfbfa 100%);
    }
    .chat-header {
      position: relative;
      z-index: 4;
      flex: 0 0 auto;
      padding: 13px 18px 11px;
      border-bottom: 1px solid var(--seller-msg-line-soft);
      background: rgba(255,255,255,.95);
      box-shadow: 0 3px 12px rgba(60,44,29,.035);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
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
      color: var(--seller-msg-accent-dark);
      font-weight: 650;
    }
    .chat-back {
      border: 1px solid var(--seller-msg-line);
      border-radius: 50%;
      background: #fff;
      color: #675f59;
      box-shadow: 0 3px 10px rgba(0,0,0,.05);
    }
    .chat-back:hover {
      border-color: #efc79f;
      background: var(--seller-msg-accent-soft);
      color: var(--seller-msg-accent-dark);
    }
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
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .045em;
    }
    .order-summary-line {
      gap: 7px 12px;
      color: #6f6257;
      font-size: .62rem;
    }
    .status-pill {
      min-height: 22px;
      padding: 3px 9px;
      border: 1px solid #f1cfaa;
      border-radius: 999px;
      background: #fff;
      color: #aa5e20;
      box-shadow: 0 2px 7px rgba(234,138,58,.07);
      font-size: .58rem;
      font-weight: 800;
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
      padding: 18px 20px 13px;
      background:
        linear-gradient(180deg, rgba(250,249,247,.58), rgba(255,255,255,.82));
      scrollbar-width: thin;
      scrollbar-color: #d6cec7 transparent;
    }
    .chat-body::-webkit-scrollbar {
      width: 7px;
    }
    .chat-body::-webkit-scrollbar-track {
      background: transparent;
    }
    .chat-body::-webkit-scrollbar-thumb {
      border-radius: 999px;
      background: #d6cec7;
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
      box-shadow: 0 3px 10px rgba(50,38,29,.045);
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
      box-shadow: 0 5px 15px rgba(226,125,44,.15);
    }
    .message-time {
      margin-top: 5px;
      font-size: .58rem;
      opacity: .72;
    }
    .attachment,
    .attachment img,
    .attachment-file {
      border-radius: 12px;
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
    .chat-input-wrap {
      position: relative;
      z-index: 5;
      flex: 0 0 auto;
      padding: 8px 14px max(11px, env(safe-area-inset-bottom));
      border-top: 1px solid var(--seller-msg-line-soft);
      background: rgba(255,255,255,.99);
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
      flex: 0 0 36px;
      border-radius: 50%;
      background: #fff;
      color: #8b7d72;
      box-shadow: 0 2px 7px rgba(0,0,0,.045);
      transition: .17s ease;
    }
    .attach-label:hover {
      background: var(--seller-msg-accent-soft);
      color: var(--seller-msg-accent-dark);
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
      flex: 0 0 36px;
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
    .no-selection {
      width: min(430px, calc(100% - 40px));
      height: auto;
      min-height: 0;
      margin: auto;
      padding: 38px 26px;
      border: 1px dashed #e6d9cd;
      border-radius: 18px;
      background: rgba(255,250,245,.82);
      color: #847c75;
    }
    .empty-chat.show {
      display: grid;
      width: min(430px, calc(100% - 30px));
      height: auto;
      min-height: 150px;
      margin: auto;
      padding: 30px 20px;
      border: 1px dashed #e6d9cd;
      border-radius: 16px;
      background: rgba(255,250,245,.82);
      color: #847c75;
    }
    .chat-error {
      border-radius: 11px;
      box-shadow: 0 3px 10px rgba(191,76,76,.06);
    }
    @media (min-width: 841px) and (max-width: 1400px) {
      .messages-wrap {
        padding: 9px 10px 11px;
      }
      .messages-shell {
        grid-template-columns: 285px minmax(0, 1fr);
        border-radius: 15px;
      }
      .conversation {
        border-radius: 12px;
      }
    }
    @media (min-width: 841px) and (max-width: 1100px) {
      .page > .content {
        inset: 0 0 0 210px !important;
      }
      .messages-shell {
        grid-template-columns: 235px minmax(0, 1fr);
      }
      .message-content {
        max-width: 78%;
      }
    }
    @media (max-width: 840px) {
      .page > main.content.messages-main {
        inset: 0 !important;
      }
    }
    @media (max-width: 760px) {
      body {
        overflow: hidden;
      }
      .page,
      .page > main.content.messages-main {
        width: 100% !important;
        min-height: 0 !important;
        height: 100vh !important;
        height: 100dvh !important;
      }
      .page > main.content.messages-main {
        position: fixed !important;
        inset: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow: hidden !important;
      }
      .messages-wrap {
        position: fixed;
        top: 0;
        right: 0;
        bottom: var(--keyboard-inset);
        left: 0;
        width: auto;
        height: auto;
        min-height: 0;
        padding: 0;
        overflow: hidden;
      }
      .messages-shell {
        display: block;
        width: 100%;
        height: 100%;
        min-height: 0;
        border: 0;
        border-top: 1px solid var(--seller-msg-line);
        border-radius: 0;
        box-shadow: none;
      }
      .conversation-list {
        height: 100%;
        border-right: 0;
      }
      .list-heading {
        padding: 15px 14px 12px;
      }
      #conversationItems {
        padding: 6px;
      }
      .conversation {
        margin: 3px 0;
        padding: 10px 11px;
        border-radius: 13px;
      }
      .conversation.active::before {
        top: 9px;
        bottom: 9px;
      }
      .chat-panel {
        display: none;
        height: 100%;
        min-height: 0;
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
      .chat-header {
        padding-left: max(12px, env(safe-area-inset-left));
        padding-right: max(12px, env(safe-area-inset-right));
      }
      .chat-body {
        padding: 13px 12px 9px;
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
      .chat-input-wrap {
        padding-left: max(10px, env(safe-area-inset-left));
        padding-right: max(10px, env(safe-area-inset-right));
        padding-bottom: max(10px, env(safe-area-inset-bottom));
      }
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
  <style id="sellerArrangementStyles">
    .order-summary-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }
    .order-summary-head > div:first-child {
      min-width: 0;
      flex: 1;
    }
    .arrange-order-btn {
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
    .arrange-order-btn:hover {
      border-color: #e99b52;
      background: #fff3e7;
      transform: translateY(-1px);
    }
    .arrange-order-btn.accept-ready {
      border-color: #43a16c;
      background: #eef9f2;
      color: #28754a;
      box-shadow: 0 4px 11px rgba(47,138,88,.10);
    }
    .arrange-order-btn.accept-ready:hover {
      border-color: #2f8a58;
      background: #e5f6ec;
      color: #246b44;
    }
    .arrangement-dialog {
      width: min(760px, calc(100% - 28px));
      max-height: min(88dvh, 820px);
      margin: auto;
      padding: 0;
      overflow: hidden;
      border: 0;
      border-radius: 20px;
      background: #fff;
      color: #303030;
      box-shadow: 0 28px 90px rgba(41, 28, 18, .28);
    }
    .arrangement-dialog::backdrop {
      background: rgba(32, 25, 20, .48);
      backdrop-filter: blur(3px);
      -webkit-backdrop-filter: blur(3px);
    }
    .arrangement-form {
      max-height: min(88dvh, 820px);
      overflow-y: auto;
      overscroll-behavior: contain;
      -webkit-overflow-scrolling: touch;
      padding: 22px;
    }
    .arrangement-dialog-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 16px;
    }

    .arrangement-dialog h2 {
      margin: 0;
      color: #302d2a;
      font-size: 1.25rem;
      letter-spacing: -.3px;
    }
    .arrangement-dialog-head p {
      margin: 6px 0 0;
      color: #817970;
      font-size: .74rem;
      line-height: 1.5;
    }
    .arrangement-close {
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

    .arrangement-status-pill {
      flex: 0 0 auto;
      padding: 6px 9px;
      border: 1px solid #efc79f;
      border-radius: 999px;
      background: #fff;
      color: #a75b1d;
      font-size: .6rem;
      font-weight: 800;
    }
    .arrangement-items {
      display: grid;
      gap: 7px;
      margin-bottom: 12px;
    }
    .arrangement-items[hidden] {
      display: none !important;
    }
    .arrangement-item-row {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 10px;
      padding: 9px 10px;
      border: 1px solid #eee7e0;
      border-radius: 11px;
      background: #fbfaf8;
    }
    .arrangement-item-name {
      color: #4b433d;
      font-size: .68rem;
      font-weight: 800;
    }
    .arrangement-item-meta {
      margin-top: 2px;
      color: #8b8179;
      font-size: .6rem;
    }

    .arrangement-fields {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 11px;
    }
    .arrangement-field {
      display: flex;
      min-width: 0;
      flex-direction: column;
      gap: 6px;
    }
    .arrangement-field-wide {
      grid-column: 1 / -1;
    }
    .arrangement-field > span {
      color: #655d57;
      font-size: .68rem;
      font-weight: 750;
    }
    .arrangement-field > span b {
      color: #d87522;
    }
    .arrangement-field input,
    .arrangement-field select,
    .arrangement-field textarea {
      width: 100%;
      min-height: 40px;
      padding: 9px 10px;
      border: 1px solid #ded7d0;
      border-radius: 10px;
      outline: 0;
      background: #fbfaf9;
      color: #35312e;
      font: inherit;
      font-size: .74rem;
      transition: .17s ease;
    }
    .arrangement-field textarea {
      min-height: 78px;
      resize: vertical;
      line-height: 1.45;
    }
    .arrangement-field input:focus,
    .arrangement-field select:focus,
    .arrangement-field textarea:focus {
      border-color: #edaa69;
      background: #fff;
      box-shadow: 0 0 0 3px rgba(234,138,58,.10);
    }
    .arrangement-field input:disabled,
    .arrangement-field select:disabled,
    .arrangement-field textarea:disabled {
      background: #f3f1ef;
      color: #8a827c;
      cursor: not-allowed;
    }

    .arrangement-bottom-feedback {
      display: none;
      margin-top: 12px;
      padding: 10px 12px;
      border: 1px solid transparent;
      border-radius: 11px;
      font-size: .7rem;
      font-weight: 700;
      line-height: 1.45;
    }
    .arrangement-bottom-feedback.show {
      display: block;
    }
    .arrangement-bottom-feedback.error {
      border-color: #efc7c7;
      background: #fff3f3;
      color: #a84444;
    }
    .arrangement-bottom-feedback.success {
      border-color: #c7e5d1;
      background: #effaf3;
      color: #247144;
    }
    .arrangement-field.invalid > span {
      color: #b84343;
    }
    .arrangement-field.invalid input, .arrangement-field.invalid select, .arrangement-field.invalid textarea{
      border-color: #df7777 !important;
      background: #fff7f7 !important;
      box-shadow: 0 0 0 3px rgba(191, 76, 76, .08) !important;
    }
    #arrangementDialog .seller-datetime-error{display:block;margin-top:2px;color:#b84343;font-size:.68rem;font-weight:650;line-height:1.4;overflow-wrap:anywhere}
    #arrangementDialog .seller-datetime-error[hidden]{display:none!important}
    .arrangement-actions {
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      margin-top: 16px;
      padding-top: 14px;
      border-top: 1px solid #eee8e2;
    }
    .arrangement-action {
      min-height: 38px;
      padding: 8px 14px;
      border-radius: 10px;
      font: inherit;
      font-size: .7rem;
      font-weight: 800;
      cursor: pointer;
      transition: .17s ease;
    }
    .arrangement-action.secondary {
      border: 1px solid #ded7d0;
      background: #fff;
      color: #655d57;
    }
    .arrangement-action.primary {
      border: 1px solid #df792b;
      background: linear-gradient(135deg, #ee9348, #df792b);
      color: #fff;
      box-shadow: 0 5px 13px rgba(221,118,39,.17);
    }
    .arrangement-action.accept {
      border: 1px solid #2f8a58;
      background: linear-gradient(135deg, #45a66f, #2f8a58);
      color: #fff;
      box-shadow: 0 5px 13px rgba(47,138,88,.18);
    }
    .arrangement-action.accept:hover:not(:disabled) {
      box-shadow: 0 7px 16px rgba(47,138,88,.24);
    }
    .arrangement-action:hover:not(:disabled) {
      transform: translateY(-1px);
    }
    .arrangement-action:disabled {
      opacity: .5;
      cursor: not-allowed;
      transform: none;
      box-shadow: none;
    }
    @media (max-width: 760px) {
      .order-summary-head {
        align-items: flex-start;
      }
      .arrange-order-btn {
        min-height: 30px;
        padding: 6px 9px;
        font-size: .58rem;
      }
      .arrangement-dialog {
        width: calc(100% - 16px);
        max-height: calc(100dvh - 18px);
        border-radius: 17px;
      }
      .arrangement-form {
        max-height: calc(100dvh - 18px);
        padding:
          max(16px, env(safe-area-inset-top))
          max(14px, env(safe-area-inset-right))
          max(16px, env(safe-area-inset-bottom))
          max(14px, env(safe-area-inset-left));
      }
      .arrangement-dialog h2 {
        font-size: 1.08rem;
      }

      .arrangement-fields {
        grid-template-columns: 1fr;
      }
      .arrangement-field-wide {
        grid-column: auto;
      }
      .arrangement-field input,
      .arrangement-field select,
      .arrangement-field textarea {
        font-size: 16px;
      }
      .arrangement-bottom-feedback {
        position: relative;
        z-index: 4;
        margin-bottom: 8px;
      }
      .arrangement-actions {
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
      .arrangement-action {
        flex: 1;
        padding-right: 8px;
        padding-left: 8px;
      }
    }

    .seller-message-notice {
      margin: 0 0 7px;
      padding: 8px 11px;
      border: 1px solid #efc7c7;
      border-radius: 9px;
      background: #fff3f3;
      color: #a84444;
      font-size: .69rem;
      line-height: 1.45;
    }
    .seller-message-notice[hidden] { display: none !important; }
    /* Compact action cues: no extra modals or long policy paragraphs. */
    .seller-next-action {
      display: flex;
      align-items: flex-start;
      gap: 7px;
      margin-top: 9px;
      padding: 9px 11px;
      border: 1px solid #e9e3dc;
      border-radius: 10px;
      background: #faf8f5;
      color: #756a60;
      font-size: .68rem;
      line-height: 1.5;
    }
    .seller-next-action::before {
      content: 'i';
      display: grid;
      place-items: center;
      width: 16px;
      height: 16px;
      flex: 0 0 16px;
      border: 1px solid currentColor;
      border-radius: 50%;
      font-size: .61rem;
      font-weight: 800;
    }
    .seller-next-action[data-tone="ready"] {
      border-color: #c9e5d5;
      background: #f1faf4;
      color: #26744b;
    }
    .seller-next-action[data-tone="warning"] {
      border-color: #efd6b7;
      background: #fff9ed;
      color: #936320;
    }
    .seller-next-action[data-tone="closed"] {
      border-color: #e4e1dc;
      background: #f6f5f3;
      color: #77736d;
    }
    .seller-next-action[hidden] { display: none !important; }
    .seller-action-readiness {
      margin-top: 12px;
      padding: 10px 12px;
      border: 1px solid #e6e2dc;
      border-radius: 10px;
      background: #fcfaf8;
      color: #736b63;
      font-size: .68rem;
      line-height: 1.55;
    }
    .seller-action-readiness[data-tone="ready"] {
      border-color: #c4e4d0;
      background: #f0faf4;
      color: #26744b;
    }
    .seller-action-readiness[data-tone="warning"] {
      border-color: #efd6b7;
      background: #fff8ef;
      color: #916220;
    }
    .seller-action-readiness[hidden] { display: none !important; }
    @media (max-width: 760px) {
      .seller-next-action { font-size: .65rem; margin-top: 7px; }
      .seller-action-readiness { font-size: .66rem; }
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
    .buyer-arrangement-item, .arrangement-item-row { display:flex; gap:10px; align-items:center; }
    .order-modal-photo { flex:0 0 55px; width:55px; height:55px; object-fit:cover; border-radius:10px; background:#ede8e3; }
    .order-modal-photo.blank { display:grid;place-items:center; font-size:22px; }
    .buyer-arrangement-dialog .buyer-arrangement-card, .arrangement-dialog .arrangement-form { max-height:calc(100dvh - 24px); overflow-y:auto; }
    .arrangement-field input[type="date"], .arrangement-field input[type="time"] { color-scheme:light; cursor:pointer; }
    @media(max-width:500px) { .message-order-card.order-chat-card { width:min(300px,100%); gap:9px; } .order-chat-photo { width:56px;height:56px;flex-basis:56px; } }

    /* Order-only visual polish: Seller terracotta, clear confirmed total, stable dialog controls. */
    .message-order-card.order-chat-card { border-color:#dfd4cb; border-radius:16px; background:#fffdfa; box-shadow:0 7px 20px rgba(71,50,35,.065); }
    .message-order-card.order-chat-card.is-history { opacity:.8; box-shadow:none; background:#f9f8f6; }
    .order-chat-kicker { color:#8e6246; }
    .order-chat-action { display:flex; align-items:center; justify-content:space-between; gap:8px; min-height:38px; padding:9px 11px; border-color:#debc9e; background:#f9eee3; color:#795038; }
    .order-chat-action::after { content:'›'; font-size:19px; line-height:12px; font-weight:600; }
    .order-chat-action:focus-visible { outline:2px solid #ba875c; }
    .arrangement-dialog { width:min(790px,calc(100% - 20px)); max-height:calc(100dvh - 24px); overflow:hidden; border:1px solid #e7d9cc; }
    .arrangement-form { max-height:calc(100dvh - 24px); overflow-y:auto; overflow-x:hidden; overscroll-behavior:contain; scroll-padding-top:80px; scrollbar-gutter:stable; padding:20px 20px 22px; }
    .arrangement-dialog-head { position:sticky; top:-20px; z-index:20; margin:-20px -20px 15px; padding:18px 20px 15px; background:rgba(255,253,250,.97); border-bottom:1px solid #eae0d6; backdrop-filter:blur(12px); -webkit-backdrop-filter:blur(12px); }
    .arrangement-close { display:grid; place-items:center; flex:0 0 40px; width:40px; height:40px; background:#fff; border:1px solid #decbba; color:#74513b; box-shadow:0 4px 15px rgba(65,42,26,.08); }
    .arrangement-close:hover,.arrangement-close:focus-visible { background:#f7eadf; outline:2px solid #c99c78; outline-offset:2px; }
    .arrangement-field[hidden]{ display:none!important; }

    .arrangement-actions { position:sticky; bottom:-22px; z-index:19; margin:17px -20px -22px; padding:14px 20px max(16px,env(safe-area-inset-bottom)); border-top:1px solid #eae0d6; background:rgba(255,253,250,.98); box-shadow:0 -8px 18px rgba(75,52,36,.04); backdrop-filter:blur(12px); -webkit-backdrop-filter:blur(12px); flex-wrap:wrap; }
    .arrangement-actions button:not([hidden]) { min-width:125px; }
    @media(max-width:540px) {
      .arrangement-dialog { width:calc(100% - 12px); max-height:calc(100dvh - 12px); }
      .arrangement-form { max-height:calc(100dvh - 12px); padding:14px 14px 18px; scrollbar-gutter:auto; }
      .arrangement-dialog-head { top:-14px; margin:-14px -14px 12px; padding:14px 14px 12px; }
      .arrangement-actions { bottom:-18px; margin:14px -14px -18px; padding:12px 14px max(14px,env(safe-area-inset-bottom)); }
    }
    /* Terminal order state is visible without opening the details popup. */
    .chat-order-closed:not([hidden]) { display:flex; align-items:center; flex-wrap:wrap; gap:6px 10px; padding:10px 16px; border-top:1px solid #e5dfd9; background:#f7f4f1; color:#62564d; font-size:11px; line-height:1.45; }
    .chat-order-closed strong { color:#a14e3c; font-size:11px; }
    .chat-order-closed span { flex:1 1 190px; }
    .message-order-card.is-closed-order { background:#faf8f6; border-color:#e7dbd2; box-shadow:none; }
    .order-chat-status.is-closed { align-self:flex-start; padding:4px 8px; border:1px solid #ead3ce; background:#fff2f0; color:#a34e41; font-weight:800; font-size:10px; border-radius:999px; }
    .order-chat-reason { margin-top:4px; padding:7px 9px; border-radius:8px; background:#f5f1ed; color:#62554b; font-size:11px; line-height:1.5; overflow-wrap:anywhere; white-space:pre-wrap; }
    .message-order-card.is-closed-order .order-chat-action { background:#f2efec; border-color:#ded8d1; color:#635a53; }

    /* Seller Messages: clearer order summary, roomier form, consistent orange/ivory surfaces. */
    .messages-shell {
      border: 1px solid #e9dfd6;
      border-radius: 20px;
      box-shadow: 0 12px 36px rgba(51, 36, 23, .06);
    }
    .conversation-list { background: #fffdfa; }
    .conversation { border-bottom-color: #f0e9e2; border-radius: 0; }
    .conversation:hover { background: #fff8f1; }
    .conversation.active { background: #fff0df; box-shadow: inset 3px 0 #e58a40; }
    .conversation:focus-visible { outline: 2px solid #dd914e; outline-offset: -2px; }
    .chat-header { background: #fffdfa; }
    .chat-body { background: linear-gradient(180deg, #fdfbf8, #f7f5f1); }
    .chat-input-wrap { background: #fffdfa; }
    .arrangement-dialog {
      border: 1px solid #eaded2;
      border-radius: 20px;
      background: #fff;
      box-shadow: 0 30px 90px rgba(47,31,17,.22);
    }
    .arrangement-form { min-width: 0; }
    .arrangement-dialog-head { background: rgba(255,253,250,.98); }

    .arrangement-fields { column-gap: 15px; row-gap: 16px; }
    .arrangement-field { min-width: 0; gap: 8px; }
    .arrangement-field > span { font-size: .74rem; color: #4b4037; }
    .arrangement-field input,
    .arrangement-field select,
    .arrangement-field textarea {
      display: block;
      min-width: 0;
      max-width: 100%;
      min-inline-size: 0;
      max-inline-size: 100%;
      min-height: 46px;
      padding: 11px 13px;
      border-color: #ded8d1;
      background: #fff;
      font-size: .82rem;
    }
    .arrangement-field input[type=date],
    .arrangement-field input[type=time] {
      box-sizing: border-box;
      inline-size: 100%;
      max-inline-size: 100%;
      min-inline-size: 0;
      overflow: hidden;
      color-scheme: light;
    }
    .arrangement-field input[type=date]::-webkit-date-and-time-value,
    .arrangement-field input[type=time]::-webkit-date-and-time-value {
      min-width: 0;
      text-align: left;
    }


    .arrangement-field textarea { min-height: 84px; }

    .seller-action-readiness {
      margin-top: 16px;
      padding: 9px 12px;
      border-radius: 10px;
      font-size: .71rem;
    }
    .arrangement-actions { gap: 10px; }
    .arrangement-action { min-height: 43px; font-size: .75rem; }
    @media (max-width: 600px) {

      .arrangement-fields { grid-template-columns: minmax(0, 1fr); gap: 14px; }

      .arrangement-dialog, .arrangement-form { max-width: 100%; }
      .arrangement-form { box-sizing: border-box; overflow-x: hidden; }
      .arrangement-field, .arrangement-field-wide{ min-width: 0; max-width: 100%; }
      .arrangement-field input[type=date],
      .arrangement-field input[type=time] {
        display: block;
        width: 100%;
        min-width: 0;
        max-width: 100%;
        font-size: 16px;
      }
      .arrangement-dialog-head { gap: 10px; }
    }
    @media (max-width: 360px) {

      .arrangement-actions button:not([hidden]) { min-width: 0; }
    }
  </style>

  <style id="sellerMessagesBuyerAlignedAndSafariFix">
    /* Seller inbox: share the compact, scannable layout of Buyer Messages. */
    #conversationItems { padding: 8px; }
    #conversationItems .conversation {
      display: flex;
      align-items: center;
      width: 100%;
      min-width: 0;
      margin: 2px 0;
      padding: 13px 12px;
      gap: 11px;
      border: 1px solid transparent;
      border-radius: 12px;
      background: transparent;
      color: inherit;
      text-align: left;
      font: inherit;
      cursor: pointer;
      box-shadow: none;
      transform: none;
      -webkit-tap-highlight-color: transparent;
    }
    #conversationItems .conversation:hover { border-color: #f0e6dc; background: #fff9f3; box-shadow: none; }
    #conversationItems .conversation.active {
      border-color: #f2d6ba;
      background: #fff4e9;
      box-shadow: none;
    }
    #conversationItems .conversation.active::before { top: 10px; bottom: 10px; left: 1px; width: 3px; }
    #conversationItems .conversation:focus-visible { outline: 2px solid #d78540; outline-offset: -2px; }
    #conversationItems .conversation-main { min-width: 0; flex: 1; display: flex; flex-direction: column; gap: 5px; }
    #conversationItems .conversation-name-row { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; min-width: 0; }
    #conversationItems .conversation-name { margin: 0; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .79rem; font-weight: 800; color: #342f2a; }
    #conversationItems .conversation-time { flex: 0 0 auto; white-space: nowrap; font-size: .62rem; color: #827971; }
    #conversationItems .conversation-product, #conversationItems .conversation-preview { display: block; margin: 0; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; line-height: 1.35; font-size: .7rem; color: #766d66; }
    #conversationItems .conversation-preview { color: #91877e; }
    #conversationItems .badge { flex: 0 0 auto; font-size: .62rem; align-self: center; }
    .conversation-list { background: #fff; }
    .list-heading { padding: 19px 20px 16px; background: #fff; color: #312c29; }
    .list-heading::after { display: none; }
    .chat-panel { background: #fbfaf8; }
    .chat-header { background: #fff; border-bottom-color: #efe9e3; box-shadow: none; }
    .chat-header-copy h2 { font-size: .98rem; font-weight: 800; }
    .chat-header-copy p { font-size: .74rem; color: #8c8178; }
    .chat-body { background: #fbfaf8; }
    .chat-input-wrap { border-top: 1px solid #eee7df; background: #fff; }
    .messages-shell { border-color: #e6ded7; box-shadow: 0 7px 25px rgba(36,27,19,.04); }

    /* Native date/time controls stay visible and tappable on Safari and Chromium.
       No transparent overlay or scripted showPicker(): iOS handles the picker itself. */
    .arrangement-fields, .arrangement-field { min-width: 0; min-inline-size: 0; }
    .seller-native-picker {
      position: relative;
      display: flex;
      align-items: center;
      width: 100%;
      min-width: 0;
      max-width: 100%;
      min-inline-size: 0;
      min-height: 46px;
      padding: 0;
      border: 1px solid #ded8d1;
      border-radius: 11px;
      overflow: hidden;
      background: #fff;
      color: #332d29;
    }
    .seller-native-picker:focus-within {
      border-color: #df934f;
      box-shadow: 0 0 0 3px rgba(234,138,58,.11);
    }
    .seller-native-picker.is-disabled { background: #f3f1ee; color: #aaa19a; }
    .arrangement-field.invalid .seller-native-picker {
      border-color: #df7777 !important;
      background: #fff7f7;
      box-shadow: 0 0 0 3px rgba(191,76,76,.08);
    }
    #arrangementDialog .arrangement-field .seller-native-picker input[type="date"],
    #arrangementDialog .arrangement-field .seller-native-picker input[type="time"] {
      position: relative !important;
      inset: auto !important;
      display: block;
      flex: 1 1 auto;
      width: 100% !important;
      max-width: 100% !important;
      min-width: 0 !important;
      min-inline-size: 0 !important;
      min-height: 44px !important;
      height: 44px !important;
      margin: 0 !important;
      padding: 9px 12px !important;
      border: 0 !important;
      border-radius: 0 !important;
      background: transparent !important;
      color: #332d29 !important;
      opacity: 1 !important;
      color-scheme: light;
      font-size: 16px !important;
      font-weight: 400;
      line-height: normal;
      cursor: pointer;
      /* Keep the browser's native picker affordance for iOS/macOS Safari. */
    }
    #arrangementDialog .seller-native-picker input:disabled {
      color: #877b70 !important;
      cursor: not-allowed;
    }
    #arrangementDialog .seller-native-picker input::-webkit-date-and-time-value {
      min-width: 0;
      text-align: left;
    }
    #arrangementDialog .seller-native-picker input::-webkit-calendar-picker-indicator {
      opacity: 1;
      cursor: pointer;
    }
    .arrangement-dialog { max-width: calc(100vw - 12px); }
    .arrangement-form { min-width: 0; width: 100%; box-sizing: border-box; overflow-x: clip; }

    .arrangement-field > span:first-child { line-height: 1.4; }
    @media (max-width: 760px) {
      .arrangement-form { overflow-x: hidden; }
      .arrangement-fields { grid-template-columns: minmax(0, 1fr); }
      .arrangement-fields > * { min-width: 0; max-width: 100%; }
      .seller-native-picker { max-width: 100%; width: 100%; }
      .chat-header { padding-top: 14px; padding-bottom: 13px; }
      #conversationItems .conversation { padding: 13px 12px; }
      .list-heading { padding: 16px; }
    }
    @media (max-width: 360px) {
      .arrangement-dialog { max-width: calc(100vw - 8px); }

      .seller-native-picker { min-height: 46px; padding: 0; }
    }
  </style>

  <style id="sellerMessageProfileAlignment">
    /* One final Seller Profile design layer. Prior controls and IDs stay unchanged. */
    .page > main.content.messages-main, .messages-wrap { background:#f7f6f4; }
    @media(min-width:761px) {
      .page > main.content.messages-main { padding-top:70px !important; }
      .messages-wrap { padding:8px 14px 12px; }
    }
    .messages-shell { border:1px solid #ebe3dc; border-radius:17px; background:#fff; box-shadow:0 9px 25px rgba(52,36,23,.045); }
    .conversation-list { background:#fff; border-right-color:#efe8e1; }
    .list-heading { display:flex;align-items:center;gap:12px;min-height:56px;padding:14px 19px;border-bottom:1px solid #eee8e2;background:#fff;color:#40352d;font-size:15px;font-weight:800; }
    .list-heading-title { min-width:0; }
    .chat-back svg { display:block;width:18px;height:18px;flex:0 0 18px; }
    .chat-back:focus-visible { outline:2px solid #d88743;outline-offset:2px; }
    #conversationItems { padding:8px 9px; }
    #conversationItems .conversation { min-height:74px;gap:12px;padding:12px 11px;margin:3px 0;border-radius:13px;border:1px solid transparent;transition:background-color .14s ease,border-color .14s ease; }
    #conversationItems .conversation:hover { background:#fff8f2;border-color:#f2e6db; }
    #conversationItems .conversation.active { background:#fff3e7;border-color:#f0d7bf;box-shadow:inset 3px 0 #ea8a3a; }
    #conversationItems .conversation.active::before { content:none; }
    #conversationItems .conversation-main { gap:4px; }
    #conversationItems .conversation-name { font-size:13px;font-weight:750;color:#44372e; }
    #conversationItems .conversation-time { color:#98897d;font-size:11px;font-weight:450; }
    #conversationItems .conversation-product { color:#aa6f3a;font-weight:650;font-size:12px; }
    #conversationItems .conversation-preview { color:#887d72;font-size:12px;font-weight:400; }
    #conversationItems .conversation .avatar,.chat-header .avatar {
      display:grid;place-items:center;flex:0 0 43px;width:43px;height:43px;min-width:43px;min-height:43px;
      padding:0;line-height:1;aspect-ratio:1 / 1;box-sizing:border-box;overflow:hidden;border-radius:50% !important;
      border:2px solid #fff;background:linear-gradient(140deg,#f4b474,#d77a3e);box-shadow:0 4px 12px rgba(182,98,36,.15);
      color:#fff;font-size:12px;font-weight:750;
    }
    .chat-header .avatar { flex-basis:45px;width:45px;height:45px;min-width:45px;min-height:45px; }
    .chat-panel { background:#fcfbf9; }
    .chat-header { background:#fff;border-bottom:1px solid #efe7e0;box-shadow:none; }
    .chat-header h2 { font-size:15px;color:#41362f;font-weight:800; }
    .chat-header p { color:#85796f;font-size:12px;font-weight:400; }
    .chat-body { background:#fbfaf8;scroll-behavior:auto !important;overscroll-behavior-y:contain; }
    .chat-input-wrap { background:#fff;border-top:1px solid #eee7df; }
    .chat-back { gap:6px;white-space:nowrap; }
    .chat-back span { font-size:12px;font-weight:700; }
    .message-row.focused-order .order-chat-card { border-color:#eab57f;box-shadow:0 0 0 2px rgba(234,138,58,.10),0 4px 15px rgba(58,42,28,.055); }
    @media(min-width:761px) and (max-width:840px) { .page > main.content.messages-main { padding-top:69px !important; }.messages-wrap { padding:7px 10px 10px; } }
    @media(max-width:760px) {
      /* The Seller navigation header remains visible; Messages begins immediately below it. */
      :root { --seller-messages-top:64px; }
      .page > main.content.messages-main {
        position:fixed !important;inset:var(--seller-messages-top) 0 0 !important;
        top:var(--seller-messages-top) !important;right:0 !important;bottom:0 !important;left:0 !important;
        margin:0 !important;padding:0 !important;min-height:0 !important;
        width:100% !important;height:calc(100vh - var(--seller-messages-top)) !important;
        height:calc(100dvh - var(--seller-messages-top)) !important;
        overflow:hidden !important;z-index:1;
      }
      /* Keep the same shared search/header that other Seller pages display. */
      .seller-messages-search { display:block !important; visibility:visible !important; }
      .messages-wrap {
        position:absolute;inset:auto 0 var(--keyboard-inset,0px);
        top:var(--seller-messages-search-offset,0px);left:0;right:0;
        width:100%;height:auto;max-height:none;margin:0;padding:0;overflow:hidden;background:#fff;
      }
      .messages-shell { display:block;width:100%;height:100%;min-height:0;padding-top:0;border:0;border-radius:0;box-shadow:none; }
      .list-heading { min-height:58px;padding:10px 14px;gap:12px;border-bottom:1px solid #ede5de; }
      .list-heading-title { font-size:16px;font-weight:800; }
      #conversationItems .conversation { min-height:69px;padding:11px 10px; }
      #conversationItems .conversation .avatar { flex-basis:40px;width:40px;height:40px;min-width:40px;min-height:40px; }
      .chat-header .avatar { flex-basis:42px;width:42px;height:42px;min-width:42px;min-height:42px; }
      .chat-back { display:inline-flex;align-items:center;justify-content:center;flex:0 0 40px;width:40px;min-width:40px;height:40px;padding:0;border-radius:10px;box-shadow:none; }
      .chat-back span { display:none; }
      .chat-back svg { width:21px;height:21px;flex-basis:21px; }
      .chat-header { padding-top:11px;padding-bottom:11px; }
      .chat-header-top { gap:9px; }
      .chat-header-copy { min-width:0; }
      .chat-header-copy h2,.chat-header-copy p { max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
    }
    @media(max-width:360px) {
      .list-heading { padding-left:10px;padding-right:10px; }
      .chat-back { padding:0;gap:0; }
      .chat-header-top { gap:6px; }
    }
    /* Order Details UI: same visual hierarchy as the Buyer review popup.
       Seller actions, editable controls, IDs and server behavior are unchanged. */
    #arrangementDialog {
      box-sizing:border-box;width:min(650px,calc(100vw - 24px));max-width:calc(100vw - 12px);
      max-height:calc(100dvh - 24px);margin:auto;padding:0;border:1px solid #e9ded3;
      border-radius:18px;background:#fff;color:#352f29;
      box-shadow:0 26px 75px rgba(33,22,13,.23);
      font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;font-size:14px;
      font-weight:400;line-height:1.5;
    }
    #arrangementDialog *,#arrangementDialog *::before,#arrangementDialog *::after { box-sizing:border-box; }
    #arrangementDialog::backdrop { background:rgba(33,25,20,.52);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px); }
    #arrangementDialog button,#arrangementDialog input,#arrangementDialog select,#arrangementDialog textarea { font-family:inherit; }
    #arrangementDialog .arrangement-form {
      width:100%;min-width:0;max-height:calc(100dvh - 24px);padding:0 20px 20px;
      overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch;
      overscroll-behavior:contain;scroll-padding-top:92px;scroll-padding-bottom:100px;
    }
    #arrangementDialog .arrangement-dialog-head {
      position:sticky;top:0;z-index:10;margin:0 -20px 16px;padding:18px 20px 16px;
      display:flex;align-items:flex-start;justify-content:space-between;gap:15px;
      background:#fffaf6;border-bottom:1px solid #eee5dc;box-shadow:none;backdrop-filter:none;
    }

    #arrangementDialog .arrangement-dialog-head h2 { margin:0;color:#3d3128;font-size:17px;font-weight:800;line-height:1.35;letter-spacing:-.02em; }
    #arrangementDialog .arrangement-dialog-head p { margin:5px 0 0;color:#81756b;font-size:12px;font-weight:400;line-height:1.5; }
    #arrangementDialog .arrangement-close {
      display:grid;place-items:center;flex:0 0 36px;width:36px;height:36px;
      padding:0;border:1px solid #e5d9ce;border-radius:50%;background:#fff;color:#6e5f52;
      box-shadow:none;font-size:19px;
    }
    #arrangementDialog .arrangement-close svg { width:19px;height:19px; }
    #arrangementDialog .arrangement-bottom-feedback, #arrangementDialog .seller-action-readiness{
      font-size:12px;font-weight:400;line-height:1.5;
    }

    #arrangementDialog .arrangement-status-pill {
      flex:0 0 auto;max-width:45%;padding:6px 10px;font-size:11px;font-weight:700;
      line-height:1.35;text-align:center;white-space:normal;
    }

    #arrangementDialog .arrangement-items { display:grid;gap:9px;margin-bottom:12px; }
    #arrangementDialog .arrangement-items[hidden] { display:none!important; }
    #arrangementDialog .arrangement-item-row { min-width:0;border:1px solid #ebe4dd;border-radius:10px;background:#fcfaf8; }
    #arrangementDialog .arrangement-item-name { font-size:13px;font-weight:650; }
    #arrangementDialog .arrangement-item-meta { font-size:12px;font-weight:400; }


    #arrangementDialog .arrangement-field > span:first-child{
      display:block;margin:0;color:#968779;font-size:11px;font-weight:650;
      line-height:1.4;letter-spacing:0;text-transform:none;
    }

    #arrangementDialog .arrangement-fields{
      display:grid;grid-template-columns:minmax(0,1fr);gap:8px;min-width:0;
    }
    #arrangementDialog .arrangement-field{
      display:grid;grid-template-columns:minmax(0,135px) minmax(0,1fr);
      align-items:center;gap:12px;min-width:0;min-height:49px;
      padding:12px 14px;border:1px solid #ebe4dd;border-radius:11px;background:#fcfaf8;
    }
    #arrangementDialog .arrangement-field[hidden]{ display:none!important; }
    #arrangementDialog .arrangement-field-wide { grid-column:auto; }
    #arrangementDialog .arrangement-field > span:first-child { min-width:0;overflow-wrap:anywhere; }
    #arrangementDialog .arrangement-field > span:first-child b { color:#d87522;font-weight:750; }


    #arrangementDialog .arrangement-field input,#arrangementDialog .arrangement-field select,
    #arrangementDialog .arrangement-field textarea {
      display:block;min-width:0;max-width:100%;width:100%;min-height:44px;
      padding:10px 12px;border:1px solid #ded5cc;border-radius:9px;
      background:#fff;color:#433a34;font-size:16px;font-weight:400;line-height:1.45;
      outline:0;box-shadow:none;
    }
    #arrangementDialog .arrangement-field textarea { min-height:86px;resize:vertical;line-height:1.5; }
    #arrangementDialog .arrangement-field input:focus,#arrangementDialog .arrangement-field select:focus,
    #arrangementDialog .arrangement-field textarea:focus {
      border-color:#e5a56b;box-shadow:0 0 0 3px rgba(234,138,58,.10);
    }
    #arrangementDialog .arrangement-field :disabled { background:#f3f1ef;color:#877b70; }

    #arrangementDialog .seller-native-picker { display:flex;min-width:0;max-width:100%;width:100%;min-height:44px;border-radius:9px;background:#fff; }
    #arrangementDialog .arrangement-actions {
      position:sticky;bottom:-20px;z-index:9;display:flex;justify-content:flex-end;flex-wrap:wrap;gap:10px;
      margin:18px -20px -20px;padding:13px 20px max(16px,env(safe-area-inset-bottom));
      border-top:1px solid #eee5dd;background:#fffdfa;box-shadow:0 -8px 18px rgba(60,40,25,.025);
    }
    #arrangementDialog .arrangement-action {
      flex:0 1 auto;min-width:120px;min-height:42px;padding:10px 15px;
      border-radius:10px;font-size:13px;font-weight:700;line-height:1.4;box-shadow:none;
    }
    #arrangementDialog .arrangement-action:hover:not(:disabled) { transform:none;filter:brightness(.98); }
    /* Keep the existing iOS visual-viewport centering logic. */
    #arrangementDialog[open] {
      position:fixed;inset:auto 0 auto;top:var(--seller-arrangement-center,50%);
      margin-right:auto;margin-left:auto;transform:translateY(-50%);
      max-height:var(--seller-arrangement-available,calc(100dvh - 24px));
      overscroll-behavior:contain;
    }
    #arrangementDialog[open] .arrangement-form {
      max-height:var(--seller-arrangement-available,calc(100dvh - 24px));
      overflow-y:auto;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;
    }
    body.seller-arrangement-visible { overflow:hidden; }
    @media(max-width:540px) {
      #arrangementDialog { width:calc(100vw - 16px);max-height:calc(100dvh - 16px);border-radius:16px; }
      #arrangementDialog .arrangement-form { max-height:calc(100dvh - 16px);padding:0 14px 16px; }
      #arrangementDialog .arrangement-dialog-head { margin:0 -14px 14px;padding:15px 14px 13px; }
      #arrangementDialog .arrangement-dialog-head h2 { font-size:16px; }


      #arrangementDialog .arrangement-field{
        grid-template-columns:minmax(0,1fr);gap:4px;padding:11px 12px;
      }
      #arrangementDialog .arrangement-field > span:first-child { margin:0 0 4px; }
      #arrangementDialog .arrangement-actions {
        bottom:-16px;margin:15px -14px -16px;
        padding:12px 14px max(14px,env(safe-area-inset-bottom));
      }
      #arrangementDialog .arrangement-action { flex:1 1 135px;min-width:0; }
    }
  </style>
  <style id="sellerThreadSearchStyles">
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

    /* Seller order popup: Cart snapshots are read-only; only handover details can be edited. */
    #arrangementDialog{width:min(620px,calc(100vw - 20px));border-radius:17px}
    #arrangementDialog .arrangement-form{padding:0 18px 18px}
    #arrangementDialog .arrangement-dialog-head{align-items:center;margin:0 -18px 14px;padding:15px 18px;background:#fff;border-bottom:1px solid #ece5dd}
    #arrangementDialog .lc-order-heading{display:flex;align-items:baseline;flex-wrap:wrap;gap:9px;min-width:0}
    #arrangementDialog .lc-order-heading h2{font-size:17px;font-weight:800;line-height:1.3;color:#332a23;margin:0}
    #arrangementDialog .lc-order-heading span{font-size:12px;font-weight:650;color:#8b7c70}
    #arrangementDialog .lc-order-overview{display:flex;align-items:center;justify-content:space-between;gap:14px;margin:0 0 16px;padding:12px 13px;border:1px solid #eaded1;border-radius:11px;background:#fdf9f4}
    #arrangementDialog .lc-order-overview-copy{display:grid;min-width:0;gap:3px}
    #arrangementDialog .lc-order-overview-copy strong{font-size:13px;line-height:1.35;color:#3b3028;overflow-wrap:anywhere}
    #arrangementDialog .lc-order-overview-copy span{font-size:11px;color:#84756b}
    #arrangementDialog .lc-order-section-title{margin:0;color:#44372d;font-size:13px;font-weight:800;line-height:1.5}
    #arrangementDialog .arrangement-items{margin:10px 0 12px;gap:8px}
    #arrangementDialog .lc-order-total-row{display:flex;align-items:center;justify-content:space-between;gap:10px;border-radius:11px;background:#fff2e5;border:1px solid #f2d1b0;padding:13px 15px;color:#6e4c31;font-size:13px;font-weight:800;margin-bottom:20px}
    #arrangementDialog .lc-order-total-row strong{font-size:19px;color:#af6025;font-variant-numeric:tabular-nums;white-space:nowrap}
    #arrangementDialog .lc-order-section-header{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:11px}
    #arrangementDialog .lc-order-payment-status{font-size:11px;font-weight:700;color:#725f4e;border:1px solid #e9e0d5;background:#faf7f3;padding:4px 9px;border-radius:50px}
    #arrangementDialog .arrangement-fields{grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}
    #arrangementDialog .arrangement-field{grid-template-columns:minmax(0,1fr);align-items:start;gap:6px;min-height:0;padding:0;border:0;border-radius:0;background:transparent}
    #arrangementDialog .arrangement-field-wide{grid-column:1 / -1}
    #arrangementDialog .arrangement-field>span:first-child{color:#6c5f54;font-size:12px;font-weight:700}
    #arrangementDialog .arrangement-field input,#arrangementDialog .arrangement-field select,#arrangementDialog .arrangement-field textarea{background:#fff;min-height:42px;border:1px solid #e1d9d2;font-size:15px}
    #arrangementDialog .arrangement-field textarea{min-height:68px}
    #arrangementDialog .seller-action-readiness:empty,#arrangementDialog .arrangement-bottom-feedback:empty{display:none!important}
    #arrangementDialog .arrangement-actions{margin-top:19px}
    #arrangementDialog .arrangement-action{min-width:110px}
    @media(max-width:560px){
      #arrangementDialog .arrangement-form{padding:0 14px 14px}
      #arrangementDialog .arrangement-dialog-head{margin:0 -14px 14px;padding:13px 14px}
      #arrangementDialog .arrangement-fields{grid-template-columns:minmax(0,1fr)}
      #arrangementDialog .arrangement-actions{gap:8px}
      #arrangementDialog .arrangement-action{flex:1 1 auto;min-width:100px}
      #arrangementDialog .lc-order-total-row strong{font-size:17px}
    }
</style>
  <style id="sellerSafariFormControls">
    /* Delivery/Payment: identical styled options in Safari, Chromium and Firefox. */
    #arrangementDialog .seller-select-holder { position:relative; min-width:0; width:100%; }
    #arrangementDialog .seller-select-holder.is-open { z-index:35; }
    #arrangementDialog .seller-select-holder.is-enhanced > select {
      position:absolute!important; width:1px!important; height:1px!important; min-height:0!important;
      padding:0!important; margin:0!important; opacity:0!important; border:0!important;
      clip-path:inset(50%); pointer-events:none; overflow:hidden;
    }
    #arrangementDialog .seller-select-trigger {
      display:flex; align-items:center; justify-content:space-between; gap:10px;
      width:100%; min-width:0; min-height:44px; padding:10px 12px;
      border:1px solid #ded5cc; border-radius:9px; background:#fff;
      color:#433a34; font:400 16px/1.45 Inter,system-ui,-apple-system,'Segoe UI',sans-serif;
      text-align:left; cursor:pointer; -webkit-appearance:none; appearance:none;
      -webkit-tap-highlight-color:transparent;
    }
    #arrangementDialog .seller-select-trigger .seller-select-text { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    #arrangementDialog .seller-select-trigger.is-placeholder .seller-select-text { color:#968c82; }
    #arrangementDialog .seller-select-trigger .seller-select-chevron { width:16px; height:16px; flex-shrink:0; color:#8b7867; transition:transform .16s ease; }
    #arrangementDialog .seller-select-holder.is-open .seller-select-chevron { transform:rotate(180deg); }
    #arrangementDialog .seller-select-trigger:focus-visible,
    #arrangementDialog .seller-select-holder.is-open .seller-select-trigger { outline:0; border-color:#e5a56b; box-shadow:0 0 0 3px rgba(234,138,58,.10); }
    #arrangementDialog .seller-select-trigger:disabled { background:#f3f1ef; color:#877b70; cursor:not-allowed; }
    #arrangementDialog .arrangement-field.invalid .seller-select-trigger { border-color:#df7777; background:#fff7f7; }
    #arrangementDialog .seller-select-menu:not([hidden]) {
      display:grid; gap:3px; position:absolute; top:calc(100% + 5px); left:0; right:0;
      max-height:225px; overflow-y:auto; overscroll-behavior:contain;
      z-index:36; padding:5px; border:1px solid #e7d9cc; border-radius:11px;
      background:#fff; box-shadow:0 12px 28px rgba(60,39,25,.14);
      -webkit-overflow-scrolling:touch;
    }
    #arrangementDialog .seller-select-option {
      display:flex; align-items:center; justify-content:space-between; gap:8px;
      width:100%; min-height:42px; padding:10px 11px; border:0; border-radius:7px;
      background:transparent; color:#433a34; font:400 15px/1.35 Inter,system-ui,-apple-system,'Segoe UI',sans-serif;
      text-align:left; cursor:pointer; -webkit-appearance:none; appearance:none;
    }
    #arrangementDialog .seller-select-option:hover,
    #arrangementDialog .seller-select-option:focus-visible { background:#fff2e6; outline:0; }
    #arrangementDialog .seller-select-option[aria-selected="true"] { background:#fdf0e4; color:#a85d29; font-weight:650; }
    #arrangementDialog .seller-select-option:disabled { color:#a9a19a; cursor:not-allowed; }
    #arrangementDialog .seller-select-option:disabled:hover { background:transparent; }
    #arrangementDialog .seller-select-option .seller-option-tick { font-size:16px; font-weight:700; }
    /* Date/Time: blank placeholder until selected; real picker remains usable. */
    #arrangementDialog .seller-native-picker { position:relative; isolation:isolate; }
    #arrangementDialog .seller-native-picker .seller-picker-placeholder {
      position:absolute; left:12px; top:50%; transform:translateY(-50%);
      z-index:1; color:#968c82; font-size:16px; font-weight:400;
      line-height:1.4; white-space:nowrap; pointer-events:none;
    }
    #arrangementDialog .seller-native-picker .seller-picker-placeholder-icon {
      display:grid; place-items:center; position:absolute; right:12px; top:50%; transform:translateY(-50%);
      z-index:1; color:#9b8573; pointer-events:none;
    }
    #arrangementDialog .seller-native-picker:not(.is-empty) .seller-picker-placeholder,
    #arrangementDialog .seller-native-picker:not(.is-empty) .seller-picker-placeholder-icon,
    #arrangementDialog .seller-native-picker:focus-within .seller-picker-placeholder,
    #arrangementDialog .seller-native-picker:focus-within .seller-picker-placeholder-icon { display:none; }
    #arrangementDialog .seller-native-picker.is-empty:not(:focus-within) input[type="date"],
    #arrangementDialog .seller-native-picker.is-empty:not(:focus-within) input[type="time"] {
      opacity:0!important;
    }
    #arrangementDialog .seller-native-picker.is-empty:not(:focus-within) { cursor:pointer; }
    @media(max-width:560px) {
      #arrangementDialog .seller-select-menu:not([hidden]) { max-height:min(225px,36dvh); }
      #arrangementDialog .seller-select-trigger { min-height:46px; }
    }
  </style>
  <style id="sellerBuyerPrivacyAndLinks">
    .chat-header .seller-buyer-avatar-button{display:grid;place-items:center;cursor:pointer;border:0;appearance:none;-webkit-appearance:none;color:#fff;}
    .chat-header .seller-buyer-name-button{max-width:100%;padding:0;border:0;background:transparent;color:inherit;font:inherit;font-weight:inherit;line-height:inherit;text-align:left;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer;}
    .chat-header .seller-buyer-avatar-button:focus-visible,.chat-header .seller-buyer-name-button:focus-visible{outline:3px solid rgba(234,138,58,.42);outline-offset:3px;border-radius:7px;}
    .chat-header .seller-buyer-name-button:hover{text-decoration:underline;text-decoration-color:#e7ae7c;text-underline-offset:3px;}
    .attach-label svg,.seller-file-icon svg{display:block;width:19px;height:19px;flex-shrink:0;}
    .attachment-file svg{width:17px;height:17px;display:block;flex:none;}
    .attachment-file{display:flex;align-items:center;gap:7px;min-width:0;overflow-wrap:anywhere;}
    .bubble .message-link{color:inherit;text-decoration:underline;text-underline-offset:2px;overflow-wrap:anywhere;word-break:break-word;}
    .bubble .message-link:focus-visible{outline:2px solid #e9a064;outline-offset:2px;border-radius:3px;}
    /* Buyer profile: same warm hero treatment as the marketplace Store Profile. */
    .seller-buyer-profile-dialog{position:fixed;inset:auto 0 auto;top:var(--buyer-profile-center,50%);transform:translateY(-50%);margin-right:auto;margin-left:auto;box-sizing:border-box;width:min(760px,calc(100vw - 24px));max-width:calc(100vw - 24px);max-height:var(--buyer-profile-available,calc(100dvh - 24px));padding:0;border:1px solid #e9d9c9;border-radius:19px;background:#fffdfb;box-shadow:0 24px 70px rgba(32,24,17,.24);overflow:hidden;color:#43362d;font-family:inherit;}
    .seller-buyer-profile-dialog::backdrop{background:rgba(30,25,21,.51);-webkit-backdrop-filter:blur(2px);backdrop-filter:blur(2px);}
    .seller-buyer-profile-card{display:flex;flex-direction:column;max-height:var(--buyer-profile-available,calc(100dvh - 24px));min-width:0;overflow:hidden;}
    .seller-buyer-profile-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:17px 23px;border-bottom:1px solid #eee5dc;background:#fffdfb;}
    .seller-buyer-kicker{display:block;margin-bottom:5px;font-size:10px;font-weight:850;letter-spacing:.1em;color:#b96f37;}
    .seller-buyer-profile-head h2{margin:0;font-size:19px;font-weight:800;color:#382d24;}
    .seller-buyer-profile-close{display:grid;place-items:center;flex:0 0 39px;width:39px;height:39px;border:1px solid #eadbcc;border-radius:50%;background:#fffdfa;color:#865d42;cursor:pointer;box-shadow:0 5px 16px rgba(62,42,27,.07);}
    .seller-buyer-profile-close svg{width:18px;height:18px;}
    .seller-buyer-profile-close:hover{background:#fff4e8;border-color:#e3b989;}
    .seller-buyer-profile-close:focus-visible{outline:3px solid #f5c79f;outline-offset:2px;}
    .seller-buyer-profile-scroll{flex:1;min-height:0;padding:22px 23px 25px;overflow-x:hidden;overflow-y:auto;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;}
    .seller-buyer-hero{position:relative;isolation:isolate;display:flex;align-items:center;justify-content:space-between;gap:16px;min-height:138px;padding:23px 24px;margin-bottom:23px;overflow:hidden;border:1px solid #f0d9c1;border-radius:17px;background:linear-gradient(110deg,#fffdfb 4%,#fff7ee 53%,#ffead4 100%);box-shadow:0 8px 20px rgba(67,43,23,.04);}
    .seller-buyer-hero::before{content:"";position:absolute;z-index:-1;top:-168px;right:-55px;width:250px;height:250px;border:1px solid rgba(229,157,95,.24);border-radius:50%;box-shadow:0 0 0 46px rgba(252,230,203,.24),0 0 0 84px rgba(255,240,223,.25);pointer-events:none;}
    .seller-buyer-overview{display:flex;align-items:center;gap:17px;min-width:0;flex:1;}
    .seller-buyer-profile-avatar{display:grid;place-items:center;flex:0 0 67px;width:67px;height:67px;border:3px solid #fff;border-radius:21px;background:linear-gradient(135deg,#f1b578,#dc833d);box-shadow:0 8px 18px rgba(194,111,48,.15);color:#fff;font-size:21px;font-weight:850;letter-spacing:.01em;}
    .seller-buyer-profile-identity{display:grid;gap:5px;min-width:0;}
    .seller-buyer-profile-identity strong{font-size:clamp(19px,2.5vw,25px);font-weight:850;letter-spacing:-.035em;line-height:1.2;color:#402e22;overflow-wrap:anywhere;}
    .seller-buyer-profile-identity span{color:#8e7b6b;font-size:12px;line-height:1.5;overflow-wrap:anywhere;}
    .seller-buyer-profile-identity .seller-buyer-kicker{color:#b96f37;font-size:10px;font-weight:850;letter-spacing:.1em;}
    .seller-buyer-profile-active{display:inline-flex;align-items:center;gap:7px;flex:0 0 auto;border:1px solid #dbe7de;background:rgba(255,255,255,.92);padding:9px 12px;border-radius:999px;color:#6c6158;font-size:11px;font-weight:800;white-space:nowrap;box-shadow:0 4px 12px rgba(67,43,23,.04);}
    .seller-buyer-profile-active::before{content:"";width:9px;height:9px;border-radius:50%;background:#49a271;box-shadow:0 0 0 3px #e9f5ed;}
    .seller-buyer-profile-active[hidden],.seller-buyer-profile-state[hidden],#sellerBuyerReviewContents[hidden]{display:none!important;}
    .seller-buyer-profile-state{padding:18px 12px;color:#887b70;font-size:13px;text-align:center;line-height:1.5;}
    .seller-buyer-review-section-title{display:flex;align-items:end;justify-content:space-between;gap:10px;margin:0 0 13px;}
    .seller-buyer-review-section-title h3{margin:0;color:#49382c;font-size:17px;font-weight:800;}
    .seller-buyer-rating-layout{display:grid;grid-template-columns:minmax(0,.85fr) minmax(0,1.15fr);gap:13px;margin-bottom:23px;}
    .seller-buyer-rating-summary{display:flex;flex-direction:column;justify-content:center;align-items:flex-start;gap:12px;min-width:0;padding:20px;border:1px solid #efdfd0;border-radius:14px;background:linear-gradient(145deg,#fffaf4,#fff1e2);}
    .seller-buyer-rating-score{display:flex;align-items:baseline;gap:9px;}
    .seller-buyer-rating-score strong{font-size:40px;line-height:1;font-weight:850;color:#ac642d;font-variant-numeric:tabular-nums;}
    .seller-buyer-rating-score span{font-size:12px;color:#947f70;}
    .seller-buyer-rating-summary-right{display:grid;gap:6px;}
    .seller-buyer-rating-summary-right span{font-size:12px;color:#826f60;}
    .seller-buyer-rating-stars,.seller-buyer-review-stars{color:#db9639;letter-spacing:2px;font-size:18px;line-height:1.2;}
    .seller-buyer-rating-bars{display:grid;align-content:center;gap:10px;min-width:0;padding:19px 20px;border:1px solid #efe5dc;border-radius:14px;background:#fff;}
    .seller-buyer-rating-bar{display:grid;grid-template-columns:33px minmax(0,1fr) 29px;gap:9px;align-items:center;color:#877669;font-size:12px;}
    .seller-buyer-rating-bar-track{height:8px;border-radius:20px;background:#f1e9e2;overflow:hidden;}
    .seller-buyer-rating-bar-fill{height:100%;background:linear-gradient(90deg,#e5b17d,#dd8c44);border-radius:20px;}
    .seller-buyer-review-heading{margin-bottom:12px;font-size:15px;font-weight:800;color:#49382c;}
    .seller-buyer-review-list{display:grid;gap:11px;}
    .seller-buyer-review{padding:16px 17px;border:1px solid #efe5dc;border-radius:13px;background:#fff;min-width:0;}
    .seller-buyer-review-top{display:flex;justify-content:space-between;align-items:start;gap:10px;}
    .seller-buyer-review-shop{font-size:13px;font-weight:800;color:#4c4036;overflow-wrap:anywhere;}
    .seller-buyer-review-date{flex:0 0 auto;color:#9a8e84;font-size:11px;}
    .seller-buyer-review-stars{font-size:15px;margin-top:7px;}
    .seller-buyer-review-text{margin-top:9px;color:#655a51;font-size:12px;line-height:1.6;white-space:pre-wrap;overflow-wrap:anywhere;}
    .seller-buyer-review-empty{padding:18px;border:1px dashed #e9ded2;border-radius:12px;color:#887c70;font-size:12px;line-height:1.5;text-align:center;}
    @media(max-width:660px){.seller-buyer-rating-layout{grid-template-columns:1fr;}.seller-buyer-rating-summary{flex-direction:row;align-items:center;justify-content:flex-start;}.seller-buyer-profile-active{padding:7px 10px;}}
    @media(max-width:560px){.seller-buyer-profile-dialog{width:calc(100vw - 20px);border-radius:16px;}.seller-buyer-profile-head{padding:14px 16px;}.seller-buyer-profile-scroll{padding:15px;}.seller-buyer-hero{padding:19px 17px;align-items:flex-start;flex-direction:column;gap:14px;min-height:0;margin-bottom:19px;}.seller-buyer-overview{gap:12px;}.seller-buyer-profile-avatar{flex-basis:56px;width:56px;height:56px;border-radius:17px;font-size:18px;}.seller-buyer-profile-identity strong{font-size:19px;}.seller-buyer-profile-active{align-self:flex-start;}.seller-buyer-rating-summary,.seller-buyer-rating-bars{padding:16px;}.seller-buyer-review{padding:14px;}}
    @media(max-width:360px){.seller-buyer-review-top{flex-wrap:wrap;gap:4px;}.seller-buyer-profile-identity strong{font-size:17px;}.seller-buyer-rating-score strong{font-size:35px;}}
  </style>
  <style id="sellerMessagesCtaGradientAndAvatars">
    body, .page, .page > main.content.messages-main, .messages-wrap {
      background: #FFF8ED;
    }
    .chat-body { background: #FFF8ED; }
    @media (max-width: 760px) {
      .messages-wrap { background: #FFF8ED; }
    }
    /* Color only: keep the Buyer Profile avatar's existing dimensions and shape. */
    .seller-buyer-profile-avatar {
      background: linear-gradient(135deg, #A55325, #C2692E);
    }
    /* Match the existing Buyer Messages CTA colors without changing chat behavior. */
    .outgoing .bubble {
      border-color: #A55325;
      background: linear-gradient(135deg, #A55325, #C2692E);
      color: #FFF8ED;
    }
    .send-btn:not(:disabled),
    .arrange-order-btn:not(.accept-ready):not(:disabled),
    #arrangementDialog .arrangement-action.primary:not(:disabled),
    .message-order-card:not(.is-closed-order) .order-chat-action:not(:disabled) {
      border-color: #A55325;
      background: linear-gradient(135deg, #A55325, #C2692E);
      color: #FFF8ED;
    }
    .send-btn:hover:not(:disabled),
    .arrange-order-btn:not(.accept-ready):hover:not(:disabled),
    #arrangementDialog .arrangement-action.primary:hover:not(:disabled),
    .message-order-card:not(.is-closed-order) .order-chat-action:hover:not(:disabled) {
      background: linear-gradient(135deg, #C2692E, #A55325);
    }
    /* Keep acceptance (green), disabled buttons, and closed-order states intact. */

    /* Match the circular, warm-brown avatar design of the seller search header. */
    #conversationItems .conversation .avatar,
    .chat-header .avatar,
    #chatAvatar {
      display: grid;
      place-items: center;
      padding: 0;
      line-height: 1;
      aspect-ratio: 1 / 1;
      border: 1px solid rgba(116, 46, 18, .22);
      border-radius: 50% !important;
      background: linear-gradient(135deg, #A55325, #C2692E);
      color: #FFF8ED;
      font-weight: 800;
      text-shadow: none;
      box-shadow: inset 0 1px 1px rgba(255, 248, 237, .14),
                  0 2px 6px rgba(102, 46, 19, .16);
    }
    #conversationItems .conversation:hover .avatar,
    #conversationItems .conversation:focus-visible .avatar,
    #chatAvatar:hover,
    #chatAvatar:focus-visible {
      box-shadow: inset 0 1px 1px rgba(255, 248, 237, .18),
                  0 3px 8px rgba(102, 46, 19, .20);
    }
  </style>
</head>
<body>
  <div class="page">
    <?php require __DIR__ . '/seller_nav.php'; ?>
    <main class="content messages-main">
      <div class="seller-messages-search"><?php $searchPlaceholder = 'Search buyers...'; require __DIR__ . '/seller_search.php'; ?></div>
      <div class="messages-wrap">
        <h1 class="page-title">Messages</h1>
        
        <?php if ($errorMessage !== ''): ?>
          <div class="chat-error show"><?php echo e($errorMessage); ?></div>
        <?php endif; ?>
        <div class="messages-shell <?php echo $selectedBuyerId > 0 ? 'chat-open' : ''; ?>" id="messagesShell">
          <aside class="conversation-list" id="conversationList" aria-label="Buyer conversations">
            <div class="list-heading">
              <span class="list-heading-title">Messages</span>
            </div>
            <div id="conversationItems">
              <?php if (empty($conversationList)): ?>
                <div class="empty-conversations show" id="emptyConversations">
                  No customer conversations yet.
                </div>
              <?php else: ?>
                <?php foreach ($conversationList as $conversation): ?>
                  <?php
                    $isActive = (int)$conversation['buyer_id'] === $selectedBuyerId;
                    $preview = trim((string)$conversation['latest_message']);
                    if ($preview === '' && $conversation['latest_attachment'] !== '') {
                        $preview = 'Attachment';
                    }
                  ?>
                  <button
                    type="button"
                    class="conversation <?php echo $isActive ? 'active' : ''; ?>"
                    data-buyer-id="<?php echo (int)$conversation['buyer_id']; ?>"
                    aria-pressed="<?php echo $isActive ? 'true' : 'false'; ?>"
                  >
                    <span class="avatar"><?php echo e($conversation['initials']); ?></span>
                    <span class="conversation-main">
                      <span class="conversation-name-row">
                        <span class="conversation-name"><?php echo e($conversation['buyer_name']); ?></span>
                        <span class="conversation-time"><?php echo e($conversation['latest_time']); ?></span>
                      </span>
                      <?php if ($conversation['product'] !== ''): ?>
                        <span class="conversation-product"><?php echo e($conversation['product']); ?></span>
                      <?php endif; ?>
                      <span class="conversation-preview"><?php echo e($preview); ?></span>
                    </span>
                    <?php if ((int)$conversation['unread'] > 0 && !$isActive): ?>
                      <span class="badge"><?php echo (int)$conversation['unread']; ?></span>
                    <?php endif; ?>
                  </button>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </aside>
          <section class="chat-panel" id="chatPanel" aria-label="Chat with buyer">
            <?php if ($selectedBuyer !== null): ?>
              <header class="chat-header">
                <div class="chat-header-top">
                  <button class="chat-back" id="chatBack" type="button" aria-label="Back to messages">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                    <span>Messages</span>
                  </button>
                  <button type="button" class="avatar seller-buyer-avatar-button" id="chatAvatar" aria-label="View buyer reviews" aria-haspopup="dialog" aria-controls="sellerBuyerProfileDialog"><?php echo e($selectedBuyer['initials']); ?></button>
                  <div class="chat-header-copy">
                    <h2><button type="button" class="seller-buyer-name-button" id="chatName" aria-haspopup="dialog" aria-controls="sellerBuyerProfileDialog"><?php echo e($selectedBuyer['buyer_name']); ?></button></h2>
                    <p id="chatProduct">
                      <?php echo $selectedBuyer['product'] !== '' ? 'Product: ' . e($selectedBuyer['product']) : 'Marketplace conversation'; ?>
                    </p>
                    <div class="typing-indicator" id="typingIndicator"></div>
                  </div>
                </div>
                <div class="order-summary" id="orderSummary">
                  <div class="order-summary-head">
                    <div>
                      <div class="order-summary-title">Order Request</div>
                      <div class="order-summary-line">
                        <span id="orderSummaryId"></span>
                        <span id="orderSummaryProduct"></span>
                        <span id="orderSummaryTotal"></span>
                        <span id="orderSummaryDelivery"></span>
                        <span id="orderSummaryPayment"></span>
                        <span id="orderSummaryPaymentStatus"></span>
                        <span class="status-pill" id="orderSummaryStatus"></span>
                      </div>
                    </div>
                    <button
                      class="arrange-order-btn"
                      id="manageArrangementButton"
                      type="button"
                      hidden
                    >
                      Arrange Order
                    </button>
                  </div>
                  <div class="seller-next-action" id="sellerNextAction" role="status" aria-live="polite" hidden></div>
                </div>
              </header>
              <div class="chat-order-closed" id="sellerChatOrderClosed" role="status" hidden>
                <strong id="sellerChatClosedTitle"></strong>
                <span id="sellerChatClosedMessage">This transaction is closed. Messages will not reopen the order.</span>
              </div>
              <div class="chat-body" id="chatBody">
                <?php if (empty($initialMessages)): ?>
                  <div class="empty-chat show" id="emptyChat">No messages in this conversation yet.</div>
                <?php endif; ?>
              </div>
              <div class="chat-input-wrap">
                <div class="seller-message-notice" id="sellerMessageNotice" role="status" aria-live="polite" hidden></div>
                <div class="attachment-preview" id="attachmentPreview">
                  <span class="seller-file-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m20.5 11.5-8.7 8.7a6 6 0 0 1-8.5-8.5L13 2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8L15 5.8"/></svg></span>
                  <span class="attachment-preview-name" id="attachmentPreviewName"></span>
                  <button class="attachment-remove" id="attachmentRemove" type="button" aria-label="Remove attachment">×</button>
                </div>
                <form class="chat-input" id="messageForm" enctype="multipart/form-data">
                  <label class="attach-label" aria-label="Attach file">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m20.5 11.5-8.7 8.7a6 6 0 0 1-8.5-8.5L13 2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8L15 5.8"/></svg>
                    <input id="attachmentInput" type="file" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx" />
                  </label>
                  <textarea id="messageInput" rows="1" placeholder="Type a message..." autocomplete="off"></textarea>
                  <button class="send-btn" id="sendButton" type="submit" aria-label="Send message">➤</button>
                </form>
              </div>
            <?php else: ?>
              <div class="no-selection">
                Select a customer conversation to start chatting.
              </div>
            <?php endif; ?>
          </section>
        </div>
      </div>
    </main>
  </div>
  <dialog class="seller-buyer-profile-dialog" id="sellerBuyerProfileDialog" aria-labelledby="sellerBuyerProfileTitle">
    <section class="seller-buyer-profile-card">
      <header class="seller-buyer-profile-head">
        <div><span class="seller-buyer-kicker">BUYER PROFILE</span><h2 id="sellerBuyerProfileTitle">Buyer Profile</h2></div>
        <button type="button" class="seller-buyer-profile-close" id="sellerBuyerProfileClose" aria-label="Close buyer profile">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
      </header>
      <div class="seller-buyer-profile-scroll">
        <div class="seller-buyer-hero">
          <div class="seller-buyer-overview">
            <div class="seller-buyer-profile-avatar" id="sellerBuyerProfileAvatar" aria-hidden="true">B</div>
            <div class="seller-buyer-profile-identity">
              <span class="seller-buyer-kicker">LOKALCRAFT BUYER</span>
              <strong id="sellerBuyerProfileName">Buyer</strong>
              <span>Ratings and reviews given to shops</span>
            </div>
          </div>
          <span class="seller-buyer-profile-active" id="sellerBuyerProfileActive" hidden>Active Account</span>
        </div>
        <div class="seller-buyer-profile-state" id="sellerBuyerProfileState" role="status" aria-live="polite">Loading buyer reviews…</div>
        <div id="sellerBuyerReviewContents" hidden>
          <div class="seller-buyer-review-section-title">
            <div><span class="seller-buyer-kicker">RATING ACTIVITY</span><h3>Ratings Given</h3></div>
          </div>
          <div class="seller-buyer-rating-layout">
            <div class="seller-buyer-rating-summary">
              <div class="seller-buyer-rating-score"><strong id="sellerBuyerAverageRating">0.0</strong><span>out of 5</span></div>
              <div class="seller-buyer-rating-summary-right">
                <div class="seller-buyer-rating-stars" id="sellerBuyerAverageStars" aria-hidden="true"></div>
                <span id="sellerBuyerRatingCount">0 ratings given</span>
              </div>
            </div>
            <div class="seller-buyer-rating-bars" id="sellerBuyerRatingBars" aria-label="Ratings given by this buyer"></div>
          </div>
          <div class="seller-buyer-review-heading">Recent Reviews Given</div>
          <div class="seller-buyer-review-list" id="sellerBuyerReviewList"></div>
        </div>
      </div>
    </section>
  </dialog>
  <dialog class="arrangement-dialog" id="arrangementDialog" aria-labelledby="arrangementDialogTitle">
    <form class="arrangement-form" id="arrangementForm">
      <div class="arrangement-dialog-head">
        <div class="lc-order-heading">
          <h2 id="arrangementDialogTitle">Order Details</h2>
          <span id="arrangementOrderNumber">Order #</span>
        </div>
        <button class="arrangement-close" id="arrangementClose" type="button" aria-label="Close order details">
          <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
      </div>
      <div class="lc-order-overview">
        <div class="lc-order-overview-copy">
          <strong id="arrangementProduct">Product</strong>
          <span id="lcOrderBuyerName">Buyer</span>
        </div>
        <span class="arrangement-status-pill" id="arrangementStatus">Pending</span>
      </div>
      <h3 class="lc-order-section-title">Products</h3>
      <div class="arrangement-items" id="arrangementItems" hidden></div>
      <div class="lc-order-total-row">
        <span>Order Total</span>
        <strong id="arrangementFinalTotal">₱0.00</strong>
      </div>
      <div class="lc-order-section-header">
        <h3 class="lc-order-section-title">Delivery &amp; Payment</h3>
        <span id="arrangementPaymentStatus" class="lc-order-payment-status">Not Paid</span>
      </div>
      <div class="arrangement-fields">
        <div class="arrangement-field">
          <span id="sellerDeliveryLabel">Delivery <b>*</b></span>
          <div class="seller-select-holder">
          <select id="arrangementFulfillment" name="fulfillment_method" aria-labelledby="sellerDeliveryLabel">
            <option value="">Choose delivery</option>
            <option value="meetup">Meetup</option>
            <option value="pickup">Pickup</option>
            <option value="third_party_delivery">Third-Party Delivery</option>
          </select>
          </div>
        </div>
        <div class="arrangement-field">
          <span id="sellerPaymentLabel">Payment <b>*</b></span>
          <div class="seller-select-holder">
          <select id="arrangementPayment" name="payment_option" aria-labelledby="sellerPaymentLabel">
            <option value="">Choose payment</option>
            <option value="paymongo">QR Ph</option>
            <option value="cash">Cash</option>
          </select>
          </div>
        </div>
        <label class="arrangement-field arrangement-field-wide">
          <span id="arrangementLocationLabel">Location <b>*</b></span>
          <input id="arrangementLocation" name="arrangement_location" type="text" maxlength="255" placeholder="Enter location"/>
        </label>
        <label class="arrangement-field">
          <span>Date <b>*</b></span>
          <span class="seller-native-picker is-empty" id="sellerDatePicker">
            <span class="seller-picker-placeholder" aria-hidden="true">MM/DD/YYYY</span>
            <span class="seller-picker-placeholder-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18"/></svg></span>
            <input id="arrangementDate" name="arrangement_date" type="date" min="<?php echo e(date('Y-m-d')); ?>" aria-label="Date"/>
          </span>
          <span class="seller-datetime-error" id="arrangementDateError" role="alert" hidden></span>
        </label>
        <label class="arrangement-field">
          <span>Time <b>*</b></span>
          <span class="seller-native-picker is-empty" id="sellerTimePicker">
            <span class="seller-picker-placeholder" aria-hidden="true">HH:MM</span>
            <span class="seller-picker-placeholder-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
            <input id="arrangementTime" name="arrangement_time" type="time" step="60" aria-label="Time"/>
          </span>
          <span class="seller-datetime-error" id="arrangementTimeError" role="alert" hidden></span>
        </label>
        <label class="arrangement-field arrangement-field-wide">
          <span>Delivery Note (Optional)</span>
          <textarea id="arrangementNotes" name="arrangement_notes" rows="2" maxlength="2000" placeholder="Add a note"></textarea>
        </label>
      </div>
      <div class="seller-action-readiness" id="sellerActionReadiness" role="status" aria-live="polite" hidden></div>
      <div class="arrangement-bottom-feedback" id="arrangementBottomFeedback" role="alert" aria-live="polite"></div>
      <div class="arrangement-actions">
        <button class="arrangement-action secondary" id="saveArrangementButton" type="button">Save</button>
        <button class="arrangement-action primary" id="sendArrangementButton" type="button">Send to Buyer</button>
        <button class="arrangement-action accept" id="acceptOrderButton" type="button" hidden>Accept Order</button>
      </div>
    </form>
  </dialog>
  <script>
    const sellerId = <?php echo (int)$sellerId; ?>;
    const messagesCsrf = <?php echo json_encode($_SESSION['seller_messages_csrf'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const sellerFulfillmentMethods = <?php echo json_encode(sellerFulfillmentMethods()); ?>;
    let selectedBuyerId = <?php echo (int)$selectedBuyerId; ?>;
    let selectedOrderId = <?php echo (int)$selectedOrderId; ?>;
    // Only a validated order deep-link should scroll to a specific order row.
    const linkedOrderId = <?php echo (int)($requestedOrderId > 0 && $requestedOrderId === $selectedOrderId ? $requestedOrderId : 0); ?>;
    let activeOrderDetails = <?php echo json_encode($initialOrderDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
    let selectedBuyerName = <?php echo json_encode($selectedBuyer['buyer_name'] ?? 'Buyer', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
    let selectedBuyerInitials = <?php echo json_encode($selectedBuyer['initials'] ?? 'U', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
    let lastMessageId = 0;
    let pollTimer = null;
    let typingTimer = null;
    let lastTypingState = false;
    let sellerArrangementSubmitting = false;
    let sellerAcceptingOrder = false;
    let sellerStockRefreshInFlight = false;
    let sellerLastStockRefresh = 0;
    let sellerLastOrderStatusRefresh = 0;
    const initialMessages = <?php echo json_encode($initialMessages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
    const initialConversations = <?php echo json_encode($conversationList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
    const messagesShell = document.getElementById('messagesShell');
    const conversationItems = document.getElementById('conversationItems');
    const chatBody = document.getElementById('chatBody');
    const chatName = document.getElementById('chatName');
    const chatAvatar = document.getElementById('chatAvatar');
    const sellerBuyerProfileDialog = document.getElementById('sellerBuyerProfileDialog');
    const sellerBuyerProfileClose = document.getElementById('sellerBuyerProfileClose');
    const sellerBuyerProfileState = document.getElementById('sellerBuyerProfileState');
    const sellerBuyerReviewContents = document.getElementById('sellerBuyerReviewContents');
    const chatProduct = document.getElementById('chatProduct');
    const typingIndicator = document.getElementById('typingIndicator');
    const orderSummary = document.getElementById('orderSummary');
    const chatOrderClosed = document.getElementById('sellerChatOrderClosed');
    const chatClosedTitle = document.getElementById('sellerChatClosedTitle');
    const chatClosedMessage = document.getElementById('sellerChatClosedMessage');
    const orderSummaryId = document.getElementById('orderSummaryId');
    const orderSummaryProduct = document.getElementById('orderSummaryProduct');
    const orderSummaryTotal = document.getElementById('orderSummaryTotal');
    const orderSummaryDelivery = document.getElementById('orderSummaryDelivery');
    const orderSummaryPayment = document.getElementById('orderSummaryPayment');
    const orderSummaryPaymentStatus = document.getElementById('orderSummaryPaymentStatus');
    const orderSummaryStatus = document.getElementById('orderSummaryStatus');
    const messageForm = document.getElementById('messageForm');
    const messageInput = document.getElementById('messageInput');
    const sendButton = document.getElementById('sendButton');
    const sellerMessageNotice = document.getElementById('sellerMessageNotice');
    const attachmentInput = document.getElementById('attachmentInput');
    const attachmentPreview = document.getElementById('attachmentPreview');
    const attachmentPreviewName = document.getElementById('attachmentPreviewName');
    const attachmentRemove = document.getElementById('attachmentRemove');
    const emptyChat = document.getElementById('emptyChat');
    const chatBack = document.getElementById('chatBack');
    const manageArrangementButton = document.getElementById('manageArrangementButton');
    const arrangementDialog = document.getElementById('arrangementDialog');
    const arrangementForm = document.getElementById('arrangementForm');
    const arrangementClose = document.getElementById('arrangementClose');
    const arrangementBottomFeedback = document.getElementById('arrangementBottomFeedback');
    const arrangementProduct = document.getElementById('arrangementProduct');
    const arrangementOrderNumber = document.getElementById('arrangementOrderNumber');
    const arrangementStatus = document.getElementById('arrangementStatus');
    const arrangementItems = document.getElementById('arrangementItems');
    const arrangementFinalTotal = document.getElementById('arrangementFinalTotal');
    const arrangementPaymentStatus = document.getElementById('arrangementPaymentStatus');
    const arrangementFulfillment = document.getElementById('arrangementFulfillment');
    const arrangementPayment = document.getElementById('arrangementPayment');
    const arrangementLocation = document.getElementById('arrangementLocation');
    const arrangementDate = document.getElementById('arrangementDate');
    const arrangementTime = document.getElementById('arrangementTime');
    const arrangementDateError = document.getElementById('arrangementDateError');
    const arrangementTimeError = document.getElementById('arrangementTimeError');
    const arrangementNotes = document.getElementById('arrangementNotes');
    /* Keep the real <select> values for existing seller validation and AJAX.
       Render a consistent, keyboard-friendly options menu instead of iOS Safari's native sheet. */
    const sellerStyledSelects = [];
    function initSellerStyledSelect(select) {
      if (!select || !select.parentElement?.classList.contains('seller-select-holder')) return;
      const holder = select.parentElement;
      const label = select.getAttribute('aria-labelledby') || '';
      const trigger = document.createElement('button');
      trigger.type = 'button';
      trigger.className = 'seller-select-trigger';
      trigger.setAttribute('aria-haspopup', 'listbox');
      trigger.setAttribute('aria-expanded', 'false');
      if (label) trigger.setAttribute('aria-labelledby', label);
      trigger.innerHTML = '<span class="seller-select-text"></span>'
        + '<svg class="seller-select-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
      const menu = document.createElement('div');
      menu.className = 'seller-select-menu';
      menu.setAttribute('role', 'listbox');
      menu.hidden = true;
      const menuId = select.id + 'StyledOptions';
      menu.id = menuId;
      trigger.setAttribute('aria-controls', menuId);
      holder.append(trigger, menu);
      select.tabIndex = -1;
      select.setAttribute('aria-hidden', 'true');
      holder.classList.add('is-enhanced');
      const control = {select, holder, trigger, menu};
      sellerStyledSelects.push(control);
      const options = () => Array.from(menu.querySelectorAll('.seller-select-option:not(:disabled)'));
      function close(restoreFocus = false) {
        if (menu.hidden) return;
        menu.hidden = true;
        holder.classList.remove('is-open');
        trigger.setAttribute('aria-expanded', 'false');
        if (restoreFocus) trigger.focus({preventScroll:true});
      }
      function refresh() {
        const selected = Array.from(select.options).find(option => option.value === select.value);
        trigger.querySelector('.seller-select-text').textContent = selected?.textContent?.trim() || 'Choose';
        trigger.classList.toggle('is-placeholder', !select.value);
        trigger.disabled = select.disabled;
        if (select.disabled) close();
        menu.replaceChildren();
        Array.from(select.options).forEach((option) => {
          const item = document.createElement('button');
          item.type = 'button';
          item.className = 'seller-select-option';
          item.setAttribute('role', 'option');
          item.setAttribute('aria-selected', String(option.value === select.value));
          item.dataset.value = option.value;
          item.disabled = option.disabled;
          const name = document.createElement('span');
          name.textContent = option.textContent.trim();
          item.appendChild(name);
          if (option.value === select.value) {
            const tick = document.createElement('span');
            tick.className = 'seller-option-tick';
            tick.setAttribute('aria-hidden', 'true');
            tick.textContent = '✓';
            item.appendChild(tick);
          }
          item.addEventListener('click', () => {
            if (item.disabled) return;
            const changed = select.value !== option.value;
            select.value = option.value;
            close(true);
            if (changed) {
              select.dispatchEvent(new Event('input', {bubbles:true}));
              select.dispatchEvent(new Event('change', {bubbles:true}));
            }
            refresh();
          });
          item.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' || event.key === 'Tab') {
              close(event.key === 'Escape');
              return;
            }
            if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const enabled = options();
            const pos = enabled.indexOf(item);
            const next = event.key === 'Home' ? 0
              : event.key === 'End' ? enabled.length - 1
              : (pos + (event.key === 'ArrowDown' ? 1 : -1) + enabled.length) % enabled.length;
            enabled[next]?.focus();
          });
          menu.appendChild(item);
        });
      }
      function open(preferLast = false) {
        if (select.disabled) return;
        sellerStyledSelects.forEach(other => {
          if (other !== control) other.close();
        });
        refresh();
        menu.hidden = false;
        holder.classList.add('is-open');
        trigger.setAttribute('aria-expanded', 'true');
        const enabled = options();
        const chosen = enabled.find(option => option.dataset.value === select.value);
        (preferLast ? enabled[enabled.length - 1] : chosen || enabled[0])?.focus({preventScroll:true});
        // The arrangement form scrolls, so ensure the menu is not hidden behind its footer.
        requestAnimationFrame(() => {
          if (!menu.hidden) menu.scrollIntoView({block:'nearest', behavior:'auto'});
        });
      }
      control.close = close;
      control.refresh = refresh;
      trigger.addEventListener('click', () => menu.hidden ? open() : close());
      trigger.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
          event.preventDefault();
          open(event.key === 'ArrowUp');
        } else if (event.key === 'Escape') close();
      });
      select.addEventListener('change', refresh);
      refresh();
    }
    function refreshSellerStyledSelects() {
      sellerStyledSelects.forEach(control => control.refresh());
    }
    [arrangementFulfillment, arrangementPayment].forEach(initSellerStyledSelect);
    document.addEventListener('pointerdown', event => {
      sellerStyledSelects.forEach(control => {
        if (!control.holder.contains(event.target)) control.close();
      });
    });
    arrangementDialog?.addEventListener('close', () => {
      sellerStyledSelects.forEach(control => control.close());
    });
    const saveArrangementButton = document.getElementById('saveArrangementButton');
    const sendArrangementButton = document.getElementById('sendArrangementButton');
    const acceptOrderButton = document.getElementById('acceptOrderButton');
    let sellerMobileViewportBaseline = Math.max(
      window.innerHeight,
      document.documentElement.clientHeight
    );
    function syncSellerMessageViewport() {
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
      const inputFocused =
        document.activeElement === messageInput;
      if (!inputFocused) {
        sellerMobileViewportBaseline = Math.max(
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
      let keyboardInset = Math.max(
        0,
        sellerMobileViewportBaseline
          - visibleHeight
          - viewportTop
      );
      if (!inputFocused || keyboardInset < 100) {
        keyboardInset = 0;
      }
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
          if (chatBody) {
            chatBody.scrollTop = chatBody.scrollHeight;
          }
        });
      }
    }
    // Respect the existing mobile Seller header (including iPhone safe area).
    // Never place the inbox over nav controls; fall back to its standard 64px height.
    function syncSellerMessagesHeader() {
      if (!window.matchMedia('(max-width: 760px)').matches) {
        document.documentElement.style.removeProperty('--seller-messages-top');
        return;
      }
      const main = document.querySelector('main.messages-main');
      const candidates = document.querySelectorAll(
        'body header, body [role="banner"], .mobile-header, .mobile-topbar, .seller-topbar, .topbar, .top-bar, .nav-topbar'
      );
      let headerBottom = 0;
      for (const element of candidates) {
        if (main?.contains(element) || element.closest('dialog')) continue;
        const box = element.getBoundingClientRect();
        if (box.width < window.innerWidth * .60 || box.height < 36 || box.height > 125) continue;
        if (box.top > 12 || box.bottom < 40 || box.bottom > 150) continue;
        const position = window.getComputedStyle(element).position;
        if (position !== 'fixed' && position !== 'sticky') continue;
        headerBottom = Math.max(headerBottom, box.bottom);
      }
      const mainTop = Math.round(headerBottom || 64);
      document.documentElement.style.setProperty('--seller-messages-top', mainTop + 'px');
      // The shared seller_search.php can render as a fixed topbar or as an
      // in-flow row. Position Messages immediately below whichever it uses.
      const search = main?.querySelector('.seller-messages-search');
      let searchBottom = mainTop;
      if (search) {
        const candidates = [search, ...search.querySelectorAll('*')];
        for (const item of candidates) {
          if (item.hidden) continue;
          const style = window.getComputedStyle(item);
          if (style.display === 'none' || style.visibility === 'hidden'
              || style.position === 'absolute') continue;
          const rect = item.getBoundingClientRect();
          if (rect.width < 1 || rect.height < 1 || rect.height > 140
              || rect.top < -2 || rect.top > mainTop + 125
              || rect.bottom > mainTop + 175) continue;
          searchBottom = Math.max(searchBottom, rect.bottom);
        }
      }
      document.documentElement.style.setProperty(
        '--seller-messages-search-offset',
        Math.max(0, Math.round(searchBottom - mainTop)) + 'px'
      );
    }
    syncSellerMessagesHeader();
    syncSellerMessageViewport();
    window.addEventListener('resize', syncSellerMessagesHeader);
    window.addEventListener(
      'resize',
      syncSellerMessageViewport
    );
    window.addEventListener('orientationchange', () => {
      syncSellerMessagesHeader();
      document.documentElement.style.setProperty(
        '--keyboard-inset',
        '0px'
      );
      document.body.classList.remove('keyboard-open');
      setTimeout(() => {
        sellerMobileViewportBaseline = Math.max(
          window.innerHeight,
          document.documentElement.clientHeight
        );
        syncSellerMessageViewport();
      }, 350);
    });
    if (window.visualViewport) {
      window.visualViewport.addEventListener(
        'resize',
        syncSellerMessageViewport
      );
      window.visualViewport.addEventListener(
        'scroll',
        syncSellerMessageViewport
      );
    }
    // Jump without smooth scrolling (including when a conversation opens on iOS).
    function scrollChatToBottom(force = false) {
      if (!chatBody) return;
      const distanceFromBottom = chatBody.scrollHeight - chatBody.scrollTop - chatBody.clientHeight;
      if (force || distanceFromBottom < 180) {
        chatBody.scrollTop = chatBody.scrollHeight;
      }
    }
    function jumpToOrderMessage(orderId) {
      if (!chatBody || !(Number(orderId) > 0)) return false;
      const rows = Array.from(chatBody.querySelectorAll('.message-row[data-order-id]'))
        .filter(row => Number(row.dataset.orderId) === Number(orderId));
      if (!rows.length) return false;
      // Highlight the order card, but scroll to the newest message for this exact order.
      const target = rows[rows.length - 1];
      const orderCard = [...rows].reverse().find(row => row.querySelector('.order-chat-card'));
      chatBody.querySelectorAll('.message-row.focused-order').forEach(row => row.classList.remove('focused-order'));
      (orderCard || target).classList.add('focused-order');
      const top = chatBody.scrollTop + target.getBoundingClientRect().top
        - chatBody.getBoundingClientRect().top - 14;
      chatBody.scrollTop = Math.max(0, top);
      return true;
    }
    function showLatestMessage(orderId = 0) {
      if (!jumpToOrderMessage(orderId)) scrollChatToBottom(true);
    }
    function clearChat() {
      if (!chatBody) return;
      chatBody.innerHTML = '';
    }
    function createTextElement(tag, className, text) {
      const element = document.createElement(tag);
      if (className) {
        element.className = className;
      }
      element.textContent = text;
      return element;
    }
    const sellerPaperclipSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m20.5 11.5-8.7 8.7a6 6 0 0 1-8.5-8.5L13 2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8L15 5.8"/></svg>`;
    function sellerAppendLinkedMessage(target, rawText) {
      const value = String(rawText ?? '');
      const pattern = /(?:https?:\/\/|www\.)[^\s<>"'`]+/gi;
      let previous = 0;
      for (const match of value.matchAll(pattern)) {
        const position = match.index;
        if (position > previous) target.appendChild(document.createTextNode(value.slice(previous, position)));
        let display = match[0];
        // Keep sentence punctuation outside the link, including unmatched parentheses.
        while (/[.,!?;:]$/.test(display) || (display.endsWith(')') && (display.match(/\)/g) || []).length > (display.match(/\(/g) || []).length)) {
          display = display.slice(0, -1);
        }
        const tail = match[0].slice(display.length);
        try {
          const targetUrl = new URL(/^www\./i.test(display) ? 'https://' + display : display);
          if (display && /^https?:$/.test(targetUrl.protocol) && targetUrl.hostname && !targetUrl.username && !targetUrl.password) {
            const link = document.createElement('a');
            link.className = 'message-link';
            link.href = targetUrl.href;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = display;
            target.appendChild(link);
          } else target.appendChild(document.createTextNode(display));
        } catch (_) { target.appendChild(document.createTextNode(display)); }
        if (tail) target.appendChild(document.createTextNode(tail));
        previous = position + match[0].length;
      }
      if (previous < value.length) target.appendChild(document.createTextNode(value.slice(previous)));
    }
    function renderAttachment(container, attachment) {
      if (!attachment) return;
      const link = document.createElement('a');
      link.className = 'attachment';
      link.href = attachment;
      link.target = '_blank';
      link.rel = 'noopener';
      const extension = attachment.split('.').pop()?.toLowerCase() || '';
      if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(extension)) {
        const image = document.createElement('img');
        image.src = attachment;
        image.alt = 'Attachment';
        image.loading = 'lazy';
        link.appendChild(image);
      } else {
        const file = document.createElement('div');
        file.className = 'attachment-file';
        file.innerHTML = sellerPaperclipSvg;
        file.appendChild(document.createTextNode(' ' + (attachment.split('/').pop()?.split('?')[0] || 'File')));
        link.appendChild(file);
      }
      container.appendChild(link);
    }
    function orderTerminal(status) {
      return ['cancelled', 'canceled', 'rejected', 'declined', 'expired', 'completed']
        .includes(String(status || '').trim().toLowerCase());
    }
    function orderTerminalLabel(status) {
      const key = String(status || '').trim().toLowerCase();
      return ({cancelled:'Cancelled',canceled:'Cancelled',rejected:'Rejected',
        declined:'Rejected',expired:'Expired',completed:'Completed'})[key] || 'Closed';
    }
    function orderChatEvent(message) {
      if (!Number(message?.order_id)) return '';
      const body = String(message.message_text || '');
      const fromSeller = Number(message.sender_id) === Number(sellerId);
      const fromBuyer = !fromSeller;
      if (fromBuyer && message.is_order_opening) return 'request';
      if (fromSeller && /^Order #\d+ .*details are ready\./.test(body)) return 'details';
      if (fromBuyer && /^Buyer requested changes to Order #\d+\./.test(body)) return 'changes';
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
    function orderCardLabel(kind, isSeller, message) {
      const orderStatus = String(message.order_status || '').toLowerCase();
      if (orderTerminal(orderStatus)) return 'View Order';
      const stage = String(message.order_arrangement_status || '').toLowerCase();
      if (isSeller) {
        if (orderStatus === 'pending' && stage === 'awaiting_seller_acceptance') return 'Review & Accept';
        if (orderStatus === 'pending' && stage === 'awaiting_confirmation') return 'View Sent Details';
        if (orderStatus === 'pending' && stage === 'arranging') return 'Set Delivery Details';
        return 'View Order Details';
      }
      return orderStatus === 'pending' && stage === 'awaiting_confirmation'
        ? 'View Order Details' : 'View Order Details';
    }
    function orderEventLabel(kind) {
      return ({request:'Order Requested',details:'Details Sent',changes:'Changes Requested',
        confirmed:'Details Confirmed',accepted:'Order Accepted',processing:'Processing',completed:'Completed',paid:'Payment Confirmed',
        cancelled:'Order Cancelled',rejected:'Order Rejected'})[kind] || 'Order';
    }
    // One active action per order, attached to that order’s newest event card.
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
    function renderOrderCard(container, message, isLatest = true) {
      const kind = orderChatEvent(message);
      if (!kind) return false;
      const orderId = Number(message.order_id);
      const card = createTextElement('div', 'message-order-card order-chat-card');
      const closed = orderTerminal(message.order_status);
      if (closed) card.classList.add('is-closed-order');
      card.appendChild(orderCardPhoto(message.order_photo));
      const info = createTextElement('div', 'order-chat-info');
      info.appendChild(createTextElement('div', 'order-chat-kicker', 'Order #' + orderId + ' · ' + orderEventLabel(kind)));
      info.appendChild(createTextElement('div', 'order-chat-name', message.order_product || 'Order items'));
      info.appendChild(createTextElement('div', 'order-chat-meta',
        'Qty ' + (Number(message.order_quantity) || 1) + ' · ' + money(message.order_total)));
      if (closed) info.appendChild(createTextElement('span', 'order-chat-status is-closed', orderTerminalLabel(message.order_status)));
      if (kind === 'cancelled') {
        const reason = String(message.message_text || '').split('\n')
          .filter(line => /^(Reason|Details):/i.test(line)).join(' · ');
        if (reason) info.appendChild(createTextElement('div', 'order-chat-reason', reason));
      }
      if (kind === 'rejected') {
        const details = String(message.message_text || '').replace(/^.*?(?:Reason:\s*)/is, '').trim();
        if (details && details !== message.message_text) info.appendChild(createTextElement('div', 'order-chat-reason', 'Reason: ' + details));
      }
      if (isLatest) {
        const button = createTextElement('button', 'order-chat-action', orderCardLabel(kind, true, message));
        button.type = 'button';
        button.dataset.orderId = String(orderId);
        button.setAttribute('aria-label', orderCardLabel(kind, true, message) + ', Order #' + orderId);
        info.appendChild(button);
      } else {
        card.classList.add('is-history');
      }
      card.appendChild(info);
      container.appendChild(card);
      return true;
    }
    function appendDateSeparator(label) {
      if (!chatBody || !label) return;
      const separator = document.createElement('div');
      separator.className = 'date-separator';
      separator.dataset.dateLabel = label;
      separator.textContent = label;
      chatBody.appendChild(separator);
    }
    function hasDateSeparator(label) {
      return Array.from(chatBody?.querySelectorAll('.date-separator') || [])
        .some((element) => element.dataset.dateLabel === label);
    }
    function renderMessage(message, allowScroll = false, isLatest = true) {
      if (!chatBody || !message) return;
      if (message.date_label && !hasDateSeparator(message.date_label)) {
        appendDateSeparator(message.date_label);
      }
      const row = document.createElement('div');
      const outgoing = Number(message.sender_id) === sellerId;
      row.className = 'message-row ' + (outgoing ? 'outgoing' : 'incoming');
      row.dataset.messageId = String(message.message_id);
      if (Number(message.order_id) > 0) row.dataset.orderId = String(message.order_id);
      const content = document.createElement('div');
      content.className = 'message-content';
      const bubble = document.createElement('div');
      bubble.className = 'bubble';
      renderAttachment(bubble, message.attachment);
      const orderEvent = orderChatEvent(message);
      if (Number(message.order_id) > 0 && !orderEvent) {
        bubble.appendChild(createTextElement('span', 'lc-chat-order-tag', 'Order #' + Number(message.order_id)));
      }
      if (message.message_text && !orderEvent) {
        const text = document.createElement('div');
        sellerAppendLinkedMessage(text, message.message_text);
        bubble.appendChild(text);
      }
      if (isLatest && orderEvent) retireEarlierOrderAction(Number(message.order_id));
      renderOrderCard(bubble, message, isLatest);
      const meta = document.createElement('div');
      meta.className = 'message-time';
      meta.appendChild(createTextElement('span', '', message.time || ''));
      if (outgoing) {
        const status = message.is_read ? '✓✓' : '✓';
        const statusElement = createTextElement('span', 'message-status', status);
        meta.appendChild(statusElement);
      }
      bubble.appendChild(meta);
      content.appendChild(bubble);
      row.appendChild(content);
      chatBody.appendChild(row);
      lastMessageId = Math.max(lastMessageId, Number(message.message_id) || 0);
      if (allowScroll) {
        scrollChatToBottom(true);
      }
    }
    function renderMessages(messages, forceScroll = true, focusOrderId = 0) {
      clearChat();
      lastMessageId = 0;
      if (!messages.length) {
        if (emptyChat) {
          emptyChat.classList.add('show');
          chatBody.appendChild(emptyChat);
        }
        return;
      }
      if (emptyChat) {
        emptyChat.classList.remove('show');
      }
      let previousDate = '';
      const latestOrderEvents = latestOrderEventMessages(messages);
      messages.forEach((message) => {
        if (message.date_label !== previousDate) {
          appendDateSeparator(message.date_label || '');
          previousDate = message.date_label || '';
        }
        const row = document.createElement('div');
        const outgoing = Number(message.sender_id) === sellerId;
        row.className = 'message-row ' + (outgoing ? 'outgoing' : 'incoming');
        row.dataset.messageId = String(message.message_id);
        if (Number(message.order_id) > 0) row.dataset.orderId = String(message.order_id);
        const content = document.createElement('div');
        content.className = 'message-content';
        const bubble = document.createElement('div');
        bubble.className = 'bubble';
        renderAttachment(bubble, message.attachment);
        const orderEvent = orderChatEvent(message);
        if (message.message_text && !orderEvent) {
          const text = document.createElement('div');
          sellerAppendLinkedMessage(text, message.message_text);
          bubble.appendChild(text);
        }
        renderOrderCard(bubble, message,
          !orderEvent || latestOrderEvents.get(Number(message.order_id)) === Number(message.message_id));
        const meta = document.createElement('div');
        meta.className = 'message-time';
        meta.appendChild(createTextElement('span', '', message.time || ''));
        if (outgoing) {
          meta.appendChild(createTextElement('span', 'message-status', message.is_read ? '✓✓' : '✓'));
        }
        bubble.appendChild(meta);
        content.appendChild(bubble);
        row.appendChild(content);
        chatBody.appendChild(row);
        lastMessageId = Math.max(lastMessageId, Number(message.message_id) || 0);
      });
      if (forceScroll) showLatestMessage(focusOrderId);
      else scrollChatToBottom(false);
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
    function niceArrangementStatus(value) {
      const key = String(value || 'arranging')
        .trim()
        .toLowerCase();
      const labels = {
        arranging: 'Setting Details',
        awaiting_confirmation: 'Waiting for Buyer',
        awaiting_seller_acceptance: 'Ready to Accept',
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
      if (labels[key]) {
        return labels[key];
      }
      const status = key
        .replaceAll('_', ' ')
        .replaceAll('-', ' ');
      return status.replace(
        /\b\w/g,
        (letter) => letter.toUpperCase()
      );
    }
    function deliveryOptionLabel(value) {
      const key = String(value || '')
        .trim()
        .toLowerCase();
      const labels = {
        meetup: 'Meetup',
        pickup: 'Pickup',
        third_party_delivery: 'Third-Party Delivery'
      };
      return labels[key] || niceArrangementStatus(value);
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
      return niceArrangementStatus(value);
    }
    function paymentStatusLabel(
      paymentOption,
      paymentStatus
    ) {
      const optionKey = String(
        paymentOption || ''
      ).toLowerCase();
      const statusKey = String(
        paymentStatus || ''
      ).toLowerCase();
      if (
        optionKey === 'cash'
        && (!statusKey || statusKey === 'pending')
      ) {
        return 'Cash on Handover';
      }
      if (statusKey === 'paid') {
        return 'Paid';
      }
      if (statusKey === 'pending') {
        return 'Waiting for Payment';
      }
      if (statusKey === 'failed') {
        return 'Payment Failed';
      }
      if (
        statusKey === 'cancelled'
        || statusKey === 'canceled'
      ) {
        return 'Payment Cancelled';
      }
      if (statusKey === 'refunded') {
        return 'Refunded';
      }
      return statusKey
        ? niceArrangementStatus(statusKey)
        : 'Not Paid';
    }
    function syncPaymentOptionsForFulfillment() {
      if (
        !arrangementFulfillment
        || !arrangementPayment
      ) {
        return;
      }
      const fulfillment =
        arrangementFulfillment.value;
      const cashOption =
        arrangementPayment.querySelector(
          'option[value="cash"]'
        );
      const cashAllowed = [
        'meetup',
        'pickup'
      ].includes(fulfillment);
      if (cashOption) {
        cashOption.disabled =
          fulfillment !== ''
          && !cashAllowed;
      }
      if (
        arrangementPayment.value === 'cash'
        && fulfillment !== ''
        && !cashAllowed
      ) {
        arrangementPayment.value = '';
        setArrangementBottomFeedback(
          'For Third-Party Delivery, choose QR Ph.',
          'error'
        );
      }
      refreshSellerStyledSelects();
    }
    function arrangementEditable(order) {
      if (!order) return false;
      const orderStatus = String(order.order_status || '').toLowerCase();
      const arrangementState = String(
        order.arrangement_status || 'arranging'
      ).toLowerCase();
      return order.price_mode === 'fixed' && orderStatus === 'pending'
        && ['arranging', 'awaiting_confirmation'].includes(
          arrangementState
        );
    }
    function sellerAwaitingAcceptance(order) {
      if (!order) return false;
      const orderStatus = String(order.order_status || '').toLowerCase();
      const arrangementState = String(order.arrangement_status || '').toLowerCase();
      return orderStatus === 'pending'
        && arrangementState === 'awaiting_seller_acceptance'
        && Boolean(order.buyer_confirmed_at)
        && !order.seller_accepted_at;
    }
    function sellerStockIssue(order) {
      if (!order) return '';
      return String(order.acceptance_stock_message || '');
    }
    function sellerCanAcceptOrder(order) {
      return order?.price_mode === 'fixed'
        && sellerAwaitingAcceptance(order)
        && order.acceptance_stock_ready === true;
    }
    function refreshAcceptOrderButton(order) {
      if (!acceptOrderButton) return;
      const awaiting = order?.price_mode === 'fixed' && sellerAwaitingAcceptance(order);
      const issue = sellerStockIssue(order);
      acceptOrderButton.hidden = !awaiting;
      acceptOrderButton.disabled = !sellerCanAcceptOrder(order)
        || sellerAcceptingOrder;
      acceptOrderButton.textContent = awaiting && issue
        ? 'Stock Unavailable'
        : 'Accept Order';
      acceptOrderButton.title = awaiting && issue
        ? issue
        : 'Accept this confirmed order';
      acceptOrderButton.setAttribute('aria-describedby',
        'sellerActionReadiness');
    }
    function setArrangementFeedback(message = '', type = '') {
      // Only errors need a popup message. Success is shown by the action itself.
      if (type === 'error' && message) setArrangementBottomFeedback(message, 'error');
    }
    function setArrangementBottomFeedback(
      message = '',
      type = ''
    ) {
      if (!arrangementBottomFeedback) return;
      arrangementBottomFeedback.textContent = message;
      arrangementBottomFeedback.className =
        'arrangement-bottom-feedback';
      if (message) {
        arrangementBottomFeedback.classList.add('show');
        if (type) {
          arrangementBottomFeedback.classList.add(type);
        }
      }
    }
    function clearArrangementInvalidFields() {
      document
        .querySelectorAll('.arrangement-field.invalid')
        .forEach((field) => {
          field.classList.remove('invalid');
        });
    }
    function markArrangementInvalid(element) {
      if (!element) return;
      const wrapper = element.closest('.arrangement-field');
      if (wrapper) {
        wrapper.classList.add('invalid');
      }
    }
    // PHP validates arrangements in Asia/Manila. Match that timezone even when
    // Safari is running on a device configured to another region.
    const arrangementManilaClock = new Intl.DateTimeFormat('en-GB', {
      timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit', hourCycle: 'h23'
    });
    function arrangementManilaParts(date = new Date()) {
      const parts = {};
      arrangementManilaClock.formatToParts(date).forEach(part => {
        if (part.type !== 'literal') parts[part.type] = part.value;
      });
      return parts;
    }
    function localDateString(date = new Date()) {
      const parts = arrangementManilaParts(date);
      return `${parts.year}-${parts.month}-${parts.day}`;
    }
    function localTimeString(date = new Date()) {
      const parts = arrangementManilaParts(date);
      return `${parts.hour}:${parts.minute}`;
    }
    function updateArrangementDateTimeLimits() {
      if (!arrangementDate || !arrangementTime) return;
      const now = new Date();
      const today = localDateString(now);
      arrangementDate.min = today;
      if (arrangementDate.value === today) {
        arrangementTime.min = localTimeString(now);
      } else {
        arrangementTime.removeAttribute('min');
      }
    }
    function arrangementDateTimeError() {
      if (!arrangementDate?.value) {
        return '';
      }
      const now = new Date();
      const today = localDateString(now);
      const selectedDate = arrangementDate.value;
      if (selectedDate < today) {
        return 'The selected date has already passed. Choose today or a future date.';
      }
      if (
        selectedDate === today
        && arrangementTime?.value
        && arrangementTime.value < localTimeString(now)
      ) {
        return 'The selected time has already passed. Choose a later time.';
      }
      return '';
    }
    function clearArrangementDateTimeErrors() {
      [arrangementDateError, arrangementTimeError].forEach((hint) => {
        if (!hint) return;
        hint.textContent = '';
        hint.hidden = true;
      });
    }
    function showArrangementDateTimeError() {
      clearArrangementDateTimeErrors();
      [arrangementDate, arrangementTime].forEach((field) => {
        field?.closest('.arrangement-field')?.classList.remove('invalid');
      });
      const message = arrangementDateTimeError();
      if (!message) return '';
      const dateIsPast = arrangementDate?.value
        && arrangementDate.value < localDateString(new Date());
      const invalidField = dateIsPast ? arrangementDate : arrangementTime;
      const hint = dateIsPast ? arrangementDateError : arrangementTimeError;
      markArrangementInvalid(invalidField);
      if (hint) {
        hint.textContent = message;
        hint.hidden = false;
      }
      return message;
    }
    function validateFinalArrangement() {
      clearArrangementInvalidFields();
      setArrangementBottomFeedback();
      const checks = [];
      checks.push(
        {
          element: arrangementFulfillment,
          valid:
            arrangementFulfillment
            && sellerFulfillmentMethods.includes(arrangementFulfillment.value),
          message: 'Choose Meetup, Pickup, or Third-Party Delivery.'
        },
        {
          element: arrangementPayment,
          valid:
            arrangementPayment
            && arrangementPayment.value !== '',
          message: 'Choose payment.'
        },
        {
          element: arrangementLocation,
          valid:
            arrangementLocation
            && arrangementLocation.value.trim() !== '',
          message: 'Enter location.'
        },
        {
          element: arrangementDate,
          valid:
            arrangementDate
            && arrangementDate.value !== '',
          message: 'Choose a date.'
        },
        {
          element: arrangementTime,
          valid:
            arrangementTime
            && arrangementTime.value !== '',
          message: 'Choose a time.'
        }
      );
      const invalid = checks.filter((check) => !check.valid);
      const dateTimeError = showArrangementDateTimeError();
      if (dateTimeError) {
        const dateIsPast =
          arrangementDate?.value
          && arrangementDate.value < localDateString(new Date());
        invalid.push({
          element: dateIsPast ? arrangementDate : arrangementTime,
          message: dateTimeError
        });
      }
      if (
        arrangementPayment?.value === 'cash'
        && arrangementFulfillment?.value
        && !['meetup', 'pickup'].includes(
          arrangementFulfillment.value
        )
      ) {
        invalid.push({
          element: arrangementPayment,
          message:
            'Cash is only for Meetup or Pickup.'
        });
      }
      invalid.forEach((check) => {
        markArrangementInvalid(check.element);
      });
      if (!invalid.length) {
        return true;
      }
      const message =
        invalid.length === 1
          ? invalid[0].message
          : 'Missing: '
            + invalid
                .map((check) =>
                  check.message
                    .replace(/^Enter the /, '')
                    .replace(/^Choose the /, '')
                    .replace(/\.$/, '')
                )
                .join(', ')
            + '.';
      setArrangementBottomFeedback(
        message,
        'error'
      );
      const first = invalid[0].element;
      if (first) {
        first.scrollIntoView({
          behavior: 'smooth',
          block: 'center'
        });
        setTimeout(() => {
          first.focus({preventScroll: true});
        }, 280);
      }
      return false;
    }
    function renderArrangementItems(order) {
      if (!arrangementItems) return;
      arrangementItems.replaceChildren();
      const items = Array.isArray(order?.items) ? order.items : [];
      items.forEach(item => {
        const qty = Math.max(1, Number(item.quantity) || 1);
        const detail = item.price_breakdown || {};
        const addOns = Array.isArray(detail.add_ons) ? detail.add_ons : [];
        const row = document.createElement('article');
        row.className = 'arrangement-item-row lc-cart-order-item';
        row.appendChild(orderCardPhoto(item.product_photo, 'order-modal-photo'));
        const main = createTextElement('div', 'lc-order-line-main', '');
        main.appendChild(createTextElement('div', 'arrangement-item-name', item.product_name || 'Product'));
        main.appendChild(createTextElement('div', 'arrangement-item-meta', 'Qty: ' + qty));
        const breakdown = createTextElement('div', 'lc-price-breakdown', '');
        const moneyRow = (label, amount, klass = '') => {
          const line = createTextElement('div', 'lc-price-line ' + klass, '');
          line.append(createTextElement('span', '', label), createTextElement('strong', '', money(amount)));
          breakdown.appendChild(line);
        };
        moneyRow(addOns.length ? 'Item price × ' + qty : 'Unit price × ' + qty,
          addOns.length ? Number(detail.base_subtotal || 0) : Number(item.subtotal || 0));
        addOns.forEach(opt => {
          moneyRow(opt.name + ' × ' + qty, Number(opt.price_per_unit || 0) * qty, 'lc-price-addon');
          if (opt.details) breakdown.appendChild(createTextElement('div', 'lc-custom-text', opt.details));
        });
        moneyRow('Item total', Number(item.subtotal || 0), 'lc-price-total');
        main.appendChild(breakdown);
        row.append(main);
        arrangementItems.append(row);
      });
      arrangementItems.hidden = items.length === 0;
    }
    function calculateArrangementTotal() {
      if (!activeOrderDetails || !arrangementFinalTotal) return;
      arrangementFinalTotal.textContent = money(activeOrderDetails.current_total || 0);
    }
    function showSellerMessageNotice(message = '') {
      if (!sellerMessageNotice) return;
      sellerMessageNotice.textContent = message;
      sellerMessageNotice.hidden = !message;
    }
    function sellerNextActionState(order) {
      const status = String(order?.order_status || '').toLowerCase();
      const stage = String(order?.arrangement_status || 'arranging').toLowerCase();
      const payment = String(order?.payment_option || '').toLowerCase();
      const paymentStatus = String(order?.payment_status || '').toLowerCase();
      if (['cancelled', 'canceled', 'rejected', 'declined', 'expired'].includes(status)) {
        return {tone: 'closed', text: 'Request closed. No further order action is available.'};
      }
      if (status === 'completed') {
        return {tone: 'ready', text: 'Order completed.'};
      }
      if (status === 'processing') {
        return {tone: 'ready', text: 'Processing · Complete after handover.'};
      }
      if (status === 'pending') {
        if (stage === 'awaiting_seller_acceptance') {
          if (sellerStockIssue(order)) {
            return {tone: 'warning', text: sellerStockIssue(order)};
          }
          const fee = payment === 'cash'
            ? ' For Cash, the 5% platform fee is reserved from your wallet.' : '';
          return {tone: 'ready', text: 'Buyer confirmed · Ready to accept.' + fee};
        }
        if (stage === 'awaiting_confirmation') {
          return {tone: 'warning', text: 'Waiting for buyer confirmation'};
        }
        return {tone: 'warning', text: 'Set order details'};
      }
      if (status === 'accepted' || status === 'confirmed') {
        if (payment === 'paymongo' && paymentStatus !== 'paid') {
          return {tone: 'warning', text: 'Order accepted. Stock reserved; wait for confirmed QR Ph payment.'};
        }
        return {tone: 'ready', text: 'Order accepted. Stock reserved; you can start processing from Orders.'};
      }
      return {tone: 'closed', text: 'Open Orders to view the current status.'};
    }
    function refreshSellerActionGuide(order) {
      const guide = document.getElementById('sellerNextAction');
      if (!guide) return;
      if (!order) {
        guide.textContent = '';
        guide.hidden = true;
        return;
      }
      const state = sellerNextActionState(order);
      guide.dataset.tone = state.tone;
      guide.textContent = state.text;
      guide.hidden = false;
    }
    function sellerMissingArrangementDetails() {
      const order = activeOrderDetails;
      if (!order || !arrangementEditable(order)) return [];
      const missing = [];
      const delivery = arrangementFulfillment?.value || '';
      const payment = arrangementPayment?.value || '';
      if (!sellerFulfillmentMethods.includes(delivery)) {
        missing.push('Delivery');
      }
      if (!['cash', 'paymongo'].includes(payment)) {
        missing.push('Payment');
      } else if (payment === 'cash' && delivery && !['meetup', 'pickup'].includes(delivery)) {
        missing.push('QR Ph for delivery');
      }
      if (!arrangementLocation?.value.trim()) missing.push('Location');
      if (!arrangementDate?.value) missing.push('Date');
      if (!arrangementTime?.value) missing.push('Time');
      if (arrangementDate?.value && arrangementTime?.value && arrangementDateTimeError()) {
        missing.push('Valid date/time');
      }
      return missing;
    }
    function refreshSellerReadiness() {
      const guide = document.getElementById('sellerActionReadiness');
      if (!guide) return;
      guide.hidden = true;
      guide.textContent = '';
      const order = activeOrderDetails;
      if (!order) return;
      if (arrangementEditable(order)) {
        const missing = sellerMissingArrangementDetails();
        if (sendArrangementButton) {
          sendArrangementButton.disabled = sellerArrangementSubmitting || missing.length > 0;
          sendArrangementButton.title = missing.length ? 'Missing: ' + missing.join(', ') : '';
        }
      } else if (sellerAwaitingAcceptance(order) && sellerStockIssue(order)) {
        guide.hidden = false;
        guide.dataset.tone = 'warning';
        guide.textContent = sellerStockIssue(order);
      }
    }
    function updateLocationContext() {
      if (!arrangementLocation) return;
      const delivery = arrangementFulfillment?.value || '';
      const isCourier = delivery === 'third_party_delivery';
      arrangementLocation.placeholder = isCourier
        ? 'Enter delivery address'
        : delivery === 'meetup' ? 'Enter meetup place'
        : delivery === 'pickup' ? 'Enter pickup place'
        : 'Enter location';
      const label = document.getElementById('arrangementLocationLabel');
      if (label) label.firstChild.textContent = isCourier
        ? 'Delivery Address ' : delivery === 'pickup'
        ? 'Pickup Place ' : delivery === 'meetup'
        ? 'Meetup Place ' : 'Location ';
    }
    function syncSellerNativePickerState() {
      // The real date/time inputs still open the browser's picker when tapped.
      [[arrangementDate, 'sellerDatePicker'], [arrangementTime, 'sellerTimePicker']]
        .forEach(([field, id]) => {
          const holder = document.getElementById(id);
          if (!holder || !field) return;
          holder.classList.toggle('is-disabled', field.disabled);
          holder.classList.toggle('is-empty', field.value === '');
        });
    }
    function populateArrangementForm(
      order,
      preserveFeedback = false
    ) {
      if (!order) return;
      clearArrangementInvalidFields();
      if (!preserveFeedback) {
        setArrangementBottomFeedback();
      }
      const editable = arrangementEditable(order);
      renderArrangementItems(order);
      arrangementProduct.textContent = order.product_name || 'Product';
      arrangementOrderNumber.textContent = 'Order #' + order.order_id;
      const buyerName = document.getElementById('lcOrderBuyerName');
      if (buyerName) buyerName.textContent = selectedBuyerName || 'Buyer';
      const status = String(order.order_status || 'pending').toLowerCase();
      const stage = String(order.arrangement_status || 'arranging').toLowerCase();
      arrangementStatus.textContent = status === 'pending'
        ? (stage === 'awaiting_seller_acceptance' ? 'Ready to Accept'
          : stage === 'awaiting_confirmation' ? 'Waiting for Buyer' : 'Pending')
        : ({accepted:'Accepted',processing:'Processing',completed:'Completed',cancelled:'Cancelled',canceled:'Cancelled',rejected:'Rejected',declined:'Rejected',expired:'Expired'})[status] || 'Pending';
      arrangementStatus.hidden = false;
      arrangementFulfillment.value =
        order.fulfillment_method || '';
      syncPaymentOptionsForFulfillment();
      arrangementPayment.value =
        order.payment_option || '';
      if (arrangementPaymentStatus) {
        arrangementPaymentStatus.textContent =
          paymentStatusLabel(
            order.payment_option,
            order.payment_status
          );
      }
      arrangementLocation.value =
        order.arrangement_location || '';
      updateLocationContext();
      arrangementDate.value =
        order.arrangement_date || '';
      arrangementTime.value =
        order.arrangement_time
          ? String(order.arrangement_time).slice(0, 5)
          : '';
      updateArrangementDateTimeLimits();

      arrangementNotes.value =
        order.arrangement_notes || '';
      [
        arrangementFulfillment,
        arrangementPayment,
        arrangementLocation,
        arrangementDate,
        arrangementTime,
        arrangementNotes
      ].forEach((field) => {
        if (field) {
          field.disabled = !editable;
        }
      });
      if (editable) showArrangementDateTimeError();
      else clearArrangementDateTimeErrors();
      syncSellerNativePickerState();
      if (saveArrangementButton) {
        const draftAllowed = editable && stage === 'arranging';
        saveArrangementButton.hidden = !draftAllowed;
        saveArrangementButton.disabled = !draftAllowed;
      }
      if (sendArrangementButton) {
        sendArrangementButton.hidden = !editable;
        sendArrangementButton.disabled = !editable;
      }
      refreshAcceptOrderButton(order);
      calculateArrangementTotal();
      refreshSellerReadiness();
    }

    function applyOrderDetails(order) {
      activeOrderDetails = order || null;
      const closed = Boolean(order && orderTerminal(order.order_status));
      if (chatOrderClosed) {
        chatOrderClosed.hidden = !closed;
        if (closed) {
          chatClosedTitle.textContent = 'Order #' + order.order_id + ' · ' + orderTerminalLabel(order.order_status);
          chatClosedMessage.textContent = 'Transaction closed. You can still chat with the buyer, but cannot reopen this order.';
        }
      }
      if (order && chatBody) {
        chatBody.querySelectorAll('.order-chat-action[data-order-id="' + Number(order.order_id) + '"]')
          .forEach((button) => {
            if (closed) button.textContent = 'View Order';
            button.closest('.order-chat-card')?.classList.toggle('is-closed-order', closed);
          });
      }
      refreshSellerActionGuide(activeOrderDetails);
      if (!order) {
        selectedOrderId = 0;
        orderSummary?.classList.remove('show');
        if (manageArrangementButton) {
          manageArrangementButton.hidden = true;
          manageArrangementButton.classList.remove(
            'accept-ready'
          );
        }
        return;
      }
      selectedOrderId = Number(order.order_id) || 0;
      orderSummary?.classList.add('show');
      orderSummaryId.textContent =
        'Order #' + order.order_id;
      orderSummaryProduct.textContent =
        order.product_name || '';
      orderSummaryTotal.textContent = 'Total: ' + money(order.current_total || 0);
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
          : '';
      if (orderSummaryPaymentStatus) {
        orderSummaryPaymentStatus.textContent =
          order.payment_option
            ? '• '
              + paymentStatusLabel(
                order.payment_option,
                order.payment_status
              )
            : '';
      }
      const currentStatus = String(order.order_status || '').toLowerCase();
      orderSummaryStatus.textContent = ['processing','completed','accepted'].includes(currentStatus)
        ? currentStatus.charAt(0).toUpperCase() + currentStatus.slice(1)
        : (orderTerminal(currentStatus)
          ? orderTerminalLabel(order.order_status)
          : niceArrangementStatus(order.arrangement_status || 'arranging'));
      if (manageArrangementButton) {
        manageArrangementButton.hidden = false;
        if (sellerCanAcceptOrder(order)) {
          manageArrangementButton.textContent = 'Accept Order';
          manageArrangementButton.classList.add('accept-ready');
        } else {
          const stage = String(order.arrangement_status || '').toLowerCase();
          manageArrangementButton.textContent = orderTerminal(order.order_status)
            ? 'View Order'
            : sellerAwaitingAcceptance(order) && sellerStockIssue(order)
              ? 'Check Stock'
              : stage === 'awaiting_confirmation'
                ? 'Sent — Waiting for Buyer'
                : (arrangementEditable(order)
                  ? 'Set Delivery Details' : 'View Order Details');
          manageArrangementButton.classList.remove('accept-ready');
        }
      }
      populateArrangementForm(order);
    }
    async function loadOrderDetails(
      buyerId,
      preferredOrderId = 0
    ) {
      if (!buyerId) {
        applyOrderDetails(null);
        return null;
      }
      const formData = new FormData();
      formData.append('action', 'fetch_order_details');
      formData.append('buyer_id', String(buyerId));
      if (preferredOrderId > 0) {
        formData.append(
          'order_id',
          String(preferredOrderId)
        );
      }
      const data = await postForm(formData);
      applyOrderDetails(data.order || null);
      return data.order || null;
    }
    function updateOrderSummary(messages) {
      if (activeOrderDetails) {
        applyOrderDetails(activeOrderDetails);
        return;
      }
      const orderMessage = [...messages]
        .reverse()
        .find(
          (message) => Number(message.order_id) > 0
        );
      if (!orderMessage) {
        orderSummary?.classList.remove('show');
        manageArrangementButton.hidden = true;
        return;
      }
      loadOrderDetails(
        selectedBuyerId,
        Number(orderMessage.order_id)
      ).catch(() => {});
    }
    async function submitArrangement(action) {
      if (sellerArrangementSubmitting || orderTerminal(activeOrderDetails?.order_status)) return;
      if (
        !activeOrderDetails
        || !selectedBuyerId
        || !selectedOrderId
      ) {
        return;
      }
      setArrangementFeedback();
      setArrangementBottomFeedback();
      clearArrangementInvalidFields();
      updateArrangementDateTimeLimits();
      const dateTimeError = showArrangementDateTimeError();
      if (dateTimeError) {
        const dateIsPast =
          arrangementDate?.value
          && arrangementDate.value < localDateString(new Date());
        setArrangementBottomFeedback(dateTimeError, 'error');
        (dateIsPast ? arrangementDate : arrangementTime)?.scrollIntoView({
          behavior: 'smooth', block: 'center'
        });
        return;
      }
      if (
        action === 'send_arrangement_confirmation'
        && !validateFinalArrangement()
      ) {
        return;
      }
      sellerArrangementSubmitting = true;
      if (saveArrangementButton) {
        saveArrangementButton.disabled = true;
      }
      const originalSendLabel =
        sendArrangementButton?.textContent || '';
      if (sendArrangementButton) {
        sendArrangementButton.disabled = true;
        if (action === 'send_arrangement_confirmation') {
          sendArrangementButton.textContent = 'Sending...';
        }
      }
      const formData = new FormData();
      formData.append('action', action);
      formData.append(
        'buyer_id',
        String(selectedBuyerId)
      );
      formData.append(
        'order_id',
        String(selectedOrderId)
      );
      formData.append(
        'fulfillment_method',
        arrangementFulfillment?.value || ''
      );
      formData.append(
        'payment_option',
        arrangementPayment?.value || ''
      );
      formData.append(
        'arrangement_location',
        arrangementLocation?.value.trim() || ''
      );
      formData.append(
        'arrangement_date',
        arrangementDate?.value || ''
      );
      formData.append(
        'arrangement_time',
        arrangementTime?.value || ''
      );
      formData.append(
        'arrangement_notes',
        arrangementNotes?.value.trim() || ''
      );
      try {
        const data = await postForm(formData);
        applyOrderDetails(data.order || null);
        setArrangementFeedback(
          data.message || 'Saved.',
          'success'
        );
        setArrangementBottomFeedback(
          data.message || 'Saved.',
          'success'
        );
        if (action === 'send_arrangement_confirmation') {
          setTimeout(() => {
            arrangementDialog?.close();
          }, 450);
          await loadConversation(
            selectedBuyerId,
            true,
            selectedOrderId
          );
        }
      } catch (error) {
        if (activeOrderDetails) {
          populateArrangementForm(activeOrderDetails, true);
        }
        setArrangementFeedback(error.message, 'error');
        setArrangementBottomFeedback(error.message, 'error');
      } finally {
        sellerArrangementSubmitting = false;
        if (sendArrangementButton) {
          sendArrangementButton.textContent =
            originalSendLabel
            || 'Send to Buyer';
        }
        refreshSellerReadiness();
        if (
          saveArrangementButton
          && activeOrderDetails
          && arrangementEditable(activeOrderDetails)
        ) {
          saveArrangementButton.disabled = false;
        }
      }
    }
    async function acceptSellerOrder() {
      if (sellerAcceptingOrder) return;
      if (
        !activeOrderDetails
        || !selectedBuyerId
        || !selectedOrderId
        || !sellerCanAcceptOrder(activeOrderDetails)
      ) {
        setArrangementBottomFeedback(
          'This order is not ready to accept yet.',
          'error'
        );
        return;
      }
      sellerAcceptingOrder = true;
      const acceptingCash =
        String(
          activeOrderDetails.payment_option || ''
        ).toLowerCase() === 'cash';
      if (acceptOrderButton) {
        acceptOrderButton.disabled = true;
        acceptOrderButton.textContent =
          acceptingCash
            ? 'Checking Wallet...'
            : 'Checking stock...';
      }
      setArrangementFeedback(
        acceptingCash
          ? 'Checking your Wallet balance and stock before accepting the cash order...'
          : 'Checking stock before accepting the order...',
        ''
      );
      setArrangementBottomFeedback();
      const formData = new FormData();
      formData.append('action', 'accept_order');
      formData.append(
        'buyer_id',
        String(selectedBuyerId)
      );
      formData.append(
        'order_id',
        String(selectedOrderId)
      );
      try {
        const data = await postForm(formData);
        applyOrderDetails(
          data.order || null
        );
        setArrangementFeedback(
          data.message
            || 'Order accepted successfully.',
          'success'
        );
        if (
          data.cash_platform_fee !== null
          && data.cash_platform_fee !== undefined
        ) {
          const feeLabel = '₱' + Number(
            data.cash_platform_fee
          ).toLocaleString(
            'en-PH',
            {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2
            }
          );
          setArrangementBottomFeedback(
            'Order accepted. Stock is held and the 5% platform fee (' + feeLabel + ') was reserved from your Wallet.',
            'success'
          );
        } else {
          setArrangementBottomFeedback(
            'Order accepted. The requested stock is now held for this order.',
            'success'
          );
        }
        await loadConversation(
          selectedBuyerId,
          true,
          selectedOrderId
        );
      } catch (error) {
        // Refresh from the database so insufficient stock immediately
        // disables this button instead of offering another futile click.
        try {
          await loadOrderDetails(selectedBuyerId, selectedOrderId);
        } catch (refreshError) {
          // Preserve the original acceptance error as the useful message.
        }
        setArrangementFeedback(error.message, 'error');
        setArrangementBottomFeedback(error.message, 'error');
      } finally {
        sellerAcceptingOrder = false;
        refreshAcceptOrderButton(activeOrderDetails);
      }
    }
    let sellerBuyerProfileRequest = 0;
    function syncSellerBuyerProfilePosition() {
      if (!sellerBuyerProfileDialog?.open) return;
      const viewport = window.visualViewport;
      const height = viewport ? viewport.height : window.innerHeight;
      const top = viewport ? viewport.offsetTop : 0;
      sellerBuyerProfileDialog.style.setProperty('--buyer-profile-center', Math.round(top + height / 2) + 'px');
      sellerBuyerProfileDialog.style.setProperty('--buyer-profile-available', Math.max(160, Math.floor(height - 24)) + 'px');
    }
    function sellerBuyerProfileStars(rating) {
      const count = Math.max(0, Math.min(5, Math.round(Number(rating) || 0)));
      return '★'.repeat(count) + '☆'.repeat(5 - count);
    }
    function sellerRenderBuyerProfile(profile) {
      document.getElementById('sellerBuyerProfileName').textContent = profile.name || 'Buyer';
      document.getElementById('sellerBuyerProfileAvatar').textContent = (profile.name || 'Buyer').trim().split(/\s+/).slice(0, 2).map(part => part[0] || '').join('').toUpperCase() || 'B';
      document.getElementById('sellerBuyerProfileActive').hidden = profile.is_active !== true;
      const count = Math.max(0, Number(profile.rating_count) || 0);
      const average = Math.max(0, Math.min(5, Number(profile.average_rating) || 0));
      document.getElementById('sellerBuyerAverageRating').textContent = count ? average.toFixed(1) : '—';
      document.getElementById('sellerBuyerAverageStars').textContent = count ? sellerBuyerProfileStars(average) : '☆☆☆☆☆';
      document.getElementById('sellerBuyerRatingCount').textContent = count + (count === 1 ? ' rating given' : ' ratings given');
      const bars = document.getElementById('sellerBuyerRatingBars');
      bars.replaceChildren();
      for (let stars = 5; stars >= 1; stars--) {
        const number = Math.max(0, Number(profile.distribution?.[stars]) || 0);
        const row = createTextElement('div', 'seller-buyer-rating-bar');
        row.appendChild(createTextElement('span', '', stars + ' ★'));
        const track = createTextElement('div', 'seller-buyer-rating-bar-track');
        const fill = createTextElement('div', 'seller-buyer-rating-bar-fill');
        fill.style.width = (count ? number / count * 100 : 0) + '%';
        track.appendChild(fill);
        row.appendChild(track);
        row.appendChild(createTextElement('span', '', String(number)));
        bars.appendChild(row);
      }
      const list = document.getElementById('sellerBuyerReviewList');
      list.replaceChildren();
      const reviews = Array.isArray(profile.reviews) ? profile.reviews : [];
      if (!reviews.length) {
        list.appendChild(createTextElement('div', 'seller-buyer-review-empty', 'No reviews given yet.'));
      }
      for (const review of reviews) {
        const card = createTextElement('div', 'seller-buyer-review');
        const header = createTextElement('div', 'seller-buyer-review-top');
        header.appendChild(createTextElement('span', 'seller-buyer-review-shop', review.shop_name || 'Shop'));
        const date = String(review.created_at || '').slice(0, 10);
        header.appendChild(createTextElement('span', 'seller-buyer-review-date', date));
        card.appendChild(header);
        const stars = Math.max(1, Math.min(5, Number(review.rating) || 1));
        card.appendChild(createTextElement('div', 'seller-buyer-review-stars', sellerBuyerProfileStars(stars)));
        if (String(review.review_text || '').trim()) {
          card.appendChild(createTextElement('div', 'seller-buyer-review-text', review.review_text));
        }
        list.appendChild(card);
      }
      sellerBuyerProfileState.hidden = true;
      sellerBuyerReviewContents.hidden = false;
    }
    async function openSellerBuyerProfile() {
      if (!selectedBuyerId || !sellerBuyerProfileDialog || typeof sellerBuyerProfileDialog.showModal !== 'function') return;
      const requestedBuyerId = Number(selectedBuyerId);
      const request = ++sellerBuyerProfileRequest;
      sellerBuyerProfileState.hidden = false;
      sellerBuyerProfileState.textContent = 'Loading buyer reviews…';
      sellerBuyerReviewContents.hidden = true;
      document.getElementById('sellerBuyerProfileName').textContent = 'Buyer';
      document.getElementById('sellerBuyerProfileAvatar').textContent = 'B';
      document.getElementById('sellerBuyerProfileActive').hidden = true;
      if (!sellerBuyerProfileDialog.open) sellerBuyerProfileDialog.showModal();
      syncSellerBuyerProfilePosition();
      const data = new FormData();
      data.append('action', 'chat_buyer_profile');
      data.append('buyer_id', String(requestedBuyerId));
      try {
        const result = await postForm(data);
        if (request !== sellerBuyerProfileRequest || !sellerBuyerProfileDialog.open || Number(selectedBuyerId) !== requestedBuyerId) return;
        sellerRenderBuyerProfile(result.profile || {});
      } catch (error) {
        if (request !== sellerBuyerProfileRequest || !sellerBuyerProfileDialog.open) return;
        sellerBuyerProfileState.textContent = error.message || 'Buyer reviews are temporarily unavailable.';
      }
    }
    chatAvatar?.addEventListener('click', openSellerBuyerProfile);
    chatName?.addEventListener('click', openSellerBuyerProfile);
    sellerBuyerProfileClose?.addEventListener('click', () => sellerBuyerProfileDialog?.close());
    sellerBuyerProfileDialog?.addEventListener('click', event => {
      if (event.target === sellerBuyerProfileDialog) sellerBuyerProfileDialog.close();
    });
    sellerBuyerProfileDialog?.addEventListener('close', () => {
      sellerBuyerProfileRequest++;
      sellerBuyerProfileDialog.style.removeProperty('--buyer-profile-center');
      sellerBuyerProfileDialog.style.removeProperty('--buyer-profile-available');
      if (chatName?.isConnected) chatName.focus({preventScroll:true});
    });
    window.visualViewport?.addEventListener('resize', syncSellerBuyerProfilePosition);
    window.visualViewport?.addEventListener('scroll', syncSellerBuyerProfilePosition);
    window.addEventListener('resize', syncSellerBuyerProfilePosition);
    function updateHeader(conversation) {
      if (!conversation || !chatName) return;
      selectedBuyerName = conversation.buyer_name || 'Buyer';
      selectedBuyerInitials = conversation.initials || 'U';
      chatName.textContent = selectedBuyerName;
      chatAvatar.textContent = selectedBuyerInitials;
      if (conversation.product) {
        chatProduct.textContent = 'Product: ' + conversation.product;
      } else {
        chatProduct.textContent = 'Marketplace conversation';
      }
    }
    function getConversationFromCache(buyerId) {
      return initialConversations.find((conversation) => Number(conversation.buyer_id) === Number(buyerId)) || null;
    }
    function setActiveConversation(buyerId) {
      document.querySelectorAll('.conversation').forEach((item) => {
        item.setAttribute('aria-pressed', String(Number(item.dataset.buyerId) === Number(buyerId)));
        item.classList.toggle('active', Number(item.dataset.buyerId) === Number(buyerId));
      });
    }
    function openChatOnMobile() {
      if (messagesShell && window.matchMedia('(max-width: 760px)').matches) {
        messagesShell.classList.add('chat-open');
        document.body.classList.add('chat-mobile-open');
        syncSellerMessageViewport();
      }
    }
    function closeChatOnMobile() {
      if (messagesShell) {
        messagesShell.classList.remove('chat-open');
      }
      document.body.classList.remove('chat-mobile-open');
      document.body.classList.remove('keyboard-open');
      document.documentElement.style.setProperty(
        '--keyboard-inset',
        '0px'
      );
      if (messageInput && document.activeElement === messageInput) {
        messageInput.blur();
      }
    }
    async function postForm(formData) {
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
    // Use the shared Seller header search only on this Messages page.
    // The shared seller_search.php remains unchanged for other seller modules.
    const sellerHeaderBuyerSearch = document.querySelector('.seller-messages-search #productSearch');
    if (sellerHeaderBuyerSearch) {
      sellerHeaderBuyerSearch.setAttribute('aria-label', 'Search buyer conversations');
      sellerHeaderBuyerSearch.setAttribute('autocomplete', 'off');
      sellerHeaderBuyerSearch.addEventListener('focus', () => {
        // On phones the conversation list is hidden when a chat is open.
        if (window.matchMedia('(max-width: 760px)').matches && messagesShell?.classList.contains('chat-open')) {
          closeChatOnMobile();
        }
      });
      sellerHeaderBuyerSearch.addEventListener('input', () => {
        renderConversationList(initialConversations);
      });
    }
    async function loadConversation(
      buyerId,
      forceScroll = true,
      preferredOrderId = 0
    ) {
      if (!buyerId) return;
      if (sellerBuyerProfileDialog?.open) sellerBuyerProfileDialog.close();
      selectedBuyerId = Number(buyerId);
      selectedOrderId = Number(preferredOrderId) || 0;
      activeOrderDetails = null;
      lastMessageId = 0;
      setActiveConversation(selectedBuyerId);
      const conversation = getConversationFromCache(selectedBuyerId);
      if (conversation) {
        updateHeader(conversation);
      }
      clearChat();
      if (emptyChat) {
        emptyChat.classList.remove('show');
      }
      const formData = new FormData();
      formData.append('action', 'fetch_messages');
      formData.append('buyer_id', String(selectedBuyerId));
      try {
        const data = await postForm(formData);
        const messages = data.messages || [];
        renderMessages(messages, forceScroll, preferredOrderId);
        await loadOrderDetails(
          selectedBuyerId,
          selectedOrderId
        );
        await markConversationRead(selectedBuyerId);
        openChatOnMobile();
        // Mobile panel may have been display:none while the message DOM loaded.
        // Position after it becomes visible, without animating from the top.
        requestAnimationFrame(() => {
          showLatestMessage(preferredOrderId);
        });
        startPolling();
        startTypingPolling();
      } catch (error) {
        if (chatBody) {
          chatBody.innerHTML = '';
          const errorElement = document.createElement('div');
          errorElement.className = 'chat-error show';
          errorElement.textContent = error.message;
          chatBody.appendChild(errorElement);
        }
      }
    }
    function syncSellerNavUnreadFromConversationList() {
      let unreadTotal = 0;
      initialConversations.forEach((conversation) => {
        if (Number(conversation.buyer_id) !== Number(selectedBuyerId)) {
          unreadTotal += Math.max(0, Number(conversation.unread) || 0);
        }
      });
      if (typeof window.setSellerUnreadMessageCount === 'function') {
        window.setSellerUnreadMessageCount(unreadTotal);
      }
    }
    async function markConversationRead(buyerId) {
      if (!buyerId) return;
      const formData = new FormData();
      formData.append('action', 'mark_read');
      formData.append('buyer_id', String(buyerId));
      try {
        await postForm(formData);
        const active = document.querySelector('.conversation.active .badge');
        if (active) {
          active.remove();
        }
        syncSellerNavUnreadFromConversationList();
      } catch (error) {
      }
    }
    async function pollMessages() {
      if (!selectedBuyerId) return;
      const formData = new FormData();
      formData.append('action', 'fetch_messages');
      formData.append('buyer_id', String(selectedBuyerId));
      formData.append('after_id', String(lastMessageId));
      try {
        const data = await postForm(formData);
        const newMessages = data.messages || [];
        if (newMessages.length) {
          const shouldStick = chatBody.scrollHeight - chatBody.scrollTop - chatBody.clientHeight < 180;
          newMessages.forEach((message) => {
            const exists = chatBody.querySelector('[data-message-id="' + message.message_id + '"]');
            if (!exists) {
              if (!hasDateSeparator(message.date_label)) {
                appendDateSeparator(message.date_label || '');
              }
              renderMessage(message, false);
            }
          });
          const newestOrderMessage = [...newMessages]
            .reverse()
            .find(
              (message) => Number(message.order_id) > 0
            );
          if (newestOrderMessage) {
            if (!arrangementDialog?.open) {
              await loadOrderDetails(
                selectedBuyerId,
                selectedOrderId || Number(newestOrderMessage.order_id)
              );
            }
          }
          if (shouldStick) {
            scrollChatToBottom(true);
          }
          await markConversationRead(selectedBuyerId);
        }
        if (activeOrderDetails && selectedBuyerId && selectedOrderId
          && !arrangementDialog?.open
          && Date.now() - sellerLastOrderStatusRefresh > 9000) {
          sellerLastOrderStatusRefresh = Date.now();
          await loadOrderDetails(selectedBuyerId, selectedOrderId).catch(() => {});
        }
        await refreshSellerStockStatus();
        await refreshConversationList();
      } catch (error) {
      }
    }
    async function refreshSellerStockStatus() {
      if (!arrangementDialog?.open || !selectedBuyerId || !selectedOrderId
        || !sellerAwaitingAcceptance(activeOrderDetails)
        || sellerAcceptingOrder || sellerArrangementSubmitting
        || sellerStockRefreshInFlight
        || Date.now() - sellerLastStockRefresh < 9000) return;
      sellerStockRefreshInFlight = true;
      sellerLastStockRefresh = Date.now();
      const buyerId = selectedBuyerId;
      const orderId = selectedOrderId;
      try {
        const formData = new FormData();
        formData.append('action', 'fetch_order_details');
        formData.append('buyer_id', String(buyerId));
        formData.append('order_id', String(orderId));
        const data = await postForm(formData);
        if (arrangementDialog.open && buyerId === selectedBuyerId
          && orderId === selectedOrderId && !sellerAcceptingOrder) {
          applyOrderDetails(data.order || null);
        }
      } catch (error) {
        // A later refresh can retry; acceptance is still checked server-side.
      } finally {
        sellerStockRefreshInFlight = false;
      }
    }
    async function refreshConversationList() {
      const formData = new FormData();
      formData.append('action', 'fetch_conversations');
      try {
        const data = await postForm(formData);
        const conversations = data.conversations || [];
        initialConversations.splice(0, initialConversations.length, ...conversations);
        renderConversationList(conversations);
        const activeConversation = conversations.find((conversation) => Number(conversation.buyer_id) === Number(selectedBuyerId));
        if (activeConversation) {
          updateHeader(activeConversation);
        }
      } catch (error) {
      }
    }
    function renderConversationList(conversations) {
      if (!conversationItems) return;
      conversationItems.innerHTML = '';
      const query = (sellerHeaderBuyerSearch?.value || '').trim().toLocaleLowerCase();
      const visibleConversations = query
        ? conversations.filter(conversation => String(conversation.buyer_name || '').toLocaleLowerCase().includes(query))
        : conversations;
      if (!visibleConversations.length) {
        const empty = document.createElement('div');
        empty.className = 'empty-conversations show';
        empty.textContent = query ? 'No matching buyer conversations.' : 'No customer conversations yet.';
        conversationItems.appendChild(empty);
        syncSellerNavUnreadFromConversationList();
        return;
      }
      visibleConversations.forEach((conversation) => {
        const active = Number(conversation.buyer_id) === Number(selectedBuyerId);
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'conversation' + (active ? ' active' : '');
        item.dataset.buyerId = conversation.buyer_id;
        item.setAttribute('aria-pressed', active ? 'true' : 'false');
        const avatar = createTextElement('span', 'avatar', conversation.initials || 'U');
        const main = document.createElement('span');
        main.className = 'conversation-main';
        const nameRow = document.createElement('span');
        nameRow.className = 'conversation-name-row';
        nameRow.appendChild(createTextElement('span', 'conversation-name', conversation.buyer_name || 'Buyer'));
        nameRow.appendChild(createTextElement('span', 'conversation-time', conversation.latest_time || ''));
        main.appendChild(nameRow);
        if (conversation.product) {
          main.appendChild(createTextElement('span', 'conversation-product', conversation.product));
        }
        let preview = conversation.latest_message || '';
        if (!preview && conversation.latest_attachment) preview = 'Attachment';
        main.appendChild(createTextElement('span', 'conversation-preview', preview));
        item.appendChild(avatar);
        item.appendChild(main);
        if (Number(conversation.unread) > 0 && !active) {
          item.appendChild(createTextElement('span', 'badge', String(conversation.unread)));
        }
        conversationItems.appendChild(item);
      });
      syncSellerNavUnreadFromConversationList();
    }
    function startPolling() {
      if (pollTimer) {
        clearInterval(pollTimer);
      }
      pollTimer = setInterval(pollMessages, 3000);
    }
    async function updateTyping(isTyping) {
      if (!selectedBuyerId || lastTypingState === isTyping) return;
      lastTypingState = isTyping;
      const formData = new FormData();
      formData.append('action', 'typing');
      formData.append('buyer_id', String(selectedBuyerId));
      formData.append('is_typing', isTyping ? '1' : '0');
      try {
        await postForm(formData);
      } catch (error) {
      }
    }
    async function pollTyping() {
      if (!selectedBuyerId || !typingIndicator) return;
      const formData = new FormData();
      formData.append('action', 'fetch_typing');
      formData.append('buyer_id', String(selectedBuyerId));
      try {
        const data = await postForm(formData);
        typingIndicator.textContent = data.is_typing ? selectedBuyerName + ' is typing...' : '';
      } catch (error) {
        typingIndicator.textContent = '';
      }
    }
    function startTypingPolling() {
      window.clearInterval(window.sellerTypingPoller);
      window.sellerTypingPoller = setInterval(pollTyping, 2500);
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
      messageInput.style.height = Math.min(messageInput.scrollHeight, 110) + 'px';
    }
    function resetAttachment() {
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
    // Position the active order popup within Safari's visible viewport, including the keyboard.
    function syncSellerArrangementPosition() {
      if (!arrangementDialog?.open) return;
      const viewport = window.visualViewport;
      const height = viewport ? viewport.height : window.innerHeight;
      const top = viewport ? viewport.offsetTop : 0;
      arrangementDialog.style.setProperty('--seller-arrangement-center', Math.round(top + height / 2) + 'px');
      arrangementDialog.style.setProperty('--seller-arrangement-available', Math.max(160, Math.floor(height - 24)) + 'px');
    }
    function openSellerArrangementDialog() {
      if (!arrangementDialog || typeof arrangementDialog.showModal !== 'function') return;
      if (!arrangementDialog.open) arrangementDialog.showModal();
      document.body.classList.add('seller-arrangement-visible');
      const form = document.getElementById('arrangementForm');
      if (form) form.scrollTop = 0;
      syncSellerArrangementPosition();
    }
    arrangementDialog?.addEventListener('close', () => {
      document.body.classList.remove('seller-arrangement-visible');
      arrangementDialog.style.removeProperty('--seller-arrangement-center');
      arrangementDialog.style.removeProperty('--seller-arrangement-available');
    });
    // The native modal owns Escape / device Back: it closes before returning to chat.
    window.visualViewport?.addEventListener('resize', syncSellerArrangementPosition);
    window.visualViewport?.addEventListener('scroll', syncSellerArrangementPosition);
    window.addEventListener('resize', syncSellerArrangementPosition);
    chatBody?.addEventListener('click', async (event) => {
      const button = event.target.closest('button.order-chat-action[data-order-id]');
      if (!button || !selectedBuyerId) return;
      const orderId = Number(button.dataset.orderId);
      if (!Number.isSafeInteger(orderId) || orderId <= 0) return;
      button.disabled = true;
      try {
        const order = await loadOrderDetails(selectedBuyerId, orderId);
        if (!order || Number(order.order_id) !== orderId) throw new Error('Order not found.');
        sellerLastStockRefresh = Date.now();
        openSellerArrangementDialog();
      } catch (error) {
        showSellerMessageNotice(error.message);
      } finally {
        button.disabled = false;
      }
    });
    manageArrangementButton?.addEventListener(
      'click',
      async () => {
        if (!selectedBuyerId) return;
        try {
          await loadOrderDetails(selectedBuyerId, selectedOrderId);
          sellerLastStockRefresh = Date.now();
          if (!activeOrderDetails) {
            return;
          }
          populateArrangementForm(
            activeOrderDetails
          );
          openSellerArrangementDialog();
        } catch (error) {
          setArrangementBottomFeedback(
            error.message,
            'error'
          );
          openSellerArrangementDialog();
        }
      }
    );
    arrangementClose?.addEventListener(
      'click',
      () => arrangementDialog?.close()
    );
    arrangementDialog?.addEventListener(
      'click',
      (event) => {
        if (event.target === arrangementDialog) {
          arrangementDialog.close();
        }
      }
    );
    arrangementFulfillment?.addEventListener(
      'change',
      () => {
        updateLocationContext();
        syncPaymentOptionsForFulfillment();
        refreshSellerReadiness();
      }
    );
    [arrangementDate, arrangementTime].forEach(field => {
      field?.addEventListener('input', syncSellerNativePickerState);
      field?.addEventListener('change', syncSellerNativePickerState);
      field?.addEventListener('blur', syncSellerNativePickerState);
    });
    [
      arrangementFulfillment,
      arrangementPayment,
      arrangementLocation,
      arrangementDate,
      arrangementTime
    ].forEach((field) => {
      const onFieldChange = () => {
        if (field === arrangementDate || field === arrangementTime) {
          updateArrangementDateTimeLimits();
          const dateTimeError = showArrangementDateTimeError();
          setArrangementBottomFeedback(dateTimeError, dateTimeError ? 'error' : '');
        } else {
          field?.closest('.arrangement-field')?.classList.remove('invalid');
          setArrangementBottomFeedback();
        }
        refreshSellerReadiness();
      };
      field?.addEventListener('input', onFieldChange);
      field?.addEventListener('change', onFieldChange);
    });
    saveArrangementButton?.addEventListener(
      'click',
      () => submitArrangement('save_arrangement')
    );
    sendArrangementButton?.addEventListener(
      'click',
      () => submitArrangement(
        'send_arrangement_confirmation'
      )
    );
    acceptOrderButton?.addEventListener(
      'click',
      acceptSellerOrder
    );
    if (conversationItems) {
      conversationItems.addEventListener('click', (event) => {
        const item = event.target.closest('.conversation');
        if (!item) return;
        const buyerId = Number(item.dataset.buyerId);
        const conversation = getConversationFromCache(buyerId);
        const latestOrderId = Number(
          conversation?.latest_order_id || 0
        );
        if (!chatBody || !chatName || !messageForm) {
          const params = new URLSearchParams();
          params.set('buyer_id', String(buyerId));
          if (latestOrderId > 0) {
            params.set(
              'order_id',
              String(latestOrderId)
            );
          }
          window.location.href =
            window.location.pathname + '?' + params.toString();
          return;
        }
        loadConversation(
          buyerId,
          true,
          latestOrderId
        );
      });
    }
    if (chatBack) {
      chatBack.addEventListener('click', closeChatOnMobile);
    }
    if (messageInput) {
      messageInput.addEventListener('focus', () => {
        sellerMobileViewportBaseline = Math.max(
          sellerMobileViewportBaseline,
          window.innerHeight,
          document.documentElement.clientHeight,
          window.visualViewport
            ? window.visualViewport.height
                + window.visualViewport.offsetTop
            : 0
        );
        syncSellerMessageViewport();
        setTimeout(() => {
          syncSellerMessageViewport();
          scrollChatToBottom(true);
        }, 80);
        setTimeout(() => {
          syncSellerMessageViewport();
          scrollChatToBottom(true);
        }, 220);
        setTimeout(() => {
          syncSellerMessageViewport();
          scrollChatToBottom(true);
        }, 420);
      });
      messageInput.addEventListener('input', () => {
        autoResizeTextarea();
        updateSendButton();
        updateTyping(true);
        window.clearTimeout(typingTimer);
        typingTimer = window.setTimeout(() => {
          updateTyping(false);
        }, 1200);
      });
      messageInput.addEventListener('blur', () => {
        updateTyping(false);
        document.documentElement.style.setProperty(
          '--keyboard-inset',
          '0px'
        );
        document.body.classList.remove('keyboard-open');
        setTimeout(() => {
          sellerMobileViewportBaseline = Math.max(
            window.innerHeight,
            document.documentElement.clientHeight
          );
          syncSellerMessageViewport();
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
          resetAttachment();
          return;
        }
        if (file.size > 8 * 1024 * 1024) {
          attachmentInput.value = '';
          attachmentPreview?.classList.remove('show');
          showSellerMessageNotice('Attachments must be 8 MB or smaller.');
          updateSendButton();
          return;
        }
        attachmentPreview?.classList.add('show');
        if (attachmentPreviewName) {
          attachmentPreviewName.textContent = file.name;
        }
        showSellerMessageNotice();
        updateSendButton();
      });
    }
    if (attachmentRemove) {
      attachmentRemove.addEventListener('click', resetAttachment);
    }
    if (messageForm) {
      messageForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!selectedBuyerId) return;
        const messageText = messageInput.value.trim();
        const file = attachmentInput.files?.[0];
        if (!messageText && !file) return;
        sendButton.disabled = true;
        updateTyping(false);
        const formData = new FormData();
        formData.append('action', 'send_message');
        formData.append('buyer_id', String(selectedBuyerId));
        formData.append('message_text', messageText);
        if (selectedOrderId > 0 && activeOrderDetails && !orderTerminal(activeOrderDetails.order_status)) {
          formData.append('order_id', String(selectedOrderId));
        }
        if (file) {
          formData.append('attachment', file);
        }
        try {
          const data = await postForm(formData);
          showSellerMessageNotice();
          const message = data.message;
          if (!chatBody.querySelector('[data-message-id="' + message.message_id + '"]')) {
            if (!hasDateSeparator(message.date_label)) {
              appendDateSeparator(message.date_label || '');
            }
            renderMessage(message, true);
          }
          messageInput.value = '';
          autoResizeTextarea();
          resetAttachment();
          await refreshConversationList();
          await markConversationRead(selectedBuyerId);
        } catch (error) {
          showSellerMessageNotice(error.message);
          updateSendButton();
        }
      });
    }
    if (initialMessages.length) {
      renderMessages(initialMessages, true, linkedOrderId);
    }
    if (activeOrderDetails) {
      applyOrderDetails(activeOrderDetails);
    } else if (initialMessages.length) {
      updateOrderSummary(initialMessages);
    }
    if (selectedBuyerId) {
      markConversationRead(selectedBuyerId);
      startPolling();
      startTypingPolling();
      if (window.matchMedia('(max-width: 760px)').matches) {
        messagesShell?.classList.add('chat-open');
      }
      // Reposition after the order summary and mobile chat are actually visible.
      // The seller notification's validated order ID takes precedence over newer orders.
      requestAnimationFrame(() => {
        requestAnimationFrame(() => showLatestMessage(linkedOrderId));
      });
    }
    window.addEventListener('beforeunload', () => {
      updateTyping(false);
      if (pollTimer) {
        clearInterval(pollTimer);
      }
      if (window.sellerTypingPoller) {
        clearInterval(window.sellerTypingPoller);
      }
    });
  </script>
</body>
</html>
