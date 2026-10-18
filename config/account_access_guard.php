<?php
/**
 * LokalCraft shared account access guard.
 * Include near the TOP of every protected Buyer/Seller PHP page and API endpoint,
 * after session_start() and after config/database.php ($conn) has loaded.
 * Avoid including this on public landing/login/signup or guest pages.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(503);
    exit('Account verification is temporarily unavailable.');
}
$accountGuardId = (int)($_SESSION['user_id'] ?? $_SESSION['buyer_id'] ?? $_SESSION['seller_user_id'] ?? $_SESSION['logged_id'] ?? 0);
if ($accountGuardId > 0) {
    try {
        $accountGuardQuery = $conn->prepare('SELECT account_status FROM users WHERE id = ? LIMIT 1');
        $accountGuardQuery->bind_param('i', $accountGuardId);
        $accountGuardQuery->execute();
        $accountGuardQuery->bind_result($accountGuardStatus);
        $accountGuardExists = $accountGuardQuery->fetch();
        $accountGuardQuery->close();
        if (!$accountGuardExists || strtolower(trim((string)$accountGuardStatus)) !== 'active') {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $accountGuardCookie = session_get_cookie_params();
                $accountGuardOptions = [
                    'expires' => time() - 3600,
                    'path' => $accountGuardCookie['path'] ?: '/',
                    'secure' => (bool)$accountGuardCookie['secure'],
                    'httponly' => (bool)$accountGuardCookie['httponly'],
                    'samesite' => $accountGuardCookie['samesite'] ?? 'Lax',
                ];
                if ($accountGuardCookie['domain'] !== '') $accountGuardOptions['domain'] = $accountGuardCookie['domain'];
                setcookie(session_name(), '', $accountGuardOptions);
            }
            session_destroy();
            header('Cache-Control: no-store, max-age=0');
            $isApi = isset($_SERVER['HTTP_ACCEPT']) && stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
            if ($isApi) {
                http_response_code(403);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['success' => false, 'message' => 'This account is not active. Please sign in or contact the administrator.']);
            } else {
                header('Location: ../login.php?account=disabled', true, 303);
            }
            exit;
        }
    } catch (Throwable $e) {
        error_log('LokalCraft account access guard: ' . $e->getMessage());
        http_response_code(503);
        exit('Account verification is temporarily unavailable.');
    }
}
