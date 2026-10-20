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
require_once __DIR__ . '/config/private_storage.php';
require_once __DIR__ . '/includes/otp_service.php';
if (empty($_SESSION['seller_application_csrf'])) {
    $_SESSION['seller_application_csrf'] = bin2hex(random_bytes(32));
}
$error = '';
$application_submitted = false;
$sellerOtpFlow = 'seller_registration';
class SellerUploadException extends RuntimeException {}
function sellerTextLength(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : (preg_match_all('/./us', $value, $match) ?: 0);
}
function sellerIniBytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $number = (float)$value;
    return match ($unit) {
        'g' => (int)($number * 1024 * 1024 * 1024),
        'm' => (int)($number * 1024 * 1024),
        'k' => (int)($number * 1024),
        default => (int)$number,
    };
}
function sellerRequestTooLarge(): bool
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return false;
    }
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMax = sellerIniBytes((string)ini_get('post_max_size'));
    return $contentLength > 0 && $postMax > 0 && $contentLength > $postMax;
}
function saveSellerUpload(
    array $file,
    string $label,
    string $uploadDirectory,
    bool $allowPdf
): string {
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        throw new SellerUploadException("Please upload your {$label}.");
    }
    if (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        throw new SellerUploadException("The {$label} exceeds the server upload limit.");
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new SellerUploadException("The {$label} could not be uploaded. Please try again.");
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new SellerUploadException("Invalid {$label} upload.");
    }
    if (($file['size'] ?? 0) < 1 || ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new SellerUploadException("The {$label} must not exceed 5 MB.");
    }
    if (!class_exists('finfo')) {
        throw new SellerUploadException('File validation is unavailable on the server.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if ($allowPdf) {
        $allowed['application/pdf'] = 'pdf';
    }
    if (!isset($allowed[$mime])) {
        $message = $allowPdf
            ? "The {$label} must be a PDF, JPG, PNG, or WEBP file."
            : "The {$label} must be a JPG, PNG, or WEBP image.";
        throw new SellerUploadException($message);
    }
    if (
        !is_dir($uploadDirectory)
        && !mkdir($uploadDirectory, 0700, true)
        && !is_dir($uploadDirectory)
    ) {
        throw new SellerUploadException('Unable to prepare document storage.');
    }
    if (!is_writable($uploadDirectory)) {
        throw new SellerUploadException('Document storage is not writable on the server.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $target = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new SellerUploadException("Unable to save the {$label}.");
    }
    @chmod($target, 0600);
    return $filename;
}
function saveSellerDocument(array $file, string $label, string $uploadDirectory): string
{
    return saveSellerUpload($file, $label, $uploadDirectory, true);
}
function saveSellerIdImage(array $file, string $label, string $uploadDirectory): string
{
    return saveSellerUpload($file, $label, $uploadDirectory, false);
}
function getBusinessCraftCategories(mysqli $conn): array
{
    $categories = [];
    try {
        $result = $conn->query(
            'SELECT category_name
             FROM craft_categories
             ORDER BY category_id ASC'
        );
        if (!$result) {
            error_log('Unable to load craft categories: ' . $conn->error);
            return [];
        }
        while ($row = $result->fetch_assoc()) {
            $category = trim((string)($row['category_name'] ?? ''));
            if ($category !== '') {
                $categories[] = $category;
            }
        }
        $result->free();
    } catch (mysqli_sql_exception $e) {
        error_log('Unable to load craft categories: ' . $e->getMessage());
        return [];
    }
    return $categories;
}
function sellerEmailAccountState(mysqli $conn, string $email): ?array
{
    $stmt = $conn->prepare(
        'SELECT
            u.SellerOrNot,
            u.AdminOrNot,
            u.account_status,
            (
                SELECT sa.status
                FROM seller_applications sa
                WHERE sa.user_id = u.id
                ORDER BY sa.submitted_at DESC, sa.application_id DESC
                LIMIT 1
            ) AS seller_application_status
         FROM users u
         WHERE LOWER(u.email) = LOWER(?)
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to check this email right now.');
    }
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->bind_result(
        $sellerOrNot,
        $adminOrNot,
        $accountStatus,
        $applicationStatus
    );
    if (!$stmt->fetch()) {
        $stmt->close();
        return null;
    }
    $stmt->close();
    return [
        'SellerOrNot' => (int)$sellerOrNot,
        'AdminOrNot' => (int)$adminOrNot,
        'account_status' => (string)$accountStatus,
        'seller_application_status' => $applicationStatus !== null
            ? (string)$applicationStatus
            : '',
    ];
}
function sellerEmailConflictMessage(array $account): string
{
    if ((int)($account['AdminOrNot'] ?? 0) === 1) {
        return 'This email already has an Administrator account. Please use another email.';
    }
    if (
        (int)($account['SellerOrNot'] ?? 0) === 1
        && strtolower((string)($account['account_status'] ?? '')) === 'active'
    ) {
        return 'This email already has an approved Seller account. Please log in instead.';
    }
    $applicationStatus = strtolower(
        trim((string)($account['seller_application_status'] ?? ''))
    );
    if ($applicationStatus === 'pending') {
        return 'This email already has a pending Seller application. Please log in to check its status.';
    }
    if ($applicationStatus === 'rejected') {
        return 'This email already has a Seller application. Please log in first to resubmit it.';
    }
    return 'This email already has a LokalCraft Buyer account. Please log in first, then choose Become a Seller.';
}
function sellerNameIsValid(string $value, bool $allowApostrophe = false): bool
{
    if ($value === '' || sellerTextLength($value) > 50) return false;
    $separator = $allowApostrophe ? "[ '-]" : "[ -]";
    return preg_match('/^\p{L}+(?:' . $separator . '\p{L}+)*$/u', $value) === 1;
}
function sellerFormatName(string $value): string
{
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    $value = function_exists('mb_convert_case')
        ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8')
        : ucwords(strtolower($value), " -'");
    // Preserve standard surname suffixes without allowing digits or punctuation.
    return preg_replace_callback('/\b(Jr|Sr|Ii|Iii|Iv|Vi|Vii|Viii|Ix|Xi)\z/u', static function ($matches) {
        return in_array(strtolower($matches[1]), ['jr','sr'], true) ? ucfirst(strtolower($matches[1])) : strtoupper($matches[1]);
    }, $value) ?? $value;
}
function sellerNormalizePhone(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (str_starts_with($digits, '63')) return '0' . substr($digits, 2);
    if (str_starts_with($digits, '9')) return '0' . $digits;
    return $digits;
}
function sellerEmailIsValid(string $email): bool
{
    if ($email === '' || strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $domain = strtolower((string)substr(strrchr($email, '@') ?: '', 1));
    if ($domain === 'g.mail.com' || $domain === 'gmail.con' || $domain === 'yahoo.con') return false;
    // Verify mail DNS only if the server's DNS resolver is working.
    // OTP confirmation remains the definitive check that the address is reachable.
    if (function_exists('checkdnsrr') && checkdnsrr('gmail.com', 'MX')) {
        return checkdnsrr($domain, 'MX');
    }
    return true;
}
function sellerTaguigBarangays(): array
{
    // Official 38 Taguig barangays (PSA PSGC); ZIP mappings are area postal routing references.
    // Some original Taguig ZIP areas differ across postal directories; review before production.
    return [
        'Bagumbayan'=>'1630','Bambang'=>'1637','Calzada'=>'1630',
        'Central Bicutan'=>'1637','Central Signal Village'=>'1637','Fort Bonifacio'=>'1637',
        'Hagonoy'=>'1630','Ibayo-Tipas'=>'1630','Katuparan'=>'1637',
        'Ligid-Tipas'=>'1638','Lower Bicutan'=>'1632','Maharlika Village'=>'1630',
        'Napindan'=>'1630','New Lower Bicutan'=>'1637','North Daang Hari'=>'1637',
        'North Signal Village'=>'1637','Palingon'=>'1630','Pinagsama'=>'1630',
        'San Miguel'=>'1637','Santa Ana'=>'1630','South Daang Hari'=>'1637',
        'South Signal Village'=>'1637','Tanyag'=>'1637','Tuktukan'=>'1637',
        'Upper Bicutan'=>'1633','Ususan'=>'1632','Wawa'=>'1630','Western Bicutan'=>'1630',
        'Cembo'=>'1640','Comembo'=>'1641','East Rembo'=>'1643','Pembo'=>'1642',
        'Pitogo'=>'1646','Post Proper Northside'=>'1647','Post Proper Southside'=>'1648',
        'Rizal'=>'1649','South Cembo'=>'1645','West Rembo'=>'1644',
    ];
}
function sellerPasswordIsValid(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[a-z]/', $password)
        && preg_match('/[0-9]/', $password);
}
$validIdTypes = [
    'TIN ID' => ['requires_back' => true, 'recommended' => true],
    'PhilSys National ID' => ['requires_back' => true, 'recommended' => false],
    "Driver's License" => ['requires_back' => true, 'recommended' => false],
    'Passport' => ['requires_back' => false, 'recommended' => false],
    'UMID' => ['requires_back' => true, 'recommended' => false],
    'PRC ID' => ['requires_back' => true, 'recommended' => false],
    'Postal ID' => ['requires_back' => true, 'recommended' => false],
];
$businessDocumentTypes = [
    'DTI Business Name Registration',
    'BIR Certificate of Registration (Form 2303)',
    "Mayor's / Business Permit",
];
$craftCategories = isset($conn) && $conn instanceof mysqli ? getBusinessCraftCategories($conn) : [];
if ($craftCategories === []) {
    $error = 'Unable to load craft categories from the database. Please refresh the page or contact the administrator.';
}
$requestTooLarge = sellerRequestTooLarge();
if ($requestTooLarge) {
    $error = 'The selected files are too large for the server request limit. Please upload smaller files and try again.';
}
$otpActions = [
    'seller_check_email',
    'seller_request_otp',
    'seller_resend_otp',
    'seller_verify_otp',
    'seller_cancel_otp',
];
if (
    !$requestTooLarge
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array((string)($_POST['action'] ?? ''), $otpActions, true)
) {
    header('Content-Type: application/json; charset=utf-8');
    if (
        !hash_equals(
            (string)($_SESSION['seller_application_csrf'] ?? ''),
            (string)($_POST['csrf_token'] ?? '')
        )
    ) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Your form session expired. Reload the page and try again.',
        ]);
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'seller_check_email') {
        $checkEmail = strtolower(trim((string)($_POST['email'] ?? '')));
        if (!sellerEmailIsValid($checkEmail)) {
            echo json_encode(['success'=>false, 'field'=>'email', 'message'=>'Enter a valid email address.']);
            exit;
        }
        try {
            $account = sellerEmailAccountState($conn, $checkEmail);
            echo json_encode($account
                ? ['success'=>false, 'field'=>'email', 'message'=>sellerEmailConflictMessage($account)]
                : ['success'=>true, 'available'=>true, 'message'=>'Email is available.']);
        } catch (Throwable $e) {
            error_log('Seller email availability: ' . $e->getMessage());
            http_response_code(503);
            echo json_encode(['success'=>false, 'message'=>'Email check unavailable. Try again.']);
        }
        exit;
    }
    if ($action === 'seller_cancel_otp') {
        otpClearChallenge($sellerOtpFlow);
        unset($_SESSION['seller_registration_email_verified']);
        echo json_encode(['success' => true]);
        exit;
    }
    if ($action === 'seller_resend_otp') {
        $result = otpResendChallenge($sellerOtpFlow);
        $result['resend_seconds'] = otpResendSeconds($sellerOtpFlow);
        echo json_encode($result);
        exit;
    }
    if ($action === 'seller_verify_otp') {
        $result = otpVerifyChallenge(
            $sellerOtpFlow,
            (string)($_POST['otp'] ?? '')
        );
        if (!empty($result['success'])) {
            $verifiedEmail = strtolower(
                trim((string)($result['email'] ?? ''))
            );
            $_SESSION['seller_registration_email_verified'] = [
                'email' => $verifiedEmail,
                'verified_at' => time(),
                'expires_at' => time() + 1800,
            ];
            otpClearChallenge($sellerOtpFlow);
            $result['verified_email'] = $verifiedEmail;
        }
        echo json_encode($result);
        exit;
    }
    $checkEmail = strtolower(trim((string)($_POST['email'] ?? '')));
    $firstNameForOtp = trim((string)($_POST['firstname'] ?? ''));
    $lastNameForOtp = trim((string)($_POST['lastname'] ?? ''));
    if (
        $checkEmail === ''
        || sellerTextLength($checkEmail) > 100
        || !sellerEmailIsValid($checkEmail)
    ) {
        echo json_encode([
            'success' => false,
            'field' => 'email',
            'message' => 'Please enter a valid email address.',
        ]);
        exit;
    }
    if (
        $firstNameForOtp === ''
        || !sellerNameIsValid($firstNameForOtp)
    ) {
        echo json_encode([
            'success' => false,
            'field' => 'firstname',
            'message' => 'Please enter a valid first name.',
        ]);
        exit;
    }
    if (
        $lastNameForOtp === ''
        || !sellerNameIsValid($lastNameForOtp)
    ) {
        echo json_encode([
            'success' => false,
            'field' => 'lastname',
            'message' => 'Please enter a valid last name.',
        ]);
        exit;
    }
    try {
        $account = sellerEmailAccountState($conn, $checkEmail);
        if ($account) {
            echo json_encode([
                'success' => false,
                'field' => 'email',
                'message' => sellerEmailConflictMessage($account),
            ]);
            exit;
        }
        unset($_SESSION['seller_registration_email_verified']);
        otpStartChallenge(
            $sellerOtpFlow,
            $checkEmail,
            trim($firstNameForOtp . ' ' . $lastNameForOtp),
            ['email' => $checkEmail],
            'verify your email for seller registration'
        );
        echo json_encode([
            'success' => true,
            'message' => 'A 6-digit verification code was sent to your email.',
            'masked_email' => otpMaskEmail($checkEmail),
            'resend_seconds' => otpResendSeconds($sellerOtpFlow),
        ]);
        exit;
    } catch (Throwable $e) {
        error_log('Seller OTP error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'field' => 'email',
            'message' => 'Unable to send the verification code right now. Please try again.',
        ]);
        exit;
    }
}
$verifiedSellerEmail = '';
$sellerVerifiedState = $_SESSION['seller_registration_email_verified'] ?? null;
if (is_array($sellerVerifiedState)) {
    if ((int)($sellerVerifiedState['expires_at'] ?? 0) >= time()) {
        $verifiedSellerEmail = strtolower(
            trim((string)($sellerVerifiedState['email'] ?? ''))
        );
    } else {
        unset($_SESSION['seller_registration_email_verified']);
    }
}
if (
    !$requestTooLarge
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && !in_array((string)($_POST['action'] ?? ''), $otpActions, true)
) {
    if (
        !hash_equals(
            (string)($_SESSION['seller_application_csrf'] ?? ''),
            (string)($_POST['csrf_token'] ?? '')
        )
    ) {
        http_response_code(403);
        exit('Invalid form token. Reload the page and try again.');
    }
    $firstname = trim((string)($_POST['firstname'] ?? ''));
    $lastname = trim((string)($_POST['lastname'] ?? ''));
    $middlename = trim((string)($_POST['middlename'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $phone = sellerNormalizePhone((string)($_POST['phone'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    $businessname = trim((string)($_POST['businessname'] ?? ''));
    $craftcategory = trim((string)($_POST['craftcategory'] ?? ''));
    $businessdescription = trim((string)($_POST['businessdescription'] ?? ''));
    $valididType = trim((string)($_POST['validid_type'] ?? ''));
    $businessDocumentType = trim((string)($_POST['business_document_type'] ?? ''));
    $streetaddress = trim((string)($_POST['streetaddress'] ?? ''));
    $barangay = trim((string)($_POST['barangay'] ?? ''));
    $city = trim((string)($_POST['city'] ?? ''));
    $zipcode = trim((string)($_POST['zipcode'] ?? ''));
    $taguigBarangays = sellerTaguigBarangays();
    $selectedBarangay = null;
    foreach ($taguigBarangays as $brgyName => $brgyZip) {
        if (strcasecmp($brgyName, $barangay) === 0) { $selectedBarangay = $brgyName; break; }
    }
    if ($selectedBarangay !== null) $barangay = $selectedBarangay;
    $validid = $_FILES['validid'] ?? null;
    $valididBack = $_FILES['validid_back'] ?? null;
    $businesspermit = $_FILES['businesspermit'] ?? null;
    $validIdRequiresBack = isset($validIdTypes[$valididType])
        ? (bool)$validIdTypes[$valididType]['requires_back']
        : false;
    $verifiedEmailState = $_SESSION['seller_registration_email_verified'] ?? null;
    if ($firstname === '' || $lastname === '' || $email === '' || $phone === '') {
        $error = 'Please complete all required personal information.';
    } elseif (
        !sellerNameIsValid($firstname)
    ) {
        $error = 'First name: letters, spaces and hyphens only, up to 50 characters.';
    } elseif (
        $middlename !== ''
        && (
            !sellerNameIsValid($middlename, true)
        )
    ) {
        $error = 'Middle name: letters, spaces, hyphens and apostrophes only, up to 50 characters.';
    } elseif (
        !sellerNameIsValid($lastname)
    ) {
        $error = 'Last name: letters, spaces and hyphens only, up to 50 characters. Suffixes such as Jr or III may follow the surname.';
    } elseif (
        !sellerEmailIsValid($email)
    ) {
        $error = 'Please enter a valid email address.';
    } elseif (!preg_match('/^09\d{9}$/', $phone)) {
        $error = 'Phone number must be 11 digits and start with 09.';
    } elseif (!sellerPasswordIsValid($password)) {
        $error = 'Password needs at least 8 characters, an uppercase letter, a lowercase letter, and a number.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (
        !is_array($verifiedEmailState)
        || (int)($verifiedEmailState['expires_at'] ?? 0) < time()
        || strcasecmp(
            trim((string)($verifiedEmailState['email'] ?? '')),
            $email
        ) !== 0
    ) {
        $error = 'Please verify your email using the OTP before submitting the seller application.';
    } elseif (
        $businessname === ''
        || sellerTextLength($businessname) > 50
        || $craftcategory === ''
        || sellerTextLength($businessdescription) < 20
        || sellerTextLength($businessdescription) > 300
    ) {
        $error = 'Business name must be 1–50 characters, and description 20–300 characters.';
    } elseif (
        sellerTextLength($craftcategory) > 100
        || count($selectedCategories = array_map('trim', explode(',', $craftcategory))) > count($craftCategories)
        || count(array_unique($selectedCategories)) !== count($selectedCategories)
        || count(array_filter($selectedCategories, static fn($category) => !in_array($category, $craftCategories, true))) > 0
    ) {
        $error = 'Select one or more valid categories (combined maximum: 100 characters).';
    } elseif (!isset($validIdTypes[$valididType])) {
        $error = 'Please select one of the accepted valid ID types.';
    } elseif (!in_array($businessDocumentType, $businessDocumentTypes, true)) {
        $error = 'Please select a valid business verification document type.';
    } elseif (
        $streetaddress === ''
        || $barangay === ''
        || $city === ''
        || $zipcode === ''
    ) {
        $error = 'Please complete all required location information.';
    } elseif (sellerTextLength($streetaddress) > 255) {
        $error = 'Street address must be 255 characters or fewer.';
    } elseif (sellerTextLength($barangay) > 25 || $selectedBarangay === null) {
        $error = 'Barangay must be one of the 38 Taguig barangays.';
    } elseif ($city !== 'Taguig City') {
        $error = 'Only businesses in Taguig City can register.';
    } elseif (!preg_match('/^[0-9]{4}$/', $zipcode)) {
        $error = 'Please enter a valid 4-digit ZIP code.';
    } elseif (
        !is_array($validid)
        || ($validid['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    ) {
        $error = $valididType === 'Passport'
            ? 'Please upload the passport information page.'
            : 'Please upload the front image of your selected valid ID.';
    } elseif (
        $validIdRequiresBack
        && (
            !is_array($valididBack)
            || ($valididBack['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        )
    ) {
        $error = 'Please upload the back image of your selected valid ID.';
    } elseif (
        !is_array($businesspermit)
        || ($businesspermit['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    ) {
        $error = 'Please upload your selected business verification document.';
    } elseif (!isset($_POST['agreeTerms'])) {
        $error = 'Please agree to the Terms and Conditions, Privacy Policy, and Seller Agreement.';
    }
    $createdFiles = [];
    $transactionStarted = false;
    if ($error === '') {
        try {
            $account = sellerEmailAccountState($conn, $email);
            if ($account) {
                throw new SellerUploadException(
                    sellerEmailConflictMessage($account)
                );
            }
            $uploadDirectory = LOKALCRAFT_SELLER_DOCUMENT_ROOT;
            $validIdLabel = $valididType === 'Passport'
                ? 'Passport Information Page'
                : $valididType . ' Front';
            $validIdPath = saveSellerIdImage(
                $validid,
                $validIdLabel,
                $uploadDirectory
            );
            $createdFiles[] = $validIdPath;
            $validIdBackPath = null;
            if ($validIdRequiresBack) {
                $validIdBackPath = saveSellerIdImage(
                    $valididBack,
                    $valididType . ' Back',
                    $uploadDirectory
                );
                $createdFiles[] = $validIdBackPath;
            }
            $businessPermitPath = saveSellerDocument(
                $businesspermit,
                $businessDocumentType,
                $uploadDirectory
            );
            $createdFiles[] = $businessPermitPath;
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            if (!is_string($hashedPassword) || $hashedPassword === '') {
                throw new SellerUploadException('Unable to secure the password.');
            }
            $firstname = sellerFormatName($firstname);
            $lastname = sellerFormatName($lastname);
            $middleNameForDatabase = $middlename !== '' ? sellerFormatName($middlename) : null;
            $sellerOrNot = 0;
            $adminOrNot = 0;
            $accountStatus = 'pending';
            $conn->begin_transaction();
            $transactionStarted = true;
            $userStmt = $conn->prepare(
                'INSERT INTO users
                (
                    firstname,
                    middlename,
                    lastname,
                    email,
                    phone,
                    password,
                    SellerOrNot,
                    AdminOrNot,
                    account_status
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$userStmt) {
                throw new SellerUploadException('Unable to create the account.');
            }
            $userStmt->bind_param(
                'ssssssiis',
                $firstname,
                $middleNameForDatabase,
                $lastname,
                $email,
                $phone,
                $hashedPassword,
                $sellerOrNot,
                $adminOrNot,
                $accountStatus
            );
            if (!$userStmt->execute()) {
                $statementErrno = $userStmt->errno;
                $userStmt->close();
                if ($statementErrno === 1062) {
                    throw new SellerUploadException(
                        'This email already has a LokalCraft account. Please log in instead.'
                    );
                }
                throw new SellerUploadException('Failed to create the account.');
            }
            $userId = (int)$conn->insert_id;
            $userStmt->close();
            $businessStmt = $conn->prepare(
                'INSERT INTO businessinfo
                (
                    user_id,
                    businessname,
                    craftcategory,
                    businessdescription,
                    validid_type,
                    businesspermit,
                    validid,
                    validid_back,
                    business_document_type
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$businessStmt) {
                throw new SellerUploadException('Unable to save business information.');
            }
            $businessStmt->bind_param(
                'issssssss',
                $userId,
                $businessname,
                $craftcategory,
                $businessdescription,
                $valididType,
                $businessPermitPath,
                $validIdPath,
                $validIdBackPath,
                $businessDocumentType
            );
            if (!$businessStmt->execute()) {
                $businessStmt->close();
                throw new SellerUploadException('Failed to save business information.');
            }
            $businessStmt->close();
            $locationStmt = $conn->prepare(
                'INSERT INTO locationinfo
                (user_id, streetaddress, barangay, city, zipcode)
                VALUES (?, ?, ?, ?, ?)'
            );
            if (!$locationStmt) {
                throw new SellerUploadException('Unable to save location information.');
            }
            $locationStmt->bind_param(
                'issss',
                $userId,
                $streetaddress,
                $barangay,
                $city,
                $zipcode
            );
            if (!$locationStmt->execute()) {
                $locationStmt->close();
                throw new SellerUploadException('Failed to save location information.');
            }
            $locationStmt->close();
            $applicationStmt = $conn->prepare(
                "INSERT INTO seller_applications
                (user_id, status, submitted_at)
                VALUES (?, 'pending', NOW())"
            );
            if (!$applicationStmt) {
                throw new SellerUploadException('Unable to create the seller application.');
            }
            $applicationStmt->bind_param('i', $userId);
            if (!$applicationStmt->execute()) {
                $applicationStmt->close();
                throw new SellerUploadException('Failed to submit the seller application.');
            }
            $applicationStmt->close();
            $conn->commit();
            $transactionStarted = false;
            unset(
                $_SESSION['seller_registration_email_verified'],
                $_SESSION['seller_application_csrf']
            );
            otpClearChallenge($sellerOtpFlow);
            $application_submitted = true;
        } catch (Throwable $e) {
            if ($transactionStarted) {
                try {
                    $conn->rollback();
                } catch (Throwable $rollbackError) {
                    error_log('Seller registration rollback failed: ' . $rollbackError->getMessage());
                }
            }
            error_log('Seller registration failed: ' . $e->getMessage());
            $error = $e instanceof SellerUploadException
                ? $e->getMessage()
                : 'Unable to submit the application. Please try again.';
            foreach ($createdFiles as $createdFile) {
                $absolutePath =
                    LOKALCRAFT_SELLER_DOCUMENT_ROOT
                    . DIRECTORY_SEPARATOR
                    . basename($createdFile);
                if (is_file($absolutePath)) {
                    @unlink($absolutePath);
                }
            }
        }
    }
}
if ($application_submitted) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Application Submitted — LokalCraft</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, sans-serif;
      min-height: 100vh;
      background: #fff8eb;
      position: relative;
    }
    /* Same viewport-fixed background as signup.php; the seller form's height cannot resize the image. */
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
    /* The success screen uses the same 1600px public content boundary as the header. */
    .success-page { width: min(1600px, calc(100% - 64px)); min-height: calc(100svh - 82px); margin-inline: auto; display: grid; place-items: center; padding: 28px 16px; position: relative; z-index: 2; }
    .success-container {
      position: relative;
      z-index: 1;
      width: 100%;
      max-width: 560px;
      padding: 60px 40px;
      border: 1px solid rgba(220,180,135,.6);
      border-radius: 30px;
      background: rgba(255,252,245,.96);
      box-shadow: 0 25px 45px rgba(45,30,16,.12);
      text-align: center;
      animation: sellerPageEnter .65s cubic-bezier(.16,1,.3,1) both;
    }
    .success-icon {
      display: grid;
      place-items: center;
      width: 92px;
      height: 92px;
      margin: 0 auto 22px;
      border: 1px solid #cce7cb;
      border-radius: 50%;
      background: #eaf6e8;
      box-shadow: 0 10px 24px rgba(47,141,84,.10);
    }
    .success-icon svg { display: block; width: 62px; height: 62px; }
    .success-title { font-size: 1.8rem; font-weight: 800; color: #8b5423; margin-bottom: 12px; }
    .success-message { color: #5A3F28; font-size: 1rem; line-height: 1.6; margin-bottom: 30px; }
    .success-details { background: #f1e5d5; border-radius: 16px; padding: 20px; margin-bottom: 30px; text-align: left; font-size: .95rem; color: #5A3F28; line-height: 1.55; }
    .success-details strong { color: #8b5423; }
    .btn-home { display: inline-block; padding: 14px 38px; border: 0; border-radius: 60px; background: #B85C28; color: #fff; box-shadow: 0 6px 14px rgba(160,80,35,.3); font-size: 1rem; font-weight: 700; text-decoration: none; cursor: pointer; transition: .2s; }
    .btn-home:hover { background: #94481F; transform: translateY(-2px); }
    @keyframes sellerPageEnter { from { opacity: 0; transform: translateY(22px); } to { opacity: 1; transform: translateY(0); } }
    @media (max-width: 600px) {
      .success-page { width: calc(100% - 28px); min-height: calc(100svh - 70px); padding: 20px 0; }
      .success-container { padding: 40px 24px; border-radius: 24px; }
    }
    @media (prefers-reduced-motion: reduce) { .success-container { animation: none; } }
  </style>
</head>
<body>
<?php $publicActive = ''; include __DIR__ . '/public_nav.php'; ?>
<main class="success-page">
  <div class="success-container">
    <div class="success-icon" aria-hidden="true">
      <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
        <circle cx="32" cy="32" r="29" fill="#2F8D54"/>
        <circle cx="32" cy="32" r="28" stroke="#FFFFFF" stroke-opacity=".24" stroke-width="2"/>
        <path d="M18.5 32.5 28 42l18-19" stroke="#FFFFFF" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </div>
    <h1 class="success-title">Application Submitted!</h1>
    <p class="success-message">Your seller application has been submitted successfully and is now waiting for administrator review.</p>
    <div class="success-details">
      <strong>What happens next?</strong><br><br>
      Your new account is not a Seller yet. An administrator must verify your business information and submitted documents before Seller access is granted.
    </div>
    <a href="login.php" class="btn-home">Go to Login</a>
  </div>
</main>
</body>
</html>
<?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Become a Seller — LokalCraft | Support Filipino Artisans</title>
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
      overflow-x: hidden;
      min-height: 100vh;
      background: #fff8eb;
    }
    /* Same viewport-fixed background as signup.php; the seller form's height cannot resize the image. */
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
    /* Match public_nav.php's 1600px content boundary, while keeping the form readable. */
    .seller-page {
      width: min(1600px, calc(100% - 64px));
      min-height: calc(100svh - 82px);
      margin-inline: auto;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: clamp(24px, 2.4vw, 40px) 16px;
      position: relative;
      z-index: 2;
    }
    .seller-container {
      width: min(100%, 550px);
      min-width: 0;
      position: relative;
      z-index: 1;
    }
    .seller-card {
      position: relative;
      z-index: 1;
    }
    .back-link {
      display: inline-flex;
      gap: 8px;
      text-decoration: none;
      color: #8b5423;
      font-weight: 900;
      margin-bottom: 16px;
      transition: color 0.2s;
      font-size: 0.95rem;
    }
    .back-link:hover {
      color: #b45824;
    }
    .seller-card {
      background: rgba(255, 252, 245, 0.96);
      border: 1px solid rgba(220, 180, 135, 0.6);
      border-radius: 30px;
      box-shadow: 0 25px 45px rgba(45, 30, 16, 0.12);
      padding: 40px 34px 32px;
    }
    .progress-indicator {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 32px;
      gap: 8px;
    }
    .progress-step {
      min-width:0;
      border:0;
      border-radius:14px;
      padding:7px 2px;
      background:transparent;
      color:inherit;
      font:inherit;
      cursor:pointer;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
      flex: 1;
      text-align: center;
    }
    .step-circle {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: #e8dcd3;
      color: #8b5423;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.9rem;
      transition: all 0.3s ease;
    }
    .progress-step.active .step-circle {
      background: #B85C28;
      color: white;
      box-shadow: 0 4px 12px rgba(184, 92, 40, 0.3);
    }
    .progress-step.completed .step-circle {
      background: #6baf56;
      color: white;
    }
    .step-label {
      font-size: 0.75rem;
      color: #8b5423;
      font-weight: 600;
      display: none;
    }
    .progress-line {
      flex: 1;
      height: 2px;
      background: #e8dcd3;
      margin: 0 8px;
      transform: translateY(-16px);
    }
    .progress-line.active {
      background: #B85C28;
    }
    .seller-header {
      text-align: center;
      margin-bottom: 28px;
    }
    .seller-title {
      font-size: 1.8rem;
      font-weight: 800;
      color: #8b5423;
      margin-bottom: 8px;
      line-height: 1.2;
    }
    .seller-subtitle {
      color: #5A3F28;
      font-size: 0.95rem;
      line-height: 1.5;
    }
    .seller-form {
      display: flex;
      flex-direction: column;
      gap: 20px;
    }
    .form-step {
      display: none;
    }
    .form-step.active {
      display: flex;
      flex-direction: column;
      gap: 20px;
    }
    .step-title {
      font-size: 1.3rem;
      font-weight: 700;
      color: #8b5423;
      margin-bottom: 12px;
    }
    .form-row {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 16px;
    }
    .form-row.full {
      grid-template-columns: 1fr;
    }
    .form-group.form-row.full {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    .form-group {
      display: flex;
      flex-direction: column;
      min-width: 0;
      gap: 8px;
    }
    .form-group label {
      font-weight: 600;
      color: #2B1E12;
      font-size: 0.9rem;
    }
    .form-group input,
    .form-group select,
    .form-group textarea {
      background: #F9EFE2;
      border: 1.5px solid #E7CDB0;
      border-radius: 16px;
      padding: 12px 16px;
      font-size: 1rem;
      color: #2B1E12;
      font-family: inherit;
      transition: all 0.2s;
    }
    .form-group input,
    .form-group select,
    .form-group textarea {
      width: 100%;
      max-width: 100%;
      min-width: 0;
    }
    .form-group textarea {
      resize: vertical;
      min-height: 100px;
      font-size: 0.95rem;
    }
    .form-group input::placeholder,
    .form-group select::placeholder,
    .form-group textarea::placeholder {
      color: #9A7F69;
    }
    .email-check-message {
      min-height: 0;
      font-size: 0.86rem;
      line-height: 1.45;
    }
    .email-check-message:empty { display: none; }
    .email-check-message.success {
      color: #357a2d;
    }
    .email-check-message.error {
      color: #a52b2b;
    }
    .email-check-message.info {
      color: #1565c0;
    }
    .email-login-link {
      color: #B85C28;
      font-weight: 700;
      text-decoration: none;
    }
    .email-login-link:hover {
      text-decoration: underline;
    }
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
      outline: none;
      border-color: #B85C28;
      background: #FFFAF4;
      box-shadow: 0 0 0 3px rgba(184, 92, 40, 0.1);
    }
    .password-field {
      position: relative;
    }
    .password-field input {
      width: 100%;
      padding-right: 52px;
    }
    .password-toggle {
      position: absolute;
      right: 10px;
      top: 50%;
      transform: translateY(-50%);
      width: 38px;
      height: 38px;
      display: flex;
      align-items: center;
      justify-content: center;
      border: 0;
      border-radius: 50%;
      background: transparent;
      color: #8b5423;
      cursor: pointer;
      padding: 0;
      z-index: 2;
      -webkit-tap-highlight-color: transparent;
    }
    .password-toggle svg { width: 21px; height: 21px; pointer-events: none; }
    .password-match { font-size: .82rem; margin-top: 6px; line-height: 1.35; }
    .password-match.valid { color: #357a2d; }
    .password-match.invalid { color: #a52b2b; }
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
    .form-buttons {
      display: flex;
      gap: 12px;
      margin-top: 20px;
      justify-content: flex-end;
    }
    .btn {
      border: none;
      font-weight: 700;
      font-size: 0.95rem;
      padding: 12px 28px;
      border-radius: 60px;
      cursor: pointer;
      font-family: inherit;
      transition: all 0.2s ease;
    }
    .btn-back {
      background: #F9EFE2;
      color: #8b5423;
      border: 1.5px solid #E7CDB0;
    }
    .btn-back:hover {
      background: #FFF3E6;
      border-color: #C87A48;
    }
    .btn-next,
    .btn-submit {
      background: #B85C28;
      color: white;
      box-shadow: 0 6px 14px rgba(160, 80, 35, 0.3);
    }
    .btn-next:hover,
    .btn-submit:hover {
      background: #94481F;
      transform: translateY(-2px);
      box-shadow: 0 8px 18px rgba(160, 80, 35, 0.4);
    }
    .btn:active {
      transform: translateY(0);
    }
    .btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
    .review-section {
      background: #f1e5d5;
      border-radius: 16px;
      padding: 20px;
      margin-bottom: 16px;
    }
    .review-title {
      font-weight: 700;
      color: #8b5423;
      margin-bottom: 12px;
      font-size: 0.95rem;
    }
    .review-item {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
      font-size: 0.9rem;
      color: #5A3F28;
      margin-bottom: 8px;
    }
    .review-item strong {
      color: #8b5423;
      display: block;
      font-size: 0.85rem;
      margin-bottom: 2px;
    }
    .terms-checkbox {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      margin-top: 16px;
      font-size: 0.9rem;
      color: #5A3F28;
    }
    .terms-checkbox input {
      margin-top: 4px;
      cursor: pointer;
      accent-color: #B85C28;
      width: 18px;
      height: 18px;
    }
    .terms-checkbox a {
      color: #B85C28;
      text-decoration: none;
      font-weight: 600;
    }
    .terms-checkbox a:hover {
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
      background: rgba(43, 30, 18, 0.45);
    }
    .policy-modal.open {
      display: flex;
    }
    .policy-card {
      position: relative;
      z-index: 20001;
      width: min(640px, 100%);
      max-height: 90vh;
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
      padding: 18px 22px;
      border-bottom: 1px solid #E7CDB0;
    }
    .policy-header h2 {
      margin: 0;
      color: #8b5423;
      font-size: 1.25rem;
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
      max-height: calc(90vh - 76px);
      overflow-y: auto;
      padding: 22px 24px 26px;
      color: #3F2E1F;
      font-size: .92rem;
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
    @media (max-width: 600px) {
      .seller-page {
        width: calc(100% - 28px);
        min-height: calc(100svh - 70px);
        padding: 24px 0 28px;
      }
      .seller-card {
        padding: 28px 22px;
        border-radius: 24px;
      }
      .seller-title {
        font-size: 1.4rem;
      }
      .form-row {
        grid-template-columns: 1fr;
      }
      .step-label {
        display: block;
      }
      .form-buttons {
        flex-direction: column-reverse;
      }
      .btn {
        width: 100%;
      }
      .progress-indicator {
        gap: 4px;
        margin-bottom: 24px;
      }
      .progress-line {
        margin: 0 4px;
      }
      .review-item {
        grid-template-columns: 1fr;
      }
    }
    @media (min-width: 601px) and (max-width: 900px) {
      .seller-page { width: calc(100% - 40px); }
    }
    @media (max-width: 380px) {
      .seller-page { width: calc(100% - 22px); }
    }
    .document-upload-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
    .upload-card{min-width:0;padding:15px;border:1px solid #E7CDB0;border-radius:17px;background:rgba(255,252,245,.92)}
    .business-document-card{width:100%}
    .upload-card-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px}
    .upload-card-heading>div{min-width:0}
    .upload-card-heading strong{display:block;color:#5A3F28;font-size:.9rem;line-height:1.35}
    .upload-card-heading span:not(.upload-required){display:block;margin-top:4px;color:#8C7562;font-size:.75rem;line-height:1.4}
    .upload-required{flex:0 0 auto;padding:4px 7px;border-radius:999px;background:#F4E5D6;color:#8b5423;font-size:.67rem;font-weight:800}
    .document-file-input{position:absolute;width:1px;height:1px;opacity:0;pointer-events:none}
    .upload-dropzone{min-height:142px;width:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:18px;border:1.5px dashed #D6B899;border-radius:14px;background:#FFFDF9;color:#8b5423;text-align:center;cursor:pointer;transition:.2s ease}
    .upload-dropzone:hover{border-color:#B85C28;background:#FFF6EC}
    .upload-dropzone strong{font-size:.88rem}
    .upload-dropzone span:last-child{color:#947A65;font-size:.73rem}
    .upload-icon{width:38px;height:38px;display:grid;place-items:center;border-radius:50%;background:#F4E5D6;font-size:1.15rem;font-weight:900}
    .upload-preview{display:grid;gap:10px}
    .upload-preview-media{width:100%;min-height:160px;padding:0;overflow:hidden;border:1px solid #E7CDB0;border-radius:13px;background:#F9EFE2;cursor:pointer}
    .upload-preview-image{display:block;width:100%;height:180px;object-fit:contain;background:#F7F3EE}
    .upload-preview-pdf{min-height:160px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;color:#6A4A31;font-size:.8rem}
    .pdf-icon{display:grid;place-items:center;width:58px;height:70px;border-radius:8px;background:#B85C28;color:white;font-size:.9rem;font-weight:900;box-shadow:0 8px 18px rgba(160,80,35,.18)}
    .upload-file-meta{min-width:0;display:flex;flex-direction:column;gap:3px}
    .upload-file-meta strong{overflow:hidden;color:#4B3422;font-size:.82rem;text-overflow:ellipsis;white-space:nowrap}
    .upload-file-meta span{color:#8C7562;font-size:.72rem}
    .upload-actions{display:flex;flex-wrap:wrap;gap:7px}
    .upload-actions button{border:1px solid #E0C2A4;border-radius:999px;background:#FFF8EF;color:#8b5423;padding:7px 11px;font:inherit;font-size:.74rem;font-weight:800;cursor:pointer}
    .upload-actions button:hover{border-color:#B85C28;background:#FFF0E2}
    .review-document-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:9px;margin-top:12px}
    .review-document-card{display:grid;grid-template-columns:66px minmax(0,1fr);align-items:center;gap:12px;min-width:0;padding:11px 12px;border:1px solid #DFC5AA;border-radius:13px;background:#FFF9F1}
    .review-doc-preview{display:grid;place-items:center;width:66px;height:66px;overflow:hidden;border:1px solid #E7CDB0;border-radius:10px;background:#F7F2EB;color:#8b5423;font-weight:800;font-size:1.15rem}
    .review-doc-thumb{display:block;width:100%;height:100%;object-fit:contain}
    .review-doc-pdf{display:grid;place-items:center;width:100%;height:100%;background:#F4E7D8;color:#8b5423;font-size:.85rem;font-weight:900}
    .review-doc-info{display:flex;flex-direction:column;gap:5px;min-width:0}
    .review-doc-label{display:block;color:#8b5423;font-size:.78rem;font-weight:800;line-height:1.35}
    .review-doc-name{display:block;color:#5A3F28;font-size:.76rem;line-height:1.4;overflow-wrap:anywhere;word-break:break-word}
    .review-doc-size{display:block;color:#967f6a;font-size:.69rem}
    @media(max-width:760px){
      .document-upload-grid{grid-template-columns:1fr}
    }
    @media(max-width:480px){
      .review-document-card{grid-template-columns:56px minmax(0,1fr);gap:9px;padding:10px}
      .review-doc-preview{width:56px;height:58px}
    }
    .upload-dropzone[hidden],
    .upload-preview[hidden],
    .upload-preview-image[hidden],
    .upload-preview-pdf[hidden],
    #validIdBackCard[hidden],
    .review-document-card[hidden],
    #documentPreviewImage[hidden],
    #documentPreviewPdf[hidden] {
      display: none !important;
    }
    #validIdFrontCard .upload-preview-media,
    #validIdBackCard .upload-preview-media {
      min-height: 142px;
      background: #FFFDF9;
    }
    #validIdFrontCard .upload-preview-image,
    #validIdBackCard .upload-preview-image {
      width: 100%;
      height: 190px;
      object-fit: contain;
      background: #FFFDF9;
    }
    #validIdFrontCard .upload-preview,
    #validIdBackCard .upload-preview {
      gap: 9px;
    }
    #validIdFrontCard .upload-file-meta,
    #validIdBackCard .upload-file-meta {
      padding: 0 2px;
    }
    .business-document-card .upload-preview-pdf {
      min-height: 160px;
    }
    @media (max-width: 600px) {
      #validIdFrontCard .upload-preview-image,
      #validIdBackCard .upload-preview-image {
        height: 220px;
      }
    }
    .field-error {
      margin-top: 6px;
      color: #a52b2b;
      font-size: .78rem;
      font-weight: 650;
      line-height: 1.35;
    }
    .form-group input.input-invalid,
    .form-group select.input-invalid,
    .form-group textarea.input-invalid {
      border-color: #dc3545 !important;
      background: #fff7f7 !important;
      box-shadow: 0 0 0 3px rgba(220,53,69,.08) !important;
    }
    .upload-card.input-invalid {
      border-color: #dc3545 !important;
      box-shadow: 0 0 0 3px rgba(220,53,69,.08);
    }
    .terms-checkbox.input-invalid {
      padding: 10px;
      border: 1px solid rgba(220,53,69,.35);
      border-radius: 12px;
      background: rgba(220,53,69,.05);
    }
    .seller-otp-modal {
      position: fixed;
      inset: 0;
      z-index: 110000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .seller-otp-modal.open { display: flex; }
    .seller-otp-backdrop {
      position: absolute;
      inset: 0;
      background: rgba(43,30,18,.55);
      backdrop-filter: blur(5px);
    }
    .seller-otp-card {
      position: relative;
      z-index: 1;
      width: min(470px, 100%);
      max-height: calc(100dvh - 40px);
      overflow-y: auto;
      padding: 30px 28px 26px;
      border: 1px solid #E7CDB0;
      border-radius: 24px;
      background: #FFFCF7;
      box-shadow: 0 28px 70px rgba(45,30,16,.28);
    }
    .seller-otp-close {
      position: absolute;
      top: 12px;
      right: 12px;
      width: 36px;
      height: 36px;
      border: 0;
      border-radius: 50%;
      background: #F9EFE2;
      color: #8b5423;
      font-size: 1.4rem;
      cursor: pointer;
    }
    .seller-otp-icon {
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
    .seller-otp-card h2 {
      margin: 0;
      color: #8b5423;
      text-align: center;
      font-size: 1.5rem;
    }
    .seller-otp-subtitle {
      margin: 8px 0 0;
      color: #6f5a47;
      text-align: center;
      font-size: .88rem;
    }
    .seller-otp-email {
      margin-top: 7px;
      color: #3f2e1f;
      text-align: center;
      font-size: .9rem;
      font-weight: 800;
      overflow-wrap: anywhere;
    }
    .seller-otp-message {
      display: none;
      margin-top: 14px;
      padding: 10px 12px;
      border-radius: 11px;
      text-align: center;
      font-size: .8rem;
      line-height: 1.4;
    }
    .seller-otp-message.error {
      display: block;
      border: 1px solid #efc0c0;
      background: #fff2f2;
      color: #a83d3d;
    }
    .seller-otp-message.success {
      display: block;
      border: 1px solid #bfe2c9;
      background: #f2fbf5;
      color: #2f7a4d;
    }
    .seller-otp-inputs {
      display: grid;
      grid-template-columns: repeat(6, minmax(0, 1fr));
      gap: 8px;
      margin-top: 20px;
    }
    .seller-otp-digit {
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
    .seller-otp-digit:focus {
      border-color: #B85C28;
      background: #FFFAF4;
      box-shadow: 0 0 0 3px rgba(184,92,40,.1);
    }
    .seller-otp-primary {
      width: 100%;
      margin-top: 18px;
      border: 0;
      border-radius: 60px;
      background: #B85C28;
      color: #fff;
      padding: 14px 18px;
      font: inherit;
      font-weight: 800;
      cursor: pointer;
    }
    .seller-otp-primary:disabled,
    .seller-otp-text:disabled {
      opacity: .55;
      cursor: not-allowed;
    }
    .seller-otp-actions {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 9px;
      margin-top: 13px;
      color: #c2aa96;
      flex-wrap: wrap;
    }
    .seller-otp-text {
      border: 0;
      background: transparent;
      color: #B85C28;
      padding: 5px;
      font: inherit;
      font-size: .8rem;
      font-weight: 800;
      cursor: pointer;
    }
    .seller-otp-delivery-help {
      max-width: 350px;
      margin: 12px auto 0;
      color: #806957;
      font-size: .78rem;
      line-height: 1.5;
      text-align: center;
      overflow-wrap: anywhere;
    }
    .seller-otp-expiry {
      margin: 10px 0 0;
      color: #927b68;
      text-align: center;
      font-size: .74rem;
    }
    @media (max-width: 600px) {
      .seller-otp-modal {
        align-items: flex-start;
        overflow-y: auto;
        padding:
          max(12px, env(safe-area-inset-top))
          10px
          max(12px, env(safe-area-inset-bottom));
      }
      .seller-otp-card {
        margin: auto 0;
        max-height: calc(100dvh - 24px);
        padding: 26px 16px 22px;
        border-radius: 20px;
      }
      .seller-otp-inputs { gap: 5px; }
      .seller-otp-digit { height: 47px; font-size: 1.05rem; }
    }
    .policy-modal {
      z-index: 2147483647 !important;
      isolation: isolate;
    }
    .policy-modal .policy-card {
      position: relative;
      z-index: 1 !important;
    }
    #personalNextBtn.btn-loading {
      pointer-events: none;
      opacity: .82;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 9px;
    }
    .next-loading-spinner {
      width: 16px;
      height: 16px;
      border: 2px solid rgba(255,255,255,.45);
      border-top-color: #fff;
      border-radius: 50%;
      animation: lokalcraftOtpSpin .75s linear infinite;
      flex: 0 0 16px;
    }
    @keyframes lokalcraftOtpSpin {
      to { transform: rotate(360deg); }
    }
    .seller-otp-modal {
      z-index: 2147483647 !important;
      isolation: isolate;
    }
    .seller-otp-backdrop {
      z-index: 0 !important;
    }
    .seller-otp-card {
      z-index: 1 !important;
    }
  .password-strength{display:flex;align-items:center;gap:10px;margin-top:7px;min-height:17px}
  .password-strength-bar{display:flex;gap:4px;flex:1;height:5px;max-width:110px}
  .password-strength-bar i{flex:1;border-radius:6px;background:#e6d9cb;transition:background .16s}
  .password-strength.weak i:first-child{background:#d94c47}
  .password-strength.normal i:nth-child(-n+2){background:#efa63e}
  .password-strength.strong i{background:#3a9b62}
  .password-strength-label{font-size:.75rem;font-weight:700}
  .password-strength.weak .password-strength-label{color:#bd4340}
  .password-strength.normal .password-strength-label{color:#ad781e}
  .password-strength.strong .password-strength-label{color:#338954}
  .category-select{position:relative;min-width:0}
  .category-toggle{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;min-height:47px;padding:11px 15px;border:1.5px solid #E7CDB0;border-radius:15px;background:#F9EFE2;text-align:left;color:#2B1E12;font:inherit;cursor:pointer}
  .category-options{position:absolute;z-index:120;top:calc(100% + 5px);left:0;right:0;max-height:var(--seller-dropdown-max-height,230px);overflow-y:auto;overscroll-behavior:contain;border:1px solid #E7CDB0;border-radius:14px;background:#fffdfa;box-shadow:0 13px 28px rgba(60,37,15,.14);padding:6px}
  .category-options[hidden]{display:none}
  .category-choice{display:flex;align-items:center;gap:10px;padding:10px;border-radius:9px;font-weight:500!important;cursor:pointer}
  .category-choice:hover{background:#fff1e3}
  .category-choice input{width:17px;height:17px;accent-color:#B85C28;flex:none;padding:0}
  .category-select.input-invalid .category-toggle{border-color:#dc3545}
  .barangay-combobox{position:relative;min-width:0}
  .barangay-combobox input{width:100%}
  .barangay-suggestions{position:absolute;z-index:130;top:calc(100% + 5px);left:0;right:0;max-height:var(--seller-dropdown-max-height,220px);overflow-y:auto;overscroll-behavior:contain;padding:5px;border:1px solid #E7CDB0;border-radius:13px;background:#fffdfa;box-shadow:0 12px 26px rgba(60,37,15,.15)}
  .category-options.opens-up,.barangay-suggestions.opens-up{top:auto;bottom:calc(100% + 5px)}
  .barangay-suggestions[hidden]{display:none}
  .barangay-suggestion{display:block;width:100%;padding:10px 12px;border:0;border-radius:8px;background:transparent;color:#2B1E12;text-align:left;font:inherit;cursor:pointer}
  .barangay-suggestion:hover,.barangay-suggestion.active{background:#fff0e1}
  #city[readonly]{background:#eee8e0;color:#61564b;cursor:default}
  .location-title{display:flex;align-items:center;gap:7px;position:relative}
  .location-tip{border:0;background:none;color:#956035;font:inherit;font-size:1rem;cursor:help}
  .location-tip-text{display:none;position:absolute;z-index:150;left:0;top:calc(100% + 3px);max-width:min(330px,95%);padding:10px 13px;border:1px solid #e0c3a6;border-radius:9px;background:#fffdf8;color:#59402c;box-shadow:0 10px 22px rgba(45,30,16,.14);font-size:.77rem;line-height:1.5;font-weight:400}
  .location-tip:hover+.location-tip-text,.location-tip:focus+.location-tip-text{display:block}
  @media(max-width:600px){.location-tip-text{max-width:95%}}

    .progress-step:disabled{cursor:default;opacity:.58}
    .progress-step.completed:not(:disabled):hover .step-circle{filter:brightness(.94);transform:translateY(-2px)}
    .progress-step:focus-visible{outline:2px solid #B85C28;outline-offset:3px}
    @keyframes sellerPageEnter{from{opacity:0;transform:translateY(22px)}to{opacity:1;transform:translateY(0)}}
    @keyframes sellerStepEnter{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
    .seller-card{animation:sellerPageEnter .72s cubic-bezier(.16,1,.3,1) both}
    .form-step.active{animation:sellerStepEnter .38s ease both}
    .form-group select{appearance:none;-webkit-appearance:none;width:100%;padding-right:48px;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='%238b5423' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 16px center;background-size:18px}
    .form-group select:invalid{color:#796759}
    .category-toggle{transition:border-color .2s,background-color .2s,box-shadow .2s}
    .category-toggle:focus-visible{outline:none;border-color:#B85C28;box-shadow:0 0 0 3px rgba(184,92,40,.12)}
    .verification-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:4px 0 -2px;padding-top:12px;border-top:1px solid rgba(220,180,135,.45)}
    .verification-heading-label{display:flex;align-items:center;gap:10px;color:#493627;font-weight:800;font-size:1.05rem}
    .verification-heading-label svg{width:22px;height:22px;flex-shrink:0;color:#B85C28}
    .document-guide-button{display:inline-flex;align-items:center;gap:6px;justify-content:center;border:1px solid #DCA875;border-radius:13px;padding:10px 14px;background:#FFF2E5;color:#A55325;font:inherit;font-weight:700;font-size:.85rem;cursor:pointer;transition:background .2s,border-color .2s}
    .document-guide-button:hover,.document-guide-button:focus-visible{background:#FFE8D2;border-color:#B85C28;outline-offset:2px}
    .seller-guide-modal{position:fixed;inset:0;z-index:2000000;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(43,30,18,.52);backdrop-filter:blur(3px)}
    .seller-guide-modal.open{display:flex}
    .seller-guide-card{width:min(620px,100%);max-height:calc(100dvh - 36px);overflow:hidden;display:flex;flex-direction:column;border:1px solid #E7CDB0;border-radius:22px;background:#FFFCF7;box-shadow:0 25px 60px rgba(30,18,9,.27);animation:sellerStepEnter .26s ease both}
    .seller-guide-header{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:20px 24px;border-bottom:1px solid #E7CDB0}
    .seller-guide-header h2{font-size:1.22rem;color:#443529;line-height:1.3}
    .seller-guide-close{width:40px;height:40px;flex-shrink:0;border:2px solid #30271e;border-radius:12px;background:transparent;color:#403329;font-size:1.5rem;cursor:pointer}
    .seller-guide-content{overflow-y:auto;padding:22px 25px 26px;color:#645345;line-height:1.65;font-size:.95rem}
    .seller-guide-content strong{color:#594637}
    .seller-guide-content ul{padding-left:22px;margin:12px 0}
    .seller-guide-content li{margin:6px 0}
    .seller-guide-content[hidden]{display:none}
    .upload-preview-media:focus-visible,.review-doc-preview:focus-visible{outline:3px solid #B85C28;outline-offset:3px}
    .review-doc-preview[data-file-view]{border:1px solid #E7CDB0;cursor:pointer;padding:0;font:inherit}
    @media(max-width:700px){.verification-heading{align-items:stretch}.document-guide-button{padding:9px 12px}.seller-guide-header{padding:15px 17px}.seller-guide-content{padding:17px 20px}}
    @media(prefers-reduced-motion:reduce){.seller-card,.seller-guide-card,.form-step.active{animation:none!important;transition:none!important}}
    /* Full-document viewer: fit the whole image, and give PDFs the available viewport. */
    .document-preview-modal {
      position: fixed;
      inset: 0;
      z-index: 120000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 15px;
      isolation: isolate;
      background: rgba(28, 23, 19, .84);
      -webkit-backdrop-filter: blur(5px);
      backdrop-filter: blur(5px);
    }
    .document-preview-modal.open { display: flex; }
    .document-preview-backdrop { position: absolute; inset: 0; z-index: 0; }
    .document-preview-dialog {
      position: relative;
      z-index: 1;
      display: flex;
      flex-direction: column;
      width: min(1200px, 100%);
      height: calc(100dvh - 30px);
      max-height: 100%;
      min-width: 0;
      min-height: 0;
      overflow: hidden;
      border: 1px solid #69584c;
      border-radius: 18px;
      background: #211e1b;
      box-shadow: 0 26px 90px rgba(0, 0, 0, .4);
      animation: sellerViewerEnter .22s ease-out both;
    }
    .document-preview-close {
      position: absolute;
      z-index: 2;
      top: 14px;
      right: 14px;
      display: grid;
      place-items: center;
      width: 42px;
      height: 42px;
      padding: 0;
      border: 1px solid rgba(255, 255, 255, .27);
      border-radius: 50%;
      background: rgba(36, 32, 29, .9);
      color: #fff;
      cursor: pointer;
      box-shadow: 0 5px 19px rgba(0, 0, 0, .22);
    }
    .document-preview-close svg { width: 21px; height: 21px; }
    .document-preview-close:hover, .document-preview-close:focus-visible {
      background: #4d3a2e;
      outline: 2px solid #f0bc8d;
      outline-offset: 2px;
    }
    .document-preview-body {
      display: flex;
      flex: 1 1 auto;
      align-items: center;
      justify-content: center;
      min-width: 0;
      min-height: 0;
      overflow: hidden;
      padding: 58px 14px 10px;
    }
    .document-preview-body img {
      display: block;
      width: auto;
      height: auto;
      max-width: 100%;
      max-height: 100%;
      margin: auto;
      object-fit: contain;
      object-position: center;
      user-select: none;
      -webkit-user-select: none;
    }
    .document-preview-body iframe {
      display: block;
      flex: 1 1 auto;
      width: 100%;
      height: 100%;
      min-width: 0;
      min-height: 0;
      border: 0;
      border-radius: 9px;
      background: #fff;
    }
    .document-preview-body img[hidden],
    .document-preview-body iframe[hidden] { display: none !important; }
    .document-preview-dialog.is-pdf .document-preview-body { padding: 60px 15px 12px; }
    .document-preview-footer {
      display: flex;
      flex: 0 0 auto;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      min-height: 58px;
      padding: 12px 21px;
      color: #f1e6dc;
      font-size: .82rem;
    }
    .document-preview-file-name {
      display: block;
      flex: 1 1 auto;
      min-width: 0;
      overflow: hidden;
      font-size: .82rem;
      font-weight: 750;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .document-preview-type { flex: 0 0 auto; color: #c2b3a6; font-size: .72rem; }
    .document-preview-open-pdf {
      display: inline-flex;
      flex: 0 0 auto;
      align-items: center;
      gap: 6px;
      margin: 0;
      padding: 8px 11px;
      border: 1px solid rgba(255, 255, 255, .32);
      border-radius: 9px;
      background: #3e342c;
      color: #fff;
      font-size: .75rem;
      font-weight: 750;
      text-decoration: none;
    }
    .document-preview-open-pdf[hidden] { display: none !important; }
    .document-preview-open-pdf svg { width: 15px; height: 15px; }
    .document-preview-open-pdf:hover { background: #594536; }
    .document-preview-open-pdf:focus-visible { outline: 2px solid #f0bc8d; outline-offset: 2px; }
    @keyframes sellerViewerEnter { from { opacity: 0; transform: translateY(9px); } to { opacity: 1; transform: translateY(0); } }
    @media (max-width: 760px) {
      .document-preview-modal { padding: 0; }
      .document-preview-dialog {
        width: 100%;
        height: 100vh;
        height: 100dvh;
        max-height: 100dvh;
        border: 0;
        border-radius: 0;
      }
      .document-preview-close { top: calc(12px + env(safe-area-inset-top)); right: 13px; }
      .document-preview-body { padding: calc(60px + env(safe-area-inset-top)) 9px 8px; }
      .document-preview-dialog.is-pdf .document-preview-body { padding: calc(60px + env(safe-area-inset-top)) 8px 8px; }
      .document-preview-footer { min-height: 59px; gap: 8px; padding: 12px 14px calc(13px + env(safe-area-inset-bottom)); }
      .document-preview-type { display: none; }
    }
    @media (prefers-reduced-motion: reduce) { .document-preview-dialog { animation: none; } }
    /* Mobile keyboard-safe placement for interactive popups. This doesn't scale the page. */
    @media (max-width: 768px) {
      .seller-otp-modal.open, .seller-guide-modal.open, .policy-modal.open {
        top: var(--seller-visual-top, 0px);
        bottom: auto;
        height: var(--seller-visual-height, 100dvh);
        max-height: var(--seller-visual-height, 100dvh);
        overflow-y: auto;
      }
      .seller-otp-card {
        max-height: calc(var(--seller-visual-height, 100dvh) - 24px);
      }
      .seller-guide-card, .policy-card {
        max-height: calc(var(--seller-visual-height, 100dvh) - 24px);
        margin: auto;
      }
      .policy-card { display: flex; flex-direction: column; min-height: 0; }
      .policy-content { flex: 1 1 auto; min-height: 0; max-height: none; }
    }
</style>
</head>
<body>
<?php $publicActive = ''; include __DIR__ . '/public_nav.php'; ?>
<div class="seller-page">
  <div class="seller-container">
  <div class="seller-card">
    <div class="progress-indicator">
      <button type="button" class="progress-step active" id="step1" data-progress-step="1" aria-label="Step 1" aria-current="step">
        <div class="step-circle">1</div>
        <div class="step-label">Personal</div>
      </button>
      <div class="progress-line"></div>
      <button type="button" class="progress-step" id="step2" data-progress-step="2" aria-label="Step 2" disabled>
        <div class="step-circle">2</div>
        <div class="step-label">Business</div>
      </button>
      <div class="progress-line"></div>
      <button type="button" class="progress-step" id="step3" data-progress-step="3" aria-label="Step 3" disabled>
        <div class="step-circle">3</div>
        <div class="step-label">Location</div>
      </button>
      <div class="progress-line"></div>
      <button type="button" class="progress-step" id="step4" data-progress-step="4" aria-label="Step 4" disabled>
        <div class="step-circle">4</div>
        <div class="step-label">Review</div>
      </button>
    </div>
    <div class="seller-header">
      <h1 class="seller-title">Become a Seller</h1>
      <p class="seller-subtitle">Join our community of Filipino artisans and share your craft with the world.</p>
    </div>
    <?php if (!empty($error)): ?>
      <div class="error-box"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <form class="seller-form" method="POST" action="become_a_seller.php" id="sellerForm" enctype="multipart/form-data" autocomplete="off" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['seller_application_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
      <div class="form-step active" data-step="1">
        <h2 class="step-title">Personal Information</h2>
        <div class="form-row">
          <div class="form-group">
            <label for="firstname">First Name *</label>
            <input type="text" id="firstname" name="firstname" maxlength="50" placeholder="e.g., Maria" required autocomplete="given-name" value="<?php echo htmlspecialchars($_POST['firstname'] ?? ''); ?>">
          </div>
          <div class="form-group">
            <label for="lastname">Last Name *</label>
            <input type="text" id="lastname" name="lastname" maxlength="50" placeholder="e.g., Santos" required autocomplete="family-name" value="<?php echo htmlspecialchars($_POST['lastname'] ?? ''); ?>">
          </div>
        </div>
        <div class="form-group form-row full">
          <label for="middlename">Middle Name (Optional)</label>
          <input type="text" id="middlename" name="middlename" maxlength="50" placeholder="e.g., Reyes" autocomplete="additional-name" value="<?php echo htmlspecialchars($_POST['middlename'] ?? ''); ?>">
        </div>
        <div class="form-group form-row full">
          <label for="email">Email Address *</label>
          <input type="email" id="email" name="email" maxlength="100" placeholder="artisan@lokalcraft.com" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" autocomplete="email">
          <div class="email-check-message" id="emailCheckMessage" aria-live="polite"></div>
        </div>
        <div class="form-group form-row full">
          <label for="phone">Phone Number *</label>
          <input type="tel" id="phone" name="phone" placeholder="09XXXXXXXXX" maxlength="14" pattern="09[0-9]{9}" inputmode="tel" required value="<?php echo htmlspecialchars($_POST['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="form-row">
          <div class="form-group">
            <label for="password">Password *</label>
            <div class="password-field">
              <input type="password" id="password" name="password" placeholder="Create a strong password" minlength="8" required autocomplete="new-password" aria-describedby="passwordStrength">
              <button type="button" class="password-toggle" data-target="password" aria-label="Show password"><svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg><svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="m3 3 18 18"/><path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 4.2A10.4 10.4 0 0 1 12 4c6.5 0 10 8 10 8a18 18 0 0 1-2.1 3.2"/><path d="M6.6 6.6C3.8 8.5 2 12 2 12s3.5 8 10 8a9.8 9.8 0 0 0 5.4-1.6"/></svg></button>
            </div>
            <div class="password-strength" id="passwordStrength" aria-live="polite">
              <span class="password-strength-bar" aria-hidden="true"><i></i><i></i><i></i></span>
              <span class="password-strength-label" id="passwordWarning"></span>
            </div>
          </div>
          <div class="form-group">
            <label for="confirm_password">Confirm Password *</label>
            <div class="password-field">
              <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter password" minlength="8" required autocomplete="new-password" aria-describedby="passwordMatch">
              <button type="button" class="password-toggle" data-target="confirm_password" aria-label="Show password"><svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg><svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="m3 3 18 18"/><path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 4.2A10.4 10.4 0 0 1 12 4c6.5 0 10 8 10 8a18 18 0 0 1-2.1 3.2"/><path d="M6.6 6.6C3.8 8.5 2 12 2 12s3.5 8 10 8a9.8 9.8 0 0 0 5.4-1.6"/></svg></button>
            </div>
            <div class="password-match" id="passwordMatch" aria-live="polite"></div>
          </div>
        </div>
        <div class="verification-heading">
          <div class="verification-heading-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3h7l4 4v14H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M14 3v5h5M9 12h6m-6 4h6"/></svg><span>Valid ID Documents</span></div>
          <button type="button" class="document-guide-button" data-open-guide="id" aria-haspopup="dialog">Document Guide ↗</button>
        </div>
        <div class="form-group form-row full">
          <label for="valid_id_type">Valid ID Type *</label>
          <select id="valid_id_type" name="validid_type" required>
            <option value="" disabled hidden <?php echo empty($_POST['validid_type']) ? 'selected' : ''; ?>>Choose valid ID</option>
            <?php foreach ($validIdTypes as $idType => $idConfig): ?>
              <option
                value="<?php echo htmlspecialchars($idType, ENT_QUOTES, 'UTF-8'); ?>"
                <?php echo ($_POST['validid_type'] ?? '') === $idType ? 'selected' : ''; ?>
              >
                <?php echo htmlspecialchars(
                    $idType . (!empty($idConfig['recommended']) ? ' — Recommended' : ''),
                    ENT_QUOTES,
                    'UTF-8'
                ); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="document-upload-grid">
          <div class="upload-card" id="validIdFrontCard">
            <div class="upload-card-heading">
              <div>
                <strong id="validIdFrontTitle">Front of ID *</strong>
                <span id="validIdFrontHelp">Take or choose a clear photo of your ID.</span>
              </div>
              <span class="upload-required">Required</span>
            </div>
            <input class="document-file-input" type="file" id="valid_id" name="validid" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp">
            <label class="upload-dropzone" for="valid_id" data-upload-empty="valid_id">
              <span class="upload-icon">↥</span>
              <strong>Take or choose photo</strong>
              <span>JPG, PNG or WEBP • Max 5 MB</span>
            </label>
            <div class="upload-preview" data-upload-preview="valid_id" hidden>
              <button class="upload-preview-media" type="button" data-file-view="valid_id">
                <img class="upload-preview-image" data-file-image="valid_id" alt="Valid ID preview" hidden>
              </button>
              <div class="upload-file-meta">
                <strong data-file-name="valid_id"></strong>
                <span data-file-size="valid_id"></span>
              </div>
              <div class="upload-actions">
                <button type="button" data-file-change="valid_id">Change</button>
                <button type="button" data-file-remove="valid_id">Remove</button>
              </div>
            </div>
          </div>
          <div class="upload-card" id="validIdBackCard" hidden>
            <div class="upload-card-heading">
              <div>
                <strong>Back of ID *</strong>
                <span>Upload the back side clearly and without cropping important details.</span>
              </div>
              <span class="upload-required">Required</span>
            </div>
            <input class="document-file-input" type="file" id="valid_id_back" name="validid_back" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp">
            <label class="upload-dropzone" for="valid_id_back" data-upload-empty="valid_id_back">
              <span class="upload-icon">↥</span>
              <strong>Take or choose back photo</strong>
              <span>JPG, PNG or WEBP • Max 5 MB</span>
            </label>
            <div class="upload-preview" data-upload-preview="valid_id_back" hidden>
              <button class="upload-preview-media" type="button" data-file-view="valid_id_back">
                <img class="upload-preview-image" data-file-image="valid_id_back" alt="Back of ID preview" hidden>
              </button>
              <div class="upload-file-meta">
                <strong data-file-name="valid_id_back"></strong>
                <span data-file-size="valid_id_back"></span>
              </div>
              <div class="upload-actions">
                <button type="button" data-file-change="valid_id_back">Change</button>
                <button type="button" data-file-remove="valid_id_back">Remove</button>
              </div>
            </div>
          </div>
        </div>
        <div class="form-buttons">
          <button type="button" class="btn btn-next" id="personalNextBtn" onclick="nextStep(1)">Next</button>
        </div>
      </div>
      <div class="form-step" data-step="2">
        <h2 class="step-title">Business Information</h2>
        <div class="form-group form-row full">
          <label for="business_name">Business/Shop Name *</label>
          <input type="text" id="business_name" name="businessname" maxlength="50" placeholder="e.g., Maria's Handmade Bags" required value="<?php echo htmlspecialchars($_POST['businessname'] ?? ''); ?>">
        </div>
        <div class="form-group form-row full">
          <label for="craft_category">Craft Category *</label>
          <div class="category-select" id="categorySelect">
            <input type="hidden" id="craft_category" name="craftcategory" value="<?php echo htmlspecialchars($_POST['craftcategory'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <button type="button" class="category-toggle" id="categoryToggle" aria-expanded="false" aria-controls="categoryOptions" aria-haspopup="true">Choose categories <span aria-hidden="true">⌄</span></button>
            <div class="category-options" id="categoryOptions" hidden>
              <?php foreach ($craftCategories as $category): ?>
                <label class="category-choice"><input type="checkbox" class="category-checkbox" value="<?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>" <?php echo in_array($category, array_map('trim', explode(',', (string)($_POST['craftcategory'] ?? ''))), true) ? 'checked' : ''; ?>><span><?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?></span></label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="form-group form-row full">
          <label for="business_description">Business Description *</label>
          <textarea id="business_description" name="businessdescription" minlength="20" maxlength="300" placeholder="Describe your craft materials, years of experience, and what makes your products unique..." required><?php echo htmlspecialchars($_POST['businessdescription'] ?? ''); ?></textarea>
        </div>
        <div class="verification-heading">
          <div class="verification-heading-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3h7l4 4v14H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M14 3v5h5M9 12h6m-6 4h6"/></svg><span>Business Documents</span></div>
          <button type="button" class="document-guide-button" data-open-guide="business" aria-haspopup="dialog">Document Guide ↗</button>
        </div>
        <div class="form-group form-row full">
          <label for="business_document_type">Business Verification Document *</label>
          <select id="business_document_type" name="business_document_type" required>
            <option value="" disabled hidden <?php echo empty($_POST['business_document_type']) ? 'selected' : ''; ?>>Choose document</option>
            <?php foreach ($businessDocumentTypes as $documentType): ?>
              <option
                value="<?php echo htmlspecialchars($documentType, ENT_QUOTES, 'UTF-8'); ?>"
                <?php echo ($_POST['business_document_type'] ?? '') === $documentType ? 'selected' : ''; ?>
              >
                <?php echo htmlspecialchars($documentType, ENT_QUOTES, 'UTF-8'); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="upload-card business-document-card">
          <div class="upload-card-heading">
            <div>
              <strong id="businessDocumentUploadTitle">Business Verification Document *</strong>
              <span id="businessDocumentUploadHelp">Upload the document selected above.</span>
            </div>
            <span class="upload-required">Required</span>
          </div>
          <input class="document-file-input" type="file" id="business_permit" name="businesspermit" accept=".pdf,.jpg,.jpeg,.png,.webp">
          <label class="upload-dropzone" for="business_permit" data-upload-empty="business_permit">
            <span class="upload-icon">↥</span>
            <strong>Choose business document</strong>
            <span>PDF, JPG, PNG or WEBP • Max 5 MB</span>
          </label>
          <div class="upload-preview" data-upload-preview="business_permit" hidden>
            <button class="upload-preview-media" type="button" data-file-view="business_permit">
              <img class="upload-preview-image" data-file-image="business_permit" alt="Business document preview" hidden>
              <span class="upload-preview-pdf" data-file-pdf="business_permit" hidden>
                <span class="pdf-icon">PDF</span>
                <span>Click to view document</span>
              </span>
            </button>
            <div class="upload-file-meta">
              <strong data-file-name="business_permit"></strong>
              <span data-file-size="business_permit"></span>
            </div>
            <div class="upload-actions">
              <button type="button" data-file-change="business_permit">Change</button>
              <button type="button" data-file-remove="business_permit">Remove</button>
            </div>
          </div>
        </div>
        <div class="form-buttons">
          <button type="button" class="btn btn-back" onclick="prevStep(2)">Back</button>
          <button type="button" class="btn btn-next" onclick="nextStep(2)">Next</button>
        </div>
      </div>
      <div class="form-step" data-step="3">
        <h2 class="step-title location-title">Location Information <button type="button" class="location-tip" aria-label="Location information" aria-describedby="locationHelp">ⓘ</button><span role="tooltip" id="locationHelp" class="location-tip-text">Taguig City sellers only. Your address supports local discovery and delivery arrangements.</span></h2>
        <div class="form-group form-row full">
          <label for="street_address">Street Address *</label>
          <input type="text" id="street_address" name="streetaddress" maxlength="255" placeholder="e.g., 123 Sampaguita Street" required value="<?php echo htmlspecialchars($_POST['streetaddress'] ?? ''); ?>">
        </div>
        <div class="form-group form-row full">
          <label for="barangay">Barangay *</label>
          <div class="barangay-combobox" id="barangayBox">
            <input type="text" id="barangay" name="barangay" maxlength="25" placeholder="Search Taguig barangay" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="barangaySuggestions" autocomplete="off" required value="<?php echo htmlspecialchars($_POST['barangay'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <div class="barangay-suggestions" id="barangaySuggestions" role="listbox" hidden></div>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label for="city">City *</label>
            <input type="text" id="city" name="city" maxlength="100" readonly required value="Taguig City">
          </div>
          <div class="form-group">
            <label for="zipcode">Zip Code *</label>
            <input type="text" id="zipcode" name="zipcode" placeholder="e.g., 1637" maxlength="4" inputmode="numeric" pattern="[0-9]{4}" required value="<?php echo htmlspecialchars($_POST['zipcode'] ?? ''); ?>">
          </div>
        </div>
        <div class="form-buttons">
          <button type="button" class="btn btn-back" onclick="prevStep(3)">Back</button>
          <button type="button" class="btn btn-next" onclick="nextStep(3)">Next</button>
        </div>
      </div>
      <div class="form-step" data-step="4">
        <h2 class="step-title">Review Your Information</h2>
        <div class="review-section">
          <div class="review-title">📋 Personal Information</div>
          <div class="review-item">
            <div><strong>Name</strong> <span id="reviewName">-</span></div>
            <div><strong>Email</strong> <span id="reviewEmail">-</span></div>
          </div>
          <div class="review-item">
            <div><strong>Phone</strong> <span id="reviewPhone">-</span></div>
          </div>
        </div>
        <div class="review-section">
          <div class="review-title">🏪 Business Information</div>
          <div class="review-item">
            <div><strong>Business Name</strong> <span id="reviewBusinessName">-</span></div>
            <div><strong>Category</strong> <span id="reviewCategory">-</span></div>
          </div>
          <div class="review-item full">
            <div><strong>Description</strong> <span id="reviewDescription">-</span></div>
          </div>
        </div>
        <div class="review-section">
          <div class="review-title">🪪 Verification Documents</div>
          <div class="review-item">
            <div><strong>Valid ID Type</strong> <span id="reviewValidIdType">-</span></div>
            <div><strong>Business Document Type</strong> <span id="reviewBusinessDocumentType">-</span></div>
          </div>
          <div class="review-document-grid">
            <div class="review-document-card" id="reviewValidIdFront"></div>
            <div class="review-document-card" id="reviewValidIdBack" hidden></div>
            <div class="review-document-card" id="reviewBusinessDocument"></div>
          </div>
        </div>
        <div class="review-section">
          <div class="review-title">📍 Location Information</div>
          <div class="review-item full">
            <div><strong>Address</strong> <span id="reviewAddress">-</span></div>
          </div>
        </div>
        <div class="terms-checkbox">
          <input type="checkbox" id="agreeTerms" name="agreeTerms" required>
          <label for="agreeTerms">I agree to the <a href="#" data-policy="terms">Terms and Conditions, Privacy Policy</a>, and <a href="#" data-policy="seller-agreement">Seller Agreement</a>. I understand that my seller account will be reviewed and approved by LokalCraft administrators.</label>
        </div>
        <div class="form-buttons">
          <button type="button" class="btn btn-back" onclick="prevStep(4)">Back</button>
          <button type="submit" class="btn btn-submit" id="submitBtn">Submit Application</button>
        </div>
      </div>
    </form>
  </div>
  </div>
</div>
<div class="seller-otp-modal" id="sellerOtpModal" aria-hidden="true">
  <div class="seller-otp-backdrop" data-otp-close></div>
  <div class="seller-otp-card" role="dialog" aria-modal="true" aria-labelledby="sellerOtpTitle">
    <button type="button" class="seller-otp-close" data-otp-close aria-label="Close OTP verification">&times;</button>
    <div class="seller-otp-icon" aria-hidden="true">@</div>
    <h2 id="sellerOtpTitle">Verify your email</h2>
    <p class="seller-otp-subtitle">Enter the 6-digit code sent to:</p>
    <div class="seller-otp-email" id="sellerOtpEmail"></div>
    <div class="seller-otp-message" id="sellerOtpMessage" aria-live="polite"></div>
    <div class="seller-otp-inputs" id="sellerOtpInputs" aria-label="6-digit verification code">
      <input class="seller-otp-digit" type="text" inputmode="numeric" maxlength="1" autocomplete="one-time-code" aria-label="Digit 1">
      <input class="seller-otp-digit" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 2">
      <input class="seller-otp-digit" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 3">
      <input class="seller-otp-digit" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 4">
      <input class="seller-otp-digit" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 5">
      <input class="seller-otp-digit" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 6">
    </div>
    <button type="button" class="seller-otp-primary" id="sellerOtpVerifyButton" disabled>Verify Email</button>
    <p class="seller-otp-delivery-help">Didn't receive a code? Check your email address and Spam folder.</p>
    <div class="seller-otp-actions">
      <button type="button" class="seller-otp-text" id="sellerOtpResendButton">Resend code</button>
      <span>•</span>
      <button type="button" class="seller-otp-text" id="sellerOtpChangeEmail">Change email</button>
    </div>
    <p class="seller-otp-expiry">The verification code expires in 10 minutes.</p>
  </div>
</div>
<div class="seller-guide-modal" id="sellerGuideModal" aria-hidden="true">
  <div class="seller-guide-card" role="dialog" aria-modal="true" aria-labelledby="sellerGuideTitle" tabindex="-1">
    <div class="seller-guide-header">
      <h2 id="sellerGuideTitle">Document Guide</h2>
      <button class="seller-guide-close" type="button" data-close-guide aria-label="Close document guide">&times;</button>
    </div>
    <div class="seller-guide-content" data-guide-panel="id" hidden>
      <strong>Accepted valid IDs</strong>
      <ul>
        <?php foreach ($validIdTypes as $type => $config): ?>
          <li><?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?><?php echo !empty($config['recommended']) ? ' (Recommended)' : ''; ?></li>
        <?php endforeach; ?>
      </ul>
      <p>Upload a readable JPG, PNG, or WEBP image, maximum 5 MB per file. Include the front and back where required. For passports, upload the information page.</p>
    </div>
    <div class="seller-guide-content" data-guide-panel="business" hidden>
      <strong>Accepted business documents</strong>
      <ul>
        <?php foreach ($businessDocumentTypes as $type): ?>
          <li><?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?></li>
        <?php endforeach; ?>
      </ul>
      <p>Upload one readable document. PDF, JPG, PNG, or WEBP; maximum 5 MB.</p>
    </div>
  </div>
</div>
<div class="document-preview-modal" id="documentPreviewModal" aria-hidden="true">
  <div class="document-preview-backdrop" data-document-preview-close></div>
  <div class="document-preview-dialog" role="dialog" aria-modal="true" aria-labelledby="documentPreviewFileName">
    <button type="button" class="document-preview-close" data-document-preview-close aria-label="Close full preview">
      <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </button>
    <div class="document-preview-body">
      <img id="documentPreviewImage" alt="Full uploaded document image" hidden>
      <iframe id="documentPreviewPdf" title="Full PDF document preview" referrerpolicy="no-referrer" hidden></iframe>
    </div>
    <div class="document-preview-footer">
      <strong class="document-preview-file-name" id="documentPreviewFileName">File Preview</strong>
      <a class="document-preview-open-pdf" id="documentPreviewOpenPdf" href="#" target="_blank" rel="noopener noreferrer" hidden>
        Open full PDF
        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M14 5h5v5m0-5-9 9"/><path d="M19 13v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h5"/></svg>
      </a>
      <span class="document-preview-type" id="documentPreviewMediaKind">Full image</span>
    </div>
  </div>
</div>
<div class="policy-modal" id="policy-modal" role="dialog" aria-modal="true" aria-labelledby="policy-title">
  <div class="policy-card">
    <div class="policy-header">
      <h2 id="policy-title">Terms and Conditions</h2>
      <button class="policy-close" type="button" aria-label="Close policy">&times;</button>
    </div>
    <div class="policy-content" id="policy-content">
      <?php include __DIR__ . '/terms_privacy.php'; ?>
    </div>
  </div>
</div>
<div class="policy-modal" id="seller-agreement-modal" role="dialog" aria-modal="true" aria-labelledby="seller-agreement-title">
  <div class="policy-card">
    <div class="policy-header">
      <h2 id="seller-agreement-title">Seller Agreement</h2>
      <button class="policy-close" type="button" aria-label="Close seller agreement">&times;</button>
    </div>
    <div class="policy-content">
      <?php include __DIR__ . '/seller_agreement.php'; ?>
    </div>
  </div>
</div>
<script>
  // Treat an open popup as a temporary browser-history entry (Samsung/Android Back support).
  // No entry is added for normal form steps, so Back works normally when no popup is open.
  const sellerPopupHistoryKey = '__lokalcraftSellerPopup';
  function openSellerPopupHistory(modalId) {
    try {
      if (window.history.state?.[sellerPopupHistoryKey] === modalId) return;
      const state = window.history.state;
      const nextState = state && typeof state === 'object' ? {...state} : {};
      nextState[sellerPopupHistoryKey] = modalId;
      window.history.pushState(nextState, '', window.location.href);
    } catch (_) {
      // The popup still works with its on-screen close button if history is unavailable.
    }
  }
  function closeSellerPopupHistory(modalId) {
    if (window.history.state?.[sellerPopupHistoryKey] === modalId) {
      window.history.back();
    }
  }
  window.addEventListener('popstate', () => {
    // Only intercept Back while a popup is open; otherwise allow normal browser navigation.
    if (sellerGuideModal?.classList.contains('open')) { closeSellerGuide(); return; }
    if (sellerOtpModal?.classList.contains('open')) { cancelSellerOtp(); return; }
    if (documentPreviewModal?.classList.contains('open')) { closeDocumentPreview(); return; }
    const openPolicy = document.querySelector('.policy-modal.open');
    if (openPolicy) closePolicyModal(openPolicy);
  });
  let currentStep = 1;
  let furthestCompletedStep = 1;
  let verifiedEmail = <?php echo json_encode($verifiedSellerEmail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  let emailVerified = verifiedEmail !== '';
  const VALID_ID_TYPES = {
    'TIN ID': { requiresBack: true, recommended: true },
    'PhilSys National ID': { requiresBack: true },
    "Driver's License": { requiresBack: true },
    'Passport': { requiresBack: false },
    'UMID': { requiresBack: true },
    'PRC ID': { requiresBack: true },
    'Postal ID': { requiresBack: true }
  };
  const TAGUIG_BARANGAYS = <?php echo json_encode(sellerTaguigBarangays(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  const BUSINESS_DOCUMENT_HELP = {
    'DTI Business Name Registration': 'Upload the DTI Business Name Registration document. Digitally issued PDF files are accepted.',
    'BIR Certificate of Registration (Form 2303)': 'Upload the BIR Certificate of Registration (Form 2303). PDF files downloaded online are accepted.',
    "Mayor's / Business Permit": "Upload the Mayor's / Business Permit as a clear image or PDF."
  };
  const documentFileState = new Map();
  function getFieldElement(fieldId) {
    return document.getElementById(fieldId);
  }
  function getFieldErrorAnchor(fieldId) {
    const field = getFieldElement(fieldId);
    if (!field) return null;
    if (fieldId === 'agreeTerms') {
      return field.closest('.terms-checkbox');
    }
    if (field.type === 'file') {
      return field.closest('.upload-card');
    }
    return field.closest('.form-group') || field.parentElement;
  }
  function clearFieldError(fieldId) {
    const field = getFieldElement(fieldId);
    const anchor = getFieldErrorAnchor(fieldId);
    field?.classList.remove('input-invalid');
    anchor?.classList.remove('input-invalid');
    anchor?.querySelector(`.field-error[data-error-for="${fieldId}"]`)?.remove();
  }
  function setFieldError(fieldId, message) {
    const field = getFieldElement(fieldId);
    const anchor = getFieldErrorAnchor(fieldId);
    if (!field || !anchor) return false;
    clearFieldError(fieldId);
    field.classList.add('input-invalid');
    anchor.classList.add('input-invalid');
    const error = document.createElement('div');
    error.className = 'field-error';
    error.dataset.errorFor = fieldId;
    error.textContent = message;
    anchor.appendChild(error);
    return false;
  }
  function focusFirstInvalid(fieldIds) {
    for (const fieldId of fieldIds) {
      if (document.querySelector(`.field-error[data-error-for="${fieldId}"]`)) {
        if (fieldId === 'craft_category') categoryToggle?.focus({preventScroll:true});
        else getFieldElement(fieldId)?.focus({ preventScroll: true });
        break;
      }
    }
  }
  function isPersonalName(value, allowApostrophe = false) {
    if (!value || [...value].length > 50) return false;
    const pattern = allowApostrophe ? /^\p{L}+(?:[ '-]\p{L}+)*$/u : /^\p{L}+(?:[ -]\p{L}+)*$/u;
    return pattern.test(value.trim());
  }
  const sellerOtpModal = document.getElementById('sellerOtpModal');
  const sellerOtpEmail = document.getElementById('sellerOtpEmail');
  const sellerOtpMessage = document.getElementById('sellerOtpMessage');
  const sellerOtpDigits = Array.from(document.querySelectorAll('.seller-otp-digit'));
  const sellerOtpVerifyButton = document.getElementById('sellerOtpVerifyButton');
  const sellerOtpResendButton = document.getElementById('sellerOtpResendButton');
  const sellerOtpChangeEmail = document.getElementById('sellerOtpChangeEmail');
  const personalNextBtn = document.getElementById('personalNextBtn');
  let otpResendTimer = null;
  let otpResendSeconds = 0;
  function setPersonalNextLoading(isLoading) {
    if (!personalNextBtn) return;
    personalNextBtn.disabled = isLoading;
    personalNextBtn.classList.toggle('btn-loading', isLoading);
    if (isLoading) {
      personalNextBtn.innerHTML = '<span class="next-loading-spinner" aria-hidden="true"></span><span>Sending OTP...</span>';
      personalNextBtn.setAttribute('aria-busy', 'true');
    } else {
      personalNextBtn.textContent = 'Next';
      personalNextBtn.removeAttribute('aria-busy');
    }
  }
  function setOtpMessage(message = '', type = '') {
    sellerOtpMessage.textContent = message;
    sellerOtpMessage.className = 'seller-otp-message' + (type ? ` ${type}` : '');
  }
  function setOtpResendCountdown(seconds) {
    otpResendSeconds = Math.max(0, Number(seconds) || 0);
    window.clearInterval(otpResendTimer);
    const update = () => {
      if (otpResendSeconds > 0) {
        sellerOtpResendButton.disabled = true;
        sellerOtpResendButton.textContent = `Resend code (${otpResendSeconds}s)`;
        otpResendSeconds--;
      } else {
        sellerOtpResendButton.disabled = false;
        sellerOtpResendButton.textContent = 'Resend code';
        window.clearInterval(otpResendTimer);
      }
    };
    update();
    otpResendTimer = window.setInterval(update, 1000);
  }
  function updateOtpVerifyState() {
    const code = sellerOtpDigits.map(input => input.value).join('');
    sellerOtpVerifyButton.disabled = !/^\d{6}$/.test(code) || sellerOtpVerifyButton.dataset.busy === '1';
  }
  function resetOtpDigits() {
    sellerOtpDigits.forEach(input => { input.value = ''; });
    updateOtpVerifyState();
  }
  function openSellerOtp(maskedEmail, resendSeconds = 60) {
    sellerOtpEmail.textContent = maskedEmail;
    sellerOtpVerifyButton.dataset.busy = '0';
    resetOtpDigits();
    setOtpMessage();
    setOtpResendCountdown(resendSeconds);
    openSellerPopupHistory('sellerOtpModal');
    sellerOtpModal.classList.add('open');
    sellerOtpModal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('seller-otp-open');
    document.body.style.overflow = 'hidden';
    window.setTimeout(() => sellerOtpDigits[0]?.focus(), 80);
  }
  async function postSellerOtpAction(action, extra = {}) {
    const csrfInput = document.querySelector('input[name="csrf_token"]');
    if (!csrfInput?.value) {
      throw new Error('Your form session is missing. Reload the page and try again.');
    }
    const body = new URLSearchParams();
    body.append('action', action);
    body.append('csrf_token', csrfInput.value);
    Object.entries(extra).forEach(([key, value]) => {
      body.append(key, String(value ?? ''));
    });
    const response = await fetch('become_a_seller.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'Accept': 'application/json'
      },
      body: body.toString(),
      cache: 'no-store'
    });
    const rawResponse = await response.text();
    let data;
    try {
      data = JSON.parse(rawResponse);
    } catch (_) {
      console.error('Seller OTP returned a non-JSON response.');
      throw new Error('The OTP request could not be completed. Check the PHP error log or reload the page.');
    }
    if (!response.ok && !data?.message) {
      throw new Error('The OTP request could not be completed.');
    }
    return data;
  }
  async function cancelSellerOtp() {
    sellerOtpModal.classList.remove('open');
    sellerOtpModal.setAttribute('aria-hidden', 'true');
    closeSellerPopupHistory('sellerOtpModal');
    document.body.classList.remove('seller-otp-open');
    if (
      !document.querySelector('.policy-modal.open')
      && !document.getElementById('documentPreviewModal')?.classList.contains('open')
    ) {
      document.body.style.overflow = '';
    }
    try {
      await postSellerOtpAction('seller_cancel_otp');
    } catch (_) {
    }
  }
  async function startSellerEmailVerification() {
    const email = document.getElementById('email').value.trim();
    const firstname = document.getElementById('firstname').value.trim();
    const lastname = document.getElementById('lastname').value.trim();
    const messageBox = document.getElementById('emailCheckMessage');
    clearFieldError('email');
    setPersonalNextLoading(true);
    messageBox.className = 'email-check-message info';
    messageBox.textContent = 'Checking email and sending your verification code...';
    try {
      const data = await postSellerOtpAction('seller_request_otp', {
        email,
        firstname,
        lastname
      });
      if (!data.success) {
        emailVerified = false;
        verifiedEmail = '';
        messageBox.className = 'email-check-message';
        messageBox.textContent = '';
        setFieldError(
          data.field || 'email',
          data.message || 'Unable to send the verification code.'
        );
        return false;
      }
      if (!sellerOtpModal) {
        throw new Error('The OTP popup could not be opened. Reload the page and try again.');
      }
      messageBox.className = 'email-check-message success';
      messageBox.textContent = 'Verification code sent. Enter the code in the popup.';
      openSellerOtp(
        data.masked_email || email,
        data.resend_seconds || 60
      );
      return true;
    } catch (error) {
      emailVerified = false;
      verifiedEmail = '';
      messageBox.className = 'email-check-message';
      messageBox.textContent = '';
      setFieldError(
        'email',
        error?.message || 'Unable to send the verification code right now. Please try again.'
      );
      console.error('Seller OTP request failed:', error);
      return false;
    } finally {
      setPersonalNextLoading(false);
    }
  }
  async function verifySellerOtp() {
    const code = sellerOtpDigits.map(input => input.value).join('');
    if (!/^\d{6}$/.test(code)) {
      setOtpMessage('Enter the complete 6-digit verification code.', 'error');
      sellerOtpDigits.find(input => !input.value)?.focus();
      return;
    }
    let verifiedSuccessfully = false;
    sellerOtpVerifyButton.dataset.busy = '1';
    updateOtpVerifyState();
    sellerOtpVerifyButton.textContent = 'Verifying...';
    setOtpMessage();
    try {
      const data = await postSellerOtpAction('seller_verify_otp', {otp: code});
      if (!data.success) {
        setOtpMessage(data.message || 'Unable to verify the code.', 'error');
        return;
      }
      verifiedSuccessfully = true;
      emailVerified = true;
      verifiedEmail = String(
        data.verified_email || document.getElementById('email').value.trim()
      );
      const messageBox = document.getElementById('emailCheckMessage');
      messageBox.className = 'email-check-message success';
      messageBox.textContent = 'Email verified successfully.';
      clearFieldError('email');
      setOtpMessage('Email verified successfully.', 'success');
      window.setTimeout(() => {
        if (!sellerOtpModal.classList.contains('open')) return;
        sellerOtpModal.classList.remove('open');
        sellerOtpModal.setAttribute('aria-hidden', 'true');
        closeSellerPopupHistory('sellerOtpModal');
        document.body.classList.remove('seller-otp-open');
        document.body.style.overflow = '';
        currentStep = 2;
        furthestCompletedStep = Math.max(furthestCompletedStep, 2);
        updateForm();
      }, 450);
    } catch (_) {
      setOtpMessage('Unable to verify the code right now. Please try again.', 'error');
    } finally {
      sellerOtpVerifyButton.dataset.busy = verifiedSuccessfully ? '1' : '0';
      updateOtpVerifyState();
      sellerOtpVerifyButton.textContent = 'Verify Email';
    }
  }
  async function resendSellerOtp() {
    sellerOtpResendButton.disabled = true;
    setOtpMessage();
    try {
      const data = await postSellerOtpAction('seller_resend_otp');
      if (!data.success) {
        setOtpMessage(data.message || 'Unable to resend the code.', 'error');
        setOtpResendCountdown(data.resend_seconds || 1);
        return;
      }
      resetOtpDigits();
      setOtpMessage(data.message || 'A new verification code was sent.', 'success');
      setOtpResendCountdown(data.resend_seconds || 60);
      sellerOtpDigits[0]?.focus();
    } catch (_) {
      setOtpMessage('Unable to resend the code right now.', 'error');
      setOtpResendCountdown(1);
    }
  }
  sellerOtpDigits.forEach((input, index) => {
    input.addEventListener('input', () => {
      input.value = input.value.replace(/\D/g, '').slice(0, 1);
      setOtpMessage();
      updateOtpVerifyState();
      if (input.value && sellerOtpDigits[index + 1]) {
        sellerOtpDigits[index + 1].focus();
      }
    });
    input.addEventListener('keydown', event => {
      if (event.key === 'Backspace' && !input.value && sellerOtpDigits[index - 1]) {
        sellerOtpDigits[index - 1].value = '';
        updateOtpVerifyState();
        sellerOtpDigits[index - 1].focus();
      }
      if (event.key === 'ArrowLeft' && sellerOtpDigits[index - 1]) {
        event.preventDefault();
        sellerOtpDigits[index - 1].focus();
      }
      if (event.key === 'ArrowRight' && sellerOtpDigits[index + 1]) {
        event.preventDefault();
        sellerOtpDigits[index + 1].focus();
      }
    });
    input.addEventListener('paste', event => {
      const pasted = (event.clipboardData?.getData('text') || '')
        .replace(/\D/g, '')
        .slice(0, 6);
      if (!pasted) return;
      event.preventDefault();
      pasted.split('').forEach((digit, position) => {
        if (sellerOtpDigits[position]) {
          sellerOtpDigits[position].value = digit;
        }
      });
      updateOtpVerifyState();
      sellerOtpDigits[Math.min(pasted.length, 6) - 1]?.focus();
    });
  });
  sellerOtpVerifyButton.addEventListener('click', verifySellerOtp);
  sellerOtpResendButton.addEventListener('click', resendSellerOtp);
  sellerOtpChangeEmail.addEventListener('click', () => {
    cancelSellerOtp();
    const field = document.getElementById('email');
    window.requestAnimationFrame(() => { field?.focus({preventScroll:true}); field?.select(); });
  });
  document.querySelectorAll('[data-otp-close]').forEach(control => {
    control.addEventListener('click', cancelSellerOtp);
  });
  async function nextStep(step) {
    if (step === 1 && personalNextBtn?.disabled) {
      return;
    }
    if (!validateStep(step)) {
      return;
    }
    if (step === 1) {
      const email = document.getElementById('email').value.trim();
      if (!emailVerified || verifiedEmail.toLowerCase() !== email.toLowerCase()) {
        await startSellerEmailVerification();
        return;
      }
    }
    currentStep = step + 1;
    furthestCompletedStep = Math.max(furthestCompletedStep, currentStep);
    updateForm();
  }
  function prevStep(step) {
    currentStep = step - 1;
    updateForm();
  }
  async function goToCompletedStep(target) {
    if (!Number.isInteger(target) || target < 1 || target > furthestCompletedStep || target === currentStep) return;
    if (target > currentStep) {
      for (let step = currentStep; step < target; step++) {
        if (step !== currentStep) { currentStep = step; updateForm(); }
        if (!validateStep(step)) return;
        if (step === 1) {
          const email = document.getElementById('email').value.trim();
          if (!emailVerified || verifiedEmail.toLowerCase() !== email.toLowerCase()) {
            await startSellerEmailVerification();
            return;
          }
        }
      }
    }
    currentStep = target;
    updateForm();
  }
  document.querySelectorAll('[data-progress-step]').forEach(button => {
    button.addEventListener('click', () => goToCompletedStep(Number(button.dataset.progressStep)));
  });
  function getMissingPasswordRules(value) {
    const missing = [];
    if (value.length < 8) missing.push('8+ characters');
    if (!/[A-Z]/.test(value)) missing.push('uppercase letter');
    if (!/[a-z]/.test(value)) missing.push('lowercase letter');
    if (!/[0-9]/.test(value)) missing.push('number');
    return missing;
  }
  const personalNameInputs = ['firstname', 'middlename', 'lastname'];
  personalNameInputs.forEach(fieldId => {
    const input = document.getElementById(fieldId);
    const allowed = fieldId === 'middlename' ? /[^\p{L} '-]/gu : /[^\p{L} -]/gu;
    const allowedSingle = fieldId === 'middlename' ? /^[\p{L} '-]+$/u : /^[\p{L} -]+$/u;
    input?.addEventListener('beforeinput', event => {
      if (event.inputType === 'insertText' && event.data?.length === 1 && !allowedSingle.test(event.data)) event.preventDefault();
    });
    input?.addEventListener('input', () => {
      input.value = [...input.value.replace(allowed, '')].slice(0, 50).join('');
      if (isPersonalName(input.value.trim(), fieldId === 'middlename')) clearFieldError(fieldId);
    });
    input?.addEventListener('blur', () => {
      input.value = input.value.trim().replace(/\s+/g,' ');
      if (input.value && !isPersonalName(input.value, fieldId === 'middlename')) {
        setFieldError(fieldId, 'Check this name.');
      } else {
        input.value = input.value.toLocaleLowerCase().replace(/(^|[ '-])\p{L}/gu, part => part.toLocaleUpperCase());
        if (fieldId === 'lastname') input.value = input.value.replace(/\b(Jr|Sr|Ii|Iii|Iv|Vi)$/, value => ['Jr','Sr'].includes(value) ? value : value.toUpperCase());
        clearFieldError(fieldId);
      }
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
    if (/^09\d{9}$/.test(normalized)) clearFieldError('phone');
  });
  phoneInput.addEventListener('blur', () => {
    if (phoneInput.value && !/^09\d{9}$/.test(phoneInput.value)) setFieldError('phone', 'Enter a complete 11-digit mobile number starting with 09.');
    else clearFieldError('phone');
  });
  // Smart dropdown placement: pick the roomier side of the visible viewport.
  // Supports scrolling, browser zoom, mobile keyboards and orientation changes.
  function positionSellerDropdown(menu, trigger, preferredHeight) {
    if (!menu || !trigger || menu.hidden) return;
    const viewport = window.visualViewport;
    const visibleTop = viewport ? viewport.offsetTop : 0;
    const visibleBottom = visibleTop + (viewport ? viewport.height : window.innerHeight);
    const anchor = trigger.getBoundingClientRect();
    const edge = 12;
    const gap = 5;
    const availableAbove = Math.max(0, anchor.top - visibleTop - edge - gap);
    const availableBelow = Math.max(0, visibleBottom - anchor.bottom - edge - gap);
    const openUp = availableBelow < Math.min(preferredHeight, 150) && availableAbove > availableBelow;
    const available = openUp ? availableAbove : availableBelow;
    menu.classList.toggle('opens-up', openUp);
    menu.style.setProperty('--seller-dropdown-max-height', `${Math.max(0, Math.floor(Math.min(preferredHeight, available)))}px`);
  }
  let sellerPositionFrame = 0;
  function scheduleSellerDropdownPosition() {
    if (sellerPositionFrame) return;
    sellerPositionFrame = window.requestAnimationFrame(() => {
      sellerPositionFrame = 0;
      if (!barangayOptions.hidden) positionSellerDropdown(barangayOptions, barangayInput, 220);
      if (!categoryOptions.hidden) positionSellerDropdown(categoryOptions, categoryToggle, 230);
    });
  }
  // The visual viewport shrinks independently of the layout viewport on many mobile browsers.
  function syncSellerVisualViewport() {
    const viewport = window.visualViewport;
    const height = viewport ? viewport.height : window.innerHeight;
    const top = viewport ? viewport.offsetTop : 0;
    document.documentElement.style.setProperty('--seller-visual-height', `${Math.round(height)}px`);
    document.documentElement.style.setProperty('--seller-visual-top', `${Math.round(top)}px`);
    scheduleSellerDropdownPosition();
  }
  window.addEventListener('resize', syncSellerVisualViewport, {passive:true});
  window.addEventListener('scroll', scheduleSellerDropdownPosition, {passive:true, capture:true});
  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', syncSellerVisualViewport, {passive:true});
    window.visualViewport.addEventListener('scroll', syncSellerVisualViewport, {passive:true});
  }
  syncSellerVisualViewport();
  // Barangay suggests a ZIP, but applicants may correct the 4-digit ZIP manually.
  const barangayInput = document.getElementById('barangay');
  const barangayOptions = document.getElementById('barangaySuggestions');
  const zipInput = document.getElementById('zipcode');
  const barangayNames = Object.keys(TAGUIG_BARANGAYS);
  let barangayActiveIndex = -1;
  function hideBarangaySuggestions() {
    barangayOptions.hidden = true;
    barangayOptions.classList.remove('opens-up');
    barangayOptions.style.removeProperty('--seller-dropdown-max-height');
    barangayInput.setAttribute('aria-expanded','false');
    barangayActiveIndex = -1;
  }
  function selectBarangay(name, updateZip = true) {
    barangayInput.value = name;
    if (updateZip) zipInput.value = TAGUIG_BARANGAYS[name];
    clearFieldError('barangay');
    clearFieldError('zipcode');
    hideBarangaySuggestions();
  }
  function renderBarangaySuggestions() {
    const term = barangayInput.value.trim().toLocaleLowerCase();
    const matching = barangayNames.filter(name => name.toLocaleLowerCase().includes(term)).slice(0,38);
    barangayOptions.replaceChildren();
    matching.forEach(name => {
      const button = document.createElement('button');
      button.type = 'button';
      button.setAttribute('role','option');
      button.className = 'barangay-suggestion';
      button.textContent = name;
      button.addEventListener('mousedown', event => event.preventDefault());
      button.addEventListener('click', () => selectBarangay(name));
      barangayOptions.appendChild(button);
    });
    barangayOptions.hidden = matching.length === 0;
    barangayInput.setAttribute('aria-expanded', String(matching.length > 0));
    barangayActiveIndex = -1;
    if (matching.length) positionSellerDropdown(barangayOptions, barangayInput, 220);
    else hideBarangaySuggestions();
  }
  barangayInput.addEventListener('input', () => {
    barangayInput.value = [...barangayInput.value].slice(0,25).join('');
    const exact = barangayNames.find(name => name.toLocaleLowerCase() === barangayInput.value.trim().toLocaleLowerCase());
    zipInput.value = exact ? TAGUIG_BARANGAYS[exact] : '';
    renderBarangaySuggestions();
    if (exact) clearFieldError('barangay');
  });
  barangayInput.addEventListener('focus', renderBarangaySuggestions);
  barangayInput.addEventListener('keydown', event => {
    const options = [...barangayOptions.querySelectorAll('button')];
    if (event.key === 'Escape') hideBarangaySuggestions();
    if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && options.length) {
      event.preventDefault();
      barangayActiveIndex = (barangayActiveIndex + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
      options.forEach((b,i) => b.classList.toggle('active', i === barangayActiveIndex));
    }
    if (event.key === 'Enter' && !barangayOptions.hidden && options.length) {
      event.preventDefault();
      options[Math.max(0,barangayActiveIndex)].click();
    }
  });
  barangayInput.addEventListener('blur', () => {
    window.setTimeout(hideBarangaySuggestions, 120);
    const exact = barangayNames.find(name => name.toLocaleLowerCase() === barangayInput.value.trim().toLocaleLowerCase());
    if (exact) selectBarangay(exact, false);
    else if (barangayInput.value.trim()) { zipInput.value=''; setFieldError('barangay','Choose a barangay from Taguig.'); }
  });
  zipInput.addEventListener('input', () => {
    zipInput.value = zipInput.value.replace(/\D/g, '').slice(0, 4);
    if (/^\d{4}$/.test(zipInput.value)) clearFieldError('zipcode');
  });
  if (TAGUIG_BARANGAYS[barangayInput.value] && !zipInput.value) zipInput.value = TAGUIG_BARANGAYS[barangayInput.value];
  const categoryHidden = document.getElementById('craft_category');
  const categoryToggle = document.getElementById('categoryToggle');
  const categoryOptions = document.getElementById('categoryOptions');
  const categoryChecks = [...document.querySelectorAll('.category-checkbox')];
  function syncCategorySelection(changed=null) {
    let selected = categoryChecks.filter(box=>box.checked).map(box=>box.value);
    if ([...selected.join(', ')].length > 100) {
      if (changed) changed.checked = false;
      selected = categoryChecks.filter(box=>box.checked).map(box=>box.value);
      setFieldError('craft_category','Selected categories are too long. Remove one.');
    } else if (selected.length) clearFieldError('craft_category');
    categoryHidden.value = selected.join(', ');
    categoryToggle.firstChild.textContent = selected.length ? selected.join(', ') : 'Choose categories ';
    categoryToggle.title = selected.join(', ');
  }
  function toggleCategoryMenu(open) {
    categoryOptions.hidden = !open;
    categoryToggle.setAttribute('aria-expanded',String(open));
    if (open) positionSellerDropdown(categoryOptions, categoryToggle, 230);
    else {
      categoryOptions.classList.remove('opens-up');
      categoryOptions.style.removeProperty('--seller-dropdown-max-height');
    }
  }
  categoryToggle.addEventListener('click', () => toggleCategoryMenu(categoryOptions.hidden));
  categoryChecks.forEach(box => box.addEventListener('change', () => syncCategorySelection(box)));
  document.addEventListener('click', event => {
    if (!event.target.closest('#categorySelect')) toggleCategoryMenu(false);
    if (!event.target.closest('#barangayBox')) hideBarangaySuggestions();
  });
  categoryToggle.addEventListener('keydown', event => { if (event.key === 'Escape') toggleCategoryMenu(false); });
  syncCategorySelection();
  const businessNameInput = document.getElementById('business_name');
  const descriptionInput = document.getElementById('business_description');
  businessNameInput.addEventListener('blur', () => {
    if (!businessNameInput.value.trim()) setFieldError('business_name','Enter your business name.');
    else clearFieldError('business_name');
  });
  descriptionInput.addEventListener('blur', () => {
    const count = [...descriptionInput.value.trim()].length;
    if (count < 20 || count > 300) setFieldError('business_description','Use 20 to 300 characters.');
    else clearFieldError('business_description');
  });
  const sellerGuideModal = document.getElementById('sellerGuideModal');
  const sellerGuideTitle = document.getElementById('sellerGuideTitle');
  let guideTrigger = null;
  function closeSellerGuide() {
    sellerGuideModal.classList.remove('open');
    sellerGuideModal.setAttribute('aria-hidden', 'true');
    closeSellerPopupHistory('sellerGuideModal');
    if (!document.querySelector('.policy-modal.open') && !documentPreviewModal?.classList.contains('open') && !sellerOtpModal?.classList.contains('open')) document.body.style.overflow = '';
    guideTrigger?.focus({preventScroll:true});
  }
  document.querySelectorAll('[data-open-guide]').forEach(button => {
    button.addEventListener('click', () => {
      const kind = button.dataset.openGuide;
      guideTrigger = button;
      sellerGuideTitle.textContent = kind === 'id' ? 'Valid ID Document Guide' : 'Business Document Guide';
      sellerGuideModal.querySelectorAll('[data-guide-panel]').forEach(panel => { panel.hidden = panel.dataset.guidePanel !== kind; });
      openSellerPopupHistory('sellerGuideModal');
      sellerGuideModal.classList.add('open');
      sellerGuideModal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      sellerGuideModal.querySelector('[data-close-guide]')?.focus({preventScroll:true});
    });
  });
  sellerGuideModal.querySelector('[data-close-guide]').addEventListener('click', closeSellerGuide);
  sellerGuideModal.addEventListener('click', event => { if (event.target === sellerGuideModal) closeSellerGuide(); });
  const simpleLiveFields = [
    'business_name',
    'craft_category',
    'business_description',
    'business_document_type',
    'street_address',
    'barangay',
    'city',
    'valid_id_type'
  ];
  simpleLiveFields.forEach(fieldId => {
    const field = document.getElementById(fieldId);
    const clearWhenValid = () => {
      if (String(field?.value || '').trim()) {
        clearFieldError(fieldId);
      }
    };
    field?.addEventListener('input', clearWhenValid);
    field?.addEventListener('change', clearWhenValid);
  });
  const passwordInput = document.getElementById('password');
  const confirmPasswordInput = document.getElementById('confirm_password');
  const passwordStrength = document.getElementById('passwordStrength');
  const passwordWarning = document.getElementById('passwordWarning');
  const passwordMatch = document.getElementById('passwordMatch');
  function updatePasswordSecurity() {
    const value = passwordInput.value;
    const complete = getMissingPasswordRules(value).length === 0;
    let level = '';
    if (value) level = complete && value.length >= 12 ? 'strong' : complete ? 'normal' : 'weak';
    passwordStrength.className = 'password-strength' + (level ? ' '+level : '');
    passwordWarning.textContent = level ? (level==='weak' ? 'Weak' : level==='normal' ? 'Normal' : 'Strong') : '';
    passwordInput.setAttribute('aria-invalid', value && !complete ? 'true' : 'false');
    updatePasswordMatch();
  }
  function updatePasswordMatch() {
    if (!confirmPasswordInput.value) {
      passwordMatch.textContent='';
      passwordMatch.className='password-match';
      confirmPasswordInput.setAttribute('aria-invalid','false');
      return;
    }
    const matches = passwordInput.value === confirmPasswordInput.value;
    passwordMatch.textContent = matches ? '✓ Passwords match.' : 'Passwords do not match.';
    passwordMatch.className = 'password-match ' + (matches ? 'valid' : 'invalid');
    confirmPasswordInput.setAttribute('aria-invalid', matches ? 'false' : 'true');
  }
  document.querySelectorAll('.password-toggle').forEach(button => {
    button.addEventListener('click', () => {
      const input = document.getElementById(button.dataset.target);
      const showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      button.querySelector('.eye-open').style.display = showing ? '' : 'none';
      button.querySelector('.eye-closed').style.display = showing ? 'none' : '';
      button.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    });
  });
  passwordInput.addEventListener('input', () => {
    updatePasswordSecurity();
    if (getMissingPasswordRules(passwordInput.value).length === 0) {
      clearFieldError('password');
    }
    if (
      confirmPasswordInput.value
      && passwordInput.value === confirmPasswordInput.value
    ) {
      clearFieldError('confirm_password');
    }
  });
  confirmPasswordInput.addEventListener('input', () => {
    updatePasswordMatch();
    if (
      confirmPasswordInput.value
      && passwordInput.value === confirmPasswordInput.value
    ) {
      clearFieldError('confirm_password');
    }
  });
  updatePasswordSecurity();
  function validateStep(step) {
    const invalidFields = [];
    const required = (fieldId, message) => {
      const field = getFieldElement(fieldId);
      const value = String(field?.value || '').trim();
      if (!value) {
        setFieldError(fieldId, message);
        invalidFields.push(fieldId);
        return false;
      }
      clearFieldError(fieldId);
      return true;
    };
    if (step === 1) {
      const firstName = document.getElementById('firstname').value.trim();
      const middleName = document.getElementById('middlename').value.trim();
      const lastName = document.getElementById('lastname').value.trim();
      const email = document.getElementById('email').value.trim();
      const phone = document.getElementById('phone').value.trim();
      const password = document.getElementById('password').value;
      const confirmPassword = document.getElementById('confirm_password').value;
      const idType = document.getElementById('valid_id_type').value;
      const idFront = document.getElementById('valid_id').files[0];
      const idBack = document.getElementById('valid_id_back').files[0];
      if (!firstName) {
        setFieldError('firstname', 'First name is required.');
        invalidFields.push('firstname');
      } else if (!isPersonalName(firstName)) {
        setFieldError('firstname', 'Letters, spaces and hyphens only (max 50).');
        invalidFields.push('firstname');
      } else {
        clearFieldError('firstname');
      }
      if (middleName && !isPersonalName(middleName, true)) {
        setFieldError('middlename', 'Letters, spaces, apostrophes and hyphens only (max 50).');
        invalidFields.push('middlename');
      } else {
        clearFieldError('middlename');
      }
      if (!lastName) {
        setFieldError('lastname', 'Last name is required.');
        invalidFields.push('lastname');
      } else if (!isPersonalName(lastName)) {
        setFieldError('lastname', 'Letters, spaces and hyphens only (max 50).');
        invalidFields.push('lastname');
      } else {
        clearFieldError('lastname');
      }
      if (!email) {
        setFieldError('email', 'Email address is required.');
        invalidFields.push('email');
      } else if (!emailFormatValid(email)) {
        setFieldError('email', 'Enter a valid email address.');
        invalidFields.push('email');
      } else {
        clearFieldError('email');
      }
      if (!/^09\d{9}$/.test(phone)) {
        setFieldError('phone', 'Enter an 11-digit mobile number starting with 09.');
        invalidFields.push('phone');
      } else {
        clearFieldError('phone');
      }
      const missingPasswordRules = getMissingPasswordRules(password);
      if (missingPasswordRules.length) {
        updatePasswordSecurity();
        setFieldError('password', 'Needs 8+ characters, uppercase, lowercase and number.');
        invalidFields.push('password');
      } else {
        clearFieldError('password');
      }
      if (!confirmPassword) {
        setFieldError('confirm_password', 'Please confirm your password.');
        invalidFields.push('confirm_password');
      } else if (password !== confirmPassword) {
        setFieldError('confirm_password', 'Passwords do not match.');
        invalidFields.push('confirm_password');
      } else {
        clearFieldError('confirm_password');
      }
      if (!VALID_ID_TYPES[idType]) {
        setFieldError('valid_id_type', 'Select the valid ID you will submit.');
        invalidFields.push('valid_id_type');
      } else {
        clearFieldError('valid_id_type');
      }
      if (!idFront) {
        setFieldError(
          'valid_id',
          idType === 'Passport'
            ? 'Take or choose a clear photo of the passport information page.'
            : 'Take or choose a clear photo of the front of your ID.'
        );
        invalidFields.push('valid_id');
      } else {
        clearFieldError('valid_id');
      }
      if (VALID_ID_TYPES[idType]?.requiresBack) {
        if (!idBack) {
          setFieldError('valid_id_back', 'Take or choose a clear photo of the back of your ID.');
          invalidFields.push('valid_id_back');
        } else {
          clearFieldError('valid_id_back');
        }
      } else {
        clearFieldError('valid_id_back');
      }
    }
    if (step === 2) {
      required('business_name', 'Business / shop name is required.');
      if (!categoryHidden.value.trim()) { setFieldError('craft_category','Select at least one category.'); invalidFields.push('craft_category'); }
      else if ([...categoryHidden.value].length > 100) { setFieldError('craft_category','Please select fewer categories.'); invalidFields.push('craft_category'); }
      else clearFieldError('craft_category');
      const descLength = [...document.getElementById('business_description').value.trim()].length;
      if (descLength < 20 || descLength > 300) { setFieldError('business_description','Description must be 20–300 characters.'); invalidFields.push('business_description'); }
      else clearFieldError('business_description');
      const documentType = document.getElementById('business_document_type').value;
      const businessFile = document.getElementById('business_permit').files[0];
      if (!documentType) {
        setFieldError('business_document_type', 'Select the business document you will submit.');
        invalidFields.push('business_document_type');
      } else {
        clearFieldError('business_document_type');
      }
      if (!businessFile) {
        setFieldError('business_permit', 'Upload the selected business verification document.');
        invalidFields.push('business_permit');
      } else {
        clearFieldError('business_permit');
      }
    }
    if (step === 3) {
      required('street_address', 'Street address is required.');
      const barangayName = barangayInput.value.trim();
      const selected = barangayNames.find(name=>name.toLowerCase()===barangayName.toLowerCase());
      if (!selected) { setFieldError('barangay','Select a Taguig barangay from the suggestions.'); invalidFields.push('barangay'); }
      else { barangayInput.value = selected; clearFieldError('barangay'); }
      if (document.getElementById('city').value !== 'Taguig City') { setFieldError('city','Taguig City only.'); invalidFields.push('city'); }
      else clearFieldError('city');
      if (!/^\d{4}$/.test(zipInput.value)) { setFieldError('zipcode','Enter a valid 4-digit ZIP code.'); invalidFields.push('zipcode'); }
      else clearFieldError('zipcode');
    }
    if (invalidFields.length) {
      focusFirstInvalid(invalidFields);
      return false;
    }
    return true;
  }
  function updateForm() {
    document.querySelectorAll('.form-step').forEach(step => {
      step.classList.remove('active');
    });
    document.querySelector(`[data-step="${currentStep}"]`).classList.add('active');
    for (let i = 1; i <= 4; i++) {
      const stepElement = document.getElementById(`step${i}`);
      stepElement.classList.remove('active', 'completed');
      if (i === currentStep) stepElement.classList.add('active');
      else if (i < furthestCompletedStep) stepElement.classList.add('completed');
      stepElement.disabled = i > furthestCompletedStep;
      if (i === currentStep) stepElement.setAttribute('aria-current', 'step');
      else stepElement.removeAttribute('aria-current');
      const line = stepElement.nextElementSibling;
      if (line && line.classList.contains('progress-line')) {
        line.classList.remove('active');
        if (i < currentStep) {
          line.classList.add('active');
        }
      }
    }
    if (currentStep === 4) {
      updateReview();
    }
    window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
  }
  function updateReview() {
    const form = document.getElementById('sellerForm');
    const firstname = form.querySelector('#firstname').value;
    const lastname = form.querySelector('#lastname').value;
    const middlename = form.querySelector('#middlename').value;
    const email = form.querySelector('#email').value;
    const phone = form.querySelector('#phone').value;
    const businessName = form.querySelector('#business_name').value;
    const category = form.querySelector('#craft_category').value;
    const description = form.querySelector('#business_description').value;
    const street = form.querySelector('#street_address').value;
    const barangay = form.querySelector('#barangay').value;
    const city = form.querySelector('#city').value;
    const zipcode = form.querySelector('#zipcode').value;
    const validIdType = form.querySelector('#valid_id_type').value;
    const businessDocumentType = form.querySelector('#business_document_type').value;
    const fullName = middlename
      ? `${firstname} ${middlename} ${lastname}`
      : `${firstname} ${lastname}`;
    document.getElementById('reviewName').textContent = fullName;
    document.getElementById('reviewEmail').textContent = email;
    document.getElementById('reviewPhone').textContent = phone;
    document.getElementById('reviewBusinessName').textContent = businessName;
    document.getElementById('reviewCategory').textContent = category;
    document.getElementById('reviewDescription').textContent = description;
    document.getElementById('reviewAddress').textContent = `${street}, ${barangay}, ${city} ${zipcode}`;
    document.getElementById('reviewValidIdType').textContent = validIdType || '-';
    document.getElementById('reviewBusinessDocumentType').textContent = businessDocumentType || '-';
    renderReviewDocument(
      'valid_id',
      'reviewValidIdFront',
      validIdType === 'Passport' ? 'Passport Information Page' : 'Valid ID — Front'
    );
    const backReview = document.getElementById('reviewValidIdBack');
    const requiresBack = Boolean(VALID_ID_TYPES[validIdType]?.requiresBack);
    backReview.hidden = !requiresBack;
    if (requiresBack) {
      renderReviewDocument('valid_id_back', 'reviewValidIdBack', 'Valid ID — Back');
    }
    renderReviewDocument(
      'business_permit',
      'reviewBusinessDocument',
      'Business Verification Document'
    );
  }
  function formatFileSize(bytes) {
    if (!Number.isFinite(bytes) || bytes < 1) return '';
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
  }
  function fileLooksLikePdf(file) {
    return file?.type === 'application/pdf' || /\.pdf$/i.test(file?.name || '');
  }
  function fileLooksLikeImage(file) {
    return /^image\/(jpeg|png|webp)$/i.test(file?.type || '')
      || /\.(jpe?g|png|webp)$/i.test(file?.name || '');
  }
  function validateDocumentFile(file, inputId) {
    if (!file) return { valid: false, message: 'Please choose a file.' };
    if (file.size > 5 * 1024 * 1024) {
      return { valid: false, message: 'The selected file must not exceed 5 MB.' };
    }
    const isValidIdPhoto = inputId === 'valid_id' || inputId === 'valid_id_back';
    if (isValidIdPhoto) {
      if (!fileLooksLikeImage(file)) {
        return {
          valid: false,
          message: 'Valid ID uploads must be a JPG, PNG, or WEBP image. Please take or choose a clear photo of the ID.'
        };
      }
      return { valid: true, message: '' };
    }
    if (!fileLooksLikePdf(file) && !fileLooksLikeImage(file)) {
      return {
        valid: false,
        message: 'Business documents must be PDF, JPG, PNG, or WEBP.'
      };
    }
    return { valid: true, message: '' };
  }
  function clearDocumentInput(inputId) {
    const input = document.getElementById(inputId);
    if (!input) return;
    const state = documentFileState.get(inputId);
    if (state?.url) URL.revokeObjectURL(state.url);
    documentFileState.delete(inputId);
    input.value = '';
    renderDocumentInput(inputId);
  }
  function renderDocumentInput(inputId) {
    const empty = document.querySelector(`[data-upload-empty="${inputId}"]`);
    const preview = document.querySelector(`[data-upload-preview="${inputId}"]`);
    const image = document.querySelector(`[data-file-image="${inputId}"]`);
    const pdf = document.querySelector(`[data-file-pdf="${inputId}"]`);
    const name = document.querySelector(`[data-file-name="${inputId}"]`);
    const size = document.querySelector(`[data-file-size="${inputId}"]`);
    const state = documentFileState.get(inputId);
    if (!state) {
      if (empty) empty.hidden = false;
      if (preview) preview.hidden = true;
      if (image) {
        image.hidden = true;
        image.removeAttribute('src');
      }
      if (pdf) pdf.hidden = true;
      if (name) name.textContent = '';
      if (size) size.textContent = '';
      return;
    }
    if (empty) empty.hidden = true;
    if (preview) preview.hidden = false;
    if (name) name.textContent = state.file.name;
    if (size) size.textContent = formatFileSize(state.file.size);
    if (fileLooksLikeImage(state.file)) {
      if (image) {
        image.src = state.url;
        image.hidden = false;
      }
      if (pdf) pdf.hidden = true;
    } else {
      if (image) {
        image.hidden = true;
        image.removeAttribute('src');
      }
      if (pdf) {
        pdf.hidden = false;
      }
    }
  }
  function handleDocumentSelection(input) {
    const file = input.files?.[0] || null;
    if (!file) {
      clearDocumentInput(input.id);
      return;
    }
    const validation = validateDocumentFile(file, input.id);
    if (!validation.valid) {
      input.value = '';
      clearDocumentInput(input.id);
      setFieldError(input.id, validation.message);
      return;
    }
    const oldState = documentFileState.get(input.id);
    if (oldState?.url) URL.revokeObjectURL(oldState.url);
    documentFileState.set(input.id, {
      file,
      url: URL.createObjectURL(file)
    });
    renderDocumentInput(input.id);
    clearFieldError(input.id);
  }
  const documentPreviewModal = document.getElementById('documentPreviewModal');
  const documentPreviewDialog = documentPreviewModal?.querySelector('.document-preview-dialog');
  const documentPreviewImage = document.getElementById('documentPreviewImage');
  const documentPreviewPdf = document.getElementById('documentPreviewPdf');
  const documentPreviewFileName = document.getElementById('documentPreviewFileName');
  const documentPreviewMediaKind = document.getElementById('documentPreviewMediaKind');
  const documentPreviewOpenPdf = document.getElementById('documentPreviewOpenPdf');
  let documentPreviewReturnFocus = null;
  function useNativeMobilePdfViewer() {
    // iOS browsers often cannot scroll through every page in an embedded PDF.
    return /iPad|iPhone|iPod/i.test(navigator.userAgent)
      || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  }
  function openDocumentPreview(inputId) {
    const state = documentFileState.get(inputId);
    if (!state || !documentPreviewModal) return;
    const isImage = fileLooksLikeImage(state.file);
    const isPdf = fileLooksLikePdf(state.file);
    if (!isImage && !isPdf) return;
    if (isPdf && useNativeMobilePdfViewer()) {
      const link = document.createElement('a');
      link.href = state.url;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      document.body.appendChild(link);
      link.click();
      link.remove();
      return;
    }
    documentPreviewReturnFocus = document.activeElement;
    documentPreviewFileName.textContent = state.file.name || 'File Preview';
    documentPreviewMediaKind.textContent = isPdf ? 'PDF document' : 'Full image';
    documentPreviewDialog.classList.toggle('is-pdf', isPdf);
    documentPreviewOpenPdf.hidden = !isPdf;
    if (isPdf) {
      documentPreviewOpenPdf.href = state.url;
      documentPreviewImage.hidden = true;
      documentPreviewImage.removeAttribute('src');
      documentPreviewPdf.src = state.url;
      documentPreviewPdf.hidden = false;
    } else {
      documentPreviewOpenPdf.removeAttribute('href');
      documentPreviewPdf.hidden = true;
      documentPreviewPdf.removeAttribute('src');
      documentPreviewImage.alt = 'Full image of ' + state.file.name;
      documentPreviewImage.src = state.url;
      documentPreviewImage.hidden = false;
    }
    openSellerPopupHistory('documentPreviewModal');
    documentPreviewModal.classList.add('open');
    documentPreviewModal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('document-preview-open');
    document.body.style.overflow = 'hidden';
    documentPreviewModal.querySelector('.document-preview-close')?.focus({preventScroll: true});
  }
  function closeDocumentPreview() {
    if (!documentPreviewModal || !documentPreviewModal.classList.contains('open')) return;
    documentPreviewModal.classList.remove('open');
    documentPreviewModal.setAttribute('aria-hidden', 'true');
    closeSellerPopupHistory('documentPreviewModal');
    document.body.classList.remove('document-preview-open');
    documentPreviewDialog.classList.remove('is-pdf');
    documentPreviewImage.hidden = true;
    documentPreviewImage.removeAttribute('src');
    documentPreviewPdf.hidden = true;
    documentPreviewPdf.removeAttribute('src');
    documentPreviewOpenPdf.hidden = true;
    documentPreviewOpenPdf.removeAttribute('href');
    if (
      !document.querySelector('.policy-modal.open')
      && !sellerOtpModal?.classList.contains('open')
      && !sellerGuideModal?.classList.contains('open')
    ) {
      document.body.style.overflow = '';
    }
    if (documentPreviewReturnFocus?.isConnected) {
      documentPreviewReturnFocus.focus({preventScroll: true});
    }
    documentPreviewReturnFocus = null;
  }
  function renderReviewDocument(inputId, containerId, label) {
    const container = document.getElementById(containerId);
    const state = documentFileState.get(inputId);
    if (!container) return;
    container.replaceChildren();
    const preview = document.createElement(state ? 'button' : 'div');
    preview.className = 'review-doc-preview';
    if (state) {
      preview.type = 'button';
      preview.dataset.fileView = inputId;
      preview.setAttribute('aria-label', `View ${label}`);
    }
    if (state && fileLooksLikeImage(state.file)) {
      const image = document.createElement('img');
      image.className = 'review-doc-thumb';
      image.src = state.url;
      image.alt = `${label} preview`;
      preview.appendChild(image);
    } else if (state && fileLooksLikePdf(state.file)) {
      const pdf = document.createElement('span');
      pdf.className = 'review-doc-pdf';
      pdf.textContent = 'PDF';
      preview.appendChild(pdf);
    } else {
      preview.textContent = '—';
    }
    const info = document.createElement('div');
    info.className = 'review-doc-info';
    const heading = document.createElement('strong');
    heading.className = 'review-doc-label';
    heading.textContent = label;
    const fileName = document.createElement('span');
    fileName.className = 'review-doc-name';
    fileName.textContent = state ? state.file.name : 'No file selected';
    info.append(heading, fileName);
    if (state) {
      const size = document.createElement('span');
      size.className = 'review-doc-size';
      size.textContent = formatFileSize(state.file.size);
      info.appendChild(size);
    }
    container.append(preview, info);
  }
  document.querySelectorAll('.document-file-input').forEach(input => {
    input.addEventListener('change', () => handleDocumentSelection(input));
    renderDocumentInput(input.id);
  });
  document.addEventListener('click', event => {
    const viewButton = event.target.closest('[data-file-view]');
    if (viewButton) {
      openDocumentPreview(viewButton.dataset.fileView);
      return;
    }
    const changeButton = event.target.closest('[data-file-change]');
    if (changeButton) {
      document.getElementById(changeButton.dataset.fileChange)?.click();
      return;
    }
    const removeButton = event.target.closest('[data-file-remove]');
    if (removeButton) {
      clearDocumentInput(removeButton.dataset.fileRemove);
    }
  });
  document.querySelectorAll('[data-document-preview-close]').forEach(control => {
    control.addEventListener('click', closeDocumentPreview);
  });
  documentPreviewModal?.addEventListener('keydown', event => {
    if (event.key !== 'Tab' || !documentPreviewModal.classList.contains('open')) return;
    const focusable = Array.from(documentPreviewDialog.querySelectorAll('button:not([disabled]), a[href]'))
      .filter(element => !element.hidden);
    if (focusable.length === 0) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });
  const validIdTypeSelect = document.getElementById('valid_id_type');
  const validIdBackCard = document.getElementById('validIdBackCard');
  const validIdFrontTitle = document.getElementById('validIdFrontTitle');
  const validIdFrontHelp = document.getElementById('validIdFrontHelp');
  function updateValidIdFields() {
    const selectedType = validIdTypeSelect.value;
    const config = VALID_ID_TYPES[selectedType];
    const isPassport = selectedType === 'Passport';
    validIdFrontTitle.textContent = isPassport
      ? 'Passport Information Page *'
      : 'Front of ID *';
    validIdFrontHelp.textContent = isPassport
      ? 'Take or choose a clear photo of the passport information/photo page.'
      : 'Take or choose a clear photo of the front side without cropping important details.';
    const showBack = Boolean(config?.requiresBack);
    validIdBackCard.hidden = !showBack;
    if (!showBack) {
      clearDocumentInput('valid_id_back');
    }
  }
  validIdTypeSelect.addEventListener('change', updateValidIdFields);
  updateValidIdFields();
  const businessDocumentTypeSelect = document.getElementById('business_document_type');
  const businessDocumentUploadTitle = document.getElementById('businessDocumentUploadTitle');
  const businessDocumentUploadHelp = document.getElementById('businessDocumentUploadHelp');
  function updateBusinessDocumentFields() {
    const selectedType = businessDocumentTypeSelect.value;
    businessDocumentUploadTitle.textContent = selectedType
      ? `${selectedType} *`
      : 'Business Verification Document *';
    businessDocumentUploadHelp.textContent = BUSINESS_DOCUMENT_HELP[selectedType]
      || 'Upload the document selected above.';
  }
  businessDocumentTypeSelect.addEventListener('change', updateBusinessDocumentFields);
  updateBusinessDocumentFields();
  const policyModal = document.getElementById('policy-modal');
  const sellerAgreementModal = document.getElementById('seller-agreement-modal');
  const policyTitle = document.getElementById('policy-title');
  document.querySelectorAll('.terms-checkbox a').forEach((link) => {
    link.addEventListener('click', function(event) {
      event.preventDefault();
      if (link.dataset.policy === 'seller-agreement') {
        openSellerPopupHistory('seller-agreement-modal');
        sellerAgreementModal.classList.add('open');
        document.body.classList.add('policy-modal-open');
        document.body.style.overflow = 'hidden';
        return;
      }
      const isTerms = link.dataset.policy === 'terms';
      policyTitle.textContent = isTerms ? 'Terms and Conditions' : 'Privacy Policy';
      openSellerPopupHistory('policy-modal');
      policyModal.classList.add('open');
      document.body.classList.add('policy-modal-open');
      document.body.style.overflow = 'hidden';
      const sectionId = isTerms ? 'terms-section' : 'privacy-section';
      window.setTimeout(() => document.getElementById(sectionId)?.scrollIntoView({ block: 'start' }), 0);
    });
  });
  function closePolicyModal(modal) {
    modal.classList.remove('open');
    closeSellerPopupHistory(modal.id);
    if (!document.querySelector('.policy-modal.open')) {
      document.body.classList.remove('policy-modal-open');
    }
    if (
      !document.querySelector('.policy-modal.open')
      && !sellerOtpModal?.classList.contains('open')
      && !documentPreviewModal?.classList.contains('open')
    ) {
      document.body.style.overflow = '';
    }
  }
  document.querySelectorAll('.policy-modal').forEach((modal) => {
    modal.querySelector('.policy-close').addEventListener('click', () => closePolicyModal(modal));
    modal.addEventListener('click', (event) => {
      if (event.target === modal) closePolicyModal(modal);
    });
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      if (sellerGuideModal?.classList.contains('open')) { closeSellerGuide(); return; }
      if (sellerOtpModal?.classList.contains('open')) {
        cancelSellerOtp();
        return;
      }
      if (documentPreviewModal?.classList.contains('open')) {
        closeDocumentPreview();
        return;
      }
      const openPolicy = document.querySelector('.policy-modal.open');
      if (openPolicy) closePolicyModal(openPolicy);
    }
  });
  const emailInput = document.getElementById('email');
  const emailMessage = document.getElementById('emailCheckMessage');
  let emailCheckTimer = null;
  let emailCheckSerial = 0;
  function emailFormatValid(email) {
    return emailInput.validity.valid && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) && !['g.mail.com','gmail.con','yahoo.con'].includes((email.split('@')[1] || '').toLowerCase());
  }
  async function checkSellerEmail() {
    const email = emailInput.value.trim().toLowerCase();
    const serial = ++emailCheckSerial;
    if (!email) { emailMessage.textContent=''; emailMessage.className='email-check-message'; return false; }
    if (!emailFormatValid(email)) { setFieldError('email','Enter a valid email address.'); return false; }
    if (emailVerified && email === verifiedEmail.toLowerCase()) {
      emailMessage.textContent='✓ Email verified'; emailMessage.className='email-check-message success'; return true;
    }
    emailMessage.textContent='Checking email...'; emailMessage.className='email-check-message info';
    try {
      const result = await postSellerOtpAction('seller_check_email',{email});
      if (serial!==emailCheckSerial || email!==emailInput.value.trim().toLowerCase()) return false;
      emailMessage.textContent = result.success ? '✓ Email available' : '';
      emailMessage.className = 'email-check-message' + (result.success ? ' success' : '');
      if (!result.success) setFieldError('email',result.message || 'Email cannot be used.');
      else clearFieldError('email');
      return !!result.success;
    } catch (_) {
      if(serial===emailCheckSerial) {
        emailMessage.textContent='';
        setFieldError('email','Could not check this email. Please try again.');
      }
      return false;
    }
  }
  if (emailVerified && verifiedEmail.toLowerCase() === emailInput.value.trim().toLowerCase()) {
    emailMessage.className='email-check-message success'; emailMessage.textContent='✓ Email verified';
  }
  emailInput.addEventListener('input', () => {
    clearTimeout(emailCheckTimer);
    emailCheckSerial++;
    const email = emailInput.value.trim();
    if (email.toLowerCase() !== verifiedEmail.toLowerCase()) { emailVerified=false; verifiedEmail=''; }
    emailMessage.textContent=''; emailMessage.className='email-check-message';
    if (emailFormatValid(email)) {
      clearFieldError('email');
      emailCheckTimer = setTimeout(checkSellerEmail,650);
    }
  });
  emailInput.addEventListener('blur', () => { clearTimeout(emailCheckTimer); checkSellerEmail(); });
  document.getElementById('sellerForm').addEventListener('keydown', function(event) {
    if (event.key === 'Enter' && !event.isComposing && event.target.matches('input:not([type="checkbox"]):not([type="file"])')) {
      // Text fields should never submit the entire multi-step form or bypass OTP.
      event.preventDefault();
    }
  });
  document.getElementById('sellerForm').addEventListener('submit', function(e) {
    for (const step of [1, 2, 3]) {
      if (!validateStep(step)) {
        e.preventDefault();
        currentStep = step;
        updateForm();
        return;
      }
    }
    if (
      !emailVerified
      || verifiedEmail.toLowerCase() !== document.getElementById('email').value.trim().toLowerCase()
    ) {
      e.preventDefault();
      currentStep = 1;
      updateForm();
      setFieldError('email', 'Verify this email using the OTP before submitting.');
      return;
    }
    if (!document.getElementById('agreeTerms').checked) {
      e.preventDefault();
      currentStep = 4;
      updateForm();
      setFieldError(
        'agreeTerms',
        'Please agree to the Terms and Conditions, Privacy Policy, and Seller Agreement.'
      );
      return;
    }
    clearFieldError('agreeTerms');
    const submitButton = document.getElementById('submitBtn');
    if (submitButton) {
      submitButton.disabled = true;
      submitButton.textContent = 'Submitting Application...';
    }
  });
  document.getElementById('agreeTerms')?.addEventListener('change', function() {
    if (this.checked) {
      clearFieldError('agreeTerms');
    }
  });
</script>
</body>
</html>
