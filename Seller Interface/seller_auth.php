<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
$sellerId = (int)(
    $_SESSION['seller_id']
    ?? $_SESSION['seller_user_id']
    ?? $_SESSION['user_id']
    ?? $_SESSION['id']
    ?? 0
);
if ($sellerId <= 0) {
    header('Location: ../login.php?seller_session=expired', true, 303);
    exit;
}
$stmt = $conn->prepare('
    SELECT
        u.firstname,
        u.middlename,
        u.lastname,
        u.email,
        u.SellerOrNot,
        u.AdminOrNot,
        u.account_status,
        (
            SELECT b.businessname
            FROM businessinfo b
            WHERE b.user_id = u.id
            ORDER BY b.business_id DESC
            LIMIT 1
        ) AS businessname,
        (
            SELECT LOWER(sa.status)
            FROM seller_applications sa
            WHERE sa.user_id = u.id
            ORDER BY sa.submitted_at DESC, sa.application_id DESC
            LIMIT 1
        ) AS application_status
    FROM users u
    WHERE u.id = ?
    LIMIT 1
');
if (!$stmt) {
    error_log('LokalCraft seller auth: unable to prepare seller access check.');
    header('Location: ../login.php');
    exit;
}
$stmt->bind_param('i', $sellerId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (
    !$row
    || (int)($row['SellerOrNot'] ?? 0) !== 1
    || (int)($row['AdminOrNot'] ?? 0) !== 0
    || strtolower(trim((string)($row['account_status'] ?? ''))) !== 'active'
    || strtolower(trim((string)($row['application_status'] ?? ''))) !== 'approved'
) {
    session_unset();
    session_destroy();
    header('Location: ../login.php?seller_session=invalid', true, 303);
    exit;
}
$userName = trim(
    (string)($row['firstname'] ?? '')
    . ' '
    . (string)($row['middlename'] ?? '')
    . ' '
    . (string)($row['lastname'] ?? '')
);
$userName = preg_replace('/\s+/', ' ', $userName) ?: 'Seller';
$businessName = trim((string)($row['businessname'] ?? ''));
if ($businessName === '') {
    $businessName = 'Seller Portal';
}
$_SESSION['user_id'] = $sellerId;
$_SESSION['user_name'] = $userName;
$_SESSION['user_email'] = trim((string)($row['email'] ?? ''));
$_SESSION['user_role'] = 'seller';
$_SESSION['is_seller'] = true;
$_SESSION['seller_id'] = $sellerId;
$_SESSION['seller_user_id'] = $sellerId;
$_SESSION['seller_name'] = $userName;
$_SESSION['seller_business_name'] = $businessName;
$_SESSION['seller_application_status'] = 'approved';
unset($_SESSION['seller_application_rejection_reason']);
