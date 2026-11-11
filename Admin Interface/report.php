<?php
declare(strict_types=1);
require_once __DIR__ . '/admin_auth.php';
date_default_timezone_set('Asia/Manila');
if (!$conn->query("SET time_zone = '+08:00'")) {
    error_log('Unable to set report session time zone to Asia/Manila.');
}
$activePage = 'reports';

function srEsc($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function srMoney($value): string { return '₱' . number_format((float)$value, 2); }
function srCount($value): string { return number_format((int)$value); }
function srDate($value): string { $time = $value ? strtotime((string)$value) : false; return $time ? date('M j, Y · g:i A', $time) : '—'; }
function srStatus($value): string {
    $raw = strtolower(trim((string)$value));
    return match($raw) { 'confirmed' => 'Accepted', 'delivered' => 'Completed', 'canceled' => 'Cancelled', 'declined', 'rejected' => 'Rejected', default => ucwords(str_replace('_', ' ', $raw ?: 'Unknown')) };
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
    if ($s === '') return '';
    if (str_contains($s, 'cash') || $s === 'cod') return 'Cash';
    if (
        str_contains($s, 'qr') || str_contains($s, 'gcash')
        || str_contains($s, 'maya') || str_contains($s, 'paymongo')
        || str_contains($s, 'online') || str_contains($s, 'digital')
        || str_contains($s, 'e-wallet') || str_contains($s, 'ewallet')
    ) return 'QR Ph';
    return '';
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
$statusCounts = ['Pending'=>0,'Accepted'=>0,'Processing'=>0,'Completed'=>0,'Cancelled'=>0,'Rejected'=>0];
$paymentCounts = ['Cash'=>0,'QR Ph'=>0];
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
            if (!is_array($o)) continue;
            $summary['orders_in_period']++;
            $status = srStatus($o['order_status'] ?? '');
            if (isset($statusCounts[$status])) $statusCounts[$status]++;
            $recordedMethod = trim((string)($o['payment_method'] ?? ''));
            $agreedMethod = trim((string)($o['agreed_payment_option'] ?? ''));
            $method = srPayMethod($recordedMethod !== '' ? $recordedMethod : $agreedMethod);
            if ($method !== '' && isset($paymentCounts[$method])) $paymentCounts[$method]++;
            $paymentStatus = strtolower(trim((string)($o['payment_status'] ?? '')));
            $payBucket = srPaymentStatus($paymentStatus);
            if (!isset($paymentStatusCounts[$payBucket])) $paymentStatusCounts[$payBucket] = 0;
            $paymentStatusCounts[$payBucket]++;
            if ($payBucket === 'Paid') $summary['paid_orders']++;
            if ($status !== 'Completed') continue;
            $amount = (float)($o['total_amount'] ?? 0);
            $summary['gross_sales'] += $amount;
            $summary['completed_orders']++;
            if (strtolower((string)($o['commission_status'] ?? '')) === 'earned') $summary['platform_fees'] += (float)($o['commission_amount'] ?? 0);
            else $summary['fees_unavailable']++;
            $stamp = strtotime((string)($o['ordered_at'] ?? ''));
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
$topProducts = array_values(array_filter($products, static fn($p) => (int)$p['units_sold'] > 0));
$slowProducts = $products;
usort($slowProducts, static fn($a, $b) => ((int)$a['units_sold'] <=> (int)$b['units_sold']) ?: ((float)$a['product_revenue'] <=> (float)$b['product_revenue']) ?: strcmp((string)$a['product_name'], (string)$b['product_name']));
$slowProducts = array_values($slowProducts);
$popularProducts = $products;
usort($popularProducts, static fn($a, $b) => (((int)$b['cart_units']+(int)$b['saved_count']) <=> ((int)$a['cart_units']+(int)$a['saved_count'])) ?: strcmp((string)$a['product_name'], (string)$b['product_name']));
$popularProducts = array_values($popularProducts);
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
:root{
  --sr-bg:#fff8ed;--sr-card:#fffefc;--sr-text:#342d27;--sr-muted:#80756b;
  --sr-line:#eae0d7;--sr-accent:#a55325;--sr-accent-soft:#fff1e4;
  --sr-shadow:0 8px 22px rgba(67,44,22,.045)
}
*{box-sizing:border-box}
html,body{width:100%;max-width:100%;min-width:0;margin:0;overflow-x:hidden}
body{background:var(--sr-bg);color:var(--sr-text);font:14px/1.5 "Segoe UI",Arial,sans-serif}
body.sr-record-modal-open{overflow:hidden}
[hidden]{display:none!important}

/* Same shell contract used by admin_wallet.php. admin_nav.php is the first flex item. */
.page{display:flex;min-width:0;min-height:100vh;background:var(--sr-bg)}
.page>.content{flex:1 1 0;min-width:0;width:0;max-width:none;padding:28px 26px 42px;overflow-x:hidden}
.sr-wrap-page{width:100%;max-width:1600px;min-width:0;margin:0 auto}

.sr-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:22px}
.sr-heading h1{margin:0;color:#382c22;font-size:clamp(1.6rem,3.3vw,2.1rem);font-weight:800;letter-spacing:-.04em}
.sr-pill{display:inline-flex;padding:7px 10px;border:1px solid #f0d9be;border-radius:10px;background:var(--sr-accent-soft);color:var(--sr-accent);font-size:.71rem;font-weight:700;white-space:nowrap}
.sr-panel{width:100%;max-width:100%;min-width:0;background:var(--sr-card);border:1px solid var(--sr-line);border-radius:18px;box-shadow:var(--sr-shadow)}
.sr-alert{padding:12px 14px;margin-bottom:16px;border:1px solid #eabfbc;border-radius:11px;background:#fff2f1;color:#a34843;font-size:.81rem}

/* Report filters */
.sr-filter-panel{display:grid;gap:12px;padding:18px 20px;margin-bottom:19px}
.sr-filter-section{min-width:0;padding:13px;border:1px solid #eee3da;border-radius:13px;background:#fffaf5}
.sr-filter-section-title{margin-bottom:10px}
.sr-title-with-tip,.sr-block-title{display:flex;align-items:center;gap:7px;min-width:0}
.sr-title-with-tip strong,.sr-block-title h3{margin:0;color:#47372c}
.sr-filter-grid{display:grid;gap:10px;align-items:end;min-width:0}
.sr-filter-grid.scope{grid-template-columns:minmax(220px,1.8fr) repeat(2,minmax(145px,1fr))}
.sr-filter-grid.profit{grid-template-columns:repeat(2,minmax(150px,1fr)) auto}
.sr-field{display:flex;flex-direction:column;gap:6px;min-width:0}
.sr-field label{color:#75685e;font-size:.72rem;font-weight:650}
.sr-field input,.sr-field select{width:100%;min-width:0;min-height:41px;padding:8px 10px;border:1px solid #e5d9cf;border-radius:10px;background:#fffefc;color:var(--sr-text);font:inherit;font-size:.81rem;outline:0}
.sr-field input:focus,.sr-field select:focus{border-color:#d99a63;box-shadow:0 0 0 3px rgba(201,109,32,.12)}
.sr-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:41px;padding:10px 16px;border:0;border-radius:9px;font:inherit;font-size:.79rem;font-weight:750;text-decoration:none;white-space:nowrap;cursor:pointer}
.sr-btn.primary{background:var(--sr-accent);color:#fff8ed}.sr-btn.primary:hover{background:#c2692e}
.sr-btn.secondary{border:1px solid #f0d9be;background:var(--sr-accent-soft);color:var(--sr-accent)}
.sr-btn:focus-visible{outline:3px solid rgba(201,109,32,.22);outline-offset:2px}

/* Headings, exports and statistics */
.sr-section-title{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:21px 0 12px}
.sr-section-title h2{margin:0;color:#47372c;font-size:1.03rem;font-weight:800}
.sr-report-business,.sr-report-period{display:block;margin-top:5px;color:#827d78;font-size:.75rem}.sr-report-business{color:#625b53;font-weight:650}
.sr-export{display:flex;align-items:center;gap:9px;flex-wrap:wrap}.sr-export .sr-btn{min-width:120px}
.sr-summary-group{margin-top:14px}.sr-summary-group-title{margin:0 0 9px;color:#75685e;font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em}
.sr-stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;min-width:0}
.sr-stat{display:flex;justify-content:space-between;gap:10px;min-height:116px;padding:17px}
.sr-stat label{display:block;color:#80756b;font-size:.74rem;font-weight:650}.sr-stat strong{display:block;margin-top:9px;color:#382c22;font-size:clamp(1.08rem,1.8vw,1.58rem);font-weight:800;line-height:1.16;overflow-wrap:anywhere}
.sr-stat small{display:none!important}.sr-stat-icon{display:grid;place-items:center;flex:0 0 36px;width:36px;height:36px;border-radius:11px;background:var(--sr-accent-soft);color:var(--sr-accent);font-weight:750}
.sr-stat-filter{appearance:none;text-align:left;font:inherit;color:inherit;cursor:default}.sr-stat-filter:hover,.sr-stat-filter.active{border-color:var(--sr-line);box-shadow:var(--sr-shadow);transform:none}.sr-stat-filter:focus-visible{outline:2px solid rgba(165,83,37,.24);outline-offset:2px}

/* Content grid and tables */
.sr-two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:14px;min-width:0}
.sr-block{min-width:0;padding:17px}.sr-block-title{margin-bottom:12px}.sr-block h3{margin:0;color:#47372c;font-size:.9rem;font-weight:800}
.sr-list{display:flex;flex-direction:column}.sr-data-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid #f0e7de;color:#746f6a;font-size:.78rem}.sr-data-row:last-child{border-bottom:0}.sr-data-row strong{min-width:0;color:#47372c;text-align:right;overflow-wrap:anywhere}
.sr-bar-line{display:grid;grid-template-columns:92px minmax(0,1fr) 93px;gap:11px;align-items:center;margin:13px 0;color:#716c68;font-size:.75rem}.sr-bar{height:9px;overflow:hidden;border-radius:99px;background:#f3e8df}.sr-bar>span{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,#d98b50,#a55325)}.sr-bar-line strong{text-align:right;font-size:.74rem}
.sr-divider{margin:14px 0;border-top:1px solid #f2eeea}.sr-mini-metrics{display:flex;gap:18px;flex-wrap:wrap}.sr-mini-metrics>div{min-width:105px}.sr-mini-metrics strong{display:block;color:#47372c;font-size:1.1rem}.sr-mini-metrics span{display:block;margin-top:4px;color:#98908b;font-size:.69rem}
.sr-table-wrap{width:100%;max-width:100%;min-width:0;overflow-x:auto;-webkit-overflow-scrolling:touch}.sr-table{width:100%;border-collapse:collapse;text-align:left}.sr-table th{background:#fffaf5;color:#75685e;font-size:.7rem;font-weight:700;white-space:nowrap}.sr-table td{color:#645e59;font-size:.77rem}.sr-table td,.sr-table th{padding:11px 10px;border-bottom:1px solid #f0e7de;vertical-align:top}.sr-table tbody tr:last-child td{border-bottom:0}.sr-table td strong{color:#47372c}.sr-right{text-align:right}.sr-wrap{overflow-wrap:anywhere}.sr-muted{color:#a19a94;font-size:.7rem}.sr-empty{padding:21px 8px;color:#97918b;font-size:.77rem;text-align:center}
.sr-chip{display:inline-flex;padding:4px 7px;border-radius:6px;background:#f7f0e9;color:#75685e;font-size:.67rem;font-weight:700;white-space:nowrap}.sr-chip.good{background:#effaf3;color:#278553}.sr-chip.warn{background:var(--sr-accent-soft);color:var(--sr-accent)}.sr-chip.bad{background:#fff1f1;color:#c94141}

/* Contextual tooltip */
.sr-context-tip{position:relative;flex:0 0 24px;width:24px;height:24px;display:grid;place-items:center;padding:0;border:0;border-radius:50%;background:#f4efea;color:#857970;cursor:help}.sr-context-tip svg{width:14px;height:14px}
.sr-context-tip::after{content:attr(data-tooltip);position:absolute;left:0;bottom:calc(100% + 7px);z-index:500;width:230px;padding:8px 10px;border:1px solid #e7ded6;border-radius:8px;background:#342d27;color:#fff;font-size:.66rem;font-weight:500;line-height:1.45;box-shadow:0 8px 24px rgba(0,0,0,.14);opacity:0;visibility:hidden;transform:translateY(3px);transition:.14s;pointer-events:none;text-align:left}.sr-context-tip:hover::after,.sr-context-tip:focus-visible::after{opacity:1;visibility:visible;transform:none}

/* Progressive controls */
.sr-daily-controls,.sr-record-tools,.sr-inventory-tools{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px;min-width:0}.sr-chart-scroll{max-width:100%;overflow-x:auto}.sr-chart-scroll .sr-bar-line{min-width:430px}
.sr-record-search{position:relative;flex:1 1 280px;max-width:500px;min-width:0}.sr-record-search input,.sr-inventory-search{width:100%;height:41px;min-width:0;padding:8px 39px 8px 11px;border:1px solid #e5d9cf;border-radius:10px;background:#fffefc;color:var(--sr-text);font:inherit;font-size:.8rem}.sr-inventory-tools .sr-inventory-search{flex:1 1 260px;max-width:440px;padding-right:11px}
.sr-record-clear{position:absolute;right:7px;top:50%;transform:translateY(-50%);width:28px;height:28px;border:0;border-radius:50%;background:transparent;color:#927f70;cursor:pointer}.sr-quick-filter,.sr-stock-filter,.sr-daily-toggle,.sr-see-more{padding:7px 10px;border:1px solid #ead8c8;border-radius:999px;background:#fffaf5;color:#75685e;font:inherit;font-size:.68rem;font-weight:750;cursor:pointer}.sr-quick-filter.active,.sr-stock-filter.active,.sr-daily-toggle.active{border-color:var(--sr-accent);background:var(--sr-accent);color:#fff}.sr-see-more-wrap{text-align:center;margin-top:10px}
.sr-product-row[hidden],.sr-inventory-row[hidden],[data-report-record][hidden],.sr-daily-row[hidden]{display:none!important}

/* Status/payment breakdowns now use an inner container. */
.sr-breakdown-card{overflow:hidden}.sr-breakdown-list{padding:2px 12px;border:1px solid #eee3da;border-radius:11px;background:#fffaf5}.sr-progress{padding:4px 0;margin:6px 0}.sr-label-row{display:flex;justify-content:space-between;gap:10px;margin:7px 0;color:#8d8278;font-size:.71rem}.sr-progress-track{height:7px;margin:5px 0;border-radius:99px;background:#f3e8df;overflow:hidden}.sr-progress-track>span{display:block;height:100%;border-radius:99px;background:#d98b50}

/* Inventory */
.sr-stock-block{margin-top:14px}.sr-inner-title{margin:0 0 12px!important}.sr-stock-table{min-width:650px}.sr-stock-table th,.sr-stock-table td{white-space:nowrap}.sr-stock-table .sr-wrap{min-width:150px;max-width:240px;white-space:normal}

/* Recent compact records */
.sr-records-panel{margin:14px 0 0;padding:14px 16px;overflow:hidden}.sr-records-title{margin:0 0 10px!important}.sr-records-panel .sr-table-wrap{border:1px solid #eee7e1;border-radius:10px}.sr-records-panel .sr-table{table-layout:fixed}.sr-records-panel th:nth-child(1){width:13%}.sr-records-panel th:nth-child(2){width:34%}.sr-records-panel th:nth-child(3){width:22%}.sr-records-panel th:nth-child(4){width:23%}.sr-records-panel th:nth-child(5){width:8%}
[data-report-record]{cursor:pointer;outline:none}[data-report-record] td{vertical-align:middle}[data-report-record]:hover td{background:#fff8f1}[data-report-record]:focus-visible{box-shadow:inset 0 0 0 2px rgba(165,83,37,.25)}.sr-record-buyer{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.sr-record-mobile-date{display:block;margin-top:3px;color:#9a9189;font-size:.62rem}.sr-record-chevron{text-align:center;color:#9a8d82}.sr-record-chevron svg{width:16px;height:16px}
.sr-report-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:14px;color:#887a6d;font-size:.74rem}.sr-pagination,.sr-page-numbers{display:flex;align-items:center;justify-content:center;gap:6px}.sr-page-nav,.sr-page-number{min-width:38px;min-height:38px;padding:7px 11px;border:1px solid var(--sr-line);border-radius:10px;background:#fffaf5;color:#75685e;font:inherit;font-size:.76rem;font-weight:750;cursor:pointer}.sr-page-number.active{border-color:var(--sr-accent);background:var(--sr-accent);color:#fff}.sr-page-nav:disabled{opacity:.45;cursor:not-allowed}.sr-page-ellipsis{display:grid;place-items:center;min-width:18px;color:#94887d}
.sr-bottom{margin-top:18px;color:#a5a09a;font-size:.7rem;text-align:right}

/* Record modal */
.sr-record-modal{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:18px;background:rgba(27,21,17,.56);backdrop-filter:blur(3px)}.sr-record-dialog{width:min(620px,100%);max-height:calc(100dvh - 36px);display:flex;flex-direction:column;overflow:hidden;border:1px solid #e8ddd4;border-radius:16px;background:#fff;box-shadow:0 24px 70px rgba(29,20,13,.23)}.sr-record-dialog header{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:16px 18px;border-bottom:1px solid #eee7e1}.sr-record-dialog header small{color:#91877f;font-size:.64rem;text-transform:uppercase;letter-spacing:.07em}.sr-record-dialog h2{margin:3px 0 0;font-size:1.05rem}.sr-record-dialog header button{width:34px;height:34px;display:grid;place-items:center;padding:0;border:0;border-radius:9px;background:#f4efea;color:#6e6258;cursor:pointer}.sr-record-dialog header button svg{width:17px;height:17px}.sr-record-detail-grid{min-height:0;overflow-y:auto;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;padding:16px 18px}.sr-record-detail{min-width:0;padding:10px;border:1px solid #eee7e1;border-radius:9px;background:#fcfaf8}.sr-record-detail span{display:block;margin-bottom:4px;color:#91877f;font-size:.65rem}.sr-record-detail strong{display:block;color:#40372f;font-size:.76rem;overflow-wrap:anywhere}.sr-record-dialog footer{display:flex;justify-content:flex-end;padding:12px 18px;border-top:1px solid #eee7e1}

@media(max-width:1100px){.sr-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.sr-two{grid-template-columns:1fr}.sr-filter-grid.profit{grid-template-columns:repeat(2,minmax(0,1fr))}.sr-filter-grid.profit .sr-btn{grid-column:1/-1}}
@media(max-width:840px){.page{display:block}.page>.content{width:100%;padding:93px 17px 80px}.sr-wrap-page{max-width:100%}}
@media(max-width:700px){
  .page>.content{padding-left:13px;padding-right:13px}.sr-heading{flex-direction:column;gap:8px}.sr-filter-panel{padding:10px}.sr-filter-grid.scope,.sr-filter-grid.profit{grid-template-columns:1fr}.sr-field input,.sr-field select,.sr-record-search input,.sr-inventory-search{font-size:16px;min-height:44px}
  .sr-export{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));width:100%}.sr-export .sr-btn{width:100%;min-width:0;white-space:normal}.sr-stat-grid{gap:9px}.sr-stat{min-height:104px;padding:12px}.sr-stat-icon{width:28px;height:28px;flex-basis:28px}.sr-two{gap:10px}.sr-block{padding:13px}
  .sr-daily-controls{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));width:100%}.sr-inventory-tools,.sr-record-tools{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));width:100%}.sr-inventory-tools .sr-inventory-search,.sr-record-search{grid-column:1/-1;width:100%;max-width:none}.sr-stock-filter,.sr-quick-filter{width:100%;min-height:40px;white-space:normal}
  .sr-data-row{align-items:flex-start}.sr-data-row>strong{flex:0 1 48%}.sr-chart-scroll .sr-bar-line{min-width:390px}.sr-context-tip::after{left:auto;right:0;width:min(230px,72vw)}
  .sr-stock-table{display:block;min-width:0}.sr-stock-table thead{display:none}.sr-stock-table tbody{display:block}.sr-stock-table tbody tr{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 12px;padding:13px 0;border-bottom:1px solid #eee9e4}.sr-stock-table tbody td{display:flex;flex-direction:column;gap:3px;min-width:0;padding:7px 0;border:0;white-space:normal;text-align:left!important;overflow-wrap:anywhere}.sr-stock-table tbody td:before{color:#a49c93;font-size:.63rem;font-weight:650}.sr-current-stock tbody td:nth-child(1):before{content:"Product"}.sr-current-stock tbody td:nth-child(2):before{content:"First listed"}.sr-current-stock tbody td:nth-child(3):before{content:"Total stock"}.sr-current-stock tbody td:nth-child(4):before{content:"Reserved units"}.sr-current-stock tbody td:nth-child(5):before{content:"Available units"}.sr-current-stock tbody td:nth-child(6):before{content:"Stock alert"}
  .sr-records-panel{padding:11px 9px}.sr-records-panel th:nth-child(1){width:18%}.sr-records-panel th:nth-child(2){width:38%}.sr-records-panel th:nth-child(3){width:27%}.sr-records-panel th:nth-child(4){width:25%}.sr-records-panel th:nth-child(5){width:10%}.sr-records-panel th,.sr-records-panel td{padding:9px 5px}.sr-report-footer{display:grid;grid-template-columns:1fr;justify-items:center}.sr-pagination{display:grid;grid-template-columns:auto minmax(0,1fr) auto;width:100%;max-width:390px}.sr-page-numbers{min-width:0;gap:4px}.sr-page-nav{min-width:64px;padding-inline:7px}.sr-page-number{min-width:34px;padding-inline:5px}
  .sr-record-modal{align-items:flex-start;padding:max(18px,calc(env(safe-area-inset-top) + 10px)) 8px}.sr-record-dialog{max-height:calc(100dvh - 30px)}.sr-record-detail-grid{grid-template-columns:1fr;padding:13px}
}
@media(max-width:390px){.sr-export{grid-template-columns:1fr}.sr-records-panel th:nth-child(2),.sr-records-panel td:nth-child(2){display:none}.sr-records-panel th:nth-child(1){width:24%}.sr-records-panel th:nth-child(3){width:31%}.sr-records-panel th:nth-child(4){width:33%}.sr-records-panel th:nth-child(5){width:12%}.sr-stat-icon{display:none}}
</style>
</head>
<body>
<div class="page">
<?php include __DIR__ . '/admin_nav.php'; ?>
<main class="content">
<div class="sr-wrap-page">
<header class="sr-heading"><div><h1>Reports &amp; Analytics</h1></div><span class="sr-pill">Seller-specific report</span></header>
<?php if ($errorMessage !== ''): ?><div class="sr-alert" role="alert"><?= srEsc($errorMessage) ?></div><?php endif; ?>
<form class="sr-panel sr-filter-panel" method="get" action="report.php" aria-label="Individual seller report filters">
<section class="sr-filter-section" aria-labelledby="reportScopeTitle">
<div class="sr-filter-section-title"><div class="sr-title-with-tip"><strong id="reportScopeTitle">Report scope</strong><button class="sr-context-tip" type="button" aria-label="Select one seller and an inclusive report period of up to 367 days." data-tooltip="Select one seller and an inclusive report period of up to 367 days."><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg></button></div></div>
<div class="sr-filter-grid scope">
<div class="sr-field seller"><label for="seller_id">Seller</label><select id="seller_id" name="seller_id" required <?= !$sellers ? 'disabled' : '' ?>><?php if (!$sellers): ?><option value="">No registered sellers</option><?php endif; ?><?php foreach ($sellers as $entry): ?><option value="<?= (int)$entry['id'] ?>" <?= (int)$entry['id'] === $sellerId ? 'selected' : '' ?>>#<?= (int)$entry['id'] ?> · <?= srEsc($entry['business_name']) ?> · <?= srEsc($entry['seller_name']) ?></option><?php endforeach; ?></select></div>
<div class="sr-field"><label for="reportFrom">From</label><input id="reportFrom" type="date" name="from" required value="<?= srEsc($from) ?>"></div>
<div class="sr-field"><label for="reportTo">To</label><input id="reportTo" type="date" name="to" required value="<?= srEsc($to) ?>"></div>
</div></section>
<section class="sr-filter-section" aria-labelledby="profitInputsTitle">
<div class="sr-filter-section-title"><div class="sr-title-with-tip"><strong id="profitInputsTitle">Optional profit inputs</strong><button class="sr-context-tip" type="button" aria-label="These values are not stored and apply only to the current report and its export." data-tooltip="These values are not stored and apply only to the current report and its export."><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg></button></div></div>
<div class="sr-filter-grid profit">
<div class="sr-field optional"><label for="reportCogs">Cost of Goods Sold</label><input id="reportCogs" type="number" step="0.01" min="0" name="cogs" placeholder="Not entered" value="<?= $cogs === null ? '' : srEsc(number_format($cogs,2,'.','')) ?>"></div>
<div class="sr-field optional"><label for="reportExpenses">Other Business Expenses</label><input id="reportExpenses" type="number" step="0.01" min="0" name="expenses" placeholder="Not entered" value="<?= $expenses === null ? '' : srEsc(number_format($expenses,2,'.','')) ?>"></div>
<button class="sr-btn primary" type="submit" <?= !$sellers ? 'disabled' : '' ?>>View report →</button>
</div></section>
</form>
<?php if ($seller && !$errorMessage): ?>
<div class="sr-section-title sr-report-anchor" id="reportOverview"><div><h2><?= srEsc($seller['seller_name']) ?></h2><small class="sr-report-business">Business: <?= srEsc($seller['business_name']) ?></small><small class="sr-report-period">Report period: <?= srEsc(srFriendlyDate($from)) ?> to <?= srEsc(srFriendlyDate($to)) ?></small></div><div class="sr-export"><a class="sr-btn secondary" data-report-export="CSV" href="report.php?<?= srEsc($exportQuery) ?>">↓ Download CSV</a><a class="sr-btn primary" data-report-export="Excel" href="report.php?<?= srEsc($excelQuery) ?>">↓ Download Excel</a></div></div>

<div class="sr-summary-group"><h3 class="sr-summary-group-title">Sales</h3><div class="sr-stat-grid">
<button class="sr-panel sr-stat sr-stat-filter" type="button" data-order-filter="completed" aria-label="Filter order records by Completed Order Sales"><div><label>Completed Order Sales</label><strong><?= srMoney($summary['gross_sales']) ?></strong></div><span class="sr-stat-icon">₱</span></button>
<article class="sr-panel sr-stat"><div><label>Average Completed Order</label><strong><?= srMoney($summary['average_order']) ?></strong></div><span class="sr-stat-icon">↗</span></article>
<button class="sr-panel sr-stat sr-stat-filter" type="button" data-order-filter="completed" aria-label="Filter order records by Completed Orders"><div><label>Completed Orders</label><strong><?= srCount($summary['completed_orders']) ?></strong></div><span class="sr-stat-icon">✓</span></button>
<button class="sr-panel sr-stat sr-stat-filter" type="button" data-order-filter="fees" aria-label="Filter order records by Recorded Platform Fees"><div><label>Recorded Platform Fees</label><strong><?= srMoney($summary['platform_fees']) ?></strong></div><span class="sr-stat-icon">▤</span></button>
</div></div><div class="sr-summary-group"><h3 class="sr-summary-group-title">Fees and estimated profit</h3><div class="sr-stat-grid"><article class="sr-panel sr-stat"><div><label>Seller Proceeds Before Costs</label><strong><?= srMoney($summary['gross_sales']-$summary['platform_fees']) ?></strong></div><span class="sr-stat-icon">₱</span></article>
<article class="sr-panel sr-stat"><div><label>Estimated Gross Profit</label><strong><?= $summary['gross_profit'] === null ? '—' : srMoney($summary['gross_profit']) ?></strong></div><span class="sr-stat-icon">∑</span></article>
<article class="sr-panel sr-stat"><div><label>Estimated Net Profit</label><strong><?= $summary['net_profit'] === null ? '—' : srMoney($summary['net_profit']) ?></strong></div><span class="sr-stat-icon">↗</span></article>
<button class="sr-panel sr-stat sr-stat-filter" type="button" data-order-filter="paid" aria-label="Filter order records by Paid Orders"><div><label>Paid Orders</label><strong><?= srCount($summary['paid_orders']) ?></strong></div><span class="sr-stat-icon">✓</span></button>
</div>
</div></div><div class="sr-section-title sr-report-anchor" id="salesTrends"><h2>1. Sales &amp; Revenue Trends</h2></div>
<div class="sr-two">
<section class="sr-panel sr-block"><div class="sr-block-title"><h3>Daily Sales Trend</h3><button class="sr-context-tip" type="button" aria-label="Sales by order placement date within the selected period" data-tooltip="Sales by order placement date within the selected period"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg></button></div>
<?php if (!$daily): ?><div class="sr-empty">No completed sales were recorded in the selected period.</div><?php else: $maxDay = max($daily); ?><div class="sr-daily-controls" aria-label="Daily sales display"><button class="sr-daily-toggle" type="button" data-daily-limit="7">7 days</button><button class="sr-daily-toggle active" type="button" data-daily-limit="15">15 days</button><button class="sr-daily-toggle" type="button" data-daily-limit="all">All</button></div><div class="sr-chart-scroll"><?php foreach ($daily as $day=>$amount): ?><div class="sr-bar-line sr-daily-row" data-daily-row title="<?= srEsc(srFriendlyDate($day)) ?> · <?= srMoney($amount) ?>"><span><?= srEsc(date('M j',strtotime($day))) ?></span><div class="sr-bar"><span style="width:<?= $maxDay > 0 ? round($amount/$maxDay*100,2) : 0 ?>%"></span></div><strong><?= srMoney($amount) ?></strong></div><?php endforeach; ?></div><?php endif; ?>
</section>
<section class="sr-panel sr-block"><div class="sr-block-title"><h3>Monthly &amp; Half-Month Sales</h3><button class="sr-context-tip" type="button" aria-label="Monthly performance and kinsenas breakdown within selected dates" data-tooltip="Monthly performance and kinsenas breakdown within selected dates"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg></button></div>
<?php if (!$monthly): ?><div class="sr-empty">No monthly sales data yet.</div><?php else: $maxMonth = max($monthly); foreach ($monthly as $month=>$amount): ?><div class="sr-bar-line"><span><?= srEsc(date('M Y',strtotime($month.'-01'))) ?></span><div class="sr-bar"><span style="width:<?= $maxMonth > 0 ? round($amount/$maxMonth*100,2) : 0 ?>%"></span></div><strong><?= srMoney($amount) ?></strong></div><?php endforeach; endif; ?>
<div class="sr-divider"></div><div class="sr-mini-metrics"><div><strong><?= srMoney($halves['1–15']) ?></strong><span>Days 1–15 (combined across months)</span></div><div><strong><?= srMoney($halves['16–end']) ?></strong><span>Days 16–end (combined across months)</span></div></div>
</section>
<section class="sr-panel sr-block"><div class="sr-block-title"><h3>Most Active Selling Hours</h3><button class="sr-context-tip" type="button" aria-label="Completed orders by the hour they were placed (Manila time). Peak hour:" data-tooltip="Completed orders by the hour they were placed (Manila time). Peak hour:"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg></button></div>
<?php $hourKeys=range(0,23); usort($hourKeys,fn($a,$b)=>($hours[$b]['orders']<=>$hours[$a]['orders']) ?: ($hours[$b]['amount']<=>$hours[$a]['amount'])); $topHours=array_slice(array_filter($hourKeys,fn($h)=>$hours[$h]['orders']>0),0,6); ?>
<?php if (!$topHours): ?><div class="sr-empty">No completed order times yet.</div><?php else: foreach ($topHours as $h): ?><div class="sr-data-row"><span><?= srEsc(date('g A',strtotime(sprintf('%02d:00',$h)))) ?> – <?= srEsc(date('g A',strtotime(sprintf('%02d:00',($h+1)%24)))) ?></span><strong><?= srCount($hours[$h]['orders']) ?> orders · <?= srMoney($hours[$h]['amount']) ?></strong></div><?php endforeach; endif; ?>
</section>
<section class="sr-panel sr-block"><div class="sr-block-title"><h3>Sales Summary</h3><button class="sr-context-tip" type="button" aria-label="Quick financial snapshot of the selected seller" data-tooltip="Quick financial snapshot of the selected seller"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg></button></div>
<div class="sr-list"><div class="sr-data-row"><span>Completed-order gross sales</span><strong><?= srMoney($summary['gross_sales']) ?></strong></div><div class="sr-data-row"><span>Earned platform fees recorded</span><strong><?= srMoney($summary['platform_fees']) ?></strong></div><div class="sr-data-row"><span>Entered COGS</span><strong><?= $cogs === null ? 'Not entered' : srMoney($cogs) ?></strong></div><div class="sr-data-row"><span>Entered other expenses</span><strong><?= $expenses === null ? 'Not entered' : srMoney($expenses) ?></strong></div><div class="sr-data-row"><span>Estimated net profit</span><strong><?= $summary['net_profit'] === null ? 'Not calculable yet' : srMoney($summary['net_profit']) ?></strong></div><div class="sr-data-row"><span>Average completed-order value</span><strong><?= srMoney($summary['average_order']) ?></strong></div></div>
</section>
</div>
<div class="sr-section-title sr-report-anchor" id="productPerformance"><h2>2. Product Performance</h2></div>
<div class="sr-two">
<section class="sr-panel sr-block"><div class="sr-block-title"><h3>Top-Selling Products</h3><button class="sr-context-tip" type="button" aria-label="Products ranked by completed units sold" data-tooltip="Products ranked by completed units sold"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg></button></div><div class="sr-table-wrap"><table class="sr-table"><thead><tr><th>Product</th><th class="sr-right">Units</th><th class="sr-right">Item Sales</th></tr></thead><tbody id="topProductsBody"><?php foreach($topProducts as $p): ?><tr class="sr-product-row" data-product-row><td class="sr-wrap"><strong><?= srEsc($p['product_name']) ?></strong><div class="sr-muted">#<?= (int)$p['product_id'] ?></div></td><td class="sr-right"><?= srCount($p['units_sold']) ?></td><td class="sr-right"><?= srMoney($p['product_revenue']) ?></td></tr><?php endforeach; ?><?php if(!$topProducts): ?><tr><td colspan="3" class="sr-empty">No completed product sales in this period.</td></tr><?php endif; ?></tbody></table></div><div class="sr-see-more-wrap"><button class="sr-see-more" type="button" data-product-pager="topProductsBody">See 5 More</button></div></section>
<section class="sr-panel sr-block"><div class="sr-block-title"><h3>Low-Selling Products</h3><button class="sr-context-tip" type="button" aria-label="Products with the fewest completed sales in this period" data-tooltip="Products with the fewest completed sales in this period"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg></button></div><div class="sr-table-wrap"><table class="sr-table"><thead><tr><th>Product</th><th class="sr-right">Units</th><th class="sr-right">Current Stock</th></tr></thead><tbody id="slowProductsBody"><?php foreach($slowProducts as $p): ?><tr class="sr-product-row" data-product-row><td class="sr-wrap"><strong><?= srEsc($p['product_name']) ?></strong><div class="sr-muted"><?= (int)$p['units_sold']===0?'No sales in period':srEsc($p['status']) ?></div></td><td class="sr-right"><?= srCount($p['units_sold']) ?></td><td class="sr-right"><?= srCount($p['stock']) ?></td></tr><?php endforeach; ?><?php if(!$slowProducts): ?><tr><td colspan="3" class="sr-empty">No products in this seller's catalog.</td></tr><?php endif; ?></tbody></table></div><div class="sr-see-more-wrap"><button class="sr-see-more" type="button" data-product-pager="slowProductsBody">See 5 More</button></div></section>
<section class="sr-panel sr-block"><div class="sr-block-title"><h3>Saved &amp; Cart Interest</h3><button class="sr-context-tip" type="button" aria-label="Currently in buyer carts and saved lists (not a historical event count)" data-tooltip="Currently in buyer carts and saved lists (not a historical event count)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg></button></div><div class="sr-mini-metrics"><div><strong><?= srCount($summary['cart']) ?></strong><span>Units in carts now</span></div><div><strong><?= srCount($summary['saved']) ?></strong><span>Current saved-product entries</span></div></div><div class="sr-divider"></div><div class="sr-table-wrap"><table class="sr-table"><thead><tr><th>Product</th><th class="sr-right">Cart Units</th><th class="sr-right">Saved</th></tr></thead><tbody id="popularProductsBody"><?php foreach($popularProducts as $p): ?><tr class="sr-product-row" data-product-row><td class="sr-wrap"><strong><?= srEsc($p['product_name']) ?></strong></td><td class="sr-right"><?= srCount($p['cart_units']) ?></td><td class="sr-right"><?= srCount($p['saved_count']) ?></td></tr><?php endforeach; ?><?php if(!$popularProducts): ?><tr><td colspan="3" class="sr-empty">No product interest records available.</td></tr><?php endif; ?></tbody></table></div><div class="sr-see-more-wrap"><button class="sr-see-more" type="button" data-product-pager="popularProductsBody">See 5 More</button></div></section>
<section class="sr-panel sr-block"><div class="sr-block-title"><h3>Product Report Notes</h3><button class="sr-context-tip" type="button" aria-label="How to interpret product sales and interest" data-tooltip="How to interpret product sales and interest"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg></button></div><div class="sr-list"><div class="sr-data-row"><span>Products in seller catalog</span><strong><?= srCount(count($products)) ?></strong></div><div class="sr-data-row"><span>Completed-order item units</span><strong><?= srCount($summary['units']) ?></strong></div><div class="sr-data-row"><span>Top product basis</span><strong>Units sold</strong></div><div class="sr-data-row"><span>Low-selling basis</span><strong>Selected period only</strong></div><div class="sr-data-row"><span>Saved / cart time basis</span><strong>Current snapshot</strong></div><div class="sr-data-row"><span>Full product export</span><strong>Included in CSV and Excel</strong></div></div></section>
</div>
<div class="sr-section-title sr-report-anchor" id="inventoryReports"><h2>3. Inventory &amp; Stock Reports</h2></div>
<div class="sr-stat-grid">
<article class="sr-panel sr-stat"><div><label>Products in Catalog</label><strong><?= srCount($inventorySummary['total']) ?></strong></div><span class="sr-stat-icon">▤</span></article>
<article class="sr-panel sr-stat"><div><label>Low Stock Alerts</label><strong><?= srCount($inventorySummary['low']) ?></strong></div><span class="sr-stat-icon">!</span></article>
<article class="sr-panel sr-stat"><div><label>Out of Stock</label><strong><?= srCount($inventorySummary['out']) ?></strong></div><span class="sr-stat-icon">×</span></article>
<article class="sr-panel sr-stat"><div><label>Products In Stock</label><strong><?= srCount($inventorySummary['in_stock']) ?></strong></div><span class="sr-stat-icon">✓</span></article>
</div>
<section class="sr-panel sr-block sr-stock-block">
<div class="sr-section-title sr-inner-title"><div class="sr-block-title"><h3>Current Inventory and Low-Stock Alerts</h3><small><?= srCount($inventorySummary['available_units']) ?> available units · <?= srCount($inventorySummary['reserved_units']) ?> reserved units</small></div>
<div class="sr-inventory-tools" aria-label="Inventory filters"><input class="sr-inventory-search" id="inventorySearch" type="search" placeholder="Search product or category" aria-label="Search inventory"><button class="sr-stock-filter active" type="button" data-stock-filter="all">All</button><button class="sr-stock-filter" type="button" data-stock-filter="Low stock">Low Stock</button><button class="sr-stock-filter" type="button" data-stock-filter="Out of stock">Out of Stock</button><button class="sr-stock-filter" type="button" data-stock-filter="In stock">In Stock</button><button class="sr-stock-filter" type="button" data-stock-filter="Archived / Removed">Archived</button><select id="inventorySort" class="sr-inventory-search" aria-label="Sort inventory"><option value="alert">Alerts first</option><option value="available-asc">Available: low to high</option><option value="available-desc">Available: high to low</option><option value="reserved-desc">Reserved: high to low</option></select></div><div class="sr-table-wrap"><table class="sr-table sr-stock-table sr-current-stock"><thead><tr><th>Product</th><th>Date Listed</th><th class="sr-right">Total Stock</th><th class="sr-right">Reserved</th><th class="sr-right">Available</th><th>Stock Alert</th></tr></thead><tbody>
<?php foreach($products as $p): ?>
<?php $stockColor=$p['stock_label']==='Out of stock'?'bad':($p['stock_label']==='Low stock'?'warn':($p['stock_label']==='In stock'?'good':'')); ?>
<tr class="sr-inventory-row" data-inventory-row data-search="<?= srEsc(strtolower($p['product_name'].' '.$p['craft_category'])) ?>" data-stock-status="<?= srEsc($p['stock_label']) ?>" data-available="<?= (int)$p['stock_available'] ?>" data-reserved="<?= (int)$p['reserved_stock'] ?>"><td class="sr-wrap"><strong><?= srEsc($p['product_name']) ?></strong><div class="sr-muted"><?= srEsc($p['craft_category']) ?></div></td><td><?= srEsc(srFriendlyDate($p['created_at'])) ?></td><td class="sr-right"><?= srCount($p['stock']) ?></td><td class="sr-right"><?= srCount($p['reserved_stock']) ?></td><td class="sr-right"><strong><?= srCount($p['stock_available']) ?></strong></td><td><span class="sr-chip <?= $stockColor ?>"><?= srEsc($p['stock_label']) ?></span></td></tr>
<?php endforeach; ?>
<?php if(!$products): ?><tr><td colspan="6" class="sr-empty">No products are listed for this seller.</td></tr><?php endif; ?>
</tbody></table></div>
</section>
<div class="sr-section-title sr-report-anchor" id="ordersPayments"><h2>4. Orders &amp; Transactions</h2></div>
<div class="sr-two">
<section class="sr-panel sr-block sr-breakdown-card">
<div class="sr-block-title"><h3>Order Status Breakdown</h3><button class="sr-context-tip" type="button" aria-label="Order stage, independent from payment status" data-tooltip="Order stage, independent from payment status"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg></button></div>
<div class="sr-breakdown-list"><?php foreach($statusCounts as $status=>$num): ?><div class="sr-progress"><div class="sr-label-row"><span><?= srEsc($status) ?></span><strong><?= srCount($num) ?> · <?= srPercent((float)$num,(float)$summary['orders_in_period']) ?></strong></div><div class="sr-progress-track"><span style="width:<?= $summary['orders_in_period'] ? round($num/$summary['orders_in_period']*100,2) : 0 ?>%"></span></div></div><?php endforeach; ?></div>
</section>
<section class="sr-panel sr-block"><div class="sr-block-title"><h3>Payment Methods &amp; Status</h3><button class="sr-context-tip" type="button" aria-label="Cash versus QR Ph groups the recorded electronic and online payment labels. A selected method does not prove collection; Paid is a separate payment status." data-tooltip="Cash versus QR Ph groups the recorded electronic and online payment labels. A selected method does not prove collection; Paid is a separate payment status."><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg></button></div><div class="sr-breakdown-list"><?php foreach ($paymentCounts as $method=>$num): ?><div class="sr-progress"><div class="sr-label-row"><span><?= srEsc($method) ?></span><strong><?= srCount($num) ?> · <?= srPercent((float)$num,(float)$summary['orders_in_period']) ?></strong></div><div class="sr-progress-track"><span style="width:<?= $summary['orders_in_period'] ? round($num/$summary['orders_in_period']*100,2) : 0 ?>%"></span></div></div><?php endforeach; ?><div class="sr-divider"></div><?php foreach ($paymentStatusCounts as $status=>$num): ?><div class="sr-data-row"><span><?= srEsc($status) ?></span><strong><?= srCount($num) ?></strong></div><?php endforeach; ?></div></section>
</div>
<section class="sr-panel sr-block sr-records-panel"><div class="sr-section-title sr-records-title"><div class="sr-title-with-tip"><h3 style="margin:0">Recent Order / Payment Records</h3><button class="sr-context-tip" type="button" aria-label="10 records per page · Full period included in downloads" data-tooltip="10 records per page · Full period included in downloads"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg></button></div></div><div class="sr-record-tools"><div class="sr-record-search"><input id="recentRecordSearch" type="search" placeholder="Search order, buyer, status, payment, amount, or date" aria-label="Search recent order and payment records"><button class="sr-record-clear" id="recentRecordClear" type="button" aria-label="Clear record search" hidden>&times;</button></div><button class="sr-quick-filter active" type="button" data-order-filter="all">All</button><button class="sr-quick-filter" type="button" data-order-filter="completed">Completed</button><button class="sr-quick-filter" type="button" data-order-filter="paid">Paid</button><button class="sr-quick-filter" type="button" data-order-filter="fees">With Fees</button></div><div class="sr-empty sr-record-empty" id="recentRecordEmpty" hidden>No order or payment records match the current search.</div><div class="sr-table-wrap"><table class="sr-table"><thead><tr><th>Order</th><th>Buyer</th><th>Status</th><th class="sr-right">Amount</th><th aria-label="View details"></th></tr></thead><tbody><?php foreach($orders as $o): ?><?php
$status=srStatus($o['order_status']);
$paymentLabel=srPaymentStatus($o['payment_status']);
$paymentMethod=srPayMethod(($o['payment_method'] ?? '') ?: ($o['agreed_payment_option'] ?? '')) ?: 'Not recorded';
$recordDetails=json_encode([
'order_id'=>'#'.(int)$o['order_id'],
'buyer'=>(string)$o['buyer_name'],
'order_status'=>$status,
'order_total'=>srMoney($o['total_amount']),
'order_date'=>srFriendlyDate($o['ordered_at'],true),
'payment_method'=>$paymentMethod,
'payment_status'=>$paymentLabel,
'payment_amount'=>$o['payment_amount']===null?'Not recorded':srMoney($o['payment_amount']),
'paid_at'=>$o['paid_at']?srFriendlyDate($o['paid_at'],true):'Not recorded',
'payment_reference'=>trim((string)($o['payment_reference']??'')) ?: 'Not recorded',
'platform_fee'=>strtolower((string)($o['commission_status']??''))==='earned'?srMoney($o['commission_amount']):'Not recorded'
],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);
?><tr data-report-record tabindex="0" role="button" aria-label="View details for order #<?= (int)$o['order_id'] ?>" data-record-details="<?= srEsc($recordDetails ?: '{}') ?>" data-order-status="<?= srEsc(strtolower($status)) ?>" data-payment-status="<?= srEsc(strtolower($paymentLabel)) ?>" data-has-fee="<?= strtolower((string)($o['commission_status']??''))==='earned' ? '1' : '0' ?>" data-search="<?= srEsc(strtolower(implode(' ',['#'.$o['order_id'],'order '.$o['order_id'],$o['buyer_name'],$status,$paymentLabel,$paymentMethod,number_format((float)$o['total_amount'],2,'.',''),srFriendlyDate($o['ordered_at'],true)]))) ?>"><td><strong>#<?= (int)$o['order_id'] ?></strong><span class="sr-record-mobile-date"><?= srEsc(srFriendlyDate($o['ordered_at'])) ?></span></td><td class="sr-record-buyer"><?= srEsc($o['buyer_name']) ?></td><td><span class="sr-chip <?= $status==='Completed'?'good':($status==='Cancelled'?'bad':'warn') ?>"><?= srEsc($status) ?></span></td><td class="sr-right"><strong><?= srMoney($o['total_amount']) ?></strong></td><td class="sr-record-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></td></tr><?php endforeach; ?><?php if(!$orders): ?><tr><td colspan="5" class="sr-empty">No orders were placed in this period.</td></tr><?php endif; ?></tbody></table></div><div class="sr-report-footer" id="recentRecordsFooter" <?= !$orders ? 'hidden' : '' ?>><span id="recentRecordsSummary" aria-live="polite"></span><nav class="sr-pagination" id="recentRecordsPagination" aria-label="Recent order and payment record pages"><button type="button" class="sr-page-nav" id="recentRecordsPrev">Previous</button><div class="sr-page-numbers" id="recentRecordsPages"></div><button type="button" class="sr-page-nav" id="recentRecordsNext">Next</button></nav></div></section>
<div class="sr-bottom">Individual seller only · From <?= srEsc(srFriendlyDate($from)) ?> to <?= srEsc(srFriendlyDate($to)) ?></div>
<div class="sr-record-modal" id="reportRecordModal" hidden aria-hidden="true"><div class="sr-record-dialog" role="dialog" aria-modal="true" aria-labelledby="reportRecordTitle"><header><div><small>Order and payment record</small><h2 id="reportRecordTitle">Record Details</h2></div><button type="button" id="reportRecordClose" aria-label="Close record details"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button></header><div class="sr-record-detail-grid" id="reportRecordGrid"></div><footer><button type="button" class="sr-btn primary" id="reportRecordDone">Done</button></footer></div></div>
<?php elseif (!$sellers && !$errorMessage): ?><section class="sr-panel sr-block"><div class="sr-empty">No sellers are registered in this system yet.</div></section><?php endif; ?>
</div>
</main></div>
<script>(()=>{'use strict';
const normalize=v=>String(v||'').toLocaleLowerCase().replace(/[_-]+/g,' ').replace(/[^\p{L}\p{N}#₱.@+\s]/gu,' ').replace(/\s+/g,' ').trim();
// Native export download with temporary status only.
document.querySelectorAll('[data-report-export]').forEach(link=>link.addEventListener('click',()=>{const original=link.textContent;link.classList.add('is-preparing');link.textContent='Preparing '+link.dataset.reportExport+'...';setTimeout(()=>{link.classList.remove('is-preparing');link.textContent=original},2600)}));
// Product progressive disclosure.
document.querySelectorAll('[data-product-pager]').forEach(button=>{const body=document.getElementById(button.dataset.productPager);let visible=5;const apply=()=>{const rows=[...body.querySelectorAll('[data-product-row]')];rows.forEach((row,i)=>row.hidden=i>=visible);const remaining=Math.max(0,rows.length-visible);button.hidden=remaining===0;button.textContent=remaining?`See ${Math.min(5,remaining)} More`:'All Shown'};button.addEventListener('click',()=>{visible+=5;apply()});apply()});
// Daily 7/15/All display.
const dailyRows=[...document.querySelectorAll('[data-daily-row]')];const dailyButtons=[...document.querySelectorAll('[data-daily-limit]')];function applyDaily(limit='15'){const count=limit==='all'?dailyRows.length:Number(limit);dailyRows.forEach((row,i)=>row.hidden=i<Math.max(0,dailyRows.length-count));dailyButtons.forEach(b=>b.classList.toggle('active',b.dataset.dailyLimit===String(limit)))}dailyButtons.forEach(b=>b.addEventListener('click',()=>applyDaily(b.dataset.dailyLimit)));if(dailyRows.length)applyDaily('15');
// Inventory search/filter/sort.
const inventoryBody=document.querySelector('.sr-current-stock tbody');const inventoryRows=[...document.querySelectorAll('[data-inventory-row]')];const inventorySearch=document.getElementById('inventorySearch');const stockButtons=[...document.querySelectorAll('[data-stock-filter]')];const inventorySort=document.getElementById('inventorySort');let stockFilter='all';function applyInventory(){const q=normalize(inventorySearch?.value);const severity={'Out of stock':0,'Low stock':1,'In stock':2,'Archived / Removed':3};inventoryRows.sort((a,b)=>{switch(inventorySort?.value){case'available-asc':return +a.dataset.available-+b.dataset.available;case'available-desc':return +b.dataset.available-+a.dataset.available;case'reserved-desc':return +b.dataset.reserved-+a.dataset.reserved;default:return (severity[a.dataset.stockStatus]??9)-(severity[b.dataset.stockStatus]??9)}}).forEach(row=>inventoryBody?.append(row));inventoryRows.forEach(row=>row.hidden=!((stockFilter==='all'||row.dataset.stockStatus===stockFilter)&&(!q||normalize(row.dataset.search).includes(q))))}inventorySearch?.addEventListener('input',applyInventory);inventorySearch?.addEventListener('search',applyInventory);inventorySort?.addEventListener('change',applyInventory);stockButtons.forEach(b=>b.addEventListener('click',()=>{stockFilter=b.dataset.stockFilter;stockButtons.forEach(x=>x.classList.toggle('active',x===b));applyInventory()}));applyInventory();
// Recent record search, quick filters and compact pagination.
const allRows=[...document.querySelectorAll('[data-report-record]')],footer=document.getElementById('recentRecordsFooter'),summary=document.getElementById('recentRecordsSummary'),pages=document.getElementById('recentRecordsPages'),previous=document.getElementById('recentRecordsPrev'),next=document.getElementById('recentRecordsNext'),search=document.getElementById('recentRecordSearch'),clear=document.getElementById('recentRecordClear'),empty=document.getElementById('recentRecordEmpty'),quick=[...document.querySelectorAll('.sr-quick-filter,[data-order-filter].sr-stat-filter')];let currentPage=1,currentFilter='all';const PER_PAGE=10;function matchesFilter(row){return currentFilter==='all'||(currentFilter==='completed'&&row.dataset.orderStatus==='completed')||(currentFilter==='paid'&&row.dataset.paymentStatus==='paid')||(currentFilter==='fees'&&row.dataset.hasFee==='1')}function filtered(){const q=normalize(search?.value);return allRows.filter(row=>matchesFilter(row)&&(!q||normalize(row.dataset.search).includes(q)))}function pageItems(total,p){const mobile=matchMedia('(max-width:600px)').matches,limit=mobile?3:7;if(total<=limit)return Array.from({length:total},(_,i)=>i+1);if(mobile){if(p<=2)return[1,2,3,'…',total];if(p>=total-1)return[1,'…',total-2,total-1,total];return[1,'…',p,'…',total]}const start=Math.max(2,p-2),end=Math.min(total-1,p+2),items=[1];if(start>2)items.push('…');for(let n=start;n<=end;n++)items.push(n);if(end<total-1)items.push('…');items.push(total);return items}function render(){const rows=filtered(),totalPages=Math.max(1,Math.ceil(rows.length/PER_PAGE));currentPage=Math.min(currentPage,totalPages);allRows.forEach(r=>r.hidden=true);const start=(currentPage-1)*PER_PAGE,end=Math.min(start+PER_PAGE,rows.length);rows.slice(start,end).forEach(r=>r.hidden=false);empty.hidden=rows.length!==0;if(summary)summary.textContent=rows.length?`Showing ${start+1}–${end} of ${rows.length} records`:'No matching records';if(previous)previous.disabled=currentPage===1;if(next)next.disabled=currentPage===totalPages;if(pages){pages.replaceChildren();pageItems(totalPages,currentPage).forEach(item=>{if(item==='…'){const s=document.createElement('span');s.className='sr-page-ellipsis';s.textContent='…';pages.append(s);return}const b=document.createElement('button');b.type='button';b.className='sr-page-number'+(item===currentPage?' active':'');b.textContent=String(item);if(item===currentPage)b.setAttribute('aria-current','page');b.addEventListener('click',()=>{currentPage=item;render()});pages.append(b)})}if(footer)footer.hidden=rows.length===0;clear.hidden=!search?.value;quick.forEach(b=>b.classList.toggle('active',b.dataset.orderFilter===currentFilter))}search?.addEventListener('input',()=>{currentPage=1;render()});search?.addEventListener('search',()=>{currentPage=1;render()});clear?.addEventListener('click',()=>{search.value='';currentPage=1;render();search.focus({preventScroll:true})});quick.forEach(b=>b.addEventListener('click',()=>{currentFilter=b.dataset.orderFilter||'all';currentPage=1;render();document.getElementById('ordersPayments')?.scrollIntoView({behavior:'smooth',block:'start'})}));previous?.addEventListener('click',()=>{if(currentPage>1){currentPage--;render()}});next?.addEventListener('click',()=>{if(currentPage<Math.ceil(filtered().length/PER_PAGE)){currentPage++;render()}});matchMedia('(max-width:600px)').addEventListener?.('change',render);render();
// Full details for compact order/payment rows. Browser/mobile Back closes the modal only.
const recordModal=document.getElementById('reportRecordModal'),recordGrid=document.getElementById('reportRecordGrid'),recordTitle=document.getElementById('reportRecordTitle'),recordClose=document.getElementById('reportRecordClose'),recordDone=document.getElementById('reportRecordDone');let recordTrigger=null,recordHistoryActive=false;const recordLabels={order_id:'Order ID',buyer:'Buyer',order_status:'Order Status',order_total:'Order Total',order_date:'Order Date',payment_method:'Payment Method',payment_status:'Payment Status',payment_amount:'Payment Amount',paid_at:'Paid At',payment_reference:'Payment Reference',platform_fee:'Recorded Platform Fee'};function openRecordDetails(row){let details;try{details=JSON.parse(row.dataset.recordDetails||'{}')}catch(_){return}recordTrigger=row;recordTitle.textContent='Order '+(details.order_id||'Details');recordGrid.replaceChildren();Object.entries(recordLabels).forEach(([key,label])=>{const item=document.createElement('div');item.className='sr-record-detail';const caption=document.createElement('span');caption.textContent=label;const value=document.createElement('strong');value.textContent=details[key]||'Not recorded';item.append(caption,value);recordGrid.append(item)});recordModal.hidden=false;recordModal.setAttribute('aria-hidden','false');document.body.classList.add('sr-record-modal-open');if(!recordHistoryActive){history.pushState({...history.state,lokalcraftReportRecord:true},'',location.href);recordHistoryActive=true}recordClose.focus({preventScroll:true})}function hideRecordDetails(){if(!recordModal||recordModal.hidden)return;recordModal.hidden=true;recordModal.setAttribute('aria-hidden','true');document.body.classList.remove('sr-record-modal-open');recordTrigger?.focus?.({preventScroll:true});recordTrigger=null}function closeRecordDetails(){if(recordHistoryActive){history.back();return}hideRecordDetails()}allRows.forEach(row=>{row.addEventListener('click',()=>openRecordDetails(row));row.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();openRecordDetails(row)}})});recordClose?.addEventListener('click',closeRecordDetails);recordDone?.addEventListener('click',closeRecordDetails);recordModal?.addEventListener('click',event=>{if(event.target===recordModal)closeRecordDetails()});document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!recordModal?.hidden){event.preventDefault();closeRecordDetails()}});addEventListener('popstate',()=>{if(recordHistoryActive){recordHistoryActive=false;hideRecordDetails()}});
})();</script></body></html>
