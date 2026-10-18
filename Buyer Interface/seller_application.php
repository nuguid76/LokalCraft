<?php
require_once __DIR__ . '/buyer_auth.php';
require_once __DIR__ . '/../config/private_storage.php';
$userId = (int)$buyerId;
$authUserName = trim((string)($userName ?? 'Buyer'));
$error = '';
$errorField = '';
$success = '';
$applicationStatus = 'none';
$rejectionReason = '';
$applicationId = 0;
$reviewedAt = '';
$user = [
    'firstname' => '',
    'middlename' => '',
    'lastname' => '',
    'email' => '',
    'phone' => '',
];
$profile = [
    'businessname' => '',
    'craftcategory' => '',
    'businessdescription' => '',
    'validid_type' => '',
    'business_document_type' => '',
    'streetaddress' => '',
    'barangay' => '',
    'city' => '',
    'zipcode' => '',
];
$oldDocuments = [
    'validid' => '',
    'validid_back' => '',
    'businesspermit' => '',
];
if (empty($_SESSION['seller_application_csrf'])) {
    $_SESSION['seller_application_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['seller_application_csrf'];
class SellerApplicationUploadException extends RuntimeException
{
}
function sellerAppTextLength(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : (preg_match_all('/./us', $value, $matches) ?: 0);
}
function sellerAppInput(string $key): string
{
    return isset($_POST[$key]) && is_string($_POST[$key])
        ? trim($_POST[$key])
        : '';
}
function sellerAppIniBytes(string $value): int
{
    $value = trim($value);
    if ($value === '') return 0;
    $amount = (float)$value;
    $unit = strtolower(substr($value, -1));
    switch ($unit) {
        case 'g': return (int)($amount * 1073741824);
        case 'm': return (int)($amount * 1048576);
        case 'k': return (int)($amount * 1024);
        default: return (int)$amount;
    }
}
function getBusinessCraftCategories(mysqli $conn): array
{
    $categories = [];
    $stmt = $conn->prepare('SELECT category_name FROM craft_categories ORDER BY category_id ASC');
    if (!$stmt) throw new RuntimeException('Unable to load craft categories.');
    try {
        if (!$stmt->execute()) throw new RuntimeException('Unable to load craft categories.');
        $stmt->bind_result($categoryName);
        while ($stmt->fetch()) {
            $category = trim((string)$categoryName);
            if ($category !== '') $categories[] = $category;
        }
    } finally {
        $stmt->close();
    }
    return $categories;
}
function sellerAppSaveUpload(array $file, string $label, string $uploadDirectory, bool $allowPdf): string
{
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        throw new SellerApplicationUploadException("Please upload your {$label}.");
    }
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        throw new SellerApplicationUploadException("{$label} exceeds the server upload limit.");
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new SellerApplicationUploadException("Could not upload {$label}. Try again.");
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new SellerApplicationUploadException("Invalid {$label} upload.");
    }
    if (!is_numeric($file['size'] ?? null) || (int)$file['size'] < 1 || (int)$file['size'] > 5 * 1048576) {
        throw new SellerApplicationUploadException("{$label} must be 5 MB or smaller.");
    }
    if (!class_exists('finfo')) {
        throw new SellerApplicationUploadException('File validation is unavailable on the server.');
    }
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if ($allowPdf) $allowed['application/pdf'] = 'pdf';
    if (!isset($allowed[$mime])) {
        throw new SellerApplicationUploadException(
            $allowPdf ? "{$label} must be PDF, JPG, PNG, or WEBP." : "{$label} must be JPG, PNG, or WEBP."
        );
    }
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0700, true) && !is_dir($uploadDirectory)) {
        throw new SellerApplicationUploadException('Unable to prepare private document storage.');
    }
    if (!is_writable($uploadDirectory)) {
        throw new SellerApplicationUploadException('Private document storage is not writable.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new SellerApplicationUploadException("Unable to save {$label}.");
    }
    @chmod($destination, 0600);
    return $filename;
}
function saveSellerDocument(array $file, string $label, string $uploadDirectory): string
{
    return sellerAppSaveUpload($file, $label, $uploadDirectory, true);
}
function saveSellerIdImage(array $file, string $label, string $uploadDirectory): string
{
    return sellerAppSaveUpload($file, $label, $uploadDirectory, false);
}
function deleteStoredSellerDocument(string $storedValue): void
{
    $storedValue = trim($storedValue);
    if ($storedValue === '') {
        return;
    }
    /*
     * New Become a Seller / Seller Application uploads are stored as private
     * basenames. This also safely supports older public-path records from the
     * previous seller_application.php.
     */
    if (basename($storedValue) === $storedValue) {
        $root = realpath(LOKALCRAFT_SELLER_DOCUMENT_ROOT);
        if ($root === false) {
            return;
        }
        $candidate = realpath($root . DIRECTORY_SEPARATOR . $storedValue);
        if (
            $candidate !== false
            && is_file($candidate)
            && strpos($candidate, $root . DIRECTORY_SEPARATOR) === 0
        ) {
            @unlink($candidate);
        }
        return;
    }
    $legacyRoot = realpath(__DIR__ . '/../uploads/seller_documents');
    if ($legacyRoot === false) {
        return;
    }
    $legacyCandidate = realpath(
        __DIR__ . '/../' . ltrim(
            str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $storedValue),
            DIRECTORY_SEPARATOR
        )
    );
    if (
        $legacyCandidate !== false
        && is_file($legacyCandidate)
        && strpos($legacyCandidate, $legacyRoot . DIRECTORY_SEPARATOR) === 0
    ) {
        @unlink($legacyCandidate);
    }
}
function sellerAppTaguigBarangays(): array
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
try {
    $craftCategories = getBusinessCraftCategories($conn);
    if ($craftCategories === []) {
        $error = 'Craft categories are temporarily unavailable. Please try again later.';
    }
} catch (Throwable $e) {
    error_log('Buyer seller application categories: ' . $e->getMessage());
    $craftCategories = [];
    $error = 'Unable to load craft categories right now.';
}
$taguigBarangays = sellerAppTaguigBarangays();
$userStmt = $conn->prepare("
    SELECT
        id,
        firstname,
        middlename,
        lastname,
        email,
        phone,
        SellerOrNot,
        AdminOrNot,
        account_status
    FROM users
    WHERE id = ?
    LIMIT 1
");
if (!$userStmt) {
    $error = 'Unable to load your account information.';
} else {
    $userStmt->bind_param('i', $userId);
    $userStmt->execute();
    $userStmt->bind_result(
        $dbUserId,
        $dbFirstName,
        $dbMiddleName,
        $dbLastName,
        $dbEmail,
        $dbPhone,
        $dbSellerOrNot,
        $dbAdminOrNot,
        $dbAccountStatus
    );
    if (!$userStmt->fetch()) {
        $userStmt->close();
        header('Location: ../login.php');
        exit;
    }
    $userStmt->close();
    if ((int)$dbAdminOrNot === 1) {
        header('Location: ../Admin%20Interface/dashboard.php');
        exit;
    }
    $user = [
        'id' => (int)$dbUserId,
        'firstname' => (string)$dbFirstName,
        'middlename' => (string)($dbMiddleName ?? ''),
        'lastname' => (string)$dbLastName,
        'email' => (string)$dbEmail,
        'phone' => (string)($dbPhone ?? ''),
        'SellerOrNot' => (int)$dbSellerOrNot,
        'AdminOrNot' => (int)$dbAdminOrNot,
        'account_status' => (string)$dbAccountStatus,
    ];
}
$applicationStmt = $conn->prepare("
    SELECT
        application_id,
        status,
        rejection_reason,
        submitted_at,
        reviewed_at
    FROM seller_applications
    WHERE user_id = ?
    ORDER BY submitted_at DESC, application_id DESC
    LIMIT 1
");
if ($applicationStmt) {
    $applicationStmt->bind_param('i', $userId);
    $applicationStmt->execute();
    $applicationStmt->bind_result(
        $dbApplicationId,
        $dbApplicationStatus,
        $dbRejectionReason,
        $dbSubmittedAt,
        $dbReviewedAt
    );
    if ($applicationStmt->fetch()) {
        $applicationId = (int)$dbApplicationId;
        $applicationStatus = strtolower(
            trim((string)($dbApplicationStatus ?? 'none'))
        );
        $rejectionReason = trim(
            (string)($dbRejectionReason ?? '')
        );
        $reviewedAt = trim(
            (string)($dbReviewedAt ?? '')
        );
    }
    $applicationStmt->close();
}
$profileStmt = $conn->prepare("
    SELECT
        b.businessname,
        b.craftcategory,
        b.businessdescription,
        b.validid_type,
        b.validid,
        b.validid_back,
        b.business_document_type,
        b.businesspermit,
        l.streetaddress,
        l.barangay,
        l.city,
        l.zipcode
    FROM users u
    LEFT JOIN businessinfo b ON b.user_id = u.id
    LEFT JOIN locationinfo l ON l.user_id = u.id
    WHERE u.id = ?
    LIMIT 1
");
if ($profileStmt) {
    $profileStmt->bind_param('i', $userId);
    $profileStmt->execute();
    $profileStmt->bind_result(
        $dbBusinessName,
        $dbCraftCategory,
        $dbBusinessDescription,
        $dbValidIdType,
        $dbValidId,
        $dbValidIdBack,
        $dbBusinessDocumentType,
        $dbBusinessPermit,
        $dbStreetAddress,
        $dbBarangay,
        $dbCity,
        $dbZipcode
    );
    if ($profileStmt->fetch()) {
        $profile = [
            'businessname' => (string)($dbBusinessName ?? ''),
            'craftcategory' => (string)($dbCraftCategory ?? ''),
            'businessdescription' => (string)($dbBusinessDescription ?? ''),
            'validid_type' => (string)($dbValidIdType ?? ''),
            'business_document_type' => (string)($dbBusinessDocumentType ?? ''),
            'streetaddress' => (string)($dbStreetAddress ?? ''),
            'barangay' => (string)($dbBarangay ?? ''),
            'city' => (string)($dbCity ?? ''),
            'zipcode' => (string)($dbZipcode ?? ''),
        ];
        $oldDocuments['validid'] = trim((string)($dbValidId ?? ''));
        $oldDocuments['validid_back'] = trim((string)($dbValidIdBack ?? ''));
        $oldDocuments['businesspermit'] = trim((string)($dbBusinessPermit ?? ''));
    }
    $profileStmt->close();
}
$sellerAccountApproved = (
    (int)($user['SellerOrNot'] ?? 0) === 1
    && strtolower((string)($user['account_status'] ?? 'active')) === 'active'
    && $applicationStatus === 'approved'
);
$isApplicationApproved = $applicationStatus === 'approved';
if ($applicationStatus === 'pending') {
    $_SESSION['seller_application_status'] = 'pending';
    unset($_SESSION['seller_application_rejection_reason']);
} elseif ($applicationStatus === 'rejected') {
    $_SESSION['seller_application_status'] = 'rejected';
    $_SESSION['seller_application_rejection_reason'] = $rejectionReason;
}
if ($isApplicationApproved) {
    $_SESSION['seller_application_status'] = 'approved';
    unset($_SESSION['seller_application_rejection_reason']);
}
if (
    isset($_GET['enter_seller'])
    && $_GET['enter_seller'] === '1'
    && $isApplicationApproved
    && $sellerAccountApproved
) {
    $sellerSessionName = trim(
        (string)($user['firstname'] ?? '')
        . ' '
        . (string)($user['middlename'] ?? '')
        . ' '
        . (string)($user['lastname'] ?? '')
    );
    $sellerSessionName = preg_replace('/\s+/', ' ', $sellerSessionName) ?: 'Seller';
    $_SESSION['user_role'] = 'seller';
    $_SESSION['is_seller'] = true;
    $_SESSION['seller_user_id'] = $userId;
    $_SESSION['seller_name'] = $sellerSessionName;
    $_SESSION['seller_business_name'] = trim((string)($profile['businessname'] ?? '')) ?: 'Seller Portal';
    $_SESSION['seller_application_status'] = 'approved';
    unset($_SESSION['seller_application_rejection_reason']);
    header('Location: ../Seller%20Interface/seller_dashboard.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $limit = sellerAppIniBytes((string)ini_get('post_max_size'));
    if ($limit > 0 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit) {
        $error = 'Uploads exceed the server request limit. Choose smaller files.';
        $errorField = 'business_permit';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $postedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
        ? $_POST['csrf_token'] : '';
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } elseif ($applicationStatus === 'pending') {
        $error = 'Your seller application is still under review.';
    } elseif ($isApplicationApproved || $sellerAccountApproved) {
        $error = 'Your seller application has already been approved.';
    } else {
        $profile['businessname'] = sellerAppInput('businessname');
        $profile['craftcategory'] = sellerAppInput('craftcategory');
        $profile['businessdescription'] = sellerAppInput('businessdescription');
        $profile['validid_type'] = sellerAppInput('validid_type');
        $profile['business_document_type'] = sellerAppInput('business_document_type');
        $profile['streetaddress'] = sellerAppInput('streetaddress');
        $profile['barangay'] = sellerAppInput('barangay');
        $profile['city'] = sellerAppInput('city');
        $profile['zipcode'] = sellerAppInput('zipcode');
        $validId = $_FILES['validid'] ?? null;
        $validIdBack = $_FILES['validid_back'] ?? null;
        $businessDocument = $_FILES['businesspermit'] ?? null;
        $agreeTerms = isset($_POST['agree_terms']) && $_POST['agree_terms'] === '1';
        $validIdRequiresBack = isset($validIdTypes[$profile['validid_type']])
            && !empty($validIdTypes[$profile['validid_type']]['requires_back']);
        $selectedCategories = array_map('trim', explode(',', $profile['craftcategory']));
        $categoryValid = count($selectedCategories) >= 1
            && count($selectedCategories) <= count($craftCategories)
            && count(array_unique($selectedCategories)) === count($selectedCategories);
        foreach ($selectedCategories as $category) {
            if ($category === '' || !in_array($category, $craftCategories, true)) {
                $categoryValid = false;
                break;
            }
        }
        $matchedBarangay = null;
        foreach ($taguigBarangays as $brgyName => $brgyZip) {
            if (strcasecmp($brgyName, $profile['barangay']) === 0) {
                $matchedBarangay = $brgyName;
                break;
            }
        }
        if ($matchedBarangay !== null) $profile['barangay'] = $matchedBarangay;
        if ($profile['businessname'] === '' || sellerAppTextLength($profile['businessname']) > 50
            || preg_match('/[\x00-\x1f\x7f]/', $profile['businessname'])) {
            $errorField = 'businessname';
            $error = 'Shop name must be 1–50 characters.';
        } elseif ($craftCategories === [] || $profile['craftcategory'] === ''
            || sellerAppTextLength($profile['craftcategory']) > 100 || !$categoryValid) {
            $errorField = 'craftcategory';
            $error = 'Select valid craft categories (100 characters maximum combined).';
        } elseif (sellerAppTextLength($profile['businessdescription']) < 20
            || sellerAppTextLength($profile['businessdescription']) > 300) {
            $errorField = 'businessdescription';
            $error = 'Business description must be 20–300 characters.';
        } elseif (!isset($validIdTypes[$profile['validid_type']])) {
            $errorField = 'validid_type';
            $error = 'Select an accepted valid ID type.';
        } elseif (!in_array($profile['business_document_type'], $businessDocumentTypes, true)) {
            $errorField = 'business_document_type';
            $error = 'Select an accepted business document type.';
        } elseif (!is_array($validId) || ($validId['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errorField = 'valid_id';
            $error = 'Upload a valid ID front image (or passport information page).';
        } elseif ($validIdRequiresBack && (!is_array($validIdBack)
            || ($validIdBack['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)) {
            $errorField = 'valid_id_back';
            $error = 'Upload the back of your selected valid ID.';
        } elseif (!is_array($businessDocument)
            || ($businessDocument['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errorField = 'business_permit';
            $error = 'Upload your business verification document.';
        } elseif ($profile['streetaddress'] === '' || sellerAppTextLength($profile['streetaddress']) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $profile['streetaddress'])) {
            $errorField = 'streetaddress';
            $error = 'Street address must be 1–255 characters.';
        } elseif ($matchedBarangay === null || sellerAppTextLength($profile['barangay']) > 25) {
            $errorField = 'barangay';
            $error = 'Choose a barangay in Taguig City.';
        } elseif ($profile['city'] !== 'Taguig City') {
            $errorField = 'city';
            $error = 'Only Taguig City business locations are accepted.';
        } elseif (!preg_match('/^[0-9]{4}$/D', $profile['zipcode'])) {
            $errorField = 'zipcode';
            $error = 'Enter a valid 4-digit ZIP code.';
        } elseif (!$agreeTerms) {
            $errorField = 'agree_terms';
            $error = 'Please accept the terms and seller agreement.';
        }
        $createdFiles = [];
        if ($error === '') {
            $transactionStarted = false;
            try {
                $uploadDirectory = LOKALCRAFT_SELLER_DOCUMENT_ROOT;
                $validIdLabel = $profile['validid_type'] === 'Passport'
                    ? 'Passport Information Page'
                    : $profile['validid_type'] . ' Front';
                $validIdPath = saveSellerIdImage(
                    $validId,
                    $validIdLabel,
                    $uploadDirectory
                );
                $createdFiles[] = $validIdPath;
                $validIdBackPath = null;
                if ($validIdRequiresBack) {
                    $validIdBackPath = saveSellerIdImage(
                        $validIdBack,
                        $profile['validid_type'] . ' Back',
                        $uploadDirectory
                    );
                    $createdFiles[] = $validIdBackPath;
                }
                $businessDocumentPath = saveSellerDocument(
                    $businessDocument,
                    $profile['business_document_type'],
                    $uploadDirectory
                );
                $createdFiles[] = $businessDocumentPath;
                $conn->begin_transaction();
                $transactionStarted = true;
                // Serialize submissions with Seller approval/reapplication changes for this user.
                $accountLock = $conn->prepare('SELECT SellerOrNot, AdminOrNot, account_status FROM users WHERE id = ? FOR UPDATE');
                if (!$accountLock) throw new RuntimeException('Unable to lock seller application account.');
                $accountLock->bind_param('i', $userId);
                $accountLock->execute();
                $accountLock->bind_result($lockedSeller, $lockedAdmin, $lockedStatus);
                if (!$accountLock->fetch() || (int)$lockedAdmin !== 0
                    || strtolower(trim((string)$lockedStatus)) !== 'active') {
                    $accountLock->close();
                    throw new RuntimeException('Seller account state changed during submission.');
                }
                $accountLock->close();
                $latestLock = $conn->prepare('SELECT application_id, status FROM seller_applications WHERE user_id = ? ORDER BY submitted_at DESC, application_id DESC LIMIT 1 FOR UPDATE');
                if (!$latestLock) throw new RuntimeException('Unable to verify current application status.');
                $latestLock->bind_param('i', $userId);
                $latestLock->execute();
                $latestLock->bind_result($lockedAppId, $lockedAppStatus);
                $latestExists = $latestLock->fetch();
                $latestStatus = $latestExists ? strtolower(trim((string)$lockedAppStatus)) : 'none';
                $latestId = $latestExists ? (int)$lockedAppId : 0;
                $latestLock->close();
                if (in_array($latestStatus, ['pending', 'approved'], true) || $latestId !== $applicationId) {
                    throw new RuntimeException('Seller application status changed. Refresh and try again.');
                }
                $businessExistsStmt = $conn->prepare("
                    SELECT business_id
                    FROM businessinfo
                    WHERE user_id = ?
                    LIMIT 1
                ");
                $locationExistsStmt = $conn->prepare("
                    SELECT location_id
                    FROM locationinfo
                    WHERE user_id = ?
                    LIMIT 1
                ");
                if (!$businessExistsStmt || !$locationExistsStmt) {
                    throw new RuntimeException('Unable to prepare the seller application.');
                }
                $businessExistsStmt->bind_param('i', $userId);
                $businessExistsStmt->execute();
                $businessExistsStmt->bind_result($existingBusinessId);
                $businessExists = (bool)$businessExistsStmt->fetch();
                $businessExistsStmt->close();
                $locationExistsStmt->bind_param('i', $userId);
                $locationExistsStmt->execute();
                $locationExistsStmt->bind_result($existingLocationId);
                $locationExists = (bool)$locationExistsStmt->fetch();
                $locationExistsStmt->close();
                if ($businessExists) {
                    $businessStmt = $conn->prepare("
                        UPDATE businessinfo
                        SET
                            businessname = ?,
                            craftcategory = ?,
                            businessdescription = ?,
                            validid_type = ?,
                            businesspermit = ?,
                            validid = ?,
                            validid_back = ?,
                            business_document_type = ?
                        WHERE user_id = ?
                        LIMIT 1
                    ");
                    if (!$businessStmt) {
                        throw new RuntimeException('Unable to save business information.');
                    }
                    $businessStmt->bind_param(
                        'ssssssssi',
                        $profile['businessname'],
                        $profile['craftcategory'],
                        $profile['businessdescription'],
                        $profile['validid_type'],
                        $businessDocumentPath,
                        $validIdPath,
                        $validIdBackPath,
                        $profile['business_document_type'],
                        $userId
                    );
                } else {
                    $businessStmt = $conn->prepare("
                        INSERT INTO businessinfo
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
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    if (!$businessStmt) {
                        throw new RuntimeException('Unable to save business information.');
                    }
                    $businessStmt->bind_param(
                        'issssssss',
                        $userId,
                        $profile['businessname'],
                        $profile['craftcategory'],
                        $profile['businessdescription'],
                        $profile['validid_type'],
                        $businessDocumentPath,
                        $validIdPath,
                        $validIdBackPath,
                        $profile['business_document_type']
                    );
                }
                if (!$businessStmt->execute()) {
                    $businessStmt->close();
                    throw new RuntimeException('Unable to save business information.');
                }
                $businessStmt->close();
                if ($locationExists) {
                    $locationStmt = $conn->prepare("
                        UPDATE locationinfo
                        SET
                            streetaddress = ?,
                            barangay = ?,
                            city = ?,
                            zipcode = ?
                        WHERE user_id = ?
                        LIMIT 1
                    ");
                    if (!$locationStmt) {
                        throw new RuntimeException('Unable to save business location.');
                    }
                    $locationStmt->bind_param(
                        'ssssi',
                        $profile['streetaddress'],
                        $profile['barangay'],
                        $profile['city'],
                        $profile['zipcode'],
                        $userId
                    );
                } else {
                    $locationStmt = $conn->prepare("
                        INSERT INTO locationinfo
                        (user_id, streetaddress, barangay, city, zipcode)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    if (!$locationStmt) {
                        throw new RuntimeException('Unable to save business location.');
                    }
                    $locationStmt->bind_param(
                        'issss',
                        $userId,
                        $profile['streetaddress'],
                        $profile['barangay'],
                        $profile['city'],
                        $profile['zipcode']
                    );
                }
                if (!$locationStmt->execute()) {
                    $locationStmt->close();
                    throw new RuntimeException('Unable to save business location.');
                }
                $locationStmt->close();
                if ($applicationId > 0) {
                    $applicationUpdateStmt = $conn->prepare("
                        UPDATE seller_applications
                        SET
                            status = 'pending',
                            rejection_reason = NULL,
                            submitted_at = NOW(),
                            reviewed_at = NULL,
                            reviewed_by = NULL
                        WHERE application_id = ?
                          AND user_id = ?
                        LIMIT 1
                    ");
                    if (!$applicationUpdateStmt) {
                        throw new RuntimeException('Unable to update your seller application.');
                    }
                    $applicationUpdateStmt->bind_param(
                        'ii',
                        $applicationId,
                        $userId
                    );
                    if (!$applicationUpdateStmt->execute()) {
                        $applicationUpdateStmt->close();
                        throw new RuntimeException('Unable to update your seller application.');
                    }
                    $applicationUpdateStmt->close();
                } else {
                    $applicationInsertStmt = $conn->prepare("
                        INSERT INTO seller_applications
                        (user_id, status, submitted_at)
                        VALUES (?, 'pending', NOW())
                    ");
                    if (!$applicationInsertStmt) {
                        throw new RuntimeException('Unable to create your seller application.');
                    }
                    $applicationInsertStmt->bind_param('i', $userId);
                    if (!$applicationInsertStmt->execute()) {
                        $applicationInsertStmt->close();
                        throw new RuntimeException('Unable to create your seller application.');
                    }
                    $applicationInsertStmt->close();
                }
                /*
                 * This person is already a Buyer, so the Buyer account stays active
                 * while the seller application is pending.
                 */
                $sellerResetStmt = $conn->prepare("
                    UPDATE users
                    SET
                        SellerOrNot = 0,
                        account_status = 'active'
                    WHERE id = ?
                    LIMIT 1
                ");
                if (!$sellerResetStmt) {
                    throw new RuntimeException('Unable to update account status.');
                }
                $sellerResetStmt->bind_param('i', $userId);
                if (!$sellerResetStmt->execute()) {
                    $sellerResetStmt->close();
                    throw new RuntimeException('Unable to update account status.');
                }
                $sellerResetStmt->close();
                $conn->commit();
                $transactionStarted = false;
                foreach ($oldDocuments as $oldDocument) {
                    if (
                        $oldDocument !== ''
                        && !in_array(basename($oldDocument), $createdFiles, true)
                    ) {
                        deleteStoredSellerDocument($oldDocument);
                    }
                }
                $_SESSION['is_seller'] = false;
                $_SESSION['seller_application_status'] = 'pending';
                unset(
                    $_SESSION['seller_id'],
                    $_SESSION['seller_user_id'],
                    $_SESSION['seller_name'],
                    $_SESSION['seller_business_name'],
                    $_SESSION['seller_application_rejection_reason']
                );
                header('Location: seller_application.php?submitted=1', true, 303);
                exit;
            } catch (Throwable $e) {
                if ($transactionStarted) {
                    try {
                        $conn->rollback();
                    } catch (Throwable $ignored) {
                    }
                }
                foreach ($createdFiles as $createdFile) {
                    $candidate = LOKALCRAFT_SELLER_DOCUMENT_ROOT
                        . DIRECTORY_SEPARATOR
                        . basename($createdFile);
                    if (is_file($candidate)) {
                        @unlink($candidate);
                    }
                }
                error_log('Buyer seller application submission failed: ' . $e->getMessage());
                $error = $e instanceof SellerApplicationUploadException
                    ? $e->getMessage()
                    : 'Unable to submit your seller application. Please try again.';
            }
        }
    }
}
if (isset($_GET['submitted']) && $_GET['submitted'] === '1') {
    $success = 'Your seller application has been submitted and is now pending administrator review.';
    $applicationStatus = 'pending';
}
$fullName = trim(
    (string)($user['firstname'] ?? '')
    . ' '
    . (string)($user['middlename'] ?? '')
    . ' '
    . (string)($user['lastname'] ?? '')
);
$fullName = preg_replace('/\s+/', ' ', $fullName);
$userName = $fullName !== '' ? $fullName : ($authUserName !== '' ? $authUserName : 'Buyer');
$activePage = 'profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LokalCraft | Seller Application</title>
<style>
:root{--app-orange:#e98b40;--app-dark:#a85e26;--app-ink:#352e28;--app-muted:#81766d;--app-line:#eadfd5;--app-cream:#f8f0e8;--app-bg:#f7f6f4;--app-error:#b83939;--app-success:#28794b}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;background:var(--app-bg);color:var(--app-ink);font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
[hidden]{display:none!important}
.page{display:block!important;min-height:100vh!important}
.page>.content{position:relative!important;width:auto!important;max-width:none!important;min-height:100vh!important;margin-left:240px!important;padding:88px 24px 45px!important;overflow:visible!important;z-index:1}
.app-wrap{max-width:880px;margin:0 auto;width:100%;min-width:0}
.app-top{display:flex;justify-content:space-between;align-items:center;gap:14px;margin:0 0 20px}
.app-top h1{margin:0;font-size:clamp(1.45rem,2.8vw,1.85rem);letter-spacing:-.04em;font-weight:800}
.back-link{flex-shrink:0;border:1px solid var(--app-line);background:#fff;color:#705f50;text-decoration:none;padding:9px 12px;border-radius:11px;font-size:.8rem;font-weight:700}
.back-link:hover{background:var(--app-cream)}
.app-notice{padding:12px 14px;border-radius:12px;background:#fff;border:1px solid var(--app-line);font-size:.85rem;margin-bottom:15px;overflow-wrap:anywhere}
.app-notice.error{background:#fff5f3;color:#a73232;border-color:#ebbbbb}
.app-notice.success{background:#effaf1;color:#267342;border-color:#c3e3cc}
.app-status{border-radius:16px;background:#fff;border:1px solid var(--app-line);padding:26px;text-align:center}
.app-status h2{margin:0 0 8px;font-size:1.25rem}
.app-status p{margin:0 0 14px;color:var(--app-muted);font-size:.89rem}
.app-status .reason{padding:12px;border-radius:10px;background:#fff1ee;color:#9c3931;margin-bottom:14px;text-align:left;overflow-wrap:anywhere}
.app-status a{display:inline-flex;padding:11px 15px;background:var(--app-cream);border:1px solid var(--app-line);border-radius:11px;color:var(--app-ink);text-decoration:none;font-weight:700;font-size:.86rem}
.app-status a.primary{background:var(--app-orange);color:#fff;border-color:var(--app-orange)}
.rejection-reason{margin-top:6px}
.app-steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px;margin-bottom:17px}
.step-label{display:flex;align-items:center;gap:10px;padding:12px 10px;border:1px solid var(--app-line);border-radius:13px;background:#fff;color:#91867e;min-width:0;transition:.15s ease;text-align:left}
.step-label .number{width:28px;height:28px;flex:0 0 28px;border:1px solid #e6d6c8;display:grid;place-items:center;border-radius:9px;background:#faf6f1;font-size:.76rem;font-weight:800}
.step-label .step-copy{font-size:.76rem;font-weight:700;min-width:0}
.step-label.active{background:#fff4e9;color:#965322;border-color:#e9c4a5;box-shadow:0 2px 12px rgba(101,62,27,.05)}
.step-label.active .number,.step-label.done .number{background:var(--app-orange);color:#fff;border-color:var(--app-orange)}
.step-label.done{color:#64594e}
.app-form{background:#fff;border:1px solid var(--app-line);border-radius:18px;box-shadow:0 10px 35px rgba(55,37,19,.04);overflow:visible;min-width:0}
.wizard-panel{padding:23px 24px 8px;min-width:0}
.panel-heading{margin:0 0 17px;font-size:1.04rem;font-weight:800;letter-spacing:-.02em}
.field-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.field{min-width:0;position:relative}
.field.wide{grid-column:1 / -1}
.field>label,.field-label{display:block;margin:0 0 7px;color:#584d44;font-size:.8rem;font-weight:700}
.field input:not([type=file]):not([type=checkbox]),.field select,.field textarea{width:100%;max-width:100%;min-height:45px;padding:11px 12px;border-radius:11px;border:1px solid #e0d5ca;background:#fbf9f6;color:var(--app-ink);outline:none;font-size:.91rem;transition:.15s ease}
.field textarea{min-height:112px;resize:vertical;line-height:1.5}
.field input:focus,.field select:focus,.field textarea:focus{border-color:#d9a476!important;background:#fff!important;box-shadow:0 0 0 3px rgba(233,139,64,.10)}
.field input[readonly]{background:#f0eeeb!important;color:#6f675f;cursor:default}
.field input.is-valid,.field textarea.is-valid,.field select.is-valid,.category-toggle.is-valid{border-color:#8fceaa!important}
.field input.is-invalid,.field textarea.is-invalid,.field select.is-invalid,.category-toggle.is-invalid{border-color:#d96d63!important;background:#fffafa!important}
.field-feedback{display:block;min-height:0;margin-top:4px;font-size:.72rem;line-height:1.4;color:var(--app-error)}
.field-feedback:empty{display:none}
.counter{display:flex;justify-content:end;margin-top:5px;font-size:.72rem;color:#93867a;font-variant-numeric:tabular-nums}
.counter.ok{color:var(--app-success)}
.category-picker,.barangay-picker{position:relative;min-width:0}
.category-toggle{display:flex;align-items:center;justify-content:space-between;gap:10px;text-align:left;width:100%;min-height:45px;border:1px solid #e0d5ca;border-radius:11px;padding:10px 12px;color:#73695f;background:#fbf9f6;font-size:.91rem}
.category-toggle span:first-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.category-options,.barangay-options{position:absolute;top:calc(100% + 5px);left:0;right:0;z-index:450;max-height:220px;overflow-y:auto;border:1px solid #e3d4c5;border-radius:12px;background:#fff;box-shadow:0 15px 35px rgba(54,38,24,.15);padding:6px}
.category-choice{display:flex;align-items:center;gap:9px;padding:10px 8px;border-radius:8px;cursor:pointer;font-size:.85rem;color:#554c44}
.category-choice:hover,.barangay-option:hover,.barangay-option.highlight{background:#fff3e7}
.category-choice input{width:17px;height:17px;accent-color:#d78036;flex-shrink:0}
.barangay-option{display:block;width:100%;padding:10px;border:0;border-radius:9px;background:transparent;text-align:left;font-size:.85rem;color:#51463d}
.group-divider{height:1px;background:#eee5dd;margin:22px 0}
.subheading{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:0 0 13px}
.subheading h3{margin:0;font-size:.91rem;font-weight:800}
.guide-btn{padding:6px 9px;border:1px solid #ead6c4;border-radius:9px;background:#fff8f0;color:#9d5a26;font-size:.75rem;font-weight:700}
.guide-btn:hover{background:#fbe8d6}
.doc-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px;margin:14px 0 0}
.upload-card{padding:12px;border-radius:12px;background:#fcfaf8;border:1px solid #e9dfd5;min-width:0}
.upload-card.is-invalid{border-color:#d96d63;background:#fff9f8}
.upload-title{font-size:.78rem;font-weight:750;color:#574c42;margin:0 0 8px}
.file-input{position:absolute;opacity:0;width:1px;height:1px;pointer-events:none}
.upload-box{display:flex;align-items:center;justify-content:center;flex-direction:column;gap:5px;min-height:95px;border:1px dashed #d8c9b9;border-radius:10px;color:#9c6335;background:#fff;cursor:pointer;text-align:center;padding:10px}
.upload-box svg{width:24px;height:24px}
.upload-box strong{font-size:.77rem;color:#5c5147}
.upload-box small{font-size:.7rem;color:#95877b}
.upload-box:hover{border-color:#db995d;background:#fffaf6}
.file-preview{display:flex;align-items:center;gap:9px;min-width:0;border:1px solid #eaded2;background:#fff;border-radius:10px;padding:8px}
.file-thumb{width:45px;height:45px;display:grid;place-items:center;flex:0 0 45px;border-radius:8px;background:#f4ede5;color:#a4612d;overflow:hidden;font-size:.72rem;font-weight:850}
.file-thumb img{width:100%;height:100%;object-fit:cover}
.file-meta{flex:1;min-width:0}
.file-meta strong{display:block;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;font-size:.72rem;color:#5d5147}
.file-meta small{color:#87796d;font-size:.68rem}
.file-tools{display:flex;gap:6px;margin-top:6px}
.file-tools button{border:1px solid #e5d9ce;border-radius:6px;background:#fff;padding:4px 7px;font-size:.68rem;color:#7d5740;font-weight:700}
.file-tools button:hover{background:#fff2e6}
.location-title{display:flex;align-items:center;gap:7px}
.info-tip{position:relative;display:inline-flex;align-items:center;justify-content:center;border:1px solid #e2c1a2;border-radius:50%;color:#a9672d;background:#fff5ed;width:20px;height:20px;font-size:.76rem;font-weight:800}
.info-tip:focus-visible{outline:2px solid var(--app-orange);outline-offset:2px}
.tip-bubble{position:absolute;left:26px;top:-10px;width:215px;max-width:calc(100vw - 110px);padding:9px 11px;background:#44352a;color:#fff;border-radius:9px;font-size:.73rem;line-height:1.45;font-weight:500;z-index:500;box-shadow:0 10px 25px rgba(0,0,0,.18)}
.info-tip:hover .tip-bubble,.info-tip:focus .tip-bubble,.info-tip.tip-open .tip-bubble{display:block!important}
.review-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 24px;margin:12px 0 22px}
.review-group{border:1px solid #eae1d8;border-radius:12px;padding:15px;background:#fdfbf9;min-width:0}
.review-group h3{font-size:.85rem;color:#9c612f;margin:0 0 12px}
.review-item{margin:0 0 12px;min-width:0}
.review-item:last-child{margin-bottom:0}
.review-item .key{display:block;color:#95877a;font-size:.69rem;margin-bottom:2px}
.review-item .value{font-size:.83rem;font-weight:650;color:#54483f;overflow-wrap:anywhere;white-space:pre-wrap}
.review-group.wide{grid-column:1/-1}
.agreement{display:flex;align-items:flex-start;gap:9px;border-top:1px solid var(--app-line);padding:17px 0 3px;font-size:.82rem;line-height:1.5;color:#554a41}
.agreement input{margin-top:3px;width:17px;height:17px;flex:0 0 17px;accent-color:var(--app-orange)}
.agreement a{color:#9c5727;font-weight:700}
.app-actions{display:flex;gap:10px;align-items:center;justify-content:space-between;border-top:1px solid #f0e7df;padding:17px 24px 20px}
.app-actions button{min-width:120px;padding:11px 17px;border-radius:11px;border:1px solid #e7d8cb;background:#fff;color:#776558;font-weight:750;font-size:.85rem}
.app-actions button.primary{border-color:var(--app-orange);color:#fff;background:var(--app-orange)}
.app-actions button.primary:hover{background:#d97b32}
.app-actions button:disabled{opacity:.6;cursor:wait}
.overlay-modal{position:fixed;inset:0;z-index:2147483600;display:none;align-items:center;justify-content:center;background:rgba(36,26,19,.5);padding:12px;-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px)}
.overlay-modal.open{display:flex}
.modal-card{width:min(690px,100%);max-height:calc(100dvh - 24px);display:flex;flex-direction:column;border-radius:17px;background:#fffdfb;overflow:hidden;box-shadow:0 25px 70px rgba(0,0,0,.22);border:1px solid #ecdace}
.modal-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 17px;border-bottom:1px solid #eee2d7}
.modal-head h2{font-size:1.03rem;margin:0;color:#493d33}
.modal-close{border:1px solid #e8dcd1;border-radius:9px;min-width:34px;height:34px;background:#fff;color:#625448;font-size:1.2rem}
.modal-content{padding:18px;overflow:auto;font-size:.85rem;line-height:1.6;color:#63574d}
.modal-content ul{margin:5px 0;padding-left:22px}
.modal-content li{margin:7px 0}
body.app-modal-open{overflow:hidden}
.viewer-media{display:block;max-width:100%;max-height:75dvh;object-fit:contain;margin:auto}
.viewer-frame{display:block;width:100%;height:65dvh;border:0}
@media(max-width:840px){.page{display:flex!important;flex-direction:column!important}.page>.content{width:100%!important;max-width:100%!important;margin-left:0!important;padding:79px 13px 32px!important;min-height:100vh!important;overflow:visible!important}.field input:not([type=file]):not([type=checkbox]),.field select,.field textarea,.category-toggle,.barangay-option{font-size:16px!important}}
@media(max-width:620px){.app-top h1{font-size:1.45rem}.app-top{align-items:flex-start}.back-link{font-size:.73rem;padding:8px 10px}.app-steps{gap:5px}.step-label{flex-direction:column;gap:4px;justify-content:center;padding:9px 3px;text-align:center}.step-label .step-copy{font-size:.66rem}.step-label .number{width:25px;height:25px;flex:0 0 25px}.wizard-panel{padding:18px 15px 8px}.field-grid,.doc-row,.review-grid{grid-template-columns:1fr}.field.wide,.review-group.wide{grid-column:auto}.app-actions{padding:14px 15px 18px}.app-actions button{min-width:104px;padding:11px 12px}.tip-bubble{left:-80px;top:26px;max-width:240px}.field input:not([type=file]):not([type=checkbox]),.field select,.field textarea,.category-toggle,.barangay-option{font-size:16px!important}.category-choice{font-size:16px}.app-wrap{min-width:0}.app-form{min-width:0}}
@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important;transition:none!important}}

/* Reachable wizard tabs. Future steps stay disabled until validated. */
.step-label{font:inherit;cursor:pointer}
.step-label:not(:disabled):hover{background:#fff6ed;border-color:#dfba96;color:#925826}
.step-label:focus-visible,.file-preview:focus-visible,.review-file:focus-visible{outline:3px solid rgba(233,139,64,.35);outline-offset:2px}
.step-label:disabled{cursor:default;opacity:.72}
.step-label.active{cursor:default}
/* The entire uploaded preview opens on tap, except the separate edit controls. */
.file-preview[data-preview-open]{cursor:pointer;transition:border-color .15s ease,background .15s ease}
.file-preview[data-preview-open]:hover{background:#fff8f0;border-color:#dba87e}
.file-preview[data-preview-open] .file-thumb,.file-preview[data-preview-open] .file-meta{pointer-events:none}
.file-preview[data-preview-open] .file-tools{pointer-events:auto}
.file-preview[data-preview-open] .file-tools button{pointer-events:auto;position:relative;z-index:1}
/* Compact, clickable image/PDF thumbnails on the review step. */
.review-file{width:100%;min-width:0;display:flex;gap:11px;align-items:center;text-align:left;border:1px solid #e9ddd0;border-radius:11px;background:#fff;padding:9px 10px;margin:4px 0 2px;cursor:pointer;color:#5d5147;transition:background .15s,border-color .15s}
.review-file:hover{background:#fff8f1;border-color:#dca77d}
.review-file:disabled{opacity:.6;cursor:default}
.review-file-thumb{width:52px;height:54px;flex:0 0 52px;border-radius:8px;background:#f6eee5;display:grid;place-items:center;overflow:hidden;color:#a4612d;font-size:.69rem;font-weight:850}
.review-file-thumb img{display:block;width:100%;height:100%;object-fit:cover}
.review-file-thumb.pdf{background:#fff0e9;border:1px solid #f2d2c2;color:#ab5434}
.review-file-thumb.pdf svg{width:32px;height:36px;display:block}
.review-file-detail{display:block;flex:1;min-width:0}
.review-file-detail strong{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.79rem;font-weight:750}
.review-file-detail small{display:block;margin-top:3px;color:#97887b;font-size:.69rem}
.review-view-icon{width:19px;height:19px;flex:0 0 19px;color:#b67949}
.viewer-open-pdf{display:block;align-self:flex-start;margin:12px 17px 0;border:1px solid #e0ba95;border-radius:9px;background:#fff5ec;padding:9px 12px;color:#975826;font-size:.78rem;font-weight:750;text-decoration:none}
.viewer-open-pdf:hover{background:#ffe9d7}
.viewer-media{touch-action:auto;user-select:none;-webkit-user-select:none}
@media(max-width:620px){.review-file{min-height:68px}.review-file-thumb{width:48px;height:50px;flex-basis:48px}.step-label:not(:disabled){-webkit-tap-highlight-color:rgba(233,139,64,.14)}}

/* Seller Application styling aligned with Buyer Profile's LokalCraft visual system. */
.page > .content.buyer-seller-application-content {
  position:fixed!important;left:240px!important;right:0!important;top:0!important;bottom:0!important;
  width:calc(100vw - 240px)!important;max-width:none!important;min-width:0!important;
  min-height:0!important;margin:0!important;padding:88px 26px 45px!important;
  overflow-x:hidden!important;overflow-y:auto!important;-webkit-overflow-scrolling:touch;
}
.app-wrap{max-width:1080px}
.app-top{margin:0 0 21px;gap:14px;min-width:0}
.app-top-copy{min-width:0}
.app-eyebrow{display:block;margin:0 0 5px;color:#b57945;font-size:.67rem;font-weight:800;letter-spacing:.15em}
.app-top h1{font-size:clamp(1.5rem,3vw,2.05rem);font-weight:800;line-height:1.15;color:#312a25}
.back-link{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:43px;padding:10px 15px;
  border:1px solid #ead6c3;border-radius:12px;background:#fffdfb;color:#a86836;font-size:.81rem;font-weight:760;
  box-shadow:0 4px 14px rgba(80,45,20,.035);transition:background .16s,border-color .16s}
.back-link svg{width:17px;height:17px;flex:0 0 17px}
.back-link:hover{background:#fff3e8;border-color:#ddb88f}
.back-link:focus-visible,.guide-btn:focus-visible,.app-actions button:focus-visible,.info-tip:focus-visible{outline:3px solid #f1b681;outline-offset:2px}
.app-intro{position:relative;isolation:isolate;display:flex;justify-content:space-between;align-items:center;gap:18px;
  padding:25px 29px;min-height:130px;margin:0 0 18px;overflow:hidden;border:1px solid #eadac9;border-radius:20px;
  background:linear-gradient(113deg,#fffdfb 1%,#fff8f1 49%,#fcead6 100%);box-shadow:0 12px 27px rgba(70,44,22,.055)}
.app-intro::before{content:"";position:absolute;z-index:-1;width:260px;height:260px;border:1px solid rgba(224,154,92,.22);
  border-radius:50%;top:-193px;right:-62px;box-shadow:0 0 0 42px rgba(251,226,197,.23),0 0 0 92px rgba(253,235,216,.27);pointer-events:none}
.app-intro-main{display:flex;align-items:center;gap:18px;min-width:0}
.app-intro-icon{display:grid;place-items:center;flex:0 0 67px;width:67px;height:67px;border-radius:19px;border:3px solid #fff;
  background:linear-gradient(135deg,#f3b87a,#d57c38);box-shadow:0 8px 18px rgba(184,103,37,.17);color:#fff}
.app-intro-icon svg{width:33px;height:33px}
.app-intro-copy{min-width:0}
.app-intro-kicker{display:block;font-size:.65rem;letter-spacing:.13em;font-weight:800;color:#b77540}
.app-intro-copy h2{margin:5px 0 0;color:#382b23;line-height:1.2;letter-spacing:-.035em;font-size:clamp(1.12rem,2.6vw,1.52rem);font-weight:800;overflow-wrap:anywhere}
.app-intro-badge{display:inline-flex;align-items:center;gap:8px;flex:0 0 auto;padding:9px 12px;
  border:1px solid #e7e1d9;border-radius:999px;background:#fffdfa;color:#705f53;font-weight:750;font-size:.73rem;white-space:nowrap}
.app-intro-dot{width:8px;height:8px;border-radius:50%;background:#e98b40;box-shadow:0 0 0 3px #fff0df}
.app-steps{gap:12px;margin-bottom:17px}
.step-label{position:relative;display:flex;align-items:center;gap:13px;min-height:70px;padding:13px 16px;
  border:1px solid #ebe3dc;border-radius:15px;background:#fff;color:#8c8075;box-shadow:0 7px 17px rgba(55,36,17,.035)}
.step-label .number{width:35px;height:35px;flex:0 0 35px;border-radius:11px;background:#f8f4ef;color:#9d806a;border:1px solid #f1e6da;font-size:.83rem}
.step-label .step-copy{font-size:.82rem;font-weight:780;line-height:1.25;white-space:normal}
.step-label.active{color:#915422;border-color:#e9c8a7;background:#fff8f0;box-shadow:0 7px 17px rgba(157,94,31,.065)}
.step-label.active::before{content:"";position:absolute;left:0;top:15px;bottom:15px;width:3px;border-radius:0 6px 6px 0;background:#e98b40}
.step-label.active .number,.step-label.done .number{background:#e98b40;border-color:#e98b40;color:#fff}
.step-label.done{color:#685749}
.app-form,.app-status{border:1px solid #ebe3dc;border-radius:18px;background:#fff;box-shadow:0 10px 26px rgba(52,36,23,.04)}
.wizard-panel{padding:24px 27px 13px}
.panel-heading{display:flex;align-items:center;gap:11px;margin-bottom:22px;color:#49392b;font-size:1.02rem;font-weight:800;letter-spacing:-.015em;min-width:0}
.panel-icon{display:grid;place-items:center;flex:0 0 40px;width:40px;height:40px;border:1px solid #f4e3d1;
  border-radius:12px;background:#fff3e7;color:#c27b3a}
.panel-icon svg{width:21px;height:21px}
.field-grid{gap:18px 17px}
.field>label,.field-label{margin-bottom:8px;color:#625548;font-size:.81rem;font-weight:750}
.field input:not([type=file]):not([type=checkbox]),.field select,.field textarea,.category-toggle{
  min-height:48px;padding:12px 13px;border:1px solid #e5d9cd;border-radius:11px;background:#fdfbf9;color:#483c32;font-size:16px}
.field input:not([type=file]):not([type=checkbox]):focus,.field select:focus,.field textarea:focus,.category-toggle:focus-visible{border-color:#d99a67!important;background:#fff!important;box-shadow:0 0 0 3px rgba(233,139,64,.1)}
.field textarea{min-height:122px}
.field-feedback{font-size:.77rem}
.group-divider{margin:24px 0;background:#f1e9e2}
.subheading h3{display:flex;align-items:center;gap:9px;color:#49392b;font-size:.93rem;font-weight:800}
.subheading h3 svg{width:21px;height:21px;flex:0 0 21px;color:#c27b3a}
.guide-btn{min-height:34px;padding:7px 12px;border-radius:10px;background:#fff4e9;border-color:#eed4bb;color:#a46433}
.upload-card{padding:15px;border:1px solid #eee3da;border-radius:13px;background:#fdfbf9}
.upload-title{font-size:.8rem;color:#57483d;margin-bottom:10px}
.upload-box{min-height:120px;background:#fff;border-color:#ddcfc0;border-radius:11px}
.upload-box:hover{background:#fff9f2;border-color:#d49b71}
.file-preview{min-height:64px;border-radius:11px}
.file-thumb{width:49px;height:49px;flex-basis:49px;border-radius:9px}
.file-meta strong{font-size:.77rem}
.file-meta small{font-size:.72rem}
.file-tools button{padding:5px 9px;border-radius:8px;font-size:.71rem}
.info-tip{flex:0 0 22px;width:22px;height:22px}
.review-grid{gap:15px;margin-top:0}
.review-group{background:#fdfbf9;border-color:#eee4dc;border-radius:14px;padding:18px}
.review-group h3{font-size:.92rem;color:#99602e}
.review-item .key{font-size:.72rem}
.review-item .value{font-size:.84rem;color:#483c33}
.review-file{border-color:#ebdfd4;border-radius:11px}
.agreement{padding-top:18px}
.app-actions{padding:18px 27px 23px}
.app-actions button{min-height:44px;border-radius:11px;font-size:.84rem;padding:11px 19px}
.app-actions button.primary{background:#e98b40;border-color:#e98b40;box-shadow:0 5px 14px rgba(202,111,36,.1)}
.app-actions button.primary:hover{background:#cf792f}
.app-status{padding:35px 25px}
.app-status h2{color:#49392b}
.app-status a{border-radius:11px;min-height:43px;align-items:center}
@media(max-width:840px){
 .page>.content.buyer-seller-application-content{position:relative!important;left:0!important;right:0!important;top:auto!important;bottom:auto!important;
   width:100%!important;max-width:100%!important;min-height:100vh!important;height:auto!important;margin-left:0!important;
   padding:80px 16px 34px!important;overflow-x:visible!important;overflow-y:visible!important}
}
@media(max-width:620px){
 .page>.content.buyer-seller-application-content{padding:76px 12px 30px!important}
 .app-top{margin-bottom:15px;gap:8px;align-items:center}
 .app-eyebrow{font-size:.58rem}
 .app-top h1{font-size:1.55rem}
 .back-link{min-height:40px;padding:9px 10px;font-size:.72rem;border-radius:10px;gap:3px}
 .back-link svg{width:15px;height:15px;flex-basis:15px}
 .app-intro{min-height:0;margin-bottom:15px;padding:18px 16px;gap:13px;border-radius:17px;align-items:flex-start;flex-direction:column}
 .app-intro-main{gap:12px;width:100%}
 .app-intro-icon{width:57px;height:57px;flex-basis:57px;border-radius:16px}
 .app-intro-icon svg{width:29px;height:29px}
 .app-intro-kicker{font-size:.57rem}
 .app-intro-copy h2{font-size:1.19rem}
 .app-intro-badge{padding:6px 9px;font-size:.68rem}
 .app-steps{gap:7px;margin-bottom:13px}
 .step-label{min-height:66px;gap:5px;padding:9px 3px;flex-direction:column;justify-content:center;text-align:center;border-radius:12px}
 .step-label .number{width:27px;height:27px;flex-basis:27px;border-radius:9px;font-size:.74rem}
 .step-label .step-copy{font-size:.66rem}
 .step-label.active::before{top:9px;bottom:9px}
 .wizard-panel{padding:17px 16px 9px}
 .panel-heading{font-size:.94rem;margin-bottom:18px;gap:9px}
 .panel-icon{width:35px;height:35px;flex-basis:35px;border-radius:10px}
 .panel-icon svg{width:19px;height:19px}
 .field-grid{gap:15px}
 .subheading{align-items:center;gap:7px}
 .subheading h3{font-size:.85rem;gap:6px}
 .subheading h3 svg{width:18px;height:18px;flex-basis:18px}
 .guide-btn{font-size:.71rem;min-height:33px;padding:6px 9px}
 .upload-card{padding:12px}
 .review-group{padding:14px}
 .app-actions{padding:14px 16px 18px}
 .app-actions button{min-width:108px;min-height:44px;padding:10px 13px}
 .modal-content{font-size:.84rem}
}
@media(max-width:360px){.back-link{padding:9px 7px;font-size:.68rem}.app-top h1{font-size:1.39rem}.step-label .step-copy{font-size:.61rem}}

/* Full file preview: preserve the whole image; PDFs use a large browser-native viewer. */
.document-viewer{padding:15px;background:rgba(28,23,19,.84);-webkit-backdrop-filter:blur(5px);backdrop-filter:blur(5px)}
.document-viewer-panel{position:relative;display:flex;flex-direction:column;min-width:0;min-height:0;width:min(1200px,100%);height:calc(100vh - 30px);height:calc(100dvh - 30px);max-height:100%;border:1px solid #69584c;border-radius:18px;background:#211e1b;box-shadow:0 26px 90px rgba(0,0,0,.4);overflow:hidden}
.document-viewer-close{position:absolute;z-index:4;top:14px;right:14px;display:grid;place-items:center;width:41px;height:41px;padding:0;border:1px solid rgba(255,255,255,.27);border-radius:50%;background:rgba(36,32,29,.87);color:#fff;cursor:pointer;box-shadow:0 5px 19px rgba(0,0,0,.22)}
.document-viewer-close svg{width:21px;height:21px}
.document-viewer-close:hover,.document-viewer-close:focus-visible{background:#4d3a2e;outline:2px solid #f0bc8d;outline-offset:2px}
.document-viewer-stage{display:flex;flex:1 1 auto;min-width:0;min-height:0;align-items:center;justify-content:center;overflow:hidden;padding:55px 14px 10px}
.document-viewer-stage .viewer-media{display:block;max-width:100%;max-height:100%;width:auto;height:auto;margin:auto;object-fit:contain;object-position:center}
.document-viewer-stage .viewer-frame{display:block;flex:1 1 auto;width:100%;height:100%;min-width:0;min-height:0;border:0;border-radius:9px;background:#fff}
.document-viewer-panel.is-pdf .document-viewer-stage{padding:60px 15px 12px}
.document-viewer-footer{display:flex;flex:0 0 auto;align-items:center;justify-content:space-between;gap:14px;min-height:58px;padding:12px 21px;color:#f1e6dc;font-size:.82rem}
.document-viewer-footer #viewerTitle{display:block;flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:750}
.document-viewer-footer #viewerMediaKind{flex:0 0 auto;color:#c2b3a6;font-size:.72rem}
.document-viewer-footer .viewer-open-pdf{display:inline-flex;align-items:center;gap:6px;flex:0 0 auto;margin:0;padding:8px 11px;border:1px solid rgba(255,255,255,.32);border-radius:9px;background:#3e342c;color:#fff;text-decoration:none;font-size:.75rem;font-weight:750}
.document-viewer-footer .viewer-open-pdf[hidden]{display:none}
.document-viewer-footer .viewer-open-pdf svg{width:15px;height:15px}
.document-viewer-footer .viewer-open-pdf:hover{background:#594536}
.field-hint{display:block;margin-top:6px;color:#8b7c70;font-size:.72rem;line-height:1.45}
.agreement{padding:14px 15px;border:1px solid #eae0d6;border-radius:12px;background:#fdfbf8;transition:border-color .16s,background .16s,box-shadow .16s}
.agreement input{width:20px;height:20px;flex-basis:20px;cursor:pointer}
.agreement.is-invalid{border:2px solid #cf5449;background:#fff2f0;box-shadow:0 0 0 3px rgba(207,84,73,.09);color:#8e302a}
.agreement.is-invalid input{outline:2px solid #cf5449;outline-offset:3px}
.agreement.is-valid{border-color:#9bd0ad;background:#f6fcf7}
.agreement-feedback:not(:empty){display:flex;align-items:center;gap:7px;margin:8px 0 0;padding:8px 11px;border-radius:9px;background:#fff2f0;border:1px solid #f0beb9;color:#a43d35;font-weight:750}
.agreement-feedback:not(:empty)::before{content:'!';display:grid;place-items:center;flex:0 0 18px;width:18px;height:18px;border-radius:50%;background:#c95148;color:#fff;font-size:.73rem;font-weight:850}
.field input.is-invalid,.field select.is-invalid,.field textarea.is-invalid{border:2px solid #cf5449!important;background:#fff8f7!important;box-shadow:0 0 0 2px rgba(207,84,73,.08)}
.upload-card.is-invalid{border:2px solid #cf5449;background:#fff7f5}
.category-toggle.is-invalid{border:2px solid #cf5449!important;background:#fff7f5}
.field-feedback:not(:empty){font-weight:700}
@media(max-width:760px){.document-viewer{padding:0}.document-viewer-panel{width:100%;height:100vh;height:100dvh;max-height:100vh;max-height:100dvh;border:0;border-radius:0}.document-viewer-stage{padding:calc(57px + env(safe-area-inset-top)) 9px 8px}.document-viewer-panel.is-pdf .document-viewer-stage{padding:calc(57px + env(safe-area-inset-top)) 8px 8px}.document-viewer-close{top:calc(12px + env(safe-area-inset-top));right:13px}.document-viewer-footer{padding:12px 14px calc(13px + env(safe-area-inset-bottom));min-height:59px;gap:8px}.document-viewer-footer #viewerMediaKind{display:none}}
</style>
</head>
<body>
<div class="page">
<?php include __DIR__ . '/buyer_nav.php'; ?>
<main class="content buyer-seller-application-content">
<?php include __DIR__ . '/buyer_search.php'; ?>
<div class="app-wrap">
<header class="app-top">
  <div class="app-top-copy"><span class="app-eyebrow">YOUR ACCOUNT</span><h1>Seller Application</h1></div>
  <a href="profile.php" class="back-link"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg><span>Back to Profile</span></a>
</header>
<section class="app-intro" aria-label="Seller registration">
  <div class="app-intro-main">
    <span class="app-intro-icon"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10h18l-2-6H5l-2 6Z"/><path d="M5 10v10h14V10M9 20v-6h6v6M3 10c0 2 2 3 4 1.4C9 13 11 12 12 10c1 2 3 3 5 1.4 2 1.6 4 .6 4-1.4"/></svg></span>
    <div class="app-intro-copy"><span class="app-intro-kicker">LOKALCRAFT SELLER</span><h2>Create your shop</h2></div>
  </div>
  <span class="app-intro-badge"><span class="app-intro-dot"></span><?php echo $applicationStatus === 'approved' ? 'Approved' : ($applicationStatus === 'pending' ? 'Under Review' : '3-Step Application'); ?></span>
</section>
<?php if ($success !== ''): ?><div class="app-notice success" role="status"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="app-notice error" role="alert" id="serverNotice"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<?php if ($applicationStatus === 'approved'): ?>
<section class="app-status"><h2>Application Approved</h2><p>Your Seller account is ready.</p><?php if ($sellerAccountApproved): ?><a class="primary" href="seller_application.php?enter_seller=1">Open Seller Dashboard</a><?php else: ?><a href="profile.php">Return to Profile</a><?php endif; ?></section>
<?php elseif ($applicationStatus === 'pending'): ?>
<section class="app-status"><h2>Application Under Review</h2><p>We'll update your status after verification.</p><a href="profile.php">Return to Profile</a></section>
<?php else: ?>
<?php if ($applicationStatus === 'rejected'): ?><div class="app-notice error" role="status"><strong>Application Rejected.</strong> Update your information and resubmit.<?php if ($rejectionReason !== ''): ?><div class="rejection-reason"><?php echo htmlspecialchars($rejectionReason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?></div><?php endif; ?>
<div class="app-steps" role="group" aria-label="Seller application steps">
<button type="button" class="step-label active" data-step-indicator="1" aria-label="Step 1: Business Info"><span class="number">1</span><span class="step-copy">Business Info</span></button>
<button type="button" class="step-label" data-step-indicator="2" aria-label="Step 2: Location" disabled><span class="number">2</span><span class="step-copy">Location</span></button>
<button type="button" class="step-label" data-step-indicator="3" aria-label="Step 3: Review" disabled><span class="number">3</span><span class="step-copy">Review</span></button>
</div>
<form class="app-form" id="sellerApplicationForm" method="POST" enctype="multipart/form-data" novalidate>
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
<section class="wizard-panel" data-step="1">
<h2 class="panel-heading"><span class="panel-icon"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10h18l-2-6H5l-2 6Z"/><path d="M5 10v10h14V10M9 20v-6h6v6M3 10c0 2 2 3 4 1.4C9 13 11 12 12 10c1 2 3 3 5 1.4 2 1.6 4 .6 4-1.4"/></svg></span><span>Business Information</span></h2>
<div class="field-grid">
<div class="field"><label for="businessname">Shop Name *</label><input id="businessname" name="businessname" type="text" maxlength="50" autocomplete="organization" placeholder="Your shop name" value="<?php echo htmlspecialchars($profile['businessname'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required><small class="field-feedback" data-feedback="businessname"></small></div>
<div class="field"><span class="field-label" id="categoryLabel">Craft Categories *</span><div class="category-picker" id="categoryPicker"><input type="hidden" name="craftcategory" id="craftcategory" value="<?php echo htmlspecialchars($profile['craftcategory'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><button class="category-toggle" id="categoryToggle" type="button" aria-expanded="false" aria-controls="categoryOptions" aria-labelledby="categoryLabel categoryToggleText"><span id="categoryToggleText">Choose categories</span><span aria-hidden="true">⌄</span></button><div id="categoryOptions" class="category-options" hidden><?php foreach ($craftCategories as $category): ?><label class="category-choice"><input type="checkbox" class="category-checkbox" value="<?php echo htmlspecialchars($category, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" <?php echo in_array($category, array_map('trim', explode(',', (string)$profile['craftcategory'])), true) ? 'checked' : ''; ?>><span><?php echo htmlspecialchars($category, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span></label><?php endforeach; ?></div></div><small class="field-feedback" data-feedback="craftcategory"></small></div>
<div class="field wide"><label for="businessdescription">Business Description *</label><textarea id="businessdescription" name="businessdescription" minlength="20" maxlength="300" rows="4" placeholder="Describe your shop and handmade products" required><?php echo htmlspecialchars($profile['businessdescription'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea><div class="counter" id="descriptionCounter">0/300</div><small class="field-feedback" data-feedback="businessdescription"></small></div>
</div>
<div class="group-divider"></div>
<div class="subheading"><h3><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8"/></svg><span>Business Documents</span></h3><button class="guide-btn" type="button" data-open-modal="businessGuide">Document Guide ↗</button></div>
<div class="field-grid"><div class="field wide"><label for="business_document_type">Business Document Type *</label><select id="business_document_type" name="business_document_type" required><option value="">Select document</option><?php foreach ($businessDocumentTypes as $docType): ?><option value="<?php echo htmlspecialchars($docType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" <?php echo $profile['business_document_type'] === $docType ? 'selected' : ''; ?>><?php echo htmlspecialchars($docType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></option><?php endforeach; ?></select><small class="field-feedback" data-feedback="business_document_type"></small></div></div>
<div class="doc-row"><div class="upload-card" id="business_permitCard"><div class="upload-title">Business Document *</div><input class="file-input" id="business_permit" name="businesspermit" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp"><label for="business_permit" class="upload-box" data-empty="business_permit"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4m-4 4 4-4 4 4M4 16v4h16v-4"/></svg><strong>Choose document</strong><small>PDF, JPG, PNG, WEBP · Max 5 MB</small></label><div class="file-preview" data-preview="business_permit" data-preview-open="business_permit" role="button" tabindex="0" aria-label="Preview uploaded file" hidden><div class="file-thumb" data-thumb="business_permit"></div><div class="file-meta"><strong data-file-name="business_permit"></strong><small data-file-size="business_permit"></small><div class="file-tools"><button type="button" data-view="business_permit">View</button><button type="button" data-change="business_permit">Change</button><button type="button" data-remove="business_permit">Remove</button></div></div></div><small class="field-feedback" data-feedback="business_permit"></small></div></div>
<div class="group-divider"></div>
<div class="subheading"><h3><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m12 22 7-4V6l-7-4-7 4v12l7 4Z"/><path d="m9 12 2 2 4-4"/></svg><span>Identity Verification</span></h3><button class="guide-btn" type="button" data-open-modal="idGuide">Valid ID Guide ↗</button></div>
<div class="field-grid"><div class="field wide"><label for="valid_id_type">Valid ID Type *</label><select id="valid_id_type" name="validid_type" required><option value="">Select valid ID</option><?php foreach ($validIdTypes as $idType => $idConfig): ?><option value="<?php echo htmlspecialchars($idType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" <?php echo $profile['validid_type'] === $idType ? 'selected' : ''; ?>><?php echo htmlspecialchars($idType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?><?php echo !empty($idConfig['recommended']) ? ' (Recommended)' : ''; ?></option><?php endforeach; ?></select><small class="field-feedback" data-feedback="validid_type"></small></div></div>
<div class="doc-row">
<div class="upload-card" id="valid_idCard"><div class="upload-title" id="validFrontTitle">Valid ID Front *</div><input class="file-input" id="valid_id" name="validid" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"><label for="valid_id" class="upload-box" data-empty="valid_id"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4m-4 4 4-4 4 4M4 16v4h16v-4"/></svg><strong>Choose ID photo</strong><small>JPG, PNG, WEBP · Max 5 MB</small></label><div class="file-preview" data-preview="valid_id" data-preview-open="valid_id" role="button" tabindex="0" aria-label="Preview uploaded file" hidden><div class="file-thumb" data-thumb="valid_id"></div><div class="file-meta"><strong data-file-name="valid_id"></strong><small data-file-size="valid_id"></small><div class="file-tools"><button type="button" data-view="valid_id">View</button><button type="button" data-change="valid_id">Change</button><button type="button" data-remove="valid_id">Remove</button></div></div></div><small class="field-feedback" data-feedback="valid_id"></small></div>
<div class="upload-card" id="valid_id_backCard" hidden><div class="upload-title">Valid ID Back *</div><input class="file-input" id="valid_id_back" name="validid_back" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"><label for="valid_id_back" class="upload-box" data-empty="valid_id_back"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4m-4 4 4-4 4 4M4 16v4h16v-4"/></svg><strong>Choose back photo</strong><small>JPG, PNG, WEBP · Max 5 MB</small></label><div class="file-preview" data-preview="valid_id_back" data-preview-open="valid_id_back" role="button" tabindex="0" aria-label="Preview uploaded file" hidden><div class="file-thumb" data-thumb="valid_id_back"></div><div class="file-meta"><strong data-file-name="valid_id_back"></strong><small data-file-size="valid_id_back"></small><div class="file-tools"><button type="button" data-view="valid_id_back">View</button><button type="button" data-change="valid_id_back">Change</button><button type="button" data-remove="valid_id_back">Remove</button></div></div></div><small class="field-feedback" data-feedback="valid_id_back"></small></div>
</div>
</section>
<section class="wizard-panel" data-step="2" hidden>
<h2 class="panel-heading location-title"><span class="panel-icon"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg></span><span>Business Location</span> <button class="info-tip" id="taguigTip" type="button" aria-label="Taguig seller requirement" aria-expanded="false">i<span class="tip-bubble" id="taguigTipText" hidden>Taguig City sellers only. Applications from outside Taguig are not accepted.</span></button></h2>
<div class="field-grid">
<div class="field wide"><label for="streetaddress">Street Address *</label><input id="streetaddress" name="streetaddress" type="text" maxlength="255" placeholder="House / building, street, subdivision" value="<?php echo htmlspecialchars($profile['streetaddress'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required><small class="field-feedback" data-feedback="streetaddress"></small></div>
<div class="field"><label for="barangay">Barangay *</label><div class="barangay-picker" id="barangayPicker"><input id="barangay" name="barangay" type="text" maxlength="25" autocomplete="off" placeholder="Search Taguig barangay" role="combobox" aria-autocomplete="list" aria-controls="barangayOptions" aria-expanded="false" value="<?php echo htmlspecialchars($profile['barangay'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required><div id="barangayOptions" class="barangay-options" role="listbox" hidden></div></div><small class="field-feedback" data-feedback="barangay"></small></div>
<div class="field"><label for="city">City *</label><input id="city" name="city" type="text" value="Taguig City" readonly required><small class="field-feedback" data-feedback="city"></small></div>
<div class="field"><label for="zipcode">ZIP Code *</label><input id="zipcode" name="zipcode" type="text" inputmode="numeric" maxlength="4" placeholder="Auto-filled; you can edit" pattern="[0-9]{4}" autocomplete="postal-code" value="<?php echo htmlspecialchars($profile['zipcode'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required><small class="field-hint">Suggested from the barangay. You can correct it.</small><small class="field-feedback" data-feedback="zipcode"></small></div>
</div>
</section>
<section class="wizard-panel" data-step="3" hidden>
<h2 class="panel-heading"><span class="panel-icon"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4M16 2v4M3 9h18m-13 6 3 3 5-5"/></svg></span><span>Review &amp; Submit</span></h2>
<div class="review-grid">
<div class="review-group"><h3>Business</h3><div class="review-item"><span class="key">Shop Name</span><span class="value" data-review="businessname"></span></div><div class="review-item"><span class="key">Categories</span><span class="value" data-review="craftcategory"></span></div><div class="review-item"><span class="key">Description</span><span class="value" data-review="businessdescription"></span></div></div>
<div class="review-group"><h3>Location</h3><div class="review-item"><span class="key">Street</span><span class="value" data-review="streetaddress"></span></div><div class="review-item"><span class="key">Barangay</span><span class="value" data-review="barangay"></span></div><div class="review-item"><span class="key">City / ZIP</span><span class="value" data-review="cityzip"></span></div></div>
<div class="review-group wide"><h3>Verification Documents</h3><div class="review-item"><span class="key">Business Document</span><span class="value" data-review="business_document_type"></span></div><div class="review-item"><span class="key">Uploaded File</span><button type="button" class="review-file" data-review-file="business_permit" aria-label="View uploaded business document"><span class="review-file-thumb" data-review-thumb="business_permit" aria-hidden="true"></span><span class="review-file-detail"><strong data-review-name="business_permit">—</strong><small data-review-size="business_permit"></small></span><svg aria-hidden="true" class="review-view-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z"/><circle cx="12" cy="12" r="3"/></svg></button></div><div class="review-item"><span class="key">Valid ID</span><span class="value" data-review="validid_type"></span></div><div class="review-item"><span class="key">ID Front / Passport</span><button type="button" class="review-file" data-review-file="valid_id" aria-label="View uploaded id front / passport"><span class="review-file-thumb" data-review-thumb="valid_id" aria-hidden="true"></span><span class="review-file-detail"><strong data-review-name="valid_id">—</strong><small data-review-size="valid_id"></small></span><svg aria-hidden="true" class="review-view-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z"/><circle cx="12" cy="12" r="3"/></svg></button></div><div class="review-item" id="reviewBackRow"><div class="review-item"><span class="key">ID Back</span><button type="button" class="review-file" data-review-file="valid_id_back" aria-label="View uploaded id back"><span class="review-file-thumb" data-review-thumb="valid_id_back" aria-hidden="true"></span><span class="review-file-detail"><strong data-review-name="valid_id_back">—</strong><small data-review-size="valid_id_back"></small></span><svg aria-hidden="true" class="review-view-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z"/><circle cx="12" cy="12" r="3"/></svg></button></div></div></div>
</div>
<label class="agreement" id="agreementCard"><input id="agree_terms" type="checkbox" name="agree_terms" value="1" aria-describedby="agree_termsFeedback"><span>I agree to the <a href="#" data-open-modal="terms-modal">Terms &amp; Conditions</a>, <a href="#" data-open-modal="privacy-modal">Privacy Policy</a>, and <a href="#" data-open-modal="seller-agreement-modal">Seller Agreement</a>.</span></label><small class="field-feedback agreement-feedback" id="agree_termsFeedback" data-feedback="agree_terms" role="status"></small>
</section>
<div class="app-actions"><button id="wizardBack" type="button" hidden>Back</button><span id="wizardSpacer"></span><button type="button" id="wizardNext" class="primary">Next</button><button id="wizardSubmit" class="primary" type="submit" hidden><?php echo $applicationStatus === 'rejected' ? 'Resubmit Application' : 'Submit Application'; ?></button></div>
</form>
<?php endif; ?>
</div>
</main>
</div>
<div class="overlay-modal" id="businessGuide" role="dialog" aria-modal="true" aria-labelledby="businessGuideTitle" aria-hidden="true"><div class="modal-card"><div class="modal-head"><h2 id="businessGuideTitle">Business Document Guide</h2><button class="modal-close" type="button" data-close-modal aria-label="Close guide">×</button></div><div class="modal-content"><strong>Accepted business documents</strong><ul><?php foreach ($businessDocumentTypes as $docType): ?><li><?php echo htmlspecialchars($docType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></li><?php endforeach; ?></ul>Upload one readable document. PDF or image, maximum 5 MB.</div></div></div>
<div class="overlay-modal" id="idGuide" role="dialog" aria-modal="true" aria-labelledby="idGuideTitle" aria-hidden="true"><div class="modal-card"><div class="modal-head"><h2 id="idGuideTitle">Valid ID Guide</h2><button class="modal-close" type="button" data-close-modal aria-label="Close guide">×</button></div><div class="modal-content"><strong>Accepted IDs</strong><ul><?php foreach ($validIdTypes as $idType => $config): ?><li><?php echo htmlspecialchars($idType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?><?php echo !empty($config['requires_back']) ? ' — Front and back' : ' — Information page only'; ?><?php echo !empty($config['recommended']) ? ' (Recommended)' : ''; ?></li><?php endforeach; ?></ul>Upload clear images (JPG, PNG, WEBP), maximum 5 MB each. PDFs are not accepted for IDs.</div></div></div>
<div class="overlay-modal" id="terms-modal" role="dialog" aria-modal="true" aria-labelledby="termsTitle" aria-hidden="true"><div class="modal-card"><div class="modal-head"><h2 id="termsTitle">Terms &amp; Conditions</h2><button class="modal-close" type="button" data-close-modal aria-label="Close">×</button></div><div class="modal-content"><?php include __DIR__ . '/../terms_privacy.php'; ?></div></div></div>
<div class="overlay-modal" id="privacy-modal" role="dialog" aria-modal="true" aria-labelledby="privacyTitle" aria-hidden="true"><div class="modal-card"><div class="modal-head"><h2 id="privacyTitle">Privacy Policy</h2><button class="modal-close" type="button" data-close-modal aria-label="Close">×</button></div><div class="modal-content"><?php include __DIR__ . '/../terms_privacy.php'; ?></div></div></div>
<div class="overlay-modal" id="seller-agreement-modal" role="dialog" aria-modal="true" aria-labelledby="agreementTitle" aria-hidden="true"><div class="modal-card"><div class="modal-head"><h2 id="agreementTitle">Seller Agreement</h2><button class="modal-close" type="button" data-close-modal aria-label="Close">×</button></div><div class="modal-content"><?php include __DIR__ . '/../seller_agreement.php'; ?></div></div></div>
<div class="overlay-modal document-viewer" id="fileViewer" role="dialog" aria-modal="true" aria-labelledby="viewerTitle" aria-hidden="true">
  <div class="document-viewer-panel">
    <button class="document-viewer-close" type="button" data-close-modal aria-label="Close full preview"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    <div class="document-viewer-stage" id="viewerBody"></div>
    <div class="document-viewer-footer"><span id="viewerTitle">File Preview</span><a id="viewerOpenPdf" class="viewer-open-pdf" href="#" target="_blank" rel="noopener noreferrer" hidden>Open full PDF <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M14 5h5v5m0-5-9 9"/><path d="M19 13v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h5"/></svg></a><span id="viewerMediaKind">Full image</span></div>
  </div>
</div>
<script>
(function(){
'use strict';
const FORM=document.getElementById('sellerApplicationForm');
if(!FORM) return;
const VALID_ID=<?php echo json_encode(array_map(static fn(array $config): array => ['requires_back'=>!empty($config['requires_back'])],$validIdTypes), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const BARANGAYS=<?php echo json_encode($taguigBarangays, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const CATEGORIES=<?php echo json_encode($craftCategories, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const DOC_TYPES=<?php echo json_encode($businessDocumentTypes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const SERVER_FIELD=<?php echo json_encode($errorField, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const SERVER_ERROR=<?php echo json_encode($error, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const byId=id=>document.getElementById(id);
const textLen=value=>[...value].length;
let currentStep=1, furthestStep=1, submitting=false;
// History-based navigation: the last opened preview/guide closes before the
// wizard goes back a step, matching the Buyer For You popup behavior.
const sellerAppNavId='lc-seller-application-'+Date.now()+'-'+Math.random().toString(36).slice(2);
const sellerAppFrames=[];
let sellerAppDepth=0, sellerAppBaseStep=1, sellerAppNavigating=false;
let sellerAppHistoryReady=false;
function sellerAppHistoryState(depth){
 const state=window.history.state;
 return {...(state&&typeof state==='object'?state:{}),lcSellerApplication:{id:sellerAppNavId,depth}};
}
try{
 window.history.replaceState(sellerAppHistoryState(0),'',window.location.href);
 sellerAppHistoryReady=true;
}catch(_){ /* In restricted browsers, direct step/modal navigation still works. */ }
function sellerAppPush(frame){
 if(sellerAppNavigating)return;
 if(sellerAppHistoryReady){
  try{window.history.pushState(sellerAppHistoryState(sellerAppDepth+1),'',window.location.href)}
  catch(_){sellerAppHistoryReady=false}
 }
 sellerAppFrames.splice(sellerAppDepth);
 sellerAppFrames.push(frame);
 sellerAppDepth=sellerAppFrames.length;
 sellerAppRender();
}
function sellerAppBackTo(depth){
 if(sellerAppNavigating)return;
 if(!sellerAppHistoryReady){
  sellerAppDepth=Math.max(0,Math.min(sellerAppFrames.length,depth));
  sellerAppRender();
  return;
 }
 if(depth<0||depth>=sellerAppDepth)return;
 sellerAppNavigating=true;
 window.history.go(depth-sellerAppDepth);
}
function sellerAppGoToStep(step){
 if(step===currentStep||step<1||step>3)return;
 if(step<currentStep){
  for(let i=sellerAppDepth-1;i>=0;i--){
   if(sellerAppFrames[i].type==='step'&&sellerAppFrames[i].step===step){
    sellerAppBackTo(i+1);
    return;
   }
  }
  if(step===sellerAppBaseStep&&sellerAppDepth>0){sellerAppBackTo(0);return;}
 }
 sellerAppPush({type:'step',step});
}
window.addEventListener('popstate',event=>{
 sellerAppNavigating=false;
 const closing=sellerAppFrames[sellerAppDepth-1];
 const nav=event.state?.lcSellerApplication;
 const requested=nav?.id===sellerAppNavId?Number(nav.depth):0;
 sellerAppDepth=Math.max(0,Math.min(sellerAppFrames.length,Number.isInteger(requested)?requested:0));
 sellerAppRender(closing?.type==='modal'?closing.opener:null);
});
const fileUrls=new Map();
const changedFields=new Set();
function input(key){return byId(key==='validid_type'?'valid_id_type':key)?.value?.trim()||'';}
function errorOf(key){return document.querySelector(`[data-feedback="${key}"]`)}
function mark(key,message,showOk=false){
 const field=byId(key==='validid_type'?'valid_id_type':key), node=errorOf(key);
 if(node) node.textContent=message||'';
 const target=key==='agree_terms'?byId('agreementCard'):key==='craftcategory'?byId('categoryToggle'):field?.type==='file'?byId(key+'Card'):field;
 target?.classList.toggle('is-invalid',Boolean(message));
 target?.classList.toggle('is-valid',!message&&showOk);
 if(field) field.setAttribute('aria-invalid',message?'true':'false');
 return !message;
}
function clearAllErrors(){['businessname','craftcategory','businessdescription','business_document_type','validid_type','business_permit','valid_id','valid_id_back','streetaddress','barangay','city','zipcode','agree_terms'].forEach(k=>mark(k,''));}
function currentCategories(){return [...document.querySelectorAll('.category-checkbox:checked')].map(el=>el.value)}
const categoryPicker=byId('categoryPicker'),categoryToggle=byId('categoryToggle'),categoryOptions=byId('categoryOptions');
function closeCategories(){if(categoryOptions){categoryOptions.hidden=true;categoryToggle?.setAttribute('aria-expanded','false')}}
function renderCategories(){
 const names=currentCategories(),stringValue=names.join(', ');
 byId('craftcategory').value=stringValue;
 byId('categoryToggleText').textContent=names.length?names.join(', '):'Choose categories';
 if(changedFields.has('craftcategory')) validateField('craftcategory');
}
categoryToggle?.addEventListener('click',()=>{categoryOptions.hidden=!categoryOptions.hidden;categoryToggle.setAttribute('aria-expanded',categoryOptions.hidden?'false':'true');closeBarangays()});
document.querySelectorAll('.category-checkbox').forEach(check=>check.addEventListener('change',()=>{
  changedFields.add('craftcategory');
  if(currentCategories().join(', ').length>100){check.checked=false;mark('craftcategory','Selected categories exceed 100 characters.');return}
  renderCategories()
}));
renderCategories();
const barangay=byId('barangay'),barangayOptions=byId('barangayOptions');
const barangayList=Object.keys(BARANGAYS);
let highlighted=-1;
function closeBarangays(){barangayOptions.hidden=true;barangay?.setAttribute('aria-expanded','false');highlighted=-1}
function chooseBarangay(name){const wasSame=barangay.value.trim().toLowerCase()===name.toLowerCase();barangay.value=name;if(!wasSame||!byId('zipcode').value.trim())byId('zipcode').value=BARANGAYS[name];byId('city').value='Taguig City';closeBarangays();changedFields.add('barangay');validateField('barangay');validateField('zipcode')}
function showBarangaySuggestions(){
 const search=barangay.value.trim().toLowerCase(),matching=barangayList.filter(name=>name.toLowerCase().includes(search)).slice(0,38);
 barangayOptions.replaceChildren();highlighted=-1;
 matching.forEach(name=>{const button=document.createElement('button');button.type='button';button.className='barangay-option';button.setAttribute('role','option');button.textContent=name;button.addEventListener('mousedown',e=>e.preventDefault());button.addEventListener('click',()=>chooseBarangay(name));barangayOptions.appendChild(button)});
 barangayOptions.hidden=!matching.length;barangay.setAttribute('aria-expanded',matching.length?'true':'false');
}
barangay?.addEventListener('focus',showBarangaySuggestions);
barangay?.addEventListener('input',()=>{
 const typed=barangay.value.trim();const matched=barangayList.find(name=>name.toLowerCase()===typed.toLowerCase());
 byId('zipcode').value=matched?BARANGAYS[matched]:'';
 showBarangaySuggestions();changedFields.add('barangay');validateField('barangay');validateField('zipcode');
});
barangay?.addEventListener('keydown',e=>{
 const options=[...barangayOptions.querySelectorAll('button')];
 if(e.key==='Escape'){closeBarangays();return}
 if(!options.length || barangayOptions.hidden)return;
 if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();highlighted=(highlighted+(e.key==='ArrowDown'?1:-1)+options.length)%options.length;options.forEach((b,i)=>b.classList.toggle('highlight',i===highlighted));options[highlighted].scrollIntoView({block:'nearest'})}
 if(e.key==='Enter'&&highlighted>=0){e.preventDefault();chooseBarangay(options[highlighted].textContent)}
});
barangay?.addEventListener('blur',()=>{setTimeout(()=>{closeBarangays();const canonical=barangayList.find(k=>k.toLowerCase()===barangay.value.trim().toLowerCase());if(canonical)chooseBarangay(canonical);else{byId('zipcode').value='';if(changedFields.has('barangay'))validateField('barangay')}},130)});
document.addEventListener('click',e=>{if(!categoryPicker?.contains(e.target))closeCategories();if(!byId('barangayPicker')?.contains(e.target))closeBarangays()});
function updateIdRequirement(){
 const idType=input('valid_id_type'),needsBack=Boolean(VALID_ID[idType]?.requires_back),backCard=byId('valid_id_backCard');
 backCard.hidden=!needsBack;byId('validFrontTitle').textContent=idType==='Passport'?'Passport Information Page *':'Valid ID Front *';
 if(!needsBack && byId('valid_id_back').files.length) clearFile('valid_id_back');
 return needsBack;
}
byId('valid_id_type')?.addEventListener('change',()=>{updateIdRequirement();changedFields.add('validid_type');validateField('validid_type')});
updateIdRequirement();
if(barangayList.some(k=>k.toLowerCase()===input('barangay').toLowerCase()))chooseBarangay(barangayList.find(k=>k.toLowerCase()===input('barangay').toLowerCase()));else if(!input('zipcode'))byId('zipcode').value='';
function formatSize(bytes){return bytes>=1048576?(bytes/1048576).toFixed(1)+' MB':Math.max(1,Math.round(bytes/1024))+' KB'}
function isPdf(file){return file?.type==='application/pdf'||/\.pdf$/i.test(file?.name||'')}
function selectedFile(id){return byId(id)?.files?.[0]||null}
function releaseUrl(id){const prev=fileUrls.get(id);if(prev){URL.revokeObjectURL(prev);fileUrls.delete(id)}}
const PDF_THUMBNAIL_SVG='<svg viewBox="0 0 32 36" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M7 2h13l7 7v24H7a3 3 0 0 1-3-3V5a3 3 0 0 1 3-3Z" stroke="currentColor" stroke-width="1.6"/><path d="M20 2v7h7" stroke="currentColor" stroke-width="1.5"/><text x="16" y="24" text-anchor="middle" fill="currentColor" font-size="8" font-weight="800">PDF</text></svg>';
function fillFileThumbnail(element,file,url){
 if(!element)return;
 element.replaceChildren();element.classList.toggle('pdf',Boolean(file&&isPdf(file)));
 if(!file)return;
 if(isPdf(file)){element.innerHTML=PDF_THUMBNAIL_SVG;return}
 const img=document.createElement('img');img.alt='';img.src=url;element.appendChild(img);
}
function drawFile(id){
 const file=selectedFile(id),empty=document.querySelector(`[data-empty="${id}"]`),preview=document.querySelector(`[data-preview="${id}"]`),thumb=document.querySelector(`[data-thumb="${id}"]`);
 releaseUrl(id);if(!empty||!preview)return;
 empty.hidden=Boolean(file);preview.hidden=!file;
 if(!file){fillFileThumbnail(thumb,null,'');preview.removeAttribute('aria-label');return}
 const url=URL.createObjectURL(file);fileUrls.set(id,url);
 fillFileThumbnail(thumb,file,url);
 preview.setAttribute('aria-label','View uploaded '+file.name);
 document.querySelector(`[data-file-name="${id}"]`).textContent=file.name;
 document.querySelector(`[data-file-size="${id}"]`).textContent=formatSize(file.size);
}
function clearFile(id){byId(id).value='';drawFile(id);mark(id,'')}
['valid_id','valid_id_back','business_permit'].forEach(id=>{
 byId(id)?.addEventListener('change',()=>{
  drawFile(id);changedFields.add(id);validateField(id)
 });
});
document.querySelectorAll('[data-change]').forEach(b=>b.addEventListener('click',()=>byId(b.dataset.change)?.click()));
document.querySelectorAll('[data-remove]').forEach(b=>b.addEventListener('click',()=>clearFile(b.dataset.remove)));
const MODALS=[...document.querySelectorAll('.overlay-modal')];
function syncModals(){document.body.classList.toggle('app-modal-open',MODALS.some(m=>m.classList.contains('open')))}
function sellerAppRender(restoreFocus=null){
 const previousModal=MODALS.find(modal=>modal.classList.contains('open'));
 const activeFrames=sellerAppFrames.slice(0,sellerAppDepth);
 const top=activeFrames[activeFrames.length-1];
 const activeModalId=top?.type==='modal'?top.id:'';
 let targetStep=sellerAppBaseStep;
 for(let i=activeFrames.length-1;i>=0;i--){
  if(activeFrames[i].type==='step'){targetStep=activeFrames[i].step;break;}
 }
 if(targetStep!==currentStep)showStep(targetStep);
 MODALS.forEach(modal=>{
  const open=modal.id===activeModalId;
  if(!open&&modal.classList.contains('open')&&modal.id==='fileViewer'){
   byId('viewerBody').replaceChildren();
   byId('viewerOpenPdf').hidden=true;
   byId('viewerOpenPdf').removeAttribute('href');
   modal.querySelector('.document-viewer-panel')?.classList.remove('is-pdf');
  }
  modal.classList.toggle('open',open);
  modal.setAttribute('aria-hidden',open?'false':'true');
 });
 syncModals();
 if(activeModalId==='fileViewer'&&top?.fileId&&(!previousModal||previousModal.id!=='fileViewer')){
  if(!populateFileViewer(top.fileId))byId('viewerBody').textContent='Preview unavailable. Choose a file again.';
 }
 if(previousModal?.id!==activeModalId){
  if(activeModalId){byId(activeModalId)?.querySelector('[data-close-modal]')?.focus({preventScroll:true})}
  else if(previousModal&&restoreFocus?.isConnected){
   restoreFocus.focus({preventScroll:true});
  }
 }
}
function closeModal(modal){
 if(!modal||!modal.classList.contains('open'))return;
 const top=sellerAppFrames[sellerAppDepth-1];
 if(top?.type==='modal'&&top.id===modal.id){sellerAppBackTo(sellerAppDepth-1)}
}
function openModal(id,fileId=''){
 const modal=byId(id);
 if(!modal||!MODALS.includes(modal)||modal.classList.contains('open'))return;
 sellerAppPush({type:'modal',id,fileId,opener:document.activeElement});
}
document.querySelectorAll('[data-open-modal]').forEach(button=>button.addEventListener('click',e=>{e.preventDefault();closeCategories();closeBarangays();openModal(button.dataset.openModal)}));
document.querySelectorAll('[data-close-modal]').forEach(button=>button.addEventListener('click',()=>closeModal(button.closest('.overlay-modal'))));
MODALS.forEach(modal=>modal.addEventListener('click',e=>{if(e.target===modal)closeModal(modal)}));
document.addEventListener('keydown',e=>{if(e.key==='Escape'){const m=MODALS.find(modal=>modal.classList.contains('open'));if(m){e.preventDefault();closeModal(m)}else{closeCategories();closeBarangays()}}});
function isTouchMobile(){
 return /iPad|iPhone|iPod/i.test(navigator.userAgent)
  || (navigator.platform==='MacIntel'&&navigator.maxTouchPoints>1)
  || (window.matchMedia('(max-width: 840px) and (pointer: coarse)').matches);
}
function populateFileViewer(id){
 const file=selectedFile(id),url=fileUrls.get(id);
 if(!file||!url)return false;
 const pdf=isPdf(file);
 byId('viewerTitle').textContent=file.name||'File Preview';
 byId('viewerMediaKind').textContent=pdf?'PDF document':'Full image';
 byId('fileViewer').querySelector('.document-viewer-panel').classList.toggle('is-pdf',pdf);
 const body=byId('viewerBody');body.replaceChildren();
 const browserLink=byId('viewerOpenPdf');browserLink.hidden=!pdf;
 if(pdf){
  browserLink.href=url;
  const iframe=document.createElement('iframe');iframe.className='viewer-frame';
  iframe.title='Full PDF preview';iframe.setAttribute('referrerpolicy','no-referrer');
  iframe.src=url;body.appendChild(iframe);
 }else{
  browserLink.removeAttribute('href');
  const img=document.createElement('img');img.className='viewer-media';
  img.alt='Full image of '+file.name;img.src=url;body.appendChild(img);
 }
 return true;
}
function openSelectedFile(id){
 const file=selectedFile(id),url=fileUrls.get(id);
 if(!file||!url)return;
 // iPhone/iPad Safari handles multi-page PDFs more reliably in its native viewer.
 if(isPdf(file)&&isTouchMobile()){
  const link=document.createElement('a');link.href=url;link.target='_blank';link.rel='noopener noreferrer';
  document.body.appendChild(link);link.click();link.remove();
  return;
 }
 openModal('fileViewer',id);
}
document.querySelectorAll('[data-preview-open],[data-review-file]').forEach(item=>{
 const fileKey=item.dataset.previewOpen||item.dataset.reviewFile;
 item.addEventListener('click',event=>{
  if(event.target.closest('[data-change],[data-remove]'))return;
  openSelectedFile(fileKey);
 });
 if(item.matches('[data-preview-open]'))item.addEventListener('keydown',event=>{
  if(event.target!==item)return;
  if(event.key==='Enter'||event.key===' '){event.preventDefault();openSelectedFile(fileKey)}
 });
});
const tooltip=byId('taguigTip');tooltip?.addEventListener('click',()=>{const open=tooltip.getAttribute('aria-expanded')!=='true';tooltip.setAttribute('aria-expanded',open?'true':'false');tooltip.classList.toggle('tip-open',open);byId('taguigTipText').hidden=!open});
function fileProblem(id,allowPdf){
 const file=selectedFile(id);if(!file)return 'Upload a file.';
 if(file.size<1||file.size>5*1048576)return 'File must be 5 MB or smaller.';
 const ext=file.name.split('.').pop().toLowerCase();
 const allowed=allowPdf?['pdf','jpg','jpeg','png','webp']:['jpg','jpeg','png','webp'];
 if(!allowed.includes(ext))return allowPdf?'Choose PDF, JPG, PNG or WEBP.':'Choose JPG, PNG or WEBP.';
 if(file.type && !(allowPdf?['application/pdf','image/jpeg','image/png','image/webp']:['image/jpeg','image/png','image/webp']).includes(file.type))return 'Unsupported file type.';
 return '';
}
function fieldProblem(id){
 const v=input(id);
 switch(id){
 case 'businessname':return !v||textLen(v)>50?'Shop name must be 1–50 characters.':/[\x00-\x1f\x7f]/.test(v)?'Invalid characters.':'';
 case 'craftcategory':{
  const categories=currentCategories(),joined=categories.join(', ');
  return !categories.length?'Choose at least one category.':textLen(joined)>100?'Categories exceed 100 characters.':categories.some(x=>!CATEGORIES.includes(x))?'Invalid category.':'';
 }
 case 'businessdescription':return textLen(v)<20||textLen(v)>300?'Use 20–300 characters.':'';
 case 'validid_type':return !Object.prototype.hasOwnProperty.call(VALID_ID,v)?'Choose an accepted ID.':'';
 case 'business_document_type':return !DOC_TYPES.includes(v)?'Choose an accepted document.':'';
 case 'valid_id':return fileProblem(id,false);
 case 'valid_id_back':return updateIdRequirement()?fileProblem(id,false):'';
 case 'business_permit':return fileProblem(id,true);
 case 'streetaddress':return !v||textLen(v)>255?'Street address must be 1–255 characters.':/[\x00-\x1f\x7f]/.test(v)?'Invalid characters.':'';
 case 'barangay':return !barangayList.some(k=>k.toLowerCase()===v.toLowerCase())?'Choose a Taguig barangay.':'';
 case 'city':return v!=='Taguig City'?'Taguig City only.':'';
 case 'zipcode':return !/^[0-9]{4}$/.test(v)?'Enter a 4-digit ZIP code.':'';
 case 'agree_terms':return byId('agree_terms').checked?'':'Check this box to agree before submitting.';
 default:return '';
 }
}
function validateField(id){const problem=fieldProblem(id);mark(id,problem,!problem&&changedFields.has(id));return !problem}
const stepFields={1:['businessname','craftcategory','businessdescription','business_document_type','business_permit','validid_type','valid_id','valid_id_back'],2:['streetaddress','barangay','city','zipcode'],3:['agree_terms']};
function validateStep(step){
 let first=null;stepFields[step].forEach(id=>{if(id==='valid_id_back'&&!updateIdRequirement())return;if(!validateField(id)&&!first)first=id});
 if(first){const focusEl=first==='craftcategory'?categoryToggle:byId(first)?.type==='file'?document.querySelector(`[data-empty="${first}"]`):byId(first);focusEl?.focus?.({preventScroll:true});focusEl?.scrollIntoView({behavior:'smooth',block:'center'});return false}
 return true;
}
function updateReview(){
 const values={businessname:input('businessname'),craftcategory:input('craftcategory'),businessdescription:input('businessdescription'),streetaddress:input('streetaddress'),barangay:input('barangay'),cityzip:`${input('city')} · ${input('zipcode')}`,validid_type:input('valid_id_type'),business_document_type:input('business_document_type')};
 document.querySelectorAll('[data-review]').forEach(element=>element.textContent=values[element.dataset.review]||'—');
 ['business_permit','valid_id','valid_id_back'].forEach(id=>{
  const file=selectedFile(id),url=fileUrls.get(id),row=document.querySelector(`[data-review-file="${id}"]`);
  if(!row)return;
  row.disabled=!file;
  fillFileThumbnail(document.querySelector(`[data-review-thumb="${id}"]`),file,url||'');
  document.querySelector(`[data-review-name="${id}"]`).textContent=file?.name||'No file selected';
  document.querySelector(`[data-review-size="${id}"]`).textContent=file?formatSize(file.size):'';
 });
 byId('reviewBackRow').hidden=!updateIdRequirement();
}
function showStep(step){
 currentStep=step;
 furthestStep=Math.max(furthestStep,step);
 document.querySelectorAll('[data-step]').forEach(p=>p.hidden=Number(p.dataset.step)!==step);
 document.querySelectorAll('[data-step-indicator]').forEach(el=>{
  const n=Number(el.dataset.stepIndicator);
  el.disabled=n>furthestStep;
  el.classList.toggle('active',n===step);
  el.classList.toggle('done',n!==step&&n<=furthestStep);
  if(n===step)el.setAttribute('aria-current','step');else el.removeAttribute('aria-current');
 });
 byId('wizardBack').hidden=step===1;byId('wizardNext').hidden=step===3;byId('wizardSubmit').hidden=step!==3;byId('wizardSpacer').hidden=step!==1;
 if(step===3)updateReview();closeCategories();closeBarangays();
 // Return to the step heading in both the fixed desktop content scroller
 // and the normal mobile page, without moving the page when closing a modal.
 const scroller=document.querySelector('.buyer-seller-application-content');
 const steps=document.querySelector('.app-steps');
 if(scroller&&steps&&scroller.scrollHeight>scroller.clientHeight){
  const offset=steps.getBoundingClientRect().top-scroller.getBoundingClientRect().top+scroller.scrollTop-16;
  if(Math.abs(scroller.scrollTop-offset)>40)scroller.scrollTo({top:Math.max(0,offset),behavior:'smooth'});
 }else if(steps){
  const y=steps.getBoundingClientRect().top;
  if(y<64||y>window.innerHeight-100)window.scrollBy({top:y-96,behavior:'smooth'});
 }
}
function moveToVisitedStep(target){
 if(target===currentStep||target>furthestStep||target<1||target>3)return;
 if(target<currentStep){sellerAppGoToStep(target);return}
 // Returning to Review cannot bypass newly-invalid edits made on an earlier step.
 for(let step=currentStep;step<target;step++){
  if(!validateStep(step)){
   if(step!==currentStep){sellerAppGoToStep(step);validateStep(step)}
   return;
  }
 }
 sellerAppGoToStep(target);
}
document.querySelectorAll('[data-step-indicator]').forEach(button=>button.addEventListener('click',()=>moveToVisitedStep(Number(button.dataset.stepIndicator))));
byId('wizardNext').addEventListener('click',()=>{if(validateStep(currentStep))sellerAppGoToStep(Math.min(3,currentStep+1))});
byId('wizardBack').addEventListener('click',()=>sellerAppGoToStep(Math.max(1,currentStep-1)));
['businessname','businessdescription','streetaddress'].forEach(id=>{
 const field=byId(id);
 field?.addEventListener('input',()=>{changedFields.add(id);if(id==='businessdescription')updateCounter();if(field.value.trim()||changedFields.has(id))validateField(id)});
 field?.addEventListener('blur',()=>{changedFields.add(id);validateField(id)});
});
['business_document_type','valid_id_type'].forEach(id=>byId(id)?.addEventListener('change',()=>{changedFields.add(id);validateField(id)}));
byId('agree_terms')?.addEventListener('change',()=>{changedFields.add('agree_terms');validateField('agree_terms')});
byId('zipcode')?.addEventListener('input',()=>{changedFields.add('zipcode');validateField('zipcode')});
byId('zipcode')?.addEventListener('blur',()=>{changedFields.add('zipcode');validateField('zipcode')});
function updateCounter(){const n=textLen(input('businessdescription'));byId('descriptionCounter').textContent=`${n}/300`;byId('descriptionCounter').classList.toggle('ok',n>=20&&n<=300)}
updateCounter();
FORM.addEventListener('submit',event=>{
 if(submitting){event.preventDefault();return}
 if(!validateStep(1)){event.preventDefault();sellerAppGoToStep(1);return}
 if(!validateStep(2)){event.preventDefault();sellerAppGoToStep(2);return}
 if(!validateStep(3)){event.preventDefault();sellerAppGoToStep(3);return}
 submitting=true;byId('wizardSubmit').disabled=true;byId('wizardSubmit').textContent='Submitting...';
});
if(SERVER_FIELD&&SERVER_ERROR){mark(SERVER_FIELD,SERVER_ERROR);sellerAppBaseStep=stepFields[2].includes(SERVER_FIELD)?2:stepFields[3].includes(SERVER_FIELD)?3:1;}
showStep(sellerAppBaseStep);
// File URLs are revoked on replacement/removal; the browser frees the others on document unload.
// Do not revoke on pagehide: iOS Safari fires that when opening a PDF in another tab.
})();
</script>
</body>
</html>
