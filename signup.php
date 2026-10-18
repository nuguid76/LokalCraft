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
if (empty($_SESSION['signup_csrf'])) {
    $_SESSION['signup_csrf'] = bin2hex(random_bytes(32));
}
$errorMessage = '';
$successMessage = '';
$otpFlowKey = 'buyer_signup';
$showVerifiedTransition =
    isset($_GET['verified'])
    && $_GET['verified'] === '1'
    && !empty($_SESSION['signup_verified_transition']);
if ($showVerifiedTransition) {
    unset($_SESSION['signup_verified_transition']);
}
function signupTextLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : (preg_match_all('/./us', $value) ?: 0);
}
function signupNameIsValid(string $value, bool $middle = false): bool
{
    if ($value === '' || signupTextLength($value) > 50) return false;
    $separator = $middle ? "[ '-]" : '[ -]';
    return preg_match('/^\p{L}+(?:' . $separator . '\p{L}+)*$/u', $value) === 1;
}
function signupFormatName(string $value): string
{
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    $value = function_exists('mb_convert_case')
        ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8')
        : ucwords(strtolower($value), " -'");
    return preg_replace_callback('/\b(Jr|Sr|Ii|Iii|Iv|V|Vi|Vii|Viii|Ix|X|Xi)\z/u', static function ($matches) {
        return in_array(strtolower($matches[1]), ['jr','sr'], true) ? ucfirst(strtolower($matches[1])) : strtoupper($matches[1]);
    }, $value) ?? $value;
}
function signupNormalizePhone(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (str_starts_with($digits, '63')) return '0' . substr($digits, 2);
    if (str_starts_with($digits, '9')) return '0' . $digits;
    return $digits;
}
function signupEmailIsValid(string $email): bool
{
    if ($email === '' || strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $domain = strtolower((string)substr(strrchr($email, '@') ?: '', 1));
    if (in_array($domain, ['g.mail.com','gmail.con','yahoo.con'], true)) return false;
    // If DNS is available on the hosting server, check that the domain can receive mail.
    if (function_exists('checkdnsrr') && checkdnsrr('gmail.com', 'MX')) {
        return checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A');
    }
    return true;
}
function signupEmailAlreadyExists(mysqli $conn, string $email): bool
{
    $stmt = $conn->prepare(
        'SELECT id
         FROM users
         WHERE LOWER(email) = LOWER(?)
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to check the email address.');
    }
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->bind_result($existingId);
    $exists = $stmt->fetch();
    $stmt->close();
    return $exists === true;
}
function signupValidateAccountData(string $firstname, string $middlename, string $lastname, string $email, string $phone): string
{
    if ($firstname === '' || $lastname === '' || $email === '' || $phone === '') return 'Please complete all required fields.';
    if (!signupNameIsValid($firstname)) return 'First name: letters, spaces and hyphens only, up to 50 characters.';
    if ($middlename !== '' && !signupNameIsValid($middlename, true)) return 'Middle name: letters, spaces, hyphens and apostrophes only, up to 50 characters.';
    if (!signupNameIsValid($lastname)) return 'Last name: letters, spaces and hyphens only, up to 50 characters. Suffixes such as Jr or III can follow the surname.';
    if (!signupEmailIsValid($email)) return 'Please enter a valid email address.';
    if (!preg_match('/^09\d{9}$/', $phone)) return 'Phone number must be 11 digits and start with 09.';
    return '';
}
function signupPasswordError(string $password, string $confirmPassword): string
{
    if ($password === '') return 'Please enter a password.';
    if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return 'Password needs at least 8 characters, an uppercase letter, a lowercase letter, and a number.';
    }
    return $password === $confirmPassword ? '' : 'Passwords do not match.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
        ? $_POST['csrf_token']
        : '';
    if (!hash_equals((string)($_SESSION['signup_csrf'] ?? ''), $csrfToken)) {
        http_response_code(403);
        exit('Invalid form token. Please reload the page and try again.');
    }
    $action = isset($_POST['action']) && is_string($_POST['action'])
        ? trim($_POST['action'])
        : 'request_otp';
    $allowedActions = [
        'request_otp',
        'verify_otp',
        'resend_otp',
        'cancel_otp',
        'check_email',
    ];
    if (!in_array($action, $allowedActions, true)) {
        $errorMessage = 'Invalid signup request. Please reload the page and try again.';
    } elseif ($action === 'check_email') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');
        $checkEmail = strtolower(trim((string)($_POST['email'] ?? '')));
        if (!signupEmailIsValid($checkEmail)) {
            echo json_encode(['success'=>false,'message'=>'Enter a valid email address.']);
            exit;
        }
        try {
            $exists = signupEmailAlreadyExists($conn, $checkEmail);
            echo json_encode($exists
                ? ['success'=>false,'message'=>'This email already has an account. Please log in.']
                : ['success'=>true,'available'=>true,'message'=>'Email is available.']);
        } catch (Throwable $e) {
            error_log('Buyer signup email check failed: ' . $e->getMessage());
            http_response_code(503);
            echo json_encode(['success'=>false,'message'=>'Email check is unavailable. Try again.']);
        }
        exit;
    } elseif ($action === 'cancel_otp') {
        otpClearChallenge($otpFlowKey);
        header('Location: signup.php');
        exit;
    } elseif ($action === 'resend_otp') {
        $result = otpResendChallenge($otpFlowKey);
        if (!empty($result['success'])) {
            $successMessage = (string)($result['message'] ?? '');
        } else {
            $errorMessage = (string)($result['message'] ?? 'Unable to resend the verification code.');
        }
    } elseif ($action === 'verify_otp') {
        $verification = otpVerifyChallenge(
            $otpFlowKey,
            isset($_POST['otp']) && is_string($_POST['otp'])
                ? $_POST['otp']
                : ''
        );
        if (empty($verification['success'])) {
            $errorMessage = (string)($verification['message'] ?? 'Unable to verify the code.');
        } else {
            try {
                $payload = is_array($verification['payload'] ?? null)
                    ? $verification['payload']
                    : [];
                $firstname = trim((string)($payload['firstname'] ?? ''));
                $middlename = trim((string)($payload['middlename'] ?? ''));
                $lastname = trim((string)($payload['lastname'] ?? ''));
                $email = strtolower(trim((string)($verification['email'] ?? '')));
                $phone = signupNormalizePhone((string)($payload['phone'] ?? ''));
                $hashedPassword = (string)($payload['password_hash'] ?? '');
                $accountError = signupValidateAccountData(
                    $firstname,
                    $middlename,
                    $lastname,
                    $email,
                    $phone
                );
                if ($accountError !== '') {
                    otpClearChallenge($otpFlowKey);
                    throw new RuntimeException($accountError);
                }
                if ($hashedPassword === '' || !password_get_info($hashedPassword)['algo']) {
                    otpClearChallenge($otpFlowKey);
                    throw new RuntimeException('The signup session is invalid. Please register again.');
                }
                if (signupEmailAlreadyExists($conn, $email)) {
                    otpClearChallenge($otpFlowKey);
                    $errorMessage = 'An account with this email already exists.';
                } else {
                    $firstname = signupFormatName($firstname);
                    $lastname = signupFormatName($lastname);
                    $middleNameForDatabase = $middlename !== '' ? signupFormatName($middlename) : null;
                    $stmt = $conn->prepare(
                        'INSERT INTO users
                        (
                            firstname,
                            lastname,
                            middlename,
                            email,
                            phone,
                            password,
                            SellerOrNot,
                            AdminOrNot,
                            account_status
                        )
                        VALUES (?, ?, ?, ?, ?, ?, 0, 0, "active")'
                    );
                    if (!$stmt) {
                        throw new RuntimeException('Unable to prepare account creation.');
                    }
                    $stmt->bind_param(
                        'ssssss',
                        $firstname,
                        $lastname,
                        $middleNameForDatabase,
                        $email,
                        $phone,
                        $hashedPassword
                    );
                    if (!$stmt->execute()) {
                        $statementErrno = $stmt->errno;
                        $stmt->close();
                        if ($statementErrno === 1062) {
                            otpClearChallenge($otpFlowKey);
                            $errorMessage = 'An account with this email already exists.';
                        } else {
                            throw new RuntimeException('Unable to save the account.');
                        }
                    } else {
                        $stmt->close();
                        otpClearChallenge($otpFlowKey);
                        unset($_SESSION['signup_csrf']);
                        $_SESSION['login_success'] =
                            'Email verified successfully. Your LokalCraft account has been created. Please log in to continue.';
                        $_SESSION['signup_verified_transition'] = true;
                        header('Location: signup.php?verified=1');
                        exit;
                    }
                }
            } catch (Throwable $e) {
                error_log('Buyer OTP verification signup failed: ' . $e->getMessage());
                if ($errorMessage === '') {
                    $errorMessage = $e instanceof RuntimeException
                        ? $e->getMessage()
                        : 'Unable to create your account right now. Please try again.';
                }
            }
        }
    } elseif ($action === 'request_otp') {
        $firstname = isset($_POST['firstname']) && is_string($_POST['firstname'])
            ? trim($_POST['firstname'])
            : '';
        $middlename = isset($_POST['middlename']) && is_string($_POST['middlename'])
            ? trim($_POST['middlename'])
            : '';
        $lastname = isset($_POST['lastname']) && is_string($_POST['lastname'])
            ? trim($_POST['lastname'])
            : '';
        $email = isset($_POST['email']) && is_string($_POST['email'])
            ? strtolower(trim($_POST['email']))
            : '';
        $phone = isset($_POST['phone']) && is_string($_POST['phone'])
            ? signupNormalizePhone($_POST['phone'])
            : '';
        $password = isset($_POST['password']) && is_string($_POST['password'])
            ? $_POST['password']
            : '';
        $confirmPassword =
            isset($_POST['confirm_password']) && is_string($_POST['confirm_password'])
                ? $_POST['confirm_password']
                : '';
        $errorMessage = signupValidateAccountData(
            $firstname,
            $middlename,
            $lastname,
            $email,
            $phone
        );
        if ($errorMessage === '') {
            $errorMessage = signupPasswordError($password, $confirmPassword);
        }
        if ($errorMessage === '' && !isset($_POST['terms'])) {
            $errorMessage = 'Please agree to the Terms and Privacy Policy.';
        }
        if ($errorMessage === '') {
            try {
                if (signupEmailAlreadyExists($conn, $email)) {
                    $errorMessage = 'An account with this email already exists.';
                } else {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    if (!is_string($hashedPassword) || $hashedPassword === '') {
                        throw new RuntimeException('Unable to secure the password.');
                    }
                    otpStartChallenge(
                        $otpFlowKey,
                        $email,
                        trim($firstname . ' ' . $lastname),
                        [
                            'firstname' => $firstname,
                            'middlename' => $middlename,
                            'lastname' => $lastname,
                            'phone' => $phone,
                            'password_hash' => $hashedPassword,
                        ],
                        'confirm your email and finish creating your buyer account'
                    );
                    $successMessage =
                        'We sent a 6-digit verification code to your email.';
                }
            } catch (Throwable $e) {
                error_log('Buyer signup failed: ' . $e->getMessage());
                if ($errorMessage === '') {
                    $errorMessage =
                        'Unable to start email verification right now. Please try again.';
                }
            }
        }
    }
}
$pendingSignup = otpGetChallenge($otpFlowKey);
$otpModalOpen = is_array($pendingSignup);
$otpEmailMasked = $otpModalOpen
    ? otpMaskEmail((string)($pendingSignup['email'] ?? ''))
    : '';
$otpResendSeconds = $otpModalOpen
    ? otpResendSeconds($otpFlowKey)
    : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Sign Up — LokalCraft | Support Filipino Artisans</title>
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
      overflow-x: hidden;
      font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, sans-serif;
      position: relative;
      min-height: 100vh;
      background-image: url('uploads/website background.png');
      background-size: cover;
      background-position: center center;
      background-attachment: scroll;
      background-repeat: no-repeat;
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
    .container {
      max-width: 1380px;
      width: 100%;
      margin: 0 auto;
      padding: 0 32px;
      position: relative;
      z-index: 1;
    }
    .signup-page {
      position: relative;
      z-index: 1;
    }
    /* ========== PUBLIC NAVIGATION LAYER ========== */
    /*
       EXACTLY the same navigation wrapper structure used by login.php:
       full-width fixed outer layer + 1380px centered inner layer.
       This keeps the navigation aligned with main.php/login.php while
       allowing the signup content to scroll independently underneath.
    */
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
    /* Keep the opened public menu above the signup card. */
    .public-nav-container .nav-menu,
    .public-nav-container .mobile-menu,
    .public-nav-container .menu,
    .public-nav-container .dropdown,
    .public-nav-container .dropdown-menu,
    .public-nav-container .menu-panel,
    .public-nav-container .mobile-nav,
    .public-nav-container [class*="menu"],
    .public-nav-container [class*="dropdown"] {
      z-index: 10004 !important;
    }
    /* ========== HEADER ========== */
    .navbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: nowrap;
      padding: 20px 0;
      gap: 20px;
      border-bottom: 1px solid rgba(160, 110, 70, 0.25);
      backdrop-filter: blur(2px);
      position: sticky;
      top: 0;
      z-index: 100;
    }
    .signup-container {
      width: 100%;
      max-width: 560px;
    }
    .back-link {
      display: inline-flex;
      gap: 8px;
      text-decoration: none;
      color: #8b5423;
      font-weight: 900;
      margin-bottom: 0px;
      transition: color 0.2s;
      font-size: 0.95rem;
    }
    .back-link:hover {
      color: #b45824;
    }
    .signup-card {
      position: relative;
      z-index: 1;
      background: rgba(255, 252, 245, 0.96);
      border: 1px solid rgba(220, 180, 135, 0.6);
      border-radius: 30px;
      box-shadow: 0 25px 45px rgba(45, 30, 16, 0.12);
      padding: 40px 34px 32px;
    }
    .login-header {
      text-align: center;
      margin-bottom: 26px;
    }
    .login-logo {
      font-size: 2rem;
      font-weight: 800;
      background: linear-gradient(135deg, #A55325, #C2692E);
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
      margin-bottom: 14px;
    }
    .login-title {
      font-size: 2rem;
      font-weight: 800;
      color: #8b5423;
      line-height: 1.2;
      margin-bottom: 8px;
    }
    .login-subtitle {
      color: #5A3F28;
      font-size: 0.95rem;
      line-height: 1.5;
    }
    .login-form {
      display: flex;
      flex-direction: column;
      gap: 18px;
    }
    .name-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 16px;
    }
    .form-group {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    .form-group label {
      font-size: 0.9rem;
      font-weight: 600;
      color: #2B1E12;
    }
    .form-group input {
      width: 100%;
      background: #F9EFE2;
      border: 1.5px solid #E7CDB0;
      border-radius: 15px;
      padding: 13px 16px;
      font-size: 1rem;
      color: #2B1E12;
      font-family: inherit;
      transition: all 0.2s ease;
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
    .password-field {
      position: relative;
      width: 100%;
    }
    .password-field input {
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
    .signup-field-error{display:block;color:#b33;font-size:.78rem;line-height:1.4;margin-top:1px}
    .signup-field-error:empty{display:none}
    .form-group input.input-invalid{border-color:#cc665e;background:#fff9f8}
    .form-group input.input-valid{border-color:#79ad83}
    .email-check-message{display:block;margin-top:1px;font-size:.78rem;line-height:1.45}
    .email-check-message:empty{display:none}
    .email-check-message.success{color:#347d4a}
    .email-check-message.error{color:#b33}
    .email-check-message.info{color:#907047}
    .password-strength{display:flex;align-items:center;gap:10px;margin-top:2px;min-height:17px}
    .password-strength-bar{display:flex;gap:4px;flex:1;height:5px;max-width:110px}
    .password-strength-bar i{flex:1;border-radius:6px;background:#e6d9cb;transition:background .16s}
    .password-strength.weak i:first-child{background:#d94c47}
    .password-strength.normal i:nth-child(-n+2){background:#efa63e}
    .password-strength.strong i{background:#3a9b62}
    .password-strength-label{font-size:.75rem;font-weight:700}
    .password-strength.weak .password-strength-label{color:#bd4340}
    .password-strength.normal .password-strength-label{color:#ad781e}
    .password-strength.strong .password-strength-label{color:#338954}
    .password-match{margin-top:2px;font-size:.78rem;font-weight:600;line-height:1.4}
    .password-match:empty{display:none}
    .password-match.valid{color:#347d4a}
    .password-match.invalid{color:#b33}
    .checkbox-row {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      color: #3F2E1F;
      font-size: 0.9rem;
      line-height: 1.5;
      cursor: pointer;
    }
    .checkbox-row input {
      width: 16px;
      height: 16px;
      margin-top: 3px;
      accent-color: #B85C28;
      cursor: pointer;
    }
    .checkbox-row a {
      color: #B85C28;
      font-weight: 700;
      text-decoration: none;
    }
    .checkbox-row a:hover {
      text-decoration: underline;
    }
    .policy-modal {
      position: fixed;
      inset: 0;
      z-index: 20000 !important;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
      background: rgba(43, 30, 18, 0.42);
    }
    .policy-modal.open {
      display: flex;
    }
    .policy-card {
      position: relative;
      z-index: 20001;
      width: min(620px, 100%);
      max-height: min(760px, 90vh);
      overflow: hidden;
      border: 1px solid #E7CDB0;
      border-radius: 20px;
      background: #FFFCF5;
      box-shadow: 0 24px 60px rgba(45, 30, 16, 0.24);
    }
    .policy-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      padding: 20px 24px;
      border-bottom: 1px solid #E7CDB0;
    }
    .policy-header h2 {
      margin: 0;
      color: #8b5423;
      font-size: 1.35rem;
    }
    .policy-close {
      width: 32px;
      height: 32px;
      border: 0;
      border-radius: 50%;
      background: #F9EFE2;
      color: #8b5423;
      font-size: 1.25rem;
      cursor: pointer;
    }
    .policy-content {
      max-height: calc(min(760px, 90vh) - 78px);
      overflow-y: auto;
      padding: 22px 24px 26px;
      color: #3F2E1F;
      font-size: 0.92rem;
      line-height: 1.6;
    }
    .policy-content h2 {
      display: none;
    }
    .policy-content h3 {
      margin: 0 0 8px;
      color: #8b5423;
      font-size: 1.05rem;
    }
    .policy-content h3:not(:first-of-type) {
      margin-top: 24px;
    }
    .policy-content p {
      margin: 0 0 10px;
    }
    .policy-content ul {
      margin: 0;
      padding-left: 22px;
    }
    .btn-login {
      width: 100%;
      margin-top: 8px;
      background-color: #B85C28;
      border: none;
      color: white;
      font-weight: 700;
      font-size: 1rem;
      padding: 15px 24px;
      border-radius: 60px;
      cursor: pointer;
      font-family: inherit;
      transition: all 0.2s ease;
      box-shadow: 0 6px 14px rgba(160, 80, 35, 0.3);
    }
    .btn-login:hover {
      background-color: #94481F;
      transform: translateY(-2px);
      box-shadow: 0 8px 18px rgba(160, 80, 35, 0.4);
    }
    .divider {
      display: flex;
      align-items: center;
      gap: 12px;
      margin: 10px 0 4px;
      color: #9A7F69;
      font-size: 0.9rem;
    }
    .divider::before,
    .divider::after {
      content: "";
      flex: 1;
      height: 1px;
      background: #E7CDB0;
    }
    .social-login {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    .btn-social {
      width: 100%;
      background: #F9EFE2;
      border: 1.5px solid #E7CDB0;
      color: #3F2E1F;
      font-weight: 600;
      font-size: 0.95rem;
      padding: 12px 18px;
      border-radius: 16px;
      cursor: pointer;
      font-family: inherit;
      transition: all 0.2s;
    }
    .btn-social:hover {
      background: #FFF7EE;
      border-color: #D89C6C;
    }
    .switch-text {
      text-align: center;
      color: #3F2E1F;
      font-size: 0.92rem;
      margin-top: 18px;
    }
    .switch-text a {
      color: #B85C28;
      text-decoration: none;
      font-weight: 700;
    }
    .signup-page {
      min-height: 100svh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 138px 24px 40px;
      position: relative;
      z-index: 1;
    }
    @media (max-width: 560px) {
      body {
        background-attachment: scroll;
        background-position: center center;
      }
      .signup-page {
        min-height: 100svh;
        padding: 108px 16px 28px;
      }
      .public-nav-container .public-nav-inner {
        padding: 0 16px;
      }
      .signup-card {
        padding: 32px 22px 24px;
      }
      .name-grid {
        grid-template-columns: 1fr;
      }
    }
    .public-header-inner {
      width: 100%;
    }
    .auth-transition-overlay {
      position: fixed;
      inset: 0;
      z-index: 99999;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background: rgba(255, 250, 240, 0.98);
      backdrop-filter: blur(8px);
    }
    .auth-transition-card {
      width: min(440px, 100%);
      padding: 38px 30px 32px;
      border: 1px solid rgba(133, 83, 31, 0.14);
      border-radius: 24px;
      background: #fffdf8;
      box-shadow: 0 22px 60px rgba(85, 52, 19, 0.16);
      text-align: center;
    }
    .auth-transition-check {
      width: 72px;
      height: 72px;
      margin: 0 auto 18px;
      border-radius: 50%;
      display: grid;
      place-items: center;
      background: #f3e5c8;
      color: #6f461f;
      font-size: 34px;
      font-weight: 800;
    }
    .auth-transition-card h2 {
      margin: 0 0 10px;
      color: #4b2e15;
      font-size: 26px;
    }
    .auth-transition-card p {
      margin: 0;
      color: #745b45;
      line-height: 1.6;
    }
    .auth-transition-loading {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      margin-top: 22px;
      color: #6f461f;
      font-weight: 700;
    }
    .auth-transition-spinner {
      width: 19px;
      height: 19px;
      border: 3px solid #eadbc2;
      border-top-color: #8b5a2b;
      border-radius: 50%;
      animation: authSpin .8s linear infinite;
    }
    .auth-transition-link {
      display: inline-block;
      margin-top: 18px;
      color: #7b4c22;
      font-weight: 700;
      text-decoration: none;
    }
    .auth-transition-link:hover {
      text-decoration: underline;
    }
    @keyframes authSpin {
      to { transform: rotate(360deg); }
    }
  </style>
</head>
<body>
<div class="public-nav-container">
  <div class="public-nav-inner">
    <?php $publicActive = 'signup'; include __DIR__ . '/public_nav.php'; ?>
  </div>
</div>
  <main class="signup-page">
    <div class="signup-card">
      <div class="login-card">
      <div class="login-header">
        <div class="login-logo">LokalCraft</div>
        <h1 class="login-title">Create your account</h1>
        <p class="login-subtitle">Join our community and support Filipino artisans with authentic handcrafted products.</p>
      </div>
      <?php if ($errorMessage !== '' && !$otpModalOpen): ?>
        <div style="margin-bottom:16px;padding:12px 14px;border:1px solid #efc0c0;border-radius:12px;background:#fff2f2;color:#b33;font-size:.9rem;">
          <?php echo htmlspecialchars($errorMessage); ?>
        </div>
      <?php endif; ?>
      <form class="login-form" action="signup.php" method="POST" autocomplete="off">
          <input type="hidden" name="action" value="request_otp">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['signup_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
        <div class="name-grid">
          <div class="form-group">
            <label for="firstname">First Name</label>
            <input type="text" id="firstname" name="firstname" maxlength="50" value="<?php echo htmlspecialchars($_POST['firstname'] ?? ''); ?>" placeholder="Enter your first name" required autocomplete="given-name">
          <span class="signup-field-error" id="firstnameError" aria-live="polite"></span>
          </div>
          <div class="form-group">
            <label for="middlename">Middle Name</label>
            <input type="text" id="middlename" name="middlename" maxlength="50" value="<?php echo htmlspecialchars($_POST['middlename'] ?? ''); ?>" placeholder="Enter your middle name (optional)" autocomplete="additional-name">
          <span class="signup-field-error" id="middlenameError" aria-live="polite"></span>
          </div>
          <div class="form-group" style="grid-column: 1 / -1;">
            <label for="lastname">Last Name</label>
            <input type="text" id="lastname" name="lastname" maxlength="50" value="<?php echo htmlspecialchars($_POST['lastname'] ?? ''); ?>" placeholder="Enter your last name" required autocomplete="family-name">
          <span class="signup-field-error" id="lastnameError" aria-live="polite"></span>
          </div>
        </div>
        <div class="form-group">
          <label for="email">Email Address</label>
          <input type="email" id="email" name="email" maxlength="100" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" placeholder="Enter your email address" required autocomplete="email">
          <span class="email-check-message" id="emailCheckMessage" aria-live="polite"></span>
        </div>
        <div class="form-group">
          <label for="phone">Phone Number</label>
          <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? '09', ENT_QUOTES, 'UTF-8'); ?>" placeholder="09XXXXXXXXX" maxlength="16" pattern="09[0-9]{9}" inputmode="numeric" required autocomplete="tel">
          <span class="signup-field-error" id="phoneError" aria-live="polite"></span>
        </div>
        <div class="form-group">
          <label for="password">Password</label>
          <div class="password-field">
            <input type="password" id="password" name="password" placeholder="Enter your password" minlength="8" required autocomplete="new-password" aria-describedby="passwordStrength">
            <button type="button" class="password-toggle" data-target="password" aria-label="Show password" aria-pressed="false">
              <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"></path>
                <circle cx="12" cy="12" r="3"></circle>
              </svg>
              <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none">
                <path d="M3 3l18 18"></path>
                <path d="M10.6 10.6a2 2 0 002.8 2.8"></path>
                <path d="M9.9 4.2A10.8 10.8 0 0112 4c6.5 0 10 8 10 8a17.7 17.7 0 01-2.1 3.2"></path>
                <path d="M6.2 6.2C3.5 8.1 2 12 2 12s3.5 8 10 8a9.8 9.8 0 004.1-.9"></path>
              </svg>
            </button>
          </div>
          <div class="password-strength" id="passwordStrength" aria-live="polite">
            <span class="password-strength-bar" aria-hidden="true"><i></i><i></i><i></i></span>
            <span class="password-strength-label" id="passwordWarning"></span>
          </div>
        </div>
        <div class="form-group">
          <label for="confirm_password">Confirm Password</label>
          <div class="password-field">
            <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter your password" minlength="8" required autocomplete="new-password" aria-describedby="confirmPasswordWarning">
            <button type="button" class="password-toggle" data-target="confirm_password" aria-label="Show confirm password" aria-pressed="false">
              <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"></path>
                <circle cx="12" cy="12" r="3"></circle>
              </svg>
              <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none">
                <path d="M3 3l18 18"></path>
                <path d="M10.6 10.6a2 2 0 002.8 2.8"></path>
                <path d="M9.9 4.2A10.8 10.8 0 0112 4c6.5 0 10 8 10 8a17.7 17.7 0 01-2.1 3.2"></path>
                <path d="M6.2 6.2C3.5 8.1 2 12 2 12s3.5 8 10 8a9.8 9.8 0 004.1-.9"></path>
              </svg>
            </button>
          </div>
          <div class="password-match" id="confirmPasswordWarning" aria-live="polite"></div>
        </div>
        <label class="checkbox-row">
          <input type="checkbox" name="terms" required>
          <span>I agree to the <a href="#" class="policy-link" data-policy-section="terms-section">Terms and Privacy Policy</a>.</span>
        </label>
        <button type="submit" class="btn-login" id="signupSubmitBtn">Continue to Email Verification</button>
      </form>
      <p class="switch-text">Already have an account? <a href="login.php">Log in</a></p>
      </div>
    </div>
  </main>
<?php
  renderOtpModal([
      'id' => 'buyer-signup-otp',
      'open' => $otpModalOpen,
      'title' => 'Verify your email',
      'subtitle' => 'Enter the 6-digit code we sent to:',
      'masked_email' => $otpEmailMasked,
      'csrf_token' => $_SESSION['signup_csrf'],
      'verify_action' => 'verify_otp',
      'resend_action' => 'resend_otp',
      'cancel_action' => 'cancel_otp',
      'action_url' => 'signup.php',
      'error' => $otpModalOpen ? $errorMessage : '',
      'success' => $otpModalOpen ? $successMessage : '',
      'resend_seconds' => $otpResendSeconds,
      'verify_label' => 'Verify & Create Account',
      'cancel_label' => 'Change email',
  ]);
?>
  <div class="policy-modal" id="policy-modal" role="dialog" aria-modal="true" aria-labelledby="policy-title">
    <div class="policy-card">
      <div class="policy-header">
        <h2 id="policy-title">Terms &amp; Privacy Policy</h2>
        <button class="policy-close" id="policy-close" type="button" aria-label="Close policy">&times;</button>
      </div>
      <div class="policy-content" id="policy-content">
        <?php include __DIR__ . '/terms_privacy.php'; ?>
      </div>
    </div>
  </div>
<?php if ($showVerifiedTransition): ?>
  <div class="auth-transition-overlay" id="authTransitionOverlay" role="status" aria-live="polite">
    <div class="auth-transition-card">
      <div class="auth-transition-check" aria-hidden="true">✓</div>
      <h2>Email Verified!</h2>
      <p>Your LokalCraft account has been created successfully.</p>
      <div class="auth-transition-loading">
        <span class="auth-transition-spinner" aria-hidden="true"></span>
        <span>Taking you to the Login page...</span>
      </div>
      <a class="auth-transition-link" href="login.php?registered=1">Continue to Login now</a>
    </div>
  </div>
<?php endif; ?>
  <script>
    const signupForm = document.querySelector('.login-form');
    const signupSubmitBtn = document.getElementById('signupSubmitBtn');
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirm_password');
    const passwordStrength = document.getElementById('passwordStrength');
    const passwordWarning = document.getElementById('passwordWarning');
    const confirmPasswordWarning = document.getElementById('confirmPasswordWarning');
    const csrfToken = signupForm.querySelector('[name="csrf_token"]').value;
    const nameInputs = ['firstname','middlename','lastname'];
    const namePattern = /^[\p{L}]+(?:[ -][\p{L}]+)*$/u;
    const middlePattern = /^[\p{L}]+(?:[ '-][\p{L}]+)*$/u;
    function displayFieldError(input, message) {
      const target = document.getElementById(input.id+'Error');
      if (target) target.textContent = message;
      input.classList.toggle('input-invalid', Boolean(message));
      input.setAttribute('aria-invalid', String(Boolean(message)));
    }
    function titleCaseName(value, surname = false) {
      let formatted = value.trim().replace(/\s+/g,' ').toLocaleLowerCase().replace(/(^|[ '-])\p{L}/gu, part => part.toLocaleUpperCase());
      if (surname) formatted = formatted.replace(/\b(Jr|Sr|Ii|Iii|Iv|V|Vi|Vii|Viii|Ix|X|Xi)$/u, part => /^(Jr|Sr)$/.test(part) ? part : part.toUpperCase());
      return formatted;
    }
    function nameIsValid(value, middle = false) {
      const chars = [...value];
      return chars.length > 0 && chars.length <= 50 && (middle ? middlePattern : namePattern).test(value);
    }
    nameInputs.forEach(id => {
      const input = document.getElementById(id);
      const middle = id === 'middlename';
      const allowed = middle ? /[^\p{L} '-]/gu : /[^\p{L} -]/gu;
      const oneChar = middle ? /^[\p{L} '-]$/u : /^[\p{L} -]$/u;
      input.addEventListener('beforeinput', event => {
        if (event.inputType === 'insertText' && event.data?.length === 1 && !oneChar.test(event.data)) event.preventDefault();
      });
      input.addEventListener('input', () => {
        input.value = [...input.value.replace(allowed,'')].slice(0,50).join('');
        displayFieldError(input, '');
      });
      input.addEventListener('blur', () => {
        input.value = input.value.trim().replace(/\s+/g,' ');
        if (!input.value && middle) { displayFieldError(input,''); return; }
        if (!nameIsValid(input.value, middle)) displayFieldError(input, id === 'firstname' ? 'Enter a valid first name.' : id === 'lastname' ? 'Enter a valid last name.' : 'Check the middle name.');
        else { input.value = titleCaseName(input.value,id==='lastname'); displayFieldError(input,''); }
      });
    });
    const phoneInput = document.getElementById('phone');
    function normalizePhone(value) {
      let digits = String(value).replace(/\D/g,'');
      if (digits.startsWith('63')) digits='0'+digits.slice(2);
      else if (digits.startsWith('9')) digits='0'+digits;
      if (!digits.startsWith('09')) return '09';
      return digits.slice(0,11);
    }
    phoneInput.value = normalizePhone(phoneInput.value);
    phoneInput.addEventListener('paste', event => {
      const pasted = event.clipboardData?.getData('text');
      if (pasted === undefined) return;
      event.preventDefault();
      phoneInput.value = normalizePhone(pasted);
      phoneInput.dispatchEvent(new Event('input',{bubbles:true}));
    });
    phoneInput.addEventListener('beforeinput', event => {
      if (event.inputType === 'insertText' && event.data?.length === 1 && /[^0-9]/.test(event.data)) event.preventDefault();
    });
    phoneInput.addEventListener('input', () => {
      phoneInput.value = normalizePhone(phoneInput.value);
      if (/^09\d{9}$/.test(phoneInput.value)) displayFieldError(phoneInput,'');
    });
    phoneInput.addEventListener('focus', () => { if (!phoneInput.value.startsWith('09')) phoneInput.value='09'; });
    phoneInput.addEventListener('blur', () => {
      displayFieldError(phoneInput,/^09\d{9}$/.test(phoneInput.value) ? '' : 'Enter a complete 11-digit mobile number.');
    });
    const emailInput = document.getElementById('email');
    const emailCheckMessage = document.getElementById('emailCheckMessage');
    let checkedEmail = '';
    let emailIsAvailable = false;
    let emailRequest = null;
    let emailCheckTimer = null;
    function emailHasValidFormat(value) {
      const email = value.trim().toLowerCase();
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || email.length > 100) return false;
      const domain = email.split('@').pop();
      return !['g.mail.com','gmail.con','yahoo.con'].includes(domain);
    }
    function setEmailMessage(text, state='') {
      emailCheckMessage.textContent = text;
      emailCheckMessage.className = 'email-check-message' + (state ? ' '+state : '');
      emailInput.classList.toggle('input-invalid', state==='error');
      emailInput.classList.toggle('input-valid', state==='success');
      emailInput.setAttribute('aria-invalid', String(state==='error'));
    }
    async function checkEmailAvailability() {
      const value = emailInput.value.trim().toLowerCase();
      emailInput.value = value;
      clearTimeout(emailCheckTimer);
      emailRequest?.abort();
      if (!value) { checkedEmail=''; emailIsAvailable=false; setEmailMessage(''); return false; }
      if (!emailHasValidFormat(value)) { checkedEmail=''; emailIsAvailable=false; setEmailMessage('Enter a valid email address.','error'); return false; }
      if (value===checkedEmail && emailIsAvailable) return true;
      const controller = new AbortController();
      emailRequest=controller;
      setEmailMessage('Checking email...','info');
      const body = new URLSearchParams({action:'check_email',csrf_token:csrfToken,email:value});
      try {
        const response = await fetch('signup.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},body:body.toString(),signal:controller.signal,cache:'no-store'});
        const result=await response.json();
        if (controller.signal.aborted || emailInput.value.trim().toLowerCase()!==value) return false;
        checkedEmail=value;
        emailIsAvailable=Boolean(response.ok && result.success && result.available);
        setEmailMessage(result.message || (emailIsAvailable ? 'Email is available.' : 'Check your email address.'),emailIsAvailable?'success':'error');
        return emailIsAvailable;
      } catch(error) {
        if (error.name==='AbortError') return false;
        if (emailInput.value.trim().toLowerCase()!==value) return false;
        checkedEmail=''; emailIsAvailable=false;
        setEmailMessage('Could not check email right now. Try again.','info');
        return false;
      } finally { if (emailRequest===controller) emailRequest=null; }
    }
    emailInput.addEventListener('input', () => {
      checkedEmail=''; emailIsAvailable=false;
      emailRequest?.abort();
      clearTimeout(emailCheckTimer);
      setEmailMessage('');
      if (emailInput.value.trim()) emailCheckTimer=setTimeout(checkEmailAvailability,650);
    });
    emailInput.addEventListener('blur', () => { void checkEmailAvailability(); });
    function passwordRulesMet(value) {
      return value.length>=8 && /[A-Z]/.test(value) && /[a-z]/.test(value) && /[0-9]/.test(value);
    }
    function checkPassword() {
      const value=passwordInput.value;
      const complete=passwordRulesMet(value);
      const level=!value?'':complete && value.length>=12?'strong':complete?'normal':'weak';
      passwordStrength.className='password-strength'+(level?' '+level:'');
      passwordWarning.textContent=level?level.charAt(0).toUpperCase()+level.slice(1):'';
      passwordInput.setAttribute('aria-invalid',String(Boolean(value) && !complete));
      checkConfirmPassword();
      return complete;
    }
    function checkConfirmPassword() {
      const value=confirmPasswordInput.value;
      const match=Boolean(value && value===passwordInput.value);
      confirmPasswordWarning.textContent=!value?'':match?'✓ Passwords match.':'Passwords do not match.';
      confirmPasswordWarning.className='password-match'+(value?(match?' valid':' invalid'):'');
      confirmPasswordInput.setAttribute('aria-invalid',String(Boolean(value) && !match));
      return match;
    }
    document.querySelectorAll('.password-toggle').forEach(button => {
      button.addEventListener('click', () => {
        const input=document.getElementById(button.dataset.target);
        const showing=input.type==='text';
        input.type=showing?'password':'text';
        button.setAttribute('aria-pressed',String(!showing));
        button.setAttribute('aria-label',showing?'Show password':'Hide password');
        const eyeOpen=button.querySelector('.eye-open');
        const eyeClosed=button.querySelector('.eye-closed');
        if (eyeOpen) eyeOpen.style.display=showing?'':'none';
        if (eyeClosed) eyeClosed.style.display=showing?'none':'';
        input.focus({preventScroll:true});
      });
    });
    passwordInput.addEventListener('input',checkPassword);
    confirmPasswordInput.addEventListener('input',checkConfirmPassword);
    signupForm.addEventListener('submit',event => {
      let invalid=null;
      for(const id of nameInputs) {
        const field=document.getElementById(id);
        const value=field.value.trim().replace(/\s+/g,' ');
        if (id==='middlename' && !value) continue;
        if (!nameIsValid(value,id==='middlename')) {
          displayFieldError(field,'Check this name.');
          invalid??=field;
        } else field.value=titleCaseName(value,id==='lastname');
      }
      if (!emailHasValidFormat(emailInput.value)) { setEmailMessage('Enter a valid email address.','error'); invalid??=emailInput; }
      else if (checkedEmail===emailInput.value.trim().toLowerCase() && !emailIsAvailable) { setEmailMessage('This email cannot be used.','error'); invalid??=emailInput; }
      if (!/^09\d{9}$/.test(phoneInput.value)) { displayFieldError(phoneInput,'Enter a complete 11-digit mobile number.'); invalid??=phoneInput; }
      if (!checkPassword()) invalid??=passwordInput;
      else if (!checkConfirmPassword()) invalid??=confirmPasswordInput;
      if (invalid) {
        event.preventDefault();
        invalid.focus();
        if (invalid===passwordInput) passwordInput.setCustomValidity('Use at least 8 characters with uppercase, lowercase and a number.');
        else passwordInput.setCustomValidity('');
        return;
      }
      passwordInput.setCustomValidity('');
      signupSubmitBtn.disabled=true;
      signupSubmitBtn.textContent='Sending verification code...';
    });
    passwordInput.addEventListener('input',()=>passwordInput.setCustomValidity(''));
    const policyModal = document.getElementById('policy-modal');
    const policyContent = document.getElementById('policy-content');
    const policyTitle = document.getElementById('policy-title');
    const policyLinks = document.querySelectorAll('.policy-link');
    policyLinks.forEach((link) => {
      link.addEventListener('click', (event) => {
        event.preventDefault();
        const section = document.getElementById(link.dataset.policySection);
        policyTitle.textContent = link.dataset.policySection === 'terms-section' ? 'Terms and Conditions' : 'Privacy Policy';
        policyModal.classList.add('open');
        document.body.style.overflow = 'hidden';
        section?.scrollIntoView({ block: 'start' });
      });
    });
    function closePolicyModal() {
      policyModal.classList.remove('open');
      document.body.style.overflow = '';
    }
    document.getElementById('policy-close').addEventListener('click', closePolicyModal);
    policyModal.addEventListener('click', (event) => {
      if (event.target === policyModal) closePolicyModal();
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && policyModal.classList.contains('open')) closePolicyModal();
    });
    const authTransitionOverlay = document.getElementById('authTransitionOverlay');
    if (authTransitionOverlay) {
      window.setTimeout(() => {
        window.location.replace('login.php?registered=1');
      }, 2600);
    }
  </script>
</body>
</html>
