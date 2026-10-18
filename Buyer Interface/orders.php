<?php
require_once __DIR__ . '/buyer_auth.php';
require_once __DIR__ . '/../config/notification_events.php';
date_default_timezone_set('Asia/Manila');
$activePage = 'orders';
$searchPlaceholder = 'Search LokalCraft...';
$buyerId = (int)($buyerId ?? ($_SESSION['buyer_user_id'] ?? ($_SESSION['user_id'] ?? 0)));
if ($buyerId <= 0) {
    header('Location: ../login.php');
    exit;
}
if (empty($_SESSION['buyer_orders_csrf'])) {
    $_SESSION['buyer_orders_csrf'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['cart_csrf'])) {
    $_SESSION['cart_csrf'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['buyer_messages_csrf'])) {
    $_SESSION['buyer_messages_csrf'] = bin2hex(random_bytes(32));
}
function orderE($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function orderMoney($amount): string
{
    return '₱' . number_format((float)$amount, 2);
}
function orderStatusKey($value): string
{
    return strtolower(trim((string)$value));
}
function orderNice($value): string
{
    return ucwords(str_replace(['_', '-'], ' ', trim((string)$value)));
}
/** Small inline icons; each icon is a fixed, safe SVG. */
function orderIcon(string $name, string $class = 'ui-icon'): string
{
    $paths = [
        'package' => '<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'truck' => '<path d="M3 6h12v11H3zM15 10h4l3 3v4h-7z"/><circle cx="7.5" cy="18" r="2"/><circle cx="18.5" cy="18" r="2"/>',
        'check' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/>',
        'search' => '<circle cx="10.8" cy="10.8" r="7"/><path d="m16 16 5 5"/>',
        'chevron' => '<path d="m9 6 6 6-6 6"/>',
        'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'message' => '<path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5H5l-2 2V11.5a8.5 8.5 0 0 1 17-1"/>',
        'trash' => '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v5M14 11v5"/>',
        'card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'store' => '<path d="M3 10h18l-2-6H5l-2 6ZM5 10v10h14V10M9 20v-6h6v6"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18"/>',
        'bag' => '<path d="M5 8h14l1 13H4L5 8ZM9 9V6a3 3 0 0 1 6 0v3"/>',
    ];
    if (!isset($paths[$name])) return '';
    $class = preg_replace('/[^a-zA-Z0-9 _-]/', '', $class) ?? 'ui-icon';
    return '<svg class="' . $class . '" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[$name] . '</svg>';
}

function orderCancellationReasons(): array
{
    return [
        'changed_mind' => 'Changed my mind',
        'ordered_by_mistake' => 'Ordered by mistake',
        'wrong_product' => 'Selected the wrong product',
        'wrong_quantity' => 'Selected the wrong quantity',
        'duplicate_order' => 'Accidentally ordered twice',
        'change_order' => 'Need to change my order',
        'budget' => 'No longer within my budget',
        'found_alternative' => 'Found another option',
        'schedule' => 'Schedule no longer works',
        'delivery' => 'Need a different delivery option',
        'payment' => 'Need a different payment option',
        'no_response' => 'Seller has not responded',
        'not_needed' => 'No longer need the item',
        'other' => 'Other reason',
    ];
}

function orderNormalizeStatus($value): string
{
    $status = orderStatusKey($value);
    if ($status === 'canceled') {
        return 'cancelled';
    }
    if ($status === 'declined') {
        return 'rejected';
    }
    if ($status === 'confirmed') {
        return 'accepted';
    }
    return $status !== '' ? $status : 'pending';
}
function orderDeliveryLabel($method): string
{
    return match (orderStatusKey($method)) {
        'meetup' => 'Meetup',
        'pickup' => 'Pickup',
        'third_party_delivery' => 'Third-Party Delivery',
        'other' => 'Other',
        default => trim((string)$method) !== ''
            ? orderNice((string)$method)
            : 'Not set',
    };
}
/** Name the saved handover place according to the selected delivery method. */
function orderDeliveryLocationLabel($method): string
{
    return match (orderStatusKey($method)) {
        'meetup' => 'Meetup Place',
        'pickup' => 'Pickup Place',
        'third_party_delivery' => 'Delivery Address',
        default => 'Location',
    };
}
function orderPaymentLabel(array $order): string
{
    $option = orderStatusKey($order['payment_option'] ?? '');
    if ($option === 'cash') {
        return 'Cash';
    }
    if ($option === 'paymongo') {
        return 'QR Ph';
    }
    $method = orderStatusKey($order['payment_method'] ?? '');
    return match ($method) {
        'cash' => 'Cash',
        'paymongo', 'qr ph', 'qrph', 'gcash', 'gcash qr' => 'QR Ph',
        default => trim((string)($order['payment_method'] ?? '')) ?: 'Not set',
    };
}
function orderIsCash(array $order): bool
{
    return orderPaymentLabel($order) === 'Cash';
}
function orderIsQr(array $order): bool
{
    return orderPaymentLabel($order) === 'QR Ph';
}
function orderPaymentStatus(array $order): array
{
    $paymentStatus = orderStatusKey($order['payment_status'] ?? '');
    $orderStatus = orderNormalizeStatus($order['order_status'] ?? '');
    if (orderIsCash($order)) {
        if ($paymentStatus === 'paid' || $orderStatus === 'completed') {
            return ['key' => 'paid', 'label' => 'Paid'];
        }
        if ($paymentStatus === 'refunded') {
            return ['key' => 'refunded', 'label' => 'Refunded'];
        }
        if ($paymentStatus === 'cancelled') {
            return ['key' => 'cancelled', 'label' => 'Cancelled'];
        }
        return ['key' => 'cash-handover', 'label' => 'Cash in Person'];
    }
    if (orderIsQr($order)) {
        return match ($paymentStatus) {
            'paid' => ['key' => 'paid', 'label' => 'Paid'],
            'refunded' => ['key' => 'refunded', 'label' => 'Refunded'],
            'cancelled' => ['key' => 'cancelled', 'label' => 'Cancelled'],
            'failed' => ['key' => 'failed', 'label' => 'Failed'],
            default => ['key' => 'pending-payment', 'label' => 'Pending Payment'],
        };
    }
    return [
        'key' => $paymentStatus !== '' ? $paymentStatus : 'not-set',
        'label' => $paymentStatus !== '' ? orderNice($paymentStatus) : 'Not set',
    ];
}
function unifiedOrderStatus(array $order): array
{
    $key = orderGroup($order);
    return [
        'key' => $key,
        'label' => match ($key) {
            'pending' => 'Pending',
            'accepted' => 'Accepted',
            'to-pay' => 'To Pay',
            'processing' => 'Processing',
            'completed' => 'Completed',
            'closed' => 'Closed',
            default => 'Pending',
        },
    ];
}
function orderGroup(array $order): string
{
    $status = orderNormalizeStatus($order['order_status'] ?? 'pending');
    if (in_array($status, ['cancelled', 'rejected'], true)) {
        return 'closed';
    }
    if ($status === 'completed') {
        return 'completed';
    }
    if ($status === 'processing') {
        return 'processing';
    }
    if ($status === 'accepted') {
        $payment = orderPaymentStatus($order);
        return orderIsQr($order) && $payment['key'] !== 'paid' ? 'to-pay' : 'accepted';
    }
    return 'pending';
}
function orderDate($value): string
{
    if (!$value) {
        return '—';
    }
    $time = strtotime((string)$value);
    return $time
        ? date('M d, Y · h:i A', $time)
        : '—';
}
function orderShortDate($value): string
{
    if (!$value) {
        return '—';
    }
    $time = strtotime((string)$value);
    return $time
        ? date('M d, Y', $time)
        : '—';
}
function orderReviewPhoto($photo): string
{
    $photo = trim((string)$photo);
    return $photo !== ''
        && !str_contains($photo, '..')
        && !str_starts_with($photo, '/')
        && !str_contains($photo, '\\')
        && preg_match('~^[A-Za-z0-9_./ -]+\.(png|jpe?g|webp)$~i', $photo)
        ? '../' . $photo : '';
}

function orderTimeLabel($value): string
{
    if (!$value) {
        return 'Not set';
    }
    $time = strtotime((string)$value);
    return $time
        ? date('h:i A', $time)
        : (string)$value;
}
/** Show saved Cart choices; never recalculate or edit the original order. */
function orderSavedChoices(string $text): array
{
    $choices = [];
    foreach (preg_split('/\r?\n/u', $text) ?: [] as $line) {
        if (!preg_match('/^\s*-\s*(.+?)\s+\(\+₱([0-9,]+(?:\.[0-9]{2})?)\/unit\)(?::\s*(.*))?\s*$/u', $line, $match)) {
            continue;
        }
        $choices[] = [
            'name' => trim($match[1]),
            'price' => (float)str_replace(',', '', $match[2]),
            'details' => trim((string)($match[3] ?? ''))
        ];
    }
    return $choices;
}

function latestBuyerPayment(
    mysqli $conn,
    int $orderId,
    bool $forUpdate = false
): ?array {
    $sql = '
        SELECT
            payment_id,
            order_id,
            buyer_id,
            amount,
            payment_method,
            payment_provider,
            payment_status,
            paid_at,
            created_at
        FROM payments
        WHERE order_id = ?
        ORDER BY payment_id DESC
        LIMIT 1
    ';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to load the payment record.');
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $payment;
}
function buyerNotifySeller(
    mysqli $conn,
    int $buyerId,
    int $sellerId,
    int $orderId,
    string $message
): void {
    // Cancellation and its chat record must succeed or fail together.
    $stmt = $conn->prepare('
        INSERT INTO messages
            (sender_id, receiver_id, order_id, message_text, is_read, sent_at)
        VALUES (?, ?, ?, ?, 0, NOW())
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to notify the seller. Please try again.');
    }
    try {
        $stmt->bind_param('iiis', $buyerId, $sellerId, $orderId, $message);
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to notify the seller. Please try again.');
        }
    } finally {
        $stmt->close();
    }
}

function buyerCancelQrSession(
    mysqli $conn,
    int $orderId
): void {
    try {
        $check = $conn->query(
            "SHOW TABLES LIKE 'paymongo_qr_sessions'"
        );
        if (!$check) {
            return;
        }
        $exists = $check->num_rows > 0;
        $check->free();
        if (!$exists) {
            return;
        }
        $stmt = $conn->prepare('
            UPDATE paymongo_qr_sessions
            SET qr_status = "cancelled",
                updated_at = CURRENT_TIMESTAMP
            WHERE order_id = ?
              AND LOWER(COALESCE(qr_status, "")) NOT IN ("paid", "refunded")
        ');
        if ($stmt) {
            $stmt->bind_param('i', $orderId);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $ignored) {
    }
}
function buyerAssertPendingCancellationSafe(mysqli $conn, int $orderId): void
{
    // Check every attempt, not only the newest one. No QR Ph refunds are automated.
    $stmt = $conn->prepare('SELECT payment_status FROM payments WHERE order_id = ? FOR UPDATE');
    if (!$stmt) {
        throw new RuntimeException('Unable to verify payment safety. Try again later.');
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $statuses = $stmt->get_result()->fetch_all(MYSQLI_NUM);
    $stmt->close();
    foreach ($statuses as $row) {
        $status = strtolower(trim((string)$row[0]));
        if (in_array($status, ['paid', 'refunded', 'successful', 'succeeded', 'completed'], true)) {
            throw new RuntimeException('This order has a confirmed payment and cannot be cancelled here. Please contact support.');
        }
    }

    // A pending provider session might be paid asynchronously; never locally
    // cancel it without confirming its actual status with PayMongo first.
    $table = $conn->query("SHOW TABLES LIKE 'paymongo_qr_sessions'");
    $hasQrTable = $table && $table->num_rows > 0;
    if ($table) {
        $table->free();
    }
    if (!$hasQrTable) {
        return;
    }
    $stmt = $conn->prepare('SELECT qr_status FROM paymongo_qr_sessions WHERE order_id = ? FOR UPDATE');
    if (!$stmt) {
        throw new RuntimeException('Unable to verify QR Ph status. Try again later.');
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $sessions = $stmt->get_result()->fetch_all(MYSQLI_NUM);
    $stmt->close();
    foreach ($sessions as $session) {
        if (!in_array(strtolower(trim((string)$session[0])), ['cancelled', 'canceled', 'expired', 'failed'], true)) {
            throw new RuntimeException('A QR Ph session exists for this order. Contact support to verify payment before cancelling.');
        }
    }
}

$reviewsReady = false;
try {
    $check = $conn->query("SHOW TABLES LIKE 'reviews'");
    $reviewsReady = $check && $check->num_rows > 0;
    if ($check) {
        $check->free();
    }
} catch (Throwable $ignored) {
    $reviewsReady = false;
}
if (isset($_GET['watch'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        $stmt = $conn->prepare('
            SELECT
                o.order_id,
                o.order_status,
                COALESCE(p.payment_status, "") AS payment_status,
                COALESCE(p.paid_at, "") AS paid_at,
                COALESCE(oa.arrangement_status, "") AS arrangement_status
            FROM orders o
            LEFT JOIN order_arrangements oa
                ON oa.order_id = o.order_id
            LEFT JOIN payments p
                ON p.payment_id = (
                    SELECT p2.payment_id
                    FROM payments p2
                    WHERE p2.order_id = o.order_id
                    ORDER BY p2.payment_id DESC
                    LIMIT 1
                )
            WHERE o.buyer_id = ?
            ORDER BY o.order_id DESC
        ');
        if (!$stmt) {
            throw new RuntimeException('Unable to watch orders.');
        }
        $stmt->bind_param('i', $buyerId);
        $stmt->execute();
        $snapshot = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode([
            'success' => true,
            'signature' => hash(
                'sha256',
                json_encode($snapshot)
            ),
        ]);
    } catch (Throwable $e) {
        error_log(
            'Buyer orders watcher: '
            . $e->getMessage()
        );
        http_response_code(500);
        echo json_encode([
            'success' => false,
        ]);
    }
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string)($_POST['csrf_token'] ?? '');
    if (
        !hash_equals(
            (string)$_SESSION['buyer_orders_csrf'],
            $csrfToken
        )
    ) {
        $_SESSION['buyer_orders_error'] =
            'Session expired. Refresh the page and try again.';
        header('Location: orders.php');
        exit;
    }
    $action = trim((string)($_POST['action'] ?? ''));
    $orderId = filter_input(
        INPUT_POST,
        'order_id',
        FILTER_VALIDATE_INT
    ) ?: 0;
    try {
        if ($orderId <= 0) {
            throw new RuntimeException('Invalid order.');
        }
        if ($action === 'cancel_order') {
            $allowedReasons = orderCancellationReasons();
            $reasonCode = trim((string)($_POST['cancel_reason'] ?? ''));
            $reasonDetails = trim((string)($_POST['cancel_details'] ?? ''));
            if (!array_key_exists($reasonCode, $allowedReasons)) {
                throw new RuntimeException('Choose why you want to cancel this order.');
            }
            if ($reasonCode === 'other' && $reasonDetails === '') {
                throw new RuntimeException('Write your cancellation reason before continuing.');
            }
            $detailLength = function_exists('mb_strlen')
                ? mb_strlen($reasonDetails, 'UTF-8') : strlen($reasonDetails);
            if ($detailLength > 300 || strlen($reasonDetails) > 1200) {
                throw new RuntimeException('Cancellation details must be within 300 characters.');
            }
            $reasonDetails = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $reasonDetails) ?? '';
            $reasonMessage = 'Order #' . $orderId . ' cancelled by the buyer.'
                . "\nReason: " . $allowedReasons[$reasonCode]
                . ($reasonDetails !== '' ? "\nDetails: " . $reasonDetails : '');
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare('
                    SELECT
                        order_id,
                        buyer_id,
                        seller_id,
                        order_status,
                        (SELECT oa.arrangement_status FROM order_arrangements oa
                         WHERE oa.order_id = orders.order_id LIMIT 1) AS arrangement_status
                    FROM orders
                    WHERE order_id = ?
                      AND buyer_id = ?
                    LIMIT 1
                    FOR UPDATE
                ');
                if (!$stmt) {
                    throw new RuntimeException(
                        'Unable to load the order.'
                    );
                }
                $stmt->bind_param(
                    'ii',
                    $orderId,
                    $buyerId
                );
                $stmt->execute();
                $order = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$order) {
                    throw new RuntimeException(
                        'Order not found.'
                    );
                }
                $orderStatus = orderNormalizeStatus(
                    $order['order_status']
                );
                if ($orderStatus !== 'pending'
                    || orderStatusKey($order['arrangement_status'] ?? '') === 'accepted') {
                    throw new RuntimeException(
                        'Only Pending orders can be cancelled before the arrangement is accepted.'
                    );
                }
                buyerAssertPendingCancellationSafe($conn, $orderId);
                $payment = latestBuyerPayment(
                    $conn,
                    $orderId,
                    true
                );
                if (
                    $payment
                    && orderStatusKey(
                        $payment['payment_status'] ?? ''
                    ) === 'paid'
                ) {
                    throw new RuntimeException(
                        'A paid order cannot be cancelled. Payment has already been confirmed.'
                    );
                }
                $stmt = $conn->prepare('
                    UPDATE orders
                    SET order_status = "Cancelled",
                        updated_at = NOW()
                    WHERE order_id = ?
                      AND buyer_id = ?
                      AND LOWER(order_status) = "pending"
                ');
                if (!$stmt) {
                    throw new RuntimeException(
                        'Unable to cancel the order.'
                    );
                }
                $stmt->bind_param(
                    'ii',
                    $orderId,
                    $buyerId
                );
                $stmt->execute();
                if ($stmt->affected_rows !== 1) {
                    $stmt->close();
                    throw new RuntimeException(
                        'The order status changed. Refresh the page.'
                    );
                }
                $stmt->close();
                $stmt = $conn->prepare('
                    UPDATE payments SET payment_status = "cancelled"
                    WHERE order_id = ?
                      AND LOWER(COALESCE(payment_status, "pending"))
                          NOT IN ("paid", "refunded", "successful", "succeeded", "completed")
                ');
                if (!$stmt) {
                    throw new RuntimeException('Unable to close the pending payment records.');
                }
                $stmt->bind_param('i', $orderId);
                $stmt->execute();
                $stmt->close();
                buyerCancelQrSession(
                    $conn,
                    $orderId
                );
                $sellerId = (int)$order['seller_id'];
                buyerNotifySeller(
                    $conn,
                    $buyerId,
                    $sellerId,
                    $orderId,
                    $reasonMessage
                );
                lokalcraftCreateNotification(
                    $conn,
                    $sellerId,
                    'seller_order_cancelled',
                    'Order Cancelled',
                    $reasonMessage,
                    'order',
                    $orderId,
                    'seller-order-cancelled-order-' . $orderId
                );
                $conn->commit();
                $_SESSION['buyer_orders_success'] =
                    'Order #'
                    . $orderId
                    . ' was cancelled.';
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        } elseif ($action === 'submit_review') {
            if (!$reviewsReady) {
                throw new RuntimeException(
                    'Reviews are not available yet.'
                );
            }
            $orderItemId = filter_input(
                INPUT_POST,
                'order_item_id',
                FILTER_VALIDATE_INT
            ) ?: 0;
            $rating = filter_input(
                INPUT_POST,
                'rating',
                FILTER_VALIDATE_INT
            ) ?: 0;
            $reviewText = trim(
                (string)($_POST['review_text'] ?? '')
            );
            if (
                $orderItemId <= 0
                || $rating < 1
                || $rating > 5
            ) {
                throw new RuntimeException(
                    'Choose a rating from 1 to 5 stars.'
                );
            }
            if ($reviewText === '') {
                throw new RuntimeException('Choose a quick review or write your own feedback.');
            }
            $reviewLength = function_exists('mb_strlen')
                ? mb_strlen($reviewText, 'UTF-8') : strlen($reviewText);
            if ($reviewLength > 1000) {
                throw new RuntimeException(
                    'Review must be 1000 characters or less.'
                );
            }
            $stmt = $conn->prepare('
                SELECT
                    oi.order_item_id,
                    oi.product_id,
                    o.order_id,
                    o.seller_id,
                    o.order_status
                FROM order_items oi
                INNER JOIN orders o
                    ON o.order_id = oi.order_id
                WHERE oi.order_item_id = ?
                  AND o.order_id = ?
                  AND o.buyer_id = ?
                LIMIT 1
            ');
            if (!$stmt) {
                throw new RuntimeException(
                    'Unable to load the review item.'
                );
            }
            $stmt->bind_param(
                'iii',
                $orderItemId,
                $orderId,
                $buyerId
            );
            $stmt->execute();
            $item = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (
                !$item
                || orderNormalizeStatus(
                    $item['order_status']
                ) !== 'completed'
            ) {
                throw new RuntimeException(
                    'Only products from completed orders can be reviewed.'
                );
            }
            $productId = (int)$item['product_id'];
            $sellerId = (int)$item['seller_id'];
            $duplicateCheck = $conn->prepare('
                SELECT review_id
                FROM reviews
                WHERE order_item_id = ?
                  AND buyer_id = ?
                LIMIT 1
            ');
            if (!$duplicateCheck) {
                throw new RuntimeException(
                    'Unable to check the existing review.'
                );
            }
            $duplicateCheck->bind_param(
                'ii',
                $orderItemId,
                $buyerId
            );
            $duplicateCheck->execute();
            $alreadyReviewed = (bool)$duplicateCheck
                ->get_result()
                ->fetch_assoc();
            $duplicateCheck->close();
            if ($alreadyReviewed) {
                throw new RuntimeException(
                    'You already reviewed this product from this order.'
                );
            }
            $stmt = $conn->prepare('
                INSERT INTO reviews
                    (
                        order_item_id,
                        order_id,
                        product_id,
                        buyer_id,
                        seller_id,
                        rating,
                        review_text,
                        created_at
                    )
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            if (!$stmt) {
                throw new RuntimeException(
                    'Unable to prepare the review.'
                );
            }
            $stmt->bind_param(
                'iiiiiis',
                $orderItemId,
                $orderId,
                $productId,
                $buyerId,
                $sellerId,
                $rating,
                $reviewText
            );
            if (!$stmt->execute()) {
                $duplicate = $stmt->errno === 1062;
                $stmt->close();
                throw new RuntimeException(
                    $duplicate
                        ? 'You already reviewed this product from this order.'
                        : 'Unable to submit the review.'
                );
            }
            $stmt->close();
            $_SESSION['buyer_orders_success'] =
                'Thank you. Your review was submitted.';
        } else {
            throw new RuntimeException(
                'Unsupported order action.'
            );
        }
    } catch (Throwable $e) {
        error_log(
            'Buyer orders action: '
            . $e->getMessage()
        );
        $_SESSION['buyer_orders_error'] = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Unable to complete your request. Please try again.';
    }
    header(
        'Location: orders.php?order_id='
        . $orderId
        . '#order-' . $orderId
    );
    exit;
}
$orders = [];
$orderItems = [];
$reviewsByItem = [];
$errorMessage = '';
$successMessage = (string)(
    $_SESSION['buyer_orders_success'] ?? ''
);
$actionError = (string)(
    $_SESSION['buyer_orders_error'] ?? ''
);
unset(
    $_SESSION['buyer_orders_success'],
    $_SESSION['buyer_orders_error']
);
try {
    $stmt = $conn->prepare('
        SELECT
            o.order_id,
            o.seller_id,
            o.total_amount,
            o.order_status,
            o.notes,
            o.ordered_at,
            COALESCE(
                NULLIF(TRIM(b.businessname), ""),
                NULLIF(
                    TRIM(
                        CONCAT(
                            COALESCE(u.firstname, ""),
                            " ",
                            COALESCE(u.lastname, "")
                        )
                    ),
                    ""
                ),
                "Local Seller"
            ) AS seller_name,
            COALESCE(NULLIF(TRIM(b.businessname), ""), "Not set") AS shop_name,
            COALESCE(NULLIF(TRIM(CONCAT_WS(" ", NULLIF(TRIM(u.firstname), ""), NULLIF(TRIM(u.lastname), ""))), ""), "Not set") AS seller_person_name,
            pay.payment_id,
            pay.payment_method,
            pay.payment_provider,
            pay.payment_status,
            pay.paid_at,
            pay.paymongo_payment_id,
            pay.paymongo_payment_intent_id,
            (
                SELECT qr.payment_intent_id
                FROM paymongo_qr_sessions qr
                WHERE qr.order_id = o.order_id
                  AND qr.payment_id = pay.payment_id
                ORDER BY qr.qr_session_id DESC
                LIMIT 1
            ) AS qr_payment_intent_id,
            oa.payment_option,
            oa.fulfillment_method,
            oa.arrangement_location,
            oa.arrangement_date,
            oa.arrangement_time,
            oa.arrangement_notes,
            oa.arrangement_status,
            items.items_summary,
            items.total_quantity
        FROM orders o
        INNER JOIN users u
            ON u.id = o.seller_id
        LEFT JOIN businessinfo b
            ON b.user_id = o.seller_id
        LEFT JOIN order_arrangements oa
            ON oa.order_id = o.order_id
        LEFT JOIN (
            SELECT
                oi.order_id,
                GROUP_CONCAT(
                    CONCAT(
                        oi.quantity,
                        " × ",
                        COALESCE(p.product_name, "Product")
                    )
                    ORDER BY oi.order_item_id ASC
                    SEPARATOR ", "
                ) AS items_summary,
                SUM(oi.quantity) AS total_quantity
            FROM order_items oi
            LEFT JOIN products p
                ON p.product_id = oi.product_id
            GROUP BY oi.order_id
        ) items
            ON items.order_id = o.order_id
        LEFT JOIN payments pay
            ON pay.payment_id = (
                SELECT p2.payment_id
                FROM payments p2
                WHERE p2.order_id = o.order_id
                ORDER BY p2.payment_id DESC
                LIMIT 1
            )
        WHERE o.buyer_id = ?
        ORDER BY
            o.ordered_at DESC,
            o.order_id DESC
    ');
    if (!$stmt) {
        throw new RuntimeException(
            'Unable to load your orders.'
        );
    }
    $stmt->bind_param(
        'i',
        $buyerId
    );
    $stmt->execute();
    $orders = $stmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $stmt = $conn->prepare('
        SELECT
            oi.order_item_id,
            oi.order_id,
            oi.product_id,
            oi.quantity,
            oi.unit_price,
            oi.agreed_unit_price,
            oi.subtotal,
            oi.special_request,
            COALESCE(
                p.product_name,
                "Product"
            ) AS product_name,
            p.product_photo
        FROM order_items oi
        INNER JOIN orders o
            ON o.order_id = oi.order_id
        LEFT JOIN products p
            ON p.product_id = oi.product_id
        WHERE o.buyer_id = ?
        ORDER BY
            oi.order_id DESC,
            oi.order_item_id ASC
    ');
    if (!$stmt) {
        throw new RuntimeException(
            'Unable to load order items.'
        );
    }
    $stmt->bind_param(
        'i',
        $buyerId
    );
    $stmt->execute();
    foreach (
        $stmt->get_result()->fetch_all(MYSQLI_ASSOC)
        as $item
    ) {
        $orderItems[
            (int)$item['order_id']
        ][] = $item;
    }
    $stmt->close();
    if ($reviewsReady) {
        $stmt = $conn->prepare('
            SELECT
                review_id,
                order_item_id,
                rating,
                review_text,
                created_at
            FROM reviews
            WHERE buyer_id = ?
        ');
        if ($stmt) {
            $stmt->bind_param(
                'i',
                $buyerId
            );
            $stmt->execute();
            foreach (
                $stmt->get_result()->fetch_all(MYSQLI_ASSOC)
                as $review
            ) {
                $reviewsByItem[
                    (int)$review['order_item_id']
                ] = $review;
            }
            $stmt->close();
        }
    }
} catch (Throwable $e) {
    error_log(
        'Buyer orders load: '
        . $e->getMessage()
    );
    $errorMessage =
        'Unable to load your orders right now. '
        . 'Please refresh or try again later.';
}
$counts = [
    'all' => count($orders),
    'pending' => 0,
    'accepted' => 0,
    'to-pay' => 0,
    'processing' => 0,
    'completed' => 0,
    'closed' => 0,
];
foreach ($orders as $order) {
    $group = orderGroup($order);
    if (isset($counts[$group])) {
        $counts[$group]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>
<title>LokalCraft | My Orders</title>
<style>
:root {
    --orders-bg: #f4f3f1;
    --orders-card: #ffffff;
    --orders-text: #2d2d2d;
    --orders-muted: #76716d;
    --orders-line: #e8e1da;
    --orders-orange: #ea8a3a;
    --orders-orange-dark: #d87522;
    --orders-orange-soft: #fff3e8;
    --orders-green: #278553;
    --orders-green-soft: #edf9f1;
    --orders-blue: #3978b7;
    --orders-blue-soft: #eef5fc;
    --orders-red: #bd4c4c;
    --orders-red-soft: #fff1f1;
    --orders-gold: #9d6b21;
    --orders-gold-soft: #fff6df;
    --orders-purple: #7357b8;
    --orders-purple-soft: #f1e9ff;
}
* {
    box-sizing: border-box;
}
body {
    margin: 0;
    min-height: 100vh;
    background: var(--orders-bg);
    color: var(--orders-text);
    font-family: "Segoe UI", Arial, sans-serif;
}
body.modal-open {
    overflow: hidden;
}
button,
textarea {
    font: inherit;
}
.page {
    display: block !important;
    min-height: 100vh;
}
.page > .content {
    position: relative !important;
    width: auto !important;
    max-width: none !important;
    min-height: 100vh !important;
    margin-left: 240px !important;
    padding: 74px 26px 34px !important;
    overflow: visible !important;
}
.orders-wrap {
    width: 100%;
    max-width: 1180px;
    margin: 0 auto;
}
.orders-hero {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 18px;
}
.orders-heading h1 {
    margin: 0;
    color: #2c2927;
    font-size: 2.05rem;
    font-weight: 800;
    letter-spacing: -.7px;
}
.orders-heading p {
    max-width: 680px;
    margin: 5px 0 0;
    color: var(--orders-muted);
    font-size: .87rem;
    line-height: 1.55;
}

.notice {
    margin-bottom: 14px;
    padding: 11px 13px;
    border: 1px solid #efd4ae;
    border-radius: 10px;
    background: #fff8eb;
    color: #75541f;
    font-size: .74rem;
    line-height: 1.5;
}
.notice.error {
    border-color: #edc1be;
    background: var(--orders-red-soft);
    color: #933d38;
}
.notice.success {
    border-color: #b9e5cc;
    background: var(--orders-green-soft);
    color: #187448;
}

.order-tabs {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
    margin-bottom: 14px;
}
.order-tab {
    min-height: 35px;
    padding: 7px 11px;
    border: 1px solid #e1dcd7;
    border-radius: 9px;
    background: #fff;
    color: #5d5752;
    font-size: .68rem;
    font-weight: 800;
    cursor: pointer;
}
.order-tab:hover {
    border-color: #d8c6b8;
    background: #fffaf6;
}
.order-tab.active {
    border-color: var(--orders-orange);
    background: var(--orders-orange);
    color: #fff;
}
.order-tab small {
    margin-left: 5px;
    font-size: .61rem;
    opacity: .82;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    min-height: 25px;
    padding: 5px 8px;
    border-radius: 999px;
    background: var(--orders-gold-soft);
    color: var(--orders-gold);
    font-size: .58rem;
    font-weight: 800;
    letter-spacing: .025em;
    text-transform: uppercase;
}
.status-pill.accepted {
    background: var(--orders-blue-soft);
    color: var(--orders-blue);
}
.status-pill.processing {
    background: var(--orders-purple-soft);
    color: var(--orders-purple);
}
.status-pill.completed,
.status-pill.paid {
    background: var(--orders-green-soft);
    color: var(--orders-green);
}
.status-pill.cancelled,
.status-pill.rejected,
.status-pill.failed,
.status-pill.refunded {
    background: var(--orders-red-soft);
    color: var(--orders-red);
}
.status-pill.pending,
.status-pill.pending-payment,
.status-pill.cash-handover {
    background: var(--orders-gold-soft);
    color: var(--orders-gold);
}

.order-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 34px;
    padding: 7px 11px;
    border: 1px solid #ddd7d1;
    border-radius: 8px;
    background: #fff;
    color: #57514c;
    font-size: .66rem;
    font-weight: 800;
    text-decoration: none;
    cursor: pointer;
}
.order-action:hover {
    border-color: #cbbeb3;
    background: #fcf9f6;
}
.order-action.primary {
    border-color: var(--orders-orange);
    background: var(--orders-orange);
    color: #fff;
}
.order-action.primary:hover {
    background: var(--orders-orange-dark);
}
.order-action.danger {
    border-color: #e4baba;
    background: #fff8f8;
    color: var(--orders-red);
}
.order-action.danger:hover {
    border-color: var(--orders-red);
    background: #fff1f1;
}


.order-item-info {
    display: flex;
    gap: 10px;
    align-items: center;
    min-width: 0;
}
.order-item-photo,
.order-item-placeholder {
    width: 48px;
    height: 48px;
    flex-shrink: 0;
    border-radius: 8px;
    background: #f1ece6;
}
.order-item-photo {
    object-fit: cover;
}
.order-item-placeholder {
    display: grid;
    place-items: center;
}
.order-item-name {
    color: #3b3734;
    font-size: .7rem;
    font-weight: 800;
}
.order-item-sub {
    display: block;
    margin-top: 3px;
    color: #928a84;
    font-size: .61rem;
}
.order-item-price {
    flex-shrink: 0;
    color: #4c4641;
    font-weight: 800;
}

.reviewed-badge {
    display: inline-flex;
    margin-left: 6px;
    padding: 3px 6px;
    border-radius: 999px;
    background: var(--orders-green-soft);
    color: var(--orders-green);
    font-size: .56rem;
    font-weight: 800;
}
.review-link {
    display: inline-flex;
    margin-top: 5px;
    border: 0;
    padding: 0;
    background: transparent;
    color: var(--orders-blue);
    font-size: .6rem;
    font-weight: 800;
    cursor: pointer;
}
.orders-empty {
    padding: 62px 20px;
    border: 1px solid var(--orders-line);
    border-radius: 14px;
    background: #fff;
    text-align: center;
}
.orders-empty-icon {
    font-size: 2rem;
}
.orders-empty h2 {
    margin: 10px 0 5px;
    font-size: 1.05rem;
}
.orders-empty p {
    margin: 0;
    color: #88817b;
    font-size: .7rem;
}
.hidden {
    display: none !important;
}
.confirm-dialog,
.review-dialog,
.review-view-dialog {
    width: calc(100% - 28px);
    max-width: 440px;
    margin: auto;
    padding: 23px;
    border: 0;
    border-radius: 14px;
    box-shadow: 0 18px 65px rgba(0,0,0,.26);
}
.confirm-dialog::backdrop,
.review-dialog::backdrop,
.review-view-dialog::backdrop {
    background: rgba(0,0,0,.48);
    backdrop-filter: blur(2px);
}
.confirm-dialog h2,
.review-dialog h2,
.review-view-dialog h2 {
    margin: 0 0 8px;
    color: #312d2a;
    font-size: 1.08rem;
}
.confirm-dialog p,
.review-dialog p,
.review-view-dialog p {
    margin: 0;
    color: #6d6660;
    font-size: .72rem;
    line-height: 1.6;
}
.confirm-actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    margin-top: 18px;
}
.review-form {
    display: grid;
    gap: 12px;
    margin-top: 14px;
}
.review-stars {
    display: flex;
    gap: 4px;
}
.review-star {
    width: 38px;
    height: 38px;
    border: 1px solid #e2ddd8;
    border-radius: 8px;
    background: #fff;
    color: #c9c2bb;
    font-size: 1.18rem;
    cursor: pointer;
}
.review-star.active {
    border-color: #e1ad68;
    background: #fff8ea;
    color: #e59a31;
}
.review-form textarea {
    width: 100%;
    min-height: 110px;
    resize: vertical;
    padding: 10px 11px;
    border: 1px solid #ddd7d1;
    border-radius: 8px;
    background: #fff;
    color: #403b36;
    font-size: .7rem;
    line-height: 1.5;
}
.review-rating-view {
    margin: 10px 0 8px;
    color: #e59a31;
    font-size: 1.08rem;
    letter-spacing: 2px;
}
.qr-payment-dialog {
    width: min(470px, calc(100vw - 28px));
    max-width: 470px;
    margin: auto;
    padding: 0;
    border: 0;
    border-radius: 22px;
    background: transparent;
    overflow: visible;
}
.qr-payment-dialog::backdrop {
    background: rgba(32, 27, 23, .48);
    backdrop-filter: blur(3px);
}
.qr-payment-shell {
    overflow: hidden;
    border: 1px solid #eadfd6;
    border-radius: 22px;
    background: #fff;
    box-shadow: 0 24px 70px rgba(36, 25, 15, .22);
}
.qr-payment-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    padding: 18px 20px 15px;
    border-bottom: 1px solid #eee6df;
    background: #fffaf6;
}
.qr-payment-head h2 {
    margin: 0;
    color: #302b27;
    font-size: 1.08rem;
}
.qr-payment-kicker {
    display: block;
    margin-bottom: 3px;
    color: var(--orders-orange-dark);
    font-size: .61rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .06em;
}
.qr-payment-close {
    width: 36px;
    height: 36px;
    flex: 0 0 auto;
    border: 1px solid #e1d8d0;
    border-radius: 11px;
    background: #fff;
    color: #6e655e;
    font-size: 1.35rem;
    line-height: 1;
    cursor: pointer;
}
.qr-payment-body {
    padding: 18px 20px 20px;
}
.qr-payment-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
}
.qr-payment-order {
    color: #625a54;
    font-size: .7rem;
    font-weight: 800;
}
.qr-payment-amount {
    color: var(--orders-orange-dark);
    font-size: 1rem;
    font-weight: 800;
}
.qr-payment-code {
    display: grid;
    place-items: center;
    min-height: 240px;
    padding: 14px;
    border: 1px solid #eee6df;
    border-radius: 16px;
    background: #faf9f7;
}
.qr-payment-code img[hidden] {
    display: none !important;
}
.qr-payment-code img {
    display: block;
    width: min(240px, 100%);
    height: auto;
    border-radius: 10px;
}
.qr-payment-timer {
    margin-top: 11px;
    color: #7d756f;
    font-size: .66rem;
    font-weight: 800;
    text-align: center;
}
.qr-payment-feedback {
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
.qr-payment-feedback.success {
    border-color: #cde5d5;
    background: #f2fbf5;
    color: #287a4b;
}
.qr-payment-feedback.error {
    border-color: #ebcaca;
    background: #fff5f5;
    color: #9d4848;
}
.qr-payment-actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    flex-wrap: wrap;
    margin-top: 14px;
}
.qr-payment-actions .order-action[hidden] {
    display: none !important;
}

@media (max-width: 840px) {
    .page > .content {
        margin: 0 !important;
        padding: 74px 14px 25px !important;
    }
    .orders-hero {
        align-items: flex-start;
        flex-direction: column;
    }
}
@media (max-width: 560px) {
    .orders-heading h1 {
        font-size: 1.62rem;
    }

    .confirm-actions {
        flex-direction: column-reverse;
    }
    .confirm-actions .order-action {
        width: 100%;
    }

}
.orders-wrap {
    max-width: 1480px;
    margin: 0 auto;
}
.orders-hero {
    margin-bottom: 18px;
}

.order-tabs {
    gap: 8px;
    margin-bottom: 18px;
}
.order-tab {
    min-height: 38px;
    border-radius: 12px;
    padding: 8px 13px;
}

.order-action {
    border-radius: 11px;
}

.order-details-dialog {
    width: min(760px, calc(100vw - 36px));
    max-width: 760px;
    max-height: min(82vh, 760px);
    padding: 0;
    border: 0;
    border-radius: 22px;
    background: transparent;
    overflow: visible;
}
.order-details-dialog::backdrop {
    background: rgba(32, 27, 23, .48);
    backdrop-filter: blur(3px);
}
.order-details-shell {
    display: flex;
    flex-direction: column;
    max-height: min(82vh, 760px);
    overflow: hidden;
    border: 1px solid #eadfd6;
    border-radius: 22px;
    background: #fff;
    box-shadow: 0 24px 70px rgba(36, 25, 15, .22);
}
.order-details-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    padding: 18px 20px 15px;
    border-bottom: 1px solid #eee6df;
    background: #fffaf6;
}
.order-details-kicker {
    display: block;
    margin-bottom: 3px;
    color: var(--orders-orange-dark);
    font-size: .62rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .06em;
}
.order-details-head h2 {
    margin: 0;
    color: #302b27;
    font-size: 1.18rem;
}
.order-details-close {
    width: 36px;
    height: 36px;
    flex: 0 0 auto;
    border: 1px solid #e1d8d0;
    border-radius: 11px;
    background: #fff;
    color: #6e655e;
    font-size: 1.35rem;
    line-height: 1;
    cursor: pointer;
}
.order-details-body {
    overflow-y: auto;
    padding: 18px 20px 22px;
    overscroll-behavior: contain;
}


@media (max-width: 760px) {

.order-tabs {
        flex-wrap: nowrap;
        overflow-x: auto;
        gap: 7px;
        margin-right: -14px;
        padding: 0 14px 7px 0;
        scrollbar-width: none;
        -webkit-overflow-scrolling: touch;
    }
    .order-tabs::-webkit-scrollbar {
        display: none;
    }
    .order-tab {
        flex: 0 0 auto;
        white-space: nowrap;
    }

.order-action {
        width: 100%;
        min-width: 0;
        text-align: center;
    }
    .order-details-dialog {
        width: calc(100vw - 24px);
        max-height: 84vh;
    }
    .order-details-shell {
        max-height: 84vh;
        border-radius: 18px;
    }
    .order-details-head {
        padding: 15px 16px 13px;
    }
    .order-details-body {
        padding: 15px 16px 18px;
    }

}
@media (max-width: 430px) {
    .orders-heading h1 {
        font-size: 1.5rem;
    }
    .orders-heading p {
        font-size: .76rem;
    }

}

.status-pill {
    min-height: 22px !important;
    padding: 4px 7px !important;
    font-size: .52rem !important;
}

.order-action {
    width: 100% !important;
    min-width: 0 !important;
    min-height: 34px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 6px !important;
    border-radius: 8px !important;
    font-size: .57rem !important;
    line-height: 1.18 !important;
    white-space: normal !important;
    overflow-wrap: anywhere !important;
    text-align: center !important;
}

@media (max-width: 760px) {

.order-action {
        min-height: 38px !important;
        padding: 8px 7px !important;
        font-size: .61rem !important;
    }
}
@media (max-width: 430px) {

.order-action {
        min-height: 40px !important;
        font-size: .63rem !important;
    }
}
.orders-hero {
    margin-bottom: 14px !important;
}
.orders-heading h1 {
    margin-bottom: 0 !important;
}

/* Buyer order dialogs: compact responsive layouts and clear tap targets. */
.order-cancel-dialog,
.order-write-dialog {
    width: min(540px, calc(100vw - 24px));
    max-width: 540px;
    max-height: min(90dvh, 850px);
    padding: 0;
    overflow: hidden;
    background: #fff;
    border: 1px solid #eadfd6;
    border-radius: 18px;
    box-shadow: 0 22px 70px rgba(40, 28, 18, .23);
}
.order-cancel-dialog form,
.order-write-dialog form {
    display: flex;
    flex-direction: column;
    min-height: 0;
    margin: 0;
}
.order-cancel-dialog[open], .order-write-dialog[open] {
    display: flex;
    flex-direction: column;
}
.order-dialog-heading {
    padding: 19px 20px 15px;
    border-bottom: 1px solid #f0eae5;
    background: #fffaf6;
}
.order-dialog-heading h2 { margin: 3px 0 4px; font-size: 1.07rem; }
.order-dialog-heading p { font-size: .73rem; line-height: 1.5; }
.order-dialog-eyebrow {
    display: block;
    color: #ae672e;
    font-size: .62rem;
    font-weight: 800;
    letter-spacing: .045em;
    text-transform: uppercase;
}
.order-dialog-scroll {
    overflow-y: auto;
    overscroll-behavior: contain;
    min-height: 0;
    padding: 16px 20px 18px;
}
.order-dialog-field-title {
    display: block;
    margin: 0 0 9px;
    color: #47403a;
    font-size: .76rem;
    font-weight: 800;
}
.order-dialog-field-title:not(:first-child) { margin-top: 17px; }
.order-dialog-field-title small,
.order-dialog-field-title span { font-size: .68rem; font-weight: 500; color: #837972; }
.order-choice-grid { display: flex; flex-wrap: wrap; gap: 8px; }
.order-quick-choice {
    min-height: 37px;
    max-width: 100%;
    padding: 8px 11px;
    border: 1px solid #e7ddd5;
    border-radius: 10px;
    background: #fff;
    color: #514940;
    font-size: .71rem;
    font-weight: 700;
    line-height: 1.35;
    text-align: left;
    cursor: pointer;
    transition: background .16s, border-color .16s, color .16s;
}
.order-quick-choice:hover { border-color: #e0a775; background: #fff8f0; }
.order-quick-choice.is-selected {
    border-color: #df8b43;
    background: #fff1e3;
    color: #a95c20;
}
.order-quick-choice.is-selected::before { content: "✓"; margin-right: 6px; }
.order-quick-choice:focus-visible { outline: 2px solid #d87522; outline-offset: 2px; }
.order-dialog-scroll textarea {
    width: 100%;
    min-height: 86px;
    padding: 11px 12px;
    border: 1px solid #ddd7d1;
    border-radius: 11px;
    background: #fff;
    color: #3a332d;
    font-size: .76rem;
    line-height: 1.5;
    resize: vertical;
}
.order-dialog-scroll textarea:focus { outline: 2px solid #e8bc91; border-color: #d87522; }
.order-field-error { margin: 7px 0 0 !important; color: #b63d3d !important; font-size: .69rem !important; }
.order-field-error[hidden] { display: none !important; }
.order-dialog-footer {
    flex-shrink: 0;
    justify-content: flex-end;
    margin: 0;
    padding: 13px 20px calc(13px + env(safe-area-inset-bottom, 0px));
    border-top: 1px solid #eee5de;
    background: #fff;
}
.order-dialog-footer .order-action { min-height: 41px; padding: 10px 15px; font-size: .74rem; }
.order-dialog-footer .order-action:disabled { opacity: .45; cursor: not-allowed; }
.order-review-product {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 20px;
    border-bottom: 1px solid #f0eae5;
}
.order-review-image { display: grid; place-items: center; flex: 0 0 62px; height: 62px; overflow: hidden; border-radius: 10px; background: #f1ece6; }
.order-review-image img { width: 100%; height: 100%; object-fit: cover; }
.order-review-image img[hidden], .order-review-image span[hidden] { display: none; }
.order-review-product-text { display: grid; min-width: 0; gap: 4px; }
.order-review-product-text strong { font-size: .8rem; overflow-wrap: anywhere; }
.order-review-product-text span { color: #827972; font-size: .7rem; overflow-wrap: anywhere; }
.order-write-dialog .review-form { gap: 0; }
.order-write-dialog .review-stars { gap: 6px; margin-bottom: 5px; }
.order-write-dialog .review-star { width: 43px; height: 43px; font-size: 1.4rem; }

.review-choice-grid { gap: 7px; }
.review-text-meta { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; color: #837972; font-size: .67rem; }
.review-text-meta > span { margin-left: auto; flex-shrink: 0; }
@media (max-width: 560px) {
    .order-cancel-dialog, .order-write-dialog { width: calc(100vw - 18px); max-height: 91dvh; border-radius: 15px; }
    .order-dialog-heading { padding: 15px 15px 12px; }
    .order-dialog-scroll { padding: 13px 15px 16px; }
    .order-review-product { padding: 11px 15px; }
    .order-dialog-footer { padding: 11px 15px calc(11px + env(safe-area-inset-bottom, 0px)); flex-direction: row; }
    .order-dialog-footer .order-action { width: auto; flex: 1 1 0; min-width: 0; }
    .order-quick-choice { padding: 8px 10px; font-size: .68rem; }
}
@media (prefers-reduced-motion: reduce) { .order-quick-choice { transition: none; } }

/* Orders — aligned with Buyer Profile */
.page { min-width: 0; }
.page > .content.buyer-orders-content {
    position: fixed !important;
    top: 0 !important;
    bottom: 0 !important;
    left: 240px !important;
    right: 0 !important;
    width: calc(100vw - 240px) !important;
    max-width: none !important;
    min-width: 0 !important;
    height: auto !important;
    margin: 0 !important;
    padding: 79px 26px 34px !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
}
.orders-wrap { width: 100%; max-width: 1080px; min-width: 0; margin: 0 auto; }
.orders-hero {
    position: relative;
    isolation: isolate;
    display: flex;
    justify-content: space-between;
    align-items: center;
    min-width: 0;
    gap: 18px;
    margin: 0 0 17px !important;
    padding: 23px 26px;
    overflow: hidden;
    border: 1px solid #eadac9;
    border-radius: 19px;
    background: linear-gradient(113deg,#fffdfb 0%,#fff8f1 54%,#fcead6 100%);
    box-shadow: 0 12px 27px rgba(70,44,22,.05);
}
.orders-hero::before {
    content: "";
    position: absolute;
    z-index: -1;
    width: 225px;
    height: 225px;
    top: -152px;
    right: -37px;
    border: 1px solid rgba(224,154,92,.22);
    border-radius: 50%;
    box-shadow: 0 0 0 34px rgba(251,226,197,.23),0 0 0 75px rgba(253,235,216,.27);
    pointer-events: none;
}
.orders-heading { min-width: 0; }
.orders-eyebrow { display: block; margin-bottom: 5px; color: #b57945; font-size: .64rem; font-weight: 800; letter-spacing: .14em; }
.orders-heading h1 { margin: 0 !important; color: #312a25; font-size: clamp(1.55rem,3vw,2rem); font-weight: 800; line-height: 1.16; letter-spacing: -.045em; }
.orders-count { display: inline-flex; align-items: center; gap: 8px; flex: 0 0 auto; padding: 9px 13px; border: 1px solid #e7ded4; border-radius: 999px; background: #fffdfa; color: #715f51; font-size: .75rem; font-weight: 750; box-shadow: 0 3px 8px rgba(70,44,22,.035); }
.orders-count-dot { width: 8px; height: 8px; border-radius: 50%; background: #e98b40; box-shadow: 0 0 0 3px #fbe8d7; }
.order-tabs { display: grid; grid-template-columns: repeat(7,minmax(0,1fr)); gap: 7px; min-width: 0; margin: 0 0 16px; padding: 7px; border: 1px solid #ebe3dc; border-radius: 14px; background: #fff; box-shadow: 0 5px 15px rgba(52,36,23,.025); }
.order-tab { display: flex; align-items: center; justify-content: center; flex-wrap: wrap; gap: 5px; min-height: 41px; min-width: 0; padding: 8px 6px; border: 1px solid transparent; border-radius: 10px; background: transparent; color: #817265; font-size: .74rem; font-weight: 750; line-height: 1.25; cursor: pointer; transition: background .15s ease,color .15s ease; }
.order-tab:hover { border-color: #f3e4d6; background: #fff7ef; color: #aa652c; }
.order-tab.active { border-color: #db8037; background: #e98b40; color: #fff; box-shadow: 0 4px 10px rgba(202,111,36,.12); }
.order-tab small { display: inline-grid; place-items: center; min-width: 20px; min-height: 20px; margin: 0; padding: 2px 5px; border-radius: 8px; background: #f5efe9; color: #86786b; font-size: .64rem; font-weight: 800; opacity: 1; }
.order-tab.active small { background: rgba(255,255,255,.2); color: #fff; }
.order-tab:focus-visible, .order-row:focus-visible, .order-details-close:focus-visible, .order-action:focus-visible { outline: 3px solid #f6bc8b; outline-offset: 2px; }
.orders-table-wrap { min-width: 0; overflow: hidden; border: 1px solid #ebe3dc; border-radius: 18px; background: #fff; box-shadow: 0 10px 26px rgba(52,36,23,.04); }
.orders-table { width: 100%; min-width: 0; border-collapse: collapse; table-layout: fixed; }
.orders-table thead { background: #fffaf5; }
.orders-table th { padding: 17px 16px; border-bottom: 1px solid #f0e8e0; color: #967f6b; font-size: .68rem; font-weight: 800; text-align: left; white-space: nowrap; }
.orders-table th:nth-child(1) { width: 13%; }
.orders-table th:nth-child(2) { width: 20%; }
.orders-table th:nth-child(3) { width: 22%; }
.orders-table th:nth-child(4) { width: 16%; }
.orders-table th:nth-child(5) { width: 16%; }
.orders-table th:nth-child(6) { width: 13%; }
.orders-table td { min-width: 0; padding: 16px; border-bottom: 1px solid #f2ece7; color: #57493d; font-size: .78rem; font-weight: 600; line-height: 1.45; vertical-align: middle; }
.orders-table tbody tr:last-child td { border-bottom: 0; }
.order-row { cursor: pointer; transition: background .15s ease; scroll-margin-top: 95px; }
.order-row:hover, .order-row:focus-visible { background: #fff9f3; }
.order-row[hidden] { display: none !important; }
.table-order { color: #ba702f !important; font-size: .83rem !important; font-weight: 800 !important; }
.table-seller { font-weight: 750 !important; }
.table-seller, .table-items { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.table-items { color: #84776b !important; }
.table-money { color: #3d3026 !important; font-weight: 800 !important; text-align: right !important; white-space: nowrap; }
.table-date { color: #8b8178 !important; white-space: nowrap; }
.status-pill { display: inline-flex; align-items: center; width: fit-content; max-width: 100%; min-height: 26px !important; padding: 6px 10px !important; border-radius: 999px; font-size: .67rem !important; font-weight: 750; line-height: 1.2; letter-spacing: 0; text-transform: none; }
.table-status .status-pill { white-space: normal; }
.orders-filter-empty { padding: 36px 14px; color: #82766b; font-size: .8rem; text-align: center; }
.orders-filter-empty[hidden] { display: none; }
.orders-empty { border: 1px solid #ebe3dc; border-radius: 18px; box-shadow: 0 10px 26px rgba(52,36,23,.04); }
.orders-empty .order-action { width: auto !important; margin-top: 16px; padding: 10px 18px !important; }
/* Reuse Profile dialog surfaces and keep existing action hooks unchanged. */
.order-details-dialog { width: min(760px,calc(100vw - 24px)); max-width: 760px; max-height: min(88dvh,820px); }
.order-details-shell { max-height: min(88dvh,820px); border: 1px solid #eadfd6; border-radius: 19px; box-shadow: 0 24px 70px rgba(30,20,10,.22); }
.order-details-head { padding: 19px 22px 16px; background: #fffaf6; }
.order-details-kicker { color: #b57945; font-weight: 800; }
.order-details-head h2 { color: #382b23; font-size: 1.12rem; }
.order-details-close { border-radius: 50%; }
.order-details-body { min-width: 0; padding: 19px 22px 22px; overflow-x: hidden; overflow-y: auto; }
.order-popup-content { min-width: 0; overflow-wrap: anywhere; }
.order-popup-meta { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 7px 14px; margin-bottom: 13px; color: #8b8076; font-size: .74rem; }
.order-popup-status { display: flex; flex-wrap: wrap; gap: 7px; }
.order-section-title { margin: 20px 0 10px; color: #49392b; font-size: .88rem; font-weight: 800; }
.order-popup-items { min-width: 0; overflow: hidden; border: 1px solid #eee5dc; border-radius: 13px; }
.order-popup-item { display: grid; grid-template-columns: minmax(0,1fr) auto; gap: 8px 12px; align-items: start; min-width: 0; padding: 14px; border-bottom: 1px solid #f0e9e2; }
.order-popup-item:last-child { border-bottom: 0; }
.order-popup-item-main { min-width: 0; }
.order-popup-item .order-item-info { min-width: 0; }
.order-popup-item .order-item-name { display: block; color: #483e37; font-size: .78rem; font-weight: 800; line-height: 1.45; }
.order-popup-item .order-item-sub { color: #948477; font-size: .68rem; }
.order-popup-item .order-item-price { padding-top: 4px; font-size: .74rem; white-space: nowrap; }
.order-popup-item .order-saved-options, .order-popup-item .order-item-review { grid-column: 1 / -1; }
.order-saved-options { min-width: 0; padding: 11px 12px; border: 1px solid #f1e9e1; border-radius: 10px; background: #fcfaf7; }
.order-small-label { display: block; margin-bottom: 5px; color: #9a897b; font-size: .67rem; font-weight: 750; }
.order-choice-line { display: flex; justify-content: space-between; align-items: start; gap: 6px 12px; padding: 4px 0; color: #625952; font-size: .72rem; }
.order-choice-line > :last-child { flex: 0 0 auto; color: #b46b31; white-space: nowrap; }
.order-choice-detail { color: #877d74; font-size: .72rem; overflow-wrap: anywhere; }
.order-note-content { color: #615750; font-size: .75rem; line-height: 1.55; overflow-wrap: anywhere; }
.order-popup-total { display: flex; justify-content: space-between; gap: 12px; align-items: center; margin-top: 12px; padding: 14px 16px; border: 1px solid #f0dfcd; border-radius: 12px; background: #fff5eb; color: #71513a; font-size: .81rem; font-weight: 800; }
.order-popup-total strong { color: #b65c1e; font-size: 1.15rem; }
.order-popup-fields { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 9px; }
.order-popup-fields > div { min-width: 0; padding: 12px; border: 1px solid #eee7e0; border-radius: 11px; background: #fcfaf8; }
.order-popup-fields strong { display: block; color: #50473e; font-size: .76rem; font-weight: 750; overflow-wrap: anywhere; }
.order-popup-note { min-width: 0; margin-top: 9px; padding: 11px 13px; border: 1px solid #eee7e0; border-radius: 11px; }
.order-popup-actions { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 9px; margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee7e0; }
.order-popup-actions .order-action { width: auto !important; min-width: 110px !important; min-height: 40px !important; padding: 9px 14px !important; border-radius: 10px !important; font-size: .74rem !important; }
.order-item-review .review-link { margin-top: 0; font-size: .72rem; }
.order-action.primary { background: #e98b40; border-color: #d77d32; }
.order-action.primary:hover { background: #cd722c; }
.order-action { min-width: 0; }
.order-cancel-dialog, .order-write-dialog, .qr-payment-dialog, .review-view-dialog { max-width: calc(100vw - 24px); }
.order-dialog-scroll, .order-review-product-text, .qr-payment-shell, .qr-payment-body { min-width: 0; }
.order-quick-choice { min-width: 0; overflow-wrap: anywhere; }
@media (max-width: 1080px) {
    .order-tabs { grid-template-columns: repeat(3,minmax(0,1fr)); }
    /* Same six-column data, arranged as a compact list. No horizontal swipe. */
    .orders-table-wrap { overflow: hidden; }
    .orders-table, .orders-table tbody { display: block; width: 100%; min-width: 0; }
    .orders-table thead { display: none; }
    .orders-table tbody tr.order-row {
        display: grid;
        grid-template-columns: minmax(0,1fr) auto;
        grid-template-areas: "number status" "seller money" "items items" "date date";
        align-items: center;
        column-gap: 12px;
        row-gap: 8px;
        padding: 15px 17px;
        border-bottom: 1px solid #f0e9e2;
    }
    .orders-table tbody tr.order-row:last-child { border-bottom: 0; }
    .orders-table td { display: block; width: auto !important; min-width: 0; padding: 0; border: 0 !important; }
    .table-order { grid-area: number; font-size: .85rem !important; }
    .table-status { grid-area: status; justify-self: end; }
    .table-seller { grid-area: seller; font-size: .81rem !important; }
    .table-money { grid-area: money; font-size: .85rem !important; }
    .table-items { grid-area: items; font-size: .75rem !important; }
    .table-date { grid-area: date; font-size: .68rem !important; }
    .order-popup-fields { grid-template-columns: repeat(2,minmax(0,1fr)); }
}
@media (max-width: 840px) {
    .orders-hero { flex-direction: row; align-items: center; }
    .page { display: flex !important; flex-direction: column !important; }
    .page > .content.buyer-orders-content {
        position: relative !important;
        top: auto !important;
        bottom: auto !important;
        left: 0 !important;
        right: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        min-height: auto !important;
        height: auto !important;
        margin-left: 0 !important;
        padding: 80px 16px 32px !important;
        overflow-x: hidden !important;
        overflow-y: visible !important;
    }
    .orders-wrap { max-width: 100%; }
    .order-details-dialog, .order-details-shell { max-height: 91dvh; }
}
@media (max-width: 560px) {
    .page > .content.buyer-orders-content { padding: 76px 12px 28px !important; }
    .orders-hero { gap: 12px; margin-bottom: 14px !important; padding: 19px 17px; border-radius: 16px; }
    .orders-hero::before { right: -114px; }
    .orders-eyebrow { font-size: .57rem; }
    .orders-heading h1 { font-size: 1.58rem; }
    .orders-count { padding: 8px 9px; gap: 6px; font-size: .68rem; }
    .order-tabs { gap: 5px; margin-bottom: 13px; padding: 5px; border-radius: 12px; }
    .order-tab { min-height: 42px; padding: 6px 4px; font-size: .69rem; }
    .order-tab small { min-width: 18px; min-height: 18px; font-size: .59rem; }
    .orders-table-wrap { border-radius: 15px; }
    .orders-table tbody tr.order-row { padding: 14px 13px; }
    .table-status .status-pill { font-size: .62rem !important; }
    .order-details-dialog { width: calc(100vw - 20px); max-height: 93dvh; }
    .order-details-shell { max-height: 93dvh; border-radius: 16px; }
    .order-details-head { padding: 15px 16px 13px; }
    .order-details-body { padding: 15px 15px 19px; }

    .order-popup-item { grid-template-columns: minmax(0,1fr); padding: 12px; }
    .order-popup-item .order-item-price { padding-top: 0; }
    .order-choice-line { flex-wrap: wrap; }
    .order-popup-actions .order-action { flex: 1 1 130px; }
}
@media (max-width: 360px) {
    .orders-hero { padding: 17px 13px; }
    .orders-count { font-size: .61rem; padding: 7px; }
    .orders-count-dot { width: 6px; height: 6px; }
    .order-tabs { grid-template-columns: repeat(2,minmax(0,1fr)); }
    .orders-table tbody tr.order-row { padding: 12px; column-gap: 5px; }
    .table-money { font-size: .78rem !important; }
    .order-popup-fields { grid-template-columns: 1fr; }
    .order-popup-actions .order-action { flex: 1 1 100%; }
}
@media (prefers-reduced-motion: reduce) { .order-tab,.order-row { transition: none; } }

/* Seller Approvals-inspired Buyer Orders design. Visual updates only. */
.ui-icon { display:inline-block; width:20px; height:20px; flex:0 0 auto; vertical-align:middle; }
body { background:#f7f6f4; }
.orders-wrap { max-width:1320px; }
.orders-hero { background:none; box-shadow:none; border:0; border-radius:0; padding: 10px 2px 14px; margin-bottom:8px !important; overflow:visible; }
.orders-hero::before { display:none; }
.orders-heading h1 { font-size:clamp(1.55rem,2.5vw,2rem); line-height:1.2; letter-spacing:-.025em; color:#37332f; }
.order-metrics { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:15px; margin-bottom:23px; }
.order-metric { display:flex; justify-content:space-between; align-items:center; min-width:0; min-height:112px; padding:21px 23px; border:1px solid #eae5df; border-radius:23px; background:#fff; box-shadow:0 9px 24px rgba(44,31,21,.035); }
.order-metric > div { min-width:0; }
.order-metric span:not(.order-metric-icon) { display:block; margin-bottom:10px; color:#85807b; font-size:.82rem; font-weight:650; }
.order-metric strong { display:block; color:#37332f; font-size:1.72rem; line-height:1.1; font-weight:800; }
.order-metric-icon { display:grid; place-items:center; width:48px; height:48px; flex:0 0 auto; border-radius:15px; }
.order-metric-icon.orange { color:#da8538; background:#fff0e0; }
.order-metric-icon.amber { color:#c68b26; background:#fff4de; }
.order-metric-icon.blue { color:#5181b5; background:#eaf3fc; }
.order-metric-icon.green { color:#489d6b; background:#e9f7ee; }
.order-metric-icon svg { width:23px; height:23px; }
.orders-panel { min-width:0; border:1px solid #eae5df; border-radius:24px; background:#fff; padding:23px 27px 16px; box-shadow:0 10px 27px rgba(44,31,21,.035); }
.orders-controls { display:flex; justify-content:space-between; align-items:center; gap:20px; margin-bottom:21px; min-width:0; }
.order-tabs { display:flex; flex:1 1 auto; flex-wrap:wrap; gap:4px; min-width:0; margin:0 !important; padding:0; border:none; border-radius:0; box-shadow:none; }
.order-tab { display:inline-flex; flex:0 0 auto; align-items:center; justify-content:center; gap:7px; min-width:0; min-height:43px; padding:10px 9px; border:1px solid transparent; border-radius:13px; background:transparent; font-size:.76rem; font-weight:750; color:#716b65; white-space:nowrap; }
.order-tab:hover { background:#fff7f0; border-color:#f6ddc4; }
.order-tab.active { background:#fff3e8; border-color:#f5d4b8; color:#ae642b; box-shadow:none; }
.order-tab small { border-radius:7px; background:#f5f2ef; color:#827970; padding:3px 6px; min-width:25px; font-size:.66rem; }
.order-tab.active small { background:#ffe6ce; color:#a66027; }
.orders-search { display:flex; align-items:center; gap:12px; flex:0 1 320px; min-width:165px; height:50px; padding:0 15px; border:1px solid #e8e3dd; border-radius:15px; background:#faf9f8; color:#a9a29c; }
.orders-search:focus-within { border-color:#e8a36b; box-shadow:0 0 0 3px rgba(233,139,64,.10); background:#fff; }
.orders-search .ui-icon { width:21px; height:21px; }
.orders-search input { width:100%; min-width:0; border:0; outline:0; background:transparent; color:#403a35; font:inherit; font-size:.85rem; }
.orders-search input::placeholder { color:#9d9690; }
.orders-search input::-webkit-search-cancel-button { cursor:pointer; }
.orders-table-wrap { border:none; border-radius:0; background:#fff; box-shadow:none; overflow:visible; }
.orders-table { table-layout:fixed; border-collapse:collapse; width:100%; }
.orders-table thead { background:#fff; }
.orders-table th { color:#847d76; font-size:.73rem; font-weight:750; padding:14px 12px 18px; border-bottom:1px solid #eae6e1; }
.orders-table th:nth-child(1) { width:13%; }
.orders-table th:nth-child(2) { width:24%; }
.orders-table th:nth-child(3) { width:19%; }
.orders-table th:nth-child(4) { width:14%; }
.orders-table th:nth-child(5) { width:15%; }
.orders-table th:nth-child(6) { width:12%; }
.orders-table th:nth-child(7) { width:3%; }
.orders-table td { padding:16px 12px; font-size:.81rem; border-bottom:1px solid #f0ebe6; }
.order-row:hover, .order-row:focus-visible { background:#fff9f4; }
.table-order { display:table-cell; }
.order-cell-content { display:inline-flex; align-items:center; gap:9px; min-width:0; max-width:100%; vertical-align:middle; }
.order-row-icon,.order-seller-icon { display:inline-grid; align-items:center; justify-items:center; flex:0 0 auto; width:35px; height:35px; border-radius:11px; background:#fff0e0; color:#bd773c; }
.order-row-icon .ui-icon,.order-seller-icon .ui-icon { width:17px; height:17px; }
.order-row-id { font-size:.86rem; font-weight:800; }
.table-seller { display:table-cell; }
.order-seller-icon { background:#f1f4f1; color:#77927c; }
.order-seller-name { min-width:0; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; color:#3e3934; font-weight:750; }
.table-items { color:#8a8078 !important; font-weight:500 !important; }
.table-money { color:#3b3631 !important; font-weight:800 !important; }
.table-date { font-size:.76rem !important; color:#807870 !important; }
.table-open { text-align:right; color:#b1a59b !important; }
.table-open .ui-icon { width:17px; height:17px; }
.status-pill { gap:7px; font-weight:750; font-size:.71rem !important; border-radius:11px; min-height:31px !important; padding:7px 10px !important; }
.status-pill::before { content:""; display:inline-block; width:7px; height:7px; flex:0 0 auto; background:currentColor; border-radius:50%; opacity:.55; }
.orders-filter-empty { padding:48px 15px; }
.orders-empty { border:0; box-shadow:none; }
/* Header and footer do not scroll. Only Order Details content scrolls. */
.order-details-dialog { width:min(790px,calc(100vw - 24px)); max-width:790px; max-height:min(90dvh,850px); padding:0; border:0; border-radius:20px; overflow:hidden; background:#fff; }
.order-details-shell { display:flex; flex-direction:column; width:100%; height:min(90dvh,850px); max-height:none; min-height:0; border:0; border-radius:20px; overflow:hidden; background:#fff; }
.order-details-head { flex:0 0 auto; align-items:center; padding:19px 24px; background:#fffaf6; border-bottom:1px solid #eee5dc; }
.order-details-kicker { color:#b46a32; font-size:.69rem; letter-spacing:.06em; }
.order-details-head h2 { margin-top:3px; font-size:1.25rem; }
.order-details-close { display:grid; place-items:center; width:38px; height:38px; background:#fff; border:1px solid #e6ddd4; border-radius:50%; color:#615950; }
.order-details-close:hover { border-color:#dfa776; background:#fff8f1; }
.order-details-body { flex:1 1 auto; min-height:0; padding:18px 24px 25px; overflow-x:hidden; overflow-y:auto; overscroll-behavior:contain; }
.order-details-footer { display:flex; align-items:center; justify-content:flex-end; flex:0 0 auto; flex-wrap:wrap; gap:9px; padding:14px 24px; border-top:1px solid #ede6df; background:#fff; box-shadow:0 -8px 24px rgba(38,27,17,.035); }
.order-details-footer[hidden] { display:none !important; }
.order-details-footer .order-action { width:auto !important; flex:0 1 auto; display:inline-flex !important; gap:8px; min-width:0 !important; min-height:42px !important; padding:10px 15px !important; border-radius:11px !important; font-size:.77rem !important; white-space:nowrap !important; }
.order-details-footer .ui-icon { width:17px; height:17px; }
.order-section-title { font-size:.92rem; }
.order-popup-item .order-item-name { font-size:.81rem; }
.order-popup-fields strong { font-size:.8rem; }
.order-popup-item .order-item-sub,.order-small-label { font-size:.72rem; }
.order-popup-total { margin-top:14px; }
@media(max-width:1360px) {
    .orders-panel { padding:20px 20px 13px; }
    .orders-controls { flex-wrap:wrap; }
    .order-tabs { flex:1 1 100%; }
    .orders-search { flex:1 1 100%; width:100%; }
}
@media(max-width:1080px) {
    .orders-table thead { display:none; }
    .orders-table,.orders-table tbody { display:block; min-width:0; }
    .orders-table tbody tr.order-row { display:grid; position:relative; grid-template-columns:minmax(0,1fr) auto; grid-template-areas:"number status" "seller seller" "items items" "date money"; column-gap:12px; row-gap:10px; padding:18px; margin:0 0 12px; border:1px solid #eee7e0; border-radius:17px; background:#fff; }
    .orders-table tbody tr.order-row:last-child { border-bottom:1px solid #eee7e0; }
    .orders-table tbody tr.order-row:hover,.orders-table tbody tr.order-row:focus-visible { border-color:#edc5a3; background:#fffaf6; }
    .orders-table td { display:block; min-width:0; width:auto !important; padding:0; border:0 !important; }
    .table-order { grid-area:number; }
    .table-status { grid-area:status; align-self:center; justify-self:end; }
    .table-seller { grid-area:seller; padding-top:12px !important; border-top:1px solid #f2ece6 !important; }
    .table-items { grid-area:items; padding-left:45px !important; font-size:.78rem !important; }
    .table-items::before { content:"Items: "; color:#a49a90; }
    .table-money { grid-area:money; align-self:end; }
    .table-date { grid-area:date; color:#958980 !important; align-self:center; padding-top:6px !important; }
    .table-date::before { content:"Ordered "; }
    .table-open { display:none !important; }
    .order-seller-name { white-space:normal; overflow-wrap:anywhere; }
    .orders-table-wrap { overflow:visible; }
}
@media(max-width:840px) {
    .orders-wrap { max-width:100%; }
    .order-metrics { gap:12px; }
    .order-metric { padding:17px; min-height:102px; }
    .order-metric-icon { width:41px; height:41px; }
    .orders-panel { padding:17px; }
}
@media(max-width:650px) {
    .orders-hero { padding:4px 1px 11px; }
    .order-metrics { grid-template-columns:repeat(2,minmax(0,1fr)); gap:9px; margin-bottom:15px; }
    .order-metric { min-height:92px; padding:15px; border-radius:17px; }
    .order-metric span:not(.order-metric-icon) { font-size:.69rem; margin-bottom:7px; }
    .order-metric strong { font-size:1.44rem; }
    .order-metric-icon { width:36px; height:36px; border-radius:12px; }
    .order-metric-icon .ui-icon { width:19px; height:19px; }
    .orders-panel { padding:12px; border-radius:19px; }
    .orders-controls { gap:13px; margin-bottom:14px; }
    .order-tabs { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:5px; }
    .order-tab { min-width:0; min-height:41px; padding:7px 4px; font-size:.69rem; border-radius:10px; white-space:normal; }
    .order-tab small { font-size:.61rem; min-width:21px; padding:2px 4px; }
    .orders-search { height:46px; border-radius:13px; }
    .orders-table tbody tr.order-row {
        grid-template-areas:
            "number status"
            "divider divider"
            "seller seller"
            "items items"
            "date money";
        padding:13px;
        border-radius:15px;
        row-gap:8px;
    }
    .orders-table tbody tr.order-row::before {
        content:"";
        grid-area:divider;
        display:block;
        width:100%;
        border-top:1px solid #e8d9ca;
        margin:3px 0 1px;
    }
    .order-row-icon,.order-seller-icon { width:35px; height:35px; }
    .table-order .order-row-id { font-size:.86rem; }
    .table-seller { padding-top:10px !important; }
    .table-items { padding-left:45px !important; font-size:.73rem !important; }
    .table-money { font-size:.9rem !important; }
    .table-date { font-size:.68rem !important; }
    .table-status .status-pill { font-size:.65rem !important; min-height:28px !important; padding:6px 8px !important; }
    .order-details-dialog { width:calc(100vw - 16px); max-height:94dvh; border-radius:17px; }
    .order-details-shell { height:94dvh; max-height:none; border-radius:17px; }
    .order-details-head { padding:14px 15px; }
    .order-details-body { padding:14px 14px 19px; }
    .order-details-footer { padding:11px 13px max(11px, env(safe-area-inset-bottom)); gap:8px; }
    .order-details-footer .order-action { flex:1 1 calc(50% - 5px); min-height:44px !important; padding:9px 10px !important; font-size:.78rem !important; white-space:normal !important; }
    .order-details-footer .order-action .ui-icon { display:inline-block; width:16px; height:16px; }
    .order-popup-fields { grid-template-columns:repeat(2,minmax(0,1fr)); }
}
@media(max-width:365px) {
    .order-tabs { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .order-metric { padding:12px; }
    .order-metric-icon { width:32px; height:32px; }
    .order-popup-fields { grid-template-columns:1fr; }
    .order-details-footer .order-action { font-size:.69rem !important; }
}

/* One-row Buyer status tabs at every screen width. */
.order-tabs {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    flex: 1 1 auto;
    flex-wrap: nowrap;
    width: 100%;
    min-width: 0;
    gap: 4px;
}
.order-tab {
    display: flex;
    flex: initial;
    width: 100%;
    min-width: 0;
    justify-content: center;
    gap: 4px;
    padding: 8px 3px;
    font-size: clamp(.6rem, .85vw, .74rem);
    line-height: 1.15;
}
.status-pill.to-pay { background: var(--orders-orange-soft); color: var(--orders-orange-dark); }
.status-pill.closed { background: var(--orders-red-soft); color: var(--orders-red); }
@media (max-width: 650px) {
    .order-tabs {
        grid-template-columns: repeat(7, minmax(0, 1fr));
        gap: 2px;
    }
    .order-tab {
        flex-direction: column;
        min-height: 53px;
        gap: 3px;
        padding: 5px 1px;
        font-size: clamp(.5rem, 2.15vw, .68rem);
        letter-spacing: -.025em;
        white-space: normal;
        overflow-wrap: anywhere;
        text-align: center;
    }
    .order-tab small {
        min-width: 17px;
        min-height: 17px;
        padding: 2px 4px;
        font-size: .57rem;
    }
}

/* Keep the date on the right of the single order status pill. */
.order-popup-topline { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px 14px; margin-bottom:14px; }
.order-popup-topline .order-popup-status { min-width:0; }
.order-popup-topline .order-popup-meta { margin:0; min-width:0; text-align:right; }
/* Match Seller and Buyer order information without native browser controls. */
.order-section-heading { display:flex; align-items:center; justify-content:space-between; gap:10px 14px; margin:20px 0 10px; }
.order-section-heading .order-section-title { margin:0; }
.order-section-heading .status-pill { flex:0 0 auto; max-width:58%; text-align:center; }
.order-popup-fields.order-popup-store-details,
.order-popup-fields.order-popup-delivery-fields,
.order-popup-fields.order-popup-qr-references { grid-template-columns:repeat(2,minmax(0,1fr)); }
.order-popup-delivery-fields .order-popup-full-width { grid-column:1 / -1; }
.order-popup-fields.order-popup-qr-references { margin-top:9px; }
.order-popup-qr-references strong { overflow-wrap:anywhere; word-break:break-word; }
</style>
</head>
<body>
<div class="page">
    <?php include __DIR__ . '/buyer_nav.php'; ?>
    <main class="content buyer-orders-content">
        <?php include __DIR__ . '/buyer_search.php'; ?>
        <div class="orders-wrap">
            <header class="orders-hero">
                <div class="orders-heading">
                    <h1>My Orders</h1>
                </div>
            </header>
            <?php if ($errorMessage !== ''): ?>
                <div class="notice error" role="alert">
                    <?= orderE($errorMessage) ?>
                </div>
            <?php endif; ?>
            <?php if ($actionError !== ''): ?>
                <div class="notice error" role="alert">
                    <?= orderE($actionError) ?>
                </div>
            <?php endif; ?>
            <?php if ($successMessage !== ''): ?>
                <div class="notice success" role="status">
                    <?= orderE($successMessage) ?>
                </div>
            <?php endif; ?>
            <section class="order-metrics" aria-label="Order overview">
                <div class="order-metric"><div><span>Total Orders</span><strong><?= (int)$counts['all'] ?></strong></div><span class="order-metric-icon orange"><?= orderIcon('bag') ?></span></div>
                <div class="order-metric"><div><span>Pending</span><strong><?= (int)$counts['pending'] ?></strong></div><span class="order-metric-icon amber"><?= orderIcon('clock') ?></span></div>
                <div class="order-metric"><div><span>Processing</span><strong><?= (int)$counts['processing'] ?></strong></div><span class="order-metric-icon blue"><?= orderIcon('truck') ?></span></div>
                <div class="order-metric"><div><span>Completed</span><strong><?= (int)$counts['completed'] ?></strong></div><span class="order-metric-icon green"><?= orderIcon('check') ?></span></div>
            </section>
            <section class="orders-panel" aria-label="Order list">
            <div class="orders-controls">
            <div class="order-tabs" role="group" aria-label="Order status">
                <?php foreach ([
                    'all' => 'All',
                    'pending' => 'Pending',
                    'accepted' => 'Accepted',
                    'to-pay' => 'To Pay',
                    'processing' => 'Processing',
                    'completed' => 'Completed',
                    'closed' => 'Closed'
                ] as $key => $label): ?>
                    <button class="order-tab <?= $key === 'all' ? 'active' : '' ?>"
                            type="button" data-filter="<?= orderE($key) ?>"
                            aria-pressed="<?= $key === 'all' ? 'true' : 'false' ?>">
                        <?= orderE($label) ?><small><?= (int)$counts[$key] ?></small>
                    </button>
                <?php endforeach; ?>
            </div>
            <label class="orders-search" for="ordersSearch">
                <?= orderIcon('search') ?>
                <input id="ordersSearch" type="search" placeholder="Search order or seller" autocomplete="off" aria-label="Search orders">
            </label>
            </div>
            <?php if (!$orders && $errorMessage === ''): ?>
                <div class="orders-empty">
                    <div class="orders-empty-icon" aria-hidden="true">📦</div>
                    <h2>No orders yet</h2>
                    <a href="foryou.php" class="order-action primary">Browse Products</a>
                </div>
            <?php elseif ($orders): ?>
                <div class="orders-table-wrap" id="ordersList">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th scope="col">Order</th>
                                <th scope="col">Seller</th>
                                <th scope="col">Items</th>
                                <th scope="col" class="table-money">Total</th>
                                <th scope="col">Status</th>
                                <th scope="col">Date</th>
                                <th scope="col" aria-label="Open order"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <?php
                                $orderId = (int)$order['order_id'];
                                $orderStatus = orderNormalizeStatus($order['order_status'] ?? 'pending');
                                $displayStatus = unifiedOrderStatus($order);
                                $paymentInfo = orderPaymentStatus($order);
                                $paymentLabel = orderPaymentLabel($order);
                                $deliveryLabel = orderDeliveryLabel($order['fulfillment_method'] ?? '');
                                $locationLabel = orderDeliveryLocationLabel($order['fulfillment_method'] ?? '');
                                $group = orderGroup($order);
                                $items = $orderItems[$orderId] ?? [];
                                $canCancel = $orderStatus === 'pending'
                                    && orderStatusKey($order['arrangement_status'] ?? '') !== 'accepted'
                                    && !in_array($paymentInfo['key'], ['paid','refunded'], true);
                                $canPay = orderIsQr($order)
                                    && $orderStatus === 'accepted'
                                    && orderStatusKey($order['arrangement_status'] ?? '') === 'accepted'
                                    && !in_array($paymentInfo['key'], ['paid','refunded','cancelled'], true);
                                $arrangementDate = trim((string)($order['arrangement_date'] ?? ''));
                                $arrangementTime = trim((string)($order['arrangement_time'] ?? ''));
                                $arrangementLocation = trim((string)($order['arrangement_location'] ?? ''));
                                ?>
                                <tr id="order-<?= $orderId ?>" class="order-row"
                                    data-group="<?= orderE($group) ?>"
                                    data-order-id="<?= $orderId ?>"
                                    data-order-title="Order #<?= $orderId ?>"
                                    data-search="<?= orderE('#' . $orderId . ' ' . $order['seller_name'] . ' ' . ($order['items_summary'] ?? '') . ' ' . $displayStatus['label']) ?>"
                                    tabindex="0" aria-label="View Order #<?= $orderId ?> details">
                                    <td class="table-order"><span class="order-cell-content"><span class="order-row-icon"><?= orderIcon('package') ?></span><span class="order-row-id">#<?= $orderId ?></span></span></td>
                                    <td class="table-seller"><span class="order-cell-content"><span class="order-seller-icon"><?= orderIcon('store') ?></span><span class="order-seller-name"><?= orderE($order['seller_name']) ?></span></span></td>
                                    <td class="table-items" title="<?= orderE($order['items_summary'] ?? '') ?>">
                                        <?= orderE($order['items_summary'] ?: 'Product') ?>
                                    </td>
                                    <td class="table-money"><?= orderMoney($order['total_amount']) ?></td>
                                    <td class="table-status">
                                        <span class="status-pill <?= orderE($displayStatus['key']) ?>">
                                            <?= orderE($displayStatus['label']) ?>
                                        </span>
                                        <template class="order-detail-template" data-order-id="<?= $orderId ?>">
                                            <div class="order-popup-content">
                                                <div class="order-popup-topline">
                                                    <div class="order-popup-status">
                                                        <span class="status-pill <?= orderE($displayStatus['key']) ?>">
                                                            <?= orderE($displayStatus['label']) ?>
                                                        </span>
                                                    </div>
                                                    <div class="order-popup-meta">
                                                        <span><?= orderE(orderDate($order['ordered_at'])) ?></span>
                                                    </div>
                                                </div>
                                                <h3 class="order-section-title">Products</h3>
                                                <div class="order-popup-items">
                                                    <?php foreach ($items as $item): ?>
                                                        <?php
                                                        $photo = trim((string)($item['product_photo'] ?? ''));
                                                        $safePhoto = orderReviewPhoto($photo);
                                                        $itemId = (int)$item['order_item_id'];
                                                        $existingReview = $reviewsByItem[$itemId] ?? null;
                                                        $unitPrice = ($item['agreed_unit_price'] !== null && $item['agreed_unit_price'] !== '')
                                                            ? (float)$item['agreed_unit_price'] : (float)$item['unit_price'];
                                                        $specialRequest = trim((string)($item['special_request'] ?? ''));
                                                        $savedChoices = orderSavedChoices($specialRequest);
                                                        ?>
                                                        <div class="order-popup-item">
                                                            <div class="order-item-info">
                                                                <?php if ($safePhoto !== ''): ?>
                                                                    <img class="order-item-photo" src="<?= orderE($safePhoto) ?>"
                                                                         alt="" loading="lazy">
                                                                <?php else: ?>
                                                                    <div class="order-item-placeholder" aria-hidden="true">🛍</div>
                                                                <?php endif; ?>
                                                                <div class="order-popup-item-main">
                                                                    <strong class="order-item-name"><?= orderE($item['product_name']) ?></strong>
                                                                    <span class="order-item-sub"><?= (int)$item['quantity'] ?> × <?= orderMoney($unitPrice) ?></span>
                                                                </div>
                                                            </div>
                                                            <strong class="order-item-price"><?= orderMoney($unitPrice * (int)$item['quantity']) ?></strong>
                                                            <?php if ($savedChoices): ?>
                                                                <div class="order-saved-options">
                                                                    <span class="order-small-label">Selected Add-ons</span>
                                                                    <?php foreach ($savedChoices as $choice): ?>
                                                                        <div class="order-choice-line">
                                                                            <span><?= orderE($choice['name']) ?>
                                                                                <?php if ($choice['details'] !== ''): ?>
                                                                                    <span class="order-choice-detail">— <?= orderE($choice['details']) ?></span>
                                                                                <?php endif; ?>
                                                                            </span>
                                                                            <span>+<?= orderMoney($choice['price']) ?> each</span>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php elseif ($specialRequest !== ''): ?>
                                                                <div class="order-saved-options">
                                                                    <span class="order-small-label">Item Details</span>
                                                                    <div class="order-note-content"><?= nl2br(orderE($specialRequest)) ?></div>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if ($reviewsReady && $orderStatus === 'completed'): ?>
                                                                <div class="order-item-review">
                                                                    <?php if ($existingReview): ?>
                                                                        <button class="review-link view-review" type="button"
                                                                                data-product-name="<?= orderE($item['product_name']) ?>"
                                                                                data-rating="<?= (int)$existingReview['rating'] ?>"
                                                                                data-review-text="<?= orderE($existingReview['review_text'] ?? '') ?>"
                                                                                data-review-date="<?= orderE(orderDate($existingReview['created_at'] ?? '')) ?>">
                                                                            ★ <?= (int)$existingReview['rating'] ?>/5 · View Review
                                                                        </button>
                                                                    <?php else: ?>
                                                                        <button class="review-link review-product" type="button"
                                                                                data-order-id="<?= $orderId ?>"
                                                                                data-order-item-id="<?= $itemId ?>"
                                                                                data-product-name="<?= orderE($item['product_name']) ?>"
                                                                                data-seller-name="<?= orderE($order['seller_name']) ?>"
                                                                                data-product-photo="<?= orderE($safePhoto) ?>">
                                                                            Rate & Review
                                                                        </button>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <div class="order-popup-total">
                                                    <span>Order Total</span><strong><?= orderMoney($order['total_amount']) ?></strong>
                                                </div>
                                                <h3 class="order-section-title">Store Details</h3>
                                                <div class="order-popup-fields order-popup-store-details">
                                                    <div><span class="order-small-label">Business / Shop</span><strong><?= orderE($order['shop_name']) ?></strong></div>
                                                    <div><span class="order-small-label">Seller Name</span><strong><?= orderE($order['seller_person_name']) ?></strong></div>
                                                </div>
                                                <div class="order-section-heading">
                                                    <h3 class="order-section-title">Delivery &amp; Payment</h3>
                                                    <span class="status-pill <?= orderE($paymentInfo['key']) ?>">
                                                        <?= orderE($paymentInfo['label']) ?>
                                                    </span>
                                                </div>
                                                <div class="order-popup-fields order-popup-delivery-fields">
                                                    <div><span class="order-small-label">Delivery</span><strong><?= orderE($deliveryLabel) ?></strong></div>
                                                    <div><span class="order-small-label">Payment</span><strong><?= orderE($paymentLabel) ?></strong></div>
                                                    <div class="order-popup-full-width"><span class="order-small-label"><?= orderE($locationLabel) ?></span><strong><?= orderE($arrangementLocation ?: 'Not set') ?></strong></div>
                                                    <div><span class="order-small-label">Date</span><strong><?= orderE($arrangementDate ? orderShortDate($arrangementDate) : 'Not set') ?></strong></div>
                                                    <div><span class="order-small-label">Time</span><strong><?= orderE($arrangementTime ? orderTimeLabel($arrangementTime) : 'Not set') ?></strong></div>
                                                </div>
                                                <?php if (orderIsQr($order) && $paymentInfo['key'] === 'paid'): ?>
                                                    <?php
                                                    // PayMongo references must be provider-issued IDs, never made-up order IDs.
                                                    $paymongoPaymentReference = trim((string)($order['paymongo_payment_id'] ?? ''));
                                                    $paymongoIntentReference = trim((string)($order['paymongo_payment_intent_id'] ?? ''));
                                                    if ($paymongoIntentReference === '') {
                                                        $paymongoIntentReference = trim((string)($order['qr_payment_intent_id'] ?? ''));
                                                    }
                                                    ?>
                                                    <?php if ($paymongoPaymentReference !== '' || $paymongoIntentReference !== ''): ?>
                                                        <div class="order-popup-fields order-popup-qr-references">
                                                            <?php if ($paymongoPaymentReference !== ''): ?>
                                                                <div><span class="order-small-label">QR Ph Payment Reference</span><strong><?= orderE($paymongoPaymentReference) ?></strong></div>
                                                            <?php endif; ?>
                                                            <?php if ($paymongoIntentReference !== ''): ?>
                                                                <div><span class="order-small-label">PayMongo Intent Reference</span><strong><?= orderE($paymongoIntentReference) ?></strong></div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <?php if (trim((string)($order['arrangement_notes'] ?? '')) !== ''): ?>
                                                    <div class="order-popup-note"><span class="order-small-label">Delivery Note</span>
                                                        <div class="order-note-content"><?= nl2br(orderE($order['arrangement_notes'])) ?></div>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (trim((string)($order['notes'] ?? '')) !== ''): ?>
                                                    <div class="order-popup-note"><span class="order-small-label">Order Note</span>
                                                        <div class="order-note-content"><?= nl2br(orderE($order['notes'])) ?></div>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="order-popup-actions">
                                                    <a class="order-action" href="messages.php?seller_id=<?= (int)$order['seller_id'] ?>&amp;order_id=<?= $orderId ?>"><?= orderIcon('message') ?> Message Seller</a>
                                                    <?php if ($canCancel): ?>
                                                        <button class="order-action danger cancel-order" type="button" data-id="<?= $orderId ?>"><?= orderIcon('trash') ?> Cancel Order</button>
                                                    <?php endif; ?>
                                                    <?php if ($canPay): ?>
                                                        <button class="order-action primary pay-order-qr" type="button"
                                                                data-order-id="<?= $orderId ?>"
                                                                data-seller-id="<?= (int)$order['seller_id'] ?>"
                                                                data-order-total="<?= orderE((string)$order['total_amount']) ?>"><?= orderIcon('card') ?> Pay Now</button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </template>
                                    </td>
                                    <td class="table-date"><?= orderE(orderShortDate($order['ordered_at'])) ?></td>
                                    <td class="table-open"><?= orderIcon('chevron') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div id="ordersFilteredEmpty" class="orders-filter-empty" hidden>No orders here.</div>
                </div>
            <?php endif; ?>
            </section>
        </div>
    </main>
</div>
<dialog class="order-details-dialog" id="orderDetailsDialog" aria-labelledby="orderDetailsTitle">
    <div class="order-details-shell">
        <div class="order-details-head">
            <div>
                <span class="order-details-kicker">My Orders</span>
                <h2 id="orderDetailsTitle">Order</h2>
            </div>
            <button class="order-details-close" id="orderDetailsClose" type="button" aria-label="Close order details"><?= orderIcon('x') ?></button>
        </div>
        <div class="order-details-body" id="orderDetailsBody"></div>
        <div class="order-details-footer" id="orderDetailsFooter" hidden></div>
    </div>
</dialog>
<dialog
    class="qr-payment-dialog"
    id="qrPaymentDialog"
    aria-labelledby="qrPaymentTitle"
>
    <div class="qr-payment-shell">
        <div class="qr-payment-head">
            <div>
                <span class="qr-payment-kicker">QR Ph via PayMongo</span>
                <h2 id="qrPaymentTitle">Pay Order</h2>
            </div>
            <button
                class="qr-payment-close"
                id="qrPaymentClose"
                type="button"
                aria-label="Close QR payment"
            >×</button>
        </div>
        <div class="qr-payment-body">
            <div class="qr-payment-summary">
                <div class="qr-payment-order" id="qrPaymentOrder">Order</div>
                <div class="qr-payment-amount" id="qrPaymentAmount">₱0.00</div>
            </div>
            <div class="qr-payment-code">
                <img
                    id="qrPaymentImage"
                    alt="QR Ph payment code"
                    hidden
                >
            </div>
            <div class="qr-payment-timer" id="qrPaymentTimer">15:00</div>
            <div class="qr-payment-feedback" id="qrPaymentFeedback">
                Generating your QR Ph code...
            </div>
            <div class="qr-payment-actions">
                <button
                    class="order-action"
                    id="qrPaymentRegenerate"
                    type="button"
                    hidden
                >
                    Generate New QR
                </button>
                <button
                    class="order-action primary"
                    id="qrPaymentSimulate"
                    type="button"
                    hidden
                >
                    Simulate Payment
                </button>
            </div>
        </div>
    </div>
</dialog>
<dialog class="confirm-dialog order-cancel-dialog" id="cancelDialog" aria-labelledby="cancelDialogTitle">
    <div class="order-dialog-heading">
        <span class="order-dialog-eyebrow">Order cancellation</span>
        <h2 id="cancelDialogTitle">Cancel Order #<span id="cancelOrderNumber"></span>?</h2>
        <p>Select a reason.</p>
    </div>
    <form method="post" id="cancelForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= orderE($_SESSION['buyer_orders_csrf']) ?>">
        <input type="hidden" name="action" value="cancel_order">
        <input type="hidden" name="order_id" id="cancelOrderId" value="">
        <input type="hidden" name="cancel_reason" id="cancelReason" value="">
        <div class="order-dialog-scroll">
            <div class="order-dialog-field-title">Why are you cancelling?</div>
            <div class="order-choice-grid" id="cancelReasonChoices" role="group" aria-label="Cancellation reasons">
                <?php foreach (orderCancellationReasons() as $reasonCode => $reasonLabel): ?>
                    <button class="order-quick-choice cancel-reason-choice" type="button"
                        data-reason="<?= orderE($reasonCode) ?>" aria-pressed="false">
                        <?= orderE($reasonLabel) ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <p class="order-field-error" id="cancelReasonError" role="alert" hidden></p>
            <label class="order-dialog-field-title" for="cancelDetails">
                Additional details <span id="cancelDetailsRequirement">(optional)</span>
            </label>
            <textarea id="cancelDetails" name="cancel_details" maxlength="300" rows="3"
                placeholder="Add details if needed..."></textarea>
            <p class="order-field-error" id="cancelDetailsError" role="alert" hidden></p>
        </div>
        <div class="confirm-actions order-dialog-footer">
            <button class="order-action" type="button" id="cancelDialogClose">Keep Order</button>
            <button class="order-action danger" type="submit" id="cancelSubmit" disabled>Confirm Cancellation</button>
        </div>
    </form>
</dialog>
<?php if ($reviewsReady): ?>
<dialog class="review-dialog order-write-dialog" id="reviewDialog" aria-labelledby="reviewDialogTitle">
    <div class="order-dialog-heading">
        <span class="order-dialog-eyebrow">Completed order</span>
        <h2 id="reviewDialogTitle">Write a Review</h2>
        <p>Rate the product and your experience with the seller.</p>
    </div>
    <div class="order-review-product">
        <div class="order-review-image">
            <img id="reviewProductPhoto" src="" alt="Product photo" hidden>
            <span id="reviewPhotoFallback" aria-hidden="true">🛍</span>
        </div>
        <div class="order-review-product-text">
            <strong id="reviewProductName">Product</strong>
            <span id="reviewSellerName">Seller</span>
        </div>
    </div>
    <form method="post" class="review-form" id="reviewForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= orderE($_SESSION['buyer_orders_csrf']) ?>">
        <input type="hidden" name="action" value="submit_review">
        <input type="hidden" name="order_id" id="reviewOrderId" value="">
        <input type="hidden" name="order_item_id" id="reviewOrderItemId" value="">
        <input type="hidden" name="rating" id="reviewRating" value="0">
        <div class="order-dialog-scroll">
            <div class="order-dialog-field-title">Overall rating</div>
            <div class="review-stars" role="group" aria-label="Overall rating">
                <?php for ($star = 1; $star <= 5; $star++): ?>
                    <button class="review-star" type="button" data-rating="<?= $star ?>"
                        aria-pressed="false" aria-label="<?= $star ?> star<?= $star === 1 ? '' : 's' ?>">★</button>
                <?php endfor; ?>
            </div>
            <p class="order-field-error" id="reviewRatingError" role="alert" hidden></p>
            <div class="order-dialog-field-title">Quick write <small>(choose more than one)</small></div>
            <span class="order-quick-caption">Product</span>
            <div class="order-choice-grid review-choice-grid" role="group" aria-label="Quick product reviews">
                <?php foreach ([
                    'Looks just like the photos.',
                    'Great quality and craftsmanship.',
                    'Beautiful handmade details.',
                    'The material feels durable.',
                    'Good value for the price.',
                    'Exactly what I expected.',
                    'Arrived in great condition.',
                    'Carefully packaged.',
                    'The quality could be improved.',
                    'Different from what I expected.',
                ] as $phrase): ?>
                    <button class="order-quick-choice review-quick-choice" type="button"
                        data-phrase="<?= orderE($phrase) ?>" aria-pressed="false"><?= orderE($phrase) ?></button>
                <?php endforeach; ?>
            </div>
            <span class="order-quick-caption">Seller service</span>
            <div class="order-choice-grid review-choice-grid" role="group" aria-label="Quick seller service reviews">
                <?php foreach ([
                    'Seller was friendly and helpful.',
                    'Seller replied quickly.',
                    'Updates were clear and timely.',
                    'The seller accommodated my request.',
                    'Order was prepared on time.',
                    'Pickup or delivery went smoothly.',
                    'The seller was professional.',
                    'Communication could be improved.',
                    'Pickup or delivery was delayed.',
                    'I would buy from this seller again.',
                ] as $phrase): ?>
                    <button class="order-quick-choice review-quick-choice" type="button"
                        data-phrase="<?= orderE($phrase) ?>" aria-pressed="false"><?= orderE($phrase) ?></button>
                <?php endforeach; ?>
            </div>
            <label class="order-dialog-field-title" for="reviewText">Your review</label>
            <textarea id="reviewText" name="review_text" maxlength="1000" rows="4"
                placeholder="Choose quick reviews above or write your own..."></textarea>
            <div class="review-text-meta">
                <p class="order-field-error" id="reviewTextError" role="alert" hidden></p>
                <span id="reviewTextCount">0 / 1000</span>
            </div>
        </div>
        <div class="confirm-actions order-dialog-footer">
            <button class="order-action" type="button" id="reviewCancel">Close</button>
            <button class="order-action primary" type="submit" id="reviewSubmit" disabled>Submit Review</button>
        </div>
    </form>
</dialog>
<dialog
    class="review-view-dialog"
    id="reviewViewDialog"
>
    <h2 id="reviewViewProduct">
        Review
    </h2>
    <div
        class="review-rating-view"
        id="reviewViewStars"
    ></div>
    <p id="reviewViewText"></p>
    <p
        id="reviewViewDate"
        style="margin-top:10px;color:#938b84;font-size:.64rem;"
    ></p>
    <div class="confirm-actions">
        <button
            class="order-action"
            type="button"
            id="reviewViewClose"
        >
            Close
        </button>
    </div>
</dialog>
<?php endif; ?>
<script>
const orderTabs = Array.from(document.querySelectorAll('.order-tab'));
const orderRows = Array.from(document.querySelectorAll('.order-row'));
const filteredEmpty = document.getElementById('ordersFilteredEmpty');
const ordersSearch = document.getElementById('ordersSearch');
let activeOrderFilter = 'all';
function applyOrderFilter(filter) {
    activeOrderFilter = filter;
    const query = (ordersSearch?.value || '').trim().toLocaleLowerCase();
    orderTabs.forEach((button) => {
        const active = button.dataset.filter === filter;
        button.classList.toggle('active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    let visible = 0;
    orderRows.forEach((row) => {
        const matchesFilter = filter === 'all' || row.dataset.group === filter;
        const matchesSearch = !query || (row.dataset.search || '').toLocaleLowerCase().includes(query);
        const show = matchesFilter && matchesSearch;
        row.hidden = !show;
        row.setAttribute('aria-hidden', show ? 'false' : 'true');
        if (show) visible++;
    });
    if (filteredEmpty) filteredEmpty.hidden = visible > 0;
}
orderTabs.forEach((tab) => tab.addEventListener('click', () => {
    applyOrderFilter(tab.dataset.filter || 'all');
}));
ordersSearch?.addEventListener('input', () => applyOrderFilter(activeOrderFilter));
applyOrderFilter('all');
const orderDetailsDialog = document.getElementById('orderDetailsDialog');
const orderDetailsBody = document.getElementById('orderDetailsBody');
const orderDetailsFooter = document.getElementById('orderDetailsFooter');
const orderDetailsTitle = document.getElementById('orderDetailsTitle');
const orderDetailsClose = document.getElementById('orderDetailsClose');
function openOrderDetails(row) {
    const orderId = row.dataset.orderId || '';
    const template = row.querySelector('.order-detail-template');
    if (!template || !orderDetailsDialog || !orderDetailsBody) return;
    orderDetailsTitle.textContent = row.dataset.orderTitle || `Order #${orderId}`;
    const detail = template.content.cloneNode(true);
    const actions = detail.querySelector('.order-popup-actions');
    orderDetailsFooter.replaceChildren();
    if (actions) {
        while (actions.firstChild) orderDetailsFooter.appendChild(actions.firstChild);
        actions.remove();
    }
    orderDetailsFooter.hidden = !orderDetailsFooter.querySelector('a,button');
    orderDetailsBody.replaceChildren(detail);
    orderDetailsBody.scrollTop = 0;
    if (!orderDetailsDialog.open) orderDetailsDialog.showModal();
}
orderRows.forEach((row) => {
    row.addEventListener('click', () => openOrderDetails(row));
    row.addEventListener('keydown', (event) => {
        if (event.target !== row || (event.key !== 'Enter' && event.key !== ' ')) return;
        event.preventDefault();
        openOrderDetails(row);
    });
});
// Buyer notification links use orders.php?order_id=123. Open only an order
// already returned by the buyer-scoped query above.
const linkedOrderId = new URLSearchParams(window.location.search).get('order_id');
if (linkedOrderId && /^[1-9]\d*$/.test(linkedOrderId)) {
    const linkedRow = orderRows.find((row) => row.dataset.orderId === linkedOrderId);
    if (linkedRow) {
        if (ordersSearch) ordersSearch.value = '';
        applyOrderFilter(linkedRow.dataset.group || 'pending');
        openOrderDetails(linkedRow);
    }
}
orderDetailsClose?.addEventListener('click', () => orderDetailsDialog?.close());
orderDetailsDialog?.addEventListener('click', (event) => {
    if (event.target === orderDetailsDialog) orderDetailsDialog.close();
});
const cancelDialog = document.getElementById('cancelDialog');
const cancelOrderId = document.getElementById('cancelOrderId');
const cancelOrderNumber = document.getElementById('cancelOrderNumber');
const cancelReason = document.getElementById('cancelReason');
const cancelDetails = document.getElementById('cancelDetails');
const cancelSubmit = document.getElementById('cancelSubmit');
const cancelReasonError = document.getElementById('cancelReasonError');
const cancelDetailsError = document.getElementById('cancelDetailsError');
const cancelDetailsRequirement = document.getElementById('cancelDetailsRequirement');
const cancelReasonButtons = [...document.querySelectorAll('.cancel-reason-choice')];
function updateCancelState() {
    const reason = cancelReason?.value || '';
    const details = cancelDetails?.value.trim() || '';
    const needDetails = reason === 'other';
    if (cancelSubmit) cancelSubmit.disabled = !reason || (needDetails && !details);
    if (cancelDetails) {
        cancelDetails.required = needDetails;
        cancelDetails.placeholder = needDetails ? 'Briefly explain your reason...' : 'Add details if needed...';
    }
    if (cancelDetailsRequirement) cancelDetailsRequirement.textContent = needDetails ? '(required)' : '(optional)';
    cancelReasonButtons.forEach((button) => {
        const selected = button.dataset.reason === reason;
        button.classList.toggle('is-selected', selected);
        button.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });
    if (reason && cancelReasonError) cancelReasonError.hidden = true;
    if ((!needDetails || details) && cancelDetailsError) cancelDetailsError.hidden = true;
}
document.addEventListener('click', (event) => {
    const button = event.target.closest('.cancel-order');
    if (!button || !cancelDialog) return;
    cancelOrderId.value = button.dataset.id || '';
    cancelOrderNumber.textContent = button.dataset.id || '';
    cancelReason.value = '';
    cancelDetails.value = '';
    cancelReasonError.hidden = true;
    cancelDetailsError.hidden = true;
    updateCancelState();
    if (orderDetailsDialog?.open) orderDetailsDialog.close();
    if (!cancelDialog.open) cancelDialog.showModal();
});
cancelReasonButtons.forEach((button) => button.addEventListener('click', () => {
    cancelReason.value = button.dataset.reason || '';
    updateCancelState();
}));
cancelDetails?.addEventListener('input', updateCancelState);
document.getElementById('cancelForm')?.addEventListener('submit', (event) => {
    updateCancelState();
    if (!cancelReason.value || (cancelReason.value === 'other' && !cancelDetails.value.trim())) {
        event.preventDefault();
        if (!cancelReason.value) {
            cancelReasonError.textContent = 'Choose a cancellation reason.';
            cancelReasonError.hidden = false;
            cancelReasonButtons[0]?.focus();
        } else {
            cancelDetailsError.textContent = 'Please explain your reason.';
            cancelDetailsError.hidden = false;
            cancelDetails.focus();
        }
    } else {
        cancelSubmit.disabled = true;
        cancelSubmit.textContent = 'Cancelling...';
    }
});
cancelDialog?.addEventListener('close', () => {
    if (cancelSubmit) cancelSubmit.textContent = 'Confirm Cancellation';
    updateCancelState();
});
document.getElementById('cancelDialogClose')?.addEventListener('click', () => cancelDialog?.close());
cancelDialog?.addEventListener('click', (event) => {
    if (event.target === cancelDialog) cancelDialog.close();
});
const qrPaymentDialog = document.getElementById('qrPaymentDialog');
const qrPaymentClose = document.getElementById('qrPaymentClose');
const qrPaymentOrder = document.getElementById('qrPaymentOrder');
const qrPaymentAmount = document.getElementById('qrPaymentAmount');
const qrPaymentImage = document.getElementById('qrPaymentImage');
const qrPaymentTimer = document.getElementById('qrPaymentTimer');
const qrPaymentFeedback = document.getElementById('qrPaymentFeedback');
const qrPaymentRegenerate = document.getElementById('qrPaymentRegenerate');
const qrPaymentSimulate = document.getElementById('qrPaymentSimulate');
const qrPaymentCsrf = <?= json_encode(
    $_SESSION['buyer_messages_csrf'],
    JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
) ?>;
let activeQrOrderId = 0;
let activeQrSellerId = 0;
let activeQrTotal = 0;
let activeQrSession = null;
let qrPaymentPoller = null;
let qrCountdownTimer = null;
let qrCheckInProgress = false;
let qrPaymentWasConfirmed = false;
let paymongoTestWindow = null;
function qrMoney(value) {
    return '₱' + Number(value || 0).toLocaleString(
        'en-PH',
        {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }
    );
}
function setQrPaymentFeedback(message, type = '') {
    if (!qrPaymentFeedback) {
        return;
    }
    qrPaymentFeedback.textContent = message;
    qrPaymentFeedback.className =
        `qr-payment-feedback ${type}`.trim();
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
    try {
        if (new URL(testUrl).protocol !== 'https:') return false;
    } catch (error) {
        return false;
    }
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
function stopQrTimers() {
    if (qrPaymentPoller) {
        clearInterval(qrPaymentPoller);
        qrPaymentPoller = null;
    }
    if (qrCountdownTimer) {
        clearInterval(qrCountdownTimer);
        qrCountdownTimer = null;
    }
}
function qrExpiryTime(session) {
    const unix = Number(session?.expires_at_unix || 0);
    if (Number.isFinite(unix) && unix > 0) {
        return unix * 1000;
    }
    const raw = String(session?.expires_at || '').trim();
    if (!raw) {
        return 0;
    }
    const parsed = Date.parse(raw.replace(' ', 'T'));
    return Number.isFinite(parsed) ? parsed : 0;
}
function updateQrCountdown() {
    if (!qrPaymentTimer || !activeQrSession) {
        return;
    }
    const expiresAt = qrExpiryTime(activeQrSession);
    if (!expiresAt) {
        qrPaymentTimer.textContent = '15:00';
        return;
    }
    const remaining = Math.max(
        0,
        Math.ceil((expiresAt - Date.now()) / 1000)
    );
    const minutes = Math.floor(remaining / 60);
    const seconds = remaining % 60;
    qrPaymentTimer.textContent =
        `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
    if (remaining <= 0) {
        qrPaymentRegenerate.hidden = false;
        qrPaymentSimulate.hidden = true;
        qrPaymentSimulate.disabled = true;
    }
}
function startQrTimers() {
    stopQrTimers();
    updateQrCountdown();
    qrCountdownTimer = setInterval(
        updateQrCountdown,
        1000
    );
    qrPaymentPoller = setInterval(
        () => {
            if (qrPaymentDialog?.open) {
                checkQrPaymentStatus(false);
            }
        },
        4000
    );
}
async function postQrPaymentAction(action, orderId) {
    if (!Number.isSafeInteger(activeQrSellerId) || activeQrSellerId <= 0
        || !Number.isSafeInteger(orderId) || orderId <= 0) {
        throw new Error('Invalid order information. Refresh and try again.');
    }
    const messageAction = action === 'create'
        ? 'generate_paymongo_qr'
        : action === 'status'
            ? 'check_paymongo_payment'
            : '';
    if (!messageAction) {
        throw new Error('Unsupported payment action.');
    }
    const form = new FormData();
    form.append('action', messageAction);
    form.append('seller_id', String(activeQrSellerId));
    form.append('order_id', String(orderId));
    form.append('csrf_token', qrPaymentCsrf);
    const response = await fetch('messages.php', {
        method: 'POST',
        body: form,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    });
    let result;
    try {
        result = await response.json();
    } catch (error) {
        throw new Error('The payment server returned an unexpected response.');
    }
    if (!response.ok || !result.success) {
        throw new Error(result.message || 'Unable to process QR Ph payment.');
    }
    return result;
}
function applyQrPaymentResult(result) {
    activeQrSession = result || null;
    const status = String(result?.qr_status || '').toLowerCase();
    const paid = status === 'paid';
    const failed = status === 'failed';
    const expired = status === 'expired' || status === 'cancelled';
    const imageUrl = !paid && !expired && !failed ? String(result?.qr_image || '').trim() : '';
    if (qrPaymentImage) {
        qrPaymentImage.hidden = !imageUrl;
        if (imageUrl) {
            qrPaymentImage.src = imageUrl;
        } else {
            qrPaymentImage.removeAttribute('src');
        }
    }
    const testUrl = !paid && !expired && !failed ? String(result?.test_url || '').trim() : '';
    if (qrPaymentSimulate) {
        qrPaymentSimulate.hidden = !testUrl;
        qrPaymentSimulate.disabled = !testUrl;
    }
    if (qrPaymentRegenerate) {
        qrPaymentRegenerate.hidden = !(expired || failed);
    }
    if ((paid || failed) && qrPaymentTimer) {
        qrPaymentTimer.textContent = failed ? 'FAILED' : 'PAID';
    } else {
        updateQrCountdown();
    }
}
function qrPaymentConfirmed() {
    qrPaymentWasConfirmed = true;
    stopQrTimers();
    closePayMongoTestWindow();
    applyQrPaymentResult({qr_status: 'paid'});
    if (qrPaymentRegenerate) qrPaymentRegenerate.hidden = true;
    setQrPaymentFeedback('Payment confirmed. Refreshing your order...', 'success');
    window.setTimeout(() => window.location.reload(), 900);
}
async function generateQrPayment() {
    if (!activeQrOrderId) {
        return;
    }
    qrPaymentSimulate.disabled = true;
    qrPaymentRegenerate.disabled = true;
    setQrPaymentFeedback(
        'Generating your 15-minute QR Ph code...',
        ''
    );
    try {
        const result = await postQrPaymentAction(
            'create',
            activeQrOrderId
        );
        if (qrPaymentWasConfirmed) return;
        if (result.paid || String(result.order?.payment_status || '').toLowerCase() === 'paid') {
            qrPaymentConfirmed();
            return;
        }
        applyQrPaymentResult(result.qr || null);
        setQrPaymentFeedback(
            result.qr?.test_url
                ? 'QR ready. Use Simulate Payment to test without a real e-wallet.'
                : 'QR ready. Scan the QR Ph code to continue.',
            ''
        );
        startQrTimers();
    } catch (error) {
        setQrPaymentFeedback(
            error.message,
            'error'
        );
    } finally {
        qrPaymentRegenerate.disabled = qrPaymentWasConfirmed;
        if (qrPaymentWasConfirmed || !String(activeQrSession?.test_url || '').trim()) {
            qrPaymentSimulate.disabled = true;
        }
    }
}
async function checkQrPaymentStatus(showWaitingMessage = true) {
    if (!activeQrOrderId || qrCheckInProgress) {
        return;
    }
    qrCheckInProgress = true;
    try {
        const result = await postQrPaymentAction(
            'status',
            activeQrOrderId
        );
        if (qrPaymentWasConfirmed) return;
        if (result.paid || String(result.order?.payment_status || '').toLowerCase() === 'paid') {
            qrPaymentConfirmed();
            return;
        }
        if (result.failed) {
            applyQrPaymentResult({...result.qr, qr_status: 'failed'});
            stopQrTimers();
            closePayMongoTestWindow();
            qrPaymentRegenerate.hidden = false;
            qrPaymentRegenerate.disabled = false;
            qrPaymentSimulate.hidden = true;
            qrPaymentSimulate.disabled = true;
            setQrPaymentFeedback(
                result.message || 'Payment failed in PayMongo Test Mode. Generate a new QR to try again.',
                'error'
            );
            return;
        }
        if (result.expired) {
            applyQrPaymentResult({...result.qr, qr_status: 'expired'});
            stopQrTimers();
            closePayMongoTestWindow();
            if (qrPaymentTimer) {
                qrPaymentTimer.textContent =
                    result.expired ? 'EXPIRED' : 'TRY AGAIN';
            }
            qrPaymentRegenerate.hidden = false;
            qrPaymentSimulate.hidden = true;
            qrPaymentSimulate.disabled = true;
            setQrPaymentFeedback(
                result.message || 'Generate a new QR Ph code to continue.',
                'error'
            );
            return;
        }
        if (result.qr && !activeQrSession) {
            applyQrPaymentResult(result.qr);
        }
        if (showWaitingMessage) {
            setQrPaymentFeedback(
                'Payment is still pending. Complete the PayMongo test authorization to continue.',
                ''
            );
        }
    } catch (error) {
        if (showWaitingMessage) {
            setQrPaymentFeedback(
                error.message,
                'error'
            );
        }
    } finally {
        qrCheckInProgress = false;
    }
}
document.addEventListener('click', async (event) => {
    const button = event.target.closest('.pay-order-qr');
    if (!button) return;
    if (orderDetailsDialog?.open) orderDetailsDialog.close();
        activeQrOrderId =
            Number(button.dataset.orderId || 0);
        activeQrSellerId =
            Number(button.dataset.sellerId || 0);
        activeQrTotal =
            Number(button.dataset.orderTotal || 0);
        activeQrSession = null;
        qrPaymentWasConfirmed = false;
        if (!activeQrOrderId || !activeQrSellerId || !qrPaymentDialog) {
            return;
        }
        qrPaymentOrder.textContent =
            `Order #${activeQrOrderId}`;
        qrPaymentAmount.textContent =
            qrMoney(activeQrTotal);
        qrPaymentImage.hidden = true;
        qrPaymentImage.removeAttribute('src');
        qrPaymentTimer.textContent = '15:00';
        qrPaymentRegenerate.hidden = true;
        qrPaymentSimulate.hidden = true;
        setQrPaymentFeedback(
            'Generating your QR Ph code...',
            ''
        );
        qrPaymentDialog.showModal();
        await generateQrPayment();
});
qrPaymentRegenerate?.addEventListener(
    'click',
    () => {
        closePayMongoTestWindow();
        generateQrPayment();
    }
);
qrPaymentSimulate?.addEventListener(
    'click',
    () => {
        const testUrl = String(
            activeQrSession?.test_url || ''
        ).trim();
        if (!testUrl) {
            setQrPaymentFeedback(
                'The test payment link is not available for this QR.',
                'error'
            );
            return;
        }
        const opened = openPayMongoTestWindow(testUrl);
        if (!opened) {
            setQrPaymentFeedback(
                'The PayMongo test window was blocked. Allow pop-ups for LokalCraft, then try again.',
                'error'
            );
            return;
        }
        setQrPaymentFeedback(
            'PayMongo test payment opened. After authorization, LokalCraft will detect the payment and close the test window automatically.',
            ''
        );
    }
);
function closeQrPaymentDialog() {
    stopQrTimers();
    closePayMongoTestWindow();
    qrPaymentDialog?.close();
}
qrPaymentClose?.addEventListener(
    'click',
    closeQrPaymentDialog
);
qrPaymentDialog?.addEventListener(
    'click',
    (event) => {
        if (event.target === qrPaymentDialog) {
            closeQrPaymentDialog();
        }
    }
);
qrPaymentDialog?.addEventListener(
    'close',
    () => {
        stopQrTimers();
        closePayMongoTestWindow();
    }
);
window.addEventListener(
    'focus',
    () => {
        if (qrPaymentDialog?.open) {
            checkQrPaymentStatus(false);
        }
    }
);
document.addEventListener(
    'visibilitychange',
    () => {
        if (
            !document.hidden
            && qrPaymentDialog?.open
        ) {
            checkQrPaymentStatus(false);
        }
    }
);
<?php if ($reviewsReady): ?>
const reviewDialog = document.getElementById('reviewDialog');
const reviewOrderId = document.getElementById('reviewOrderId');
const reviewOrderItemId = document.getElementById('reviewOrderItemId');
const reviewRating = document.getElementById('reviewRating');
const reviewProductName = document.getElementById('reviewProductName');
const reviewSellerName = document.getElementById('reviewSellerName');
const reviewProductPhoto = document.getElementById('reviewProductPhoto');
const reviewPhotoFallback = document.getElementById('reviewPhotoFallback');
const reviewText = document.getElementById('reviewText');
const reviewTextCount = document.getElementById('reviewTextCount');
const reviewSubmit = document.getElementById('reviewSubmit');
const reviewRatingError = document.getElementById('reviewRatingError');
const reviewTextError = document.getElementById('reviewTextError');
const reviewStars = [...document.querySelectorAll('.review-star')];
const reviewQuickButtons = [...document.querySelectorAll('.review-quick-choice')];
function updateReviewState() {
    const value = reviewText?.value || '';
    const rating = Number(reviewRating?.value || 0);
    if (reviewSubmit) reviewSubmit.disabled = rating < 1 || !value.trim() || value.length > 1000;
    if (reviewTextCount) reviewTextCount.textContent = `${value.length} / 1000`;
    const lines = value.split(/\r?\n/).map((line) => line.trim());
    reviewQuickButtons.forEach((button) => {
        const selected = lines.includes(button.dataset.phrase || '');
        button.classList.toggle('is-selected', selected);
        button.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });
    if (rating >= 1 && reviewRatingError) reviewRatingError.hidden = true;
    if (value.trim() && reviewTextError) reviewTextError.hidden = true;
}
function setReviewRating(value) {
    const rating = Math.max(0, Math.min(5, Number(value) || 0));
    reviewRating.value = String(rating);
    reviewStars.forEach((star) => {
        const n = Number(star.dataset.rating);
        star.classList.toggle('active', n <= rating);
        star.setAttribute('aria-pressed', n === rating ? 'true' : 'false');
    });
    updateReviewState();
}
function setReviewPhoto(url) {
    if (!reviewProductPhoto || !reviewPhotoFallback) return;
    reviewProductPhoto.hidden = !url;
    reviewPhotoFallback.hidden = !!url;
    if (url) reviewProductPhoto.src = url;
    else reviewProductPhoto.removeAttribute('src');
}
reviewProductPhoto?.addEventListener('error', () => setReviewPhoto(''));
document.addEventListener('click', (event) => {
    const button = event.target.closest('.review-product');
    if (!button || !reviewDialog) return;
    reviewOrderId.value = button.dataset.orderId || '';
    reviewOrderItemId.value = button.dataset.orderItemId || '';
    reviewProductName.textContent = button.dataset.productName || 'Product';
    reviewSellerName.textContent = `Sold by ${button.dataset.sellerName || 'Local Seller'}`;
    reviewText.value = '';
    setReviewPhoto(button.dataset.productPhoto || '');
    setReviewRating(0);
    reviewRatingError.hidden = true;
    reviewTextError.hidden = true;
    if (orderDetailsDialog?.open) orderDetailsDialog.close();
    if (!reviewDialog.open) reviewDialog.showModal();
});
reviewStars.forEach((star) => star.addEventListener('click', () => setReviewRating(star.dataset.rating)));
reviewQuickButtons.forEach((button) => button.addEventListener('click', () => {
    const phrase = button.dataset.phrase || '';
    if (!phrase) return;
    const lines = reviewText.value.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
    const index = lines.indexOf(phrase);
    if (index >= 0) lines.splice(index, 1);
    else lines.push(phrase);
    const next = lines.join('\n');
    if (next.length > 1000) {
        reviewTextError.textContent = 'Review must be within 1000 characters.';
        reviewTextError.hidden = false;
        reviewText.focus();
        return;
    }
    reviewText.value = next;
    updateReviewState();
}));
reviewText?.addEventListener('input', updateReviewState);
document.getElementById('reviewCancel')?.addEventListener('click', () => reviewDialog?.close());
reviewDialog?.addEventListener('click', (event) => {
    if (event.target === reviewDialog) reviewDialog.close();
});
document.getElementById('reviewForm')?.addEventListener('submit', (event) => {
    updateReviewState();
    if (Number(reviewRating.value) < 1 || !reviewText.value.trim()) {
        event.preventDefault();
        if (Number(reviewRating.value) < 1) {
            reviewRatingError.textContent = 'Select 1–5 stars.';
            reviewRatingError.hidden = false;
            reviewStars[0]?.focus();
        } else {
            reviewTextError.textContent = 'Choose a quick phrase or write your own review.';
            reviewTextError.hidden = false;
            reviewText.focus();
        }
    } else {
        reviewSubmit.disabled = true;
        reviewSubmit.textContent = 'Submitting...';
    }
});
reviewDialog?.addEventListener('close', () => {
    if (reviewSubmit) reviewSubmit.textContent = 'Submit Review';
});
const reviewViewDialog = document.getElementById('reviewViewDialog');
const reviewViewProduct = document.getElementById('reviewViewProduct');
const reviewViewStars = document.getElementById('reviewViewStars');
const reviewViewText = document.getElementById('reviewViewText');
const reviewViewDate = document.getElementById('reviewViewDate');
document.addEventListener('click', (event) => {
    const button = event.target.closest('.view-review');
    if (!button) {
        return;
    }
    if (orderDetailsDialog?.open) orderDetailsDialog.close();
        const rating = Math.max(
            0,
            Math.min(
                5,
                Number(button.dataset.rating) || 0
            )
        );
        reviewViewProduct.textContent =
            button.dataset.productName || 'Review';
        reviewViewStars.textContent =
            '★'.repeat(rating)
            + '☆'.repeat(5 - rating);
        reviewViewText.textContent =
            button.dataset.reviewText
                || 'No written review.';
        reviewViewDate.textContent =
            button.dataset.reviewDate
                ? `Submitted ${button.dataset.reviewDate}`
                : '';
        reviewViewDialog.showModal();
});
document.getElementById('reviewViewClose')?.addEventListener(
    'click',
    () => {
        reviewViewDialog.close();
    }
);
<?php endif; ?>
</script>
</body>
</html>
