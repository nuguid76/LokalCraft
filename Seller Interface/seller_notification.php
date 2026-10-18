<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('X-Content-Type-Options: nosniff');
function sellerNotifJson(bool $success, string $message = '', array $data = [], int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        array_merge(['success' => $success, 'message' => $message], $data),
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}
// Check the active interface BEFORE seller_auth.php normalizes session keys.
// This prevents a Buyer-mode request from becoming a Seller-mode request.
$sessionUserId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
$sessionSellerId = (int)($_SESSION['seller_id'] ?? $_SESSION['seller_user_id'] ?? 0);
if (($_SESSION['user_role'] ?? '') !== 'seller'
    || $sessionUserId <= 0 || $sessionSellerId <= 0
    || $sessionUserId !== $sessionSellerId) {
    sellerNotifJson(false, 'Sign in to your Seller account to view notifications.', [], 401);
}
require_once __DIR__ . '/seller_auth.php';
$notificationSellerId = (int)($sellerId ?? 0);
if ($notificationSellerId <= 0 || $notificationSellerId !== $sessionUserId) {
    sellerNotifJson(false, 'Seller session is invalid.', [], 403);
}
function sellerNotifDestination(mysqli $conn, int $sellerId, string $referenceType, int $referenceId): ?string
{
    if ($referenceType !== 'order' || $referenceId <= 0) {
        return null;
    }
    $stmt = $conn->prepare(
        'SELECT buyer_id FROM orders WHERE order_id = ? AND seller_id = ? LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to verify the Seller order.');
    }
    try {
        $stmt->bind_param('ii', $referenceId, $sellerId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
    } finally {
        $stmt->close();
    }
    if (!$order || (int)$order['buyer_id'] <= 0) {
        return null;
    }
    // The Seller Messages page is messages_seller.php (plural).
    if (!is_file(__DIR__ . '/messages_seller.php')) {
        return null;
    }
    return 'messages_seller.php?buyer_id=' . (int)$order['buyer_id']
        . '&order_id=' . $referenceId;
}
try {
    if (!isset($_SESSION['seller_notifications_csrf'])
        || !is_string($_SESSION['seller_notifications_csrf'])
        || strlen($_SESSION['seller_notifications_csrf']) !== 64) {
        $_SESSION['seller_notifications_csrf'] = bin2hex(random_bytes(32));
    }
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'list';
        if (!is_string($action) || !in_array($action, ['list', 'count'], true)) {
            sellerNotifJson(false, 'Invalid notification request.', [], 400);
        }
        $counter = $conn->prepare(
            "SELECT COUNT(*) FROM notifications
             WHERE user_id = ? AND is_read = 0 AND LEFT(type, 7) = 'seller_' AND LEFT(type, 19) <> 'seller_application_' AND reference_type = 'order'"
        );
        if (!$counter) {
            throw new RuntimeException('Unable to count Seller notifications.');
        }
        $counter->bind_param('i', $notificationSellerId);
        $counter->execute();
        $counter->bind_result($unreadCount);
        $counter->fetch();
        $counter->close();
        $payload = ['unread_count' => (int)$unreadCount];
        if ($action === 'count') {
            sellerNotifJson(true, '', $payload);
        }
        $rawLimit = $_GET['limit'] ?? 20;
        $requestedLimit = is_scalar($rawLimit)
            ? filter_var($rawLimit, FILTER_VALIDATE_INT)
            : false;
        $limit = $requestedLimit !== false ? max(1, min(30, $requestedLimit)) : 20;
        $stmt = $conn->prepare(
            "SELECT n.notification_id, n.type, n.title, n.message,
                    n.reference_type, n.reference_id, n.is_read, n.created_at,
                    CASE WHEN n.reference_type = 'order' AND EXISTS (
                        SELECT 1 FROM orders o
                        WHERE o.order_id = n.reference_id AND o.seller_id = n.user_id
                    ) THEN 1 ELSE 0 END AS can_open
             FROM notifications n
             WHERE n.user_id = ? AND LEFT(n.type, 7) = 'seller_' AND LEFT(n.type, 19) <> 'seller_application_' AND n.reference_type = 'order'
             ORDER BY n.created_at DESC, n.notification_id DESC LIMIT ?"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to load Seller notifications.');
        }
        $stmt->bind_param('ii', $notificationSellerId, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $payload['notifications'] = [];
        foreach ($rows as $row) {
            $payload['notifications'][] = [
                'id' => (int)$row['notification_id'],
                'type' => (string)$row['type'],
                'title' => (string)$row['title'],
                'message' => (string)$row['message'],
                'read' => (bool)$row['is_read'],
                'created_at' => (string)$row['created_at'],
                'can_open' => (bool)$row['can_open'],
            ];
        }
        $payload['csrf_token'] = $_SESSION['seller_notifications_csrf'];
        sellerNotifJson(true, '', $payload);
    }
    if ($method !== 'POST') {
        header('Allow: GET, POST');
        sellerNotifJson(false, 'Unsupported request method.', [], 405);
    }
    $postedCsrf = $_POST['csrf_token'] ?? null;
    $expectedCsrf = $_SESSION['seller_notifications_csrf'];
    if (!is_string($postedCsrf) || $postedCsrf === ''
        || !hash_equals($expectedCsrf, $postedCsrf)) {
        sellerNotifJson(false, 'Session expired. Refresh and try again.', [], 403);
    }
    $action = $_POST['action'] ?? null;
    if ($action === 'mark_all_read') {
        $stmt = $conn->prepare(
            "UPDATE notifications
             SET is_read = 1, read_at = COALESCE(read_at, NOW())
             WHERE user_id = ? AND is_read = 0 AND LEFT(type, 7) = 'seller_' AND LEFT(type, 19) <> 'seller_application_' AND reference_type = 'order'"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to mark Seller notifications as read.');
        }
        $stmt->bind_param('i', $notificationSellerId);
        $stmt->execute();
        $marked = $stmt->affected_rows;
        $stmt->close();
        sellerNotifJson(true, 'Seller notifications marked as read.', [
            'marked_count' => $marked,
            'unread_count' => 0,
        ]);
    }
    if ($action === 'mark_read') {
        $notificationIdRaw = $_POST['notification_id'] ?? null;
        if (!is_string($notificationIdRaw) || !ctype_digit($notificationIdRaw)
            || $notificationIdRaw === '0'
            || (string)(int)$notificationIdRaw !== $notificationIdRaw) {
            sellerNotifJson(false, 'Invalid notification.', [], 422);
        }
        $notificationId = (int)$notificationIdRaw;
        $stmt = $conn->prepare(
            "SELECT reference_type, reference_id FROM notifications
             WHERE notification_id = ? AND user_id = ?
               AND LEFT(type, 7) = 'seller_' AND LEFT(type, 19) <> 'seller_application_' AND reference_type = 'order' LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to find Seller notification.');
        }
        $stmt->bind_param('ii', $notificationId, $notificationSellerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            sellerNotifJson(false, 'Notification not found.', [], 404);
        }
        $url = sellerNotifDestination(
            $conn,
            $notificationSellerId,
            (string)$row['reference_type'],
            (int)$row['reference_id']
        );
        $update = $conn->prepare(
            "UPDATE notifications
             SET is_read = 1, read_at = COALESCE(read_at, NOW())
             WHERE notification_id = ? AND user_id = ? AND is_read = 0
               AND LEFT(type, 7) = 'seller_' AND LEFT(type, 19) <> 'seller_application_' AND reference_type = 'order'"
        );
        if (!$update) {
            throw new RuntimeException('Unable to update Seller notification.');
        }
        $update->bind_param('ii', $notificationId, $notificationSellerId);
        $update->execute();
        $update->close();
        sellerNotifJson(true, 'Notification opened.', ['url' => $url]);
    }
    sellerNotifJson(false, 'Invalid notification action.', [], 400);
} catch (Throwable $e) {
    error_log('LokalCraft Seller notifications: ' . $e->getMessage());
    sellerNotifJson(false, 'Seller notifications are unavailable right now. Please try again.', [], 503);
}
