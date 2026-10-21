<?php
require_once __DIR__ . '/buyer_auth.php';
require_once __DIR__ . '/../config/notification_events.php';
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function orderJson(
    bool $success,
    string $message,
    array $data = [],
    int $status = 200
): void {
    http_response_code($status);
    echo json_encode(
        array_merge(
            ['success' => $success, 'message' => $message],
            $data
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}
function orderText($value): string
{
    return trim((string)$value);
}
function orderTextLength(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}
function orderMoney(float $value): string
{
    return '₱' . number_format($value, 2);
}
function orderDeliveryLabel(string $value): string
{
    $labels = [
        'meetup' => 'Meetup',
        'pickup' => 'Pickup',
        'third_party_delivery' => 'Third-Party Delivery',
        'other' => 'Other',
    ];
    return $labels[$value] ?? 'Not selected';
}
function orderPaymentLabel(string $value): string
{
    $labels = [
        'paymongo' => 'QR Ph',
        'cash' => 'Cash',
    ];
    return $labels[$value] ?? 'Not selected';
}
function orderValidateOptions(
    string $deliveryOption,
    string $paymentOption
): void {
    $allowedDelivery = [
        'meetup',
        'pickup',
        'third_party_delivery',
        'other',
    ];
    $allowedPayment = ['paymongo', 'cash'];
    if (!in_array($deliveryOption, $allowedDelivery, true)) {
        throw new RuntimeException('Please select a valid Delivery Option.');
    }
    if (!in_array($paymentOption, $allowedPayment, true)) {
        throw new RuntimeException('Please select a valid Payment Option.');
    }
    if (
        in_array($deliveryOption, ['third_party_delivery', 'other'], true)
        && $paymentOption === 'cash'
    ) {
        throw new RuntimeException(
            'Cash is only available for Meetup or Pickup. Please select QR Ph.'
        );
    }
}
// A Pending request does not reserve stock or collect payment here.
// The arrangement-confirmation/payment modules must recheck availability and
// reserve stock exactly once when the order is accepted.
function orderSellerProductAvailable(array $product): bool
{
    return strtolower(orderText($product['product_status'] ?? '')) === 'available'
        && max(0, (int)($product['available_stock'] ?? 0)) > 0
        && (int)($product['seller_flag'] ?? 0) === 1
        && (int)($product['admin_flag'] ?? 0) === 0
        && strtolower(orderText($product['seller_account_status'] ?? '')) === 'active'
        && strtolower(orderText($product['seller_application_status'] ?? '')) === 'approved';
}
function orderValidateCsrf(): void
{
    $sessionToken = (string)($_SESSION['cart_csrf'] ?? '');
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    if ($sessionToken === '' || $submittedToken === ''
        || !hash_equals($sessionToken, $submittedToken)) {
        throw new RuntimeException('Session expired. Refresh the page and try again.');
    }
}

function orderBuyerName(): string
{
    $name = orderText(
        $_SESSION['user_name']
        ?? $_SESSION['buyer_name']
        ?? ''
    );
    return $name !== '' ? $name : 'Buyer';
}
/** Validate selected seller-defined add-ons and snapshot their names, prices, and text. */
function orderResolveCustomizations(mysqli $conn, int $productId, $choices): array
{
    if (!is_array($choices) || array_values($choices) !== $choices || count($choices) > 5) {
        throw new RuntimeException('Review the selected customizations and try again.');
    }
    if (!$choices) return ['extra_cents' => 0, 'snapshot' => '', 'labels' => []];

    $selected = [];
    foreach ($choices as $choice) {
        if (!is_array($choice) || !isset($choice['option_id'])
            || !filter_var($choice['option_id'], FILTER_VALIDATE_INT)
            || !is_string($choice['text'] ?? '')) {
            throw new RuntimeException('A selected customization is invalid. Please review it.');
        }
        $optionId = (int)$choice['option_id'];
        if ($optionId < 1 || isset($selected[$optionId])) {
            throw new RuntimeException('Duplicate or invalid customization selected.');
        }
        $text = orderText($choice['text']);
        if (orderTextLength($text) > 100 || preg_match('/[\r\n]/u', $text)) {
            throw new RuntimeException('Customization details must be one line, up to 100 characters.');
        }
        $selected[$optionId] = $text;
    }

    $stmt = $conn->prepare('SELECT option_id, option_name, additional_price, requires_text
        FROM product_customization_options
        WHERE product_id = ? AND is_active = 1
        ORDER BY sort_order, option_id FOR UPDATE');
    if (!$stmt) throw new RuntimeException('Unable to validate product customizations.');
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $stmt->bind_result($dbOptionId, $dbOptionName, $dbPrice, $dbRequiresText);
    $extraCents = 0;
    $labels = [];
    while ($stmt->fetch()) {
        $id = (int)$dbOptionId;
        if (!array_key_exists($id, $selected)) continue;
        $price = (float)$dbPrice;
        if (!is_finite($price) || $price < 0 || $price > 99999999.99) {
            $stmt->close();
            throw new RuntimeException('A customization price is currently invalid.');
        }
        $text = $selected[$id];
        if ((int)$dbRequiresText === 1 && $text === '') {
            $stmt->close();
            throw new RuntimeException('Please enter the required customization details.');
        }
        if ((int)$dbRequiresText !== 1 && $text !== '') {
            $stmt->close();
            throw new RuntimeException('Unexpected details for a customization option.');
        }
        $name = trim(preg_replace('/[\r\n\t]+/u', ' ', (string)$dbOptionName) ?? '');
        if ($name === '') {
            $stmt->close();
            throw new RuntimeException('A selected customization is no longer available.');
        }
        $extraCents += (int)round($price * 100);
        $labels[] = $name . ' (+' . orderMoney($price) . '/unit)'
            . ($text !== '' ? ': ' . $text : '');
    }
    $stmt->close();
    if (count($labels) !== count($selected)) {
        throw new RuntimeException('Customizations have changed. Reopen Request Order and select again.');
    }
    return [
        'extra_cents' => $extraCents,
        'snapshot' => implode("\n", array_map(static function ($line) { return '- ' . $line; }, $labels)),
        'labels' => $labels,
    ];
}

function createCartOrders(
    mysqli $conn,
    int $buyerId,
    array $selectedItems,
    string $deliveryOption,
    string $paymentOption
): array {
    $normalized = [];
    foreach ($selectedItems as $selected) {
        if (!is_array($selected)
            || !filter_var($selected['cart_id'] ?? null, FILTER_VALIDATE_INT)
            || !filter_var($selected['quantity'] ?? null, FILTER_VALIDATE_INT)) {
            throw new RuntimeException('Refresh your cart and select valid items.');
        }
        $cartId = (int)$selected['cart_id'];
        $quantity = (int)$selected['quantity'];
        if ($cartId <= 0 || $quantity <= 0 || $quantity > 999 || isset($normalized[$cartId])) {
            throw new RuntimeException('Refresh your cart and select valid items.');
        }
        $normalized[$cartId] = [
            'cart_id' => $cartId,
            'quantity' => $quantity,
            'customizations' => $selected['customizations'] ?? [],
        ];
    }
    if (empty($normalized)) {
        throw new RuntimeException(
            'Select at least one valid cart item.'
        );
    }
    if (count($normalized) > 50) {
        throw new RuntimeException(
            'Too many cart items were selected at once.'
        );
    }
    ksort($normalized);
    $conn->begin_transaction();
    try {
        $lookup = $conn->prepare('
            SELECT
                c.cart_id,
                c.product_id,
                c.quantity,
                p.product_name,
                p.seller_id,
                p.cost,
                p.stock,
                p.reserved_stock,
                p.status,
                u.sellerOrNot,
                u.adminOrNot,
                u.account_status,
                COALESCE(sa.status, "none"),
                COALESCE(
                    NULLIF(TRIM(b.businessname), ""),
                    CONCAT(u.firstname, " ", u.lastname)
                )
            FROM cart c
            INNER JOIN products p ON p.product_id = c.product_id
            INNER JOIN users u ON u.id = p.seller_id
            LEFT JOIN seller_applications sa ON sa.application_id = (SELECT sa2.application_id FROM seller_applications sa2 WHERE sa2.user_id = u.id ORDER BY sa2.submitted_at DESC, sa2.application_id DESC LIMIT 1)
            LEFT JOIN businessinfo b ON b.user_id = u.id
            WHERE c.cart_id = ? AND c.buyer_id = ?
            LIMIT 1
            FOR UPDATE
        ');
        if (!$lookup) {
            throw new RuntimeException(
                'Unable to load the selected cart items.'
            );
        }
        $groups = [];
        $requestedByProduct = [];
        foreach ($normalized as $selected) {
            $cartId = (int)$selected['cart_id'];
            $requestedQuantity = (int)$selected['quantity'];
            $lookup->bind_param('ii', $cartId, $buyerId);
            $lookup->execute();
            $lookup->bind_result(
                $dbCartId,
                $dbProductId,
                $dbCartQuantity,
                $dbProductName,
                $dbSellerId,
                $dbCost,
                $dbStock,
                $dbReservedStock,
                $dbProductStatus,
                $dbSellerFlag,
                $dbAdminFlag,
                $dbSellerAccountStatus,
                $dbSellerApplicationStatus,
                $dbSellerName
            );
            $item = null;
            if ($lookup->fetch()) {
                $physicalStock = max(0, (int)$dbStock);
                $reservedStock = max(0, (int)$dbReservedStock);
                $item = [
                    'cart_id' => (int)$dbCartId,
                    'product_id' => (int)$dbProductId,
                    'cart_quantity' => max(
                        1,
                        (int)$dbCartQuantity
                    ),
                    'product_name' => (string)$dbProductName,
                    'seller_id' => (int)$dbSellerId,
                    'unit_price' => round(
                        (float)$dbCost,
                        2
                    ),
                    'available_stock' => max(
                        0,
                        $physicalStock - $reservedStock
                    ),
                    'product_status' =>
                        (string)$dbProductStatus,
                    'seller_flag' =>
                        (int)$dbSellerFlag,
                    'admin_flag' =>
                        (int)$dbAdminFlag,
                    'seller_account_status' =>
                        (string)$dbSellerAccountStatus,
                    'seller_application_status' =>
                        (string)$dbSellerApplicationStatus,
                    'seller_name' =>
                        orderText($dbSellerName)
                        ?: 'Seller',
                ];
            }
            $lookup->free_result();
            if (!$item) {
                throw new RuntimeException(
                    'One of the selected cart items no longer exists.'
                );
            }
            if ((int)$item['seller_id'] === $buyerId) {
                throw new RuntimeException(
                    'You cannot order your own product.'
                );
            }
            if (!orderSellerProductAvailable($item)) {
                throw new RuntimeException(
                    $item['product_name']
                    . ' is currently unavailable.'
                );
            }
            if (!is_finite((float)$item['unit_price']) || (float)$item['unit_price'] <= 0) {
                throw new RuntimeException($item['product_name'] . ' does not have a valid price.');
            }
            if ($requestedQuantity > (int)$item['cart_quantity']) {
                throw new RuntimeException(
                    'Refresh your cart before checking out '
                    . $item['product_name'] . '.'
                );
            }
            $productId = (int)$item['product_id'];
            $requestedByProduct[$productId] = ($requestedByProduct[$productId] ?? 0)
                + $requestedQuantity;
            if ($requestedByProduct[$productId] > (int)$item['available_stock']) {
                throw new RuntimeException(
                    'Only ' . (int)$item['available_stock']
                    . ' item(s) of '
                    . $item['product_name']
                    . ' are currently available.'
                );
            }
            $addons = orderResolveCustomizations($conn, $productId, $selected['customizations']);
            $baseCents = (int)round((float)$item['unit_price'] * 100);
            $unitCents = $baseCents + $addons['extra_cents'];
            if ($unitCents < 1 || $unitCents > 9999999999
                || $requestedQuantity > intdiv(9999999999, $unitCents)) {
                throw new RuntimeException('The selected item total is outside the supported price range.');
            }
            $baseUnitPrice = $baseCents / 100;
            $finalUnitPrice = $unitCents / 100;
            $sellerId = (int)$item['seller_id'];
            if (!isset($groups[$sellerId])) {
                $groups[$sellerId] = [
                    'seller_id' => $sellerId,
                    'seller_name' => $item['seller_name'],
                    'items' => [],
                    'total' => 0.0,
                ];
            }
            $subtotal = ($unitCents * $requestedQuantity) / 100;
            $groups[$sellerId]['items'][] = [
                'cart_id' => (int)$item['cart_id'],
                'product_id' =>
                    (int)$item['product_id'],
                'product_name' =>
                    $item['product_name'],
                'quantity' =>
                    $requestedQuantity,
                'cart_quantity' =>
                    (int)$item['cart_quantity'],
                'base_unit_price' => $baseUnitPrice,
                'add_on_unit_price' => $addons['extra_cents'] / 100,
                'unit_price' => $finalUnitPrice,
                'special_request' => $addons['snapshot'],
                'subtotal' => $subtotal,
            ];
            $groups[$sellerId]['total'] += $subtotal;
        }
        $lookup->close();
        if (empty($groups)) {
            throw new RuntimeException(
                'No valid cart items were selected.'
            );
        }
        $buyerName = orderBuyerName();
        $createdOrders = [];
        foreach ($groups as $group) {
            $sellerId = (int)$group['seller_id'];
            $sellerName = (string)$group['seller_name'];
            $orderTotal = round((float)$group['total'], 2);
            if ($orderTotal <= 0 || $orderTotal > 99999999.99) {
                throw new RuntimeException('The seller order total is outside the supported price range.');
            }
            $orderStmt = $conn->prepare('
                INSERT INTO orders
                    (
                        buyer_id,
                        seller_id,
                        total_amount,
                        order_status,
                        notes,
                        ordered_at
                    )
                VALUES
                    (?, ?, ?, "Pending", NULL, NOW())
            ');
            if (!$orderStmt) {
                throw new RuntimeException(
                    'Unable to create the cart order.'
                );
            }
            $orderStmt->bind_param(
                'iid',
                $buyerId,
                $sellerId,
                $orderTotal
            );
            if (!$orderStmt->execute()) {
                $orderStmt->close();
                throw new RuntimeException(
                    'Unable to create the cart order.'
                );
            }
            $orderId = (int)$orderStmt->insert_id;
            $orderStmt->close();
            $itemStmt = $conn->prepare('
                INSERT INTO order_items
                    (
                        order_id,
                        product_id,
                        quantity,
                        unit_price,
                        agreed_unit_price,
                        special_request,
                        subtotal
                    )
                VALUES
                    (?, ?, ?, ?, ?, NULLIF(?, \'\'), ?)
            ');
            if (!$itemStmt) {
                throw new RuntimeException(
                    'Unable to save the fixed-price cart items.'
                );
            }
            foreach ($group['items'] as $item) {
                $productId = (int)$item['product_id'];
                $quantity = (int)$item['quantity'];
                $unitPrice = (float)$item['unit_price'];
                $fixedPrice = $unitPrice;
                $subtotal = (float)$item['subtotal'];
                $customizationSnapshot = (string)$item['special_request'];
                $itemStmt->bind_param(
                    'iiiddsd',
                    $orderId,
                    $productId,
                    $quantity,
                    $unitPrice,
                    $fixedPrice,
                    $customizationSnapshot,
                    $subtotal
                );
                if (!$itemStmt->execute()) {
                    $itemStmt->close();
                    throw new RuntimeException(
                        'Unable to save the fixed-price cart items.'
                    );
                }
            }
            $itemStmt->close();
            $arrangementStmt = $conn->prepare('
                INSERT INTO order_arrangements
                    (
                        order_id,
                        fulfillment_method,
                        payment_option,
                        arrangement_status,
                        created_at,
                        updated_at
                    )
                VALUES
                    (?, ?, ?, "arranging", NOW(), NOW())
            ');
            if (!$arrangementStmt) {
                throw new RuntimeException(
                    'Unable to save the order options.'
                );
            }
            $arrangementStmt->bind_param(
                'iss',
                $orderId,
                $deliveryOption,
                $paymentOption
            );
            if (!$arrangementStmt->execute()) {
                $arrangementStmt->close();
                throw new RuntimeException(
                    'Unable to save the order options.'
                );
            }
            $arrangementStmt->close();
            $message =
                "New LokalCraft Order Request\n\n"
                . "Order #{$orderId}\n"
                . "Buyer: {$buyerName}\n"
                . "Seller: {$sellerName}\n\n"
                . "Items:\n";
            foreach ($group['items'] as $index => $item) {
                $message .=
                    ($index + 1) . '. '
                    . $item['product_name']
                    . ' x' . (int)$item['quantity']
                    . ' @ '
                    . orderMoney((float)$item['unit_price'])
                    . ' = '
                    . orderMoney((float)$item['subtotal'])
                    . "\n";
                if ($item['special_request'] !== '') {
                    $message .= '   Base: ' . orderMoney((float)$item['base_unit_price'])
                        . '; Add-ons: ' . orderMoney((float)$item['add_on_unit_price'])
                        . " per unit\n"
                        . (string)$item['special_request'] . "\n";
                }
            }
            $message .=
                "\nEstimated Product Total: "
                . orderMoney($orderTotal)
                . "\nDelivery Option: "
                . orderDeliveryLabel($deliveryOption)
                . "\nPayment Option: "
                . orderPaymentLabel($paymentOption)
                . "\nStatus: Pending — awaiting confirmation."
                . "\n\nProduct prices include any selected Quick Customizations. "
                . "Use Messages to confirm customization and fulfillment details. "
                . "The buyer may cancel and seller may reject before acceptance. "
                . "QR Ph payment becomes available only after acceptance.";
            $messageStmt = $conn->prepare('
                INSERT INTO messages
                    (
                        sender_id,
                        receiver_id,
                        order_id,
                        message_text,
                        is_read,
                        sent_at
                    )
                VALUES
                    (?, ?, ?, ?, 0, NOW())
            ');
            if (!$messageStmt) {
                throw new RuntimeException(
                    'Unable to open the seller conversation.'
                );
            }
            $messageStmt->bind_param(
                'iiis',
                $buyerId,
                $sellerId,
                $orderId,
                $message
            );
            if (!$messageStmt->execute()) {
                $messageStmt->close();
                throw new RuntimeException(
                    'Unable to open the seller conversation.'
                );
            }
            $messageStmt->close();
            foreach ($group['items'] as $item) {
                $cartId = (int)$item['cart_id'];
                $remainingQuantity =
                    (int)$item['cart_quantity']
                    - (int)$item['quantity'];
                if ($remainingQuantity <= 0) {
                    $cartStmt = $conn->prepare(
                        'DELETE FROM cart '
                        . 'WHERE cart_id = ? AND buyer_id = ?'
                    );
                    if (!$cartStmt) {
                        throw new RuntimeException(
                            'Unable to update your cart.'
                        );
                    }
                    $cartStmt->bind_param(
                        'ii',
                        $cartId,
                        $buyerId
                    );
                } else {
                    $cartStmt = $conn->prepare(
                        'UPDATE cart SET quantity = ? '
                        . 'WHERE cart_id = ? AND buyer_id = ?'
                    );
                    if (!$cartStmt) {
                        throw new RuntimeException(
                            'Unable to update your cart.'
                        );
                    }
                    $cartStmt->bind_param(
                        'iii',
                        $remainingQuantity,
                        $cartId,
                        $buyerId
                    );
                }
                if (!$cartStmt->execute()) {
                    $cartStmt->close();
                    throw new RuntimeException(
                        'Unable to update your cart.'
                    );
                }
                $cartStmt->close();
            }
            // Save one Seller-only notification with the completed order request.
            // This runs inside the existing order transaction, before commit.
            lokalcraftCreateNotification(
                $conn,
                $sellerId,
                'seller_new_order',
                'New Order Received',
                'Order #' . $orderId . ' from ' . $buyerName
                    . ' has been submitted. Review the request in Messages.',
                'order',
                $orderId,
                'seller_new_order_' . $orderId
            );
            $createdOrders[] = [
                'order_id' => $orderId,
                'seller_id' => $sellerId,
                'seller_name' => $sellerName,
                'total_amount' => $orderTotal,
                'item_count' => count($group['items']),
                'price_mode' => 'fixed',
            ];
        }
        $conn->commit();
        $redirect = count($createdOrders) === 1
            ? 'messages.php?'
                . http_build_query([
                    'seller_id' =>
                        $createdOrders[0]['seller_id'],
                    'order_id' =>
                        $createdOrders[0]['order_id'],
                ])
            : 'orders.php';
        return [
            'orders' => $createdOrders,
            'order_count' => count($createdOrders),
            'redirect' => $redirect,
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    orderJson(
        false,
        'Invalid request method.',
        [],
        405
    );
}
if ($buyerId <= 0) {
    orderJson(
        false,
        'Please log in as a buyer first.',
        ['redirect' => '../login.php'],
        401
    );
}
$source = strtolower(orderText($_POST['order_source'] ?? ''));
if ($source !== 'cart') {
    orderJson(false, 'Order requests must be submitted from your Cart.', [], 400);
}
try {
    orderValidateCsrf();
    $deliveryOption = orderText($_POST['delivery_option'] ?? '');
    $paymentOption = orderText($_POST['payment_option'] ?? '');
    orderValidateOptions($deliveryOption, $paymentOption);
    $selectedJson = (string)($_POST['selected_items'] ?? '');
    if (strlen($selectedJson) > 32768) {
        throw new RuntimeException('Too many cart items were selected at once.');
    }
    $selectedItems = json_decode($selectedJson, true);
    if (!is_array($selectedItems) || array_values($selectedItems) !== $selectedItems) {
        throw new RuntimeException('Select at least one cart item.');
    }
    $result = createCartOrders(
        $conn, $buyerId, $selectedItems, $deliveryOption, $paymentOption
    );
    $orderCount = (int)$result['order_count'];
    orderJson(true, $orderCount === 1
        ? 'Order request sent. Confirm the arrangement in Messages.'
        : $orderCount . ' seller order requests sent. Confirm arrangements in Messages.',
        $result);
} catch (RuntimeException $e) {
    error_log(
        'LokalCraft create order request: '
        . $e->getMessage()
    );
    orderJson(
        false,
        $e->getMessage(),
        [],
        400
    );
} catch (Throwable $e) {
    error_log(
        'LokalCraft create order server error: '
        . $e->getMessage()
    );
    orderJson(
        false,
        'Unable to create the order right now.',
        [],
        500
    );
}
