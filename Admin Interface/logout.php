<?php
/**
 * LokalCraft secure logout endpoint.
 * Keep this file beside admin_nav.php and admin_auth.php.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

$sessionToken = $_SESSION['admin_logout_csrf'] ?? '';
$submittedToken = $_POST['csrf_token'] ?? '';

if (
    !is_string($sessionToken)
    || $sessionToken === ''
    || !is_string($submittedToken)
    || !hash_equals($sessionToken, $submittedToken)
) {
    http_response_code(403);
    exit('Invalid logout request. Please refresh the page and try again.');
}

// Remove every authentication key in the shared Buyer/Seller/Admin session.
$_SESSION = [];

// Expire the PHP session cookie using the same settings used to create it.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    $cookieOptions = [
        'expires' => time() - 42000,
        'path' => $params['path'] !== '' ? $params['path'] : '/',
        'secure' => (bool)$params['secure'],
        'httponly' => (bool)$params['httponly'],
        'samesite' => $params['samesite'] !== '' ? $params['samesite'] : 'Lax',
    ];

    if ($params['domain'] !== '') {
        $cookieOptions['domain'] = $params['domain'];
    }

    setcookie(session_name(), '', $cookieOptions);
}

session_destroy();

// Prevent another response from reusing the old authenticated session ID.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Location: ../login.php?logged_out=1', true, 303);
exit;
