<?php
/**
 * Admin logout endpoint - put in the same directory as admin_nav.php.
 * Destroys the shared PHP login session (Buyer/Seller/Admin), not merely an admin flag.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

$sessionToken = $_SESSION['admin_logout_csrf'] ?? '';
$submittedToken = $_POST['csrf_token'] ?? '';
if (!is_string($sessionToken) || $sessionToken === ''
    || !is_string($submittedToken)
    || !hash_equals($sessionToken, $submittedToken)) {
    http_response_code(403);
    exit('Invalid logout request. Please refresh and try again.');
}

// Remove *all* PHP session keys, including shared Buyer/Seller authentication.
$_SESSION = [];

// Expire the PHP session cookie using exactly its original path and domain.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    $cookieOptions = [
        'expires' => time() - 3600,
        'path' => $params['path'] ?: '/',
        'secure' => (bool)$params['secure'],
        'httponly' => (bool)$params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ];
    if (!empty($params['domain'])) {
        $cookieOptions['domain'] = $params['domain'];
    }
    setcookie(session_name(), '', $cookieOptions);
}

session_destroy();
header('Location: ../login.php?logged_out=1', true, 303);
exit;
