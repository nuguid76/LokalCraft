<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
$activePage = 'product_listing';
function pmEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function pmDate($value): string
{
    $time = $value ? strtotime((string)$value) : false;
    return $time ? date('M j, Y · g:i A', $time) : '—';
}
function pmMoney($value): string
{
    return '₱' . number_format((float)$value, 2);
}
function pmImagePaths($stored): array
{
    $stored = trim(str_replace('\\', '/', (string)$stored));
    if ($stored === '' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $stored)) {
        return [];
    }
    $stored = ltrim($stored, '/');
    while (str_starts_with($stored, './')) {
        $stored = substr($stored, 2);
    }
    $parts = explode('/', $stored);
    if (in_array('..', $parts, true) || in_array('.', $parts, true) || in_array('', $parts, true)) {
        return [];
    }
    $encoded = implode('/', array_map('rawurlencode', $parts));
    $paths = count($parts) > 1 ? [$encoded] : [
        'uploads/products/' . $encoded,
        'uploads/product_photos/' . $encoded,
        'uploads/product_images/' . $encoded,
        'uploads/' . $encoded,
        $encoded
    ];
    return array_values(array_unique(array_map(static fn($p) => '../' . $p, $paths)));
}
function pmLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}
function pmModerationEntry(string $message): ?array
{
    // Supports new internal notice records and older moderation messages.
    if (preg_match('/^\[LokalCraft Seller Notice\] Seller #(\d+) Product #(\d+) removed from marketplace\. Reason: (.*)$/s', $message, $match)) {
        return ['product_id' => (int)$match[2], 'reason' => trim($match[3])];
    }
    if (preg_match('/^\[LokalCraft Moderation\] Product #(\d+) \((.*?)\) was removed from the marketplace by Admin\. Reason: (.*?)(?:\. You may update the photo, category, or product information and contact support for review\.)?$/s', $message, $match)) {
        return ['product_id' => (int)$match[1], 'reason' => trim($match[3])];
    }
    if (preg_match('/^\[LokalCraft Moderation\] Product #(\d+) was removed from the marketplace\. Reason: (.*)$/s', $message, $match)) {
        return ['product_id' => (int)$match[1], 'reason' => trim($match[2])];
    }
    return null;
}
$adminId = 0;
foreach (['user_id', 'admin_id', 'logged_id', 'id'] as $key) {
    $value = $_SESSION[$key] ?? null;
    if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
        $adminId = (int)$value;
        break;
    }
}
if ($adminId <= 0) {
    header('Location: ../login.php');
    exit;
}
try {
    $adminStmt = $conn->prepare('SELECT firstname, lastname, AdminOrNot, account_status FROM users WHERE id = ? LIMIT 1');
    if (!$adminStmt) {
        throw new RuntimeException('Unable to verify administrator.');
    }
    $adminStmt->bind_param('i', $adminId);
    $adminStmt->execute();
    $adminStmt->bind_result($firstName, $lastName, $isAdmin, $adminStatus);
    $found = $adminStmt->fetch();
    $adminStmt->close();
    if (!$found || (int)$isAdmin !== 1 || strtolower(trim((string)$adminStatus)) !== 'active') {
        http_response_code(403);
        exit('Access denied.');
    }
    $userName = trim((string)$firstName . ' ' . (string)$lastName) ?: 'Admin';
    $adminName = $userName;
} catch (Throwable $error) {
    error_log('LokalCraft product monitoring auth: ' . $error->getMessage());
    http_response_code(503);
    exit('Product monitoring is temporarily unavailable.');
}
if (empty($_SESSION['product_monitoring_csrf'])) {
    $_SESSION['product_monitoring_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['product_monitoring_csrf'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transactionStarted = false;
    try {
        $postedCsrf = (string)($_POST['csrf_token'] ?? '');
        if ($postedCsrf === '' || !hash_equals($csrfToken, $postedCsrf)) {
            throw new DomainException('Your session expired. Please refresh and try again.');
        }
        if (($_POST['action'] ?? '') !== 'remove') {
            throw new DomainException('Invalid request.');
        }
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reason = trim((string)($_POST['removal_reason'] ?? ''));
        if (!$productId) {
            throw new DomainException('Invalid product.');
        }
        if ($reason === '' || pmLength($reason) > 500 || strlen($reason) > 2000) {
            throw new DomainException('Please enter a removal reason of 1 to 500 characters.');
        }
        $conn->begin_transaction();
        $transactionStarted = true;
        $stmt = $conn->prepare('SELECT product_id, seller_id, product_name, status FROM products WHERE product_id = ? FOR UPDATE');
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $stmt->bind_result($dbProductId, $sellerId, $productName, $productStatus);
        $found = $stmt->fetch();
        $stmt->close();
        if (!$found) {
            throw new DomainException('This product could not be found.');
        }
        if (strcasecmp(trim((string)$productStatus), 'Archived') === 0) {
            throw new DomainException('This product is already archived.');
        }
        $stmt = $conn->prepare("UPDATE products SET status = 'Archived' WHERE product_id = ? AND status <> 'Archived'");
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        if ($changed !== 1) {
            throw new RuntimeException('Unable to archive this listing.');
        }
        // Internal moderation notice: stored in the administrator's own message ledger.
        // It cannot appear in the seller's Buyer chat, which reads messages addressed to the seller.
        // Seller-only interfaces can read this marker after verifying the seller account.
        $message = '[LokalCraft Seller Notice] Seller #' . (int)$sellerId
            . ' Product #' . $productId . ' removed from marketplace. Reason: ' . $reason;
        $stmt = $conn->prepare('INSERT INTO messages (sender_id, receiver_id, order_id, message_text, is_read, sent_at) VALUES (?, ?, NULL, ?, 0, NOW())');
        $sellerId = (int)$sellerId;
        $stmt->bind_param('iis', $adminId, $adminId, $message);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
        $transactionStarted = false;
        $_SESSION['product_monitoring_flash'] = [
            'type' => 'success',
            'message' => 'Listing archived. The seller warning was saved for the Seller interface.'
        ];
    } catch (Throwable $error) {
        if ($transactionStarted) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
                error_log('LokalCraft moderation rollback failed: ' . $rollbackError->getMessage());
            }
        }
        error_log('LokalCraft product removal: ' . $error->getMessage());
        $_SESSION['product_monitoring_flash'] = [
            'type' => 'error',
            'message' => $error instanceof DomainException ? $error->getMessage() : 'Could not remove this listing. Please try again.'
        ];
    }
    header('Location: product_listing.php', true, 303);
    exit;
}
$flash = $_SESSION['product_monitoring_flash'] ?? null;
unset($_SESSION['product_monitoring_flash']);
$products = [];
$counts = ['total' => 0, 'live' => 0, 'out_of_stock' => 0, 'removed' => 0];
$errorMessage = '';
try {
    $moderation = [];
    // Keeping the reason in messages means no new product columns are needed.
    $moderationQuery = $conn->query("SELECT m.message_text, m.sent_at, m.message_id
        FROM messages m
        INNER JOIN users moderator ON moderator.id = m.sender_id
        WHERE moderator.AdminOrNot = 1
          AND (m.message_text LIKE '[LokalCraft Moderation] Product #%'
               OR (m.sender_id = m.receiver_id AND m.message_text LIKE '[LokalCraft Seller Notice] Seller #%'))
        ORDER BY m.sent_at DESC, m.message_id DESC");
    if (!$moderationQuery) {
        throw new RuntimeException('Cannot load moderation records.');
    }
    while ($row = $moderationQuery->fetch_assoc()) {
        $entry = pmModerationEntry((string)$row['message_text']);
        if ($entry !== null && !isset($moderation[$entry['product_id']])) {
            $moderation[$entry['product_id']] = [
                'reason' => $entry['reason'],
                'date' => $row['sent_at']
            ];
        }
    }
    $moderationQuery->free();
    $productQuery = $conn->query("SELECT
        p.product_id, p.seller_id, p.product_photo, p.product_name, p.craft_category,
        p.description, p.cost, p.stock, COALESCE(p.reserved_stock, 0) AS reserved_stock,
        p.status, p.created_at,
        COALESCE(
            (SELECT NULLIF(TRIM(b.businessname), '') FROM businessinfo b
             WHERE b.user_id = p.seller_id ORDER BY b.business_id DESC LIMIT 1),
            NULLIF(TRIM(CONCAT_WS(' ', u.firstname, u.lastname)), ''),
            'Unknown seller'
        ) AS seller_name
        FROM products p
        LEFT JOIN users u ON u.id = p.seller_id
        ORDER BY p.created_at DESC, p.product_id DESC");
    if (!$productQuery) {
        throw new RuntimeException('Cannot load products.');
    }
    while ($row = $productQuery->fetch_assoc()) {
        $id = (int)$row['product_id'];
        $state = strtolower(trim((string)$row['status']));
        $isArchived = $state === 'archived';
        $removed = $isArchived && isset($moderation[$id]);
        // The three tabs cover every product: Archived goes under Removed,
        // with a separate flag identifying actual admin moderation.
        $row['monitor_state'] = $isArchived ? 'removed' : ($state === 'out of stock' ? 'out_of_stock' : 'live');
        $row['admin_removed'] = $removed;
        $row['removal_reason'] = $removed ? $moderation[$id]['reason'] : '';
        $row['removed_at'] = $removed ? $moderation[$id]['date'] : '';
        $counts['total']++;
        $counts[$row['monitor_state']]++;
        $products[] = $row;
    }
    $productQuery->free();
} catch (Throwable $error) {
    error_log('LokalCraft product monitoring load: ' . $error->getMessage());
    $errorMessage = 'Could not load product records. Check the server log.';
}
function pmProductCard(array $p): void
{
    $photos = pmImagePaths($p['product_photo'] ?? '');
    $photoJson = pmEscape(json_encode($photos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $state = (string)$p['monitor_state'];
    $isAdminRemoved = !empty($p['admin_removed']);
    $badgeLabel = $state === 'removed'
        ? ($isAdminRemoved ? 'Removed by Admin' : 'Seller Archived')
        : ($state === 'out_of_stock' ? 'Out of Stock' : 'Available');
    $search = implode(' ', [
        $p['product_id'], $p['product_name'], $p['seller_name'],
        $p['craft_category'], $p['status'], $badgeLabel
    ]);
    ?>
    <article class="product-card" data-product data-state="<?= pmEscape($state) ?>" data-search="<?= pmEscape(strtolower($search)) ?>" <?= $state === 'live' ? '' : 'hidden' ?>>
        <button type="button" class="card-open" data-detail
            data-id="<?= (int)$p['product_id'] ?>"
            data-name="<?= pmEscape($p['product_name']) ?>"
            data-seller="<?= pmEscape($p['seller_name']) ?>"
            data-category="<?= pmEscape($p['craft_category']) ?>"
            data-description="<?= pmEscape($p['description']) ?>"
            data-price="<?= pmEscape(pmMoney($p['cost'])) ?>"
            data-stock="<?= (int)$p['stock'] ?>"
            data-reserved="<?= (int)$p['reserved_stock'] ?>"
            data-status="<?= pmEscape($p['status']) ?>"
            data-date="<?= pmEscape(pmDate($p['created_at'])) ?>"
            data-state="<?= pmEscape($state) ?>"
            data-admin-removed="<?= $isAdminRemoved ? '1' : '0' ?>"
            data-removed="<?= pmEscape(pmDate($p['removed_at'])) ?>"
            data-reason="<?= pmEscape($p['removal_reason']) ?>"
            data-photos="<?= $photoJson ?>"
            aria-label="View <?= pmEscape($p['product_name']) ?>">
            <div class="product-photo">
                <?php if ($photos): ?>
                    <img src="<?= pmEscape($photos[0]) ?>" data-fallbacks="<?= $photoJson ?>" alt="<?= pmEscape($p['product_name']) ?>" loading="lazy">
                <?php endif; ?>
                <div class="photo-empty" <?= $photos ? 'hidden' : '' ?>><span aria-hidden="true">▧</span><small>No product image</small></div>
                <span class="product-state state-<?= pmEscape($state) ?>"><?= pmEscape($badgeLabel) ?></span>
            </div>
            <div class="product-copy">
                <h3><?= pmEscape($p['product_name']) ?></h3>
                <p><?= pmEscape($p['seller_name']) ?></p>
                <div class="product-bottom"><strong><?= pmEscape(pmMoney($p['cost'])) ?></strong><span>View details <span aria-hidden="true">↗</span></span></div>
            </div>
        </button>
    </article>
    <?php
}
header('Cache-Control: private, no-store, max-age=0');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LokalCraft | Product Listing</title>
<style>
:root{--pm-bg:#f8f7f5;--pm-text:#4a4540;--pm-muted:#827d78;--pm-line:#eae5de;--pm-orange:#e99951;--pm-soft:#fff6ed;--pm-green:#488a65;--pm-red:#bc625e;--pm-shadow:0 4px 20px rgba(70,54,38,.035)}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;background:var(--pm-bg);color:var(--pm-text);font:14px/1.5 "Segoe UI",Arial,sans-serif;overflow-x:hidden}
body.pm-modal-open{overflow:hidden}
.page{display:flex;min-height:100vh}
.content{min-width:0;flex:1;padding:29px 28px 42px}
.pm-header{margin-bottom:18px}
.pm-header h1{margin:0 0 4px;font-size:clamp(1.35rem,2.2vw,1.75rem);line-height:1.25;font-weight:750;letter-spacing:-.35px;color:#3f3a35}
.pm-header p{margin:0;color:var(--pm-muted);font-size:.82rem}
.pm-note{padding:11px 13px;margin-bottom:14px;border:1px solid var(--pm-line);border-radius:11px;background:#fff;font-size:.83rem}
.pm-note.success{background:#f0faf3;border-color:#cee9d7;color:#39694b}
.pm-note.error{background:#fff3f3;border-color:#eed4d4;color:#99504d}
.pm-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:0 0 19px}
.pm-stat{min-width:0;min-height:104px;padding:15px 16px;border:1px solid var(--pm-line);border-radius:13px;background:#fff;box-shadow:var(--pm-shadow)}
.pm-stat-top{display:flex;align-items:flex-start;justify-content:space-between;gap:8px}
.pm-stat-label{display:block;min-width:0;color:var(--pm-muted);font-size:.78rem;font-weight:600;line-height:1.4}
.pm-stat-value{display:block;margin-top:8px;color:#403b36;font-size:1.6rem;font-weight:750;line-height:1.16;letter-spacing:-.5px}
.pm-stat-icon{display:grid;place-items:center;flex:0 0 33px;width:33px;height:33px;border-radius:9px;background:#f6efe9;color:#ae7950;font-size:1.04rem;font-weight:700}
.pm-stat.available .pm-stat-icon{background:#eaf7ef;color:#488c62}
.pm-stat.stock .pm-stat-icon{background:#fff4e5;color:#b88844}
.pm-stat.removed .pm-stat-icon{background:#fff0ee;color:#b9635b}
.pm-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px 16px;margin-bottom:16px;flex-wrap:wrap;min-width:0}
.pm-tabs{display:flex;align-items:center;gap:3px;flex:0 1 auto;min-width:0;max-width:100%;padding:3px;border:1px solid var(--pm-line);border-radius:10px;background:#fff}
.pm-tab{appearance:none;display:inline-flex;align-items:center;justify-content:center;gap:6px;min-width:0;min-height:34px;padding:6px 12px;border:1px solid transparent;border-radius:7px;background:transparent;color:#77716b;font:inherit;font-size:.76rem;font-weight:650;line-height:1.2;white-space:nowrap;text-align:center;cursor:pointer;transition:background-color .15s,border-color .15s,color .15s}
.pm-tab:hover{background:#f9f3ec;color:#a66a39}
.pm-tab:focus-visible{outline:2px solid #e2a46e;outline-offset:1px}
.pm-tab.active{background:#fff2e5;border-color:#f3d9c1;color:#b47037}
.pm-tab-name{min-width:0}
.pm-tab-count{display:inline-grid;place-items:center;min-width:19px;min-height:17px;padding:0 4px;border-radius:5px;background:#f3efeb;color:#85807c;font-size:.66rem;font-weight:750;line-height:1.2}
.pm-tab.active .pm-tab-count{background:#fbe3cc;color:#a96733}
.pm-tools{display:flex;align-items:center;justify-content:flex-end;gap:9px;flex:1 1 245px;min-width:0}
.pm-search{width:100%;max-width:335px;min-width:0;min-height:37px;padding:8px 11px;border:1px solid var(--pm-line);border-radius:9px;background:#fff;color:var(--pm-text);font:inherit;font-size:.8rem;outline:none}
.pm-search:focus{border-color:#e5ac76;box-shadow:0 0 0 3px rgba(233,153,81,.11)}
.pm-visible{flex:0 0 auto;font-size:.71rem;color:var(--pm-muted);white-space:nowrap}
.pm-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px}
.product-card{background:#fff;border:1px solid var(--pm-line);border-radius:14px;box-shadow:var(--pm-shadow);overflow:hidden;min-width:0}
.card-open{display:flex;flex-direction:column;width:100%;height:100%;margin:0;padding:0;background:transparent;border:0;text-align:left;color:inherit;font:inherit;cursor:pointer}
.card-open:hover .product-photo img{transform:scale(1.025)}
.card-open:focus-visible{outline:3px solid #e9ae78;outline-offset:-3px}
.product-photo{width:100%;aspect-ratio:1.42;position:relative;background:#f4f1ed;overflow:hidden}
.product-photo img{display:block;width:100%;height:100%;object-fit:cover;transition:transform .2s ease}
.photo-empty{display:flex;flex-direction:column;gap:3px;align-items:center;justify-content:center;position:absolute;inset:0;color:#a9a199;font-size:2rem}
.photo-empty small{font-size:.7rem}
.product-state{position:absolute;left:9px;top:9px;padding:4px 8px;border-radius:6px;background:rgba(255,255,255,.94);font-size:.65rem;font-weight:750;white-space:nowrap;color:#635e58}
.state-live{color:#33805b}.state-out_of_stock{color:#9a7641}.state-removed{color:#af4f51}
.product-copy{display:flex;flex:1;flex-direction:column;gap:3px;padding:12px 12px 11px;min-width:0}
.product-copy h3{margin:0;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;font-size:.87rem;line-height:1.35;font-weight:700;color:#4b4540}
.product-copy p{margin:2px 0 0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--pm-muted);font-size:.75rem}
.product-bottom{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;margin-top:auto;padding-top:10px}
.product-bottom strong{font-size:.88rem;color:#615044}
.product-bottom>span{font-size:.68rem;color:#bb7842}
.pm-empty{padding:32px 14px;border:1px dashed #ded6cd;border-radius:13px;text-align:center;color:var(--pm-muted);background:#fff;font-size:.84rem}
.pm-hidden,[hidden]{display:none!important}
.pm-overlay{position:fixed;inset:0;z-index:20000;display:none;justify-content:center;align-items:center;padding:16px;background:rgba(29,23,18,.44)}
.pm-overlay.open{display:flex}
.pm-dialog{width:min(540px,100%);max-height:calc(100dvh - 32px);display:flex;flex-direction:column;overflow:hidden;border:1px solid #eadfd3;border-radius:17px;background:#fff;box-shadow:0 25px 70px rgba(40,27,15,.17)}
.pm-dialog-head{padding:15px 17px 12px;display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid var(--pm-line)}
.pm-dialog h2{margin:0;font-size:1.05rem;line-height:1.3;color:#4b4038}
.pm-x{width:30px;height:30px;flex-shrink:0;border:1px solid var(--pm-line);border-radius:9px;background:#fff;color:#8d8277;font:inherit;font-size:1.12rem;cursor:pointer}
.pm-dialog-body{overflow-y:auto;padding:16px 17px;min-height:0}
.pm-detail-image{display:block;width:100%;height:205px;object-fit:contain;border-radius:10px;background:#f8f5f1;margin-bottom:12px}
.pm-image-missing{display:grid;place-items:center;height:130px;border-radius:10px;background:#f8f5f1;margin-bottom:12px;color:var(--pm-muted)}
.pm-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px 12px;margin:12px 0}
.pm-field{min-width:0;padding:9px 10px;border:1px solid #f0ece6;border-radius:9px;background:#fcfbf9}
.pm-field span{display:block;margin-bottom:2px;color:#94877b;font-size:.7rem}
.pm-field strong{font-weight:650;font-size:.82rem;overflow-wrap:anywhere}
.pm-description{margin:5px 0 0;white-space:pre-wrap;overflow-wrap:anywhere;color:#685f55;font-size:.82rem;line-height:1.55}
.pm-reason{padding:10px 11px;margin-top:12px;border:1px solid #f0d8d6;border-radius:10px;background:#fff8f7;font-size:.8rem;color:#875654;white-space:pre-wrap;overflow-wrap:anywhere}
.pm-reason.pm-seller-archived{border-color:#e7e2db;background:#faf9f7;color:#776d64}
.pm-dialog-foot{padding:12px 17px;display:flex;justify-content:flex-end;gap:9px;flex-wrap:wrap;border-top:1px solid var(--pm-line);background:#fdfcfb}
.pm-btn{padding:9px 13px;border:1px solid #e4dcd2;border-radius:9px;background:#fff;color:#62584e;font:inherit;font-size:.81rem;font-weight:650;cursor:pointer}
.pm-btn:hover{background:#faf7f4}
.pm-btn.remove{background:#fff1f0;color:#ad5551;border-color:#efd2d1}
.pm-btn.confirm{background:#d87469;color:#fff;border-color:#d87469}
.pm-btn:disabled{opacity:.6;cursor:wait}
.pm-help{font-size:.79rem;color:#76675b;line-height:1.55;margin:0 0 13px}
.pm-input-label{display:block;margin:0 0 5px;font-size:.8rem;font-weight:700;color:#544941}
.pm-reason-input{width:100%;min-height:110px;border:1px solid #e5dbd1;border-radius:9px;padding:10px 11px;resize:vertical;color:var(--pm-text);font:inherit;font-size:.83rem;outline:none}
.pm-reason-input:focus{border-color:#e6ac77;box-shadow:0 0 0 3px #f9e9da}
@media(max-width:1180px){.pm-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:960px){.pm-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:1050px){.pm-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:840px){.content{padding:86px 16px 35px}.pm-toolbar{align-items:stretch}.pm-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:640px){.pm-stats{gap:9px;margin-bottom:15px}.pm-stat{min-height:94px;padding:12px}.pm-stat-icon{width:29px;height:29px;flex-basis:29px;font-size:.92rem}.pm-stat-label{font-size:.72rem}.pm-stat-value{font-size:1.4rem}.pm-toolbar{gap:9px;margin-bottom:13px}.pm-tabs{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:2px;width:100%;flex:1 1 100%;padding:3px}.pm-tab{width:100%;min-height:36px;padding:5px 2px;gap:3px;font-size:clamp(.65rem,2.4vw,.72rem);line-height:1.14;white-space:normal}.pm-tab-count{display:none}.pm-tools{width:100%;flex:1 1 100%;justify-content:space-between;gap:6px}.pm-search{max-width:none;flex:1 1 100%;font-size:.79rem}.pm-visible{display:none}.pm-grid{gap:10px}.product-copy{padding:10px}.product-photo{aspect-ratio:1.13}.pm-detail-grid{gap:7px}.pm-field{padding:8px}.pm-overlay{padding:9px}.pm-dialog{max-height:calc(100dvh - 18px)}}
@media(max-width:350px){.pm-stat{padding:10px}.pm-stat-icon{width:24px;height:24px;flex-basis:24px;font-size:.81rem}.pm-stat-label{font-size:.67rem}.pm-detail-grid{grid-template-columns:1fr}.pm-grid{grid-template-columns:1fr}.pm-tab{font-size:.65rem;padding:5px 1px}}
</style>
</head>
<body>
<div class="page">
<?php include __DIR__ . '/admin_nav.php'; ?>
<main class="content">
<header class="pm-header"><h1>Product Listing</h1><p>Monitor published products. Remove listings that violate marketplace guidelines.</p></header>
<?php if ($flash): ?><div class="pm-note <?= pmEscape($flash['type'] ?? '') ?>" role="status"><?= pmEscape($flash['message'] ?? '') ?></div><?php endif; ?>
<?php if ($errorMessage): ?><div class="pm-note error" role="alert"><?= pmEscape($errorMessage) ?></div><?php endif; ?>
<section class="pm-stats" aria-label="Product summary">
<article class="pm-stat total"><div class="pm-stat-top"><span class="pm-stat-label">Total Products</span><span class="pm-stat-icon" aria-hidden="true">▣</span></div><strong class="pm-stat-value"><?= number_format($counts['total']) ?></strong></article>
<article class="pm-stat available"><div class="pm-stat-top"><span class="pm-stat-label">Available</span><span class="pm-stat-icon" aria-hidden="true">✓</span></div><strong class="pm-stat-value"><?= number_format($counts['live']) ?></strong></article>
<article class="pm-stat stock"><div class="pm-stat-top"><span class="pm-stat-label">Out of Stock</span><span class="pm-stat-icon" aria-hidden="true">◷</span></div><strong class="pm-stat-value"><?= number_format($counts['out_of_stock']) ?></strong></article>
<article class="pm-stat removed"><div class="pm-stat-top"><span class="pm-stat-label">Removed</span><span class="pm-stat-icon" aria-hidden="true">⊘</span></div><strong class="pm-stat-value"><?= number_format($counts['removed']) ?></strong></article>
</section>
<div class="pm-toolbar">
<div class="pm-tabs" role="group" aria-label="Product filters">
<button class="pm-tab active" type="button" data-filter="all" aria-pressed="true"><span class="pm-tab-name">All</span><span class="pm-tab-count"><?= number_format($counts['total']) ?></span></button>
<button class="pm-tab" type="button" data-filter="live" aria-pressed="false"><span class="pm-tab-name">Available</span><span class="pm-tab-count"><?= number_format($counts['live']) ?></span></button>
<button class="pm-tab" type="button" data-filter="out_of_stock" aria-pressed="false"><span class="pm-tab-name">Out of Stock</span><span class="pm-tab-count"><?= number_format($counts['out_of_stock']) ?></span></button>
<button class="pm-tab" type="button" data-filter="removed" aria-pressed="false" title="Seller-archived and Admin-removed products"><span class="pm-tab-name">Removed</span><span class="pm-tab-count"><?= number_format($counts['removed']) ?></span></button>
</div>
<div class="pm-tools"><input class="pm-search" type="search" id="pmSearch" autocomplete="off" aria-label="Search products" placeholder="Search product, seller, category, or ID"><span class="pm-visible" id="pmVisible"></span></div>
</div>
<section class="pm-grid" id="pmGrid" aria-label="Seller products">
<?php foreach ($products as $product): ?><?php pmProductCard($product); ?><?php endforeach; ?>
</section>
<div class="pm-empty" id="pmEmpty" <?= $products ? 'hidden' : '' ?>>No products match your filter.</div>
</main>
</div>
<div class="pm-overlay" id="pmDetails" aria-hidden="true"><section class="pm-dialog" role="dialog" aria-modal="true" aria-labelledby="pmDetailsTitle">
<div class="pm-dialog-head"><h2 id="pmDetailsTitle">Product Details</h2><button type="button" class="pm-x" data-close="details" aria-label="Close">×</button></div>
<div class="pm-dialog-body"><img class="pm-detail-image" id="pmDetailImage" alt="Seller product" hidden><div class="pm-image-missing" id="pmImageMissing">No product image</div>
<div class="pm-detail-grid">
<div class="pm-field"><span>Product ID</span><strong id="pmId"></strong></div>
<div class="pm-field"><span>Seller</span><strong id="pmSeller"></strong></div>
<div class="pm-field"><span>Category</span><strong id="pmCategory"></strong></div>
<div class="pm-field"><span>Price</span><strong id="pmPrice"></strong></div>
<div class="pm-field"><span>Stock</span><strong id="pmStock"></strong></div>
<div class="pm-field"><span>Reserved</span><strong id="pmReserved"></strong></div>
<div class="pm-field"><span>Product Status</span><strong id="pmStatus"></strong></div>
<div class="pm-field"><span>Published</span><strong id="pmDate"></strong></div>
</div><strong class="pm-input-label">Description</strong><p class="pm-description" id="pmDescription"></p>
<div id="pmRemovedInfo" hidden><div class="pm-reason"><strong>Admin removal reason</strong><div id="pmReason"></div></div><p class="pm-help" id="pmRemovedDate"></p></div>
<div class="pm-reason pm-seller-archived" id="pmSellerArchivedInfo" hidden>This product was archived by the seller. It is not available in the marketplace.</div>
</div><div class="pm-dialog-foot"><button class="pm-btn" type="button" data-close="details">Close</button><button class="pm-btn remove" type="button" id="pmRemoveButton">Remove listing</button></div>
</section></div>
<div class="pm-overlay" id="pmRemove" aria-hidden="true"><section class="pm-dialog" role="dialog" aria-modal="true" aria-labelledby="pmRemoveTitle">
<div class="pm-dialog-head"><h2 id="pmRemoveTitle">Remove Listing</h2><button type="button" class="pm-x" data-close="remove" aria-label="Cancel">×</button></div>
<form method="post" action="product_listing.php" id="pmRemoveForm"><div class="pm-dialog-body">
<p class="pm-help">The listing will be changed to <strong>Archived</strong>. A warning and the reason will be recorded for the Seller interface. This action does not delete product data.</p>
<input type="hidden" name="csrf_token" value="<?= pmEscape($csrfToken) ?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="product_id" id="pmRemoveId">
<label class="pm-input-label" for="pmRemovalReason">Reason for removal *</label><textarea class="pm-reason-input" id="pmRemovalReason" name="removal_reason" maxlength="500" required placeholder="Describe the marketplace guideline violation..."></textarea>
</div><div class="pm-dialog-foot"><button class="pm-btn" type="button" data-close="remove">Cancel</button><button class="pm-btn confirm" type="submit" id="pmConfirmRemove">Archive listing</button></div></form>
</section></div>
<script>
(() => {
    const cards = [...document.querySelectorAll('[data-product]')];
    const grid = document.getElementById('pmGrid');
    const search = document.getElementById('pmSearch');
    const tabs = [...document.querySelectorAll('[data-filter]')];
    const details = document.getElementById('pmDetails');
    const removal = document.getElementById('pmRemove');
    const reason = document.getElementById('pmRemovalReason');
    let currentFilter = 'all';
    let currentButton = null;
    function filterCards() {
        const q = search.value.trim().toLowerCase();
        let visible = 0;
        cards.forEach(card => {
            const matches = (currentFilter === 'all' || card.dataset.state === currentFilter) && card.dataset.search.includes(q);
            card.hidden = !matches;
            if (matches) visible++;
        });
        document.getElementById('pmVisible').textContent = visible + ' of <?= (int)$counts['total'] ?> products shown';
        document.getElementById('pmEmpty').hidden = visible !== 0;
    }
    tabs.forEach(tab => tab.addEventListener('click', () => {
        currentFilter = tab.dataset.filter;
        tabs.forEach(item => {
            const active = item === tab;
            item.classList.toggle('active', active);
            item.setAttribute('aria-pressed', String(active));
        });
        filterCards();
    }));
    search.addEventListener('input', filterCards);
    filterCards();
    function loadImage(img, placeholder, sources) {
        let at = 0;
        const next = () => {
            if (at >= sources.length) {
                img.hidden = true;
                placeholder.hidden = false;
                img.removeAttribute('src');
                return;
            }
            img.hidden = false;
            placeholder.hidden = true;
            img.src = sources[at++];
        };
        img.onerror = next;
        next();
    }
    document.querySelectorAll('.product-photo img').forEach(img => {
        let sources = [];
        try { sources = JSON.parse(img.dataset.fallbacks || '[]'); } catch (error) {}
        loadImage(img, img.parentElement.querySelector('.photo-empty'), sources);
    });
    function show(modal) {
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('pm-modal-open');
    }
    function hide(modal) {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        if (!details.classList.contains('open') && !removal.classList.contains('open')) {
            document.body.classList.remove('pm-modal-open');
        }
    }
    grid.addEventListener('click', event => {
        const button = event.target.closest('[data-detail]');
        if (!button) return;
        currentButton = button;
        const d = button.dataset;
        const values = {
            pmId: '#' + d.id,
            pmSeller: d.seller,
            pmCategory: d.category || 'Not specified',
            pmPrice: d.price,
            pmStock: d.stock,
            pmReserved: d.reserved,
            pmStatus: d.status,
            pmDate: d.date
        };
        Object.entries(values).forEach(([key, value]) => {
            document.getElementById(key).textContent = value || '—';
        });
        document.getElementById('pmDetailsTitle').textContent = d.name || 'Product Details';
        document.getElementById('pmDescription').textContent = d.description || 'No description provided.';
        document.getElementById('pmRemovedInfo').hidden = d.adminRemoved !== '1';
        document.getElementById('pmSellerArchivedInfo').hidden = !(d.state === 'removed' && d.adminRemoved !== '1');
        document.getElementById('pmReason').textContent = d.reason || 'Reason not available.';
        document.getElementById('pmRemovedDate').textContent = 'Removed: ' + (d.removed || '—');
        document.getElementById('pmRemoveButton').hidden = !['live', 'out_of_stock'].includes(d.state);
        let images = [];
        try { images = JSON.parse(d.photos || '[]'); } catch (error) {}
        loadImage(document.getElementById('pmDetailImage'), document.getElementById('pmImageMissing'), images);
        show(details);
        details.querySelector('.pm-x').focus();
    });
    function closeDetails() {
        hide(details);
        currentButton?.focus();
    }
    function closeRemoval() {
        hide(removal);
        if (currentButton) {
            show(details);
            details.querySelector('.pm-x').focus();
        }
    }
    details.querySelectorAll('[data-close="details"]').forEach(b => b.addEventListener('click', closeDetails));
    removal.querySelectorAll('[data-close="remove"]').forEach(b => b.addEventListener('click', closeRemoval));
    document.getElementById('pmRemoveButton').addEventListener('click', () => {
        if (!currentButton) return;
        document.getElementById('pmRemoveId').value = currentButton.dataset.id;
        document.getElementById('pmRemoveTitle').textContent = 'Remove: ' + currentButton.dataset.name;
        reason.value = '';
        reason.setCustomValidity('');
        hide(details);
        show(removal);
        reason.focus();
    });
    [details, removal].forEach(modal => modal.addEventListener('click', event => {
        if (event.target !== modal) return;
        modal === removal ? closeRemoval() : closeDetails();
    }));
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        if (removal.classList.contains('open')) closeRemoval();
        else if (details.classList.contains('open')) closeDetails();
    });
    reason.addEventListener('input', () => reason.setCustomValidity(''));
    document.getElementById('pmRemoveForm').addEventListener('submit', event => {
        if (!reason.value.trim()) {
            event.preventDefault();
            reason.setCustomValidity('Please enter a reason for removal.');
            reason.reportValidity();
            return;
        }
        document.getElementById('pmConfirmRemove').disabled = true;
    });
})();
</script>
</body>
</html>
