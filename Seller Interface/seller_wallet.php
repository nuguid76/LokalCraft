<?php
require_once __DIR__ . '/seller_auth.php';
date_default_timezone_set('Asia/Manila');
$sellerUserId = (int)$sellerId;
$activePage = 'wallet';
function sellerWalletEsc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function sellerWalletMoney($amount): string
{
    return '₱' . number_format((float)$amount, 2);
}
function sellerWalletWhen($value): string
{
    if (!$value) {
        return 'Date unavailable';
    }
    $timestamp = strtotime((string)$value);
    return $timestamp
        ? date('M j, Y · g:i A', $timestamp)
        : 'Date unavailable';
}
function sellerWalletLabel(string $category): string
{
    $labels = [
        'seller_share' => 'Seller Share',
        'platform_fee' => 'Platform Fee',
        'cash_fee' => 'Platform Fee',
        'refund' => 'Refund',
        'test_fund' => 'Test Funds',
        'adjustment' => 'Adjustment'
    ];
    return $labels[$category]
        ?? ucwords(str_replace(['_', '-'], ' ', $category));
}
function sellerWalletTransactionLabel(array $transaction): string
{
    $category = strtolower(trim((string)($transaction['transaction_category'] ?? '')));
    $reference = strtolower(trim((string)($transaction['reference_key'] ?? '')));
    if ($category === 'seller_share') {
        return 'QR Ph Seller Share';
    }
    if ($category === 'cash_fee') {
        return 'Cash Platform Fee';
    }
    if ($category === 'refund' && str_starts_with($reference, 'qr-refund-seller-order-')) {
        return 'QR Ph Reversal';
    }
    if ($category === 'refund' && str_starts_with($reference, 'cash-platform-fee-refund-seller-order-')) {
        return 'Cash Fee Refund';
    }
    return sellerWalletLabel($category);
}
$walletBalance = 0.0;
$totalEarnings = 0.0;
$qrSales = 0;
$transactionCount = 0;
$transactions = [];
$errorMessage = '';
try {
    $conn->begin_transaction();
    $stmt = $conn->prepare(
        'INSERT INTO wallet_accounts
            (user_id, wallet_type, balance, status, created_at, updated_at)
         VALUES
            (?, "seller", 0.00, "active", NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            updated_at = updated_at'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare your wallet.');
    }
    $stmt->bind_param('i', $sellerUserId);
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare(
        'SELECT wallet_id, balance, status
         FROM wallet_accounts
         WHERE user_id = ?
           AND wallet_type = "seller"
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to load your wallet.');
    }
    $stmt->bind_param('i', $sellerUserId);
    $stmt->execute();
    $wallet = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$wallet) {
        throw new RuntimeException('Seller wallet was not found.');
    }
    $walletId = (int)$wallet['wallet_id'];
    $walletBalance = (float)$wallet['balance'];
    $stmt = $conn->prepare(
        'SELECT
            COALESCE(SUM(
                CASE
                    WHEN collection_method = "split_payment"
                     AND commission_status = "earned"
                    THEN seller_net_amount
                    ELSE 0
                END
            ), 0) AS total_earnings,
            COALESCE(SUM(
                CASE
                    WHEN collection_method = "split_payment"
                     AND commission_status = "earned"
                    THEN 1
                    ELSE 0
                END
            ), 0) AS qr_sales
         FROM platform_commissions
         WHERE seller_id = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to load QR wallet totals.');
    }
    $stmt->bind_param('i', $sellerUserId);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $totalEarnings = (float)($summary['total_earnings'] ?? 0);
    $qrSales = (int)($summary['qr_sales'] ?? 0);
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS transaction_count
         FROM wallet_transactions
         WHERE wallet_id = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to load wallet transaction count.');
    }
    $stmt->bind_param('i', $walletId);
    $stmt->execute();
    $transactionCount = (int)($stmt->get_result()->fetch_assoc()['transaction_count'] ?? 0);
    $stmt->close();
    $stmt = $conn->prepare(
        'SELECT
            wt.wallet_transaction_id,
            wt.order_id,
            wt.payment_id,
            wt.transaction_category,
            wt.direction,
            wt.amount,
            wt.balance_after,
            wt.reference_key,
            wt.description,
            wt.created_at,
            pay.paymongo_payment_id AS recorded_paymongo_payment_id,
            pay.paymongo_payment_intent_id AS recorded_paymongo_intent_id,
            pc.paymongo_payment_id AS commission_paymongo_payment_id,
            o.order_status,
            pc.commission_id,
            pc.gross_amount AS commission_gross_amount,
            pc.commission_rate,
            pc.commission_amount,
            pc.seller_net_amount,
            pc.collection_method,
            pc.commission_status,
            (
                SELECT GROUP_CONCAT(
                    CONCAT(oi.quantity, " × ", COALESCE(p.product_name, "Product"))
                    ORDER BY oi.order_item_id SEPARATOR ", "
                )
                FROM order_items oi
                LEFT JOIN products p ON p.product_id = oi.product_id
                WHERE oi.order_id = o.order_id
            ) AS order_items_summary,
            COALESCE(
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
                "Buyer"
            ) AS buyer_name
         FROM wallet_transactions wt
         LEFT JOIN orders o
           ON o.order_id = wt.order_id AND o.seller_id = ?
         LEFT JOIN platform_commissions pc
           ON pc.order_id = o.order_id AND pc.seller_id = ?
         LEFT JOIN payments pay ON pay.payment_id = wt.payment_id
           AND pay.order_id = wt.order_id AND pay.buyer_id = o.buyer_id
         LEFT JOIN users u ON u.id = o.buyer_id
         WHERE wt.wallet_id = ?
         ORDER BY wt.created_at DESC, wt.wallet_transaction_id DESC
         LIMIT 100'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to load wallet transactions.');
    }
    $stmt->bind_param('iii', $sellerUserId, $sellerUserId, $walletId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    $stmt->close();
    $conn->commit();
} catch (Throwable $e) {
    if ($conn->errno === 0 || $conn->ping()) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
    }
    error_log('LokalCraft seller wallet error: ' . $e->getMessage());
    $errorMessage = 'Wallet data is temporarily unavailable.';
}
// Modal details come only from the current seller's wallet and commission records.
// Missing commission data is shown as unavailable, never estimated as a 5% charge.
$walletDetailData = [];
foreach ($transactions as $transaction) {
    $transactionId = (int)$transaction['wallet_transaction_id'];
    $orderId = (int)($transaction['order_id'] ?? 0);
    $direction = strtolower((string)($transaction['direction'] ?? 'credit'));
    $category = strtolower((string)($transaction['transaction_category'] ?? ''));
    $amount = round((float)$transaction['amount'], 2);
    $balanceAfter = round((float)$transaction['balance_after'], 2);
    $hasCommission = !empty($transaction['commission_id']);
    $walletDetailData[(string)$transactionId] = [
        'id' => $transactionId,
        'title' => sellerWalletTransactionLabel($transaction),
        'category' => $category,
        'direction' => $direction === 'debit' ? 'debit' : 'credit',
        'amount' => sellerWalletMoney($amount),
        'balanceBefore' => sellerWalletMoney(round($balanceAfter + ($direction === 'debit' ? $amount : -$amount), 2)),
        'balanceAfter' => sellerWalletMoney($balanceAfter),
        'date' => sellerWalletWhen($transaction['created_at'] ?? null),
        'description' => trim((string)($transaction['description'] ?? '')),
        'orderId' => $orderId,
        'paymongoPaymentId' => trim((string)(($transaction['recorded_paymongo_payment_id'] ?? '')
            ?: ($transaction['commission_paymongo_payment_id'] ?? ''))),
        'paymongoIntentId' => trim((string)($transaction['recorded_paymongo_intent_id'] ?? '')),
        'buyerName' => $orderId > 0 && !empty($transaction['commission_id'])
            ? (string)$transaction['buyer_name'] : '',
        'orderStatus' => (string)($transaction['order_status'] ?? ''),
        'orderItems' => (string)($transaction['order_items_summary'] ?? ''),
        'hasCommission' => $hasCommission,
        'commissionMethod' => $hasCommission ? (string)$transaction['collection_method'] : '',
        'commissionStatus' => $hasCommission ? (string)$transaction['commission_status'] : '',
        'gross' => $hasCommission ? sellerWalletMoney($transaction['commission_gross_amount']) : '',
        'rate' => $hasCommission ? number_format((float)$transaction['commission_rate'], 2) : '',
        'platformFee' => $hasCommission ? sellerWalletMoney($transaction['commission_amount']) : '',
        'sellerNet' => $hasCommission ? sellerWalletMoney($transaction['seller_net_amount']) : '',
    ];
}
header('Cache-Control: private, no-store, max-age=0');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Seller Wallet | LokalCraft</title>
  <style>
:root {
    --wallet-card: #ffffff;
    --wallet-text: #2d2d2d;
    --wallet-muted: #7c7671;
    --wallet-line: #e9e2dc;
    --wallet-orange: #ea8a3a;
    --wallet-orange-dark: #d87522;
    --wallet-orange-soft: #fff4e9;
    --wallet-green: #2f8f62;
    --wallet-green-soft: #edf8f1;
    --wallet-blue: #4b7fc1;
    --wallet-blue-soft: #eef5ff;
    --wallet-purple: #8b67b4;
    --wallet-purple-soft: #f3edfb;
    --wallet-red: #c94f45;
    --wallet-red-soft: #fff1ef;
    --wallet-shadow: 0 8px 24px rgba(54,39,25,.045);
}
* { box-sizing: border-box; }
body {
    margin: 0;
    min-height: 100vh;
    background: #f4f3f1;
    color: var(--wallet-text);
    font-family: "Segoe UI", Arial, sans-serif;
}
.app { min-height: 100vh; }
/* Same normal document-flow positioning as the Seller Dashboard. */
body .app > main.main.wallet-main {
    position: relative !important;
    inset: auto !important;
    top: auto !important;
    right: auto !important;
    bottom: auto !important;
    left: auto !important;
    width: auto !important;
    max-width: none !important;
    min-width: 0 !important;
    height: auto !important;
    min-height: 100vh !important;
    margin: 0 0 0 250px !important;
    padding: var(--wallet-header-offset, 70px) 0 28px !important;
    overflow: visible !important;
    background: transparent !important;
}
.wallet-search-shell { min-width: 0; }
body .app > main.wallet-main > .wallet-content {
    width: 100% !important;
    max-width: 1240px !important;
    margin: 0 auto !important;
    padding: 8px 26px 24px !important;
}
.page-heading {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 18px;
}
.page-heading h1 {
    margin: 0;
    color: var(--wallet-text);
    font-size: 2.05rem;
    font-weight: 800;
    letter-spacing: -.7px;
}
.page-heading p {
    margin: 5px 0 0;
    color: var(--wallet-muted);
    font-size: .9rem;
    line-height: 1.45;
}
.wallet-note {
    max-width: 410px;
    color: #8d837a;
    font-size: .67rem;
    line-height: 1.5;
    text-align: right;
}
.stats {
    display: grid;
    grid-template-columns: repeat(4,minmax(0,1fr));
    gap: 14px;
    margin-bottom: 18px;
}
.stat-card,
.panel {
    border: 1px solid var(--wallet-line);
    background: var(--wallet-card);
    box-shadow: var(--wallet-shadow);
}
.stat-card {
    min-width: 0;
    min-height: 92px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px;
    border-radius: 12px;
}
.stat-copy { min-width: 0; }
.stat-label {
    margin-bottom: 7px;
    color: #817970;
    font-size: .69rem;
    font-weight: 700;
}
.stat-value {
    color: #49372a;
    font-size: 1.15rem;
    font-weight: 900;
    overflow-wrap: anywhere;
}
.stat-hint {
    margin-top: 4px;
    color: #9a9188;
    font-size: .56rem;
    line-height: 1.35;
}
/* All four wallet stats use the same Seller Profile / Dashboard gradient. */
.stats .stat-card {
    border: 1px solid #eedfce;
    background: linear-gradient(108deg, #fffdfb 0%, #fff7ee 46%, #ffe9d5 100%);
    box-shadow: var(--wallet-shadow);
}
.stat-icon {
    width: 38px;
    height: 38px;
    flex: 0 0 38px;
    display: grid;
    place-items: center;
    border: 1px solid #f1d5bb;
    border-radius: 10px;
    background: rgba(255,255,255,.62);
    color: #c76c27;
}
.stat-icon svg {
    display: block;
    width: 20px;
    height: 20px;
    fill: none;
    stroke: currentColor;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
}
.panel {
    padding: 17px;
    border-radius: 12px;
}
.panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 8px;
}
.panel-title {
    margin: 0;
    color: #403731;
    font-size: .82rem;
    font-weight: 800;
}
.count-badge {
    padding: 5px 9px;
    border-radius: 999px;
    background: var(--wallet-orange-soft);
    color: var(--wallet-orange-dark);
    font-size: .62rem;
    font-weight: 800;
}
.error-box {
    margin-bottom: 14px;
    padding: 11px 12px;
    border: 1px solid #efb9b9;
    border-radius: 9px;
    background: var(--wallet-red-soft);
    color: #963d3d;
    font-size: .74rem;
}
.transaction-list { width: 100%; }
.transaction-row {
    display: grid;
    width: 100%;
    padding: 0 10px;
    text-align: left;
    font: inherit;
    color: inherit;
    cursor: pointer;
    border-left: 0;
    border-right: 0;
    border-bottom: 0;
    background: transparent;
    transition: background .15s ease;
    grid-template-columns: minmax(0,1.5fr) minmax(125px,.8fr) 145px 130px;
    align-items: center;
    gap: 12px;
    min-height: 70px;
    border-top: 1px solid #eee7e1;
}
.transaction-row:first-child { border-top: 0; }
.transaction-row:hover, .transaction-row:focus-visible { background: #fff7ef; }
.transaction-row:focus-visible { outline: 3px solid #f0ae72; outline-offset: -3px; border-radius: 8px; }
.transaction-title {
    margin-bottom: 4px;
    color: #403731;
    font-size: .75rem;
    font-weight: 800;
}
.transaction-meta,
.transaction-date,
.balance-after {
    color: #958c84;
    font-size: .64rem;
    line-height: 1.45;
}
.transaction-category {
    display: inline-flex;
    width: fit-content;
    padding: 5px 8px;
    border-radius: 999px;
    background: #f6f2ee;
    color: #6d6259;
    font-size: .6rem;
    font-weight: 800;
}
.transaction-amount {
    text-align: right;
    font-size: .78rem;
    font-weight: 900;
}
.transaction-amount.credit { color: var(--wallet-green); }
.transaction-amount.debit { color: var(--wallet-red); }
.empty-list {
    padding: 28px 0 15px;
    color: #999087;
    font-size: .7rem;
    text-align: center;
}
/* Single responsive transaction-details popup, no additional PHP endpoints. */
.wallet-detail-dialog {
    position: fixed;
    width: min(560px, calc(100vw - 24px));
    max-width: 560px;
    max-height: min(88dvh, 760px);
    margin: auto;
    padding: 0;
    border: 1px solid #eadbcf;
    border-radius: 18px;
    background: #fffdfb;
    color: var(--wallet-text);
    box-shadow: 0 22px 70px rgba(48,31,18,.22);
    overflow: hidden;
}
.wallet-detail-dialog::backdrop { background: rgba(41,32,25,.52); backdrop-filter: blur(2px); }
.wallet-detail-shell { display: flex; flex-direction: column; max-height: min(88dvh, 760px); min-height: 0; }
.wallet-detail-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 17px 20px; border-bottom: 1px solid #eee3d9; background: #fff8f1; }
.wallet-detail-head h2 { margin: 0; font-size: 1.05rem; }
.wallet-detail-close { display: grid; place-items: center; flex: 0 0 34px; width: 34px; height: 34px; border: 1px solid #e7d9cb; border-radius: 50%; background: #fff; color: #68594d; cursor: pointer; }
.wallet-detail-close svg { width: 17px; height: 17px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.wallet-detail-close:hover { background: #fff0e2; }
.wallet-detail-body { padding: 20px; overflow: auto; overscroll-behavior: contain; -webkit-overflow-scrolling: touch; }
.wallet-detail-kicker { color: #aa6a3b; font-size: .69rem; font-weight: 850; letter-spacing: .045em; text-transform: uppercase; }
.wallet-detail-value { margin-top: 6px; color: #a95d20; font-size: clamp(1.65rem,5.5vw,2rem); font-weight: 900; }
.wallet-detail-value.debit { color: #b44e47; }
.wallet-detail-caption { margin-top: 3px; color: #8d7f72; font-size: .73rem; }
.wallet-detail-section { margin-top: 19px; }
.wallet-detail-section h3 { margin: 0 0 9px; color: #44392f; font-size: .82rem; }
.wallet-detail-list { margin: 0; padding: 0; border: 1px solid #eee4db; border-radius: 12px; background: #fff; overflow: hidden; }
.wallet-detail-item { display: flex; justify-content: space-between; gap: 14px; padding: 11px 12px; border-bottom: 1px solid #f2ece7; font-size: .75rem; }
.wallet-detail-item:last-child { border: 0; }
.wallet-detail-item dt { flex: 0 0 auto; color: #877a70; }
.wallet-detail-item dd { min-width: 0; margin: 0; color: #483c34; font-weight: 750; text-align: right; overflow-wrap: anywhere; }
.wallet-detail-item.highlight { background: #fff3e7; }
.wallet-detail-item.highlight dd { color: #ad632a; }
.wallet-detail-note { margin: 11px 0 0; padding: 12px 13px; border: 1px solid #efdfce; border-radius: 11px; background: #fff8f0; color: #755c48; font-size: .72rem; line-height: 1.55; }
.wallet-detail-footer { display: flex; justify-content: flex-end; padding: 13px 20px; border-top: 1px solid #eee3d9; }
.wallet-detail-done { min-width: 92px; padding: 9px 17px; border: 1px solid #dc7a2a; border-radius: 10px; background: #ea8a3a; color: #fff; font: inherit; font-size: .78rem; font-weight: 800; cursor: pointer; }
.wallet-detail-done:hover { background: #d87522; }
body.wallet-detail-open { overflow: hidden; }
@media (max-width: 1050px) {
    .stats { grid-template-columns: repeat(2,minmax(0,1fr)); }
    .transaction-row { grid-template-columns: minmax(0,1fr) 130px 125px; }
    .transaction-category { display: none; }
}
@media (max-width: 840px) {
    /* Seller sidebar remains visible down to 761px: keep its 250px clearance. */
    body .app > main.wallet-main > .wallet-content {
        max-width: none !important;
        padding: 8px 16px 24px !important;
    }
}
@media (max-width: 760px) {
    body .app > main.main.wallet-main {
        width: 100% !important;
        margin-left: 0 !important;
        padding-top: var(--wallet-header-offset, 76px) !important;
    }
    body .app > main.wallet-main > .wallet-content { padding: 8px 12px 24px !important; }
    .page-heading { align-items: flex-start; flex-direction: column; }
    .page-heading h1 { font-size: 1.8rem; }
    .wallet-note { max-width: none; text-align: left; }
    .transaction-row { grid-template-columns: 1fr auto; gap: 7px 12px; padding: 12px 8px; }
    .transaction-category { display: inline-flex; }
    .transaction-date, .balance-after { grid-column: 1 / -1; }
}
@media (max-width: 520px) {
    .stats { grid-template-columns: repeat(2,minmax(0,1fr)); gap: 10px; }
    .stat-card { min-height: 86px; padding: 12px; gap: 7px; }
    .stat-icon { width: 32px; height: 32px; flex-basis: 32px; }
    .stat-icon svg { width: 18px; height: 18px; }
    .stat-label { font-size: .65rem; line-height: 1.3; }
    .stat-value { font-size: clamp(.84rem,3.6vw,1.12rem); }
    .stat-hint { font-size: .55rem; }
    .panel { padding: 14px; }
    .wallet-detail-body { padding: 15px; }
    .wallet-detail-item { gap: 8px; }
}
@media (max-width: 360px) { .stat-card { padding: 10px; gap: 5px; } .stat-icon { width: 29px; height: 29px; flex-basis: 29px; } }
@media (prefers-reduced-motion: reduce) { .transaction-row { transition: none; } }
</style>
</head>
<body>
<div class="app">
  <?php require __DIR__ . '/seller_nav.php'; ?>
  <main class="main wallet-main">
    <div class="wallet-search-shell" id="walletSearchShell"><?php require __DIR__ . '/seller_search.php'; ?></div>
    <section class="content wallet-content">
      <div class="page-heading">
        <div>
          <h1>Wallet</h1>
        </div>
        <div class="wallet-note">
          This is an internal LokalCraft wallet record. It does not represent
          an automatic bank or e-wallet withdrawal.
        </div>
      </div>
      <?php if ($errorMessage !== ''): ?>
        <div class="error-box">
          <?php echo sellerWalletEsc($errorMessage); ?>
        </div>
      <?php endif; ?>
      <section class="stats" aria-label="Wallet summary">
        <article class="stat-card">
          <div class="stat-copy">
            <div class="stat-label">Current Balance</div>
            <div class="stat-value">
              <?php echo sellerWalletMoney($walletBalance); ?>
            </div>
          </div>
          <div class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><rect x="3" y="6" width="18" height="15" rx="2"/><path d="M3 10h18m-5 5h3"/></svg></div>
        </article>
        <article class="stat-card">
          <div class="stat-copy">
            <div class="stat-label">Net QR Earnings</div>
            <div class="stat-value">
              <?php echo sellerWalletMoney($totalEarnings); ?>
            </div><div class="stat-hint">95% seller share from active QR Ph payments</div>
          </div>
          <div class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="m4 17 6-6 4 4 6-7"/><path d="M15 8h5v5"/></svg></div>
        </article>
        <article class="stat-card">
          <div class="stat-copy">
            <div class="stat-label">QR Ph Sales</div>
            <div class="stat-value">
              <?php echo number_format($qrSales); ?>
            </div><div class="stat-hint">Successful, non-refunded QR Ph sales</div>
          </div>
          <div class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><rect x="3" y="7" width="18" height="14" rx="2"/><path d="M7 7V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2M7 12h10m-10 4h6"/></svg></div>
        </article>
        <article class="stat-card">
          <div class="stat-copy">
            <div class="stat-label">Transactions</div>
            <div class="stat-value">
              <?php echo number_format($transactionCount); ?>
            </div>
          </div>
          <div class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M7 3h10a2 2 0 0 1 2 2v16l-3-2-4 2-4-2-3 2V5a2 2 0 0 1 2-2Z"/><path d="M9 9h6m-6 4h6"/></svg></div>
        </article>
      </section>
      <section class="panel" aria-labelledby="sellerWalletHistoryTitle">
        <div class="panel-head">
          <h2 class="panel-title" id="sellerWalletHistoryTitle">
            Recent Transactions
          </h2>
          <span class="count-badge">
            <?php echo number_format($transactionCount); ?> total
          </span>
        </div>
        <div class="transaction-list">
          <?php if (!$transactions): ?>
            <div class="empty-list">
              No wallet transactions yet.
            </div>
          <?php else: ?>
            <?php foreach ($transactions as $transaction): ?>
              <?php
                $direction = strtolower((string)$transaction['direction']);
                $sign = $direction === 'debit' ? '-' : '+';
                $orderId = (int)($transaction['order_id'] ?? 0);
              ?>
              <button class="transaction-row" type="button"
                data-wallet-transaction="<?php echo (int)$transaction['wallet_transaction_id']; ?>"
                aria-label="View details for <?php echo $orderId > 0 ? 'Order #' . $orderId : sellerWalletEsc(sellerWalletTransactionLabel($transaction)); ?>">
                <div>
                  <div class="transaction-title">
                    <?php
                    echo $orderId > 0
                        ? 'Order #' . sellerWalletEsc($orderId)
                        : sellerWalletEsc(
                            sellerWalletTransactionLabel($transaction)
                        );
                    ?>
                  </div>
                  <div class="transaction-meta">
                    <?php
                    if ($orderId > 0) {
                        echo sellerWalletEsc(
                            (string)$transaction['buyer_name']
                        );
                    } else {
                        echo sellerWalletEsc(
                            (string)($transaction['description'] ?? '')
                        );
                    }
                    ?>
                  </div>
                </div>
                <div>
                  <span class="transaction-category">
                    <?php
                    echo sellerWalletEsc(
                        sellerWalletLabel(
                            (string)$transaction['transaction_category']
                        )
                    );
                    ?>
                  </span>
                </div>
                <div>
                  <div class="transaction-date">
                    <?php
                    echo sellerWalletEsc(
                        sellerWalletWhen($transaction['created_at'] ?? null)
                    );
                    ?>
                  </div>
                  <div class="balance-after">
                    Balance:
                    <?php
                    echo sellerWalletMoney(
                        (float)$transaction['balance_after']
                    );
                    ?>
                  </div>
                </div>
                <div class="transaction-amount <?php echo sellerWalletEsc($direction); ?>">
                  <?php
                  echo $sign
                      . sellerWalletMoney(
                          (float)$transaction['amount']
                      );
                  ?>
                </div>
              </button>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </section>
    </section>
  </main>
</div>

<dialog class="wallet-detail-dialog" id="walletTransactionDialog" aria-labelledby="walletTransactionTitle">
  <div class="wallet-detail-shell">
    <div class="wallet-detail-head">
      <h2 id="walletTransactionTitle">Transaction Details</h2>
      <button class="wallet-detail-close" type="button" id="walletTransactionClose" aria-label="Close transaction details"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 5 19 19M19 5 5 19"/></svg></button>
    </div>
    <div class="wallet-detail-body">
      <div class="wallet-detail-kicker" id="walletDetailType"></div>
      <div class="wallet-detail-value" id="walletDetailAmount"></div>
      <div class="wallet-detail-caption" id="walletDetailDate"></div>
      <section class="wallet-detail-section" aria-labelledby="walletDetailInfoTitle">
        <h3 id="walletDetailInfoTitle">Transaction information</h3>
        <dl class="wallet-detail-list" id="walletDetailInformation"></dl>
      </section>
      <section class="wallet-detail-section" id="walletDetailBreakdown" aria-labelledby="walletDetailBreakdownTitle" hidden>
        <h3 id="walletDetailBreakdownTitle">Order &amp; 5% platform fee breakdown</h3>
        <dl class="wallet-detail-list" id="walletDetailCommissionRows"></dl>
        <p class="wallet-detail-note" id="walletDetailFeeNote"></p>
      </section>
      <p class="wallet-detail-note" id="walletDetailGeneralNote" hidden></p>
      <section class="wallet-detail-section" aria-labelledby="walletDetailBalanceTitle">
        <h3 id="walletDetailBalanceTitle">Wallet balance</h3>
        <dl class="wallet-detail-list" id="walletDetailBalances"></dl>
      </section>
    </div>
    <div class="wallet-detail-footer"><button type="button" class="wallet-detail-done" id="walletTransactionDone">Close</button></div>
  </div>
</dialog>
<script type="application/json" id="walletDetailJson"><?php echo json_encode($walletDetailData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}'; ?></script>
<script>
(function () {
  'use strict';
  const main = document.querySelector('main.wallet-main');
  const searchShell = document.getElementById('walletSearchShell');
  const header = searchShell?.querySelector('.seller-header-tools');
  function syncWalletHeaderSpace() {
    if (!main) return;
    // Shared Seller Search is fixed. Measure its real height instead of relying on hard-coded padding.
    const rect = header?.getBoundingClientRect();
    const bottom = rect && rect.height > 30 && rect.top < 20
      ? rect.bottom : (window.innerWidth <= 840 ? 76 : 70);
    main.style.setProperty('--wallet-header-offset', `${Math.ceil(Math.max(0, bottom))}px`);
  }
  syncWalletHeaderSpace();
  window.addEventListener('load', syncWalletHeaderSpace);
  window.addEventListener('resize', syncWalletHeaderSpace);
  if (header && typeof ResizeObserver !== 'undefined') {
    new ResizeObserver(syncWalletHeaderSpace).observe(header);
  }

  const dataNode = document.getElementById('walletDetailJson');
  const entries = JSON.parse(dataNode?.textContent || '{}');
  const dialog = document.getElementById('walletTransactionDialog');
  if (!dialog) return;
  let lastTrigger = null;
  const get = (id) => document.getElementById(id);
  const asText = (value) => value == null ? '' : String(value);
  function appendRow(container, name, value, highlighted = false) {
    const row = document.createElement('div');
    row.className = `wallet-detail-item${highlighted ? ' highlight' : ''}`;
    const title = document.createElement('dt');
    const content = document.createElement('dd');
    title.textContent = name;
    content.textContent = asText(value);
    row.append(title, content);
    container.appendChild(row);
  }
  function openDetails(button) {
    const entry = entries[button.dataset.walletTransaction];
    if (!entry) return;
    lastTrigger = button;
    get('walletDetailType').textContent = entry.title;
    const amount = get('walletDetailAmount');
    amount.textContent = `${entry.direction === 'debit' ? '−' : '+'}${entry.amount}`;
    amount.classList.toggle('debit', entry.direction === 'debit');
    get('walletDetailDate').textContent = entry.date;
    const info = get('walletDetailInformation');
    info.replaceChildren();
    appendRow(info, 'Transaction ID', `#${entry.id}`);
    appendRow(info, 'Entry type', entry.direction === 'debit' ? 'Deducted from wallet' : 'Added to wallet');
    if (entry.orderId > 0) appendRow(info, 'Related order', `Order #${entry.orderId}`);
    if (entry.paymongoPaymentId) appendRow(info, 'PayMongo Payment Reference', entry.paymongoPaymentId);
    if (entry.paymongoIntentId) appendRow(info, 'PayMongo Intent Reference', entry.paymongoIntentId);
    if (entry.buyerName) appendRow(info, 'Buyer', entry.buyerName);
    if (entry.orderItems) appendRow(info, 'Products', entry.orderItems);
    if (entry.orderStatus) appendRow(info, 'Order status', entry.orderStatus);
    if (entry.description) appendRow(info, 'Recorded as', entry.description);

    const feeSection = get('walletDetailBreakdown');
    const feeRows = get('walletDetailCommissionRows');
    feeRows.replaceChildren();
    const generalNote = get('walletDetailGeneralNote');
    generalNote.hidden = true;
    if (entry.hasCommission) {
      feeSection.hidden = false;
      const isQr = entry.commissionMethod === 'split_payment';
      const isRefund = entry.category === 'refund';
      get('walletDetailBreakdownTitle').textContent = 'Order & platform fee breakdown';
      appendRow(feeRows, 'Original order amount', entry.gross);
      appendRow(feeRows, `Platform fee (${entry.rate}%)`, `−${entry.platformFee}`);
      appendRow(feeRows, 'Seller share after fee', entry.sellerNet, true);
      appendRow(feeRows, 'Payment method', isQr ? 'QR Ph' : 'Cash');
      appendRow(feeRows, 'Commission status', entry.commissionStatus);
      const note = isRefund
        ? 'This transaction is a reversal or refund. The amounts above describe the original order and are not a new platform fee deduction.'
        : (isQr
          ? 'For QR Ph, the platform fee is taken from the buyer payment before your seller share is credited. It is not a second debit from your wallet.'
          : 'For Cash, only the platform fee is deducted from your Seller Wallet. The cash sale amount is handled outside this wallet; the seller share shown above is a breakdown reference, not a wallet credit.');
      get('walletDetailFeeNote').textContent = note;
    } else {
      feeSection.hidden = true;
      generalNote.hidden = false;
      generalNote.textContent = entry.orderId > 0
        ? 'No recorded platform commission breakdown is available for this transaction. No fee is being estimated.'
        : 'This wallet entry is not associated with an order platform fee.';
    }
    const balances = get('walletDetailBalances');
    balances.replaceChildren();
    appendRow(balances, 'Before this transaction', entry.balanceBefore);
    appendRow(balances, 'After this transaction', entry.balanceAfter, true);
    document.body.classList.add('wallet-detail-open');
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
  }
  document.querySelectorAll('[data-wallet-transaction]').forEach(button => {
    button.addEventListener('click', () => openDetails(button));
  });
  function closeDetails() {
    if (dialog.open && typeof dialog.close === 'function') dialog.close();
    else dialog.removeAttribute('open');
  }
  get('walletTransactionClose').addEventListener('click', closeDetails);
  get('walletTransactionDone').addEventListener('click', closeDetails);
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) closeDetails();
  });
  dialog.addEventListener('close', () => {
    document.body.classList.remove('wallet-detail-open');
    lastTrigger?.focus({preventScroll:true});
  });
})();
</script>
</body>
</html>
