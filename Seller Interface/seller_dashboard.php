<?php
require_once __DIR__ . '/seller_auth.php';
$sellerUserId = $sellerId;
$activePage = 'dashboard';
$totalProducts = 0;
$pendingOrders = 0;
$unreadMessages = 0;
$totalSales = 0.0;
$recentOrders = [];
$lowStockProducts = [];
$reviewsReady = false;
$totalReviews = 0;
$averageRating = 0.0;
$recentReviews = [];
$dashboardError = '';
try {
$stmt = $conn->prepare('SELECT COUNT(*) AS total FROM products WHERE seller_id = ?');
if ($stmt) {
    $stmt->bind_param('i', $sellerUserId);
    $stmt->execute();
    $totalProducts = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
}
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM orders
     WHERE seller_id = ?
       AND LOWER(order_status) = 'pending'"
);
if ($stmt) {
    $stmt->bind_param('i', $sellerUserId);
    $stmt->execute();
    $pendingOrders = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
}
$stmt = $conn->prepare(
    'SELECT COUNT(*) AS total
     FROM messages
     WHERE receiver_id = ?
       AND is_read = 0'
);
if ($stmt) {
    $stmt->bind_param('i', $sellerUserId);
    $stmt->execute();
    $unreadMessages = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
}
$stmt = $conn->prepare(
    "SELECT COALESCE(SUM(total_amount), 0) AS total
     FROM orders
     WHERE seller_id = ?
       AND LOWER(COALESCE(order_status, '')) = 'completed'"
);
if ($stmt) {
    $stmt->bind_param('i', $sellerUserId);
    $stmt->execute();
    $totalSales = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
}
$stmt = $conn->prepare(
    "SELECT
        o.order_id,
        o.total_amount,
        o.order_status,
        o.ordered_at,
        COALESCE(
            NULLIF(TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, ''))), ''),
            'Buyer'
        ) AS buyer_name,
        COALESCE(SUM(oi.quantity), 0) AS item_count
     FROM orders o
     LEFT JOIN users u ON u.id = o.buyer_id
     LEFT JOIN order_items oi ON oi.order_id = o.order_id
     WHERE o.seller_id = ?
     GROUP BY o.order_id, o.total_amount, o.order_status, o.ordered_at, u.firstname, u.lastname
     ORDER BY o.ordered_at DESC
     LIMIT 3"
);
if ($stmt) {
    $stmt->bind_param('i', $sellerUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recentOrders[] = $row;
    }
    $stmt->close();
}
$stmt = $conn->prepare(
    "SELECT
        product_id,
        product_name,
        stock,
        COALESCE(reserved_stock, 0) AS reserved_stock,
        GREATEST(stock - COALESCE(reserved_stock, 0), 0) AS available_stock
     FROM products
     WHERE seller_id = ?
       AND LOWER(status) <> 'archived'
       AND GREATEST(stock - COALESCE(reserved_stock, 0), 0) <= 5
     ORDER BY available_stock ASC, created_at DESC, product_id DESC
     LIMIT 3"
);
if ($stmt) {
    $stmt->bind_param('i', $sellerUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $lowStockProducts[] = $row;
    }
    $stmt->close();
}
try {
    $reviewTableCheck = $conn->query("SHOW TABLES LIKE 'reviews'");
    $reviewsReady = $reviewTableCheck && $reviewTableCheck->num_rows > 0;
    if ($reviewTableCheck) {
        $reviewTableCheck->free();
    }
} catch (Throwable $ignored) {
    $reviewsReady = false;
}
if ($reviewsReady) {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total_reviews, COALESCE(AVG(rating), 0) AS average_rating
         FROM reviews
         WHERE seller_id = ?'
    );
    if ($stmt) {
        $stmt->bind_param('i', $sellerUserId);
        $stmt->execute();
        $reviewSummary = $stmt->get_result()->fetch_assoc() ?: [];
        $totalReviews = (int)($reviewSummary['total_reviews'] ?? 0);
        $averageRating = (float)($reviewSummary['average_rating'] ?? 0);
        $stmt->close();
    }
    $stmt = $conn->prepare(
        'SELECT
            r.review_id,
            r.order_id,
            r.product_id,
            r.rating,
            r.review_text,
            r.created_at,
            COALESCE(NULLIF(TRIM(p.product_name), ""), "Product") AS product_name,
            u.firstname,
            u.lastname
         FROM reviews r
         LEFT JOIN products p ON p.product_id = r.product_id
         LEFT JOIN users u ON u.id = r.buyer_id
         WHERE r.seller_id = ?
         ORDER BY r.created_at DESC, r.review_id DESC
         LIMIT 3'
    );
    if ($stmt) {
        $stmt->bind_param('i', $sellerUserId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $firstName = trim((string)($row['firstname'] ?? ''));
            $lastName = trim((string)($row['lastname'] ?? ''));
            if ($firstName === '') {
                $row['buyer_name'] = 'Buyer';
            } else {
                $row['buyer_name'] = $firstName;
                if ($lastName !== '') {
                    $lastInitial = function_exists('mb_substr')
                        ? mb_substr($lastName, 0, 1)
                        : substr($lastName, 0, 1);
                    $row['buyer_name'] .= ' ' . strtoupper($lastInitial) . '.';
                }
            }
            $recentReviews[] = $row;
        }
        $stmt->close();
    }
}
$dailyLabels = [];
$dailyValues = [];
$startOfToday = new DateTimeImmutable('today');
$dailyStart = $startOfToday->modify('-6 days');
$stmt = $conn->prepare(
    "SELECT DATE(ordered_at) AS period_date, COALESCE(SUM(total_amount), 0) AS total
     FROM orders
     WHERE seller_id = ?
       AND ordered_at >= ?
       AND LOWER(COALESCE(order_status, '')) = 'completed'
     GROUP BY DATE(ordered_at)
     ORDER BY period_date ASC"
);
$dailyMap = [];
if ($stmt) {
    $dailyStartSql = $dailyStart->format('Y-m-d 00:00:00');
    $stmt->bind_param('is', $sellerUserId, $dailyStartSql);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $dailyMap[$row['period_date']] = (float)$row['total'];
    }
    $stmt->close();
}
for ($i = 0; $i < 7; $i++) {
    $date = $dailyStart->modify("+{$i} days");
    $key = $date->format('Y-m-d');
    $dailyLabels[] = $date->format('D');
    $dailyValues[] = round($dailyMap[$key] ?? 0, 2);
}
$weeklyLabels = [];
$weeklyValues = [];
$weekStart = $startOfToday->modify('monday this week')->modify('-6 weeks');
$stmt = $conn->prepare(
    "SELECT YEARWEEK(ordered_at, 1) AS period_key, COALESCE(SUM(total_amount), 0) AS total
     FROM orders
     WHERE seller_id = ?
       AND ordered_at >= ?
       AND LOWER(COALESCE(order_status, '')) = 'completed'
     GROUP BY YEARWEEK(ordered_at, 1)
     ORDER BY period_key ASC"
);
$weeklyMap = [];
if ($stmt) {
    $weekStartSql = $weekStart->format('Y-m-d 00:00:00');
    $stmt->bind_param('is', $sellerUserId, $weekStartSql);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $weeklyMap[(string)$row['period_key']] = (float)$row['total'];
    }
    $stmt->close();
}
for ($i = 0; $i < 7; $i++) {
    $date = $weekStart->modify("+{$i} weeks");
    $key = $date->format('oW');
    $weeklyLabels[] = 'W' . ($i + 1);
    $weeklyValues[] = round($weeklyMap[$key] ?? 0, 2);
}
$monthlyLabels = [];
$monthlyValues = [];
$monthStart = $startOfToday->modify('first day of this month')->modify('-5 months');
$stmt = $conn->prepare(
    "SELECT DATE_FORMAT(ordered_at, '%Y-%m') AS period_key, COALESCE(SUM(total_amount), 0) AS total
     FROM orders
     WHERE seller_id = ?
       AND ordered_at >= ?
       AND LOWER(COALESCE(order_status, '')) = 'completed'
     GROUP BY DATE_FORMAT(ordered_at, '%Y-%m')
     ORDER BY period_key ASC"
);
$monthlyMap = [];
if ($stmt) {
    $monthStartSql = $monthStart->format('Y-m-d 00:00:00');
    $stmt->bind_param('is', $sellerUserId, $monthStartSql);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $monthlyMap[$row['period_key']] = (float)$row['total'];
    }
    $stmt->close();
}
for ($i = 0; $i < 6; $i++) {
    $date = $monthStart->modify("+{$i} months");
    $key = $date->format('Y-m');
    $monthlyLabels[] = $date->format('M');
    $monthlyValues[] = round($monthlyMap[$key] ?? 0, 2);
}
$chartData = [
    'daily' => ['labels' => $dailyLabels, 'values' => $dailyValues],
    'weekly' => ['labels' => $weeklyLabels, 'values' => $weeklyValues],
    'monthly' => ['labels' => $monthlyLabels, 'values' => $monthlyValues]
];
} catch (Throwable $e) {
    error_log('LokalCraft seller dashboard error: ' . $e->getMessage());
    $dashboardError = 'Some dashboard information could not be loaded right now. Please refresh the page.';
}
function formatStatus(string $status): string
{
    return ucwords(str_replace(['_', '-'], ' ', strtolower($status)));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Seller Dashboard | LokalCraft</title>
  <style>
:root {
    /* Same warm palette used by Seller Profile. */
    --bg: #f4f3f1;
    --panel: #ffffff;
    --line: #e9e2dc;
    --text: #2d2d2d;
    --muted: #7c7671;
    --orange: #ea8a3a;
    --orange-dark: #d87522;
    --orange-soft: #fff4e9;
    --green: #278553;
    --green-soft: #edf9f1;
    /* Keep shared-theme tokens available to included Seller navigation. */
    --blue: #4b7fc1;
    --blue-soft: #eef5ff;
    --purple: #8b67b4;
    --purple-soft: #f3edfb;
    --red: #c94f45;
    --red-soft: #fff1ef;
    --shadow: 0 8px 24px rgba(54,39,25,.045);
}
* { box-sizing: border-box; }
body {
    margin: 0;
    min-height: 100vh;
    background: var(--bg);
    color: var(--text);
    font-family: "Segoe UI", Arial, sans-serif;
}
.app { min-height: 100vh; }
/* Keep Dashboard in document flow. The shared search bar is fixed on some
   seller layouts, so its actual position determines the content clearance. */
body .app > main.main.dashboard-main {
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
    padding: var(--dashboard-header-offset, 70px) 0 28px !important;
    overflow: visible !important;
    background: transparent !important;
}
.dashboard-search-shell { min-width: 0; }
body .app > main.dashboard-main > .dashboard-content {
    width: 100% !important;
    max-width: 1240px !important;
    margin: 0 auto !important;
    padding: 8px 26px 24px !important;
}
.page-heading { margin-bottom: 18px; }
.page-heading h1 {
    margin: 0;
    color: var(--text);
    font-size: 2.1rem;
    font-weight: 800;
    letter-spacing: -.7px;
}
.dashboard-error {
    margin-bottom: 16px;
    padding: 11px 13px;
    border: 1px solid #efc0c0;
    border-radius: 9px;
    background: var(--red-soft);
    color: var(--red);
    font-size: .76rem;
    line-height: 1.45;
}
.stats {
    display: grid;
    grid-template-columns: repeat(4,minmax(0,1fr));
    gap: 14px;
    margin-bottom: 18px;
}
.stat-card,
.panel {
    border: 1px solid var(--line);
    background: var(--panel);
    box-shadow: var(--shadow);
}
.stat-card {
    min-width: 0;
    min-height: 92px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 17px;
    border-radius: 12px;
}
/* Match the Seller Profile banner: warm cream blending into soft orange.
   All four cards share the same color, with no colored accent stripe. */
.stats .stat-card {
    border: 1px solid #eedfce;
    background: linear-gradient(108deg, #fffdfb 0%, #fff7ee 46%, #ffe9d5 100%);
    box-shadow: 0 8px 24px rgba(54,39,25,.045);
}
.stat-copy { min-width: 0; }
.stat-label {
    margin-bottom: 7px;
    color: var(--muted);
    font-size: .69rem;
    font-weight: 700;
}
.stat-value {
    color: #49372a;
    font-size: 1.18rem;
    font-weight: 900;
    overflow-wrap: anywhere;
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
.panel-title {
    margin: 0;
    color: #403731;
    font-size: .82rem;
    font-weight: 800;
}
.chart-panel { margin-bottom: 18px; }
.chart-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 12px;
}
.chart-copy p {
    margin: 4px 0 0;
    color: #928981;
    font-size: .65rem;
}
.periods {
    display: flex;
    gap: 4px;
    padding: 3px;
    border: 1px solid #ece4dd;
    border-radius: 10px;
    background: #f7f4f1;
}
.periods button {
    min-height: 30px;
    padding: 4px 9px;
    border: 0;
    border-radius: 7px;
    background: transparent;
    color: #77706a;
    font: inherit;
    font-size: .66rem;
    font-weight: 700;
    cursor: pointer;
}
.periods button:hover { color: var(--orange-dark); background: var(--orange-soft); }
.periods button.active {
    background: #fff;
    color: var(--orange-dark);
    box-shadow: 0 2px 6px rgba(76,50,32,.08);
}
.chart-shell {
    width: 100%;
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: thin;
}
.chart {
    width: 100%;
    min-width: 660px;
    height: 190px;
    display: block;
}
.chart-grid { stroke: #ece5df; stroke-dasharray: 2 3; }
.chart-line {
    fill: none;
    stroke: var(--orange);
    stroke-width: 2.4;
    stroke-linecap: round;
    stroke-linejoin: round;
}
.chart-point { fill: #fff; stroke: var(--orange); stroke-width: 2; }
.chart text { fill: #9a9189; font: 10px "Segoe UI", sans-serif; }
.lower-grid {
    display: grid;
    grid-template-columns: minmax(0,1.05fr) minmax(0,.95fr);
    gap: 14px;
}
.list { margin-top: 10px; }
.list-item {
    min-height: 58px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border-top: 1px solid #f0eae5;
    font-size: .76rem;
}
.list-item:first-child { border-top: 0; }
.item-copy { min-width: 0; }
.item-title {
    margin-bottom: 4px;
    color: #403731;
    font-weight: 800;
    overflow-wrap: anywhere;
}
.item-meta {
    color: #958c84;
    font-size: .65rem;
    line-height: 1.45;
}
.status {
    flex: 0 0 auto;
    padding: 5px 8px;
    border-radius: 999px;
    background: var(--orange-soft);
    color: var(--orange-dark);
    font-size: .61rem;
    font-weight: 800;
    white-space: nowrap;
}
.status.low-stock { background: var(--red-soft); color: var(--red); }
.empty-list {
    padding: 20px 0 5px;
    color: #999087;
    font-size: .7rem;
    line-height: 1.5;
}
.reviews-panel { margin-top: 14px; }
.reviews-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 12px;
}
.reviews-heading-copy p {
    margin: 4px 0 0;
    color: #999087;
    font-size: .65rem;
    line-height: 1.45;
}
.review-summary {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 9px;
    flex-wrap: wrap;
}
.review-score {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 9px;
    border-radius: 999px;
    background: #fff7e8;
    color: #b87817;
    font-size: .68rem;
    font-weight: 800;
}
.review-score .star { color: #e3a22e; font-size: .88rem; }
.review-count { color: #8d857e; font-size: .65rem; font-weight: 700; }
.review-list {
    display: grid;
    grid-template-columns: repeat(3,minmax(0,1fr));
    gap: 10px;
}
.review-card {
    min-width: 0;
    padding: 13px;
    border: 1px solid #eee7e1;
    border-radius: 10px;
    background: #fdfbf9;
}
.review-card-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
}
.review-product {
    min-width: 0;
    overflow: hidden;
    color: #39332f;
    font-size: .72rem;
    font-weight: 800;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.review-stars { display: inline-flex; gap: 1px; flex-shrink: 0; }
.review-star { color: #d6d0c9; font-size: .72rem; line-height: 1; }
.review-star.on { color: #e3a22e; }
.review-meta {
    margin-top: 5px;
    color: #999087;
    font-size: .61rem;
    line-height: 1.45;
}
.review-text {
    margin-top: 9px;
    display: -webkit-box;
    overflow: hidden;
    color: #625b55;
    font-size: .67rem;
    line-height: 1.55;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 3;
}
.review-no-comment { color: #aaa29b; font-style: italic; }
@media (max-width: 1080px) {
    .stats { grid-template-columns: repeat(2,minmax(0,1fr)); }
    .review-list { grid-template-columns: repeat(2,minmax(0,1fr)); }
}
@media (max-width: 820px) {
    .lower-grid { grid-template-columns: 1fr; }
}
@media (max-width: 840px) {
    body .app > main.main.dashboard-main {
        width: 100% !important;
        margin-left: 0 !important;
        padding-top: var(--dashboard-header-offset, 76px) !important;
    }
    body .app > main.dashboard-main > .dashboard-content {
        max-width: none !important;
        padding: 8px 16px 24px !important;
    }
}
@media (max-width: 760px) {
    body .app > main.dashboard-main > .dashboard-content {
        padding: 8px 12px 24px !important;
    }
    .page-heading h1 { font-size: 1.8rem; }
    .chart-header { align-items: flex-start; flex-direction: column; }
    .periods { width: 100%; }
    .periods button { flex: 1; min-height: 36px; }
    .reviews-header { align-items: flex-start; flex-direction: column; }
    .review-summary { justify-content: flex-start; }
    .review-list { grid-template-columns: 1fr; }
}
@media (max-width: 520px) {
    .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .stat-card { min-height: 86px; padding: 12px; gap: 7px; }
    .stat-icon { width: 32px; height: 32px; flex-basis: 32px; }
    .stat-icon svg { width: 18px; height: 18px; }
    .stat-label { font-size: .65rem; line-height: 1.3; }
    .stat-value { font-size: clamp(.91rem, 3.6vw, 1.13rem); }
    .panel { padding: 14px; }
    .list-item { align-items: flex-start; padding: 11px 0; }
}
</style>
</head>
<body>
  <div class="app">
    <?php require __DIR__ . '/seller_nav.php'; ?>
    <main class="main dashboard-main">
      <div class="dashboard-search-shell" id="dashboardSearchShell"><?php require __DIR__ . '/seller_search.php'; ?></div>
      <section class="content dashboard-content">
        <div class="page-heading">
          <h1>Dashboard</h1>
        </div>
        <?php if ($dashboardError !== ''): ?>
          <div class="dashboard-error"><?= htmlspecialchars($dashboardError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <section class="stats" aria-label="Store summary">
          <article class="stat-card">
            <div class="stat-copy">
              <div class="stat-label">Total Products</div>
              <div class="stat-value"><?php echo $totalProducts; ?></div>
            </div>
            <div class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5v-9Z"/><path d="M3 7.5 12 12l9-4.5M12 12v9"/></svg></div>
          </article>
          <article class="stat-card">
            <div class="stat-copy">
              <div class="stat-label">Pending Orders</div>
              <div class="stat-value"><?php echo $pendingOrders; ?></div>
            </div>
            <div class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M5 8h14l1 12H4L5 8Z"/><path d="M9 9V6a3 3 0 0 1 6 0v3"/></svg></div>
          </article>
          <article class="stat-card">
            <div class="stat-copy">
              <div class="stat-label">Unread Messages</div>
              <div class="stat-value"><?php echo $unreadMessages; ?></div>
            </div>
            <div class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M20 11.5a8 8 0 0 1-8 8 8.4 8.4 0 0 1-3.6-.8L4 20l1.3-4.4A8.4 8.4 0 0 1 4.5 12a8 8 0 0 1 8-8A7.5 7.5 0 0 1 20 11.5Z"/><path d="M8.5 12h7"/></svg></div>
          </article>
          <article class="stat-card">
            <div class="stat-copy">
              <div class="stat-label">Total Sales</div>
              <div class="stat-value">₱<?php echo number_format($totalSales, 2); ?></div>
            </div>
            <div class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="m3 17 6-6 4 4 8-8"/><path d="M15 7h6v6"/></svg></div>
          </article>
        </section>
        <section class="panel chart-panel">
          <div class="chart-header">
            <div class="chart-copy">
              <h2 class="panel-title">Sales Overview</h2>
              <p>Completed-order sales only</p>
            </div>
            <div class="periods">
              <button class="active" type="button" data-period="daily">Daily</button>
              <button type="button" data-period="weekly">Weekly</button>
              <button type="button" data-period="monthly">Monthly</button>
            </div>
          </div>
          <div class="chart-shell"><svg class="chart" viewBox="0 0 900 180" role="img" aria-label="Sales overview chart">
            <g id="chart-grid">
              <line class="chart-grid" x1="35" y1="20" x2="880" y2="20"/>
              <line class="chart-grid" x1="35" y1="60" x2="880" y2="60"/>
              <line class="chart-grid" x1="35" y1="100" x2="880" y2="100"/>
              <line class="chart-grid" x1="35" y1="140" x2="880" y2="140"/>
            </g>
            <g id="y-axis-labels">
              <text x="7" y="24">0</text>
              <text x="7" y="64">0</text>
              <text x="7" y="104">0</text>
              <text x="15" y="144">0</text>
              <text x="27" y="174">0</text>
            </g>
            <polyline id="chart-line" class="chart-line" points="35,140 175,140 315,140 455,140 595,140 735,140 875,140"/>
            <g id="chart-points" class="chart-point"></g>
            <g id="x-axis-labels"></g>
          </svg></div>
        </section>
        <section class="lower-grid">
          <article class="panel">
            <h2 class="panel-title">Recent Orders</h2>
            <div class="list">
              <?php if (count($recentOrders) === 0): ?>
                <div class="empty-list">No orders yet.</div>
              <?php else: ?>
                <?php foreach ($recentOrders as $order): ?>
                  <div class="list-item">
                    <div class="item-copy">
                      <div class="item-title">
                        Order #<?php echo htmlspecialchars((string)$order['order_id'], ENT_QUOTES, 'UTF-8'); ?>
                      </div>
                      <div class="item-meta">
                        <?php echo htmlspecialchars((string)$order['buyer_name'], ENT_QUOTES, 'UTF-8'); ?>
                        · <?php echo (int)$order['item_count']; ?> item<?php echo (int)$order['item_count'] === 1 ? '' : 's'; ?>
                        · ₱<?php echo number_format((float)$order['total_amount'], 2); ?>
                      </div>
                    </div>
                    <span class="status"><?php echo htmlspecialchars(formatStatus((string)$order['order_status']), ENT_QUOTES, 'UTF-8'); ?></span>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </article>
          <article class="panel">
            <h2 class="panel-title">Low Stock Products</h2>
            <div class="list">
              <?php if (count($lowStockProducts) === 0): ?>
                <div class="empty-list">No low-stock products right now.</div>
              <?php else: ?>
                <?php foreach ($lowStockProducts as $product): ?>
                  <div class="list-item">
                    <div class="item-copy">
                      <div class="item-title"><?php echo htmlspecialchars((string)$product['product_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                      <div class="item-meta">
                        <?php $availableStock = (int)($product['available_stock'] ?? 0); ?>
                        <?php echo $availableStock; ?> unit<?php echo $availableStock === 1 ? '' : 's'; ?> available
                      </div>
                    </div>
                    <span class="status low-stock">Low</span>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </article>
        </section>
        <section class="panel reviews-panel" aria-label="Recent customer reviews">
          <div class="reviews-header">
            <div class="reviews-heading-copy">
              <h2 class="panel-title">Recent Reviews</h2>
              <p>Latest feedback from buyers who completed an order.</p>
            </div>
            <div class="review-summary">
              <?php if ($totalReviews > 0): ?>
                <span class="review-score"><span class="star">★</span><?php echo number_format($averageRating, 1); ?> / 5</span>
                <span class="review-count"><?php echo $totalReviews; ?> review<?php echo $totalReviews === 1 ? '' : 's'; ?></span>
              <?php else: ?>
                <span class="review-count">No ratings yet</span>
              <?php endif; ?>
            </div>
          </div>
          <?php if (!$reviewsReady): ?>
            <div class="empty-list">Reviews are not available yet.</div>
          <?php elseif (count($recentReviews) === 0): ?>
            <div class="empty-list">No reviews yet. Completed orders can be reviewed by buyers.</div>
          <?php else: ?>
            <div class="review-list">
              <?php foreach ($recentReviews as $review): ?>
                <?php
                  $rating = max(1, min(5, (int)$review['rating']));
                  $reviewText = trim((string)($review['review_text'] ?? ''));
                  $reviewTimestamp = !empty($review['created_at']) ? strtotime((string)$review['created_at']) : false;
                  $reviewDate = $reviewTimestamp ? date('M d, Y', $reviewTimestamp) : '';
                ?>
                <article class="review-card">
                  <div class="review-card-top">
                    <div class="review-product" title="<?php echo htmlspecialchars((string)$review['product_name'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$review['product_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="review-stars" aria-label="<?php echo $rating; ?> out of 5 stars">
                      <?php for ($star = 1; $star <= 5; $star++): ?><span class="review-star<?php echo $star <= $rating ? ' on' : ''; ?>">★</span><?php endfor; ?>
                    </div>
                  </div>
                  <div class="review-meta">
                    <?php echo htmlspecialchars((string)$review['buyer_name'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ((int)($review['order_id'] ?? 0) > 0): ?> · Order #<?php echo (int)$review['order_id']; ?><?php endif; ?>
                    <?php if ($reviewDate !== ''): ?> · <?php echo htmlspecialchars($reviewDate, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                  </div>
                  <?php if ($reviewText !== ''): ?>
                    <div class="review-text"><?php echo htmlspecialchars($reviewText, ENT_QUOTES, 'UTF-8'); ?></div>
                  <?php else: ?>
                    <div class="review-text review-no-comment">Rated without a written comment.</div>
                  <?php endif; ?>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      </section>
    </main>
  </div>
  <script>
    // Align Dashboard immediately beneath the shared Seller search/header.
    // Measure only topbars, not the sidebar or Dashboard cards.
    function syncDashboardHeaderSpace() {
      const main = document.querySelector('main.dashboard-main');
      const searchShell = document.getElementById('dashboardSearchShell');
      if (!main || !searchShell) return;
      const inFlowSearch = searchShell.getBoundingClientRect().height >= 36;
      let externalHeaderBottom = 0;
      let searchHeaderBottom = 0;
      const candidates = document.querySelectorAll(
        'body header, body [role="banner"], .mobile-header, .mobile-topbar, .seller-topbar, .topbar, .top-bar, .nav-topbar'
      );
      for (const element of candidates) {
        if (element.closest('.dashboard-content') || searchShell.contains(element)) continue;
        const style = window.getComputedStyle(element);
        if (style.display === 'none' || style.visibility === 'hidden') continue;
        if (style.position !== 'fixed' && style.position !== 'sticky') continue;
        const rect = element.getBoundingClientRect();
        if (rect.width < window.innerWidth * .53 || rect.height < 35 || rect.height > 145) continue;
        if (rect.top > 15 || rect.bottom < 35 || rect.bottom > 180) continue;
        externalHeaderBottom = Math.max(externalHeaderBottom, rect.bottom);
      }
      // Some shared templates use div-based headers rather than <header>.
      for (const element of searchShell.querySelectorAll('*')) {
        const style = window.getComputedStyle(element);
        if (style.position !== 'fixed' && style.position !== 'sticky') continue;
        const rect = element.getBoundingClientRect();
        if (rect.width < window.innerWidth * .53 || rect.height < 35 || rect.height > 145) continue;
        if (rect.top > 15 || rect.bottom < 35 || rect.bottom > 180) continue;
        searchHeaderBottom = Math.max(searchHeaderBottom, rect.bottom);
      }
      const fallback = window.innerWidth <= 840 ? 76 : 70;
      const clearance = inFlowSearch
        ? externalHeaderBottom
        : (Math.max(externalHeaderBottom, searchHeaderBottom) || fallback);
      main.style.setProperty('--dashboard-header-offset', `${Math.ceil(clearance)}px`);
    }
    syncDashboardHeaderSpace();
    window.addEventListener('load', syncDashboardHeaderSpace);
    window.addEventListener('resize', syncDashboardHeaderSpace);
    const chartData = <?php echo json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const chartLine = document.getElementById('chart-line');
    const chartPoints = document.getElementById('chart-points');
    const xAxisLabels = document.getElementById('x-axis-labels');
    const yAxisLabels = document.getElementById('y-axis-labels');
    function formatChartValue(value) {
      const number = Number(value || 0);
      if (number >= 1000000) {
        return `₱${(number / 1000000).toFixed(1)}M`;
      }
      if (number >= 1000) {
        return `₱${(number / 1000).toFixed(1)}k`;
      }
      return `₱${Math.round(number)}`;
    }
    function renderChart(period) {
      const data = chartData[period] || { labels: [], values: [] };
      const labels = data.labels || [];
      const values = (data.values || []).map((value) => Number(value || 0));
      if (!labels.length) {
        return;
      }
      const left = 35;
      const right = 875;
      const top = 20;
      const bottom = 140;
      const usableWidth = right - left;
      const usableHeight = bottom - top;
      const maxValue = Math.max(...values, 1);
      const stepX = labels.length > 1 ? usableWidth / (labels.length - 1) : 0;
      const points = values.map((value, index) => {
        const x = labels.length > 1 ? left + (index * stepX) : (left + right) / 2;
        const y = bottom - ((value / maxValue) * usableHeight);
        return { x, y, value };
      });
      chartLine.setAttribute(
        'points',
        points.map((point) => `${point.x},${point.y}`).join(' ')
      );
      chartPoints.innerHTML = points
        .map((point) => `<circle cx="${point.x}" cy="${point.y}" r="3"></circle>`)
        .join('');
      xAxisLabels.innerHTML = labels
        .map((label, index) => {
          const x = labels.length > 1 ? left + (index * stepX) : (left + right) / 2;
          const offset = String(label).length > 4 ? 10 : 12;
          return `<text x="${x - offset}" y="174">${String(label)}</text>`;
        })
        .join('');
      const tickValues = [
        maxValue,
        maxValue * 0.75,
        maxValue * 0.5,
        maxValue * 0.25,
        0
      ];
      yAxisLabels.innerHTML = tickValues
        .map((value, index) => {
          const y = [24, 64, 104, 144, 174][index];
          return `<text x="2" y="${y}">${formatChartValue(value)}</text>`;
        })
        .join('');
    }
    document.querySelectorAll('.periods button').forEach((button) => {
      button.addEventListener('click', () => {
        document.querySelectorAll('.periods button').forEach((item) => item.classList.remove('active'));
        button.classList.add('active');
        renderChart(button.dataset.period);
      });
    });
    renderChart('daily');
  </script>
</body>
</html>
