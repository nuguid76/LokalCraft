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
$error = '';
$email = '';
$forgotFlowKey = 'forgot_password';
$forgotEmailValue = trim((string)($_SESSION['forgot_form_email'] ?? ''));
$forgotEmailError = trim((string)($_SESSION['forgot_email_error'] ?? ''));
$forgotOtpError = trim((string)($_SESSION['forgot_otp_error'] ?? ''));
$forgotOtpSuccess = trim((string)($_SESSION['forgot_otp_success'] ?? ''));
$forgotResetError = trim((string)($_SESSION['forgot_reset_error'] ?? ''));
$forgotPasswordReuseError = trim((string)($_SESSION['forgot_password_reuse_error'] ?? ''));
unset(
    $_SESSION['forgot_email_error'],
    $_SESSION['forgot_otp_error'],
    $_SESSION['forgot_otp_success'],
    $_SESSION['forgot_reset_error'],
    $_SESSION['forgot_password_reuse_error']
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
// Keep the 15-minute lockout tied to the fifth failed attempt, not the oldest attempt.
$loginLockoutUntil = (int)($_SESSION['login_lockout_until'] ?? 0);
if ($loginLockoutUntil > 0 && $loginLockoutUntil <= $now) {
    unset($_SESSION['login_lockout_until']);
    $_SESSION['login_failures'] = [];
    $loginLockoutUntil = 0;
}
// Continue any already-exhausted attempts from sessions started before this UI update.
if ($loginLockoutUntil === 0 && count($_SESSION['login_failures']) >= $maxLoginFailures) {
    $lastFailure = (int)end($_SESSION['login_failures']);
    $loginLockoutUntil = $lastFailure + $loginWindowSeconds;
    $_SESSION['login_lockout_until'] = $loginLockoutUntil;
}
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
        if ($newPassword !== $confirmPassword) {
            $_SESSION['forgot_reset_error'] = 'The confirmation password does not match the new password.';
            header('Location: login.php?forgot=reset');
            exit;
        }
        try {
            $resetUserId = (int)$verifiedReset['user_id'];
            $resetEmail = trim((string)($verifiedReset['email'] ?? ''));
            // Compare against the stored hash only after OTP verification.
            // Never expose the existing hash or previous password to the browser.
            $currentPasswordStmt = $conn->prepare("
                SELECT password
                FROM users
                WHERE id = ?
                  AND LOWER(email) = LOWER(?)
                  AND account_status <> 'disabled'
                LIMIT 1
            ");
            if (!$currentPasswordStmt) {
                throw new RuntimeException('Unable to verify the current password.');
            }
            $currentPasswordStmt->bind_param('is', $resetUserId, $resetEmail);
            $currentPasswordStmt->execute();
            $currentPasswordStmt->bind_result($currentPasswordHash);
            $hasCurrentPassword = $currentPasswordStmt->fetch();
            $currentPasswordStmt->close();
            if (!$hasCurrentPassword) {
                throw new RuntimeException('No matching user was found.');
            }
            if (password_verify($newPassword, (string)$currentPasswordHash)) {
                $_SESSION['forgot_password_reuse_error'] =
                    'Your new password must be different from your previous password.';
                header('Location: login.php?forgot=reset', true, 303);
                exit;
            }
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
                  AND password = ?
                LIMIT 1
            ");
            if (!$resetStmt) {
                throw new RuntimeException('Unable to prepare the password update.');
            }
            // Prevent overwriting a password that changed after the comparison.
            $resetStmt->bind_param('siss', $newPasswordHash, $resetUserId, $resetEmail, $currentPasswordHash);
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
        if ($loginLockoutUntil > $now) {
            $error = 'Too many login attempts. Sign In is temporarily disabled for 15 minutes.';
        } elseif ($email === '' || $password === '') {
            $error = 'Please enter your email and password.';
        } elseif (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
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
                    if (count($_SESSION['login_failures']) >= $maxLoginFailures) {
                        $loginLockoutUntil = $now + $loginWindowSeconds;
                        $_SESSION['login_lockout_until'] = $loginLockoutUntil;
                        $error = 'Too many login attempts. Sign In is temporarily disabled for 15 minutes.';
                    } else {
                        $error = 'Invalid email or password. Please try again.';
                    }
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
$loginLockoutRemaining = max(0, $loginLockoutUntil - time());
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
      background: #fff8eb;
      overflow-x: hidden;
    }
    /* Keep the landing-page image framed to the viewport, not the form height. */
    body::before,
    body::after {
      content: "";
      position: fixed;
      inset: 0 0 auto;
      width: 100%;
      height: 100vh;
      height: 100lvh;
      pointer-events: none;
    }
    body::before {
      z-index: 0;
      background: url('uploads/website background.png') center center / cover no-repeat;
    }
    body::after {
      z-index: 1;
      background-color: rgba(255, 248, 235, 0.60);
      -webkit-backdrop-filter: blur(1px);
      backdrop-filter: blur(1px);
    }
    /* One stable desktop layout. Browser zoom naturally scales CSS pixels without
       width-based enlargement or alignment jumps at arbitrary breakpoints. */
    .login-page {
      min-height: calc(100svh - 82px);
      width: 100%;
      max-width: 1600px;
      margin-inline: auto;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: clamp(24px, 2.4vw, 40px) 16px;
      position: relative;
      z-index: 2;
    }
    .login-container {
      width: min(100%, 420px);
      min-width: 0;
      margin-inline: auto;
      position: relative;
      z-index: 1;
    }
    .login-card {
      width: 100%;
      min-width: 0;
      background: rgba(255, 252, 245, 0.95);
      backdrop-filter: blur(8px);
      border-radius: 30px;
      padding: clamp(30px, 2.6vw, 44px) clamp(22px, 2.4vw, 38px);
      box-shadow: 0 25px 40px -8px rgba(0, 0, 0, 0.15);
      border: 1px solid rgba(220, 180, 135, 0.6);
    }
    /* Lightweight entrance motion; no artificial zoom or layout movement. */
    .login-card {
      animation: lokalcraftLoginCardEnter 650ms cubic-bezier(.22, 1, .36, 1) both;
    }
    .login-header,
    .login-form > .form-group,
    .login-form > .forgot-password-row,
    .login-form > .btn-login,
    .signup-prompt {
      animation: lokalcraftLoginItemEnter 480ms ease-out both;
    }
    .login-header { animation-delay: 100ms; }
    .login-form > .form-group { animation-delay: 170ms; }
    .login-form > .form-group + .form-group { animation-delay: 230ms; }
    .login-form > .forgot-password-row { animation-delay: 290ms; }
    .login-form > .btn-login { animation-delay: 350ms; }
    .signup-prompt { animation-delay: 410ms; }
    @keyframes lokalcraftLoginCardEnter {
      from { opacity: 0; transform: translateY(20px) scale(.985); }
      to { opacity: 1; transform: translateY(0) scale(1); }
    }
    @keyframes lokalcraftLoginItemEnter {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .login-header {
      text-align: center;
      margin-bottom: clamp(22px, 2vw, 30px);
    }
    .login-title {
      font-size: 2rem;
      font-weight: 800;
      color: #8b5423;
      margin-bottom: 8px;
      line-height: 1.2;
    }
    .login-form {
      display: flex;
      flex-direction: column;
      min-width: 0;
      gap: clamp(16px, 1.5vw, 20px);
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
      width: 100%;
      min-width: 0;
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
    .btn-login.is-locked:disabled {
      cursor: not-allowed;
    }
    .login-lockout-hint {
      margin-top: -10px;
      text-align: center;
      color: #8B3430;
      font-size: 0.82rem;
      line-height: 1.45;
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
    /* Floating notices overlay the shared navigation without moving the form. */
    .login-notification-stack {
      position: fixed;
      z-index: 20000;
      top: max(14px, env(safe-area-inset-top));
      left: 50%;
      transform: translateX(-50%);
      display: grid;
      gap: 10px;
      width: min(520px, calc(100% - 32px));
      pointer-events: none;
    }
    .login-notification {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      width: 100%;
      min-width: 0;
      padding: 12px 12px 12px 13px;
      border: 1px solid #DEC8A8;
      border-radius: 13px;
      background: #FFFCF7;
      box-shadow: 0 12px 34px rgba(45, 30, 16, 0.17);
      pointer-events: auto;
      animation: loginNoticeDrop .48s cubic-bezier(.16, 1, .3, 1) both;
      color: #4A3323;
      font-size: 0.84rem;
      line-height: 1.48;
      overflow-wrap: anywhere;
    }
    .login-notification--error {
      border-color: #E9C7C2;
      background: #FFF8F6;
      color: #8B3430;
    }
    .login-notification-icon {
      display: grid;
      place-items: center;
      flex: 0 0 23px;
      width: 23px;
      height: 23px;
      border-radius: 50%;
      background: #F2E0C7;
      color: #875020;
      font-size: 0.88rem;
      font-weight: 800;
      line-height: 1;
    }
    .login-notification--error .login-notification-icon {
      background: #F8E0DC;
      color: #A34239;
    }
    .login-notification-message { flex: 1; min-width: 0; padding-top: 2px; }
    .login-notification-close {
      display: grid;
      place-items: center;
      flex: 0 0 27px;
      width: 27px;
      height: 27px;
      margin: -2px -3px -2px 0;
      padding: 0;
      border: 0;
      border-radius: 8px;
      background: transparent;
      color: inherit;
      cursor: pointer;
      font: inherit;
      font-size: 20px;
      line-height: 1;
    }
    .login-notification-close:hover, .login-notification-close:focus-visible {
      background: rgba(92, 65, 43, 0.09);
      outline-offset: 2px;
    }
    .login-notification.is-leaving {
      animation: loginNoticeLeave .32s ease-in both;
      pointer-events: none;
    }
    @keyframes loginNoticeDrop {
      from { opacity: 0; transform: translateY(-140%); }
      to { opacity: 1; transform: translateY(0); }
    }
    @keyframes loginNoticeLeave {
      from { opacity: 1; transform: translateY(0); }
      to { opacity: 0; transform: translateY(-35%); }
    }
    /* Phone field and button sizes are handled with the mobile rules below. */
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
      animation: lokalcraftLoginModalBackdrop 220ms ease-out both;
    }
    .forgot-modal.open > .forgot-card {
      animation: lokalcraftLoginModalEnter 310ms cubic-bezier(.22, 1, .36, 1) both;
    }
    @keyframes lokalcraftLoginModalBackdrop {
      from { opacity: 0; }
      to { opacity: 1; }
    }
    @keyframes lokalcraftLoginModalEnter {
      from { opacity: 0; transform: translateY(12px) scale(.98); }
      to { opacity: 1; transform: translateY(0) scale(1); }
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
    .forgot-reset-form .forgot-primary {
      margin-top: 2px;
    }
    .reset-password-toggle .reset-eye-open,
    .reset-password-toggle .reset-eye-closed {
      width: 20px;
      height: 20px;
      pointer-events: none;
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
    @media (max-width: 768px) {
      html,
      body {
        width: 100%;
        max-width: 100%;
        overflow-x: hidden;
      }
      body {
        min-height: 100dvh;
      }
      .login-page {
        min-height: calc(100svh - 82px);
        align-items: flex-start;
        padding: 32px 16px 28px;
      }
      .login-container {
        width: min(100%, 420px);
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
      .login-form {
        gap: 18px;
      }
      .form-group input,
      .forgot-field input {
        min-height: 50px;
        font-size: 16px;
      }
      .form-group input {
        padding: 12px 16px;
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
      .btn-login {
        padding: 12px 32px;
        font-size: 0.95rem;
      }
      .signup-prompt {
        margin-top: 22px;
        line-height: 1.5;
      }
      .login-notification {
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

      .password-inline-feedback {
        overflow-wrap: anywhere;
      }
    }
    @media (max-width: 700px) {
      .login-page { min-height: calc(100svh - 70px); }
    }
    @media (max-width: 480px) {
      .login-page {
        padding: 24px 12px 22px;
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

    }
    @media (max-height: 620px) and (max-width: 900px) {
      .login-page {
        align-items: flex-start;
        padding-top: 18px;
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
    /* Forgot Password modal alone uses the short-viewport desktop breakpoints.
       The Login card stays centered and keeps the same proportions at every zoom. */
    @media (min-width: 769px) and (max-width: 1440px) and (max-height: 900px) {
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
      .forgot-card h2 {
        font-size: 1.42rem;
      }
      .forgot-description {
        margin-bottom: 16px;
      }
      .forgot-reset-form {
        gap: 14px;
      }
      .forgot-primary {
        margin-top: 10px;
      }
    }
    @media (min-width: 769px) and (max-width: 1440px) and (max-height: 760px) {
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
    /* Respect reduced-motion preferences, including modal animations. */
    @media (prefers-reduced-motion: reduce) {
      .login-notification,
      .login-notification.is-leaving,
      .login-card,
      .login-header,
      .login-form > .form-group,
      .login-form > .forgot-password-row,
      .login-form > .btn-login,
      .signup-prompt,
      .forgot-modal.open,
      .forgot-modal.open > .forgot-card { animation: none; }
    }

    /* LokalCraft self-contained password recovery: matches the Login card. */
    .forgot-modal,.otp-modal {
      position:fixed;inset:0;z-index:30000;display:none;align-items:center;justify-content:center;
      top:var(--login-dialog-visible-top,0px);bottom:auto;
      width:100%;height:var(--login-dialog-visible-height,100dvh);
      max-height:var(--login-dialog-visible-height,100dvh);overflow-y:auto;
      padding:max(12px,env(safe-area-inset-top)) 14px max(12px,env(safe-area-inset-bottom));
      background:rgba(43,30,18,.55);-webkit-backdrop-filter:blur(5px);backdrop-filter:blur(5px);
      overscroll-behavior:contain;
    }
    .forgot-modal.open,.otp-modal.open{display:flex}
    .otp-modal.open{animation:lokalcraftLoginModalBackdrop 220ms ease-out both}
    .forgot-modal .forgot-card,.otp-modal .otp-card{
      position:relative;width:min(460px,100%);min-width:0;flex:0 1 auto;
      margin:auto;max-height:calc(var(--login-dialog-visible-height,100dvh) - 28px);
      padding:32px 30px 28px;overflow-y:auto;overscroll-behavior:contain;
      border:1px solid rgba(220,180,135,.6);border-radius:28px;
      background:rgba(255,252,245,.98);box-shadow:0 25px 45px rgba(45,30,16,.2);
      -webkit-overflow-scrolling:touch;
    }
    .otp-modal.open>.otp-card{animation:lokalcraftLoginModalEnter 310ms cubic-bezier(.22,1,.36,1) both}
    .forgot-modal .forgot-step,.otp-modal .forgot-step{
      display:block;margin:0 44px 11px 0;color:#A55325;font-size:.69rem;font-weight:800;
      letter-spacing:.13em;
    }
    .forgot-modal .forgot-card h2,.otp-modal .otp-card h2{
      margin:0;padding:0;text-align:left;color:#8b5423;font-size:1.65rem;font-weight:800;line-height:1.2;
    }
    .forgot-modal .forgot-description,.otp-modal .forgot-description{
      margin:9px 0 22px;max-width:none;color:#6f5a47;text-align:left;font-size:.88rem;line-height:1.55;
    }
    .forgot-modal .forgot-description strong{overflow-wrap:anywhere;color:#493423}
    .forgot-modal .forgot-close,.otp-modal .forgot-close{
      position:absolute;top:17px;right:17px;display:grid;place-items:center;
      width:36px;height:36px;padding:0;border:0;border-radius:50%;
      background:#F9EFE2;color:#8b5423;font-size:1.4rem;line-height:1;cursor:pointer;
    }
    .forgot-modal .forgot-close:hover,.otp-modal .forgot-close:hover{background:#f0dfcb}
    .forgot-modal .forgot-close:focus-visible,.otp-modal .forgot-close:focus-visible,
    .otp-modal .otp-digit:focus-visible,.otp-modal .otp-text-button:focus-visible{
      outline:2px solid #C9854E;outline-offset:2px;
    }
    .forgot-modal .forgot-field label{font-size:.86rem;color:#2B1E12;font-weight:700}
    .forgot-modal .forgot-field input{
      width:100%;min-height:49px;border:1.5px solid #E7CDB0;border-radius:15px;
      background:#F9EFE2;color:#2B1E12;font-size:16px;padding:13px 16px;
    }
    .forgot-modal .forgot-reset-form,.forgot-modal #forgotPasswordForm{display:flex;flex-direction:column;gap:16px}
    .forgot-modal .forgot-primary,.otp-modal .forgot-primary{
      width:100%;min-height:49px;margin:1px 0 0;padding:13px 18px;border:0;border-radius:60px;
      background:#B85C28;color:#fff;font:inherit;font-weight:800;font-size:.94rem;cursor:pointer;
      box-shadow:0 6px 14px rgba(160,80,35,.17);
    }
    .forgot-modal .forgot-primary:hover,.otp-modal .forgot-primary:hover{background:#94481F}
    .forgot-modal .forgot-primary:disabled,.otp-modal .forgot-primary:disabled,
    .otp-modal .otp-text-button:disabled{opacity:.6;cursor:not-allowed}
    .forgot-modal .forgot-helper{margin:13px 0 0;font-size:.74rem;text-align:center;color:#927b68;line-height:1.5}
    .forgot-modal .forgot-message,.otp-modal .forgot-message{
      margin:0 0 16px;padding:10px 12px;border:1px solid #efc0c0;border-radius:11px;
      background:#fff2f2;color:#a83d3d;text-align:left;font-size:.79rem;line-height:1.45;
    }
    .otp-modal .forgot-message--success{border-color:#bfe2c9;background:#f2fbf5;color:#2f7a4d}
    .otp-modal .otp-email{margin:-13px 0 14px;overflow-wrap:anywhere;color:#493423;font-size:.83rem;font-weight:800}
    .otp-modal .otp-inputs{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:11px;margin:14px 0 0}
    .otp-modal .otp-digit{
      width:100%;min-width:0;height:50px;padding:0;border:1.5px solid #E7CDB0;border-radius:11px;
      background:#F9EFE2;color:#2B1E12;text-align:center;font:800 1.16rem/1 Inter,system-ui,sans-serif;
    }
    .otp-modal .otp-digit:focus{border-color:#B85C28;background:#FFFAF4;box-shadow:0 0 0 3px rgba(184,92,40,.1)}
    .otp-modal .otp-inline-error{margin:6px 0 0;font-size:.74rem;color:#a83d3d;line-height:1.4}
    .otp-modal .otp-inline-error:empty{display:none}
    .otp-modal .otp-delivery-help{margin:20px 0 0;text-align:center;color:#806957;font-size:.77rem;line-height:1.5}
    .otp-modal .otp-actions{display:flex;justify-content:center;align-items:center;gap:18px;flex-wrap:wrap;margin:16px 0 0}
    .otp-modal .otp-actions form{margin:0}
    .otp-modal .otp-text-button{padding:8px 5px;border:0;background:transparent;color:#B85C28;font:inherit;font-size:.8rem;font-weight:800;cursor:pointer}
    .otp-modal .otp-separator{color:#bdac9c}
    /* Keep the six digits and recovery actions visually separate. */
    .otp-modal #forgot-password-otp-verify-form > .forgot-primary{margin-top:23px}
    .otp-modal .otp-expiry{margin:13px 0 0;text-align:center;color:#927b68;font-size:.73rem}
    /* Three-segment strength indicator matches Signup. */
    .forgot-modal .forgot-strength{display:flex;align-items:center;gap:10px;min-height:17px;margin:6px 0 0}
    .forgot-modal .forgot-strength-bar{display:flex;flex:1;gap:4px;height:5px;max-width:110px}
    .forgot-modal .forgot-strength-bar i{flex:1;border-radius:6px;background:#e6d9cb;transition:background .16s}
    .forgot-modal .forgot-strength.weak i:first-child{background:#d94c47}
    .forgot-modal .forgot-strength.normal i:nth-child(-n+2){background:#efa63e}
    .forgot-modal .forgot-strength.strong i{background:#3a9b62}
    .forgot-modal .forgot-strength-label{font-size:.75rem;font-weight:700}
    .forgot-modal .forgot-strength.weak .forgot-strength-label{color:#bd4340}
    .forgot-modal .forgot-strength.normal .forgot-strength-label{color:#ad781e}
    .forgot-modal .forgot-strength.strong .forgot-strength-label{color:#338954}
    .forgot-modal .password-inline-feedback{min-height:0;margin:5px 0 0;padding:0;font-size:.77rem;font-weight:600;line-height:1.4}
    .forgot-modal .password-inline-feedback:empty{display:none}
    .forgot-modal .password-inline-feedback.success{color:#2F7A4F}
    .forgot-modal .password-inline-feedback.error{color:#A83D3D}
    .forgot-modal .forgot-field input.password-valid{border-color:#79ad83;background:#f7fcf9}
    .forgot-modal .forgot-field input.password-invalid{border-color:#cc665e;background:#fff9f8}
    @media(max-width:480px){
      .forgot-modal,.otp-modal{padding:max(10px,env(safe-area-inset-top)) 10px max(10px,env(safe-area-inset-bottom))}
      .forgot-modal .forgot-card,.otp-modal .otp-card{max-height:calc(var(--login-dialog-visible-height,100dvh) - 22px);padding:27px 18px 23px;border-radius:23px}
      .forgot-modal .forgot-card h2,.otp-modal .otp-card h2{font-size:1.45rem}
      .otp-modal .otp-inputs{gap:7px}
      .otp-modal .otp-digit{height:46px;border-radius:9px;font-size:1.06rem}
    }
    @media(max-width:340px){.otp-modal .otp-inputs{gap:5px}.otp-modal .otp-digit{height:42px;font-size:1rem}}
    @media(prefers-reduced-motion:reduce){
      .otp-modal.open,.otp-modal.open>.otp-card{animation:none}
    }
  </style>
</head>
<body>
<?php $publicActive = 'login'; include __DIR__ . '/public_nav.php'; ?>
<?php if ($loginNotice !== '' || $error !== ''): ?>
    <div class="login-notification-stack" aria-label="Login notifications">
      <?php if ($loginNotice !== ''): ?>
        <div class="login-notification" role="status" data-login-notification data-auto-dismiss="12000">
          <span class="login-notification-icon" aria-hidden="true">i</span>
          <span class="login-notification-message"><?php echo htmlspecialchars($loginNotice, ENT_QUOTES, 'UTF-8'); ?></span>
          <button class="login-notification-close" type="button" data-dismiss-login-notification aria-label="Dismiss notification">&times;</button>
        </div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="login-notification login-notification--error" role="alert" data-login-notification>
          <span class="login-notification-icon" aria-hidden="true">!</span>
          <span class="login-notification-message"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
          <button class="login-notification-close" type="button" data-dismiss-login-notification aria-label="Dismiss error message">&times;</button>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<main class="login-page">
<div class="login-container">
  <div class="login-card">
    <div class="login-header">
      <h1 class="login-title">Welcome</h1>
    </div>
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
      <button type="submit" class="btn-login<?php echo $loginLockoutRemaining > 0 ? ' is-locked' : ''; ?>" id="signInButton"<?php if ($loginLockoutRemaining > 0): ?> disabled aria-disabled="true" aria-describedby="loginLockoutHint" data-login-lockout-seconds="<?php echo $loginLockoutRemaining; ?>"<?php endif; ?>>Sign In</button>
      <?php if ($loginLockoutRemaining > 0): ?>
        <p class="login-lockout-hint" id="loginLockoutHint">
          Try again in <span id="loginLockoutCountdown"><?php echo sprintf('%02d:%02d', intdiv($loginLockoutRemaining, 60), $loginLockoutRemaining % 60); ?></span>.
        </p>
      <?php endif; ?>
    </form>
    <div class="signup-prompt">
      Don't have an account? <a href="signup.php">Sign up here</a>
    </div>
  </div>
</div>
</main>
<div class="forgot-modal<?php echo $forgotEmailModalOpen ? ' open' : ''; ?>" id="forgotPasswordModal" aria-hidden="<?php echo $forgotEmailModalOpen ? 'false' : 'true'; ?>">
  <section class="forgot-card" role="dialog" aria-modal="true" aria-labelledby="forgotPasswordTitle" tabindex="-1">
    <button type="button" class="forgot-close" id="forgotPasswordClose" aria-label="Close forgot password">&times;</button>
    <div class="forgot-step">STEP 1 OF 3</div>
    <h2 id="forgotPasswordTitle">Forgot Password?</h2>
    <p class="forgot-description">Enter your registered email to receive a verification code.</p>
    <?php if ($forgotEmailError !== ''): ?>
      <div class="forgot-message" role="alert"><?php echo htmlspecialchars($forgotEmailError, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <form method="POST" action="login.php?forgot=email" id="forgotPasswordForm" autocomplete="off">
      <input type="hidden" name="action" value="forgot_request_otp">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
      <div class="forgot-field">
        <label for="forgotEmail">Email Address</label>
        <input type="email" id="forgotEmail" name="forgot_email" placeholder="yourname@gmail.com" value="<?php echo htmlspecialchars($forgotEmailValue !== '' ? $forgotEmailValue : $email, ENT_QUOTES, 'UTF-8'); ?>" required maxlength="100" autocomplete="email">
      </div>
      <button type="submit" class="forgot-primary" id="forgotSendButton">Send Verification Code</button>
    </form>
    <p class="forgot-helper">For your privacy, we don't disclose whether an email is registered.</p>
  </section>
</div>
<div class="otp-modal<?php echo $forgotOtpModalOpen ? ' open' : ''; ?>" id="forgot-password-otp" aria-hidden="<?php echo $forgotOtpModalOpen ? 'false' : 'true'; ?>">
  <section class="otp-card" role="dialog" aria-modal="true" aria-labelledby="forgotOtpTitle" tabindex="-1">
    <button type="button" class="forgot-close" id="forgotOtpClose" aria-label="Close email verification">&times;</button>
    <div class="forgot-step">STEP 2 OF 3</div>
    <h2 id="forgotOtpTitle">Verify Your Email</h2>
    <p class="forgot-description">Enter the 6-digit code sent to your email.</p>
    <p class="otp-email"><?php echo htmlspecialchars($forgotOtpMaskedEmail, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php if ($forgotOtpError !== ''): ?>
      <div class="forgot-message" role="alert"><?php echo htmlspecialchars($forgotOtpError, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php elseif ($forgotOtpSuccess !== ''): ?>
      <div class="forgot-message forgot-message--success" role="status"><?php echo htmlspecialchars($forgotOtpSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <form action="login.php" method="POST" id="forgot-password-otp-verify-form" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="action" value="forgot_verify_otp">
      <input type="hidden" name="otp" id="forgot-password-otp-value" value="">
      <div class="otp-inputs" aria-label="6-digit verification code">
        <?php for ($i = 1; $i <= 6; $i++): ?>
          <input class="otp-digit" type="text" inputmode="numeric" maxlength="6" <?php echo $i === 1 ? 'autocomplete="one-time-code"' : ''; ?> aria-label="Digit <?php echo $i; ?>" required>
        <?php endfor; ?>
      </div>
      <p class="otp-inline-error" id="forgotOtpInputError" role="alert" aria-live="polite"></p>
      <button class="forgot-primary" type="submit" id="forgot-password-otp-verify-button">Verify Code</button>
    </form>
    <p class="otp-delivery-help">Didn't receive a code? Check your email address and Spam folder.</p>
    <div class="otp-actions">
      <form action="login.php" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="forgot_resend_otp">
        <button class="otp-text-button" type="submit" id="forgot-password-otp-resend-button">Resend Code</button>
      </form>
      <span class="otp-separator" aria-hidden="true">•</span>
      <form action="login.php" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="forgot_change_email">
        <button class="otp-text-button" type="submit">Change Email</button>
      </form>
    </div>
    <p class="otp-expiry">Code expires in 10 minutes.</p>
  </section>
</div>
<div class="forgot-modal<?php echo $forgotResetModalOpen ? ' open' : ''; ?>" id="forgotResetModal" aria-hidden="<?php echo $forgotResetModalOpen ? 'false' : 'true'; ?>">
  <section class="forgot-card forgot-reset-card" role="dialog" aria-modal="true" aria-labelledby="forgotResetTitle" tabindex="-1">
    <button type="button" class="forgot-close" id="forgotResetClose" aria-label="Close reset password">&times;</button>
    <div class="forgot-step">STEP 3 OF 3</div>
    <h2 id="forgotResetTitle">Create New Password</h2>
    <p class="forgot-description">Create a new password for <strong><?php echo htmlspecialchars($forgotResetMaskedEmail, ENT_QUOTES, 'UTF-8'); ?></strong>.</p>
    <?php if ($forgotResetError !== ''): ?>
      <div class="forgot-message" role="alert"><?php echo htmlspecialchars($forgotResetError, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <form class="forgot-reset-form" method="POST" action="login.php?forgot=reset" id="forgotResetForm" autocomplete="off">
      <input type="hidden" name="action" value="forgot_reset_password">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
      <div class="forgot-field">
        <label for="forgotNewPassword">New Password</label>
        <div class="password-wrap">
          <input type="password" id="forgotNewPassword" name="new_password" placeholder="Enter your new password" minlength="8" required autocomplete="new-password" aria-describedby="forgotNewPasswordFeedback forgotStrength" aria-invalid="<?php echo $forgotPasswordReuseError !== '' ? 'true' : 'false'; ?>"<?php echo $forgotPasswordReuseError !== '' ? ' class="password-invalid"' : ''; ?>>
          <button type="button" class="password-toggle reset-password-toggle" data-target="forgotNewPassword" aria-label="Show password" aria-pressed="false">
            <svg class="reset-eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"></path><circle cx="12" cy="12" r="3"></circle></svg>
            <svg class="reset-eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none"><path d="M3 3l18 18"></path><path d="M10.6 10.6a2 2 0 002.8 2.8"></path><path d="M9.9 4.2A10.8 10.8 0 0112 4c6.5 0 10 8 10 8a17.7 17.7 0 01-2.1 3.2"></path><path d="M6.2 6.2C3.5 8.1 2 12 2 12s3.5 8 10 8a9.8 9.8 0 004.1-.9"></path></svg>
          </button>
        </div>
        <div class="password-inline-feedback<?php echo $forgotPasswordReuseError !== '' ? ' error' : ''; ?>" id="forgotNewPasswordFeedback" role="alert"><?php echo htmlspecialchars($forgotPasswordReuseError, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="forgot-strength" id="forgotStrength" role="status" aria-live="polite">
          <span class="forgot-strength-bar" aria-hidden="true"><i></i><i></i><i></i></span>
          <span class="forgot-strength-label" id="forgotStrengthLabel"></span>
        </div>
      </div>
      <div class="forgot-field">
        <label for="forgotConfirmPassword">Confirm New Password</label>
        <div class="password-wrap">
          <input type="password" id="forgotConfirmPassword" name="confirm_password" placeholder="Re-enter your new password" minlength="8" required autocomplete="new-password" aria-describedby="forgotPasswordMatchFeedback">
          <button type="button" class="password-toggle reset-password-toggle" data-target="forgotConfirmPassword" aria-label="Show password" aria-pressed="false">
            <svg class="reset-eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"></path><circle cx="12" cy="12" r="3"></circle></svg>
            <svg class="reset-eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none"><path d="M3 3l18 18"></path><path d="M10.6 10.6a2 2 0 002.8 2.8"></path><path d="M9.9 4.2A10.8 10.8 0 0112 4c6.5 0 10 8 10 8a17.7 17.7 0 01-2.1 3.2"></path><path d="M6.2 6.2C3.5 8.1 2 12 2 12s3.5 8 10 8a9.8 9.8 0 004.1-.9"></path></svg>
          </button>
        </div>
        <div class="password-inline-feedback" id="forgotPasswordMatchFeedback" role="status" aria-live="polite"></div>
      </div>
      <button type="submit" class="forgot-primary" id="forgotResetButton">Update Password</button>
    </form>
  </section>
</div>
<script>
  // Match the visible viewport, including when a phone's keyboard is open.
  // Keep existing OTP, forgot/reset actions and validations unchanged.
  function updateLoginDialogViewport() {
    const viewport = window.visualViewport;
    const visibleHeight = viewport ? viewport.height : document.documentElement.clientHeight;
    const visibleTop = viewport ? viewport.offsetTop : 0;
    const root = document.documentElement.style;
    root.setProperty('--login-dialog-visible-height', `${Math.max(1, Math.round(visibleHeight))}px`);
    root.setProperty('--login-dialog-visible-top', `${Math.max(0, Math.round(visibleTop))}px`);
  }
  updateLoginDialogViewport();
  window.addEventListener('resize', updateLoginDialogViewport, { passive: true });
  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', updateLoginDialogViewport, { passive: true });
    window.visualViewport.addEventListener('scroll', updateLoginDialogViewport, { passive: true });
  }
  document.addEventListener('focusin', function (event) {
    const field = event.target;
    if (!(field instanceof HTMLElement) || !field.closest('.forgot-modal.open, .otp-modal.open')) return;
    updateLoginDialogViewport();
    window.setTimeout(function () {
      updateLoginDialogViewport();
      // Scroll inside the dialog if the keyboard obscures a focused field.
      field.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'auto' });
    }, 180);
  });
  // Informational notices leave automatically; login errors stay until dismissed.
  document.querySelectorAll('[data-login-notification]').forEach(function (notice) {
    const close = notice.querySelector('[data-dismiss-login-notification]');
    const dismiss = function () {
      if (notice.classList.contains('is-leaving')) return;
      notice.classList.add('is-leaving');
      window.setTimeout(function () {
        notice.remove();
        const stack = document.querySelector('.login-notification-stack');
        if (stack && !stack.children.length) stack.remove();
      }, 320);
    };
    if (close) close.addEventListener('click', dismiss);
    const timeout = Number(notice.getAttribute('data-auto-dismiss'));
    if (Number.isFinite(timeout) && timeout > 0) window.setTimeout(dismiss, timeout);
  });
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
    const initialLockoutSeconds = Number(signInButton.dataset.loginLockoutSeconds || 0);
    if (initialLockoutSeconds > 0) {
      const lockoutEndsAt = Date.now() + initialLockoutSeconds * 1000;
      const countdown = document.getElementById('loginLockoutCountdown');
      const hint = document.getElementById('loginLockoutHint');
      let countdownTimer = null;
      const updateLockoutCountdown = function () {
        const secondsLeft = Math.max(0, Math.ceil((lockoutEndsAt - Date.now()) / 1000));
        if (secondsLeft === 0) {
          signInButton.disabled = false;
          signInButton.classList.remove('is-locked');
          signInButton.removeAttribute('aria-disabled');
          signInButton.removeAttribute('aria-describedby');
          signInButton.removeAttribute('data-login-lockout-seconds');
          if (hint) {
            hint.textContent = 'You can now try signing in again.';
            hint.setAttribute('role', 'status');
          }
          if (countdownTimer !== null) window.clearInterval(countdownTimer);
          return;
        }
        if (countdown) {
          const minutes = String(Math.floor(secondsLeft / 60)).padStart(2, '0');
          const seconds = String(secondsLeft % 60).padStart(2, '0');
          countdown.textContent = `${minutes}:${seconds}`;
        }
      };
      updateLockoutCountdown();
      if (signInButton.disabled) countdownTimer = window.setInterval(updateLockoutCountdown, 1000);
    }
    loginForm.addEventListener('submit', function (event) {
      if (signInButton.disabled || !loginForm.checkValidity()) {
        event.preventDefault();
        return;
      }
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
  const forgotResetModal = document.getElementById('forgotResetModal');
  const forgotOtpModal = document.querySelector('.otp-modal');

  // Password-reset OTP input is now owned by Login (otp_service.php stays the backend).
  const forgotOtpDigits = Array.from(forgotOtpModal?.querySelectorAll('.otp-digit') || []);
  const forgotOtpVerifyForm = document.getElementById('forgot-password-otp-verify-form');
  const forgotOtpHiddenValue = document.getElementById('forgot-password-otp-value');
  const forgotOtpVerifyButton = document.getElementById('forgot-password-otp-verify-button');
  const forgotOtpResendButton = document.getElementById('forgot-password-otp-resend-button');
  const forgotOtpInlineError = document.getElementById('forgotOtpInputError');
  const forgotOtpResendDeadline = Date.now() + <?php echo (int)$forgotOtpResendSeconds; ?> * 1000;
  const syncForgotOtp = () => {
    const code = forgotOtpDigits.map(input => input.value).join('');
    if (forgotOtpHiddenValue) forgotOtpHiddenValue.value = code;
    if (forgotOtpInlineError) forgotOtpInlineError.textContent = '';
    return code;
  };
  forgotOtpDigits.forEach((input,index) => {
    input.addEventListener('input', () => {
      const incoming = input.value.replace(/\D/g,'');
      if (incoming.length > 1) {
        incoming.slice(0,6-index).split('').forEach((digit,j) => { if (forgotOtpDigits[index+j]) forgotOtpDigits[index+j].value = digit; });
        forgotOtpDigits[Math.min(5,index+incoming.length-1)]?.focus();
      } else {
        input.value = incoming.slice(0,1);
        if (input.value) forgotOtpDigits[index+1]?.focus();
      }
      syncForgotOtp();
    });
    input.addEventListener('keydown',event => {
      if (event.key === 'Backspace' && !input.value && forgotOtpDigits[index-1]) forgotOtpDigits[index-1].focus();
      if (event.key === 'ArrowLeft' && forgotOtpDigits[index-1]) { event.preventDefault(); forgotOtpDigits[index-1].focus(); }
      if (event.key === 'ArrowRight' && forgotOtpDigits[index+1]) { event.preventDefault(); forgotOtpDigits[index+1].focus(); }
    });
    input.addEventListener('paste',event => {
      const pasted = (event.clipboardData?.getData('text') || '').replace(/\D/g,'').slice(0,6);
      if (!pasted) return;
      event.preventDefault();
      pasted.split('').forEach((digit,i) => { if (forgotOtpDigits[i]) forgotOtpDigits[i].value = digit; });
      syncForgotOtp();
      forgotOtpDigits[Math.min(5,pasted.length-1)]?.focus();
    });
  });
  forgotOtpVerifyForm?.addEventListener('submit',event => {
    const code = syncForgotOtp();
    if (!/^\d{6}$/.test(code)) {
      event.preventDefault();
      if (forgotOtpInlineError) forgotOtpInlineError.textContent = 'Enter the complete 6-digit code.';
      (forgotOtpDigits.find(input => !input.value) || forgotOtpDigits[0])?.focus();
      return;
    }
    if (forgotOtpVerifyButton) {
      forgotOtpVerifyButton.disabled = true;
      forgotOtpVerifyButton.textContent = 'Verifying...';
    }
  });
  let forgotOtpResendTimer = null;
  const updateForgotOtpResend = () => {
    if (!forgotOtpResendButton) return;
    const secondsLeft = Math.max(0, Math.ceil((forgotOtpResendDeadline - Date.now()) / 1000));
    forgotOtpResendButton.disabled = secondsLeft > 0;
    forgotOtpResendButton.textContent = secondsLeft > 0 ? `Resend Code (${secondsLeft}s)` : 'Resend Code';
    if (secondsLeft === 0 && forgotOtpResendTimer !== null) {
      window.clearInterval(forgotOtpResendTimer);
      forgotOtpResendTimer = null;
    }
  };
  updateForgotOtpResend();
  if (forgotOtpResendButton && Date.now() < forgotOtpResendDeadline) {
    forgotOtpResendTimer = window.setInterval(updateForgotOtpResend, 1000);
  }
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) updateForgotOtpResend();
  });
  document.getElementById('forgotOtpClose')?.addEventListener('click', () => closeLoginPopup('otp'));
  document.getElementById('forgotResetClose')?.addEventListener('click', () => closeLoginPopup('reset'));

  // Android/iPhone browser Back and desktop Alt+Left close the frontmost popup
  // before leaving Login. A popup gets one same-document history entry.
  const loginPopupHistoryKey = '__lokalcraftLoginPopup';
  const loginPopups = {
    email: forgotPasswordModal,
    otp: forgotOtpModal,
    reset: forgotResetModal
  };
  const popupIsOpen = popup => Boolean(popup?.classList.contains('open'));
  const updateLoginPopupScroll = () => {
    document.body.style.overflow = Object.values(loginPopups).some(popupIsOpen) ? 'hidden' : '';
  };
  const showLoginPopup = (name, focusField = false) => {
    Object.entries(loginPopups).forEach(([key, popup]) => {
      if (!popup) return;
      const isOpen = key === name;
      popup.classList.toggle('open', isOpen);
      popup.setAttribute('aria-hidden', String(!isOpen));
    });
    updateLoginPopupScroll();
    if (focusField && name === 'email') {
      window.setTimeout(() => forgotEmailInput?.focus({ preventScroll: true }), 80);
    } else if (focusField && name === 'otp') {
      window.setTimeout(() => forgotOtpDigits[0]?.focus({ preventScroll: true }), 80);
    } else if (focusField && name === 'reset') {
      window.setTimeout(() => document.getElementById('forgotNewPassword')?.focus({ preventScroll: true }), 80);
    }
  };
  const addLoginPopupHistory = (name, initial = false) => {
    try {
      if (window.history.state?.[loginPopupHistoryKey] === name) return;
      const state = window.history.state;
      const nextState = state && typeof state === 'object' ? { ...state } : {};
      const popupUrl = window.location.href;
      // A server redirect to ?forgot=otp/reset opens an overlay on page load.
      // Leave a clean Login entry underneath, so Back hides the overlay and
      // refreshing after Back does not unexpectedly reopen it.
      if (initial) {
        const cleanUrl = new URL(popupUrl);
        if (cleanUrl.searchParams.has('forgot')) {
          cleanUrl.searchParams.delete('forgot');
          window.history.replaceState(nextState, '', cleanUrl.href);
        }
      }
      window.history.pushState({ ...nextState, [loginPopupHistoryKey]: name }, '', popupUrl);
    } catch (_) {
      // If History API is unavailable, on-screen closing still works.
    }
  };
  let loginPopupBackPending = false;
  const closeLoginPopup = name => {
    if (!popupIsOpen(loginPopups[name]) || loginPopupBackPending) return;
    if (window.history.state?.[loginPopupHistoryKey] === name) {
      loginPopupBackPending = true;
      window.history.back(); // popstate handles closing; don't go back twice.
      window.setTimeout(() => { loginPopupBackPending = false; }, 900);
    } else {
      showLoginPopup('');
    }
  };
  window.addEventListener('popstate', event => {
    loginPopupBackPending = false;
    const requestedPopup = event.state?.[loginPopupHistoryKey];
    // Back closes the overlay; Forward may reopen it without adding a new entry.
    showLoginPopup(Object.prototype.hasOwnProperty.call(loginPopups, requestedPopup)
      ? requestedPopup : '');
  });
  const openForgotPassword = () => {
    if (!forgotPasswordModal) return;
    addLoginPopupHistory('email');
    showLoginPopup('email', true);
  };
  const closeForgotPassword = () => closeLoginPopup('email');
  forgotPasswordLink?.addEventListener('click', openForgotPassword);
  forgotPasswordClose?.addEventListener('click', closeForgotPassword);
  forgotPasswordModal?.addEventListener('click', event => {
    if (event.target === forgotPasswordModal) closeForgotPassword();
  });
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    if (popupIsOpen(forgotOtpModal)) closeLoginPopup('otp');
    else if (popupIsOpen(forgotResetModal)) closeLoginPopup('reset');
    else if (popupIsOpen(forgotPasswordModal)) closeForgotPassword();
  });
  // OTP and reset are opened by PHP after redirects, unlike the email popup.
  // Register their initial open state so the first browser Back closes only it.
  const initiallyOpenPopup = popupIsOpen(forgotOtpModal) ? 'otp'
    : popupIsOpen(forgotResetModal) ? 'reset'
    : popupIsOpen(forgotPasswordModal) ? 'email' : '';
  if (initiallyOpenPopup) {
    addLoginPopupHistory(initiallyOpenPopup, true);
    updateLoginPopupScroll();
    if (initiallyOpenPopup === 'email') {
      window.setTimeout(() => forgotEmailInput?.focus({ preventScroll: true }), 80);
    } else if (initiallyOpenPopup === 'otp') {
      window.setTimeout(() => forgotOtpDigits[0]?.focus({ preventScroll: true }), 80);
    }
  }
  forgotPasswordForm?.addEventListener('submit', function () {
    if (!forgotPasswordForm.checkValidity()) return;
    if (forgotSendButton) {
      forgotSendButton.disabled = true;
      forgotSendButton.textContent = 'Sending Code...';
      forgotSendButton.setAttribute('aria-busy', 'true');
    }
  });
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
  const forgotNewPasswordFeedback = document.getElementById('forgotNewPasswordFeedback');
  const forgotPasswordMatchFeedback = document.getElementById('forgotPasswordMatchFeedback');
  const forgotStrength = document.getElementById('forgotStrength');
  const forgotStrengthLabel = document.getElementById('forgotStrengthLabel');
  const resetPasswordRulesMet = value =>
    value.length >= 8 && /[A-Z]/.test(value) && /[a-z]/.test(value) && /[0-9]/.test(value);
  const validateNewPassword = (showErrors = false) => {
    if (!forgotNewPassword) return false;
    const value = forgotNewPassword.value;
    const valid = resetPasswordRulesMet(value);
    const level = !value ? '' : valid && value.length >= 12 ? 'strong' : valid ? 'normal' : 'weak';
    if (forgotStrength) forgotStrength.className = 'forgot-strength' + (level ? ' ' + level : '');
    if (forgotStrengthLabel) forgotStrengthLabel.textContent = level ? level[0].toUpperCase() + level.slice(1) : '';
    forgotNewPassword.classList.toggle('password-valid',valid && Boolean(value));
    forgotNewPassword.classList.toggle('password-invalid',showErrors && !valid);
    forgotNewPassword.setAttribute('aria-invalid',String(showErrors && !valid));
    if (showErrors && !valid && forgotNewPasswordFeedback) {
      forgotNewPasswordFeedback.textContent = 'Use 8+ characters, uppercase, lowercase, and a number.';
      forgotNewPasswordFeedback.className = 'password-inline-feedback error';
    }
    return valid;
  };
  const validatePasswordMatch = (showErrors = false) => {
    if (!forgotNewPassword || !forgotConfirmPassword || !forgotPasswordMatchFeedback) return false;
    const confirmation = forgotConfirmPassword.value;
    const matches = Boolean(confirmation && confirmation === forgotNewPassword.value);
    forgotPasswordMatchFeedback.textContent = !confirmation ? '' : matches ? '✓ Passwords match.' : 'Passwords do not match.';
    forgotPasswordMatchFeedback.className = 'password-inline-feedback' + (confirmation ? matches ? ' success' : ' error' : '');
    forgotConfirmPassword.classList.toggle('password-valid',matches);
    forgotConfirmPassword.classList.toggle('password-invalid',showErrors && Boolean(confirmation) && !matches);
    forgotConfirmPassword.setAttribute('aria-invalid',String(Boolean(confirmation) && !matches));
    return matches;
  };
  forgotNewPassword?.addEventListener('input', () => {
    // A server-side password reuse error is cleared only when the user edits the field.
    if (forgotNewPasswordFeedback?.textContent.trim()) {
      forgotNewPasswordFeedback.textContent = '';
      forgotNewPasswordFeedback.className = 'password-inline-feedback';
    }
    validateNewPassword(false);
    if (forgotConfirmPassword?.value) validatePasswordMatch(true);
  });
  forgotConfirmPassword?.addEventListener('input', () => validatePasswordMatch(true));
  forgotResetForm?.addEventListener('submit', event => {
    const valid = validateNewPassword(true);
    const matched = validatePasswordMatch(true);
    if (!forgotResetForm.checkValidity() || !valid || !matched) {
      event.preventDefault();
      if (!valid) forgotNewPassword?.focus();
      else if (!matched) forgotConfirmPassword?.focus();
      return;
    }
    if (forgotResetButton) {
      forgotResetButton.disabled = true;
      forgotResetButton.textContent = 'Updating Password...';
      forgotResetButton.setAttribute('aria-busy','true');
    }
  });
  if (popupIsOpen(forgotResetModal)) {
    window.setTimeout(() => forgotNewPassword?.focus({ preventScroll: true }), 80);
  }
  window.addEventListener('pageshow', event => {
    updateForgotOtpResend();
    if (!event.persisted) return;
    if (signInButton && !signInButton.classList.contains('is-locked')) {
      signInButton.disabled = false;
      signInButton.textContent = 'Sign In';
      signInButton.removeAttribute('aria-busy');
    }
    if (forgotSendButton) {
      forgotSendButton.disabled = false;
      forgotSendButton.textContent = 'Send Verification Code';
      forgotSendButton.removeAttribute('aria-busy');
    }
    if (forgotOtpVerifyButton) {
      forgotOtpVerifyButton.disabled = false;
      forgotOtpVerifyButton.textContent = 'Verify Code';
    }
    if (forgotResetButton) {
      forgotResetButton.disabled = false;
      forgotResetButton.textContent = 'Update Password';
      forgotResetButton.removeAttribute('aria-busy');
    }
    updateLoginPopupScroll();
  });
</script>
</body>
</html>
