<?php
require_once __DIR__ . '/buyer_auth.php';
if (empty($_SESSION['marketplace_csrf'])) {
    $_SESSION['marketplace_csrf'] = bin2hex(random_bytes(32));
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
    if ($storedPath === '' || str_contains($storedPath, '..') || str_starts_with($storedPath, '/')) {
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
/** Keep withdrawn/archived listings separate from genuinely depleted stock. */
function savedAvailabilityState(array $p, int $buyerId): string
{
    $status = strtolower(trim((string)($p['status'] ?? '')));
    if (!in_array($status, ['available', 'out of stock', 'out_of_stock'], true)
        || strtolower(trim((string)($p['seller_account_status'] ?? ''))) !== 'active'
        || (int)($p['seller_flag'] ?? 0) !== 1
        || (int)($p['admin_flag'] ?? 0) !== 0
        || strtolower(trim((string)($p['seller_application_status'] ?? ''))) !== 'approved'
        || (int)($p['seller_id'] ?? 0) === $buyerId) {
        return 'unavailable';
    }
    if ($status !== 'available' || (int)($p['available_stock'] ?? 0) <= 0) {
        return 'out_of_stock';
    }
    return 'available';
}
function savedCanBuy(array $p, int $buyerId): bool
{
    return savedAvailabilityState($p, $buyerId) === 'available';
}
function savedProductData(array $row, int $buyerId, bool $saved = true): array
{
    $photo = trim((string)($row['product_photo'] ?? ''));
    $sellerName = trim((string)($row['firstname'] ?? '') . ' ' . (string)($row['lastname'] ?? '')) ?: 'Local Seller';
    $business = trim((string)($row['businessname'] ?? ''));
    $availabilityState = savedAvailabilityState($row, $buyerId);
    $available = $availabilityState === 'available';
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
        'availability_state'=>$availabilityState,
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
       . ' LEFT JOIN seller_applications sa ON sa.user_id=u.id'
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
            $exists = $conn->prepare('SELECT product_id FROM products WHERE product_id=? LIMIT 1');
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
        if ($action === 'add_to_cart') {
            if ($productId <= 0) jsonResponse(false, 'Invalid product.', [], 400);
            $quantity = max(1, min(9999, (int)($_POST['quantity'] ?? 1)));
            $conn->begin_transaction();
            try {
                $query = 'SELECT p.product_id,p.seller_id,p.product_name,p.stock,p.reserved_stock,p.status,'
                    . 'u.SellerOrNot AS seller_flag,u.AdminOrNot AS admin_flag,u.account_status AS seller_account_status,'
                    . 'COALESCE(sa.status,\'none\') AS seller_application_status '
                    . 'FROM products p INNER JOIN users u ON u.id=p.seller_id '
                    . 'LEFT JOIN seller_applications sa ON sa.user_id=u.id WHERE p.product_id=? LIMIT 1 FOR UPDATE';
                $stmt = $conn->prepare($query);
                $stmt->bind_param('i', $productId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) throw new RuntimeException('Product not found.');
                $row['available_stock'] = max(0, (int)$row['stock']-(int)$row['reserved_stock']);
                if (!savedCanBuy($row, (int)$buyerId)) {
                    throw new RuntimeException(savedAvailabilityState($row, (int)$buyerId) === 'out_of_stock'
                        ? 'This product is out of stock.' : 'This product is currently unavailable.');
                }
                $quantity = min($quantity, (int)$row['available_stock']);
                $check = $conn->prepare('SELECT cart_id,quantity FROM cart WHERE buyer_id=? AND product_id=? LIMIT 1 FOR UPDATE');
                $check->bind_param('ii', $buyerId, $productId);
                $check->execute();
                $existing = $check->get_result()->fetch_assoc();
                $check->close();
                if ($existing) {
                    $existingQuantity = max(1, (int)$existing['quantity']);
                    if ($existingQuantity >= (int)$row['available_stock']) {
                        throw new RuntimeException('All available units are already in your cart.');
                    }
                    $newQuantity = min((int)$row['available_stock'], $existingQuantity + $quantity);
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
            // Withdrawn/archived products must not be browsable through another store's list.
            $sql = savedDetailsQuery($conn) . ' WHERE p.seller_id=? AND LOWER(TRIM(p.status)) IN (\'available\',\'out of stock\',\'out_of_stock\') ORDER BY p.created_at DESC,p.product_id DESC';
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
            $check = $conn->prepare('SELECT product_id FROM products WHERE product_id=? LIMIT 1');
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
        $safeMessages = ['Product not found.', 'This product is currently unavailable.', 'This product is out of stock.', 'All available units are already in your cart.'];
        $publicMessage = in_array($e->getMessage(), $safeMessages, true)
            ? $e->getMessage() : 'Unable to complete the request. Please try again.';
        jsonResponse(false, $publicMessage, [], 400);
    } catch (Throwable $e) {
        error_log('LokalCraft Saved server: ' . $e->getMessage());
        jsonResponse(false, 'Unable to complete the request. Please try again.', [], 500);
    }
}
$activePage = 'saved';
$searchPlaceholder = 'Search saved products...';
$userName = $_SESSION['user_name'] ?? $_SESSION['buyer_name'] ?? 'Buyer';
$savedProducts = [];
$errorMessage = '';
try {
    $sql = savedDetailsQuery($conn) . ' INNER JOIN saved_products sp ON sp.product_id=p.product_id WHERE sp.buyer_id=? ORDER BY sp.saved_at DESC,sp.saved_id DESC';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $buyerId);
    $stmt->execute();
    $savedProducts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Throwable $e) {
    error_log('LokalCraft Saved list: ' . $e->getMessage());
    $errorMessage = 'Unable to load your saved products right now.';
}
$savedCount = count($savedProducts);
$availableCount = 0;
foreach ($savedProducts as $item) {
    if (savedCanBuy($item, (int)$buyerId)) $availableCount++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>LokalCraft | Saved Items</title>
  <style>
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
    .product-details-panel{
      border-radius: 24px;
    }
    .product-details-image-wrap, .product-reviews-card, .review-card, .seller-details-card, .marketplace-toast{
      border-radius: 16px;
    }
    .product-details-btn, .seller-action-btn{
      border-radius: 12px;
    }
    @media (max-width: 560px) {
      .product-card { border-radius: 18px; }
      .product-details-panel{
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
.product-details-modal, .lc-store-modal{position:fixed!important;inset:0!important;width:100%!important;height:var(--lc-vh,100dvh)!important;min-width:0;box-sizing:border-box}
.product-details-modal{z-index:140000;padding:22px}.product-details-backdrop,.lc-store-backdrop{background:rgba(35,27,21,.57);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px)}
.product-details-panel{width:min(980px,100%);height:auto;max-height:calc(var(--lc-vh,100dvh) - 44px);border-radius:21px;border:1px solid #ebded2;background:#fffdfa;padding:27px;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;scrollbar-gutter:stable}
.product-details-top{gap:24px}.product-details-image-wrap{border-radius:17px;background:#f7f1ea}.product-details-name{color:#362b23;font-size:clamp(1.32rem,2.4vw,1.8rem)}.product-details-price{color:#bc702f}.product-details-category{background:#fff0df;color:#a8612b}.product-details-section-title,.product-reviews-title,.seller-details-title{color:#42352a}
.product-details-description,.product-details-stock{color:#77675c;font-size:.86rem}.product-details-close,.lc-store-close{display:grid;place-items:center;position:sticky;float:right;top:0;right:0;width:40px;height:40px;z-index:10;border-radius:50%;border:1px solid #ebdacf;background:#fffaf4;color:#795338;font-size:25px;cursor:pointer;box-shadow:0 3px 12px rgba(52,32,17,.12)}
.product-details-actions{grid-template-columns:minmax(0,1fr) 48px;gap:9px}.product-details-btn{display:inline-flex;justify-content:center;align-items:center;gap:7px;min-width:0;min-height:47px;padding:8px 12px;border-radius:12px;font-size:.81rem;font-weight:800}.product-details-btn svg{width:18px;height:18px;flex:0 0 18px}.product-details-cart{background:#fff6ed;color:#b86c32;border:1px solid #f1d1b2}.product-details-cart:hover{background:#fdebd8}.product-details-save{display:grid;place-items:center;padding:0;border:1px solid #ead9c9;background:#fff;color:#947b66;transition:.15s}.product-details-save:hover,.product-details-save.is-saved{background:#fff0e1;border-color:#e7aa77;color:#ca6c2c}.product-details-save.is-saved svg{fill:currentColor}
.product-details-btn:disabled{opacity:.65;cursor:default}.product-details-btn.is-complete{background:#e9f8f1;color:#20744b;border-color:#b7dfc8}.product-reviews-card,.seller-details-card{border:1px solid #eee4d9;border-radius:16px;background:#fff;box-shadow:0 3px 12px rgba(67,44,22,.035)}.review-card{background:#fdfaf7;border:1px solid #f1e6da}.review-card-text{font-size:.82rem}.review-card-buyer{font-size:.77rem}.review-card-date{font-size:.7rem}
.lc-see-more{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:42px;margin-top:13px;padding:10px;border:1px solid #ebd8c5;border-radius:11px;background:#fff6ed;color:#a55f2f;font-size:.8rem;font-weight:800;cursor:pointer}.lc-see-more:hover{background:#ffebd8}.lc-see-more[hidden]{display:none!important}.lc-see-more:disabled{opacity:.55;cursor:wait}
.lc-store-name{border:0;padding:0;background:transparent;text-align:left;color:#664529;font-size:.83rem;font-weight:800;cursor:pointer;overflow-wrap:anywhere}.lc-store-name:hover{text-decoration:underline}.seller-details-person{min-width:0}.seller-details-person>div:last-child{min-width:0}.seller-details-actions{min-width:0}.seller-action-btn{border-radius:10px;border:1px solid #e6d7ca;background:#fffaf6;color:#9a602f;min-height:38px;font-size:.76rem}
.lc-store-modal{display:none;align-items:center;justify-content:center;padding:22px;z-index:130000}.lc-store-modal.is-open{display:flex}.lc-store-backdrop{position:absolute;inset:0}.lc-store-panel{position:relative;z-index:1;display:flex;flex-direction:column;width:min(850px,100%);max-height:calc(var(--lc-vh,100dvh) - 44px);height:min(830px,100%);min-width:0;border:1px solid #edddcb;border-radius:22px;background:#fffdfa;box-shadow:0 28px 80px rgba(29,19,12,.22);overflow:hidden}.lc-store-header{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 21px;border-bottom:1px solid #f0e7de;background:#fffaf5}.lc-store-heading{min-width:0}.lc-eyebrow{display:block;color:#b47543;font-size:.65rem;font-weight:850;letter-spacing:.14em}.lc-store-heading h2{font-size:clamp(1.2rem,3vw,1.55rem);margin-top:5px;line-height:1.15;color:#372b21;overflow-wrap:anywhere}.lc-store-close{float:none;position:relative;flex:0 0 40px}.lc-store-content{flex:1 1 auto;min-height:0;overflow-y:auto;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;padding:20px}.lc-store-summary{display:flex;align-items:center;gap:14px;padding:21px;border:1px solid #ead8c7;border-radius:16px;background:linear-gradient(116deg,#fffdfa,#ffeedb)}.lc-store-avatar{display:grid;place-items:center;flex:0 0 60px;width:60px;height:60px;border-radius:17px;border:3px solid #fff;background:linear-gradient(135deg,#f6ba80,#d88037);color:#fff;font-size:1.4rem;font-weight:800}.lc-store-summary-name{font-size:1.1rem;font-weight:850;color:#493225;overflow-wrap:anywhere}.lc-store-summary-meta{font-size:.8rem;color:#8e7764;margin-top:5px}.lc-store-details-toggle{max-width:190px;margin:12px 0 0}.lc-store-info{margin:14px 0 0}.lc-store-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.lc-store-fact{padding:14px;border:1px solid #f0e4da;border-radius:11px;background:#fff}.lc-store-fact.wide{grid-column:1/-1}.lc-store-fact small{display:block;margin-bottom:6px;color:#a08369;font-weight:800;font-size:.7rem}.lc-store-fact strong{display:block;color:#504338;font-size:.84rem;font-weight:700;line-height:1.45;white-space:pre-wrap;overflow-wrap:anywhere}.lc-store-products-heading{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:25px 0 12px}.lc-store-products-heading h3{font-size:1rem;color:#413122}.lc-store-products-heading span{font-size:.75rem;color:#987d67}.lc-store-products{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}.lc-store-product{min-width:0;display:flex;gap:12px;align-items:center;text-align:left;border:1px solid #eee2d7;border-radius:13px;padding:10px;background:#fff;cursor:pointer}.lc-store-product:hover{border-color:#ddaf82;background:#fffaf5}.lc-store-product-image{flex:0 0 83px;width:83px;height:83px;border-radius:10px;overflow:hidden;background:#f5ede5;display:grid;place-items:center;color:#c08353;font-size:1.5rem}.lc-store-product-image img{display:block;width:100%;height:100%;object-fit:cover}.lc-store-product-meta{min-width:0}.lc-store-product-meta strong{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;font-size:.86rem;color:#473a30}.lc-store-product-meta span{display:block;margin-top:5px;color:#b56b2d;font-weight:850;font-size:.88rem}.lc-store-product-meta small{display:block;margin-top:5px;color:#846f5c;font-size:.72rem}.lc-loading{padding:24px;text-align:center;font-size:.86rem;color:#887a6d;grid-column:1/-1}

.marketplace-toast{z-index:180000;border:1px solid #d7bfaa;background:#3e332b;font-size:.82rem;border-radius:12px}.marketplace-toast.success{background:#2e6544}.marketplace-toast.error{background:#883e36}
body.modal-open{overflow:hidden;touch-action:pan-y}
@media(max-width:980px){.product-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:840px){.page>.content{padding:108px 15px 28px!important}.product-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}.page-title{font-size:1.65rem}.page-subtitle{font-size:.78rem}}
@media(max-width:560px){
 .page>.content{padding:104px 12px 26px!important}.product-grid{gap:10px}.product-card{border-radius:13px}.product-body{padding:11px}.product-name{font-size:.88rem}.product-price{font-size:.91rem}.category-badge{font-size:.61rem}.seller-tag,.location-tag{font-size:.69rem}
 .product-details-modal,.lc-store-modal{padding:0!important}.product-details-panel{width:100vw;height:var(--lc-vh,100dvh);max-height:var(--lc-vh,100dvh);border:0;border-radius:0!important;padding:max(18px,env(safe-area-inset-top)) 15px calc(25px + env(safe-area-inset-bottom));overflow-y:auto}.product-details-close{position:sticky;top:0;margin-right:0;width:40px;height:40px}.product-details-top{display:grid;grid-template-columns:1fr;gap:13px}.product-details-image-wrap{aspect-ratio:1/0.8;border-radius:14px}.product-details-name{font-size:1.35rem;padding-right:0;clear:both}.product-details-actions{grid-template-columns:minmax(0,1fr) 43px;gap:6px}.product-details-btn{font-size:.74rem;padding:8px 6px;min-height:45px;border-radius:10px}.product-details-btn svg{height:15px;width:15px;flex-basis:15px}.seller-details-card{margin-top:17px;padding:15px}.seller-details-row{flex-direction:column;align-items:stretch}.seller-details-actions{width:100%}.seller-action-btn{flex:1}.product-reviews-card{padding:15px}.review-card{padding:11px}
 .lc-store-panel{height:var(--lc-vh,100dvh);max-height:var(--lc-vh,100dvh);width:100vw;border:0;border-radius:0}.lc-store-header{padding:calc(15px + env(safe-area-inset-top)) 15px 13px}.lc-store-content{padding:15px 14px calc(30px + env(safe-area-inset-bottom))}.lc-store-summary{padding:15px}.lc-store-avatar{flex-basis:52px;width:52px;height:52px}.lc-store-facts{grid-template-columns:1fr}.lc-store-fact.wide{grid-column:auto}.lc-store-products{grid-template-columns:1fr}.lc-store-product-image{flex-basis:75px;width:75px;height:75px}
 
 
}
@media(max-width:350px){.product-details-btn{font-size:.68rem}.product-details-actions{grid-template-columns:minmax(0,1fr) 40px}.lc-store-avatar{flex-basis:45px;width:45px;height:45px}.lc-store-summary{gap:9px}}
@media(prefers-reduced-motion:reduce){.product-card,.product-details-btn,.lc-see-more,.marketplace-toast{transition:none!important}}
/* Final interaction refinements. */
.product-details-modal, .lc-store-modal{top:var(--lc-vtop,0px)!important;bottom:auto!important}
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
.product-details-panel, .lc-store-panel{background:var(--lc-surface);border:1px solid #ead9c9;border-radius:22px;box-shadow:var(--lc-shadow)}
/* The close controls are absolutely positioned siblings of scrolling content. */
.lc-floating-close, .product-details-close, .lc-store-close{position:absolute!important;float:none!important;z-index:35!important;top:14px!important;right:14px!important;margin:0!important;display:grid;place-items:center;flex:none;width:40px!important;height:40px!important;padding:0!important;border:1px solid #eddcc9;border-radius:50%!important;background:rgba(255,253,249,.97);color:#835c3d;box-shadow:0 5px 18px rgba(59,35,15,.11);cursor:pointer;transition:transform .14s,background .14s}
.lc-floating-close svg, .product-details-close svg, .lc-store-close svg{width:19px;height:19px;pointer-events:none}
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
.product-details-actions{grid-template-columns:minmax(0,1fr) 48px;gap:9px;margin-top:6px}
.product-details-btn{min-height:48px;border-radius:12px;font-size:.79rem;min-width:0}
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
.lc-store-product{min-width:0;display:flex;flex-direction:column;align-items:stretch;gap:0;min-height:0;padding:0;border:1px solid #eadfd5;border-radius:16px;background:#fff;text-align:left;overflow:hidden;box-shadow:0 6px 17px rgba(43,27,15,.045);transition:border-color .16s,transform .16s,box-shadow .16s}
.lc-store-product:hover{border-color:#d9ac85;background:#fff;transform:translateY(-2px);box-shadow:0 12px 26px rgba(43,27,15,.1)}
.lc-store-product-image{flex:none;width:100%;height:auto;aspect-ratio:1/0.9;border-radius:0;display:grid;place-items:center;background:#f5eee6;overflow:hidden}
.lc-store-product-image img{width:100%;height:100%;object-fit:cover}.lc-store-product-image svg{width:34px;height:34px;color:#bd8d65}
.lc-store-product-meta{flex:1;min-width:0;width:100%;padding:14px 13px 15px;display:flex;flex-direction:column;gap:5px}
.lc-store-item-category{display:inline-flex!important;align-self:flex-start;margin:0 0 3px!important;padding:5px 8px;border-radius:999px;background:#fff0df;color:#ac692e!important;font-size:.68rem!important;font-weight:800}
.lc-store-product-meta strong{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4;font-size:.9rem;color:#433529;font-weight:800;min-height:2.5em}
.lc-store-product-meta span{color:#b86629;font-weight:850;font-size:1rem;margin:1px 0}
.lc-store-item-rating{font-size:.74rem!important;color:#957a63!important;margin:auto 0 0!important;line-height:1.5;overflow-wrap:anywhere}

.lc-store-fact{display:flex;flex-direction:row;align-items:flex-start;gap:12px}
.lc-store-fact-icon{display:grid;place-items:center;flex:0 0 37px;width:37px;height:37px;border-radius:11px;background:#fff2e7;color:#bf7d44}
.lc-store-fact-icon svg{width:18px;height:18px}.lc-store-fact-copy{display:block;min-width:0;flex:1}
/* Viewport corrections: photo stacks on mobile, while modal actions remain accessible. */
@media(max-width:1050px){.seller-details-row{flex-wrap:wrap}.seller-details-actions{width:100%;justify-content:stretch}.seller-action-btn{flex:1}.lc-store-products{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:760px){.lc-product-layout{grid-template-columns:1fr;display:flex;flex-direction:column;overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;height:100%}.lc-product-media{flex:none;padding:15px 15px 6px;background:#fffdfa;border-right:0}.product-details-image-wrap{max-height:none;aspect-ratio:1/0.73;border-radius:15px}.lc-image-caption{display:none}.lc-product-info-scroll{flex:none;min-height:0;height:auto;overflow:visible;padding:16px 17px 28px}.product-details-name{padding-right:0}.seller-details-row{flex-direction:column;align-items:stretch}.seller-details-actions{width:100%}.lc-store-products{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.lc-store-summary{grid-template-columns:58px minmax(0,1fr);grid-template-areas:"avatar details" "avatar status";column-gap:12px;row-gap:8px;align-items:center}.lc-store-active{margin-left:0;justify-self:start;max-width:100%}.lc-store-header{min-height:74px}.lc-store-content{padding:16px}}
@media(max-width:560px){
 .product-details-modal, .lc-store-modal{padding:0!important}
 .product-details-panel, .lc-store-panel{width:100vw!important;height:var(--lc-vh,100dvh)!important;max-height:var(--lc-vh,100dvh)!important;border:0!important;border-radius:0!important;padding:0!important}
 .product-details-panel{overflow:hidden!important}.lc-product-layout{padding-top:max(8px,env(safe-area-inset-top));padding-bottom:env(safe-area-inset-bottom)}
 .lc-product-media{padding:12px 12px 0}.product-details-image-wrap{aspect-ratio:1/0.76}.lc-product-info-scroll{padding:17px 15px 30px}
 .lc-floating-close, .product-details-close, .lc-store-close{top:calc(11px + env(safe-area-inset-top))!important;right:12px!important;width:39px!important;height:39px!important;background:rgba(255,253,250,.98)}
 .product-details-actions{grid-template-columns:minmax(0,1fr) 44px;gap:7px}.product-details-btn{font-size:.72rem;min-height:45px;padding:8px 7px}
 .seller-details-card,.product-reviews-card{padding:15px}.seller-details-title{margin-bottom:13px}.seller-details-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.seller-action-btn{min-height:43px;padding:9px 8px}
 .lc-store-header{padding:calc(15px + env(safe-area-inset-top)) 62px 14px 16px}
 .lc-store-summary{grid-template-columns:54px minmax(0,1fr);column-gap:12px;row-gap:8px;padding:16px;min-height:0}.lc-store-avatar{flex-basis:54px;width:54px;height:54px;border-radius:17px}.lc-store-active{margin:0;justify-self:start}.lc-store-summary-name{font-size:1.13rem}.lc-store-details-toggle{margin-top:12px;min-height:47px}
 .lc-store-product-meta{padding:10px}.lc-store-product-meta strong{font-size:.82rem}.lc-store-product-meta span{font-size:.87rem}.lc-store-product-image{aspect-ratio:1/0.83}.lc-store-item-rating{font-size:.66rem!important}
 
 
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


.saved-heading { align-items:center; }
.saved-summary { display:flex; flex-wrap:wrap; gap:8px; justify-content:flex-end; }
.saved-summary-pill { display:inline-flex; align-items:center; gap:5px; min-height:32px; padding:6px 11px; border:1px solid #eadfd5; border-radius:999px; background:#fffaf5; color:#827266; font-size:.73rem; font-weight:700; }
.saved-summary-pill strong { color:#4a3425; font-size:.8rem; }
.product-card.is-out-of-stock { opacity:.9; }
.stock-tag.out-of-stock { background:#fff3e8; color:#a76225; }
/* Unavailable Saved items keep the regular product-card layout, without a photo or private listing details. */
.lc-withdrawn-card { cursor:default!important; }
.lc-withdrawn-card:hover { transform:none; box-shadow:0 8px 22px rgba(67,44,22,.045); }
.lc-withdrawn-media { background:linear-gradient(135deg,#f6f0ea,#ede4db); }
.lc-withdrawn-media .stock-tag { color:#a7514b;background:#fff8f6;border-color:#f1dfd9; }
.lc-withdrawn-card .product-body { justify-content:flex-start; }
.lc-withdrawn-card .product-name { overflow-wrap:anywhere; }
.lc-withdrawn-card .product-save-btn { cursor:pointer; }
.lc-store-item-unavailable { align-self:flex-start; color:#a04a40; font-size:.67rem; font-weight:800; }
#productDetailsCart:disabled{ cursor:not-allowed; opacity:.52; }
@media(max-width:560px){ .saved-summary { justify-content:flex-end; } .saved-summary-pill { padding:5px 8px; font-size:.66rem; } .saved-heading { flex-direction:row; align-items:center; } }
  
/* Centralized ordering: Marketplace adds items; Cart handles requests and options. */
.product-details-actions{grid-template-columns:minmax(0,1fr) 48px;gap:9px}
.product-details-cart{background:#e98b40;color:#fff;border:1px solid #e98b40}
.product-details-cart:hover:not(:disabled){background:#cf722b;color:#fff;border-color:#cf722b}
.lc-cart-guidance{margin:10px 0 0;color:#887465;font-size:.74rem;line-height:1.5}
.marketplace-toast.show.has-action{pointer-events:auto}
.lc-toast-action{display:inline-block;margin-left:10px;color:#fff;font-weight:800;text-decoration:underline;text-underline-offset:2px;white-space:nowrap}
.lc-toast-action:focus-visible{outline:2px solid #fff;outline-offset:3px;border-radius:3px}
@media(max-width:560px){.product-details-actions{grid-template-columns:minmax(0,1fr) 44px;gap:7px}.lc-cart-guidance{font-size:.72rem}}
@media(max-width:350px){.product-details-actions{grid-template-columns:minmax(0,1fr) 40px}}
</style>
</head>
<body>
  <div class="page">
    <?php require __DIR__ . '/buyer_nav.php'; ?>
    <style>
      .page { display: block !important; min-height: 100vh !important; }
      .page > .content { position: fixed !important; inset: 0 0 0 240px !important; width: auto !important; max-width: none !important; height: auto !important; min-height: 100vh !important; margin: 0 !important; overflow-y: auto !important; overflow-x: hidden !important; }
      @media (max-width: 840px) { .page { display: flex !important; flex-direction: column !important; } .page > .content { position: relative !important; inset: auto !important; width: 100% !important; height: auto !important; min-height: auto !important; overflow: visible !important; } }
    </style>
    <main class="content">
      <?php require __DIR__ . '/buyer_search.php'; ?>
      <div class="marketplace-wrap saved-wrap">
        <div class="heading-row lc-market-heading saved-heading">
          <h1 class="page-title">Saved</h1>
          <div class="saved-summary" aria-label="Saved product summary">
            <span class="saved-summary-pill"><strong id="savedCount"><?= $savedCount ?></strong> saved</span>
            <span class="saved-summary-pill"><strong id="availableCount"><?= $availableCount ?></strong> available</span>
          </div>
        </div>
        <?php if ($errorMessage !== ''): ?>
          <div class="error-message"><?= htmlspecialchars($errorMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($errorMessage === '' && !$savedProducts): ?>
          <div class="empty-state" id="savedEmptyState"><h2>No saved products yet</h2><p>Tap the heart on products in Marketplace to save them here.</p></div>
        <?php elseif ($savedProducts): ?>
          <div class="product-grid" id="savedGrid">
          <?php foreach ($savedProducts as $row):
              $product = savedProductData($row, (int)$buyerId, true);
              $productId = (int)$product['product_id'];
              $available = (bool)$product['is_available'];
              $availabilityState = $product['availability_state'];
              $withdrawn = $availabilityState === 'unavailable';
              $outOfStock = $availabilityState === 'out_of_stock';
              $displaySeller = $product['seller_name'];
              $photo = $product['product_photo'];
              $reviewCount = (int)$product['review_count'];
              $rating = (float)$product['avg_rating'];
              $filledStars = $reviewCount ? max(0,min(5,(int)round($rating))) : 0;
          ?>
            <article class="product-card<?= $withdrawn ? ' is-unavailable lc-withdrawn-card' : ($outOfStock ? ' is-out-of-stock' : '') ?>"
              data-product-id="<?= $productId ?>"
              data-name="<?= htmlspecialchars($product['product_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              data-category="<?= htmlspecialchars(strtolower($product['craft_category']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              data-seller="<?= $withdrawn ? '' : htmlspecialchars(strtolower($displaySeller), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              data-location="<?= $withdrawn ? '' : htmlspecialchars(strtolower(trim($product['barangay'].' '.$product['city'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              data-available="<?= $available ? '1' : '0' ?>"
              data-availability-state="<?= htmlspecialchars($availabilityState, ENT_QUOTES, 'UTF-8') ?>"
              <?php if (!$withdrawn): ?>data-product="<?= htmlspecialchars(json_encode($product, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?php endif; ?>>
              <?php if ($withdrawn): ?>
              <div class="product-image-wrap lc-withdrawn-media">
                <button type="button" class="product-save-btn is-saved" data-save-product="<?= $productId ?>" title="Remove from Saved" aria-pressed="true" aria-label="Remove <?= htmlspecialchars($product['product_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> from Saved">♥</button>
                <span class="stock-tag">Unavailable</span>
              </div>
              <div class="product-body">
                <span class="category-badge"><?= htmlspecialchars($product['craft_category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <div class="product-name"><?= htmlspecialchars($product['product_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              </div>
              <?php else: ?>
              <div class="product-image-wrap">
                <button type="button" class="product-save-btn is-saved" data-save-product="<?= $productId ?>" title="Remove from Saved" aria-pressed="true" aria-label="Remove <?= htmlspecialchars($product['product_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> from Saved">♥</button>
                <?php if ($photo): ?>
                  <img class="product-image" src="<?= htmlspecialchars($photo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="<?= htmlspecialchars($product['product_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" loading="lazy" decoding="async">
                <?php else: ?>
                  <div class="placeholder-image" aria-label="Photo unavailable">🛍</div>
                <?php endif; ?>
                <span class="stock-tag<?= $outOfStock ? ' out-of-stock' : '' ?>"><?= $outOfStock ? 'Out of Stock' : (int)$product['available_stock'] . ' available' ?></span>
              </div>
              <div class="product-body">
                <span class="category-badge"><?= htmlspecialchars($product['craft_category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <div class="product-name"><?= htmlspecialchars($product['product_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <div class="product-price"><?= $product['cost'] ?></div>
                <div class="product-rating" aria-label="Product rating">
                  <span class="product-rating-stars"><?= $reviewCount ? str_repeat('★',$filledStars).str_repeat('☆',5-$filledStars) : '☆☆☆☆☆' ?></span>
                  <?php if ($reviewCount): ?><strong><?= number_format($rating,1) ?></strong><span>(<?= $reviewCount ?>)</span><?php else: ?><span>No reviews yet</span><?php endif; ?>
                </div>
                <?php if ($product['description'] !== ''): ?><div class="product-description"><?= htmlspecialchars($product['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
                <div class="seller-tag">by <?= htmlspecialchars($displaySeller, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <?php if ($product['barangay'] !== '' || $product['city'] !== ''): ?><div class="location-tag"><?= htmlspecialchars(implode(', ',array_filter([$product['barangay'],$product['city']])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
              </div>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
          </div>
          <div class="lc-market-no-results" id="buyerNoResults" hidden><strong>No matching saved products</strong><p>Try a different search term.</p></div>
        <?php endif; ?>
      </div>
    </main>
  </div>
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
let selectedProduct=null,toastTimeout=null;
let selectedStoreId=0,storeFetchToken=0,reviewsFetchToken=0,reviewOffset=0,reviewBusy=false,reviewHasMore=false;
const savedStates=new Map(),storeCache=new Map();
// Each overlay is a navigation level, including nested shop and product details.
// Using actual browser history means Android/iPhone Back closes one level at a time.
const navId='lc-market-'+Date.now()+'-'+Math.random().toString(36).slice(2);
const frames=[];let depth=0;
const stateAt=n=>({...window.history.state,lcMarket:{navId,depth:n}});
try { window.history.replaceState(stateAt(0),'',window.location.href); }catch(_){ }
function money(amount){return '₱'+Number(amount||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});}
function productLocation(product){return [product?.barangay,product?.city].filter(Boolean).join(', ');}
function stars(value){const n=Math.max(0,Math.min(5,Math.round(Number(value)||0)));return '★'.repeat(n)+'☆'.repeat(5-n);}
function showToast(message,type='',action=null){
  clearTimeout(toastTimeout);
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
async function postAction(form,url='saved.php'){
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
  if(product.availability_state==='unavailable')return;
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
  $('productDetailsStock').textContent=product.availability_state==='out_of_stock'?'Out of Stock':(product.is_available===false?'Unavailable':'Available: '+Math.max(0,Number(product.stock)||0));
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
  const button=document.createElement('button');button.type='button';button.className='lc-store-product';
  const withdrawn=product.availability_state==='unavailable';
  button.disabled=withdrawn;
  button.setAttribute('aria-label',withdrawn?'Unavailable: '+(product.product_name||'product'):'View '+(product.product_name||'product')+' details');
  if(withdrawn){button.textContent=product.product_name||'Product';button.classList.add('lc-store-withdrawn');return button;}
  const thumb=document.createElement('div');thumb.className='lc-store-product-image';
  if(product.product_photo){
    const image=document.createElement('img');
    image.loading='eager';
    image.decoding='async';
    image.alt=product.product_name||'Handmade product';
    image.addEventListener('error',()=>{
      image.remove();
      thumb.classList.add('lc-store-image-unavailable');
      const label=document.createElement('span');label.textContent='Photo unavailable';thumb.append(label);
    },{once:true});
    image.src=product.product_photo;
    thumb.append(image);
  }else{
    thumb.classList.add('lc-store-image-unavailable');
    const label=document.createElement('span');label.textContent='No photo available';thumb.append(label);
  }
  const meta=document.createElement('div');meta.className='lc-store-product-meta';
  const category=document.createElement('small');category.className='lc-store-item-category';category.textContent=storeCategoryLabel(product.craft_category);
  const name=document.createElement('strong');name.textContent=product.product_name;
  const price=document.createElement('span');price.className='lc-store-item-price';price.textContent=product.cost||money(product.cost_value);
  const reviews=document.createElement('small');reviews.className='lc-store-item-rating';reviews.textContent=product.review_count?stars(product.avg_rating)+'  '+Number(product.avg_rating||0).toFixed(1)+' ('+product.review_count+')':'☆☆☆☆☆  No reviews yet';
  meta.append(category,name,price,reviews);if(product.availability_state==='out_of_stock'){const stock=document.createElement('small');stock.className='lc-store-item-unavailable';stock.textContent='Out of Stock';meta.append(stock);}button.append(thumb,meta);
  button.addEventListener('click',()=>openProduct(product));return button;
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
  const meta=document.createElement('div');meta.className='lc-store-profile-details';const role=document.createElement('span');role.className='lc-store-role';role.textContent=shop.is_active===false?'LOCAL ARTISAN':'VERIFIED LOCAL ARTISAN';const nameEl=document.createElement('div');nameEl.className='lc-store-summary-name';nameEl.textContent=name;
  const sub=document.createElement('div');sub.className='lc-store-summary-meta';sub.textContent=[shop.craftcategory,shop.barangay,shop.city||'Taguig City'].filter(Boolean).join(' · ');meta.append(role,nameEl,sub);const active=document.createElement('span');active.className='lc-store-active';active.innerHTML='<span class="lc-store-active-dot"></span>'+(shop.is_active===false?'Inactive Store':'Active Store');$('lcStoreSummary').append(avatar,meta,active);
  const facts=$('lcStoreFacts');facts.replaceChildren(storeFact('Seller Name',shop.seller_name),storeFact('Craft Category',shop.craftcategory),storeFact('Business Description',shop.description,true),storeFact('Business Location',[shop.barangay,shop.city||'Taguig City'].filter(Boolean).join(', '),true));
  $('lcStoreMore').hidden=false;$('lcStoreProductCount').textContent=items.length+' items';
  const listing=$('lcStoreProducts');listing.replaceChildren();
  if(!items.length){const empty=document.createElement('div');empty.className='lc-loading';empty.textContent='No products listed yet.';listing.append(empty);}
  else items.forEach(item=>listing.append(makeStoreProduct(item)));
}
function openStore(sellerId){if(!Number(sellerId))return;selectedStoreId=0;push('store',{sellerId:Number(sellerId)});}
$('lcStoreMore')?.addEventListener('click',()=>{const info=$('lcStoreInfo'),exp=info.hidden;info.hidden=!exp;const btn=$('lcStoreMore');btn.setAttribute('aria-expanded',exp?'true':'false');btn.querySelector('span').textContent=exp?'Hide Business Details':'See Business Details';});
storeClose?.addEventListener('click',()=>{if(currentFrame()?.type==='store')backOne();});
$('sellerDetailsName')?.addEventListener('click',()=>openStore(selectedProduct?.seller_id));
$('lcSellerAvatar')?.addEventListener('click',()=>openStore(selectedProduct?.seller_id));
$('sellerDetailsStore')?.addEventListener('click',()=>openStore(selectedProduct?.seller_id));
// Saved and cart never navigate away automatically.
function refreshSavedSummary(){
  const cards=Array.from(document.querySelectorAll('#savedGrid .product-card'));
  const count=$('savedCount'),available=$('availableCount');
  if(count)count.textContent=String(cards.length);
  if(available)available.textContent=String(cards.filter(card=>card.dataset.available==='1').length);
  const grid=$('savedGrid');
  if(grid&&!cards.length){grid.remove();const empty=document.createElement('div');empty.className='empty-state';empty.id='savedEmptyState';empty.innerHTML='<h2>No saved products yet</h2><p>Tap the heart on products in Marketplace to save them here.</p>';document.querySelector('.saved-wrap')?.append(empty);$('buyerNoResults')?.remove();}
  else applySavedSearch();
}
function applySavedSearch(){
  const term=String($('productSearch')?.value||'').trim().toLowerCase();
  const cards=Array.from(document.querySelectorAll('#savedGrid .product-card'));
  let visible=0;
  for(const card of cards){const haystack=[card.dataset.name,card.dataset.seller,card.dataset.category,card.dataset.location].join(' ').toLowerCase();const show=!term||haystack.includes(term);card.hidden=!show;card.style.display=show?'':'none';if(show)visible++;}
  const empty=$('buyerNoResults');if(empty)empty.hidden=visible!==0;
}
$('productSearch')?.addEventListener('input',applySavedSearch);
function syncSaveButton(productId,saved){
  savedStates.set(Number(productId),Boolean(saved));
  document.querySelectorAll('[data-save-product="'+productId+'"]').forEach(btn=>{btn.classList.toggle('is-saved',saved);btn.textContent=saved?'♥':'♡';btn.title=saved?'Saved':'Save product';btn.setAttribute('aria-label',saved?'Remove from saved':'Save product');btn.setAttribute('aria-pressed',saved?'true':'false');});
  if(Number(selectedProduct?.product_id)===Number(productId)){selectedProduct.is_saved=saved;detailSave.classList.toggle('is-saved',saved);detailSave.setAttribute('aria-pressed',saved?'true':'false');detailSave.setAttribute('aria-label',saved?'Remove from saved':'Save product');detailSave.title=saved?'Saved':'Save product';}
}
const busySaveIds=new Set();
async function toggleSaved(productId){if(busySaveIds.has(productId))return;busySaveIds.add(productId);detailSave.disabled=true;
  try{const form=new FormData();form.append('marketplace_action','toggle_saved');form.append('product_id',String(productId));const data=await postAction(form);syncSaveButton(productId,Boolean(data.saved));if(!data.saved){document.querySelector('[data-product-id="'+productId+'"]')?.remove();refreshSavedSummary();}showToast(data.saved?'Saved to your list.':'Removed from Saved.','success');}
  catch(e){showToast(e.message,'error');}finally{busySaveIds.delete(productId);detailSave.disabled=false;}
}
detailSave.addEventListener('click',()=>{if(selectedProduct)toggleSaved(Number(selectedProduct.product_id));});
document.querySelectorAll('[data-save-product]').forEach(btn=>btn.addEventListener('click',event=>{event.stopPropagation();event.preventDefault();toggleSaved(Number(btn.dataset.saveProduct));}));
const cartBusyIds=new Set();
detailCart.addEventListener('click',async()=>{
  if(!selectedProduct||selectedProduct.is_available===false||stockMax()<=0)return;
  const productId=Number(selectedProduct.product_id);
  if(cartBusyIds.has(productId))return;cartBusyIds.add(productId);
  detailCart.disabled=true;detailCart.innerHTML='<span class="lc-spinner"></span><span>Adding...</span>';
  try{const form=new FormData();form.append('marketplace_action','add_to_cart');form.append('product_id',String(productId));form.append('quantity','1');await postAction(form);
    detailCart.classList.add('is-complete');detailCart.innerHTML='<span>✓ Added to Cart</span>';showToast('Added to cart. Review your items when ready.','success',{label:'View Cart',href:'cart.php'});
    setTimeout(()=>{cartBusyIds.delete(productId);if(Number(selectedProduct?.product_id)!==productId)return;detailCart.disabled=selectedProduct.is_available===false||stockMax()<=0;detailCart.classList.remove('is-complete');detailCart.innerHTML='<span>Add to Cart</span>';},1800);
  }catch(e){cartBusyIds.delete(productId);if(Number(selectedProduct?.product_id)===productId){detailCart.disabled=selectedProduct.is_available===false||stockMax()<=0;detailCart.textContent='Add to Cart';}showToast(e.message,'error');}
});
function stockMax(){return Math.max(0,Math.trunc(Number(selectedProduct?.stock)||0));}
// Message opens the seller's existing conversation in the Buyer Messages module.
// Messaging is optional and does not create an order request.
// Product cards remain keyboard accessible; seller name opens shop rather than searching all products.
document.querySelectorAll('.product-card').forEach(card=>{
  if(card.dataset.availabilityState==='unavailable')return;
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
  if(depth>0){event.preventDefault();backOne();}
});
// Keep native phone back in charge of closing one overlay at a time.
window.addEventListener('pageshow',event=>{if(event.persisted)render();});
})();


  </script>
</body>
</html>
