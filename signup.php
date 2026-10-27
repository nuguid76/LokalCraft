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
if (empty($_SESSION['signup_csrf'])) {
    $_SESSION['signup_csrf'] = bin2hex(random_bytes(32));
}
$errorMessage = '';
$successMessage = '';
$otpFlowKey = 'buyer_signup';
// AJAX keeps the filled Signup form in the browser during the OTP flow.
$signupAjax = $_SERVER['REQUEST_METHOD'] === 'POST'
    && strtolower(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''))) === 'xmlhttprequest';
function signupJsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload);
    exit;
}
// A fresh visit to Signup never resumes an abandoned OTP dialog.
// Closing a popup with browser Back stays in the same document (no GET),
// so it does not clear the working OTP while the user is still on Signup.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['verified'])) {
    otpClearChallenge($otpFlowKey);
}
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
        if ($signupAjax) {
            signupJsonResponse(['success' => false, 'message' => 'Your session expired. Refresh and try again.'], 403);
        }
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
        if ($signupAjax) signupJsonResponse(['success' => true, 'message' => 'You can change your email address.']);
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
                        if ($signupAjax) {
                            signupJsonResponse([
                                'success' => true,
                                'message' => 'Email verified successfully. Your account is ready.',
                                'redirect' => 'login.php?registered=1',
                            ]);
                        }
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
    if ($signupAjax) {
        $challenge = otpGetChallenge($otpFlowKey);
        signupJsonResponse([
            'success' => $errorMessage === '',
            'message' => $errorMessage !== '' ? $errorMessage : $successMessage,
            'otp_available' => is_array($challenge),
            'masked_email' => is_array($challenge) ? otpMaskEmail((string)($challenge['email'] ?? '')) : '',
            'resend_seconds' => is_array($challenge) ? otpResendSeconds($otpFlowKey) : 0,
        ], $errorMessage !== '' ? 422 : 200);
    }
}
$pendingSignup = otpGetChallenge($otpFlowKey);
// Show automatically only as a direct response to an OTP form action;
// visiting Signup normally must never resurrect a session's old OTP modal.
$otpModalOpen = is_array($pendingSignup)
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($action ?? '', ['request_otp', 'resend_otp', 'verify_otp'], true);
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
      background: #fff8eb;
    }
    /* The image has its own fixed viewport-sized layer. It never grows with
       the signup form, and 100lvh stays stable when mobile browser bars move. */
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
    .signup-card {
      position: relative;
      z-index: 1;
      width: min(560px, 100%);
      max-width: 100%;
      min-width: 0;
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
      min-width: 0;
      width: 100%;
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
    .account-paths {
      margin-top: 22px;
    }
    .switch-text {
      margin: 0;
      text-align: center;
      color: #5A4635;
      font-size: 0.92rem;
      line-height: 1.5;
    }
    .switch-text a {
      color: #A55325;
      text-decoration: none;
      font-weight: 800;
    }
    .switch-text a:hover {
      color: #C2692E;
      text-decoration: underline;
      text-underline-offset: 3px;
    }
    .switch-text a:focus-visible,
    .seller-path-link:focus-visible {
      outline: 3px solid rgba(201, 109, 32, .22);
      outline-offset: 3px;
      border-radius: 8px;
    }
    .account-path-divider {
      display: flex;
      align-items: center;
      gap: 12px;
      margin: 15px 0;
      color: #9A7F69;
      font-size: .76rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .08em;
    }
    .account-path-divider::before,
    .account-path-divider::after {
      content: "";
      flex: 1;
      height: 1px;
      background: #E7CDB0;
    }
    .seller-path-card {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      padding: 15px 16px;
      border: 1px solid #E7CDB0;
      border-radius: 16px;
      background: #FFF8ED;
    }
    .seller-path-copy {
      min-width: 0;
      display: grid;
      gap: 3px;
      color: #5A4635;
      line-height: 1.4;
    }
    .seller-path-copy strong {
      color: #3F2E1F;
      font-size: .9rem;
      font-weight: 800;
    }
    .seller-path-copy span {
      color: #806957;
      font-size: .76rem;
    }
    .seller-path-link {
      flex: 0 0 auto;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 39px;
      padding: 9px 13px;
      border: 1px solid #A55325;
      border-radius: 11px;
      background: #A55325;
      color: #FFF8ED;
      text-decoration: none;
      font-size: .8rem;
      font-weight: 800;
      white-space: nowrap;
      transition: background-color .18s ease, border-color .18s ease, transform .18s ease;
    }
    .seller-path-link:hover {
      border-color: #C2692E;
      background: #C2692E;
      transform: translateY(-1px);
    }
    .signup-page {
      width: min(1600px, 100%);
      max-width: 100%;
      margin-inline: auto;
      min-width: 0;
      min-height: calc(100svh - 82px);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 38px 24px 40px;
      position: relative;
      z-index: 2;
    }
    /* public_nav.php controls its own header sizing and menu breakpoints. */
    @media (max-width: 840px) {
      .signup-page { padding: 32px 20px 34px; }
    }
    @media (max-width: 560px) {
      .signup-page {
        min-height: calc(100svh - 70px);
        padding: 24px 14px 28px;
        align-items: flex-start;
      }
      .signup-card { padding: 30px 20px 26px; border-radius: 23px; }
      .login-header { margin-bottom: 22px; }
      .login-title { font-size: clamp(1.55rem, 6.2vw, 1.95rem); }
      .login-subtitle { font-size: .88rem; }
      .login-form { gap: 16px; }
      .name-grid { grid-template-columns: minmax(0, 1fr); gap: 16px; }
      .form-group, .password-field { min-width: 0; }
      .form-group input { min-height: 47px; padding: 12px 14px; font-size: 16px; }
      .btn-login { min-height: 48px; padding: 13px 18px; }
      .seller-path-card {
        align-items: stretch;
        flex-direction: column;
        gap: 12px;
      }
      .seller-path-link { width: 100%; }
      .policy-modal { padding: 12px; }
      .policy-card { max-height: calc(100dvh - 24px); }
      .policy-header { padding: 17px; }
      .policy-content {
        max-height: calc(100dvh - 92px);
        padding: 17px;
        -webkit-overflow-scrolling: touch;
      }
      .auth-transition-card { padding: 30px 20px 26px; }
    }
    @media (max-width: 360px) {
      .signup-page { padding: 20px 10px 22px; }
      .signup-card { padding: 26px 16px 22px; border-radius: 20px; }
      .login-title { font-size: 1.55rem; }
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
    /* Same soft landing entrance used on Become a Seller. */
    @keyframes signupPageEnter {
      from { opacity: 0; transform: translateY(22px); }
      to { opacity: 1; transform: translateY(0); }
    }
    @keyframes signupSuccessEnter {
      from { opacity: 0; transform: translateY(12px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .signup-page .signup-card {
      animation: signupPageEnter .72s cubic-bezier(.16,1,.3,1) both;
    }
    .auth-transition-card {
      animation: signupSuccessEnter .38s ease both;
    }
    @media (prefers-reduced-motion: reduce) {
      .signup-page .signup-card,
      .auth-transition-card { animation: none !important; }
    }
    @keyframes authSpin {
      to { transform: rotate(360deg); }
    }

    /* Popup positioning and browser Back behavior share the same visible viewport. */
    .policy-modal {
      top: var(--signup-popup-top, 0px);
      bottom: auto;
      height: var(--signup-popup-height, 100dvh);
      min-height: 0;
      overflow-y: auto;
      overscroll-behavior: contain;
      -webkit-overflow-scrolling: touch;
      padding: 12px;
    }
    .policy-card {
      display: flex;
      flex-direction: column;
      flex: 0 1 auto;
      margin: auto;
      min-height: 0;
      max-height: calc(var(--signup-popup-height, 100dvh) - 24px);
    }
    .policy-header { flex: 0 0 auto; }
    .policy-content {
      flex: 1 1 auto;
      min-height: 0;
      max-height: none;
      overflow-y: auto;
      overscroll-behavior: contain;
    }
    /* Signup owns its OTP popup; no shared OTP interface required. */
    .signup-otp-modal.otp-modal {
      position: fixed;
      left: 0;
      right: 0;
      top: var(--signup-popup-top, 0px);
      bottom: auto;
      width: 100%;
      height: var(--signup-popup-height, 100dvh);
      max-height: var(--signup-popup-height, 100dvh);
      z-index: 30000;
      display: none;
      align-items: center;
      justify-content: center;
      overflow-y: auto;
      overscroll-behavior: contain;
      -webkit-overflow-scrolling: touch;
      padding: 16px;
      background: rgba(43, 30, 18, .55);
      -webkit-backdrop-filter: blur(5px);
      backdrop-filter: blur(5px);
    }
    .signup-otp-modal.open { display: flex; }
    .signup-otp-card.otp-card {
      position: relative;
      flex: 0 1 auto;
      width: min(470px, 100%);
      min-width: 0;
      min-height: 0;
      max-height: calc(var(--signup-popup-height, 100dvh) - 32px);
      margin: auto;
      overflow-y: auto;
      overscroll-behavior: contain;
      border: 1px solid #E7CDB0;
      border-radius: 24px;
      background: #FFFCF7;
      box-shadow: 0 28px 70px rgba(45, 30, 16, .28);
      padding: 30px 28px 26px;
    }
    .signup-otp-close {
      position: absolute;
      right: 12px;
      top: 12px;
      display: grid;
      place-items: center;
      width: 36px;
      height: 36px;
      border: 0;
      border-radius: 50%;
      background: #F9EFE2;
      color: #8b5423;
      font: inherit;
      font-size: 1.4rem;
      cursor: pointer;
    }
    .signup-otp-close:hover, .signup-otp-close:focus-visible {
      background: #F2DFC9;
      outline: 2px solid #D99A67;
      outline-offset: 2px;
    }
    .signup-otp-icon {
      display: grid;
      place-items: center;
      width: 58px;
      height: 58px;
      margin: 0 auto 14px;
      border-radius: 18px;
      background: #F9EFE2;
      color: #A55325;
      font-size: 1.45rem;
      font-weight: 900;
    }
    .signup-otp-card h2 { margin: 0; text-align: center; color: #8b5423; font-size: 1.5rem; }
    .signup-otp-subtitle { margin: 8px 0 0; text-align: center; color: #6f5a47; font-size: .88rem; line-height: 1.5; }
    .signup-otp-email { margin-top: 7px; text-align: center; color: #3f2e1f; font-size: .9rem; font-weight: 800; overflow-wrap: anywhere; }
    .signup-otp-message { margin-top: 14px; padding: 10px 12px; text-align: center; border-radius: 11px; font-size: .8rem; line-height: 1.45; }
    .signup-otp-message.error { border: 1px solid #efc0c0; background: #fff2f2; color: #a83d3d; }
    .signup-otp-message.success { border: 1px solid #bfe2c9; background: #f2fbf5; color: #2f7a4d; }
    .signup-otp-inputs { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 8px; margin-top: 20px; }
    .signup-otp-digit {
      width: 100%;
      min-width: 0;
      height: 52px;
      padding: 0 !important;
      border: 1.5px solid #E7CDB0;
      border-radius: 12px;
      background: #F9EFE2;
      color: #2B1E12;
      text-align: center;
      font: 800 1.2rem/1 Inter, system-ui, sans-serif;
      outline: none;
    }
    .signup-otp-digit:focus { border-color: #B85C28; background: #FFFAF4; box-shadow: 0 0 0 3px rgba(184, 92, 40, .1); }
    .signup-otp-primary {
      width: 100%;
      min-height: 46px;
      margin-top: 18px;
      padding: 13px 18px;
      border: 0;
      border-radius: 60px;
      background: #B85C28;
      color: #fff;
      font: inherit;
      font-size: .94rem;
      font-weight: 800;
      cursor: pointer;
      box-shadow: 0 6px 14px rgba(160, 80, 35, .24);
    }
    .signup-otp-primary:hover { background: #94481F; }
    .signup-otp-primary:disabled, .signup-otp-text:disabled { opacity: .55; cursor: not-allowed; }
    .signup-otp-delivery-help {
      max-width: 360px;
      margin: 13px auto 0;
      color: #806957;
      text-align: center;
      font-size: .78rem;
      line-height: 1.5;
      overflow-wrap: anywhere;
    }
    .signup-otp-actions { display: flex; align-items: center; justify-content: center; gap: 9px; flex-wrap: wrap; margin-top: 13px; color: #c2aa96; }
    .signup-otp-actions form { margin: 0; }
    .signup-otp-text { padding: 5px; border: 0; background: transparent; color: #B85C28; font: inherit; font-size: .8rem; font-weight: 800; cursor: pointer; }
    .signup-otp-text:focus-visible { outline: 2px solid #D99A67; outline-offset: 2px; border-radius: 5px; }
    .signup-otp-expiry { margin: 10px 0 0; color: #927b68; text-align: center; font-size: .74rem; line-height: 1.5; }
    @media (max-width: 560px) {
      .policy-modal, .signup-otp-modal.otp-modal { align-items: safe center; }
      .signup-otp-modal.otp-modal { padding: max(12px, env(safe-area-inset-top)) 10px max(12px, env(safe-area-inset-bottom)); }
      .signup-otp-card.otp-card { max-height: calc(var(--signup-popup-height, 100dvh) - 24px); padding: 26px 16px 22px; border-radius: 20px; }
      .signup-otp-inputs { gap: 5px; }
      .signup-otp-digit { height: 47px; border-radius: 10px; font-size: 1.05rem; }
    }
    @media (prefers-reduced-motion: reduce) {
      .signup-otp-modal *, .signup-otp-modal *::before, .signup-otp-modal *::after { scroll-behavior: auto !important; transition: none !important; }
    }
  </style>
</head>
<body>
<?php $publicActive = 'signup'; include __DIR__ . '/public_nav.php'; ?>
  <main class="signup-page">
    <div class="signup-card">
      <div class="login-card">
      <div class="login-header">
        <h1 class="login-title">Create Your Buyer Account</h1>
        <p class="login-subtitle">Join LokalCraft as a buyer and support Filipino artisans by discovering authentic handcrafted products.</p>
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
          <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="09XXXXXXXXX" maxlength="16" pattern="09[0-9]{9}" inputmode="tel" required autocomplete="tel">
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
      <div class="account-paths" aria-label="Other account options">
        <p class="switch-text account-login-text">
          Already have an account?
          <a href="login.php">Log in</a>
        </p>
        <div class="account-path-divider" aria-hidden="true"><span>or</span></div>
        <div class="seller-path-card">
          <div class="seller-path-copy">
            <strong>Are you an artisan or craft seller?</strong>
            <span>Apply to showcase and sell your handcrafted products on LokalCraft.</span>
          </div>
          <a class="seller-path-link" href="become_a_seller.php">Become a Seller</a>
        </div>
      </div>
      </div>
    </div>
  </main>
  <!-- Signup OTP popup is self-contained; sending and validation remain in otp_service.php. -->
  <div class="signup-otp-modal otp-modal<?= $otpModalOpen ? ' open' : '' ?>" id="buyer-signup-otp" aria-hidden="<?= $otpModalOpen ? 'false' : 'true' ?>">
    <div class="signup-otp-card otp-card" role="dialog" aria-modal="true" aria-labelledby="buyer-signup-otp-title" tabindex="-1">
      <button class="signup-otp-close" type="button" data-signup-otp-close aria-label="Close email verification">&times;</button>
      <div class="signup-otp-icon" aria-hidden="true">@</div>
      <h2 id="buyer-signup-otp-title">Verify your email</h2>
      <p class="signup-otp-subtitle">Enter the 6-digit code sent to:</p>
      <div class="signup-otp-email"><?= htmlspecialchars($otpEmailMasked, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php if ($otpModalOpen && $errorMessage !== ''): ?>
        <div class="signup-otp-message error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php elseif ($otpModalOpen && $successMessage !== ''): ?>
        <div class="signup-otp-message success" role="status"><?= htmlspecialchars($successMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php endif; ?>
      <form action="signup.php" method="POST" id="buyer-signup-otp-verify-form" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['signup_csrf'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <input type="hidden" name="action" value="verify_otp">
        <input type="hidden" name="otp" id="buyer-signup-otp-value" value="">
        <div class="signup-otp-inputs" id="buyer-signup-otp-inputs" aria-label="6-digit verification code">
          <?php for ($i = 1; $i <= 6; $i++): ?>
            <input class="signup-otp-digit" type="text" inputmode="numeric" maxlength="<?= $i === 1 ? 6 : 1 ?>" <?= $i === 1 ? 'autocomplete="one-time-code"' : '' ?> aria-label="Digit <?= $i ?>" required>
          <?php endfor; ?>
        </div>
        <button class="signup-otp-primary" id="buyer-signup-otp-verify-button" type="submit">Verify &amp; Create Account</button>
      </form>
      <p class="signup-otp-delivery-help" role="note">Didn't receive a code? Check your email address and Spam folder. If the address is incorrect, choose Change email.</p>
      <div class="signup-otp-actions">
        <form action="signup.php" method="POST" id="buyer-signup-otp-resend-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['signup_csrf'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <input type="hidden" name="action" value="resend_otp">
          <button class="signup-otp-text" id="buyer-signup-otp-resend-button" type="submit">Resend code</button>
        </form>
        <span aria-hidden="true">•</span>
        <form action="signup.php" method="POST">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['signup_csrf'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <input type="hidden" name="action" value="cancel_otp">
          <button class="signup-otp-text" type="submit">Change email</button>
        </form>
      </div>
      <p class="signup-otp-expiry">The verification code expires in 10 minutes.</p>
    </div>
  </div>
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
      let digits = String(value || '').replace(/\D/g, '');
      if (digits.startsWith('63') && digits.length >= 3) digits = '0' + digits.slice(2);
      else if (digits.startsWith('9')) digits = '0' + digits;
      return digits.slice(0, 11);
    }
    phoneInput.value = normalizePhone(phoneInput.value);
    phoneInput.addEventListener('input', () => {
      const normalized = normalizePhone(phoneInput.value);
      if (phoneInput.value !== normalized) phoneInput.value = normalized;
      if (/^09\d{9}$/.test(normalized)) displayFieldError(phoneInput,'');
    });
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
    const signupOtpModal = document.getElementById('buyer-signup-otp') || document.querySelector('.otp-modal');
    // The OTP input, resend timer and verification form are owned by Signup.
    // Backend OTP generation, delivery, cooldown and attempts remain in otp_service.php.
    const signupOtpDigits = Array.from(signupOtpModal?.querySelectorAll('.signup-otp-digit') || []);
    const signupOtpForm = document.getElementById('buyer-signup-otp-verify-form');
    const signupOtpValue = document.getElementById('buyer-signup-otp-value');
    const signupOtpVerify = document.getElementById('buyer-signup-otp-verify-button');
    const signupOtpResend = document.getElementById('buyer-signup-otp-resend-button');
    const signupOtpResendForm = document.getElementById('buyer-signup-otp-resend-form');
    let signupOtpSeconds = <?= (int)$otpResendSeconds ?>;
    const syncSignupOtp = () => {
      const code = signupOtpDigits.map(input => input.value).join('');
      if (signupOtpValue) signupOtpValue.value = code;
      return code;
    };
    signupOtpDigits.forEach((input, index) => {
      input.addEventListener('input', () => {
        const entered = input.value.replace(/\D/g, '').slice(0, 6);
        if (entered.length > 1) { // Mobile one-time-code autofill may insert all six digits at once.
          signupOtpDigits.forEach((field, offset) => { field.value = entered[offset] || ''; });
          syncSignupOtp();
          signupOtpDigits[Math.min(entered.length, 6) - 1]?.focus();
          return;
        }
        input.value = entered;
        syncSignupOtp();
        if (input.value && signupOtpDigits[index + 1]) signupOtpDigits[index + 1].focus();
      });
      input.addEventListener('keydown', event => {
        if (event.key === 'Backspace' && !input.value && signupOtpDigits[index - 1]) {
          event.preventDefault();
          signupOtpDigits[index - 1].value = '';
          signupOtpDigits[index - 1].focus();
          syncSignupOtp();
        }
        if (event.key === 'ArrowLeft' && signupOtpDigits[index - 1]) {
          event.preventDefault();
          signupOtpDigits[index - 1].focus();
        }
        if (event.key === 'ArrowRight' && signupOtpDigits[index + 1]) {
          event.preventDefault();
          signupOtpDigits[index + 1].focus();
        }
      });
      input.addEventListener('paste', event => {
        const pasted = (event.clipboardData?.getData('text') || '').replace(/\D/g, '').slice(0, 6);
        if (!pasted) return;
        event.preventDefault();
        signupOtpDigits.forEach((field, offset) => { field.value = pasted[offset] || ''; });
        syncSignupOtp();
        signupOtpDigits[Math.min(pasted.length, 6) - 1]?.focus();
      });
    });
    signupOtpForm?.addEventListener('submit', event => {
      if (!/^\d{6}$/.test(syncSignupOtp())) {
        event.preventDefault();
        signupOtpDigits.find(input => !input.value)?.focus();
        return;
      }
      if (signupOtpVerify) {
        signupOtpVerify.disabled = true;
        signupOtpVerify.textContent = 'Verifying...';
      }
    });
    let signupResendTimer = null;
    const tickSignupOtpResend = () => {
      window.clearTimeout(signupResendTimer);
      if (!signupOtpResend) return;
      if (signupOtpSeconds > 0) {
        signupOtpResend.disabled = true;
        signupOtpResend.textContent = `Resend code (${signupOtpSeconds}s)`;
        signupOtpSeconds -= 1;
        signupResendTimer = window.setTimeout(tickSignupOtpResend, 1000);
      } else {
        signupOtpResend.disabled = false;
        signupOtpResend.textContent = 'Resend code';
      }
    };
    tickSignupOtpResend();

    // Mobile system Back, browser Back and desktop Alt+Left dismiss the topmost
    // open popup first, without navigating away from the Signup page.
    const signupPopupHistoryKey = '__lokalcraftSignupPopupStack';
    const signupPopupInstanceKey = '__lokalcraftSignupPopupInstance';
    let signupPopupInstance = String(Date.now()) + '-' + Math.random().toString(36).slice(2);
    let signupPopupStack = [];
    let signupPreviousFocus = null;
    const signupPopups = { otp: signupOtpModal, policy: policyModal };
    const signupPopupVisible = node => Boolean(node && node.classList.contains('open'));
    const signupPopupKnown = name => Object.prototype.hasOwnProperty.call(signupPopups, name) && Boolean(signupPopups[name]);
    const signupPopupStackFromState = state => {
      if (!state || typeof state !== 'object' || state[signupPopupInstanceKey] !== signupPopupInstance) return [];
      const value = state[signupPopupHistoryKey];
      return Array.isArray(value) ? value.filter(signupPopupKnown) : [];
    };
    const updateSignupPopupViewport = () => {
      const viewport = window.visualViewport;
      const height = Math.max(1, Math.round(viewport?.height || document.documentElement.clientHeight));
      const top = Math.max(0, Math.round(viewport?.offsetTop || 0));
      document.documentElement.style.setProperty('--signup-popup-height', `${height}px`);
      document.documentElement.style.setProperty('--signup-popup-top', `${top}px`);
    };
    const syncSignupPopupStack = stack => {
      const shouldFocusSignupOtp = !signupPopupVisible(signupOtpModal) && stack.includes('otp');
      signupPopupStack = stack.filter(signupPopupKnown);
      Object.entries(signupPopups).forEach(([name, popup]) => {
        if (!popup) return;
        const shouldOpen = signupPopupStack.includes(name);
        popup.classList.toggle('open', shouldOpen);
        popup.setAttribute('aria-hidden', String(!shouldOpen));
      });
      document.body.style.overflow = signupPopupStack.length ? 'hidden' : '';
      if (!signupPopupStack.length && signupPreviousFocus?.isConnected) {
        signupPreviousFocus.focus({ preventScroll: true });
        signupPreviousFocus = null;
      }
      updateSignupPopupViewport();
      if (signupPopupStack.at(-1) === 'otp' && shouldFocusSignupOtp) {
        window.setTimeout(() => {
          if (signupPopupVisible(signupOtpModal)) signupOtpDigits[0]?.focus({ preventScroll: true });
        }, 80);
      }
    };
    const pushSignupPopup = name => {
      if (!signupPopupKnown(name) || signupPopupStack.at(-1) === name) return;
      const nextStack = signupPopupStack.filter(item => item !== name).concat(name);
      try {
        const state = window.history.state;
        const stateObject = state && typeof state === 'object' ? { ...state } : {};
        window.history.pushState({ ...stateObject, [signupPopupInstanceKey]: signupPopupInstance, [signupPopupHistoryKey]: nextStack }, '', window.location.href);
      } catch (_) { /* On-screen close remains available without History API. */ }
      if (!signupPopupStack.length) signupPreviousFocus = document.activeElement;
      syncSignupPopupStack(nextStack);
    };
    const closeSignupPopup = name => {
      if (!signupPopupStack.includes(name)) return;
      const stateStack = signupPopupStackFromState(window.history.state);
      if (stateStack.at(-1) === name) {
        window.history.back(); // popstate removes exactly one popup layer.
      } else {
        syncSignupPopupStack(signupPopupStack.filter(item => item !== name));
      }
    };
    window.addEventListener('popstate', event => {
      syncSignupPopupStack(signupPopupStackFromState(event.state));
    });
    updateSignupPopupViewport();
    window.addEventListener('resize', updateSignupPopupViewport, { passive: true });
    window.visualViewport?.addEventListener('resize', updateSignupPopupViewport, { passive: true });
    window.visualViewport?.addEventListener('scroll', updateSignupPopupViewport, { passive: true });
    document.addEventListener('focusin', event => {
      const field = event.target;
      if (!(field instanceof HTMLElement) || !field.closest('.otp-modal.open, .policy-modal.open')) return;
      updateSignupPopupViewport();
      window.setTimeout(() => {
        updateSignupPopupViewport();
        if (field.isConnected) field.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'auto' });
      }, 180);
    });

    policyLinks.forEach(link => {
      link.addEventListener('click', event => {
        event.preventDefault();
        const section = document.getElementById(link.dataset.policySection);
        policyTitle.textContent = link.dataset.policySection === 'terms-section'
          ? 'Terms and Conditions' : 'Privacy Policy';
        pushSignupPopup('policy');
        // Scroll inside the policy card only, not the underlying Signup page.
        if (section && policyContent) {
          const sectionY = section.getBoundingClientRect().top - policyContent.getBoundingClientRect().top;
          policyContent.scrollTop += sectionY;
        } else if (policyContent) {
          policyContent.scrollTop = 0;
        }
      });
    });
    signupOtpModal?.querySelector('[data-signup-otp-close]')?.addEventListener('click', () => closeSignupPopup('otp'));
    signupOtpModal?.addEventListener('click', event => {
      if (event.target === signupOtpModal) closeSignupPopup('otp');
    });
    document.getElementById('policy-close').addEventListener('click', () => closeSignupPopup('policy'));
    policyModal.addEventListener('click', event => {
      if (event.target === policyModal) closeSignupPopup('policy');
    });
    document.addEventListener('keydown', event => {
      if (event.key !== 'Escape' || !signupPopupStack.length) return;
      event.preventDefault();
      closeSignupPopup(signupPopupStack.at(-1));
    });

    // The OTP modal opens from PHP after an OTP request or resend. Give this
    // already-visible overlay a same-document history entry on load so pressing
    // Back on a phone closes only it. Never clear the server-side OTP challenge.
    if (signupPopupVisible(signupOtpModal)) {
      signupOtpModal.setAttribute('aria-hidden', 'false');
      const stateStack = signupPopupStackFromState(window.history.state);
      if (stateStack.includes('otp')) {
        syncSignupPopupStack(stateStack);
      } else {
        pushSignupPopup('otp');
      }
    } else {
      syncSignupPopupStack(signupPopupStackFromState(window.history.state));
    }
    // The server may render an already-open OTP dialog after a POST.
    // Its first input still receives focus even before any history transition.
    if (signupPopupVisible(signupOtpModal)) {
      window.setTimeout(() => {
        if (signupPopupVisible(signupOtpModal)) signupOtpDigits[0]?.focus({ preventScroll: true });
      }, 100);
    }
    // Keep every entered Signup field, including passwords, in memory while the
    // user is on this page. Never store passwords in localStorage/sessionStorage.
    // All OTP actions use the same PHP backend without reloading Signup.
    const signupOtpMessage = signupOtpModal?.querySelector('.signup-otp-message') || document.createElement('div');
    if (signupOtpModal && !signupOtpMessage.isConnected) {
      signupOtpMessage.className = 'signup-otp-message';
      signupOtpMessage.hidden = true;
      signupOtpModal.querySelector('.signup-otp-email')?.after(signupOtpMessage);
    }
    const signupOtpEmailLabel = signupOtpModal?.querySelector('.signup-otp-email');
    const signupOtpCancelForm = signupOtpModal?.querySelector('form [name="action"][value="cancel_otp"]')?.form;
    const showSignupOtpMessage = (message, kind = 'error') => {
      if (!signupOtpMessage) return;
      signupOtpMessage.hidden = !message;
      signupOtpMessage.className = 'signup-otp-message ' + kind;
      signupOtpMessage.textContent = message || '';
    };
    const signupJsonPost = async form => {
      const response = await fetch('signup.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(form),
      });
      const result = await response.json();
      return { ...result, ok: response.ok && Boolean(result.success) };
    };
    const beginOtpOnPage = result => {
      if (signupOtpEmailLabel) signupOtpEmailLabel.textContent = result.masked_email || '';
      signupOtpDigits.forEach(input => { input.value = ''; });
      syncSignupOtp();
      signupOtpSeconds = Math.max(0, Number(result.resend_seconds) || 0);
      tickSignupOtpResend();
      showSignupOtpMessage(result.message || '', 'success');
      pushSignupPopup('otp');
    };
    signupForm.addEventListener('submit', async event => {
      if (event.defaultPrevented) return; // Existing client-side validation rejected it.
      event.preventDefault();
      try {
        const result = await signupJsonPost(signupForm);
        if (result.ok && result.otp_available) {
          beginOtpOnPage(result);
        } else {
          setEmailMessage(result.message || 'Unable to send a code right now.', 'error');
        }
      } catch (_) {
        setEmailMessage('Unable to connect. Please try again.', 'error');
      } finally {
        signupSubmitBtn.disabled = false;
        signupSubmitBtn.textContent = 'Continue to Email Verification';
      }
    });
    signupOtpForm?.addEventListener('submit', async event => {
      if (event.defaultPrevented) return;
      event.preventDefault();
      try {
        const result = await signupJsonPost(signupOtpForm);
        if (result.ok && result.redirect) {
          window.location.replace(result.redirect);
          return;
        }
        showSignupOtpMessage(result.message || 'Unable to verify the code.');
        if (!result.otp_available) closeSignupPopup('otp');
        signupOtpDigits.forEach(input => { input.value = ''; });
        syncSignupOtp();
      } catch (_) {
        showSignupOtpMessage('Unable to connect. Please try again.');
      } finally {
        if (signupOtpVerify) {
          signupOtpVerify.disabled = false;
          signupOtpVerify.textContent = 'Verify & Create Account';
        }
      }
    });
    signupOtpResendForm?.addEventListener('submit', async event => {
      event.preventDefault();
      if (signupOtpResend?.disabled) return;
      signupOtpResend.disabled = true;
      signupOtpResend.textContent = 'Sending...';
      try {
        const result = await signupJsonPost(signupOtpResendForm);
        if (result.ok) {
          signupOtpDigits.forEach(input => { input.value = ''; });
          syncSignupOtp();
          signupOtpSeconds = Math.max(0, Number(result.resend_seconds) || 0);
          showSignupOtpMessage(result.message || 'A new code was sent.', 'success');
        } else {
          showSignupOtpMessage(result.message || 'Unable to resend the code.');
          signupOtpSeconds = Math.max(0, Number(result.resend_seconds) || 0);
        }
      } catch (_) {
        showSignupOtpMessage('Unable to connect. Please try again.');
      } finally {
        tickSignupOtpResend();
      }
    });
    signupOtpCancelForm?.addEventListener('submit', async event => {
      event.preventDefault();
      const button = signupOtpCancelForm.querySelector('button[type="submit"]');
      if (button?.disabled) return;
      if (button) button.disabled = true;
      try {
        const result = await signupJsonPost(signupOtpCancelForm);
        if (!result.ok) {
          showSignupOtpMessage(result.message || 'Unable to change email. Try again.');
          return;
        }
        closeSignupPopup('otp');
        window.setTimeout(() => emailInput.focus({ preventScroll: true }), 60);
      } catch (_) {
        showSignupOtpMessage('Unable to connect. Please try again.');
      } finally {
        if (button) button.disabled = false;
      }
    });
    // Leaving this document is different from browser Back inside a popup.
    // Clearing on pagehide also prevents mobile bfcache from restoring old data.
    let signupLeftDocument = false;
    const resetSignupOnLeave = () => {
      signupLeftDocument = true;
      signupPopupInstance = 'left-' + signupPopupInstance;
      signupPopupStack = [];
      signupForm.querySelectorAll('input').forEach(input => {
        if (input.type === 'hidden') return;
        if (input.type === 'checkbox') input.checked = false;
        else input.value = '';
        input.classList.remove('input-valid', 'input-invalid');
      });
      signupOtpDigits.forEach(input => { input.value = ''; });
      syncSignupOtp();
      checkedEmail = '';
      emailIsAvailable = false;
      clearTimeout(emailCheckTimer);
      emailRequest?.abort();
      setEmailMessage('');
      document.querySelectorAll('.signup-field-error').forEach(el => { el.textContent = ''; });
      passwordStrength.className = 'password-strength';
      passwordWarning.textContent = '';
      confirmPasswordWarning.textContent = '';
      confirmPasswordWarning.className = 'password-match';
      signupOtpModal?.classList.remove('open');
      signupOtpModal?.setAttribute('aria-hidden', 'true');
      policyModal.classList.remove('open');
      policyModal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    };
    window.addEventListener('pagehide', resetSignupOnLeave);
    window.addEventListener('pageshow', event => {
      if (!event.persisted) return;
      // An older OTP history state must never reopen after leaving Signup.
      if (signupLeftDocument) {
        signupLeftDocument = false;
        try {
          const state = window.history.state;
          if (state && typeof state === 'object') {
            const clean = { ...state };
            delete clean[signupPopupHistoryKey];
            delete clean[signupPopupInstanceKey];
            window.history.replaceState(clean, '', window.location.href);
          }
        } catch (_) { /* A restored page still remains cleared visually. */ }
      }
      syncSignupPopupStack([]);
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
