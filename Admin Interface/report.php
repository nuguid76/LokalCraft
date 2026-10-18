<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../config/database.php';
date_default_timezone_set('Asia/Manila');
// Align MySQL TIMESTAMP dates to Philippine time.
if (!$conn->query("SET time_zone = '+08:00'")) error_log('Unable to set report session time zone to Asia/Manila.');
$activePage = 'reports';
function srEsc($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function srMoney($value): string { return '₱' . number_format((float)$value, 2); }
function srCount($value): string { return number_format((int)$value); }
function srDate($value): string { $time = $value ? strtotime((string)$value) : false; return $time ? date('M j, Y · g:i A', $time) : '—'; }
function srStatus($value): string {
    $raw = strtolower(trim((string)$value));
    return match($raw) { 'confirmed' => 'Accepted', 'delivered' => 'Completed', 'canceled' => 'Cancelled', default => ucwords(str_replace('_', ' ', $raw ?: 'Unknown')) };
}
function srRun(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Report query could not be prepared.');
    try {
        if ($types !== '') $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) throw new RuntimeException('Report query failed.');
        $result = $stmt->get_result();
        if (!$result) throw new RuntimeException('Report records are unavailable.');
        return $result->fetch_all(MYSQLI_ASSOC);
    } finally { $stmt->close(); }
}
function srPeriodDate($value, string $fallback): string {
    if (!is_string($value) || $value === '') return $fallback;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
}
function srOptionalAmount($value): ?float {
    if ($value === null || $value === '') return null;
    if (!is_scalar($value) || !is_numeric($value)) throw new DomainException('Costs and expenses must be valid non-negative amounts.');
    $n = (float)$value;
    if (!is_finite($n) || $n < 0 || $n > 1000000000) throw new DomainException('Costs and expenses must be between 0 and 1,000,000,000.');
    return round($n, 2);
}
function srCsvSafe($value) {
    if (is_int($value) || is_float($value) || $value === null) return $value;
    $string = (string)$value;
    if (preg_match('/^[\s\x00-\x1f]*[=+@\-]/u', $string)) return "'" . $string;
    return $string;
}
function srCsvRow($handle, array $values): void { fputcsv($handle, array_map('srCsvSafe', $values), ',', '"', ''); }
function srPayMethod($value): string {
    $s = strtolower(trim((string)$value));
    if ($s === '') return 'Not recorded';
    if (str_contains($s, 'cash') || $s === 'cod') return 'Cash';
    if (str_contains($s, 'qr')) return 'QR Ph';
    if (str_contains($s, 'gcash') || str_contains($s, 'maya')) return 'GCash / Maya';
    if (str_contains($s, 'paymongo')) return 'Online payment';
    return 'Other (' . ucwords(str_replace('_', ' ', $s)) . ')';
}
function srPercent(float $value, float $total): string { return $total > 0 ? number_format($value * 100 / $total, 1) . '%' : '0.0%'; }
function srPaymentStatus($value): string {
    return match (strtolower(trim((string)$value))) {
        'paid', 'completed', 'succeeded', 'success' => 'Paid',
        'refunded' => 'Refunded',
        'failed' => 'Payment failed',
        'cancelled', 'canceled' => 'Payment cancelled',
        'pending', 'unpaid', 'awaiting_payment' => 'Awaiting payment',
        default => 'No payment recorded'
    };
}
function srInventoryStatus(array $product): string {
    if (strtolower(trim((string)($product['status'] ?? ''))) === 'archived') return 'Archived / Removed';
    $available = max(0, (int)$product['stock'] - (int)($product['reserved_stock'] ?? 0));
    if ($available === 0) return 'Out of stock';
    if ($available <= 5) return 'Low stock';
    return 'In stock';
}
function srFriendlyDate($date, bool $withTime = false): string {
    $stamp = $date ? strtotime((string)$date) : false;
    return $stamp ? date($withTime ? 'M j, Y g:i A' : 'M j, Y', $stamp) : 'Not recorded';
}
function srFilenamePart($value, string $fallback): string {
    $part = trim(preg_replace('/[^\p{L}\p{N}._ -]+/u', '', (string)$value) ?? '');
    $part = preg_replace('/[\s.]+/u', '_', $part) ?? '';
    $part = trim($part, '_-');
    return $part === '' ? $fallback : (function_exists('mb_substr') ? mb_substr($part, 0, 65, 'UTF-8') : substr($part, 0, 65));
}
function srCsvFilename(array $seller, string $ext): string {
    $business = srFilenamePart($seller['business_name'] ?? '', 'Business');
    $last = srFilenamePart($seller['lastname'] ?? '', 'Seller');
    $first = srFilenamePart($seller['firstname'] ?? '', 'Unknown');
    return $business . '_' . $last . ',' . $first . '.' . $ext;
}

$adminId = 0;
foreach (['admin_id','user_id','logged_id','id'] as $key) {
    $candidate = filter_var($_SESSION[$key] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if ($candidate !== false) { $adminId = (int)$candidate; break; }
}
if ($adminId < 1) { header('Location: ../login.php'); exit; }
try {
    $adminRecords = srRun($conn, 'SELECT firstname, lastname, AdminOrNot, account_status FROM users WHERE id = ? LIMIT 1', 'i', [$adminId]);
    $admin = $adminRecords[0] ?? null;
    if (!$admin || (int)$admin['AdminOrNot'] !== 1 || in_array(strtolower((string)$admin['account_status']), ['disabled','inactive','suspended','banned'], true)) {
        http_response_code(403); exit('Access denied.');
    }
    $adminName = trim((string)$admin['firstname'] . ' ' . (string)$admin['lastname']) ?: 'Admin';
    $userName = $adminName;
} catch (Throwable $error) {
    error_log('LokalCraft reports admin verification: ' . $error->getMessage());
    http_response_code(503); exit('Could not verify administrator access.');
}
header('Cache-Control: private, no-store, max-age=0');
$exportFormat = (string)($_GET['export'] ?? '');
$exporting = in_array($exportFormat, ['csv','xlsx'], true);
$errorMessage = '';
$sellers = [];
$seller = null;
$sellerId = 0;
$from = srPeriodDate($_GET['from'] ?? null, date('Y-m-01'));
$to = srPeriodDate($_GET['to'] ?? null, date('Y-m-d'));
$cogs = null;
$expenses = null;
$orders = [];
$products = [];
$inventorySummary = ['total'=>0,'in_stock'=>0,'low'=>0,'out'=>0,'archived'=>0,'available_units'=>0,'reserved_units'=>0];
$daily = [];
$monthly = [];
$hours = array_fill(0, 24, ['amount'=>0.0, 'orders'=>0]);
$halves = ['1–15'=>0.0,'16–end'=>0.0];
$statusCounts = ['Pending'=>0,'Accepted'=>0,'Processing'=>0,'Completed'=>0,'Cancellation Requested'=>0,'Cancelled'=>0,'Declined'=>0,'Expired'=>0,'Disputed'=>0,'Other'=>0];
$paymentCounts = ['Cash'=>0,'QR Ph'=>0,'GCash / Maya'=>0,'Online payment'=>0,'Not recorded'=>0];
$paymentStatusCounts = ['Paid'=>0,'Awaiting payment'=>0,'Refunded'=>0,'Payment failed'=>0,'Payment cancelled'=>0,'No payment recorded'=>0];
$summary = ['gross_sales'=>0.0,'completed_orders'=>0,'platform_fees'=>0.0,'fees_unavailable'=>0,'average_order'=>0.0,'orders_in_period'=>0,'paid_orders'=>0,'saved'=>0,'cart'=>0,'units'=>0,'gross_profit'=>null,'net_profit'=>null];
$peakHour = null;
$endExclusive = null;
try {
    $sellers = srRun($conn, "SELECT u.id, u.firstname, u.lastname, u.account_status,
      COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.firstname, u.lastname)), ''), 'Unnamed Seller') AS seller_name,
      COALESCE((SELECT NULLIF(TRIM(b.businessname), '') FROM businessinfo b WHERE b.user_id = u.id ORDER BY b.business_id DESC LIMIT 1), 'No business name recorded') AS business_name,
      COALESCE((SELECT NULLIF(TRIM(b.businessname), '') FROM businessinfo b WHERE b.user_id = u.id ORDER BY b.business_id DESC LIMIT 1), NULLIF(TRIM(CONCAT_WS(' ', u.firstname, u.lastname)), ''), CONCAT('Seller #', u.id)) AS display_name
      FROM users u WHERE u.SellerOrNot = 1 ORDER BY display_name ASC, u.id ASC");
    $requestedId = filter_var($_GET['seller_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    $sellerId = $requestedId === false || $requestedId === null ? (int)($sellers[0]['id'] ?? 0) : (int)$requestedId;
    foreach ($sellers as $entry) { if ((int)$entry['id'] === $sellerId) { $seller = $entry; break; } }
    if ($sellerId > 0 && !$seller) throw new DomainException('Please select an existing seller.');
    if ($from === '' || $to === '') throw new DomainException('Please use valid start and end dates.');
    $fromObject = new DateTimeImmutable($from);
    $toObject = new DateTimeImmutable($to);
    if ($fromObject > $toObject) throw new DomainException('Start date must not be after end date.');
    if ($fromObject->diff($toObject)->days > 366) throw new DomainException('Please select a period of up to 367 days.');
    $endExclusive = $toObject->modify('+1 day')->format('Y-m-d');
    $cogs = srOptionalAmount($_GET['cogs'] ?? null);
    $expenses = srOptionalAmount($_GET['expenses'] ?? null);
    if ($seller) {
        // One latest payment and commission row per order prevents duplicate sales totals.
        $orders = srRun($conn, "SELECT o.order_id, o.buyer_id, o.total_amount, o.order_status, o.ordered_at, o.updated_at,
          COALESCE(NULLIF(TRIM(CONCAT_WS(' ', buyer.firstname, buyer.lastname)), ''), CONCAT('Buyer #', o.buyer_id)) AS buyer_name,
          pay.payment_id, pay.payment_method, pay.payment_provider, pay.payment_status, pay.amount AS payment_amount, pay.payment_reference, pay.paid_at,
          oa.payment_option AS agreed_payment_option,
          pc.commission_id, pc.commission_status, pc.commission_amount, pc.seller_net_amount, pc.commission_rate
          FROM orders o LEFT JOIN users buyer ON buyer.id = o.buyer_id
          LEFT JOIN payments pay ON pay.payment_id = (SELECT p2.payment_id FROM payments p2 WHERE p2.order_id = o.order_id ORDER BY CASE LOWER(TRIM(p2.payment_status)) WHEN 'refunded' THEN 2 WHEN 'paid' THEN 1 ELSE 0 END DESC, p2.payment_id DESC LIMIT 1)
          LEFT JOIN order_arrangements oa ON oa.arrangement_id = (SELECT MAX(oa2.arrangement_id) FROM order_arrangements oa2 WHERE oa2.order_id = o.order_id)
          LEFT JOIN platform_commissions pc ON pc.commission_id = (SELECT MAX(pc2.commission_id) FROM platform_commissions pc2 WHERE pc2.order_id = o.order_id AND pc2.seller_id = o.seller_id)
          WHERE o.seller_id = ? AND o.ordered_at >= ? AND o.ordered_at < ?
          ORDER BY o.ordered_at DESC, o.order_id DESC", 'iss', [$sellerId, $from, $endExclusive]);
        foreach ($orders as $o) {
            $summary['orders_in_period']++;
            $status = srStatus($o['order_status']);
            $normalized = array_key_exists($status, $statusCounts) ? $status : 'Other';
            $statusCounts[$normalized]++;
            $method = srPayMethod(($o['payment_method'] ?? '') ?: ($o['agreed_payment_option'] ?? ''));
            $paymentCounts[$method] = ($paymentCounts[$method] ?? 0) + 1;
            $paymentStatus = strtolower(trim((string)$o['payment_status']));
            $payBucket = srPaymentStatus($paymentStatus);
            $paymentStatusCounts[$payBucket]++;
            if ($payBucket === 'Paid') $summary['paid_orders']++;
            if ($status !== 'Completed') continue;
            $amount = (float)$o['total_amount'];
            $summary['gross_sales'] += $amount;
            $summary['completed_orders']++;
            if (strtolower((string)$o['commission_status']) === 'earned') $summary['platform_fees'] += (float)$o['commission_amount'];
            else $summary['fees_unavailable']++;
            $stamp = strtotime((string)$o['ordered_at']);
            if (!$stamp) continue;
            $day = date('Y-m-d', $stamp);
            $month = date('Y-m', $stamp);
            $hour = (int)date('G', $stamp);
            $daily[$day] = ($daily[$day] ?? 0) + $amount;
            $monthly[$month] = ($monthly[$month] ?? 0) + $amount;
            $hours[$hour]['amount'] += $amount;
            $hours[$hour]['orders']++;
            $halfKey = (int)date('j', $stamp) <= 15 ? '1–15' : '16–end';
            $halves[$halfKey] += $amount;
        }
        $summary['average_order'] = $summary['completed_orders'] ? $summary['gross_sales'] / $summary['completed_orders'] : 0;
        if ($cogs !== null) $summary['gross_profit'] = $summary['gross_sales'] - $cogs;
        if ($cogs !== null && $expenses !== null) $summary['net_profit'] = $summary['gross_sales'] - $cogs - $expenses - $summary['platform_fees'];
        ksort($daily);
        ksort($monthly);
        for ($h=0; $h<24; $h++) {
            if ($hours[$h]['orders'] > 0 && ($peakHour === null || $hours[$h]['orders'] > $hours[$peakHour]['orders'])) $peakHour = $h;
        }
        // Subqueries aggregate their own tables before joining, preventing cart/saved/sales multiplication.
        $products = srRun($conn, "SELECT p.product_id, p.product_name, p.craft_category, p.status, p.stock, COALESCE(p.reserved_stock,0) AS reserved_stock, p.created_at,
          COALESCE(s.units, 0) AS units_sold, COALESCE(s.revenue, 0) AS product_revenue,
          COALESCE(c.cart_units, 0) AS cart_units, COALESCE(c.cart_buyers, 0) AS cart_buyers,
          COALESCE(w.saved_count, 0) AS saved_count
          FROM products p
          LEFT JOIN (SELECT oi.product_id, SUM(oi.quantity) AS units,
            SUM(COALESCE(oi.agreed_unit_price, oi.unit_price, 0) * oi.quantity) AS revenue
            FROM order_items oi JOIN orders o ON o.order_id = oi.order_id
            WHERE o.seller_id = ? AND LOWER(TRIM(o.order_status)) IN ('completed','delivered') AND o.ordered_at >= ? AND o.ordered_at < ? GROUP BY oi.product_id) s ON s.product_id = p.product_id
          LEFT JOIN (SELECT c0.product_id, SUM(c0.quantity) AS cart_units, COUNT(DISTINCT c0.buyer_id) AS cart_buyers FROM cart c0 INNER JOIN products cp ON cp.product_id=c0.product_id WHERE cp.seller_id = ? GROUP BY c0.product_id) c ON c.product_id = p.product_id
          LEFT JOIN (SELECT w0.product_id, COUNT(DISTINCT w0.buyer_id) AS saved_count FROM saved_products w0 INNER JOIN products wp ON wp.product_id=w0.product_id WHERE wp.seller_id = ? GROUP BY w0.product_id) w ON w.product_id = p.product_id
          WHERE p.seller_id = ? ORDER BY units_sold DESC, product_revenue DESC, p.product_name ASC", 'issiii', [$sellerId,$from,$endExclusive,$sellerId,$sellerId,$sellerId]);
        foreach ($products as $p) {
            $summary['saved'] += (int)$p['saved_count'];
            $summary['cart'] += (int)$p['cart_units'];
            $summary['units'] += (int)$p['units_sold'];
        }
        foreach ($products as &$p) {
            $p['stock_available'] = strtolower(trim((string)$p['status'])) === 'archived' ? 0 : max(0, (int)$p['stock'] - (int)$p['reserved_stock']);
            $p['stock_label'] = srInventoryStatus($p);
            $inventorySummary['total']++;
            $inventorySummary['available_units'] += $p['stock_available'];
            $inventorySummary['reserved_units'] += (int)$p['reserved_stock'];
            if ($p['stock_label'] === 'Archived / Removed') $inventorySummary['archived']++;
            elseif ($p['stock_label'] === 'Out of stock') $inventorySummary['out']++;
            elseif ($p['stock_label'] === 'Low stock') $inventorySummary['low']++;
            else $inventorySummary['in_stock']++;
        }
        unset($p);
    }
} catch (Throwable $error) {
    error_log('LokalCraft individual seller report: ' . $error->getMessage());
    $errorMessage = $error instanceof DomainException ? $error->getMessage() : 'Could not load this seller report. Please check the database tables and server log.';
}
$topProducts = array_slice(array_values(array_filter($products, static fn($p) => (int)$p['units_sold'] > 0)), 0, 6);
$slowProducts = $products;
usort($slowProducts, static fn($a, $b) => ((int)$a['units_sold'] <=> (int)$b['units_sold']) ?: ((float)$a['product_revenue'] <=> (float)$b['product_revenue']) ?: strcmp((string)$a['product_name'], (string)$b['product_name']));
$slowProducts = array_slice($slowProducts, 0, 6);
$popularProducts = $products;
usort($popularProducts, static fn($a, $b) => (((int)$b['cart_units']+(int)$b['saved_count']) <=> ((int)$a['cart_units']+(int)$a['saved_count'])) ?: strcmp((string)$a['product_name'], (string)$b['product_name']));
$popularProducts = array_slice($popularProducts, 0, 6);
function srSheet(string $title): array { return ['title'=>$title,'rows'=>[[$title]],'headings'=>[]]; }
function srSheetHeader(array &$sheet, array $cells): void { $sheet['headings'][] = count($sheet['rows']) + 1; $sheet['rows'][] = $cells; }
function srSheetRow(array &$sheet, array $cells): void { $sheet['rows'][] = $cells; }
function srReportSheets(array $seller, string $from, string $to, array $summary, ?float $cogs, ?float $expenses, array $daily, array $monthly, array $halves, array $hours, array $products, array $inventorySummary, array $orders, array $statusCounts, array $paymentCounts, array $paymentStatusCounts): array {
    $sheets = [];
    $overview = srSheet('SELLER SALES AND REVENUE');
    srSheetRow($overview, ['Seller name', $seller['seller_name']]);
    srSheetRow($overview, ['Business name', $seller['business_name']]);
    srSheetRow($overview, ['Report period: From', srFriendlyDate($from)]);
    srSheetRow($overview, ['Report period: To', srFriendlyDate($to)]);
    srSheetRow($overview, ['Sales counted by', 'Date the order was placed; includes completed orders only']);
    srSheetRow($overview, []);
    srSheetHeader($overview, ['SALES SUMMARY', 'Value']);
    foreach ([
        ['Total sales from completed orders (PHP)', round($summary['gross_sales'],2)],
        ['Number of completed orders', $summary['completed_orders']],
        ['Average spent per completed order (PHP)', round($summary['average_order'],2)],
        ['Recorded platform fees earned (PHP)', round($summary['platform_fees'],2)],
        ['Completed orders with missing earned-fee record', $summary['fees_unavailable']],
        ['Seller proceeds before product costs and expenses (PHP)', round($summary['gross_sales']-$summary['platform_fees'],2)],
        ['Entered cost of goods sold (PHP)', $cogs],
        ['Estimated gross profit (PHP)', $summary['gross_profit'] === null ? 'Cannot calculate: enter product costs' : round($summary['gross_profit'],2)],
        ['Entered other business expenses (PHP)', $expenses],
        ['Estimated net profit (PHP)', $summary['net_profit'] === null ? 'Cannot calculate: enter product costs and expenses' : round($summary['net_profit'],2)],
        ['Total orders placed in the chosen period', $summary['orders_in_period']],
        ['Orders with confirmed paid payment record', $summary['paid_orders']]
    ] as $row) srSheetRow($overview,$row);
    $sheets[] = $overview;
    $trend = srSheet('SALES TRENDS');
    srSheetHeader($trend,['Monthly sales period','Completed sales (PHP)']);
    foreach ($monthly as $month=>$amount) srSheetRow($trend,[srFriendlyDate($month.'-01'),round($amount,2)]);
    srSheetRow($trend,[]);
    srSheetHeader($trend,['First / second half of month (combined for selected dates)','Completed sales (PHP)']);
    foreach ($halves as $half=>$amount) srSheetRow($trend,[$half==='1–15'?'Days 1 to 15':'Days 16 to month end',round($amount,2)]);
    srSheetRow($trend,[]);
    srSheetHeader($trend,['Date order was placed','Completed sales (PHP)']);
    foreach ($daily as $day=>$amount) srSheetRow($trend,[srFriendlyDate($day),round($amount,2)]);
    srSheetRow($trend,[]);
    srSheetHeader($trend,['Hour order was placed (Philippine time)','Completed orders','Completed sales (PHP)']);
    foreach ($hours as $hour=>$amount) srSheetRow($trend,[date('g:i A',strtotime(sprintf('%02d:00',$hour))),$amount['orders'],round($amount['amount'],2)]);
    $sheets[] = $trend;
    $perf=srSheet('PRODUCT SALES AND INTEREST');
    srSheetRow($perf,['Sales columns use completed orders during the selected date range. Cart and saved counts show their current state.']);
    srSheetHeader($perf,['Product','Category','Listing status','Units sold','Product sales (PHP)','Units in carts now','Buyers with item in cart','Buyers who saved item']);
    foreach ($products as $p) srSheetRow($perf,[$p['product_name'],$p['craft_category'],srStatus($p['status']),(int)$p['units_sold'],round((float)$p['product_revenue'],2),(int)$p['cart_units'],(int)$p['cart_buyers'],(int)$p['saved_count']]);
    $sheets[]=$perf;
    $inventory=srSheet('INVENTORY AND CURRENT STOCK');
    srSheetRow($inventory,['Current inventory is a snapshot. Product listing date does not prove when stock was first added.']);
    srSheetRow($inventory,['Low stock alert threshold','1 to 5 available units; 0 means out of stock']);
    srSheetHeader($inventory,['Stock summary','Value']);
    foreach ([['Products in catalog',$inventorySummary['total']],['Available for sale (units)',$inventorySummary['available_units']],['Units reserved',$inventorySummary['reserved_units']],['Products with low stock',$inventorySummary['low']],['Products out of stock',$inventorySummary['out']],['Products archived or removed',$inventorySummary['archived']]] as $entry) srSheetRow($inventory,$entry);
    srSheetRow($inventory,[]);
    srSheetHeader($inventory,['Product','Category','Date first listed','Total units in stock','Units reserved','Available units to sell','Current stock alert']);
    foreach ($products as $p) srSheetRow($inventory,[$p['product_name'],$p['craft_category'],srFriendlyDate($p['created_at']), (int)$p['stock'],(int)$p['reserved_stock'], (int)$p['stock_available'], $p['stock_label']]);
    $sheets[]=$inventory;
    $payment=srSheet('ORDER AND PAYMENT BREAKDOWN');
    srSheetHeader($payment,['Order status','Number of orders']);
    foreach ($statusCounts as $status=>$count) srSheetRow($payment,[$status,$count]);
    srSheetRow($payment,[]);
    srSheetHeader($payment,['Payment method selected / recorded','Number of orders']);
    foreach ($paymentCounts as $method=>$count) srSheetRow($payment,[$method,$count]);
    srSheetRow($payment,[]);
    srSheetHeader($payment,['Actual payment status','Number of orders']);
    foreach ($paymentStatusCounts as $status=>$count) srSheetRow($payment,[$status,$count]);
    $sheets[]=$payment;
    $transactions=srSheet('ORDER AND PAYMENT RECORDS');
    srSheetHeader($transactions,['Order no.','Order date','Buyer','Order status','Order total (PHP)','Payment method recorded','Payment option agreed','Payment status','Payment amount (PHP)','Date paid','Payment reference','Platform fee (PHP)']);
    foreach ($orders as $o) srSheetRow($transactions,[(int)$o['order_id'],srFriendlyDate($o['ordered_at'],true),$o['buyer_name'],srStatus($o['order_status']),round((float)$o['total_amount'],2),srPayMethod($o['payment_method']),srPayMethod($o['agreed_payment_option']),srPaymentStatus($o['payment_status']),$o['payment_amount']===null?'':round((float)$o['payment_amount'],2),$o['paid_at']?srFriendlyDate($o['paid_at'],true):'Not recorded',(string)($o['payment_reference']??''),strtolower((string)($o['commission_status']??''))==='earned'?round((float)$o['commission_amount'],2):'Not recorded']);
    $sheets[]=$transactions;
    $notes=srSheet('HOW TO READ THIS REPORT');
    foreach ([
        'Only the selected seller is included in this report.',
        'From and To are inclusive; dates refer to the day the order was placed, not necessarily the date it was completed.',
        'Sales amount counts only completed orders and uses the stored order total.',
        'Product revenue is calculated from sold units and their recorded agreed/unit prices. It may differ from order totals.',
        'Estimated gross and net profit require entered business costs; these are not automatically known from the database.',
        'Seller proceeds minus recorded platform fees are not the same as profit.',
        'A Cash or QR Ph payment option means the method was selected, not necessarily that the payment was collected.',
        'Payment status is shown separately so paid and unpaid orders are not confused.',
        'Cart and saved-product counts show current quantities, not historical events within the date range.',
        'Product first listed is the catalog creation date; it is not a verified first stock date.',
        'Out-of-stock/current low-stock alerts are based on units on hand minus reserved units, excluding archived products.',
        'The existing product records do not contain reliable dates for past stockouts or restocks; this report does not estimate them.'
    ] as $note) srSheetRow($notes,[$note]);
    $sheets[]=$notes;
    return $sheets;
}
function srXml(string $text): string {
    return htmlspecialchars(preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/u','',$text) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
function srColumnLetter(int $index): string {
    $s=''; for ($index++;$index>0;$index=intdiv($index-1,26)) $s=chr(65+($index-1)%26).$s;
    return $s;
}
function srXlsxXml(array $sheet): string {
    $rows=$sheet['rows'];
    $cols=[];
    foreach ($rows as $row) foreach ($row as $i=>$v) {
        $len=function_exists('mb_strlen')?mb_strlen((string)$v,'UTF-8'):strlen((string)$v);
        $cols[$i] = max($cols[$i]??9, min(55, $len+3));
    }
    ksort($cols);
    $xml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="19"/><cols>';
    foreach ($cols as $i=>$w) $xml.='<col min="'.($i+1).'" max="'.($i+1).'" width="'.$w.'" customWidth="1"/>';
    $xml.='</cols><sheetData>';
    foreach ($rows as $ri=>$row) {
        $n=$ri+1;
        $isHead=in_array($n,$sheet['headings'],true);
        $xml.='<row r="'.$n.'"'.($n===1?' ht="29" customHeight="1"':'').'>';
        foreach ($row as $i=>$val) {
            if ($val===null || $val==='') continue;
            $ref=srColumnLetter($i).$n;
            $style=$n===1?2:($isHead?1:(is_float($val)?3:0));
            if (is_int($val)||is_float($val)) $xml.='<c r="'.$ref.'" s="'.$style.'"><v>'.srXml((string)$val).'</v></c>';
            else $xml.='<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.srXml((string)$val).'</t></is></c>';
        }
        $xml.='</row>';
    }
    return $xml.'</sheetData><pageMargins left="0.4" right="0.4" top="0.6" bottom="0.6" header="0.3" footer="0.3"/></worksheet>';
}
function srZipFiles(array $files): string {
    // Minimal standards-compliant ZIP (XLSX), compatible without ZipArchive extension.
    $body=''; $dir=''; $count=0;
    foreach ($files as $name=>$content) {
        $crc=(int)sprintf('%u',crc32($content));
        $packed=function_exists('gzdeflate')?gzdeflate($content,6):false;
        $compressed=$packed!==false;
        $data=$compressed?$packed:$content;
        $method=$compressed?8:0;
        $size=strlen($data); $original=strlen($content); $nlen=strlen($name); $offset=strlen($body);
        $body.=pack('VvvvvvVVVvv',0x04034b50,20,0,$method,0,0,$crc,$size,$original,$nlen,0).$name.$data;
        $dir.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,0,$method,0,0,$crc,$size,$original,$nlen,0,0,0,0,0,$offset).$name;
        $count++;
    }
    return $body.$dir.pack('VvvvvVVv',0x06054b50,0,0,$count,$count,strlen($dir),strlen($body),0);
}
function srXlsxWorkbook(array $sheets): string {
    $types='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $workbook='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $files=['_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>'];
    foreach ($sheets as $i=>$sheet) {
        $num=$i+1;
        $types.='<Override PartName="/xl/worksheets/sheet'.$num.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $workbook.='<sheet name="'.srXml(substr(ucwords(strtolower($sheet['title'])),0,31)).'" sheetId="'.$num.'" r:id="rId'.$num.'"/>';
        $rels.='<Relationship Id="rId'.$num.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$num.'.xml"/>';
        $files['xl/worksheets/sheet'.$num.'.xml']=srXlsxXml($sheet);
    }
    $types.='</Types>';
    $workbook.='</sheets></workbook>';
    $rels.='<Relationship Id="rId'.(count($sheets)+1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $styles='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="3"><font><sz val="11"/><color rgb="FF30302F"/><name val="Aptos"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Aptos"/></font><font><b/><sz val="15"/><color rgb="FF915321"/><name val="Aptos"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE8924B"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" applyAlignment="1"><alignment wrapText="1" vertical="center"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment wrapText="1"/></xf><xf numFmtId="0" fontId="2" fillId="0" borderId="0" applyFont="1"/><xf numFmtId="4" fontId="0" fillId="0" borderId="0" applyNumberFormat="1"/></cellXfs></styleSheet>';
    $files['[Content_Types].xml']=$types;
    $files['xl/workbook.xml']=$workbook;
    $files['xl/_rels/workbook.xml.rels']=$rels;
    $files['xl/styles.xml']=$styles;
    return srZipFiles($files);
}
if ($exporting) {
    if ($errorMessage !== '' || !$seller) {
        http_response_code(422); header('Content-Type: text/plain; charset=utf-8');
        echo $errorMessage ?: 'Please choose a seller before exporting.'; exit;
    }
    $sheets=srReportSheets($seller,$from,$to,$summary,$cogs,$expenses,$daily,$monthly,$halves,$hours,$products,$inventorySummary,$orders,$statusCounts,$paymentCounts,$paymentStatusCounts);
    $filename=srCsvFilename($seller,$exportFormat);
    $ascii=preg_replace('/[^\\x20-\\x7E]/','_', $filename);
    header('Content-Disposition: attachment; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($filename));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    if ($exportFormat === 'xlsx') {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        echo srXlsxWorkbook($sheets); exit;
    }
    header('Content-Type: text/csv; charset=UTF-8');
    echo "\xEF\xBB\xBF";
    $out=fopen('php://output','w');
    if (!$out) exit;
    // CSV has no fonts, column widths or row heights. Each section has clear headers.
    foreach ($sheets as $section) {
        foreach ($section['rows'] as $cells) srCsvRow($out,$cells);
        srCsvRow($out,[]);
        srCsvRow($out,[]);
    }
    fclose($out); exit;
}
$exportParams=['seller_id'=>$sellerId,'from'=>$from,'to'=>$to,'cogs'=>$cogs===null?'':$cogs,'expenses'=>$expenses===null?'':$expenses];
$exportQuery=http_build_query($exportParams+['export'=>'csv']);
$excelQuery=http_build_query($exportParams+['export'=>'xlsx']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>LokalCraft | Individual Seller Reports</title>
<style>
:root{--sr-bg:#f7f6f4;--sr-card:#fff;--sr-text:#30302f;--sr-muted:#89837e;--sr-line:#e9e5e0;--sr-orange:#e8924b;--sr-shadow:0 5px 18px rgba(49,35,19,.038)}
*{box-sizing:border-box}html,body{max-width:100%;min-width:0}body{margin:0;background:var(--sr-bg);color:var(--sr-text);font-family:Inter,"Segoe UI",Arial,sans-serif;font-size:14px}.page{display:flex;min-width:0;min-height:100vh}.page>.content{flex:1;min-width:0;max-width:100%;padding:30px 29px 43px}
.sr-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:21px}.sr-heading h1{margin:0 0 6px;font-size:clamp(1.5rem,2.2vw,1.95rem);letter-spacing:-.65px}.sr-heading p{margin:0;color:var(--sr-muted);font-size:.84rem;line-height:1.55}.sr-pill{display:inline-flex;padding:7px 10px;border:1px solid #eedccc;border-radius:9px;background:#fff5ea;color:#aa7548;font-size:.71rem;font-weight:700;white-space:nowrap}
.sr-panel{background:var(--sr-card);border:1px solid var(--sr-line);border-radius:15px;box-shadow:var(--sr-shadow);min-width:0}.sr-filter-panel{padding:18px;margin-bottom:19px}.sr-filter-grid{display:grid;grid-template-columns:minmax(180px,1.7fr) repeat(2,minmax(128px,1fr)) repeat(2,minmax(110px,.8fr)) auto;gap:11px;align-items:end}.sr-field{display:flex;flex-direction:column;gap:6px;min-width:0}.sr-field label{font-size:.72rem;color:#827e78;font-weight:650}.sr-field input,.sr-field select{width:100%;min-width:0;min-height:41px;border:1px solid #e7e3de;border-radius:9px;background:#fbfaf8;padding:8px 10px;color:#373431;font:inherit;font-size:.81rem;outline:none}.sr-field input:focus,.sr-field select:focus{background:#fff;border-color:#dda06b;box-shadow:0 0 0 3px #fff0e1}.sr-btn{border:0;border-radius:9px;padding:11px 17px;min-height:41px;cursor:pointer;font:inherit;font-size:.8rem;font-weight:750;white-space:nowrap;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:7px}.sr-btn.primary{background:var(--sr-orange);color:#fff}.sr-btn.primary:hover{background:#d87b35}.sr-btn.secondary{background:#fff6ed;color:#ac703d;border:1px solid #f1d8c0}.sr-btn:focus-visible{outline:3px solid #f6b879;outline-offset:2px}.sr-filter-foot{margin-top:11px;color:#97918b;font-size:.72rem;line-height:1.5}.sr-alert{padding:12px 14px;margin-bottom:16px;border:1px solid #eabfbc;border-radius:11px;background:#fff2f1;color:#a34843;font-size:.81rem}
.sr-section-title{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:21px 0 12px}.sr-section-title h2{margin:0;font-size:1.03rem;letter-spacing:-.2px}.sr-section-title small{font-size:.72rem;color:#928d88}.sr-export{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.sr-stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.sr-stat{padding:16px 16px 15px;min-height:118px;display:flex;justify-content:space-between;gap:10px}.sr-stat label{display:block;color:#89837e;font-size:.75rem;line-height:1.35}.sr-stat strong{display:block;font-size:clamp(1.1rem,1.8vw,1.65rem);margin-top:10px;line-height:1.13;letter-spacing:-.45px;overflow-wrap:anywhere}.sr-stat small{display:block;font-size:.67rem;color:#9a9692;margin-top:8px;line-height:1.4}.sr-stat-icon{display:grid;place-items:center;width:33px;height:33px;flex:0 0 33px;border-radius:10px;color:#ba8756;background:#fff2e6;font-size:1rem;font-weight:750}.sr-stat:nth-child(2) .sr-stat-icon{background:#eaf3fc;color:#5c88ba}.sr-stat:nth-child(3) .sr-stat-icon{background:#e9f7ed;color:#4a9b70}.sr-stat:nth-child(4) .sr-stat-icon{background:#f6eafa;color:#ad7fc0}
.sr-footnote{margin:11px 0 0;padding:11px 14px;border:1px solid #f0e5d9;border-radius:10px;background:#fffbf6;color:#8d7865;font-size:.75rem;line-height:1.6}.sr-two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:14px}.sr-block{padding:18px;min-width:0}.sr-block h3{margin:0 0 5px;font-size:.9rem}.sr-block .sr-sub{margin:0 0 14px;color:#96918a;font-size:.72rem;line-height:1.4}.sr-list{display:flex;flex-direction:column;gap:0}.sr-data-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid #f0ede9;font-size:.78rem;color:#746f6a}.sr-data-row:last-child{border-bottom:0}.sr-data-row strong{font-weight:700;text-align:right;color:#403d3a;overflow-wrap:anywhere}.sr-bar-line{display:grid;grid-template-columns:92px minmax(0,1fr) 93px;gap:11px;align-items:center;margin:13px 0;font-size:.75rem;color:#716c68}.sr-bar{height:9px;overflow:hidden;border-radius:99px;background:#f3eee9}.sr-bar>span{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,#f4b67b,#df8c47)}.sr-bar-line strong{text-align:right;font-weight:750;font-size:.74rem;color:#45403c}.sr-table-wrap{overflow-x:auto;width:100%;-webkit-overflow-scrolling:touch}.sr-table{width:100%;border-collapse:collapse;text-align:left}.sr-table th{font-size:.7rem;font-weight:700;color:#99918c;white-space:nowrap;background:#fbfaf8}.sr-table td{font-size:.77rem;color:#645e59}.sr-table td,.sr-table th{padding:11px 10px;border-bottom:1px solid #f0edea;vertical-align:top}.sr-table tbody tr:last-child td{border-bottom:0}.sr-table td strong{color:#393430}.sr-right{text-align:right}.sr-wrap{overflow-wrap:anywhere}.sr-muted{color:#a19a94;font-size:.7rem}.sr-chip{display:inline-flex;padding:4px 7px;border-radius:6px;background:#f6f2ef;color:#806d5e;font-size:.67rem;font-weight:700;white-space:nowrap}.sr-chip.good{background:#eaf6ee;color:#45855f}.sr-chip.warn{background:#fff3df;color:#af803d}.sr-chip.bad{background:#ffeded;color:#ba6963}.sr-empty{padding:21px 8px;color:#97918b;font-size:.77rem;text-align:center}.sr-label-row{display:flex;justify-content:space-between;gap:10px;margin:8px 0;color:#8d8278;font-size:.71rem}.sr-divider{border-top:1px solid #f2eeea;margin:14px 0}.sr-mini-metrics{display:flex;gap:18px;flex-wrap:wrap}.sr-mini-metrics>div{min-width:105px}.sr-mini-metrics strong{display:block;color:#49413a;font-size:1.1rem}.sr-mini-metrics span{display:block;color:#98908b;font-size:.69rem;margin-top:4px}.sr-progress{margin:10px 0}.sr-progress-track{background:#f4f0eb;height:7px;border-radius:99px;overflow:hidden;margin:5px 0}.sr-progress-track>span{height:100%;display:block;border-radius:99px;background:#ebb17c}.sr-bottom{margin-top:18px;text-align:right;color:#a5a09a;font-size:.7rem}
.sr-report-business,.sr-report-period{display:block;margin-top:5px;color:#827d78;font-size:.75rem}.sr-report-business{color:#625b53;font-weight:650}.sr-stock-block{margin-top:14px}.sr-inner-title{margin:0 0 12px!important}.sr-stock-table{min-width:650px}.sr-stock-table th,.sr-stock-table td{white-space:nowrap}.sr-stock-table .sr-wrap{min-width:150px;max-width:240px;white-space:normal}.sr-export .sr-btn{min-width:120px}
@media(max-width:600px){
.sr-stock-table{display:block;min-width:0;width:100%}.sr-stock-table thead{display:none}.sr-stock-table tbody{display:block}.sr-stock-table tbody tr{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 12px;padding:13px 0;border-bottom:1px solid #eee9e4}.sr-stock-table tbody tr:last-child{border-bottom:0}.sr-stock-table tbody td{display:flex;flex-direction:column;align-items:flex-start;justify-content:flex-start;gap:3px;min-width:0;max-width:none!important;padding:7px 0;border:0;font-size:.74rem;white-space:normal;text-align:left!important;overflow-wrap:anywhere}.sr-stock-table tbody td:before{display:block;font-size:.63rem;line-height:1.3;font-weight:650;color:#a49c93}.sr-current-stock tbody td:nth-child(1):before{content:"Product"}.sr-current-stock tbody td:nth-child(2):before{content:"First listed"}.sr-current-stock tbody td:nth-child(3):before{content:"Total stock"}.sr-current-stock tbody td:nth-child(4):before{content:"Reserved units"}.sr-current-stock tbody td:nth-child(5):before{content:"Available units"}.sr-current-stock tbody td:nth-child(6):before{content:"Stock alert"}.sr-stock-table tbody td[colspan]{grid-column:1/-1}
}
@media(max-width:1400px){.sr-filter-grid{grid-template-columns:minmax(180px,2fr) repeat(2,minmax(125px,1fr)) auto}.sr-field.optional{grid-row:2}.sr-filter-grid .sr-btn{grid-column:auto}}
@media(max-width:1100px){.sr-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.sr-filter-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.sr-field.optional{grid-row:auto}}
@media(max-width:840px){.page>.content{padding:92px 16px 38px}.sr-two{grid-template-columns:1fr}.sr-heading{margin-bottom:15px}}
@media(max-width:600px){.page>.content{padding:88px 12px 34px}.sr-heading{flex-direction:column;gap:8px}.sr-filter-panel{padding:14px}.sr-filter-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.sr-field.seller{grid-column:1/-1}.sr-filter-grid .sr-btn{grid-column:1/-1}.sr-section-title{align-items:flex-start}.sr-export{width:100%}.sr-export .sr-btn{flex:1}.sr-stat-grid{gap:9px}.sr-stat{padding:12px;min-height:107px}.sr-stat strong{font-size:clamp(1rem,4vw,1.3rem)}.sr-stat-icon{width:26px;height:26px;flex-basis:26px;font-size:.84rem}.sr-stat label{font-size:.69rem}.sr-stat small{font-size:.63rem}.sr-block{padding:14px}.sr-bar-line{grid-template-columns:75px minmax(0,1fr) 78px;gap:6px;font-size:.7rem}.sr-bar-line strong{font-size:.68rem}.sr-table td,.sr-table th{padding:10px 7px}.sr-bar-line strong{overflow-wrap:anywhere}}
@media(max-width:360px){.sr-stat strong{font-size:.99rem}.sr-stat-icon{display:none}.sr-field label{font-size:.68rem}}
</style>
</head>
<body>
<div class="page">
<?php include __DIR__ . '/admin_nav.php'; ?>
<main class="content">
<header class="sr-heading"><div><h1>Reports &amp; Analytics</h1><p>Individual Seller • Sales, revenue, product performance, orders and payments</p></div><span class="sr-pill">Individual seller report</span></header>
<?php if ($errorMessage !== ''): ?><div class="sr-alert" role="alert"><?= srEsc($errorMessage) ?></div><?php endif; ?>
<form class="sr-panel sr-filter-panel" method="get" action="report.php" aria-label="Individual seller report filters">
<div class="sr-filter-grid">
<div class="sr-field seller"><label for="seller_id">Select seller</label><select id="seller_id" name="seller_id" required <?= !$sellers ? 'disabled' : '' ?>><?php if (!$sellers): ?><option value="">No registered sellers</option><?php endif; ?><?php foreach ($sellers as $entry): ?><option value="<?= (int)$entry['id'] ?>" <?= (int)$entry['id'] === $sellerId ? 'selected' : '' ?>><?= srEsc($entry['business_name']) ?> · <?= srEsc($entry['seller_name']) ?></option><?php endforeach; ?></select></div>
<div class="sr-field"><label for="reportFrom">Period from</label><input id="reportFrom" type="date" name="from" required value="<?= srEsc($from) ?>"></div>
<div class="sr-field"><label for="reportTo">Period to</label><input id="reportTo" type="date" name="to" required value="<?= srEsc($to) ?>"></div>
<div class="sr-field optional"><label for="reportCogs">Period COGS (optional)</label><input id="reportCogs" type="number" step="0.01" min="0" name="cogs" placeholder="Not recorded" value="<?= $cogs === null ? '' : srEsc(number_format($cogs,2,'.','')) ?>"></div>
<div class="sr-field optional"><label for="reportExpenses">Other expenses (optional)</label><input id="reportExpenses" type="number" step="0.01" min="0" name="expenses" placeholder="Not recorded" value="<?= $expenses === null ? '' : srEsc(number_format($expenses,2,'.','')) ?>"></div>
<button class="sr-btn primary" type="submit" <?= !$sellers ? 'disabled' : '' ?>>View report →</button>
</div><div class="sr-filter-foot">Select one seller and a date range. Optional COGS and expenses are used only for estimated profit, not saved to your database. Maximum range: 367 days.</div>
</form>
<?php if ($seller && !$errorMessage): ?>
<div class="sr-section-title"><div><h2><?= srEsc($seller['seller_name']) ?></h2><small class="sr-report-business">Business: <?= srEsc($seller['business_name']) ?></small><small class="sr-report-period">Report period: <?= srEsc(srFriendlyDate($from)) ?> to <?= srEsc(srFriendlyDate($to)) ?></small></div><div class="sr-export"><a class="sr-btn secondary" href="report.php?<?= srEsc($exportQuery) ?>">↓ Download CSV</a><a class="sr-btn primary" href="report.php?<?= srEsc($excelQuery) ?>">↓ Download Excel</a></div></div>
<div class="sr-stat-grid">
<article class="sr-panel sr-stat"><div><label>Gross Completed Sales</label><strong><?= srMoney($summary['gross_sales']) ?></strong><small>Completed orders only</small></div><span class="sr-stat-icon">₱</span></article>
<article class="sr-panel sr-stat"><div><label>Average Order Value</label><strong><?= srMoney($summary['average_order']) ?></strong><small>Gross sales ÷ completed orders</small></div><span class="sr-stat-icon">↗</span></article>
<article class="sr-panel sr-stat"><div><label>Completed Orders</label><strong><?= srCount($summary['completed_orders']) ?></strong><small><?= srCount($summary['units']) ?> product units sold</small></div><span class="sr-stat-icon">✓</span></article>
<article class="sr-panel sr-stat"><div><label>Earned Platform Fees</label><strong><?= srMoney($summary['platform_fees']) ?></strong><small><?= srCount($summary['fees_unavailable']) ?> completed orders without earned fee</small></div><span class="sr-stat-icon">▤</span></article>
<article class="sr-panel sr-stat"><div><label>After Recorded Fees</label><strong><?= srMoney($summary['gross_sales']-$summary['platform_fees']) ?></strong><small>Sales less recorded fees · not business profit</small></div><span class="sr-stat-icon">₱</span></article>
<article class="sr-panel sr-stat"><div><label>Estimated Gross Profit</label><strong><?= $summary['gross_profit'] === null ? '—' : srMoney($summary['gross_profit']) ?></strong><small><?= $cogs === null ? 'Enter COGS to calculate' : 'Gross sales minus entered COGS' ?></small></div><span class="sr-stat-icon">∑</span></article>
<article class="sr-panel sr-stat"><div><label>Estimated Net Profit</label><strong><?= $summary['net_profit'] === null ? '—' : srMoney($summary['net_profit']) ?></strong><small><?= $summary['net_profit'] === null ? 'Enter COGS and expenses' : 'Less entered costs, expenses and recorded fees' ?></small></div><span class="sr-stat-icon">↗</span></article>
<article class="sr-panel sr-stat"><div><label>Paid Orders</label><strong><?= srCount($summary['paid_orders']) ?></strong><small>Recorded payment record marked paid</small></div><span class="sr-stat-icon">✓</span></article>
</div>
<p class="sr-footnote"><strong>How these numbers work:</strong> Completed-order sales use the order placement date (`ordered_at`), since the schema has no completion timestamp. Gross/net profit needs cost-of-goods-sold and other expenses; the existing product costing records are not linked to sold product IDs. Estimated net profit subtracts only recorded <em>earned</em> commissions plus your entered costs. Missing commission records, refunds or unrecorded costs can affect accuracy.</p>
<div class="sr-section-title"><h2>1. Sales &amp; Revenue Trends</h2><small>Completed-order sales only</small></div>
<div class="sr-two">
<section class="sr-panel sr-block"><h3>Daily Sales Trend</h3><p class="sr-sub">Sales by order placement date within the selected period</p>
<?php if (!$daily): ?><div class="sr-empty">No completed sales in this period.</div><?php else: $maxDay = max($daily); $dailyShow = count($daily)>15 ? array_slice($daily,-15,null,true) : $daily; foreach ($dailyShow as $day=>$amount): ?><div class="sr-bar-line"><span><?= srEsc(date('M j',strtotime($day))) ?></span><div class="sr-bar"><span style="width:<?= $maxDay > 0 ? round($amount/$maxDay*100,2) : 0 ?>%"></span></div><strong><?= srMoney($amount) ?></strong></div><?php endforeach; ?><?php if(count($daily)>15): ?><p class="sr-muted">Showing latest 15 sales dates; downloads include every recorded date.</p><?php endif; endif; ?>
</section>
<section class="sr-panel sr-block"><h3>Monthly &amp; Half-Month Sales</h3><p class="sr-sub">Monthly performance and kinsenas breakdown within selected dates</p>
<?php if (!$monthly): ?><div class="sr-empty">No monthly sales data yet.</div><?php else: $maxMonth = max($monthly); foreach ($monthly as $month=>$amount): ?><div class="sr-bar-line"><span><?= srEsc(date('M Y',strtotime($month.'-01'))) ?></span><div class="sr-bar"><span style="width:<?= $maxMonth > 0 ? round($amount/$maxMonth*100,2) : 0 ?>%"></span></div><strong><?= srMoney($amount) ?></strong></div><?php endforeach; endif; ?>
<div class="sr-divider"></div><div class="sr-mini-metrics"><div><strong><?= srMoney($halves['1–15']) ?></strong><span>Days 1–15 (combined across months)</span></div><div><strong><?= srMoney($halves['16–end']) ?></strong><span>Days 16–end (combined across months)</span></div></div>
</section>
<section class="sr-panel sr-block"><h3>Most Active Selling Hours</h3><p class="sr-sub">Completed orders by the hour they were placed (Manila time). Peak hour: <strong><?= $peakHour === null ? 'No data' : srEsc(date('g A',strtotime(sprintf('%02d:00',$peakHour)))) ?></strong></p>
<?php $hourKeys=range(0,23); usort($hourKeys,fn($a,$b)=>($hours[$b]['orders']<=>$hours[$a]['orders']) ?: ($hours[$b]['amount']<=>$hours[$a]['amount'])); $topHours=array_slice(array_filter($hourKeys,fn($h)=>$hours[$h]['orders']>0),0,6); ?>
<?php if (!$topHours): ?><div class="sr-empty">No completed order times yet.</div><?php else: foreach ($topHours as $h): ?><div class="sr-data-row"><span><?= srEsc(date('g A',strtotime(sprintf('%02d:00',$h)))) ?> – <?= srEsc(date('g A',strtotime(sprintf('%02d:00',($h+1)%24)))) ?></span><strong><?= srCount($hours[$h]['orders']) ?> orders · <?= srMoney($hours[$h]['amount']) ?></strong></div><?php endforeach; endif; ?>
</section>
<section class="sr-panel sr-block"><h3>Sales Summary</h3><p class="sr-sub">Quick financial snapshot of the selected seller</p>
<div class="sr-list"><div class="sr-data-row"><span>Completed-order gross sales</span><strong><?= srMoney($summary['gross_sales']) ?></strong></div><div class="sr-data-row"><span>Earned platform fees recorded</span><strong><?= srMoney($summary['platform_fees']) ?></strong></div><div class="sr-data-row"><span>Entered COGS</span><strong><?= $cogs === null ? 'Not entered' : srMoney($cogs) ?></strong></div><div class="sr-data-row"><span>Entered other expenses</span><strong><?= $expenses === null ? 'Not entered' : srMoney($expenses) ?></strong></div><div class="sr-data-row"><span>Estimated net profit</span><strong><?= $summary['net_profit'] === null ? 'Not calculable yet' : srMoney($summary['net_profit']) ?></strong></div><div class="sr-data-row"><span>Average completed-order value</span><strong><?= srMoney($summary['average_order']) ?></strong></div></div>
</section>
</div>
<div class="sr-section-title"><h2>2. Product Performance</h2><small>Sales within selected dates · cart and saved figures are current quantities</small></div>
<div class="sr-two">
<section class="sr-panel sr-block"><h3>Top-Selling Products</h3><p class="sr-sub">Products ranked by completed units sold</p><div class="sr-table-wrap"><table class="sr-table"><thead><tr><th>Product</th><th class="sr-right">Units</th><th class="sr-right">Item Sales</th></tr></thead><tbody><?php foreach($topProducts as $p): ?><tr><td class="sr-wrap"><strong><?= srEsc($p['product_name']) ?></strong><div class="sr-muted">#<?= (int)$p['product_id'] ?></div></td><td class="sr-right"><?= srCount($p['units_sold']) ?></td><td class="sr-right"><?= srMoney($p['product_revenue']) ?></td></tr><?php endforeach; ?><?php if(!$topProducts): ?><tr><td colspan="3" class="sr-empty">No completed product sales in this period.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="sr-panel sr-block"><h3>Low-Selling Products</h3><p class="sr-sub">Products with the fewest completed sales in this period</p><div class="sr-table-wrap"><table class="sr-table"><thead><tr><th>Product</th><th class="sr-right">Units</th><th class="sr-right">Current Stock</th></tr></thead><tbody><?php foreach($slowProducts as $p): ?><tr><td class="sr-wrap"><strong><?= srEsc($p['product_name']) ?></strong><div class="sr-muted"><?= (int)$p['units_sold']===0?'No sales in period':srEsc($p['status']) ?></div></td><td class="sr-right"><?= srCount($p['units_sold']) ?></td><td class="sr-right"><?= srCount($p['stock']) ?></td></tr><?php endforeach; ?><?php if(!$slowProducts): ?><tr><td colspan="3" class="sr-empty">No products in this seller's catalog.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="sr-panel sr-block"><h3>Saved &amp; Cart Interest</h3><p class="sr-sub">Currently in buyer carts and saved lists (not a historical event count)</p><div class="sr-mini-metrics"><div><strong><?= srCount($summary['cart']) ?></strong><span>Units in carts now</span></div><div><strong><?= srCount($summary['saved']) ?></strong><span>Current saved-product entries</span></div></div><div class="sr-divider"></div><div class="sr-table-wrap"><table class="sr-table"><thead><tr><th>Product</th><th class="sr-right">Cart Units</th><th class="sr-right">Saved</th></tr></thead><tbody><?php foreach ($popularProducts as $p): ?><tr><td class="sr-wrap"><strong><?= srEsc($p['product_name']) ?></strong></td><td class="sr-right"><?= srCount($p['cart_units']) ?></td><td class="sr-right"><?= srCount($p['saved_count']) ?></td></tr><?php endforeach; ?><?php if(!$popularProducts): ?><tr><td colspan="3" class="sr-empty">No product interest records available.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="sr-panel sr-block"><h3>Product Report Notes</h3><p class="sr-sub">How to interpret product sales and interest</p><div class="sr-list"><div class="sr-data-row"><span>Products in seller catalog</span><strong><?= srCount(count($products)) ?></strong></div><div class="sr-data-row"><span>Completed-order item units</span><strong><?= srCount($summary['units']) ?></strong></div><div class="sr-data-row"><span>Top product basis</span><strong>Units sold</strong></div><div class="sr-data-row"><span>Low-selling basis</span><strong>Selected period only</strong></div><div class="sr-data-row"><span>Saved / cart time basis</span><strong>Current snapshot</strong></div><div class="sr-data-row"><span>Full product export</span><strong>Included in CSV and Excel</strong></div></div><p class="sr-footnote">Item-level revenue uses recorded agreed unit prices where available. It may not exactly equal order gross totals due to adjustments and order-level pricing.</p></section>
</div>
<div class="sr-section-title"><h2>3. Inventory &amp; Stock Reports</h2><small>Current inventory snapshot · Low stock at 1–5 available units</small></div>
<div class="sr-stat-grid">
<article class="sr-panel sr-stat"><div><label>Products in Catalog</label><strong><?= srCount($inventorySummary['total']) ?></strong><small>Including archived or removed products</small></div><span class="sr-stat-icon">▤</span></article>
<article class="sr-panel sr-stat"><div><label>Low Stock Alerts</label><strong><?= srCount($inventorySummary['low']) ?></strong><small>1 to 5 units available to sell</small></div><span class="sr-stat-icon">!</span></article>
<article class="sr-panel sr-stat"><div><label>Out of Stock</label><strong><?= srCount($inventorySummary['out']) ?></strong><small>0 units available to sell</small></div><span class="sr-stat-icon">×</span></article>
<article class="sr-panel sr-stat"><div><label>Products In Stock</label><strong><?= srCount($inventorySummary['in_stock']) ?></strong><small>More than 5 units available to sell</small></div><span class="sr-stat-icon">✓</span></article>
</div>
<p class="sr-footnote"><strong>Inventory:</strong> Available units = current stock minus reserved units (minimum 0). Stock alerts exclude archived products. The listing date comes from the product record, but the existing database does not have reliable historical restock or stockout dates. No additional SQL or database changes are required.</p>
<section class="sr-panel sr-block sr-stock-block">
<div class="sr-section-title sr-inner-title"><h3>Current Inventory and Low-Stock Alerts</h3><small><?= srCount($inventorySummary['available_units']) ?> available units · <?= srCount($inventorySummary['reserved_units']) ?> reserved units</small></div>
<div class="sr-table-wrap"><table class="sr-table sr-stock-table sr-current-stock"><thead><tr><th>Product</th><th>Date Listed</th><th class="sr-right">Total Stock</th><th class="sr-right">Reserved</th><th class="sr-right">Available</th><th>Stock Alert</th></tr></thead><tbody>
<?php foreach($products as $p): ?>
<?php $stockColor=$p['stock_label']==='Out of stock'?'bad':($p['stock_label']==='Low stock'?'warn':($p['stock_label']==='In stock'?'good':'')); ?>
<tr><td class="sr-wrap"><strong><?= srEsc($p['product_name']) ?></strong><div class="sr-muted"><?= srEsc($p['craft_category']) ?></div></td><td><?= srEsc(srFriendlyDate($p['created_at'])) ?></td><td class="sr-right"><?= srCount($p['stock']) ?></td><td class="sr-right"><?= srCount($p['reserved_stock']) ?></td><td class="sr-right"><strong><?= srCount($p['stock_available']) ?></strong></td><td><span class="sr-chip <?= $stockColor ?>"><?= srEsc($p['stock_label']) ?></span></td></tr>
<?php endforeach; ?>
<?php if(!$products): ?><tr><td colspan="6" class="sr-empty">No products are listed for this seller.</td></tr><?php endif; ?>
</tbody></table></div>
</section>
<div class="sr-section-title"><h2>4. Orders &amp; Transactions</h2><small>All orders placed in the selected period</small></div>
<div class="sr-two">
<section class="sr-panel sr-block"><h3>Order Status Breakdown</h3><p class="sr-sub">Order stage, independent from payment status</p><?php foreach($statusCounts as $status=>$num): ?><div class="sr-progress"><div class="sr-label-row"><span><?= srEsc($status) ?></span><strong><?= srCount($num) ?> · <?= srPercent((float)$num,(float)$summary['orders_in_period']) ?></strong></div><div class="sr-progress-track"><span style="width:<?= $summary['orders_in_period'] ? round($num/$summary['orders_in_period']*100,2) : 0 ?>%"></span></div></div><?php endforeach; ?></section>
<section class="sr-panel sr-block"><h3>Payment Methods &amp; Status</h3><p class="sr-sub">Recorded method or selected payment option. A chosen method does not confirm payment</p><?php foreach ($paymentCounts as $method=>$num): ?><div class="sr-progress"><div class="sr-label-row"><span><?= srEsc($method) ?></span><strong><?= srCount($num) ?> · <?= srPercent((float)$num,(float)$summary['orders_in_period']) ?></strong></div><div class="sr-progress-track"><span style="width:<?= $summary['orders_in_period'] ? round($num/$summary['orders_in_period']*100,2) : 0 ?>%"></span></div></div><?php endforeach; ?><div class="sr-divider"></div><?php foreach ($paymentStatusCounts as $status=>$num): ?><div class="sr-data-row"><span><?= srEsc($status) ?></span><strong><?= srCount($num) ?></strong></div><?php endforeach; ?><p class="sr-footnote">A selected payment option does not confirm payment. “Paid” is a payment status, not an order status. A pending order can already have a paid payment; unpaid completed orders are also possible in the data.</p></section>
</div>
<section class="sr-panel sr-block" style="margin-top:14px"><div class="sr-section-title" style="margin:0 0 10px"><h3 style="margin:0">Recent Order / Payment Records</h3><small>Latest 30 shown · Full period included in downloads</small></div><div class="sr-table-wrap"><table class="sr-table"><thead><tr><th>Order</th><th>Buyer</th><th>Status</th><th>Payment</th><th>Method</th><th class="sr-right">Amount</th><th>Date</th></tr></thead><tbody><?php foreach(array_slice($orders,0,30) as $o): ?><?php $status=srStatus($o['order_status']); $pstatus=strtolower((string)$o['payment_status']); ?><tr><td><strong>#<?= (int)$o['order_id'] ?></strong></td><td class="sr-wrap"><?= srEsc($o['buyer_name']) ?></td><td><span class="sr-chip <?= $status==='Completed'?'good':($status==='Cancelled'?'bad':'warn') ?>"><?= srEsc($status) ?></span></td><td><span class="sr-chip <?= srPaymentStatus($o['payment_status'])==='Paid'?'good':'warn' ?>"><?= srEsc(srPaymentStatus($o['payment_status'])) ?></span></td><td><?= srEsc(srPayMethod(($o['payment_method'] ?? '') ?: ($o['agreed_payment_option'] ?? ''))) ?></td><td class="sr-right"><strong><?= srMoney($o['total_amount']) ?></strong></td><td class="sr-muted"><?= srEsc(srFriendlyDate($o['ordered_at'],true)) ?></td></tr><?php endforeach; ?><?php if(!$orders): ?><tr><td colspan="7" class="sr-empty">No orders were placed in this period.</td></tr><?php endif; ?></tbody></table></div></section>
<div class="sr-bottom">Individual seller only · From <?= srEsc(srFriendlyDate($from)) ?> to <?= srEsc(srFriendlyDate($to)) ?></div>
<?php elseif (!$sellers && !$errorMessage): ?><section class="sr-panel sr-block"><div class="sr-empty">No sellers are registered in this system yet.</div></section><?php endif; ?>
</main></div>
</body></html>
