<?php
require_once __DIR__ . '/buyer_auth.php';
$activePage = 'cart';
$searchPlaceholder = 'Search your cart...';
if (empty($_SESSION['marketplace_csrf'])) {
    $_SESSION['marketplace_csrf'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['cart_csrf'])) {
    $_SESSION['cart_csrf'] = bin2hex(random_bytes(32));
}
function cartJsonResponse(
    bool $success,
    string $message = '',
    array $data = [],
    int $status = 200
): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        array_merge(
            ['success' => $success, 'message' => $message],
            $data
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}
function cartMoney(float $value): string
{
    return '₱' . number_format($value, 2);
}
function cartCategory(string $category): string
{
    $category = trim($category);
    return $category !== '' ? $category : 'Uncategorized';
}
function cartText($value): string
{
    return trim((string)$value);
}
function cartListingState(array $item): string
{
    $status = strtolower(cartText($item['product_status'] ?? $item['status'] ?? ''));
    $sellerReady = (int)($item['seller_flag'] ?? 0) === 1
        && (int)($item['admin_flag'] ?? 0) === 0
        && strtolower(cartText($item['seller_account_status'] ?? '')) === 'active'
        && strtolower(cartText($item['seller_application_status'] ?? '')) === 'approved';
    if (!$sellerReady || !in_array($status, ['available', 'out of stock'], true)) return 'unavailable';
    return $status === 'out of stock' || (int)($item['available_stock'] ?? 0) < 1 ? 'out_of_stock' : 'available';
}
function cartSellerProductAvailable(array $item): bool
{
    return strtolower(cartText($item['product_status'] ?? '')) === 'available'
        && max(0, (int)($item['available_stock'] ?? 0)) > 0
        && (int)($item['seller_flag'] ?? 0) === 1
        && (int)($item['admin_flag'] ?? 0) === 0
        && strtolower(cartText($item['seller_account_status'] ?? '')) === 'active'
        && strtolower(cartText($item['seller_application_status'] ?? '')) === 'approved';
}
function jsonResponse(bool $success, string $message, array $data = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function cleanText($value): string
{
    return trim((string)$value);
}
function marketplaceProjectFilePath(string $storedPath): ?string
{
    $storedPath = trim(str_replace('\\', '/', $storedPath));
    if ($storedPath === '' || str_contains($storedPath, '..') || str_starts_with($storedPath, '/') || preg_match('~^[a-z][a-z0-9+.-]*:~i', $storedPath)) {
        return null;
    }
    $root = realpath(__DIR__ . '/..');
    if ($root === false) return null;
    return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedPath);
}
function formatMoney(float $value): string
{
    return '₱' . number_format($value, 2);
}
function formatCategory(string $category): string
{
    $names = ['woodcraft'=>'Woodcraft','crochet'=>'Crochet','accessories'=>'Accessories','weaving'=>'Weaving','bamboo'=>'Bamboo Craft','pottery'=>'Pottery','handmade'=>'Handmade','others'=>'Others'];
    $value = trim($category);
    return $names[strtolower($value)] ?? ($value !== '' ? ucwords(str_replace('_', ' ', $value)) : 'Handmade');
}
function marketplaceReviewsReady(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $test = $conn->query("SHOW TABLES LIKE 'reviews'");
        $ready = $test && $test->num_rows > 0;
        if ($test) $test->free();
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}
function savedCanBuy(array $p, int $buyerId): bool
{
    return strtolower(trim((string)($p['status'] ?? ''))) === 'available'
        && strtolower(trim((string)($p['seller_account_status'] ?? ''))) === 'active'
        && (int)($p['seller_flag'] ?? 0) === 1
        && (int)($p['admin_flag'] ?? 0) === 0
        && strtolower(trim((string)($p['seller_application_status'] ?? ''))) === 'approved'
        && (int)($p['available_stock'] ?? 0) > 0
        && (int)($p['seller_id'] ?? 0) !== $buyerId;
}
function savedProductData(array $row, int $buyerId, bool $saved = true): array
{
    // Archived listings never expose media, seller, price or location in any JSON response.
    if (cartListingState($row) === 'unavailable') {
        return [
            'product_id' => (int)$row['product_id'],
            'product_name' => (string)$row['product_name'],
            'is_archived' => true,
            'is_unavailable' => true,
            'is_available' => false,
            'stock' => 0
        ];
    }
    $photo = trim((string)($row['product_photo'] ?? ''));
    $sellerName = trim((string)($row['firstname'] ?? '') . ' ' . (string)($row['lastname'] ?? '')) ?: 'Local Seller';
    $business = trim((string)($row['businessname'] ?? ''));
    $available = savedCanBuy($row, $buyerId);
    $stock = max(0, (int)($row['available_stock'] ?? 0));
    return [
        'product_id'=>(int)$row['product_id'],
        'product_photo'=>$photo !== '' && marketplaceProjectFilePath($photo) !== null ? '../' . $photo : '',
        'product_name'=>(string)$row['product_name'],
        'craft_category'=>formatCategory((string)($row['craft_category'] ?? '')),
        'description'=>(string)($row['description'] ?? ''),
        'cost'=>formatMoney((float)$row['cost']),
        'cost_value'=>(float)$row['cost'],
        'stock'=>$available ? $stock : 0,
        'available_stock'=>$stock,
        'is_available'=>$available,
        'is_out_of_stock'=>cartListingState($row) === 'out_of_stock',
        'seller_id'=>(int)$row['seller_id'],
        'seller_name'=>$business !== '' ? $business : $sellerName,
        'seller_business'=>$business,
        'seller_person'=>$sellerName,
        'barangay'=>(string)($row['barangay'] ?? ''),
        'city'=>(string)($row['city'] ?? ''),
        'avg_rating'=>(float)($row['avg_rating'] ?? 0),
        'review_count'=>(int)($row['review_count'] ?? 0),
        'is_saved'=>$saved
    ];
}
function savedDetailsQuery(mysqli $conn): string
{
    $reviewJoin = marketplaceReviewsReady($conn)
        ? 'LEFT JOIN (SELECT product_id, AVG(rating) AS avg_rating, COUNT(*) AS review_count FROM reviews GROUP BY product_id) rs ON rs.product_id = p.product_id'
        : '';
    $reviews = marketplaceReviewsReady($conn)
        ? 'COALESCE(rs.avg_rating,0) AS avg_rating, COALESCE(rs.review_count,0) AS review_count'
        : '0 AS avg_rating, 0 AS review_count';
    return 'SELECT p.product_id,p.seller_id,p.product_photo,p.product_name,p.craft_category,p.description,p.cost,p.stock,p.reserved_stock,p.status,'
       . 'GREATEST(p.stock-p.reserved_stock,0) AS available_stock,'
       . 'u.firstname,u.lastname,u.SellerOrNot AS seller_flag,u.AdminOrNot AS admin_flag,u.account_status AS seller_account_status,'
       . 'COALESCE(sa.status,\'none\') AS seller_application_status,b.businessname,l.barangay,l.city,' . $reviews
       . ' FROM products p INNER JOIN users u ON u.id=p.seller_id'
       . ' LEFT JOIN seller_applications sa ON sa.application_id=(SELECT sa2.application_id FROM seller_applications sa2 WHERE sa2.user_id=u.id ORDER BY sa2.submitted_at DESC, sa2.application_id DESC LIMIT 1)'
       . ' LEFT JOIN businessinfo b ON b.user_id=u.id'
       . ' LEFT JOIN locationinfo l ON l.user_id=u.id '
       . $reviewJoin;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['marketplace_action'])) {
    try {
        if (empty($buyerId)) jsonResponse(false, 'Please log in as a buyer first.', ['redirect'=>'../login.php'], 401);
        if (empty($_SESSION['marketplace_csrf']) || !hash_equals((string)$_SESSION['marketplace_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
            jsonResponse(false, 'Session expired. Refresh and try again.', [], 403);
        }
        $action = cleanText($_POST['marketplace_action']);
        $productId = (int)($_POST['product_id'] ?? 0);
        if ($action === 'load_cart_product') {
            $cartId = (int)($_POST['cart_id'] ?? 0);
            if ($cartId <= 0) jsonResponse(false, 'Invalid cart item.', [], 400);
            $sql = savedDetailsQuery($conn)
                . ' INNER JOIN cart c ON c.product_id=p.product_id WHERE c.cart_id=? AND c.buyer_id=? LIMIT 1';
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $cartId, $buyerId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) jsonResponse(false, 'Cart item is no longer available.', [], 404);
            $data = savedProductData($row, (int)$buyerId, false);
            $data['in_cart'] = true;
            $data['cart_id'] = $cartId;
            if (empty($data['is_archived'])) {
                $saved = $conn->prepare('SELECT saved_id FROM saved_products WHERE buyer_id=? AND product_id=? LIMIT 1');
                $id = (int)$row['product_id'];
                $saved->bind_param('ii', $buyerId, $id);
                $saved->execute();
                $data['is_saved'] = (bool)$saved->get_result()->fetch_assoc();
                $saved->close();
            }
            jsonResponse(true, 'Product loaded.', ['product'=>$data]);
        }
        if ($action === 'toggle_saved') {
            if ($productId <= 0) jsonResponse(false, 'Invalid product.', [], 400);
            $check = $conn->prepare('SELECT saved_id FROM saved_products WHERE buyer_id=? AND product_id=? LIMIT 1');
            $check->bind_param('ii', $buyerId, $productId);
            $check->execute();
            $found = $check->get_result()->fetch_assoc();
            $check->close();
            if ($found) {
                $stmt = $conn->prepare('DELETE FROM saved_products WHERE buyer_id=? AND product_id=?');
                $stmt->bind_param('ii', $buyerId, $productId);
                $stmt->execute();
                $stmt->close();
                jsonResponse(true, 'Removed from Saved.', ['saved'=>false]);
            }
            $exists = $conn->prepare('SELECT product_id FROM products WHERE product_id=? AND LOWER(TRIM(status)) NOT IN (\'archived\',\'archive\') LIMIT 1');
            $exists->bind_param('i', $productId);
            $exists->execute();
            $foundProduct = $exists->get_result()->fetch_assoc();
            $exists->close();
            if (!$foundProduct) jsonResponse(false, 'Product no longer exists.', [], 404);
            $insert = $conn->prepare('INSERT INTO saved_products (buyer_id,product_id,saved_at) VALUES (?,?,NOW())');
            $insert->bind_param('ii', $buyerId, $productId);
            $insert->execute();
            $insert->close();
            jsonResponse(true, 'Product saved.', ['saved'=>true]);
        }
        if ($action === 'check_availability') {
            if ($productId <= 0) jsonResponse(false, 'Invalid product.', [], 400);
            $stmt = $conn->prepare(savedDetailsQuery($conn) . ' WHERE p.product_id=? LIMIT 1');
            $stmt->bind_param('i', $productId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) jsonResponse(false, 'Product not found.', [], 404);
            $available = savedCanBuy($row, (int)$buyerId);
            jsonResponse(true, 'Availability checked.', ['available'=>$available, 'stock'=>$available ? max(0, (int)$row['available_stock']) : 0]);
        }
        if ($action === 'add_to_cart') {
            if ($productId <= 0) jsonResponse(false, 'Invalid product.', [], 400);
            $quantity = max(1, min(999, (int)($_POST['quantity'] ?? 1)));
            $conn->begin_transaction();
            try {
                $query = 'SELECT p.product_id,p.seller_id,p.product_name,p.stock,p.reserved_stock,p.status,'
                    . 'u.SellerOrNot AS seller_flag,u.AdminOrNot AS admin_flag,u.account_status AS seller_account_status,'
                    . 'COALESCE(sa.status,\'none\') AS seller_application_status '
                    . 'FROM products p INNER JOIN users u ON u.id=p.seller_id '
                    . 'LEFT JOIN seller_applications sa ON sa.application_id=(SELECT sa2.application_id FROM seller_applications sa2 WHERE sa2.user_id=u.id ORDER BY sa2.submitted_at DESC, sa2.application_id DESC LIMIT 1) WHERE p.product_id=? LIMIT 1 FOR UPDATE';
                $stmt = $conn->prepare($query);
                $stmt->bind_param('i', $productId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) throw new RuntimeException('Product not found.');
                $row['available_stock'] = max(0, (int)$row['stock']-(int)$row['reserved_stock']);
                if (!savedCanBuy($row, (int)$buyerId)) throw new RuntimeException('This product is currently unavailable.');
                $quantity = min($quantity, (int)$row['available_stock']);
                $check = $conn->prepare('SELECT cart_id,quantity FROM cart WHERE buyer_id=? AND product_id=? LIMIT 1 FOR UPDATE');
                $check->bind_param('ii', $buyerId, $productId);
                $check->execute();
                $existing = $check->get_result()->fetch_assoc();
                $check->close();
                if ($existing) {
                    // Never report an addition as successful when the cart is already at the stock limit.
                    if ((int)$existing['quantity'] >= min(999, (int)$row['available_stock'])) {
                        throw new RuntimeException('All available units are already in your cart.');
                    }
                    $newQuantity = min(999, (int)$row['available_stock'], max(1, (int)$existing['quantity']) + $quantity);
                    $stmt = $conn->prepare('UPDATE cart SET quantity=? WHERE cart_id=? AND buyer_id=?');
                    $cartId = (int)$existing['cart_id'];
                    $stmt->bind_param('iii', $newQuantity, $cartId, $buyerId);
                } else {
                    $newQuantity = $quantity;
                    $stmt = $conn->prepare('INSERT INTO cart (buyer_id,product_id,quantity,added_at) VALUES (?,?,?,NOW())');
                    $stmt->bind_param('iii', $buyerId, $productId, $newQuantity);
                }
                $stmt->execute();
                $stmt->close();
                $conn->commit();
                jsonResponse(true, 'Added to Cart.', ['cart_quantity'=>$newQuantity]);
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        }
        if ($action === 'load_store') {
            $sellerId = (int)($_POST['seller_id'] ?? 0);
            if ($sellerId <= 0) jsonResponse(false, 'Invalid seller.', [], 400);
            $stmt = $conn->prepare('SELECT u.firstname,u.middlename,u.lastname,COALESCE(b.businessname,\'\'),COALESCE(b.craftcategory,\'\'),COALESCE(b.businessdescription,\'\'),COALESCE(l.barangay,\'\'),COALESCE(l.city,\'\'),u.account_status FROM users u LEFT JOIN businessinfo b ON b.user_id=u.id LEFT JOIN locationinfo l ON l.user_id=u.id WHERE u.id=? AND u.SellerOrNot=1 LIMIT 1');
            $stmt->bind_param('i', $sellerId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
            if (!$row) jsonResponse(false, 'Store profile is unavailable.', [], 404);
            [$fn,$mn,$ln,$bn,$category,$description,$barangay,$city,$accountStatus] = $row;
            $shop = [
                'seller_id'=>$sellerId,
                'seller_name'=>trim(preg_replace('/\s+/u',' ',trim($fn.' '.$mn.' '.$ln)) ?? ''),
                'businessname'=>(string)$bn,'craftcategory'=>(string)$category,'description'=>(string)$description,
                'barangay'=>(string)$barangay,'city'=>(string)$city,
                'is_active'=>strtolower(trim((string)$accountStatus)) === 'active'
            ];
            // Store-wide rating: use every review across this seller's products,
            // not an unweighted average of the product averages.
            $shopReviewCount = 0;
            $shopAverageRating = 0.0;
            if (marketplaceReviewsReady($conn)) {
                $shopReviewStmt = $conn->prepare('SELECT COUNT(*), COALESCE(AVG(r.rating), 0) FROM reviews r INNER JOIN products p ON p.product_id = r.product_id WHERE p.seller_id = ?');
                if ($shopReviewStmt) {
                    try {
                        $shopReviewStmt->bind_param('i', $sellerId);
                        $shopReviewStmt->execute();
                        $shopReviewStmt->bind_result($shopReviewCount, $shopAverageRating);
                        $shopReviewStmt->fetch();
                    } finally {
                        $shopReviewStmt->close();
                    }
                }
            }
            $shop['review_count'] = (int)$shopReviewCount;
            $shop['avg_rating'] = $shopReviewCount > 0 ? round((float)$shopAverageRating, 1) : 0.0;
            $sql = savedDetailsQuery($conn) . ' WHERE p.seller_id=? AND LOWER(TRIM(p.status)) IN (\'available\',\'out of stock\') AND LOWER(TRIM(u.account_status))=\'active\' AND u.SellerOrNot=1 AND u.AdminOrNot=0 ORDER BY p.created_at DESC,p.product_id DESC';
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $sellerId);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $stmt = $conn->prepare('SELECT product_id FROM saved_products WHERE buyer_id=?');
            $stmt->bind_param('i', $buyerId);
            $stmt->execute();
            $savedIds = [];
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $savedRow) {
                $savedIds[(int)$savedRow['product_id']] = true;
            }
            $stmt->close();
            $items = [];
            foreach ($rows as $item) {
                $items[] = savedProductData($item, (int)$buyerId, !empty($savedIds[(int)$item['product_id']]));
            }
            jsonResponse(true, 'Store loaded.', ['shop'=>$shop,'products'=>$items]);
        }
        if ($action === 'load_reviews') {
            if ($productId <= 0) jsonResponse(false, 'Invalid product.', [], 400);
            $check = $conn->prepare('SELECT product_id FROM products WHERE product_id=? AND LOWER(TRIM(status)) NOT IN (\'archived\',\'archive\') LIMIT 1');
            $check->bind_param('i', $productId);
            $check->execute();
            $row = $check->get_result()->fetch_assoc();
            $check->close();
            if (!$row) jsonResponse(false, 'Product not found.', [], 404);
            $offset = max(0, min(100000, (int)($_POST['offset'] ?? 0)));
            $limit = $offset === 0 ? 2 : 10;
            if (!marketplaceReviewsReady($conn)) jsonResponse(true, 'Reviews loaded.', ['average_rating'=>0,'review_count'=>0,'reviews'=>[],'has_more'=>false,'next_offset'=>0]);
            $stmt = $conn->prepare('SELECT COUNT(*),COALESCE(AVG(rating),0) FROM reviews WHERE product_id=?');
            $stmt->bind_param('i', $productId);
            $stmt->execute();
            $stmt->bind_result($count,$average);
            $stmt->fetch();
            $stmt->close();
            $stmt = $conn->prepare('SELECT r.rating,r.review_text,r.created_at,u.firstname,u.lastname FROM reviews r INNER JOIN users u ON u.id=r.buyer_id WHERE r.product_id=? ORDER BY r.created_at DESC,r.review_id DESC LIMIT ? OFFSET ?');
            $stmt->bind_param('iii', $productId,$limit,$offset);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $reviews = [];
            foreach ($rows as $r) {
                $first = trim((string)$r['firstname']);
                $last = trim((string)$r['lastname']);
                $initial = $last !== '' ? (function_exists('mb_substr') ? mb_substr($last,0,1,'UTF-8') : substr($last,0,1)) : '';
                $reviews[] = ['buyer_name'=>($first !== '' ? $first : 'Buyer') . ($initial !== '' ? ' '.strtoupper($initial).'.' : ''),
                    'rating'=>max(1,min(5,(int)$r['rating'])),'review_text'=>(string)($r['review_text']??''),'created_at'=>(string)$r['created_at']];
            }
            $next = $offset + count($reviews);
            jsonResponse(true, 'Reviews loaded.', ['average_rating'=>round((float)$average,1),'review_count'=>(int)$count,'reviews'=>$reviews,'has_more'=>$next<(int)$count,'next_offset'=>$next]);
        }
        jsonResponse(false, 'Unsupported Saved action.', [], 400);
    } catch (RuntimeException $e) {
        error_log('LokalCraft Saved action: ' . $e->getMessage());
        $safeMessages = ['Product not found.', 'This product is currently unavailable.', 'All available units are already in your cart.'];
        $publicMessage = in_array($e->getMessage(), $safeMessages, true)
            ? $e->getMessage() : 'Unable to complete the request. Please try again.';
        jsonResponse(false, $publicMessage, [], 400);
    } catch (Throwable $e) {
        error_log('LokalCraft Saved server: ' . $e->getMessage());
        jsonResponse(false, 'Unable to complete the request. Please try again.', [], 500);
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cart_action'])) {
    try {
        $sessionToken = (string)($_SESSION['cart_csrf'] ?? '');
        $postedToken = (string)($_POST['csrf_token'] ?? '');
        if (
            $sessionToken === ''
            || $postedToken === ''
            || !hash_equals($sessionToken, $postedToken)
        ) {
            cartJsonResponse(false, 'Session expired. Refresh the page and try again.', [], 403);
        }
        $action = cartText($_POST['cart_action'] ?? '');
        if ($action === 'load_customizations') {
            $ids = json_decode((string)($_POST['cart_ids'] ?? ''), true);
            if (!is_array($ids) || array_values($ids) !== $ids
                || count($ids) < 1 || count($ids) > 50) {
                cartJsonResponse(false, 'Select up to 50 cart products.', [], 422);
            }
            $cartIds = [];
            foreach ($ids as $id) {
                if (!(is_int($id) || (is_string($id) && ctype_digit($id)))
                    || (int)$id <= 0 || isset($cartIds[(int)$id])) {
                    cartJsonResponse(false, 'Refresh your Cart and select valid products.', [], 422);
                }
                $cartIds[(int)$id] = (int)$id;
            }
            $cartIds = array_values($cartIds);
            $placeholders = implode(',', array_fill(0, count($cartIds), '?'));
            $sql = 'SELECT c.cart_id, o.option_id, o.option_name, o.additional_price, o.requires_text
                FROM cart c
                LEFT JOIN product_customization_options o
                    ON o.product_id = c.product_id AND o.is_active = 1
                WHERE c.buyer_id = ? AND c.cart_id IN (' . $placeholders . ')
                ORDER BY c.cart_id, o.sort_order, o.option_id';
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new RuntimeException('Unable to load customization options.');
            $types = 'i' . str_repeat('i', count($cartIds));
            $stmt->bind_param($types, $buyerId, ...$cartIds);
            $stmt->execute();
            $stmt->bind_result($dbCartId, $dbOptionId, $dbName, $dbPrice, $dbNeedsText);
            $options = [];
            $foundIds = [];
            foreach ($cartIds as $id) $options[(string)$id] = [];
            while ($stmt->fetch()) {
                $foundIds[(int)$dbCartId] = true;
                if ((int)$dbOptionId <= 0) continue;
                $options[(string)$dbCartId][] = [
                    'option_id' => (int)$dbOptionId,
                    'name' => (string)$dbName,
                    'price' => round((float)$dbPrice, 2),
                    'requires_text' => (int)$dbNeedsText === 1,
                ];
            }
            $stmt->close();
            if (count($foundIds) !== count($cartIds)) {
                cartJsonResponse(false, 'Cart changed. Refresh and try again.', [], 409);
            }
            cartJsonResponse(true, 'Customization options loaded.', ['options' => $options]);
        }
        if ($action === 'bulk_edit') {
            $operation = cartText($_POST['bulk_operation'] ?? '');
            $ids = json_decode((string)($_POST['cart_ids'] ?? ''), true);
            if (!in_array($operation, ['remove', 'save'], true) || !is_array($ids)
                || count($ids) < 1 || count($ids) > 100) {
                cartJsonResponse(false, 'Select up to 100 cart items first.', [], 422);
            }
            $selectedIds = [];
            foreach ($ids as $id) {
                if (!(is_int($id) || (is_string($id) && ctype_digit($id))) || (int)$id < 1) {
                    cartJsonResponse(false, 'Invalid cart selection.', [], 422);
                }
                $selectedIds[(int)$id] = (int)$id;
            }
            if (count($selectedIds) !== count($ids)) {
                cartJsonResponse(false, 'Duplicate cart items in selection.', [], 422);
            }
            $selectedIds = array_values($selectedIds);
            $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
            $conn->begin_transaction();
            try {
                $sql = 'SELECT c.cart_id, c.product_id FROM cart c INNER JOIN products p ON p.product_id=c.product_id '
                    . 'WHERE c.buyer_id=? AND c.cart_id IN (' . $placeholders . ') FOR UPDATE';
                $lookup = $conn->prepare($sql);
                $types = 'i' . str_repeat('i', count($selectedIds));
                $lookup->bind_param($types, $buyerId, ...$selectedIds);
                $lookup->execute();
                $rows = $lookup->get_result()->fetch_all(MYSQLI_ASSOC);
                $lookup->close();
                if (count($rows) !== count($selectedIds)) {
                    throw new DomainException('Some cart items have changed. Refresh your cart and try again.');
                }
                if ($operation === 'save') {
                    $save = $conn->prepare('INSERT INTO saved_products (buyer_id, product_id, saved_at) '
                        . 'SELECT ?, ?, NOW() WHERE NOT EXISTS '
                        . '(SELECT 1 FROM saved_products WHERE buyer_id=? AND product_id=?)');
                    foreach ($rows as $row) {
                        $productId = (int)$row['product_id'];
                        $save->bind_param('iiii', $buyerId, $productId, $buyerId, $productId);
                        $save->execute();
                    }
                    $save->close();
                }
                $delete = $conn->prepare('DELETE FROM cart WHERE buyer_id=? AND cart_id IN (' . $placeholders . ')');
                $delete->bind_param($types, $buyerId, ...$selectedIds);
                $delete->execute();
                if ($delete->affected_rows !== count($selectedIds)) {
                    throw new DomainException('Cart changed while updating. Refresh and try again.');
                }
                $delete->close();
                $conn->commit();
                cartJsonResponse(true, $operation === 'save' ? 'Moved to Saved.' : 'Items removed.',
                    ['cart_ids'=>$selectedIds, 'count'=>count($selectedIds)]);
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        }
        if ($action === 'update_quantity') {
            $cartId = (int)($_POST['cart_id'] ?? 0);
            $rawQuantity = $_POST['quantity'] ?? null;
            if ($cartId <= 0) {
                cartJsonResponse(false, 'Invalid cart item.', [], 400);
            }
            // create_order.php allows at most 999 units per selected cart item.
            if (!is_string($rawQuantity) || !preg_match('/^[1-9][0-9]{0,2}$/D', $rawQuantity)) {
                cartJsonResponse(false, 'Enter a quantity from 1 to 999.', [], 422);
            }
            $quantity = (int)$rawQuantity;
            // Serialize concurrent edits to the same cart row on MySQL/InnoDB.
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare('
                    SELECT
                        c.cart_id,
                        p.product_id,
                        p.product_name,
                        p.stock,
                        p.reserved_stock,
                        p.status,
                        u.sellerOrNot,
                        u.adminOrNot,
                        u.account_status,
                        COALESCE(sa.status, "none")
                    FROM cart c
                    INNER JOIN products p ON p.product_id = c.product_id
                    INNER JOIN users u ON u.id = p.seller_id
                    LEFT JOIN seller_applications sa ON sa.application_id = (SELECT sa2.application_id FROM seller_applications sa2 WHERE sa2.user_id = u.id ORDER BY sa2.submitted_at DESC, sa2.application_id DESC LIMIT 1)
                    WHERE c.cart_id = ? AND c.buyer_id = ?
                    LIMIT 1 FOR UPDATE
                ');
                if (!$stmt) throw new RuntimeException('Unable to check the cart item.');
                $stmt->bind_param('ii', $cartId, $buyerId);
                $stmt->execute();
                $stmt->bind_result(
                    $dbCartId,
                    $dbProductId,
                    $dbProductName,
                    $dbStock,
                    $dbReservedStock,
                    $dbProductStatus,
                    $dbSellerFlag,
                    $dbAdminFlag,
                    $dbSellerAccountStatus,
                    $dbSellerApplicationStatus
                );
                $item = null;
                if ($stmt->fetch()) {
                    $physicalStock = max(0, (int)$dbStock);
                    $reservedStock = max(0, (int)$dbReservedStock);
                    $item = [
                        'cart_id' => (int)$dbCartId,
                        'product_id' => (int)$dbProductId,
                        'product_name' => (string)$dbProductName,
                        'available_stock' => max(0, $physicalStock - $reservedStock),
                        'product_status' => (string)$dbProductStatus,
                        'seller_flag' => (int)$dbSellerFlag,
                        'admin_flag' => (int)$dbAdminFlag,
                        'seller_account_status' => (string)$dbSellerAccountStatus,
                        'seller_application_status' => (string)$dbSellerApplicationStatus,
                    ];
                }
                $stmt->close();
                if (!$item) throw new DomainException('Cart item not found.');
                if (!cartSellerProductAvailable($item)) {
                    throw new DomainException(cartListingState($item) === 'out_of_stock'
                        ? 'Out of stock. Please save this item for later.'
                        : 'This product is unavailable. You can remove it in Edit mode.');
                }
                $quantity = min($quantity, (int)$item['available_stock']);
                $update = $conn->prepare('UPDATE cart SET quantity = ? WHERE cart_id = ? AND buyer_id = ?');
                if (!$update) throw new RuntimeException('Unable to update the cart quantity.');
                $update->bind_param('iii', $quantity, $cartId, $buyerId);
                $update->execute();
                $update->close();
                $conn->commit();
            } catch (DomainException $error) {
                $conn->rollback();
                cartJsonResponse(false, $error->getMessage(), [], 409);
            } catch (Throwable $error) {
                $conn->rollback();
                throw $error;
            }
            cartJsonResponse(true, 'Cart quantity updated.', ['quantity' => $quantity]);
        }
        if ($action === 'remove_item') {
            $cartId = (int)($_POST['cart_id'] ?? 0);
            if ($cartId <= 0) {
                cartJsonResponse(false, 'Invalid cart item.', [], 400);
            }
            $stmt = $conn->prepare(
                'DELETE FROM cart WHERE cart_id = ? AND buyer_id = ?'
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to remove the cart item.');
            }
            $stmt->bind_param('ii', $cartId, $buyerId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Unable to remove the cart item.');
            }
            $stmt->close();
            cartJsonResponse(true, 'Item removed from cart.');
        }
        cartJsonResponse(false, 'Unsupported cart action.', [], 400);
    } catch (RuntimeException $e) {
        error_log('LokalCraft cart request: ' . $e->getMessage());
        cartJsonResponse(false, $e->getMessage(), [], 400);
    } catch (Throwable $e) {
        error_log('LokalCraft cart server error: ' . $e->getMessage());
        cartJsonResponse(
            false,
            'Unable to process your cart request right now.',
            [],
            500
        );
    }
}
$cartItems = [];
$errorMessage = '';
$cartCount = 0;
try {
    $stmt = $conn->prepare('
        SELECT
            c.cart_id,
            c.product_id,
            c.quantity,
            c.added_at,
            p.product_photo,
            p.product_name,
            p.craft_category,
            p.description,
            p.cost,
            p.stock,
            p.reserved_stock,
            p.status,
            u.id,
            u.firstname,
            u.lastname,
            u.sellerOrNot,
            u.adminOrNot,
            u.account_status,
            COALESCE(sa.status, "none"),
            b.businessname,
            l.barangay,
            l.city
        FROM cart c
        INNER JOIN products p ON p.product_id = c.product_id
        INNER JOIN users u ON u.id = p.seller_id
        LEFT JOIN seller_applications sa ON sa.application_id = (SELECT sa2.application_id FROM seller_applications sa2 WHERE sa2.user_id = u.id ORDER BY sa2.submitted_at DESC, sa2.application_id DESC LIMIT 1)
        LEFT JOIN businessinfo b ON b.user_id = u.id
        LEFT JOIN locationinfo l ON l.user_id = u.id
        WHERE c.buyer_id = ?
        ORDER BY c.added_at DESC, c.cart_id DESC
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to load your cart.');
    }
    $stmt->bind_param('i', $buyerId);
    $stmt->execute();
    $stmt->bind_result(
        $dbCartId,
        $dbProductId,
        $dbCartQuantity,
        $dbAddedAt,
        $dbProductPhoto,
        $dbProductName,
        $dbCraftCategory,
        $dbDescription,
        $dbCost,
        $dbStock,
        $dbReservedStock,
        $dbProductStatus,
        $dbSellerId,
        $dbFirstName,
        $dbLastName,
        $dbSellerFlag,
        $dbAdminFlag,
        $dbSellerAccountStatus,
        $dbSellerApplicationStatus,
        $dbBusinessName,
        $dbBarangay,
        $dbCity
    );
    while ($stmt->fetch()) {
        $physicalStock = max(0, (int)$dbStock);
        $reservedStock = max(0, (int)$dbReservedStock);
        $availableStock = max(0, $physicalStock - $reservedStock);
        $quantity = max(1, (int)$dbCartQuantity);
        $availabilityData = [
            'product_status' => (string)$dbProductStatus,
            'available_stock' => $availableStock,
            'seller_flag' => (int)$dbSellerFlag,
            'admin_flag' => (int)$dbAdminFlag,
            'seller_account_status' => (string)$dbSellerAccountStatus,
            'seller_application_status' => (string)$dbSellerApplicationStatus,
        ];
        $listingState = cartListingState($availabilityData);
        $isArchived = $listingState === 'unavailable';
        $isOutOfStock = $listingState === 'out_of_stock';
        $isAvailable = $listingState === 'available' && cartSellerProductAvailable($availabilityData);
        $quantityWithinStock = $isAvailable && $quantity <= $availableStock;
        $sellerPersonName = trim(
            (string)$dbFirstName . ' ' . (string)$dbLastName
        ) ?: 'Local Seller';
        $sellerName = cartText($dbBusinessName) !== ''
            ? (string)$dbBusinessName
            : $sellerPersonName;
        $location = implode(', ', array_filter([
            cartText($dbBarangay),
            cartText($dbCity),
        ]));
        $cartItems[] = [
            'cart_id' => (int)$dbCartId,
            'product_id' => (int)$dbProductId,
            'product_photo' => $isArchived || marketplaceProjectFilePath(cartText($dbProductPhoto)) === null ? '' : cartText($dbProductPhoto),
            'product_name' => (string)$dbProductName,
            'craft_category' => $isArchived ? '' : cartCategory((string)($dbCraftCategory ?? '')),
            'description' => $isArchived ? '' : cartText($dbDescription),
            'cost' => $isArchived ? 0.0 : (float)$dbCost,
            'quantity' => $quantity,
            'stock' => $availableStock,
            'physical_stock' => $physicalStock,
            'reserved_stock' => $reservedStock,
            'status' => (string)$dbProductStatus,
            'seller_id' => $isArchived ? 0 : (int)$dbSellerId,
            'seller_name' => $isArchived ? '' : $sellerName,
            'location' => $isArchived ? '' : $location,
            'is_archived' => $isArchived,
            'is_out_of_stock' => $isOutOfStock,
            'is_available' => $isAvailable,
            'quantity_within_stock' => $quantityWithinStock,
        ];
        $cartCount += $quantity;
    }
    $stmt->close();
} catch (Throwable $e) {
    error_log('LokalCraft cart load error: ' . $e->getMessage());
    $errorMessage = 'Unable to load your cart right now.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>LokalCraft | Cart</title>
  <style>
/* Marketplace detail, store and photo viewer styling (same as Saved / For You) */
    :root {
      --bg: #f3f3f3;
      --text: #2d2d2d;
      --muted: #6e6e73;
      --line: #e2e1de;
      --panel: #ffffff;
      --soft: #f8f7f6;
      --orange: #ea8a3a;
      --orange-soft: #fff0e2;
      --green: #1b9a5e;
      --green-soft: #effaf3;
      --red-soft: #fff1f1;
      --red: #c94141;
    }
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }
    body {
      font-family: "Segoe UI", Arial, sans-serif;
      background: var(--bg);
      color: var(--text);
      min-height: 100vh;
    }
    .page {
      display: flex;
      min-height: 100vh;
      background: #f4f3f1;
    }
    .content {
      flex: 1;
      min-width: 0;
      padding: 18px 26px 32px;
      position: relative;
    }
    .marketplace-wrap {
      max-width: 1180px;
      margin: 0 auto;
    }
    .heading-row {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 18px;
      margin-bottom: 18px;
    }
    .page-title {
      font-size: 2.05rem;
      font-weight: 800;
      letter-spacing: -0.7px;
      color: #2c2c2c;
      margin: 0;
    }
    .page-subtitle {
      margin-top: 4px;
      color: var(--muted);
      font-size: 0.95rem;
    }
    .product-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 18px;
    }
    .product-card {
      display: flex;
      flex-direction: column;
      min-width: 0;
      background: rgba(255,255,255,0.8);
      border: 1px solid #e8e4df;
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 10px 20px rgba(18,13,9,0.04);
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .product-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 16px 24px rgba(18,13,9,0.08);
    }
    .product-image-wrap {
      position: relative;
      width: 100%;
      aspect-ratio: 1 / 0.82;
      background: #eae4df;
      overflow: hidden;
    }
    .product-image {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
    .placeholder-image {
      width: 100%;
      height: 100%;
      display: grid;
      place-items: center;
      color: #97908a;
      background: linear-gradient(135deg, #ece7e2, #e2dbd4);
      font-size: 2rem;
    }
    .product-save-btn {
      position: absolute;
      top: 10px;
      left: 10px;
      z-index: 3;
      width: 36px;
      height: 36px;
      display: grid;
      place-items: center;
      border: 1px solid rgba(255,255,255,.9);
      border-radius: 50%;
      background: rgba(255,255,255,.94);
      color: #5d5d5d;
      font-size: 1.15rem;
      cursor: pointer;
      box-shadow: 0 4px 12px rgba(0,0,0,.08);
      transition: transform .18s ease, background .18s ease, color .18s ease;
    }
    .product-save-btn:hover {
      transform: scale(1.06);
      background: #fff;
    }
    .product-save-btn.is-saved {
      color: #d96d19;
      background: #fff4e9;
    }
    .product-details-save.is-saved {
      border-color: #e7a365;
      background: #fff4e9;
      color: #bd6318;
    }
    .stock-tag {
      position: absolute;
      top: 10px;
      right: 10px;
      padding: 5px 8px;
      border-radius: 999px;
      background: rgba(255,255,255,0.94);
      color: var(--green);
      font-size: 0.66rem;
      font-weight: 800;
      border: 1px solid rgba(255,255,255,0.9);
    }
    .product-body {
      padding: 14px 14px 15px;
      display: flex;
      flex-direction: column;
      flex: 1;
    }
    .category-badge {
      align-self: flex-start;
      display: inline-flex;
      padding: 5px 8px;
      border-radius: 999px;
      background: var(--orange-soft);
      color: #c86d1d;
      font-size: 0.65rem;
      font-weight: 800;
      margin-bottom: 8px;
    }
    .product-name {
      font-size: 1rem;
      font-weight: 800;
      color: #2f2f2f;
      line-height: 1.3;
      margin-bottom: 7px;
    }
    .product-price {
      font-size: 1.02rem;
      font-weight: 800;
      color: #2d2d2d;
      margin-bottom: 9px;
    }
    .product-rating {
      display: flex;
      align-items: center;
      gap: 5px;
      min-height: 18px;
      margin: -2px 0 9px;
      color: #7b746e;
      font-size: .65rem;
    }
    .product-rating-stars,
    .product-details-rating-stars,
    .review-card-stars {
      color: #e3a22e;
      letter-spacing: .04em;
    }
    .product-rating strong {
      color: #4b4540;
      font-size: .66rem;
    }
    .product-description {
      color: #707070;
      font-size: 0.73rem;
      line-height: 1.45;
      margin-bottom: 10px;
      min-height: 2.9em;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }
    .seller-tag,
    .location-tag {
      color: #585858;
      font-size: 0.73rem;
      line-height: 1.4;
    }
    .seller-tag {
      margin-top: auto;
      padding-top: 8px;
      font-weight: 700;
    }
    .location-tag {
      margin-top: 3px;
      color: #737373;
    }
    .empty-state {
      padding: 70px 20px;
      border: 1px solid var(--line);
      border-radius: 14px;
      background: rgba(255,255,255,0.55);
      text-align: center;
    }
    .empty-state h2 {
      margin-bottom: 8px;
      font-size: 1.15rem;
    }
    .empty-state p {
      color: var(--muted);
      font-size: 0.8rem;
    }
    .error-message {
      margin-bottom: 18px;
      padding: 11px 13px;
      border: 1px solid #efc0c0;
      border-radius: 8px;
      background: var(--red-soft);
      color: var(--red);
      font-size: 0.78rem;
    }
    .product-card {
      cursor: pointer;
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
    .product-details-buy {
      border: 1px solid #b85d00;
      background: #fff4e7;
      color: #a95300;
    }
    .product-details-chat:hover,
    .product-details-buy:hover {
      background: #f4f6f8;
    }
    .modal {
      position: fixed;
      inset: 0;
      z-index: 115000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .modal.open {
      display: flex;
    }
    .modal-backdrop {
      position: absolute;
      inset: 0;
      background: rgba(18,20,24,.55);
      backdrop-filter: blur(2px);
      -webkit-backdrop-filter: blur(2px);
    }
    .checkout-panel {
      position: relative;
      z-index: 1;
      width: min(540px, 100%);
      max-height: calc(100vh - 40px);
      overflow-y: auto;
      border-radius: 14px;
      background: #fff;
      box-shadow: 0 24px 70px rgba(0,0,0,.25);
    }
    .checkout-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
      padding: 18px 19px 14px;
      border-bottom: 1px solid #ece7e2;
    }
    .checkout-header h2 {
      font-size: 1rem;
      font-weight: 800;
    }
    .checkout-header p {
      margin-top: 4px;
      color: #777;
      font-size: .67rem;
    }
    .close-modal {
      width: 32px;
      height: 32px;
      border: 1px solid #ded9d4;
      border-radius: 50%;
      background: #fff;
      color: #555;
      cursor: pointer;
      font-size: 1rem;
    }
    .checkout-body {
      padding: 17px 19px;
    }
    .checkout-product {
      display: grid;
      grid-template-columns: 72px 1fr;
      gap: 11px;
      align-items: center;
      padding: 10px;
      border: 1px solid #ece7e2;
      border-radius: 10px;
      background: #faf9f8;
      margin-bottom: 15px;
    }
    .checkout-product-image {
      width: 72px;
      height: 64px;
      overflow: hidden;
      border-radius: 8px;
      background: #ebe6e1;
    }
    .checkout-product-image img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
    .checkout-product-placeholder {
      width: 100%;
      height: 100%;
      display: grid;
      place-items: center;
      background: linear-gradient(135deg, #ece7e2, #e2dbd4);
      color: #8e877f;
      font-size: 1.4rem;
    }
    .checkout-product-name {
      font-size: .76rem;
      font-weight: 800;
    }
    .checkout-product-meta {
      margin-top: 3px;
      color: #747474;
      font-size: .63rem;
    }
    .checkout-section {
      margin-top: 14px;
    }
    .checkout-label {
      display: block;
      margin-bottom: 6px;
      font-size: .68rem;
      font-weight: 800;
      color: #3e3e3e;
    }
    .checkout-input,
    .checkout-textarea {
      width: 100%;
      border: 1px solid #ded9d4;
      border-radius: 8px;
      padding: 9px 10px;
      background: #fff;
      color: #333;
      outline: none;
      font: inherit;
      font-size: .7rem;
    }
    .checkout-input:focus,
    .checkout-textarea:focus {
      border-color: #e3a35d;
    }
    .checkout-textarea {
      min-height: 76px;
      resize: vertical;
    }
    .checkout-select {
      width: 100%;
      min-height: 40px;
      border: 1px solid #ded9d4;
      border-radius: 8px;
      padding: 9px 10px;
      background: #fff;
      color: #333;
      outline: none;
      font: inherit;
      font-size: .7rem;
      cursor: pointer;
    }
    .checkout-select:focus {
      border-color: #e3a35d;
    }
    .checkout-select:disabled {
      background: #f5f3f1;
      color: #9a938d;
      cursor: not-allowed;
    }
    .checkout-hint {
      margin-top: 6px;
      color: #7a746f;
      font-size: .63rem;
      line-height: 1.45;
    }
    .checkout-footer {
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      padding: 13px 19px 17px;
      border-top: 1px solid #ece7e2;
    }
    .checkout-btn {
      min-height: 36px;
      padding: 8px 13px;
      border-radius: 8px;
      border: 1px solid #ddd8d3;
      background: #fff;
      color: #4d4d4d;
      font-size: .68rem;
      font-weight: 800;
      cursor: pointer;
    }
    .checkout-btn.primary {
      border-color: #ea8a3a;
      background: #ea8a3a;
      color: #fff;
    }
    .checkout-btn.primary:hover:not(:disabled) {
      background: #d87522;
    }
    .checkout-btn:hover:not(:disabled) {
      background: #f7f4f1;
    }
    .checkout-btn:disabled {
      opacity: .55;
      cursor: wait;
    }
    .checkout-modal-status {
      padding: 0 19px 13px;
      margin-top: -4px;
    }
    .marketplace-action-modal {
      position: fixed;
      inset: 0;
      z-index: 110000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 18px;
    }
    .marketplace-action-modal.is-open {
      display: flex;
    }
    .marketplace-action-backdrop {
      position: absolute;
      inset: 0;
      background: rgba(17, 24, 39, .55);
      backdrop-filter: blur(2px);
      -webkit-backdrop-filter: blur(2px);
    }
    .marketplace-action-panel {
      position: relative;
      z-index: 1;
      width: min(560px, 100%);
      max-height: calc(100vh - 36px);
      overflow-y: auto;
      padding: 25px;
      border: 1px solid #e1e5e9;
      border-radius: 16px;
      background: #fff;
      box-shadow: 0 25px 70px rgba(0,0,0,.22);
    }
    .marketplace-action-close {
      position: absolute;
      top: 12px;
      right: 12px;
      width: 34px;
      height: 34px;
      border: 1px solid #e1e5e9;
      border-radius: 50%;
      background: #fff;
      color: #505860;
      font-size: 20px;
      cursor: pointer;
    }
    .marketplace-action-kicker {
      margin-bottom: 5px;
      color: #c66e16;
      font-size: .68rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .7px;
    }
    .marketplace-action-panel h2 {
      padding-right: 40px;
      color: #111827;
      font-size: 1.35rem;
      line-height: 1.25;
      font-weight: 800;
    }
    .marketplace-action-subtitle {
      margin: 5px 0 18px;
      color: #69717b;
      font-size: .74rem;
      line-height: 1.5;
    }
    .marketplace-action-view label,
    .checkout-field label {
      display: block;
      margin-bottom: 6px;
      color: #303740;
      font-size: .73rem;
      font-weight: 800;
    }
    .checkout-field label span {
      color: #8a9199;
      font-weight: 500;
    }
    #chatMessageText,
    #checkoutNotes,
    #checkoutQuantity {
      width: 100%;
      border: 1px solid #dfe3e7;
      border-radius: 9px;
      background: #f9fafb;
      color: #2f353b;
      font: inherit;
      font-size: .78rem;
      outline: none;
    }
    #chatMessageText:focus,
    #checkoutNotes:focus,
    #checkoutQuantity:focus {
      border-color: #e3a35d;
      background: #fff;
    }
    #chatMessageText,
    #checkoutNotes {
      resize: vertical;
      min-height: 100px;
      padding: 10px 11px;
    }
    .chat-product-summary,
    .checkout-summary {
      margin-bottom: 14px;
      padding: 11px 12px;
      border: 1px solid #e9e2d9;
      border-radius: 10px;
      background: #fcfaf7;
    }
    .chat-product-summary strong,
    .checkout-summary strong {
      display: block;
      color: #272d33;
      font-size: .8rem;
    }
    .chat-product-summary span,
    .checkout-summary span {
      display: block;
      margin-top: 3px;
      color: #777f87;
      font-size: .69rem;
    }
    .chat-quick-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 7px;
      margin: 9px 0 14px;
    }
    .chat-quick-actions button {
      border: 1px solid #e0e4e8;
      border-radius: 999px;
      background: #fff;
      color: #555e66;
      padding: 7px 10px;
      font-size: .68rem;
      cursor: pointer;
    }
    .chat-quick-actions button:hover {
      background: #f7f8f9;
    }
    .checkout-field-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 12px;
      margin-bottom: 13px;
    }
    .checkout-field {
      margin-bottom: 12px;
    }
    .quantity-control {
      display: grid;
      grid-template-columns: 34px 1fr 34px;
      gap: 5px;
    }
    .quantity-control button {
      border: 1px solid #dfe3e7;
      border-radius: 8px;
      background: #f7f8f9;
      color: #40474e;
      font-size: 1rem;
      cursor: pointer;
    }
    #checkoutQuantity {
      text-align: center;
      min-height: 38px;
      padding: 8px;
    }
    .tooltip-label-row {
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .tooltip-title-row {
      display: flex;
      align-items: center;
      gap: 7px;
      padding-right: 42px;
    }
    .info-tooltip-trigger {
      width: 18px;
      height: 18px;
      flex: 0 0 18px;
      display: inline-grid;
      place-items: center;
      padding: 0;
      border: 1px solid #d8dde3;
      border-radius: 50%;
      background: #fff;
      color: #707880;
      font: inherit;
      font-size: .65rem;
      font-weight: 800;
      line-height: 1;
      cursor: help;
      vertical-align: middle;
      transition: background .16s ease, border-color .16s ease, color .16s ease;
    }
    .info-tooltip-trigger:hover,
    .info-tooltip-trigger:focus-visible,
    .info-tooltip-trigger[aria-expanded="true"] {
      border-color: #e9a35d;
      background: #fff5e9;
      color: #bd6519;
      outline: none;
    }
    .responsive-tooltip {
      position: fixed;
      top: 0;
      left: 0;
      z-index: 150000;
      width: max-content;
      max-width: min(290px, calc(100vw - 24px));
      padding: 9px 11px;
      border: 1px solid rgba(255,255,255,.08);
      border-radius: 8px;
      background: #262a2f;
      color: #fff;
      box-shadow: 0 10px 26px rgba(0,0,0,.2);
      font-size: .68rem;
      font-weight: 500;
      line-height: 1.45;
      text-align: left;
      overflow-wrap: anywhere;
      pointer-events: none;
      opacity: 0;
      visibility: hidden;
      transform: translateY(2px);
      transition: opacity .13s ease, transform .13s ease, visibility .13s ease;
    }
    .responsive-tooltip.is-visible {
      opacity: 1;
      visibility: visible;
      transform: translateY(0);
    }
    .responsive-tooltip::after {
      content: '';
      position: absolute;
      width: 8px;
      height: 8px;
      background: #262a2f;
      transform: rotate(45deg);
    }
    .responsive-tooltip[data-placement="top"]::after {
      left: var(--arrow-x, 50%);
      bottom: -4px;
      margin-left: -4px;
    }
    .responsive-tooltip[data-placement="bottom"]::after {
      left: var(--arrow-x, 50%);
      top: -4px;
      margin-left: -4px;
    }
    .responsive-tooltip[data-placement="left"]::after {
      top: var(--arrow-y, 50%);
      right: -4px;
      margin-top: -4px;
    }
    .responsive-tooltip[data-placement="right"]::after {
      top: var(--arrow-y, 50%);
      left: -4px;
      margin-top: -4px;
    }
    .checkout-total {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 15px;
      margin-top: 17px;
      padding-top: 14px;
      border-top: 1px solid #ece7e2;
      font-size: .78rem;
      font-weight: 800;
    }
    .checkout-total strong {
      color: #d87522;
      font-size: 1rem;
    }
    .marketplace-primary-action,
    .marketplace-secondary-action {
      width: 100%;
      min-height: 40px;
      border-radius: 8px;
      padding: 9px 13px;
      font-size: .74rem;
      font-weight: 800;
      cursor: pointer;
    }
    .marketplace-primary-action {
      border: 1px solid #de790e;
      background: #e9851d;
      color: #fff;
    }
    .marketplace-primary-action:hover {
      background: #d8750f;
    }
    .marketplace-secondary-action {
      margin-bottom: 8px;
      border: 1px solid #dfe3e7;
      background: #fff;
      color: #363d44;
    }
    .marketplace-action-status {
      min-height: 20px;
      margin-top: 8px;
      color: #c94141;
      font-size: .7rem;
      line-height: 1.4;
    }
    .checkout-success {
      text-align: center;
      padding: 8px 6px 2px;
    }
    .checkout-success-icon {
      width: 48px;
      height: 48px;
      margin: 0 auto 12px;
      display: grid;
      place-items: center;
      border-radius: 50%;
      background: #e9f8ef;
      color: #1b9a5e;
      font-size: 1.3rem;
      font-weight: 900;
    }
    .checkout-success h3 {
      margin-bottom: 5px;
      color: #22272d;
      font-size: 1.05rem;
    }
    .checkout-success p {
      margin-bottom: 16px;
      color: #66707a;
      font-size: .74rem;
      line-height: 1.6;
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
    body.modal-open {
      overflow: hidden;
    }
    @media (max-width: 1100px) {
      .product-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }
    }
    @media (max-width: 900px) {
      .product-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }
    @media (max-width: 840px) {
      .page {
        flex-direction: column;
      }
      .content {
        padding: 18px;
      }
      .heading-row {
        flex-direction: column;
      }
    }
    @media (max-width: 560px) {
      .marketplace-wrap {
        width: 100%;
      }
      .product-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
      }
      .product-card {
        border-radius: 12px;
      }
      .product-image-wrap {
        aspect-ratio: 1 / 1;
      }
      .product-save-btn {
        top: 7px;
        left: 7px;
        width: 30px;
        height: 30px;
        font-size: .95rem;
      }
      .stock-tag {
        top: 7px;
        right: 7px;
        padding: 4px 6px;
        font-size: .56rem;
      }
      .product-body {
        padding: 10px;
      }
      .category-badge {
        padding: 4px 6px;
        margin-bottom: 6px;
        font-size: .56rem;
      }
      .product-name {
        margin-bottom: 5px;
        font-size: .82rem;
      }
      .product-price {
        margin-bottom: 6px;
        font-size: .88rem;
      }
      .product-description {
        display: none;
      }
      .seller-tag,
      .location-tag {
        font-size: .62rem;
      }
      .seller-tag {
        padding-top: 5px;
      }
    }
    @media (max-width: 560px) {
      .product-details-modal {
        padding: 10px;
      }
      .product-details-panel {
        max-height: calc(100vh - 20px);
        padding: 18px;
        border-radius: 12px;
      }
      .product-details-top {
        grid-template-columns: 1fr;
        gap: 17px;
      }
      .product-details-name {
        font-size: 1.25rem;
      }
      .product-details-actions {
        grid-template-columns: 1fr;
      }
      .seller-details-row {
        align-items: flex-start;
        flex-direction: column;
      }
      .seller-details-actions {
        width: 100%;
      }
      .seller-action-btn {
        flex: 1;
      }
      .checkout-footer {
        flex-direction: column-reverse;
      }
      .checkout-btn {
        width: 100%;
      }
      .checkout-input,
      .checkout-textarea,
      .checkout-select {
        font-size: 16px;
      }
    }
    .product-card {
      border-radius: 22px;
      border-color: #e7e0d9;
      box-shadow: 0 10px 28px rgba(47,31,19,.055);
    }
    .product-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 18px 36px rgba(47,31,19,.10);
    }
    .empty-state { border-radius: 22px; }
    .error-message { border-radius: 16px; }
    .product-details-panel,
    .checkout-panel,
    .marketplace-action-panel {
      border-radius: 24px;
    }
    .product-details-image-wrap,
    .product-reviews-card,
    .review-card,
    .seller-details-card,
    .checkout-product,
    .checkout-product-image,
    .checkout-summary,
    .responsive-tooltip,
    .marketplace-toast {
      border-radius: 16px;
    }
    .product-details-btn,
    .seller-action-btn,
    .checkout-input,
    .checkout-textarea,
    .checkout-select,
    .checkout-btn,
    .quantity-control button,
    .marketplace-secondary-action {
      border-radius: 12px;
    }
    @media (max-width: 560px) {
      .product-card { border-radius: 18px; }
      .product-details-panel,
      .checkout-panel,
      .marketplace-action-panel {
        border-radius: 20px;
      }
    }
/* Buyer Marketplace — shared LokalCraft cream/orange design and mobile-first dialogs. */
:root{--bg:#f8f7f5;--text:#342d27;--muted:#80756b;--line:#eae1d8;--panel:#fff;--soft:#fffaf5;--orange:#e98b40;--orange-soft:#fff1e4;--green:#278553}
body{background:#f8f7f5;color:#342d27}
.page>.content{padding:80px 26px 38px!important;min-width:0}
.marketplace-wrap{max-width:1220px;min-width:0}
.heading-row{margin-bottom:20px}.page-title{font-size:clamp(1.6rem,3.3vw,2.1rem);letter-spacing:-.04em;color:#382c22}.page-subtitle{max-width:690px;font-size:.83rem;line-height:1.5;color:#9a8a7b}
.product-grid{gap:16px}.product-card{border:1px solid #eae0d7;background:#fffdfa;border-radius:18px;box-shadow:0 8px 22px rgba(67,44,22,.045)}
.product-card:hover{box-shadow:0 11px 24px rgba(67,44,22,.09)}.product-image-wrap{background:#f3ede6}.product-name{color:#3f3329}.product-price{color:#b76624}.seller-tag{color:#71553b}
.product-save-btn{display:grid;place-items:center;font-size:20px}.product-save-btn.is-saved{color:#d36d23}
.product-details-modal,.lc-store-modal,.modal,.marketplace-action-modal{position:fixed!important;inset:0!important;width:100%!important;height:var(--lc-vh,100dvh)!important;min-width:0;box-sizing:border-box}
.product-details-modal{z-index:140000;padding:22px}.product-details-backdrop,.lc-store-backdrop{background:rgba(35,27,21,.57);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px)}
.product-details-panel{width:min(980px,100%);height:auto;max-height:calc(var(--lc-vh,100dvh) - 44px);border-radius:21px;border:1px solid #ebded2;background:#fffdfa;padding:27px;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;scrollbar-gutter:stable}
.product-details-top{gap:24px}.product-details-image-wrap{border-radius:17px;background:#f7f1ea}.product-details-name{color:#362b23;font-size:clamp(1.32rem,2.4vw,1.8rem)}.product-details-price{color:#bc702f}.product-details-category{background:#fff0df;color:#a8612b}.product-details-section-title,.product-reviews-title,.seller-details-title{color:#42352a}
.product-details-description,.product-details-stock{color:#77675c;font-size:.86rem}.product-details-close,.lc-store-close{display:grid;place-items:center;position:sticky;float:right;top:0;right:0;width:40px;height:40px;z-index:10;border-radius:50%;border:1px solid #ebdacf;background:#fffaf4;color:#795338;font-size:25px;cursor:pointer;box-shadow:0 3px 12px rgba(52,32,17,.12)}
.product-details-actions{grid-template-columns:minmax(0,1fr) minmax(0,1fr) 48px;gap:9px}.product-details-btn{display:inline-flex;justify-content:center;align-items:center;gap:7px;min-width:0;min-height:47px;padding:8px 12px;border-radius:12px;font-size:.81rem;font-weight:800}.product-details-btn svg{width:18px;height:18px;flex:0 0 18px}.product-details-cart{background:#fff6ed;color:#b86c32;border:1px solid #f1d1b2}.product-details-cart:hover{background:#fdebd8}.product-details-buy{background:#e98b40;color:#fff;border:1px solid #e98b40}.product-details-buy:hover{background:#cf722b}.product-details-save{display:grid;place-items:center;padding:0;border:1px solid #ead9c9;background:#fff;color:#947b66;transition:.15s}.product-details-save:hover,.product-details-save.is-saved{background:#fff0e1;border-color:#e7aa77;color:#ca6c2c}.product-details-save.is-saved svg{fill:currentColor}
.product-details-btn:disabled{opacity:.65;cursor:default}.product-details-btn.is-complete{background:#e9f8f1;color:#20744b;border-color:#b7dfc8}.product-reviews-card,.seller-details-card{border:1px solid #eee4d9;border-radius:16px;background:#fff;box-shadow:0 3px 12px rgba(67,44,22,.035)}.review-card{background:#fdfaf7;border:1px solid #f1e6da}.review-card-text{font-size:.82rem}.review-card-buyer{font-size:.77rem}.review-card-date{font-size:.7rem}
.lc-see-more{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:42px;margin-top:13px;padding:10px;border:1px solid #ebd8c5;border-radius:11px;background:#fff6ed;color:#a55f2f;font-size:.8rem;font-weight:800;cursor:pointer}.lc-see-more:hover{background:#ffebd8}.lc-see-more[hidden]{display:none!important}.lc-see-more:disabled{opacity:.55;cursor:wait}
.lc-store-name{border:0;padding:0;background:transparent;text-align:left;color:#664529;font-size:.83rem;font-weight:800;cursor:pointer;overflow-wrap:anywhere}.lc-store-name:hover{text-decoration:underline}.seller-details-person{min-width:0}.seller-details-person>div:last-child{min-width:0}.seller-details-actions{min-width:0}.seller-action-btn{border-radius:10px;border:1px solid #e6d7ca;background:#fffaf6;color:#9a602f;min-height:38px;font-size:.76rem}
.lc-store-modal{display:none;align-items:center;justify-content:center;padding:22px;z-index:130000}.lc-store-modal.is-open{display:flex}.lc-store-backdrop{position:absolute;inset:0}.lc-store-panel{position:relative;z-index:1;display:flex;flex-direction:column;width:min(850px,100%);max-height:calc(var(--lc-vh,100dvh) - 44px);height:min(830px,100%);min-width:0;border:1px solid #edddcb;border-radius:22px;background:#fffdfa;box-shadow:0 28px 80px rgba(29,19,12,.22);overflow:hidden}.lc-store-header{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 21px;border-bottom:1px solid #f0e7de;background:#fffaf5}.lc-store-heading{min-width:0}.lc-eyebrow{display:block;color:#b47543;font-size:.65rem;font-weight:850;letter-spacing:.14em}.lc-store-heading h2{font-size:clamp(1.2rem,3vw,1.55rem);margin-top:5px;line-height:1.15;color:#372b21;overflow-wrap:anywhere}.lc-store-close{float:none;position:relative;flex:0 0 40px}.lc-store-content{flex:1 1 auto;min-height:0;overflow-y:auto;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;padding:20px}.lc-store-summary{display:flex;align-items:center;gap:14px;padding:21px;border:1px solid #ead8c7;border-radius:16px;background:linear-gradient(116deg,#fffdfa,#ffeedb)}.lc-store-avatar{display:grid;place-items:center;flex:0 0 60px;width:60px;height:60px;border-radius:17px;border:3px solid #fff;background:linear-gradient(135deg,#f6ba80,#d88037);color:#fff;font-size:1.4rem;font-weight:800}.lc-store-summary-name{font-size:1.1rem;font-weight:850;color:#493225;overflow-wrap:anywhere}.lc-store-summary-meta{font-size:.8rem;color:#8e7764;margin-top:5px}.lc-store-details-toggle{max-width:190px;margin:12px 0 0}.lc-store-info{margin:14px 0 0}.lc-store-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.lc-store-fact{padding:14px;border:1px solid #f0e4da;border-radius:11px;background:#fff}.lc-store-fact.wide{grid-column:1/-1}.lc-store-fact small{display:block;margin-bottom:6px;color:#a08369;font-weight:800;font-size:.7rem}.lc-store-fact strong{display:block;color:#504338;font-size:.84rem;font-weight:700;line-height:1.45;white-space:pre-wrap;overflow-wrap:anywhere}.lc-store-products-heading{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:25px 0 12px}.lc-store-products-heading h3{font-size:1rem;color:#413122}.lc-store-products-heading span{font-size:.75rem;color:#987d67}.lc-store-products{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}.lc-store-product{min-width:0;display:flex;gap:12px;align-items:center;text-align:left;border:1px solid #eee2d7;border-radius:13px;padding:10px;background:#fff;cursor:pointer}.lc-store-product:hover{border-color:#ddaf82;background:#fffaf5}.lc-store-product-image{flex:0 0 83px;width:83px;height:83px;border-radius:10px;overflow:hidden;background:#f5ede5;display:grid;place-items:center;color:#c08353;font-size:1.5rem}.lc-store-product-image img{display:block;width:100%;height:100%;object-fit:cover}.lc-store-product-meta{min-width:0}.lc-store-product-meta strong{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;font-size:.86rem;color:#473a30}.lc-store-product-meta span{display:block;margin-top:5px;color:#b56b2d;font-weight:850;font-size:.88rem}.lc-store-product-meta small{display:block;margin-top:5px;color:#846f5c;font-size:.72rem}.lc-loading{padding:24px;text-align:center;font-size:.86rem;color:#887a6d;grid-column:1/-1}
.modal{z-index:150000;padding:16px}.checkout-panel{width:min(530px,100%);max-height:calc(var(--lc-vh,100dvh) - 32px);border-radius:20px;border:1px solid #eaded4;box-shadow:0 22px 65px rgba(40,25,13,.22);-webkit-overflow-scrolling:touch;overscroll-behavior:contain;scroll-padding:65px 0 110px;position:relative}.checkout-header{position:sticky;top:0;z-index:2;background:#fffbf7}.checkout-header h2{color:#433127;font-size:1.1rem}.checkout-body{padding:16px 19px}.checkout-section{margin-top:14px}.checkout-label{font-size:.79rem;color:#5b4735}.checkout-input,.checkout-textarea,.checkout-select,#chatMessageText{font-size:16px!important;min-height:46px;border:1px solid #e2d6c9;background:#fff;border-radius:11px;color:#47382b}.checkout-textarea{line-height:1.5;min-height:82px}.checkout-hint{font-size:.76rem;color:#987864}.checkout-total{font-size:.86rem}.checkout-btn{min-height:45px;font-size:.85rem;border-radius:11px}.checkout-btn.primary{background:#e98b40;border-color:#e98b40}.checkout-btn.primary:disabled{opacity:.65}.checkout-modal-status{padding:0 18px 15px;margin:0;min-height:0;color:#b34838;font-size:.78rem}.checkout-modal-status:empty{display:none}.lc-optional{color:#9b8a7b;font-size:.75rem;font-weight:550}.lc-char-counter{margin-top:5px;text-align:right;color:#9a8270;font-size:.73rem}.lc-char-counter.invalid{color:#bd4545}.lc-invalid{border-color:#d4655a!important;box-shadow:0 0 0 2px rgba(212,101,90,.09)!important}
.lc-order-sent{position:absolute;inset:0;z-index:7;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;padding:25px;text-align:center;background:#fffdfa}.lc-order-sent[hidden]{display:none!important}.lc-order-sent>svg{width:63px;height:63px;color:#269259;stroke-width:1.5}.lc-order-sent strong{font-size:1.3rem;color:#3b3028}.lc-order-sent span{max-width:280px;font-size:.91rem;line-height:1.55;color:#7e7066}.lc-order-sent button{min-height:46px;margin-top:7px;border:1px solid #e68b40;border-radius:11px;padding:11px 20px;background:#e98b40;color:#fff;font-weight:850;cursor:pointer}
.marketplace-action-modal{z-index:160000}.marketplace-action-panel{width:min(560px,100%);max-height:calc(var(--lc-vh,100dvh) - 32px);overscroll-behavior:contain;-webkit-overflow-scrolling:touch}.marketplace-primary-action{background:#e98b40;border-color:#e98b40}.marketplace-toast{z-index:180000;border:1px solid #d7bfaa;background:#3e332b;font-size:.82rem;border-radius:12px}.marketplace-toast.success{background:#2e6544}.marketplace-toast.error{background:#883e36}
body.modal-open{overflow:hidden;touch-action:pan-y}
@media(max-width:980px){.product-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:840px){.page>.content{padding:108px 15px 28px!important}.product-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}.page-title{font-size:1.65rem}.page-subtitle{font-size:.78rem}}
@media(max-width:560px){
 .page>.content{padding:104px 12px 26px!important}.product-grid{gap:10px}.product-card{border-radius:13px}.product-body{padding:11px}.product-name{font-size:.88rem}.product-price{font-size:.91rem}.category-badge{font-size:.61rem}.seller-tag,.location-tag{font-size:.69rem}
 .product-details-modal,.lc-store-modal{padding:0!important}.product-details-panel{width:100vw;height:var(--lc-vh,100dvh);max-height:var(--lc-vh,100dvh);border:0;border-radius:0!important;padding:max(18px,env(safe-area-inset-top)) 15px calc(25px + env(safe-area-inset-bottom));overflow-y:auto}.product-details-close{position:sticky;top:0;margin-right:0;width:40px;height:40px}.product-details-top{display:grid;grid-template-columns:1fr;gap:13px}.product-details-image-wrap{aspect-ratio:1/0.8;border-radius:14px}.product-details-name{font-size:1.35rem;padding-right:0;clear:both}.product-details-actions{grid-template-columns:minmax(0,1fr) minmax(0,1fr) 43px;gap:6px}.product-details-btn{font-size:.74rem;padding:8px 6px;min-height:45px;border-radius:10px}.product-details-btn svg{height:15px;width:15px;flex-basis:15px}.seller-details-card{margin-top:17px;padding:15px}.seller-details-row{flex-direction:column;align-items:stretch}.seller-details-actions{width:100%}.seller-action-btn{flex:1}.product-reviews-card{padding:15px}.review-card{padding:11px}
 .lc-store-panel{height:var(--lc-vh,100dvh);max-height:var(--lc-vh,100dvh);width:100vw;border:0;border-radius:0}.lc-store-header{padding:calc(15px + env(safe-area-inset-top)) 15px 13px}.lc-store-content{padding:15px 14px calc(30px + env(safe-area-inset-bottom))}.lc-store-summary{padding:15px}.lc-store-avatar{flex-basis:52px;width:52px;height:52px}.lc-store-facts{grid-template-columns:1fr}.lc-store-fact.wide{grid-column:auto}.lc-store-products{grid-template-columns:1fr}.lc-store-product-image{flex-basis:75px;width:75px;height:75px}
 .modal{padding:0!important}.checkout-panel{height:var(--lc-vh,100dvh);max-height:var(--lc-vh,100dvh);width:100%;border:0;border-radius:0!important;overflow-y:auto;padding-bottom:env(safe-area-inset-bottom)}.checkout-header{padding:calc(15px + env(safe-area-inset-top)) 15px 12px}.checkout-footer{display:grid;grid-template-columns:1fr 1fr;gap:9px;position:sticky;bottom:0;background:#fffdfa;padding:12px 15px calc(12px + env(safe-area-inset-bottom));z-index:2}.checkout-footer .checkout-btn{width:100%;font-size:.79rem}.checkout-body{padding:15px}.checkout-textarea{min-height:86px}.checkout-product{grid-template-columns:64px 1fr}.checkout-product-image{width:64px;height:64px}
 .marketplace-action-modal{padding:0!important}.marketplace-action-panel{width:100%;height:var(--lc-vh,100dvh);max-height:var(--lc-vh,100dvh);border:0;border-radius:0!important;overflow-y:auto;padding:max(20px,env(safe-area-inset-top)) 18px 25px}
}
@media(max-width:350px){.product-details-btn{font-size:.68rem}.product-details-actions{grid-template-columns:minmax(0,1fr) minmax(0,1fr) 40px}.lc-store-avatar{flex-basis:45px;width:45px;height:45px}.lc-store-summary{gap:9px}}
@media(prefers-reduced-motion:reduce){.product-card,.product-details-btn,.lc-see-more,.marketplace-toast{transition:none!important}}
/* Final interaction refinements. */
.product-details-modal,.lc-store-modal,.modal,.marketplace-action-modal{top:var(--lc-vtop,0px)!important;bottom:auto!important}
.lc-card-store{border:0;background:transparent;color:#9d5a27;font:inherit;font-weight:800;cursor:pointer;text-align:left;padding:0;line-height:inherit}
.lc-card-store:hover{text-decoration:underline}.lc-card-store:focus-visible,.product-card:focus-visible,.lc-store-product:focus-visible,.lc-store-close:focus-visible{outline:3px solid #e4a56e;outline-offset:2px}
.lc-spinner{display:inline-block;width:15px;height:15px;flex:0 0 15px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:lcspin .7s linear infinite}
@keyframes lcspin{to{transform:rotate(360deg)}}
@media(prefers-reduced-motion:reduce){.lc-spinner{animation-duration:1.4s}}
.lc-store-avatar-button{border:1px solid #f4dec7;cursor:pointer;color:#af6e39;background:#fff1e0}
.lc-store-avatar-button:hover{background:#ffe3c5}
/* Buyer Marketplace polish — profile-style identity, independent scroll areas, floating controls. */
:root{--lc-brand:#ea8a3a;--lc-brand-deep:#b8662c;--lc-cream:#fff5e9;--lc-surface:#fffdfb;--lc-hairline:#eadfd5;--lc-ink:#382f29;--lc-shadow:0 24px 65px rgba(43,28,16,.2)}
body{background:#f8f7f5;color:var(--lc-ink)}
.product-grid{row-gap:18px}.product-card{border-radius:19px;background:#fff;border-color:var(--lc-hairline)}
.product-card:focus-visible{outline:3px solid #e8a96e;outline-offset:2px}
.product-details-panel,.lc-store-panel,.checkout-panel{background:var(--lc-surface);border:1px solid #ead9c9;border-radius:22px;box-shadow:var(--lc-shadow)}
/* The close controls are absolutely positioned siblings of scrolling content. */
.lc-floating-close,.product-details-close,.lc-store-close,.close-modal{position:absolute!important;float:none!important;z-index:35!important;top:14px!important;right:14px!important;margin:0!important;display:grid;place-items:center;flex:none;width:40px!important;height:40px!important;padding:0!important;border:1px solid #eddcc9;border-radius:50%!important;background:rgba(255,253,249,.97);color:#835c3d;box-shadow:0 5px 18px rgba(59,35,15,.11);cursor:pointer;transition:transform .14s,background .14s}
.lc-floating-close svg,.product-details-close svg,.lc-store-close svg,.close-modal svg{width:19px;height:19px;pointer-events:none}
.lc-floating-close:hover{background:#fff0df;transform:scale(1.04)}.lc-floating-close:focus-visible{outline:3px solid #e9a264;outline-offset:2px}
/* Desktop is a split view: only right side scrolls, image stays visible. */
.product-details-modal{padding:20px}
.product-details-panel{position:relative;display:flex;flex-direction:column;overflow:hidden;width:min(1120px,100%);height:min(820px,calc(var(--lc-vh,100dvh) - 40px));max-height:calc(var(--lc-vh,100dvh) - 40px);padding:0;scrollbar-gutter:auto}
.lc-product-layout{display:grid;grid-template-columns:minmax(0,46%) minmax(0,54%);width:100%;height:100%;min-height:0;min-width:0;overflow:hidden}
.lc-product-media{min-width:0;min-height:0;padding:20px;display:flex;flex-direction:column;align-items:stretch;justify-content:center;gap:12px;background:#f8f1e9;border-right:1px solid #eee1d4}
.product-details-image-wrap{width:100%;aspect-ratio:1/1;max-height:calc(var(--lc-vh,100dvh) - 165px);border:1px solid #ebddd0;border-radius:18px;background:#fffaf5;overflow:hidden;display:grid;place-items:center}
.product-details-image{object-fit:contain;width:100%;height:100%}
.product-details-placeholder{min-height:230px}
.lc-image-caption{display:flex;align-items:center;justify-content:center;gap:7px;color:#987f6a;font-size:12px;font-weight:700}.lc-image-caption svg{width:16px;height:16px}
.lc-product-info-scroll{height:100%;min-height:0;min-width:0;padding:29px 28px 30px;overflow-y:auto;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;scroll-padding-top:30px;scroll-padding-bottom:50px}
.product-details-top{display:block}.lc-product-main{min-width:0}.product-details-name{padding-right:43px;font-size:clamp(1.35rem,2.2vw,1.9rem);color:#3b2d23;line-height:1.28;margin:0 0 8px}
.product-details-price{font-size:1.45rem;color:#ba6829}.product-details-rating{color:#77675b}.product-details-description{font-size:.87rem;line-height:1.7;color:#66594e}.product-details-section-title{font-size:.92rem}
.product-details-actions{grid-template-columns:minmax(0,1fr) minmax(0,1fr) 48px;gap:9px;margin-top:6px}
.product-details-btn{min-height:48px;border-radius:12px;font-size:.79rem;min-width:0}.product-details-buy svg{width:17px;height:17px;flex:0 0 17px}
.seller-details-card,.product-reviews-card{margin-top:20px;padding:17px;border:1px solid #eddfd2;border-radius:16px;background:#fff;box-shadow:none}
.seller-details-title,.product-reviews-title{display:flex;align-items:center;gap:9px;margin:0 0 15px;color:#47372c;font-size:.9rem;font-weight:800}
.seller-details-title svg,.product-reviews-title svg{width:18px;height:18px;color:#bd783c;flex:none}
.seller-details-row{align-items:center;gap:10px}.seller-details-person{flex:1 1 auto;min-width:0}
.seller-avatar{width:44px;height:44px;border:2px solid #fff;border-radius:13px;background:linear-gradient(135deg,#f7ba7c,#e88a3e);box-shadow:0 4px 12px rgba(177,98,34,.14);color:#fff}
.seller-avatar svg{width:22px;height:22px}.seller-details-name{font-size:.84rem}.seller-details-location{font-size:.73rem;line-height:1.5;color:#877262}
.seller-details-actions{flex:0 0 auto;min-width:0;display:flex;gap:8px;flex-wrap:wrap}.seller-action-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:41px;padding:9px 11px;border:1px solid #e8d8c8;background:#fff9f2;color:#985a29;border-radius:11px;font-weight:750;font-size:.77rem}
.seller-action-btn svg{width:16px;height:16px;flex:none}.seller-action-btn:hover{background:#ffeddc;border-color:#eac19b}.lc-view-store{color:#fff;background:#e98b40;border-color:#e98b40}.lc-view-store:hover{background:#d5792f;color:#fff}
.product-reviews-summary,.product-reviews-title{color:#5b4b3e}.lc-see-more{border-radius:12px}
/* Store mirrors the cream profile banner, with a full-width details expander. */
.lc-store-modal{padding:20px}.lc-store-panel{position:relative;width:min(1040px,100%);height:min(860px,calc(var(--lc-vh,100dvh) - 40px));max-height:calc(var(--lc-vh,100dvh) - 40px);overflow:hidden}
.lc-store-header{display:flex;align-items:center;min-height:85px;padding:19px 66px 16px 25px;border-bottom:1px solid #efe3d8;background:#fffdfb}
.lc-store-heading h2{margin-top:5px;font-size:1.35rem;color:#3e3025}.lc-eyebrow{color:#ac7345;font-size:.68rem;letter-spacing:.12em}
.lc-store-content{padding:21px 25px 30px;scroll-padding-top:15px}
.lc-store-summary{position:relative;display:grid;grid-template-columns:70px minmax(0,1fr) auto;grid-template-areas:"avatar details status";align-items:center;column-gap:16px;row-gap:8px;min-width:0;min-height:147px;padding:24px;border:1px solid #ecdac8;border-radius:19px;background:linear-gradient(115deg,#fffdfa 0%,#fff8ef 52%,#fce9d4 100%);overflow:hidden;box-shadow:0 8px 18px rgba(65,40,18,.045)}
.lc-store-summary::before{position:absolute;z-index:0;content:"";width:220px;height:220px;top:-154px;right:-63px;border:1px solid rgba(215,149,94,.23);border-radius:50%;box-shadow:0 0 0 42px rgba(251,224,190,.18),0 0 0 88px rgba(253,231,207,.17);pointer-events:none}
.lc-store-summary>*{position:relative;z-index:1;min-width:0}.lc-store-avatar{grid-area:avatar;flex:0 0 70px;width:70px;height:70px;align-self:start;justify-self:start;margin-top:3px;border:4px solid #fff;border-radius:20px;box-shadow:0 6px 18px rgba(171,97,34,.16);font-size:1.3rem}
.lc-store-profile-details{grid-area:details;min-width:0;width:100%;overflow-wrap:anywhere;flex:1 1 auto}.lc-store-role{display:block;color:#b77946;font-weight:850;font-size:10px;letter-spacing:.13em;margin-bottom:5px}
.lc-store-summary-name{max-width:100%;font-size:clamp(1.12rem,2.3vw,1.55rem);line-height:1.25;color:#4b3525;font-weight:850;overflow-wrap:anywhere;word-break:break-word}
.lc-store-summary-meta{max-width:100%;font-size:.79rem;color:#887465;line-height:1.5;margin-top:6px;overflow-wrap:anywhere;word-break:break-word}
.lc-store-active{grid-area:status;display:inline-flex;align-items:center;justify-content:center;justify-self:end;gap:7px;white-space:nowrap;flex:0 0 auto;padding:9px 12px;border:1px solid #d0e9d9;border-radius:999px;background:rgba(255,255,255,.91);color:#237a4b;font-size:.72rem;font-weight:800}
.lc-store-active-dot{width:8px;height:8px;border-radius:50%;background:#51a071;box-shadow:0 0 0 4px #e5f5eb}
.lc-store-details-toggle{display:flex;width:100%;max-width:none!important;min-height:49px;margin:14px 0 0;align-items:center;justify-content:space-between;padding:12px 16px;border:1px solid #eddfd0;border-radius:12px;background:#fff;color:#805130;font-size:.83rem;font-weight:800;text-align:left}
.lc-store-details-toggle:hover{background:#fff7ef}.lc-store-details-toggle svg{width:18px;height:18px;transition:transform .2s ease}.lc-store-details-toggle[aria-expanded="true"] svg{transform:rotate(180deg)}
.lc-store-info{margin:12px 0 0}.lc-store-fact{padding:16px;display:flex;align-items:flex-start;gap:10px;flex-direction:column;border-radius:12px;background:#fffdfb}
.lc-store-fact small{font-size:.73rem;color:#a08368}.lc-store-fact strong{font-size:.85rem;color:#5c4b3d}
.lc-store-products-heading{margin:25px 0 14px}.lc-store-products-heading h3{font-size:1.05rem;color:#433428}
.lc-store-products{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.lc-store-product{position:relative;min-width:0;display:flex;flex-direction:column;align-items:stretch;gap:0;min-height:0;padding:0;border:1px solid #eadfd5;border-radius:16px;background:#fff;text-align:left;overflow:hidden;box-shadow:0 6px 17px rgba(43,27,15,.045);transition:border-color .16s,transform .16s,box-shadow .16s}
.lc-store-product:hover{border-color:#d9ac85;background:#fff;transform:translateY(-2px);box-shadow:0 12px 26px rgba(43,27,15,.1)}
.lc-store-product-image{flex:none;width:100%;height:auto;aspect-ratio:1/0.9;border-radius:0;display:grid;place-items:center;background:#f5eee6;overflow:hidden}
.lc-store-product-image img{width:100%;height:100%;object-fit:cover}.lc-store-product-image svg{width:34px;height:34px;color:#bd8d65}
.lc-store-product-meta{flex:1;min-width:0;width:100%;padding:14px 13px 15px;display:flex;flex-direction:column;gap:5px}
.lc-store-item-category{display:inline-flex!important;align-self:flex-start;margin:0 0 3px!important;padding:5px 8px;border-radius:999px;background:#fff0df;color:#ac692e!important;font-size:.68rem!important;font-weight:800}
.lc-store-product-meta strong{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4;font-size:.9rem;color:#433529;font-weight:800;min-height:2.5em}
.lc-store-product-meta span{color:#b86629;font-weight:850;font-size:1rem;margin:1px 0}
.lc-store-item-rating{font-size:.74rem!important;color:#957a63!important;margin:auto 0 0!important;line-height:1.5;overflow-wrap:anywhere}
/* Order request keeps its own scroll body; header, footer, and close remain in place. */
.checkout-panel{position:relative;display:flex;flex-direction:column;overflow:hidden;width:min(540px,100%);height:min(720px,calc(var(--lc-vh,100dvh) - 32px));max-height:calc(var(--lc-vh,100dvh) - 32px);padding:0}
.checkout-header{position:relative;top:auto;display:flex;align-items:center;min-height:76px;padding:20px 65px 17px 20px;flex:0 0 auto;background:#fffdfa}
.checkout-header h2{font-size:1.1rem;color:#453328}.checkout-body{flex:1 1 auto;min-height:0;overflow-y:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;padding:18px 20px 25px;scroll-padding:16px 0 80px}
.checkout-footer{position:relative;bottom:auto;flex:0 0 auto;padding:13px 20px max(14px,env(safe-area-inset-bottom));background:#fffdfa;border-top:1px solid #f0e7df}
.checkout-modal-status{flex:0 0 auto;margin:0;padding:0 20px 9px;overflow-wrap:anywhere}
.checkout-product{border-radius:13px;background:#fffaf6}.checkout-product-image{border-radius:10px}
.checkout-input,.checkout-select,.checkout-textarea{min-height:46px;font-size:16px!important;border-radius:11px;background:#fff}.checkout-textarea{line-height:1.5}.checkout-btn{min-height:44px;font-size:.81rem;border-radius:11px}
.lc-store-fact{display:flex;flex-direction:row;align-items:flex-start;gap:12px}
.lc-store-fact-icon{display:grid;place-items:center;flex:0 0 37px;width:37px;height:37px;border-radius:11px;background:#fff2e7;color:#bf7d44}
.lc-store-fact-icon svg{width:18px;height:18px}.lc-store-fact-copy{display:block;min-width:0;flex:1}
/* Viewport corrections: photo stacks on mobile, while modal actions remain accessible. */
@media(max-width:1050px){.seller-details-row{flex-wrap:wrap}.seller-details-actions{width:100%;justify-content:stretch}.seller-action-btn{flex:1}.lc-store-products{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:760px){.lc-product-layout{grid-template-columns:1fr;display:flex;flex-direction:column;overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;height:100%}.lc-product-media{flex:none;padding:15px 15px 6px;background:#fffdfa;border-right:0}.product-details-image-wrap{max-height:none;aspect-ratio:1/0.73;border-radius:15px}.lc-image-caption{display:none}.lc-product-info-scroll{flex:none;min-height:0;height:auto;overflow:visible;padding:16px 17px 28px}.product-details-name{padding-right:0}.seller-details-row{flex-direction:column;align-items:stretch}.seller-details-actions{width:100%}.lc-store-products{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.lc-store-summary{grid-template-columns:58px minmax(0,1fr);grid-template-areas:"avatar details" "avatar status";column-gap:12px;row-gap:8px;align-items:center}.lc-store-active{margin-left:0;justify-self:start;max-width:100%}.lc-store-header{min-height:74px}.lc-store-content{padding:16px}}
@media(max-width:560px){
 .product-details-modal,.lc-store-modal,.modal{padding:0!important}
 .product-details-panel,.lc-store-panel,.checkout-panel{width:100vw!important;height:var(--lc-vh,100dvh)!important;max-height:var(--lc-vh,100dvh)!important;border:0!important;border-radius:0!important;padding:0!important}
 .product-details-panel{overflow:hidden!important}.lc-product-layout{padding-top:max(8px,env(safe-area-inset-top));padding-bottom:env(safe-area-inset-bottom)}
 .lc-product-media{padding:12px 12px 0}.product-details-image-wrap{aspect-ratio:1/0.76}.lc-product-info-scroll{padding:17px 15px 30px}
 .lc-floating-close,.product-details-close,.lc-store-close,.close-modal{top:calc(11px + env(safe-area-inset-top))!important;right:12px!important;width:39px!important;height:39px!important;background:rgba(255,253,250,.98)}
 .product-details-actions{grid-template-columns:minmax(0,1fr) minmax(0,1fr) 44px;gap:7px}.product-details-btn{font-size:.72rem;min-height:45px;padding:8px 7px}
 .seller-details-card,.product-reviews-card{padding:15px}.seller-details-title{margin-bottom:13px}.seller-details-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.seller-action-btn{min-height:43px;padding:9px 8px}
 .lc-store-header{padding:calc(15px + env(safe-area-inset-top)) 62px 14px 16px}
 .lc-store-summary{grid-template-columns:54px minmax(0,1fr);column-gap:12px;row-gap:8px;padding:16px;min-height:0}.lc-store-avatar{flex-basis:54px;width:54px;height:54px;border-radius:17px}.lc-store-active{margin:0;justify-self:start}.lc-store-summary-name{font-size:1.13rem}.lc-store-details-toggle{margin-top:12px;min-height:47px}
 .lc-store-product-meta{padding:10px}.lc-store-product-meta strong{font-size:.82rem}.lc-store-product-meta span{font-size:.87rem}.lc-store-product-image{aspect-ratio:1/0.83}.lc-store-item-rating{font-size:.66rem!important}
 .checkout-header{padding:calc(19px + env(safe-area-inset-top)) 62px 14px 16px;min-height:70px}.checkout-body{padding:16px 15px 20px}.checkout-footer{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;padding:12px 15px calc(12px + env(safe-area-inset-bottom));min-height:69px}.checkout-btn{width:100%}
 .checkout-modal-status{padding:0 15px 10px}
}
@media(max-width:360px){.product-details-btn{font-size:.67rem}.lc-store-products{gap:8px}.lc-store-product-meta{padding:9px}.lc-store-item-rating{font-size:.63rem!important}.lc-store-summary{grid-template-columns:45px minmax(0,1fr);column-gap:9px;padding:13px}.lc-store-avatar{width:45px;height:45px;flex-basis:45px;border-radius:14px;font-size:1rem}.lc-store-active{margin:0;padding:7px 9px;font-size:.68rem}}
@media(prefers-reduced-motion:reduce){.lc-store-product,.lc-floating-close{transition:none!important}}
/* Photo viewer: no cropping, stable desktop centering, and Safari-safe mobile sizing. */
.lc-product-media{min-height:0;min-width:0;display:grid;grid-template-rows:minmax(0,1fr) auto;justify-items:center;align-items:stretch;gap:9px;overflow:hidden}
.product-details-image-wrap.lc-photo-open{display:flex;align-items:center;justify-content:center;width:100%;height:100%;min-width:0;min-height:0;max-height:none;aspect-ratio:auto;padding:12px;border:1px solid #e9dbcf;border-radius:18px;background:#fffaf5;overflow:hidden;cursor:zoom-in;-webkit-appearance:none;appearance:none;touch-action:manipulation}
.product-details-image-wrap.lc-photo-open:focus-visible{outline:3px solid #e7a46e;outline-offset:2px}
.product-details-image-wrap.lc-photo-open:disabled{cursor:default;color:#997e65}
.product-details-image-wrap .product-details-image{display:block;flex:none;max-width:100%;max-height:100%;width:100%;height:100%;object-fit:contain;object-position:center center;align-self:center;margin:auto}
.product-details-image-wrap .product-details-placeholder{min-height:0;display:grid;place-items:center;font-size:.85rem;color:#9c8069}
/* Shop uses the same centered, proportional image treatment on every screen. */
.lc-store-product-image{aspect-ratio:1 / .82;min-height:0;background:#f5eee6;display:grid;place-items:center}
.lc-store-product-image img{display:block;width:100%;height:100%;max-width:100%;max-height:100%;object-fit:contain;object-position:50% 50%}
/* Standalone photo lightbox fits the complete image without moving the product popup. */
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
@media(max-width:760px){
 .lc-product-layout{min-height:0;height:100%;display:flex;flex-direction:column;overflow-x:hidden;overflow-y:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain}
 .lc-product-media{display:block;flex:0 0 auto;min-height:0;height:auto;overflow:visible;padding:14px 13px 0;background:#fffdfa;border-right:0}
 .product-details-image-wrap.lc-photo-open{display:flex;width:100%;height:clamp(185px,42svh,385px);max-height:none;min-height:185px;aspect-ratio:auto;padding:9px;border-radius:15px}
 .lc-product-info-scroll{flex:0 0 auto;height:auto;min-height:0;overflow:visible;padding:18px 16px 30px}
 .lc-photo-lightbox{padding:0}
 .lc-photo-panel{height:100%;width:100%;max-height:100%;border:none;border-radius:0}
 .lc-photo-stage{padding:max(57px,calc(env(safe-area-inset-top) + 55px)) 9px 8px}
 .lc-photo-footer{min-height:56px;padding:13px 17px calc(14px + env(safe-area-inset-bottom))}
 .lc-photo-close.lc-floating-close{top:calc(12px + env(safe-area-inset-top))!important;right:13px!important}
}
@media(max-width:560px){
 .lc-product-layout{padding-top:env(safe-area-inset-top);padding-bottom:env(safe-area-inset-bottom)}
 .lc-product-media{padding:12px 12px 0}
 .product-details-image-wrap.lc-photo-open{height:clamp(185px,42svh,340px);min-height:185px;aspect-ratio:auto}
 .lc-product-info-scroll{padding:16px 15px 30px}
 .lc-store-product-image{aspect-ratio:1 / .82}
}
@media(max-width:340px){.product-details-image-wrap.lc-photo-open{height:clamp(175px,39svh,270px);min-height:175px}}
/* Store product cards: one predictable layout for Safari and Chromium.
   Image frames retain their own size even before the image has loaded. */
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
@media(max-width:760px) {
  .lc-store-products {grid-template-columns:repeat(2,minmax(0,1fr));gap:11px;}
  .lc-store-product-image {aspect-ratio:1 / 1;}
  .lc-store-product-meta {gap:6px;padding:11px 10px 13px;}
  .lc-store-product-meta .lc-store-item-category {padding:4px 7px;font-size:.62rem!important;}
  .lc-store-product-meta strong {font-size:.83rem;}
  .lc-store-product-meta .lc-store-item-price {font-size:.9rem;}
  .lc-store-product-meta .lc-store-item-rating {font-size:.67rem!important;}
}
@media(max-width:360px) {
  .lc-store-products {gap:8px;}
  .lc-store-product {border-radius:12px;}
  .lc-store-product-meta {padding:10px 8px 11px;gap:5px;}
  .lc-store-product-meta .lc-store-item-category {font-size:.58rem!important;padding:4px 6px;}
  .lc-store-product-meta strong {font-size:.78rem;}
  .lc-store-product-meta .lc-store-item-price {font-size:.85rem;}
  .lc-store-product-meta .lc-store-item-rating {font-size:.62rem!important;}
}
@media(max-width:290px) {
  .lc-store-products {grid-template-columns:minmax(0,1fr);}
  .lc-store-product-meta {padding:12px;}
}
/* Marketplace filters: compact anchored popover, touch-safe mobile bottom sheet. */
.lc-market-heading{display:flex;flex-direction:row;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}
.lc-market-heading .page-title{margin:0;min-width:0}
.lc-market-filter{position:relative;flex:0 0 auto;z-index:91}
.lc-market-filter-trigger{display:inline-flex;align-items:center;justify-content:center;gap:9px;min-width:113px;min-height:43px;padding:9px 14px;border:1px solid #e2cbb5;border-radius:13px;background:#fffefa;color:#8e5325;font-size:.84rem;font-weight:800;font-family:inherit;cursor:pointer;box-shadow:0 4px 11px rgba(73,44,17,.04)}
.lc-market-filter-trigger:hover,.lc-market-filter-trigger[aria-expanded="true"]{background:#fff3e5;border-color:#e9a364}
.lc-market-filter-trigger svg{width:19px;height:19px;flex:none}
.lc-market-filter-trigger:focus-visible,.lc-market-filter-close:focus-visible,.lc-market-filter-panel button:focus-visible,.lc-market-filter-panel select:focus-visible{outline:3px solid #e5a56c;outline-offset:2px}
.lc-market-filter-count{display:grid;place-items:center;min-width:20px;height:20px;padding:0 5px;border-radius:999px;background:#e88735;color:#fff;font-size:.65rem;line-height:1}
.lc-market-filter-count[hidden]{display:none}
.lc-market-filter-shade[hidden],.lc-market-filter-panel[hidden],.lc-market-filter-active[hidden],.lc-market-filter-empty[hidden]{display:none!important}
.lc-market-filter-shade{position:fixed;inset:0;z-index:92;background:transparent}
.lc-market-filter-panel{position:absolute;top:calc(100% + 11px);right:0;z-index:93;width:min(365px,calc(100vw - 28px));padding:19px;border:1px solid #e8dacc;border-radius:19px;background:#fffefa;box-shadow:0 24px 54px rgba(49,30,16,.16);color:#41352e}
.lc-market-filter-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.lc-market-filter-top h2{font-size:1.04rem;font-weight:800;letter-spacing:-.02em;margin:0;color:#3d3027}
.lc-market-filter-top p{font-size:.73rem;color:#847469;line-height:1.5;margin:5px 0 0}
.lc-market-filter-close{width:33px;height:33px;flex:none;display:grid;place-items:center;border:1px solid #f0e1d3;border-radius:50%;background:#fff;color:#73533c;cursor:pointer}
.lc-market-filter-close svg{width:17px;height:17px}
.lc-market-filter-fields{display:grid;gap:15px;margin:21px 0 22px}
.lc-market-filter-field{min-width:0}
.lc-market-filter-field label{display:block;margin-bottom:7px;color:#4e3c2e;font-size:.78rem;font-weight:800}
.lc-market-filter-field label small{font-size:.72rem;font-weight:600;color:#958271}
.lc-market-filter-field select{display:block;width:100%;max-width:100%;min-height:47px;padding:10px 37px 10px 12px;border:1px solid #eadccf;border-radius:11px;background:#fff;color:#4a3e35;font:inherit;font-size:16px;outline:none;cursor:pointer;appearance:auto;-webkit-appearance:menulist}
.lc-market-filter-field select:focus{border-color:#de9e68;box-shadow:0 0 0 3px rgba(233,142,62,.11)}
.lc-market-filter-actions{display:grid;grid-template-columns:1fr 1.35fr;gap:9px}
.lc-market-filter-actions button{min-height:44px;padding:10px 11px;border-radius:11px;font:inherit;font-size:.8rem;font-weight:800;cursor:pointer}
.lc-market-filter-reset{border:1px solid #e9d9c8;background:#fff;color:#8e6043}
.lc-market-filter-apply{border:1px solid #d97926;background:#e88b3e;color:#fff}
.lc-market-filter-apply:hover{background:#d97b2c}
.lc-market-filter-active{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:-4px 0 17px}
.lc-market-filter-chip{display:inline-flex;align-items:center;gap:5px;max-width:100%;padding:6px 10px;border:1px solid #f0d9be;border-radius:999px;background:#fff2e5;color:#995929;font-size:.73rem;font-weight:700;overflow-wrap:anywhere}
.lc-market-filter-chip svg{width:13px;height:13px;flex:none}
.lc-market-filter-empty{margin-top:16px;padding:27px 17px;text-align:center;border:1px solid #eaded0;border-radius:15px;background:#fffbf7}
.lc-market-filter-empty strong{display:block;color:#4b3a30;font-size:1rem}
.lc-market-filter-empty p{margin-top:6px;color:#877569;font-size:.78rem}
.lc-market-filter-empty button{margin-top:14px;border:1px solid #e2ae7e;border-radius:10px;background:#fff3e8;color:#9c602d;font-weight:800;padding:9px 14px;cursor:pointer}
@media(max-width:600px){
 body.lc-filter-open{overflow:hidden}
 .lc-market-heading{flex-direction:row;align-items:center;gap:8px}
 .lc-market-filter-trigger{min-width:94px;min-height:40px;padding:8px 10px;gap:5px;border-radius:12px;font-size:.75rem}
 .lc-market-filter-trigger svg{width:16px;height:16px}
 .lc-market-filter-panel{position:fixed;top:auto;right:0;bottom:0;left:0;width:100%;max-height:calc(100dvh - 20px);max-height:calc(var(--lc-vh,100dvh) - 20px);padding:20px 17px calc(20px + env(safe-area-inset-bottom));border-radius:22px 22px 0 0;border-bottom:0;overflow-y:auto;-webkit-overflow-scrolling:touch;box-shadow:0 -16px 55px rgba(41,28,15,.2)}
 .lc-market-filter-shade{background:rgba(28,23,19,.43);-webkit-backdrop-filter:blur(2px);backdrop-filter:blur(2px)}
 .lc-market-filter-fields{margin:22px 0 24px}
 .lc-market-filter-actions{grid-template-columns:1fr 1.35fr}
}
/* Saved-specific styling; product, store, photo and order popups are the same as Marketplace. */
.saved-heading { align-items:center; }
.saved-summary { display:flex; flex-wrap:wrap; gap:8px; justify-content:flex-end; }
.saved-summary-pill { display:inline-flex; align-items:center; gap:5px; min-height:32px; padding:6px 11px; border:1px solid #eadfd5; border-radius:999px; background:#fffaf5; color:#827266; font-size:.73rem; font-weight:700; }
.saved-summary-pill strong { color:#4a3425; font-size:.8rem; }
.product-card.is-unavailable { opacity:.88; }
.stock-tag.unavailable { background:#fff3f1; color:#ad4949; }
.lc-store-item-unavailable { align-self:flex-start; color:#a04a40; font-size:.67rem; font-weight:800; }
#productDetailsCart:disabled,#productDetailsBuy:disabled { cursor:not-allowed; opacity:.52; }
@media(max-width:560px){ .saved-summary { justify-content:flex-end; } .saved-summary-pill { padding:5px 8px; font-size:.66rem; } .saved-heading { flex-direction:row; align-items:center; } }
/* Existing cart styles below take precedence for checkout and cart. */
    :root {
      --bg: #f4f3f1;
      --panel: #fff;
      --text: #2d2d2d;
      --muted: #6e6e73;
      --line: #e2e1de;
      --orange: #ea8a3a;
      --orange-dark: #d87522;
      --orange-soft: #fff0e2;
      --green: #25834e;
      --green-soft: #edf9f1;
      --red: #c54b4b;
      --red-soft: #fff2f2;
    }
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }
    body {
      min-height: 100vh;
      font-family: "Segoe UI", Arial, sans-serif;
      background: var(--bg);
      color: var(--text);
    }
    body.modal-open {
      overflow: hidden;
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
    .cart-wrap {
      width: 100%;
      max-width: 1180px;
      margin: 0 auto;
      padding-top: 0;
    }
    .cart-header {
      margin-bottom: 20px;
    }
    .page-title {
      margin: 0;
      font-size: 2.05rem;
      font-weight: 800;
      letter-spacing: -.7px;
      color: #2c2c2c;
    }
    .page-subtitle {
      margin-top: 4px;
      color: var(--muted);
      font-size: .9rem;
    }
    .error-box {
      margin-bottom: 16px;
      padding: 11px 13px;
      border: 1px solid #efc0c0;
      border-radius: 9px;
      background: var(--red-soft);
      color: var(--red);
      font-size: .75rem;
    }
    .cart-layout {
      display: grid;
      grid-template-columns: minmax(0, 1fr) 310px;
      gap: 18px;
      align-items: start;
    }
    .cart-card,
    .summary-card {
      background: rgba(255,255,255,.86);
      border: 1px solid #e3dfdb;
      border-radius: 14px;
      box-shadow: 0 8px 20px rgba(35,25,15,.04);
    }
    .cart-card {
      overflow: hidden;
    }
    .cart-card-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 16px 18px;
      border-bottom: 1px solid #ece8e4;
    }
    .cart-card-title {
      font-size: .95rem;
      font-weight: 800;
    }
    .cart-card-count {
      color: var(--muted);
      font-size: .7rem;
    }
    .cart-item {
      display: grid;
      grid-template-columns: 104px minmax(0, 1fr) auto;
      gap: 15px;
      align-items: center;
      padding: 16px 18px;
      border-top: 1px solid #ece8e4;
    }
    .cart-item:first-child {
      border-top: 0;
    }
    .cart-item.is-unavailable {
      background: #fbfaf9;
    }
    .cart-item-image-wrap {
      width: 104px;
      height: 92px;
      overflow: hidden;
      border-radius: 11px;
      background: #ece7e2;
    }
    .cart-item-image {
      width: 100%;
      height: 100%;
      display: block;
      object-fit: cover;
    }
    .cart-item-placeholder {
      width: 100%;
      height: 100%;
      display: grid;
      place-items: center;
      font-size: 1.8rem;
      background: linear-gradient(135deg, #ece7e2, #e2dbd4);
      color: #8e877f;
    }
    .cart-item-main {
      min-width: 0;
    }
    .cart-item-category {
      display: inline-flex;
      padding: 4px 7px;
      border-radius: 999px;
      background: var(--orange-soft);
      color: #bd691d;
      font-size: .6rem;
      font-weight: 800;
    }
    .cart-item-name {
      margin-top: 7px;
      font-size: .95rem;
      font-weight: 800;
      line-height: 1.3;
    }
    .cart-item-seller {
      margin-top: 4px;
      color: #5f5f5f;
      font-size: .68rem;
    }
    .cart-item-location {
      margin-top: 2px;
      color: #858585;
      font-size: .65rem;
    }
    .stock-line {
      margin-top: 7px;
      font-size: .66rem;
      color: var(--green);
      font-weight: 700;
    }
    .stock-line.out {
      color: var(--red);
    }
    .stock-warning {
      margin-top: 5px;
      color: var(--red);
      font-size: .63rem;
      line-height: 1.4;
    }
    .cart-item-actions {
      min-width: 150px;
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 8px;
    }
    .cart-item-price {
      color: var(--orange-dark);
      font-size: .98rem;
      font-weight: 800;
    }
    .quantity-control {
      display: inline-flex;
      align-items: center;
      height: 34px;
      border: 1px solid #ded9d4;
      border-radius: 8px;
      background: #fff;
      overflow: hidden;
    }
    .quantity-control button {
      width: 31px;
      height: 100%;
      border: 0;
      background: #faf9f8;
      color: #565656;
      font-size: .85rem;
      cursor: pointer;
    }
    .quantity-control button:hover {
      background: #f0ece8;
    }
    .quantity-control input {
      width: 42px;
      height: 100%;
      border: 0;
      border-left: 1px solid #e7e2dd;
      border-right: 1px solid #e7e2dd;
      outline: 0;
      text-align: center;
      color: #333;
      font: inherit;
      font-size: .7rem;
      background: #fff;
    }
    .item-total {
      font-size: .69rem;
      color: #6f6f6f;
    }
    .item-buttons {
      display: flex;
      gap: 6px;
      justify-content: flex-end;
      width: 100%;
    }
    .item-btn {
      min-height: 31px;
      padding: 6px 9px;
      border-radius: 7px;
      border: 1px solid #ddd8d3;
      background: #fff;
      color: #4f4f4f;
      font-size: .64rem;
      font-weight: 700;
      cursor: pointer;
    }
    .item-btn:hover:not(:disabled) {
      background: #f6f2ee;
    }
    .item-btn.buy {
      border-color: var(--orange);
      background: var(--orange);
      color: #fff;
    }
    .item-btn.buy:hover:not(:disabled) {
      background: var(--orange-dark);
    }
    .item-btn:disabled {
      opacity: .45;
      cursor: not-allowed;
    }
    .summary-card {
      padding: 18px;
      position: sticky;
      top: 20px;
    }
    .summary-title {
      font-size: .9rem;
      font-weight: 800;
      margin: 0;
    }
    .summary-heading {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 15px;
    }
    .summary-row {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      margin-top: 9px;
      color: #5f5f5f;
      font-size: .72rem;
    }
    .summary-row strong {
      color: #2d2d2d;
    }
    .summary-total {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      margin-top: 15px;
      padding-top: 13px;
      border-top: 1px solid #ebe6e1;
      font-size: .85rem;
      font-weight: 800;
    }
    .summary-total strong {
      color: var(--orange-dark);
      font-size: 1rem;
    }
    .cart-search-empty {
      padding: 26px 18px;
      border-top: 1px solid #ece8e4;
      color: var(--muted);
      text-align: center;
      font-size: .74rem;
    }
    .empty-cart {
      padding: 70px 20px;
      text-align: center;
    }
    .empty-cart-icon {
      font-size: 2rem;
      margin-bottom: 12px;
    }
    .empty-cart h2 {
      font-size: 1.1rem;
      margin-bottom: 6px;
    }
    .empty-cart p {
      color: var(--muted);
      font-size: .75rem;
      margin-bottom: 16px;
    }
    .browse-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 36px;
      padding: 8px 13px;
      border-radius: 8px;
      background: var(--orange);
      color: #fff;
      text-decoration: none;
      font-size: .7rem;
      font-weight: 800;
    }
    .modal {
      position: fixed;
      inset: 0;
      z-index: 100000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .modal.open {
      display: flex;
    }
    .modal-backdrop {
      position: absolute;
      inset: 0;
      background: rgba(18,20,24,.55);
      backdrop-filter: blur(2px);
    }
    .checkout-panel {
      position: relative;
      z-index: 1;
      width: min(540px, 100%);
      max-height: calc(100vh - 40px);
      overflow-y: auto;
      border-radius: 14px;
      background: #fff;
      box-shadow: 0 24px 70px rgba(0,0,0,.25);
    }
    .checkout-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
      padding: 18px 19px 14px;
      border-bottom: 1px solid #ece7e2;
    }
    .checkout-header h2 {
      font-size: 1rem;
      font-weight: 800;
    }
    .checkout-header p {
      margin-top: 4px;
      color: #777;
      font-size: .67rem;
    }
    .close-modal {
      width: 32px;
      height: 32px;
      border: 1px solid #ded9d4;
      border-radius: 50%;
      background: #fff;
      color: #555;
      cursor: pointer;
      font-size: 1rem;
    }
    .checkout-body {
      padding: 17px 19px;
    }
    .checkout-product {
      display: grid;
      grid-template-columns: 72px 1fr;
      gap: 11px;
      align-items: center;
      padding: 10px;
      border: 1px solid #ece7e2;
      border-radius: 10px;
      background: #faf9f8;
      margin-bottom: 15px;
    }
    .checkout-product-image {
      width: 72px;
      height: 64px;
      overflow: hidden;
      border-radius: 8px;
      background: #ebe6e1;
    }
    .checkout-product-image img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .checkout-product-name {
      font-size: .76rem;
      font-weight: 800;
    }
    .checkout-product-meta {
      margin-top: 3px;
      color: #747474;
      font-size: .63rem;
    }
    .checkout-section {
      margin-top: 14px;
    }
    .checkout-label {
      display: block;
      margin-bottom: 6px;
      font-size: .68rem;
      font-weight: 800;
      color: #3e3e3e;
    }
    .checkout-input,
    .checkout-select,
    .checkout-textarea {
      width: 100%;
      border: 1px solid #ded9d4;
      border-radius: 8px;
      padding: 9px 10px;
      background: #fff;
      color: #333;
      outline: none;
      font: inherit;
      font-size: .7rem;
    }
    .checkout-textarea {
      min-height: 76px;
      resize: vertical;
    }
    .payment-options {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
    }
    .payment-option {
      display: flex;
      align-items: center;
      gap: 7px;
      padding: 9px 10px;
      border: 1px solid #ded9d4;
      border-radius: 8px;
      cursor: pointer;
      font-size: .68rem;
      color: #4b4b4b;
    }
    .payment-option:has(input:checked) {
      border-color: var(--orange);
      background: var(--orange-soft);
    }
    .payment-option span {
      display: flex;
      flex-direction: column;
      gap: 2px;
      min-width: 0;
    }
    .payment-option strong {
      font-size: .73rem;
      color: #35312d;
    }
    .payment-option small {
      color: #857a70;
      font-size: .62rem;
      line-height: 1.35;
    }
    .test-payment-note {
      margin-top: 10px;
      padding: 10px 11px;
      border: 1px solid #f0d5aa;
      border-radius: 9px;
      background: #fff9ed;
      color: #77582f;
      font-size: .65rem;
      line-height: 1.5;
    }
    .checkout-total {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 15px;
      margin-top: 17px;
      padding-top: 14px;
      border-top: 1px solid #ece7e2;
      font-size: .78rem;
      font-weight: 800;
    }
    .checkout-total strong {
      color: var(--orange-dark);
      font-size: 1rem;
    }
    .checkout-footer {
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      padding: 13px 19px 17px;
      border-top: 1px solid #ece7e2;
    }
    .checkout-btn {
      min-height: 36px;
      padding: 8px 13px;
      border-radius: 8px;
      border: 1px solid #ddd8d3;
      background: #fff;
      color: #4d4d4d;
      font-size: .68rem;
      font-weight: 800;
      cursor: pointer;
    }
    .checkout-btn.primary {
      border-color: var(--orange);
      background: var(--orange);
      color: #fff;
    }
    .checkout-btn:disabled {
      opacity: .55;
      cursor: default;
    }
    @media (max-width: 940px) {
      .cart-layout {
        grid-template-columns: 1fr;
      }
      .summary-card {
        position: static;
      }
    }
    @media (max-width: 840px) {
      .page > .content {
        position: relative !important;
        top: auto !important;
        right: auto !important;
        bottom: auto !important;
        left: auto !important;
        inset: auto !important;
        width: 100% !important;
        max-width: none !important;
        min-height: 100vh !important;
        height: auto !important;
        margin: 0 !important;
        padding: 74px 14px 25px !important;
        overflow: visible !important;
      }
      .cart-wrap {
        padding-top: 0;
      }
      .page-title {
        font-size: 1.6rem;
      }
      .page-subtitle {
        font-size: .76rem;
      }
      .cart-item {
        grid-template-columns: 82px minmax(0, 1fr);
        gap: 11px;
      }
      .cart-item-image-wrap {
        width: 82px;
        height: 78px;
      }
      .cart-item-actions {
        grid-column: 1 / -1;
        width: 100%;
        min-width: 0;
        align-items: stretch;
      }
      .item-buttons {
        justify-content: stretch;
      }
      .item-buttons .item-btn {
        flex: 1;
      }
      .cart-item-price {
        align-self: flex-end;
      }
    }
    @media (max-width: 480px) {
      .cart-layout {
        gap: 12px;
      }
      .cart-card-header {
        padding: 13px 14px;
      }
      .cart-item {
        padding: 13px 14px;
      }
      .payment-options {
        grid-template-columns: 1fr;
      }
      .checkout-footer {
        flex-direction: column-reverse;
      }
      .checkout-btn {
        width: 100%;
      }
    }
    .cart-card,
    .summary-card {
      border-radius: 22px;
      border-color: #e7e0d9;
      box-shadow: 0 10px 28px rgba(47,31,19,.055);
    }
    .cart-item-image-wrap {
      border-radius: 16px;
    }
    .quantity-control,
    .item-btn,
    .browse-btn {
      border-radius: 12px;
    }
    .checkout-panel {
      border-radius: 24px;
    }
    .checkout-product {
      border-radius: 16px;
    }
    .checkout-product-image {
      border-radius: 12px;
    }
    .checkout-input,
    .checkout-select,
    .checkout-textarea,
    .checkout-btn,
    .test-payment-note {
      border-radius: 12px;
    }
    @media (max-width: 560px) {
      .cart-card,
      .summary-card {
        border-radius: 18px;
      }
      .checkout-panel {
        border-radius: 20px;
      }
    }
    .select-all-control {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      margin-top: 8px;
      color: var(--muted);
      font-size: .68rem;
      font-weight: 700;
      cursor: pointer;
      user-select: none;
    }
    .select-all-control input {
      accent-color: var(--orange);
    }
    .cart-item {
      grid-template-columns: 34px 104px minmax(0, 1fr) auto;
    }
    .cart-select-wrap {
      align-self: center;
      display: grid;
      place-items: center;
      width: 30px;
      height: 30px;
      cursor: pointer;
    }
    .cart-select {
      position: absolute;
      opacity: 0;
      pointer-events: none;
    }
    .cart-select-box {
      width: 20px;
      height: 20px;
      display: grid;
      place-items: center;
      border: 1.5px solid #d6cdc5;
      border-radius: 7px;
      background: #fff;
      transition: .18s ease;
    }
    .cart-select:checked + .cart-select-box {
      border-color: var(--orange);
      background: var(--orange);
      box-shadow: 0 0 0 3px rgba(234,138,58,.10);
    }
    .cart-select:checked + .cart-select-box::after {
      content: "✓";
      color: #fff;
      font-size: .72rem;
      font-weight: 900;
      line-height: 1;
    }
    .cart-select:disabled + .cart-select-box {
      background: #eeeae6;
      border-color: #e2ddd8;
      cursor: not-allowed;
      opacity: .65;
    }
    .cart-item.is-selected {
      background: #fffaf5;
      box-shadow: inset 0 0 0 1px rgba(234,138,58,.14);
    }
    .summary-checkout-btn {
      width: 100%;
      min-height: 42px;
      margin-top: 16px;
      padding: 10px 14px;
      border: 1px solid var(--orange);
      border-radius: 13px;
      background: var(--orange);
      color: #fff;
      font: inherit;
      font-size: .72rem;
      font-weight: 800;
      cursor: pointer;
      transition: .18s ease;
    }
    .summary-checkout-btn:hover:not(:disabled) {
      background: var(--orange-dark);
      border-color: var(--orange-dark);
    }
    .summary-checkout-btn:disabled {
      opacity: .48;
      cursor: default;
    }
    .checkout-selected-list {
      display: grid;
      gap: 11px;
      margin-bottom: 14px;
    }
    .checkout-seller-group {
      overflow: hidden;
      border: 1px solid #e7e0d9;
      border-radius: 16px;
      background: #fcfaf8;
    }
    .checkout-seller-title {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 10px 12px;
      border-bottom: 1px solid #ebe5df;
      background: #fffaf5;
      color: #4d4138;
      font-size: .69rem;
      font-weight: 800;
    }
    .checkout-seller-items {
      display: grid;
      gap: 0;
    }
    .checkout-selected-item {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 12px;
      padding: 10px 12px;
      border-top: 1px solid #eee8e2;
      font-size: .66rem;
    }
    .checkout-selected-item:first-child {
      border-top: 0;
    }
    .checkout-selected-name {
      font-weight: 800;
      color: #3e3833;
    }
    .checkout-selected-meta {
      margin-top: 3px;
      color: #81766d;
    }
    .checkout-selected-price {
      color: var(--orange-dark);
      font-weight: 800;
      white-space: nowrap;
    }
    @media (max-width: 840px) {
      .cart-item {
        grid-template-columns: 30px 82px minmax(0, 1fr);
      }
      .cart-item-actions {
        grid-column: 2 / -1;
      }
    }
    @media (max-width: 480px) {
      .cart-item {
        grid-template-columns: 26px 70px minmax(0, 1fr);
        gap: 9px;
      }
      .cart-item-image-wrap {
        width: 70px;
        height: 70px;
      }
      .cart-select-wrap {
        width: 24px;
        height: 24px;
      }
      .cart-select-box {
        width: 18px;
        height: 18px;
        border-radius: 6px;
      }
      .checkout-selected-item {
        grid-template-columns: 1fr;
        gap: 5px;
      }
      .checkout-selected-price {
        white-space: normal;
      }
    }
/* Unavailable listings cannot be opened or exposed in a product preview. */
#productDetailsModal.lc-archived-view .lc-product-media,
#productDetailsModal.lc-archived-view .product-details-price,
#productDetailsModal.lc-archived-view .product-details-rating,
#productDetailsModal.lc-archived-view .product-details-meta,
#productDetailsModal.lc-archived-view .product-details-section-title,
#productDetailsModal.lc-archived-view .product-details-description,
#productDetailsModal.lc-archived-view .seller-details-card,
#productDetailsModal.lc-archived-view .product-reviews-card {display:none!important}
#productDetailsModal.lc-archived-view .lc-product-layout {display:block;min-height:240px}
#productDetailsModal.lc-archived-view .lc-product-info-scroll {width:100%;max-height:none;overflow:visible}
#productDetailsModal.lc-archived-view .product-details-stock {display:inline-flex;color:#a34d4d;background:#fff0f0;border:1px solid #f1cdcd;border-radius:9px;padding:10px 14px;margin:15px 0}
#productDetailsModal.lc-archived-view .product-details-actions {margin-top:15px}
.cart-item .lc-cart-click {cursor:pointer;transition:background .16s ease}
.cart-item .lc-cart-click:hover {filter:brightness(.97)}
.cart-item .lc-cart-click:focus-visible {outline:2px solid var(--orange);outline-offset:3px}
.cart-item.is-archived .quantity-control,.cart-item.is-archived .cart-item-price,.cart-item.is-archived .cart-item-category,.cart-item.is-archived .cart-item-seller,.cart-item.is-archived .cart-item-location {display:none!important}
.cart-item.is-archived .cart-item-placeholder.lc-archived-photo{font-size:.7rem;font-weight:800;color:#9b7676;background:#f1eeee}
.cart-item.is-archived .cart-item-actions {gap:9px}
.cart-item.is-archived .stock-line.out {color:#b04d4d}
.cart-select:disabled + .cart-select-box{cursor:not-allowed}
/* Popup layers, including an independent cart checkout dialog. */
#productDetailsModal{z-index:140000!important}
#lcStoreModal{z-index:130000!important}
#lcPhotoLightbox{z-index:170000!important}
@media(max-width:840px){
 .page > .content{padding-top:76px!important}
}
.lc-store-product-open{display:flex;flex-direction:column;align-items:stretch;gap:0;width:100%;height:100%;min-width:0;min-height:0;margin:0;padding:0;border:0;border-radius:inherit;background:transparent;color:inherit;text-align:left;font:inherit;cursor:pointer;-webkit-appearance:none;appearance:none}
.lc-store-product-open:focus-visible,.lc-store-save-btn:focus-visible{outline:3px solid #e4a56e;outline-offset:-3px}
.lc-store-save-btn{position:absolute;top:10px;left:10px;z-index:6;display:grid;place-items:center;width:37px;height:37px;padding:0;border:1px solid rgba(255,255,255,.94);border-radius:50%;background:rgba(255,255,255,.96);color:#6f6158;font:700 1.45rem/1 Arial,sans-serif;box-shadow:0 4px 12px rgba(45,27,11,.11);cursor:pointer;-webkit-tap-highlight-color:transparent}
.lc-store-save-btn.is-saved{background:#fff2e5;color:#d7772c}
.lc-store-save-btn:disabled{opacity:.6;cursor:wait}
.lc-store-out-of-stock{position:absolute;top:12px;right:10px;z-index:3;max-width:calc(100% - 65px);padding:6px 9px;border:1px solid #ebd8c6;border-radius:999px;background:rgba(255,251,246,.96);color:#9b693e;font-size:.68rem;font-weight:800;line-height:1.2;text-align:center;box-shadow:0 2px 8px rgba(42,28,15,.06)}
.lc-store-product.is-out-of-stock .lc-store-product-meta .lc-store-item-price{color:#8b7c70}
@media(max-width:760px){.lc-store-save-btn{top:8px;left:8px;width:35px;height:35px}}
/* Cart management: purchase choices and edit choices are independent. */
/* Same button visual language as the Marketplace Filters trigger. */
.cart-edit-toggle{display:inline-flex;align-items:center;justify-content:center;gap:9px;min-width:113px;min-height:43px;padding:9px 14px;border:1px solid #e2cbb5;border-radius:13px;background:#fffefa;color:#8e5325;font-family:inherit;font-size:.84rem;font-weight:800;cursor:pointer;box-shadow:0 4px 11px rgba(73,44,17,.04);-webkit-tap-highlight-color:transparent}
.cart-edit-toggle:hover,.cart-edit-toggle[aria-pressed="true"]{background:#fff3e5;color:#8e5325;border-color:#e9a364}
.cart-edit-toggle .cart-edit-icon{display:block;width:19px;height:19px;flex:none}
.cart-edit-toggle .cart-edit-icon-done{display:none}
.cart-edit-toggle[aria-pressed="true"] .cart-edit-icon-pencil{display:none}
.cart-edit-toggle[aria-pressed="true"] .cart-edit-icon-done{display:block}
.cart-card-header{position:relative;flex-wrap:wrap;gap:10px}
.cart-edit-select-all{display:none;align-items:center;gap:8px;flex:1 0 100%;font-size:.76rem;color:#785e48;cursor:pointer}
.cart-edit-select-all input{width:17px;height:17px;accent-color:#e98b40}
.cart-edit-check-wrap{display:none;place-items:center;align-self:center;width:30px;height:30px;cursor:pointer}
.cart-edit-check{position:absolute;opacity:0;width:1px;height:1px}
.cart-edit-check+.cart-select-box{display:grid;place-items:center;width:20px;height:20px;border:1.5px solid #d6cdc5;border-radius:7px;background:#fff}
.cart-edit-check:checked+.cart-select-box{border-color:#e98b40;background:#e98b40}
.cart-edit-check:checked+.cart-select-box::after{content:'✓';color:#fff;font-weight:800;font-size:.72rem}
.cart-edit-check:focus-visible+.cart-select-box{outline:3px solid rgba(233,139,64,.27);outline-offset:2px}
.cart-wrap.is-editing .cart-select-wrap,.cart-wrap.is-editing .select-all-control{display:none}
.cart-wrap.is-editing .cart-edit-check-wrap{display:grid}
.cart-wrap.is-editing .cart-edit-select-all{display:flex}
.cart-wrap.is-editing .cart-item .lc-cart-click{pointer-events:none;cursor:default}
.cart-wrap.is-editing .quantity-control{pointer-events:none;opacity:.72}
.cart-wrap.is-editing .cart-item.is-edit-selected{background:#fff8f0;box-shadow:inset 3px 0 0 #e98b40}
.cart-edit-toolbar{display:grid;grid-template-rows:0fr;opacity:0;transition:grid-template-rows .25s ease,opacity .22s ease,margin .22s ease;margin-bottom:0}
.cart-edit-toolbar-inner{min-height:0;overflow:hidden}
.cart-wrap.is-editing .cart-edit-toolbar{grid-template-rows:1fr;opacity:1;margin-bottom:19px}
.cart-edit-toolbar-heading{display:flex;align-items:center;justify-content:space-between;gap:9px;padding-bottom:12px;font-size:.84rem;color:#46372c}
.cart-edit-toolbar-heading span{font-size:.72rem;color:#817469}
.cart-edit-toolbar-actions{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,0.7fr);gap:9px}
.cart-edit-toolbar-actions button{min-height:42px;border:1px solid #e6c6a8;border-radius:11px;background:#fff7ed;color:#ae652f;font-family:inherit;font-size:.72rem;font-weight:800;cursor:pointer;padding:9px 11px}
.cart-edit-toolbar-actions button.danger{background:#fff4f2;color:#b44b40;border-color:#edd0ca}
.cart-edit-toolbar-actions button:not(:disabled):hover{filter:brightness(.97)}
.cart-edit-toolbar-actions button:disabled{opacity:.45;cursor:not-allowed}
.cart-edit-toolbar-note{font-size:.73rem;line-height:1.45;margin-top:7px;color:#a33d32}
.cart-edit-toolbar-note:empty{display:none}
.cart-item.is-out-of-stock .stock-line.out{color:#b46c2e}
.cart-item.is-archived{grid-template-columns:minmax(0,1fr)!important;min-height:90px}
.cart-item.is-archived .cart-select-wrap{display:none!important}
.cart-item.is-archived .cart-item-image-wrap,.cart-item.is-archived .cart-item-actions{display:none!important}
.cart-item.is-archived .cart-item-main{grid-column:auto}
.cart-item.is-archived .cart-item-name{margin:0 0 7px}
.cart-item.is-archived .stock-line.out{margin:0}
.cart-wrap.is-editing .cart-item.is-archived{grid-template-columns:34px minmax(0,1fr)!important}
/* Product details retain Add to Cart and Save; removal is in Edit mode. */
/* Compact quantity controls on phones and tablets. */
.cart-item-actions{min-width:0}
.cart-item .quantity-control{width:max-content;max-width:100%;height:34px;flex-shrink:0}
.cart-item .quantity-control button{width:30px;flex:0 0 30px;font-size:.86rem}
.cart-item .quantity-control input{width:39px;min-width:0;font-size:14px}
@media(max-width:940px){.cart-wrap.is-editing .cart-edit-toolbar{margin-bottom:12px}}
@media(max-width:840px){
 .cart-item{grid-template-columns:30px 80px minmax(0,1fr);align-items:start;gap:10px}
 .cart-item-actions{grid-column:2/-1;flex-direction:row;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:7px;margin-top:-3px}
 .cart-item-price{align-self:center;font-size:.86rem}
 .cart-item .quantity-control{height:32px}
 .cart-item .quantity-control button{width:28px;flex-basis:28px}
 .cart-item .quantity-control input{width:36px}
 .cart-item .item-total{width:100%;text-align:right}
 .cart-wrap.is-editing .cart-edit-toolbar{position:fixed;left:10px;right:10px;bottom:calc(12px + env(safe-area-inset-bottom,0px));z-index:90000;display:block;background:#fffdfb;box-shadow:0 14px 45px rgba(35,22,14,.25);border:1px solid #e8d7c7;border-radius:15px;transform:translateY(0);padding:13px 14px;margin:0}
 .cart-wrap:not(.is-editing) .cart-edit-toolbar{position:fixed;bottom:-260px;left:10px;right:10px;transition:bottom .25s ease;pointer-events:none}
 .cart-wrap.is-editing .cart-edit-toolbar-inner{overflow:visible}
 .cart-wrap.is-editing{padding-bottom:165px}
 .cart-edit-toolbar-heading{padding-bottom:8px}
 .cart-edit-toolbar-actions button{min-height:44px}
 .cart-card-header{align-items:center}
 .cart-card-header .cart-card-count{white-space:nowrap}
 .cart-edit-select-all{padding-top:2px}
 .cart-item.is-archived,.cart-wrap.is-editing .cart-item.is-archived{grid-template-columns:30px minmax(0,1fr)!important}
}
@media(max-width:480px){
 .cart-item{grid-template-columns:24px 70px minmax(0,1fr);gap:8px;padding:12px}
 .cart-item-image-wrap{width:70px;height:70px}
 .cart-item-actions{grid-column:2/-1;justify-content:flex-start}
 .cart-item .item-total{width:auto;margin-left:auto;text-align:right;font-size:.64rem}
 .cart-edit-toggle{padding:8px 12px;min-height:35px}
}
@media(prefers-reduced-motion:reduce){.cart-edit-toolbar{transition:none!important}}
/* Buyer Cart layout: consistent column sizing and Edit mode on desktop and mobile. */
.cart-wrap {width:100%;max-width:1220px;min-width:0;margin-inline:auto}
.cart-header {display:flex;align-items:center;justify-content:space-between;gap:16px;min-width:0;margin:0 0 20px}
.cart-header .page-title {min-width:0;line-height:1.25;overflow-wrap:anywhere}
.cart-header>.cart-edit-toggle {flex:0 0 auto;min-width:113px;min-height:43px;display:inline-flex;align-items:center;justify-content:center;gap:9px;white-space:nowrap;transition:background-color .18s ease,border-color .18s ease,color .18s ease}
.cart-header>.cart-edit-toggle:disabled {opacity:.52;cursor:not-allowed}
.cart-edit-toggle:focus-visible {outline:3px solid #e5a56c;outline-offset:2px}
.cart-layout {grid-template-columns:minmax(0,1fr) clamp(294px,26vw,330px);column-gap:20px;align-items:start;min-width:0}
.cart-card,.summary-card {min-width:0;width:100%;border-radius:18px;background:#fff}
.cart-card-header {align-items:flex-start;gap:10px;min-width:0;flex-wrap:wrap;padding:17px 19px}
.cart-card-header>div {min-width:0}
.cart-card-title {color:#3b3028;font-size:1rem}
.cart-card-count {flex:0 0 auto;white-space:nowrap;line-height:1.5;padding-top:2px}
.select-all-control,.cart-edit-select-all {min-height:22px;line-height:1.4}
.cart-item {grid-template-columns:28px 94px minmax(0,1fr) minmax(112px,max-content);gap:12px;min-width:0;padding:18px 17px;align-items:center}
.cart-item-image-wrap {width:94px;height:91px;max-width:100%}
.cart-item-main {min-width:0;overflow-wrap:anywhere}
.cart-item-name {font-size:.94rem;overflow-wrap:anywhere}
.cart-item-actions {width:auto;min-width:0;max-width:100%;display:flex;flex-direction:column;align-items:flex-end;gap:9px}
.cart-item-price {font-variant-numeric:tabular-nums;white-space:nowrap;font-size:.94rem}
.cart-item .quantity-control {height:36px;border-radius:10px}
.cart-item .quantity-control input {font-size:16px}
.cart-item .item-total {line-height:1.45;text-align:right;overflow-wrap:anywhere}
.cart-item.is-archived {grid-template-columns:minmax(0,1fr)!important}
.cart-wrap.is-editing .cart-item.is-archived {grid-template-columns:28px minmax(0,1fr)!important}
.cart-wrap.is-editing .cart-item.is-archived .cart-edit-check-wrap {grid-column:1}
.cart-wrap.is-editing .cart-item.is-archived .cart-item-main {grid-column:2}
.summary-card {position:sticky;top:92px;padding:20px;align-self:start}
.summary-heading {min-width:0;justify-content:space-between}
.summary-title {min-width:0;overflow-wrap:anywhere}
.summary-row strong,.summary-total strong {text-align:right;font-variant-numeric:tabular-nums}
.cart-edit-toolbar {min-width:0}
.cart-edit-toolbar-inner {min-width:0}
.cart-edit-toolbar-heading {flex-wrap:wrap}
.cart-edit-toolbar-actions {grid-template-columns:minmax(0,1.2fr) minmax(0,1fr)}
.cart-edit-toolbar-actions button {min-width:0;white-space:normal;line-height:1.3}
.cart-wrap.is-editing .cart-edit-toolbar {margin-bottom:18px}
/* Keep interactions tappable and cart readable even on compact desktop windows. */
@media(max-width:1160px){
 .cart-layout {grid-template-columns:minmax(0,1fr);gap:17px}
 .summary-card {position:static}
 .cart-item {grid-template-columns:30px 100px minmax(0,1fr) minmax(132px,max-content)}
 .cart-item-image-wrap {width:100px;height:94px}
}
@media(max-width:840px){
 .cart-header {gap:12px;margin-bottom:15px}
 .cart-card-header {align-items:center}
 .cart-item {grid-template-columns:30px 84px minmax(0,1fr);gap:11px;align-items:start;padding:15px}
 .cart-item-image-wrap {width:84px;height:84px}
 .cart-item-actions {grid-column:2/-1;justify-self:stretch;display:flex;flex-direction:row;align-items:center;justify-content:flex-start;gap:8px 12px;flex-wrap:wrap;margin-top:0}
 .cart-item-price {font-size:.9rem;margin-right:auto}
 .cart-item .quantity-control {height:36px}
 .cart-item .item-total {flex:1 1 100%;text-align:right}
 .cart-wrap.is-editing {padding-bottom:calc(190px + env(safe-area-inset-bottom,0px))}
 .cart-wrap.is-editing .cart-edit-toolbar {position:fixed;left:12px;right:12px;bottom:calc(12px + env(safe-area-inset-bottom,0px));z-index:90000;display:grid;grid-template-rows:1fr;max-width:560px;margin-inline:auto;margin-bottom:0;padding:14px 16px;border-radius:16px;background:#fffdfb;box-shadow:0 13px 40px rgba(37,24,12,.24)}
 .cart-wrap:not(.is-editing) .cart-edit-toolbar {position:fixed;left:12px;right:12px;bottom:calc(-320px - env(safe-area-inset-bottom,0px));display:grid;max-width:560px;margin-inline:auto;pointer-events:none;visibility:hidden}
 .cart-wrap.is-editing .cart-edit-toolbar-inner {overflow:visible}
 .cart-edit-toolbar-actions button {min-height:45px}
}
@media(max-width:560px){
 .cart-header .page-title {font-size:1.58rem}
 .cart-header>.cart-edit-toggle {min-width:94px;min-height:40px;padding:8px 10px;gap:5px;border-radius:12px;font-size:.75rem}
 .cart-edit-toggle .cart-edit-icon {width:16px;height:16px}
 .cart-card-header {padding:14px}
 .cart-item {grid-template-columns:26px 76px minmax(0,1fr);gap:9px;padding:13px 12px}
 .cart-item-image-wrap {width:76px;height:76px;border-radius:12px}
 .cart-item-name {font-size:.86rem;line-height:1.36}
 .cart-item-seller,.cart-item-location {font-size:.69rem;line-height:1.4}
 .cart-item-actions {grid-column:2/-1;align-items:center;gap:7px 9px}
 .cart-item-price {margin:0 auto 0 0;font-size:.88rem}
 .cart-item .quantity-control {height:35px}
 .cart-item .quantity-control button {width:30px;flex-basis:30px}
 .cart-item .quantity-control input {width:38px;min-width:0}
 .cart-item .item-total {flex:1 1 100%;text-align:left;font-size:.68rem}
 .cart-wrap.is-editing .cart-item.is-archived {grid-template-columns:26px minmax(0,1fr)!important}
 .cart-edit-toolbar-actions {grid-template-columns:minmax(0,1.3fr) minmax(0,1fr);gap:8px}
 .summary-card {padding:17px}
}
@media(max-width:385px){
 .cart-item {grid-template-columns:24px 65px minmax(0,1fr);gap:8px;padding:12px 10px}
 .cart-item-image-wrap {width:65px;height:70px}
 .cart-item-actions {gap:7px}
 .cart-item-price {font-size:.8rem}
 .cart-item .quantity-control button {width:27px;flex-basis:27px}
 .cart-item .quantity-control input {width:34px}
 .cart-card-count {font-size:.66rem}
 .cart-edit-toolbar-actions button {font-size:.7rem;padding:9px 7px}
 .cart-wrap.is-editing .cart-item.is-archived {grid-template-columns:24px minmax(0,1fr)!important}
}
@media(prefers-reduced-motion:reduce){.cart-header>.cart-edit-toggle {transition:none}}

/* Cart Request Order: single, scoped responsive dialog and contextual validation. */
#checkoutModal{z-index:160000;padding:16px!important;align-items:center;justify-content:center}
#checkoutModal .checkout-panel{position:relative;display:flex;flex-direction:column;width:min(650px,100%)!important;min-width:0;height:auto!important;max-height:calc(100vh - 32px)!important;max-height:calc(100dvh - 32px)!important;overflow:hidden;border:1px solid #e8dace!important;border-radius:20px!important;background:#fffdfb;box-shadow:0 24px 70px rgba(45,27,14,.24)}
#checkoutModal .checkout-header{position:relative;flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:19px 22px;background:linear-gradient(110deg,#fffdf9,#fff5ea);border-bottom:1px solid #eee2d5}
#checkoutModal .lc-cart-checkout-heading{display:flex;align-items:center;gap:12px;min-width:0}
#checkoutModal .lc-cart-checkout-icon{display:grid;place-items:center;flex:0 0 44px;width:44px;height:44px;border:1px solid #f3d6bd;border-radius:13px;background:#fff0e0;color:#b97237}
#checkoutModal .lc-cart-checkout-icon svg{width:24px;height:24px}
#checkoutModal .checkout-header h2{margin:0;color:#46362c;font-size:1.12rem;line-height:1.2;font-weight:800}
#checkoutModal .checkout-header p{max-width:440px;margin-top:5px;color:#817266;font-size:.76rem;line-height:1.45}
#checkoutModal #closeCheckout{display:grid;place-items:center;flex:0 0 38px;width:38px;height:38px;border-radius:11px;color:#67594e;background:#fff;border:1px solid #e7dacd}
#checkoutModal #closeCheckout svg{width:18px;height:18px}
#checkoutModal .checkout-body{flex:1 1 auto;min-height:0;padding:19px 22px 17px;overflow-x:hidden;overflow-y:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;scroll-padding:14px 0 22px}
#checkoutModal .lc-cart-checkout-block{margin-bottom:21px;min-width:0}
#checkoutModal .lc-cart-checkout-section-title{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:11px}
#checkoutModal .lc-cart-checkout-section-title h3{margin:0;color:#4c3d32;font-size:.88rem;font-weight:800}
#checkoutModal .lc-cart-checkout-count{flex:0 0 auto;color:#9b6336;background:#fff0e3;border-radius:999px;padding:5px 9px;font-size:.68rem;font-weight:750}
#checkoutModal .checkout-selected-list{max-width:100%;margin:0;gap:10px}
#checkoutModal .checkout-seller-group{border:1px solid #ebe1d6;border-radius:13px;overflow:hidden;background:#fff}
#checkoutModal .checkout-seller-title{padding:11px 12px;background:#fff8f1;font-size:.75rem;overflow-wrap:anywhere}
#checkoutModal .checkout-selected-item{padding:11px 12px;min-width:0;grid-template-columns:minmax(0,1fr) auto;gap:10px;font-size:.74rem}
#checkoutModal .checkout-selected-name{overflow-wrap:anywhere}
#checkoutModal .checkout-selected-price{white-space:nowrap;align-self:center}
#checkoutModal .checkout-section{margin:0 0 15px;min-width:0}
#checkoutModal .checkout-label{display:flex;align-items:center;gap:4px;margin-bottom:8px;color:#584637;font-size:.81rem;font-weight:750}
#checkoutModal .lc-checkout-required{color:#c94a43;font-weight:800}
#checkoutModal .checkout-select{display:block;width:100%;min-height:48px;padding:12px 14px;border:1px solid #e5d8ca;border-radius:11px;background:#fdfbf8;color:#463a30;font-size:16px!important;outline:none;transition:border-color .17s ease,box-shadow .17s ease,background .17s ease}
#checkoutModal .checkout-select:focus-visible{border-color:#ce945f;box-shadow:0 0 0 3px rgba(234,138,58,.12)}
#checkoutModal .lc-checkout-field[data-validation="valid"] .checkout-select{border-color:#93c9a6;background:#fcfffd}
#checkoutModal .lc-checkout-field[data-validation="valid"] .checkout-label::after{content:"✓";margin-left:5px;color:#3a9461;font-weight:800}
#checkoutModal .lc-checkout-field[data-validation="invalid"] .checkout-select{border-color:#ce5953!important;background:#fff9f8!important;box-shadow:0 0 0 3px rgba(206,89,83,.08)}
#checkoutModal .lc-checkout-field[data-validation="invalid"] .checkout-label{color:#a7423e}
#checkoutModal .lc-checkout-helper{margin:6px 0 0;color:#85766c;font-size:.72rem;line-height:1.5}
#checkoutModal .lc-checkout-field-error{display:flex;align-items:flex-start;gap:5px;margin:6px 0 0;font-size:.73rem;line-height:1.45;font-weight:650;color:#b6443d}
#checkoutModal .lc-checkout-field-error::before{content:"!";display:grid;place-items:center;flex:0 0 15px;width:15px;height:15px;border:1px solid #d16f66;border-radius:50%;font-size:.65rem}
#checkoutModal .lc-checkout-field-error[hidden]{display:none}
#checkoutModal .checkout-inline-error{margin:5px 0 13px;padding:12px;border:1px solid #edc0b7;border-radius:11px;background:#fff5f2;color:#a33d34;font-size:.76rem;line-height:1.5;overflow-wrap:anywhere}
#checkoutModal .checkout-inline-error[hidden]{display:none}
#checkoutModal .lc-cart-checkout-total{margin-top:0;padding:15px 0 0;border-top:1px solid #eee4da;font-size:.85rem}
#checkoutModal .lc-cart-checkout-total strong{color:#cd732b;font-size:1.14rem;font-variant-numeric:tabular-nums}
#checkoutModal .lc-checkout-disclaimer{margin-top:9px;color:#87766a;font-size:.71rem;line-height:1.5}
#checkoutModal .checkout-footer{display:flex;flex:0 0 auto;flex-direction:row!important;justify-content:flex-end;gap:10px;padding:13px 22px calc(13px + env(safe-area-inset-bottom,0px));border-top:1px solid #eee4db;background:#fffdfb;box-shadow:0 -5px 15px rgba(50,30,16,.035)}
#checkoutModal .checkout-btn{min-width:120px;min-height:45px;border-radius:11px;font-size:.8rem;font-weight:800}
#checkoutModal .checkout-btn.primary{display:inline-flex;align-items:center;justify-content:center;gap:9px;background:#ea8a3a;border-color:#ea8a3a;color:#fff}
#checkoutModal .checkout-btn.primary:disabled{opacity:.67;cursor:not-allowed}
#checkoutModal .checkout-btn.primary.is-request-sent:disabled{opacity:1;background:#edf8f0;border-color:#b8ddc6;color:#258052;cursor:not-allowed}
#checkoutModal .lc-cart-request-sent[hidden]{display:none}
#checkoutModal .lc-cart-request-sent{display:flex;flex:0 0 100%;align-items:center;gap:9px;padding:10px 12px;border:1px solid #bde4c9;border-radius:10px;background:#f1fbf4;color:#276743;font-size:.75rem;line-height:1.45}
#checkoutModal .lc-cart-request-sent strong{color:#1c7746}
#checkoutModal .lc-cart-request-sent-icon{flex:0 0 22px;width:22px;height:22px;display:grid;place-items:center;border-radius:50%;background:#d6f1df;color:#218249;font-weight:900}
#checkoutModal .checkout-footer{flex-wrap:wrap}
#checkoutModal .checkout-btn:focus-visible,#checkoutModal #closeCheckout:focus-visible{outline:3px solid rgba(234,138,58,.42);outline-offset:2px}
@media(max-width:600px){
 #checkoutModal{padding:9px!important;align-items:center}
 #checkoutModal .checkout-panel{max-height:calc(100vh - 18px)!important;max-height:calc(100dvh - 18px)!important;border-radius:16px!important}
 #checkoutModal .checkout-header{padding:14px 15px;gap:8px}
 #checkoutModal .lc-cart-checkout-icon{width:37px;height:37px;flex-basis:37px;border-radius:11px}
 #checkoutModal .lc-cart-checkout-icon svg{width:21px;height:21px}
 #checkoutModal .checkout-header h2{font-size:1rem}
 #checkoutModal .checkout-header p{font-size:.7rem}
 #checkoutModal #closeCheckout{width:35px;height:35px;flex-basis:35px}
 #checkoutModal .checkout-body{padding:15px}
 #checkoutModal .checkout-footer{padding:12px 15px calc(12px + env(safe-area-inset-bottom,0px))}
 #checkoutModal .checkout-btn{flex:1;min-width:0}
 #checkoutModal .checkout-selected-item{gap:8px}
}
@media(max-width:360px){
 #checkoutModal .lc-cart-checkout-heading{gap:8px}
 #checkoutModal .checkout-header h2{font-size:.96rem}
 #checkoutModal .checkout-header p{font-size:.66rem}
 #checkoutModal .lc-cart-checkout-icon{display:none}
 #checkoutModal .checkout-selected-item{grid-template-columns:minmax(0,1fr);gap:4px}
 #checkoutModal .checkout-selected-price{justify-self:start}
}

/* Seller-defined add-ons are selected only during centralized Cart checkout. */
#checkoutModal .lc-cart-custom-info{font-size:.7rem;color:#7a6d62;line-height:1.5;margin:7px 0 12px}
#checkoutModal .lc-custom-options{margin-top:10px;padding:11px;border:1px solid #eadbcf;border-radius:10px;background:#fffaf5}
#checkoutModal .lc-custom-title{font-weight:800;font-size:.74rem;color:#764b2c;margin-bottom:3px}
#checkoutModal .lc-custom-option{padding:9px 0;border-top:1px solid #f1e6dd}
#checkoutModal .lc-custom-choice{display:flex;align-items:center;gap:9px;cursor:pointer;min-height:28px;font-size:.74rem;color:#4b3d33}
#checkoutModal .lc-custom-choice input{width:17px;height:17px;flex:0 0 17px;accent-color:#e98b40}
#checkoutModal .lc-custom-choice-name{flex:1;min-width:0;overflow-wrap:anywhere}
#checkoutModal .lc-custom-choice-price{font-weight:800;color:#b8662d;font-variant-numeric:tabular-nums;white-space:nowrap}
#checkoutModal .lc-custom-details{margin:8px 0 2px 26px}
#checkoutModal .lc-custom-details[hidden]{display:none}
#checkoutModal .lc-custom-details label{display:block;margin-bottom:5px;color:#685347;font-size:.69rem;font-weight:700}
#checkoutModal .lc-custom-details input{width:100%;min-height:38px;border:1px solid #e1d5ca;border-radius:8px;padding:8px 10px;background:#fff;color:#352d26;font:inherit;font-size:16px}
#checkoutModal .lc-custom-details input:focus{outline:2px solid rgba(233,139,64,.25);border-color:#e98b40}
#checkoutModal .lc-custom-details input.is-invalid{border-color:#c94141;background:#fff6f6}
#checkoutModal .lc-custom-error{display:block;color:#b63b3b;font-size:.66rem;margin-top:5px}
#checkoutModal .lc-custom-error[hidden]{display:none}
#checkoutModal .lc-custom-empty{font-size:.72rem;color:#746b63;padding:12px 0}
#checkoutModal .lc-custom-loading{font-size:.73rem;color:#8c704f;padding:12px 4px}
@media(max-width:560px){
 #checkoutModal .checkout-selected-item{grid-template-columns:minmax(0,1fr);gap:7px}
 #checkoutModal .checkout-selected-price{justify-self:end;align-self:auto}
}

/* --- Simplified shopping cart and transparent pricing (v2) --- */
.lc-cart-page-heading{min-width:0}
.cart-header{align-items:center;margin-bottom:20px}
.cart-header .page-title{color:#342c27;font-size:clamp(1.55rem,2.5vw,2.05rem)}
.cart-layout{grid-template-columns:minmax(0,1fr) minmax(315px,355px);gap:22px}
.cart-card,.summary-card{border:1px solid #ebe2d9;background:#fff;box-shadow:0 8px 22px rgba(65,38,18,.045);border-radius:20px}
.cart-card-header{padding:19px 21px;background:#fffdfb}
.cart-card-title{font-size:1.05rem;color:#352d27}
.cart-card-count{padding:6px 10px;border-radius:999px;background:#faf2eb;color:#9a5f30;font-weight:800}
.cart-item{padding:19px 20px;gap:14px;border-top:1px solid #f0eae4}
.cart-item:hover:not(.is-unavailable){background:#fffdfa}
.cart-item.is-selected{background:#fff9f3;box-shadow:inset 3px 0 0 #e98b40}
.cart-item-image-wrap{border-radius:14px}
.cart-item-name{color:#3b3029;font-size:.94rem}
.cart-item-seller,.cart-item-location{line-height:1.5}
.lc-cart-base-label{font-size:.66rem;color:#918278;font-weight:700;text-align:right}
.cart-item-price{color:#b96224;font-size:1rem}
.cart-item .item-total{color:#655b52;font-size:.69rem;font-weight:700}
.summary-card{top:93px;padding:22px}
.summary-heading{margin-bottom:20px}
.summary-title{font-size:1.12rem;letter-spacing:-.02em;color:#342c27}
.summary-row{align-items:center;margin-top:14px;font-size:.78rem;color:#776d65}
.summary-row strong{font-size:.83rem}
.summary-total{margin-top:18px;padding-top:18px;font-size:.84rem}
.summary-total strong{font-size:1.12rem}
.lc-price-example{margin-top:16px;padding:15px;border:1px dashed #e6d6c6;border-radius:14px;background:linear-gradient(135deg,#fffdfb,#fff9f2)}
.lc-example-tag{display:block;margin-bottom:5px;font-size:.57rem;color:#a36c42;font-weight:850;letter-spacing:.1em}
.lc-example-row{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin:8px 0;color:#665b50;font-size:.69rem;line-height:1.45}
.lc-example-row strong{flex:0 0 auto;color:#5a3a25;font-variant-numeric:tabular-nums}
.lc-example-divider{height:1px;background:#ece0d4;margin:12px 0}
.lc-example-final{font-weight:800;color:#543b2a}
.lc-example-final strong{color:#b7662d;font-size:.83rem}
.summary-checkout-btn{display:flex;align-items:center;justify-content:center;gap:12px;margin-top:18px;min-height:49px;font-size:.82rem;font-weight:800;box-shadow:0 5px 13px rgba(233,139,64,.13)}
.summary-checkout-btn > span[aria-hidden="true"]{font-size:1.25rem;font-weight:500}
#checkoutModal .checkout-panel{width:min(760px,100%)!important;border-radius:20px!important}
#checkoutModal .checkout-header{padding:19px 24px;background:#fffaf5}
#checkoutModal .checkout-header h2{font-size:1.2rem}
#checkoutModal .checkout-header p{max-width:550px;font-size:.73rem}
#checkoutModal .checkout-body{padding:22px 24px 16px}
#checkoutModal .lc-cart-checkout-block{margin-bottom:25px}
#checkoutModal .lc-cart-checkout-section-title h3{display:flex;align-items:center;gap:10px;font-size:.94rem;color:#382d26}
#checkoutModal .lc-checkout-step{display:inline-grid;place-items:center;flex:0 0 27px;width:27px;height:27px;border-radius:9px;background:#fff0e2;color:#ae6127;font-size:.78rem;font-weight:900}
#checkoutModal .checkout-seller-group{border-color:#e8ddd1;border-radius:15px}
#checkoutModal .checkout-seller-title{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 16px;background:#fff9f2;color:#65432b;font-size:.76rem}
#checkoutModal .checkout-selected-item{grid-template-columns:minmax(0,1fr);gap:12px;padding:17px}
#checkoutModal .checkout-selected-name{font-size:.91rem;color:#382e29}
#checkoutModal .checkout-selected-meta{margin:5px 0 0;font-size:.73rem;color:#8c7e70}
#checkoutModal .lc-custom-options{margin:15px 0 8px;padding:14px;border:1px solid #eddfd1;border-radius:13px;background:#fffaf6}
#checkoutModal .lc-custom-title{font-size:.79rem;color:#6d482e}
#checkoutModal .lc-custom-option{padding:11px 0}
#checkoutModal .lc-custom-choice{font-size:.77rem}
#checkoutModal .lc-custom-choice-price{color:#b86b35;font-size:.75rem}
#checkoutModal .lc-seller-price-breakdown{margin:0 13px 13px;padding:14px 15px;background:#f8f7f5;border:1px solid #ebe6e0;border-radius:13px}
#checkoutModal .lc-seller-breakdown-heading{font-size:.74rem;font-weight:850;color:#494037;margin-bottom:9px}
#checkoutModal .lc-breakdown-line,#checkoutModal .lc-selected-extra-line{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin:7px 0;font-size:.73rem;color:#756a5e;line-height:1.45}
#checkoutModal .lc-breakdown-line strong,#checkoutModal .lc-selected-extra-line strong{white-space:normal;text-align:right;font-variant-numeric:tabular-nums;color:#43362d}
#checkoutModal .lc-selected-extras{margin:7px 0 9px 11px;padding-left:11px;border-left:2px solid #e6bc9c}
#checkoutModal .lc-selected-extras[hidden]{display:none}
#checkoutModal .lc-selected-extra-line{font-size:.68rem;color:#986338}
#checkoutModal .lc-selected-extra-line strong{color:#a3602e}
#checkoutModal .lc-seller-breakdown-total{padding-top:10px;border-top:1px solid #e5ded6;font-weight:800;color:#55453a}
#checkoutModal .lc-seller-breakdown-total strong{color:#b86b35;font-size:.82rem}
#checkoutModal .checkout-selected-price{justify-self:stretch;text-align:right;padding:9px 3px 0;color:#9a5224;font-size:1.01rem;font-weight:850}
#checkoutModal .checkout-selected-price::before{content:'Item total  ';font-size:.74rem;color:#7d6c5e;font-weight:600}
#checkoutModal .lc-checkout-price-review{padding:17px;border-radius:15px;background:#fffaf5;border:1px solid #eddbc9}
#checkoutModal .lc-checkout-totals{padding:14px 16px;border:1px solid #ecdfd1;border-radius:12px;background:#fff}
#checkoutModal .lc-checkout-total-row{display:flex;align-items:flex-start;justify-content:space-between;gap:15px;margin:10px 0;color:#75685b;font-size:.76rem;line-height:1.4}
#checkoutModal .lc-checkout-total-row strong{color:#48372c;white-space:nowrap;font-variant-numeric:tabular-nums}
#checkoutModal .lc-cart-checkout-total{margin:13px 0 0;padding:16px 0 0;border-top:1px solid #e9ded4}
#checkoutModal .lc-cart-checkout-total strong{color:#bd6929;font-size:1.27rem}
#checkoutModal .lc-checkout-disclaimer{margin:12px 0 0;color:#806b5c;font-size:.72rem;line-height:1.55}
#checkoutModal .checkout-inline-error{margin-bottom:13px}
@media(max-width:1160px){.cart-layout{grid-template-columns:minmax(0,1fr)}.summary-card{position:static}}
@media(max-width:840px){.cart-item{gap:11px}.lc-cart-base-label{text-align:left}.summary-card{padding:20px}}
@media(max-width:600px){
 .cart-header{align-items:start;gap:8px}
 .cart-card-header{padding:15px}.cart-item{padding:14px 12px}
 #checkoutModal .checkout-panel{border-radius:15px!important}
 #checkoutModal .checkout-header{padding:13px 16px}#checkoutModal .checkout-header h2{font-size:1.02rem}#checkoutModal .checkout-header p{font-size:.69rem}
 #checkoutModal .checkout-body{padding:15px}
 #checkoutModal .checkout-selected-item{padding:13px}
 #checkoutModal .lc-seller-price-breakdown{margin:0 10px 10px;padding:12px 11px}
 #checkoutModal .lc-breakdown-line,#checkoutModal .lc-selected-extra-line{gap:8px;font-size:.7rem}
 #checkoutModal .lc-checkout-price-review{padding:12px}
 #checkoutModal .lc-checkout-totals{padding:10px 12px}
 #checkoutModal .lc-checkout-total-row{font-size:.7rem;gap:8px}
 #checkoutModal .lc-cart-checkout-total strong{font-size:1.06rem}
}
@media(max-width:390px){
 #checkoutModal .lc-breakdown-line,#checkoutModal .lc-selected-extra-line{flex-wrap:wrap}
 #checkoutModal .lc-checkout-total-row{flex-wrap:wrap}
 #checkoutModal .lc-cart-checkout-total{align-items:center}
}

/* Cart product details: match the For You product actions on desktop and mobile. */
#productDetailsModal .product-details-actions {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 48px;
  gap: 9px;
  margin-top: 8px;
  width: 100%;
}
#productDetailsModal .product-details-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-width: 0;
  min-height: 48px;
  padding: 9px 12px;
  border-radius: 12px;
  font: 800 .80rem/1.25 "Segoe UI", Arial, sans-serif;
  cursor: pointer;
  transition: background-color .18s ease, border-color .18s ease, color .18s ease;
}
#productDetailsModal .product-details-btn svg { width: 18px; height: 18px; flex: 0 0 18px; }
#productDetailsModal .product-details-cart { border: 1px solid #e98b40; background: #e98b40; color: #fff; }
#productDetailsModal .product-details-cart:hover:not(:disabled) { border-color: #cf722b; background: #cf722b; color: #fff; }
#productDetailsModal .product-details-save { display: grid; place-items: center; padding: 0; border: 1px solid #ead9c9; background: #fff; color: #947b66; }
#productDetailsModal .product-details-save:hover:not(:disabled),
#productDetailsModal .product-details-save.is-saved { border-color: #e7aa77; background: #fff0e1; color: #ca6c2c; }
#productDetailsModal .product-details-save.is-saved svg { fill: currentColor; }
#productDetailsModal .product-details-btn:disabled { opacity: .58; cursor: not-allowed; }
#productDetailsModal .product-details-btn.is-complete { border-color: #b7dfc8; background: #e9f8f1; color: #20744b; }
#productDetailsModal .seller-details-actions .seller-action-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 43px; border: 1px solid #ebd9c8; border-radius: 11px; background: #fffaf5; color: #8e542d; font-weight: 800; text-decoration: none; }
#productDetailsModal .seller-details-actions .seller-action-btn:hover { border-color: #e5ab7a; background: #fff0e1; color: #9c5423; }
#productDetailsModal .seller-details-actions .seller-action-btn svg { width: 17px; height: 17px; flex-shrink: 0; }
/* Keep item/add-on choice readable; no repeated price equations in the review popup. */
#checkoutModal .checkout-selected-item { display: block; }
#checkoutModal .lc-custom-help { margin: 5px 0 3px; color: #877569; font-size: .72rem; line-height: 1.45; }
#checkoutModal .lc-checkout-simple-note { margin: 0 0 14px; padding: 11px 13px; border: 1px solid #eddfd2; border-radius: 11px; background: #fffaf5; color: #705d4d; font-size: .73rem; line-height: 1.5; }
#checkoutModal.open.lc-closing { opacity: 0; pointer-events: none; transition: opacity .18s ease-out; }
@media(max-width:560px) {
  #productDetailsModal .product-details-actions { grid-template-columns: minmax(0,1fr) 44px; gap: 7px; }
  #productDetailsModal .product-details-btn { min-height: 45px; padding: 8px 7px; font-size: .73rem; }
  #productDetailsModal .product-details-btn svg { width: 16px; height: 16px; flex-basis: 16px; }
  #checkoutModal .lc-custom-help { font-size: .7rem; }
}
@media(prefers-reduced-motion:reduce) { #checkoutModal.open.lc-closing { transition: none; } }

/* Cart header only: small orange kicker and checkbox-column alignment. */
.cart-card-header .cart-card-title{color:#c66e16;font-size:.68rem;font-weight:800;line-height:1.4;letter-spacing:.7px;text-transform:uppercase}
.cart-card-header .select-all-control,.cart-card-header .cart-edit-select-all{flex:1 0 100%;min-width:0;min-height:30px;margin:0;padding-left:4px;gap:9px;align-items:center;font-size:.72rem;font-weight:700}
.cart-card-header .cart-header-checkbox{position:absolute;width:1px;height:1px;margin:0;padding:0;opacity:0}
.cart-card-header .cart-header-checkbox:checked+.cart-select-box,.cart-card-header .cart-header-checkbox:indeterminate+.cart-select-box{border-color:var(--orange);background:var(--orange)}
.cart-card-header .cart-header-checkbox:checked+.cart-select-box::after{content:"✓";color:#fff;font-size:.72rem;font-weight:800;line-height:1}
.cart-card-header .cart-header-checkbox:indeterminate+.cart-select-box::after{content:"−";color:#fff;font-size:.86rem;font-weight:800;line-height:1}
.cart-card-header .cart-header-checkbox:focus-visible+.cart-select-box{outline:3px solid rgba(233,139,64,.3);outline-offset:2px}
.cart-card-header .cart-header-checkbox:disabled+.cart-select-box{background:#eeeae6;border-color:#e2ddd8;opacity:.65;cursor:not-allowed}
.cart-card-header .select-all-control .cart-select-box,.cart-card-header .cart-edit-select-all .cart-select-box{flex:0 0 20px;width:20px;height:20px}
@media(max-width:600px){.cart-card-header .select-all-control,.cart-card-header .cart-edit-select-all{padding-left:2px}}
@media(max-width:480px){.cart-card-header .select-all-control{padding-left:0}.cart-card-header .select-all-control .cart-select-box{flex-basis:18px;width:18px;height:18px}.cart-card-header .cart-edit-select-all{padding-left:2px}}
/* Keep the item count beside Select All, closer to the Cart Items kicker. */
.cart-card-header{row-gap:3px}
.cart-card-header>.cart-card-heading{flex:1 0 100%}
.cart-card-selection-row{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;min-width:0}
.cart-card-header .cart-card-selection-row .select-all-control,.cart-card-header .cart-card-selection-row .cart-edit-select-all{flex:1 1 auto;min-width:0}
.cart-card-header .cart-card-selection-row .cart-card-count{flex:0 0 auto;margin-left:auto}

/* Approved Buyer Review Order: compact product rows, Safari-safe native selects and fixed total. */
#checkoutModal .checkout-selected-item{display:block;min-width:0}
#checkoutModal .lc-review-product-row{display:flex;align-items:center;justify-content:space-between;gap:15px;min-width:0}
#checkoutModal .lc-review-product-info{display:flex;align-items:center;gap:11px;flex:1 1 auto;min-width:0}
#checkoutModal .lc-review-product-photo{display:grid;place-items:center;flex:0 0 60px;width:60px;height:60px;overflow:hidden;border:1px solid #efe5db;border-radius:11px;background:#f8f3ee;color:#a0846b;font-size:1.25rem}
#checkoutModal .lc-review-product-photo img{display:block;width:100%;height:100%;object-fit:cover}
#checkoutModal .lc-review-product-text{flex:1 1 auto;min-width:0}
#checkoutModal .lc-review-product-text .checkout-selected-name{font-size:.85rem;line-height:1.4;overflow-wrap:anywhere}
#checkoutModal .lc-review-product-text .checkout-selected-meta{font-size:.74rem;margin-top:5px}
#checkoutModal .checkout-selected-price{flex:0 0 auto;align-self:center;justify-self:auto;min-width:94px;max-width:37%;padding:0;color:#ad6128;font-size:.89rem;font-weight:850;line-height:1.25;text-align:right;white-space:normal;overflow-wrap:anywhere;font-variant-numeric:tabular-nums}
#checkoutModal .checkout-selected-price::before{content:'Item Total';display:block;margin-bottom:5px;color:#8c7b6b;font-size:.64rem;font-weight:650;letter-spacing:0;text-transform:none}
#checkoutModal .lc-review-product-row + .lc-custom-options{margin-top:13px}
#checkoutModal .lc-checkout-preferences-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));align-items:start;gap:12px;min-width:0}
#checkoutModal .lc-checkout-preferences-grid .checkout-section{margin:0;min-width:0}
#checkoutModal .lc-checkout-select-wrap{position:relative;min-width:0;width:100%}
#checkoutModal .lc-checkout-select-wrap::after{content:'';position:absolute;right:17px;top:46%;width:8px;height:8px;border-right:2px solid #957d69;border-bottom:2px solid #957d69;transform:translateY(-50%) rotate(45deg);pointer-events:none}
#checkoutModal .lc-checkout-select-wrap .checkout-select{appearance:none;-webkit-appearance:none;display:block;width:100%;max-width:100%;min-height:46px;padding:10px 38px 10px 13px;border-radius:11px;background-color:#fff;color:#49382b;font-size:16px!important;font-weight:450;line-height:1.3;text-overflow:ellipsis;color-scheme:light;cursor:pointer}
#checkoutModal .lc-checkout-select-wrap .checkout-select::-ms-expand{display:none}
#checkoutModal .lc-checkout-select-wrap:focus-within::after{border-color:#cd7c39}
#checkoutModal .lc-checkout-preferences-grid .lc-checkout-helper{font-size:.69rem;line-height:1.45}
#checkoutModal .checkout-footer{display:flex;flex-direction:column!important;flex-wrap:nowrap;gap:11px;align-items:stretch;padding:12px 22px max(14px,env(safe-area-inset-bottom));background:#fffdfa}
#checkoutModal .lc-review-footer-total{display:flex;justify-content:space-between;align-items:center;gap:10px;width:100%;min-width:0;padding:0 0 10px;border-bottom:1px solid #efe4d9;color:#645144;font-size:.79rem;font-weight:750;line-height:1.35}
#checkoutModal .lc-review-footer-total strong{flex:0 0 auto;color:#b9692f;font-size:1.13rem;font-weight:850;white-space:nowrap;font-variant-numeric:tabular-nums}
#checkoutModal .lc-review-footer-actions{display:flex;justify-content:flex-end;gap:10px;min-width:0;width:100%}
#checkoutModal .lc-review-footer-actions .checkout-btn{flex:0 1 auto;min-width:120px}
#checkoutModal .lc-cart-request-sent{flex:initial}
@media(max-width:600px){
 #checkoutModal .checkout-footer{padding:11px 15px max(12px,env(safe-area-inset-bottom));gap:9px}
 #checkoutModal .lc-review-product-photo{flex-basis:53px;width:53px;height:53px}
 #checkoutModal .lc-review-product-info{gap:9px}
 #checkoutModal .checkout-selected-price{min-width:81px;max-width:40%;font-size:.8rem}
 #checkoutModal .lc-review-footer-actions .checkout-btn{flex:1 1 0;min-width:0}
 #checkoutModal .lc-checkout-preferences-grid{grid-template-columns:minmax(0,1fr);gap:15px}
}
@media(max-width:360px){
 #checkoutModal .lc-review-product-photo{flex-basis:46px;width:46px;height:46px}
 #checkoutModal .lc-review-product-info{gap:7px}
 #checkoutModal .checkout-selected-price{min-width:78px;max-width:41%;font-size:.76rem}
 #checkoutModal .lc-review-footer-total{font-size:.72rem}
 #checkoutModal .lc-review-footer-total strong{font-size:1rem}
}

/* Compact floating add-on chooser; the product row does not expand when opened. */
#checkoutModal .lc-custom-options{position:relative;margin:12px 0 0;padding:0;border:0;border-radius:11px;background:transparent}
#checkoutModal .lc-addon-trigger{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;min-height:43px;padding:10px 12px;border:1px solid #eadaca;border-radius:11px;background:#fffaf6;color:#694a34;text-align:left;font:750 .77rem/1.4 "Segoe UI",Arial,sans-serif;cursor:pointer;transition:border-color .15s ease,background .15s ease,box-shadow .15s ease}
#checkoutModal .lc-addon-trigger:hover,#checkoutModal .lc-addon-trigger[aria-expanded="true"]{border-color:#dfaa80;background:#fff5e9}
#checkoutModal .lc-addon-trigger:focus-visible{outline:3px solid rgba(233,139,64,.22);outline-offset:2px}
#checkoutModal .lc-addon-trigger-text{flex:1;min-width:0;overflow-wrap:anywhere}
#checkoutModal .lc-addon-selected-count{flex:0 0 auto;padding:3px 7px;border-radius:7px;background:#ffecdb;color:#a65a24;font-size:.67rem;font-weight:800}
#checkoutModal .lc-addon-selected-count[hidden]{display:none}
#checkoutModal .lc-addon-chevron{flex:0 0 14px;width:14px;height:14px;color:#a16e46;transition:transform .15s ease}
#checkoutModal .lc-addon-trigger[aria-expanded="true"] .lc-addon-chevron{transform:rotate(180deg)}
#checkoutModal .lc-addon-popover{position:fixed;z-index:180000;box-sizing:border-box;max-height:390px;min-width:0;display:flex;flex-direction:column;overflow:hidden;border:1px solid #e6d3c1;border-radius:13px;background:#fffdfb;box-shadow:0 16px 42px rgba(64,38,19,.23);color:#493c32}
#checkoutModal .lc-addon-popover[hidden]{display:none!important}
#checkoutModal .lc-addon-popover-head{display:flex;justify-content:space-between;align-items:center;flex:0 0 auto;gap:8px;padding:11px 13px;border-bottom:1px solid #f0e6dc;background:#fff8f1}
#checkoutModal .lc-addon-popover-heading{font-size:.74rem;font-weight:800;color:#764b2c}
#checkoutModal .lc-addon-popover-list{flex:1 1 auto;min-height:0;overflow-y:auto;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;padding:3px 13px 7px}
#checkoutModal .lc-addon-popover .lc-custom-option{padding:9px 0;border-top:1px solid #f1e6dd}
#checkoutModal .lc-addon-popover .lc-custom-option:first-child{border-top:0}
#checkoutModal .lc-addon-popover .lc-custom-choice{min-height:36px;gap:9px;font-size:.76rem;line-height:1.4}
#checkoutModal .lc-addon-popover .lc-custom-choice input{flex:0 0 18px;width:18px;height:18px}
#checkoutModal .lc-addon-popover .lc-custom-choice-price{font-size:.72rem}
#checkoutModal .lc-addon-popover .lc-custom-details{margin:9px 0 3px 26px}
#checkoutModal .lc-addon-popover .lc-custom-details input{font-size:16px!important}
@media(max-width:480px){#checkoutModal .lc-addon-trigger{min-height:44px;padding:10px 11px}#checkoutModal .lc-addon-popover-head{padding:9px 11px}#checkoutModal .lc-addon-popover-list{padding-left:11px;padding-right:11px}}

/* Cart parity with the current For You / Saved Marketplace design.
   Added last so older Cart-only rules cannot override the final palette. */
:root {
  --bg: #FFF8ED;
  --orange: #A55325;
  --orange-dark: #A55325;
  --orange-soft: #FFF0E2;
}
html, body, .page { background-color: #FFF8ED; }
.page > .content { background: #FFF8ED; }
.cart-wrap { max-width: 1600px; min-width: 0; }
.cart-card, .summary-card {
  min-width: 0;
  background: #FFFEFC;
  border-color: #EAE0D7;
  box-shadow: 0 8px 22px rgba(67, 44, 22, .045);
}
.cart-card-header { background: #FFFEFC; }
.cart-header .page-title { color: #382C22; letter-spacing: -.04em; }
.cart-card-title, .summary-title { color: #42352A; }
.cart-item:hover:not(.is-unavailable) { background: #FFFAF5; }
.cart-item.is-selected { background: #FFF8F0; box-shadow: inset 3px 0 0 #A55325; }
.cart-item-price, .summary-total strong, .lc-example-final strong { color: #B76624; }
.cart-edit-toggle { border-color: #E7D8C8; background: #FFFAF5; color: #87532C; }
.cart-edit-toggle:hover, .cart-edit-toggle[aria-pressed="true"] {
  background: #FFF0E2;
  border-color: #DAAD88;
}
.cart-wrap.is-editing .cart-item.is-edit-selected { box-shadow: inset 3px 0 0 #A55325; }
/* Brand gradient on the main cart CTA only; secondary controls stay subdued. */
.summary-checkout-btn,
#checkoutModal .checkout-btn.primary,
#productDetailsModal .product-details-cart:not(.is-complete),
.lc-view-store {
  background: linear-gradient(135deg, #A55325 0%, #C2692E 100%);
  border-color: #A55325;
  color: #FFF8ED;
}
.summary-checkout-btn:hover:not(:disabled),
#checkoutModal .checkout-btn.primary:hover:not(:disabled),
#productDetailsModal .product-details-cart:hover:not(:disabled):not(.is-complete),
.lc-view-store:hover:not(:disabled) {
  background: linear-gradient(135deg, #90461F 0%, #A55325 100%);
  border-color: #90461F;
  color: #FFF8ED;
}
/* Preserve success styling rather than leaving the CTA gradient on completion. */
#checkoutModal .checkout-btn.primary.is-request-sent:disabled,
#productDetailsModal .product-details-cart.is-complete {
  background: #E9F8F1;
  border-color: #B7DFC8;
  color: #20744B;
}
/* iPhone Safari: focused inputs smaller than 16px can trigger page auto-zoom.
   touch-action:manipulation suppresses double-tap zoom on the +/- targets,
   without disabling deliberate browser pinch-to-zoom. Chromium honors it too. */
.cart-item .quantity-control { max-width: 100%; overflow: hidden; }
.cart-item .quantity-control input.qty-input {
  font-size: 16px !important;
  line-height: normal;
  font-variant-numeric: tabular-nums;
  -webkit-text-size-adjust: 100%;
  -moz-appearance: textfield;
  appearance: textfield;
  touch-action: manipulation;
}
.cart-item .quantity-control input.qty-input::-webkit-inner-spin-button,
.cart-item .quantity-control input.qty-input::-webkit-outer-spin-button {
  -webkit-appearance: none;
  margin: 0;
}
.cart-item .quantity-control button.qty-plus,
.cart-item .quantity-control button.qty-minus {
  touch-action: manipulation;
  -webkit-tap-highlight-color: transparent;
  -webkit-user-select: none;
  user-select: none;
  font-size: 16px;
  line-height: 1;
}
.cart-item .quantity-control input.qty-input:focus-visible,
.cart-item .quantity-control button:focus-visible {
  outline: 2px solid #B76624;
  outline-offset: -2px;
}
/* Other editable checkout fields must not auto-zoom when focused on Safari. */
#checkoutModal input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]),
#checkoutModal select, #checkoutModal textarea {
  font-size: 16px !important;
}
@media (max-width: 840px) {
  .cart-wrap { width: 100%; }
}
@media (max-width: 560px) {
  .cart-item .quantity-control button.qty-plus,
  .cart-item .quantity-control button.qty-minus { touch-action: manipulation; }
  .cart-item .quantity-control { height: 40px; }
  .cart-item .quantity-control button.qty-plus,
  .cart-item .quantity-control button.qty-minus { width: 34px; flex: 0 0 34px; }
}

/* Cart header, product popups and shop: parity with final For You / Saved palette.
   The shared buyer_search.php already defines the search control: only the Cart
   header surface lacked the page-scoped cream override. */
.page .buyer-header-tools { background: #FFF8ED; }
:root {
  --lc-accent-dark: #A55325;
  --lc-accent-dark-hover: #C2692E;
  --lc-action-cream: #FFF8ED;
}
/* Saved heart: dark CTA-brown icon on the original pale surface. */
#productDetailsModal .product-details-save.is-saved,
#productDetailsModal .product-details-save:hover,
#lcStoreModal .lc-store-save-btn.is-saved { color: var(--lc-accent-dark); }
#productDetailsModal .product-details-save.is-saved,
#productDetailsModal .product-details-save:hover { background: #FFF0E1; border-color: #D2A98A; }
#lcStoreModal .lc-store-save-btn.is-saved { background: #FFF2E5; }
/* Brown-orange CTA actions, leaving nonprimary and completed-success buttons alone. */
#productDetailsModal .product-details-buy:not(.is-complete),
#productDetailsModal .product-details-cart:not(.is-complete),
#productDetailsModal .lc-view-store,
#lcStoreModal .lc-view-store {
  background: linear-gradient(135deg,var(--lc-accent-dark),var(--lc-accent-dark-hover));
  border-color: var(--lc-accent-dark);
  color: var(--lc-action-cream);
}
#productDetailsModal .product-details-buy:hover:not(:disabled):not(.is-complete),
#productDetailsModal .product-details-cart:hover:not(:disabled):not(.is-complete),
#productDetailsModal .lc-view-store:hover:not(:disabled),
#lcStoreModal .lc-view-store:hover:not(:disabled) {
  background: linear-gradient(135deg,var(--lc-accent-dark-hover),var(--lc-accent-dark));
  border-color: var(--lc-accent-dark-hover);
  color: var(--lc-action-cream);
}
#productDetailsModal .seller-action-btn:not(.lc-view-store) { color: var(--lc-accent-dark); }
/* Product seller and store identities use the same restrained CTA gradient. */
#productDetailsModal .seller-avatar,
#lcStoreModal .lc-store-avatar {
  background: linear-gradient(135deg,var(--lc-accent-dark),var(--lc-accent-dark-hover));
  color: var(--lc-action-cream);
}
#productDetailsModal .seller-details-title svg,
#productDetailsModal .product-reviews-title svg,
#lcStoreModal .lc-store-fact-icon { color: var(--lc-accent-dark); }
#productDetailsModal .product-details-category,
#lcStoreModal .lc-store-item-category,
#lcStoreModal .lc-store-product-meta .lc-store-item-category,
#lcStoreModal .lc-store-role,
#lcStoreModal .lc-eyebrow { color: var(--lc-accent-dark) !important; }
/* Shop-wide rating beneath the avatar (one count from all real seller reviews). */
#lcStoreModal .lc-store-summary { grid-template-columns: 136px minmax(0,1fr) auto; }
#lcStoreModal .lc-store-identity {
  grid-area: avatar;
  width: 136px;
  min-width: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 9px;
  align-self: center;
}
#lcStoreModal .lc-store-identity .lc-store-avatar {
  grid-area: auto;
  align-self: center;
  justify-self: center;
  margin: 0;
}
#lcStoreModal .lc-store-reviews {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-wrap: wrap;
  column-gap: 5px;
  row-gap: 1px;
  max-width: 100%;
  text-align: center;
  color: #624937;
  font-size: .73rem;
  font-weight: 800;
  line-height: 1.35;
  font-variant-numeric: tabular-nums;
}
#lcStoreModal .lc-store-review-star { color: #E3A22E; font-size: 1.02rem; line-height: 1; }
/* Match the Saved / For You shop product cards and their heart, rating and price. */
#lcStoreModal .lc-store-product { background: #FFFEFC; }
#lcStoreModal .lc-store-product-meta strong { color: #433529; }
#lcStoreModal .lc-store-product-meta .lc-store-item-price { color: #B86629; }
#lcStoreModal .lc-store-product-meta .lc-store-item-rating { color: #957A63 !important; }
#lcStoreModal .lc-store-save-btn { color: #6F6158; }
#lcStoreModal .lc-store-save-btn.is-saved { color: var(--lc-accent-dark); background: #FFF2E5; }
#lcStoreModal .lc-store-out-of-stock { color: #9B693E; background: rgba(255,251,246,.96); }
@media (max-width:760px) {
  #lcStoreModal .lc-store-summary { grid-template-columns: 110px minmax(0,1fr); grid-template-areas: "avatar details" "avatar status"; }
  #lcStoreModal .lc-store-identity { width: 110px; }
  #lcStoreModal .lc-store-reviews { font-size: .67rem; }
}
@media (max-width:560px) {
  #lcStoreModal .lc-store-products { grid-template-columns: repeat(2,minmax(0,1fr)); }
}
@media (max-width:360px) {
  #lcStoreModal .lc-store-summary { grid-template-columns: 88px minmax(0,1fr); }
  #lcStoreModal .lc-store-identity { width: 88px; }
  #lcStoreModal .lc-store-reviews { font-size: .63rem; }
}
@media (max-width:315px) {
  #lcStoreModal .lc-store-products { grid-template-columns: minmax(0,1fr); }
}

/* Checkout confirmation: clickable message links in the original bottom toast. */
#toast.marketplace-toast.show.lc-order-success-toast {
  pointer-events: auto;
  width: max-content;
  max-width: min(440px, calc(100vw - 28px));
  max-height: min(48dvh, 340px);
  overflow-y: auto;
  z-index: 190000;
  padding: 12px 16px;
  text-align: left;
}
#toast .lc-cart-toast-action {
  display: block;
  color: #fff;
  font-weight: 750;
  text-decoration: none;
  line-height: 1.55;
  border-radius: 7px;
  outline-offset: 4px;
}
#toast .lc-cart-toast-action:hover,
#toast .lc-cart-toast-action:focus-visible { text-decoration: underline; }
#toast .lc-cart-toast-heading { display: block; margin-bottom: 6px; font-weight: 800; }
#toast .lc-cart-toast-seller-link { padding: 6px 0; }
#toast .lc-cart-toast-seller-link + .lc-cart-toast-seller-link { border-top: 1px solid rgba(255,255,255,.22); }
  </style>
</head>
<body>
  <div class="page">
    <?php require __DIR__ . '/buyer_nav.php'; ?>
    <main class="content">
      <?php require __DIR__ . '/buyer_search.php'; ?>
      <div class="cart-wrap">
        <header class="cart-header">
          <div class="lc-cart-page-heading">
            <h1 class="page-title">Your Cart</h1>
          </div>
          <button id="editCartToggle" class="cart-edit-toggle" type="button" aria-pressed="false" aria-controls="cartEditToolbar" <?php echo empty($cartItems) ? 'disabled' : ''; ?>>
            <svg class="cart-edit-icon cart-edit-icon-pencil" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L9 17l-4 1 1-4Z"/></svg>
            <svg class="cart-edit-icon cart-edit-icon-done" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="m20 6-11 11-5-5"/></svg>
            <span class="cart-edit-label">Edit</span>
          </button>
        </header>
        <?php if ($errorMessage !== ''): ?>
          <div class="error-box"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <div class="cart-layout">
          <section class="cart-card">
            <div class="cart-card-header">
              <div class="cart-card-heading">
                <div class="cart-card-title">Cart Items</div>
              </div>
              <div class="cart-card-selection-row">
                <label class="select-all-control" for="selectAllCart">
                  <input class="cart-header-checkbox" id="selectAllCart" type="checkbox">
                  <span class="cart-select-box" aria-hidden="true"></span>
                  <span>Select All</span>
                </label>
                <label class="cart-edit-select-all" id="editAllWrap">
                  <input class="cart-header-checkbox" id="editAllCart" type="checkbox">
                  <span class="cart-select-box" aria-hidden="true"></span>
                  <span>Select all</span>
                </label>
                <span class="cart-card-count"><?php echo (int)$cartCount; ?> item(s)</span>
              </div>
            </div>
            <?php if (empty($cartItems)): ?>
              <div class="empty-cart">
                <div class="empty-cart-icon">🛒</div>
                <h2>Your cart is empty</h2>
                <a class="browse-btn" href="foryou.php">Browse Marketplace</a>
              </div>
            <?php else: ?>
              <div id="cartItems">
                <?php foreach ($cartItems as $item): ?>
                  <?php
                    $available = $item['is_available'];
                    $enoughStock = $item['quantity_within_stock'];
                    $lineTotal = $available
                      ? $item['cost'] * min($item['quantity'], max(1, $item['stock']))
                      : 0;
                  ?>
                  <article
                    class="cart-item <?php echo (!$available || !$enoughStock) ? 'is-unavailable' : ''; ?><?php echo !empty($item['is_archived']) ? ' is-archived' : ''; ?><?php echo !empty($item['is_out_of_stock']) ? ' is-out-of-stock' : ''; ?>"
                    data-cart-id="<?php echo (int)$item['cart_id']; ?>"
                    data-product-id="<?php echo (int)$item['product_id']; ?>"
                    data-product-name="<?php echo htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-category="<?php echo htmlspecialchars($item['craft_category'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-seller="<?php echo htmlspecialchars($item['seller_name'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-location="<?php echo htmlspecialchars($item['location'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-price="<?php echo htmlspecialchars((string)$item['cost'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-stock="<?php echo (int)$item['stock']; ?>"
                    data-available="<?php echo $available ? '1' : '0'; ?>"
                    data-seller-id="<?php echo (int)$item['seller_id']; ?>"
                    data-archived="<?php echo !empty($item['is_archived']) ? '1' : '0'; ?>"
                    data-out-of-stock="<?php echo !empty($item['is_out_of_stock']) ? '1' : '0'; ?>"
                    data-photo="<?php echo htmlspecialchars($item['product_photo'], ENT_QUOTES, 'UTF-8'); ?>"
                  >
                    <label class="cart-select-wrap" aria-label="Select <?php echo htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8'); ?>">
                      <input
                        class="cart-select"
                        type="checkbox"
                        <?php echo ($available && $enoughStock) ? '' : 'disabled'; ?>
                      >
                      <span class="cart-select-box"></span>
                    </label>
                    <label class="cart-edit-check-wrap" aria-label="Choose <?php echo htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8'); ?> for editing">
                      <input class="cart-edit-check" type="checkbox">
                      <span class="cart-select-box"></span>
                    </label>
                    <div class="cart-item-image-wrap<?php echo empty($item['is_archived']) ? ' lc-cart-click' : ''; ?>" <?php echo empty($item['is_archived']) ? 'role="button" tabindex="0" aria-label="View product details"' : ''; ?>>
                      <?php if (!empty($item['is_archived'])): ?>
                        <div class="cart-item-placeholder lc-archived-photo">Unavailable</div>
                      <?php elseif ($item['product_photo'] !== ''): ?>
                        <img
                          class="cart-item-image"
                          src="../<?php echo htmlspecialchars($item['product_photo'], ENT_QUOTES, 'UTF-8'); ?>"
                          alt="<?php echo htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8'); ?>"
                          loading="lazy"
                        >
                      <?php else: ?>
                        <div class="cart-item-placeholder">🛍</div>
                      <?php endif; ?>
                    </div>
                    <div class="cart-item-main<?php echo empty($item['is_archived']) ? ' lc-cart-click' : ''; ?>" <?php echo empty($item['is_archived']) ? 'role="button" tabindex="0" aria-label="View product details"' : ''; ?>>
                      <?php if (empty($item['is_archived'])): ?>
                        <span class="cart-item-category"><?php echo htmlspecialchars($item['craft_category'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                      <div class="cart-item-name"><?php echo htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                      <?php if (empty($item['is_archived'])): ?>
                        <div class="cart-item-seller">by <?php echo htmlspecialchars($item['seller_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                      <?php if (empty($item['is_archived']) && $item['location'] !== ''): ?>
                        <div class="cart-item-location"><?php echo htmlspecialchars($item['location'], ENT_QUOTES, 'UTF-8'); ?></div>
                      <?php endif; ?>
                      <?php if ($available): ?>
                        <div class="stock-line"><?php echo (int)$item['stock']; ?> available now</div>
                        <?php if (!$enoughStock): ?>
                          <div class="stock-warning">
                            Only <?php echo (int)$item['stock']; ?> available. Reduce quantity to continue.
                          </div>
                        <?php endif; ?>
                      <?php elseif (!empty($item['is_out_of_stock'])): ?>
                        <div class="stock-line out">Out of Stock</div>
                      <?php else: ?>
                        <div class="stock-line out">Unavailable</div>
                      <?php endif; ?>
                    </div>
                    <div class="cart-item-actions">
                      <?php if (empty($item['is_archived'])): ?>
                        <div class="lc-cart-base-label">Price each</div>
                        <div class="cart-item-price"><?php echo cartMoney($item['cost']); ?></div>
                        <?php endif; ?>
                      <div class="quantity-control">
                        <button type="button" class="qty-minus" aria-label="Decrease quantity" <?php echo $available ? '' : 'disabled'; ?>>−</button>
                        <input
                          class="qty-input"
                          type="number"
                          min="1"
                          max="<?php echo min(999, max(1, (int)$item['stock'])); ?>"
                          value="<?php echo (int)$item['quantity']; ?>"
                          inputmode="numeric"
                          <?php echo $available ? '' : 'disabled'; ?>
                        >
                        <button type="button" class="qty-plus" aria-label="Increase quantity" <?php echo $available ? '' : 'disabled'; ?>>+</button>
                      </div>
                      <div class="item-total">
                        <?php echo $available ? 'Items total: ' . cartMoney($lineTotal) : (!empty($item['is_out_of_stock']) ? 'Out of Stock' : 'Unavailable'); ?>
                      </div>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
              <div class="cart-search-empty" id="cartSearchEmpty" hidden>
                No cart items match your search.
              </div>
            <?php endif; ?>
          </section>
          <aside class="summary-card">
            <div class="cart-edit-toolbar" id="cartEditToolbar" aria-hidden="true">
              <div class="cart-edit-toolbar-inner">
                <div class="cart-edit-toolbar-heading"><strong>Manage Cart</strong><span id="editCartCount">0 selected</span></div>
                <div class="cart-edit-toolbar-actions">
                  <button id="bulkMoveSaved" type="button" disabled><span aria-hidden="true">♡</span> Move to Saved</button>
                  <button id="bulkRemove" class="danger" type="button" disabled>Remove</button>
                </div>
                <div class="cart-edit-toolbar-note" id="cartEditFeedback" role="status" aria-live="polite"></div>
              </div>
            </div>
            <div class="summary-heading">
              <h2 class="summary-title">Price Preview</h2>
            </div>
            <div class="summary-row">
              <span>Selected products</span>
              <strong id="summarySelectedProducts">0</strong>
            </div>
            <div class="summary-row">
              <span>Selected quantity</span>
              <strong id="summarySelectedQuantity">0</strong>
            </div>
            <div class="summary-total">
              <span>Items total (no add-ons)</span>
              <strong id="summarySubtotal">₱0.00</strong>
            </div>
            <button class="summary-checkout-btn" id="checkoutSelected" type="button" disabled>
              <span class="lc-review-button-label">Select products to continue</span>
              <span aria-hidden="true">→</span>
            </button>
          </aside>
        </div>
      </div>
    </main>
  </div>
  <div class="modal" id="checkoutModal" aria-hidden="true">
    <div class="modal-backdrop" id="checkoutBackdrop"></div>
    <div class="checkout-panel" role="dialog" aria-modal="true" aria-labelledby="checkoutTitle">
      <header class="checkout-header">
        <div class="lc-cart-checkout-heading">
          <span class="lc-cart-checkout-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8h14l-1 12H6L5 8ZM9 9V6a3 3 0 0 1 6 0v3"/><path d="m9 15 2 2 4-4"/></svg></span>
          <div>
            <h2 id="checkoutTitle">Review Your Order</h2>
          </div>
        </div>
        <button class="close-modal" id="closeCheckout" type="button" aria-label="Close Request Order"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
      </header>
      <div class="checkout-body" id="checkoutScrollArea">
        <section class="lc-cart-checkout-block" aria-labelledby="checkoutItemsHeading">
          <div class="lc-cart-checkout-section-title">
            <h3 id="checkoutItemsHeading"><span class="lc-checkout-step">1</span> Your Items &amp; Add-ons</h3>
            <span id="checkoutSelectionInfo" class="lc-cart-checkout-count"></span>
          </div>
          <div class="checkout-selected-list" id="checkoutSelectedList"></div>
        </section>
        <section class="lc-cart-checkout-block" aria-labelledby="checkoutPreferencesHeading">
          <div class="lc-cart-checkout-section-title"><h3 id="checkoutPreferencesHeading"><span class="lc-checkout-step">2</span> Delivery &amp; Payment Methods</h3></div>
          <div class="lc-checkout-preferences-grid">
            <div class="checkout-section lc-checkout-field" id="checkoutDeliveryField">
              <label class="checkout-label" for="checkoutDelivery">Delivery Method <span class="lc-checkout-required" aria-hidden="true">*</span></label>
              <div class="lc-checkout-select-wrap">
                <select class="checkout-select" id="checkoutDelivery" required aria-describedby="checkoutDeliveryError">
                  <option value="">Choose delivery method</option>
                  <option value="meetup">Meetup</option>
                  <option value="pickup">Pickup</option>
                  <option value="third_party_delivery">Third-Party Delivery</option>
                </select>
              </div>
              <p class="lc-checkout-field-error" id="checkoutDeliveryError" hidden role="status"></p>
            </div>
            <div class="checkout-section lc-checkout-field" id="checkoutPaymentField">
              <label class="checkout-label" for="checkoutPayment">Payment Method <span class="lc-checkout-required" aria-hidden="true">*</span></label>
              <div class="lc-checkout-select-wrap">
                <select class="checkout-select" id="checkoutPayment" required aria-describedby="paymentMethodNote checkoutPaymentError">
                  <option value="">Choose payment method</option>
                  <option value="paymongo">QR Ph</option>
                  <option value="cash">Cash</option>
                </select>
              </div>
              <p class="lc-checkout-helper" id="paymentMethodNote" aria-live="polite">Cash is available for Meetup or Pickup. QR Ph is available for all delivery methods.</p>
              <p class="lc-checkout-field-error" id="checkoutPaymentError" hidden role="status"></p>
            </div>
          </div>
        </section>
        <div class="checkout-inline-error" id="checkoutInlineError" role="alert" hidden></div>
      </div>
      <footer class="checkout-footer">
        <div class="lc-review-footer-total" aria-label="Estimated total of all selected products and add-ons">
          <span>Estimated Products Total</span>
          <strong id="checkoutTotal" aria-live="polite">₱0.00</strong>
        </div>
        <div class="lc-cart-request-sent" id="checkoutRequestSent" role="status" aria-live="polite" hidden>
          <span class="lc-cart-request-sent-icon" aria-hidden="true">✓</span>
          <span><strong>Request Sent!</strong> Continue in Messages.</span>
        </div>
        <div class="lc-review-footer-actions">
          <button class="checkout-btn" id="cancelCheckout" type="button">Cancel</button>
          <button class="checkout-btn primary" id="placeOrderButton" type="button">Send Order Request</button>
        </div>
      </footer>
    </div>
  </div>
  <div class="marketplace-toast" id="toast" role="status" aria-live="polite" aria-atomic="true"></div>
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
                <a class="seller-action-btn" id="sellerDetailsChat" href="messages.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5Z"/></svg>Message</a>
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
  <div class="responsive-tooltip" id="responsiveTooltip" role="tooltip" aria-hidden="true"></div>
  <div class="marketplace-toast" id="marketplaceToast" role="status" aria-live="polite"></div>
  <script>
(() => {
'use strict';
const $ = id => document.getElementById(id);
const productModal=$('productDetailsModal'), productClose=$('productDetailsClose');
const storeModal=$('lcStoreModal'), storeClose=$('lcStoreClose'), storeScroll=$('lcStoreScroll');
const photoModal=$('lcPhotoLightbox'),photoClose=$('lcPhotoClose'),photoEnlarged=$('lcPhotoEnlarged');
const detailCart=$('productDetailsCart'), detailSave=$('productDetailsSave');
const toast=$('marketplaceToast'),csrf=<?php echo json_encode($_SESSION['marketplace_csrf'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const responsiveTooltip=$('responsiveTooltip');
let selectedProduct=null,toastTimeout=null;
let selectedStoreId=0,storeFetchToken=0,reviewsFetchToken=0,reviewOffset=0,reviewBusy=false,reviewHasMore=false;
const savedStates=new Map(),storeCache=new Map();
// Each product, shop and photo overlay is a navigation level.
// Using actual browser history means Android/iPhone Back closes one level at a time.
const navId='lc-market-'+Date.now()+'-'+Math.random().toString(36).slice(2);
const frames=[];let depth=0,needsCartReload=false;
const stateAt=n=>({...window.history.state,lcMarket:{navId,depth:n}});
try { window.history.replaceState(stateAt(0),'',window.location.href); }catch(_){ }
function money(amount){return '₱'+Number(amount||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});}
function productLocation(product){return [product?.barangay,product?.city].filter(Boolean).join(', ');}
function stars(value){const n=Math.max(0,Math.min(5,Math.round(Number(value)||0)));return '★'.repeat(n)+'☆'.repeat(5-n);}
function showToast(message,type=''){clearTimeout(toastTimeout);toast.textContent=message;toast.className='marketplace-toast show '+type;toastTimeout=setTimeout(()=>toast.classList.remove('show'),2800);}
function showMessage(node,message){if(node)node.textContent=message||'';}
function setVisible(node,show,kind='is-open'){if(!node)return;node.classList.toggle(kind,Boolean(show));node.setAttribute('aria-hidden',show?'false':'true');}
function viewportFit(){
  const vv=window.visualViewport;
  const h=vv?vv.height:window.innerHeight;
  document.documentElement.style.setProperty('--lc-vh',Math.max(240,h)+'px');
  document.documentElement.style.setProperty('--lc-vtop',vv?Math.max(0,vv.offsetTop)+'px':'0px');
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
  const panel=input.closest('.checkout-body,.lc-product-info-scroll,.lc-product-layout,.lc-store-content');
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
async function postAction(form,url='cart.php'){
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
  const st=event.state?.lcMarket;
  const newDepth=st?.navId===navId?Number(st.depth):0;
  depth=Math.max(0,Math.min(frames.length,Number.isFinite(newDepth)?newDepth:0));
  if(depth===0&&needsCartReload){window.location.reload();return;}
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
  document.body.classList.toggle('modal-open',modalVisible);
  document.documentElement.classList.toggle('modal-open',modalVisible);
  hideTooltip();
  if(frame?.type==='store' && selectedStoreId!==Number(frame.sellerId))fillStore(frame.sellerId);
  if(frame?.type==='product' && frame.product){
    if(!selectedProduct||Number(selectedProduct.product_id)!==Number(frame.product.product_id)||Boolean(selectedProduct.in_cart)!==Boolean(frame.product.in_cart)||Number(selectedProduct.cart_id||0)!==Number(frame.product.cart_id||0)){
      selectedProduct={...frame.product};renderProduct(selectedProduct);
      if(!selectedProduct.is_archived)loadReviews(true);
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
  if(!selectedProduct.is_archived)loadReviews(true);
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
  if(selectedProduct?.is_archived||!selectedProduct?.product_photo||currentFrame()?.type!=='product')return;
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
  const archived=Boolean(product.is_archived);
  productModal.classList.toggle('lc-archived-view',archived);
  if(archived){
    $('productDetailsImageWrap')?.replaceChildren();
    $('productDetailsName').textContent=product.product_name||'Product';
    $('productDetailsPrice').textContent='';
    $('productDetailsStock').textContent='Unavailable';
    $('productDetailsLocation').textContent='';
    $('productDetailsCategory').textContent='';
    $('productDetailsDescription').textContent='';
    $('sellerDetailsName').textContent='';
    $('sellerDetailsLocation').textContent='';
    $('productReviewsList').replaceChildren();
    detailCart.disabled=true;detailSave.disabled=true;
    return;
  }
  selectProductPhoto(product);
  $('productDetailsName').textContent=product.product_name||'Product';
  $('productDetailsPrice').textContent=product.cost||money(product.cost_value);
  $('productDetailsLocation').textContent=productLocation(product)||'Location unavailable';
  $('productDetailsCategory').textContent=product.craft_category||'Handmade';
  $('productDetailsDescription').textContent=product.description||'No description provided.';
  $('productDetailsStock').textContent=product.is_out_of_stock||Number(product.stock)===0?'Out of Stock':(product.is_available===false?'Unavailable':'Available: '+Math.max(0,Number(product.stock)||0));
  $('sellerDetailsName').textContent=product.seller_business||product.seller_name||product.seller_person||'Local Seller';
  const chatLink=$('sellerDetailsChat'),sellerId=Number(product.seller_id);chatLink.href=Number.isSafeInteger(sellerId)&&sellerId>0?'messages.php?seller_id='+encodeURIComponent(String(sellerId)):'messages.php';
  $('sellerDetailsLocation').textContent=productLocation(product)||'Taguig City';
  const count=Number(product.review_count)||0,avg=Number(product.avg_rating)||0;
  $('productDetailsRating').replaceChildren(document.createTextNode(stars(avg)+'  '+(count?avg.toFixed(1)+' · '+count+' reviews':'No reviews yet')));
  detailCart.disabled=product.is_available===false||!(Number(product.stock)>0);
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
    const form=new FormData();form.append('marketplace_action','load_reviews');form.append('product_id',String(productId));form.append('offset',String(offset));
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
    try{const form=new FormData();form.append('marketplace_action','load_store');form.append('seller_id',String(sellerId));const data=await postAction(form);
      storeCache.set(Number(sellerId),data);
      if(token!==storeFetchToken||selectedStoreId!==Number(sellerId))return;renderStore(data);
    }catch(e){if(token!==storeFetchToken)return;$('lcStoreSummary').textContent=e.message;$('lcStoreProducts').replaceChildren();}
  })();
}
function renderStore(data){
  const shop=data.shop||{},items=data.products||[],name=shop.businessname||shop.seller_name||'Local Store';
  $('lcStoreTitle').textContent='Store Profile';
  $('lcStoreSummary').replaceChildren();
  const avatar=document.createElement('div');avatar.className='lc-store-avatar';avatar.textContent=String(name).trim().slice(0,2).toUpperCase();
  const identity=document.createElement('div');identity.className='lc-store-identity';
  const shopReviewCount=Math.max(0,Number(shop.review_count)||0);
  const shopRating=Math.max(0,Math.min(5,Number(shop.avg_rating)||0));
  const shopReviews=document.createElement('div');shopReviews.className='lc-store-reviews';
  const reviewStar=document.createElement('span');reviewStar.className='lc-store-review-star';reviewStar.textContent=shopReviewCount?'★':'☆';
  const reviewText=document.createElement('span');reviewText.textContent=shopReviewCount
    ?shopRating.toFixed(1)+' ('+shopReviewCount+' '+(shopReviewCount===1?'review':'reviews')+')'
    :'No reviews yet';
  shopReviews.setAttribute('aria-label',shopReviewCount?shopRating.toFixed(1)+' out of 5 stars from '+shopReviewCount+' reviews':'No store reviews yet');
  shopReviews.append(reviewStar,reviewText);identity.append(avatar,shopReviews);
  const meta=document.createElement('div');meta.className='lc-store-profile-details';const role=document.createElement('span');role.className='lc-store-role';role.textContent=shop.is_active===false?'LOCAL ARTISAN':'VERIFIED LOCAL ARTISAN';const nameEl=document.createElement('div');nameEl.className='lc-store-summary-name';nameEl.textContent=name;
  const sub=document.createElement('div');sub.className='lc-store-summary-meta';sub.textContent=[shop.craftcategory,shop.barangay,shop.city||'Taguig City'].filter(Boolean).join(' · ');meta.append(role,nameEl,sub);const active=document.createElement('span');active.className='lc-store-active';active.innerHTML='<span class="lc-store-active-dot"></span>'+(shop.is_active===false?'Inactive Store':'Active Store');$('lcStoreSummary').append(identity,meta,active);
  const facts=$('lcStoreFacts');facts.replaceChildren(storeFact('Seller Name',shop.seller_name),storeFact('Craft Category',shop.craftcategory),storeFact('Business Description',shop.description,true),storeFact('Business Location',[shop.barangay,shop.city||'Taguig City'].filter(Boolean).join(', '),true));
  $('lcStoreMore').hidden=false;$('lcStoreProductCount').textContent=items.length+' items';
  const listing=$('lcStoreProducts');listing.replaceChildren();
  if(!items.length){const empty=document.createElement('div');empty.className='lc-loading';empty.textContent='No products listed yet.';listing.append(empty);}
  else items.forEach(item=>listing.append(makeStoreProduct(item)));
}
function openStore(sellerId){if(selectedProduct?.is_archived)return;if(!Number(sellerId))return;selectedStoreId=0;push('store',{sellerId:Number(sellerId)});}
$('lcStoreMore')?.addEventListener('click',()=>{const info=$('lcStoreInfo'),exp=info.hidden;info.hidden=!exp;const btn=$('lcStoreMore');btn.setAttribute('aria-expanded',exp?'true':'false');btn.querySelector('span').textContent=exp?'Hide Business Details':'See Business Details';});
storeClose?.addEventListener('click',()=>{if(currentFrame()?.type==='store')backOne();});
$('sellerDetailsName')?.addEventListener('click',()=>openStore(selectedProduct?.seller_id));
$('lcSellerAvatar')?.addEventListener('click',()=>openStore(selectedProduct?.seller_id));
$('sellerDetailsStore')?.addEventListener('click',()=>openStore(selectedProduct?.seller_id));
function syncSaveButton(productId,saved){
  savedStates.set(Number(productId),Boolean(saved));
  document.querySelectorAll('[data-save-product="'+productId+'"]').forEach(btn=>{btn.classList.toggle('is-saved',saved);btn.textContent=saved?'♥':'♡';btn.title=saved?'Saved':'Save product';btn.setAttribute('aria-label',saved?'Remove from saved':'Save product');btn.setAttribute('aria-pressed',saved?'true':'false');});
  if(Number(selectedProduct?.product_id)===Number(productId)){selectedProduct.is_saved=saved;detailSave.classList.toggle('is-saved',saved);detailSave.setAttribute('aria-pressed',saved?'true':'false');detailSave.setAttribute('aria-label',saved?'Remove from saved':'Save product');detailSave.title=saved?'Saved':'Save product';}
}
window.addEventListener('lc-cart-moved-to-saved',event=>{
  for(const id of event.detail?.productIds||[])syncSaveButton(Number(id),true);
});
const busySaveIds=new Set();
async function toggleSaved(productId){if(busySaveIds.has(productId))return;busySaveIds.add(productId);detailSave.disabled=true;
  try{const form=new FormData();form.append('marketplace_action','toggle_saved');form.append('product_id',String(productId));const data=await postAction(form);syncSaveButton(productId,Boolean(data.saved));showToast(data.saved?'Saved to your list.':'Removed from Saved.','success');}
  catch(e){showToast(e.message,'error');}finally{busySaveIds.delete(productId);detailSave.disabled=false;}
}
detailSave.addEventListener('click',()=>{if(selectedProduct)toggleSaved(Number(selectedProduct.product_id));});
document.querySelectorAll('[data-save-product]').forEach(btn=>btn.addEventListener('click',event=>{event.stopPropagation();event.preventDefault();toggleSaved(Number(btn.dataset.saveProduct));}));
const cartBusyIds=new Set();
detailCart.addEventListener('click',async()=>{
  if(!selectedProduct||selectedProduct.is_available===false||stockMax()<=0)return;
  const productId=Number(selectedProduct.product_id);
  // Do not race a currently saving +/- quantity request with this Add to Cart request.
  if(window.lcCartHasPendingQuantity?.()){
    showToast('Wait for your quantity update to finish.','error');
    return;
  }
  if(cartBusyIds.has(productId))return;cartBusyIds.add(productId);
  detailCart.disabled=true;detailCart.innerHTML='<span class="lc-spinner"></span><span>Adding...</span>';
  try{const form=new FormData();form.append('marketplace_action','add_to_cart');form.append('product_id',String(productId));form.append('quantity','1');const result=await postAction(form);
    const row=document.querySelector('.cart-item[data-product-id="'+productId+'"]');
    if(row){
      // The Cart controller owns quantities, row totals, and the selected-price preview.
      // Send the confirmed DB quantity so its in-flight +/- state stays in sync.
      window.dispatchEvent(new CustomEvent('lc-cart-updated',{
        detail:{productId,cartQuantity:Number(result.cart_quantity)}
      }));
    }else needsCartReload=true;
    detailCart.classList.add('is-complete');detailCart.innerHTML='<span>✓ Added to Cart</span>';
    showToast(row?'Cart quantity updated.':'Added to Cart.','success');
    setTimeout(()=>{cartBusyIds.delete(productId);if(Number(selectedProduct?.product_id)!==productId)return;detailCart.disabled=selectedProduct.is_available===false||stockMax()<=0;detailCart.classList.remove('is-complete');detailCart.innerHTML='<span>Add to Cart</span>';},1800);
  }catch(e){cartBusyIds.delete(productId);if(Number(selectedProduct?.product_id)===productId){detailCart.disabled=selectedProduct.is_available===false||stockMax()<=0;detailCart.textContent='Add to Cart';}showToast(e.message,'error');}
});
// Quantity accepts pasted/tapped digits on iPhone Safari; never uses stepper arrows.
function stockMax(){return Math.max(0,Math.trunc(Number(selectedProduct?.stock)||0));}
// Message opens the seller's existing conversation in the Buyer Messages module.
// This is a deliberate navigation action, unlike Saved or Cart actions.
// Product cards remain keyboard accessible; seller name opens shop rather than searching all products.
document.querySelectorAll('.product-card').forEach(card=>{
  card.setAttribute('tabindex','0');card.setAttribute('role','button');card.setAttribute('aria-label','View '+(card.dataset.name||'product'));
  function choose(){try{const product=JSON.parse(card.dataset.product);product.cost_value=Number(product.cost_value||0);openProduct(product);}catch(_){showToast('Unable to open product.','error');}}
  card.addEventListener('click',e=>{if(e.target.closest('button,a,input,select,textarea'))return;choose();});
  card.addEventListener('keydown',e=>{if(e.target!==card)return;if(e.key==='Enter'||e.key===' '){e.preventDefault();choose();}});
  const label=card.querySelector('.seller-tag');if(label){const btn=document.createElement('button');btn.type='button';btn.className='lc-card-store';btn.textContent=label.textContent.trim().replace(/^by\s+/i,'');btn.setAttribute('aria-label','View store '+btn.textContent);btn.addEventListener('click',e=>{e.stopPropagation();try{const data=JSON.parse(card.dataset.product);openStore(data.seller_id);}catch(_){}});label.replaceChildren(document.createTextNode('by '),btn);}
});
productClose.addEventListener('click',closeProduct);
// Tapping dark backdrop won't discard a customer's partially completed form.
document.addEventListener('keydown',event=>{
  if(event.key!=='Escape')return;
  if(responsiveTooltip?.classList.contains('is-visible')){hideTooltip();return;}
  if(depth>0){event.preventDefault();backOne();}
});
// Existing tooltips: one floating layer, anchored to viewport and clipped at screen edges.
let activeTooltip=null,tooltipPinned=false;
function hideTooltip(){if(activeTooltip)activeTooltip.setAttribute('aria-expanded','false');activeTooltip=null;tooltipPinned=false;responsiveTooltip?.classList.remove('is-visible');responsiveTooltip?.setAttribute('aria-hidden','true');}
function positionTooltip(){if(!activeTooltip||!responsiveTooltip)return;const rect=activeTooltip.getBoundingClientRect();responsiveTooltip.style.left='0';responsiveTooltip.style.top='0';responsiveTooltip.style.maxWidth=Math.min(290,window.innerWidth-24)+'px';
  const w=responsiveTooltip.offsetWidth,h=responsiveTooltip.offsetHeight;const left=Math.max(10,Math.min(window.innerWidth-w-10,rect.left+rect.width/2-w/2));
  const isTop=rect.top>h+15,top=isTop?rect.top-h-9:Math.min(window.innerHeight-h-10,rect.bottom+9);
  responsiveTooltip.dataset.placement=isTop?'top':'bottom';responsiveTooltip.style.left=left+'px';responsiveTooltip.style.top=Math.max(10,top)+'px';responsiveTooltip.style.setProperty('--arrow-x',(rect.left+rect.width/2-left)+'px');
}
function showTooltip(btn,pinned=false){if(!btn.dataset.tooltip)return;hideTooltip();activeTooltip=btn;tooltipPinned=pinned;responsiveTooltip.textContent=btn.dataset.tooltip;responsiveTooltip.classList.add('is-visible');responsiveTooltip.setAttribute('aria-hidden','false');btn.setAttribute('aria-expanded','true');requestAnimationFrame(positionTooltip);}
document.querySelectorAll('[data-tooltip]').forEach(btn=>{btn.addEventListener('mouseenter',()=>showTooltip(btn));btn.addEventListener('mouseleave',()=>{if(!tooltipPinned)hideTooltip();});btn.addEventListener('click',e=>{e.stopPropagation();if(activeTooltip===btn&&tooltipPinned)hideTooltip();else showTooltip(btn,true);});});
document.addEventListener('click',e=>{if(!e.target.closest('[data-tooltip]'))hideTooltip();});
window.addEventListener('scroll',()=>{if(activeTooltip)positionTooltip();},true);
// Keep native phone back in charge of closing one overlay at a time.
window.addEventListener('pageshow',event=>{if(event.persisted)render();});
// Cart entry is a separate mode from a product opened from Store Profile.
async function openCartPreview(row){
  if(!row||row.dataset.archived==='1'||document.querySelector('.cart-wrap')?.classList.contains('is-editing')||row.dataset.loading==='1')return;
  const cartId=Number(row.dataset.cartId||0);
  if(!cartId)return;
  row.dataset.loading='1';
  try{
    const form=new FormData();form.append('marketplace_action','load_cart_product');form.append('cart_id',String(cartId));
    const data=await postAction(form);
    const product=data.product;
    if(!product)return;
    if(product.is_archived&&row.dataset.archived!=='1'){
      row.dataset.archived='1';row.dataset.available='0';row.dataset.stock='0';row.dataset.price='0';
      row.dataset.seller='';row.dataset.location='';row.dataset.photo='';row.dataset.sellerId='0';
      row.classList.add('is-archived','is-unavailable');
      row.querySelector('.cart-select')?.setAttribute('disabled','disabled');
      row.querySelectorAll('.qty-input,.qty-plus,.qty-minus').forEach(el=>el.disabled=true);
      row.querySelector('.cart-item-seller')?.remove();row.querySelector('.cart-item-location')?.remove();
      row.querySelector('.cart-item-category')?.remove();row.querySelector('.cart-item-price')?.remove();
      const image=row.querySelector('.cart-item-image-wrap');if(image)image.textContent='Unavailable';
      row.querySelector('.stock-line')?.replaceChildren(document.createTextNode('Unavailable'));
      window.dispatchEvent(new Event('lc-cart-updated'));
    }
    if(product.is_out_of_stock && row.dataset.outOfStock!=='1'){
      row.dataset.outOfStock='1';row.dataset.available='0';row.dataset.stock='0';
      row.classList.add('is-out-of-stock','is-unavailable');
      const check=row.querySelector('.cart-select');if(check){check.disabled=true;check.checked=false;}
      row.querySelectorAll('.qty-input,.qty-plus,.qty-minus').forEach(el=>el.disabled=true);
      const stockLabel=row.querySelector('.stock-line');if(stockLabel){stockLabel.classList.add('out');stockLabel.textContent='Out of Stock';}
      const itemTotal=row.querySelector('.item-total');if(itemTotal)itemTotal.textContent='Out of Stock';
      window.dispatchEvent(new Event('lc-cart-updated'));
    }
    if(!product.is_archived)openProduct(product);
  }catch(e){showToast(e.message,'error');}
  finally{delete row.dataset.loading;}
}
for(const row of document.querySelectorAll('.cart-item')){
  const img=row.querySelector('.cart-item-image');
  if(img){img.addEventListener('error',()=>{const box=img.closest('.cart-item-image-wrap');if(!box)return;const fallback=document.createElement('span');fallback.className='cart-item-placeholder';fallback.textContent='Photo unavailable';box.replaceChildren(fallback);},{once:true});}
  for(const clickTarget of row.querySelectorAll('.lc-cart-click')){
    clickTarget.addEventListener('click',()=>openCartPreview(row));
    clickTarget.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();openCartPreview(row);}});
  }
}
})();
  </script>
  <script>
  (() => {
    const cartSearchInput = document.getElementById('productSearch');
    const cartSearchEmpty = document.getElementById('cartSearchEmpty');
    const cartCsrf = <?php echo json_encode(
        $_SESSION['cart_csrf'],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ); ?>;
    const selectAllCart = document.getElementById('selectAllCart');
    const cartWrap = document.querySelector('.cart-wrap');
    const editToggle = document.getElementById('editCartToggle');
    const editAllCart = document.getElementById('editAllCart');
    const editCount = document.getElementById('editCartCount');
    const editToolbar = document.getElementById('cartEditToolbar');
    const bulkRemove = document.getElementById('bulkRemove');
    const bulkMoveSaved = document.getElementById('bulkMoveSaved');
    const cartEditFeedback = document.getElementById('cartEditFeedback');
    let editing = false, bulkBusy = false;
    const quantityStates = new WeakMap();
    let pendingQuantityRequests = 0;
    // Used by the Product Details popup to avoid overlapping changes to cart quantities.
    window.lcCartHasPendingQuantity = () => pendingQuantityRequests > 0;
    const checkoutSelected = document.getElementById('checkoutSelected');
    const summarySelectedProducts = document.getElementById('summarySelectedProducts');
    const summarySelectedQuantity = document.getElementById('summarySelectedQuantity');
    const summarySubtotal = document.getElementById('summarySubtotal');
    const checkoutModal = document.getElementById('checkoutModal');
    const checkoutBackdrop = document.getElementById('checkoutBackdrop');
    const closeCheckout = document.getElementById('closeCheckout');
    const cancelCheckout = document.getElementById('cancelCheckout');
    const placeOrderButton = document.getElementById('placeOrderButton');
    const checkoutRequestSent = document.getElementById('checkoutRequestSent');
    const checkoutSelectedList = document.getElementById('checkoutSelectedList');
    let checkoutOptionsLoaded = false;
    let checkoutLoading = false;
    let checkoutOpenSequence = 0;
    let checkoutCartSnapshot = [];
    const checkoutDelivery = document.getElementById('checkoutDelivery');
    const checkoutPayment = document.getElementById('checkoutPayment');
    const paymentMethodNote = document.getElementById('paymentMethodNote');
    const checkoutTotal = document.getElementById('checkoutTotal');
    const checkoutInlineError = document.getElementById('checkoutInlineError');
    const checkoutDeliveryField = document.getElementById('checkoutDeliveryField');
    const checkoutPaymentField = document.getElementById('checkoutPaymentField');
    const checkoutDeliveryError = document.getElementById('checkoutDeliveryError');
    const checkoutPaymentError = document.getElementById('checkoutPaymentError');
    const checkoutSelectionInfo = document.getElementById('checkoutSelectionInfo');
    const checkoutScrollArea = document.getElementById('checkoutScrollArea');
    let checkoutSending = false;
    let checkoutSent = false;
    let checkoutCashReset = false;
    const checkoutTouched = { delivery: false, payment: false };
    const toast = document.getElementById('toast');
    const cartItemsContainer = document.getElementById('cartItems');
    let toastTimer = null;
    function money(value) {
      return '₱' + Number(value || 0).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    }
    function showToast(message, type = 'success') {
      if (!toast) return;
      clearTimeout(toastTimer);
      toast.textContent = message;
      toast.className = 'marketplace-toast show ' + type;
      toastTimer = setTimeout(() => toast.classList.remove('show'), 2800);
    }
    // create_order.php returns one order for each seller. Link only to the order
    // IDs actually confirmed in its successful response, never a guessed thread.
    function showOrderSuccessToast(result) {
      if (!toast) return;
      clearTimeout(toastTimer);
      const orders = (Array.isArray(result?.orders) ? result.orders : []).filter(order =>
        Number.isSafeInteger(Number(order.order_id)) && Number(order.order_id) > 0 &&
        Number.isSafeInteger(Number(order.seller_id)) && Number(order.seller_id) > 0
      );
      toast.replaceChildren();
      toast.className = 'marketplace-toast show success lc-order-success-toast';
      if (orders.length === 1) {
        const order = orders[0];
        const link = document.createElement('a');
        link.className = 'lc-cart-toast-action';
        link.href = 'messages.php?seller_id=' + encodeURIComponent(String(order.seller_id))
          + '&order_id=' + encodeURIComponent(String(order.order_id));
        link.textContent = 'Order request sent! Tap to open your conversation with '
          + (String(order.seller_name || '').trim() || 'the seller') + ' →';
        toast.appendChild(link);
      } else if (orders.length > 1) {
        const heading = document.createElement('strong');
        heading.className = 'lc-cart-toast-heading';
        heading.textContent = orders.length + ' order requests sent! Open a seller chat:';
        toast.appendChild(heading);
        orders.forEach(order => {
          const link = document.createElement('a');
          link.className = 'lc-cart-toast-action lc-cart-toast-seller-link';
          link.href = 'messages.php?seller_id=' + encodeURIComponent(String(order.seller_id))
            + '&order_id=' + encodeURIComponent(String(order.order_id));
          link.textContent = (String(order.seller_name || '').trim() || 'Seller')
            + ' · Order #' + String(order.order_id) + ' →';
          toast.appendChild(link);
        });
      } else {
        const link = document.createElement('a');
        link.className = 'lc-cart-toast-action';
        link.href = 'messages.php';
        link.textContent = 'Order request sent! Tap to open Messages →';
        toast.appendChild(link);
      }
      // Give buyers time to tap. No automatic page reload interrupts the link.
      toastTimer = setTimeout(() => toast.classList.remove('show'), orders.length > 1 ? 14000 : 10000);
    }
    function getCartItems() {
      return [...document.querySelectorAll('.cart-item')];
    }
    function getCurrentStock(item) {
      return Math.max(0, Number(item.dataset.stock || 0));
    }
    function getQuantity(item) {
      const input = item.querySelector('.qty-input');
      return Math.max(1, Number(input?.value || 1));
    }
    function getSelectedItems() {
      return getCartItems().filter((item) => {
        const checkbox = item.querySelector('.cart-select');
        return checkbox && !checkbox.disabled && checkbox.checked;
      });
    }
    function getSelectedPayload() {
      return getSelectedItems().map((item) => ({
        cart_id: Number(item.dataset.cartId || 0),
        quantity: getQuantity(item)
      }));
    }
    function getCheckoutPayload() {
      return checkoutCartSnapshot.map(item => {
        const row = checkoutSelectedList?.querySelector('[data-checkout-cart-id="'+item.cart_id+'"]');
        const customizations = row ? [...row.querySelectorAll('.lc-custom-option')]
          .filter(option => option.querySelector('.lc-custom-check')?.checked)
          .map(option => ({
            option_id: Number(option.dataset.optionId),
            text: option.querySelector('.lc-custom-text')?.value.trim() || ''
          })) : [];
        return {cart_id: item.cart_id, quantity: item.quantity, customizations};
      });
    }
    function updateSelectionSummary() {
      const selected = getSelectedItems();
      let selectedQuantity = 0;
      let subtotal = 0;
      getCartItems().forEach((item) => {
        const checkbox = item.querySelector('.cart-select');
        item.classList.toggle('is-selected', Boolean(checkbox?.checked) && !editing);
      });
      selected.forEach((item) => {
        const quantity = getQuantity(item);
        selectedQuantity += quantity;
        subtotal += Number(item.dataset.price || 0) * quantity;
      });
      if (summarySelectedProducts) {
        summarySelectedProducts.textContent = String(selected.length);
      }
      if (summarySelectedQuantity) {
        summarySelectedQuantity.textContent = String(selectedQuantity);
      }
      if (summarySubtotal) {
        summarySubtotal.textContent = money(subtotal);
      }
      if (checkoutSelected) {
        checkoutSelected.disabled = selected.length === 0 || editing || pendingQuantityRequests > 0;
        const actionLabel = checkoutSelected.querySelector('.lc-review-button-label');
        if (actionLabel) actionLabel.textContent = pendingQuantityRequests > 0
          ? 'Saving quantity...' : editing
          ? 'Finish editing to continue'
          : (selected.length ? 'Review Items & Add-ons' : 'Select products to continue');
      }
      const selectable = getCartItems()
        .map((item) => item.querySelector('.cart-select'))
        .filter((checkbox) => checkbox && !checkbox.disabled);
      if (selectAllCart) {
        selectAllCart.disabled = selectable.length === 0;
        selectAllCart.checked =
          selectable.length > 0
          && selectable.every((checkbox) => checkbox.checked);
        selectAllCart.indeterminate =
          selectable.some((checkbox) => checkbox.checked)
          && !selectAllCart.checked;
      }
    }
    function getEditSelected() {
      return getCartItems().filter(item => item.querySelector('.cart-edit-check')?.checked);
    }
    function syncEditSelection() {
      const chosen = getEditSelected();
      const all = getCartItems().map(item=>item.querySelector('.cart-edit-check')).filter(Boolean);
      if (editCount) editCount.textContent = chosen.length + ' selected';
      if (bulkRemove) bulkRemove.disabled = bulkBusy || chosen.length === 0;
      if (bulkMoveSaved) bulkMoveSaved.disabled = bulkBusy || chosen.length === 0;
      if (editAllCart) {
        editAllCart.disabled = all.length === 0;
        editAllCart.checked = all.length > 0 && all.every(box => box.checked);
        editAllCart.indeterminate = !editAllCart.checked && all.some(box => box.checked);
      }
      getCartItems().forEach(item => item.classList.toggle('is-edit-selected',editing&&Boolean(item.querySelector('.cart-edit-check')?.checked)));
    }
    function setEditMode(next) {
      if (bulkBusy) return;
      if (pendingQuantityRequests > 0) {
        showToast('Wait for the quantity to finish saving.', 'error');
        return;
      }
      editing = Boolean(next);
      cartWrap?.classList.toggle('is-editing',editing);
      editToggle?.setAttribute('aria-pressed',String(editing));
      const editLabel = editToggle?.querySelector('.cart-edit-label');
      if (editLabel) editLabel.textContent=editing?'Done':'Edit';
      editToolbar?.setAttribute('aria-hidden',editing?'false':'true');
      getCartItems().forEach(item => {const check=item.querySelector('.cart-edit-check');if(check)check.checked=false;});
      if(editAllCart)editAllCart.checked=false;
      if(cartEditFeedback)cartEditFeedback.textContent='';
      syncEditSelection();
      updateSelectionSummary();
    }
    async function bulkEdit(operation) {
      if (bulkBusy) return;
      const selected = getEditSelected();
      if (selected.length === 0) return;
      bulkBusy = true;
      syncEditSelection();
      const chosenIds=selected.map(item=>Number(item.dataset.cartId));
      if (cartEditFeedback)cartEditFeedback.textContent=operation==='save'?'Moving items to Saved...':'Removing items...';
      const form = new FormData();
      form.append('cart_action','bulk_edit');
      form.append('bulk_operation',operation);
      form.append('cart_ids',JSON.stringify(chosenIds));
      try {
        const result=await postCartAction(form);
        const removedIds=new Set((result.cart_ids||chosenIds).map(Number));
        getCartItems().forEach(item=>{if(removedIds.has(Number(item.dataset.cartId)))item.remove();});
        updateCartCount();
        showEmptyStateIfNeeded();
        applyCartSearch();
        if(operation==='save')window.dispatchEvent(new CustomEvent('lc-cart-moved-to-saved',{
          detail:{productIds:selected.map(item=>Number(item.dataset.productId))}
        }));
        showToast(operation==='save'?result.count+' moved to Saved.':result.count+' removed from cart.','success');
      } catch(error) {
        showToast(error.message,'error');
        if(cartEditFeedback)cartEditFeedback.textContent=error.message;
      } finally {
        bulkBusy=false;
        if (!getCartItems().length) setEditMode(false);
        syncEditSelection();
        updateSelectionSummary();
      }
    }
    editToggle?.addEventListener('click',()=>setEditMode(!editing));
    editAllCart?.addEventListener('change',()=>{
      getCartItems().forEach(item=>{const checkbox=item.querySelector('.cart-edit-check');if(checkbox)checkbox.checked=editAllCart.checked;});
      syncEditSelection();
    });
    bulkRemove?.addEventListener('click',()=>bulkEdit('remove'));
    bulkMoveSaved?.addEventListener('click',()=>bulkEdit('save'));
    function applyCartSearch() {
      if (!cartSearchInput) return;
      const term = String(cartSearchInput.value || '').trim().toLowerCase();
      const items = getCartItems();
      let visible = 0;
      items.forEach((item) => {
        const searchable = [
          item.dataset.productName || '',
          item.dataset.category || '',
          item.dataset.seller || '',
          item.dataset.location || ''
        ].join(' ').toLowerCase();
        const show = term === '' || searchable.includes(term);
        item.style.display = show ? '' : 'none';
        if (show) visible++;
      });
      if (cartSearchEmpty) {
        cartSearchEmpty.hidden = visible !== 0 || items.length === 0;
      }
    }
    function updateItemVisuals(item) {
      if (!item) return;
      const stock = getCurrentStock(item);
      const quantityInput = item.querySelector('.qty-input');
      const checkbox = item.querySelector('.cart-select');
      const itemTotal = item.querySelector('.item-total');
      if (!quantityInput) return;
      const quantity = Math.max(1, Number(quantityInput.value || 1));
      const price = Number(item.dataset.price || 0);
      const available = item.dataset.available === '1' && stock > 0;
      const validQuantity = available && quantity <= stock && quantity <= 999;
      quantityInput.max = String(Math.min(999, Math.max(1, stock)));
      item.classList.toggle('is-unavailable', !validQuantity);
      if (checkbox) {
        checkbox.disabled = !validQuantity;
        if (!validQuantity) checkbox.checked = false;
      }
      if (available) {
        if (itemTotal) {
          itemTotal.textContent = validQuantity
            ? 'Item total: ' + money(price * quantity)
            : (quantity > 999 ? 'Maximum 999 per item' : 'Quantity exceeds stock');
        }
      } else {
        quantityInput.disabled = true;
        if (itemTotal) itemTotal.textContent = item.dataset.outOfStock === '1' ? 'Out of Stock' : 'Unavailable';
      }
      updateSelectionSummary();
    }
    async function postCartAction(formData) {
      if (!formData.has('csrf_token')) {
        formData.append('csrf_token', cartCsrf);
      }
      const response = await fetch('cart.php', {
        method: 'POST',
        body: formData,
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        }
      });
      let result;
      try {
        result = await response.json();
      } catch (_) {
        throw new Error('The server returned an unexpected response.');
      }
      if (!response.ok || !result.success) {
        throw new Error(result.message || 'Unable to complete the request.');
      }
      return result;
    }
    async function postCreateOrder(formData) {
      if (!formData.has('csrf_token')) {
        formData.append('csrf_token', cartCsrf);
      }
      const response = await fetch('create_order.php', {
        method: 'POST',
        body: formData,
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        }
      });
      let result;
      try {
        result = await response.json();
      } catch (_) {
        throw new Error('The server returned an unexpected response.');
      }
      if (!response.ok || !result.success) {
        throw new Error(result.message || 'Unable to create the order.');
      }
      return result;
    }
    function updateQuantity(item, requestedQuantity) {
      const input = item.querySelector('.qty-input');
      if (!input || !item.isConnected) return;
      const stockLimit = Math.min(999, Math.max(1, getCurrentStock(item)));
      let state = quantityStates.get(item);
      if (!state) {
        const saved = Number(input.dataset.savedQuantity || input.defaultValue || 1);
        state = {confirmed: saved, desired: saved, running: false};
        quantityStates.set(item, state);
      }
      const parsed = Number(requestedQuantity);
      const quantity = Number.isFinite(parsed)
        ? Math.max(1, Math.min(Math.trunc(parsed), stockLimit))
        : state.confirmed;
      state.desired = quantity;
      input.value = String(quantity);
      updateItemVisuals(item);
      updateCartCount();
      // A second tap changes the desired value instead of starting another request.
      if (!state.running && state.desired !== state.confirmed) {
        void flushQuantityUpdate(item, state);
      }
    }
    async function flushQuantityUpdate(item, state) {
      state.running = true;
      pendingQuantityRequests++;
      updateSelectionSummary();
      try {
        while (item.isConnected && state.desired !== state.confirmed) {
          const requested = state.desired;
          const formData = new FormData();
          formData.append('cart_action', 'update_quantity');
          formData.append('cart_id', item.dataset.cartId);
          formData.append('quantity', String(requested));
          const result = await postCartAction(formData);
          const confirmed = Number(result.quantity);
          if (!Number.isSafeInteger(confirmed) || confirmed < 1 || confirmed > 999) {
            throw new Error('Invalid quantity confirmation. Refresh your Cart.');
          }
          state.confirmed = confirmed;
          const input = item.querySelector('.qty-input');
          if (!input || !item.isConnected) break;
          input.dataset.savedQuantity = String(confirmed);
          if (state.desired === requested) {
            state.desired = confirmed;
            input.value = String(confirmed);
          }
          updateItemVisuals(item);
          updateCartCount();
        }
      } catch (error) {
        // Do not let an earlier failed request overwrite another saved quantity.
        state.desired = state.confirmed;
        const input = item.querySelector('.qty-input');
        if (input && item.isConnected) {
          input.value = String(state.confirmed);
          input.dataset.savedQuantity = String(state.confirmed);
          updateItemVisuals(item);
          updateCartCount();
        }
        showToast(error.message || 'Unable to save quantity.', 'error');
      } finally {
        state.running = false;
        pendingQuantityRequests = Math.max(0, pendingQuantityRequests - 1);
        updateSelectionSummary();
      }
    }
    function updateCartCount() {
      let totalQuantity = 0;
      getCartItems().forEach((item) => {
        totalQuantity += getQuantity(item);
      });
      const countElement = document.querySelector('.cart-card-count');
      if (countElement) {
        countElement.textContent = totalQuantity + ' item(s)';
      }
      if (editToggle) editToggle.disabled = getCartItems().length === 0;
    }
    function showEmptyStateIfNeeded() {
      if (getCartItems().length > 0 || !cartItemsContainer) return;
      cartItemsContainer.innerHTML = `
        <div class="empty-cart">
          <div class="empty-cart-icon">🛒</div>
          <h2>Your cart is empty</h2>
          <a class="browse-btn" href="foryou.php">Browse Marketplace</a>
        </div>
      `;
      if (selectAllCart) {
        selectAllCart.checked = false;
        selectAllCart.disabled = true;
      }
      updateCartCount();
      updateSelectionSummary();
    }
    function groupSelectedBySeller(items) {
      const groups = new Map();
      items.forEach((item) => {
        const sellerId = item.dataset.sellerId || '0';
        if (!groups.has(sellerId)) {
          groups.set(sellerId, {sellerId, sellerName: item.dataset.seller || 'Seller', items: []});
        }
        groups.get(sellerId).items.push({
          cartId: Number(item.dataset.cartId),
          name: item.dataset.productName || 'Product',
          quantity: getQuantity(item),
          price: Number(item.dataset.price || 0),
          photoSrc: item.querySelector('.cart-item-image')?.getAttribute('src') || ''
        });
      });
      return [...groups.values()];
    }
    let activeAddonDropdown = null;
    function updateAddonTrigger(block) {
      if (!block) return;
      const count = block.querySelectorAll('.lc-custom-check:checked').length;
      const countLabel = block.querySelector('.lc-addon-selected-count');
      if (!countLabel) return;
      countLabel.hidden = count === 0;
      countLabel.textContent = count === 1 ? '1 selected' : `${count} selected`;
    }
    function closeAddonDropdown(restoreFocus = false) {
      if (!activeAddonDropdown) return;
      const {trigger, popover} = activeAddonDropdown;
      popover.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      activeAddonDropdown = null;
      if (restoreFocus && trigger.isConnected) {
        try { trigger.focus({preventScroll: true}); } catch (_) { trigger.focus(); }
      }
    }
    function positionAddonDropdown() {
      if (!activeAddonDropdown) return;
      const {trigger, popover} = activeAddonDropdown;
      if (!trigger.isConnected || !checkoutModal?.classList.contains('open')) {
        closeAddonDropdown();
        return;
      }
      const rect = trigger.getBoundingClientRect();
      const scrollArea = checkoutScrollArea?.getBoundingClientRect();
      if (scrollArea && (rect.bottom < scrollArea.top || rect.top > scrollArea.bottom)) {
        closeAddonDropdown();
        return;
      }
      const viewport = window.visualViewport;
      const viewTop = viewport ? viewport.offsetTop : 0;
      const viewLeft = viewport ? viewport.offsetLeft : 0;
      const viewHeight = viewport ? viewport.height : window.innerHeight;
      const viewWidth = viewport ? viewport.width : window.innerWidth;
      const viewBottom = viewTop + viewHeight;
      const margin = 12;
      const gap = 6;
      // Match the floating list to its own Add-ons trigger on desktop and mobile.
      const width = Math.max(0, Math.min(rect.width, viewWidth - margin * 2));
      const left = Math.max(viewLeft + margin, Math.min(rect.left, viewLeft + viewWidth - width - margin));
      const below = Math.max(0, viewBottom - rect.bottom - margin - gap);
      const above = Math.max(0, rect.top - viewTop - margin - gap);
      const openAbove = below < 210 && above > below;
      const space = openAbove ? above : below;
      const height = Math.max(105, Math.min(390, space));
      popover.style.width = `${width}px`;
      popover.style.left = `${left}px`;
      popover.style.maxHeight = `${height}px`;
      popover.style.top = openAbove
        ? `${Math.max(viewTop + margin, rect.top - gap - Math.min(height, popover.scrollHeight))}px`
        : `${Math.min(rect.bottom + gap, viewBottom - margin - 105)}px`;
    }
    function openAddonDropdown(block) {
      const trigger = block?.querySelector('.lc-addon-trigger');
      const popover = block?.querySelector('.lc-addon-popover');
      if (!trigger || !popover || trigger.disabled) return;
      if (activeAddonDropdown?.popover === popover) return;
      closeAddonDropdown();
      popover.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      activeAddonDropdown = {trigger, popover};
      positionAddonDropdown();
    }
    function updateCustomizationTotals() {
      let baseGrandCents = 0;
      let addOnGrandCents = 0;
      checkoutSelectedList?.querySelectorAll('.checkout-seller-group').forEach(group => {
        let sellerBaseCents = 0;
        let sellerAddOnCents = 0;
        const extrasList = group.querySelector('.lc-selected-extras');
        if (extrasList) extrasList.replaceChildren();
        group.querySelectorAll('.checkout-selected-item').forEach(row => {
          const baseCents = Number(row.dataset.baseCents || 0);
          const qty = Math.max(1, Number(row.dataset.quantity || 1));
          let addOnCents = 0;
          row.querySelectorAll('.lc-custom-option').forEach(option => {
            if (!option.querySelector('.lc-custom-check')?.checked) return;
            const extraCents = Number(option.dataset.extraCents || 0);
            addOnCents += extraCents;
            if (extrasList) {
              const line = document.createElement('div');
              line.className = 'lc-selected-extra-line';
              const label = document.createElement('span');
              label.textContent = (row.dataset.productName || 'Product') + ' · ' +
                (option.querySelector('.lc-custom-choice-name')?.textContent || 'Add-on');
              const amount = document.createElement('strong');
              amount.textContent = '+' + money((extraCents * qty) / 100);
              line.append(label, amount);
              extrasList.appendChild(line);
            }
          });
          const baseLineCents = baseCents * qty;
          const extrasLineCents = addOnCents * qty;
          sellerBaseCents += baseLineCents;
          sellerAddOnCents += extrasLineCents;
          const itemTotal = row.querySelector('.checkout-selected-price');
          if (itemTotal) itemTotal.textContent = money((baseLineCents + extrasLineCents) / 100);
        });
        if (extrasList) extrasList.hidden = sellerAddOnCents === 0;
        const changeText = (selector, value) => {
          const target = group.querySelector(selector);
          if (target) target.textContent = value;
        };
        changeText('.lc-breakdown-base', money(sellerBaseCents / 100));
        changeText('.lc-breakdown-extras', '+' + money(sellerAddOnCents / 100));
        changeText('.lc-custom-seller-total', money((sellerBaseCents + sellerAddOnCents) / 100));
        baseGrandCents += sellerBaseCents;
        addOnGrandCents += sellerAddOnCents;
      });
      if (checkoutTotal) checkoutTotal.textContent = money((baseGrandCents + addOnGrandCents) / 100);
    }
    function updateCustomizationField(option, validate = false) {
      const checked = Boolean(option.querySelector('.lc-custom-check')?.checked);
      const details = option.querySelector('.lc-custom-details');
      const input = option.querySelector('.lc-custom-text');
      const feedback = option.querySelector('.lc-custom-error');
      if (!details || !input || !feedback) return true;
      details.hidden = !checked;
      input.disabled = !checked || checkoutSending;
      const bad = checked && !input.value.trim();
      input.classList.toggle('is-invalid', validate && bad);
      input.setAttribute('aria-invalid', validate && bad ? 'true' : 'false');
      feedback.textContent = validate && bad ? 'Enter what you want for this add-on.' : '';
      feedback.hidden = !(validate && bad);
      return !bad;
    }
    function validateCustomizations() {
      let firstInvalid = null;
      checkoutSelectedList?.querySelectorAll('.lc-custom-option').forEach(option => {
        const valid = updateCustomizationField(option, true);
        if (!valid && !firstInvalid) firstInvalid = option.querySelector('.lc-custom-text');
      });
      if (firstInvalid) {
        const block = firstInvalid.closest('.lc-custom-options');
        const trigger = block?.querySelector('.lc-addon-trigger');
        // Scroll the regular form to the trigger before opening its fixed popover.
        // Scrolling the fixed input into view would otherwise shift Safari's modal content.
        if (trigger && checkoutScrollArea) {
          const view = checkoutScrollArea.getBoundingClientRect();
          const target = trigger.getBoundingClientRect();
          checkoutScrollArea.scrollTop += target.top - (view.top + (view.height - target.height) / 2);
        }
        openAddonDropdown(block);
        const list = block?.querySelector('.lc-addon-popover-list');
        if (list) {
          const listRect = list.getBoundingClientRect();
          const inputRect = firstInvalid.getBoundingClientRect();
          if (inputRect.bottom > listRect.bottom - 7) list.scrollTop += inputRect.bottom - listRect.bottom + 10;
          else if (inputRect.top < listRect.top + 7) list.scrollTop -= listRect.top - inputRect.top + 10;
        }
        try { firstInvalid.focus({preventScroll: true}); } catch (_) { firstInvalid.focus(); }
      }
      return !firstInvalid;
    }
    function renderCheckoutPreview(optionsByCart = {}) {
      const selected = getSelectedItems();
      if (!checkoutSelectedList) return;
      closeAddonDropdown();
      checkoutSelectedList.replaceChildren();
      const groups = groupSelectedBySeller(selected);
      if (checkoutSelectionInfo) checkoutSelectionInfo.textContent =
        `${selected.length} product${selected.length === 1 ? '' : 's'} · ${groups.length} seller${groups.length === 1 ? '' : 's'}`;
      groups.forEach(group => {
        const wrapper = document.createElement('section');
        wrapper.className = 'checkout-seller-group';
        const title = document.createElement('div');
        title.className = 'checkout-seller-title';
        const sellerName = document.createElement('span');
        sellerName.textContent = group.sellerName;
        title.append(sellerName);
        const itemList = document.createElement('div');
        itemList.className = 'checkout-seller-items';
        group.items.forEach(item => {
          const row = document.createElement('div');
          row.className = 'checkout-selected-item';
          row.dataset.checkoutCartId = String(item.cartId);
          row.dataset.quantity = String(item.quantity);
          row.dataset.baseCents = String(Math.round(item.price * 100));
          row.dataset.productName = item.name;
          const overview = document.createElement('div');
          overview.className = 'lc-review-product-row';
          const productInfo = document.createElement('div');
          productInfo.className = 'lc-review-product-info';
          const photo = document.createElement('span');
          photo.className = 'lc-review-product-photo';
          const fallback = () => {
            photo.replaceChildren();
            photo.textContent = '🛍';
          };
          if (item.photoSrc) {
            const image = document.createElement('img');
            image.src = item.photoSrc;
            image.alt = '';
            image.loading = 'lazy';
            image.addEventListener('error', fallback, {once: true});
            photo.appendChild(image);
          } else {
            fallback();
          }
          const productText = document.createElement('div');
          productText.className = 'lc-review-product-text';
          const name = document.createElement('div');
          name.className = 'checkout-selected-name';
          name.textContent = item.name;
          const meta = document.createElement('div');
          meta.className = 'checkout-selected-meta';
          meta.textContent = 'Qty: ' + item.quantity;
          productText.append(name, meta);
          productInfo.append(photo, productText);
          const subtotal = document.createElement('div');
          subtotal.className = 'checkout-selected-price';
          overview.append(productInfo, subtotal);
          row.appendChild(overview);
          const left = document.createElement('div');
          const options = Array.isArray(optionsByCart[String(item.cartId)])
            ? optionsByCart[String(item.cartId)] : [];
          if (options.length) {
            const block = document.createElement('div');
            block.className = 'lc-custom-options';
            const trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'lc-addon-trigger';
            trigger.setAttribute('aria-expanded', 'false');
            const popoverId = 'lc-addon-popover-' + item.cartId;
            trigger.setAttribute('aria-controls', popoverId);
            const triggerText = document.createElement('span');
            triggerText.className = 'lc-addon-trigger-text';
            triggerText.textContent = 'Add-ons (optional)';
            const selectedCount = document.createElement('span');
            selectedCount.className = 'lc-addon-selected-count';
            selectedCount.hidden = true;
            const chevron = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            chevron.setAttribute('viewBox', '0 0 24 24');
            chevron.setAttribute('fill', 'none');
            chevron.setAttribute('stroke', 'currentColor');
            chevron.setAttribute('stroke-width', '2');
            chevron.setAttribute('stroke-linecap', 'round');
            chevron.setAttribute('stroke-linejoin', 'round');
            chevron.setAttribute('aria-hidden', 'true');
            chevron.classList.add('lc-addon-chevron');
            const arrow = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            arrow.setAttribute('d', 'm6 9 6 6 6-6');
            chevron.appendChild(arrow);
            trigger.append(triggerText, selectedCount, chevron);
            const popover = document.createElement('div');
            popover.className = 'lc-addon-popover';
            popover.id = popoverId;
            popover.hidden = true;
            const popoverHead = document.createElement('div');
            popoverHead.className = 'lc-addon-popover-head';
            const popoverTitle = document.createElement('span');
            popoverTitle.className = 'lc-addon-popover-heading';
            popoverTitle.textContent = 'Choose add-ons';
            popoverHead.appendChild(popoverTitle);
            const popoverList = document.createElement('div');
            popoverList.className = 'lc-addon-popover-list';
            popoverList.setAttribute('role', 'group');
            popoverList.setAttribute('aria-label', `Optional add-ons for ${item.name}`);
            popover.append(popoverHead, popoverList);
            trigger.addEventListener('click', () => {
              if (activeAddonDropdown?.popover === popover) closeAddonDropdown();
              else openAddonDropdown(block);
            });
            block.append(trigger, popover);
            options.forEach(option => {
              const line = document.createElement('div');
              line.className = 'lc-custom-option';
              line.dataset.optionId = String(option.option_id);
              line.dataset.extraCents = String(Math.round(Number(option.price) * 100));
              const label = document.createElement('label');
              label.className = 'lc-custom-choice';
              const check = document.createElement('input');
              check.type = 'checkbox';
              check.className = 'lc-custom-check';
              const text = document.createElement('span');
              text.className = 'lc-custom-choice-name';
              text.textContent = option.name;
              const price = document.createElement('strong');
              price.className = 'lc-custom-choice-price';
              price.textContent = '+' + money(option.price) + ' each';
              label.append(check, text, price);
              line.append(label);
              if (option.requires_text) {
                const details = document.createElement('div');
                details.className = 'lc-custom-details';
                details.hidden = true;
                const detailsLabel = document.createElement('label');
                const input = document.createElement('input');
                input.className = 'lc-custom-text';
                input.type = 'text';
                input.maxLength = 100;
                input.disabled = true;
                input.placeholder = 'Type your request here';
                const inputId = 'lc-option-' + item.cartId + '-' + option.option_id;
                input.id = inputId;
                detailsLabel.htmlFor = inputId;
                detailsLabel.textContent = 'What should the seller add for ' + option.name + '? *';
                const feedback = document.createElement('span');
                feedback.className = 'lc-custom-error';
                feedback.hidden = true;
                details.append(detailsLabel, input, feedback);
                line.append(details);
                input.addEventListener('input', () => updateCustomizationField(line, false));
                input.addEventListener('blur', () => {
                  if (check.checked) updateCustomizationField(line, true);
                });
              }
              check.addEventListener('change', () => {
                updateCustomizationField(line, false);
                updateCustomizationTotals();
                updateAddonTrigger(block);
                showCheckoutError('');
              });
              popoverList.appendChild(line);
            });
            left.appendChild(block);
          }
          if (left.childElementCount) row.appendChild(left);
          itemList.appendChild(row);
        });
        const breakdown = document.createElement('div');
        breakdown.className = 'lc-seller-price-breakdown';
        breakdown.innerHTML = `
          <div class="lc-seller-breakdown-heading">Item Breakdown</div>
          <div class="lc-breakdown-line"><span>Products</span><strong class="lc-breakdown-base"></strong></div>
          <div class="lc-selected-extras" hidden></div>
          <div class="lc-breakdown-line"><span>Add-ons</span><strong class="lc-breakdown-extras"></strong></div>
          <div class="lc-breakdown-line lc-seller-breakdown-total"><span>Seller Total</span><strong class="lc-custom-seller-total"></strong></div>
        `;
        wrapper.append(title, itemList, breakdown);
        checkoutSelectedList.appendChild(wrapper);
      });
      updateCustomizationTotals();
    }
    function showCheckoutError(message, scrollToError = true) {
      if (!checkoutInlineError) return;
      checkoutInlineError.textContent = message;
      checkoutInlineError.hidden = !message;
      if (message && scrollToError) checkoutInlineError.scrollIntoView({block: 'nearest', behavior: 'smooth'});
    }
    function setCheckoutFieldState(field, message, visible = true) {
      const isDelivery = field === 'delivery';
      const wrapper = isDelivery ? checkoutDeliveryField : checkoutPaymentField;
      const input = isDelivery ? checkoutDelivery : checkoutPayment;
      const feedback = isDelivery ? checkoutDeliveryError : checkoutPaymentError;
      if (!wrapper || !input || !feedback) return;
      const reveal = visible && Boolean(message);
      wrapper.dataset.validation = reveal ? 'invalid' : (input.value ? 'valid' : 'neutral');
      input.classList.toggle('is-invalid', reveal);
      input.setAttribute('aria-invalid', reveal ? 'true' : 'false');
      feedback.textContent = reveal ? message : '';
      feedback.hidden = !reveal;
    }
    function validateCheckoutDelivery() {
      const value = checkoutDelivery?.value || '';
      return ['meetup', 'pickup', 'third_party_delivery'].includes(value)
        ? '' : 'Please choose a delivery method.';
    }
    function validateCheckoutPayment() {
      const value = checkoutPayment?.value || '';
      const delivery = checkoutDelivery?.value || '';
      if (!['paymongo', 'cash'].includes(value)) return checkoutCashReset
        ? 'Cash was removed because Third-Party Delivery requires QR Ph.'
        : 'Please choose a payment method.';
      if (delivery === 'third_party_delivery' && value === 'cash') {
        return 'Cash is only available for Meetup or Pickup. Choose QR Ph.';
      }
      return '';
    }
    function validateCheckoutPreferences(showAll = false) {
      const deliveryError = validateCheckoutDelivery();
      const paymentError = validateCheckoutPayment();
      setCheckoutFieldState('delivery', deliveryError, showAll || checkoutTouched.delivery);
      setCheckoutFieldState('payment', paymentError, showAll || checkoutTouched.payment);
      return {deliveryError, paymentError};
    }
    function focusCheckoutField(field) {
      if (!field) return;
      try { field.focus({preventScroll: true}); } catch (_) { field.focus(); }
      // The middle panel scrolls independently; Safari must reveal the actual field.
      field.scrollIntoView({block: 'center', behavior: 'smooth'});
    }
    function setCheckoutBusy(busy) {
      if (busy) closeAddonDropdown();
      checkoutSending = busy;
      checkoutSelectedList?.querySelectorAll('.lc-addon-trigger').forEach(trigger => { trigger.disabled = busy; });
      if (placeOrderButton) {
        placeOrderButton.disabled = busy || checkoutSent || !checkoutOptionsLoaded || checkoutLoading;
        placeOrderButton.classList.toggle('is-request-sent', checkoutSent);
        placeOrderButton.innerHTML = busy
          ? '<span class="lc-spinner" aria-hidden="true"></span> Sending Request...'
          : (checkoutSent ? '✓ Request Sent' : 'Send Order Request');
      }
      if (cancelCheckout) cancelCheckout.disabled = busy;
      if (closeCheckout) closeCheckout.disabled = busy;
      if (checkoutDelivery) checkoutDelivery.disabled = busy;
      if (checkoutPayment) checkoutPayment.disabled = busy;
      checkoutSelectedList?.querySelectorAll('.lc-custom-check').forEach(el => el.disabled = busy);
      checkoutSelectedList?.querySelectorAll('.lc-custom-option').forEach(option => updateCustomizationField(option, false));
    }
    function setModalOpen(open) {
      if (!checkoutModal || (!open && checkoutSending)) return;
      if (!open) closeAddonDropdown();
      checkoutModal.classList.toggle('open', open);
      checkoutModal.setAttribute('aria-hidden', open ? 'false' : 'true');
      document.body.classList.toggle('modal-open', open);
    }
    function updatePaymentHint() {
      if (!checkoutPayment || !checkoutDelivery) return;
      const thirdParty = checkoutDelivery.value === 'third_party_delivery';
      const cashOption = checkoutPayment.querySelector('option[value="cash"]');
      const removedCashSelection = thirdParty && checkoutPayment.value === 'cash';
      if (cashOption) cashOption.disabled = thirdParty;
      if (removedCashSelection) {
        checkoutPayment.value = '';
        checkoutCashReset = true;
        checkoutTouched.payment = true;
      } else if (!thirdParty) {
        checkoutCashReset = false;
      }
      if (paymentMethodNote) {
        paymentMethodNote.textContent = removedCashSelection
          ? 'Cash was removed because Third-Party Delivery requires QR Ph.'
          : (thirdParty
              ? 'QR Ph only for Third-Party Delivery. Cash is unavailable.'
              : 'Cash is available for Meetup or Pickup. QR Ph is available for all delivery methods.');
      }
      validateCheckoutPreferences();
    }
    async function openCheckoutModal() {
      if (editing || checkoutSending || checkoutSent || checkoutLoading) return;
      if (pendingQuantityRequests > 0) {
        showToast('Wait for the quantity to finish saving.', 'error');
        return;
      }
      const selected = getSelectedItems();
      if (!selected.length) {
        showToast('Select at least one available cart item.', 'error');
        return;
      }
      checkoutCartSnapshot = getSelectedPayload();
      checkoutOptionsLoaded = false;
      checkoutLoading = true;
      const sequence = ++checkoutOpenSequence;
      checkoutTouched.delivery = false;
      checkoutTouched.payment = false;
      checkoutCashReset = false;
      if (checkoutDelivery) checkoutDelivery.value = '';
      if (checkoutPayment) checkoutPayment.value = '';
      showCheckoutError('');
      if (checkoutRequestSent) checkoutRequestSent.hidden = true;
      if (cancelCheckout) cancelCheckout.textContent = 'Cancel';
      setCheckoutFieldState('delivery', '', false);
      setCheckoutFieldState('payment', '', false);
      updatePaymentHint();
      setModalOpen(true);
      checkoutSelectedList.innerHTML = '<p class="lc-custom-loading">Loading available add-ons...</p>';
      if (placeOrderButton) placeOrderButton.disabled = true;
      if (checkoutScrollArea) checkoutScrollArea.scrollTop = 0;
      try {
        const data = new FormData();
        data.append('cart_action', 'load_customizations');
        data.append('cart_ids', JSON.stringify(checkoutCartSnapshot.map(item => item.cart_id)));
        data.append('csrf_token', cartCsrf);
        const result = await postCartAction(data);
        if (sequence !== checkoutOpenSequence) return;
        renderCheckoutPreview(result.options || {});
        checkoutOptionsLoaded = true;
      } catch (error) {
        if (sequence !== checkoutOpenSequence) return;
        checkoutSelectedList.textContent = 'Unable to load add-ons.';
        showCheckoutError(error.message || 'Unable to load add-ons. Close and try again.');
      } finally {
        if (sequence === checkoutOpenSequence) {
          checkoutLoading = false;
          if (placeOrderButton) placeOrderButton.disabled = !checkoutOptionsLoaded;
        }
      }
    }
    function closeCheckoutModal() {
      if (checkoutSending || checkoutSent) return;
      checkoutOpenSequence++;
      checkoutLoading = false;
      checkoutOptionsLoaded = false;
      setModalOpen(false);
    }
    // Reconcile only the quantities confirmed by create_order.php. The server
    // already deleted full quantities or reduced partially ordered cart rows
    // within the successful order transaction.
    function applyConfirmedOrderToCart(submittedItems) {
      const requested = new Map(submittedItems.map(item => [Number(item.cart_id), Number(item.quantity)]));
      getCartItems().forEach(item => {
        const quantityOrdered = requested.get(Number(item.dataset.cartId));
        if (!Number.isSafeInteger(quantityOrdered) || quantityOrdered <= 0) return;
        const input = item.querySelector('.qty-input');
        const oldQuantity = Number(input?.dataset.savedQuantity || input?.value || 0);
        const remaining = oldQuantity - quantityOrdered;
        if (!input || remaining <= 0) {
          item.remove();
          return;
        }
        input.value = String(remaining);
        input.defaultValue = String(remaining);
        input.dataset.savedQuantity = String(remaining);
        quantityStates.set(item, {confirmed: remaining, desired: remaining, running: false});
        const checkbox = item.querySelector('.cart-select');
        if (checkbox) checkbox.checked = false;
        updateItemVisuals(item);
      });
      updateCartCount();
      showEmptyStateIfNeeded();
      updateSelectionSummary();
      applyCartSearch();
    }
    async function createSelectedOrders() {
      if (checkoutSending || checkoutSent || checkoutLoading || !checkoutOptionsLoaded) return;
      showCheckoutError('');
      const liveSelection = getSelectedPayload();
      if (JSON.stringify(liveSelection) !== JSON.stringify(checkoutCartSnapshot)) {
        showCheckoutError('Your Cart changed. Close and reopen Request Order to review current items.');
        return;
      }
      if (!validateCustomizations()) {
        showCheckoutError('Enter the details for the add-ons you selected.', false);
        return;
      }
      checkoutTouched.delivery = true;
      checkoutTouched.payment = true;
      const {deliveryError, paymentError} = validateCheckoutPreferences(true);
      if (deliveryError || paymentError) {
        focusCheckoutField(deliveryError ? checkoutDelivery : checkoutPayment);
        return;
      }
      const selectedPayload = getCheckoutPayload();
      const invalidItem = selectedPayload.find(item =>
        !Number.isSafeInteger(item.cart_id) || item.cart_id <= 0 ||
        !Number.isSafeInteger(item.quantity) || item.quantity <= 0
      );
      if (!selectedPayload.length || invalidItem) {
        showCheckoutError('Your cart selection has changed. Review your cart and try again.');
        return;
      }
      const deliveryOption = checkoutDelivery.value;
      const paymentOption = checkoutPayment.value;
      const formData = new FormData();
      formData.append('order_source', 'cart');
      formData.append('selected_items', JSON.stringify(selectedPayload));
      formData.append('delivery_option', deliveryOption);
      formData.append('payment_option', paymentOption);
      setCheckoutBusy(true);
      let createdOrderResponse = null;
      try {
        // The server commits the order, opens the message thread and updates
        // the cart atomically. Never dismiss the modal on an error.
        createdOrderResponse = await postCreateOrder(formData);
        checkoutSent = true;
        showCheckoutError('');
      } catch (error) {
        showCheckoutError(error.message || 'Unable to send your order request. Please try again.');
      } finally {
        setCheckoutBusy(false);
        if (createdOrderResponse) {
          checkoutOpenSequence++;
          checkoutOptionsLoaded = false;
          // Start dismissal and remove/decrement confirmed ordered items in
          // the same UI update, instead of waiting for a delayed reload.
          checkoutModal.classList.add('lc-closing');
          applyConfirmedOrderToCart(selectedPayload);
          showOrderSuccessToast(createdOrderResponse);
          window.setTimeout(() => {
            setModalOpen(false);
            checkoutModal.classList.remove('lc-closing');
            checkoutSent = false;
            checkoutCartSnapshot = [];
            setCheckoutBusy(false);
            try { if (!checkoutSelected?.disabled) checkoutSelected.focus({preventScroll: true}); } catch (_) { }
          }, 180);
        }
      }
    }
    cartSearchInput?.addEventListener('input', applyCartSearch);
    selectAllCart?.addEventListener('change', () => {
      const checked = selectAllCart.checked;
      getCartItems().forEach((item) => {
        const checkbox = item.querySelector('.cart-select');
        if (checkbox && !checkbox.disabled) {
          checkbox.checked = checked;
        }
      });
      updateSelectionSummary();
    });
    // The add-on list floats above checkout content rather than changing row height.
    document.addEventListener('pointerdown', (event) => {
      if (!activeAddonDropdown) return;
      const {trigger, popover} = activeAddonDropdown;
      if (!trigger.contains(event.target) && !popover.contains(event.target)) closeAddonDropdown();
    }, true);
    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape' || !activeAddonDropdown) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      closeAddonDropdown(true);
    }, true);
    checkoutScrollArea?.addEventListener('scroll', positionAddonDropdown, {passive: true});
    window.addEventListener('resize', positionAddonDropdown, {passive: true});
    window.visualViewport?.addEventListener('resize', positionAddonDropdown, {passive: true});
    window.visualViewport?.addEventListener('scroll', positionAddonDropdown, {passive: true});
    checkoutSelected?.addEventListener('click', openCheckoutModal);
    closeCheckout?.addEventListener('click', closeCheckoutModal);
    cancelCheckout?.addEventListener('click', closeCheckoutModal);
    checkoutBackdrop?.addEventListener('click', closeCheckoutModal);
    checkoutDelivery?.addEventListener('change', () => {
      checkoutTouched.delivery = true;
      showCheckoutError('');
      updatePaymentHint();
    });
    checkoutPayment?.addEventListener('change', () => {
      checkoutCashReset = false;
      checkoutTouched.payment = true;
      showCheckoutError('');
      validateCheckoutPreferences();
    });
    placeOrderButton?.addEventListener('click', createSelectedOrders);
    getCartItems().forEach((item) => {
      updateItemVisuals(item);
      const checkbox = item.querySelector('.cart-select');
      const minus = item.querySelector('.qty-minus');
      const plus = item.querySelector('.qty-plus');
      const input = item.querySelector('.qty-input');
      const editCheckbox = item.querySelector('.cart-edit-check');
      checkbox?.addEventListener('change', updateSelectionSummary);
      editCheckbox?.addEventListener('change', syncEditSelection);
      minus?.addEventListener('click', () => {
        if (editing || input.disabled) return;
        const next = Math.max(1, Number(input.value || 1) - 1);
        input.value = String(next);
        updateQuantity(item, next);
      });
      plus?.addEventListener('click', () => {
        if (editing || input.disabled) return;
        const stock = getCurrentStock(item);
        const next = Math.min(999, stock || 1, Number(input.value || 1) + 1);
        input.value = String(next);
        updateQuantity(item, next);
      });
      input?.addEventListener('change', () => {
        if (!editing && !input.disabled) {
          updateQuantity(item, input.value);
        }
      });
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && checkoutModal?.classList.contains('open')) {
        closeCheckoutModal();
      }
    });
    window.addEventListener('lc-cart-updated',(event)=>{
      // A Product Details add is saved by PHP, but must refresh all price labels here.
      const productId = Number(event.detail?.productId);
      const confirmed = Number(event.detail?.cartQuantity);
      if (Number.isSafeInteger(productId) && productId > 0
          && Number.isSafeInteger(confirmed) && confirmed >= 1 && confirmed <= 999) {
        const item = getCartItems().find(row => Number(row.dataset.productId) === productId);
        const input = item?.querySelector('.qty-input');
        if (item && input) {
          input.value = String(confirmed);
          input.dataset.savedQuantity = String(confirmed);
          // Keep the next +/- tap consistent with the quantity confirmed by the database.
          quantityStates.set(item, {confirmed, desired: confirmed, running: false});
          updateItemVisuals(item); // Updates the individual Item total immediately.
        }
      }
      updateCartCount();
      updateSelectionSummary(); // Updates selected quantity and selected items total.
      showEmptyStateIfNeeded();
      applyCartSearch();
    });
    updateCartCount();
    updateSelectionSummary();
    window.addEventListener('pageshow', (event) => {
      if (event.persisted) {
        window.location.reload();
      }
    });
  })();
  </script>
</body>
</html>
