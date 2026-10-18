<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: seller_dashboard.php', true, 303);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    $isHttps =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$submittedToken = $_POST['csrf_token'] ?? null;
$sessionToken = $_SESSION['seller_role_switch_csrf'] ?? null;
if (
    !is_string($submittedToken)
    || !is_string($sessionToken)
    || $submittedToken === ''
    || $sessionToken === ''
    || !hash_equals($sessionToken, $submittedToken)
) {
    http_response_code(403);
    exit('Invalid role switch request. Please return to the Seller Portal and try again.');
}

// Reject conflicting legacy IDs before seller_auth.php normalizes the session.
$sessionIds = [];
foreach (['seller_id', 'seller_user_id', 'user_id'] as $key) {
    $id = (int)($_SESSION[$key] ?? 0);
    if ($id > 0) $sessionIds[] = $id;
}
if (count(array_unique($sessionIds)) > 1) {
    error_log('LokalCraft seller-to-buyer switch: conflicting session account IDs.');
    http_response_code(403);
    exit('Account session changed. Please log in again.');
}

// seller_auth.php checks that this user is an active, approved, non-admin seller.
// It also supplies the verified $sellerId and account details from the database.
require_once __DIR__ . '/seller_auth.php';

$buyerUserId = (int)($sellerId ?? 0);
if ($buyerUserId <= 0) {
    http_response_code(403);
    exit('Unable to verify your Seller account. Please log in again.');
}

// Buyer auth builds the visible account name from first name and last name.
$buyerName = trim(
    (string)($row['firstname'] ?? '') . ' ' . (string)($row['lastname'] ?? '')
);
$buyerName = preg_replace('/\s+/u', ' ', $buyerName) ?: 'Buyer';
$buyerEmail = trim((string)($row['email'] ?? ''));

if (!session_regenerate_id(true)) {
    error_log('LokalCraft seller-to-buyer switch: session ID regeneration failed.');
    http_response_code(500);
    exit('Unable to switch portals right now. Please try again.');
}

$_SESSION['user_id'] = $buyerUserId;
$_SESSION['buyer_id'] = $buyerUserId;
$_SESSION['user_name'] = $buyerName;
$_SESSION['buyer_name'] = $buyerName;
$_SESSION['user_email'] = $buyerEmail;
$_SESSION['user_role'] = 'buyer';
$_SESSION['is_admin'] = false;
$_SESSION['is_seller'] = false;
$_SESSION['seller_application_status'] = 'approved';

// Remove Seller/Admin-only session identifiers; do NOT remove user_id or buyer_id.
unset(
    $_SESSION['seller_id'],
    $_SESSION['seller_user_id'],
    $_SESSION['seller_name'],
    $_SESSION['seller_business_name'],
    $_SESSION['seller_role_switch_csrf'],
    $_SESSION['seller_application_rejection_reason'],
    $_SESSION['admin_id'],
    $_SESSION['admin_name']
);

session_write_close();
header('Location: ../Buyer%20Interface/foryou.php', true, 303);
exit;
