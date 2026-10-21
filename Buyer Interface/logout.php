<?php
/**
 * Buyer logout. Keep session settings aligned with buyer_auth.php.
 * Do not include buyer_auth.php here: logout must remain available
 * even when the account state or database has changed.
 */
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

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('Pragma: no-cache');
header('Expires: 0');

// Only the Logout confirmation form may end a session.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: foryou.php', true, 303);
    exit;
}

$submittedToken = $_POST['csrf_token'] ?? '';
$submittedToken = is_string($submittedToken) ? $submittedToken : '';
$sessionToken = (string)($_SESSION['buyer_logout_csrf'] ?? '');

if ($submittedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
    // No session is changed on CSRF failure. Never log the token values.
    $reason = $submittedToken === '' ? 'missing_form_token'
        : ($sessionToken === '' ? 'missing_session_token' : 'token_mismatch');
    error_log('LokalCraft Buyer logout rejected: ' . $reason);

    // An authenticated session may simply have an outdated header or form;
    // return to the portal for a fresh token. Otherwise ask for login.
    $hasLoginIdentity = false;
    foreach (['buyer_id', 'user_id', 'logged_id', 'id'] as $key) {
        if (isset($_SESSION[$key]) && filter_var($_SESSION[$key], FILTER_VALIDATE_INT) !== false
            && (int)$_SESSION[$key] > 0) {
            $hasLoginIdentity = true;
            break;
        }
    }
    if (!$hasLoginIdentity) {
        $_SESSION['login_notice'] = 'Your session has expired. Please log in again.';
    }
    session_write_close();
    header('Location: ' . ($hasLoginIdentity ? 'foryou.php?logout_retry=1' : '../login.php'), true, 303);
    exit;
}

// Successful CSRF-validated logout only.
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => (string)($params['path'] ?? '/'),
        'domain' => (string)($params['domain'] ?? ''),
        'secure' => (bool)($params['secure'] ?? false),
        'httponly' => (bool)($params['httponly'] ?? true),
        'samesite' => (string)($params['samesite'] ?? 'Lax'),
    ]);
}
session_destroy();
header('Location: ../login.php', true, 303);
exit;
