<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/seller_notification_service.php';
$adminId = (int)($_SESSION['user_id'] ?? 0);
if ($adminId <= 0 || empty($_SESSION['is_admin'])) {
    header('Location: ../login.php');
    exit;
}
$adminCheck = $conn->prepare("
    SELECT firstname, lastname
    FROM users
    WHERE id = ?
      AND AdminOrNot = 1
      AND account_status = 'active'
    LIMIT 1
");
$adminCheck->bind_param('i', $adminId);
$adminCheck->execute();
$adminRow = $adminCheck->get_result()->fetch_assoc();
$adminCheck->close();
if (!$adminRow) {
    http_response_code(403);
    exit('Access denied.');
}
$userName = trim(($adminRow['firstname'] ?? '') . ' ' . ($adminRow['lastname'] ?? ''));
$activePage = 'seller_approvals';
if (empty($_SESSION['seller_review_csrf'])) {
    $_SESSION['seller_review_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['seller_review_csrf'];
$flash = $_SESSION['seller_review_flash'] ?? null;
unset($_SESSION['seller_review_flash']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    if ($submittedToken === '' || !hash_equals($csrfToken, $submittedToken)) {
        http_response_code(403);
        exit('Invalid request token. Please refresh the page and try again.');
    }
    $applicationId = filter_var($_POST['application_id'] ?? null, FILTER_VALIDATE_INT);
    $status = (string)($_POST['status'] ?? '');
    $rejectionReason = trim((string)($_POST['rejection_reason'] ?? ''));
    try {
        if (!$applicationId || !in_array($status, ['approved', 'rejected'], true)) {
            throw new DomainException('Invalid review request.');
        }
        if ($status === 'rejected' && ($rejectionReason === '' || (function_exists('mb_strlen') ? mb_strlen($rejectionReason, 'UTF-8') : strlen($rejectionReason)) > 1000)) {
            throw new DomainException('Please enter a rejection reason up to 1000 characters.');
        }
        $conn->begin_transaction();
        $check = $conn->prepare("
            SELECT user_id, status
            FROM seller_applications
            WHERE application_id = ?
            FOR UPDATE
        ");
        $check->bind_param('i', $applicationId);
        $check->execute();
        $app = $check->get_result()->fetch_assoc();
        $check->close();
        if (!$app || $app['status'] !== 'pending') {
            throw new DomainException('This application is missing or has already been reviewed.');
        }
        $sellerId = (int)$app['user_id'];
        $checkUser = $conn->prepare("
            SELECT account_status, firstname, middlename, lastname, email
            FROM users
            WHERE id = ?
            FOR UPDATE
        ");
        $checkUser->bind_param('i', $sellerId);
        $checkUser->execute();
        $seller = $checkUser->get_result()->fetch_assoc();
        $checkUser->close();
        if (!$seller || $seller['account_status'] === 'disabled') {
            throw new DomainException('The account cannot be reviewed because it is unavailable or disabled.');
        }

        $businessStmt = $conn->prepare("
            SELECT businessname
            FROM businessinfo
            WHERE user_id = ?
            LIMIT 1
        ");

        if (!$businessStmt) {
            throw new RuntimeException('Unable to load the seller business information.');
        }

        $businessStmt->bind_param('i', $sellerId);
        $businessStmt->execute();
        $business = $businessStmt->get_result()->fetch_assoc();
        $businessStmt->close();

        $sellerName = trim(
            (string)($seller['firstname'] ?? '')
            . ' '
            . (string)($seller['middlename'] ?? '')
            . ' '
            . (string)($seller['lastname'] ?? '')
        );
        $sellerName = preg_replace('/\s+/', ' ', $sellerName) ?: 'LokalCraft User';
        $sellerEmail = trim((string)($seller['email'] ?? ''));
        $businessName = trim((string)($business['businessname'] ?? ''));

        if ($status === 'approved') {
            $updateApp = $conn->prepare("
                UPDATE seller_applications
                SET status = 'approved',
                    rejection_reason = NULL,
                    reviewed_at = NOW(),
                    reviewed_by = ?
                WHERE application_id = ?
            ");
            $updateApp->bind_param('ii', $adminId, $applicationId);
            $updateApp->execute();
            $updateApp->close();
            $updateUser = $conn->prepare("
                UPDATE users
                SET SellerOrNot = 1,
                    account_status = 'active'
                WHERE id = ?
            ");
            $updateUser->bind_param('i', $sellerId);
            $updateUser->execute();
            $updateUser->close();
        } else {
            $updateApp = $conn->prepare("
                UPDATE seller_applications
                SET status = 'rejected',
                    rejection_reason = ?,
                    reviewed_at = NOW(),
                    reviewed_by = ?
                WHERE application_id = ?
            ");
            $updateApp->bind_param('sii', $rejectionReason, $adminId, $applicationId);
            $updateApp->execute();
            $updateApp->close();
            $updateUser = $conn->prepare("
                UPDATE users
                SET SellerOrNot = 0,
                    account_status = 'active'
                WHERE id = ?
            ");
            $updateUser->bind_param('i', $sellerId);
            $updateUser->execute();
            $updateUser->close();
        }
        $conn->commit();

        $decisionMessage = $status === 'approved'
            ? 'Seller application approved successfully.'
            : 'Seller application rejected successfully.';

        try {
            sellerNotificationSendDecisionEmail(
                $sellerEmail,
                $sellerName,
                $businessName,
                $status,
                $rejectionReason
            );
            $decisionMessage .= ' Email notification sent.';
        } catch (Throwable $mailError) {
            error_log(
                'Seller application email notification failed for application '
                . $applicationId
                . ': '
                . $mailError->getMessage()
            );
            $decisionMessage .= ' The decision was saved, but the email notification could not be sent.';
        }

        $_SESSION['seller_review_flash'] = [
            'type' => 'success',
            'message' => $decisionMessage,
        ];
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
        error_log('Seller review failed: ' . $e->getMessage());
        $_SESSION['seller_review_flash'] = [
            'type' => 'error',
            'message' => $e instanceof DomainException
                ? $e->getMessage()
                : 'Unable to complete the review. Please try again.'
        ];
    }
    header('Location: seller_approvals.php', true, 303);
    exit;
}
$applications = [];
$query = "
    SELECT
        sa.application_id,
        sa.user_id,
        sa.status,
        sa.rejection_reason,
        sa.submitted_at,
        sa.reviewed_at,
        u.firstname,
        u.middlename,
        u.lastname,
        u.email,
        u.phone,
        bi.businessname,
        bi.craftcategory,
        bi.businessdescription,
        bi.validid_type,
        bi.validid,
        bi.validid_back,
        bi.business_document_type,
        bi.businesspermit,
        li.streetaddress,
        li.barangay,
        li.city,
        li.zipcode
    FROM seller_applications sa
    INNER JOIN users u ON u.id = sa.user_id
    LEFT JOIN businessinfo bi ON bi.user_id = sa.user_id
    LEFT JOIN locationinfo li ON li.user_id = sa.user_id
    ORDER BY
        CASE WHEN sa.status = 'pending' THEN 0 ELSE 1 END,
        sa.submitted_at DESC,
        sa.application_id DESC
";
$result = $conn->query($query);
while ($record = $result->fetch_assoc()) {
    $applications[] = $record;
}
$result->free();
$counts = [
    'all' => count($applications),
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
];
foreach ($applications as $application) {
    $applicationStatus = strtolower((string)($application['status'] ?? ''));
    if (isset($counts[$applicationStatus])) {
        $counts[$applicationStatus]++;
    }
}
function sellerDocumentUrl(int $applicationId, string $type, ?string $storedName): string
{
    if (!$storedName) {
        return '';
    }
    return 'view_seller_document.php?application_id=' . $applicationId
        . '&type=' . rawurlencode($type);
}
function sellerDocumentIsPdf(?string $storedName): bool
{
    return strtolower(pathinfo((string)$storedName, PATHINFO_EXTENSION)) === 'pdf';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>LokalCraft | Seller Approvals</title>
  <style>

:root {
  --bg:#f8f7f5;--surface:#fff;--ink:#383633;--muted:#85817b;
  --line:#ebe8e4;--orange:#dc8c46;--orange-pale:#fff3e7;
  --green:#387f5b;--green-pale:#edf8f0;--red:#b65e55;--red-pale:#fff0ed;
  --shadow:0 6px 24px rgba(41,35,29,.04);
}
*,*::before,*::after{box-sizing:border-box}
html,body{min-height:100%;margin:0}
body{font-family:Inter,"Segoe UI",Arial,sans-serif;background:var(--bg);color:var(--ink);font-size:14px}
body.modal-open{overflow:hidden}
.page{display:flex;min-width:0;min-height:100vh}
.content{flex:1;min-width:0;width:100%;padding:28px 30px 40px}
.header{margin-bottom:19px}
.header h1{font-size:clamp(1.5rem,2vw,1.9rem);font-weight:750;letter-spacing:-.5px;margin:0 0 6px}
.header p{color:var(--muted);font-size:.83rem;margin:0;line-height:1.5}
.review-flash{border:1px solid var(--line);border-radius:11px;padding:11px 13px;margin:0 0 15px;font-size:.78rem;background:#fff}
.review-flash.success{background:var(--green-pale);border-color:#cce9d6;color:var(--green)}
.review-flash.error{background:var(--red-pale);border-color:#f1d2cd;color:var(--red)}
.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px;margin-bottom:20px}
.summary-card{border:1px solid var(--line);background:#fff;border-radius:15px;box-shadow:var(--shadow);padding:15px 17px;display:flex;align-items:center;justify-content:space-between;gap:8px;min-width:0}
.summary-label{display:block;font-size:.75rem;font-weight:550;color:var(--muted);line-height:1.45}
.summary-value{display:block;font-size:1.43rem;line-height:1.3;font-weight:760;margin-top:4px;letter-spacing:-.4px}
.summary-icon{flex:none;display:grid;place-items:center;width:36px;height:36px;border-radius:11px;color:#cc8542;background:#fff3e6}
.summary-icon svg{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.summary-card:nth-child(2) .summary-icon{background:#fff4e0;color:#c58c32}
.summary-card:nth-child(3) .summary-icon{background:#eaf7f0;color:#4b9b6d}
.summary-card:nth-child(4) .summary-icon{background:#fff0ef;color:#c67d74}
.list-panel{min-width:0;padding:19px 19px 12px;background:#fff;border:1px solid var(--line);border-radius:17px;box-shadow:var(--shadow)}
.toolbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:13px;margin-bottom:14px}
.filter-tabs{display:grid;grid-template-columns:repeat(4,max-content);gap:6px;align-items:center}
.filter-tab{appearance:none;display:flex;gap:6px;align-items:center;justify-content:center;min-height:38px;border:1px solid transparent;border-radius:10px;padding:8px 11px;background:transparent;color:#77736f;font:inherit;font-size:.76rem;font-weight:650;cursor:pointer;white-space:nowrap;transition:background .15s,border-color .15s}
.filter-tab:hover{background:#f7f2ec}
.filter-tab.active{background:#fff4e9;border-color:#f1dcc7;color:#ac6b34}
.filter-count{display:grid;place-items:center;min-width:20px;padding:2px 4px;background:rgba(175,130,78,.09);border-radius:6px;font-size:.68rem;line-height:1.25}
.search-wrap{position:relative;width:min(310px,100%);flex:1 1 210px;max-width:360px}
.search-wrap svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);width:16px;height:16px;stroke:#a7a19a;fill:none;stroke-width:2;pointer-events:none}
.search-wrap input{width:100%;min-height:41px;border:1px solid var(--line);border-radius:11px;background:#fbfaf9;padding:9px 12px 9px 37px;font:inherit;color:var(--ink);font-size:.79rem;outline:none}
.search-wrap input:focus{border-color:#e8b58b;box-shadow:0 0 0 3px rgba(232,181,139,.13)}
.table-shell{min-width:0}
.table-scroll{max-height:min(64vh,730px);overflow-y:auto;overflow-x:hidden;scrollbar-width:thin;scrollbar-color:#ded6ce transparent}
.applications-table{width:100%;table-layout:fixed;border-collapse:collapse;font-size:.79rem;min-width:0}
.applications-table colgroup col:nth-child(1){width:32%}
.applications-table colgroup col:nth-child(2){width:31%}
.applications-table colgroup col:nth-child(3){width:17%}
.applications-table colgroup col:nth-child(4){width:15%}
.applications-table colgroup col:nth-child(5){width:5%}
.applications-table th{text-align:left;padding:12px 10px;border-bottom:1px solid var(--line);background:#fff;color:#8c8881;font-size:.7rem;font-weight:680;position:sticky;top:0;z-index:2}
.applications-table td{padding:13px 10px;border-bottom:1px solid #f0eeeb;vertical-align:middle;min-width:0}
.application-row{cursor:pointer;outline:0;transition:background .16s}
.application-row:hover,.application-row:focus-visible{background:#fffaf5}
.application-row:last-child td{border-bottom:0}
.seller-cell,.business-cell{display:flex;align-items:center;gap:10px;min-width:0}
.seller-avatar,.business-avatar{flex:0 0 35px;width:35px;height:35px;display:grid;place-items:center;border-radius:11px;background:#fff0e0;color:#bf7a39;font-size:.7rem;font-weight:760;letter-spacing:.03em}
.business-avatar{background:#f2f5f2;color:#77907b}
.business-avatar svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.seller-text,.business-text{min-width:0;flex:1}
.seller-name,.business-name{font-size:.8rem;color:#42403c;font-weight:680;line-height:1.35;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.seller-email,.business-category{font-size:.7rem;margin-top:4px;color:#948e88;line-height:1.4;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.business-category{color:#a37e58}
.applied-date{font-size:.75rem;color:#77716c;white-space:nowrap}
.status-badge{display:inline-flex;align-items:center;gap:6px;max-width:100%;border-radius:8px;padding:6px 9px;font-size:.69rem;font-weight:700;white-space:nowrap}
.status-badge::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;opacity:.72}
.status-pending{color:#ab7830;background:#fff5e4}
.status-approved{color:#38855e;background:#ebf8f0}
.status-rejected{color:#b86059;background:#fff0ee}
.row-arrow{font-size:1.15rem;font-weight:600;color:#b1a49a;text-align:right}
.application-extra{display:none}
.empty-state,.no-results{padding:34px 16px;text-align:center;color:var(--muted);font-size:.78rem}
.no-results{display:none;border-top:1px solid var(--line)}
.list-footer{font-size:.71rem;color:#918b85;padding:13px 2px 2px}
.modal-overlay{position:fixed;inset:0;z-index:2147483000;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(33,30,27,.40);backdrop-filter:blur(3px)}
.modal-overlay.show{display:flex}
.modal{position:relative;z-index:1;display:flex;flex-direction:column;width:min(810px,100%);max-height:calc(100dvh - 40px);overflow:hidden;background:#fff;border:1px solid #eae4df;border-radius:18px;box-shadow:0 25px 70px rgba(36,32,28,.18)}
.modal.small{width:min(470px,100%)}
.modal-header{display:flex;align-items:flex-start;justify-content:space-between;gap:13px;flex:none;padding:17px 20px 15px;border-bottom:1px solid var(--line)}
.modal-header-main{min-width:0}.modal-header h2{margin:0;font-size:1.12rem;font-weight:750;color:#48413b}.modal-header p{margin:5px 0 0;color:#8e8781;font-size:.76rem;line-height:1.5}
.modal-close{width:32px;height:32px;flex:0 0 32px;display:grid;place-items:center;border:1px solid var(--line);border-radius:10px;background:#fff;color:#817970;font-size:1.15rem;cursor:pointer}
.modal-close:hover{background:#f8f5f2}.details-topline{display:flex;gap:8px;align-items:center;margin-top:10px}
.modal-body{min-height:0;overflow-y:auto;padding:18px 20px 22px}.info-section+.info-section{margin-top:19px}
.section-label{margin-bottom:10px;color:#ae7442;font-size:.72rem;font-weight:760;letter-spacing:.025em}
.info-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 20px}
.info-item{min-width:0}.info-item.full{grid-column:1/-1}
.info-key{color:#97908b;font-size:.69rem;font-weight:680;line-height:1.45}
.info-value{margin-top:4px;overflow-wrap:anywhere;color:#48443f;font-size:.82rem;line-height:1.5}
.documents-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}
.document-tile{min-width:0;overflow:hidden;border:1px solid var(--line);border-radius:12px;background:#fcfbf9}
.document-tile.wide{grid-column:1/-1}
.document-media{display:grid;place-items:center;width:100%;min-height:130px;border:0;background:#f5f2ee;padding:0}
.document-media-button{cursor:pointer}.document-media-button:hover{background:#efeae4}
.document-media img{display:block;width:100%;height:160px;object-fit:contain;background:#f8f7f5}
.pdf-media{min-height:130px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;color:#aa743d;font-size:.75rem;font-weight:700}
.pdf-icon{display:grid;place-items:center;width:46px;height:55px;border-radius:7px;background:#bb7f4c;color:#fff;font-size:.71rem}
.document-meta{padding:11px}.document-meta strong{display:block;font-size:.78rem;color:#49443e}.document-meta span{display:block;margin-top:4px;color:#908b85;font-size:.7rem;line-height:1.4}
.document-button{width:100%;margin-top:9px;border:1px solid #ead2bb;border-radius:9px;background:#fff8f1;padding:8px;color:#99612d;font:inherit;font-size:.74rem;font-weight:700;cursor:pointer}
.document-empty{min-height:135px;display:grid;place-items:center;text-align:center;color:#98938d;font-size:.76rem;padding:15px}
.modal-footer{display:flex;align-items:center;justify-content:space-between;gap:10px;flex:none;padding:13px 20px;background:#fbfaf8;border-top:1px solid var(--line)}
.modal-footer-note{color:#9b938d;font-size:.7rem;line-height:1.45}
.modal-actions{display:flex;align-items:center;justify-content:flex-end;flex-wrap:wrap;gap:8px;margin-left:auto}
.modal-button{border:1px solid transparent;border-radius:10px;padding:9px 13px;font:inherit;font-size:.78rem;font-weight:700;cursor:pointer;transition:background .15s}
.modal-button.cancel{background:#fff;border-color:var(--line);color:#6c6761}.modal-button.cancel:hover{background:#f7f5f2}
.modal-button.approve{background:#67a580;color:#fff}.modal-button.approve:hover{background:#4b956a}
.modal-button.reject{background:#fff;border-color:#eccbc7;color:#b45e57}.modal-button.reject:hover{background:#fff3f1}
.form-actions{margin-top:16px}.form-field-gap{margin-top:14px}.verification-type-list{margin-bottom:10px}
.review-note{padding:12px;border:1px solid #f2e5d6;border-radius:11px;background:#fff8f1;color:#856543;font-size:.78rem;line-height:1.5}
.reject-form label{display:block;margin-bottom:7px;color:#6e6760;font-size:.77rem;font-weight:700}
.reject-form textarea{display:block;width:100%;min-height:115px;resize:vertical;padding:10px 12px;border:1px solid #e6e0d9;border-radius:10px;font:inherit;font-size:.82rem;color:#49433c;outline:none}
.reject-form textarea:focus{border-color:#d99a69;box-shadow:0 0 0 3px rgba(184,92,40,.08)}
.field-error{margin-top:7px;font-size:.74rem;color:#b86059;font-weight:700}
#documentViewerOverlay{z-index:2147483647}.document-viewer-modal{width:min(970px,100%)}
.viewer-body{min-height:0;flex:1;display:grid;place-items:center;overflow:auto;padding:12px;background:#f0eeeb}
.viewer-body img{max-width:100%;max-height:calc(100dvh - 155px);object-fit:contain;border-radius:8px;background:#fff}
.viewer-body iframe{width:100%;height:min(78dvh,800px);border:0;border-radius:8px;background:#fff}
.viewer-state{width:min(420px,100%);padding:21px 16px;border:1px solid #e5dfd7;border-radius:12px;background:#fff;color:#6b655f;text-align:center;font-size:.8rem;line-height:1.5}
.viewer-state.error{background:#fff6f5;color:var(--red);border-color:#f1d2cd}
.viewer-spinner{width:26px;height:26px;margin:0 auto 10px;border:3px solid #e7dfd5;border-top-color:var(--orange);border-radius:50%;animation:documentSpin .75s linear infinite}
@keyframes documentSpin{to{transform:rotate(360deg)}}
[hidden]{display:none!important}
@media(max-width:1170px){.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.list-panel{padding:15px}.toolbar{align-items:stretch}.search-wrap{max-width:none;flex-basis:100%}.applications-table th,.applications-table td{padding-left:8px;padding-right:8px}.seller-avatar,.business-avatar{display:none}}
@media(max-width:900px){
  .content{padding:20px 14px 28px}.header{margin-bottom:16px}.header h1{font-size:1.52rem}
  .summary-grid{gap:9px;margin-bottom:16px}.summary-card{padding:12px 13px;border-radius:13px}.summary-value{font-size:1.27rem}.summary-label{font-size:.7rem}.summary-icon{width:30px;height:30px}.summary-icon svg{width:16px;height:16px}
  .list-panel{padding:13px 10px 10px;border-radius:15px}.toolbar{gap:11px;margin-bottom:12px}
  .filter-tabs{width:100%;grid-template-columns:repeat(4,minmax(0,1fr));gap:5px}
  .filter-tab{min-width:0;width:100%;min-height:48px;flex-direction:column;gap:3px;padding:7px 2px;border-radius:9px;font-size:clamp(.64rem,2.5vw,.73rem);line-height:1.1}
  .filter-count{padding:1px 4px;font-size:.64rem;min-width:16px}
  .search-wrap{width:100%;min-width:0;max-width:none}
  .table-scroll{overflow-x:hidden;max-height:none;overflow-y:visible}
  .applications-table{display:block;width:100%}.applications-table thead,.applications-table colgroup{display:none}
  .applications-table tbody{display:grid;gap:10px;width:100%}
  .applications-table tr.application-row{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:start;gap:7px 10px;width:100%;padding:13px;border:1px solid var(--line);border-radius:13px;background:#fff;box-shadow:0 3px 12px rgba(35,28,19,.025)}
  .applications-table tr.application-row:hover,.applications-table tr.application-row:focus-visible{background:#fffaf6;outline:2px solid transparent;border-color:#e6ccad}
  .applications-table td{display:block;width:auto;padding:0;border:0;min-width:0}
  .applications-table td:nth-child(1){grid-column:1;grid-row:1}
  .applications-table td:nth-child(2){grid-column:1/-1;grid-row:2;border-top:1px solid #f1ede7;padding-top:9px}
  .applications-table td:nth-child(3){grid-column:1;grid-row:3;display:flex;align-items:center;gap:6px;color:#938b83;font-size:.7rem}
  .applications-table td:nth-child(3)::before{content:'Applied';color:#aca39b;font-size:.68rem}
  .applications-table td:nth-child(4){grid-column:2;grid-row:1;justify-self:end}
  .applications-table td:nth-child(5){grid-column:2;grid-row:3;align-self:end;text-align:right}
  .seller-cell{gap:9px}.seller-avatar{display:grid;flex-basis:39px;width:39px;height:39px;border-radius:12px;font-size:.75rem}
  .seller-name{font-size:.83rem}.seller-email{font-size:.7rem;white-space:nowrap}.business-cell{gap:8px}.business-avatar{display:grid;flex-basis:31px;width:31px;height:31px;border-radius:9px}
  .business-name{font-size:.77rem}.business-category{font-size:.69rem;margin-top:3px}.status-badge{padding:6px 7px;font-size:.65rem}.row-arrow{font-size:1.22rem}
  .application-extra{display:flex;align-items:center;gap:8px;min-width:0;margin-top:5px;font-size:.68rem;color:#9b9288;flex-wrap:wrap}
  .application-extra span{display:inline-flex;align-items:center;gap:4px;max-width:100%;min-width:0}
  .modal-overlay{align-items:flex-start;padding:max(10px,env(safe-area-inset-top)) 8px max(10px,env(safe-area-inset-bottom));overflow-y:auto}
  .modal{max-height:calc(100dvh - 20px);margin:auto 0;border-radius:15px}.modal-header{padding:14px 15px 12px}.modal-body{padding:14px 15px 18px}.modal-footer{padding:12px 15px;flex-direction:column;align-items:stretch}
  .modal-actions{margin-left:0;width:100%}.modal-button{flex:1;min-height:38px}.info-list,.documents-grid{grid-template-columns:1fr}.info-item.full,.document-tile.wide{grid-column:auto}
  .document-media img{height:195px}.viewer-body iframe{height:70dvh}
}
@media(max-width:385px){.content{padding-left:10px;padding-right:10px}.summary-card{padding:11px}.summary-icon{display:none}.seller-email{max-width:195px}.applications-table tr.application-row{padding:11px}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important}}
</style>
</head>
<body>
<div class="page">
  <?php include __DIR__ . '/admin_nav.php'; ?>
  <main class="content">
    <div class="header">
      <h1>Seller Approvals</h1>
      <p>Open an application to review its details and take action.</p>
    </div>
    <?php if (!empty($flash)): ?>
      <div class="review-flash <?php echo $flash['type'] === 'success' ? 'success' : 'error'; ?>" role="status">
        <?php echo htmlspecialchars((string)$flash['message'], ENT_QUOTES, 'UTF-8'); ?>
      </div>
    <?php endif; ?>
    <section class="summary-grid" aria-label="Seller application overview">
    <article class="summary-card"><div><span class="summary-label">Total Applications</span><strong class="summary-value"><?php echo number_format($counts['all']); ?></strong></div><span class="summary-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 9h8M8 13h8M8 17h5"/></svg></span></article>
    <article class="summary-card"><div><span class="summary-label">Pending Review</span><strong class="summary-value"><?php echo number_format($counts['pending']); ?></strong></div><span class="summary-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span></article>
    <article class="summary-card"><div><span class="summary-label">Approved</span><strong class="summary-value"><?php echo number_format($counts['approved']); ?></strong></div><span class="summary-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg></span></article>
    <article class="summary-card"><div><span class="summary-label">Rejected</span><strong class="summary-value"><?php echo number_format($counts['rejected']); ?></strong></div><span class="summary-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/></svg></span></article>
  </section>
  <section class="list-panel" aria-label="Seller applications">
  <div class="toolbar">
      <div class="filter-tabs" role="group" aria-label="Seller application filters">
        <button class="filter-tab active" type="button" data-filter="all">
          All <span class="filter-count"><?php echo (int)$counts['all']; ?></span>
        </button>
        <button class="filter-tab" type="button" data-filter="pending">
          Pending <span class="filter-count"><?php echo (int)$counts['pending']; ?></span>
        </button>
        <button class="filter-tab" type="button" data-filter="approved">
          Approved <span class="filter-count"><?php echo (int)$counts['approved']; ?></span>
        </button>
        <button class="filter-tab" type="button" data-filter="rejected">
          Rejected <span class="filter-count"><?php echo (int)$counts['rejected']; ?></span>
        </button>
      </div>
      <div class="search-wrap">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m16 16 5 5"/></svg>
        <input
          type="search"
          id="applicationSearch"
          placeholder="Search seller or business"
          autocomplete="off"
        >
      </div>
    </div>
    <div class="table-shell">
      <div class="table-scroll">
        <table class="applications-table">
          <colgroup>
            <col style="width:32%">
            <col style="width:31%">
            <col style="width:17%">
            <col style="width:15%">
            <col style="width:5%">
          </colgroup>
          <thead>
            <tr>
              <th>Seller</th>
              <th>Business</th>
              <th>Applied</th>
              <th>Status</th>
              <th aria-label="Open application"></th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($applications)): ?>
            <tr>
              <td colspan="5">
                <div class="empty-state">No seller applications found.</div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($applications as $application): ?>
              <?php
                $fullName = trim(
                    ($application['firstname'] ?? '')
                    . ' '
                    . ($application['middlename'] ?? '')
                    . ' '
                    . ($application['lastname'] ?? '')
                );
                $fullName = preg_replace('/\s+/', ' ', $fullName);
                $location = trim(
                    ($application['barangay'] ?? '')
                    . ', '
                    . ($application['city'] ?? '')
                );
                $status = strtolower((string)($application['status'] ?? 'pending'));
                $applicationId = (int)$application['application_id'];
                $businessDocumentUrl = sellerDocumentUrl(
                    $applicationId,
                    'businesspermit',
                    $application['businesspermit'] ?? null
                );
                $validIdUrl = sellerDocumentUrl(
                    $applicationId,
                    'validid',
                    $application['validid'] ?? null
                );
                $validIdBackUrl = sellerDocumentUrl(
                    $applicationId,
                    'validid_back',
                    $application['validid_back'] ?? null
                );
                $applicationData = [
                    'applicationId' => $applicationId,
                    'statusKey' => $status,
                    'name' => $fullName,
                    'email' => $application['email'] ?? '',
                    'phone' => $application['phone'] ?? '',
                    'business' => $application['businessname'] ?? '',
                    'category' => $application['craftcategory'] ?? '',
                    'description' => $application['businessdescription'] ?? '',
                    'street' => $application['streetaddress'] ?? '',
                    'barangay' => $application['barangay'] ?? '',
                    'city' => $application['city'] ?? '',
                    'zipcode' => $application['zipcode'] ?? '',
                    'location' => $location,
                    'validIdType' => $application['validid_type'] ?? '',
                    'businessDocumentType' => $application['business_document_type'] ?? '',
                    'validIdUrl' => $validIdUrl,
                    'validIdBackUrl' => $validIdBackUrl,
                    'businessDocumentUrl' => $businessDocumentUrl,
                    'businessDocumentIsPdf' => sellerDocumentIsPdf($application['businesspermit'] ?? null),
                    'status' => ucfirst($status),
                    'submitted' => !empty($application['submitted_at'])
                        ? date('F j, Y g:i A', strtotime($application['submitted_at']))
                        : '',
                    'rejectionReason' => $application['rejection_reason'] ?? '',
                ];
                $searchText = strtolower(implode(' ', [
                    $fullName,
                    $application['email'] ?? '',
                    $application['businessname'] ?? '',
                    $application['craftcategory'] ?? '',
                    $location,
                ]));
              ?>
              <tr
                class="application-row"
                tabindex="0"
                role="button"
                aria-label="Open application for <?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>"
                data-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>"
                data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8'); ?>"
                data-application="<?php echo htmlspecialchars(
                    json_encode(
                        $applicationData,
                        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                    ),
                    ENT_QUOTES,
                    'UTF-8'
                ); ?>"
              >
                <td>
                  <div class="seller-cell">
                    <span class="seller-avatar" aria-hidden="true"><?php echo htmlspecialchars(strtoupper(substr(trim((string)($application['firstname'] ?? '')), 0, 1) . substr(trim((string)($application['lastname'] ?? '')), 0, 1)) ?: 'S', ENT_QUOTES, 'UTF-8'); ?></span>
                    <div class="seller-text">
                      <div class="seller-name"><?php echo htmlspecialchars($fullName ?: 'Unnamed Applicant', ENT_QUOTES, 'UTF-8'); ?></div>
                      <div class="seller-email"><?php echo htmlspecialchars($application['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="business-cell">
                    <span class="business-avatar" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 9h18l-2-5H5L3 9Z"/><path d="M5 9v11h14V9M9 20v-7h6v7"/></svg></span>
                    <div class="business-text">
                      <div class="business-name"><?php echo htmlspecialchars($application['businessname'] ?? 'Not provided', ENT_QUOTES, 'UTF-8'); ?></div>
                      <div class="business-category"><?php echo htmlspecialchars($application['craftcategory'] ?? 'No category', ENT_QUOTES, 'UTF-8'); ?></div>
                      <div class="application-extra"><span>#<?php echo $applicationId; ?> · <?php echo htmlspecialchars(trim($location, ' ,') !== '' ? trim($location, ' ,') : 'Location unavailable', ENT_QUOTES, 'UTF-8'); ?></span></div>
                    </div>
                  </div>
                </td>
                <td class="applied-date">
                  <?php echo !empty($application['submitted_at'])
                      ? htmlspecialchars(date('M j, Y', strtotime($application['submitted_at'])), ENT_QUOTES, 'UTF-8')
                      : '—'; ?>
                </td>
                <td>
                  <span class="status-badge status-<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8'); ?>
                  </span>
                </td>
                <td class="row-arrow">›</td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="no-results" id="noFilterResults">
        No applications match your current filter.
      </div>
    </div>
    <div class="list-footer">Select an application to review its details and verification documents.</div>
  </section>
  </main>
</div>
<!-- APPLICATION DETAILS -->
<div class="modal-overlay" id="detailsOverlay" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="detailsTitle">
    <div class="modal-header">
      <div class="modal-header-main">
        <h2 id="detailsTitle">Seller Application</h2>
        <p id="detailsSubtitle">Review the submitted seller information.</p>
        <div class="details-topline" id="detailsStatusLine"></div>
      </div>
      <button class="modal-close" type="button" data-close="detailsOverlay" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body" id="detailsContent"></div>
    <div class="modal-footer">
      <div class="modal-footer-note" id="detailsFooterNote"></div>
      <div class="modal-actions" id="detailsActions"></div>
    </div>
  </div>
</div>
<!-- APPROVE CONFIRMATION -->
<div class="modal-overlay" id="approveOverlay" aria-hidden="true">
  <div class="modal small" role="dialog" aria-modal="true" aria-labelledby="approveTitle">
    <div class="modal-header">
      <div class="modal-header-main">
        <h2 id="approveTitle">Approve Seller</h2>
        <p>Confirm this application after reviewing the submitted information and documents.</p>
      </div>
      <button class="modal-close" type="button" data-close="approveOverlay" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="review-note" id="approveMessage"></div>
      <form method="POST" id="approveForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="application_id" id="approveApplicationId">
        <input type="hidden" name="status" value="approved">
        <div class="modal-actions form-actions">
          <button class="modal-button cancel" type="button" data-close="approveOverlay">Cancel</button>
          <button class="modal-button approve" type="submit">Approve Seller</button>
        </div>
      </form>
    </div>
  </div>
</div>
<!-- REJECT APPLICATION -->
<div class="modal-overlay" id="rejectOverlay" aria-hidden="true">
  <div class="modal small" role="dialog" aria-modal="true" aria-labelledby="rejectTitle">
    <div class="modal-header">
      <div class="modal-header-main">
        <h2 id="rejectTitle">Reject Application</h2>
        <p>Enter a clear reason for the rejection.</p>
      </div>
      <button class="modal-close" type="button" data-close="rejectOverlay" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="review-note" id="rejectMessage"></div>
      <form class="reject-form" method="POST" id="rejectForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="application_id" id="rejectApplicationId">
        <input type="hidden" name="status" value="rejected">
        <div class="form-field-gap">
          <label for="rejectionReason">Rejection Reason *</label>
          <textarea
            id="rejectionReason"
            name="rejection_reason"
            maxlength="1000"
            placeholder="Enter the reason for rejection."
          ></textarea>
          <div class="field-error" id="rejectionReasonError" hidden></div>
        </div>
        <div class="modal-actions form-actions">
          <button class="modal-button cancel" type="button" data-close="rejectOverlay">Cancel</button>
          <button class="modal-button reject" type="submit">Reject Application</button>
        </div>
      </form>
    </div>
  </div>
</div>
<!-- DOCUMENT VIEWER -->
<div class="modal-overlay" id="documentViewerOverlay" aria-hidden="true">
  <div class="modal document-viewer-modal" role="dialog" aria-modal="true" aria-labelledby="documentViewerTitle">
    <div class="modal-header">
      <div class="modal-header-main">
        <h2 id="documentViewerTitle">Verification Document</h2>
        <p id="documentViewerSubtitle"></p>
      </div>
      <button class="modal-close" type="button" data-close="documentViewerOverlay" aria-label="Close">&times;</button>
    </div>
    <div class="viewer-body">
      <div class="viewer-state" id="documentViewerLoading" hidden>
        <div class="viewer-spinner" aria-hidden="true"></div>
        Loading verification document...
      </div>
      <div class="viewer-state error" id="documentViewerError" hidden></div>
      <img id="documentViewerImage" alt="Seller verification document" hidden>
      <iframe id="documentViewerPdf" title="Seller verification PDF" hidden></iframe>
    </div>
  </div>
</div>
<script>
  let currentApplication = null;
  let currentStatusFilter = 'all';
  let currentDocumentObjectUrl = '';
  function escapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }
  function openOverlay(id) {
    const overlay = document.getElementById(id);
    if (!overlay) return;
    overlay.classList.add('show');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
  }
  function closeOverlay(id) {
    const overlay = document.getElementById(id);
    if (!overlay) return;
    overlay.classList.remove('show');
    overlay.setAttribute('aria-hidden', 'true');
    if (!document.querySelector('.modal-overlay.show')) {
      document.body.classList.remove('modal-open');
    }
    if (id === 'documentViewerOverlay') {
      const image = document.getElementById('documentViewerImage');
      const pdf = document.getElementById('documentViewerPdf');
      const loading = document.getElementById('documentViewerLoading');
      const viewerError = document.getElementById('documentViewerError');
      image.hidden = true;
      image.removeAttribute('src');
      pdf.hidden = true;
      pdf.removeAttribute('src');
      loading.hidden = true;
      viewerError.hidden = true;
      viewerError.textContent = '';
      if (currentDocumentObjectUrl) {
        URL.revokeObjectURL(currentDocumentObjectUrl);
        currentDocumentObjectUrl = '';
      }
    }
  }
  function infoItem(label, value, full = false) {
    return `
      <div class="info-item ${full ? 'full' : ''}">
        <div class="info-key">${escapeHtml(label)}</div>
        <div class="info-value">${escapeHtml(value || 'Not provided')}</div>
      </div>
    `;
  }
  function statusBadge(statusKey, statusLabel) {
    return `
      <span class="status-badge status-${escapeHtml(statusKey)}">
        ${escapeHtml(statusLabel)}
      </span>
    `;
  }
  function documentTile({label, type, url, isPdf = false, wide = false}) {
    if (!url) {
      return `
        <div class="document-tile ${wide ? 'wide' : ''}">
          <div class="document-empty">${escapeHtml(label)} was not provided.</div>
        </div>
      `;
    }
    const media = isPdf
      ? `
          <div class="pdf-media">
            <span class="pdf-icon">PDF</span>
            <span>PDF Document</span>
          </div>
        `
      : `
          <img src="${escapeHtml(url)}" alt="${escapeHtml(label)}" loading="lazy">
        `;
    return `
      <div class="document-tile ${wide ? 'wide' : ''}">
        <button
          type="button"
          class="document-media document-media-button"
          data-document-open
          data-document-url="${escapeHtml(url)}"
          data-document-label="${escapeHtml(label)}"
          data-document-pdf="${isPdf ? '1' : '0'}"
          aria-label="Open ${escapeHtml(label)}"
        >${media}</button>
        <div class="document-meta">
          <strong>${escapeHtml(label)}</strong>
          <span>${escapeHtml(type || 'Verification document')}</span>
          <button
            type="button"
            class="document-button"
            data-document-open
            data-document-url="${escapeHtml(url)}"
            data-document-label="${escapeHtml(label)}"
            data-document-pdf="${isPdf ? '1' : '0'}"
          >View Document</button>
        </div>
      </div>
    `;
  }
  function openApplication(row) {
    let data;
    try {
      data = JSON.parse(row.dataset.application || '{}');
    } catch (_) {
      return;
    }
    currentApplication = data;
    const passport = String(data.validIdType || '').toLowerCase() === 'passport';
    document.getElementById('detailsTitle').textContent =
      data.name || 'Seller Application';
    document.getElementById('detailsSubtitle').textContent =
      data.business
        ? `${data.business}${data.category ? ' · ' + data.category : ''}`
        : 'Review the submitted seller information.';
    document.getElementById('detailsStatusLine').innerHTML =
      statusBadge(data.statusKey, data.status);
    document.getElementById('detailsContent').innerHTML = `
      <section class="info-section">
        <div class="section-label">Applicant</div>
        <div class="info-list">
          ${infoItem('Email', data.email)}
          ${infoItem('Phone', data.phone)}
          ${infoItem('Applied', data.submitted)}
          ${infoItem('Application ID', data.applicationId)}
        </div>
      </section>
      <section class="info-section">
        <div class="section-label">Business</div>
        <div class="info-list">
          ${infoItem('Business / Shop Name', data.business)}
          ${infoItem('Craft Category', data.category)}
          ${infoItem('Business Description', data.description, true)}
        </div>
      </section>
      <section class="info-section">
        <div class="section-label">Address</div>
        <div class="info-list">
          ${infoItem('Street Address', data.street)}
          ${infoItem('Barangay', data.barangay)}
          ${infoItem('City', data.city)}
          ${infoItem('ZIP Code', data.zipcode)}
        </div>
      </section>
      <section class="info-section">
        <div class="section-label">Verification</div>
        <div class="info-list verification-type-list">
          ${infoItem('Valid ID Type', data.validIdType)}
          ${infoItem('Business Document Type', data.businessDocumentType)}
        </div>
        <div class="documents-grid">
          ${documentTile({
            label: passport ? 'Passport Information Page' : 'Valid ID — Front',
            type: data.validIdType,
            url: data.validIdUrl,
            isPdf: false
          })}
          ${passport ? '' : documentTile({
            label: 'Valid ID — Back',
            type: data.validIdType,
            url: data.validIdBackUrl,
            isPdf: false
          })}
          ${documentTile({
            label: 'Business Verification Document',
            type: data.businessDocumentType,
            url: data.businessDocumentUrl,
            isPdf: Boolean(data.businessDocumentIsPdf),
            wide: true
          })}
        </div>
      </section>
      ${data.rejectionReason ? `
        <section class="info-section">
          <div class="section-label">Rejection Record</div>
          <div class="info-list">
            ${infoItem('Reason', data.rejectionReason, true)}
          </div>
        </section>
      ` : ''}
    `;
    const footerNote = document.getElementById('detailsFooterNote');
    const actions = document.getElementById('detailsActions');
    if (data.statusKey === 'pending') {
      footerNote.textContent = 'Review the information before making a decision.';
      actions.innerHTML = `
        <button class="modal-button reject" type="button" id="detailsRejectButton">Reject</button>
        <button class="modal-button approve" type="button" id="detailsApproveButton">Approve Seller</button>
      `;
      document.getElementById('detailsRejectButton').addEventListener('click', () => {
        openReject(data.applicationId, data.name);
      });
      document.getElementById('detailsApproveButton').addEventListener('click', () => {
        openApprove(data.applicationId, data.name);
      });
    } else {
      footerNote.textContent =
        data.statusKey === 'approved'
          ? 'This seller application has already been approved.'
          : 'This seller application has already been rejected.';
      actions.innerHTML = `
        <button class="modal-button cancel" type="button" data-close-dynamic="detailsOverlay">Close</button>
      `;
      actions.querySelector('[data-close-dynamic]').addEventListener('click', () => {
        closeOverlay('detailsOverlay');
      });
    }
    openOverlay('detailsOverlay');
  }
  function openApprove(applicationId, applicantName) {
    document.getElementById('approveApplicationId').value = applicationId;
    document.getElementById('approveMessage').textContent =
      `Approve ${applicantName}? Seller access will be enabled after confirmation.`;
    openOverlay('approveOverlay');
  }
  function openReject(applicationId, applicantName) {
    document.getElementById('rejectApplicationId').value = applicationId;
    document.getElementById('rejectionReason').value = '';
    document.getElementById('rejectionReasonError').hidden = true;
    document.getElementById('rejectMessage').textContent =
      `Reject ${applicantName}'s seller application.`;
    openOverlay('rejectOverlay');
    window.setTimeout(() => {
      document.getElementById('rejectionReason').focus();
    }, 80);
  }
  async function openDocumentViewer(url, label, isPdf) {
    if (!url) return;
    const image = document.getElementById('documentViewerImage');
    const pdf = document.getElementById('documentViewerPdf');
    const loading = document.getElementById('documentViewerLoading');
    const viewerError = document.getElementById('documentViewerError');
    document.getElementById('documentViewerTitle').textContent =
      label || 'Verification Document';
    document.getElementById('documentViewerSubtitle').textContent =
      isPdf ? 'PDF verification document' : 'Image verification document';
    image.hidden = true;
    image.removeAttribute('src');
    pdf.hidden = true;
    pdf.removeAttribute('src');
    viewerError.hidden = true;
    viewerError.textContent = '';
    loading.hidden = false;
    if (currentDocumentObjectUrl) {
      URL.revokeObjectURL(currentDocumentObjectUrl);
      currentDocumentObjectUrl = '';
    }
    openOverlay('documentViewerOverlay');
    try {
      const response = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      if (!response.ok) {
        let message = 'Unable to load this verification document.';
        try {
          const serverMessage = (await response.text()).trim();
          if (serverMessage) message = serverMessage;
        } catch (_) {
        }
        throw new Error(message);
      }
      const blob = await response.blob();
      if (!blob.size) {
        throw new Error('The verification document is empty.');
      }
      currentDocumentObjectUrl = URL.createObjectURL(blob);
      const contentType = String(blob.type || '').toLowerCase();
      const showPdf = isPdf || contentType === 'application/pdf';
      loading.hidden = true;
      if (showPdf) {
        document.getElementById('documentViewerSubtitle').textContent =
          'PDF verification document';
        pdf.src = currentDocumentObjectUrl;
        pdf.hidden = false;
      } else {
        document.getElementById('documentViewerSubtitle').textContent =
          'Image verification document';
        image.src = currentDocumentObjectUrl;
        image.hidden = false;
      }
    } catch (error) {
      loading.hidden = true;
      viewerError.textContent = error?.message
        || 'Unable to load this verification document.';
      viewerError.hidden = false;
    }
  }
  document.getElementById('detailsContent').addEventListener('click', event => {
    const trigger = event.target.closest('[data-document-open]');
    if (!trigger) return;
    event.preventDefault();
    event.stopPropagation();
    openDocumentViewer(
      trigger.dataset.documentUrl || '',
      trigger.dataset.documentLabel || 'Verification Document',
      trigger.dataset.documentPdf === '1'
    );
  });
  document.querySelectorAll('.application-row').forEach(row => {
    row.addEventListener('click', () => openApplication(row));
    row.addEventListener('keydown', event => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        openApplication(row);
      }
    });
  });
  document.querySelectorAll('[data-close]').forEach(button => {
    button.addEventListener('click', () => {
      closeOverlay(button.dataset.close);
    });
  });
  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', event => {
      if (event.target === overlay) {
        closeOverlay(overlay.id);
      }
    });
  });
  document.getElementById('rejectForm').addEventListener('submit', event => {
    const reason = document.getElementById('rejectionReason');
    const error = document.getElementById('rejectionReasonError');
    const value = reason.value.trim();
    if (!value) {
      event.preventDefault();
      error.textContent = 'Please enter the reason for rejection.';
      error.hidden = false;
      reason.focus();
      return;
    }
    if (value.length > 1000) {
      event.preventDefault();
      error.textContent = 'The rejection reason must not exceed 1000 characters.';
      error.hidden = false;
      reason.focus();
      return;
    }
    error.hidden = true;
  });
  document.getElementById('rejectionReason').addEventListener('input', function () {
    if (this.value.trim()) {
      document.getElementById('rejectionReasonError').hidden = true;
    }
  });
  const searchInput = document.getElementById('applicationSearch');
  const rows = Array.from(document.querySelectorAll('.application-row'));
  const noResults = document.getElementById('noFilterResults');
  function filterApplications() {
    const search = searchInput.value.trim().toLowerCase();
    let visible = 0;
    rows.forEach(row => {
      const matchesSearch = !search || row.dataset.search.includes(search);
      const matchesStatus =
        currentStatusFilter === 'all'
        || row.dataset.status === currentStatusFilter;
      const show = matchesSearch && matchesStatus;
      row.hidden = !show;
      if (show) visible++;
    });
    noResults.style.display =
      rows.length > 0 && visible === 0
        ? 'block'
        : 'none';
  }
  document.querySelectorAll('.filter-tab').forEach(button => {
    button.addEventListener('click', () => {
      currentStatusFilter = button.dataset.filter || 'all';
      document.querySelectorAll('.filter-tab').forEach(tab => {
        tab.classList.toggle('active', tab === button);
      });
      filterApplications();
    });
  });
  searchInput.addEventListener('input', filterApplications);
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const openOverlays = Array.from(
      document.querySelectorAll('.modal-overlay.show')
    );
    if (openOverlays.length) {
      closeOverlay(openOverlays[openOverlays.length - 1].id);
    }
  });
</script>
</body>
</html>
