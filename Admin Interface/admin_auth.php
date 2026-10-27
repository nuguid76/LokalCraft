<?php
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

require_once __DIR__ . '/../config/database.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

function lokalcraftAdminAuthExpectsJson(): bool
{
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

    return strpos($accept, 'application/json') !== false
        || $requestedWith === 'xmlhttprequest'
        || isset($_POST['admin_action']);
}

function lokalcraftAdminAuthReject(
    string $message = 'Your administrator session has expired. Please log in again.',
    string $reason = 'expired'
): void {
    $_SESSION['login_notice'] = $message;

    if (lokalcraftAdminAuthExpectsJson()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => $message,
            'redirect' => '../login.php?admin_session=' . rawurlencode($reason),
        ], JSON_UNESCAPED_UNICODE);
        session_write_close();
        exit;
    }

    session_write_close();
    header('Location: ../login.php?admin_session=' . rawurlencode($reason), true, 303);
    exit;
}

function lokalcraftAdminAuthFailServer(): void
{
    error_log('LokalCraft admin auth: unable to verify administrator access.');

    if (lokalcraftAdminAuthExpectsJson()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Unable to verify administrator access right now.',
        ], JSON_UNESCAPED_UNICODE);
        session_write_close();
        exit;
    }

    http_response_code(503);
    session_write_close();
    exit('Administrator access is temporarily unavailable. Please try again later.');
}

$adminId = 0;
foreach (['admin_id', 'admin_user_id', 'user_id', 'logged_id', 'id'] as $sessionKey) {
    $candidate = filter_var(
        $_SESSION[$sessionKey] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($candidate !== false) {
        $adminId = (int)$candidate;
        break;
    }
}

if ($adminId <= 0) {
    lokalcraftAdminAuthReject();
}

try {
    $stmt = $conn->prepare(
        'SELECT id, firstname, middlename, lastname, email, SellerOrNot, AdminOrNot, account_status
         FROM users
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $adminAccount = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (Throwable $e) {
    error_log('LokalCraft admin auth database error: ' . $e->getMessage());
    lokalcraftAdminAuthFailServer();
}

$isValidAdmin =
    $adminAccount
    && (int)($adminAccount['AdminOrNot'] ?? 0) === 1
    && strtolower(trim((string)($adminAccount['account_status'] ?? ''))) === 'active';

if (!$isValidAdmin) {
    session_unset();
    session_destroy();
    lokalcraftAdminAuthReject(
        'Your account is not available for Administrator Portal access.',
        'invalid'
    );
}

$adminName = trim(
    (string)($adminAccount['firstname'] ?? '')
    . ' '
    . (string)($adminAccount['middlename'] ?? '')
    . ' '
    . (string)($adminAccount['lastname'] ?? '')
);
$adminName = preg_replace('/\s+/', ' ', $adminName) ?: 'Admin User';

$_SESSION['user_id'] = $adminId;
$_SESSION['user_name'] = $adminName;
$_SESSION['user_email'] = trim((string)($adminAccount['email'] ?? ''));
$_SESSION['user_role'] = 'admin';
$_SESSION['is_admin'] = true;
$_SESSION['is_seller'] = false;
$_SESSION['admin_id'] = $adminId;
$_SESSION['admin_user_id'] = $adminId;
$_SESSION['admin_name'] = $adminName;

if (
    empty($_SESSION['admin_session_regenerated_at'])
    || time() - (int)$_SESSION['admin_session_regenerated_at'] >= 1800
) {
    session_regenerate_id(true);
    $_SESSION['admin_session_regenerated_at'] = time();
}
