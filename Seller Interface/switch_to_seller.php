<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ../Buyer%20Interface/foryou.php', true, 303);
    exit;
}

require_once __DIR__ . '/../Buyer Interface/buyer_auth.php';

header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$submittedToken = $_POST['csrf_token'] ?? null;
$sessionToken = $_SESSION['buyer_role_switch_csrf'] ?? null;
if (
    !is_string($submittedToken)
    || !is_string($sessionToken)
    || $submittedToken === ''
    || $sessionToken === ''
    || !hash_equals($sessionToken, $submittedToken)
) {
    http_response_code(403);
    exit('Invalid role switch request. Please return to the Buyer Portal and try again.');
}

$buyerUserId = (int)($buyerId ?? 0);
if ($buyerUserId <= 0) {
    http_response_code(403);
    exit('Unable to verify your Buyer account. Please log in again.');
}

$stmt = $conn->prepare(
    'SELECT
        u.firstname,
        u.middlename,
        u.lastname,
        u.email,
        u.SellerOrNot,
        u.AdminOrNot,
        u.account_status,
        (
            SELECT b.businessname
            FROM businessinfo b
            WHERE b.user_id = u.id
            ORDER BY b.business_id DESC
            LIMIT 1
        ) AS businessname,
        (
            SELECT LOWER(sa.status)
            FROM seller_applications sa
            WHERE sa.user_id = u.id
            ORDER BY sa.submitted_at DESC, sa.application_id DESC
            LIMIT 1
        ) AS application_status
     FROM users u
     WHERE u.id = ?
     LIMIT 1'
);
if (!$stmt) {
    error_log('LokalCraft buyer-to-seller switch: unable to prepare seller verification.');
    http_response_code(500);
    exit('Unable to switch portals right now. Please try again.');
}
$stmt->bind_param('i', $buyerUserId);
$stmt->execute();
$sellerAccount = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (
    !$sellerAccount
    || (int)($sellerAccount['SellerOrNot'] ?? 0) !== 1
    || (int)($sellerAccount['AdminOrNot'] ?? 0) !== 0
    || strtolower(trim((string)($sellerAccount['account_status'] ?? ''))) !== 'active'
    || strtolower(trim((string)($sellerAccount['application_status'] ?? ''))) !== 'approved'
) {
    http_response_code(403);
    exit('Seller Portal access is not available for this account.');
}

$sellerName = trim(
    (string)($sellerAccount['firstname'] ?? '') . ' '
    . (string)($sellerAccount['middlename'] ?? '') . ' '
    . (string)($sellerAccount['lastname'] ?? '')
);
$sellerName = preg_replace('/\s+/u', ' ', $sellerName) ?: 'Seller';
$businessName = trim((string)($sellerAccount['businessname'] ?? ''));
if ($businessName === '') $businessName = 'Seller Portal';

if (!session_regenerate_id(true)) {
    error_log('LokalCraft buyer-to-seller switch: session ID regeneration failed.');
    http_response_code(500);
    exit('Unable to switch portals right now. Please try again.');
}

$_SESSION['user_id'] = $buyerUserId;
$_SESSION['user_name'] = $sellerName;
$_SESSION['user_email'] = trim((string)($sellerAccount['email'] ?? ''));
$_SESSION['user_role'] = 'seller';
$_SESSION['is_admin'] = false;
$_SESSION['is_seller'] = true;
$_SESSION['seller_id'] = $buyerUserId;
$_SESSION['seller_user_id'] = $buyerUserId;
$_SESSION['seller_name'] = $sellerName;
$_SESSION['seller_business_name'] = $businessName;
$_SESSION['seller_application_status'] = 'approved';

unset(
    $_SESSION['buyer_id'],
    $_SESSION['buyer_name'],
    $_SESSION['buyer_role_switch_csrf'],
    $_SESSION['seller_application_rejection_reason'],
    $_SESSION['admin_id'],
    $_SESSION['admin_name']
);

session_write_close();
header('Location: seller_dashboard.php', true, 303);
exit;
