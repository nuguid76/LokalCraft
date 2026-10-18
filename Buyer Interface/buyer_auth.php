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

function lokalcraftBuyerAuthExpectsJson(): bool
{
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

    return strpos($accept, 'application/json') !== false
        || $requestedWith === 'xmlhttprequest'
        || isset($_POST['marketplace_action']);
}

function lokalcraftBuyerAuthReject(string $message = 'Your session has expired. Please log in again.'): void
{
    $_SESSION['login_notice'] = $message;

    if (lokalcraftBuyerAuthExpectsJson()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'success' => false,
            'message' => $message,
            'redirect' => '../login.php',
        ], JSON_UNESCAPED_UNICODE);

        session_write_close();
        exit;
    }

    session_write_close();
    header('Location: ../login.php');
    exit;
}

function lokalcraftBuyerAuthFailServer(): void
{
    if (lokalcraftBuyerAuthExpectsJson()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'success' => false,
            'message' => 'Unable to verify your account right now.',
        ], JSON_UNESCAPED_UNICODE);

        session_write_close();
        exit;
    }

    http_response_code(500);
    session_write_close();
    exit('Unable to verify your account right now.');
}

$buyerAuthUserId = 0;

foreach (['buyer_id', 'user_id', 'logged_id', 'id'] as $sessionKey) {
    if (
        isset($_SESSION[$sessionKey])
        && filter_var($_SESSION[$sessionKey], FILTER_VALIDATE_INT) !== false
        && (int)$_SESSION[$sessionKey] > 0
    ) {
        $buyerAuthUserId = (int)$_SESSION[$sessionKey];
        break;
    }
}

try {
    if ($buyerAuthUserId <= 0) {
        foreach (['user_email', 'loggedEmail', 'email'] as $emailKey) {
            $sessionEmail = strtolower(trim((string)($_SESSION[$emailKey] ?? '')));

            if (
                $sessionEmail === ''
                || strlen($sessionEmail) > 100
                || !filter_var($sessionEmail, FILTER_VALIDATE_EMAIL)
            ) {
                continue;
            }

            $emailStmt = $conn->prepare(
                'SELECT id
                 FROM users
                 WHERE LOWER(email) = LOWER(?)
                 LIMIT 1'
            );

            if (!$emailStmt) {
                throw new RuntimeException('Unable to prepare buyer email lookup.');
            }

            $emailStmt->bind_param('s', $sessionEmail);
            $emailStmt->execute();
            $emailStmt->bind_result($emailUserId);

            if ($emailStmt->fetch()) {
                $buyerAuthUserId = (int)$emailUserId;
            }

            $emailStmt->close();

            if ($buyerAuthUserId > 0) {
                break;
            }
        }
    }

    if ($buyerAuthUserId <= 0) {
        lokalcraftBuyerAuthReject();
    }

    $buyerAuthStmt = $conn->prepare(
        'SELECT id, firstname, lastname, email, account_status
         FROM users
         WHERE id = ?
         LIMIT 1'
    );

    if (!$buyerAuthStmt) {
        throw new RuntimeException('Unable to prepare buyer account lookup.');
    }

    $buyerAuthStmt->bind_param('i', $buyerAuthUserId);
    $buyerAuthStmt->execute();
    $buyerAuthStmt->bind_result(
        $dbUserId,
        $dbFirstName,
        $dbLastName,
        $dbEmail,
        $dbAccountStatus
    );

    $buyerAuthUser = null;

    if ($buyerAuthStmt->fetch()) {
        $buyerAuthUser = [
            'id' => (int)$dbUserId,
            'firstname' => (string)$dbFirstName,
            'lastname' => (string)$dbLastName,
            'email' => (string)$dbEmail,
            'account_status' => (string)$dbAccountStatus,
        ];
    }

    $buyerAuthStmt->close();

    if (!$buyerAuthUser) {
        $_SESSION = [];
        session_regenerate_id(true);
        lokalcraftBuyerAuthReject('Your account is not available for Buyer Portal access.');
    }

    $buyerAccountStatus = strtolower(trim((string)$buyerAuthUser['account_status']));
    $buyerApplicationStatus = 'none';

    $applicationStmt = $conn->prepare(
        'SELECT status
         FROM seller_applications
         WHERE user_id = ?
         ORDER BY submitted_at DESC, application_id DESC
         LIMIT 1'
    );

    if (!$applicationStmt) {
        throw new RuntimeException('Unable to prepare seller application lookup.');
    }

    $applicationStmt->bind_param('i', $buyerAuthUserId);
    $applicationStmt->execute();
    $applicationStmt->bind_result($dbApplicationStatus);

    if ($applicationStmt->fetch()) {
        $buyerApplicationStatus = strtolower(trim((string)$dbApplicationStatus));
    }

    $applicationStmt->close();
} catch (Throwable $e) {
    error_log('LokalCraft buyer authentication failed: ' . $e->getMessage());
    lokalcraftBuyerAuthFailServer();
}

$buyerAccessAllowed =
    $buyerAccountStatus === 'active'
    || (
        $buyerAccountStatus === 'pending'
        && $buyerApplicationStatus === 'pending'
    );

if (!$buyerAccessAllowed) {
    $_SESSION = [];
    session_regenerate_id(true);
    lokalcraftBuyerAuthReject('Your account is not available for Buyer Portal access.');
}

$buyerFullName = trim(
    (string)$buyerAuthUser['firstname'] . ' ' .
    (string)$buyerAuthUser['lastname']
);

if ($buyerFullName === '') {
    $buyerFullName = 'Buyer';
}

$_SESSION['user_id'] = (int)$buyerAuthUser['id'];
$_SESSION['buyer_id'] = (int)$buyerAuthUser['id'];
$_SESSION['user_name'] = $buyerFullName;
$_SESSION['buyer_name'] = $buyerFullName;
$_SESSION['user_email'] = (string)$buyerAuthUser['email'];
$_SESSION['seller_application_status'] = $buyerApplicationStatus;

$buyerId = (int)$buyerAuthUser['id'];
$userName = $buyerFullName;
