<?php
require_once __DIR__ . '/seller_auth.php';
require_once __DIR__ . '/../includes/otp_service.php';
date_default_timezone_set('Asia/Manila');
header('Cache-Control: private, no-store, max-age=0');
$activePage = 'profile';
$sellerId = (int)$sellerId;
if (empty($_SESSION['seller_profile_csrf'])) $_SESSION['seller_profile_csrf'] = bin2hex(random_bytes(32));
function spE($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function spLength(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : (preg_match_all('/./us', $value) ?: 0); }
function spPhone(string $value): string {
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (str_starts_with($digits, '63')) return '0' . substr($digits, 2);
    if (str_starts_with($digits, '9')) return '0' . $digits;
    return $digits;
}
function spValidEmail(string $email): bool {
    if ($email === '' || strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $domain = strtolower((string)substr(strrchr($email, '@') ?: '', 1));
    return !in_array($domain, ['g.mail.com', 'gmail.con', 'yahoo.con'], true);
}
function spBarangays(): array {
    // Matches the accepted Taguig barangays in Become a Seller. Keep postal mappings consistent there.
    return [
        'Bagumbayan'=>'1630','Bambang'=>'1637','Calzada'=>'1630','Central Bicutan'=>'1637',
        'Central Signal Village'=>'1637','Fort Bonifacio'=>'1637','Hagonoy'=>'1630',
        'Ibayo-Tipas'=>'1630','Katuparan'=>'1637','Ligid-Tipas'=>'1638','Lower Bicutan'=>'1632',
        'Maharlika Village'=>'1630','Napindan'=>'1630','New Lower Bicutan'=>'1632',
        'North Daang Hari'=>'1637','North Signal Village'=>'1637','Palingon'=>'1630',
        'Pinagsama'=>'1630','San Miguel'=>'1637','Santa Ana'=>'1630','South Daang Hari'=>'1637',
        'South Signal Village'=>'1637','Tanyag'=>'1637','Tuktukan'=>'1637',
        'Upper Bicutan'=>'1633','Ususan'=>'1632','Wawa'=>'1630','Western Bicutan'=>'1630',
        'Cembo'=>'1640','Comembo'=>'1641','East Rembo'=>'1643','Pembo'=>'1642',
        'Pitogo'=>'1646','Post Proper Northside'=>'1647','Post Proper Southside'=>'1648',
        'Rizal'=>'1649','South Cembo'=>'1645','West Rembo'=>'1644'
    ];
}
function spCategories(mysqli $conn): array {
    $result = $conn->query('SELECT category_name FROM craft_categories ORDER BY category_id ASC');
    if (!$result) throw new RuntimeException('Unable to load the craft categories.');
    $names = [];
    try {
        while ($row = $result->fetch_assoc()) {
            $name = trim((string)($row['category_name'] ?? ''));
            if ($name !== '' && !str_contains($name, ',')) $names[] = $name;
        }
    } finally { $result->free(); }
    return array_values(array_unique($names));
}
function spUser(mysqli $conn, int $sellerId, bool $lock = false): array {
    $stmt = $conn->prepare('SELECT firstname, middlename, lastname, email, phone, gender, birthday, SellerOrNot, account_status FROM users WHERE id = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
    if (!$stmt) throw new RuntimeException('Unable to access the seller account.');
    try {
        $stmt->bind_param('i', $sellerId);
        if (!$stmt->execute()) throw new RuntimeException('Unable to access the seller account.');
        $stmt->bind_result($first, $middle, $last, $email, $phone, $gender, $birthday, $seller, $status);
        if (!$stmt->fetch()) throw new RuntimeException('Seller account not found.');
        return ['firstname'=>$first, 'middlename'=>$middle, 'lastname'=>$last, 'email'=>$email, 'phone'=>$phone, 'gender'=>$gender, 'birthday'=>$birthday, 'SellerOrNot'=>$seller, 'account_status'=>$status];
    } finally { $stmt->close(); }
}
function spEnsureApproved(array $user): void {
    if ((int)$user['SellerOrNot'] !== 1 || strtolower(trim((string)$user['account_status'])) !== 'active') {
        throw new DomainException('Your seller account is not eligible for profile editing.');
    }
}
function spProfile(mysqli $conn, int $sellerId): array {
    $stmt = $conn->prepare('SELECT u.firstname, u.middlename, u.lastname, u.email, u.phone, u.gender, u.birthday, b.businessname, b.craftcategory, b.businessdescription, l.streetaddress, l.barangay, l.city, l.zipcode FROM users u LEFT JOIN businessinfo b ON b.user_id = u.id LEFT JOIN locationinfo l ON l.user_id = u.id WHERE u.id = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Unable to load seller profile.');
    try {
        $stmt->bind_param('i', $sellerId);
        if (!$stmt->execute()) throw new RuntimeException('Unable to load seller profile.');
        $stmt->bind_result($firstname,$middlename,$lastname,$email,$phone,$gender,$birthday,$businessname,$craftcategory,$description,$streetaddress,$barangay,$city,$zipcode);
        if (!$stmt->fetch()) throw new RuntimeException('Seller profile not found.');
        return compact('firstname','middlename','lastname','email','phone','gender','birthday','businessname','craftcategory','description','streetaddress','barangay','city','zipcode');
    } finally { $stmt->close(); }
}
function spEmailUsed(mysqli $conn, int $sellerId, string $email): bool {
    $stmt = $conn->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id <> ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Email check is unavailable.');
    try {
        $stmt->bind_param('si', $email, $sellerId);
        $stmt->execute(); $stmt->bind_result($id);
        return $stmt->fetch() === true;
    } finally { $stmt->close(); }
}
function spValidBirthday(string $value): bool {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $matches)) return false;
    $year=(int)$matches[1]; $month=(int)$matches[2]; $day=(int)$matches[3];
    if ($year < 1900 || !checkdate($month,$day,$year)) return false;
    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
    return $value <= $today->format('Y-m-d');
}
function spOnceFields(array $input, array $account): array {
    $savedGender = trim((string)($account['gender'] ?? ''));
    $savedBirthday = trim((string)($account['birthday'] ?? ''));
    $genderInput = isset($input['gender']) && is_string($input['gender']) ? strtolower(trim($input['gender'])) : '';
    $birthdayInput = isset($input['birthday']) && is_string($input['birthday']) ? trim($input['birthday']) : '';
    if ($savedGender !== '' && $genderInput !== '' && $genderInput !== $savedGender) {
        throw new DomainException('Gender is already saved and cannot be changed.');
    }
    if ($savedBirthday !== '' && $birthdayInput !== '' && $birthdayInput !== $savedBirthday) {
        throw new DomainException('Birthday is already saved and cannot be changed.');
    }
    if ($savedGender === '' && $genderInput !== '' && !in_array($genderInput, ['male','female','prefer not to say'], true)) {
        throw new DomainException('Choose a valid gender.');
    }
    if ($savedBirthday === '' && $birthdayInput !== '' && !spValidBirthday($birthdayInput)) {
        throw new DomainException('Choose a valid birthday.');
    }
    return [
        'gender'=>$savedGender !== '' ? $savedGender : ($genderInput !== '' ? $genderInput : null),
        'birthday'=>$savedBirthday !== '' ? $savedBirthday : ($birthdayInput !== '' ? $birthdayInput : null)
    ];
}
function spValidate(array $input, array $categories, array $barangays, array $account): array {
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $phone = spPhone((string)($input['phone'] ?? ''));
    $businessname = trim((string)($input['businessname'] ?? ''));
    $categoryInput = trim((string)($input['craftcategory'] ?? ''));
    $description = trim((string)($input['businessdescription'] ?? ''));
    $streetaddress = trim((string)($input['streetaddress'] ?? ''));
    $barangay = trim((string)($input['barangay'] ?? ''));
    $city = trim((string)($input['city'] ?? ''));
    $zipcode = trim((string)($input['zipcode'] ?? ''));
    if (!spValidEmail($email)) throw new DomainException('Enter a valid email address.');
    if (!preg_match('/^09\d{9}$/D', $phone)) throw new DomainException('Enter an 11-digit mobile number starting with 09.');
    if ($businessname === '' || spLength($businessname) > 50) throw new DomainException('Business name must be 1–50 characters.');
    $chosen = array_map('trim', explode(',', $categoryInput));
    if ($categoryInput === '' || spLength($categoryInput) > 100 || count($chosen) !== count(array_unique($chosen)) || count(array_filter($chosen, static fn($value) => !in_array($value, $categories, true))) > 0) {
        throw new DomainException('Choose valid craft categories (100 characters total maximum).');
    }
    $categoryInput = implode(', ', $chosen);
    if (spLength($description) < 20 || spLength($description) > 300) throw new DomainException('Business description must contain 20–300 characters.');
    if ($streetaddress === '' || spLength($streetaddress) > 255) throw new DomainException('Enter a street address (maximum 255 characters).');
    $canonicalBarangay = null;
    foreach ($barangays as $name=>$zip) { if (strcasecmp($name, $barangay) === 0) { $canonicalBarangay = $name; break; } }
    if ($canonicalBarangay === null || spLength($canonicalBarangay) > 25) throw new DomainException('Choose a barangay within Taguig City.');
    if ($city !== 'Taguig City') throw new DomainException('Only Taguig City is accepted.');
    if ($zipcode !== $barangays[$canonicalBarangay]) throw new DomainException('ZIP code does not match the selected barangay.');
    return array_merge(['email'=>$email,'phone'=>$phone,'businessname'=>$businessname,'craftcategory'=>$categoryInput,'businessdescription'=>$description,'streetaddress'=>$streetaddress,'barangay'=>$canonicalBarangay,'city'=>'Taguig City','zipcode'=>$zipcode], spOnceFields($input, $account));
}
function spSave(mysqli $conn, int $sellerId, array $values, string $originalEmail): void {
    // Caller MUST have started a transaction and locked the users row.
    $stmt = $conn->prepare('UPDATE users SET phone = ?, email = ?, gender = COALESCE(gender, ?), birthday = COALESCE(birthday, ?) WHERE id = ? AND LOWER(email) = LOWER(?) AND SellerOrNot = 1 LIMIT 1');
    if (!$stmt) throw new RuntimeException('Unable to update seller contact information.');
    try {
        $stmt->bind_param('ssssis', $values['phone'], $values['email'], $values['gender'], $values['birthday'], $sellerId, $originalEmail);
        if (!$stmt->execute()) throw new RuntimeException('Unable to update seller contact information.');
    } finally { $stmt->close(); }
    $stmt = $conn->prepare('UPDATE businessinfo SET businessname = ?, craftcategory = ?, businessdescription = ? WHERE user_id = ?');
    if (!$stmt) throw new RuntimeException('Unable to update business information.');
    try {
        $stmt->bind_param('sssi', $values['businessname'], $values['craftcategory'], $values['businessdescription'], $sellerId);
        if (!$stmt->execute()) throw new RuntimeException('Unable to update business information.');
    } finally { $stmt->close(); }
    $stmt = $conn->prepare('UPDATE locationinfo SET streetaddress = ?, barangay = ?, city = ?, zipcode = ? WHERE user_id = ?');
    if (!$stmt) throw new RuntimeException('Unable to update business location.');
    try {
        $stmt->bind_param('ssssi', $values['streetaddress'], $values['barangay'], $values['city'], $values['zipcode'], $sellerId);
        if (!$stmt->execute()) throw new RuntimeException('Unable to update business location.');
    } finally { $stmt->close(); }
}
function spJson(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function spSuccess(array $values, bool $emailChanged): void {
    $_SESSION['seller_profile_notice'] = $emailChanged ? 'Business profile and new email saved successfully.' : 'Profile updated successfully.';
    $_SESSION['seller_profile_notice_type'] = 'success';
    // Session names are updated without changing approved identity information.
    foreach (['business_name','seller_business_name'] as $key) if (array_key_exists($key, $_SESSION)) $_SESSION[$key] = $values['businessname'];
    foreach (['email','seller_email','user_email'] as $key) if (array_key_exists($key, $_SESSION)) $_SESSION[$key] = $values['email'];
}
$categories = [];
$pageError = '';
try { $categories = spCategories($conn); } catch (Throwable $e) { error_log('Seller profile categories: '.$e->getMessage()); $pageError = 'Craft categories could not be loaded. Please refresh the page.'; }
$barangays = spBarangays();
$flow = 'seller_profile_email_change_' . $sellerId;
$csrf = (string)$_SESSION['seller_profile_csrf'];
$ajaxActions = ['sp_email_check','sp_email_start','sp_email_verify','sp_email_resend','sp_email_cancel'];
$action = (string)($_POST['action'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array($action, $ajaxActions, true)) {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new DomainException('Session expired. Refresh the page and try again.');
        $user = spUser($conn, $sellerId);
        spEnsureApproved($user);
        if ($action === 'sp_email_cancel') { otpClearChallenge($flow); spJson(['success'=>true]); }
        if ($action === 'sp_email_check') {
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            if (!spValidEmail($email)) throw new DomainException('Enter a valid email address.');
            $same = strcasecmp($email, (string)$user['email']) === 0;
            if (!$same && spEmailUsed($conn, $sellerId, $email)) throw new DomainException('This email is already registered.');
            spJson(['success'=>true,'same'=>$same,'message'=>$same?'Current email address.':'Email is available.']);
        }
        if ($action === 'sp_email_start') {
            if (!$categories) throw new DomainException('Craft categories are unavailable right now.');
            $values = spValidate($_POST, $categories, $barangays, $user);
            if (strcasecmp($values['email'], (string)$user['email']) === 0) throw new DomainException('Your email has not changed.');
            if (spEmailUsed($conn, $sellerId, $values['email'])) throw new DomainException('This email is already registered.');
            $lastAt = (int)($_SESSION['seller_profile_email_last_at'] ?? 0);
            if ($lastAt && time() - $lastAt < 45) throw new DomainException('Please wait before requesting another email code.');
            otpClearChallenge($flow);
            otpStartChallenge($flow, $values['email'], trim((string)$user['firstname'].' '.(string)$user['lastname']), [
                'seller_id'=>$sellerId,'original_email'=>strtolower((string)$user['email']),'values'=>$values
            ], 'verify your new email address for your LokalCraft seller account');
            $_SESSION['seller_profile_email_last_at'] = time();
            spJson(['success'=>true,'masked_email'=>otpMaskEmail($values['email']),'resend_seconds'=>otpResendSeconds($flow)]);
        }
        if ($action === 'sp_email_resend') {
            $result = otpResendChallenge($flow);
            $result['resend_seconds'] = otpResendSeconds($flow);
            spJson($result);
        }
        if ($action === 'sp_email_verify') {
            $code = trim((string)($_POST['otp'] ?? ''));
            if (!preg_match('/^\d{6}$/D', $code)) throw new DomainException('Enter the complete 6-digit code.');
            $result = otpVerifyChallenge($flow, $code);
            if (empty($result['success'])) spJson(['success'=>false,'message'=>(string)($result['message'] ?? 'Incorrect or expired verification code.')]);
            $payload = $result['payload'] ?? null;
            if (!is_array($payload) || (int)($payload['seller_id'] ?? 0) !== $sellerId || !is_array($payload['values'] ?? null)) {
                otpClearChallenge($flow); throw new DomainException('Verification session expired. Start again.');
            }
            $conn->begin_transaction();
            try {
                $locked = spUser($conn, $sellerId, true);
                spEnsureApproved($locked);
                $values = spValidate($payload['values'], $categories, $barangays, $locked);
                if (strcasecmp((string)$result['email'], $values['email']) !== 0) throw new DomainException('OTP email does not match your requested email.');
                if (strcasecmp((string)$locked['email'], (string)($payload['original_email'] ?? '')) !== 0) throw new DomainException('Your email changed during verification. Start again.');
                if (spEmailUsed($conn, $sellerId, $values['email'])) throw new DomainException('This email is already registered.');
                spSave($conn, $sellerId, $values, (string)$locked['email']);
                $conn->commit();
            } catch (Throwable $e) { $conn->rollback(); throw $e; }
            otpClearChallenge($flow);
            spSuccess($values, true);
            spJson(['success'=>true,'redirect'=>'profile_seller.php']);
        }
    } catch (Throwable $e) {
        if (!($e instanceof DomainException)) error_log('Seller profile email change: '.$e->getMessage());
        spJson(['success'=>false,'message'=>$e instanceof DomainException ? $e->getMessage() : 'Unable to complete the request. Please try again.'], $e instanceof DomainException ? 422 : 500);
    }
}
$profile = [];
try { $profile = spProfile($conn, $sellerId); } catch (Throwable $e) { error_log('Seller profile load: '.$e->getMessage()); $pageError = 'Unable to load seller profile. Please refresh the page.'; }
$notice = (string)($_SESSION['seller_profile_notice'] ?? '');
$noticeType = (string)($_SESSION['seller_profile_notice_type'] ?? 'success');
unset($_SESSION['seller_profile_notice'], $_SESSION['seller_profile_notice_type']);
$openEdit = false;
$edit = $profile;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action === 'sp_update') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new DomainException('Session expired. Refresh the page.');
        if (!$categories) throw new DomainException('Craft categories are unavailable right now.');
        $conn->begin_transaction();
        try {
            $locked = spUser($conn, $sellerId, true);
            spEnsureApproved($locked);
            $values = spValidate($_POST, $categories, $barangays, $locked);
            $edit = array_merge($edit, $values);
            if (strcasecmp((string)$locked['email'], $values['email']) !== 0) throw new DomainException('Verify your new email before saving.');
            spSave($conn, $sellerId, $values, (string)$locked['email']);
            $conn->commit();
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
        otpClearChallenge($flow);
        spSuccess($values, false);
        header('Location: profile_seller.php'); exit;
    } catch (Throwable $e) {
        if (!($e instanceof DomainException)) error_log('Seller profile update: '.$e->getMessage());
        $notice = $e instanceof DomainException ? $e->getMessage() : 'Could not save the profile right now.';
        $noticeType = 'error'; $openEdit = true;
        foreach (['email','phone','businessname','craftcategory','businessdescription','streetaddress','barangay','zipcode'] as $field) {
            if (isset($_POST[$field]) && is_string($_POST[$field])) $edit[$field] = $_POST[$field];
        }
        foreach (['gender','birthday'] as $field) {
            if (empty($profile[$field]) && isset($_POST[$field]) && is_string($_POST[$field])) $edit[$field] = $_POST[$field];
        }
    }
}
$fullName = trim(implode(' ',array_filter([(string)($profile['firstname']??''),(string)($profile['middlename']??''),(string)($profile['lastname']??'')],static fn($n)=>trim($n)!==''))) ?: ($userName ?? 'Seller');
$businessDisplayName = trim((string)($profile['businessname']??'')) ?: ($businessName ?? 'Seller Shop');
$categoryDisplay = trim((string)($profile['craftcategory']??'')) ?: 'Not provided';
$location = implode(', ',array_filter(array_map('trim',[(string)($profile['streetaddress']??''),(string)($profile['barangay']??''),(string)($profile['city']??''),(string)($profile['zipcode']??'')]))) ?: 'Not provided';
$parts = array_values(array_filter([(string)($profile['firstname']??''),(string)($profile['lastname']??'')]));
$initials = '';
foreach (array_slice($parts,0,2) as $part) { $c=function_exists('mb_substr')?mb_substr($part,0,1):substr($part,0,1);$initials.=function_exists('mb_strtoupper')?mb_strtoupper($c):strtoupper($c); }
if (!$initials) $initials = 'LC';
$selected = array_map('trim',explode(',',(string)($edit['craftcategory'] ?? '')));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>LokalCraft | Seller Profile</title>
<style>
:root{--bg:#f7f3ef;--card:#fff;--text:#302c29;--muted:#80766e;--line:#e9e2dc;--orange:#ea8a3a;--orange-dark:#d87522;--soft:#fff4e9;--green:#278553;--red:#bc4545}
*{box-sizing:border-box}body{margin:0;min-height:100vh;background:linear-gradient(#f8f5f1,#f3eee9);color:var(--text);font:14px "Segoe UI",Arial,sans-serif}button,input,textarea{font:inherit}.page{min-height:100vh}.profile-content{min-height:100vh;margin-left:250px;padding:94px 28px 36px}.profile-wrap{max-width:1180px;margin:auto}.profile-title{font-size:2rem;font-weight:800;letter-spacing:-.6px;margin:0 0 18px}.profile-summary,.profile-card{border:1px solid var(--line);border-radius:16px;background:var(--card);box-shadow:0 8px 24px rgba(54,39,25,.045)}.profile-summary{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:16px;padding:18px 20px}.profile-summary-main{min-width:0;display:flex;align-items:center;gap:14px}.profile-avatar{width:56px;height:56px;flex:0 0 56px;display:grid;place-items:center;border-radius:50%;background:linear-gradient(135deg,#f7b875,#ea8a3a);color:#fff;font-weight:800}.profile-name{font-size:1.07rem;font-weight:800;overflow-wrap:anywhere}.profile-business{margin-top:4px;font-size:.76rem;color:var(--muted);overflow-wrap:anywhere}.profile-summary-actions{display:flex;gap:9px;align-items:center;flex-wrap:wrap}.approved-pill{display:inline-flex;align-items:center;gap:6px;padding:8px 10px;border:1px solid #cce4d5;border-radius:999px;background:#edf9f1;color:var(--green);font-size:.69rem;font-weight:800}.approved-pill:before{content:'✓'}.edit-btn,.primary-btn{border:1px solid var(--orange);border-radius:10px;background:var(--orange);color:#fff;font-weight:750;padding:10px 15px;cursor:pointer}.edit-btn:hover,.primary-btn:hover{background:var(--orange-dark)}button:disabled{opacity:.48;cursor:not-allowed!important}.profile-grid{display:grid;grid-template-columns:minmax(0,.9fr) minmax(0,1.1fr);gap:16px;align-items:start}.profile-card{min-width:0;overflow:hidden}.profile-card-head{padding:14px 16px;border-bottom:1px solid #f0ebe6;background:#fffdfa}.profile-card-title{margin:0;font-size:.9rem;font-weight:800}.profile-row{display:grid;grid-template-columns:120px minmax(0,1fr);gap:18px;align-items:start;min-height:54px;padding:13px 16px;border-bottom:1px solid #f2ede9}.profile-row:last-child{border:0}.profile-label{font-size:.64rem;color:#938c86;font-weight:800;letter-spacing:.04em;text-transform:uppercase}.profile-value{font-size:.8rem;font-weight:700;line-height:1.55;overflow-wrap:anywhere}.profile-value.description{white-space:pre-line;font-weight:500;color:#625b56}.business-name{color:var(--orange-dark)}.profile-notice{margin-bottom:15px;padding:12px 14px;border:1px solid #c3e6d1;border-radius:10px;background:#effaf2;color:#1d7546;font-size:.83rem}.profile-notice.error{border-color:#edc1be;background:#fff1f1;color:#933d38}.profile-notice:empty{display:none}
body.profile-modal-open{overflow:hidden}.sp-overlay{position:fixed;inset:0;z-index:200000;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(35,27,21,.55);backdrop-filter:blur(3px)}.sp-overlay.open{display:flex}.sp-modal{width:min(620px,100%);max-height:calc(100dvh - 32px);min-width:0;overflow-y:auto;border:1px solid #eadfd6;border-radius:19px;background:#fffdfa;box-shadow:0 25px 80px rgba(30,20,10,.25)}.sp-modal-header{display:flex;align-items:flex-start;justify-content:space-between;gap:15px;position:sticky;top:0;z-index:2;padding:18px 20px;border-bottom:1px solid #efe5db;background:#fffaf6}.sp-modal-header h2{margin:0;font-size:1.08rem}.sp-modal-header p{margin:5px 0 0;color:var(--muted);font-size:.76rem;line-height:1.4}.sp-close{display:grid;place-items:center;width:35px;height:35px;flex:0 0 35px;border:1px solid #e1d8d0;border-radius:50%;background:#fff;color:#6e655e;font-size:1.24rem;cursor:pointer}.sp-form{padding:19px 20px 20px}.sp-section-title{margin:15px 0 12px;padding-top:14px;border-top:1px solid #efe9e2;font-size:.85rem;font-weight:800;color:#8b5423}.sp-section-title:first-child{margin-top:0;padding-top:0;border:0}.sp-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.sp-field{display:flex;flex-direction:column;min-width:0;gap:7px}.sp-field.full{grid-column:1/-1}.sp-field>label{font-size:.76rem;font-weight:750;color:#554941}.sp-input,.sp-field textarea,.sp-category-toggle{width:100%;min-width:0;min-height:43px;border:1px solid #ddd1c5;border-radius:10px;padding:10px 12px;background:#fff;color:#39332f;outline:0;font-size:.86rem}.sp-field textarea{min-height:96px;resize:vertical;line-height:1.5}.sp-input:focus,.sp-field textarea:focus,.sp-category-toggle:focus-visible{border-color:var(--orange);box-shadow:0 0 0 3px rgba(234,138,58,.12)}.sp-input[readonly]{background:#f4f0eb;color:#8d8178;cursor:not-allowed}.sp-field-help{font-size:.7rem;color:#9b8d81}.sp-feedback{font-size:.7rem;line-height:1.4;color:var(--muted)}.sp-feedback:empty{display:none}.sp-feedback.good{color:var(--green)}.sp-feedback.bad,.sp-error{color:var(--red)}.sp-feedback.info{color:#8b623d}.sp-error{padding:10px 12px;margin:0 0 14px;border:1px solid #edc1be;border-radius:9px;background:#fff1f1;font-size:.79rem;line-height:1.5}.sp-error[hidden]{display:none}.sp-actions{display:flex;justify-content:flex-end;gap:9px;margin-top:21px}.secondary-btn{padding:10px 15px;border:1px solid #ddd6cf;border-radius:10px;background:#fff;color:#67584d;font-weight:750;cursor:pointer}.sp-category{position:relative;min-width:0}.sp-category-toggle{display:flex;align-items:center;justify-content:space-between;gap:12px;cursor:pointer;text-align:left}.sp-category-toggle .sp-category-label{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.sp-category-toggle span:last-child{flex:0 0 auto}.sp-category-options{position:absolute;left:0;right:0;top:calc(100% + 5px);z-index:4;max-height:215px;overflow:auto;border:1px solid #decab7;border-radius:11px;padding:6px;background:#fffefa;box-shadow:0 14px 28px rgba(51,33,15,.14)}.sp-category-options[hidden]{display:none!important}.sp-category-option{display:flex;align-items:center;gap:10px;padding:10px 9px;border-radius:7px;cursor:pointer;font-size:.81rem}.sp-category-option:hover{background:var(--soft)}.sp-category-option input{width:17px;height:17px;accent-color:var(--orange);flex:0 0 auto}.sp-suggest-wrap{position:relative}.sp-suggestions{position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:4;max-height:180px;overflow:auto;border:1px solid #decab7;border-radius:10px;background:#fff;box-shadow:0 10px 25px rgba(35,25,15,.12)}.sp-suggestions[hidden]{display:none!important}.sp-suggestions button{display:block;width:100%;padding:10px 12px;background:transparent;border:0;border-bottom:1px solid #f4eee8;text-align:left;color:#463b33;font-size:.81rem;cursor:pointer}.sp-suggestions button:hover,.sp-suggestions button:focus{background:#fff3e7}.sp-otp-modal{width:min(435px,100%);padding:23px;text-align:center}.sp-otp-head{position:relative;display:flex;justify-content:center;align-items:center;min-height:59px}.sp-otp-head .sp-close{position:absolute;right:0;top:0}.sp-otp-symbol{width:58px;height:58px;display:grid;place-items:center;border:1px solid #efd8be;border-radius:50%;background:#f8e9d8;color:#aa5c2c;font-size:26px}.sp-otp-modal h2{margin:15px 0 6px;font-size:1.4rem;color:#5a3822}.sp-otp-sub{margin:0 0 22px;color:#806b5c;font-size:.84rem;line-height:1.5}.sp-otp-sub strong{display:block;margin-top:5px;color:#8b5423;overflow-wrap:anywhere}.sp-otp-label{display:block;margin:0 0 10px;text-align:left;color:#69503e;font-size:.76rem;font-weight:800}.sp-otp-digits{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px}.sp-otp-digit{width:100%;min-width:0;height:53px;padding:0;border:1.5px solid #dcc3aa;border-radius:10px;background:#fff;color:#392b22;font-size:1.35rem;font-weight:800;text-align:center;outline:0}.sp-otp-digit:focus,.sp-otp-digit.filled{border-color:#d88743;box-shadow:0 0 0 3px rgba(234,138,58,.12)}.sp-otp-message{min-height:26px;margin:13px 0 7px;color:#817061;font-size:.77rem}.sp-otp-message.bad{color:var(--red)}.sp-otp-message.good{color:var(--green)}.sp-otp-verify{width:100%;min-height:44px}.sp-otp-footer{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:17px}.sp-otp-link{padding:6px;background:none;border:0;color:#9b642f;font-weight:800;font-size:.74rem;cursor:pointer}
.profile-topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 18px}.profile-topbar .profile-title{margin:0;min-width:0}.profile-topbar .edit-btn{flex:0 0 auto;padding:7px 12px;min-height:32px;border-radius:8px;font-size:.73rem;line-height:1.2;box-shadow:none}.profile-summary>.approved-pill{margin-left:auto}
@media(max-width:980px){.profile-grid{grid-template-columns:1fr}}@media(max-width:760px){.profile-content{margin-left:0;padding:108px 14px 28px}.profile-title{font-size:1.67rem}}@media(max-width:520px){.profile-summary{padding:14px}.profile-avatar{width:47px;height:47px;flex-basis:47px}.profile-row{grid-template-columns:1fr;gap:6px;padding:12px 14px}.sp-fields{grid-template-columns:1fr}.sp-field.full{grid-column:auto}.sp-modal-header,.sp-form{padding:15px}.sp-actions{flex-direction:column-reverse}.sp-actions button{width:100%}.sp-otp-modal{padding:20px 16px}.sp-otp-digits{gap:6px}.sp-otp-digit{height:49px;font-size:1.2rem}}@media(max-width:350px){.profile-topbar .edit-btn{padding:7px 10px;font-size:.7rem}.sp-otp-modal{padding:18px 12px}.sp-otp-digits{gap:4px}.sp-otp-digit{height:45px}}

/* Seller interface profile: matches navigation palette and keeps dialogs touch-safe. */
:root{--bg:#f7f6f4;--orange:#ea8a3a;--soft:#fff3e7;--line:#ebe3dc}
body{background:#f7f6f4;color:#352f29;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif}
.profile-content{position:relative;width:auto;max-width:none;min-width:0;margin-left:250px;padding:76px 28px 36px;overflow-x:clip}
.profile-wrap{max-width:1100px;width:100%;min-width:0}
.profile-topbar{align-items:center;margin-bottom:18px}
.sp-eyebrow{margin-bottom:5px;color:#b57945;font-size:11px;font-weight:850;letter-spacing:.13em}
.profile-title{margin:0;font-size:clamp(1.5rem,2.9vw,2rem);color:#312a25;letter-spacing:-.045em}
.profile-topbar .edit-btn{min-height:42px;padding:9px 17px;border-radius:11px;font-size:.83rem;box-shadow:0 5px 14px rgba(202,111,36,.13)}
.profile-summary{position:relative;isolation:isolate;min-height:154px;padding:25px 27px;margin-bottom:17px;border:1px solid #eadac9;border-radius:20px;background:linear-gradient(112deg,#fffdfb 1%,#fff8f1 49%,#fcead6 100%);overflow:hidden;box-shadow:0 11px 27px rgba(70,44,22,.05)}
.profile-summary:before{content:"";pointer-events:none;position:absolute;z-index:-1;width:270px;height:270px;right:-82px;top:-183px;border:1px solid rgba(224,154,92,.22);border-radius:50%;box-shadow:0 0 0 46px rgba(251,226,197,.2),0 0 0 89px rgba(253,235,216,.25)}
.profile-summary-main{gap:17px}.profile-avatar{width:76px;height:76px;flex-basis:76px;border-radius:22px;border:4px solid #fff;box-shadow:0 8px 22px rgba(184,103,37,.17);font-size:1.26rem}
.sp-role{font-size:.65rem;font-weight:850;letter-spacing:.12em;color:#b77540;margin-bottom:6px}
.profile-name{font-size:clamp(1.15rem,2.5vw,1.55rem);letter-spacing:-.02em}.profile-business{font-size:.83rem;line-height:1.55}
.profile-grid{grid-template-columns:minmax(0,1fr) minmax(0,1.17fr);gap:17px}
.profile-card{border-radius:17px;border-color:#ebe3dc;box-shadow:0 10px 26px rgba(52,36,23,.04)}.profile-card-head{padding:18px 20px;background:#fff;min-height:67px;display:flex;align-items:center}.profile-card-title{font-size:.94rem;color:#49392b}
.profile-row{grid-template-columns:133px minmax(0,1fr);min-height:65px;align-items:center;gap:13px;padding:14px 20px}.profile-label{font-size:.68rem;letter-spacing:0;text-transform:none;color:#9a897b}.profile-value{font-size:.83rem;color:#483e37}
.sp-overlay{top:var(--sp-view-top,0px);bottom:auto;height:var(--sp-view-height,100dvh);min-height:0;padding:max(12px,env(safe-area-inset-top)) 12px max(12px,env(safe-area-inset-bottom));overscroll-behavior:contain}
.sp-modal{max-height:calc(var(--sp-view-height,100dvh) - 24px);-webkit-overflow-scrolling:touch;overscroll-behavior:contain;scroll-padding-top:85px;scroll-padding-bottom:110px;background:#fff}
.sp-modal-header{background:#fffaf6}.sp-modal-header p:empty{display:none}
.sp-input,.sp-field textarea,.sp-category-toggle,.sp-otp-digit,.sp-suggestions button,.sp-category-option{font-size:16px!important}
.sp-field textarea{line-height:1.5}.sp-field select.sp-input{appearance:auto;-webkit-appearance:menulist}
.sp-otp-digit{font-size:19px!important}.sp-form{padding-bottom:25px}
.sp-once-field{position:relative}.sp-label-line{display:flex;align-items:center;gap:8px}.sp-label-line label{font-size:.76rem;font-weight:750;color:#554941}
.sp-once-tooltip{position:relative;z-index:10}.sp-once-tooltip summary{list-style:none;width:23px;height:23px;border:1px solid #e4c6a8;border-radius:50%;display:grid;place-items:center;background:#fff4e7;color:#995a2b;font-size:13px;font-weight:850;cursor:pointer;user-select:none}.sp-once-tooltip summary::-webkit-details-marker{display:none}.sp-once-tooltip summary:focus-visible{outline:2px solid #da823a;outline-offset:2px}
.sp-tooltip-content{position:absolute;z-index:30;bottom:calc(100% + 8px);left:-42px;width:min(232px,calc(100vw - 42px));border:1px solid #ead4be;border-radius:11px;background:#fffdfb;padding:10px 12px;font-size:13px;font-weight:500;line-height:1.5;box-shadow:0 10px 26px rgba(60,37,20,.15);color:#5f4837}
.sp-fixed,.sp-date-button{display:flex;width:100%;min-width:0;min-height:45px;align-items:center;justify-content:space-between;gap:8px;border:1px solid #ddd1c5;border-radius:10px;padding:10px 12px;background:#fff;color:#413a34;text-align:left;font-size:16px}
.sp-fixed{background:#f5f2ef;color:#766c63}.sp-fixed span{font-size:14px}.sp-date-button{cursor:pointer}.sp-date-button svg{flex:0 0 18px;color:#b5753c}.sp-date-button:focus-visible{outline:0;border-color:var(--orange);box-shadow:0 0 0 3px rgba(234,138,58,.12)}
.sp-calendar{position:fixed;z-index:240000;width:min(330px,calc(100vw - 24px));min-width:0;margin:0;border:1px solid #ead8c6;border-radius:16px;background:#fffdfa;padding:12px;box-shadow:0 18px 48px rgba(56,33,16,.23);max-height:calc(100dvh - 24px);overflow-y:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;transform:translateZ(0)}.sp-calendar[hidden]{display:none!important}
.sp-cal-head{display:grid;grid-template-columns:32px minmax(0,1.2fr) minmax(0,.9fr) 32px;gap:4px;align-items:center}.sp-cal-head select{min-width:0;width:100%;min-height:38px;border:1px solid #eaded1;border-radius:8px;background:#fff8f2;color:#634934;padding:4px;font-size:16px}.sp-cal-head button{width:32px;height:38px;border:1px solid #eaded1;border-radius:8px;background:#fff;color:#965d2b;font-size:23px}
.sp-cal-weekdays,.sp-cal-days{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:2px;text-align:center}.sp-cal-weekdays{margin:12px 0 5px;color:#95887c;font-size:11px;font-weight:800}.sp-cal-days button{min-width:0;min-height:36px;border:1px solid transparent;border-radius:8px;background:transparent;color:#42362d;font-size:13px}.sp-cal-days button:hover:not(:disabled){background:#fff0e2;border-color:#f2d3b4}.sp-cal-days button[aria-pressed="true"]{background:#ea8a3a;color:#fff;font-weight:800}.sp-cal-days button:disabled{opacity:.23}.sp-cal-foot{display:flex;justify-content:end;margin-top:10px;padding-top:8px;border-top:1px solid #f3e7dc}.sp-cal-foot button{padding:7px 11px;border:0;border-radius:8px;background:#fff0e2;color:#a46029;font-size:13px;font-weight:750}
@media(max-width:980px){.profile-grid{grid-template-columns:1fr}}
@media(max-width:760px){.profile-content{width:100%;margin-left:0;padding:70px 14px 30px}.profile-summary{padding:20px 19px}.sp-modal{max-height:calc(var(--sp-view-height,100dvh) - 24px)}}
@media(max-width:520px){.profile-content{padding:68px 12px 26px}.profile-topbar{gap:9px}.profile-topbar .edit-btn{min-height:40px;padding:9px 11px;font-size:.76rem}.profile-summary{min-height:0;padding:20px 17px;align-items:flex-start;flex-direction:column}.profile-summary-main{gap:12px}.profile-avatar{width:60px;height:60px;flex-basis:60px;border-radius:18px;font-size:1.1rem}.profile-name{font-size:1.15rem}.profile-business{font-size:.76rem}.profile-grid{gap:13px}.profile-card-head{padding:14px 15px;min-height:60px}.profile-row{grid-template-columns:1fr;gap:5px;min-height:0;padding:13px 15px}.sp-fields{grid-template-columns:1fr}.sp-tooltip-content{left:-100px;width:min(240px,calc(100vw - 55px))}.sp-modal-header,.sp-form{padding:15px}.sp-modal{scroll-padding-top:70px;scroll-padding-bottom:95px}.sp-otp-digit{height:49px}}

/* Floating birthday picker & cohesive seller SVG details. */
.profile-card-head{gap:10px}
.sp-section-icon{width:35px;height:35px;flex:0 0 35px;display:grid;place-items:center;border:1px solid #f1e1d1;border-radius:10px;background:#fff2e6;color:#bd793b}
.sp-section-icon svg{width:18px;height:18px}
.profile-label{display:flex;align-items:center;gap:8px;min-width:0}
.profile-label svg{width:16px;height:16px;flex:0 0 16px;color:#b78961}
.profile-row{gap:10px;grid-template-columns:145px minmax(0,1fr)}
.profile-topbar .edit-btn{display:inline-flex;align-items:center;gap:7px}
.profile-topbar .edit-btn svg{width:16px;height:16px;flex:0 0 16px}
.sp-section-title{display:flex;align-items:center;gap:9px}
.sp-section-title svg{width:17px;height:17px;flex:0 0 17px;color:#c28147}
.sp-close svg{width:18px;height:18px}
.sp-once-tooltip summary{color:#995a2b}
.sp-once-tooltip summary svg{width:14px;height:14px;pointer-events:none}
.sp-cal-days button:focus-visible,.sp-cal-head select:focus-visible,.sp-cal-head button:focus-visible{outline:2px solid #da823a;outline-offset:1px}
@media(max-width:520px){.profile-row{grid-template-columns:1fr;gap:6px}.profile-label svg{width:15px;height:15px;flex-basis:15px}}


/* Seller Profile finishing touches: single-row adaptive name and floating date popover. */
.profile-summary{min-width:0}
.profile-summary-main{flex:1 1 0;max-width:100%;min-width:0}
.sp-name-block{flex:1 1 auto;min-width:0;max-width:100%}
.profile-name{display:block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;overflow-wrap:normal;font-size:clamp(1.3rem,2.5vw,1.75rem);font-weight:850;line-height:1.25}
.profile-business{display:block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.profile-summary>.approved-pill{flex:0 0 auto;white-space:nowrap}
.sp-calendar{contain:layout style;isolation:isolate;box-shadow:0 20px 62px rgba(60,35,18,.25);background:#fffdfb}
.sp-calendar .sp-cal-head select{font-size:16px!important}
.sp-date-button[aria-expanded="true"]{border-color:#dd964f;background:#fff9f2;box-shadow:0 0 0 3px rgba(234,138,58,.12)}
.sp-cal-days button{min-height:40px;touch-action:manipulation}
.sp-cal-days button:focus-visible{outline:2px solid #dd8640;outline-offset:1px}
.profile-summary .approved-pill:before{content:none}
.approved-pill svg{width:15px;height:15px;flex:0 0 15px}
.profile-avatar{border-radius:21px}
@media(max-width:1100px){.profile-summary{flex-direction:column;align-items:flex-start}.profile-summary-main{width:100%}.profile-summary>.approved-pill{margin-left:0}}
@media(max-width:760px){.profile-content{padding-top:70px}.profile-summary-main{width:100%}}
@media(max-width:520px){.profile-content{padding-top:68px}.profile-name{font-size:1.4rem}.profile-summary>.approved-pill{margin-left:0}.sp-calendar{border-radius:15px}.sp-cal-days button{min-height:42px}}

/* Buyer-style compact verified badge, detail icon tiles, and refined name color. */
.profile-summary>.approved-pill{
  display:inline-flex;align-items:center;justify-content:center;gap:8px;
  align-self:center;flex:0 0 auto;min-height:33px;margin-left:auto;
  padding:7px 11px;border:1px solid #e7e1d9;border-radius:999px;
  background:#fffdfa;color:#6f6257;font-size:.73rem;font-weight:750;
  line-height:1.2;white-space:nowrap;box-shadow:0 3px 8px rgba(70,44,22,.035)
}
.profile-summary>.approved-pill::before{content:none}
.sp-verified-check{display:grid;place-items:center;width:17px;height:17px;flex:0 0 17px;
  border-radius:50%;background:#43a46c;color:#fff;
  box-shadow:0 0 0 3px #e6f6ed,0 0 10px rgba(67,164,108,.17)}
.sp-verified-check svg{width:11px;height:11px;display:block}
.profile-name{color:#493a30;font-weight:850;letter-spacing:-.023em}
.profile-row{display:flex;align-items:center;gap:13px;min-width:0;min-height:78px;padding:15px 20px}
.sp-detail-icon{display:grid;place-items:center;width:38px;height:38px;flex:0 0 38px;
  border-radius:11px;background:#f8f5f1;color:#9c816b}
.sp-detail-icon svg{width:18px;height:18px;display:block}
.sp-detail-info{display:flex;flex:1 1 auto;flex-direction:column;justify-content:center;gap:4px;min-width:0}
.profile-row .profile-label{display:block;min-width:0;margin:0;color:#9a897b;font-size:.68rem;
  font-weight:750;letter-spacing:.02em;text-transform:none;line-height:1.4}
.profile-row .profile-value{display:block;min-width:0;color:#483e37;font-size:.86rem;font-weight:720;
  line-height:1.45;overflow-wrap:anywhere}
.profile-row .profile-value.business-name{color:#b66c2d;font-weight:800}
.profile-row .profile-value.description{color:#625b56;font-weight:500;white-space:pre-line}
.sp-detail-locked{display:grid;place-items:center;width:26px;height:26px;flex:0 0 26px;
  border-radius:8px;background:#f8f4ef;color:#a48d7c}
.sp-detail-locked svg{width:14px;height:14px}
@media(max-width:1100px){.profile-summary>.approved-pill{margin-left:0;align-self:flex-start}}
@media(max-width:520px){
  .profile-row{flex-direction:row;align-items:center;gap:11px;min-height:73px;padding:13px 15px}
  .sp-detail-icon{width:34px;height:34px;flex-basis:34px;border-radius:10px}
  .sp-detail-icon svg{width:17px;height:17px}
  .profile-row .profile-value{font-size:.83rem}
  .profile-summary>.approved-pill{padding:6px 10px;min-height:30px;font-size:.68rem}
  .profile-name{color:#493a30}
}
/* Seller Profile only: agreed page width, palette, and action colors. */
:root{--sp-cta-gradient:linear-gradient(135deg,#A55325 0%,#C2692E 100%)}
body,.page,.profile-content{background:#FFF8ED}
.profile-wrap{max-width:1600px}
.profile-avatar{background:var(--sp-cta-gradient)}
.edit-btn,.primary-btn,.secondary-btn{border-color:#A55325;background:var(--sp-cta-gradient);color:#fff}
.edit-btn:hover:not(:disabled),.primary-btn:hover:not(:disabled),.secondary-btn:hover:not(:disabled){background:var(--sp-cta-gradient);filter:brightness(.94);color:#fff}
.sp-cal-days button[aria-pressed="true"]{background:var(--sp-cta-gradient);color:#fff}
/* Tooltips stay hidden until tapped and open beneath their info icons. */
.sp-once-tooltip:not([open]) .sp-tooltip-content{display:none!important}
.sp-tooltip-content{top:calc(100% + 8px);bottom:auto}
</style>
</head>
<body>
<div class="page">
<?php require __DIR__ . '/seller_nav.php'; ?>
<main class="profile-content">
<?php require __DIR__ . '/seller_search.php'; ?>
<div class="profile-wrap">
<div class="profile-topbar">
<div><div class="sp-eyebrow">SELLER ACCOUNT</div><h1 class="profile-title">My Profile</h1></div>
<button class="edit-btn" type="button" id="spOpen" <?= $pageError !== '' || !$categories ? 'disabled' : '' ?>><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m15 5 4 4M3 21l5-1 12-12a3 3 0 0 0-4-4L4 16l-1 5Z"/></svg>Edit Profile</button>
</div>
<?php if ($pageError !== ''): ?><div class="profile-notice error" role="alert"><?= spE($pageError) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="profile-notice<?= $noticeType === 'error' ? ' error' : '' ?>" role="status"><?= spE($notice) ?></div><?php endif; ?>
<section class="profile-summary">
<div class="profile-summary-main"><div class="profile-avatar" aria-hidden="true"><?= spE($initials) ?></div><div class="sp-name-block"><div class="sp-role">VERIFIED SELLER</div><div class="profile-name" id="spProfileName" title="<?= spE($fullName) ?>"><?= spE($fullName) ?></div><div class="profile-business"><?= spE($businessDisplayName) ?> · <?= spE($categoryDisplay) ?></div></div></div>
<span class="approved-pill" aria-label="Approved Seller"><span class="sp-verified-check" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/></svg></span>Approved Seller</span>
</section>
<div class="profile-grid">
<section class="profile-card"><div class="profile-card-head"><span class="sp-section-icon"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg></span><h2 class="profile-card-title">Account Information</h2></div>
<div class="profile-row"><span class="sp-detail-icon" aria-hidden="true"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M5.5 16c.4-2 6.6-2 7 0M15 9h4m-4 4h4"/></svg></span><div class="sp-detail-info"><span class="profile-label">Approved Full Name</span><span class="profile-value"><?= spE($fullName) ?></span></div></div>
<div class="profile-row"><span class="sp-detail-icon" aria-hidden="true"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg></span><div class="sp-detail-info"><span class="profile-label">Email Address</span><span class="profile-value"><?= spE($profile['email'] ?? 'Not provided') ?></span></div></div>
<div class="profile-row"><span class="sp-detail-icon" aria-hidden="true"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2A19.7 19.7 0 0 1 2.1 4.2 2 2 0 0 1 4 2h3a2 2 0 0 1 2 1.7l.6 3a2 2 0 0 1-.6 1.8L7 10a16 16 0 0 0 7 7l1.5-2a2 2 0 0 1 1.8-.6l3 .6a2 2 0 0 1 1.7 1.9Z"/></svg></span><div class="sp-detail-info"><span class="profile-label">Phone Number</span><span class="profile-value"><?= spE($profile['phone'] ?? 'Not provided') ?></span></div></div>
<div class="profile-row"><span class="sp-detail-icon" aria-hidden="true"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg></span><div class="sp-detail-info"><span class="profile-label">Gender</span><span class="profile-value"><?= spE(!empty($profile['gender']) ? ucfirst((string)$profile['gender']) : 'Not set') ?></span></div><?php if (!empty($profile['gender'])): ?><span class="sp-detail-locked" title="Gender is saved and locked" aria-label="Gender locked"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3"/></svg></span><?php endif; ?></div>
<div class="profile-row"><span class="sp-detail-icon" aria-hidden="true"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 11h18"/></svg></span><div class="sp-detail-info"><span class="profile-label">Birthday</span><span class="profile-value"><?php $shownBirth=(string)($profile['birthday'] ?? ''); echo $shownBirth !== '' && spValidBirthday($shownBirth) ? spE(date('F j, Y',strtotime($shownBirth))) : 'Not set'; ?></span></div><?php if ($shownBirth !== ''): ?><span class="sp-detail-locked" title="Birthday is saved and locked" aria-label="Birthday locked"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3"/></svg></span><?php endif; ?></div></section>
<section class="profile-card"><div class="profile-card-head"><span class="sp-section-icon"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9 5 3h14l2 6v2a3 3 0 0 1-5 2 3 3 0 0 1-4 0 3 3 0 0 1-4 0 3 3 0 0 1-5-2V9Z"/><path d="M5 15v6h14v-6M9 21v-5h6v5"/></svg></span><h2 class="profile-card-title">Business Information</h2></div>
<div class="profile-row"><span class="sp-detail-icon" aria-hidden="true"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9 5 3h14l2 6v2a3 3 0 0 1-5 2 3 3 0 0 1-4 0 3 3 0 0 1-4 0 3 3 0 0 1-5-2V9Z"/><path d="M5 15v6h14v-6M9 21v-5h6v5"/></svg></span><div class="sp-detail-info"><span class="profile-label">Business / Shop</span><span class="profile-value business-name"><?= spE($businessDisplayName) ?></span></div></div>
<div class="profile-row"><span class="sp-detail-icon" aria-hidden="true"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="7" height="7" rx="1"/><rect x="14" y="4" width="7" height="7" rx="1"/><rect x="3" y="15" width="7" height="7" rx="1"/><rect x="14" y="15" width="7" height="7" rx="1"/></svg></span><div class="sp-detail-info"><span class="profile-label">Craft Categories</span><span class="profile-value"><?= spE($categoryDisplay) ?></span></div></div>
<div class="profile-row"><span class="sp-detail-icon" aria-hidden="true"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h9l5 5v15H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Z"/><path d="M15 2v5h5M8 12h8m-8 4h8"/></svg></span><div class="sp-detail-info"><span class="profile-label">Description</span><span class="profile-value description"><?= spE(($profile['description'] ?? '') ?: 'Not provided') ?></span></div></div>
<div class="profile-row"><span class="sp-detail-icon" aria-hidden="true"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></span><div class="sp-detail-info"><span class="profile-label">Location</span><span class="profile-value"><?= spE($location) ?></span></div></div></section>
</div></div></main></div>
<div class="sp-overlay<?= $openEdit ? ' open' : '' ?>" id="spEditOverlay" aria-hidden="<?= $openEdit ? 'false' : 'true' ?>">
<section class="sp-modal" role="dialog" aria-modal="true" aria-labelledby="spEditTitle">
<div class="sp-modal-header"><div><h2 id="spEditTitle">Edit Seller Profile</h2></div><button type="button" class="sp-close" id="spEditClose" aria-label="Close edit profile">×</button></div>
<form class="sp-form" id="spForm" action="profile_seller.php" method="post" novalidate>
<input type="hidden" name="csrf_token" value="<?= spE($csrf) ?>"><input type="hidden" name="action" value="sp_update">
<div class="sp-error" id="spFormError" <?= $openEdit && $noticeType === 'error' ? '' : 'hidden' ?>><?= $openEdit && $noticeType === 'error' ? spE($notice) : '' ?></div>
<div class="sp-section-title"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2A19.7 19.7 0 0 1 2.1 4.2 2 2 0 0 1 4 2h3a2 2 0 0 1 2 1.7l.6 3a2 2 0 0 1-.6 1.8L7 10a16 16 0 0 0 7 7l1.5-2a2 2 0 0 1 1.8-.6l3 .6a2 2 0 0 1 1.7 1.9Z"/></svg>Contact Information</div>
<div class="sp-fields">
<div class="sp-field full"><label for="spEmail">Email Address</label><input class="sp-input" id="spEmail" name="email" type="email" maxlength="100" autocomplete="email" required value="<?= spE($edit['email'] ?? '') ?>"><span id="spEmailFeedback" class="sp-feedback" aria-live="polite"></span></div>
<div class="sp-field full"><label for="spPhone">Mobile Number</label><input class="sp-input" id="spPhone" name="phone" type="tel" inputmode="numeric" maxlength="14" autocomplete="tel" required value="<?= spE($edit['phone'] ?? '') ?>"></div>
</div>
<div class="sp-section-title"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M5.5 16c.4-2 6.6-2 7 0M15 9h4m-4 4h4"/></svg>Personal Details</div>
<div class="sp-fields">
<div class="sp-field sp-once-field">
  <div class="sp-label-line"><label for="spGender">Gender</label><details class="sp-once-tooltip"><summary aria-label="Gender information"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5m0-8h.01"/></svg></summary><div class="sp-tooltip-content">Set once. You cannot change your gender after saving.</div></details></div>
  <?php if (empty($profile['gender'])): ?>
    <select class="sp-input" id="spGender" name="gender"><option value="">Select gender</option><?php foreach (['male'=>'Male','female'=>'Female','prefer not to say'=>'Prefer not to say'] as $value=>$label): ?><option value="<?= spE($value) ?>" <?= ($edit['gender'] ?? '') === $value ? 'selected' : '' ?>><?= spE($label) ?></option><?php endforeach; ?></select>
  <?php else: ?>
    <input type="hidden" name="gender" value="<?= spE($profile['gender']) ?>"><div class="sp-fixed" id="spGender" aria-label="Gender locked"><?= spE(ucfirst((string)$profile['gender'])) ?><span aria-hidden="true">🔒</span></div>
  <?php endif; ?>
</div>
<div class="sp-field sp-once-field sp-date-field">
  <div class="sp-label-line"><label for="spBirthButton">Birthday</label><details class="sp-once-tooltip"><summary aria-label="Birthday information"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5m0-8h.01"/></svg></summary><div class="sp-tooltip-content">Set once. Check your birthday before saving.</div></details></div>
  <input type="hidden" id="spBirthday" name="birthday" value="<?= spE(empty($profile['birthday']) ? ($edit['birthday'] ?? '') : $profile['birthday']) ?>">
  <?php if (empty($profile['birthday'])): ?>
    <button class="sp-date-button" type="button" id="spBirthButton" aria-haspopup="dialog" aria-expanded="false" aria-controls="spCalendar"><span id="spBirthLabel">Select birthday</span><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 11h18"/></svg></button>
    <div class="sp-calendar" id="spCalendar" role="dialog" aria-label="Choose birthday" hidden>
      <div class="sp-cal-head"><button type="button" id="spPrevMonth" aria-label="Previous month">‹</button><select id="spBirthMonth" aria-label="Month"></select><select id="spBirthYear" aria-label="Year"></select><button type="button" id="spNextMonth" aria-label="Next month">›</button></div>
      <div class="sp-cal-weekdays" aria-hidden="true"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div><div class="sp-cal-days" id="spBirthDays"></div>
      <div class="sp-cal-foot"><button type="button" id="spBirthClear"><svg aria-hidden="true" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 7h16M10 11v7m4-7v7M6 7l1 14h10l1-14M9 7V4h6v3"/></svg> Clear</button></div>
    </div>
  <?php else: ?>
    <div class="sp-fixed" id="spBirthButton" aria-label="Birthday locked"><?= spE(date('F j, Y',strtotime((string)$profile['birthday']))) ?><span aria-hidden="true">🔒</span></div>
  <?php endif; ?>
</div>
</div>
<div class="sp-section-title"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9 5 3h14l2 6v2a3 3 0 0 1-5 2 3 3 0 0 1-4 0 3 3 0 0 1-4 0 3 3 0 0 1-5-2V9Z"/><path d="M5 15v6h14v-6M9 21v-5h6v5"/></svg>Business Information</div>
<div class="sp-fields">
<div class="sp-field full"><label for="spBusinessName">Business / Shop Name</label><input class="sp-input" id="spBusinessName" name="businessname" type="text" maxlength="50" required value="<?= spE($edit['businessname'] ?? '') ?>"></div>
<div class="sp-field full"><label for="spCategoryToggle">Craft Categories</label><div class="sp-category" id="spCategoryWrap"><input type="hidden" name="craftcategory" id="spCraftCategory" value="<?= spE($edit['craftcategory'] ?? '') ?>"><button type="button" class="sp-category-toggle" id="spCategoryToggle" aria-controls="spCategoryOptions" aria-expanded="false"><span class="sp-category-label">Choose categories</span><span aria-hidden="true">⌄</span></button><div class="sp-category-options" id="spCategoryOptions" hidden>
<?php foreach($categories as $name): ?><label class="sp-category-option"><input type="checkbox" class="sp-category-check" value="<?= spE($name) ?>" <?= in_array($name,$selected,true)?'checked':'' ?>><span><?= spE($name) ?></span></label><?php endforeach; ?>
</div></div><span id="spCategoryFeedback" class="sp-feedback" aria-live="polite"></span></div>
<div class="sp-field full"><label for="spDescription">Business Description</label><textarea id="spDescription" name="businessdescription" minlength="20" maxlength="300" required><?= spE($edit['businessdescription'] ?? $edit['description'] ?? '') ?></textarea><span class="sp-field-help" id="spDescCount">20–300 characters</span></div>
</div>
<div class="sp-section-title"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>Business Location</div>
<div class="sp-fields">
<div class="sp-field full"><label for="spStreet">Street Address</label><input class="sp-input" id="spStreet" name="streetaddress" type="text" maxlength="255" required value="<?= spE($edit['streetaddress'] ?? '') ?>"></div>
<div class="sp-field full"><label for="spBarangay">Barangay</label><div class="sp-suggest-wrap" id="spBarangayWrap"><input class="sp-input" id="spBarangay" name="barangay" type="text" maxlength="25" autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="spSuggestions" aria-expanded="false" required value="<?= spE($edit['barangay'] ?? '') ?>"><div class="sp-suggestions" id="spSuggestions" role="listbox" hidden></div></div></div>
<div class="sp-field"><label for="spCity">City</label><input class="sp-input" id="spCity" name="city" type="text" readonly value="Taguig City"></div>
<div class="sp-field"><label for="spZip">ZIP Code</label><input class="sp-input" id="spZip" name="zipcode" type="text" inputmode="numeric" maxlength="4" readonly value="<?= spE($edit['zipcode'] ?? '') ?>"></div>
</div>
<div class="sp-actions"><button type="button" class="secondary-btn" id="spCancel">Cancel</button><button type="submit" class="primary-btn" id="spSave" <?= $pageError !== '' || !$categories ? 'disabled':'' ?>>Save Changes</button></div>
</form></section></div>
<div class="sp-overlay" id="spOtpOverlay" aria-hidden="true">
<section class="sp-modal sp-otp-modal" role="dialog" aria-modal="true" aria-labelledby="spOtpTitle">
<div class="sp-otp-head"><div class="sp-otp-symbol" aria-hidden="true">✉</div><button class="sp-close" id="spOtpClose" type="button" aria-label="Close email verification">×</button></div>
<h2 id="spOtpTitle">Verify your email</h2><p class="sp-otp-sub">Enter the 6-digit code sent to:<strong id="spOtpEmail"></strong></p>
<label class="sp-otp-label">Verification Code</label><input type="hidden" id="spOtpValue">
<div class="sp-otp-digits" id="spOtpDigits">
<?php for($i=1;$i<=6;$i++): ?><input class="sp-otp-digit" type="text" inputmode="numeric" maxlength="6" <?= $i===1?'autocomplete="one-time-code"':'autocomplete="off"' ?> aria-label="Digit <?= $i ?> of 6"><?php endfor; ?>
</div><div class="sp-otp-message" id="spOtpMessage" role="status" aria-live="polite"></div>
<button type="button" class="primary-btn sp-otp-verify" id="spOtpVerify" disabled>Verify &amp; Save Changes</button>
<div class="sp-otp-footer"><button type="button" class="sp-otp-link" id="spOtpResend" disabled>Resend code</button><button type="button" class="sp-otp-link" id="spOtpCancel">Change email</button></div>
</section></div>
<script>
(()=>{
'use strict';
const $=id=>document.getElementById(id);
// Match font to available space without wrapping names to a second line.
const nameEl=$('spProfileName');
if(nameEl){
  let nameFrame=0;
  const fitSellerName=()=>{
    nameFrame=0;
    const text=nameEl.textContent.trim();
    const available=nameEl.clientWidth;
    if(!text||available<1)return;
    const style=getComputedStyle(nameEl);
    const ctx=document.createElement('canvas').getContext('2d');
    if(!ctx)return;
    const max=window.innerWidth<=520?23:28,min=13;
    const weight=style.fontWeight||'800',family=style.fontFamily||'sans-serif';
    const spacing=Number.parseFloat(style.letterSpacing)||0;
    let size=max;
    while(size>min){
      ctx.font=`${weight} ${size}px ${family}`;
      if(ctx.measureText(text).width+Math.max(0,text.length-1)*spacing <= available-3)break;
      size-=0.5;
    }
    nameEl.style.fontSize=`${Math.max(min,size)}px`;
  };
  const scheduleNameFit=()=>{if(!nameFrame)nameFrame=requestAnimationFrame(fitSellerName);};
  if('ResizeObserver' in window)new ResizeObserver(scheduleNameFit).observe(nameEl);
  window.addEventListener('resize',scheduleNameFit,{passive:true});
  document.fonts?.ready?.then(scheduleNameFit);
  scheduleNameFit();
}
// Custom calendar: select month/year directly without a native Safari spinner.
const birthInput=$('spBirthday'),birthButton=$('spBirthButton'),calendar=$('spCalendar');
if(birthInput && calendar && birthButton){
  const monthBox=$('spBirthMonth'),yearBox=$('spBirthYear'),days=$('spBirthDays');
  const today=new Date(),todayIso=`${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`;
  const months=['January','February','March','April','May','June','July','August','September','October','November','December'];
  const existing=/^\d{4}-\d{2}-\d{2}$/.test(birthInput.value)?birthInput.value.split('-').map(Number):null;
  let year=existing?existing[0]:Math.max(1900,today.getFullYear()-20),month=existing?existing[1]:today.getMonth()+1;
  months.forEach((name,i)=>monthBox.add(new Option(name,String(i+1))));
  for(let i=today.getFullYear();i>=1900;i--)yearBox.add(new Option(String(i),String(i)));
  const refreshLabel=()=>{const values=birthInput.value.split('-').map(Number);$('spBirthLabel').textContent=values.length===3&&values[1]>=1&&values[1]<=12?`${months[values[1]-1]} ${values[2]}, ${values[0]}`:'Select birthday';};
  const render=()=>{
    year=Math.max(1900,Math.min(today.getFullYear(),year));month=Math.max(1,Math.min(12,month));
    monthBox.value=String(month);yearBox.value=String(year);days.replaceChildren();
    const offset=new Date(year,month-1,1).getDay(), count=new Date(year,month,0).getDate();
    for(let n=0;n<offset;n++)days.appendChild(document.createElement('span'));
    for(let n=1;n<=count;n++){
      const iso=`${year}-${String(month).padStart(2,'0')}-${String(n).padStart(2,'0')}`;
      const button=document.createElement('button');button.type='button';button.textContent=String(n);button.setAttribute('aria-label',`${months[month-1]} ${n}, ${year}`);button.setAttribute('aria-pressed',String(iso===birthInput.value));button.disabled=iso>todayIso;
      button.addEventListener('click',()=>{birthInput.value=iso;refreshLabel();close();});days.append(button);
    }
    $('spPrevMonth').disabled=year===1900&&month===1;
    $('spNextMonth').disabled=year===today.getFullYear()&&month>=today.getMonth()+1;
  };
  // Portal outside the scrollable edit modal, preventing layout shifts and clipping.
  document.body.appendChild(calendar);
  const positionCalendar=()=>{
    if(calendar.hidden)return;
    const viewport=window.visualViewport;
    const visibleLeft=viewport?viewport.offsetLeft:0;
    const visibleTop=viewport?viewport.offsetTop:0;
    const visibleWidth=viewport?viewport.width:window.innerWidth;
    const visibleHeight=viewport?viewport.height:window.innerHeight;
    const rect=birthButton.getBoundingClientRect();
    const maxWidth=Math.max(240,visibleWidth-24);
    const width=Math.min(330,maxWidth);
    calendar.style.width=`${width}px`;
    const left=Math.max(visibleLeft+12,Math.min(rect.left,visibleLeft+visibleWidth-width-12));
    calendar.style.left=`${left}px`;
    calendar.style.maxHeight=`${Math.max(180,visibleHeight-24)}px`;
    // Measure after it becomes visible; select the side with more available room.
    const height=calendar.offsetHeight;
    const below=visibleTop+visibleHeight-rect.bottom-12;
    const above=rect.top-visibleTop-12;
    const upward=below<height+8&&above>below;
    const proposed=upward?rect.top-height-8:rect.bottom+8;
    const top=Math.max(visibleTop+12,Math.min(proposed,visibleTop+visibleHeight-height-12));
    calendar.style.top=`${top}px`;
  };
  const close=()=>{calendar.hidden=true;birthButton.setAttribute('aria-expanded','false');};
  birthButton.addEventListener('click',()=>{
    if(!calendar.hidden){close();return;}
    if(document.activeElement instanceof HTMLElement && document.activeElement.matches('input,textarea,select'))document.activeElement.blur();
    render();calendar.hidden=false;birthButton.setAttribute('aria-expanded','true');
    positionCalendar();requestAnimationFrame(positionCalendar);window.setTimeout(positionCalendar,220);
  });
  const editDialog=$('spEditOverlay')?.querySelector('.sp-modal');
  editDialog?.addEventListener('scroll',()=>{if(!calendar.hidden)positionCalendar();},{passive:true});
  window.addEventListener('resize',positionCalendar,{passive:true});
  window.visualViewport?.addEventListener('resize',positionCalendar,{passive:true});
  window.visualViewport?.addEventListener('scroll',positionCalendar,{passive:true});
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!calendar.hidden){event.preventDefault();event.stopImmediatePropagation();close();}},true);
  ['spEditClose','spCancel'].forEach(id=>$(id)?.addEventListener('click',close));
  new MutationObserver(()=>{if(!$('spEditOverlay')?.classList.contains('open')&&!calendar.hidden)close();}).observe($('spEditOverlay'),{attributes:true,attributeFilter:['class']});
  $('spForm')?.addEventListener('submit',close);

  monthBox.addEventListener('change',()=>{month=Number(monthBox.value);render();});yearBox.addEventListener('change',()=>{year=Number(yearBox.value);render();});
  $('spPrevMonth').addEventListener('click',()=>{if(month===1){year--;month=12;}else month--;render();});
  $('spNextMonth').addEventListener('click',()=>{if(month===12){year++;month=1;}else month++;render();});
  $('spBirthClear').addEventListener('click',()=>{birthInput.value='';refreshLabel();close();});
  document.addEventListener('pointerdown',event=>{if(!calendar.hidden&&!calendar.contains(event.target)&&!birthButton.contains(event.target))close();});
  refreshLabel();render();
}
const profileTooltips=[...document.querySelectorAll('.sp-once-tooltip')];
const closeProfileTooltips=()=>profileTooltips.forEach(tip=>{tip.open=false;});
profileTooltips.forEach(tip=>tip.addEventListener('toggle',()=>{if(tip.open)profileTooltips.forEach(other=>{if(tip!==other)other.open=false;});}));
document.addEventListener('pointerdown',event=>{
  if(!(event.target instanceof Element)||!event.target.closest('.sp-once-tooltip'))closeProfileTooltips();
});
document.addEventListener('focusin',event=>{
  if(!(event.target instanceof Element)||!event.target.closest('.sp-once-tooltip'))closeProfileTooltips();
});
$('spEditOverlay')?.querySelector('.sp-modal')?.addEventListener('scroll',closeProfileTooltips,{passive:true});
// Safari/iOS: adapt scrolling dialog to the visible viewport above the keyboard.
const viewport=window.visualViewport;
function sizeModal(){const visible=viewport?viewport.height:window.innerHeight;document.documentElement.style.setProperty('--sp-view-height',`${Math.round(visible)}px`);document.documentElement.style.setProperty('--sp-view-top',`${Math.round(viewport?viewport.offsetTop:0)}px`);}
sizeModal();viewport?.addEventListener('resize',sizeModal);viewport?.addEventListener('scroll',sizeModal);window.addEventListener('resize',sizeModal);
function revealField(field){
  const dialog=field.closest('.sp-modal');if(!dialog)return;
  window.setTimeout(()=>{if(!field.isConnected)return;const rect=field.getBoundingClientRect();const box=dialog.getBoundingClientRect();if(rect.bottom>box.bottom-22)dialog.scrollBy({top:rect.bottom-box.bottom+90,behavior:'smooth'});else if(rect.top<box.top+70)dialog.scrollBy({top:rect.top-box.top-90,behavior:'smooth'});},180);
}
document.addEventListener('focusin',event=>{const field=event.target;if(field instanceof HTMLElement&&field.closest('.sp-modal')&&field.matches('input,textarea,select'))revealField(field);});

const edit=$('spEditOverlay'),otp=$('spOtpOverlay'),form=$('spForm'),error=$('spFormError');
const email=$('spEmail'),phone=$('spPhone'),business=$('spBusinessName'),desc=$('spDescription'),street=$('spStreet'),barangay=$('spBarangay'),city=$('spCity'),zip=$('spZip');
const currentEmail=<?= json_encode(strtolower(trim((string)($profile['email']??''))),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const csrf=<?= json_encode($csrf,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const barangays=<?= json_encode($barangays,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
let saving=false,verifying=false,emailSerial=0,emailTimer=null,cooldown=null,cooldownEnd=0;
const lockBody=()=>document.body.classList.toggle('profile-modal-open',edit.classList.contains('open')||otp.classList.contains('open'));
const setError=message=>{error.textContent=message||'';error.hidden=!message;};
const openEdit=()=>{edit.classList.add('open');edit.setAttribute('aria-hidden','false');lockBody();$('spEditClose').focus({preventScroll:true});};
const closeEdit=()=>{if(saving||verifying||otp.classList.contains('open'))return;if(calendar)calendar.hidden=true;birthButton?.setAttribute('aria-expanded','false');edit.classList.remove('open');edit.setAttribute('aria-hidden','true');lockBody();};
$('spOpen')?.addEventListener('click',openEdit);$('spEditClose').addEventListener('click',closeEdit);$('spCancel').addEventListener('click',closeEdit);
// Outside taps do not close the form; protects copy/paste and unsaved changes.
async function request(action,values={}){
  const params=new URLSearchParams({...values,action,csrf_token:csrf});
  const response=await fetch('profile_seller.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body:params.toString()});
  const result=await response.json();if(!result||typeof result!=='object')throw Error('Unexpected server response');return result;
}
const feedback=$('spEmailFeedback');
function emailFormat(value){return value.length<=100 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)&&!['g.mail.com','gmail.con','yahoo.con'].includes(value.split('@').pop());}
function setEmailFeedback(message,kind=''){feedback.textContent=message;feedback.className='sp-feedback'+(kind?' '+kind:'');}
async function checkEmail(){const serial=++emailSerial,value=email.value.trim().toLowerCase();if(value===currentEmail){setEmailFeedback('');return;}if(!emailFormat(value)){setEmailFeedback(value?'Enter a valid email address.':'',value?'bad':'');return;}
setEmailFeedback('Checking email...','info');try{const data=await request('sp_email_check',{email:value});if(serial!==emailSerial||email.value.trim().toLowerCase()!==value)return;setEmailFeedback(data.success?'Email is available.':(data.message||'Email unavailable.'),data.success?'good':'bad');}catch(e){if(serial===emailSerial)setEmailFeedback('Email check unavailable.','bad');}}
email.addEventListener('input',()=>{emailSerial++;clearTimeout(emailTimer);const value=email.value.trim().toLowerCase();if(value===currentEmail){setEmailFeedback('');return;}if(!emailFormat(value)){setEmailFeedback(value?'Enter a valid email address.':'',value?'bad':'');return;}setEmailFeedback('');emailTimer=setTimeout(checkEmail,550);});
email.addEventListener('blur',()=>{email.value=email.value.trim().toLowerCase();clearTimeout(emailTimer);checkEmail();});
function normalizePhone(value){let d=value.replace(/\D/g,'');if(d.startsWith('63'))d='0'+d.slice(2);else if(d.startsWith('9'))d='0'+d;return d.slice(0,11);}
phone.addEventListener('focus',()=>{if(!phone.value)phone.value='09';});phone.addEventListener('input',()=>{phone.value=normalizePhone(phone.value);});
const hidden=$('spCraftCategory'),toggle=$('spCategoryToggle'),options=$('spCategoryOptions'),checks=[...document.querySelectorAll('.sp-category-check')],categoryFeedback=$('spCategoryFeedback');
function syncCategories(changed){let selected=checks.filter(x=>x.checked).map(x=>x.value);if(Array.from(selected.join(', ')).length>100){if(changed)changed.checked=false;selected=checks.filter(x=>x.checked).map(x=>x.value);categoryFeedback.textContent='Maximum 100 characters for combined categories.';categoryFeedback.className='sp-feedback bad';}else{categoryFeedback.textContent='';categoryFeedback.className='sp-feedback';}
hidden.value=selected.join(', ');toggle.querySelector('.sp-category-label').textContent=selected.length?selected.join(', '):'Choose categories';toggle.title=selected.join(', ');}
function showCategories(open){options.hidden=!open;toggle.setAttribute('aria-expanded',String(open));}
toggle.addEventListener('click',()=>showCategories(options.hidden));checks.forEach(check=>check.addEventListener('change',()=>syncCategories(check)));syncCategories();
desc.addEventListener('input',()=>{$('spDescCount').textContent=`${Array.from(desc.value.trim()).length} / 300 characters (minimum 20)`;});desc.dispatchEvent(new Event('input'));
const suggestions=$('spSuggestions'),names=Object.keys(barangays);
function selectBarangay(name){barangay.value=name;zip.value=barangays[name];suggestions.hidden=true;barangay.setAttribute('aria-expanded','false');}
function showSuggestions(){const word=barangay.value.trim().toLowerCase();const matches=names.filter(name=>name.toLowerCase().includes(word)).slice(0,10);suggestions.replaceChildren();if(!matches.length){suggestions.hidden=true;barangay.setAttribute('aria-expanded','false');return;}for(const name of matches){const b=document.createElement('button');b.type='button';b.textContent=name;b.setAttribute('role','option');b.addEventListener('mousedown',event=>event.preventDefault());b.addEventListener('click',()=>selectBarangay(name));suggestions.append(b);}suggestions.hidden=false;barangay.setAttribute('aria-expanded','true');}
barangay.addEventListener('focus',showSuggestions);barangay.addEventListener('input',()=>{zip.value='';showSuggestions();});barangay.addEventListener('blur',()=>{setTimeout(()=>{const exact=names.find(name=>name.toLowerCase()===barangay.value.trim().toLowerCase());if(exact)selectBarangay(exact);else{zip.value='';suggestions.hidden=true;barangay.setAttribute('aria-expanded','false');}},140);});
if(barangays[barangay.value])zip.value=barangays[barangay.value];city.value='Taguig City';
document.addEventListener('click',event=>{if(!event.target.closest('#spCategoryWrap'))showCategories(false);if(!event.target.closest('#spBarangayWrap')){suggestions.hidden=true;barangay.setAttribute('aria-expanded','false');}});
function validate(){setError('');const values={email:email.value.trim().toLowerCase(),phone:normalizePhone(phone.value),businessname:business.value.trim(),craftcategory:hidden.value,businessdescription:desc.value.trim(),streetaddress:street.value.trim(),barangay:barangay.value.trim(),city:city.value,zipcode:zip.value};
const checks=[ [email,emailFormat(values.email),'Enter a valid email address.'],[phone,/^09\d{9}$/.test(values.phone),'Enter an 11-digit mobile number beginning with 09.'],[business,values.businessname.length>0&&Array.from(values.businessname).length<=50,'Business name must be 1–50 characters.'],[toggle,values.craftcategory.length>0&&Array.from(values.craftcategory).length<=100,'Choose at least one craft category (100 characters total maximum).'],[desc,Array.from(values.businessdescription).length>=20&&Array.from(values.businessdescription).length<=300,'Business description must contain 20–300 characters.'],[street,values.streetaddress.length>0&&Array.from(values.streetaddress).length<=255,'Enter your street address.'],[barangay,!!barangays[values.barangay],'Choose a Taguig barangay from the suggestions.'],[zip,values.zipcode===barangays[values.barangay],'ZIP code does not match your barangay.'],...(($('spGender')?.tagName==='SELECT'&&$('spGender').value)?[[$('spGender'),['male','female','prefer not to say'].includes($('spGender').value),'Choose a valid gender.']]:[]),...(birthInput?.value?[[birthButton,/^\d{4}-\d{2}-\d{2}$/.test(birthInput.value)&&birthInput.value<=(new Date()).toISOString().slice(0,10),'Choose a valid birthday.']]:[])];
for(const [element,valid,message] of checks){if(!valid){setError(message);element.focus({preventScroll:false});return false;}}phone.value=values.phone;return true;}
const save=$('spSave');function setSaving(busy){saving=busy;save.disabled=busy;save.textContent=busy?'Sending code...':'Save Changes';}
const digits=[...document.querySelectorAll('.sp-otp-digit')],otpValue=$('spOtpValue'),verify=$('spOtpVerify'),otpEmail=$('spOtpEmail'),otpMessage=$('spOtpMessage'),resend=$('spOtpResend');
function setOtpMessage(message,kind=''){otpMessage.textContent=message;otpMessage.className='sp-otp-message'+(kind?' '+kind:'');}
function syncDigits(){otpValue.value=digits.map(x=>x.value).join('');digits.forEach(x=>x.classList.toggle('filled',!!x.value));verify.disabled=verifying||!/^\d{6}$/.test(otpValue.value);}
function fillDigits(text,start=0){String(text).replace(/\D/g,'').slice(0,6-start).split('').forEach((c,i)=>digits[start+i].value=c);syncDigits();digits[Math.min(5,start+String(text).replace(/\D/g,'').length)].focus({preventScroll:true});}
digits.forEach((digit,i)=>{digit.addEventListener('input',()=>{const value=digit.value.replace(/\D/g,'');digit.value='';if(value)fillDigits(value,i);else syncDigits();setOtpMessage('');});digit.addEventListener('paste',event=>{event.preventDefault();fillDigits(event.clipboardData?.getData('text')||'',i);setOtpMessage('');});digit.addEventListener('keydown',event=>{if(event.key==='Backspace'){event.preventDefault();if(digit.value)digit.value='';else if(i>0){digits[i-1].value='';digits[i-1].focus({preventScroll:true});}syncDigits();setOtpMessage('');}else if(event.key==='ArrowLeft'&&i>0){event.preventDefault();digits[i-1].focus({preventScroll:true});}else if(event.key==='ArrowRight'&&i<5){event.preventDefault();digits[i+1].focus({preventScroll:true});}else if(event.key==='Enter'){event.preventDefault();if(!verify.disabled)verify.click();}});digit.addEventListener('focus',()=>digit.select());});
function startCooldown(seconds){clearInterval(cooldown);cooldownEnd=Date.now()+Math.max(0,Number(seconds)||0)*1000;function draw(){const left=Math.max(0,Math.ceil((cooldownEnd-Date.now())/1000));resend.disabled=left>0||verifying;resend.textContent=left>0?`Resend code (${left}s)`:'Resend code';if(!left)clearInterval(cooldown);}draw();cooldown=setInterval(draw,1000);}
function openOtp(masked,seconds){if(calendar)calendar.hidden=true;birthButton?.setAttribute('aria-expanded','false');digits.forEach(x=>x.value='');otpValue.value='';setOtpMessage('');otpEmail.textContent=masked||email.value;otp.classList.add('open');otp.setAttribute('aria-hidden','false');edit.setAttribute('aria-hidden','true');syncDigits();startCooldown(seconds);lockBody();digits[0].focus({preventScroll:true});}
async function closeOtp(){if(verifying)return;otp.classList.remove('open');otp.setAttribute('aria-hidden','true');edit.setAttribute('aria-hidden','false');clearInterval(cooldown);lockBody();email.focus({preventScroll:true});try{await request('sp_email_cancel');}catch(e){}}
$('spOtpClose').addEventListener('click',closeOtp);$('spOtpCancel').addEventListener('click',closeOtp);
form.addEventListener('submit',async event=>{if(saving){event.preventDefault();return;}if(!validate()){event.preventDefault();return;}if(email.value.trim().toLowerCase()===currentEmail){save.disabled=true;save.textContent='Saving...';return;}event.preventDefault();setSaving(true);try{const values=Object.fromEntries(new FormData(form).entries());const result=await request('sp_email_start',values);if(!result.success){setError(result.message||'Unable to send verification code.');return;}openOtp(result.masked_email,result.resend_seconds);}catch(e){setError('Unable to send verification code right now. Please try again.');}finally{setSaving(false);}});
verify.addEventListener('click',async()=>{if(verifying||verify.disabled)return;verifying=true;syncDigits();verify.textContent='Verifying...';try{const result=await request('sp_email_verify',{otp:otpValue.value});if(!result.success){setOtpMessage(result.message||'Incorrect verification code.','bad');digits.forEach(x=>x.value='');syncDigits();digits[0].focus({preventScroll:true});return;}setOtpMessage('Email verified. Saving changes...','good');window.location.assign(result.redirect||'profile_seller.php');}catch(e){setOtpMessage('Verification unavailable. Please try again.','bad');}finally{verifying=false;verify.textContent='Verify & Save Changes';syncDigits();}});
resend.addEventListener('click',async()=>{if(resend.disabled||verifying)return;resend.disabled=true;try{const result=await request('sp_email_resend');setOtpMessage(result.message|| (result.success?'New code sent.':'Could not resend code.'),result.success?'good':'bad');startCooldown(result.resend_seconds||45);}catch(e){setOtpMessage('Unable to resend right now.','bad');startCooldown(30);}});
// Close dialogs only via their Close / Cancel controls.
if(edit.classList.contains('open'))lockBody();
})();
</script>
</body>
</html>
