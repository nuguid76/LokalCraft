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
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/otp_service.php';
require_once __DIR__ . '/includes/otp_modal.php';
$error = '';
$email = '';
$forgotFlowKey = 'forgot_password';
$forgotEmailValue = trim((string)($_SESSION['forgot_form_email'] ?? ''));
$forgotEmailError = trim((string)($_SESSION['forgot_email_error'] ?? ''));
$forgotOtpError = trim((string)($_SESSION['forgot_otp_error'] ?? ''));
$forgotOtpSuccess = trim((string)($_SESSION['forgot_otp_success'] ?? ''));
$forgotResetError = trim((string)($_SESSION['forgot_reset_error'] ?? ''));
unset(
    $_SESSION['forgot_email_error'],
    $_SESSION['forgot_otp_error'],
    $_SESSION['forgot_otp_success'],
    $_SESSION['forgot_reset_error']
);
$loginNotice = trim((string)(
    $_SESSION['login_notice']
    ?? $_SESSION['login_success']
    ?? ''
));
unset($_SESSION['login_notice'], $_SESSION['login_success']);
if ($loginNotice === '') {
    $sellerSessionNotice = isset($_GET['seller_session']) && is_string($_GET['seller_session'])
        ? strtolower(trim($_GET['seller_session']))
        : '';
    $logoutNotice = isset($_GET['logout']) && is_string($_GET['logout'])
        ? strtolower(trim($_GET['logout']))
        : '';
    if ($logoutNotice === 'success') {
        $loginNotice = 'You have been logged out successfully. Please log in again to continue.';
    } elseif ($sellerSessionNotice === 'expired') {
        $loginNotice = 'Your Seller Portal session has ended. Please log in again.';
    } elseif ($sellerSessionNotice === 'invalid') {
        $loginNotice = 'Your Seller Portal session is no longer valid. Please log in again.';
    }
}
$loginWindowSeconds = 15 * 60;
$maxLoginFailures = 5;
$now = time();
$previousFailures = $_SESSION['login_failures'] ?? [];
if (!is_array($previousFailures)) {
    $previousFailures = [];
}
$_SESSION['login_failures'] = array_values(array_filter(
    $previousFailures,
    static fn ($timestamp) => is_int($timestamp) && $timestamp > $now - $loginWindowSeconds
));
if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}
function lokalcraftForgotFindUser(mysqli $conn, string $email): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, firstname, lastname, email, account_status
         FROM users
         WHERE LOWER(email) = LOWER(?)
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the password reset lookup.');
    }
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->bind_result($id, $firstName, $lastName, $storedEmail, $accountStatus);
    if (!$stmt->fetch()) {
        $stmt->close();
        return null;
    }
    $stmt->close();
    return [
        'id' => (int)$id,
        'firstname' => (string)$firstName,
        'lastname' => (string)$lastName,
        'email' => (string)$storedEmail,
        'account_status' => strtolower(trim((string)$accountStatus)),
    ];
}
function lokalcraftForgotCreateDummyChallenge(string $flowKey, string $email): void
{
    $fakeOtp = otpGenerateCode();
    $_SESSION[otpSessionKey($flowKey)] = [
        'email' => $email,
        'recipient_name' => 'LokalCraft User',
        'payload' => [
            'user_id' => 0,
            'dummy' => true,
        ],
        'purpose_text' => 'reset your LokalCraft password',
        'otp_hash' => password_hash($fakeOtp, PASSWORD_DEFAULT),
        'expires_at' => time() + 600,
        'resend_after' => time() + 60,
        'attempts' => 0,
        'max_attempts' => 5,
        'ttl_seconds' => 600,
        'resend_cooldown' => 60,
    ];
}
function lokalcraftForgotResend(string $flowKey): array
{
    $challenge = otpGetChallenge($flowKey);
    if (!$challenge) {
        return [
            'success' => false,
            'message' => 'Your verification session was not found. Please start again.',
        ];
    }
    $payload = is_array($challenge['payload'] ?? null) ? $challenge['payload'] : [];
    if (empty($payload['dummy'])) {
        return otpResendChallenge($flowKey);
    }
    $wait = otpResendSeconds($flowKey);
    if ($wait > 0) {
        return [
            'success' => false,
            'message' => 'Please wait ' . $wait . ' second' . ($wait === 1 ? '' : 's') . ' before requesting another code.',
        ];
    }
    $fakeOtp = otpGenerateCode();
    $challenge['otp_hash'] = password_hash($fakeOtp, PASSWORD_DEFAULT);
    $challenge['expires_at'] = time() + 600;
    $challenge['resend_after'] = time() + 60;
    $challenge['attempts'] = 0;
    $_SESSION[otpSessionKey($flowKey)] = $challenge;
    return [
        'success' => true,
        'message' => 'If this email is registered, a new verification code has been sent.',
    ];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) && is_string($_POST['action'])
        ? trim($_POST['action'])
        : 'login';
    $token = '';
    if (isset($_POST['login_csrf']) && is_string($_POST['login_csrf'])) {
        $token = $_POST['login_csrf'];
    } elseif (isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])) {
        $token = $_POST['csrf_token'];
    }
    $isForgotAction = str_starts_with($action, 'forgot_');
    if (!hash_equals((string)$_SESSION['login_csrf'], $token)) {
        if ($isForgotAction) {
            $_SESSION['forgot_email_error'] = 'Your session expired. Please reload the page and try again.';
            header('Location: login.php?forgot=email');
            exit;
        }
        $error = 'Your session expired. Please reload this page and try again.';
    } elseif ($action === 'forgot_request_otp') {
        $forgotEmail = isset($_POST['forgot_email']) && is_string($_POST['forgot_email'])
            ? strtolower(trim($_POST['forgot_email']))
            : '';
        $_SESSION['forgot_form_email'] = $forgotEmail;
        if ($forgotEmail === '' || strlen($forgotEmail) > 100 || !filter_var($forgotEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['forgot_email_error'] = 'Please enter a valid email address.';
            header('Location: login.php?forgot=email');
            exit;
        }
        try {
            $user = lokalcraftForgotFindUser($conn, $forgotEmail);
            if ($user && in_array($user['account_status'], ['active', 'pending'], true)) {
                otpStartChallenge(
                    $forgotFlowKey,
                    $user['email'],
                    trim($user['firstname'] . ' ' . $user['lastname']),
                    [
                        'user_id' => $user['id'],
                        'dummy' => false,
                    ],
                    'reset your LokalCraft password'
                );
            } else {
                lokalcraftForgotCreateDummyChallenge($forgotFlowKey, $forgotEmail);
            }
            $_SESSION['forgot_otp_success'] =
                'If this email is registered with LokalCraft, a 6-digit verification code has been sent.';
            header('Location: login.php?forgot=otp');
            exit;
        } catch (Throwable $e) {
            error_log('LokalCraft forgot password request failed: ' . $e->getMessage());
            $_SESSION['forgot_email_error'] =
                'Unable to send a verification code right now. Please try again.';
            header('Location: login.php?forgot=email');
            exit;
        }
    } elseif ($action === 'forgot_resend_otp') {
        $result = lokalcraftForgotResend($forgotFlowKey);
        if (!empty($result['success'])) {
            $_SESSION['forgot_otp_success'] = (string)$result['message'];
        } else {
            $_SESSION['forgot_otp_error'] = (string)$result['message'];
        }
        header('Location: login.php?forgot=otp');
        exit;
    } elseif ($action === 'forgot_change_email') {
        otpClearChallenge($forgotFlowKey);
        header('Location: login.php?forgot=email');
        exit;
    } elseif ($action === 'forgot_verify_otp') {
        $verification = otpVerifyChallenge(
            $forgotFlowKey,
            isset($_POST['otp']) && is_string($_POST['otp']) ? $_POST['otp'] : ''
        );
        if (empty($verification['success'])) {
            $_SESSION['forgot_otp_error'] = (string)$verification['message'];
            header('Location: login.php?forgot=otp');
            exit;
        }
        $payload = is_array($verification['payload'] ?? null)
            ? $verification['payload']
            : [];
        $userId = (int)($payload['user_id'] ?? 0);
        $isDummy = !empty($payload['dummy']);
        if ($isDummy || $userId <= 0) {
            otpClearChallenge($forgotFlowKey);
            $_SESSION['forgot_email_error'] =
                'Unable to verify this password reset request. Please start again.';
            header('Location: login.php?forgot=email');
            exit;
        }
        $_SESSION['forgot_password_verified'] = [
            'user_id' => $userId,
            'email' => (string)($verification['email'] ?? ''),
            'verified_at' => time(),
            'expires_at' => time() + 600,
        ];
        otpClearChallenge($forgotFlowKey);
        unset($_SESSION['forgot_form_email']);
        header('Location: login.php?forgot=reset');
        exit;
    } elseif ($action === 'forgot_reset_password') {
        $verifiedReset = $_SESSION['forgot_password_verified'] ?? null;
        if (!is_array($verifiedReset)
            || (int)($verifiedReset['user_id'] ?? 0) <= 0
            || (int)($verifiedReset['expires_at'] ?? 0) < time()
        ) {
            unset($_SESSION['forgot_password_verified']);
            $_SESSION['forgot_email_error'] =
                'Your password reset session expired. Please request a new verification code.';
            header('Location: login.php?forgot=email');
            exit;
        }
        $newPassword = isset($_POST['new_password']) && is_string($_POST['new_password'])
            ? $_POST['new_password']
            : '';
        $confirmPassword = isset($_POST['confirm_password']) && is_string($_POST['confirm_password'])
            ? $_POST['confirm_password']
            : '';
        if (strlen($newPassword) < 8) {
            $_SESSION['forgot_reset_error'] = 'New password must be at least 8 characters long.';
            header('Location: login.php?forgot=reset');
            exit;
        }
        if (!preg_match('/[A-Z]/', $newPassword)) {
            $_SESSION['forgot_reset_error'] = 'New password must include at least one uppercase letter.';
            header('Location: login.php?forgot=reset');
            exit;
        }
        if (!preg_match('/[a-z]/', $newPassword)) {
            $_SESSION['forgot_reset_error'] = 'New password must include at least one lowercase letter.';
            header('Location: login.php?forgot=reset');
            exit;
        }
        if (!preg_match('/[0-9]/', $newPassword)) {
            $_SESSION['forgot_reset_error'] = 'New password must include at least one number.';
            header('Location: login.php?forgot=reset');
            exit;
        }
        if (!preg_match('/[^A-Za-z0-9]/', $newPassword)) {
            $_SESSION['forgot_reset_error'] = 'New password must include at least one special character.';
            header('Location: login.php?forgot=reset');
            exit;
        }
        if ($newPassword !== $confirmPassword) {
            $_SESSION['forgot_reset_error'] = 'The confirmation password does not match the new password.';
            header('Location: login.php?forgot=reset');
            exit;
        }
        try {
            $resetUserId = (int)$verifiedReset['user_id'];
            $resetEmail = trim((string)($verifiedReset['email'] ?? ''));
            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            if (!is_string($newPasswordHash) || $newPasswordHash === '') {
                throw new RuntimeException('Unable to secure the new password.');
            }
            $resetStmt = $conn->prepare("
                UPDATE users
                SET password = ?
                WHERE id = ?
                  AND LOWER(email) = LOWER(?)
                  AND account_status <> 'disabled'
                LIMIT 1
            ");
            if (!$resetStmt) {
                throw new RuntimeException('Unable to prepare the password update.');
            }
            $resetStmt->bind_param('sis', $newPasswordHash, $resetUserId, $resetEmail);
            $resetStmt->execute();
            $affected = $resetStmt->affected_rows;
            $resetStmt->close();
            if ($affected < 1) {
                throw new RuntimeException('No matching user was updated.');
            }
            unset($_SESSION['forgot_password_verified']);
            $_SESSION['login_notice'] =
                'Password updated successfully. Please sign in using your new password.';
            header('Location: login.php?password_reset=success');
            exit;
        } catch (Throwable $e) {
            error_log('LokalCraft password reset failed: ' . $e->getMessage());
            $_SESSION['forgot_reset_error'] =
                'Unable to update your password right now. Please try again.';
            header('Location: login.php?forgot=reset');
            exit;
        }
    } else {
        $email = isset($_POST['email']) && is_string($_POST['email']) ? strtolower(trim($_POST['email'])) : '';
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        if ($email === '' || $password === '') {
            $error = 'Please enter your email and password.';
        } elseif (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (count($_SESSION['login_failures']) >= $maxLoginFailures) {
            $remainingSeconds = $loginWindowSeconds - ($now - $_SESSION['login_failures'][0]);
            $waitMinutes = max(1, (int)ceil($remainingSeconds / 60));
            $error = 'Too many login attempts. Please try again in ' . $waitMinutes . ' minute(s).';
        } else {
            try {
                $stmt = $conn->prepare("
                    SELECT id, firstname, lastname, email, password,
                           sellerOrNot, adminOrNot, account_status
                    FROM users
                    WHERE LOWER(email) = LOWER(?)
                    LIMIT 1
                ");
                if (!$stmt) {
                    throw new RuntimeException('Unable to prepare the login request.');
                }
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $stmt->bind_result(
                    $id,
                    $firstName,
                    $lastName,
                    $storedEmail,
                    $passwordHash,
                    $sellerFlag,
                    $adminFlag,
                    $accountStatus
                );
                $found = $stmt->fetch();
                $stmt->close();
                if (!$found || !password_verify($password, (string)$passwordHash)) {
                    $_SESSION['login_failures'][] = $now;
                    $error = count($_SESSION['login_failures']) >= $maxLoginFailures
                        ? 'Too many login attempts. Please try again in 15 minutes.'
                        : 'Invalid email or password. Please try again.';
                } elseif (strtolower(trim((string)$accountStatus)) === 'disabled') {
                    $error = 'This account has been disabled. Please contact the LokalCraft administrator.';
                } else {
                    $userId = (int)$id;
                    $userName = trim((string)$firstName . ' ' . (string)$lastName);
                    $isAdmin = (int)$adminFlag === 1;
                    $accountStatusNormalized = strtolower(trim((string)$accountStatus));
                    $isApprovedSeller = false;
                    $applicationStatus = 'none';
                    $applicationRejectionReason = '';
                    $businessName = 'Seller Portal';
                    if (!$isAdmin) {
                        $applicationStmt = $conn->prepare("
                            SELECT status, rejection_reason
                            FROM seller_applications
                            WHERE user_id = ?
                            ORDER BY submitted_at DESC, application_id DESC
                            LIMIT 1
                        ");
                        if (!$applicationStmt) {
                            throw new RuntimeException('Unable to load the seller application status.');
                        }
                        $applicationStmt->bind_param('i', $userId);
                        $applicationStmt->execute();
                        $applicationStmt->bind_result(
                            $latestApplicationStatus,
                            $latestRejectionReason
                        );
                        if ($applicationStmt->fetch()) {
                            $applicationStatus = strtolower(trim((string)$latestApplicationStatus));
                            $applicationRejectionReason = trim((string)$latestRejectionReason);
                        }
                        $applicationStmt->close();
                    }
                    $isPendingSellerApplicant =
                        !$isAdmin
                        && $applicationStatus === 'pending'
                        && $accountStatusNormalized === 'pending';
                    if ($accountStatusNormalized !== 'active' && !$isPendingSellerApplicant) {
                        $error = 'Your account is pending activation. Please contact the LokalCraft administrator.';
                    } else {
                        if (!$isAdmin) {
                            $isApprovedSeller =
                                (int)$sellerFlag === 1
                                && $applicationStatus === 'approved'
                                && $accountStatusNormalized === 'active';
                            if ($isApprovedSeller) {
                                $businessStmt = $conn->prepare("
                                    SELECT businessname
                                    FROM businessinfo
                                    WHERE user_id = ?
                                    LIMIT 1
                                ");
                                if (!$businessStmt) {
                                    throw new RuntimeException('Unable to load the seller business information.');
                                }
                                $businessStmt->bind_param('i', $userId);
                                $businessStmt->execute();
                                $businessStmt->bind_result($dbBusinessName);
                                if ($businessStmt->fetch() && trim((string)$dbBusinessName) !== '') {
                                    $businessName = (string)$dbBusinessName;
                                }
                                $businessStmt->close();
                            }
                        }
                        $_SESSION = [];
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $userId;
                        $_SESSION['user_name'] = $userName;
                        $_SESSION['user_email'] = $storedEmail;
                        $_SESSION['user_role'] = 'buyer';
                        $_SESSION['buyer_id'] = $userId;
                        $_SESSION['buyer_name'] = $userName;
                        $_SESSION['is_admin'] = $isAdmin;
                        $_SESSION['is_seller'] = false;
                        unset($_SESSION['seller_application_rejection_reason']);
                        if ($isAdmin) {
                            $_SESSION['user_role'] = 'admin';
                            $_SESSION['admin_name'] = $userName;
                            header('Location: Admin%20Interface/dashboard.php');
                            exit;
                        }
                        if ($isApprovedSeller) {
                            $_SESSION['user_role'] = 'seller';
                            $_SESSION['is_seller'] = true;
                            $_SESSION['seller_user_id'] = $userId;
                            $_SESSION['seller_name'] = $userName;
                            $_SESSION['seller_business_name'] = $businessName;
                            header('Location: Seller%20Interface/seller_dashboard.php');
                            exit;
                        }
                        $_SESSION['seller_application_status'] = $applicationStatus;
                        if ($applicationStatus === 'rejected' && $applicationRejectionReason !== '') {
                            $_SESSION['seller_application_rejection_reason'] = $applicationRejectionReason;
                        }
                        header('Location: Buyer%20Interface/foryou.php');
                        exit;
                    }
                }
            } catch (Throwable $e) {
                error_log('LokalCraft login database error: ' . $e->getMessage());
                $error = 'Login is temporarily unavailable. Please try again later.';
            }
        }
    }
}
$forgotStage = isset($_GET['forgot']) && is_string($_GET['forgot'])
    ? strtolower(trim($_GET['forgot']))
    : '';
$pendingForgot = otpGetChallenge($forgotFlowKey);
if ($forgotStage === 'otp' && !$pendingForgot) {
    $forgotStage = 'email';
    if ($forgotEmailError === '') {
        $forgotEmailError = 'Your verification session expired. Enter your email to request a new code.';
    }
}
$verifiedForgotReset = $_SESSION['forgot_password_verified'] ?? null;
if (is_array($verifiedForgotReset)
    && (int)($verifiedForgotReset['expires_at'] ?? 0) < time()
) {
    unset($_SESSION['forgot_password_verified']);
    $verifiedForgotReset = null;
}
if ($forgotStage === 'reset' && !is_array($verifiedForgotReset)) {
    $forgotStage = 'email';
    if ($forgotEmailError === '') {
        $forgotEmailError = 'Your password reset session expired. Please request a new verification code.';
    }
}
$forgotEmailModalOpen = $forgotStage === 'email';
$forgotOtpModalOpen = $forgotStage === 'otp' && is_array($pendingForgot);
$forgotResetModalOpen = $forgotStage === 'reset' && is_array($verifiedForgotReset);
$forgotOtpMaskedEmail = $forgotOtpModalOpen
    ? otpMaskEmail((string)$pendingForgot['email'])
    : '';
$forgotOtpResendSeconds = $forgotOtpModalOpen
    ? otpResendSeconds($forgotFlowKey)
    : 0;
$forgotResetMaskedEmail = $forgotResetModalOpen
    ? otpMaskEmail((string)($verifiedForgotReset['email'] ?? ''))
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Login — LokalCraft | Support Filipino Artisans</title>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }
    html,
    body {
      min-height: 100%;
    }
    body {
      font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, sans-serif;
      position: relative;
      min-height: 100vh;
      background-image: url('uploads/website background.png');
      background-size: cover;
      background-position: center center;
      background-repeat: no-repeat;
      background-attachment: scroll;
      overflow-x: hidden;
    }
    body::before {
      content: "";
      position: fixed;
      inset: 0;
      background-color: rgba(255, 248, 235, 0.60);
      backdrop-filter: blur(1px);
      pointer-events: none;
      z-index: 0;
    }
    .public-nav-container {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      z-index: 10000;
      pointer-events: none;
    }
    .public-nav-container .public-nav-inner {
      width: 100%;
      max-width: 1380px;
      margin: 0 auto;
      padding: 0 32px;
      position: relative;
      z-index: 10001;
      pointer-events: auto;
      isolation: isolate;
      overflow: visible;
    }
    .public-nav-container .public-header {
      position: relative;
      z-index: 10002;
      overflow: visible !important;
    }
    .public-nav-container .public-header-inner {
      position: relative;
      z-index: 10003;
      overflow: visible !important;
    }
    .public-nav-container .nav-menu,
    .public-nav-container .mobile-menu,
    .public-nav-container .menu,
    .public-nav-container .dropdown,
    .public-nav-container .dropdown-menu,
    .public-nav-container .menu-panel,
    .public-nav-container .mobile-nav,
    .public-nav-container [class*="menu"],
    .public-nav-container [class*="dropdown"] {
      z-index: 10004;
    }
    .container {
      max-width: 1380px;
      width: 100%;
      margin: 0 auto;
      padding: 0 32px;
      position: relative;
      z-index: 1;
    }
    .navbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: nowrap;
      padding: 20px 0;
      gap: 20px;
      border-bottom: 1px solid rgba(160, 110, 70, 0.25);
      backdrop-filter: blur(2px);
    }
    .login-page {
      min-height: 100svh;
      width: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 138px 20px 40px;
      position: relative;
      z-index: 1;
    }
    .login-container {
      width: 100%;
      max-width: 420px;
      position: relative;
      z-index: 1;
    }
.login-card {
      background: rgba(255, 252, 245, 0.95);
      backdrop-filter: blur(8px);
      border-radius: 32px;
      padding: 48px 40px;
      box-shadow: 0 25px 40px -8px rgba(0, 0, 0, 0.15);
      border: 1px solid rgba(220, 180, 135, 0.6);
    }
    .login-header {
      text-align: center;
      margin-bottom: 32px;
    }
.login-title {
      font-size: 2rem;
      font-weight: 800;
      color: #8b5423;
      margin-bottom: 8px;
      line-height: 1.2;
    }
    .login-subtitle {
      font-size: 0.95rem;
      color: #5A3F28;
      line-height: 1.5;
    }
    .login-form {
      display: flex;
      flex-direction: column;
      gap: 20px;
    }
    .form-group {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    .form-group label {
      font-weight: 600;
      color: #2B1E12;
      font-size: 0.9rem;
    }
    .form-group input {
      background: #F9EFE2;
      border: 1.5px solid #E7CDB0;
      border-radius: 16px;
      padding: 14px 18px;
      font-size: 1rem;
      color: #2B1E12;
      font-family: inherit;
      transition: all 0.2s;
    }
    .password-wrap {
      position: relative;
      width: 100%;
    }
    .password-wrap input {
      width: 100%;
      padding-right: 52px;
    }
    .password-toggle {
      position: absolute;
      top: 50%;
      right: 12px;
      transform: translateY(-50%);
      width: 36px;
      height: 36px;
      display: grid;
      place-items: center;
      border: 0;
      border-radius: 50%;
      background: transparent;
      color: #8b5423;
      cursor: pointer;
      z-index: 5;
      -webkit-tap-highlight-color: transparent;
      touch-action: manipulation;
    }
    .password-toggle svg {
      width: 20px;
      height: 20px;
      pointer-events: none;
    }
    .password-toggle:hover,
    .password-toggle:focus-visible {
      background: rgba(184, 92, 40, 0.10);
      outline: none;
    }
    .form-group input::placeholder {
      color: #9A7F69;
    }
    .form-group input:focus {
      outline: none;
      border-color: #B85C28;
      background: #FFFAF4;
      box-shadow: 0 0 0 3px rgba(184, 92, 40, 0.1);
    }
    .btn-login {
      background-color: #B85C28;
      border: none;
      color: white;
      font-weight: 700;
      font-size: 1rem;
      padding: 14px 38px;
      border-radius: 60px;
      cursor: pointer;
      font-family: inherit;
      transition: all 0.2s ease;
      box-shadow: 0 6px 14px rgba(160, 80, 35, 0.3);
      margin-top: 10px;
    }
    .btn-login:hover {
      background-color: #94481F;
      transform: translateY(-2px);
      box-shadow: 0 8px 18px rgba(160, 80, 35, 0.4);
    }
    .btn-login:disabled {
      opacity: 0.7;
      cursor: wait;
      transform: none;
    }
    .btn-login:active {
      transform: translateY(0);
    }
    .signup-prompt {
      text-align: center;
      margin-top: 24px;
      font-size: 0.95rem;
      color: #5A3F28;
    }
    .signup-prompt a {
      color: #B85C28;
      font-weight: 600;
      text-decoration: none;
      transition: color 0.2s;
    }
    .signup-prompt a:hover {
      color: #94481F;
      text-decoration: underline;
    }
    .error-box {
      background: rgba(220, 53, 69, 0.10);
      border: 1px solid rgba(220, 53, 69, 0.25);
      color: #8b1e1e;
      border-radius: 12px;
      padding: 12px 14px;
      margin-bottom: 16px;
      font-size: 0.9rem;
      font-weight: 600;
    }
    .status-box {
      background: #e3f2fd;
      border: 1px solid #b9d8ef;
      color: #155b89;
      border-radius: 12px;
      padding: 12px 14px;
      margin-bottom: 16px;
      font-size: 0.9rem;
      line-height: 1.45;
    }
@media (max-width: 600px) {
      .login-card {
        padding: 36px 28px;
        border-radius: 28px;
      }
      .login-title {
        font-size: 1.6rem;
      }
.form-group input {
        padding: 12px 16px;
        font-size: 16px;
      }
      .btn-login {
        padding: 12px 32px;
        font-size: 0.95rem;
      }
      body {
        padding: 0;
      }
    }
    @media (max-width: 600px) {
      .login-page {
        min-height: 100svh;
        padding: 122px 16px 32px;
      }
    }
    .public-header-inner {
      width: 100%;
    }
    @media (max-width: 400px) {
      .login-card {
        padding: 28px 20px;
      }
      .login-title {
        font-size: 1.4rem;
      }
}
    @media (max-width: 600px) {
      body {
        background-attachment: scroll;
        background-position: center center;
      }
    }
    .forgot-password-row {
      display: flex;
      justify-content: flex-end;
      margin-top: -8px;
    }
    .forgot-password-link {
      border: 0;
      background: transparent;
      color: #B85C28;
      padding: 0;
      font: inherit;
      font-size: 0.88rem;
      font-weight: 700;
      cursor: pointer;
      text-decoration: none;
    }
    .forgot-password-link:hover,
    .forgot-password-link:focus-visible {
      color: #94481F;
      text-decoration: underline;
      outline: none;
    }
    .forgot-modal {
      position: fixed;
      inset: 0;
      z-index: 30000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
      background: rgba(43, 30, 18, 0.48);
      backdrop-filter: blur(4px);
    }
    .forgot-modal.open {
      display: flex;
    }
    .forgot-card {
      width: min(470px, 100%);
      position: relative;
      border: 1px solid #E7CDB0;
      border-radius: 24px;
      background: #FFFCF7;
      box-shadow: 0 28px 70px rgba(45, 30, 16, 0.24);
      padding: 28px;
    }
    .forgot-close {
      position: absolute;
      top: 14px;
      right: 14px;
      width: 36px;
      height: 36px;
      display: grid;
      place-items: center;
      border: 0;
      border-radius: 50%;
      background: transparent;
      color: #8b5423;
      font-size: 1.5rem;
      line-height: 1;
      cursor: pointer;
    }
    .forgot-close:hover,
    .forgot-close:focus-visible {
      background: rgba(184, 92, 40, 0.10);
      outline: none;
    }
    .forgot-icon {
      width: 58px;
      height: 58px;
      display: grid;
      place-items: center;
      margin: 0 auto 14px;
      border-radius: 18px;
      background: #F9EFE2;
      color: #A55325;
      font-size: 1.45rem;
      font-weight: 900;
    }
    .forgot-card h2 {
      margin: 0;
      color: #8b5423;
      text-align: center;
      font-size: 1.55rem;
    }
    .forgot-description {
      margin: 8px auto 20px;
      max-width: 370px;
      color: #6f5a47;
      text-align: center;
      font-size: 0.9rem;
      line-height: 1.55;
    }
    .forgot-field {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    .forgot-field label {
      color: #2B1E12;
      font-size: 0.9rem;
      font-weight: 700;
    }
    .forgot-field input {
      width: 100%;
      background: #F9EFE2;
      border: 1.5px solid #E7CDB0;
      border-radius: 16px;
      padding: 14px 18px;
      color: #2B1E12;
      font: inherit;
      font-size: 1rem;
      outline: none;
    }
    .forgot-field input:focus {
      border-color: #B85C28;
      background: #FFFAF4;
      box-shadow: 0 0 0 3px rgba(184, 92, 40, 0.10);
    }
    .forgot-message {
      margin-bottom: 16px;
      padding: 10px 12px;
      border: 1px solid #efc0c0;
      border-radius: 12px;
      background: #fff2f2;
      color: #a83d3d;
      font-size: 0.82rem;
      line-height: 1.45;
      text-align: center;
    }
    .forgot-primary {
      width: 100%;
      margin-top: 18px;
      border: 0;
      border-radius: 60px;
      background: #B85C28;
      color: #fff;
      padding: 14px 18px;
      font: inherit;
      font-size: 0.96rem;
      font-weight: 800;
      cursor: pointer;
      box-shadow: 0 6px 14px rgba(160, 80, 35, 0.26);
    }
    .forgot-primary:hover {
      background: #94481F;
    }
    .forgot-primary:disabled {
      opacity: 0.68;
      cursor: wait;
    }
    .forgot-helper {
      margin-top: 12px;
      color: #927b68;
      text-align: center;
      font-size: 0.76rem;
      line-height: 1.45;
    }
    .forgot-reset-card {
      padding: 32px;
    }
    .forgot-reset-form {
      display: flex;
      flex-direction: column;
      gap: 18px;
    }
    .forgot-reset-form .forgot-field {
      gap: 8px;
      margin: 0;
    }
    .forgot-reset-form .password-wrap {
      width: 100%;
    }
    .forgot-reset-form .forgot-helper {
      margin: -2px 2px 0;
      text-align: left;
    }
    .forgot-reset-form .forgot-primary {
      margin-top: 2px;
    }
    .reset-password-toggle .reset-eye-open,
    .reset-password-toggle .reset-eye-closed {
      width: 20px;
      height: 20px;
      pointer-events: none;
    }
    .password-requirements {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 7px 14px;
      margin-top: 10px;
      padding: 12px 14px;
      border: 1px solid #E7CDB0;
      border-radius: 14px;
      background: #FFF9F1;
    }
    .password-requirements-title {
      grid-column: 1 / -1;
      margin: 0 0 2px;
      color: #6F5139;
      font-size: 0.78rem;
      font-weight: 800;
    }
    .password-rule {
      display: flex;
      align-items: center;
      gap: 7px;
      color: #8A7563;
      font-size: 0.76rem;
      line-height: 1.35;
    }
    .password-rule-icon {
      width: 17px;
      height: 17px;
      flex: 0 0 17px;
      display: grid;
      place-items: center;
      border: 1.5px solid #C9B29D;
      border-radius: 50%;
      font-size: 0.68rem;
      font-weight: 900;
      line-height: 1;
    }
    .password-rule.valid {
      color: #2F7A4F;
      font-weight: 700;
    }
    .password-rule.valid .password-rule-icon {
      border-color: #2F7A4F;
      background: #EAF7EF;
      color: #2F7A4F;
    }
    .password-rule.invalid {
      color: #A83D3D;
    }
    .password-rule.invalid .password-rule-icon {
      border-color: #D99A9A;
      background: #FFF2F2;
      color: #A83D3D;
    }
    .password-inline-feedback {
      min-height: 18px;
      margin-top: 7px;
      padding-left: 2px;
      font-size: 0.77rem;
      font-weight: 700;
      line-height: 1.35;
    }
    .password-inline-feedback.neutral {
      color: #927B68;
    }
    .password-inline-feedback.success {
      color: #2F7A4F;
    }
    .password-inline-feedback.error {
      color: #A83D3D;
    }
    .forgot-field input.password-valid {
      border-color: #5AA377;
      background: #F7FCF9;
      box-shadow: 0 0 0 3px rgba(90, 163, 119, 0.10);
    }
    .forgot-field input.password-invalid {
      border-color: #C96B6B;
      background: #FFF8F8;
      box-shadow: 0 0 0 3px rgba(201, 107, 107, 0.10);
    }
    @media (max-width: 480px) {
      .forgot-card {
        padding: 24px 18px;
      }
      .password-requirements {
        grid-template-columns: 1fr;
      }
    }
    @media (max-width: 768px) {
      html,
      body {
        width: 100%;
        max-width: 100%;
        overflow-x: hidden;
      }
      body {
        min-height: 100dvh;
        background-attachment: scroll;
        background-position: center center;
      }
      .public-nav-container .public-nav-inner,
      .container {
        padding-left: 16px;
        padding-right: 16px;
      }
      .login-page {
        min-height: 100dvh;
        align-items: flex-start;
        padding:
          calc(108px + env(safe-area-inset-top))
          16px
          calc(28px + env(safe-area-inset-bottom));
      }
      .login-container {
        width: 100%;
        max-width: 440px;
        margin: 0 auto;
      }
      .login-card {
        width: 100%;
        padding: 34px 24px;
        border-radius: 24px;
      }
      .login-header {
        margin-bottom: 26px;
      }
      .login-title {
        font-size: clamp(1.55rem, 7vw, 1.8rem);
      }
      .login-subtitle {
        font-size: 0.9rem;
      }
      .login-form {
        gap: 18px;
      }
      .form-group input,
      .forgot-field input {
        min-height: 50px;
        font-size: 16px;
      }
      .password-wrap input {
        padding-right: 54px;
      }
      .password-toggle {
        right: 9px;
        width: 40px;
        height: 40px;
      }
      .forgot-password-row {
        margin-top: -4px;
      }
      .btn-login,
      .forgot-primary {
        width: 100%;
        min-height: 50px;
      }
      .signup-prompt {
        margin-top: 22px;
        line-height: 1.5;
      }
      .error-box,
      .status-box {
        overflow-wrap: anywhere;
      }
      .forgot-modal {
        align-items: flex-start;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        padding:
          max(16px, env(safe-area-inset-top))
          14px
          max(16px, env(safe-area-inset-bottom));
      }
      .forgot-card {
        width: 100%;
        max-width: 470px;
        max-height: calc(100dvh - 32px);
        margin: auto 0;
        padding: 26px 20px;
        border-radius: 22px;
        overflow-y: auto;
        overscroll-behavior: contain;
        -webkit-overflow-scrolling: touch;
      }
      .forgot-reset-card {
        padding: 26px 20px;
      }
      .forgot-icon {
        width: 54px;
        height: 54px;
        margin-bottom: 12px;
      }
      .forgot-card h2 {
        font-size: 1.45rem;
        padding: 0 34px;
      }
      .forgot-description {
        max-width: 100%;
        margin-bottom: 18px;
        font-size: 0.88rem;
      }
      .forgot-reset-form {
        gap: 16px;
      }
      .password-requirements {
        grid-template-columns: 1fr;
        gap: 7px;
        padding: 12px;
      }
      .password-requirements-title {
        margin-bottom: 1px;
      }
      .password-inline-feedback {
        overflow-wrap: anywhere;
      }
    }
    @media (max-width: 480px) {
      .public-nav-container .public-nav-inner,
      .container {
        padding-left: 12px;
        padding-right: 12px;
      }
      .login-page {
        padding:
          calc(98px + env(safe-area-inset-top))
          12px
          calc(22px + env(safe-area-inset-bottom));
      }
      .login-card {
        padding: 30px 20px;
        border-radius: 22px;
      }
      .login-header {
        margin-bottom: 22px;
      }
      .login-title {
        font-size: 1.55rem;
      }
      .login-subtitle {
        font-size: 0.86rem;
      }
      .form-group label,
      .forgot-field label {
        font-size: 0.86rem;
      }
      .form-group input,
      .forgot-field input {
        padding: 12px 15px;
      }
      .password-wrap input {
        padding-right: 52px;
      }
      .forgot-password-link {
        font-size: 0.84rem;
      }
      .btn-login,
      .forgot-primary {
        padding: 13px 18px;
      }
      .forgot-card,
      .forgot-reset-card {
        padding: 24px 17px;
        border-radius: 20px;
      }
      .forgot-close {
        top: 10px;
        right: 10px;
      }
      .forgot-card h2 {
        font-size: 1.35rem;
      }
      .forgot-description {
        font-size: 0.84rem;
        line-height: 1.5;
      }
      .password-requirements {
        margin-top: 8px;
      }
    }
    @media (max-width: 360px) {
      .login-page {
        padding-left: 10px;
        padding-right: 10px;
      }
      .login-card {
        padding: 26px 16px;
      }
      .login-title {
        font-size: 1.42rem;
      }
      .login-subtitle,
      .signup-prompt {
        font-size: 0.83rem;
      }
      .forgot-card,
      .forgot-reset-card {
        padding: 22px 14px;
      }
      .forgot-card h2 {
        font-size: 1.28rem;
      }
      .forgot-description {
        font-size: 0.81rem;
      }
      .password-rule {
        font-size: 0.73rem;
      }
    }
    @media (max-height: 620px) and (max-width: 900px) {
      .login-page {
        align-items: flex-start;
        padding-top: calc(92px + env(safe-area-inset-top));
      }
      .login-card {
        padding-top: 26px;
        padding-bottom: 26px;
      }
      .forgot-modal {
        align-items: flex-start;
      }
    }
    @media (max-width: 1440px) {
      .login-container {
        max-width: 420px;
      }
      .forgot-modal {
        overflow-y: auto;
        overscroll-behavior: contain;
        -webkit-overflow-scrolling: touch;
        padding: 24px;
      }
      .forgot-card {
        max-height: calc(100dvh - 48px);
        overflow-y: auto;
        overscroll-behavior: contain;
        -webkit-overflow-scrolling: touch;
      }
    }
    @media (max-width: 1440px) and (max-height: 900px) {
      .login-page {
        align-items: flex-start;
        padding-top: 112px;
        padding-bottom: 28px;
      }
      .login-card {
        padding: 36px 34px;
      }
      .login-header {
        margin-bottom: 26px;
      }
      .forgot-modal {
        align-items: flex-start;
        padding: 20px 24px;
      }
      .forgot-card {
        width: min(470px, 100%);
        max-height: calc(100dvh - 40px);
        margin: auto;
        padding: 26px;
      }
      .forgot-reset-card {
        padding: 26px;
      }
      .forgot-icon {
        width: 52px;
        height: 52px;
        margin-bottom: 10px;
      }
      .forgot-card h2 {
        font-size: 1.42rem;
      }
      .forgot-description {
        margin-bottom: 16px;
      }
      .forgot-reset-form {
        gap: 14px;
      }
      .password-requirements {
        gap: 6px 12px;
        padding: 10px 12px;
      }
      .forgot-primary {
        margin-top: 10px;
      }
    }
    @media (max-width: 1440px) and (max-height: 760px) {
      .login-page {
        padding-top: 96px;
        padding-bottom: 20px;
      }
      .login-card {
        padding: 30px 30px;
      }
      .login-header {
        margin-bottom: 22px;
      }
      .login-title {
        font-size: 1.7rem;
      }
      .login-subtitle {
        font-size: 0.88rem;
      }
      .login-form {
        gap: 16px;
      }
      .form-group input {
        padding-top: 12px;
        padding-bottom: 12px;
      }
      .forgot-modal {
        padding: 14px 20px;
      }
      .forgot-card {
        max-height: calc(100dvh - 28px);
        padding: 22px 24px;
      }
      .forgot-reset-card {
        padding: 22px 24px;
      }
      .forgot-icon {
        width: 46px;
        height: 46px;
        margin-bottom: 8px;
      }
      .forgot-card h2 {
        font-size: 1.34rem;
      }
      .forgot-description {
        margin-top: 6px;
        margin-bottom: 14px;
        font-size: 0.84rem;
      }
      .forgot-field input {
        padding-top: 11px;
        padding-bottom: 11px;
      }
      .forgot-reset-form {
        gap: 12px;
      }
      .password-requirements {
        margin-top: 6px;
        padding: 9px 11px;
      }
      .password-rule {
        font-size: 0.72rem;
      }
      .password-inline-feedback {
        margin-top: 5px;
      }
      .forgot-primary {
        min-height: 44px;
        margin-top: 4px;
        padding-top: 11px;
        padding-bottom: 11px;
      }
    }
    .forgot-modal {
      background: rgba(43, 30, 18, 0.55);
      backdrop-filter: blur(5px);
      padding: 20px;
    }
    .forgot-card {
      width: min(470px, 100%);
      max-height: calc(100dvh - 40px);
      overflow-y: auto;
      padding: 30px 28px 26px;
      border: 1px solid #E7CDB0;
      border-radius: 24px;
      background: #FFFCF7;
      box-shadow: 0 28px 70px rgba(45, 30, 16, 0.28);
    }
    .forgot-close {
      top: 12px;
      right: 12px;
      width: 36px;
      height: 36px;
      background: #F9EFE2;
      color: #8b5423;
      font-size: 1.4rem;
    }
    .forgot-icon {
      width: 58px;
      height: 58px;
      margin: 0 auto 14px;
      border-radius: 18px;
      background: #F9EFE2;
      color: #A55325;
      font-size: 1.45rem;
      font-weight: 900;
    }
    .forgot-card h2 {
      margin: 0;
      color: #8b5423;
      text-align: center;
      font-size: 1.5rem;
      line-height: 1.2;
    }
    .forgot-description {
      max-width: 390px;
      margin: 8px auto 22px;
      color: #6f5a47;
      text-align: center;
      font-size: 0.88rem;
      line-height: 1.55;
    }
    #forgotPasswordForm {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
    #forgotPasswordForm .forgot-field {
      gap: 8px;
    }
    #forgotPasswordForm .forgot-primary {
      margin-top: 0;
    }
    #forgotPasswordForm .forgot-helper {
      margin-top: -2px;
    }
    .forgot-message {
      margin: 0 0 18px;
      padding: 10px 12px;
      border-radius: 11px;
      font-size: 0.8rem;
      line-height: 1.4;
    }
    .forgot-field input {
      min-height: 50px;
      padding: 13px 16px;
      border-radius: 12px;
    }
    .forgot-primary {
      min-height: 48px;
      border-radius: 60px;
      padding: 13px 18px;
      box-shadow: none;
    }
    .forgot-reset-card {
      padding: 30px 28px 26px;
    }
    .forgot-reset-form {
      gap: 16px;
    }
    @media (max-width: 600px) {
      .forgot-modal {
        align-items: flex-start;
        overflow-y: auto;
        padding:
          max(12px, env(safe-area-inset-top))
          10px
          max(12px, env(safe-area-inset-bottom));
      }
      .forgot-card,
      .forgot-reset-card {
        width: 100%;
        max-width: 470px;
        max-height: calc(100dvh - 24px);
        margin: auto 0;
        padding: 26px 16px 22px;
        border-radius: 20px;
      }
      .forgot-description {
        margin-bottom: 18px;
      }
      #forgotPasswordForm {
        gap: 14px;
      }
      .forgot-field input {
        min-height: 48px;
      }
    }
  </style>
</head>
<body>
<div class="public-nav-container">
  <div class="public-nav-inner">
    <?php $publicActive = 'login'; require __DIR__ . '/public_nav.php'; ?>
  </div>
</div>
<main class="login-page">
<div class="login-container">
  <div class="login-card">
    <div class="login-header">
      <h1 class="login-title">Welcome Back</h1>
      <p class="login-subtitle">Connect with local artisans in Taguig and support authentic Filipino craftsmanship.</p>
    </div>
    <?php if ($loginNotice !== ''): ?>
      <div class="status-box" role="status">
        <?php echo htmlspecialchars($loginNotice, ENT_QUOTES, 'UTF-8'); ?>
      </div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
      <div class="error-box" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <form class="login-form" method="POST" action="login.php">
      <input type="hidden" name="action" value="login">
      <input type="hidden" name="login_csrf" value="<?php echo htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
      <div class="form-group">
        <label for="email">Email Address</label>
        <input
          type="email"
          id="email"
          name="email"
          maxlength="100"
          placeholder="artisan@gmail.com"
          value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"
          required
          autocomplete="email"
        >
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <div class="password-wrap">
          <input
            type="password"
            id="password"
            name="password"
            placeholder="Enter your password"
            required
            autocomplete="current-password"
          >
          <button type="button" class="password-toggle" id="passwordToggle" aria-label="Show password" aria-pressed="false">
            <svg id="eyeOpen" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"></path>
              <circle cx="12" cy="12" r="3"></circle>
            </svg>
            <svg id="eyeClosed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none">
              <path d="M3 3l18 18"></path>
              <path d="M10.6 10.6a2 2 0 002.8 2.8"></path>
              <path d="M9.9 4.2A10.8 10.8 0 0112 4c6.5 0 10 8 10 8a17.7 17.7 0 01-2.1 3.2"></path>
              <path d="M6.2 6.2C3.5 8.1 2 12 2 12s3.5 8 10 8a9.8 9.8 0 004.1-.9"></path>
            </svg>
          </button>
        </div>
      </div>
      <div class="forgot-password-row">
        <button type="button" class="forgot-password-link" id="forgotPasswordLink">Forgot Password?</button>
      </div>
      <button type="submit" class="btn-login" id="signInButton">Sign In</button>
    </form>
    <div class="signup-prompt">
      Don't have an account? <a href="signup.php">Sign up here</a>
    </div>
  </div>
</div>
</main>
<div
  class="forgot-modal<?php echo $forgotEmailModalOpen ? ' open' : ''; ?>"
  id="forgotPasswordModal"
  aria-hidden="<?php echo $forgotEmailModalOpen ? 'false' : 'true'; ?>"
>
  <div class="forgot-card" role="dialog" aria-modal="true" aria-labelledby="forgotPasswordTitle">
    <button type="button" class="forgot-close" id="forgotPasswordClose" aria-label="Close">&times;</button>
    <div class="forgot-icon" aria-hidden="true">@</div>
    <h2 id="forgotPasswordTitle">Forgot Password?</h2>
    <p class="forgot-description">
      Enter the email address connected to your LokalCraft account. We will send a 6-digit verification code to continue the password reset process.
    </p>
    <?php if ($forgotEmailError !== ''): ?>
      <div class="forgot-message" role="alert">
        <?php echo htmlspecialchars($forgotEmailError, ENT_QUOTES, 'UTF-8'); ?>
      </div>
    <?php endif; ?>
    <form method="POST" action="login.php?forgot=email" id="forgotPasswordForm" autocomplete="off">
      <input type="hidden" name="action" value="forgot_request_otp">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
      <div class="forgot-field">
        <label for="forgotEmail">Email Address</label>
        <input
          type="email"
          id="forgotEmail"
          name="forgot_email"
          placeholder="yourname@gmail.com"
          value="<?php echo htmlspecialchars($forgotEmailValue !== '' ? $forgotEmailValue : $email, ENT_QUOTES, 'UTF-8'); ?>"
          required
          maxlength="100"
          autocomplete="email"
        >
      </div>
      <button type="submit" class="forgot-primary" id="forgotSendButton">Send Verification Code</button>
      <p class="forgot-helper">For security, LokalCraft does not reveal whether an email address is registered.</p>
    </form>
  </div>
</div>
<?php
renderOtpModal([
    'id' => 'forgot-password-otp',
    'open' => $forgotOtpModalOpen,
    'title' => 'Verify Password Reset',
    'subtitle' => 'Enter the 6-digit code sent to:',
    'masked_email' => $forgotOtpMaskedEmail,
    'csrf_token' => $_SESSION['login_csrf'],
    'verify_action' => 'forgot_verify_otp',
    'resend_action' => 'forgot_resend_otp',
    'cancel_action' => 'forgot_change_email',
    'action_url' => 'login.php',
    'error' => $forgotOtpError,
    'success' => $forgotOtpSuccess,
    'resend_seconds' => $forgotOtpResendSeconds,
    'verify_label' => 'Verify Code',
    'cancel_label' => 'Change email',
    'expiry_text' => 'The verification code expires in 10 minutes.',
]);
?>
<style>
  .otp-modal {
    z-index: 110000 !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 20px !important;
    background: rgba(43, 30, 18, 0.55) !important;
    backdrop-filter: blur(5px) !important;
    overflow-y: auto !important;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
  }
  .otp-card {
    width: min(470px, 100%) !important;
    max-height: calc(100dvh - 40px);
    overflow-y: auto;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
    padding: 30px 28px 26px !important;
    border: 1px solid #E7CDB0 !important;
    border-radius: 24px !important;
    background: #FFFCF7 !important;
    box-shadow: 0 28px 70px rgba(45, 30, 16, 0.28) !important;
  }
  .otp-icon {
    width: 58px !important;
    height: 58px !important;
    margin: 0 auto 14px !important;
    border-radius: 18px !important;
    background: #F9EFE2 !important;
    color: #A55325 !important;
    font-size: 1.45rem !important;
    font-weight: 900 !important;
  }
  .otp-card h2 {
    margin: 0 !important;
    color: #8b5423 !important;
    text-align: center !important;
    font-size: 1.5rem !important;
    line-height: 1.2 !important;
  }
  .otp-subtitle {
    max-width: none !important;
    margin: 8px 0 0 !important;
    color: #6f5a47 !important;
    text-align: center !important;
    font-size: 0.88rem !important;
    line-height: 1.45 !important;
  }
  .otp-email {
    margin-top: 7px !important;
    color: #3f2e1f !important;
    text-align: center !important;
    font-size: 0.9rem !important;
    font-weight: 800 !important;
    overflow-wrap: anywhere;
  }
  .otp-message {
    margin-top: 14px !important;
    padding: 10px 12px !important;
    border-radius: 11px !important;
    text-align: center !important;
    font-size: 0.8rem !important;
    line-height: 1.4 !important;
  }
  .otp-inputs {
    display: grid !important;
    grid-template-columns: repeat(6, minmax(0, 1fr)) !important;
    gap: 8px !important;
    width: 100% !important;
    margin-top: 20px !important;
  }
  .otp-digit {
    width: 100% !important;
    min-width: 0 !important;
    height: 52px !important;
    padding: 0 !important;
    border: 1.5px solid #E7CDB0 !important;
    border-radius: 12px !important;
    background: #F9EFE2 !important;
    color: #2B1E12 !important;
    text-align: center !important;
    font: 800 1.2rem/1 Inter, system-ui, sans-serif !important;
    outline: none !important;
  }
  .otp-digit:focus {
    border-color: #B85C28 !important;
    background: #FFFAF4 !important;
    box-shadow: 0 0 0 3px rgba(184, 92, 40, 0.1) !important;
  }
  .otp-primary {
    width: 100% !important;
    min-height: 48px !important;
    margin-top: 18px !important;
    border: 0 !important;
    border-radius: 60px !important;
    background: #B85C28 !important;
    color: #fff !important;
    padding: 13px 18px !important;
    font: inherit !important;
    font-weight: 800 !important;
    cursor: pointer;
    box-shadow: none !important;
  }
  .otp-primary:hover {
    background: #94481F !important;
  }
  .otp-actions {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 9px !important;
    margin-top: 13px !important;
    flex-wrap: wrap !important;
  }
  .otp-text-button {
    border: 0 !important;
    background: transparent !important;
    color: #B85C28 !important;
    padding: 5px !important;
    font: inherit !important;
    font-size: 0.8rem !important;
    font-weight: 800 !important;
    cursor: pointer;
  }
  .otp-expiry {
    margin: 10px 0 0 !important;
    color: #927b68 !important;
    text-align: center !important;
    font-size: 0.74rem !important;
  }
  @media (max-width: 600px) {
    .otp-modal {
      align-items: flex-start !important;
      padding:
        max(12px, env(safe-area-inset-top))
        10px
        max(12px, env(safe-area-inset-bottom)) !important;
    }
    .otp-card {
      width: 100% !important;
      max-width: 470px !important;
      max-height: calc(100dvh - 24px);
      margin: auto 0 !important;
      padding: 26px 16px 22px !important;
      border-radius: 20px !important;
    }
    .otp-inputs {
      gap: 5px !important;
    }
    .otp-digit {
      height: 47px !important;
      font-size: 1.05rem !important;
    }
  }
  @media (max-width: 360px) {
    .otp-card {
      padding-left: 12px !important;
      padding-right: 12px !important;
    }
    .otp-inputs {
      gap: 4px !important;
    }
    .otp-digit {
      height: 43px !important;
      font-size: 0.96rem !important;
    }
  }
</style>
<div
  class="forgot-modal<?php echo $forgotResetModalOpen ? ' open' : ''; ?>"
  id="forgotResetModal"
  aria-hidden="<?php echo $forgotResetModalOpen ? 'false' : 'true'; ?>"
>
  <div class="forgot-card forgot-reset-card" role="dialog" aria-modal="true" aria-labelledby="forgotResetTitle">
    <div class="forgot-icon" aria-hidden="true">✓</div>
    <h2 id="forgotResetTitle">Create New Password</h2>
    <p class="forgot-description">
      Your email <strong><?php echo htmlspecialchars($forgotResetMaskedEmail, ENT_QUOTES, 'UTF-8'); ?></strong> has been verified.
      Create a new password for your LokalCraft account.
    </p>
    <?php if ($forgotResetError !== ''): ?>
      <div class="forgot-message" role="alert">
        <?php echo htmlspecialchars($forgotResetError, ENT_QUOTES, 'UTF-8'); ?>
      </div>
    <?php endif; ?>
    <form class="forgot-reset-form" method="POST" action="login.php?forgot=reset" id="forgotResetForm" autocomplete="off">
      <input type="hidden" name="action" value="forgot_reset_password">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
      <div class="forgot-field">
        <label for="forgotNewPassword">New Password</label>
        <div class="password-wrap">
          <input
            type="password"
            id="forgotNewPassword"
            name="new_password"
            aria-describedby="forgotPasswordRequirements"
            placeholder="Enter your new password"
            minlength="8"
            required
            autocomplete="new-password"
          >
          <button
            type="button"
            class="password-toggle reset-password-toggle"
            data-target="forgotNewPassword"
            aria-label="Show password"
            aria-pressed="false"
          >
            <svg class="reset-eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"></path>
              <circle cx="12" cy="12" r="3"></circle>
            </svg>
            <svg class="reset-eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none">
              <path d="M3 3l18 18"></path>
              <path d="M10.6 10.6a2 2 0 002.8 2.8"></path>
              <path d="M9.9 4.2A10.8 10.8 0 0112 4c6.5 0 10 8 10 8a17.7 17.7 0 01-2.1 3.2"></path>
              <path d="M6.2 6.2C3.5 8.1 2 12 2 12s3.5 8 10 8a9.8 9.8 0 004.1-.9"></path>
            </svg>
          </button>
        </div>
        <div class="password-requirements" id="forgotPasswordRequirements" aria-live="polite">
          <p class="password-requirements-title">Your password must contain:</p>
          <div class="password-rule" data-rule="length">
            <span class="password-rule-icon">•</span>
            <span>At least 8 characters</span>
          </div>
          <div class="password-rule" data-rule="uppercase">
            <span class="password-rule-icon">•</span>
            <span>1 uppercase letter</span>
          </div>
          <div class="password-rule" data-rule="lowercase">
            <span class="password-rule-icon">•</span>
            <span>1 lowercase letter</span>
          </div>
          <div class="password-rule" data-rule="number">
            <span class="password-rule-icon">•</span>
            <span>1 number</span>
          </div>
          <div class="password-rule" data-rule="special">
            <span class="password-rule-icon">•</span>
            <span>1 special character</span>
          </div>
        </div>
      </div>
      <div class="forgot-field">
        <label for="forgotConfirmPassword">Confirm New Password</label>
        <div class="password-wrap">
          <input
            type="password"
            id="forgotConfirmPassword"
            name="confirm_password"
            aria-describedby="forgotPasswordMatchFeedback"
            placeholder="Re-enter your new password"
            minlength="8"
            required
            autocomplete="new-password"
          >
          <button
            type="button"
            class="password-toggle reset-password-toggle"
            data-target="forgotConfirmPassword"
            aria-label="Show password"
            aria-pressed="false"
          >
            <svg class="reset-eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"></path>
              <circle cx="12" cy="12" r="3"></circle>
            </svg>
            <svg class="reset-eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none">
              <path d="M3 3l18 18"></path>
              <path d="M10.6 10.6a2 2 0 002.8 2.8"></path>
              <path d="M9.9 4.2A10.8 10.8 0 0112 4c6.5 0 10 8 10 8a17.7 17.7 0 01-2.1 3.2"></path>
              <path d="M6.2 6.2C3.5 8.1 2 12 2 12s3.5 8 10 8a9.8 9.8 0 004.1-.9"></path>
            </svg>
          </button>
        </div>
        <div
          class="password-inline-feedback neutral"
          id="forgotPasswordMatchFeedback"
          role="status"
          aria-live="polite"
        >
          Re-enter your new password to confirm it.
        </div>
      </div>
      <button type="submit" class="forgot-primary" id="forgotResetButton">Update Password</button>
    </form>
  </div>
</div>
<script>
  const passwordInput = document.getElementById('password');
  const passwordToggle = document.getElementById('passwordToggle');
  const eyeOpen = document.getElementById('eyeOpen');
  const eyeClosed = document.getElementById('eyeClosed');
  if (passwordInput && passwordToggle) {
    passwordToggle.addEventListener('click', function () {
      const showing = passwordInput.type === 'text';
      passwordInput.type = showing ? 'password' : 'text';
      passwordToggle.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
      passwordToggle.setAttribute('aria-pressed', showing ? 'false' : 'true');
      eyeOpen.style.display = showing ? '' : 'none';
      eyeClosed.style.display = showing ? 'none' : '';
      passwordInput.focus({ preventScroll: true });
    });
  }
  const loginForm = document.querySelector('.login-form');
  const signInButton = document.getElementById('signInButton');
  if (loginForm && signInButton) {
    loginForm.addEventListener('submit', function () {
      if (!loginForm.checkValidity()) return;
      signInButton.disabled = true;
      signInButton.textContent = 'Signing In...';
      signInButton.setAttribute('aria-busy', 'true');
    });
  }
  const forgotPasswordLink = document.getElementById('forgotPasswordLink');
  const forgotPasswordModal = document.getElementById('forgotPasswordModal');
  const forgotPasswordClose = document.getElementById('forgotPasswordClose');
  const forgotEmailInput = document.getElementById('forgotEmail');
  const forgotPasswordForm = document.getElementById('forgotPasswordForm');
  const forgotSendButton = document.getElementById('forgotSendButton');
  const openForgotPassword = () => {
    if (!forgotPasswordModal) return;
    forgotPasswordModal.classList.add('open');
    forgotPasswordModal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    window.setTimeout(() => forgotEmailInput?.focus(), 80);
  };
  const closeForgotPassword = () => {
    if (!forgotPasswordModal) return;
    forgotPasswordModal.classList.remove('open');
    forgotPasswordModal.setAttribute('aria-hidden', 'true');
    const otpModalOpen = document.querySelector('.otp-modal.open');
    if (!otpModalOpen) {
      document.body.style.overflow = '';
    }
  };
  forgotPasswordLink?.addEventListener('click', openForgotPassword);
  forgotPasswordClose?.addEventListener('click', closeForgotPassword);
  forgotPasswordModal?.addEventListener('click', event => {
    if (event.target === forgotPasswordModal) {
      closeForgotPassword();
    }
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && forgotPasswordModal?.classList.contains('open')) {
      closeForgotPassword();
    }
  });
  forgotPasswordForm?.addEventListener('submit', function () {
    if (!forgotPasswordForm.checkValidity()) return;
    if (forgotSendButton) {
      forgotSendButton.disabled = true;
      forgotSendButton.textContent = 'Sending Code...';
      forgotSendButton.setAttribute('aria-busy', 'true');
    }
  });
  if (forgotPasswordModal?.classList.contains('open')) {
    document.body.style.overflow = 'hidden';
    window.setTimeout(() => forgotEmailInput?.focus(), 80);
  }
  const forgotResetModal = document.getElementById('forgotResetModal');
  const forgotResetForm = document.getElementById('forgotResetForm');
  const forgotResetButton = document.getElementById('forgotResetButton');
  const forgotNewPassword = document.getElementById('forgotNewPassword');
  const forgotConfirmPassword = document.getElementById('forgotConfirmPassword');
  document.querySelectorAll('.reset-password-toggle').forEach(button => {
    button.addEventListener('click', function () {
      const target = document.getElementById(button.dataset.target || '');
      const eyeOpen = button.querySelector('.reset-eye-open');
      const eyeClosed = button.querySelector('.reset-eye-closed');
      if (!target) return;
      const showPassword = target.type === 'password';
      target.type = showPassword ? 'text' : 'password';
      button.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
      button.setAttribute('aria-pressed', showPassword ? 'true' : 'false');
      if (eyeOpen && eyeClosed) {
        eyeOpen.style.display = showPassword ? 'none' : '';
        eyeClosed.style.display = showPassword ? '' : 'none';
      }
      target.focus({ preventScroll: true });
    });
  });
  const forgotPasswordMatchFeedback = document.getElementById('forgotPasswordMatchFeedback');
  const passwordRules = {
    length: value => value.length >= 8,
    uppercase: value => /[A-Z]/.test(value),
    lowercase: value => /[a-z]/.test(value),
    number: value => /[0-9]/.test(value),
    special: value => /[^A-Za-z0-9]/.test(value)
  };
  const getPasswordRuleElements = () =>
    Array.from(document.querySelectorAll('#forgotPasswordRequirements .password-rule'));
  const validateNewPassword = (showErrors = false) => {
    if (!forgotNewPassword) return false;
    const value = forgotNewPassword.value;
    let allValid = true;
    getPasswordRuleElements().forEach(ruleElement => {
      const ruleName = ruleElement.dataset.rule;
      const passes = Boolean(passwordRules[ruleName]?.(value));
      const icon = ruleElement.querySelector('.password-rule-icon');
      ruleElement.classList.toggle('valid', passes);
      ruleElement.classList.toggle('invalid', showErrors && value.length > 0 && !passes);
      if (icon) {
        icon.textContent = passes ? '✓' : '•';
      }
      if (!passes) {
        allValid = false;
      }
    });
    forgotNewPassword.classList.toggle('password-valid', allValid && value.length > 0);
    forgotNewPassword.classList.toggle(
      'password-invalid',
      showErrors && value.length > 0 && !allValid
    );
    return allValid;
  };
  const validatePasswordMatch = (showErrors = false) => {
    if (!forgotNewPassword || !forgotConfirmPassword || !forgotPasswordMatchFeedback) {
      return false;
    }
    const newPassword = forgotNewPassword.value;
    const confirmation = forgotConfirmPassword.value;
    forgotConfirmPassword.classList.remove('password-valid', 'password-invalid');
    forgotPasswordMatchFeedback.classList.remove('neutral', 'success', 'error');
    if (confirmation === '') {
      forgotPasswordMatchFeedback.textContent = 'Re-enter your new password to confirm it.';
      forgotPasswordMatchFeedback.classList.add('neutral');
      return false;
    }
    if (newPassword === confirmation) {
      forgotPasswordMatchFeedback.textContent = 'Passwords match.';
      forgotPasswordMatchFeedback.classList.add('success');
      forgotConfirmPassword.classList.add('password-valid');
      return true;
    }
    forgotPasswordMatchFeedback.textContent =
      'Passwords do not match. Make sure both password fields are exactly the same.';
    forgotPasswordMatchFeedback.classList.add(showErrors ? 'error' : 'neutral');
    if (showErrors) {
      forgotConfirmPassword.classList.add('password-invalid');
    }
    return false;
  };
  forgotNewPassword?.addEventListener('input', function () {
    validateNewPassword(false);
    if (forgotConfirmPassword?.value) {
      validatePasswordMatch(true);
    }
  });
  forgotNewPassword?.addEventListener('blur', function () {
    validateNewPassword(true);
  });
  forgotConfirmPassword?.addEventListener('input', function () {
    validatePasswordMatch(true);
  });
  forgotResetForm?.addEventListener('submit', function (event) {
    const passwordValid = validateNewPassword(true);
    const passwordsMatch = validatePasswordMatch(true);
    if (!forgotResetForm.checkValidity() || !passwordValid || !passwordsMatch) {
      event.preventDefault();
      if (!passwordValid) {
        forgotNewPassword?.focus({ preventScroll: true });
      } else if (!passwordsMatch) {
        forgotConfirmPassword?.focus({ preventScroll: true });
      }
      return;
    }
    if (forgotResetButton) {
      forgotResetButton.disabled = true;
      forgotResetButton.textContent = 'Updating Password...';
      forgotResetButton.setAttribute('aria-busy', 'true');
    }
  });
  if (forgotResetModal?.classList.contains('open')) {
    document.body.style.overflow = 'hidden';
    window.setTimeout(() => forgotNewPassword?.focus(), 80);
  }
</script>
</body>
</html>
