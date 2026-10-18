<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../login.php');
    exit;
}
$submittedToken = (string)($_POST['csrf_token'] ?? '');
$sessionToken = (string)($_SESSION['buyer_logout_csrf'] ?? '');
if (
    $submittedToken === ''
    || $sessionToken === ''
    || !hash_equals($sessionToken, $submittedToken)
) {
    http_response_code(403);
    exit('Invalid logout request. Please return to LokalCraft and try again.');
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        (bool)$params['secure'],
        (bool)$params['httponly']
    );
}
session_destroy();
header('Location: ../login.php', true, 303);
exit;