<?php
require_once __DIR__ . '/buyer_auth.php';
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('X-Content-Type-Options: nosniff');

function buyerNotifJson(bool $success, string $message = '', array $data = [], int $status = 200): never
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data),
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Only these order notifications change the destination. Other order events keep opening Order Details. */
function buyerNotifOrderAction(string $type, string $title): string
{
    $type = strtolower(trim($type));
    $title = strtolower(trim($title));
    // Some old events used different type names; only accept a completion label on order events.
    if (preg_match('/^(?:buyer_)?order_(?:completed|received|delivered)(?:_|$)/', $type)
        || (str_contains($type, 'order_') && in_array($title, ['order completed', 'order received', 'order delivered'], true))) {
        return 'review';
    }
    if (preg_match('/^(?:buyer_)?order_(?:payment_required|pay_now_reminder|payment_reminder|qr_payment_required)(?:_|$)/', $type)
        || (str_contains($type, 'order_') && in_array($title, ['payment required', 'pay now reminder', 'payment reminder'], true))) {
        return 'messages';
    }
    if (preg_match('/^(?:buyer_)?order_(?:rejected|declined|seller_rejected|seller_declined)(?:_|$)/', $type)
        || (str_contains($type, 'order_') && (str_contains($title, 'rejected') || str_contains($title, 'declined')))) {
        return 'messages';
    }
    return 'orders';
}

function buyerNotifDestination(mysqli $conn, int $userId, string $referenceType, int $referenceId,
                               string $notificationType = '', string $notificationTitle = ''): ?string
{
    if ($referenceId <= 0) return null;
    if ($referenceType === 'order') {
        // Resolve the seller from this BUYER-owned order, not from notification text or request parameters.
        $stmt = $conn->prepare('SELECT order_id, seller_id FROM orders WHERE order_id = ? AND buyer_id = ? LIMIT 1');
        $stmt->bind_param('ii', $referenceId, $userId);
        try {
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc();
        } finally {
            $stmt->close();
        }
        if (!$order) return null;
        $action = buyerNotifOrderAction($notificationType, $notificationTitle);
        if ($action === 'messages' && (int)$order['seller_id'] > 0) {
            return 'messages.php?seller_id=' . (int)$order['seller_id'] . '&order_id=' . $referenceId;
        }
        return 'orders.php?order_id=' . $referenceId . ($action === 'review' ? '&open_review=1' : '');
    }
    if ($referenceType === 'seller_application') {
        $stmt = $conn->prepare('SELECT application_id FROM seller_applications WHERE application_id = ? AND user_id = ? LIMIT 1');
        $stmt->bind_param('ii', $referenceId, $userId);
        try {
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc() !== null;
            return $exists ? 'seller_application.php' : null;
        } finally {
            $stmt->close();
        }
    }
    return null;
}

$notificationBuyerId = (int)($buyerId ?? 0);
if ($notificationBuyerId <= 0) buyerNotifJson(false, 'Sign in as a buyer to view notifications.', [], 401);
if (!isset($_SESSION['buyer_notifications_csrf']) || !is_string($_SESSION['buyer_notifications_csrf'])) {
    $_SESSION['buyer_notifications_csrf'] = bin2hex(random_bytes(32));
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = (string)($_GET['action'] ?? 'list');
        if (!in_array($action, ['list', 'count'], true)) {
            buyerNotifJson(false, 'Invalid notification request.', [], 400);
        }
        $counter = $conn->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND (LEFT(type, 7) <> \'seller_\' OR LEFT(type, 19) = \'seller_application_\')');
        $counter->bind_param('i', $notificationBuyerId);
        $counter->execute();
        $counter->bind_result($unreadCount);
        $counter->fetch();
        $counter->close();
        $payload = ['unread_count' => (int)$unreadCount];
        if ($action === 'count') buyerNotifJson(true, '', $payload);
        $limit = max(1, min(30, (int)($_GET['limit'] ?? 20)));
        $stmt = $conn->prepare(
            'SELECT notification_id, type, title, message, reference_type, reference_id,
                    is_read, created_at
             FROM notifications WHERE user_id = ? AND (LEFT(type, 7) <> \'seller_\' OR LEFT(type, 19) = \'seller_application_\')
             ORDER BY created_at DESC, notification_id DESC LIMIT ?'
        );
        $stmt->bind_param('ii', $notificationBuyerId, $limit);
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
                'can_open' => in_array($row['reference_type'], ['order','seller_application'], true),
            ];
        }
        $payload['csrf_token'] = $_SESSION['buyer_notifications_csrf'];
        buyerNotifJson(true, '', $payload);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: GET, POST');
        buyerNotifJson(false, 'Unsupported request method.', [], 405);
    }
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    $expectedCsrf = (string)($_SESSION['buyer_notifications_csrf'] ?? '');
    if ($postedCsrf === '' || $expectedCsrf === '' || !hash_equals($expectedCsrf, $postedCsrf)) {
        buyerNotifJson(false, 'Session expired. Refresh and try again.', [], 403);
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'mark_all_read') {
        $stmt = $conn->prepare(
            'UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW())
             WHERE user_id = ? AND is_read = 0 AND (LEFT(type, 7) <> \'seller_\' OR LEFT(type, 19) = \'seller_application_\')'
        );
        $stmt->bind_param('i', $notificationBuyerId);
        $stmt->execute();
        $marked = $stmt->affected_rows;
        $stmt->close();
        buyerNotifJson(true, 'Notifications marked as read.', ['marked_count' => $marked, 'unread_count' => 0]);
    }
    if ($action === 'mark_read') {
        $notificationIdRaw = (string)($_POST['notification_id'] ?? '');
        if (!ctype_digit($notificationIdRaw) || $notificationIdRaw === '0'
            || strlen($notificationIdRaw) > 19) {
            buyerNotifJson(false, 'Invalid notification.', [], 422);
        }
        $notificationId = (int)$notificationIdRaw;
        $stmt = $conn->prepare(
            'SELECT type, title, reference_type, reference_id FROM notifications
             WHERE notification_id = ? AND user_id = ? AND (LEFT(type, 7) <> \'seller_\' OR LEFT(type, 19) = \'seller_application_\') LIMIT 1'
        );
        $stmt->bind_param('ii', $notificationId, $notificationBuyerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) buyerNotifJson(false, 'Notification not found.', [], 404);
        $url = buyerNotifDestination(
            $conn, $notificationBuyerId,
            (string)$row['reference_type'], (int)$row['reference_id'],
            (string)$row['type'], (string)$row['title']
        );
        $update = $conn->prepare(
            'UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW())
             WHERE notification_id = ? AND user_id = ? AND is_read = 0 AND (LEFT(type, 7) <> \'seller_\' OR LEFT(type, 19) = \'seller_application_\')'
        );
        $update->bind_param('ii', $notificationId, $notificationBuyerId);
        $update->execute();
        $update->close();
        buyerNotifJson(true, 'Notification opened.', ['url' => $url]);
    }
    buyerNotifJson(false, 'Invalid notification action.', [], 400);
} catch (Throwable $e) {
    error_log('LokalCraft buyer notifications: ' . $e->getMessage());
    buyerNotifJson(false, 'Notifications are unavailable right now. Please try again.', [], 503);
}
