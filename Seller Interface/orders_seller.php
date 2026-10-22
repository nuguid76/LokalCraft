<?php
require_once __DIR__ . '/seller_auth.php';
date_default_timezone_set('Asia/Manila');
$activePage = 'orders';
$sellerId = (int)$sellerId;
if (empty($_SESSION['seller_orders_csrf'])) {
    $_SESSION['seller_orders_csrf'] = bin2hex(random_bytes(32));
}
$message = '';
$messageType = '';
$orders = [];
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function money(float $amount): string
{
    return '₱' . number_format($amount, 2);
}
function statusKey($value): string
{
    return strtolower(trim((string)$value));
}
function niceStatus(string $status): string
{
    return ucwords(str_replace(['_', '-'], ' ', trim($status)));
}
function currentPageName(): string
{
    $page = basename((string)($_SERVER['PHP_SELF'] ?? 'orders_seller.php'));
    if ($page === '' || !preg_match('/^[A-Za-z0-9._-]+\.php$/', $page)) {
        return 'orders_seller.php';
    }
    return $page;
}
function redirectOrders(int $orderId = 0): void
{
    $location = currentPageName();
    if ($orderId > 0) {
        $location .= '#order-' . $orderId;
    }
    header('Location: ' . $location);
    exit;
}
function isCashMethod(string $paymentMethod): bool
{
    return statusKey($paymentMethod) === 'cash';
}
function isQrMethod(
    string $paymentMethod,
    string $paymentOption = ''
): bool {
    $methodKey = statusKey($paymentMethod);
    $optionKey = statusKey($paymentOption);
    return
        $optionKey === 'paymongo'
        || in_array(
            $methodKey,
            ['qr ph', 'qrph', 'paymongo', 'gcash qr', 'gcash'],
            true
        );
}
function unifiedStatus(array $order): array
{
    $orderStatus = statusKey($order['order_status'] ?? 'pending');
    if ($orderStatus === 'canceled') {
        $orderStatus = 'cancelled';
    }
    if ($orderStatus === 'declined') {
        $orderStatus = 'rejected';
    }
    if ($orderStatus === 'confirmed') {
        return [
            'key' => 'accepted',
            'label' => 'Accepted',
        ];
    }
    return [
        'key' => $orderStatus !== '' ? $orderStatus : 'pending',
        'label' => niceStatus($orderStatus !== '' ? $orderStatus : 'pending'),
    ];
}
function statusCssClass(string $status): string
{
    return str_replace('_', '-', statusKey($status));
}
function sellerNotifyBuyer(
    mysqli $conn,
    int $sellerId,
    int $buyerId,
    int $orderId,
    string $message
): void {
    try {
        $stmt = $conn->prepare('
            INSERT INTO messages
                (sender_id, receiver_id, order_id, message_text, is_read, sent_at)
            VALUES (?, ?, ?, ?, 0, NOW())
        ');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('iiis', $sellerId, $buyerId, $orderId, $message);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('LokalCraft seller order notification error: ' . $e->getMessage());
    }
}
function sellerAssertPendingRejectionSafe(mysqli $conn, int $orderId): void
{
    $stmt = $conn->prepare('SELECT payment_status FROM payments WHERE order_id = ? FOR UPDATE');
    if (!$stmt) {
        throw new RuntimeException('Unable to verify payment safety. Try again later.');
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $statuses = $stmt->get_result()->fetch_all(MYSQLI_NUM);
    $stmt->close();
    foreach ($statuses as $row) {
        if (in_array(statusKey($row[0]), ['paid', 'refunded', 'successful', 'succeeded', 'completed'], true)) {
            throw new RuntimeException('A confirmed payment exists. This request cannot be rejected here. Contact support.');
        }
    }
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
    foreach ($sessions as $row) {
        if (!in_array(statusKey($row[0]), ['cancelled', 'canceled', 'expired', 'failed'], true)) {
            throw new RuntimeException('A QR Ph session exists. Contact support to verify payment before rejecting.');
        }
    }
}

/** One source of truth for the clickable reasons and server validation. */
function sellerRejectionReasons(): array
{
    return [
        'product_unavailable' => 'Product is no longer available',
        'out_of_stock' => 'Not enough stock',
        'unable_to_fulfill' => 'Unable to fulfill this request',
        'production_delay' => 'Cannot finish the item on time',
        'customization_unavailable' => 'Requested customization is unavailable',
        'quantity_unavailable' => 'Requested quantity is unavailable',
        'schedule_conflict' => 'Schedule is not available',
        'arrangement_not_possible' => 'Cannot agree on the arrangement',
        'delivery_unavailable' => 'Delivery option is unavailable',
        'location_unserviceable' => 'Delivery location is not covered',
        'pickup_unavailable' => 'Pickup or meetup is unavailable',
        'pricing_issue' => 'Pricing needs to be revised',
        'payment_unavailable' => 'Payment option cannot be supported',
        'buyer_details_incomplete' => 'Missing details from the buyer',
        'other' => 'Other reason',
    ];
}

/** Uses the valid enum value from the deployed schema without a migration. */
function sellerRejectionDbStatus(mysqli $conn): string
{
    $result = $conn->query("SHOW COLUMNS FROM `orders` LIKE 'order_status'");
    $column = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $definition = (string)($column['Type'] ?? '');
    if (str_contains($definition, "'Rejected'")) {
        return 'Rejected';
    }
    if (str_contains($definition, "'Declined'")) {
        return 'Declined';
    }
    throw new RuntimeException('Order rejection is not supported by the database.');
}

function sellerRecordRejectionReason(
    mysqli $conn, int $sellerId, int $buyerId, int $orderId, string $reason
): void {
    $message = 'Seller rejected Order #' . $orderId . ".\nReason: " . $reason;
    $stmt = $conn->prepare('
        INSERT INTO messages (sender_id, receiver_id, order_id, message_text, is_read, sent_at)
        VALUES (?, ?, ?, ?, 0, NOW())
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to record the rejection reason.');
    }
    $stmt->bind_param('iiis', $sellerId, $buyerId, $orderId, $message);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to record the rejection reason.');
    }
    $stmt->close();
}

function loadSellerOrderForUpdate(
    mysqli $conn,
    int $sellerId,
    int $orderId
): ?array {
    $stmt = $conn->prepare('
        SELECT
            o.order_id,
            o.buyer_id,
            o.seller_id,
            o.order_status,
            o.total_amount,
            o.notes,
            p.payment_id,
            p.payment_method,
            p.payment_provider,
            p.payment_status,
            p.paid_at,
            oa.payment_option,
            oa.arrangement_status
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
        WHERE o.order_id = ?
          AND o.seller_id = ?
        LIMIT 1
        FOR UPDATE
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to load the order.');
    }
    $stmt->bind_param('ii', $orderId, $sellerId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $order;
}
function loadOrderItemsForUpdate(mysqli $conn, int $orderId): array
{
    $stmt = $conn->prepare('
        SELECT
            oi.product_id,
            oi.quantity,
            oi.unit_price,
            oi.subtotal,
            p.product_name,
            p.stock,
            COALESCE(p.reserved_stock, 0) AS reserved_stock,
            p.status
        FROM order_items oi
        INNER JOIN products p ON p.product_id = oi.product_id
        WHERE oi.order_id = ?
        ORDER BY oi.order_item_id ASC
        FOR UPDATE
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to load the order items.');
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (!$items) {
        throw new RuntimeException('This order has no items.');
    }
    return $items;
}
function finalizeReservedStock(mysqli $conn, array $items): void
{
    foreach ($items as $item) {
        $quantity = max(1, (int)$item['quantity']);
        $physicalStock = max(0, (int)$item['stock']);
        $reservedStock = max(0, (int)$item['reserved_stock']);
        if ($reservedStock < $quantity) {
            throw new RuntimeException(
                'Reserved stock for ' . $item['product_name'] . ' is inconsistent.'
            );
        }
        if ($physicalStock < $quantity) {
            throw new RuntimeException(
                'Physical stock for ' . $item['product_name'] . ' is no longer sufficient.'
            );
        }
        $newStock = $physicalStock - $quantity;
        $newReservedStock = $reservedStock - $quantity;
        $currentStatus = statusKey($item['status'] ?? '');
        $newProductStatus = $currentStatus === 'archived'
            ? 'Archived'
            : ($newStock > 0 ? 'Available' : 'Out of Stock');
        $productId = (int)$item['product_id'];
        $stmt = $conn->prepare('
            UPDATE products
            SET stock = ?, reserved_stock = ?, status = ?
            WHERE product_id = ?
        ');
        if (!$stmt) {
            throw new RuntimeException('Unable to finalize product stock.');
        }
        $stmt->bind_param('iisi', $newStock, $newReservedStock, $newProductStatus, $productId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Unable to finalize product stock.');
        }
        $stmt->close();
    }
}
function markCashCommissionEarned(
    mysqli $conn,
    int $sellerId,
    int $orderId
): float {
    $stmt = $conn->prepare('
        SELECT
            commission_id,
            commission_amount,
            collection_method,
            commission_status
        FROM platform_commissions
        WHERE order_id = ?
          AND seller_id = ?
        LIMIT 1
        FOR UPDATE
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to load the cash platform fee.');
    }
    $stmt->bind_param('ii', $orderId, $sellerId);
    $stmt->execute();
    $commission = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$commission) {
        throw new RuntimeException(
            'The reserved platform fee for this cash order was not found.'
        );
    }
    if (statusKey($commission['collection_method'] ?? '') !== 'seller_wallet') {
        throw new RuntimeException('This cash platform fee record is invalid.');
    }
    $status = statusKey($commission['commission_status'] ?? '');
    if ($status === 'refunded') {
        throw new RuntimeException(
            'The platform fee for this order was already refunded.'
        );
    }
    if ($status !== 'earned') {
        $stmt = $conn->prepare('
            UPDATE platform_commissions
            SET commission_status = "earned",
                earned_at = COALESCE(earned_at, NOW()),
                updated_at = NOW()
            WHERE commission_id = ?
        ');
        if (!$stmt) {
            throw new RuntimeException(
                'Unable to finalize the cash platform fee.'
            );
        }
        $commissionId = (int)$commission['commission_id'];
        $stmt->bind_param('i', $commissionId);
        $stmt->execute();
        $stmt->close();
    }
    return (float)$commission['commission_amount'];
}
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrfToken = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals((string)$_SESSION['seller_orders_csrf'], $csrfToken)) {
            throw new RuntimeException('Session expired. Refresh the page and try again.');
        }
        $action = trim((string)($_POST['action'] ?? ''));
        $orderId = (int)($_POST['order_id'] ?? 0);
        $allowedActions = [
            'start_processing',
            'complete_order',
            'reject_order',
        ];
        if ($orderId <= 0 || !in_array($action, $allowedActions, true)) {
            throw new RuntimeException('Invalid order action.');
        }
        require_once __DIR__ . '/../config/notification_events.php';
        $conn->begin_transaction();
        try {
            $order = loadSellerOrderForUpdate($conn, $sellerId, $orderId);
            if (!$order) {
                throw new RuntimeException('Order not found.');
            }
            $orderStatus = statusKey($order['order_status']);
            $paymentStatus = statusKey($order['payment_status'] ?? 'pending');
            $paymentMethod = (string)($order['payment_method'] ?? '');
            $paymentOption = statusKey($order['payment_option'] ?? '');
            $arrangementStatus = statusKey(
                $order['arrangement_status'] ?? ''
            );
            $isCash =
                $paymentOption === 'cash'
                || isCashMethod($paymentMethod);
            $isQr = isQrMethod(
                $paymentMethod,
                $paymentOption
            );
            $buyerId = (int)$order['buyer_id'];
            $paymentId = (int)($order['payment_id'] ?? 0);
            $notifyMessage = '';
            $rejectionSuccessMessage = '';
            $notificationType = '';
            $notificationTitle = '';
            $notificationMessage = '';
            if ($action === 'start_processing') {
                if (!in_array($orderStatus, ['accepted', 'confirmed'], true)) {
                    throw new RuntimeException(
                        'Only an Accepted order can move to Processing.'
                    );
                }
                if ($arrangementStatus !== 'accepted') {
                    throw new RuntimeException(
                        'The final order arrangement must be accepted before Processing.'
                    );
                }
                if ($isQr) {
                    if ($paymentStatus !== 'paid') {
                        throw new RuntimeException(
                            'QR Ph payment must be Paid before processing starts.'
                        );
                    }
                } elseif ($isCash) {
                    if (
                        $paymentStatus !== ''
                        && !in_array($paymentStatus, ['pending', 'paid'], true)
                    ) {
                        throw new RuntimeException(
                            'This Cash order has an unexpected payment status.'
                        );
                    }
                } else {
                    throw new RuntimeException(
                        'Unsupported payment method for this order.'
                    );
                }
                $stmt = $conn->prepare('
                    UPDATE orders
                    SET order_status = "Processing",
                        updated_at = NOW()
                    WHERE order_id = ?
                      AND seller_id = ?
                      AND LOWER(order_status) IN ("accepted", "confirmed")
                ');
                if (!$stmt) {
                    throw new RuntimeException(
                        'Unable to start processing the order.'
                    );
                }
                $stmt->bind_param('ii', $orderId, $sellerId);
                $stmt->execute();
                if ($stmt->affected_rows !== 1) {
                    $stmt->close();
                    throw new RuntimeException(
                        'The order status changed. Refresh the page.'
                    );
                }
                $stmt->close();
                $notifyMessage =
                    'Seller started processing Order #'
                    . $orderId
                    . '. Please coordinate the agreed handover through Messages.';
                $notificationType = 'order_processing';
                $notificationTitle = 'Order Processing';
                $notificationMessage = 'Order #' . $orderId
                    . ' is now being prepared by the seller.';
                $_SESSION['seller_order_message'] =
                    'Order #' . $orderId . ' is now Processing.';
            }
            if ($action === 'complete_order') {
                if ($orderStatus !== 'processing') {
                    throw new RuntimeException(
                        'Only a Processing order can be completed.'
                    );
                }
                if ($isQr && $paymentStatus !== 'paid') {
                    throw new RuntimeException(
                        'QR Ph payment is not confirmed.'
                    );
                }
                if (!$isCash && !$isQr) {
                    throw new RuntimeException(
                        'Unsupported payment method for this order.'
                    );
                }
                $items = loadOrderItemsForUpdate($conn, $orderId);
                finalizeReservedStock($conn, $items);
                if ($isCash) {
                    if (
                        $paymentStatus !== ''
                        && !in_array($paymentStatus, ['pending', 'paid'], true)
                    ) {
                        throw new RuntimeException(
                            'This Cash order has an unexpected payment status.'
                        );
                    }
                    if ($paymentId > 0) {
                        if ($paymentStatus === 'pending') {
                            $stmt = $conn->prepare('
                                UPDATE payments
                                SET
                                    payment_status = "paid",
                                    paid_at = COALESCE(paid_at, NOW())
                                WHERE payment_id = ?
                                  AND order_id = ?
                                  AND payment_status = "pending"
                            ');
                            if (!$stmt) {
                                throw new RuntimeException(
                                    'Unable to confirm the Cash payment.'
                                );
                            }
                            $stmt->bind_param(
                                'ii',
                                $paymentId,
                                $orderId
                            );
                            $stmt->execute();
                            if ($stmt->affected_rows !== 1) {
                                $stmt->close();
                                throw new RuntimeException(
                                    'The Cash payment status changed. Refresh the page.'
                                );
                            }
                            $stmt->close();
                        }
                    } else {
                        $cashMethod = 'Cash';
                        $cashProvider = 'cash';
                        $paidStatus = 'paid';
                        $orderAmount = (float)($order['total_amount'] ?? 0);
                        $stmt = $conn->prepare('
                            INSERT INTO payments
                                (
                                    order_id,
                                    buyer_id,
                                    amount,
                                    payment_method,
                                    payment_provider,
                                    payment_status,
                                    paid_at,
                                    created_at
                                )
                            VALUES
                                (?, ?, ?, ?, ?, ?, NOW(), NOW())
                        ');
                        if (!$stmt) {
                            throw new RuntimeException(
                                'Unable to create the Cash payment record.'
                            );
                        }
                        $stmt->bind_param(
                            'iidsss',
                            $orderId,
                            $buyerId,
                            $orderAmount,
                            $cashMethod,
                            $cashProvider,
                            $paidStatus
                        );
                        $stmt->execute();
                        $paymentId = (int)$stmt->insert_id;
                        $stmt->close();
                    }
                    $cashFee = markCashCommissionEarned(
                        $conn,
                        $sellerId,
                        $orderId
                    );
                }
                $stmt = $conn->prepare('
                    UPDATE orders
                    SET order_status = "Completed",
                        updated_at = NOW()
                    WHERE order_id = ?
                      AND seller_id = ?
                      AND order_status = "Processing"
                ');
                if (!$stmt) {
                    throw new RuntimeException(
                        'Unable to complete the order.'
                    );
                }
                $stmt->bind_param('ii', $orderId, $sellerId);
                $stmt->execute();
                if ($stmt->affected_rows !== 1) {
                    $stmt->close();
                    throw new RuntimeException(
                        'The order status changed. Refresh the page.'
                    );
                }
                $stmt->close();
                $stmt = $conn->prepare('
                    UPDATE order_arrangements
                    SET arrangement_status = "completed",
                        updated_at = NOW()
                    WHERE order_id = ?
                      AND arrangement_status = "accepted"
                ');
                if (!$stmt) {
                    throw new RuntimeException(
                        'Unable to finalize the order arrangement.'
                    );
                }
                $stmt->bind_param('i', $orderId);
                $stmt->execute();
                $stmt->close();
                $notifyMessage =
                    'Order #'
                    . $orderId
                    . ' was marked Completed. '
                    . 'You can now leave a review and rating from your Orders page.';
                $notificationType = 'order_completed';
                $notificationTitle = 'Order Completed';
                $notificationMessage = 'Order #' . $orderId
                    . ' was marked Completed. You can now leave a review in Orders.';
                $_SESSION['seller_order_message'] =
                    $isCash
                        ? 'Order #'
                            . $orderId
                            . ' completed. Cash was recorded as received and the 5% platform fee is now earned.'
                        : 'Order #' . $orderId . ' completed.';
            }
            if ($action === 'reject_order') {
                if ($orderStatus !== 'pending' || $arrangementStatus === 'accepted') {
                    throw new RuntimeException(
                        'Only a Pending request without an accepted arrangement can be rejected.'
                    );
                }
                sellerAssertPendingRejectionSafe($conn, $orderId);
                $reasonOptions = sellerRejectionReasons();
                $reasonKey = trim((string)($_POST['rejection_reason'] ?? ''));
                if (!isset($reasonOptions[$reasonKey])) {
                    throw new RuntimeException('Select a reason for rejecting the request.');
                }
                $details = trim((string)($_POST['rejection_details'] ?? ''));
                $details = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $details) ?? '';
                $detailLength = function_exists('mb_strlen')
                    ? mb_strlen($details, 'UTF-8') : strlen($details);
                if ($detailLength > 240 || strlen($details) > 960) {
                    throw new RuntimeException('The rejection details must be 240 characters or less.');
                }
                if ($reasonKey === 'other' && $details === '') {
                    throw new RuntimeException('Please briefly explain why this request cannot be fulfilled.');
                }
                $rejectionReason = $reasonOptions[$reasonKey]
                    . ($details !== '' ? ': ' . $details : '');
                $rejectionDbStatus = sellerRejectionDbStatus($conn);

                $stmt = $conn->prepare('
                    UPDATE orders SET order_status = ?, updated_at = NOW()
                    WHERE order_id = ? AND seller_id = ? AND LOWER(order_status) = "pending"
                ');
                if (!$stmt) {
                    throw new RuntimeException('Unable to reject the request.');
                }
                $stmt->bind_param('sii', $rejectionDbStatus, $orderId, $sellerId);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                if ($affected !== 1) {
                    throw new RuntimeException('The order status changed. Refresh the page.');
                }
                $stmt = $conn->prepare('SELECT order_status FROM orders WHERE order_id = ? AND seller_id = ?');
                $stmt->bind_param('ii', $orderId, $sellerId);
                $stmt->execute();
                $stored = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (statusKey($stored['order_status'] ?? '') !== statusKey($rejectionDbStatus)) {
                    throw new RuntimeException('The rejection status could not be saved.');
                }
                $stmt = $conn->prepare('
                    UPDATE payments SET payment_status = "cancelled"
                    WHERE order_id = ? AND LOWER(COALESCE(payment_status, "pending"))
                        NOT IN ("paid", "refunded", "successful", "succeeded", "completed")
                ');
                if (!$stmt) {
                    throw new RuntimeException('Unable to close pending payment records.');
                }
                $stmt->bind_param('i', $orderId);
                $stmt->execute();
                $stmt->close();
                sellerRecordRejectionReason($conn, $sellerId, $buyerId, $orderId, $rejectionReason);
                $notificationType = 'order_rejected';
                $notificationTitle = 'Order Rejected';
                $notificationMessage = 'Order #' . $orderId . ' was rejected by the seller.'
                    . "\n" . 'Reason: ' . $rejectionReason;
                $rejectionSuccessMessage = 'Order #' . $orderId . ' rejected. Reason sent to Buyer Messages.';
            }
            // Save one Buyer notification only after the relevant state change succeeds.
            // Keep the notification in the same transaction as order/payment updates.
            if ($notificationType !== '') {
                lokalcraftCreateNotification(
                    $conn,
                    $buyerId,
                    $notificationType,
                    $notificationTitle,
                    $notificationMessage,
                    'order',
                    $orderId,
                    'order_' . $orderId . '_' . $action
                );
            }
            $conn->commit();
            if ($rejectionSuccessMessage !== '') {
                $_SESSION['seller_order_message'] = $rejectionSuccessMessage;
            }
            $_SESSION['seller_order_message_type'] = 'success';
            if ($notifyMessage !== '') {
                sellerNotifyBuyer($conn, $sellerId, $buyerId, $orderId, $notifyMessage);
            }
            redirectOrders($orderId);
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
    if (isset($_SESSION['seller_order_message'])) {
        $message = (string)$_SESSION['seller_order_message'];
        $messageType = (string)($_SESSION['seller_order_message_type'] ?? 'success');
        unset($_SESSION['seller_order_message'], $_SESSION['seller_order_message_type']);
    }
    $stmt = $conn->prepare('
        SELECT
            o.order_id,
            o.buyer_id,
            o.total_amount,
            o.order_status,
            o.notes,
            o.ordered_at,
            CONCAT(u.firstname, " ", u.lastname) AS buyer_name,
            u.phone AS buyer_phone,
            pay.payment_id,
            pay.payment_method,
            pay.payment_provider,
            pay.payment_status,
            pay.paid_at,
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
        INNER JOIN users u ON u.id = o.buyer_id
        LEFT JOIN order_arrangements oa
            ON oa.order_id = o.order_id
        LEFT JOIN (
            SELECT
                oi.order_id,
                GROUP_CONCAT(
                    CONCAT(oi.quantity, " × ", COALESCE(p.product_name, "Product"))
                    ORDER BY oi.order_item_id ASC
                    SEPARATOR ", "
                ) AS items_summary,
                SUM(oi.quantity) AS total_quantity
            FROM order_items oi
            LEFT JOIN products p ON p.product_id = oi.product_id
            GROUP BY oi.order_id
        ) items ON items.order_id = o.order_id
        LEFT JOIN payments pay
            ON pay.payment_id = (
                SELECT p2.payment_id
                FROM payments p2
                WHERE p2.order_id = o.order_id
                ORDER BY p2.payment_id DESC
                LIMIT 1
            )
        WHERE o.seller_id = ?
        ORDER BY o.ordered_at DESC, o.order_id DESC
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to load seller orders.');
    }
    $stmt->bind_param('i', $sellerId);
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Throwable $e) {
    $rawMessage = trim($e->getMessage());
    $safePrefixes = [
        'Session expired.',
        'Invalid order action.',
        'Order not found.',
        'Only an Accepted order',
        'Only a Processing order',
        'Only a Pending request without an accepted arrangement',
        'Select a reason for rejecting',
        'The rejection details must',
        'Please briefly explain why',
        'A confirmed payment exists',
        'A QR Ph session exists',
        'Order rejection is not supported',
        'The rejection status could not be saved.',
        'Unable to verify payment safety.',
        'Unable to verify QR Ph status.',
        'The final order arrangement',
        'QR Ph payment',
        'This Cash order',
        'Unsupported payment method',
        'Reserved stock for ',
        'Physical stock for ',
        'The order status changed.',
        'The Cash payment status changed.',
        'The QR payment status changed.',
    ];
    $safeMessage = false;
    foreach ($safePrefixes as $prefix) {
        if (str_starts_with($rawMessage, $prefix)) {
            $safeMessage = true;
            break;
        }
    }
    if (!$safeMessage) {
        error_log('LokalCraft seller orders error: ' . $rawMessage);
    }
    $message = $safeMessage
        ? $rawMessage
        : 'Unable to update or load orders right now. Please refresh and try again.';
    $messageType = 'error';
}

$counts = ['all'=>count($orders),'pending'=>0,'accepted'=>0,'processing'=>0,'completed'=>0,'closed'=>0];
foreach ($orders as $summaryOrder) {
    $k = statusKey($summaryOrder['order_status'] ?? 'pending');
    $k = match ($k) {'confirmed'=>'accepted','canceled'=>'cancelled','declined'=>'rejected',default=>$k};
    if (in_array($k, ['cancelled','rejected','expired'], true)) $k = 'closed';
    if (isset($counts[$k])) $counts[$k]++;
}
$orderItems = [];
try {
    $itemsStmt = $conn->prepare('SELECT oi.order_id, oi.order_item_id, oi.product_id, oi.quantity, oi.unit_price,
        oi.agreed_unit_price, oi.subtotal, oi.special_request,
        COALESCE(p.product_name,"Product") AS product_name, p.product_photo
        FROM order_items oi JOIN orders o ON o.order_id = oi.order_id
        LEFT JOIN products p ON p.product_id = oi.product_id
        WHERE o.seller_id = ? ORDER BY oi.order_id DESC, oi.order_item_id ASC');
    if (!$itemsStmt) throw new RuntimeException('Unable to load product details.');
    $itemsStmt->bind_param('i', $sellerId);
    $itemsStmt->execute();
    foreach ($itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $item) {
        $orderItems[(int)$item['order_id']][] = $item;
    }
    $itemsStmt->close();
} catch (Throwable $error) {
    error_log('LokalCraft seller orders item load: ' . $error->getMessage());
    $message = 'Unable to load product details. Refresh the page.';
    $messageType = 'error';
}
$orderReviews = [];
$orderReviewsAvailable = false;
try {
    $reviewStmt = $conn->prepare('
        SELECT r.order_id, r.product_id, r.rating, r.review_text, r.created_at
        FROM reviews r
        INNER JOIN orders o ON o.order_id = r.order_id
        WHERE r.seller_id = ? AND o.seller_id = ? AND o.buyer_id = r.buyer_id
        ORDER BY r.created_at DESC, r.review_id DESC
    ');
    if (!$reviewStmt) {
        throw new RuntimeException('Unable to load buyer reviews.');
    }
    $reviewStmt->bind_param('ii', $sellerId, $sellerId);
    $reviewStmt->execute();
    foreach ($reviewStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $review) {
        $orderReviews[(int)$review['order_id']][(int)$review['product_id']][] = $review;
    }
    $reviewStmt->close();
    $orderReviewsAvailable = true;
} catch (Throwable $error) {
    error_log('LokalCraft seller orders review load: ' . $error->getMessage());
}
function sellerOrderChoices(string $snapshot): array {
    $choices = [];
    foreach (preg_split('/\r?\n/u', $snapshot) ?: [] as $line) {
        if (!preg_match('/^\s*-\s*(.+?)\s+\(\+₱([0-9,]+(?:\.[0-9]{2})?)\/unit\)(?::\s*(.*))?\s*$/u', $line, $match)) continue;
        $choices[] = ['name'=>trim($match[1]),'price'=>(float)str_replace(',','',$match[2]),'details'=>trim((string)($match[3] ?? ''))];
    }
    return $choices;
}
function sellerOrderPhoto($photo): string {
    $photo = trim((string)$photo);
    if ($photo === '' || str_contains($photo, '..') || str_starts_with($photo, '/') || str_contains($photo, '\\')) return '';
    return preg_match('~^[A-Za-z0-9_./ -]+\.(png|jpe?g|webp)$~i',$photo) ? '../'.$photo : '';
}
function sellerOrderDate($date, bool $short = false): string {
    $timestamp = $date ? strtotime((string)$date) : false;
    return $timestamp ? date($short ? 'M d, Y' : 'M d, Y · g:i A', $timestamp) : 'Not set';
}
function sellerOrderTime($time): string {
    $timestamp = $time ? strtotime((string)$time) : false;
    return $timestamp ? date('g:i A', $timestamp) : 'Not set';
}
function sellerOrderIcon(string $icon): string {
    $paths = [
        'package'=>'<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="M3 8v9l9 5 9-5V8M12 13v9"/>',
        'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'truck'=>'<path d="M3 5h11v12H3zM14 9h4l3 3v5h-7"/><circle cx="7" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>',
        'check'=>'<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
        'search'=>'<circle cx="11" cy="11" r="7"/><path d="m16 16 5 5"/>',
        'user'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.5 3.3-7 8-7s8 2.5 8 7"/>',
        'message'=>'<path d="M4 5h16v13H9l-5 4V5Z"/><path d="M8 10h8M8 14h5"/>',
        'chevron'=>'<path d="m9 6 6 6-6 6"/>',
        'close'=>'<path d="M6 6l12 12M18 6 6 18"/>',
        'play'=>'<path d="M7 4v16l12-8-12-8Z"/>',
        'reject'=>'<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/>',
        'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18"/>',
        'wallet'=>'<rect x="3" y="6" width="18" height="15" rx="2"/><path d="M3 10h18m-5 5h2"/>',
        'pin'=>'<path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
        'bag'=>'<path d="M5 8h14l1 13H4L5 8ZM9 9V6a3 3 0 0 1 6 0v3"/>',
    ];
    return '<svg class="ui-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$icon] ?? $paths['package']).'</svg>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>LokalCraft | Seller Orders</title>
<style>
:root{--bg:#FFF8ED;--paper:#fff;--ink:#38332f;--muted:#837b74;--line:#ece5df;--orange:#e98b40;--orange-dark:#b86b2b;--orange-soft:#fff2e6;--green:#32895b;--red:#be5a55;--blue:#527fb1}
*{box-sizing:border-box}
html,body{max-width:100%;margin:0}
body{background:var(--bg);color:var(--ink);font-family:"Segoe UI",Arial,sans-serif}
body.modal-open{overflow:hidden}
button,input,select,textarea{font:inherit}
button{cursor:pointer}
.ui-icon{display:inline-block;width:20px;height:20px;flex:0 0 auto;vertical-align:middle}
.page{display:block!important;min-height:100vh;background:var(--bg)!important}
body .page>main.content.orders-content{position:relative!important;left:auto!important;right:auto!important;top:auto!important;bottom:auto!important;width:auto!important;max-width:none!important;min-width:0!important;height:auto!important;min-height:100vh!important;margin-left:250px!important;padding:var(--orders-header-offset,70px) 28px 42px!important;overflow:visible!important;background:transparent!important}
.orders-wrap{width:100%;max-width:1600px;min-width:0;margin:0 auto;padding-top:8px}
.orders-hero{display:flex;align-items:center;justify-content:space-between;margin:0 0 20px}
.orders-hero h1{margin:0;color:#37322e;font-size:clamp(1.5rem,2.5vw,2rem);letter-spacing:-.03em}
/* Non-blocking order updates and errors. Keep notifications above any open dialog. */
.orders-toast{position:fixed;z-index:2147483000;left:50%;bottom:calc(20px + env(safe-area-inset-bottom,0px));display:flex;align-items:flex-start;gap:11px;width:max-content;max-width:min(460px,calc(100vw - 28px));min-width:min(320px,calc(100vw - 28px));padding:13px 13px 13px 15px;border:1px solid #d3e6d8;border-radius:13px;background:#fff;color:#3d4a40;box-shadow:0 12px 36px rgba(42,34,27,.18);font-size:.8rem;line-height:1.48;transform:translateX(-50%);animation:ordersToastEnter .22s ease-out both}
.orders-toast.error{border-color:#f0d0cc;color:#674641}
.orders-toast-icon{display:grid;place-items:center;flex:0 0 29px;width:29px;height:29px;border-radius:50%;background:#e7f5ed;color:#31875b}
.orders-toast.error .orders-toast-icon{background:#fff0ed;color:#b95850}
.orders-toast-icon .ui-icon{width:17px;height:17px}
.orders-toast-text{flex:1;min-width:0;align-self:center;white-space:pre-wrap;overflow-wrap:anywhere;font-weight:600}
.orders-toast-close{display:grid;place-items:center;flex:0 0 29px;width:29px;height:29px;margin:-1px -3px -1px 0;padding:0;border:0;border-radius:8px;background:transparent;color:#958c84;cursor:pointer}
.orders-toast-close:hover,.orders-toast-close:focus-visible{background:#f5f1ed;color:#494039}
.orders-toast-close .ui-icon{width:16px;height:16px}
.orders-toast.is-hiding{pointer-events:none;animation:ordersToastLeave .2s ease-in forwards}
@keyframes ordersToastEnter{from{opacity:0;transform:translate(-50%,12px)}to{opacity:1;transform:translate(-50%,0)}}
@keyframes ordersToastLeave{from{opacity:1;transform:translate(-50%,0)}to{opacity:0;transform:translate(-50%,12px)}}
@media(max-width:480px){.orders-toast{bottom:calc(13px + env(safe-area-inset-bottom,0px));padding:12px;gap:9px;font-size:.76rem}.orders-toast-icon{flex-basis:27px;width:27px;height:27px}}
@media(prefers-reduced-motion:reduce){.orders-toast,.orders-toast.is-hiding{animation:none}}
/* Dashboard-matched metric cards: the same cream-to-orange finish on every status. */
.order-metrics {
    display: grid;
    grid-template-columns: repeat(4,minmax(0,1fr));
    gap: 14px;
    margin-bottom: 18px;
}
.order-metric {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    min-width: 0;
    min-height: 92px;
    padding: 17px;
    border: 1px solid #eedfce;
    border-radius: 12px;
    background: linear-gradient(108deg,#fffdfb 0%,#fff7ee 46%,#ffe9d5 100%);
    box-shadow: 0 8px 24px rgba(54,39,25,.045);
}
.order-metric > div { min-width: 0; }
.order-metric span:not(.metric-icon) {
    display: block;
    margin-bottom: 7px;
    color: #7c7671;
    font-size: .69rem;
    font-weight: 700;
    line-height: 1.3;
}
.order-metric strong {
    display: block;
    color: #49372a;
    font-size: 1.18rem;
    font-weight: 900;
    line-height: 1.2;
    overflow-wrap: anywhere;
}
.metric-icon {
    display: grid;
    place-items: center;
    flex: 0 0 38px;
    width: 38px;
    height: 38px;
    border: 1px solid #f1d5bb;
    border-radius: 10px;
    background: rgba(255,255,255,.62);
    color: #c76c27;
}
.metric-icon .ui-icon { display: block; width: 20px; height: 20px; }
.orders-panel{min-width:0;padding:22px 25px 12px;border:1px solid #eae5df;border-radius:23px;background:#fff;box-shadow:0 12px 30px rgba(50,32,17,.035)}
.orders-controls{display:flex;align-items:center;justify-content:space-between;gap:16px;min-width:0;margin-bottom:19px}
.order-tabs{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));flex:1 1 auto;gap:3px;min-width:0}
.order-tab{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:43px;padding:9px 11px;border:1px solid transparent;border-radius:12px;background:transparent;color:#716a64;font-size:.81rem;font-weight:700;white-space:nowrap}
.order-tab:hover{background:#fff7f0}.order-tab.active{border-color:#f3d3b7;background:#fff2e7;color:#ab6229}
.order-tab small{display:inline-grid;place-items:center;min-width:23px;padding:3px 5px;border-radius:7px;background:#f4f2f0;color:#857a70;font-size:.7rem}
.order-tab.active small{background:#ffe4cc;color:#a86328}
.orders-search{display:flex;align-items:center;gap:10px;flex:0 1 300px;min-width:170px;height:48px;padding:0 14px;border:1px solid #e9e3de;border-radius:15px;background:#faf9f8;color:#a7a09a}
.orders-search:focus-within{border-color:#eda36b;background:#fff;box-shadow:0 0 0 3px #fff1e4}
.orders-search input{flex:1;min-width:0;width:100%;border:none;outline:none;background:none;color:#3e3833;font-size:.86rem}
.orders-search input::placeholder{color:#a39b94}
.orders-table{width:100%;table-layout:fixed;border-collapse:collapse}
.orders-table th{padding:15px 12px 18px;border-bottom:1px solid #eae5e0;color:#857e77;text-align:left;font-size:.77rem;font-weight:700}
.orders-table td{min-width:0;padding:17px 12px;border-bottom:1px solid #f0ebe6;color:#554c44;font-size:.83rem;vertical-align:middle}
.orders-table tr:last-child td{border-bottom:0}
.orders-table th:nth-child(1){width:14%}.orders-table th:nth-child(2){width:23%}.orders-table th:nth-child(3){width:25%}.orders-table th:nth-child(4){width:15%}.orders-table th:nth-child(5){width:14%}.orders-table th:nth-child(6){width:9%}
.order-row{cursor:pointer;transition:background .16s;scroll-margin-top:100px}.order-row:hover,.order-row:focus-visible{background:#fff9f3}.order-row[hidden]{display:none!important}
.order-cell{display:flex;align-items:center;gap:11px;min-width:0}.order-cell>span:last-child{min-width:0}
.row-icon{display:inline-grid;place-items:center;flex:0 0 auto;width:38px;height:38px;border-radius:12px;background:#fff0e0;color:#bf7837}
.row-icon.user{background:#f1f4f1;color:#7a9480}.row-icon .ui-icon{width:19px;height:19px}
.row-id{color:#bb6b2d;font-size:.9rem;font-weight:800}
.buyer-name{display:block;color:#3e3935;font-weight:750;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.items-preview{display:block;overflow:hidden;color:#756d66;text-overflow:ellipsis;white-space:nowrap}
.money-column{text-align:right;font-weight:800;color:#463a30!important;white-space:nowrap}
.table-date{color:#8c837b!important;font-size:.77rem!important;white-space:nowrap}
.status-pill{display:inline-flex;align-items:center;gap:6px;min-height:29px;padding:6px 10px;border-radius:10px;background:#fff4df;color:#a87625;font-size:.74rem;font-weight:750;white-space:nowrap}
.status-pill::before{content:"";width:8px;height:8px;flex-shrink:0;border-radius:50%;background:currentColor;opacity:.65}
.status-pill.accepted,.status-pill.confirmed{background:#eaf3fd;color:#4a7eae}.status-pill.processing{background:#f0ecfc;color:#7864ad}.status-pill.completed{background:#e7f7ed;color:#318755}.status-pill.closed,.status-pill.cancelled,.status-pill.rejected,.status-pill.expired{background:#fff0ef;color:#bc5953}
.cell-date-mobile,.cell-status-mobile{display:none}
.open-arrow{text-align:right;color:#b5a89c!important}.open-arrow .ui-icon{width:18px;height:18px}
.empty-list{padding:50px 20px;text-align:center;color:#857a70}.empty-list .ui-icon{width:34px;height:34px;color:#df995b;margin-bottom:8px}.empty-list h2{font-size:1rem;color:#413a34;margin:8px 0}
.empty-list[hidden]{display:none!important}
/* Dialog content scrolls while the actions stay in place. */
dialog{box-sizing:border-box}
.order-details-dialog{width:min(790px,calc(100vw - 24px));max-width:790px;max-height:90dvh;margin:auto;padding:0;border:0;border-radius:20px;background:#fff;box-shadow:0 25px 70px rgba(30,20,10,.22);overflow:hidden}
dialog::backdrop{background:rgba(32,26,21,.52);backdrop-filter:blur(2px)}
.order-details-shell{display:flex;flex-direction:column;min-height:0;max-height:90dvh;overflow:hidden}
.order-details-head{display:flex;align-items:center;justify-content:space-between;flex:0 0 auto;gap:12px;padding:18px 23px;border-bottom:1px solid var(--line);background:#fffaf6}
.popup-kicker{display:block;margin-bottom:3px;color:#b96c33;font-size:.68rem;font-weight:850;letter-spacing:.05em;text-transform:uppercase}
.order-details-head h2{margin:0;font-size:1.28rem;color:#382d26}
.icon-close{display:grid;place-items:center;flex-shrink:0;width:39px;height:39px;border:1px solid #e6ded6;border-radius:50%;background:#fff;color:#72675f}
.icon-close:hover{border-color:#e3aa7b;background:#fff7f0}
.order-details-body{flex:1 1 auto;min-height:0;padding:19px 23px 25px;overflow-y:auto;overflow-x:hidden;overscroll-behavior:contain}
.order-details-footer{display:flex;align-items:center;justify-content:flex-end;flex:0 0 auto;flex-wrap:wrap;gap:9px;padding:14px 23px;border-top:1px solid var(--line);background:#fff;box-shadow:0 -6px 18px rgba(30,20,10,.035)}
.popup-status{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin:12px 0 17px}
.popup-order-date{color:#82776e;font-size:.82rem;text-align:right}
.popup-section-title{display:flex;align-items:center;gap:9px;margin:22px 0 11px;color:#483b31;font-size:.98rem;font-weight:800}.popup-section-title .ui-icon{width:19px;height:19px;color:#b97944}
.product-list{display:grid;gap:11px}
.product-item{min-width:0;padding:15px;border:1px solid #f0e4da;border-radius:15px;background:#fff}
.product-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;min-width:0}
.product-intro{display:flex;align-items:center;gap:12px;min-width:0}.product-photo,.product-placeholder{display:grid;place-items:center;flex:0 0 auto;width:55px;height:55px;border-radius:11px;background:#fff0e5;object-fit:cover;color:#c88a52}
.product-title{font-size:.86rem;font-weight:800;color:#403830;overflow-wrap:anywhere}.product-sub{margin-top:4px;color:#93867b;font-size:.76rem}
.product-price{flex:0 0 auto;text-align:right;font-size:.84rem;font-weight:800;color:#554237}
.addons{margin-top:12px;padding:11px 12px;border:1px solid #efe6dd;border-radius:11px;background:#fbfaf8}
.addons-title{margin-bottom:7px;color:#988b80;font-size:.72rem;font-weight:750}
.addon-line{display:flex;justify-content:space-between;gap:12px;margin:6px 0;color:#6a5e54;font-size:.78rem}.addon-line strong{flex-shrink:0;color:#bd783e;font-size:.75rem}.addon-name{overflow-wrap:anywhere}
.legacy-note{white-space:pre-wrap;overflow-wrap:anywhere;color:#75685d;font-size:.75rem}
.order-buyer-review{margin-top:13px;padding-top:12px;border-top:1px solid #f0e5db}
.order-review-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
.order-review-label{color:#786a5d;font-size:.73rem;font-weight:800}
.order-review-rating{display:inline-flex;align-items:center;gap:7px;color:#6b5a4d;font-size:.72rem}
.order-review-stars{color:#dc982d;letter-spacing:1px;font-size:.9rem;white-space:nowrap}
.order-review-text{margin:8px 0 0;color:#61564c;font-size:.77rem;line-height:1.5;white-space:pre-wrap;overflow-wrap:anywhere}
.order-review-date{margin-top:7px;color:#9c8e82;font-size:.68rem}
.order-review-empty{color:#978a7e;font-size:.73rem}
.order-buyer-review+.order-buyer-review{padding-top:13px}
.total-box{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:14px 0 23px;padding:15px 18px;border:1px solid #f2ddc7;border-radius:14px;background:#fff3e9}.total-box span{font-size:.86rem;font-weight:800;color:#71513b}.total-box strong{font-size:1.3rem;color:#b85d1c;white-space:nowrap}
/* Keep Buyer and Seller order-detail text sizing consistent while retaining Seller colors. */
.popup-fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px;min-width:0}
.popup-fields.popup-buyer-fields{grid-template-columns:repeat(2,minmax(0,1fr))}
/* Seller order details: match Buyer's Delivery/Payment review field order. */
.popup-section-heading{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:9px;margin:22px 0 11px}
.popup-section-heading .popup-section-title{margin:0}
.popup-section-heading .popup-payment-status{margin-left:auto;max-width:100%;white-space:normal;text-align:center}
.popup-fields.popup-arrangement-fields{grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}
.popup-arrangement-fields .popup-location-field{grid-column:1/-1}
@media(max-width:540px){.popup-fields.popup-arrangement-fields{grid-template-columns:minmax(0,1fr)}.popup-arrangement-fields .popup-location-field{grid-column:auto}}

.order-details-dialog .popup-field{min-width:0;min-height:75px;padding:12px;border:1px solid #eee7e0;border-radius:11px;background:#fcfaf8}
.order-details-dialog .popup-label{display:block;margin:0 0 5px;color:#9a897b;font-family:"Segoe UI",Arial,sans-serif;font-size:.72rem;font-weight:750;line-height:1.4;letter-spacing:normal;text-transform:none}
.order-details-dialog .popup-value{display:block;margin:0;color:#50473e;font-family:"Segoe UI",Arial,sans-serif;font-size:.8rem;font-weight:750;line-height:1.48;letter-spacing:normal;text-transform:none;white-space:pre-line;overflow-wrap:anywhere;word-break:normal}
.order-details-dialog .popup-note{min-width:0;margin-top:9px;padding:12px 13px;border:1px solid #eee7e0;border-radius:11px;background:#fcfaf8}
.order-action{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:42px;padding:10px 15px;border:1px solid #e4dad2;border-radius:11px;background:#fff;color:#5e5045;font-size:.8rem;font-weight:750;text-decoration:none;white-space:nowrap}
.order-action:hover{background:#fff7f0;border-color:#e9c8af}.order-action.primary{border-color:#e58a3e;background:#e98b40;color:#fff}.order-action.primary:hover{background:#d8792d}.order-action.success{border-color:#b6dcc4;background:#eef9f1;color:#2e8651}.order-action.success:hover{background:#e2f3e7}.order-action.danger{border-color:#ebc8c5;background:#fff5f4;color:#ba4f49}.order-action.danger:hover{background:#ffeae8}.order-action:disabled{opacity:.6;cursor:not-allowed}
.order-action .ui-icon{width:17px;height:17px}
:where(.order-tab,.order-row,.order-action,.icon-close,select,textarea,input):focus-visible{outline:3px solid #f7b783;outline-offset:2px}
@media(max-width:1150px){.orders-controls{align-items:stretch;flex-direction:column}.orders-search{flex:none;width:100%}.orders-table th:nth-child(3){width:21%}.orders-table th:nth-child(2){width:26%}}
@media(max-width:1080px){.order-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:840px){.page{display:block!important}body .page>main.content.orders-content{width:auto!important;max-width:none!important;min-height:100vh!important;margin-left:250px!important;padding:var(--orders-header-offset,70px) 16px 30px!important}.order-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.orders-panel{padding:17px 17px 14px}.orders-table th:nth-child(3),.orders-table td:nth-child(3){display:none}}
@media(max-width:640px){body .page>main.content.orders-content{padding:var(--orders-header-offset,76px) 12px 26px!important}.orders-hero{margin:0 0 15px}.order-metrics{gap:10px;margin-bottom:14px}.order-metric{min-height:86px;padding:12px;gap:7px;border-radius:12px}.order-metric strong{font-size:clamp(.91rem,3.6vw,1.13rem)}.order-metric span:not(.metric-icon){font-size:.65rem;margin-bottom:5px}.metric-icon{width:32px;height:32px;flex-basis:32px;border-radius:9px}.metric-icon .ui-icon{width:18px;height:18px}.orders-panel{padding:14px 13px 10px;border-radius:17px}.orders-controls{gap:12px;margin-bottom:17px}.order-tabs{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:3px;width:100%}.order-tab{min-width:0;min-height:48px;padding:6px 2px;font-size:.66rem;gap:3px;flex-direction:column;white-space:normal;overflow-wrap:anywhere;text-align:center}.order-tab small{font-size:.6rem;min-width:17px;padding:2px 4px}.orders-search{height:47px}.orders-table,.orders-table tbody{display:block;width:100%}.orders-table thead{display:none}.orders-table tbody tr.order-row{display:grid;grid-template-columns:minmax(0,1fr) auto;grid-template-areas:'order status' 'buyer buyer' 'items items' 'date total';gap:0 10px;margin:0 0 11px;padding:15px 15px 13px;border:1px solid #eae3dd;border-radius:17px;background:#fff;min-width:0}.orders-table tbody tr.order-row:hover,.orders-table tbody tr.order-row:focus-visible{border-color:#edc89e;background:#fffbf7}.orders-table tbody tr.order-row:last-child{margin-bottom:0;border:1px solid #eae3dd}.orders-table td{display:block!important;min-width:0;padding:0!important;border:0!important}.orders-table tbody tr.order-row td.table-buyer{border-top:1px solid #eee6dd!important}.orders-table tbody tr.order-row td.table-total,.orders-table tbody tr.order-row td.table-date{border-top:1px solid #f0e9e3!important}.table-order{grid-area:order;padding-bottom:12px!important}.table-status{grid-area:status;justify-self:end;align-self:start;padding-bottom:12px!important}.table-buyer{grid-area:buyer;padding-top:13px!important;border-top:1px solid #eee6dd!important}.table-items{grid-area:items;padding:8px 0 13px 51px!important;color:#8a7c71!important;font-size:.76rem!important;line-height:1.4}.table-total{grid-area:total;align-self:end;justify-self:end;padding-top:12px!important;border-top:1px solid #f0e9e3!important;font-size:.87rem!important}.table-date{grid-area:date;align-self:end;padding-top:12px!important;border-top:1px solid #f0e9e3!important;font-size:.72rem!important}.open-arrow{display:none!important}.items-preview{white-space:normal;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2}.order-cell{gap:10px}.status-pill{padding:6px 9px;font-size:.71rem}.order-details-dialog{width:calc(100vw - 18px);max-height:94dvh;border-radius:17px}.order-details-shell{max-height:94dvh}.order-details-head{padding:14px 16px}.order-details-head h2{font-size:1.1rem}.order-details-body{padding:15px 15px 22px}.order-details-footer{padding:11px 14px;gap:7px}.order-details-footer .order-action{flex:1 1 auto;padding:11px 8px;font-size:.75rem}.order-details-footer .order-action.primary,.order-details-footer .order-action.success,.order-details-footer .order-action.danger{flex:1 1 100%}.popup-fields{grid-template-columns:repeat(2,minmax(0,1fr))}.confirm-dialog{padding:19px}}
@media(max-width:360px){.order-tab{font-size:.57rem}.orders-table tbody tr.order-row{padding:13px 11px}.row-icon{width:34px;height:34px}.table-items{padding-left:44px!important}.popup-fields{grid-template-columns:1fr}.popup-fields.popup-buyer-fields{grid-template-columns:1fr}.product-head{flex-wrap:wrap}.product-price{margin-left:auto}.addon-line{flex-wrap:wrap}.confirm-actions{flex-direction:column-reverse}.confirm-actions .order-action{width:100%}}
@media(prefers-reduced-motion:reduce){.order-row{transition:none}}

/* Restore the complete Reject Order workflow without losing the compact Orders design. */
.confirm-dialog{display:none;flex-direction:column;overflow:hidden;width:min(545px,calc(100vw - 24px));max-height:min(88dvh,780px);margin:auto;padding:0;border:1px solid #eddfd2;border-radius:20px;background:#fff;box-shadow:0 20px 65px rgba(20,15,10,.22)}
.confirm-dialog[open]{display:flex}
.confirm-head{flex:0 0 auto;padding:20px 22px 12px;border-bottom:1px solid #f0e9e2;background:#fffaf6}
.confirm-head h2{margin:0 0 4px;color:#393129;font-size:1.12rem}.confirm-head p{margin:0;color:#726961;font-size:.82rem;line-height:1.5}
.confirm-head p:empty{display:none}
.confirm-dialog form{display:flex;flex:1 1 auto;flex-direction:column;min-height:0;margin:0}
.rejection-fields{flex:1 1 auto;min-height:0;display:flex;flex-direction:column;gap:11px;padding:15px 22px 17px;overflow-y:auto;overscroll-behavior:contain}
.rejection-fields[hidden]{display:none!important}
.rejection-caption{color:#57483b;font-size:.81rem;font-weight:800}
.rejection-quick-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;max-height:260px;min-height:0;overflow-y:auto;padding:2px 3px 3px;scrollbar-width:thin;scrollbar-color:#ddcbb9 transparent;overscroll-behavior:contain}
.rejection-choice{display:flex;justify-content:flex-start;align-items:center;min-width:0;min-height:42px;padding:10px 12px;border:1px solid #e6ddd5;border-radius:10px;background:#fff;color:#615348;font-size:.74rem;font-weight:680;line-height:1.3;text-align:left;white-space:normal;overflow-wrap:anywhere;transition:background .14s,border-color .14s}
.rejection-choice:hover:not(:disabled){border-color:#e7ad79;background:#fff8f2}
.rejection-choice.is-selected{border-color:#e18c43;background:#fff0e2;color:#9f591e;box-shadow:inset 0 0 0 1px #e18c43}
.rejection-choice:disabled{cursor:default}
.rejection-choice:focus-visible,.rejection-fields textarea:focus-visible{outline:2px solid #e98b40;outline-offset:2px}
.rejection-fields label{font-size:.76rem;font-weight:750;color:#64574c}
.rejection-fields textarea{flex:0 0 auto;width:100%;min-width:0;min-height:75px;max-height:160px;padding:11px 12px;border:1px solid #e4d9cf;border-radius:10px;background:#fff;color:#3e352d;line-height:1.5;font-size:.83rem;resize:vertical}
.rejection-fields textarea.is-invalid{border-color:#cb5652;background:#fffafa}
.rejection-feedback{min-height:0;margin:0!important;color:#b54141!important;font-size:.73rem!important}
.rejection-feedback:empty{display:none}
.confirm-actions{display:flex;justify-content:flex-end;gap:9px;flex:0 0 auto;margin:0;padding:13px 22px;border-top:1px solid #f0e9e2;background:#fff}
.confirm-actions .order-action{min-width:100px}
.confirm-actions .order-action.danger{background:#b95049;color:#fff;border-color:#b95049}
.confirm-actions .order-action.danger:hover{background:#a53f38}
@media(max-width:430px){.confirm-dialog{max-height:92dvh}.confirm-head{padding:17px 16px 12px}.rejection-fields{padding:13px 16px 16px}.rejection-quick-list{grid-template-columns:minmax(0,1fr);max-height:235px}.rejection-choice{min-height:39px;padding:9px 11px}.confirm-actions{padding:12px 16px}.confirm-actions .order-action{flex:1;min-width:0}}

/* Seller Orders: unified workspace background and Buyer-style responsive cards.
   The Orders-only search hookup is below; shared Seller header files remain unchanged. */
html, body, body .page {
    background: #FFF8ED !important;
}
body .page > main.content.orders-content {
    background: #FFF8ED !important;
}
.orders-wrap { min-width: 0; }
/* On compact laptop/tablet widths, the full desktop table becomes order cards. */
@media (max-width: 1100px) {
    .orders-controls { flex-direction: column; align-items: stretch; gap: 13px; }
    .order-tabs { display: grid; grid-template-columns: repeat(6, minmax(0,1fr)); gap: 5px; width: 100%; }
    .order-tab { min-width: 0; justify-content: center; }
    .orders-search { flex: 0 0 auto; width: 100%; min-width: 0; }
    .orders-table, .orders-table tbody { display: block; width: 100%; min-width: 0; }
    .orders-table thead { display: none; }
    .orders-table tbody tr.order-row {
        display: grid;
        grid-template-columns: minmax(0,1fr) auto;
        grid-template-areas:
            "order status"
            "divider divider"
            "buyer buyer"
            "items items"
            "date total";
        column-gap: 12px;
        row-gap: 0;
        min-width: 0;
        margin: 0 0 12px;
        padding: 16px;
        border: 1px solid #eae1d8;
        border-radius: 17px;
        background: #fff;
        box-shadow: none;
    }
    .orders-table tbody tr.order-row:last-child { margin-bottom: 0; border-bottom: 1px solid #eae1d8; }
    .orders-table tbody tr.order-row::before {
        content: "";
        display: block;
        grid-area: divider;
        width: 100%;
        height: 1px;
        margin: 12px 0 13px;
        background: #eee1d5;
    }
    .orders-table tbody tr.order-row td {
        display: block !important;
        width: auto !important;
        min-width: 0;
        padding: 0 !important;
        border: none !important;
    }
    .orders-table tbody tr.order-row .table-order { grid-area: order; align-self: center; }
    .orders-table tbody tr.order-row .table-status { grid-area: status; align-self: center; justify-self: end; }
    .orders-table tbody tr.order-row .table-buyer { grid-area: buyer; align-self: center; }
    .orders-table tbody tr.order-row .table-items {
        grid-area: items;
        min-width: 0;
        padding: 8px 0 15px 49px !important;
        color: #82776e;
        font-size: .75rem;
        line-height: 1.4;
        overflow-wrap: anywhere;
    }
    .orders-table .table-items::before { content: "Items: "; color: #a29386; }
    .orders-table .items-preview {
        display: inline;
        white-space: normal;
        overflow-wrap: anywhere;
    }
    .orders-table tbody tr.order-row .table-date,
    .orders-table tbody tr.order-row .table-total {
        padding-top: 13px !important;
        border-top: 0 !important;
        align-self: end;
    }
    .orders-table tbody tr.order-row .table-date { grid-area: date; justify-self: start; font-size: .73rem !important; }
    .orders-table tbody tr.order-row .table-date::before { content: "Ordered "; }
    .orders-table tbody tr.order-row .table-total {
        grid-area: total;
        justify-self: end;
        text-align: right;
        font-size: .94rem;
    }
    .orders-table .open-arrow { display: none !important; }
    .orders-table .buyer-name { white-space: normal; overflow-wrap: anywhere; }
}
/* Same visual rhythm as the Buyer Orders mobile cards, with Seller colors. */
@media (max-width: 840px) {
    body .page > main.content.orders-content {
        margin-left: 250px !important;
        padding: var(--orders-header-offset, 70px) 16px 32px !important;
        width: auto !important;
        min-width: 0 !important;
        background: #FFF8ED !important;
    }
    .orders-wrap { width: 100%; max-width: 100%; }
    .order-metrics { grid-template-columns: repeat(2,minmax(0,1fr)); gap: 10px; }
    .orders-panel { padding: 16px; border-radius: 20px; }
}
@media (max-width: 640px) {
    body .page > main.content.orders-content { padding: var(--orders-header-offset, 76px) 12px 28px !important; }
    .orders-hero { margin: 4px 0 15px; }
    .orders-hero h1 { font-size: 1.65rem; }
    .order-metrics { gap: 10px; margin-bottom: 14px; }
    .order-metric { min-height: 86px; padding: 12px; gap: 7px; border-radius: 12px; }
    .orders-panel { padding: 16px; border-radius: 20px; }
    .orders-controls { gap: 14px; margin-bottom: 18px; }
    .order-tabs { display: grid; grid-template-columns: repeat(6,minmax(0,1fr)); gap: 3px; }
    .order-tab {
        width: 100%;
        min-width: 0;
        min-height: 45px;
        padding: 8px 4px;
        border-radius: 11px;
        font-size: clamp(.57rem, 2vw, .68rem);
        letter-spacing: -.02em;
        white-space: normal;
        overflow-wrap: anywhere;
        text-align: center;
        flex-direction: column;
        gap: 2px;
    }
    .order-tab small { min-width: 17px; font-size: .6rem; padding: 2px 4px; }
    .orders-search { width: 100%; min-width: 0; height: 50px; border-radius: 15px; }
    .orders-table tbody tr.order-row {
        grid-template-areas: "order status" "divider divider" "buyer buyer" "items items" "date total";
        gap: 0 10px;
        margin-bottom: 12px;
        padding: 17px;
        border: 1px solid #ece2d9;
        border-radius: 18px;
    }
    .orders-table tbody tr.order-row::before { margin: 14px 0 14px; }
    .orders-table tbody tr.order-row td.table-buyer {
        padding: 0 !important;
        border: 0 !important;
    }
    .orders-table tbody tr.order-row .table-items {
        padding: 10px 0 18px 49px !important;
        font-size: .76rem !important;
    }
    .orders-table tbody tr.order-row td.table-date,
    .orders-table tbody tr.order-row td.table-total {
        padding-top: 14px !important;
        border-top: 0 !important;
    }
    .orders-table tbody tr.order-row .table-date { font-size: .72rem !important; }
    .orders-table tbody tr.order-row .table-total { font-size: .92rem !important; }
    .order-cell { gap: 11px; }
    .row-icon { width: 39px; height: 39px; }
    .row-id { font-size: .94rem; }
    .buyer-name { font-size: .84rem; }
    .status-pill { font-size: .71rem; min-height: 31px; border-radius: 12px; }
}
/* Sidebar switches to a mobile drawer at 760px (same as shared Seller navigation). */
@media (max-width: 760px) {
    body .page > main.content.orders-content {
        margin-left: 0 !important;
        width: 100% !important;
        max-width: none !important;
        padding: var(--orders-header-offset, 76px) 12px 28px !important;
    }
}
@media (max-width: 360px) {
    .order-tabs { grid-template-columns: repeat(6,minmax(0,1fr)); }
    .orders-panel { padding: 12px; }
    .orders-table tbody tr.order-row { padding: 13px; }
    .orders-table tbody tr.order-row .table-items { padding-left: 44px !important; }
    .orders-table tbody tr.order-row .table-date { font-size: .68rem !important; }
}

/* Show that an order row opens its details without adding another action button. */
@media (min-width: 1101px) {
    .orders-table td.table-date { position: relative; padding-right: 30px; }
    .orders-table td.table-date::after {
        content: '\203A';
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: #b9783f;
        font-size: 1.5rem;
        font-weight: 700;
        line-height: 1;
        pointer-events: none;
    }
}
@media (max-width: 1100px) {
    .orders-table tbody tr.order-row { position: relative; padding-right: 38px; }
    .orders-table tbody tr.order-row::after {
        content: '\203A';
        position: absolute;
        right: 13px;
        top: 50%;
        transform: translateY(-50%);
        color: #b9783f;
        font-size: 1.55rem;
        font-weight: 700;
        line-height: 1;
        pointer-events: none;
    }
}
/* Swipeable tabs on phones, with no visible scrollbar or page overflow. */
@media (max-width: 760px) {
    .order-tabs {
        display: flex;
        flex-wrap: nowrap;
        align-items: stretch;
        width: 100%;
        max-width: 100%;
        min-width: 0;
        gap: 7px;
        padding: 2px 1px 6px;
        overflow-x: auto;
        overflow-y: hidden;
        scrollbar-width: none;
        -ms-overflow-style: none;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior-x: contain;
        scroll-snap-type: x proximity;
    }
    .order-tabs::-webkit-scrollbar { display: none; width: 0; height: 0; }
    .order-tab {
        flex: 0 0 auto;
        width: auto;
        min-width: 94px;
        min-height: 43px;
        padding: 8px 12px;
        flex-direction: row;
        gap: 6px;
        font-size: .75rem;
        white-space: nowrap;
        overflow-wrap: normal;
        scroll-snap-align: start;
    }
}

</style>
</head>
<body>
<div class="page">
    <?php require __DIR__ . '/seller_nav.php'; ?>
    <main class="content orders-content">
        <?php $searchPlaceholder = 'Search orders...'; ?>
        <div class="orders-search-shell" id="ordersSearchShell"><?php require __DIR__ . '/seller_search.php'; ?></div>
        <div class="orders-wrap">
            <header class="orders-hero"><h1>Orders</h1></header>

            <section class="order-metrics" aria-label="Order overview">
                <div class="order-metric"><div><span>Total Orders</span><strong><?= $counts['all'] ?></strong></div><span class="metric-icon"><?= sellerOrderIcon('bag') ?></span></div>
                <div class="order-metric"><div><span>Pending</span><strong><?= $counts['pending'] ?></strong></div><span class="metric-icon amber"><?= sellerOrderIcon('clock') ?></span></div>
                <div class="order-metric"><div><span>Processing</span><strong><?= $counts['processing'] ?></strong></div><span class="metric-icon blue"><?= sellerOrderIcon('truck') ?></span></div>
                <div class="order-metric"><div><span>Completed</span><strong><?= $counts['completed'] ?></strong></div><span class="metric-icon green"><?= sellerOrderIcon('check') ?></span></div>
            </section>
            <section class="orders-panel" aria-label="Seller order list">
                <div class="orders-controls">
                    <div class="order-tabs" aria-label="Order status" role="group">
                        <?php foreach (['all'=>'All','pending'=>'Pending','accepted'=>'Accepted','processing'=>'Processing','completed'=>'Completed','closed'=>'Closed'] as $key=>$label): ?>
                            <button class="order-tab <?= $key === 'all' ? 'active' : '' ?>" type="button" data-filter="<?= e($key) ?>" aria-pressed="<?= $key === 'all' ? 'true' : 'false' ?>"><?= e($label) ?><small><?= (int)$counts[$key] ?></small></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if (!$orders): ?>
                    <div class="empty-list"><?= sellerOrderIcon('package') ?><h2>No orders yet</h2></div>
                <?php else: ?>
                    <table class="orders-table" aria-label="Seller orders"><thead><tr><th>Order</th><th>Buyer</th><th>Items</th><th style="text-align:right">Total</th><th>Status</th><th>Date</th></tr></thead><tbody>
                    <?php foreach ($orders as $order): ?>
                        <?php
                        $orderId=(int)$order['order_id'];
                        $uiStatus = statusKey($order['order_status'] ?? 'pending');
                        $uiStatus = match ($uiStatus) {'confirmed'=>'accepted','canceled'=>'cancelled','declined'=>'rejected',default=>$uiStatus};
                        $statusGroup = in_array($uiStatus,['cancelled','rejected','expired'],true)?'closed':$uiStatus;
                        $uiLabel = $statusGroup === 'closed' ? 'Closed' : niceStatus($uiStatus);
                        $buyerName = trim((string)($order['buyer_name'] ?? '')) ?: 'Buyer';
                        $summary = trim((string)($order['items_summary'] ?? '')) ?: 'Product';
                        $paymentKey = statusKey($order['payment_status'] ?? '');
                        $paymentOption = statusKey($order['payment_option'] ?? '');
                        $paymentMethod = (string)($order['payment_method'] ?? '');
                        $isCash = $paymentOption === 'cash' || isCashMethod($paymentMethod);
                        $isQr = isQrMethod($paymentMethod, $paymentOption);
                        $paymentLabel = $isCash?'Cash':($isQr?'QR Ph':'Not set');
                        $paymentStatusLabel = $paymentKey==='paid'?'Paid':($paymentKey==='refunded'?'Refunded':($isCash?'Cash in Person':($paymentKey==='failed'?'Failed':($paymentKey==='cancelled'?'Cancelled':'Waiting for Payment'))));
                        $arrangementKey=statusKey($order['arrangement_status'] ?? '');
                        $canStart = $uiStatus==='accepted' && $arrangementKey==='accepted' && (($isCash&&in_array($paymentKey,['','pending','paid'],true)) || ($isQr&&$paymentKey==='paid'));
                        $canComplete = $uiStatus==='processing' && (($isCash&&in_array($paymentKey,['','pending','paid'],true))||($isQr&&$paymentKey==='paid'));
                        $canReject = $uiStatus==='pending' && $arrangementKey!=='accepted' && !in_array($paymentKey,['paid','refunded'],true);
                        $deliveryKey=statusKey($order['fulfillment_method'] ?? '');
                        $deliveryLabel=match($deliveryKey){'meetup'=>'Meetup','pickup'=>'Pickup','third_party_delivery'=>'Third-Party Delivery','other'=>'Other',default=>'Not set'};
                        $locationLabel=match($deliveryKey){'meetup'=>'Meetup Place','pickup'=>'Pickup Place','third_party_delivery'=>'Delivery Address',default=>'Location'};
                        $arrangementLocation=trim((string)($order['arrangement_location'] ?? ''));
                        $arrangementDate=(string)($order['arrangement_date'] ?? '');
                        $arrangementTime=(string)($order['arrangement_time'] ?? '');
                        $arrangementNotes=trim((string)($order['arrangement_notes'] ?? ''));
                        $orderNotes=trim((string)($order['notes'] ?? ''));
                        $items=$orderItems[$orderId]??[];
                        ?>
                        <tr class="order-row" id="order-<?= $orderId ?>" data-order-id="<?= $orderId ?>" data-filter-group="<?= e($statusGroup) ?>" data-search="<?= e('#'.$orderId.' '.$buyerName.' '.$summary.' '.$uiLabel.' '.$uiStatus) ?>" tabindex="0" aria-label="View Order #<?= $orderId ?>">
                            <td class="table-order"><span class="order-cell"><span class="row-icon"><?= sellerOrderIcon('package') ?></span><span class="row-id">#<?= $orderId ?></span></span></td>
                            <td class="table-buyer"><span class="order-cell"><span class="row-icon user"><?= sellerOrderIcon('user') ?></span><span class="buyer-name"><?= e($buyerName) ?></span></span></td>
                            <td class="table-items"><span class="items-preview"><?= e($summary) ?></span></td>
                            <td class="table-total money-column"><?= money((float)$order['total_amount']) ?></td>
                            <td class="table-status"><span class="status-pill <?= e($statusGroup) ?>"><?= e($uiLabel) ?></span>
                                <template class="order-detail-template" data-order-id="<?= $orderId ?>">
                                    <div class="popup-info">
                                        <div class="popup-status"><span class="status-pill <?= e($statusGroup) ?>"><?= e($uiLabel) ?></span><span class="popup-order-date"><?= e(sellerOrderDate($order['ordered_at'])) ?></span></div>
                                        <h3 class="popup-section-title"><?= sellerOrderIcon('package') ?> Products</h3>
                                        <div class="product-list">
                                            <?php foreach ($items as $item): ?>
                                                <?php
                                                $photo=sellerOrderPhoto($item['product_photo'] ?? '');
                                                $qty=max(1,(int)$item['quantity']);
                                                $unit=($item['agreed_unit_price'] !== null && $item['agreed_unit_price'] !== '') ? (float)$item['agreed_unit_price'] : (float)$item['unit_price'];
                                                $choicesText=trim((string)($item['special_request'] ?? ''));
                                                $choices=sellerOrderChoices($choicesText);
                                                ?>
                                                <div class="product-item">
                                                    <div class="product-head"><div class="product-intro">
                                                        <?php if ($photo!==''): ?><img src="<?= e($photo) ?>" class="product-photo" alt="" loading="lazy"><?php else: ?><span class="product-placeholder"><?= sellerOrderIcon('bag') ?></span><?php endif; ?>
                                                        <div><div class="product-title"><?= e((string)$item['product_name']) ?></div><div class="product-sub"><?= $qty ?> × <?= money($unit) ?></div></div>
                                                    </div><strong class="product-price"><?= money((float)($item['subtotal'] ?? ($unit*$qty))) ?></strong></div>
                                                    <?php if ($choices): ?>
                                                        <div class="addons"><div class="addons-title">Selected Add-ons</div>
                                                        <?php foreach ($choices as $choice): ?>
                                                            <div class="addon-line"><span class="addon-name"><?= e($choice['name']) ?><?= $choice['details']!==''?' — '.e($choice['details']):'' ?></span><strong>+<?= money($choice['price']) ?> each</strong></div>
                                                        <?php endforeach; ?></div>
                                                    <?php elseif ($choicesText!==''): ?>
                                                        <div class="addons"><div class="addons-title">Order Details</div><div class="legacy-note"><?= e($choicesText) ?></div></div>
                                                    <?php endif; ?>
                                                    <?php if ($statusGroup === 'completed'): ?>
                                                        <?php $itemReviews = $orderReviews[$orderId][(int)$item['product_id']] ?? []; ?>
                                                        <?php if (!$orderReviewsAvailable || !$itemReviews): ?>
                                                            <div class="order-buyer-review">
                                                                <div class="order-review-head"><span class="order-review-label">Buyer Review</span><span class="order-review-empty"><?= $orderReviewsAvailable ? 'Not yet reviewed' : 'Reviews unavailable' ?></span></div>
                                                            </div>
                                                        <?php else: ?>
                                                            <?php foreach ($itemReviews as $review): ?>
                                                                <?php
                                                                $rating = max(0, min(5, (int)$review['rating']));
                                                                $reviewText = trim((string)($review['review_text'] ?? ''));
                                                                ?>
                                                                <div class="order-buyer-review">
                                                                    <div class="order-review-head"><span class="order-review-label">Buyer Review</span><span class="order-review-rating" aria-label="<?= $rating ?> out of 5 stars"><span class="order-review-stars" aria-hidden="true"><?= str_repeat('★', $rating) . str_repeat('☆', 5 - $rating) ?></span><strong><?= $rating ?>/5</strong></span></div>
                                                                    <?php if ($reviewText !== ''): ?><p class="order-review-text"><?= e($reviewText) ?></p><?php endif; ?>
                                                                    <div class="order-review-date"><?= e(sellerOrderDate($review['created_at'], true)) ?></div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (!$items): ?><p>Product details unavailable.</p><?php endif; ?>
                                        </div>
                                        <div class="total-box"><span>Order Total</span><strong><?= money((float)$order['total_amount']) ?></strong></div>
                                        <h3 class="popup-section-title"><?= sellerOrderIcon('user') ?> Buyer Details</h3>
                                        <div class="popup-fields popup-buyer-fields">
                                            <div class="popup-field"><span class="popup-label">Buyer</span><strong class="popup-value"><?= e($buyerName) ?></strong></div>
                                            <div class="popup-field"><span class="popup-label">Phone</span><strong class="popup-value"><?= e(trim((string)($order['buyer_phone'] ?? '')) ?: 'Not provided') ?></strong></div>
                                        </div>
                                        <?php if ($orderNotes!==''): ?><div class="popup-note"><span class="popup-label">Order Note</span><div class="popup-value"><?= e($orderNotes) ?></div></div><?php endif; ?>
                                        <div class="popup-section-heading">
                                            <h3 class="popup-section-title"><?= sellerOrderIcon('truck') ?> Delivery &amp; Payment</h3>
                                            <span class="status-pill popup-payment-status <?= e(match ($paymentKey) {'paid'=>'completed','refunded'=>'refunded','cancelled','canceled'=>'cancelled','failed'=>'rejected',default=>'pending'}) ?>"><?= e($paymentStatusLabel) ?></span>
                                        </div>
                                        <div class="popup-fields popup-arrangement-fields">
                                            <div class="popup-field"><span class="popup-label">Delivery</span><strong class="popup-value"><?= e($deliveryLabel) ?></strong></div>
                                            <div class="popup-field"><span class="popup-label">Payment</span><strong class="popup-value"><?= e($paymentLabel) ?></strong></div>
                                            <div class="popup-field popup-location-field"><span class="popup-label"><?= e($locationLabel) ?></span><strong class="popup-value"><?= e($arrangementLocation !== '' ? $arrangementLocation : 'Not set') ?></strong></div>
                                            <div class="popup-field"><span class="popup-label">Date</span><strong class="popup-value"><?= e(sellerOrderDate($arrangementDate, true)) ?></strong></div>
                                            <div class="popup-field"><span class="popup-label">Time</span><strong class="popup-value"><?= e(sellerOrderTime($arrangementTime)) ?></strong></div>
                                        </div>
                                        <?php if ($arrangementNotes!==''): ?><div class="popup-note"><span class="popup-label">Delivery Note</span><div class="popup-value"><?= e($arrangementNotes) ?></div></div><?php endif; ?>
                                    </div>
                                    <div class="popup-actions">
                                        <a class="order-action" href="messages_seller.php?buyer_id=<?= (int)$order['buyer_id'] ?>&amp;order_id=<?= $orderId ?>"><?= sellerOrderIcon('message') ?> Message Buyer</a>
                                        <?php if ($canReject): ?>
                                            <button class="order-action danger order-transition" type="button" data-order-id="<?= $orderId ?>" data-action="reject_order" data-title="Reject Order #<?= $orderId ?>" data-confirm-label="Reject Order"><?= sellerOrderIcon('reject') ?> Reject Order</button>
                                        <?php endif; ?>
                                        <?php if ($canStart): ?>
                                            <button class="order-action primary order-transition" type="button" data-order-id="<?= $orderId ?>" data-action="start_processing" data-title="Start Processing?" data-confirm-label="Start Processing"><?= sellerOrderIcon('play') ?> Start Processing</button>
                                        <?php endif; ?>
                                        <?php if ($canComplete): ?>
                                            <button class="order-action success order-transition" type="button" data-order-id="<?= $orderId ?>" data-action="complete_order" data-title="Complete Order?" data-message="<?= $isCash ? 'Mark as completed and record cash as received?' : 'Has the order been handed over?' ?>" data-confirm-label="Complete Order"><?= sellerOrderIcon('check') ?> Complete Order</button>
                                        <?php endif; ?>
                                    </div>
                                </template>
                            </td>
                            <td class="table-date"><?= e(sellerOrderDate($order['ordered_at'],true)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table>
                    <div class="empty-list" id="filterEmpty" hidden><?= sellerOrderIcon('search') ?><h2>No matching orders</h2></div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
<?php if ($message !== ''): ?>
<div id="ordersToast" class="orders-toast <?= $messageType === 'error' ? 'error' : 'success' ?>" role="<?= $messageType === 'error' ? 'alert' : 'status' ?>" aria-live="<?= $messageType === 'error' ? 'assertive' : 'polite' ?>" aria-atomic="true" data-duration="<?= $messageType === 'error' ? '10000' : '6500' ?>">
    <span class="orders-toast-icon" aria-hidden="true"><?= sellerOrderIcon($messageType === 'error' ? 'reject' : 'check') ?></span>
    <span class="orders-toast-text"><?= e($message) ?></span>
    <button type="button" class="orders-toast-close" aria-label="Dismiss notification"><?= sellerOrderIcon('close') ?></button>
</div>
<?php endif; ?>
<dialog class="order-details-dialog" id="orderDetailsDialog" aria-labelledby="orderDetailsTitle">
    <div class="order-details-shell">
        <div class="order-details-head"><div><span class="popup-kicker">Order Details</span><h2 id="orderDetailsTitle">Order</h2></div><button class="icon-close" id="orderDetailsClose" type="button" aria-label="Close order details"><?= sellerOrderIcon('close') ?></button></div>
        <div class="order-details-body" id="orderDetailsBody"></div>
        <div class="order-details-footer" id="orderDetailsFooter"></div>
    </div>
</dialog>
<dialog class="confirm-dialog" id="actionDialog" aria-labelledby="actionDialogTitle" aria-describedby="actionDialogMessage">
    <div class="confirm-head">
        <h2 id="actionDialogTitle">Update Order?</h2>
        <p id="actionDialogMessage"></p>
    </div>
    <form method="post" id="actionDialogForm">
        <input type="hidden" name="csrf_token" value="<?= e((string)$_SESSION['seller_orders_csrf']) ?>">
        <input type="hidden" name="action" id="actionDialogAction">
        <input type="hidden" name="order_id" id="actionDialogOrderId">
        <div class="rejection-fields" id="rejectionFields" hidden>
            <span class="rejection-caption" id="rejectionCaption">Reason</span>
            <div class="rejection-quick-list" id="rejectionQuickList" role="group" aria-labelledby="rejectionCaption">
                <?php foreach (sellerRejectionReasons() as $code=>$label): ?>
                    <button type="button" class="rejection-choice" data-reason="<?= e($code) ?>" aria-pressed="false" disabled><?= e($label) ?></button>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="rejection_reason" id="rejectionReason" value="" disabled>
            <label for="rejectionDetails" id="rejectionDetailsLabel">Extra Details (optional)</label>
            <textarea id="rejectionDetails" name="rejection_details" rows="2" maxlength="240" placeholder="Add details" disabled></textarea>
            <p class="rejection-feedback" id="rejectionFeedback" role="status" aria-live="polite"></p>
        </div>
        <div class="confirm-actions">
            <button type="button" class="order-action" id="actionDialogCancel">Back</button>
            <button type="submit" class="order-action primary" id="actionDialogSubmit">Confirm</button>
        </div>
    </form>
</dialog>
<script>
(() => {
    'use strict';
    // Keep Orders content directly below the shared fixed Seller Search header.
    // The sidebar and page content stay in normal document flow.
    const ordersMain = document.querySelector('main.orders-content');
    const ordersHeader = document.querySelector('#ordersSearchShell .seller-header-tools');
    if (ordersMain && ordersHeader) {
        function syncOrdersHeaderSpace() {
            const style = getComputedStyle(ordersHeader);
            const rect = ordersHeader.getBoundingClientRect();
            const isFixed = style.position === 'fixed' || style.position === 'sticky';
            const clearance = isFixed && rect.height > 0 ? Math.max(0, rect.bottom) : 0;
            ordersMain.style.setProperty('--orders-header-offset', `${Math.ceil(clearance)}px`);
        }
        syncOrdersHeaderSpace();
        window.addEventListener('load', syncOrdersHeaderSpace);
        window.addEventListener('resize', syncOrdersHeaderSpace);
        window.addEventListener('pageshow', syncOrdersHeaderSpace);
        if ('ResizeObserver' in window) {
            new ResizeObserver(syncOrdersHeaderSpace).observe(ordersHeader);
        }
    }
    const filterButtons=[...document.querySelectorAll('.order-tab[data-filter]')];
    const rows=[...document.querySelectorAll('.order-row')];
    // Reuse the shared header input only on this Seller Orders page.
    const search=document.querySelector('#ordersSearchShell #productSearch');
    search?.setAttribute('aria-label','Search orders');
    const empty=document.getElementById('filterEmpty');
    let filter='all';
    function updateList(){
        const query=(search?.value||'').trim().toLocaleLowerCase();
        let shown=0;
        rows.forEach(row=>{
            const visible=(filter==='all'||row.dataset.filterGroup===filter) && (!query||(row.dataset.search||'').toLocaleLowerCase().includes(query));
            row.hidden=!visible;
            if(visible) shown++;
        });
        if(empty) empty.hidden=shown!==0;
    }
    filterButtons.forEach(button=>button.addEventListener('click',()=>{
        filter=button.dataset.filter||'all';
        filterButtons.forEach(item=>{const active=item===button;item.classList.toggle('active',active);item.setAttribute('aria-pressed',String(active));});
        updateList();
    }));
    search?.addEventListener('input',updateList);
    updateList();
    const details=document.getElementById('orderDetailsDialog');
    const detailsTitle=document.getElementById('orderDetailsTitle');
    const detailsBody=document.getElementById('orderDetailsBody');
    const detailsFooter=document.getElementById('orderDetailsFooter');
    const confirm=document.getElementById('actionDialog');
    const toast=document.getElementById('ordersToast');
    let toastTimeout=null;
    function syncToastLayer(){
        if(!toast||!toast.isConnected)return;
        const layer=confirm?.open?confirm:(details?.open?details:document.body);
        if(toast.parentElement!==layer)layer.appendChild(toast);
    }
    function dismissToast(){
        if(!toast||!toast.isConnected||toast.classList.contains('is-hiding'))return;
        if(toastTimeout!==null)window.clearTimeout(toastTimeout);
        toast.classList.add('is-hiding');
        window.setTimeout(()=>toast.remove(),220);
    }
    if(toast){
        toast.querySelector('.orders-toast-close')?.addEventListener('click',dismissToast);
        toastTimeout=window.setTimeout(dismissToast,Number(toast.dataset.duration)||6500);
    }
    let lastRow=null;
    const updateScroll=()=>document.body.classList.toggle('modal-open',Boolean(details?.open||confirm?.open));
    function openDetails(row){
        const orderId=row.dataset.orderId;
        const template=row.querySelector('.order-detail-template');
        if(!template||!details||!detailsBody||!detailsFooter)return;
        const clone=template.content.cloneNode(true);
        const info=clone.querySelector('.popup-info');
        const actions=clone.querySelector('.popup-actions');
        if(!info||!actions)return;
        detailsBody.replaceChildren(info);
        detailsFooter.replaceChildren(...Array.from(actions.children));
        detailsTitle.textContent='Order #'+orderId;
        detailsBody.scrollTop=0;
        lastRow=row;
        details.showModal();
        syncToastLayer();
        updateScroll();
    }
    rows.forEach(row=>{
        row.addEventListener('click',event=>{if(event.target.closest('button,a,input,select,textarea'))return;openDetails(row)});
        row.addEventListener('keydown',event=>{if(event.target===row&&(event.key==='Enter'||event.key===' ')){event.preventDefault();openDetails(row)}});
    });
    document.getElementById('orderDetailsClose')?.addEventListener('click',()=>details?.close());
    details?.addEventListener('click',event=>{if(event.target===details)details.close()});
    details?.addEventListener('close',()=>{syncToastLayer();updateScroll();lastRow?.focus({preventScroll:true})});
    const title=document.getElementById('actionDialogTitle');
    const message=document.getElementById('actionDialogMessage');
    const action=document.getElementById('actionDialogAction');
    const order=document.getElementById('actionDialogOrderId');
    const form=document.getElementById('actionDialogForm');
    const submit=document.getElementById('actionDialogSubmit');
    const rejectFields=document.getElementById('rejectionFields');
    const reason=document.getElementById('rejectionReason');
    const extra=document.getElementById('rejectionDetails');
    const extraLabel=document.getElementById('rejectionDetailsLabel');
    const feedback=document.getElementById('rejectionFeedback');
    const rejectionChoices=[...document.querySelectorAll('.rejection-choice')];
    let saving=false;
    let actionTrigger=null;
    function updateRejectionState(){
        const rejecting=action.value==='reject_order';
        const choice=rejecting?reason.value:'';
        const needsText=choice==='other';
        rejectFields.hidden=!rejecting;
        reason.disabled=!rejecting;
        extra.disabled=!rejecting;
        extra.required=rejecting&&needsText;
        extraLabel.textContent=needsText?'Extra Details (required)':'Extra Details (optional)';
        rejectionChoices.forEach(button=>{
            button.disabled=!rejecting;
            const selected=button.dataset.reason===choice;
            button.classList.toggle('is-selected',selected);
            button.setAttribute('aria-pressed',String(selected));
        });
        const valid=!rejecting||(choice!==''&&(!needsText||extra.value.trim()!==''));
        submit.disabled=saving||!valid;
        if(!needsText)extra.classList.remove('is-invalid');
        return valid;
    }
    rejectionChoices.forEach(button=>button.addEventListener('click',()=>{
        if(button.disabled)return;
        reason.value=button.dataset.reason||'';
        feedback.textContent='';
        extra.classList.remove('is-invalid');
        updateRejectionState();
        if(reason.value==='other')extra.focus();
    }));
    extra?.addEventListener('input',()=>{feedback.textContent='';extra.classList.remove('is-invalid');updateRejectionState()});
    detailsFooter?.addEventListener('click',event=>{
        const button=event.target.closest('button.order-transition');
        if(!button||!confirm)return;
        const type=button.dataset.action||'';
        if(!['reject_order','start_processing','complete_order'].includes(type))return;
        actionTrigger=button;
        action.value=type;
        order.value=button.dataset.orderId||'';
        title.textContent=button.dataset.title||'Update Order?';
        message.textContent=button.dataset.message||(
            type==='start_processing'?'Start working on this order?':
            type==='complete_order'?'Has the order been handed over?':''
        );
        submit.textContent=button.dataset.confirmLabel||'Confirm';
        submit.classList.toggle('danger',type==='reject_order');
        submit.classList.toggle('primary',type!=='reject_order');
        saving=false;
        reason.value='';
        extra.value='';
        feedback.textContent='';
        extra.classList.remove('is-invalid');
        updateRejectionState();
        confirm.showModal();
        syncToastLayer();
        updateScroll();
    });
    document.getElementById('actionDialogCancel')?.addEventListener('click',()=>confirm?.close());
    confirm?.addEventListener('click',event=>{if(event.target===confirm)confirm.close()});
    confirm?.addEventListener('close',()=>{saving=false;syncToastLayer();updateScroll();actionTrigger?.focus({preventScroll:true});actionTrigger=null});
    form?.addEventListener('submit',event=>{
        if(saving){event.preventDefault();return}
        if(!updateRejectionState()){
            event.preventDefault();
            if(!reason.value){
                feedback.textContent='Choose a reason.';
                rejectionChoices[0]?.focus();
            }else{
                feedback.textContent='Enter the reason.';
                extra.classList.add('is-invalid');
                extra.focus();
            }
            return;
        }
        saving=true;
        submit.disabled=true;
        submit.textContent='Saving...';
    });
    // Open the specific Seller order when a link or action redirect includes its ID.
    const anchorMatch=location.hash.match(/^#order-(\d+)$/);
    const queryOrderId=new URLSearchParams(location.search).get('order_id');
    const linkedOrderId=anchorMatch?.[1]||(/^\d+$/.test(queryOrderId||'')?queryOrderId:null);
    if(linkedOrderId){
        const linkedRow=rows.find(item=>item.dataset.orderId===linkedOrderId);
        if(linkedRow){
            const linkedTab=filterButtons.find(button=>button.dataset.filter===linkedRow.dataset.filterGroup);
            linkedTab?.click();
            if(search&&search.value.trim()){search.value='';updateList()}
            linkedRow.scrollIntoView({block:'nearest'});
            openDetails(linkedRow);
        }
    }
})();
</script>
</body>
</html>
