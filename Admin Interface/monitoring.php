<?php
session_start();
require_once __DIR__ . '/../config/database.php';
$activePage = 'monitoring';
function e($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}
function money($value): string
{
    return '₱' . number_format((float)$value, 2);
}
function niceStatus($value): string
{
    return ucwords(str_replace('_', ' ', (string)$value));
}
function formatDate($value): string
{
    if (!$value) {
        return '—';
    }
    $timestamp = strtotime((string)$value);
    return $timestamp
        ? date('M d, Y · h:i A', $timestamp)
        : '—';
}
function statusClass($value): string
{
    return trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower(trim((string)$value))), '-');
}
function orderMonitorStatus($value): string
{
    $status = statusClass($value);
    return match ($status) {
        'confirmed' => 'accepted',
        'delivered' => 'completed',
        'canceled' => 'cancelled',
        default => $status,
    };
}
$adminId = 0;
foreach (['admin_id', 'user_id', 'logged_id', 'id'] as $key) {
    $value = $_SESSION[$key] ?? null;
    if (filter_var($value, FILTER_VALIDATE_INT) && (int)$value > 0) {
        $adminId = (int)$value;
        break;
    }
}
if ($adminId <= 0) {
    if (isset($_GET['monitor_data']) || isset($_GET['order_detail'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Session expired.'
        ]);
        exit;
    }
    header('Location: ../login.php');
    exit;
}
try {
    $adminStmt = $conn->prepare("
        SELECT
            firstname,
            lastname,
            AdminOrNot,
            account_status
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    if (!$adminStmt) {
        throw new RuntimeException('Unable to verify admin.');
    }
    $adminStmt->bind_param('i', $adminId);
    $adminStmt->execute();
    $admin = $adminStmt->get_result()->fetch_assoc();
    $adminStmt->close();
    $accountStatus = strtolower(
        trim((string)($admin['account_status'] ?? ''))
    );
    if (
        !$admin ||
        (int)$admin['AdminOrNot'] !== 1 ||
        in_array(
            $accountStatus,
            ['inactive', 'disabled', 'suspended', 'blocked', 'banned'],
            true
        )
    ) {
        http_response_code(403);
        exit('Access denied.');
    }
} catch (Throwable $e) {
    error_log('Order monitoring authentication: ' . $e->getMessage());
    http_response_code(503);
    exit('Unable to verify admin access.');
}
$adminName = trim(
    (string)$admin['firstname'] . ' ' . (string)$admin['lastname']
) ?: 'Admin';
$userName = $adminName;
function omReadable($value, string $fallback = 'Not agreed yet'): string
{
    $text = trim((string)($value ?? ''));
    return $text !== '' ? $text : $fallback;
}
function omArrangeDate($value): string
{
    $value = trim((string)($value ?? ''));
    if ($value === '' || $value === '0000-00-00') return 'Not agreed yet';
    $time = strtotime($value);
    return $time ? date('M j, Y', $time) : $value;
}
function omArrangeTime($value): string
{
    $value = trim((string)($value ?? ''));
    if ($value === '') return 'Not agreed yet';
    $time = strtotime($value);
    return $time ? date('g:i A', $time) : $value;
}
function omHasArrangements(mysqli $conn): bool
{
    $result = $conn->query("SHOW TABLES LIKE 'order_arrangements'");
    if (!$result) throw new RuntimeException('Unable to check order arrangements.');
    $available = $result->num_rows > 0;
    $result->free();
    return $available;
}
function loadOrderConversation(mysqli $conn, int $orderId): array
{
    $verify = $conn->prepare('SELECT buyer_id, seller_id FROM orders WHERE order_id = ? LIMIT 1');
    if (!$verify) throw new RuntimeException('Cannot verify order.');
    $verify->bind_param('i', $orderId);
    $verify->execute();
    $verify->bind_result($buyerId, $sellerId);
    $found = $verify->fetch();
    $verify->close();
    if (!$found) throw new DomainException('Order not found.');
    $items = [];
    $itemSql = "SELECT COALESCE(NULLIF(TRIM(p.product_name), ''), CONCAT('Product #', oi.product_id)) AS name,
        oi.quantity, oi.unit_price, oi.agreed_unit_price, oi.subtotal, oi.special_request
        FROM order_items oi LEFT JOIN products p ON p.product_id = oi.product_id
        WHERE oi.order_id = ? ORDER BY oi.order_item_id ASC";
    $stmt = $conn->prepare($itemSql);
    if (!$stmt) throw new RuntimeException('Cannot load order items.');
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $items[] = [
            'name' => $row['name'],
            'quantity' => (int)$row['quantity'],
            'unit_price' => money($row['unit_price']),
            'agreed_unit_price' => $row['agreed_unit_price'] === null ? null : money($row['agreed_unit_price']),
            'subtotal' => money($row['subtotal']),
            'special_request' => trim((string)($row['special_request'] ?? '')),
        ];
    }
    $stmt->close();
    // Only load messages explicitly tied to this order and exchanged between its buyer and seller.
    // Never infer an order from unrelated direct messages (order_id IS NULL).
    $chatSql = 'SELECT sender_id, message_text, attachment, sent_at FROM messages
        WHERE order_id = ? AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
        ORDER BY sent_at DESC, message_id DESC LIMIT 30';
    $stmt = $conn->prepare($chatSql);
    if (!$stmt) throw new RuntimeException('Cannot load order conversation.');
    $buyerId = (int)$buyerId;
    $sellerId = (int)$sellerId;
    $stmt->bind_param('iiiii', $orderId, $buyerId, $sellerId, $sellerId, $buyerId);
    $stmt->execute();
    $res = $stmt->get_result();
    $messages = [];
    while ($row = $res->fetch_assoc()) {
        $body = trim((string)($row['message_text'] ?? ''));
        $messages[] = [
            'sender' => (int)$row['sender_id'] === $buyerId ? 'Buyer' : 'Seller',
            'text' => $body !== '' ? $body : (!empty($row['attachment']) ? 'Attachment shared' : 'Empty message'),
            'time' => formatDate($row['sent_at']),
        ];
    }
    $stmt->close();
    return ['items' => $items, 'messages' => array_reverse($messages)];
}
if (isset($_GET['order_detail'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store, max-age=0');
    $detailId = filter_var($_GET['order_detail'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$detailId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid order ID.']);
        exit;
    }
    try {
        $details = loadOrderConversation($conn, (int)$detailId);
        echo json_encode(['success' => true] + $details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (DomainException $error) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => $error->getMessage()]);
    } catch (Throwable $error) {
        error_log('LokalCraft order detail: ' . $error->getMessage());
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Unable to load order conversation right now.']);
    }
    exit;
}
function loadOrderMonitoring(mysqli $conn): array
{
    $statsResult = $conn->query("
        SELECT
            COUNT(*) AS total,
            SUM(
                CASE
                    WHEN LOWER(TRIM(order_status)) = 'pending'
                    THEN 1 ELSE 0
                END
            ) AS pending,
            SUM(
                CASE
                    WHEN LOWER(TRIM(order_status)) IN ('accepted', 'confirmed')
                    THEN 1 ELSE 0
                END
            ) AS accepted,
            SUM(
                CASE
                    WHEN LOWER(TRIM(order_status)) = 'processing'
                    THEN 1 ELSE 0
                END
            ) AS processing,
            SUM(
                CASE
                    WHEN LOWER(TRIM(order_status)) IN ('completed', 'delivered')
                    THEN 1 ELSE 0
                END
            ) AS completed,
            SUM(
                CASE
                    WHEN LOWER(TRIM(order_status)) IN ('cancelled', 'canceled')
                    THEN 1 ELSE 0
                END
            ) AS cancelled
        FROM orders
    ");
    if (!$statsResult) {
        throw new RuntimeException('Unable to load order totals.');
    }
    $stats = $statsResult->fetch_assoc();
    $statsResult->free();
    foreach ($stats as $key => $value) {
        $stats[$key] = (int)$value;
    }
    // The buyer/seller's fulfillment agreement is stored in order_arrangements, not orders.delivery_method.
    $hasArrangement = omHasArrangements($conn);
    $arrangementSelect = $hasArrangement ? "
            oa.fulfillment_method, oa.payment_option, oa.arrangement_location,
            oa.arrangement_date, oa.arrangement_time, oa.arrangement_notes,
            oa.arrangement_status, oa.buyer_confirmed_at, oa.seller_confirmed_at,
            oa.seller_accepted_at" : "
            NULL AS fulfillment_method, NULL AS payment_option, NULL AS arrangement_location,
            NULL AS arrangement_date, NULL AS arrangement_time, NULL AS arrangement_notes,
            NULL AS arrangement_status, NULL AS buyer_confirmed_at, NULL AS seller_confirmed_at,
            NULL AS seller_accepted_at";
    $arrangementJoin = $hasArrangement ? "LEFT JOIN order_arrangements oa
        ON oa.arrangement_id = (SELECT MAX(oa2.arrangement_id) FROM order_arrangements oa2 WHERE oa2.order_id = o.order_id)" : '';
    $sql = "
        SELECT
            o.order_id,
            o.buyer_id,
            o.seller_id,
            o.total_amount,
            {$arrangementSelect},
            o.order_status,
            o.notes,
            o.ordered_at,
            COALESCE(
                NULLIF(
                    TRIM(
                        CONCAT_WS(
                            ' ',
                            buyer.firstname,
                            buyer.lastname
                        )
                    ),
                    ''
                ),
                'Unknown Buyer'
            ) AS buyer_name,
            COALESCE(
                (
                    SELECT NULLIF(TRIM(b.businessname), '')
                    FROM businessinfo b
                    WHERE b.user_id = o.seller_id
                    ORDER BY b.business_id DESC
                    LIMIT 1
                ),
                NULLIF(
                    TRIM(
                        CONCAT_WS(
                            ' ',
                            seller.firstname,
                            seller.lastname
                        )
                    ),
                    ''
                ),
                'Unknown Seller'
            ) AS seller_name,
            items.items_summary,
            items.total_quantity,
            pay.payment_method,
            pay.payment_provider,
            pay.payment_status,
            pay.amount AS paid_amount,
            pay.payment_reference,
            pay.paid_at
        FROM orders o
        LEFT JOIN users buyer
            ON buyer.id = o.buyer_id
        LEFT JOIN users seller
            ON seller.id = o.seller_id
        LEFT JOIN (
            SELECT
                oi.order_id,
                GROUP_CONCAT(
                    CONCAT(
                        oi.quantity,
                        ' × ',
                        COALESCE(
                            NULLIF(TRIM(p.product_name), ''),
                            'Product'
                        )
                    )
                    ORDER BY oi.order_item_id ASC
                    SEPARATOR ', '
                ) AS items_summary,
                SUM(oi.quantity) AS total_quantity
            FROM order_items oi
            LEFT JOIN products p
                ON p.product_id = oi.product_id
            GROUP BY oi.order_id
        ) items
            ON items.order_id = o.order_id
        {$arrangementJoin}
        LEFT JOIN payments pay
            ON pay.payment_id = (
                SELECT MAX(p2.payment_id)
                FROM payments p2
                WHERE p2.order_id = o.order_id
            )
        ORDER BY o.ordered_at DESC, o.order_id DESC
    ";
    $result = $conn->query($sql);
    if (!$result) {
        throw new RuntimeException('Unable to load order records.');
    }
    $orders = [];
    while ($row = $result->fetch_assoc()) {
        $row['order_id'] = (int)$row['order_id'];
        $row['total_amount'] = (float)$row['total_amount'];
        $row['order_status'] = strtolower(
            trim((string)($row['order_status'] ?? 'pending'))
        );
        $row['payment_status'] = $row['payment_status'] === null
            ? 'not recorded'
            : strtolower(trim((string)$row['payment_status']));
        $row['items_summary'] = trim(
            (string)($row['items_summary'] ?? '')
        ) ?: 'No item details recorded';
        $row['total_quantity'] = (int)($row['total_quantity'] ?? 0);
        $orders[] = $row;
    }
    $result->free();
    return [
        'stats' => $stats,
        'orders' => $orders,
        'updated_at' => date('h:i A')
    ];
}
function renderOrderRows(array $orders): string
{
    ob_start();
    foreach ($orders as $order):
        $orderStatus = orderMonitorStatus($order['order_status']);
        $searchText = strtolower(implode(' ', [
            $order['order_id'], $order['buyer_name'], $order['seller_name'],
            $order['items_summary'], $order['order_status'], $order['payment_status'],
            $order['payment_method'] ?? '', $order['arrangement_location'] ?? '', $order['fulfillment_method'] ?? '',
        ]));
        $buyerParts = preg_split('/\s+/u', trim($order['buyer_name'])) ?: [];
        $buyerInitials = strtoupper(substr($buyerParts[0] ?? 'U', 0, 1));
        if (count($buyerParts) > 1) {
            $buyerInitials .= strtoupper(substr($buyerParts[count($buyerParts) - 1], 0, 1));
        }
        ?>
        <button type="button" class="monitor-order" data-order
            data-status="<?php echo e($orderStatus); ?>"
            data-search="<?php echo e($searchText); ?>"
            data-order-id="<?php echo (int)$order['order_id']; ?>"
            data-customer="<?php echo e($order['buyer_name']); ?>"
            data-seller="<?php echo e($order['seller_name']); ?>"
            data-products="<?php echo e($order['items_summary']); ?>"
            data-quantity="<?php echo (int)$order['total_quantity']; ?>"
            data-total="<?php echo e(money($order['total_amount'])); ?>"
            data-order-status="<?php echo e(niceStatus($orderStatus)); ?>"
            data-payment-status="<?php echo e(niceStatus($order['payment_status'])); ?>"
            data-payment="<?php echo e($order['payment_method'] ?: 'Not recorded'); ?>"
            data-paid-at="<?php echo e(formatDate($order['paid_at'])); ?>"
            data-delivery="<?php echo e(niceStatus(omReadable($order['fulfillment_method'], 'Not agreed yet'))); ?>"
            data-location="<?php echo e(omReadable($order['arrangement_location'])); ?>"
            data-meet-date="<?php echo e(omArrangeDate($order['arrangement_date'])); ?>"
            data-meet-time="<?php echo e(omArrangeTime($order['arrangement_time'])); ?>"
            data-arrangement-status="<?php echo e(niceStatus(omReadable($order['arrangement_status'], 'No agreement yet'))); ?>"
            data-arrangement-notes="<?php echo e(omReadable($order['arrangement_notes'], 'None')); ?>"
            data-buyer-confirmed="<?php echo e($order['buyer_confirmed_at'] ? formatDate($order['buyer_confirmed_at']) : 'Awaiting confirmation'); ?>"
            data-seller-confirmed="<?php echo e($order['seller_confirmed_at'] ? formatDate($order['seller_confirmed_at']) : 'Awaiting confirmation'); ?>"
            data-payment-option="<?php echo e(niceStatus(omReadable($order['payment_option'], 'Not selected'))); ?>"
            data-payment-provider="<?php echo e(niceStatus(omReadable($order['payment_provider'], 'Not recorded'))); ?>"
            data-payment-amount="<?php echo e($order['paid_amount'] === null ? 'Not recorded' : money($order['paid_amount'])); ?>"
            data-payment-reference="<?php echo e(omReadable($order['payment_reference'], 'Not recorded')); ?>"
            data-date="<?php echo e(formatDate($order['ordered_at'])); ?>"
            data-notes="<?php echo e($order['notes'] ?: 'None'); ?>"
            aria-label="View order number <?php echo (int)$order['order_id']; ?> placed by <?php echo e($order['buyer_name']); ?>">
            <span class="monitor-order-top">
                <span class="monitor-order-id">#<?php echo (int)$order['order_id']; ?><small>ORDER</small></span>
                <span class="monitor-status <?php echo e($orderStatus); ?>"><?php echo e(niceStatus($orderStatus)); ?></span>
            </span>
            <span class="monitor-customer">
                <span class="monitor-customer-avatar" aria-hidden="true"><?php echo e($buyerInitials); ?></span>
                <span class="monitor-customer-info"><strong><?php echo e($order['buyer_name']); ?></strong><small>Customer</small></span>
            </span>
            <span class="monitor-order-store"><span class="monitor-store-icon" aria-hidden="true">▣</span><span><?php echo e($order['seller_name']); ?></span></span>
            <span class="monitor-product-line"><?php echo e($order['items_summary']); ?></span>
            <span class="monitor-order-bottom">
                <span><strong><?php echo e(money($order['total_amount'])); ?></strong><small><?php echo (int)$order['total_quantity']; ?> item(s)</small></span>
                <span class="monitor-order-date"><?php echo e(formatDate($order['ordered_at'])); ?></span>
            </span>
            <span class="monitor-order-link">View order details <span aria-hidden="true">↗</span></span>
        </button>
    <?php endforeach; ?>
    <div class="monitor-empty" id="empty-orders" <?php echo $orders ? 'hidden' : ''; ?>>
        <span class="monitor-empty-icon" aria-hidden="true">▤</span>
        <strong><?php echo $orders ? 'No matching orders' : 'No orders recorded yet'; ?></strong>
        <span><?php echo $orders ? 'Try another search or filter.' : 'New customer orders will appear here automatically.'; ?></span>
    </div>
    <?php
    return ob_get_clean();
}
$data = [
    'stats' => ['total' => 0, 'pending' => 0, 'accepted' => 0, 'processing' => 0, 'completed' => 0, 'cancelled' => 0],
    'orders' => [],
    'updated_at' => '—',
];
$errorMessage = '';
try {
    $data = loadOrderMonitoring($conn);
} catch (Throwable $e) {
    error_log('LokalCraft order monitoring: ' . $e->getMessage());
    $errorMessage = 'Unable to load orders at the moment. Please try again later.';
}
$filterCounts = ['all' => count($data['orders']), 'pending' => 0, 'accepted' => 0, 'processing' => 0, 'completed' => 0, 'cancelled' => 0];
foreach ($data['orders'] as $order) {
    $key = orderMonitorStatus($order['order_status']);
    if (isset($filterCounts[$key])) $filterCounts[$key]++;
}
header('Cache-Control: private, no-store, max-age=0');
if (isset($_GET['monitor_data'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ($errorMessage !== '') {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => $errorMessage]);
        exit;
    }
    echo json_encode([
        'success' => true,
        'stats' => $data['stats'],
        'filter_counts' => $filterCounts,
        'rows_html' => renderOrderRows($data['orders']),
        'updated_at' => $data['updated_at'],
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LokalCraft | Order Monitoring</title>
<style>
:root{--monitor-bg:#f7f6f4;--monitor-card:#fff;--monitor-line:#eae6e2;--monitor-text:#2e3135;--monitor-muted:#82848b;--monitor-accent:#d98138;--monitor-orange-soft:#fff2e6;--monitor-shadow:0 6px 22px rgba(40,32,24,.035)}
*{box-sizing:border-box}
html,body{min-width:0;max-width:100%}
body{margin:0;background:var(--monitor-bg);color:var(--monitor-text);font-family:Inter,"Segoe UI",Arial,sans-serif;font-size:14px}
body.monitor-modal-open{overflow:hidden}
.page{display:flex;min-height:100vh;min-width:0;background:var(--monitor-bg)}
.page>.content{flex:1;min-width:0;max-width:100%;padding:31px 30px 40px}
.monitor-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:24px}
.monitor-heading h1{margin:0 0 7px;font-size:clamp(1.5rem,2vw,1.95rem);font-weight:750;letter-spacing:-.7px;line-height:1.22}
.monitor-heading p{margin:0;color:var(--monitor-muted);font-size:.85rem;line-height:1.5}
.monitor-sync{display:flex;align-items:center;gap:7px;flex-shrink:0;margin-top:8px;color:#8a8988;font-size:.72rem}
.monitor-sync-dot{width:7px;height:7px;border-radius:100%;background:#66b68a;box-shadow:0 0 0 3px #e2f4e9}
.monitor-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px;margin-bottom:22px}
.monitor-stat{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:18px 17px;min-width:0;min-height:110px;border:1px solid var(--monitor-line);background:var(--monitor-card);border-radius:15px;box-shadow:var(--monitor-shadow)}
.monitor-stat-content{min-width:0}.monitor-stat-title{display:block;color:#777a80;font-size:.77rem;font-weight:600;line-height:1.35}.monitor-stat-value{display:block;margin-top:8px;font-size:1.85rem;font-weight:750;line-height:1.05;letter-spacing:-.7px;color:#2e3033}.monitor-stat-detail{display:block;margin-top:7px;font-size:.7rem;color:#96989a;line-height:1.3}
.monitor-stat-icon{display:grid;place-items:center;flex:0 0 39px;width:39px;height:39px;border-radius:12px;font-size:1.22rem;font-weight:650}
.monitor-stat.all .monitor-stat-icon{background:#f3e9e1;color:#bb8051}.monitor-stat.pending .monitor-stat-icon{background:#fff4df;color:#c79636}.monitor-stat.accepted .monitor-stat-icon{background:#e9f1ff;color:#5386d1}.monitor-stat.completed .monitor-stat-icon{background:#e5f5ec;color:#479e6d}
.monitor-controls{padding:19px 20px 17px;border:1px solid var(--monitor-line);border-radius:16px;background:#fff;box-shadow:var(--monitor-shadow);min-width:0}
.monitor-section-head{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:17px}
.monitor-section-head h2{margin:0 0 4px;font-size:1rem;letter-spacing:-.2px}.monitor-section-head p{margin:0;color:var(--monitor-muted);font-size:.77rem}
.monitor-search{position:relative;width:min(330px,100%);max-width:100%}.monitor-search svg{position:absolute;top:50%;left:13px;transform:translateY(-50%);width:17px;height:17px;color:#999;pointer-events:none}.monitor-search input{display:block;width:100%;min-width:0;height:40px;padding:9px 12px 9px 39px;border:1px solid #e6e3df;border-radius:10px;background:#faf9f7;color:#3d3d3d;font:inherit;font-size:.83rem;outline:0}.monitor-search input:focus{border-color:#de9a63;background:#fff;box-shadow:0 0 0 3px #fff2e7}
.monitor-filters{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;margin-bottom:19px}
.monitor-filter{display:flex;align-items:center;justify-content:center;gap:7px;min-width:0;min-height:42px;padding:8px 6px;border:1px solid #ebe7e3;border-radius:10px;background:#faf9f8;color:#77716d;cursor:pointer;font:inherit;font-size:.77rem;font-weight:650;transition:background .18s,border-color .18s,color .18s}.monitor-filter:hover{background:#fff2e7;border-color:#efd5bd;color:#a46532}.monitor-filter.active{background:#e9944e;color:#fff;border-color:#e9944e;box-shadow:0 3px 8px rgba(215,124,48,.12)}.monitor-filter-count{font-size:.69rem;border-radius:6px;background:rgba(50,40,30,.055);padding:2px 5px;min-width:20px;text-align:center;color:inherit}.monitor-filter.active .monitor-filter-count{background:rgba(255,255,255,.22)}
.monitor-order-list{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:13px;min-width:0}.monitor-order{appearance:none;display:flex;flex-direction:column;width:100%;min-width:0;min-height:274px;padding:16px;border:1px solid var(--monitor-line);border-radius:15px;background:#fff;text-align:left;font:inherit;color:inherit;cursor:pointer;box-shadow:var(--monitor-shadow);transition:border-color .18s,box-shadow .18s,transform .18s}.monitor-order:hover{border-color:#e9c6a7;box-shadow:0 8px 22px rgba(86,53,25,.075);transform:translateY(-2px)}.monitor-order:focus-visible,.monitor-filter:focus-visible{outline:3px solid #e0a15e;outline-offset:2px}
.monitor-order-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:14px}.monitor-order-id{font-weight:780;color:#3d3733;font-size:.97rem;line-height:1.05}.monitor-order-id small{display:block;margin-top:4px;color:#a29b94;font-size:.59rem;font-weight:700;letter-spacing:.9px}.monitor-status{display:inline-flex;align-items:center;justify-content:center;padding:5px 9px;min-height:25px;border-radius:7px;background:#f0f0f0;color:#63666a;font-size:.69rem;font-weight:700;white-space:nowrap;text-transform:capitalize}
.monitor-status.pending,.monitor-status.for-verification{background:#fff2da;color:#b78428}.monitor-status.accepted,.monitor-status.processing,.monitor-status.shipped{background:#eaf2ff;color:#5079be}.monitor-status.completed{background:#e6f5ed;color:#3e9265}.monitor-status.cancelled,.monitor-status.canceled,.monitor-status.rejected,.monitor-status.failed{background:#fdebe9;color:#be5e54}
.monitor-customer{display:flex;align-items:center;gap:9px;min-width:0;margin-bottom:11px}.monitor-customer-avatar{display:grid;place-items:center;width:35px;height:35px;flex:0 0 35px;border-radius:11px;background:linear-gradient(135deg,#fff2e5,#f2d0b2);color:#b77b46;font-weight:750;font-size:.72rem}.monitor-customer-info{display:flex;flex-direction:column;gap:3px;min-width:0}.monitor-customer-info strong{display:block;font-size:.83rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.monitor-customer-info small{font-size:.67rem;color:#a19a94}
.monitor-order-store{display:flex;align-items:center;gap:7px;min-width:0;padding:8px 9px;margin-bottom:11px;border-radius:8px;background:#faf8f5;color:#777069;font-size:.74rem}.monitor-order-store span:last-child{overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.monitor-store-icon{flex:none;color:#c18c5b;font-size:1rem}
.monitor-product-line{display:-webkit-box;overflow:hidden;-webkit-box-orient:vertical;-webkit-line-clamp:2;line-height:1.45;min-height:2.9em;margin-bottom:13px;color:#6f7377;font-size:.76rem;overflow-wrap:anywhere}
.monitor-order-bottom{margin-top:auto;display:flex;align-items:flex-end;justify-content:space-between;gap:6px;border-top:1px solid #f0eeec;padding-top:12px}.monitor-order-bottom strong{display:block;color:#333;font-size:1rem;overflow-wrap:anywhere}.monitor-order-bottom small{display:block;margin-top:4px;color:#9b9c9d;font-size:.65rem}.monitor-order-date{text-align:right;color:#959595;font-size:.65rem;line-height:1.4;max-width:54%}.monitor-order-link{display:flex;align-items:center;justify-content:space-between;margin-top:12px;color:#c27d42;font-size:.73rem;font-weight:700}
.monitor-empty{grid-column:1/-1;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:9px;padding:43px 16px;border:1px dashed #e7dcd1;border-radius:13px;background:#fff;text-align:center;color:#858282;font-size:.81rem}.monitor-empty strong{color:#494746;font-size:.9rem}.monitor-empty-icon{display:grid;place-items:center;width:46px;height:46px;border-radius:13px;background:#fbf2e9;color:#bc8753;font-size:1.65rem}
.monitor-footer{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:18px;color:#929091;font-size:.72rem}.monitor-footer strong{color:#5b5855}.monitor-error{padding:12px 14px;margin-bottom:16px;border-radius:11px;background:#fff1f0;border:1px solid #f5d2cf;color:#a64b45;font-size:.81rem}
/* Modern, compact order details popup — consistent with Admin UI */
.monitor-modal{position:fixed;inset:0;z-index:2147483000;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(29,25,22,.55);backdrop-filter:blur(4px)}
.monitor-modal.is-open{display:flex}
.monitor-modal-card{display:flex;flex-direction:column;width:min(770px,100%);max-height:min(91dvh,860px);overflow:hidden;border:1px solid #eae4dd;border-radius:20px;background:#fff;box-shadow:0 28px 80px rgba(28,23,18,.22)}
.monitor-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex:none;padding:20px 24px 17px;border-bottom:1px solid #f0ebe7}
.monitor-modal-kicker{display:block;margin-bottom:5px;color:#b68460;font-size:.7rem;font-weight:750;letter-spacing:.09em;text-transform:uppercase}
.monitor-modal-head h2{margin:0;font-size:1.34rem;line-height:1.3;color:#353230;letter-spacing:-.03em}
.monitor-modal-sub{margin:6px 0 0;color:#98918a;font-size:.75rem}
.monitor-modal-close{display:grid;place-items:center;flex:0 0 34px;width:34px;height:34px;border:1px solid #eee8e1;border-radius:10px;background:#faf8f6;color:#736b64;font-size:1.3rem;cursor:pointer}
.monitor-modal-body{min-height:0;overflow-y:auto;padding:18px 24px 24px;overscroll-behavior:contain}
.monitor-modal-hero{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:18px;padding:16px 18px;background:linear-gradient(115deg,#fff8f1,#fffdfb);border:1px solid #f5e7db;border-radius:14px}
.monitor-modal-hero small{display:block;margin-bottom:3px;color:#9b8775;font-size:.72rem}.monitor-modal-hero strong{color:#393430;font-size:1.45rem;letter-spacing:-.035em}
.monitor-modal-hero .monitor-status{font-size:.74rem;padding:8px 11px;border-radius:9px}
.monitor-detail-section{margin-top:17px;border:1px solid #eee9e3;border-radius:13px;overflow:hidden;background:#fff}
.monitor-detail-section-head{display:flex;align-items:center;gap:9px;padding:13px 15px;border-bottom:1px solid #f1ede9;background:#fcfbfa}
.monitor-detail-section-head .icon{width:29px;height:29px;display:grid;place-items:center;border-radius:9px;background:#fff0e1;color:#be7f46;font-size:.96rem}
.monitor-detail-section-head h3{margin:0;color:#504840;font-size:.87rem;font-weight:750}
.monitor-detail-section-head small{margin-left:auto;color:#9d9790;font-size:.68rem}
.monitor-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 17px;padding:15px}
.monitor-detail-field{min-width:0}.monitor-detail-field.wide{grid-column:1/-1}.monitor-detail-field .key{display:block;margin-bottom:4px;color:#948c84;font-size:.71rem}.monitor-detail-field .value{display:block;overflow-wrap:anywhere;color:#45403b;font-size:.83rem;font-weight:650;line-height:1.5;white-space:pre-wrap}
.monitor-detail-field .value.unset{font-weight:500;color:#a7a19b}
.monitor-detail-highlight{padding:10px 12px;background:#fff7ee;border:1px solid #f6e8d8;border-radius:9px}
.monitor-item-list{padding:5px 15px}.monitor-item{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:12px 0;border-bottom:1px solid #f3efec}.monitor-item:last-child{border-bottom:0}.monitor-item strong{font-size:.82rem;color:#504840}.monitor-item small{display:block;margin-top:4px;color:#89827b;font-size:.72rem}.monitor-item-price{text-align:right;white-space:nowrap;color:#514338;font-size:.83rem;font-weight:750}
.monitor-chat{display:flex;flex-direction:column;gap:11px;max-height:320px;overflow-y:auto;padding:15px;background:#fdfcfb}
.monitor-chat-message{max-width:88%;align-self:flex-start;padding:10px 12px;border:1px solid #eae6e0;border-radius:12px 12px 12px 4px;background:#fff}
.monitor-chat-message.is-seller{align-self:flex-end;border-color:#f1d5bb;border-radius:12px 12px 4px 12px;background:#fff5eb}
.monitor-chat-meta{display:flex;justify-content:space-between;gap:12px;color:#9b8a7a;font-size:.67rem;font-weight:650}
.monitor-chat-text{margin:5px 0 0;white-space:pre-wrap;overflow-wrap:anywhere;font-size:.79rem;color:#554d45;line-height:1.55}
.monitor-detail-loading{padding:16px;color:#8c8580;font-size:.78rem;text-align:center}
.monitor-modal-bottom{display:flex;align-items:center;justify-content:space-between;gap:12px;flex:none;padding:13px 24px;border-top:1px solid #f0ebe7;background:#fcfbfa}
.monitor-modal-bottom span{color:#a0978e;font-size:.72rem}.monitor-done{padding:10px 23px;border:0;border-radius:9px;background:#df8e4d;color:#fff;font:inherit;font-weight:750;font-size:.8rem;cursor:pointer}
.monitor-modal-close:hover{background:#f5ede5}.monitor-done:hover{background:#c87536}.monitor-done:focus-visible,.monitor-modal-close:focus-visible{outline:3px solid #efa75a;outline-offset:2px}
[hidden]{display:none!important}
@media(min-width:1540px){.monitor-order-list{grid-template-columns:repeat(4,minmax(0,1fr))}}
@media(max-width:1340px){.monitor-filters{grid-template-columns:repeat(3,minmax(0,1fr))}.monitor-order-list{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:1050px){.monitor-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:840px){.page>.content{padding:93px 18px 32px}.monitor-heading{margin-bottom:18px}.monitor-order-list{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.page>.content{padding-left:14px;padding-right:14px}.monitor-heading{flex-direction:column;gap:7px}.monitor-sync{margin-top:0}.monitor-stats{gap:9px;margin-bottom:15px}.monitor-stat{padding:12px;min-height:98px}.monitor-stat-icon{width:30px;height:30px;flex-basis:30px;font-size:1rem}.monitor-stat-value{font-size:1.47rem}.monitor-stat-title{font-size:.72rem}.monitor-stat-detail{font-size:.63rem}.monitor-controls{padding:15px 12px}.monitor-section-head{align-items:stretch;flex-direction:column}.monitor-search{width:100%}.monitor-filters{grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}.monitor-filter{min-height:39px;font-size:.76rem}.monitor-order-list{grid-template-columns:1fr;gap:10px}.monitor-order{min-height:0;padding:15px}.monitor-product-line{min-height:0}.monitor-modal{padding:10px}.monitor-modal-card{max-height:calc(100dvh - 20px)}.monitor-modal-head,.monitor-modal-bottom{padding:13px 14px}.monitor-modal-body{padding:8px 14px}.monitor-detail-row{gap:8px;font-size:.76rem}.monitor-detail-row span{flex-basis:38%}}
@media(max-width:600px){.monitor-modal{padding:8px}.monitor-modal-card{width:100%;max-height:calc(100dvh - 16px);border-radius:15px}.monitor-modal-head{padding:14px 15px}.monitor-modal-head h2{font-size:1.12rem}.monitor-modal-body{padding:10px 13px 17px}.monitor-modal-hero{padding:12px}.monitor-modal-hero strong{font-size:1.22rem}.monitor-detail-grid{grid-template-columns:1fr;gap:11px;padding:12px}.monitor-detail-field.wide{grid-column:auto}.monitor-detail-section-head{padding:10px 12px}.monitor-chat{padding:12px;max-height:230px}.monitor-chat-message{max-width:95%}.monitor-modal-bottom{padding:11px 15px}.monitor-modal-bottom span{font-size:.65rem}}
</style>
</head>
<body>
<div class="page">
<?php include __DIR__ . '/admin_nav.php'; ?>
<main class="content">
<header class="monitor-heading">
<div><h1>Order Monitoring</h1><p>Track customer orders, fulfillment and payments across LokalCraft.</p></div>
<div class="monitor-sync"><span class="monitor-sync-dot" aria-hidden="true"></span><span>Updates automatically every 30 seconds</span></div>
</header>
<div class="monitor-error" id="monitorError" role="status" <?php echo $errorMessage === '' ? 'hidden' : ''; ?>><?php echo e($errorMessage); ?></div>
<section class="monitor-stats" aria-label="Order overview">
<article class="monitor-stat all"><div class="monitor-stat-content"><span class="monitor-stat-title">Total Orders</span><strong class="monitor-stat-value" id="stat-total"><?php echo number_format($data['stats']['total']); ?></strong><span class="monitor-stat-detail">All recorded orders</span></div><span class="monitor-stat-icon" aria-hidden="true">▤</span></article>
<article class="monitor-stat pending"><div class="monitor-stat-content"><span class="monitor-stat-title">Pending Orders</span><strong class="monitor-stat-value" id="stat-pending"><?php echo number_format($data['stats']['pending']); ?></strong><span class="monitor-stat-detail">Awaiting confirmation</span></div><span class="monitor-stat-icon" aria-hidden="true">◷</span></article>
<article class="monitor-stat accepted"><div class="monitor-stat-content"><span class="monitor-stat-title">Accepted Orders</span><strong class="monitor-stat-value" id="stat-accepted"><?php echo number_format($data['stats']['accepted']); ?></strong><span class="monitor-stat-detail">Accepted by sellers</span></div><span class="monitor-stat-icon" aria-hidden="true">↗</span></article>
<article class="monitor-stat completed"><div class="monitor-stat-content"><span class="monitor-stat-title">Completed Orders</span><strong class="monitor-stat-value" id="stat-completed"><?php echo number_format($data['stats']['completed']); ?></strong><span class="monitor-stat-detail">Finished orders</span></div><span class="monitor-stat-icon" aria-hidden="true">✓</span></article>
</section>
<section class="monitor-controls" aria-label="Order monitoring filters">
<div class="monitor-section-head"><div><h2>All Orders</h2><p>Choose a status or search for a specific order.</p></div><label class="monitor-search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m16 16 5 5"/></svg><input id="orderSearch" type="search" placeholder="Search order, customer or product" aria-label="Search orders" autocomplete="off"></label></div>
<nav class="monitor-filters" aria-label="Filter by order status">
<?php foreach (['all'=>'All Orders','pending'=>'Pending','accepted'=>'Accepted','processing'=>'Processing','completed'=>'Completed','cancelled'=>'Cancelled'] as $key=>$label): ?>
<button type="button" class="monitor-filter <?php echo $key === 'all' ? 'active' : ''; ?>" data-filter="<?php echo e($key); ?>" aria-pressed="<?php echo $key === 'all' ? 'true' : 'false'; ?>"><span><?php echo e($label); ?></span><span class="monitor-filter-count" data-count="<?php echo e($key); ?>"><?php echo number_format($filterCounts[$key]); ?></span></button>
<?php endforeach; ?>
</nav>
<div class="monitor-order-list" id="orderList"><?php echo renderOrderRows($data['orders']); ?></div>
<footer class="monitor-footer"><span><strong id="visibleOrders"><?php echo number_format(count($data['orders'])); ?></strong> order(s) displayed</span><span id="lastUpdated">Last updated: <?php echo e($data['updated_at']); ?> · Auto-refresh every 30 seconds</span></footer>
</section>
</main>
</div>
<div class="monitor-modal" id="orderModal" aria-hidden="true">
<div class="monitor-modal-card" role="dialog" aria-modal="true" aria-labelledby="orderModalTitle" tabindex="-1">
<header class="monitor-modal-head">
<div><span class="monitor-modal-kicker">LokalCraft · Order Monitoring</span><h2 id="orderModalTitle">Order #—</h2><p class="monitor-modal-sub">Buyer and seller order agreement</p></div>
<button type="button" class="monitor-modal-close" id="modalClose" aria-label="Close order details">×</button>
</header>
<div class="monitor-modal-body">
<div class="monitor-modal-hero"><div><small>Order Total</small><strong id="detail-total">—</strong></div><span class="monitor-status" id="modalOrderStatus">Pending</span></div>
<section class="monitor-detail-section" aria-label="Order overview">
<div class="monitor-detail-section-head"><span class="icon" aria-hidden="true">▤</span><h3>Order Overview</h3></div>
<div class="monitor-detail-grid">
<div class="monitor-detail-field"><span class="key">Customer</span><strong class="value" id="detail-customer">—</strong></div>
<div class="monitor-detail-field"><span class="key">Seller</span><strong class="value" id="detail-seller">—</strong></div>
<div class="monitor-detail-field"><span class="key">Order Date</span><strong class="value" id="detail-date">—</strong></div>
<div class="monitor-detail-field"><span class="key">Quantity</span><strong class="value" id="detail-quantity">—</strong></div>
<div class="monitor-detail-field wide"><span class="key">Products</span><strong class="value" id="detail-products">—</strong></div>
</div></section>
<section class="monitor-detail-section" aria-label="Delivery and meetup agreement">
<div class="monitor-detail-section-head"><span class="icon" aria-hidden="true">⌖</span><h3>Delivery / Meetup Agreement</h3><small>From order arrangements</small></div>
<div class="monitor-detail-grid">
<div class="monitor-detail-field"><span class="key">Delivery Option</span><strong class="value" id="detail-delivery">—</strong></div>
<div class="monitor-detail-field"><span class="key">Agreement Status</span><strong class="value" id="detail-arrangement-status">—</strong></div>
<div class="monitor-detail-field wide monitor-detail-highlight"><span class="key">Agreed Location</span><strong class="value" id="detail-location">—</strong></div>
<div class="monitor-detail-field"><span class="key">Meeting / Delivery Date</span><strong class="value" id="detail-meet-date">—</strong></div>
<div class="monitor-detail-field"><span class="key">Meeting / Delivery Time</span><strong class="value" id="detail-meet-time">—</strong></div>
<div class="monitor-detail-field"><span class="key">Buyer Confirmation</span><strong class="value" id="detail-buyer-confirmed">—</strong></div>
<div class="monitor-detail-field"><span class="key">Seller Confirmation</span><strong class="value" id="detail-seller-confirmed">—</strong></div>
<div class="monitor-detail-field wide"><span class="key">Arrangement Notes</span><strong class="value" id="detail-arrangement-notes">—</strong></div>
</div></section>
<section class="monitor-detail-section" aria-label="Payment details">
<div class="monitor-detail-section-head"><span class="icon" aria-hidden="true">₱</span><h3>Payment Details</h3></div>
<div class="monitor-detail-grid">
<div class="monitor-detail-field"><span class="key">Selected Payment Option</span><strong class="value" id="detail-payment-option">—</strong></div>
<div class="monitor-detail-field"><span class="key">Payment Method</span><strong class="value" id="detail-payment">—</strong></div>
<div class="monitor-detail-field"><span class="key">Payment Provider</span><strong class="value" id="detail-payment-provider">—</strong></div>
<div class="monitor-detail-field"><span class="key">Payment Status</span><strong class="value" id="detail-payment-status">—</strong></div>
<div class="monitor-detail-field"><span class="key">Recorded Payment Amount</span><strong class="value" id="detail-payment-amount">—</strong></div>
<div class="monitor-detail-field"><span class="key">Paid At</span><strong class="value" id="detail-paid-at">—</strong></div>
<div class="monitor-detail-field wide"><span class="key">Payment Reference</span><strong class="value" id="detail-payment-reference">—</strong></div>
</div></section>
<section class="monitor-detail-section" aria-label="Ordered items">
<div class="monitor-detail-section-head"><span class="icon" aria-hidden="true">▦</span><h3>Ordered Items</h3></div>
<div class="monitor-item-list" id="monitorItems" aria-live="polite"><div class="monitor-detail-loading">Loading items...</div></div>
</section>
<section class="monitor-detail-section" aria-label="Order conversation">
<div class="monitor-detail-section-head"><span class="icon" aria-hidden="true">☷</span><h3>Buyer–Seller Conversation</h3><small>Latest 30 linked messages</small></div>
<div class="monitor-chat" id="monitorChat" aria-live="polite"><div class="monitor-detail-loading">Loading order messages...</div></div>
</section>
<section class="monitor-detail-section" aria-label="Order notes"><div class="monitor-detail-section-head"><span class="icon" aria-hidden="true">≡</span><h3>Order Notes</h3></div><div class="monitor-detail-grid"><div class="monitor-detail-field wide"><strong class="value" id="detail-notes">—</strong></div></div></section>
</div>
<footer class="monitor-modal-bottom"><span>Read-only monitoring · No order changes</span><button type="button" class="monitor-done" id="modalDone">Done</button></footer>
</div>
</div>
<script>
(() => {
    const list = document.getElementById('orderList');
    const search = document.getElementById('orderSearch');
    const filters = [...document.querySelectorAll('.monitor-filter')];
    const errorBox = document.getElementById('monitorError');
    const modal = document.getElementById('orderModal');
    const modalClose = document.getElementById('modalClose');
    const modalDone = document.getElementById('modalDone');
    let selectedFilter = 'all';
    let lastTrigger = null;
    let refreshing = false;
    let lastRefreshTime = Date.now();
    const fields = ['customer','seller','products','quantity','total','payment-status','payment','paid-at','delivery','location','meet-date','meet-time','arrangement-status','arrangement-notes','buyer-confirmed','seller-confirmed','payment-option','payment-provider','payment-amount','payment-reference','date','notes'];
    let detailRequest = null;
    const formatter = new Intl.NumberFormat('en-PH');
    function filterOrders() {
        const term = search.value.trim().toLocaleLowerCase();
        const cards = list.querySelectorAll('[data-order]');
        let visible = 0;
        cards.forEach(card => {
            const matched = (selectedFilter === 'all' || card.dataset.status === selectedFilter)
                && (card.dataset.search || '').includes(term);
            card.hidden = !matched;
            if (matched) visible++;
        });
        const empty = document.getElementById('empty-orders');
        if (empty) {
            empty.hidden = visible > 0;
            const title = empty.querySelector('strong');
            const note = empty.querySelectorAll('span')[1];
            if (title) title.textContent = cards.length ? 'No matching orders' : 'No orders recorded yet';
            if (note) note.textContent = cards.length ? 'Try another search or filter.' : 'New customer orders will appear here automatically.';
        }
        document.getElementById('visibleOrders').textContent = formatter.format(visible);
    }
    filters.forEach(btn => btn.addEventListener('click', () => {
        selectedFilter = btn.dataset.filter || 'all';
        filters.forEach(item => {
            const active = item === btn;
            item.classList.toggle('active', active);
            item.setAttribute('aria-pressed', String(active));
        });
        filterOrders();
    }));
    search.addEventListener('input', filterOrders);
    function makeNode(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }
    async function loadModalExtras(id) {
        if (detailRequest) detailRequest.abort();
        const controller = new AbortController();
        detailRequest = controller;
        const itemsArea = document.getElementById('monitorItems');
        const chatArea = document.getElementById('monitorChat');
        itemsArea.replaceChildren(makeNode('div', 'monitor-detail-loading', 'Loading items...'));
        chatArea.replaceChildren(makeNode('div', 'monitor-detail-loading', 'Loading order messages...'));
        try {
            const url = new URL(window.location.href);
            url.searchParams.delete('monitor_data');
            url.searchParams.set('order_detail', id);
            const response = await fetch(url.toString(), {credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json'}, signal:controller.signal});
            if (response.status === 401 || response.status === 403) {window.location.href = '../login.php'; return;}
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || 'Unable to load details.');
            if (controller.signal.aborted || !modal.classList.contains('is-open')) return;
            itemsArea.replaceChildren();
            if (!data.items.length) itemsArea.append(makeNode('div','monitor-detail-loading','No line items recorded for this order.'));
            data.items.forEach(item => {
                const line = makeNode('div','monitor-item');
                const meta = makeNode('div');
                meta.append(makeNode('strong','',item.name), makeNode('small','',item.quantity + ' item(s) · Agreed unit price: ' + (item.agreed_unit_price || item.unit_price)));
                if (item.special_request) meta.append(makeNode('small','', 'Request: ' + item.special_request));
                line.append(meta, makeNode('span','monitor-item-price',item.subtotal));
                itemsArea.append(line);
            });
            chatArea.replaceChildren();
            if (!data.messages.length) chatArea.append(makeNode('div','monitor-detail-loading','No messages explicitly linked to this order. Delivery agreement details above come from order_arrangements.'));
            data.messages.forEach(message => {
                const bubble = makeNode('div','monitor-chat-message' + (message.sender === 'Seller' ? ' is-seller' : ''));
                const meta = makeNode('div','monitor-chat-meta');
                meta.append(makeNode('span','',message.sender), makeNode('span','',message.time));
                bubble.append(meta, makeNode('p','monitor-chat-text',message.text));
                chatArea.append(bubble);
            });
        } catch (error) {
            if (controller.signal.aborted) return;
            const label = error.message || 'Unable to load order details.';
            itemsArea.replaceChildren(makeNode('div','monitor-detail-loading',label));
            chatArea.replaceChildren(makeNode('div','monitor-detail-loading','Order messages could not be loaded.'));
        } finally {
            if (detailRequest === controller) detailRequest = null;
        }
    }
    function openModal(card) {
        lastTrigger = card;
        fields.forEach(field => {
            const el = document.getElementById('detail-' + field);
            if (!el) return;
            const value = card.getAttribute('data-' + field) || '—';
            el.textContent = value;
            el.classList.toggle('unset', ['Not agreed yet', 'No agreement yet', 'Not recorded', 'Awaiting confirmation', '—'].includes(value));
        });
        const status = card.dataset.orderStatus || 'Pending';
        document.getElementById('orderModalTitle').textContent = 'Order #' + card.dataset.orderId;
        const chip = document.getElementById('modalOrderStatus');
        chip.className = 'monitor-status ' + (card.dataset.status || '');
        chip.textContent = status;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('monitor-modal-open');
        modalClose.focus();
        loadModalExtras(card.dataset.orderId);
    }
    function closeModal() {
        if (detailRequest) {detailRequest.abort(); detailRequest = null;}
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('monitor-modal-open');
        if (lastTrigger && lastTrigger.isConnected) lastTrigger.focus();
    }
    list.addEventListener('click', event => {
        const card = event.target.closest('[data-order]');
        if (card) openModal(card);
    });
    [modalClose, modalDone].forEach(btn => btn.addEventListener('click', closeModal));
    modal.addEventListener('click', event => { if (event.target === modal) closeModal(); });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
    });
    async function refreshOrders(force = false) {
        if (refreshing || document.hidden || modal.classList.contains('is-open')) return;
        if (!force && Date.now() - lastRefreshTime < 29000) return;
        refreshing = true;
        try {
            const url = new URL(window.location.href);
            url.searchParams.set('monitor_data', '1');
            const response = await fetch(url.toString(), { credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json'} });
            if (response.status === 401 || response.status === 403) {
                window.location.href = '../login.php';
                return;
            }
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Unable to update orders.');
            ['total','pending','accepted','completed'].forEach(key => {
                const target = document.getElementById('stat-' + key);
                if (target) target.textContent = formatter.format(result.stats[key] || 0);
            });
            Object.entries(result.filter_counts || {}).forEach(([key,count]) => {
                const target = document.querySelector('[data-count="' + key + '"]');
                if (target) target.textContent = formatter.format(count || 0);
            });
            list.innerHTML = result.rows_html;
            filterOrders();
            document.getElementById('lastUpdated').textContent = 'Last updated: ' + result.updated_at + ' · Auto-refresh every 30 seconds';
            errorBox.hidden = true;
            lastRefreshTime = Date.now();
        } catch (error) {
            errorBox.textContent = error.message || 'Unable to update order monitoring.';
            errorBox.hidden = false;
        } finally {
            refreshing = false;
        }
    }
    window.setInterval(() => refreshOrders(), 30000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshOrders(); });
    window.addEventListener('focus', () => refreshOrders());
    filterOrders();
})();
</script>
</body>
</html>
