<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../config/database.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Manila');
$activePage = 'wallet';
header('Cache-Control: private, no-store, max-age=0');
function walletEsc($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function walletMoney($value): string {
    return '₱' . number_format((float)$value, 2);
}
function walletDate($value): string {
    if (!$value) return 'Not recorded';
    $time = strtotime((string)$value);
    return $time === false ? 'Not recorded' : date('M j, Y · g:i A', $time);
}
function walletLabel(string $category): string {
    return [
        'platform_fee' => 'Platform Fee',
        'seller_share' => 'Seller Share',
        'cash_fee' => 'Cash Platform Fee',
        'refund' => 'Refund',
        'test_fund' => 'Test Funds',
        'adjustment' => 'Adjustment'
    ][$category] ?? ucwords(str_replace(['_', '-'], ' ', $category));
}
function walletValue($value): string {
    return $value === null || trim((string)$value) === '' ? 'Not recorded' : (string)$value;
}
function walletMoneyOptional($value): string {
    return $value === null || $value === '' ? 'Not recorded' : walletMoney($value);
}
function walletAdmin(mysqli $conn): ?array {
    foreach (['user_id', 'admin_id', 'logged_id', 'id'] as $key) {
        $candidate = $_SESSION[$key] ?? null;
        if (!is_scalar($candidate) || !ctype_digit((string)$candidate) || (int)$candidate < 1) continue;
        $id = (int)$candidate;
        $stmt = $conn->prepare('SELECT firstname, lastname, AdminOrNot, account_status FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->bind_result($first, $last, $isAdmin, $status);
        $found = $stmt->fetch();
        $stmt->close();
        if ($found && (int)$isAdmin === 1 && !in_array(strtolower(trim((string)$status)), ['disabled', 'inactive', 'suspended', 'blocked', 'banned'], true)) {
            return ['id' => $id, 'name' => trim((string)$first . ' ' . (string)$last) ?: 'Admin'];
        }
    }
    return null;
}
$isDataRequest = isset($_GET['wallet_data']);
try {
    $currentAdmin = walletAdmin($conn);
} catch (Throwable $error) {
    error_log('LokalCraft wallet admin access: ' . $error->getMessage());
    if ($isDataRequest) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Admin verification is currently unavailable.']);
        exit;
    }
    http_response_code(503);
    exit('Admin verification is currently unavailable.');
}
if ($currentAdmin === null) {
    if ($isDataRequest) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Admin session expired or access denied.']);
        exit;
    }
    header('Location: ../login.php');
    exit;
}
$adminName = $currentAdmin['name'];
$_SESSION['admin_name'] = $adminName;
function walletLoad(mysqli $conn): array {
    $balance = $conn->query("SELECT COALESCE(SUM(balance), 0) AS total FROM wallet_accounts WHERE wallet_type = 'admin'")->fetch_assoc();
    $fees = $conn->query("SELECT COALESCE(SUM(wt.amount), 0) AS total_fees, COUNT(*) AS fee_count FROM wallet_transactions wt INNER JOIN wallet_accounts wa ON wa.wallet_id = wt.wallet_id WHERE wa.wallet_type = 'admin' AND wt.transaction_category = 'platform_fee' AND wt.direction = 'credit'")->fetch_assoc();
    $gross = $conn->query("SELECT COALESCE(SUM(gross_amount), 0) AS total FROM platform_commissions WHERE collection_method = 'split_payment' AND commission_status = 'earned'")->fetch_assoc();
    // Scalar lookups guarantee one row per wallet transaction even with multiple business/commission records.
    $query = "SELECT wt.wallet_transaction_id, wt.wallet_id, wt.order_id, wt.payment_id,
        wt.transaction_category, wt.direction, wt.amount, wt.balance_after, wt.reference_key,
        wt.description, wt.created_at, o.total_amount AS order_amount,
        o.order_status, o.ordered_at, o.seller_id,
        COALESCE((SELECT NULLIF(TRIM(b.businessname), '') FROM businessinfo b
            WHERE b.user_id = o.seller_id ORDER BY b.business_id DESC LIMIT 1),
            NULLIF(TRIM(CONCAT_WS(' ', seller.firstname, seller.lastname)), ''), 'Not recorded') AS seller_name,
        CONCAT_WS(' ', NULLIF(TRIM(buyer.firstname), ''), NULLIF(TRIM(buyer.lastname), '')) AS buyer_name,
        pc.commission_id, pc.gross_amount, pc.commission_rate, pc.commission_amount,
        pc.seller_net_amount, pc.collection_method, pc.commission_status, pc.paymongo_reference,
        COALESCE(NULLIF(TRIM(pay.paymongo_payment_id), ''), NULLIF(TRIM(pc.paymongo_payment_id), '')) AS paymongo_payment_reference,
        COALESCE(NULLIF(TRIM(pay.paymongo_payment_intent_id), ''), NULLIF(TRIM(qrs.payment_intent_id), '')) AS paymongo_intent_reference,
        pay.payment_method, pay.payment_provider, pay.payment_status, pay.payment_reference, pay.paid_at
        FROM wallet_transactions wt
        INNER JOIN wallet_accounts wa ON wa.wallet_id = wt.wallet_id
        LEFT JOIN orders o ON o.order_id = wt.order_id
        LEFT JOIN users seller ON seller.id = o.seller_id
        LEFT JOIN users buyer ON buyer.id = o.buyer_id
        LEFT JOIN platform_commissions pc ON pc.commission_id = (
            SELECT MAX(c2.commission_id) FROM platform_commissions c2 WHERE c2.order_id = wt.order_id
        )
        LEFT JOIN payments pay ON pay.payment_id = wt.payment_id AND pay.order_id = wt.order_id
        LEFT JOIN paymongo_qr_sessions qrs ON qrs.order_id = wt.order_id AND qrs.payment_id = pay.payment_id
        WHERE wa.wallet_type = 'admin'
        ORDER BY wt.created_at DESC, wt.wallet_transaction_id DESC LIMIT 100";
    $rows = $conn->query($query);
    $transactions = [];
    while ($row = $rows->fetch_assoc()) $transactions[] = $row;
    $rows->free();
    return [
        'platform_balance' => (float)($balance['total'] ?? 0),
        'total_fees' => (float)($fees['total_fees'] ?? 0),
        'gross_qr_sales' => (float)($gross['total'] ?? 0),
        'fee_transactions' => (int)($fees['fee_count'] ?? 0),
        'transactions' => $transactions,
        'updated_at' => date('g:i A')
    ];
}
function walletDetails(array $t): array {
    $direction = strtolower(trim((string)($t['direction'] ?? '')));
    $paymongoPaymentReference = trim((string)($t['paymongo_payment_reference'] ?? ''));
    $paymongoIntentReference = trim((string)($t['paymongo_intent_reference'] ?? ''));
    $paymentMethod = strtolower(trim((string)($t['payment_method'] ?? '')));
    $provider = strtolower(trim((string)($t['payment_provider'] ?? '')));
    $hasPayMongo = $paymongoPaymentReference !== '' || $paymongoIntentReference !== ''
        || ($provider === 'paymongo' && in_array($paymentMethod, ['qr ph', 'qrph', 'paymongo'], true));
    return [
        'transaction_id' => '#' . (int)$t['wallet_transaction_id'],
        'wallet_id' => '#' . (int)$t['wallet_id'],
        'category' => walletLabel((string)$t['transaction_category']),
        'direction' => ucfirst($direction ?: 'Unknown'),
        'amount' => ($direction === 'debit' ? '−' : '+') . walletMoney($t['amount']),
        'balance_after' => walletMoneyOptional($t['balance_after']),
        'date' => walletDate($t['created_at']),
        'reference' => walletValue($t['reference_key']),
        'description' => walletValue($t['description']),
        'order_id' => empty($t['order_id']) ? 'Not linked' : '#' . (int)$t['order_id'],
        'order_total' => walletMoneyOptional($t['order_amount']),
        'order_status' => walletValue($t['order_status']),
        'order_date' => walletDate($t['ordered_at']),
        'seller' => empty($t['order_id']) ? 'Not linked' : walletValue($t['seller_name']),
        'buyer' => empty($t['order_id']) ? 'Not linked' : walletValue($t['buyer_name']),
        'gross_amount' => walletMoneyOptional($t['gross_amount']),
        'commission_rate' => $t['commission_rate'] === null ? 'Not recorded' : rtrim(rtrim(number_format((float)$t['commission_rate'], 2), '0'), '.') . '%',
        'commission_amount' => walletMoneyOptional($t['commission_amount']),
        'seller_net' => walletMoneyOptional($t['seller_net_amount']),
        'collection_method' => walletValue($t['collection_method']),
        'commission_status' => walletValue($t['commission_status']),
        'commission_id' => empty($t['commission_id']) ? 'Not linked' : '#' . (int)$t['commission_id'],
        'commission_reference' => walletValue($t['paymongo_reference']),
        'payment_id' => empty($t['payment_id']) ? 'Not linked' : '#' . (int)$t['payment_id'],
        'payment_method' => walletValue($t['payment_method']),
        'payment_provider' => walletValue($t['payment_provider']),
        'payment_status' => walletValue($t['payment_status']),
        'payment_reference' => walletValue($t['payment_reference']),
        'paymongo_payment_reference' => walletValue($paymongoPaymentReference),
        'paymongo_intent_reference' => walletValue($paymongoIntentReference),
        'has_paymongo' => $hasPayMongo,
        'paid_at' => walletDate($t['paid_at']),
        'has_order' => !empty($t['order_id']),
        'has_commission' => !empty($t['commission_id']),
        'has_payment' => !empty($t['payment_id'])
    ];
}
function walletRows(array $transactions): string {
    ob_start();
    foreach ($transactions as $transaction):
        $direction = strtolower(trim((string)$transaction['direction']));
        $directionClass = $direction === 'debit' ? 'debit' : 'credit';
        $sign = $direction === 'debit' ? '−' : '+';
        $category = walletLabel((string)$transaction['transaction_category']);
        $orderId = (int)($transaction['order_id'] ?? 0);
        $details = walletDetails($transaction);
        $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $searchText = strtolower(implode(' ', [$transaction['wallet_transaction_id'], $orderId, $category, $transaction['seller_name'] ?? '', $transaction['reference_key'] ?? '', $transaction['description'] ?? '', $transaction['payment_reference'] ?? '', $transaction['paymongo_payment_reference'] ?? '', $transaction['paymongo_intent_reference'] ?? '']));
    ?>
    <button type="button" class="wallet-row" data-wallet-row data-direction="<?= walletEsc($directionClass) ?>" data-search="<?= walletEsc($searchText) ?>" data-details="<?= walletEsc($json ?: '{}') ?>" aria-label="View <?= walletEsc($category) ?> transaction #<?= (int)$transaction['wallet_transaction_id'] ?>">
        <span class="wallet-row-main">
            <span class="wallet-entry-icon <?= walletEsc($directionClass) ?>" aria-hidden="true"><?= $directionClass === 'debit' ? '↗' : '↙' ?></span>
            <span class="wallet-row-identity"><strong><?= walletEsc($orderId > 0 ? 'Order #' . $orderId : 'Transaction #' . (int)$transaction['wallet_transaction_id']) ?></strong><small><?= walletEsc($category) ?> · <?= walletEsc($orderId > 0 ? walletValue($transaction['seller_name']) : 'Platform wallet') ?></small></span>
        </span>
        <span class="wallet-row-type"><span class="wallet-category"><?= walletEsc($category) ?></span></span>
        <span class="wallet-row-date"><?= walletEsc(walletDate($transaction['created_at'])) ?></span>
        <span class="wallet-row-end"><strong class="wallet-row-amount <?= walletEsc($directionClass) ?>"><?= $sign . walletEsc(walletMoney($transaction['amount'])) ?></strong><small>Balance: <?= walletEsc(walletMoneyOptional($transaction['balance_after'])) ?></small></span>
        <span class="wallet-row-chevron" aria-hidden="true">›</span>
    </button>
    <?php endforeach; ?>
    <div class="wallet-empty" id="walletEmpty" <?= $transactions ? 'hidden' : '' ?>><span class="wallet-empty-mark" aria-hidden="true">▤</span><strong><?= $transactions ? 'No matching transactions' : 'No wallet transactions yet' ?></strong><span><?= $transactions ? 'Try another search or transaction type.' : 'Transactions will appear here when recorded.' ?></span></div>
    <?php
    return ob_get_clean();
}
$data = ['platform_balance' => 0.0, 'total_fees' => 0.0, 'gross_qr_sales' => 0.0, 'fee_transactions' => 0, 'transactions' => [], 'updated_at' => '—'];
$errorMessage = '';
try {
    $data = walletLoad($conn);
} catch (Throwable $error) {
    error_log('LokalCraft platform wallet: ' . $error->getMessage());
    $errorMessage = 'Unable to load platform wallet data. Please try again later.';
}
$counts = ['all' => count($data['transactions']), 'credit' => 0, 'debit' => 0];
foreach ($data['transactions'] as $transaction) {
    $key = strtolower(trim((string)$transaction['direction']));
    if (isset($counts[$key])) $counts[$key]++;
}
if ($isDataRequest) {
    header('Content-Type: application/json; charset=utf-8');
    if ($errorMessage !== '') {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => $errorMessage]);
        exit;
    }
    echo json_encode([
        'success' => true,
        'summary' => ['balance' => walletMoney($data['platform_balance']), 'fees' => walletMoney($data['total_fees']), 'gross' => walletMoney($data['gross_qr_sales']), 'count' => number_format($data['fee_transactions'])],
        'counts' => $counts,
        'rows_html' => walletRows($data['transactions']),
        'updated_at' => $data['updated_at']
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LokalCraft | Platform Wallet</title>
<style>
:root{--w-bg:#f7f6f4;--w-card:#fff;--w-text:#333333;--w-muted:#81838a;--w-line:#eae6e2;--w-accent:#e9944e;--w-shadow:0 6px 20px rgba(43,33,24,.035)}
*{box-sizing:border-box}
html,body{min-width:0;max-width:100%}
body{margin:0;background:var(--w-bg);color:var(--w-text);font:14px/1.5 "Segoe UI",Arial,sans-serif}
body.wallet-dialog-open{overflow:hidden}
.page{display:flex;min-height:100vh;min-width:0;background:var(--w-bg)}
.page>.content{flex:1;min-width:0;max-width:100%;padding:31px 30px 44px}
.wallet-header{display:flex;align-items:flex-start;justify-content:space-between;gap:24px;margin-bottom:22px}
.wallet-header h1{margin:0 0 6px;font-size:clamp(1.5rem,2.1vw,1.95rem);font-weight:760;letter-spacing:-.6px;line-height:1.24}
.wallet-header p{margin:0;color:var(--w-muted);font-size:.85rem}
.wallet-header-note{max-width:295px;color:#93908c;font-size:.72rem;line-height:1.55;text-align:right}
.wallet-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px;margin-bottom:20px}
.wallet-stat{display:flex;justify-content:space-between;align-items:center;gap:12px;min-width:0;min-height:107px;padding:17px;border:1px solid var(--w-line);border-radius:15px;background:#fff;box-shadow:var(--w-shadow)}
.wallet-stat-name{display:block;color:#818087;font-size:.75rem;font-weight:650}.wallet-stat-value{display:block;margin-top:9px;font-size:clamp(1.14rem,1.7vw,1.68rem);line-height:1.2;font-weight:760;letter-spacing:-.4px;overflow-wrap:anywhere}
.wallet-stat-icon{flex:0 0 37px;width:37px;height:37px;display:grid;place-items:center;border-radius:11px;font-size:1.12rem;font-weight:750;background:#fff0e3;color:#c78248}
.wallet-stat:nth-child(2) .wallet-stat-icon{background:#e7f5ed;color:#439366}.wallet-stat:nth-child(3) .wallet-stat-icon{background:#e9f0ff;color:#5b80c6}.wallet-stat:nth-child(4) .wallet-stat-icon{background:#f3ecfb;color:#8f70b9}
.wallet-panel{padding:19px 20px 15px;border:1px solid var(--w-line);border-radius:16px;background:#fff;box-shadow:var(--w-shadow);min-width:0}
.wallet-panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}
.wallet-panel-title h2{margin:0 0 3px;font-size:1.03rem;letter-spacing:-.2px}.wallet-panel-title p{margin:0;color:var(--w-muted);font-size:.75rem}
.wallet-sync{display:flex;align-items:center;gap:8px;color:#92918f;font-size:.7rem}.wallet-sync-dot{width:7px;height:7px;border-radius:50%;background:#62b585;box-shadow:0 0 0 3px #e5f5e9}.wallet-sync.is-error .wallet-sync-dot{background:#db8174;box-shadow:0 0 0 3px #ffe8e3}
.wallet-controls{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:17px}
.wallet-filters{display:flex;align-items:center;gap:7px;min-width:0}
.wallet-filter{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:36px;padding:7px 12px;border:1px solid #e9e4de;border-radius:9px;background:#faf9f8;color:#7b7672;font:inherit;font-size:.77rem;font-weight:680;white-space:nowrap;cursor:pointer}
.wallet-filter:hover{background:#fff4e9}.wallet-filter.active{background:#f7e6d6;border-color:#efc8a8;color:#ae682f}.wallet-filter .wallet-count{padding:1px 6px;border-radius:6px;background:rgba(90,70,50,.07);font-size:.69rem}.wallet-filter.active .wallet-count{background:rgba(192,114,48,.10)}
.wallet-search{position:relative;flex:0 1 330px;min-width:0}.wallet-search svg{position:absolute;left:13px;top:50%;transform:translateY(-50%);width:17px;height:17px;color:#9c9b9a;pointer-events:none}.wallet-search input{width:100%;min-width:0;height:38px;padding:8px 11px 8px 38px;background:#faf9f7;border:1px solid #e8e3dc;border-radius:9px;color:#3e3d3b;font:inherit;font-size:.81rem;outline:0}.wallet-search input:focus{background:#fff;border-color:#e8a46b;box-shadow:0 0 0 3px #fff1e3}
.wallet-list-head{display:grid;grid-template-columns:minmax(0,2fr) minmax(100px,1fr) minmax(115px,1fr) minmax(150px,1.05fr) 14px;gap:12px;padding:10px 12px;color:#979490;font-size:.68rem;font-weight:740;text-transform:uppercase;letter-spacing:.45px;border-bottom:1px solid var(--w-line)}.wallet-list-head span:nth-child(4){text-align:right}
.wallet-row{appearance:none;display:grid;grid-template-columns:minmax(0,2fr) minmax(100px,1fr) minmax(115px,1fr) minmax(150px,1.05fr) 14px;gap:12px;align-items:center;width:100%;min-width:0;min-height:78px;padding:12px;border:0;border-bottom:1px solid #f0edeb;background:#fff;text-align:left;font:inherit;color:inherit;cursor:pointer;transition:background .15s}
.wallet-row:hover{background:#fff9f3}.wallet-row:focus-visible,.wallet-filter:focus-visible{outline:2px solid var(--w-accent);outline-offset:-2px}.wallet-row:last-of-type{border-bottom:0}
.wallet-row-main{display:flex;align-items:center;gap:11px;min-width:0}.wallet-entry-icon{display:grid;place-items:center;flex:0 0 35px;width:35px;height:35px;border-radius:11px;font-size:1.12rem;font-weight:730}.wallet-entry-icon.credit{background:#e7f6ec;color:#439369}.wallet-entry-icon.debit{background:#ffeded;color:#c96760}
.wallet-row-identity{display:flex;flex-direction:column;gap:3px;min-width:0}.wallet-row-identity strong{font-size:.83rem;color:#413f3c;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.wallet-row-identity small{color:#92918e;font-size:.7rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wallet-category{display:inline-flex;padding:6px 9px;background:#faf4ec;color:#a77443;font-size:.7rem;font-weight:700;border-radius:8px;line-height:1.2}
.wallet-row-date{color:#898a8c;font-size:.75rem;line-height:1.5}.wallet-row-end{text-align:right;min-width:0}.wallet-row-amount{font-size:.89rem;font-weight:770;overflow-wrap:anywhere}.wallet-row-amount.credit{color:#369567}.wallet-row-amount.debit{color:#c15f5b}.wallet-row-end small{display:block;color:#a0a0a0;font-size:.67rem;margin-top:3px;overflow-wrap:anywhere}.wallet-row-chevron{color:#b5afaa;font-size:1.5rem;line-height:1}
.wallet-empty{display:flex;flex-direction:column;align-items:center;gap:7px;padding:42px 15px;color:#979089;text-align:center;font-size:.79rem}.wallet-empty strong{color:#58514c;font-size:.87rem}.wallet-empty-mark{display:grid;place-items:center;width:43px;height:43px;border-radius:12px;background:#fbf0e5;color:#ba8559;font-size:1.5rem}
.wallet-panel-footer{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;margin-top:14px;color:#989594;font-size:.7rem}.wallet-panel-footer strong{color:#605d59}.wallet-error{padding:11px 13px;margin-bottom:15px;border:1px solid #f2cfca;background:#fff2f0;color:#ad5551;border-radius:10px;font-size:.8rem}
.wallet-modal{position:fixed;inset:0;display:none;z-index:2147483000;align-items:center;justify-content:center;padding:16px;background:rgba(27,23,19,.48);backdrop-filter:blur(3px)}.wallet-modal.open{display:flex}
.wallet-modal-card{display:flex;flex-direction:column;width:min(620px,100%);max-height:calc(100dvh - 32px);min-width:0;overflow:hidden;background:#fff;border:1px solid #eae2da;border-radius:18px;box-shadow:0 25px 75px rgba(29,20,13,.21)}
.wallet-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:15px;padding:19px 21px 15px;border-bottom:1px solid var(--w-line)}.wallet-modal-head small{display:block;color:#a59d96;font-weight:730;font-size:.68rem;letter-spacing:1px;text-transform:uppercase}.wallet-modal-head h2{margin:4px 0 0;font-size:1.2rem;letter-spacing:-.3px}.wallet-modal-close{flex:0 0 34px;width:34px;height:34px;border:0;background:#f7f5f3;color:#706a64;border-radius:9px;font-size:1.25rem;cursor:pointer}.wallet-modal-close:hover{background:#ece8e4}
.wallet-modal-body{min-height:0;overflow-y:auto;overscroll-behavior:contain;padding:17px 21px}
.wallet-hero{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:15px 17px;margin-bottom:17px;background:#faf6f1;border:1px solid #f0e5d9;border-radius:13px}.wallet-hero small{display:block;font-size:.72rem;color:#968a7d}.wallet-hero strong{display:block;margin-top:4px;font-size:1.47rem;line-height:1.2;color:#3c3835;overflow-wrap:anywhere}.wallet-hero strong.debit{color:#c15f5b}.wallet-hero strong.credit{color:#359063}.wallet-hero-tag{flex:0 0 auto;padding:6px 10px;border-radius:8px;background:#fff;color:#a16c3e;font-size:.73rem;font-weight:720}
.wallet-detail-section{margin-bottom:16px}.wallet-detail-section h3{display:flex;align-items:center;gap:8px;margin:0 0 8px;font-size:.81rem;font-weight:770;color:#5b534b}.wallet-detail-section h3::before{content:"";display:block;width:4px;height:13px;border-radius:3px;background:#e79c5c}
.wallet-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.wallet-field{padding:10px 11px;min-width:0;border:1px solid #f0ece7;border-radius:9px;background:#fcfbfa}.wallet-field span{display:block;color:#96918c;font-size:.69rem;margin-bottom:4px}.wallet-field strong{display:block;color:#45413e;font-size:.79rem;font-weight:680;line-height:1.45;white-space:pre-wrap;overflow-wrap:anywhere}
.wallet-description{padding:12px 13px;border-radius:9px;background:#fcfaf7;border:1px solid #eee7df;color:#68615a;font-size:.78rem;line-height:1.6;white-space:pre-wrap;overflow-wrap:anywhere}
.wallet-modal-footer{display:flex;justify-content:flex-end;align-items:center;padding:13px 21px;border-top:1px solid var(--w-line);background:#fdfcfb}.wallet-done{padding:10px 23px;border:0;border-radius:9px;background:#e9944e;color:#fff;font:inherit;font-size:.79rem;font-weight:760;cursor:pointer}
[hidden]{display:none!important}
@media(max-width:1320px){.wallet-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.wallet-list-head,.wallet-row{grid-template-columns:minmax(0,1.7fr) minmax(100px,.9fr) minmax(110px,1fr) minmax(140px,1fr) 14px}}
@media(max-width:1050px){.wallet-list-head{display:none}.wallet-row{grid-template-columns:minmax(0,1.8fr) minmax(100px,.95fr) minmax(140px,1fr) 14px}.wallet-row-type{display:none}}
@media(max-width:840px){.page>.content{padding:92px 18px 36px}.wallet-header{margin-bottom:18px}}
@media(max-width:620px){.page>.content{padding-left:14px;padding-right:14px}.wallet-header{flex-direction:column;gap:8px}.wallet-header-note{text-align:left;max-width:100%}.wallet-stats{gap:9px;margin-bottom:15px}.wallet-stat{padding:12px;min-height:97px}.wallet-stat-value{font-size:1.1rem}.wallet-stat-icon{flex-basis:31px;width:31px;height:31px;font-size:.96rem}.wallet-stat-name{font-size:.7rem}.wallet-panel{padding:15px 12px}.wallet-panel-head{align-items:flex-start}.wallet-sync{font-size:.67rem}.wallet-controls{flex-direction:column;align-items:stretch;gap:10px}.wallet-filters{display:grid;grid-template-columns:repeat(3,minmax(0,1fr))}.wallet-filter{min-width:0;padding:8px 4px;font-size:.72rem;gap:4px}.wallet-search{flex:none;width:100%}.wallet-row{position:relative;grid-template-columns:minmax(0,1fr) auto;gap:8px;min-height:105px;padding:13px 4px}.wallet-row-main{grid-column:1;grid-row:1}.wallet-row-type{display:none}.wallet-row-date{grid-column:1;grid-row:2;padding-left:46px;font-size:.69rem}.wallet-row-end{grid-column:2;grid-row:1 / span 2;align-self:center;max-width:128px}.wallet-row-amount{font-size:.85rem}.wallet-row-end small{font-size:.61rem}.wallet-row-chevron{display:none}.wallet-entry-icon{flex-basis:34px;width:34px;height:34px}.wallet-row-identity strong{font-size:.78rem}.wallet-row-identity small{font-size:.66rem}.wallet-modal{padding:9px}.wallet-modal-card{max-height:calc(100dvh - 18px);border-radius:14px}.wallet-modal-head,.wallet-modal-footer{padding:13px 14px}.wallet-modal-body{padding:13px 14px}.wallet-hero{padding:12px;gap:7px}.wallet-hero strong{font-size:1.22rem}.wallet-detail-grid{gap:7px}.wallet-field{padding:9px}.wallet-field strong{font-size:.76rem}}
@media(max-width:360px){.wallet-stat{gap:6px}.wallet-stat-icon{display:none}.wallet-stat-value{font-size:1.02rem}.wallet-modal-body{padding:11px}.wallet-detail-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="page">
<?php include __DIR__ . '/admin_nav.php'; ?>
<main class="content">
<header class="wallet-header"><div><h1>Platform Wallet</h1><p>View LokalCraft platform fees and wallet activity.</p></div><div class="wallet-header-note">Internal wallet records only. Balances do not confirm withdrawal or settlement to an external bank or e-wallet.</div></header>
<div class="wallet-error" id="walletError" role="status" <?= $errorMessage === '' ? 'hidden' : '' ?>><?= walletEsc($errorMessage) ?></div>
<section class="wallet-stats" aria-label="Platform wallet summary">
<article class="wallet-stat"><div><span class="wallet-stat-name">Platform Balance</span><strong class="wallet-stat-value" id="walletStatBalance"><?= walletEsc(walletMoney($data['platform_balance'])) ?></strong></div><span class="wallet-stat-icon" aria-hidden="true">₱</span></article>
<article class="wallet-stat"><div><span class="wallet-stat-name">Total Platform Fees</span><strong class="wallet-stat-value" id="walletStatFees"><?= walletEsc(walletMoney($data['total_fees'])) ?></strong></div><span class="wallet-stat-icon" aria-hidden="true">↗</span></article>
<article class="wallet-stat"><div><span class="wallet-stat-name">Gross QR Sales</span><strong class="wallet-stat-value" id="walletStatGross"><?= walletEsc(walletMoney($data['gross_qr_sales'])) ?></strong></div><span class="wallet-stat-icon" aria-hidden="true">▦</span></article>
<article class="wallet-stat"><div><span class="wallet-stat-name">Fee Transactions</span><strong class="wallet-stat-value" id="walletStatCount"><?= number_format($data['fee_transactions']) ?></strong></div><span class="wallet-stat-icon" aria-hidden="true">▤</span></article>
</section>
<section class="wallet-panel" aria-labelledby="walletHistoryTitle">
<div class="wallet-panel-head"><div class="wallet-panel-title"><h2 id="walletHistoryTitle">Recent Transactions</h2><p>Click a transaction to view the full ledger details.</p></div><span class="wallet-sync" id="walletSync"><span class="wallet-sync-dot" aria-hidden="true"></span><span>Updates every 30 seconds</span></span></div>
<div class="wallet-controls"><div class="wallet-filters" aria-label="Transaction filters"><button type="button" class="wallet-filter active" data-filter="all" aria-pressed="true">All <span class="wallet-count" data-count="all"><?= (int)$counts['all'] ?></span></button><button type="button" class="wallet-filter" data-filter="credit" aria-pressed="false">Credits <span class="wallet-count" data-count="credit"><?= (int)$counts['credit'] ?></span></button><button type="button" class="wallet-filter" data-filter="debit" aria-pressed="false">Debits <span class="wallet-count" data-count="debit"><?= (int)$counts['debit'] ?></span></button></div><label class="wallet-search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m16 16 5 5"/></svg><input type="search" id="walletSearch" autocomplete="off" placeholder="Search order, seller or reference" aria-label="Search wallet transactions"></label></div>
<div class="wallet-list-head" aria-hidden="true"><span>Transaction</span><span>Category</span><span>Date</span><span style="text-align:right">Amount</span><span></span></div>
<div id="walletRows"><?= walletRows($data['transactions']) ?></div>
<footer class="wallet-panel-footer"><span><strong id="walletVisible"><?= number_format($counts['all']) ?></strong> transaction(s) shown · Latest 100 records</span><span id="walletUpdated">Last updated: <?= walletEsc($data['updated_at']) ?></span></footer>
</section>
</main>
</div>
<div class="wallet-modal" id="walletModal" aria-hidden="true"><div class="wallet-modal-card" role="dialog" aria-modal="true" aria-labelledby="walletModalTitle">
<div class="wallet-modal-head"><div><small>Platform wallet activity</small><h2 id="walletModalTitle">Transaction Details</h2></div><button type="button" class="wallet-modal-close" id="walletClose" aria-label="Close transaction details">×</button></div>
<div class="wallet-modal-body">
<div class="wallet-hero"><div><small>Transaction amount</small><strong id="walletHeroAmount">—</strong></div><span class="wallet-hero-tag" id="walletHeroCategory">—</span></div>
<section class="wallet-detail-section"><h3>Transaction Overview</h3><div class="wallet-detail-grid">
<div class="wallet-field"><span>Transaction ID</span><strong data-field="transaction_id"></strong></div><div class="wallet-field"><span>Direction</span><strong data-field="direction"></strong></div>
<div class="wallet-field"><span>Transaction Date</span><strong data-field="date"></strong></div><div class="wallet-field"><span>Wallet Account</span><strong data-field="wallet_id"></strong></div>
<div class="wallet-field"><span>Balance After</span><strong data-field="balance_after"></strong></div><div class="wallet-field"><span>Ledger Reference</span><strong data-field="reference"></strong></div>
</div></section>
<section class="wallet-detail-section" data-section="order"><h3>Related Order</h3><div class="wallet-detail-grid">
<div class="wallet-field"><span>Order ID</span><strong data-field="order_id"></strong></div><div class="wallet-field"><span>Order Total</span><strong data-field="order_total"></strong></div>
<div class="wallet-field"><span>Seller</span><strong data-field="seller"></strong></div><div class="wallet-field"><span>Buyer</span><strong data-field="buyer"></strong></div>
<div class="wallet-field"><span>Order Status</span><strong data-field="order_status"></strong></div><div class="wallet-field"><span>Ordered At</span><strong data-field="order_date"></strong></div>
</div></section>
<section class="wallet-detail-section" data-section="commission"><h3>Order Commission Breakdown</h3><div class="wallet-detail-grid">
<div class="wallet-field"><span>Commission Record</span><strong data-field="commission_id"></strong></div><div class="wallet-field"><span>Gross Amount</span><strong data-field="gross_amount"></strong></div>
<div class="wallet-field"><span>Commission Rate</span><strong data-field="commission_rate"></strong></div><div class="wallet-field"><span>Platform Commission</span><strong data-field="commission_amount"></strong></div>
<div class="wallet-field"><span>Seller Net Amount</span><strong data-field="seller_net"></strong></div><div class="wallet-field"><span>Collection Method</span><strong data-field="collection_method"></strong></div>
<div class="wallet-field"><span>Commission Status</span><strong data-field="commission_status"></strong></div><div class="wallet-field"><span>Commission Reference</span><strong data-field="commission_reference"></strong></div>
</div></section>
<section class="wallet-detail-section" data-section="payment"><h3>Linked Payment</h3><div class="wallet-detail-grid">
<div class="wallet-field"><span>Payment ID</span><strong data-field="payment_id"></strong></div><div class="wallet-field"><span>Payment Status</span><strong data-field="payment_status"></strong></div>
<div class="wallet-field"><span>Payment Method</span><strong data-field="payment_method"></strong></div><div class="wallet-field"><span>Payment Provider</span><strong data-field="payment_provider"></strong></div>
<div class="wallet-field"><span>Payment Reference</span><strong data-field="payment_reference"></strong></div><div class="wallet-field"><span>Paid At</span><strong data-field="paid_at"></strong></div>
</div></section>
<section class="wallet-detail-section" data-section="paymongo"><h3>PayMongo References</h3><div class="wallet-detail-grid">
<div class="wallet-field"><span>PayMongo Payment Reference</span><strong data-field="paymongo_payment_reference"></strong></div><div class="wallet-field"><span>PayMongo Intent Reference</span><strong data-field="paymongo_intent_reference"></strong></div>
</div></section>
<section class="wallet-detail-section"><h3>Transaction Description</h3><div class="wallet-description" data-field="description">—</div></section>
</div><div class="wallet-modal-footer"><button type="button" class="wallet-done" id="walletDone">Done</button></div>
</div></div>
<script>
(() => {
    const rows = document.getElementById('walletRows');
    const search = document.getElementById('walletSearch');
    const filters = [...document.querySelectorAll('.wallet-filter')];
    const modal = document.getElementById('walletModal');
    const closeButton = document.getElementById('walletClose');
    const doneButton = document.getElementById('walletDone');
    const error = document.getElementById('walletError');
    const formatter = new Intl.NumberFormat('en-PH');
    let currentFilter = 'all';
    let trigger = null;
    let refreshing = false;
    let lastRefresh = Date.now();
    function applyFilters() {
        const term = search.value.trim().toLocaleLowerCase();
        const cards = rows.querySelectorAll('[data-wallet-row]');
        let visible = 0;
        cards.forEach(card => {
            const show = (currentFilter === 'all' || card.dataset.direction === currentFilter) && (card.dataset.search || '').includes(term);
            card.hidden = !show;
            if (show) visible++;
        });
        const empty = document.getElementById('walletEmpty');
        if (empty) {
            empty.hidden = visible > 0;
            const title = empty.querySelector('strong');
            const note = empty.querySelectorAll('span')[1];
            if (title) title.textContent = cards.length ? 'No matching transactions' : 'No wallet transactions yet';
            if (note) note.textContent = cards.length ? 'Try another search or transaction type.' : 'Transactions will appear here when recorded.';
        }
        document.getElementById('walletVisible').textContent = formatter.format(visible);
    }
    filters.forEach(button => button.addEventListener('click', () => {
        currentFilter = button.dataset.filter || 'all';
        filters.forEach(other => {
            const selected = other === button;
            other.classList.toggle('active', selected);
            other.setAttribute('aria-pressed', String(selected));
        });
        applyFilters();
    }));
    search.addEventListener('input', applyFilters);
    rows.addEventListener('click', event => {
        const row = event.target.closest('[data-wallet-row]');
        if (!row) return;
        let data;
        try { data = JSON.parse(row.dataset.details || '{}'); } catch (_) { return; }
        trigger = row;
        modal.querySelectorAll('[data-field]').forEach(field => {
            field.textContent = data[field.dataset.field] || 'Not recorded';
        });
        document.getElementById('walletModalTitle').textContent = 'Transaction ' + (data.transaction_id || 'Details');
        const amount = document.getElementById('walletHeroAmount');
        amount.textContent = data.amount || '—';
        amount.className = (data.direction || '').toLowerCase() === 'debit' ? 'debit' : 'credit';
        document.getElementById('walletHeroCategory').textContent = data.category || 'Transaction';
        ['order', 'commission', 'payment', 'paymongo'].forEach(section => {
            modal.querySelector('[data-section="' + section + '"]').hidden = !data['has_' + section];
        });
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('wallet-dialog-open');
        closeButton.focus();
    });
    function closeModal() {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('wallet-dialog-open');
        if (trigger && trigger.isConnected) trigger.focus();
    }
    closeButton.addEventListener('click', closeModal);
    doneButton.addEventListener('click', closeModal);
    modal.addEventListener('click', event => { if (event.target === modal) closeModal(); });
    document.addEventListener('keydown', event => {
        if (!modal.classList.contains('open')) return;
        if (event.key === 'Escape') closeModal();
        if (event.key !== 'Tab') return;
        const focusables = [closeButton, doneButton];
        if (event.shiftKey && document.activeElement === focusables[0]) { event.preventDefault(); focusables[1].focus(); }
        if (!event.shiftKey && document.activeElement === focusables[1]) { event.preventDefault(); focusables[0].focus(); }
    });
    async function refreshWallet(force = false) {
        if (refreshing || document.hidden || modal.classList.contains('open')) return;
        if (!force && Date.now() - lastRefresh < 29000) return;
        refreshing = true;
        try {
            const url = new URL(window.location.href);
            url.searchParams.set('wallet_data', '1');
            const response = await fetch(url.toString(), {credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
            if (response.status === 401 || response.status === 403) { window.location.href = '../login.php'; return; }
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Unable to refresh wallet data.');
            ['Balance','Fees','Gross','Count'].forEach((name, i) => {
                document.getElementById('walletStat' + name).textContent = [result.summary.balance,result.summary.fees,result.summary.gross,result.summary.count][i];
            });
            Object.entries(result.counts || {}).forEach(([key, count]) => {
                const badge = document.querySelector('[data-count="' + key + '"]');
                if (badge) badge.textContent = formatter.format(count);
            });
            rows.innerHTML = result.rows_html;
            applyFilters();
            document.getElementById('walletUpdated').textContent = 'Last updated: ' + result.updated_at;
            error.hidden = true;
            document.getElementById('walletSync').classList.remove('is-error');
            lastRefresh = Date.now();
        } catch (e) {
            error.textContent = e.message || 'Unable to refresh platform wallet.';
            error.hidden = false;
            document.getElementById('walletSync').classList.add('is-error');
        } finally { refreshing = false; }
    }
    window.setInterval(() => refreshWallet(), 30000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshWallet(); });
    window.addEventListener('focus', () => refreshWallet());
    applyFilters();
})();
</script>
</body>
</html>
