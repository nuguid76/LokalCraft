<?php
require_once __DIR__ . '/buyer_auth.php';
require_once __DIR__ . '/../includes/otp_service.php';
$userId = (int)$buyerId;
$authUserName = trim((string)($userName ?? 'Buyer'));
if (empty($_SESSION['buyer_profile_csrf'])) {
    $_SESSION['buyer_profile_csrf'] = bin2hex(random_bytes(32));
}
$profileMessage = (string)($_SESSION['buyer_profile_message'] ?? '');
$profileMessageType = (string)($_SESSION['buyer_profile_message_type'] ?? '');
unset($_SESSION['buyer_profile_message'], $_SESSION['buyer_profile_message_type']);
$openEditModal = false;
function buyerProfileE($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function buyerProfilePostedString($value): string
{
    return is_string($value) ? $value : '';
}
function buyerProfileName(string $value): string
{
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    if ($value === '') return '';
    $value = function_exists('mb_convert_case')
        ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8')
        : ucwords(strtolower($value), " -'");
    return preg_replace_callback('/\b(Jr|Sr|Ii|Iii|Iv|Vi|Vii|Viii|Ix|Xi)\z/u', static function (array $match): string {
        return in_array(strtolower($match[1]), ['jr', 'sr'], true)
            ? ucfirst(strtolower($match[1])) : strtoupper($match[1]);
    }, $value) ?? $value;
}
function buyerProfileLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : (preg_match_all('/./us', $value) ?: 0);
}
function buyerProfileValidName(string $value, bool $optional = false, bool $apostrophe = false): bool
{
    if ($value === '') return $optional;
    if (buyerProfileLength($value) > 50) return false;
    $separator = $apostrophe ? "[ '-]" : '[ -]';
    return preg_match('/^\p{L}+(?:' . $separator . '\p{L}+)*$/u', $value) === 1;
}
function buyerProfilePhone(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (str_starts_with($digits, '63')) return '0' . substr($digits, 2);
    if (str_starts_with($digits, '9')) return '0' . $digits;
    return $digits;
}
function buyerProfileValidEmail(string $email): bool
{
    if ($email === '' || strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $domain = strtolower((string)substr(strrchr($email, '@') ?: '', 1));
    return !in_array($domain, ['g.mail.com', 'gmail.con', 'yahoo.con'], true);
}
function buyerProfilePage(): string
{
    $page = basename((string)($_SERVER['PHP_SELF'] ?? 'profile.php'));
    return preg_match('/^[A-Za-z0-9._-]+\.php$/', $page) ? $page : 'profile.php';
}
function buyerProfileRow(mysqli $conn, int $userId, bool $lock = false): array
{
    $sql = 'SELECT firstname, middlename, lastname, email, phone, gender, birthday, SellerOrNot, account_status FROM users WHERE id = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : '');
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to load your profile right now.');
    try {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->bind_result($first, $middle, $last, $email, $phone, $gender, $birthday, $sellerFlag, $accountStatus);
        if (!$stmt->fetch()) throw new RuntimeException('Your account is unavailable.');
        return ['firstname'=>$first, 'middlename'=>$middle, 'lastname'=>$last, 'email'=>$email,
            'phone'=>$phone, 'gender'=>$gender, 'birthday'=>$birthday, 'SellerOrNot'=>$sellerFlag, 'account_status'=>$accountStatus];
    } finally { $stmt->close(); }
}
function buyerProfileLatestApplication(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare('SELECT sa.status, sa.rejection_reason, sa.reviewed_at, (SELECT bi.businessname FROM businessinfo bi WHERE bi.user_id = sa.user_id ORDER BY bi.business_id DESC LIMIT 1) AS businessname FROM seller_applications sa WHERE sa.user_id = ? ORDER BY sa.submitted_at DESC, sa.application_id DESC LIMIT 1');
    if (!$stmt) throw new RuntimeException('Unable to check your seller application right now.');
    try {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->bind_result($status, $reason, $reviewedAt, $businessName);
        if (!$stmt->fetch()) return [];
        return ['status'=>$status, 'rejection_reason'=>$reason, 'reviewed_at'=>$reviewedAt, 'businessname'=>$businessName];
    } finally { $stmt->close(); }
}
function buyerProfilePermissions(mysqli $conn, int $userId, array $account): array
{
    $active = strtolower(trim((string)($account['account_status'] ?? ''))) === 'active';
    $application = buyerProfileLatestApplication($conn, $userId);
    $status = strtolower(trim((string)($application['status'] ?? 'none')));
    $approved = (int)($account['SellerOrNot'] ?? 0) === 1 || $status === 'approved';
    $canEdit = $active && $status !== 'pending';
    return ['can_edit'=>$canEdit, 'can_edit_name'=>$canEdit && !$approved];
}
function buyerProfileValidBirthday(string $value): bool
{
    if (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $match)) return false;
    $year = (int)$match[1];
    $month = (int)$match[2];
    $day = (int)$match[3];
    if ($year < 1900 || !checkdate($month, $day, $year)) return false;
    // Use the server's current date, not a browser-supplied timestamp.
    return $value <= (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
}
function buyerProfileValidateInput(array $input, array $account, bool $canEditName): array
{
    $phone = buyerProfilePhone(buyerProfilePostedString($input['phone'] ?? ''));
    $email = strtolower(trim(buyerProfilePostedString($input['email'] ?? '')));
    if ($canEditName) {
        $first = buyerProfileName(buyerProfilePostedString($input['firstname'] ?? ''));
        $middle = buyerProfileName(buyerProfilePostedString($input['middlename'] ?? ''));
        $last = buyerProfileName(buyerProfilePostedString($input['lastname'] ?? ''));
        if (!buyerProfileValidName($first)) throw new DomainException('First name must use letters, spaces or hyphens (maximum 50).');
        if (!buyerProfileValidName($middle, true, true)) throw new DomainException('Middle name may use letters, spaces, hyphens or apostrophes (maximum 50).');
        if (!buyerProfileValidName($last)) throw new DomainException('Last name must use letters, spaces or hyphens (maximum 50).');
    } else {
        $first = (string)$account['firstname'];
        $middle = (string)($account['middlename'] ?? '');
        $last = (string)$account['lastname'];
        // Name fields displayed as readonly must also be protected against crafted POSTs.
        foreach (['firstname'=>$first, 'middlename'=>$middle, 'lastname'=>$last] as $key=>$savedName) {
            if (array_key_exists($key, $input) && (string)$input[$key] !== $savedName) {
                throw new DomainException('Your approved name is locked to match your verified ID.');
            }
        }
    }
    if (!preg_match('/^09\d{9}$/', $phone)) throw new DomainException('Enter an 11-digit Philippine mobile number starting with 09.');
    if (!buyerProfileValidEmail($email)) throw new DomainException('Enter a valid email address.');
    // Gender and birthday are independently set-once fields. Recheck while
    // holding the same user row lock during ordinary saves and OTP completion.
    $existingGender = trim((string)($account['gender'] ?? ''));
    $existingBirthday = trim((string)($account['birthday'] ?? ''));
    $postedGender = isset($input['gender']) && is_string($input['gender']) ? strtolower(trim($input['gender'])) : '';
    $postedBirthday = isset($input['birthday']) && is_string($input['birthday']) ? trim($input['birthday']) : '';
    $allowedGenders = ['male', 'female', 'prefer not to say'];
    if ($existingGender !== '') {
        if ($postedGender !== '' && $postedGender !== $existingGender) {
            throw new DomainException('Gender is already saved and cannot be changed.');
        }
        $gender = $existingGender;
    } else {
        if ($postedGender !== '' && !in_array($postedGender, $allowedGenders, true)) {
            throw new DomainException('Choose a valid gender.');
        }
        $gender = $postedGender !== '' ? $postedGender : null;
    }
    if ($existingBirthday !== '') {
        if ($postedBirthday !== '' && $postedBirthday !== $existingBirthday) {
            throw new DomainException('Birthday is already saved and cannot be changed.');
        }
        $birthday = $existingBirthday;
    } else {
        if ($postedBirthday !== '' && !buyerProfileValidBirthday($postedBirthday)) {
            throw new DomainException('Choose a valid birthday.');
        }
        $birthday = $postedBirthday !== '' ? $postedBirthday : null;
    }
    return ['firstname'=>$first, 'middlename'=>$middle, 'lastname'=>$last, 'phone'=>$phone, 'email'=>$email, 'gender'=>$gender, 'birthday'=>$birthday];
}
function buyerProfileEmailInUse(mysqli $conn, string $email, int $userId): bool
{
    $stmt = $conn->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id <> ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Email availability is temporarily unavailable.');
    try {
        $stmt->bind_param('si', $email, $userId);
        $stmt->execute();
        $stmt->bind_result($existingId);
        return $stmt->fetch() === true;
    } finally { $stmt->close(); }
}
function buyerProfileJson(array $result, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function buyerProfileUpdate(mysqli $conn, int $userId, array $values, string $originalEmail, bool $canEditName): void
{
    if ($canEditName) {
        $middle = $values['middlename'] !== '' ? $values['middlename'] : null;
        $stmt = $conn->prepare('UPDATE users SET firstname = ?, middlename = ?, lastname = ?, phone = ?, email = ?, gender = COALESCE(gender, ?), birthday = COALESCE(birthday, ?) WHERE id = ? AND LOWER(email) = LOWER(?) LIMIT 1');
    } else {
        // Approved sellers can update contact details without rewriting verified identity fields.
        $stmt = $conn->prepare('UPDATE users SET phone = ?, email = ?, gender = COALESCE(gender, ?), birthday = COALESCE(birthday, ?) WHERE id = ? AND LOWER(email) = LOWER(?) LIMIT 1');
    }
    if (!$stmt) throw new RuntimeException('Unable to save your profile right now.');
    try {
        if ($canEditName) {
            $stmt->bind_param('sssssssis', $values['firstname'], $middle, $values['lastname'], $values['phone'], $values['email'], $values['gender'], $values['birthday'], $userId, $originalEmail);
        } else {
            $stmt->bind_param('ssssis', $values['phone'], $values['email'], $values['gender'], $values['birthday'], $userId, $originalEmail);
        }
        if (!$stmt->execute()) throw new RuntimeException('Unable to save your profile right now.');
    } finally { $stmt->close(); }
}
function buyerProfileSetSuccess(array $values, bool $emailChanged): void
{
    $name = trim($values['firstname'] . ' ' . $values['middlename'] . ' ' . $values['lastname']);
    $_SESSION['user_name'] = $name;
    $_SESSION['buyer_name'] = $name;
    $_SESSION['buyer_profile_message'] = $emailChanged ? 'Profile and email updated successfully.' : 'Profile updated successfully.';
    $_SESSION['buyer_profile_message_type'] = 'success';
}
$profile = buyerProfileRow($conn, $userId);
$sellerOrNot = (int)$profile['SellerOrNot'];
$app = buyerProfileLatestApplication($conn, $userId);
$sellerApplicationStatus = strtolower(trim((string)($app['status'] ?? 'none')));
$sellerApplicationReason = trim((string)($app['rejection_reason'] ?? ''));
$sellerReviewedAt = trim((string)($app['reviewed_at'] ?? ''));
$sellerBusinessName = trim((string)($app['businessname'] ?? ''));
if ($sellerOrNot === 1 && $sellerApplicationStatus === 'none') $sellerApplicationStatus = 'approved';
$profileAccountActive = strtolower(trim((string)$profile['account_status'])) === 'active';
$profileApproved = $sellerOrNot === 1 || $sellerApplicationStatus === 'approved';
$profileEditAllowed = $profileAccountActive && $sellerApplicationStatus !== 'pending';
$nameEditingAllowed = $profileEditAllowed && !$profileApproved;
$editValues = ['firstname'=>(string)$profile['firstname'], 'middlename'=>(string)($profile['middlename'] ?? ''), 'lastname'=>(string)$profile['lastname'], 'phone'=>(string)($profile['phone'] ?? ''), 'email'=>(string)$profile['email'], 'gender'=>(string)($profile['gender'] ?? ''), 'birthday'=>(string)($profile['birthday'] ?? '')];
$otpFlowKey = 'buyer_profile_email_change';
$ajaxActions = ['profile_email_check', 'profile_email_start', 'profile_email_verify', 'profile_email_resend', 'profile_email_cancel'];
$action = buyerProfilePostedString($_POST['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, $ajaxActions, true)) {
    try {
        if (!hash_equals((string)($_SESSION['buyer_profile_csrf'] ?? ''), buyerProfilePostedString($_POST['csrf_token'] ?? ''))) throw new DomainException('Your session expired. Refresh the page.');
        $currentAccount = buyerProfileRow($conn, $userId);
        $currentPermissions = buyerProfilePermissions($conn, $userId, $currentAccount);
        if (!$currentPermissions['can_edit']) {
            otpClearChallenge($otpFlowKey);
            throw new DomainException('Profile editing is unavailable while your seller application is pending.');
        }
        if ($action === 'profile_email_cancel') {
            otpClearChallenge($otpFlowKey);
            buyerProfileJson(['success'=>true]);
        }
        if ($action === 'profile_email_check') {
            $newEmail = strtolower(trim(buyerProfilePostedString($_POST['email'] ?? '')));
            if (!buyerProfileValidEmail($newEmail)) throw new DomainException('Enter a valid email address.');
            $isSame = strcasecmp($newEmail, (string)$currentAccount['email']) === 0;
            if (!$isSame && buyerProfileEmailInUse($conn, $newEmail, $userId)) throw new DomainException('This email is already registered.');
            buyerProfileJson(['success'=>true, 'same'=>$isSame, 'message'=>$isSame ? 'Current email address.' : 'Email is available.']);
        }
        if ($action === 'profile_email_start') {
            $values = buyerProfileValidateInput($_POST, $currentAccount, $currentPermissions['can_edit_name']);
            if (strcasecmp($values['email'], (string)$currentAccount['email']) === 0) throw new DomainException('Your email has not changed.');
            if (buyerProfileEmailInUse($conn, $values['email'], $userId)) throw new DomainException('This email is already registered.');
            $lastEmailOtpAt = (int)($_SESSION['buyer_profile_email_otp_last_at'] ?? 0);
            if ($lastEmailOtpAt > 0 && (time() - $lastEmailOtpAt) < 45) {
                throw new DomainException('Please wait a moment before requesting another email code.');
            }
            otpClearChallenge($otpFlowKey);
            otpStartChallenge($otpFlowKey, $values['email'], $values['firstname'] . ' ' . $values['lastname'], [
                'user_id'=>$userId, 'original_email'=>strtolower(trim((string)$currentAccount['email'])), 'values'=>$values
            ], 'verify your new email address for your LokalCraft buyer account');
            $_SESSION['buyer_profile_email_otp_last_at'] = time();
            buyerProfileJson(['success'=>true, 'masked_email'=>otpMaskEmail($values['email']), 'resend_seconds'=>otpResendSeconds($otpFlowKey)]);
        }
        if ($action === 'profile_email_resend') {
            $result = otpResendChallenge($otpFlowKey);
            $result['resend_seconds'] = otpResendSeconds($otpFlowKey);
            buyerProfileJson($result);
        }
        if ($action === 'profile_email_verify') {
            $code = trim(buyerProfilePostedString($_POST['otp'] ?? ''));
            if (!preg_match('/^\d{6}$/D', $code)) throw new DomainException('Enter the complete 6-digit code.');
            $verified = otpVerifyChallenge($otpFlowKey, $code);
            if (empty($verified['success'])) buyerProfileJson(['success'=>false, 'message'=>(string)($verified['message'] ?? 'The verification code is incorrect or expired.')]);
            $payload = $verified['payload'] ?? null;
            if (!is_array($payload) || (int)($payload['user_id'] ?? 0) !== $userId || !is_array($payload['values'] ?? null)) {
                otpClearChallenge($otpFlowKey);
                throw new DomainException('Email verification expired. Please start again.');
            }
            $conn->begin_transaction();
            try {
                $lockedAccount = buyerProfileRow($conn, $userId, true);
                $lockedPermissions = buyerProfilePermissions($conn, $userId, $lockedAccount);
                if (!$lockedPermissions['can_edit']) throw new DomainException('Profile editing is unavailable while your seller application is pending.');
                $values = buyerProfileValidateInput($payload['values'], $lockedAccount, $lockedPermissions['can_edit_name']);
                if (strcasecmp((string)($verified['email'] ?? ''), $values['email']) !== 0) throw new DomainException('Email verification does not match your new email address.');
                if (strcasecmp((string)$lockedAccount['email'], (string)($payload['original_email'] ?? '')) !== 0) throw new DomainException('Your email changed during verification. Please start again.');
                if (buyerProfileEmailInUse($conn, $values['email'], $userId)) throw new DomainException('This email is already registered.');
                buyerProfileUpdate($conn, $userId, $values, (string)$lockedAccount['email'], $lockedPermissions['can_edit_name']);
                $conn->commit();
            } catch (Throwable $error) {
                $conn->rollback();
                throw $error;
            }
            otpClearChallenge($otpFlowKey);
            buyerProfileSetSuccess($values, true);
            buyerProfileJson(['success'=>true, 'redirect'=>buyerProfilePage()]);
        }
    } catch (Throwable $error) {
        if (!($error instanceof DomainException)) error_log('LokalCraft buyer email change: ' . $error->getMessage());
        $message = $error instanceof DomainException ? $error->getMessage() : 'Could not complete this request. Please try again.';
        buyerProfileJson(['success'=>false, 'message'=>$message], $error instanceof DomainException ? 422 : 500);
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_profile') {
    try {
        if (!hash_equals((string)($_SESSION['buyer_profile_csrf'] ?? ''), buyerProfilePostedString($_POST['csrf_token'] ?? ''))) throw new DomainException('Your session expired. Refresh the page.');
        $conn->begin_transaction();
        try {
            $currentAccount = buyerProfileRow($conn, $userId, true);
            $currentPermissions = buyerProfilePermissions($conn, $userId, $currentAccount);
            if (!$currentPermissions['can_edit']) throw new DomainException('Profile editing is unavailable while your seller application is pending.');
            $values = buyerProfileValidateInput($_POST, $currentAccount, $currentPermissions['can_edit_name']);
            $editValues = $values;
            if (strcasecmp($values['email'], (string)$currentAccount['email']) !== 0) throw new DomainException('Verify your new email address before saving.');
            buyerProfileUpdate($conn, $userId, $values, (string)$currentAccount['email'], $currentPermissions['can_edit_name']);
            $conn->commit();
        } catch (Throwable $error) { $conn->rollback(); throw $error; }
        buyerProfileSetSuccess($values, false);
        header('Location: ' . buyerProfilePage());
        exit;
    } catch (Throwable $error) {
        if (!($error instanceof DomainException)) error_log('LokalCraft buyer profile update: ' . $error->getMessage());
        $profileMessage = $error instanceof DomainException ? $error->getMessage() : 'Unable to update your profile right now.';
        $profileMessageType = 'error';
        $openEditModal = $profileEditAllowed;
        if (empty($profile['gender']) && isset($_POST['gender']) && is_string($_POST['gender'])) $editValues['gender'] = $_POST['gender'];
        if (empty($profile['birthday']) && isset($_POST['birthday']) && is_string($_POST['birthday'])) $editValues['birthday'] = $_POST['birthday'];
    }
}
$fullName = trim(
    (string)($profile['firstname'] ?? '')
    . ' '
    . (string)($profile['middlename'] ?? '')
    . ' '
    . (string)($profile['lastname'] ?? '')
);
$fullName = preg_replace('/\s+/', ' ', $fullName);
if ($fullName === '') {
    $fullName = $authUserName !== '' ? $authUserName : 'Buyer';
}
$userName = $fullName !== '' ? $fullName : 'Buyer';
$activePage = 'profile';
$nameParts = preg_split('/\s+/', trim($userName));
$initials = '';
if (is_array($nameParts)) {
    foreach (array_slice(array_filter($nameParts), 0, 2) as $part) {
        $firstCharacter = function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1);
        $initials .= function_exists('mb_strtoupper') ? mb_strtoupper($firstCharacter, 'UTF-8') : strtoupper($firstCharacter);
    }
}
if ($initials === '') {
    $initials = 'LC';
}
$sellerAccessReady = $sellerOrNot === 1
    && $profileAccountActive
    && $sellerApplicationStatus === 'approved';
if ($sellerApplicationStatus === 'pending') {
    $_SESSION['seller_application_status'] = 'pending';
    unset($_SESSION['seller_application_rejection_reason']);
} elseif ($sellerApplicationStatus === 'rejected') {
    $_SESSION['seller_application_status'] = 'rejected';
    $_SESSION['seller_application_rejection_reason'] = $sellerApplicationReason;
} elseif ($sellerApplicationStatus === 'approved') {
    $_SESSION['seller_application_status'] = 'approved';
    unset($_SESSION['seller_application_rejection_reason']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>LokalCraft | Profile</title>
  <style>
    :root {
      --profile-bg: #fff8ed;
      --profile-card: #ffffff;
      --profile-text: #2d2d2d;
      --profile-muted: #76716d;
      --profile-line: #e8e1da;
      --profile-orange: #a55325;
      --profile-orange-dark: #85401e;
      --profile-orange-soft: #fff1e7;
      --profile-green: #278553;
      --profile-green-soft: #edf9f1;
      --profile-red: #bd4c4c;
      --profile-red-soft: #fff1f1;
      --profile-gold: #9d6b21;
      --profile-gold-soft: #fff6df;
    }
    * {
      box-sizing: border-box;
    }
    html,
    body {
      min-height: 100%;
    }
    body {
      margin: 0;
      min-height: 100vh;
      background: var(--profile-bg);
      color: var(--profile-text);
      font-family: "Segoe UI", Arial, sans-serif;
    }
    button,
    a {
      font: inherit;
    }
    .page {
      display: block !important;
      min-height: 100vh;
    }
    .page > .content {
      position: relative !important;
      width: auto !important;
      max-width: none !important;
      min-height: 100vh !important;
      margin-left: 240px !important;
      padding: 74px 26px 34px !important;
      overflow: visible !important;
    }

    .profile-hero {
      margin-bottom: 18px;
    }

    .business-name span {
      display: block;
      color: #958e88;
      font-size: .55rem;
      font-weight: 800;
      letter-spacing: .04em;
      text-transform: uppercase;
    }
    .business-name strong {
      display: block;
      margin-top: 4px;
      color: #4a4541;
      font-size: .72rem;
      line-height: 1.4;
    }
    .seller-reason {
      margin-top: 11px;
      padding: 10px 11px;
      border: 1px solid #ebcaca;
      border-radius: 9px;
      background: #fff5f5;
      color: #914545;
      font-size: .66rem;
      line-height: 1.5;
    }
    .seller-reason strong {
      display: block;
      margin-bottom: 3px;
      font-size: .61rem;
      text-transform: uppercase;
      letter-spacing: .03em;
    }
    .seller-action {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 100%;
      min-height: 36px;
      margin-top: 14px;
      padding: 8px 11px;
      border: 1px solid var(--profile-orange);
      border-radius: 9px;
      background: var(--profile-orange);
      color: #fff;
      font-size: .68rem;
      font-weight: 800;
      text-decoration: none;
      cursor: pointer;
      transition: .18s ease;
    }

    .seller-action.secondary:hover {
      border-color: #cbbeb3;
      background: #fcf9f6;
    }

    .edit-profile-btn {
      flex: 0 0 auto;
      min-height: 34px;
      padding: 7px 11px;
      border: 1px solid var(--profile-orange);
      border-radius: 9px;
      background: var(--profile-orange);
      color: #fff;
      font-size: .66rem;
      font-weight: 800;
      cursor: pointer;
    }

    .profile-notice {
      margin-bottom: 14px;
      padding: 10px 12px;
      border: 1px solid #b9e5cc;
      border-radius: 10px;
      background: var(--profile-green-soft);
      color: #187448;
      font-size: .72rem;
      line-height: 1.45;
    }
    .profile-notice.error {
      border-color: #edc1be;
      background: var(--profile-red-soft);
      color: #933d38;
    }
    body.profile-modal-open {
      overflow: hidden;
    }
    .edit-profile-overlay {
      position: fixed;
      inset: 0;
      z-index: 200000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 18px;
      background: rgba(35,27,21,.46);
      backdrop-filter: blur(2px);
      -webkit-backdrop-filter: blur(2px);
    }
    .edit-profile-overlay.open {
      display: flex;
    }
    .edit-profile-modal {
      width: min(520px,100%);
      max-height: calc(100vh - 36px);
      overflow-y: auto;
      border: 1px solid #eadfd6;
      border-radius: 18px;
      background: #fff;
      box-shadow: 0 24px 70px rgba(30,20,10,.22);
    }
    .edit-profile-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 14px;
      padding: 18px 20px 15px;
      border-bottom: 1px solid #eee6df;
      background: #fffaf6;
    }
    .edit-profile-head h2 {
      margin: 0;
      color: #302b27;
      font-size: 1.05rem;
    }
    .edit-profile-head p {
      margin: 4px 0 0;
      color: #817970;
      font-size: .67rem;
      line-height: 1.45;
    }
    .edit-profile-close {
      width: 34px;
      height: 34px;
      flex: 0 0 34px;
      padding: 0;
      border: 1px solid #e1d8d0;
      border-radius: 50%;
      background: #fff;
      color: #6e655e;
      font-size: 1.15rem;
      cursor: pointer;
    }
    .edit-profile-form {
      padding: 18px 20px 20px;
    }
    .edit-profile-grid {
      display: grid;
      grid-template-columns: repeat(2,minmax(0,1fr));
      gap: 13px;
    }
    .edit-profile-field.full {
      grid-column: 1 / -1;
    }
    .edit-profile-field label {
      display: block;
      margin-bottom: 6px;
      color: #625b55;
      font-size: .68rem;
      font-weight: 800;
    }
    .edit-profile-field input {
      width: 100%;
      min-height: 42px;
      padding: 9px 11px;
      border: 1px solid #ddd6d0;
      border-radius: 9px;
      background: #fff;
      color: #3f3a36;
      font: inherit;
      font-size: .8rem;
      outline: 0;
    }
    .edit-profile-field input:focus {
      border-color: #dda56f;
      box-shadow: 0 0 0 3px rgba(234,138,58,.1);
    }
    .edit-profile-field input[readonly] {
      background: #f5f2ef;
      color: #8b847e;
      cursor: not-allowed;
    }

    .edit-profile-actions {
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      margin-top: 17px;
    }
    .edit-profile-cancel,
    .edit-profile-save {
      min-height: 39px;
      padding: 8px 14px;
      border-radius: 9px;
      font: inherit;
      font-size: .72rem;
      font-weight: 800;
      cursor: pointer;
    }
    .edit-profile-cancel {
      border: 1px solid #ddd6d0;
      background: #fff;
      color: #625b55;
    }
    .edit-profile-save {
      border: 1px solid var(--profile-orange);
      background: var(--profile-orange);
      color: #fff;
    }
    .edit-profile-save:hover {
      background: var(--profile-orange-dark);
    }
    @media (max-width: 980px) {
      .profile-grid {
        grid-template-columns: 1fr;
      }
    }
    @media (max-width: 840px) {
      .page {
        display: flex !important;
        flex-direction: column !important;
      }
      .page > .content {
        position: relative !important;
        width: 100% !important;
        min-height: auto !important;
        margin-left: 0 !important;
        padding: 74px 16px 28px !important;
      }
    }
    @media (max-width: 560px) {
      .page > .content {
        padding-left: 12px !important;
        padding-right: 12px !important;
      }
      .profile-hero h1 {
        font-size: 1.7rem;
      }
      .detail-row {
        grid-template-columns: 1fr;
        gap: 5px;
        min-height: auto;
        padding-top: 12px;
        padding-bottom: 12px;
      }

      .profile-avatar {
        width: 46px;
        height: 46px;
        flex-basis: 46px;
      }
    }
      @media (max-width: 560px) {

      .edit-profile-grid {
        grid-template-columns: 1fr;
      }
      .edit-profile-field.full,
      .edit-profile-lock-note {
        grid-column: auto;
      }
      .edit-profile-actions {
        flex-direction: column-reverse;
      }
      .edit-profile-cancel,
      .edit-profile-save {
        width: 100%;
      }
    }

    .profile-optional{font-size:.68rem;color:#968b80;font-weight:500}
    .edit-profile-field{min-width:0}
    .edit-profile-field input{min-width:0}
    .edit-profile-field input:disabled{background:#f5f2ef;color:#8b847e;cursor:not-allowed}
    .profile-field-feedback{font-size:.7rem;line-height:1.4;color:#8c8278}
    .profile-field-feedback:empty{display:none}
    .profile-field-feedback.success{color:#278553}
    .profile-field-feedback.error{color:#bd4c4c}
    .profile-field-feedback.info{color:#88633d}
    .profile-inline-error{margin-top:12px;padding:10px 12px;border-radius:9px;border:1px solid #edc1be;background:#fff1f1;color:#933d38;font-size:.76rem;line-height:1.45}
    .profile-inline-error[hidden]{display:none}
    .edit-profile-save:disabled{opacity:.52;cursor:not-allowed}
    .profile-otp-overlay{position:fixed;inset:0;z-index:220000;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(35,27,21,.57);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px)}
    .profile-otp-overlay.open{display:flex}
    .profile-otp-card{width:min(430px,100%);max-height:calc(100vh - 32px);max-height:calc(100dvh - 32px);overflow-y:auto;padding:24px;border:1px solid #e9d9c9;border-radius:20px;background:#fffdfa;box-shadow:0 28px 85px rgba(30,20,10,.25);text-align:center}
    .profile-otp-head{position:relative;display:flex;justify-content:center;align-items:center;min-height:62px}
    .profile-otp-head .edit-profile-close{position:absolute;right:0;top:0}
    .profile-otp-icon{display:grid;place-items:center;width:58px;height:58px;border-radius:50%;background:#f8e9d8;color:#aa5c2c;border:1px solid #efd8be;font-size:27px}
    .profile-otp-card h2{margin:16px 0 7px;font-size:1.37rem;color:#5a3822;font-weight:800}
    .profile-otp-sub{margin:0 0 22px;color:#806b5c;font-size:.84rem;line-height:1.6;overflow-wrap:anywhere}
    .profile-otp-sub strong{display:block;margin-top:4px;color:#8b5423;font-weight:800}
    .profile-otp-label{display:block;margin:0 0 10px;color:#6b4c38;text-align:left;font-size:.8rem;font-weight:800}
    .profile-otp-digits{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:9px;width:100%}
    .profile-otp-digit{width:100%;min-width:0;height:55px;padding:5px 0;border:1.5px solid #e7cdb0;border-radius:12px;background:#f9efe2;color:#432e20;text-align:center;font:800 1.38rem "Segoe UI",Arial,sans-serif;outline:0;font-variant-numeric:tabular-nums;transition:border-color .18s,box-shadow .18s,background .18s}
    .profile-otp-digit:focus{border-color:#b85c28;background:#fffaf4;box-shadow:0 0 0 3px rgba(184,92,40,.11)}
    .profile-otp-digit.filled{background:#fffaf4;border-color:#c99469}
    .profile-otp-message{min-height:21px;margin-top:10px;color:#897a6d;font-size:.75rem;line-height:1.4}
    .profile-otp-message.error{color:#bd4c4c}
    .profile-otp-message.success{color:#278553}
    .profile-otp-verify{width:100%;margin-top:10px;padding:13px 15px;font-size:.86rem;min-height:47px;border-radius:12px;background:#b85c28;border-color:#b85c28}
    .profile-otp-verify:hover:not(:disabled){background:#9d4d21}
    .profile-otp-verify:disabled{background:#d9c3b2;border-color:#d9c3b2;color:#fff;opacity:1;cursor:not-allowed}
    .profile-otp-footer{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:16px}
    .profile-otp-link{border:0;background:transparent;color:#b76733;font-size:.77rem;font-weight:750;cursor:pointer;padding:5px}
    .profile-otp-link:disabled{color:#ad9e91;cursor:not-allowed}
    .edit-profile-lock-note{grid-column:1/-1;margin:0;padding:9px 11px;border:1px solid #eee4da;border-radius:9px;background:#fcfaf7;color:#7d736a;font-size:.7rem;line-height:1.45}
    @media(max-width:560px){.profile-otp-card{padding:20px 17px;border-radius:17px}.profile-otp-digits{gap:6px}.profile-otp-digit{height:49px;font-size:1.2rem;border-radius:9px}.edit-profile-modal{max-height:calc(100vh - 26px);max-height:calc(100dvh - 26px)}}
    /* Keep edit/OTP dialogs scrollable when the virtual keyboard is visible. */
    .edit-profile-modal, .profile-otp-card {
      max-height: calc(100vh - 36px);
      max-height: calc(100dvh - 36px);
      -webkit-overflow-scrolling: touch;
      overscroll-behavior: contain;
    }
    @media (max-width: 560px) {
      .edit-profile-overlay, .profile-otp-overlay { padding: 12px; }
      .edit-profile-modal, .profile-otp-card { max-height: calc(100vh - 24px); max-height: calc(100dvh - 24px); }
      .profile-otp-digit { font-size: 19px; }
    }

/* Profile overview — LokalCraft cream & orange. */
/* Selector is deliberately more specific than shared Buyer navigation CSS. */
.page>.content.buyer-profile-content { position:fixed!important; margin:0!important; left:240px!important; right:0!important; top:0!important; bottom:0!important; width:calc(100vw - 240px)!important; max-width:none!important; min-width:0!important; height:auto!important; overflow-x:hidden!important; overflow-y:auto!important; }
/* Lock the scrollable profile pane too, not only body, while a modal is open. */
body.profile-modal-open .page>.content.buyer-profile-content { overflow-y:hidden!important; }
.profile-wrap { width:100%; max-width:1600px; margin:0 auto; min-width:0; }
.profile-hero { display:flex; justify-content:space-between; align-items:center; gap:16px; margin:0 0 21px; }
.profile-hero-copy { min-width:0; }
.profile-eyebrow { display:block; margin:0 0 5px; color:#b57945; font-size:.67rem; font-weight:800; letter-spacing:.15em; }
.profile-hero h1 { margin:0; font-size:clamp(1.5rem,3vw,2.05rem); font-weight:800; line-height:1.15; color:#312a25; letter-spacing:-.045em; }
.edit-profile-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:43px; padding:10px 17px; border:1px solid #d77d32; border-radius:12px; background:#e98b40; color:#fff; font-size:.83rem; font-weight:750; white-space:nowrap; box-shadow:0 5px 14px rgba(202,111,36,.13); transition:background .16s ease, transform .16s ease; }
.edit-profile-btn:hover { border-color:#c8752d; background:#cd722c; transform:translateY(-1px); }
.edit-profile-btn:focus-visible,.seller-action:focus-visible { outline:3px solid #f6bc8b; outline-offset:3px; }
.edit-profile-btn svg,.profile-edit-locked svg { width:17px; height:17px; flex:0 0 17px; }
.profile-edit-locked { display:inline-flex; gap:7px; align-items:center; justify-content:center; padding:10px 13px; min-height:41px; border:1px solid #ebdfd4; border-radius:11px; color:#877569; background:#f8f4ef; font-size:.78rem; font-weight:700; }
.profile-banner { position:relative; isolation:isolate; display:flex; justify-content:space-between; align-items:center; gap:20px; min-height:166px; padding:30px 32px; margin:0 0 17px; overflow:hidden; border:1px solid #eadac9; border-radius:21px; background:linear-gradient(113deg,#fffdfb 1%,#fff8f1 49%,#fcead6 100%); box-shadow:0 12px 27px rgba(70,44,22,.055); }
.profile-banner::before { content:""; position:absolute; z-index:-1; width:290px; height:290px; border:1px solid rgba(224,154,92,.22); border-radius:50%; top:-194px; right:-65px; box-shadow:0 0 0 42px rgba(251,226,197,.23),0 0 0 96px rgba(253,235,216,.27); pointer-events:none; }
.profile-banner-content { display:flex; align-items:center; gap:18px; min-width:0; }
.profile-avatar { display:grid; place-items:center; flex:0 0 82px; width:82px; height:82px; border-radius:24px; border:4px solid #fff; background:linear-gradient(135deg,#f2b779,#d57d39); box-shadow:0 8px 22px rgba(184,103,37,.17); font-size:1.45rem; font-weight:800; letter-spacing:.015em; color:#fff; }
.profile-banner-text { min-width:0; }
.profile-role-label { color:#b77540; font-size:.66rem; letter-spacing:.13em; font-weight:800; }
.profile-banner-text h2 { margin:5px 0; font-size:clamp(1.2rem,2.5vw,1.65rem); font-weight:800; letter-spacing:-.035em; line-height:1.25; color:#382b23; overflow-wrap:anywhere; }
.profile-banner-text p { margin:0; max-width:550px; font-size:.84rem; color:#84776d; overflow-wrap:anywhere; }
.profile-banner-badge { align-self:center; display:inline-flex; align-items:center; gap:7px; flex:0 0 auto; padding:8px 12px; border:1px solid #e7e1d9; border-radius:999px; background:#fffdfa; color:#6f6257; font-size:.73rem; font-weight:750; white-space:nowrap; box-shadow:0 3px 8px rgba(70,44,22,.035); }
.profile-active-dot { width:8px; height:8px; border-radius:50%; background:#51a071; box-shadow:0 0 0 3px #e6f6ed; }
.profile-grid { display:grid; grid-template-columns:minmax(0,1.45fr) minmax(290px,.9fr); gap:17px; align-items:start; }
.profile-card { min-width:0; background:#fff; border:1px solid #ebe3dc; border-radius:18px; overflow:hidden; box-shadow:0 10px 26px rgba(52,36,23,.04); }
.profile-card-header { display:flex; justify-content:space-between; align-items:center; gap:12px; min-height:74px; padding:17px 20px; border-bottom:1px solid #f0e8e0; background:#fff; }
.profile-section-heading { display:flex; align-items:center; gap:11px; min-width:0; }
.profile-section-heading h2 { margin:0; font-size:.94rem; font-weight:800; color:#49392b; letter-spacing:-.01em; }
.profile-section-icon { display:grid; place-items:center; flex:0 0 38px; width:38px; height:38px; border:1px solid #f4e3d1; border-radius:11px; background:#fff3e7; color:#c27b3a; }
.profile-section-icon svg { width:19px; height:19px; }
.details-list { display:grid; padding:2px 20px; }
.detail-row { display:flex; align-items:center; gap:13px; min-width:0; min-height:88px; padding:17px 0; border-bottom:1px solid #f2ece7; }
.detail-row:last-child { border-bottom:0; }
.detail-icon { display:grid; place-items:center; flex:0 0 38px; width:38px; height:38px; border-radius:11px; background:#f8f5f1; color:#9c816b; }
.detail-icon svg { width:18px; height:18px; }
.detail-info { min-width:0; flex:1; }
.detail-label { display:block; margin-bottom:4px; color:#9a897b; font-size:.68rem; font-weight:750; letter-spacing:.02em; text-transform:none; }
.detail-value { min-width:0; color:#483e37; font-size:.87rem; font-weight:720; line-height:1.4; overflow-wrap:anywhere; }
.detail-locked { display:grid; place-items:center; flex:0 0 26px; width:26px; height:26px; border-radius:8px; color:#a48d7c; background:#f8f4ef; }
.detail-locked svg { width:14px; height:14px; }
.profile-seller-card .profile-card-header { gap:9px; }
.profile-seller-card .profile-section-heading { gap:8px; }
.profile-seller-card .profile-section-heading h2 { font-size:.9rem; white-space:nowrap; }
.status-pill { display:inline-flex; align-items:center; flex:0 0 auto; padding:7px 9px; min-height:26px; border-radius:999px; font-size:.64rem; letter-spacing:0; text-transform:none; font-weight:750; }
.status-pill.none { background:#f6f2ee; color:#877970; }
.status-pill.pending { background:#fff3db; color:#966822; }
.status-pill.approved { background:#eaf8ef; color:#287950; }
.status-pill.rejected { background:#fff0ee; color:#b44b43; }
.seller-card-body { padding:22px 20px 20px; }
.business-name { margin:0; padding:14px 15px; border:1px solid #f0e6dc; border-radius:12px; background:#fcf9f6; }
.business-name span { display:block; margin:0 0 7px; color:#9a8c7f; font-size:.63rem; letter-spacing:.09em; font-weight:800; }
.business-name strong { display:block; margin:0; color:#473a30; font-size:.98rem; line-height:1.4; font-weight:800; overflow-wrap:anywhere; }
.seller-empty-mark { display:grid; place-items:center; width:53px; height:53px; border-radius:16px; background:#fff1e2; color:#bf7836; margin:1px 0 15px; }
.seller-empty-mark svg { width:26px; height:26px; }
.seller-empty-title { color:#4e4035; font-size:.92rem; font-weight:770; line-height:1.4; }
.seller-status-date { margin:12px 0 0; color:#96877a; font-size:.73rem; }
.seller-inline-alert { margin:12px 0 0; padding:10px 12px; border-radius:10px; background:#fff3e7; color:#9b5d2c; font-size:.78rem; }
.seller-reason { margin:14px 0 0; padding:12px 13px; border-radius:11px; border:1px solid #edc9c4; background:#fff6f4; color:#94473f; font-size:.78rem; line-height:1.5; overflow-wrap:anywhere; }
.seller-reason span { display:block; margin-bottom:5px; font-weight:850; font-size:.61rem; letter-spacing:.08em; }
.seller-action { display:flex; justify-content:center; align-items:center; gap:9px; width:100%; min-height:44px; margin:18px 0 0; padding:11px 14px; border:1px solid #e58a3d; border-radius:12px; background:#e98b40; color:#fff; font-size:.83rem; font-weight:800; text-decoration:none; transition:background .16s ease; }
.seller-action svg { width:17px; height:17px; flex:0 0 17px; }
.seller-action:hover { border-color:#cd752e; background:#cd752e; }
.seller-action.secondary { border-color:#eadacb; background:#fff9f3; color:#ad6933; }
.seller-action.secondary:hover { background:#f9e9da; }
.profile-notice { margin-bottom:16px; border-radius:12px; font-size:.81rem; }
.edit-profile-head { align-items:center; }
.edit-profile-head h2 { font-size:1.05rem; }
.edit-profile-lock-note { display:flex; align-items:center; gap:7px; font-size:.76rem; }
.edit-profile-lock-note svg { width:15px; height:15px; flex:0 0 15px; }
.edit-profile-field input { font-size:16px!important; }
.edit-profile-field input:focus-visible { outline:0; }
.edit-profile-modal,.profile-otp-card { max-height:calc(100vh - 36px); max-height:calc(100dvh - 36px); -webkit-overflow-scrolling:touch; overscroll-behavior:contain; }
@media(max-width:980px) { .profile-grid { grid-template-columns:1fr; } }
@media(max-width:840px) { .page>.content.buyer-profile-content { position:relative!important; left:0!important; right:0!important; top:auto!important; bottom:auto!important; margin-left:0!important; width:100%!important; max-width:100%!important; height:auto!important; overflow-y:visible!important; padding:80px 16px 32px!important; min-width:0; } }
@media(max-width:560px) {
  .page>.content.buyer-profile-content { padding:76px 12px 28px!important; }
  .profile-hero { gap:8px; margin-bottom:15px; }
  .profile-hero h1 { font-size:1.6rem; }
  .profile-eyebrow { font-size:.58rem; }
  .edit-profile-btn { min-height:40px; padding:9px 11px; border-radius:10px; font-size:.76rem; gap:6px; }
  .edit-profile-btn svg { width:15px; height:15px; flex-basis:15px; }
  .profile-banner { padding:19px 17px; min-height:0; gap:14px; align-items:flex-start; flex-direction:column; border-radius:17px; }
  .profile-banner-content { gap:13px; width:100%; }
  .profile-avatar { flex:0 0 62px; width:62px; height:62px; border-radius:19px; font-size:1.14rem; }
  .profile-banner-text h2 { font-size:1.2rem; }
  .profile-banner-text p { font-size:.75rem; }
  .profile-role-label { font-size:.58rem; }
  .profile-banner-badge { padding:6px 10px; font-size:.68rem; }
  .profile-card { border-radius:15px; }
  .profile-card-header { padding:14px 15px; min-height:68px; }
  .profile-section-heading h2 { font-size:.86rem; }
  .profile-section-icon { flex-basis:35px; width:35px; height:35px; }
  .details-list { padding:0 15px; }
  .detail-row { min-height:76px; padding:13px 0; gap:11px; }
  .detail-icon { width:34px; height:34px; flex-basis:34px; }
  .detail-value { font-size:.83rem; }
  .seller-card-body { padding:17px 15px; }
  .edit-profile-overlay,.profile-otp-overlay { padding:max(12px,env(safe-area-inset-top)) 12px max(12px,env(safe-area-inset-bottom)); }
  .edit-profile-modal,.profile-otp-card { max-height:calc(100vh - 24px); max-height:calc(100dvh - 24px); }
}
@media(max-width:360px) { .profile-section-heading { gap:7px; } .profile-seller-card .profile-section-heading h2 { font-size:.82rem; } .status-pill { padding:6px 7px; font-size:.6rem; } }

/* Set-once gender and birthday · touch-friendly, responsive controls. */
.profile-field-heading{display:flex;align-items:center;gap:7px;margin-bottom:7px}
.profile-field-heading label{margin:0}
.profile-one-time-tip{position:relative;display:inline-flex;align-items:center;z-index:4}
.profile-one-time-tip summary{list-style:none;display:grid;place-items:center;width:22px;height:22px;border:1px solid #e4c6a8;border-radius:50%;background:#fff4e7;color:#995a2b;font-size:13px;font-weight:850;cursor:pointer;user-select:none}
.profile-one-time-tip summary::-webkit-details-marker{display:none}
.profile-one-time-tip summary:focus-visible{outline:2px solid #da823a;outline-offset:2px}
.profile-tip-text{position:absolute;bottom:calc(100% + 9px);left:-70px;width:min(258px,calc(100vw - 54px));padding:11px 12px;border:1px solid #ead4be;border-radius:11px;background:#fffdfb;color:#5f4837;font-size:13px;font-weight:500;line-height:1.45;box-shadow:0 10px 26px rgba(60,37,20,.15);z-index:20}
.profile-one-time-field select,.profile-date-trigger,.profile-fixed-value{width:100%;min-height:45px;padding:10px 12px;border:1px solid #ddd6d0;border-radius:10px;background:#fff;color:#413a34;font:inherit;font-size:16px;text-align:left}
.profile-one-time-field select:focus,.profile-date-trigger:focus-visible{outline:0;border-color:#dc9252;box-shadow:0 0 0 3px rgba(233,139,64,.12)}
.profile-fixed-value{display:flex;justify-content:space-between;align-items:center;gap:8px;background:#f5f2ef;color:#766c63}
.profile-fixed-value span{font-size:13px}
.profile-date-trigger{display:flex;align-items:center;justify-content:space-between;gap:8px;cursor:pointer}
.profile-date-trigger svg{flex:0 0 19px;color:#b5753c}
.profile-birth-calendar{position:fixed;z-index:210000;width:min(360px,calc(100vw - 24px));max-height:calc(100vh - 24px);max-height:calc(100dvh - 24px);overflow-y:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;padding:12px;border:1px solid #ead8c6;border-radius:14px;background:#fffdfa;box-shadow:0 14px 36px rgba(74,45,23,.18)}
.profile-birth-calendar[hidden]{display:none!important}
.birth-calendar-header{display:grid;grid-template-columns:32px minmax(0,1.35fr) minmax(0,1fr) 32px;gap:5px;align-items:center}
.birth-calendar-header select{min-width:0;width:100%;min-height:39px;border:1px solid #eaded1;border-radius:8px;background:#fff8f2;color:#634934;padding:4px;font-size:16px}
.birth-calendar-header button{width:32px;height:39px;border:1px solid #eaded1;border-radius:8px;background:#fff;color:#965d2b;font-size:24px;line-height:1}
.birth-weekdays,.birth-calendar-days{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:3px;text-align:center}
.birth-weekdays{margin:13px 0 5px;color:#95887c;font-size:11px;font-weight:800}
.birth-calendar-days button{min-height:36px;border:1px solid transparent;border-radius:9px;background:transparent;color:#42362d;font-size:13px;cursor:pointer}
.birth-calendar-days button:hover:not(:disabled){background:#fff0e2;border-color:#f2d3b4}
.birth-calendar-days button[aria-pressed="true"]{background:#e98b40;color:#fff;font-weight:800}
.birth-calendar-days button:disabled{opacity:.24;cursor:not-allowed}
.birth-calendar-footer{display:flex;gap:8px;justify-content:flex-end;margin-top:9px;padding-top:9px;border-top:1px solid #f3e7dc}
.birth-calendar-footer button{padding:7px 11px;border:0;border-radius:8px;background:#fff0e2;color:#a46029;font-size:12px;font-weight:750}
@media(max-width:560px){.profile-birth-calendar{max-width:100%;padding:10px}.profile-tip-text{left:-68px;width:min(240px,calc(100vw - 48px))}.birth-calendar-days button{min-height:39px}}

/* Consistent LokalCraft CTA gradient for the Profile avatar and action buttons. */
.profile-avatar {
  background: linear-gradient(135deg, #A55325 0%, #C2692E 100%);
  color: #fff;
  box-shadow: 0 8px 22px rgba(165,83,37,.19);
}
.edit-profile-btn,
.edit-profile-save,
.seller-action,
.seller-action.secondary,
.profile-otp-verify {
  background: linear-gradient(135deg, #A55325 0%, #C2692E 100%);
  border-color: #A55325;
  color: #fff;
  transition: transform .18s ease, box-shadow .18s ease, filter .18s ease;
}
.edit-profile-btn:hover:not(:disabled),
.edit-profile-save:hover:not(:disabled),
.seller-action:hover,
.seller-action.secondary:hover,
.profile-otp-verify:hover:not(:disabled) {
  background: linear-gradient(135deg, #A55325 0%, #C2692E 100%);
  border-color: #A55325;
  color: #fff;
  filter: brightness(.94);
  box-shadow: 0 7px 17px rgba(165,83,37,.16);
}
.edit-profile-btn:focus-visible,
.edit-profile-save:focus-visible,
.seller-action:focus-visible,
.profile-otp-verify:focus-visible {
  outline: 3px solid rgba(165,83,37,.35);
  outline-offset: 3px;
}
.profile-otp-verify:disabled { background: #d9c3b2; border-color: #d9c3b2; }
.birth-calendar-days button[aria-pressed="true"] { background: linear-gradient(135deg, #A55325 0%, #C2692E 100%); border-color: #A55325; }
.profile-otp-icon { background: #f8ede5; color: #a55325; border-color: #ead4c1; }
.profile-otp-digit:focus { border-color: #a55325; box-shadow: 0 0 0 3px rgba(165,83,37,.13); }
.profile-otp-link { color: #a55325; }
.profile-otp-link:hover:not(:disabled) { color: #85401e; }
@media (prefers-reduced-motion: no-preference) {
  .edit-profile-overlay.open .edit-profile-modal,
  .profile-otp-overlay.open .profile-otp-card {
    animation: profile-dialog-enter .2s cubic-bezier(.2,.7,.2,1) both;
  }
  @keyframes profile-dialog-enter {
    from { opacity: 0; transform: translateY(9px) scale(.99); }
    to { opacity: 1; transform: translateY(0) scale(1); }
  }
}

/* My Profile palette — same #FFF8ED cream as For You; shared Buyer files untouched. */
.page { background: #fff8ed !important; }
.page > .content.buyer-profile-content { background: #fff8ed !important; }
/* This higher-specificity rule overrides the later included buyer_search.php styles. */
.page > .content.buyer-profile-content .buyer-header-tools {
  background: rgba(255, 248, 237, .98) !important;
  border-bottom-color: #eadfd2 !important;
}
.page > .content.buyer-profile-content .buyer-header-tools .filter-box {
  background: #fffaf5 !important;
  border-color: #e8d9c9 !important;
}
.page > .content.buyer-profile-content .buyer-header-tools .filter-box:focus-within {
  background: #fffefb !important;
}
</style>
</head>
<body>
<div class="page">
  <?php include __DIR__ . '/buyer_nav.php'; ?>
  <main class="content buyer-profile-content">
    <?php include __DIR__ . '/buyer_search.php'; ?>
    <div class="profile-wrap">
      <header class="profile-hero">
        <div class="profile-hero-copy">
          <span class="profile-eyebrow">YOUR ACCOUNT</span>
          <h1>My Profile</h1>
        </div>
        <?php if ($profileEditAllowed): ?>
          <button class="edit-profile-btn" id="openEditProfile" type="button" aria-haspopup="dialog" aria-controls="editProfileOverlay">
            <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L9 17l-4 1 1-4L16.5 3.5Z"/></svg>
            <span>Edit Profile</span>
          </button>
        <?php else: ?>
          <span class="profile-edit-locked" title="Profile editing unavailable">
            <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3"/></svg>
            Locked
          </span>
        <?php endif; ?>
      </header>
      <?php if ($profileMessage !== ''): ?>
        <div class="profile-notice <?= $profileMessageType === 'error' ? 'error' : '' ?>" role="status"><?= buyerProfileE($profileMessage) ?></div>
      <?php endif; ?>
      <section class="profile-banner" aria-label="Buyer account">
        <div class="profile-banner-content">
          <div class="profile-avatar" aria-hidden="true"><?= buyerProfileE($initials) ?></div>
          <div class="profile-banner-text">
            <div class="profile-role-label">LOKALCRAFT BUYER</div>
            <h2><?= buyerProfileE($userName) ?></h2>
            <p><?= buyerProfileE((string)$profile['email']) ?></p>
          </div>
        </div>
        <div class="profile-banner-badge">
          <span class="profile-active-dot" aria-hidden="true"></span>
          <?= $profileAccountActive ? 'Active Account' : 'Account Restricted' ?>
        </div>
      </section>
      <div class="profile-grid">
        <section class="profile-card profile-details-card" aria-labelledby="profileDetailsTitle">
          <div class="profile-card-header">
            <div class="profile-section-heading">
              <span class="profile-section-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8" cy="10" r="2"/><path d="M5 16c.6-1.3 1.6-2 3-2s2.4.7 3 2M14 9h4M14 13h4M14 17h4"/></svg>
              </span>
              <h2 id="profileDetailsTitle">Personal Details</h2>
            </div>
          </div>
          <div class="details-list">
            <div class="detail-row">
              <span class="detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="8" r="3.5"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/></svg></span>
              <div class="detail-info"><span class="detail-label">Full Name</span><div class="detail-value"><?= buyerProfileE($userName) ?></div></div>
              <?php if ($profileApproved): ?><span class="detail-locked" title="Verified name is read-only" aria-label="Name locked"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span><?php endif; ?>
            </div>
            <div class="detail-row">
              <span class="detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg></span>
              <div class="detail-info"><span class="detail-label">Email Address</span><div class="detail-value"><?= buyerProfileE($profile['email'] ?: 'Not provided') ?></div></div>
            </div>
            <div class="detail-row">
              <span class="detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.8 19.8 0 0 1 2.09 4.18 2 2 0 0 1 4.08 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.68 2.79a2 2 0 0 1-.45 2.11L8.1 9.9a16 16 0 0 0 6 6l1.28-1.21a2 2 0 0 1 2.11-.45c.89.32 1.83.55 2.79.68A2 2 0 0 1 22 16.92Z"/></svg></span>
              <div class="detail-info"><span class="detail-label">Contact Number</span><div class="detail-value"><?= buyerProfileE($profile['phone'] ?: 'Not provided') ?></div></div>
            </div>
            <div class="detail-row">
              <span class="detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="7" r="3.5"/><path d="M5 21v-3a7 7 0 0 1 14 0v3M9 16h6"/></svg></span>
              <div class="detail-info"><span class="detail-label">Gender</span><div class="detail-value"><?= $profile['gender'] ? buyerProfileE(ucfirst((string)$profile['gender'])) : 'Not set' ?></div></div>
              <?php if (!empty($profile['gender'])): ?><span class="detail-locked" title="Gender saved · Locked" aria-label="Gender locked"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span><?php endif; ?>
            </div>
            <div class="detail-row">
              <span class="detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 11h18M8 15h3m-3 3h3"/></svg></span>
              <div class="detail-info"><span class="detail-label">Birthday</span><div class="detail-value"><?php $shownBirthday = trim((string)($profile['birthday'] ?? '')); echo $shownBirthday !== '' && buyerProfileValidBirthday($shownBirthday) ? buyerProfileE(date('F j, Y', strtotime($shownBirthday))) : 'Not set'; ?></div></div>
              <?php if (!empty($profile['birthday'])): ?><span class="detail-locked" title="Birthday saved · Locked" aria-label="Birthday locked"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span><?php endif; ?>
            </div>
          </div>
        </section>
        <aside class="profile-card profile-seller-card" aria-labelledby="sellerAccessTitle">
          <div class="profile-card-header">
            <div class="profile-section-heading">
              <span class="profile-section-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10h18l-1.5-6h-15L3 10ZM5 10v10h14V10M9 20v-6h6v6"/><path d="M3 10c0 2 3 2 4.5 0 1.5 2 3 2 4.5 0 1.5 2 3 2 4.5 0 1.5 2 4.5 2 4.5 0"/></svg></span>
              <h2 id="sellerAccessTitle">Seller Access</h2>
            </div>
            <span class="status-pill <?= in_array($sellerApplicationStatus, ['approved', 'pending', 'rejected'], true) ? buyerProfileE($sellerApplicationStatus) : 'none' ?>">
              <?= $sellerApplicationStatus === 'approved' ? 'Approved' : ($sellerApplicationStatus === 'pending' ? 'Pending' : ($sellerApplicationStatus === 'rejected' ? 'Rejected' : 'Not Applied')) ?>
            </span>
          </div>
          <div class="seller-card-body">
            <?php if ($sellerBusinessName !== '' && $sellerApplicationStatus !== 'none'): ?>
              <div class="business-name"><span>BUSINESS NAME</span><strong><?= buyerProfileE($sellerBusinessName) ?></strong></div>
            <?php elseif ($sellerApplicationStatus === 'none'): ?>
              <div class="seller-empty-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10h18l-2-6H5l-2 6ZM5 10v10h14V10M9 20v-7h6v7"/></svg></div>
              <div class="seller-empty-title">Start selling on LokalCraft</div>
            <?php endif; ?>
            <?php if ($sellerApplicationStatus === 'approved'): ?>
              <?php if ($sellerReviewedAt !== '' && strtotime($sellerReviewedAt) !== false): ?>
                <div class="seller-status-date">Approved <?= buyerProfileE(date('M j, Y', strtotime($sellerReviewedAt))) ?></div>
              <?php endif; ?>
              <?php if (!$sellerAccessReady): ?><div class="seller-inline-alert">Seller access is not active.</div><?php endif; ?>
            <?php elseif ($sellerApplicationStatus === 'pending'): ?>
              <a class="seller-action secondary" href="seller_application.php">View Application Status<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg></a>
            <?php elseif ($sellerApplicationStatus === 'rejected'): ?>
              <?php if ($sellerApplicationReason !== ''): ?><div class="seller-reason"><span>REJECTION REASON</span><?= nl2br(buyerProfileE($sellerApplicationReason)) ?></div><?php endif; ?>
              <a class="seller-action" href="seller_application.php">Review Application<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg></a>
            <?php else: ?>
              <a class="seller-action" href="seller_application.php">Become a Seller<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg></a>
            <?php endif; ?>
          </div>
        </aside>
      </div>
    </div>
  </main>
</div>
<div class="edit-profile-overlay<?= $openEditModal && $profileEditAllowed ? ' open' : '' ?>" id="editProfileOverlay" aria-hidden="<?= $openEditModal && $profileEditAllowed ? 'false' : 'true' ?>">
  <section class="edit-profile-modal" role="dialog" aria-modal="true" aria-labelledby="editProfileTitle">
    <div class="edit-profile-head">
      <div>
        <h2 id="editProfileTitle">Edit Profile</h2>
      </div>
      <button class="edit-profile-close" id="closeEditProfile" type="button" aria-label="Close edit profile">×</button>
    </div>
    <form class="edit-profile-form" id="buyerEditForm" method="POST" action="<?= buyerProfileE(buyerProfilePage()) ?>" novalidate>
      <input type="hidden" name="action" value="update_profile">
      <input type="hidden" name="csrf_token" value="<?= buyerProfileE($_SESSION['buyer_profile_csrf']) ?>">
      <div class="edit-profile-grid">
        <div class="edit-profile-field">
          <label for="profileFirstName">First Name</label>
          <input id="profileFirstName" name="firstname" type="text" maxlength="50" autocomplete="given-name" value="<?= buyerProfileE($editValues['firstname'] ?? $profile['firstname']) ?>" <?= $nameEditingAllowed ? 'required' : 'readonly' ?>>
        </div>
        <div class="edit-profile-field">
          <label for="profileMiddleName">Middle Name <span class="profile-optional">(optional)</span></label>
          <input id="profileMiddleName" name="middlename" type="text" maxlength="50" autocomplete="additional-name" value="<?= buyerProfileE($editValues['middlename'] ?? $profile['middlename']) ?>" <?= $nameEditingAllowed ? '' : 'readonly' ?>>
        </div>
        <div class="edit-profile-field">
          <label for="profileLastName">Last Name</label>
          <input id="profileLastName" name="lastname" type="text" maxlength="50" autocomplete="family-name" value="<?= buyerProfileE($editValues['lastname'] ?? $profile['lastname']) ?>" <?= $nameEditingAllowed ? 'required' : 'readonly' ?>>
        </div>
        <div class="edit-profile-field">
          <label for="profilePhone">Phone Number</label>
          <input id="profilePhone" name="phone" type="tel" maxlength="20" autocomplete="tel" inputmode="numeric" placeholder="09XXXXXXXXX" value="<?= buyerProfileE($editValues['phone'] ?? $profile['phone']) ?>" <?= $profileEditAllowed ? 'required' : 'readonly' ?>>
        </div>
        <?php if ($profileApproved): ?>
        <div class="edit-profile-lock-note"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg> Verified name · Read-only</div>
        <?php endif; ?>
        <div class="edit-profile-field profile-one-time-field">
          <div class="profile-field-heading">
            <label for="profileGender">Gender</label>
            <details class="profile-one-time-tip"><summary aria-label="Gender information">?</summary><span class="profile-tip-text">You can set your gender once. After saving, it cannot be changed.</span></details>
          </div>
          <?php if (empty($profile['gender'])): ?>
            <select id="profileGender" name="gender">
              <option value="">Select gender</option>
              <?php foreach (['male'=>'Male','female'=>'Female','prefer not to say'=>'Prefer not to say'] as $genderValue=>$genderLabel): ?>
                <option value="<?= buyerProfileE($genderValue) ?>" <?= ($editValues['gender'] ?? '') === $genderValue ? 'selected' : '' ?>><?= buyerProfileE($genderLabel) ?></option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <input name="gender" type="hidden" value="<?= buyerProfileE($profile['gender']) ?>">
            <div class="profile-fixed-value" id="profileGender" aria-label="Gender locked"><?= buyerProfileE(ucfirst((string)$profile['gender'])) ?><span aria-hidden="true">🔒</span></div>
          <?php endif; ?>
        </div>
        <div class="edit-profile-field profile-one-time-field profile-birthday-field">
          <div class="profile-field-heading">
            <label for="profileBirthdayButton">Birthday</label>
            <details class="profile-one-time-tip"><summary aria-label="Birthday information">?</summary><span class="profile-tip-text">Choose your correct birthday. You can save it only once.</span></details>
          </div>
          <input id="profileBirthday" name="birthday" type="hidden" value="<?= buyerProfileE($editValues['birthday'] ?? '') ?>">
          <?php if (empty($profile['birthday'])): ?>
            <button class="profile-date-trigger" id="profileBirthdayButton" type="button" aria-haspopup="dialog" aria-expanded="false" aria-controls="profileBirthCalendar">
              <span id="profileBirthdayLabel">Select birthday</span><svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 11h18"/></svg>
            </button>
            <div class="profile-birth-calendar" id="profileBirthCalendar" role="dialog" aria-label="Choose birthday" hidden>
              <div class="birth-calendar-header">
                <button type="button" id="birthPreviousMonth" aria-label="Previous month">‹</button>
                <select id="birthMonthSelect" aria-label="Month"></select>
                <select id="birthYearSelect" aria-label="Year"></select>
                <button type="button" id="birthNextMonth" aria-label="Next month">›</button>
              </div>
              <div class="birth-weekdays" aria-hidden="true"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div>
              <div class="birth-calendar-days" id="birthCalendarDays"></div>
              <div class="birth-calendar-footer"><button type="button" id="birthCalendarToday">Today</button><button type="button" id="birthCalendarClear">Clear</button></div>
            </div>
          <?php else: ?>
            <div class="profile-fixed-value" id="profileBirthdayButton" aria-label="Birthday locked"><?= buyerProfileE(date('F j, Y', strtotime((string)$profile['birthday']))) ?><span aria-hidden="true">🔒</span></div>
          <?php endif; ?>
        </div>
        <div class="edit-profile-field full">
          <label for="profileEmail">Email Address</label>
          <input id="profileEmail" name="email" type="email" maxlength="100" autocomplete="email" autocapitalize="none" spellcheck="false" value="<?= buyerProfileE($editValues['email'] ?? $profile['email']) ?>" <?= $profileEditAllowed ? 'required' : 'readonly' ?>>
          <div class="profile-field-feedback" id="profileEmailFeedback" aria-live="polite"></div>
        </div>
      </div>
      <div id="profileFormError" class="profile-inline-error" role="alert" hidden></div>
      <div class="edit-profile-actions">
        <button class="edit-profile-cancel" id="cancelEditProfile" type="button">Cancel</button>
        <button class="edit-profile-save" id="profileSaveButton" type="submit" <?= $profileEditAllowed ? '' : 'disabled' ?>>Save Changes</button>
      </div>
    </form>
  </section>
</div>
<div class="profile-otp-overlay" id="profileOtpOverlay" aria-hidden="true">
  <section class="profile-otp-card" role="dialog" aria-modal="true" aria-labelledby="profileOtpTitle">
    <div class="profile-otp-head">
      <span class="profile-otp-icon" aria-hidden="true">✉</span>
      <button class="edit-profile-close" type="button" id="profileOtpClose" aria-label="Close email verification">×</button>
    </div>
    <h2 id="profileOtpTitle">Verify your email</h2>
    <p class="profile-otp-sub">Enter the 6-digit code we sent to:<strong id="profileOtpEmail"></strong></p>
    <label class="profile-otp-label" for="profileOtpDigit1">Verification Code</label>
    <input id="profileOtpCode" type="hidden" value="">
    <div class="profile-otp-digits" id="profileOtpDigits" role="group" aria-label="Six-digit email verification code">
      <?php for ($digit = 1; $digit <= 6; $digit++): ?>
      <input class="profile-otp-digit" id="profileOtpDigit<?= $digit ?>" data-otp-index="<?= $digit - 1 ?>" type="text" inputmode="numeric" maxlength="6" <?= $digit === 1 ? 'autocomplete="one-time-code"' : 'autocomplete="off"' ?> aria-label="Digit <?= $digit ?> of 6" aria-describedby="profileOtpMessage">
      <?php endfor; ?>
    </div>
    <div class="profile-otp-message" id="profileOtpMessage" role="status" aria-live="polite"></div>
    <button class="edit-profile-save profile-otp-verify" id="profileOtpVerify" type="button" disabled>Verify &amp; Save Changes</button>
    <div class="profile-otp-footer">
      <button type="button" id="profileOtpResend" class="profile-otp-link" disabled>Resend code</button>
      <button type="button" id="profileOtpCancel" class="profile-otp-link">Change email</button>
    </div>
  </section>
</div>
<script>
(function () {
  'use strict';

  // Calendar avoids the native date-input picker and does not need third-party JS.
  const birthdayInput = document.getElementById('profileBirthday');
  const birthdayButton = document.getElementById('profileBirthdayButton');
  const birthCalendar = document.getElementById('profileBirthCalendar');
  let closeCalendarFromHistory = null;
  let openCalendarFromHistory = null;
  let ignoreNextEditCloseClick = false;
  if (birthdayInput && birthCalendar && birthdayButton) {
    const monthSelect = document.getElementById('birthMonthSelect');
    const yearSelect = document.getElementById('birthYearSelect');
    const daysArea = document.getElementById('birthCalendarDays');
    // The server validates against Asia/Manila; use the same date in every browser/time zone.
    const todayIso = <?= json_encode((new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const [todayYear, todayMonth] = todayIso.split('-').map(Number);
    const firstExisting = /^\d{4}-\d{2}-\d{2}$/.test(birthdayInput.value) ? birthdayInput.value.split('-').map(Number) : null;
    let displayYear = firstExisting ? firstExisting[0] : todayYear;
    let displayMonth = firstExisting ? firstExisting[1] : todayMonth;
    const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    months.forEach((name,idx)=>{const item=document.createElement('option');item.value=String(idx+1);item.textContent=name;monthSelect.appendChild(item);});
    for (let year=todayYear;year>=1900;year--){const item=document.createElement('option');item.value=String(year);item.textContent=String(year);yearSelect.appendChild(item);}
    function selectedIso() {return birthdayInput.value;}
    function labelBirthday() {
      if (selectedIso()) { const [y,m,d]=selectedIso().split('-').map(Number); document.getElementById('profileBirthdayLabel').textContent = `${months[m-1]} ${d}, ${y}`; }
      else document.getElementById('profileBirthdayLabel').textContent = 'Select birthday';
    }
    function renderCalendar() {
      displayYear=Math.max(1900,Math.min(todayYear,displayYear));
      displayMonth=Math.max(1,Math.min(displayYear===todayYear?todayMonth:12,displayMonth));
      monthSelect.value=String(displayMonth);yearSelect.value=String(displayYear);
      daysArea.replaceChildren();
      const offset=new Date(displayYear,displayMonth-1,1).getDay();
      const count=new Date(displayYear,displayMonth,0).getDate();
      for(let i=0;i<offset;i++){const blank=document.createElement('span');daysArea.appendChild(blank);}
      for(let day=1;day<=count;day++){
        const iso=`${displayYear}-${String(displayMonth).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
        const btn=document.createElement('button');btn.type='button';btn.textContent=String(day);btn.setAttribute('aria-label',`${months[displayMonth-1]} ${day}, ${displayYear}`);
        btn.setAttribute('aria-pressed',String(iso===selectedIso()));
        btn.disabled=iso>todayIso;
        btn.addEventListener('click',()=>{birthdayInput.value=iso;labelBirthday();closeCalendar();birthdayButton.focus({preventScroll:true});});
        daysArea.appendChild(btn);
      }
      document.getElementById('birthPreviousMonth').disabled=displayYear===1900&&displayMonth===1;
      document.getElementById('birthNextMonth').disabled=displayYear===todayYear&&displayMonth>=todayMonth;
      if (!birthCalendar.hidden) positionCalendar();
    }
    // Place the picker above the scrollable form so opening it never shifts or clips the fields.
    document.body.appendChild(birthCalendar);
    const editModal = document.querySelector('.edit-profile-modal');
    function closeCalendar(fromHistory = false) {
      if (birthCalendar.hidden) return;
      birthCalendar.hidden = true;
      birthdayButton.setAttribute('aria-expanded', 'false');
      if (!fromHistory) profileHistoryBack('calendar');
    }
    closeCalendarFromHistory = () => closeCalendar(true);
    function positionCalendar() {
      if (birthCalendar.hidden) return;
      const trigger = birthdayButton.getBoundingClientRect();
      const viewportWidth = window.visualViewport?.width || window.innerWidth;
      const viewportHeight = window.visualViewport?.height || window.innerHeight;
      const viewportLeft = window.visualViewport?.offsetLeft || 0;
      const viewportTop = window.visualViewport?.offsetTop || 0;
      const edge = 12;
      const gap = 8;
      const width = Math.min(360, Math.max(0, viewportWidth - edge * 2));
      birthCalendar.style.width = `${width}px`;
      birthCalendar.style.maxHeight = `${Math.max(90, viewportHeight - edge * 2)}px`;
      const popupHeight = birthCalendar.offsetHeight;
      const below = viewportTop + viewportHeight - trigger.bottom - gap - edge;
      const above = trigger.top - viewportTop - gap - edge;
      const placeAbove = below < popupHeight && above > below;
      const space = Math.max(90, placeAbove ? above : below);
      birthCalendar.style.maxHeight = `${Math.min(viewportHeight - edge * 2, space)}px`;
      const shownHeight = birthCalendar.offsetHeight;
      const x = Math.max(viewportLeft + edge, Math.min(trigger.left, viewportLeft + viewportWidth - width - edge));
      const y = placeAbove ? trigger.top - gap - shownHeight : trigger.bottom + gap;
      birthCalendar.style.left = `${Math.round(x)}px`;
      birthCalendar.style.top = `${Math.round(Math.max(viewportTop + edge, Math.min(y, viewportTop + viewportHeight - shownHeight - edge)))}px`;
    }
    function openCalendar(fromHistory = false) {
      if (!birthCalendar.hidden) return;
      renderCalendar();
      birthCalendar.hidden = false;
      birthdayButton.setAttribute('aria-expanded', 'true');
      positionCalendar();
      if (!fromHistory) profileHistoryPush('calendar');
    }
    openCalendarFromHistory = () => openCalendar(true);
    birthdayButton.addEventListener('click', () => {
      if (!birthCalendar.hidden) { closeCalendar(); return; }
      openCalendar();
    });
    editModal?.addEventListener('scroll', () => {
      if (birthCalendar.hidden) return;
      const modalRect = editModal.getBoundingClientRect();
      const triggerRect = birthdayButton.getBoundingClientRect();
      if (triggerRect.bottom <= modalRect.top || triggerRect.top >= modalRect.bottom) closeCalendar();
      else positionCalendar();
    }, {passive:true});
    window.addEventListener('resize', positionCalendar);
    window.visualViewport?.addEventListener('resize', positionCalendar);
    window.visualViewport?.addEventListener('scroll', positionCalendar);
    monthSelect.addEventListener('change',()=>{displayMonth=Number(monthSelect.value);renderCalendar();});
    yearSelect.addEventListener('change',()=>{displayYear=Number(yearSelect.value);renderCalendar();});
    document.getElementById('birthPreviousMonth').addEventListener('click',()=>{if(displayMonth===1){displayMonth=12;displayYear--;}else displayMonth--;renderCalendar();});
    document.getElementById('birthNextMonth').addEventListener('click',()=>{if(displayMonth===12){displayMonth=1;displayYear++;}else displayMonth++;renderCalendar();});
    document.getElementById('birthCalendarToday').addEventListener('click',()=>{birthdayInput.value=todayIso;displayYear=todayYear;displayMonth=todayMonth;labelBirthday();closeCalendar();birthdayButton.focus({preventScroll:true});});
    document.getElementById('birthCalendarClear').addEventListener('click',()=>{birthdayInput.value='';labelBirthday();closeCalendar();birthdayButton.focus({preventScroll:true});});
    birthCalendar.addEventListener('keydown',event=>{if(event.key==='Escape'){event.stopPropagation();closeCalendar();birthdayButton.focus();}});
    document.addEventListener('pointerdown',event=>{
      if (birthCalendar.hidden || birthCalendar.contains(event.target) || event.target === birthdayButton || birthdayButton.contains(event.target)) return;
      if (event.target.closest('#closeEditProfile, #cancelEditProfile')) ignoreNextEditCloseClick = true;
      closeCalendar();
    });
    labelBirthday();renderCalendar();
  }
  // Only one tooltip should be expanded at a time (touch/keyboard friendly).
  document.querySelectorAll('.profile-one-time-tip').forEach(tip=>{
    tip.addEventListener('toggle',()=>{if(tip.open)document.querySelectorAll('.profile-one-time-tip').forEach(other=>{if(other!==tip)other.open=false;});});
  });
  const overlay = document.getElementById('editProfileOverlay');
  const otpOverlay = document.getElementById('profileOtpOverlay');
  const form = document.getElementById('buyerEditForm');
  const openButton = document.getElementById('openEditProfile');
  const closeButton = document.getElementById('closeEditProfile');
  const cancelButton = document.getElementById('cancelEditProfile');
  const saveButton = document.getElementById('profileSaveButton');
  const first = document.getElementById('profileFirstName');
  const middle = document.getElementById('profileMiddleName');
  const last = document.getElementById('profileLastName');
  const phone = document.getElementById('profilePhone');
  const email = document.getElementById('profileEmail');
  const emailFeedback = document.getElementById('profileEmailFeedback');
  const formError = document.getElementById('profileFormError');
  const initialFormError = <?= json_encode($openEditModal && $profileMessageType === 'error' ? $profileMessage : '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
  const otpCode = document.getElementById('profileOtpCode');
  const otpDigits = [...document.querySelectorAll('.profile-otp-digit')];
  const otpMessage = document.getElementById('profileOtpMessage');
  const otpEmail = document.getElementById('profileOtpEmail');
  const verifyButton = document.getElementById('profileOtpVerify');
  const resendButton = document.getElementById('profileOtpResend');
  const currentEmail = <?= json_encode(strtolower(trim((string)$profile['email'])), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const csrf = <?= json_encode((string)$_SESSION['buyer_profile_csrf'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const editAllowed = <?= $profileEditAllowed ? 'true' : 'false' ?>;
  const canEditName = <?= $nameEditingAllowed ? 'true' : 'false' ?>;
  const url = <?= json_encode(buyerProfilePage(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  let otpBusy = false;
  let saveBusy = false;
  let cooldownTimer = null;
  let cooldownRemaining = 0;
  let emailTimer = null;
  let emailCheckSerial = 0;
  // Native Android/Safari back gestures and desktop browser Back should unwind the
  // visible popup first (calendar > OTP > Edit Profile), without leaving this page.
  const profileHistoryProperty = '__lokalcraftBuyerProfilePopup';
  const profileHistoryId = `buyer-profile-${Date.now()}-${Math.random().toString(36).slice(2)}`;
  let profileHistoryEnabled = false;
  function profileHistoryState(layer) {
    const oldState = history.state && typeof history.state === 'object' && !Array.isArray(history.state)
      ? history.state : {};
    return {...oldState, [profileHistoryProperty]: {id:profileHistoryId, layer}};
  }
  function profileHistoryCurrent() {
    const state = history.state?.[profileHistoryProperty];
    return state?.id === profileHistoryId ? state.layer : null;
  }
  function profileHistoryPush(layer) {
    if (!profileHistoryEnabled || profileHistoryCurrent() === layer) return;
    try { history.pushState(profileHistoryState(layer), '', window.location.href); }
    catch (error) { profileHistoryEnabled = false; }
  }
  function profileHistoryBack(layer) {
    if (!profileHistoryEnabled || profileHistoryCurrent() !== layer) return;
    history.back();
  }
  function profileHistoryRestoreLayer(layer) {
    // A forward into a cancelled OTP must not resurrect an invalid verification.
    if (layer === 'otp') {
      if (!otpOverlay.classList.contains('open')) {
        openEditProfile(true);
        try { history.replaceState(profileHistoryState('edit'), '', window.location.href); } catch (error) {}
      }
      return;
    }
    if (otpOverlay.classList.contains('open')) {
      if (otpBusy) { profileHistoryPush('otp'); return; }
      void closeOtp(true);
    }
    if (layer === 'calendar' && closeCalendarFromHistory && openCalendarFromHistory) {
      if (!overlay.classList.contains('open')) openEditProfile(true);
      openCalendarFromHistory();
      return;
    }
    closeCalendarFromHistory?.();
    if (layer === 'edit') {
      if (!overlay.classList.contains('open')) openEditProfile(true);
    } else if (overlay.classList.contains('open')) {
      if (saveBusy) { profileHistoryPush('edit'); return; }
      closeEditProfile(true);
    }
  }
  try {
    history.replaceState(profileHistoryState('page'), '', window.location.href);
    profileHistoryEnabled = true;
  } catch (error) {
    // Older/private browsers that disallow pushState still support X/Escape.
    profileHistoryEnabled = false;
  }
  window.addEventListener('popstate', event => {
    const state = event.state?.[profileHistoryProperty];
    profileHistoryRestoreLayer(state?.id === profileHistoryId ? state.layer : 'page');
  });
  function waitForCalendarHistory() {
    if (profileHistoryCurrent() !== 'calendar') return Promise.resolve();
    return new Promise(resolve => {
      let timer = null;
      const finished = () => {clearTimeout(timer);window.removeEventListener('popstate',finished);resolve();};
      window.addEventListener('popstate',finished,{once:true});
      timer = setTimeout(finished,500);
    });
  }
  function setScrollLock() { document.body.classList.toggle('profile-modal-open', overlay.classList.contains('open') || otpOverlay.classList.contains('open')); }
  function openEditProfile(fromHistory = false) {
    if (!editAllowed) return;
    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden', 'false');
    setScrollLock();
    if (!fromHistory) profileHistoryPush('edit');
    (canEditName ? first : phone).focus({preventScroll:true});
  }
  function closeEditProfile(fromHistory = false) {
    if (!fromHistory && ignoreNextEditCloseClick) {ignoreNextEditCloseClick = false;return;}
    if (saveBusy || otpBusy || otpOverlay.classList.contains('open')) return;
    // Clicking the underlying Edit close while a calendar is dismissing must
    // never pop two layers in one tap.
    if (!fromHistory && profileHistoryCurrent() === 'calendar') {
      closeCalendarFromHistory?.();
      profileHistoryBack('calendar');
      return;
    }
    closeCalendarFromHistory?.();
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden','true');
    setScrollLock();
    if (!fromHistory) profileHistoryBack('edit');
    openButton?.focus({preventScroll:true});
  }
  function setError(message) { formError.textContent = message || ''; formError.hidden = !message; }
  if (initialFormError) setError(initialFormError);
  function setEmailFeedback(message, type) {
    emailFeedback.textContent = message || '';
    emailFeedback.className = 'profile-field-feedback' + (type ? ' ' + type : '');
  }
  async function request(action, values = {}) {
    const body = new URLSearchParams({...values, action, csrf_token:csrf});
    const result = await fetch(url, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body:body.toString(),cache:'no-store'});
    const data = await result.json();
    if (!data || typeof data !== 'object') throw Error('Unexpected response. Try again.');
    return data;
  }
  const validName = (value, optional, apostrophe) => {
    const text = value.trim().replace(/\s+/gu,' ');
    if (!text) return optional;
    return Array.from(text).length <= 50 && (apostrophe ? /^\p{L}+(?:[ '-]\p{L}+)*$/u : /^\p{L}+(?:[ -]\p{L}+)*$/u).test(text);
  };
  function normalizePhone(value) {
    let digits = value.replace(/\D/g,'');
    if (digits.startsWith('63')) digits = '0' + digits.slice(2);
    else if (digits.startsWith('9')) digits = '0' + digits;
    return digits.slice(0,11);
  }
  function normalizeNameInput(input, apostrophe) {
    const old = input.value;
    const clean = old.replace(apostrophe ? /[^\p{L} '\-]/gu : /[^\p{L} \-]/gu, '').slice(0,50);
    if (clean !== old) input.value = clean;
  }
  [[first,false],[middle,true],[last,false]].forEach(([element,allowApostrophe]) => {
    element.addEventListener('input',()=>normalizeNameInput(element,allowApostrophe));
    element.addEventListener('blur',()=>{ element.value = element.value.trim().replace(/\s+/gu,' '); });
  });
  phone.addEventListener('input',()=>{phone.value=normalizePhone(phone.value);});
  function checkEmailLocally() {
    const value = email.value.trim().toLowerCase();
    if (!value) {setEmailFeedback('','');return false;}
    const valid = value.length<=100 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) && !['g.mail.com','gmail.con','yahoo.con'].includes(value.split('@').pop());
    if (!valid) setEmailFeedback('Enter a valid email address.','error');
    else if (value === currentEmail) setEmailFeedback('','');
    return valid;
  }
  async function checkEmailAvailable() {
    const stamp = ++emailCheckSerial;
    const value = email.value.trim().toLowerCase();
    if (!checkEmailLocally() || value===currentEmail) return;
    setEmailFeedback('Checking email...','info');
    try {
      const result = await request('profile_email_check',{email:value});
      if (stamp!==emailCheckSerial || email.value.trim().toLowerCase()!==value) return;
      setEmailFeedback(result.success ? 'Email is available.' : (result.message || 'Email unavailable.'), result.success?'success':'error');
    } catch (error) {
      if (stamp===emailCheckSerial) setEmailFeedback('Unable to check email right now.','error');
    }
  }
  email.addEventListener('input',()=>{
    emailCheckSerial++;
    clearTimeout(emailTimer);
    if (!checkEmailLocally()) return;
    setEmailFeedback('','');
    emailTimer=setTimeout(checkEmailAvailable,550);
  });
  email.addEventListener('blur',()=>{ email.value=email.value.trim().toLowerCase(); clearTimeout(emailTimer); checkEmailAvailable(); });
  function validateInputs() {
    setError('');
    phone.value = normalizePhone(phone.value);
    const checks = [
      ...(canEditName ? [
        [first,validName(first.value,false,false),'Enter a valid first name (maximum 50 characters).'],
        [middle,validName(middle.value,true,true),'Enter a valid middle name (maximum 50 characters).'],
        [last,validName(last.value,false,false),'Enter a valid last name (maximum 50 characters).']
      ] : []),
      [phone,/^09\d{9}$/.test(phone.value),'Enter an 11-digit mobile number starting with 09.'],
      [email,checkEmailLocally(),'Enter a valid email address.']
    ];
    for (const [field, valid, message] of checks) {
      if (!valid) {setError(message);field.focus();return false;}
    }
    return true;
  }
  function setSaveBusy(busy) {
    saveBusy = busy;
    saveButton.disabled = busy;
    saveButton.textContent = busy ? 'Sending code...' : 'Save Changes';
  }
  function updateCooldown() {
    resendButton.disabled = cooldownRemaining > 0 || otpBusy;
    resendButton.textContent = cooldownRemaining > 0 ? `Resend code (${cooldownRemaining}s)` : 'Resend code';
  }
  function startCooldown(seconds) {
    clearInterval(cooldownTimer);
    cooldownRemaining = Math.max(0, Number(seconds) || 0);
    updateCooldown();
    if (cooldownRemaining > 0) {
      cooldownTimer = setInterval(() => {
        cooldownRemaining = Math.max(0, cooldownRemaining - 1);
        updateCooldown();
        if (!cooldownRemaining) clearInterval(cooldownTimer);
      }, 1000);
    }
  }
  function setOtpMessage(message, kind) {
    otpMessage.textContent=message || '';
    otpMessage.className='profile-otp-message' + (kind?' '+kind:'');
  }
  function syncOtpDigits() {
    otpCode.value = otpDigits.map(input => input.value).join('');
    otpDigits.forEach(input => input.classList.toggle('filled', input.value.length === 1));
    toggleVerify();
  }
  function fillOtpDigits(text, startIndex = 0) {
    const characters = String(text).replace(/\D/g, '').slice(0, 6 - startIndex).split('');
    characters.forEach((character, index) => {otpDigits[startIndex + index].value = character;});
    syncOtpDigits();
    const nextIndex = Math.min(5, startIndex + characters.length);
    otpDigits[nextIndex].focus({preventScroll:true});
  }
  function toggleVerify() {verifyButton.disabled=otpBusy || !/^\d{6}$/.test(otpCode.value);}
  function openOtp(masked, seconds) {
    closeCalendarFromHistory?.();
    otpDigits.forEach(input=>{input.value='';input.classList.remove('filled');});
    otpCode.value='';setOtpMessage('','');otpEmail.textContent=masked||email.value.trim();
    otpOverlay.classList.add('open');otpOverlay.setAttribute('aria-hidden','false');
    overlay.setAttribute('aria-hidden','true');
    startCooldown(seconds);
    toggleVerify();setScrollLock();profileHistoryPush('otp');otpDigits[0].focus({preventScroll:true});
  }
  let otpClosing = false;
  async function closeOtp(fromHistory = false) {
    if (otpBusy || otpClosing || !otpOverlay.classList.contains('open')) return;
    otpClosing = true;
    // Dismiss first; don't keep a popup on-screen while waiting on the server.
    otpOverlay.classList.remove('open');otpOverlay.setAttribute('aria-hidden','true');
    overlay.setAttribute('aria-hidden','false');
    clearInterval(cooldownTimer);
    cooldownRemaining = 0;
    updateCooldown();
    setScrollLock();
    if (!fromHistory) profileHistoryBack('otp');
    try { await request('profile_email_cancel'); } catch (error) {}
    finally {
      otpClosing = false;
      if (overlay.classList.contains('open') && !otpOverlay.classList.contains('open')) email.focus({preventScroll:true});
    }
  }
  otpDigits.forEach((input, index)=>{
    input.addEventListener('input',()=>{
      const numbers=input.value.replace(/\D/g,'');
      input.value='';
      if(numbers) fillOtpDigits(numbers,index);
      else syncOtpDigits();
      setOtpMessage('','');
    });
    input.addEventListener('paste',event=>{
      event.preventDefault();
      fillOtpDigits((event.clipboardData?.getData('text') || ''),index);
      setOtpMessage('','');
    });
    input.addEventListener('keydown',event=>{
      if(event.key==='Backspace'){
        event.preventDefault();
        if(input.value) input.value='';
        else if(index>0){otpDigits[index-1].value='';otpDigits[index-1].focus({preventScroll:true});}
        syncOtpDigits();setOtpMessage('','');
      } else if(event.key==='ArrowLeft' && index>0){event.preventDefault();otpDigits[index-1].focus({preventScroll:true});}
      else if(event.key==='ArrowRight' && index<5){event.preventDefault();otpDigits[index+1].focus({preventScroll:true});}
      else if(event.key==='Enter'){
        event.preventDefault();
        if(!verifyButton.disabled) verifyButton.click();
      }
    });
    input.addEventListener('focus',()=>input.select());
  });
  form.addEventListener('submit',async event=>{
    if (!editAllowed || saveBusy || otpClosing) {event.preventDefault();return;}
    if (!validateInputs()) {event.preventDefault();return;}
    if (email.value.trim().toLowerCase()===currentEmail) {
      saveButton.disabled=true;
      saveButton.textContent='Saving...';
      return;
    }
    event.preventDefault();
    setSaveBusy(true);
    try {
      await waitForCalendarHistory();
      const values=Object.fromEntries(new FormData(form).entries());
      const result=await request('profile_email_start',values);
      if (!result.success) {setError(result.message||'Could not send a verification code.');return;}
      openOtp(result.masked_email,result.resend_seconds);
    }catch(error){setError('Unable to send the code right now. Please try again.');}
    finally{setSaveBusy(false);}
  });
  verifyButton.addEventListener('click',async()=>{
    if (verifyButton.disabled || otpBusy) return;
    otpBusy=true;toggleVerify();verifyButton.textContent='Verifying...';
    try {
      const result=await request('profile_email_verify',{otp:otpCode.value});
      if (!result.success) {
        setOtpMessage(result.message||'Invalid code.','error');
        otpDigits.forEach(input=>{input.value='';input.classList.remove('filled');});
        otpCode.value='';
        otpDigits[0].focus({preventScroll:true});
        return;
      }
      window.location.assign(result.redirect||url);
    }catch(error){setOtpMessage('Unable to verify the code right now. Try again.','error');}
    finally{otpBusy=false;verifyButton.textContent='Verify & Save Changes';toggleVerify();updateCooldown();}
  });
  resendButton.addEventListener('click',async()=>{
    if (resendButton.disabled || otpBusy) return;
    otpBusy=true;resendButton.disabled=true;
    try {
      const result=await request('profile_email_resend');
      setOtpMessage(result.message||(result.success?'New verification code sent.':'Unable to resend code.'),result.success?'success':'error');
      startCooldown(result.resend_seconds||0);
    }catch(error){setOtpMessage('Unable to resend the code right now.','error');}
    finally{otpBusy=false;updateCooldown();}
  });
  document.getElementById('profileOtpClose').addEventListener('click',()=>{void closeOtp();});
  document.getElementById('profileOtpCancel').addEventListener('click',()=>{void closeOtp();});
  openButton?.addEventListener('click',()=>openEditProfile());
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    if (otpOverlay.classList.contains('open')) {
      event.preventDefault();
      void closeOtp();
    } else if (overlay.classList.contains('open')) {
      if (birthCalendar && !birthCalendar.hidden) {
        closeCalendarFromHistory?.();
        profileHistoryBack('calendar');
        birthdayButton?.focus({preventScroll:true});
      } else {
        closeEditProfile();
      }
    }
  });
  closeButton.addEventListener('click',()=>closeEditProfile());
  cancelButton.addEventListener('click',()=>closeEditProfile());
  // Restore transient button state after back/forward cache navigation (Safari/Chromium).
  window.addEventListener('pageshow', event => {
    if (!event.persisted) return;
    profileHistoryRestoreLayer(profileHistoryCurrent() || 'page');
    saveBusy = false;
    saveButton.disabled = !editAllowed;
    saveButton.textContent = 'Save Changes';
    if (verifyButton) {
      otpBusy = false;
      verifyButton.textContent = 'Verify & Save Changes';
      toggleVerify();
      updateCooldown();
    }
  });
  if (overlay.classList.contains('open')) {
    setScrollLock();
    profileHistoryPush('edit');
  }
})();
</script>
</body>
</html>
