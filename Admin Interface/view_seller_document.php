<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/private_storage.php';

$adminId = (int)($_SESSION['user_id'] ?? 0);

if ($adminId <= 0 || empty($_SESSION['is_admin'])) {
    http_response_code(403);
    exit('Access denied.');
}

$adminCheck = $conn->prepare("
    SELECT id
    FROM users
    WHERE id = ?
      AND AdminOrNot = 1
      AND account_status = 'active'
    LIMIT 1
");

if (!$adminCheck) {
    http_response_code(500);
    exit('Unable to verify administrator access.');
}

$adminCheck->bind_param('i', $adminId);
$adminCheck->execute();
$adminExists = $adminCheck->get_result()->fetch_assoc();
$adminCheck->close();

if (!$adminExists) {
    http_response_code(403);
    exit('Access denied.');
}

$applicationId = filter_input(INPUT_GET, 'application_id', FILTER_VALIDATE_INT);
$type = trim((string)($_GET['type'] ?? ''));

$allowedTypes = [
    'validid' => 'bi.validid',
    'validid_back' => 'bi.validid_back',
    'businesspermit' => 'bi.businesspermit',
    'permit' => 'bi.businesspermit',
];

if (!$applicationId || !isset($allowedTypes[$type])) {
    http_response_code(400);
    exit('Invalid document request.');
}

$column = $allowedTypes[$type];

$sql = "
    SELECT {$column} AS stored_file
    FROM seller_applications sa
    INNER JOIN businessinfo bi ON bi.user_id = sa.user_id
    WHERE sa.application_id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    exit('Unable to prepare document request.');
}

$stmt->bind_param('i', $applicationId);
$stmt->execute();
$record = $stmt->get_result()->fetch_assoc();
$stmt->close();

$storedValue = trim((string)($record['stored_file'] ?? ''));

if ($storedValue === '') {
    http_response_code(404);
    exit('Document not found.');
}

$storedFile = basename(str_replace('\\', '/', $storedValue));

if ($storedFile === '' || $storedFile === '.' || $storedFile === '..') {
    http_response_code(400);
    exit('Invalid stored document.');
}

$absolutePath = false;

/* Current private seller-document storage. */
$privateRoot = realpath(LOKALCRAFT_SELLER_DOCUMENT_ROOT);

if ($privateRoot !== false) {
    $candidate = realpath($privateRoot . DIRECTORY_SEPARATOR . $storedFile);

    if (
        $candidate !== false
        && is_file($candidate)
        && strpos($candidate, $privateRoot . DIRECTORY_SEPARATOR) === 0
    ) {
        $absolutePath = $candidate;
    }
}

/* Compatibility with older Seller Application uploads. */
if ($absolutePath === false) {
    $legacyRoot = realpath(__DIR__ . '/../uploads/seller_documents');

    if ($legacyRoot !== false) {
        $candidate = realpath($legacyRoot . DIRECTORY_SEPARATOR . $storedFile);

        if (
            $candidate !== false
            && is_file($candidate)
            && strpos($candidate, $legacyRoot . DIRECTORY_SEPARATOR) === 0
        ) {
            $absolutePath = $candidate;
        }
    }
}

if ($absolutePath === false) {
    http_response_code(404);
    exit('Document not found on the server.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($absolutePath);

$allowedMimes = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/webp',
];

if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(415);
    exit('Unsupported document format.');
}

$fileSize = filesize($absolutePath);

if ($fileSize === false) {
    http_response_code(500);
    exit('Unable to read document.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . $fileSize);
header(
    'Content-Disposition: inline; filename="' .
    str_replace(['"', "\r", "\n"], '', $storedFile) .
    '"'
);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

readfile($absolutePath);
exit;
