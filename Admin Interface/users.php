<?php
/** LokalCraft Admin - Users: Buyer/Seller accounts only. */
require_once __DIR__ . '/admin_auth.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$activePage = 'users';

function usersEsc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function usersJson(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if (empty($_SESSION['admin_users_csrf'])) $_SESSION['admin_users_csrf'] = bin2hex(random_bytes(32));
$usersCsrf = $_SESSION['admin_users_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action !== 'update_user_status') {
        usersJson(['success' => false, 'message' => 'Invalid request.'], 400);
    }
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($usersCsrf, $token)) {
        usersJson(['success' => false, 'message' => 'Session expired. Refresh the page and retry.'], 403);
    }
    $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$targetId || (int)$targetId === (int)$adminId) {
        usersJson(['success' => false, 'message' => 'Invalid user account.'], 400);
    }
    $status = strtolower(trim((string)($_POST['account_status'] ?? '')));
    if (!in_array($status, ['active', 'pending', 'disabled'], true)) {
        usersJson(['success' => false, 'message' => 'Select a valid account status.'], 422);
    }

    try {
        $conn->begin_transaction();
        $lock = $conn->prepare('SELECT SellerOrNot FROM users WHERE id = ? AND COALESCE(AdminOrNot, 0) = 0 FOR UPDATE');
        $lock->bind_param('i', $targetId);
        $lock->execute();
        $lock->bind_result($sellerFlag);
        $found = $lock->fetch();
        $lock->close();
        if (!$found) {
            $conn->rollback();
            usersJson(['success' => false, 'message' => 'User account not found or cannot be managed here.'], 404);
        }

        $stmt = $conn->prepare('UPDATE users SET account_status = ? WHERE id = ? AND COALESCE(AdminOrNot, 0) = 0');
        $stmt->bind_param('si', $status, $targetId);
        $stmt->execute();
        $stmt->close();
        $conn->commit();

        usersJson([
            'success' => true,
            'message' => 'Account status updated.',
            'user' => [
                'id' => (int)$targetId,
                'status' => $status,
                'role' => (int)$sellerFlag === 1 ? 'Seller' : 'Buyer',
            ],
        ]);
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Admin user status update: ' . $e->getMessage());
        usersJson(['success' => false, 'message' => 'Could not update this account status.'], 500);
    }
}

$users = [];
$errorMessage = '';
$hasCreatedAt = false;
try {
    $column = $conn->query("SHOW COLUMNS FROM users LIKE 'created_at'");
    $hasCreatedAt = $column->num_rows > 0;
    $column->free();
    $joinedColumn = $hasCreatedAt ? 'u.created_at' : 'NULL';
    $result = $conn->query("SELECT u.id, u.firstname, u.middlename, u.lastname, u.email, u.phone, u.SellerOrNot, u.account_status, {$joinedColumn} AS joined_at, sa.status AS application_status FROM users u LEFT JOIN (SELECT user_id, MAX(application_id) AS latest_id FROM seller_applications GROUP BY user_id) latest ON latest.user_id = u.id LEFT JOIN seller_applications sa ON sa.application_id = latest.latest_id WHERE COALESCE(u.AdminOrNot, 0) = 0 ORDER BY u.id DESC");
    while ($row = $result->fetch_assoc()) {
        $row['role'] = (int)$row['SellerOrNot'] === 1 ? 'Seller' : 'Buyer';
        $row['full_name'] = trim(implode(' ', array_filter([(string)$row['firstname'], (string)$row['middlename'], (string)$row['lastname']], static function ($v) { return trim($v) !== ''; })));
        $row['joined'] = !empty($row['joined_at']) && strtotime((string)$row['joined_at']) ? date('M j, Y', strtotime((string)$row['joined_at'])) : 'Not recorded';
        $row['account_status'] = strtolower(trim((string)$row['account_status']));
        $users[] = $row;
    }
    $result->free();
} catch (Throwable $e) {
    error_log('Admin users load: ' . $e->getMessage());
    $errorMessage = 'Unable to load user records. Please check the server log.';
}
$userCount = count($users);
$sellerCount = count(array_filter($users, static function ($u) { return $u['role'] === 'Seller'; }));
$buyerCount = $userCount - $sellerCount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LokalCraft | Users</title>
<style>
:root{--users-bg:#f8f7f5;--users-white:#fff;--users-text:#373633;--users-muted:#85827d;--users-line:#ebe8e4;--users-orange:#d9853e;--users-shadow:0 6px 24px rgba(41,35,29,.035)}
*,*::before,*::after{box-sizing:border-box}
body{margin:0;font-family:Inter,"Segoe UI",Arial,sans-serif;background:var(--users-bg);color:var(--users-text)}
.page{display:flex;min-height:100vh;min-width:0}.content{flex:1;min-width:0;width:100%;padding:28px 30px 40px}
.users-heading{margin-bottom:20px}.users-heading h1{font-size:clamp(1.5rem,2vw,1.9rem);font-weight:750;letter-spacing:-.45px;margin:0 0 6px}.users-heading p{margin:0;color:var(--users-muted);font-size:.84rem}
.users-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:20px}.users-stat{background:#fff;border:1px solid var(--users-line);border-radius:15px;padding:18px 20px;box-shadow:var(--users-shadow)}
.users-stat span{font-size:.77rem;color:var(--users-muted);font-weight:500}.users-stat strong{display:block;margin-top:7px;font-size:1.53rem;letter-spacing:-.4px;font-weight:750}.users-stat small{display:block;margin-top:4px;font-size:.7rem;color:#a19b96}
.users-panel{background:#fff;border:1px solid var(--users-line);border-radius:17px;padding:18px 19px 12px;box-shadow:var(--users-shadow);min-width:0}.users-toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:15px}
.users-search{flex:1 1 230px;min-width:0;position:relative}.users-search svg{position:absolute;width:16px;height:16px;left:12px;top:50%;transform:translateY(-50%);color:#a09a93;pointer-events:none}
.users-search input,.users-filter{min-height:41px;border:1px solid var(--users-line);border-radius:11px;background:#fbfaf9;color:var(--users-text);font:inherit;font-size:.8rem;outline:none}
.users-search input{width:100%;padding:10px 12px 10px 36px}.users-filter{padding:9px 12px;min-width:108px}.users-search input:focus,.users-filter:focus{border-color:#e8b58b;box-shadow:0 0 0 3px rgba(232,181,139,.13)}
.users-table-scroll{max-height:min(66vh,730px);overflow-y:auto;overflow-x:hidden;scrollbar-width:thin;scrollbar-color:#ded6ce transparent}
.users-table{width:100%;table-layout:fixed;border-collapse:collapse;font-size:.8rem}.users-table th{text-align:left;padding:12px 10px;background:#fff;font-weight:650;color:#8a8681;font-size:.73rem;position:sticky;top:0;z-index:2;border-bottom:1px solid var(--users-line)}
.users-table td{padding:13px 10px;border-bottom:1px solid #f0eeeb;vertical-align:middle;min-width:0}.users-table tbody tr[data-user-row]{cursor:pointer;transition:background .15s}.users-table tbody tr[data-user-row]:hover,.users-table tbody tr[data-user-row]:focus-visible{background:#fff9f3;outline:none}.users-table tbody tr:last-child td{border-bottom:0}
.users-table th:nth-child(1){width:7%}.users-table th:nth-child(2){width:23%}.users-table th:nth-child(3){width:34%}.users-table th:nth-child(4){width:12%}.users-table th:nth-child(5){width:16%}.users-table th:nth-child(6){width:8%}
.users-name{font-size:.79rem;font-weight:650;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.users-sub{font-size:.71rem;color:#918c86;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:4px}.users-email{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#6c6965}.users-mobile-sub{display:none}
.users-pill{display:inline-flex;align-items:center;gap:5px;max-width:100%;padding:5px 9px;border-radius:8px;font-size:.69rem;font-weight:650;white-space:nowrap}.users-pill.seller{color:#ad741f;background:#fff4df}.users-pill.buyer{color:#5176a1;background:#ecf3fc}.users-pill.active{color:#2b8860;background:#eaf7ef}.users-pill.pending{color:#ac7b28;background:#fff5e1}.users-pill.disabled{color:#b75c55;background:#fff0ed}
.users-arrow{background:transparent;border:0;font-size:1.05rem;color:#aa9a89;cursor:pointer;min-width:30px;min-height:30px;border-radius:7px}.users-arrow:hover{background:#f7ede2;color:#bd782d}.users-empty{text-align:center!important;padding:27px 12px!important;color:#8e8881}.users-count{font-size:.73rem;color:#918c86;padding:13px 3px 2px}
.users-alert{background:#fff1ef;border:1px solid #f2d3cc;color:#994d41;border-radius:10px;padding:11px 13px;font-size:.79rem;margin-bottom:14px}
.users-modal[hidden]{display:none!important}.users-modal{position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;padding:16px}.users-backdrop{position:absolute;inset:0;background:rgba(37,35,33,.42);backdrop-filter:blur(3px)}
.users-dialog{position:relative;width:min(590px,100%);max-height:min(92dvh,790px);overflow-y:auto;background:#fff;border:1px solid #eee8e1;border-radius:19px;box-shadow:0 22px 70px rgba(32,28,22,.17);padding:22px 23px}
.users-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:18px}.users-modal-head h2{font-size:1.1rem;font-weight:750;margin:0 0 5px}.users-modal-head p{font-size:.75rem;color:#92908b;margin:0}.users-icon-close{border:1px solid var(--users-line);border-radius:10px;background:#fff;color:#706b65;cursor:pointer;width:32px;height:32px;font-size:1.1rem}
.users-form-section{border-top:1px solid var(--users-line);padding-top:16px;margin-top:16px}.users-form-section h3{font-size:.82rem;margin:0 0 12px;color:#625e59;font-weight:750}.users-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.users-field{display:flex;flex-direction:column;gap:6px;min-width:0}.users-field.wide{grid-column:1/-1}.users-field label{font-size:.71rem;color:#76716b;font-weight:650}.users-field input,.users-field select{min-height:40px;width:100%;border:1px solid #e5e1db;border-radius:9px;padding:9px 11px;font:inherit;font-size:.81rem;color:#3c3834;background:#fff;outline:none}
.users-field input:focus,.users-field select:focus{border-color:#da9b6c;box-shadow:0 0 0 3px rgba(218,155,108,.11)}.users-field input[readonly]{background:#f8f7f5;color:#89847f;cursor:not-allowed}.users-help{font-size:.68rem;color:#9d958e;line-height:1.5}.users-status-warning{background:#fff7ec;border:1px solid #f3e4ce;padding:11px;border-radius:9px;color:#926b37;font-size:.73rem;line-height:1.45;margin-top:12px}
.users-form-message{margin-top:12px;padding:10px 12px;border:1px solid #ebc8c6;background:#fff4f2;color:#a44d45;font-size:.75rem;border-radius:9px}.users-form-message[hidden]{display:none}.users-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;margin-top:18px}.users-btn{border-radius:10px;padding:10px 16px;border:1px solid var(--users-line);font:inherit;font-size:.78rem;font-weight:700;cursor:pointer;background:#fff;color:#605b55}.users-btn.primary{background:#e9944b;border-color:#e9944b;color:#fff}.users-btn.primary:hover{background:#d9843a}.users-btn:disabled{opacity:.6;cursor:wait}
body.users-modal-open{overflow:hidden}
@media(max-width:1000px){.content{padding:24px 20px 30px}.users-table th:nth-child(1),.users-table td:nth-child(1){display:none}.users-table th:nth-child(2){width:28%}.users-table th:nth-child(3){width:36%}.users-table th:nth-child(4){width:14%}.users-table th:nth-child(5){width:17%}.users-table th:nth-child(6){width:5%}}
@media(max-width:760px){.content{padding:20px 14px 28px}.users-stats{grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.users-stat{padding:15px 13px;border-radius:13px}.users-stat:first-child{grid-column:1/-1}.users-stat strong{font-size:1.35rem}.users-panel{padding:13px 11px 10px}.users-toolbar{gap:8px}.users-search{flex-basis:100%}.users-filter{flex:1 1 0;min-width:0}.users-table-scroll{max-height:60vh}.users-table th:nth-child(3),.users-table td:nth-child(3),.users-table th:nth-child(4),.users-table td:nth-child(4){display:none}.users-table th:nth-child(2){width:59%}.users-table th:nth-child(5){width:31%}.users-table th:nth-child(6){width:10%}.users-table td,.users-table th{padding:11px 5px}.users-name{font-size:.78rem}.users-mobile-sub{display:block;font-size:.67rem;margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#89817a}.users-pill{font-size:.65rem;padding:5px 6px}.users-dialog{padding:18px 16px;max-height:90dvh}}
@media(max-width:390px){.users-fields{grid-template-columns:1fr}.users-table th:nth-child(2){width:54%}.users-table th:nth-child(5){width:35%}.users-table th:nth-child(6){width:11%}.users-heading h1{font-size:1.5rem}.users-arrow{min-width:24px}}
@media(prefers-reduced-motion:reduce){*,*:before,*:after{scroll-behavior:auto!important;transition:none!important}}

/* LokalCraft secure Users theme */
:root{--users-bg:#fff8ed;--users-white:#fffefc;--users-text:#342d27;--users-muted:#80756b;--users-line:#eae0d7;--users-orange:#e98b40;--users-shadow:0 8px 22px rgba(67,44,22,.045)}
body,.page{background:var(--users-bg)}
.content{padding:28px 26px 42px}
.users-head h1{color:#382c22;font-size:clamp(1.6rem,3.3vw,2.1rem);font-weight:800;letter-spacing:-.04em}
.users-summary-card,.users-panel{background:#fffefc;border-color:#eae0d7;border-radius:18px;box-shadow:var(--users-shadow)}
.users-toolbar input,.users-toolbar select,.users-field input,.users-field select{border-color:#e5d9cf;background:#fffefc}
.users-toolbar input:focus,.users-toolbar select:focus,.users-field select:focus{border-color:#d99a63;box-shadow:0 0 0 3px rgba(201,109,32,.12)}
.users-table th{background:#fffaf5;color:#75685e}
.users-table td{border-bottom-color:#f0e7de}
.users-table tbody tr:hover{background:#fffaf5}
.users-pill.buyer{color:#7b5a40;background:#fff3e8;border-color:#efdcc9}
.users-pill.seller,.users-pill.pending{color:#a55325;background:#fff1e4;border-color:#f0d9be}
.users-pill.active{color:#278553;background:#effaf3;border-color:#cfe8d9}
.users-pill.disabled{color:#c94141;background:#fff1f1;border-color:#efcaca}
.users-dialog{background:#fffefc;border:1px solid #eae0d7;border-radius:20px;box-shadow:0 24px 72px rgba(51,34,21,.20)}
.users-field input[readonly]{background:#f7f0e9;color:#69584b;cursor:default}
.users-btn.primary{background:#a55325;border-color:#a55325;color:#fff8ed}
.users-btn.primary:hover{background:#c2692e;border-color:#c2692e}
</style>
</head>
<body>
<div class="page">
<?php include __DIR__ . '/admin_nav.php'; ?>
<main class="content">
<header class="users-heading"><h1>Users</h1></header>
<?php if ($errorMessage !== ''): ?><div class="users-alert"><?php echo usersEsc($errorMessage); ?></div><?php endif; ?>
<section class="users-stats" aria-label="Account totals">
<div class="users-stat"><span>Total Users</span><strong id="usersTotal"><?php echo number_format($userCount); ?></strong><small>Buyers and sellers only</small></div>
<div class="users-stat"><span>Buyers</span><strong id="usersBuyers"><?php echo number_format($buyerCount); ?></strong><small>Buyer accounts</small></div>
<div class="users-stat"><span>Sellers</span><strong id="usersSellers"><?php echo number_format($sellerCount); ?></strong><small>Seller accounts</small></div>
</section>
<section class="users-panel" aria-label="User accounts">
<div class="users-toolbar">
<div class="users-search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg><input id="usersSearch" type="search" placeholder="Search users by name, email, or ID" aria-label="Search users" autocomplete="off"></div>
<select id="usersRoleFilter" class="users-filter" aria-label="Filter by role"><option value="all">All Roles</option><option value="buyer">Buyers</option><option value="seller">Sellers</option></select>
<select id="usersStatusFilter" class="users-filter" aria-label="Filter by account status"><option value="all">All Statuses</option><option value="active">Active</option><option value="pending">Pending</option><option value="disabled">Disabled</option></select>
</div>
<div class="users-table-scroll">
<table class="users-table"><thead><tr><th scope="col">ID</th><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col" aria-label="Open details"></th></tr></thead><tbody id="usersTbody">
<?php foreach ($users as $user):
    $json = json_encode(['id'=>(int)$user['id'], 'firstname'=>$user['firstname'], 'middlename'=>$user['middlename'], 'lastname'=>$user['lastname'], 'email'=>$user['email'], 'phone'=>$user['phone'] ?? '', 'role'=>$user['role'], 'status'=>$user['account_status'], 'application'=>$user['application_status'] ?? 'None', 'joined'=>$user['joined']], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    $status = $user['account_status'];
    $validStatus = in_array($status, ['active','pending','disabled'], true) ? $status : 'pending';
?>
<tr data-user-row data-record="<?php echo usersEsc($json); ?>" data-role="<?php echo usersEsc(strtolower($user['role'])); ?>" data-status="<?php echo usersEsc($validStatus); ?>" tabindex="0" aria-label="Open account for <?php echo usersEsc($user['full_name']); ?>">
<td><?php echo (int)$user['id']; ?></td>
<td><div class="users-name" data-name><?php echo usersEsc($user['full_name']); ?></div><div class="users-mobile-sub" data-mobile-info><?php echo usersEsc($user['role']); ?> · <?php echo usersEsc($user['email']); ?></div></td>
<td><span class="users-email" data-email title="<?php echo usersEsc($user['email']); ?>"><?php echo usersEsc($user['email']); ?></span></td>
<td><span class="users-pill <?php echo strtolower($user['role']); ?>"><?php echo usersEsc($user['role']); ?></span></td>
<td><span class="users-pill <?php echo usersEsc($validStatus); ?>" data-status-pill><?php echo usersEsc(ucfirst($status)); ?></span></td>
<td><button type="button" class="users-arrow" aria-label="Edit <?php echo usersEsc($user['full_name']); ?>">›</button></td>
</tr>
<?php endforeach; ?>
<tr id="usersEmptyRow" <?php echo $userCount > 0 ? 'hidden' : ''; ?>><td colspan="6" class="users-empty"><?php echo $errorMessage !== '' ? 'Unable to load accounts.' : 'No users found.'; ?></td></tr>
</tbody></table>
</div>
<div class="users-count"><span id="usersVisibleCount"><?php echo $userCount; ?></span> account(s) displayed<?php if (!$hasCreatedAt): ?> · Joined date is not stored in the current users table.<?php endif; ?></div>
</section>
</main></div>
<div class="users-modal" id="usersModal" hidden aria-hidden="true">
<div class="users-backdrop" data-close-modal></div>
<div class="users-dialog" role="dialog" aria-modal="true" aria-labelledby="usersModalTitle">
<div class="users-modal-head"><div><h2 id="usersModalTitle">User Account Details</h2><p>Review account information and manage access status.</p></div><button type="button" class="users-icon-close" data-close-modal aria-label="Close">×</button></div>
<form id="usersEditForm" autocomplete="off">
<input type="hidden" name="action" value="update_user_status"><input type="hidden" name="csrf_token" value="<?php echo usersEsc($usersCsrf); ?>"><input type="hidden" name="user_id" id="usersEditId">
<div class="users-fields"><div class="users-field"><label for="usersReadonlyId">User ID</label><input id="usersReadonlyId" readonly></div><div class="users-field"><label for="usersReadonlyRole">Role (cannot be changed)</label><input id="usersReadonlyRole" readonly></div></div>
<div class="users-form-section"><h3>Personal information</h3><div class="users-fields">
<div class="users-field"><label for="usersFirst">First Name</label><input id="usersFirst" readonly></div>
<div class="users-field"><label for="usersMiddle">Middle Name</label><input id="usersMiddle" readonly></div>
<div class="users-field"><label for="usersLast">Last Name</label><input id="usersLast" readonly></div>
<div class="users-field"><label for="usersPhone">Phone Number</label><input id="usersPhone" type="tel" readonly></div>
<div class="users-field wide"><label for="usersEmail">Email Address</label><input id="usersEmail" type="email" readonly></div>
</div><div class="users-help">Personal details are read-only in Admin. Users should update their own profile through the verified account flow.</div></div>
<div class="users-form-section"><h3>Account and access</h3><div class="users-fields">
<div class="users-field"><label for="usersStatus">Account Status</label><select id="usersStatus" name="account_status" required><option value="active">Active</option><option value="pending">Pending</option><option value="disabled">Disabled</option></select></div>
<div class="users-field"><label for="usersApplication">Seller Application</label><input id="usersApplication" readonly></div>
<div class="users-field wide"><label for="usersJoined">Date Joined</label><input id="usersJoined" readonly></div>
</div><div class="users-status-warning" id="usersStatusNote">Disabling an account prevents access on pages protected by LokalCraft authentication checks.</div></div>
<div class="users-form-message" id="usersFormError" role="alert" hidden></div>
<div class="users-actions"><button type="button" class="users-btn" data-close-modal>Cancel</button><button class="users-btn primary" type="submit" id="usersSaveButton">Save Account Status</button></div>
</form>
</div></div>
<script>
(() => {
    const rows = [...document.querySelectorAll('[data-user-row]')];
    const search = document.getElementById('usersSearch');
    const role = document.getElementById('usersRoleFilter');
    const status = document.getElementById('usersStatusFilter');
    const empty = document.getElementById('usersEmptyRow');
    const visible = document.getElementById('usersVisibleCount');
    const modal = document.getElementById('usersModal');
    const form = document.getElementById('usersEditForm');
    const error = document.getElementById('usersFormError');
    const save = document.getElementById('usersSaveButton');
    let openedRow = null;
    let saving = false;
    const fields = { id: 'usersReadonlyId', role: 'usersReadonlyRole', firstname: 'usersFirst', middlename: 'usersMiddle', lastname: 'usersLast', email: 'usersEmail', phone: 'usersPhone', status: 'usersStatus', application: 'usersApplication', joined: 'usersJoined' };
    const getRecord = row => JSON.parse(row.dataset.record);
    function filterRows() {
        const keyword = search.value.trim().toLocaleLowerCase();
        let count = 0;
        rows.forEach(row => {
            const user = getRecord(row);
            const haystack = [user.id, user.firstname, user.middlename, user.lastname, user.email, user.phone, user.role, user.status].join(' ').toLocaleLowerCase();
            const ok = haystack.includes(keyword) && (role.value === 'all' || row.dataset.role === role.value) && (status.value === 'all' || row.dataset.status === status.value);
            row.hidden = !ok;
            if (ok) count++;
        });
        empty.hidden = count !== 0;
        empty.querySelector('td').textContent = rows.length ? 'No users match the selected filters.' : 'No users found.';
        visible.textContent = String(count);
    }
    function openModal(row) {
        if (saving) return;
        const record = getRecord(row);
        openedRow = row;
        form.reset();
        document.getElementById('usersEditId').value = record.id;
        Object.entries(fields).forEach(([key, id]) => { document.getElementById(id).value = record[key] ?? ''; });
        error.hidden = true;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('users-modal-open');
        document.getElementById('usersFirst').focus();
    }
    function closeModal() {
        if (saving) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('users-modal-open');
        openedRow?.focus();
        openedRow = null;
    }
    rows.forEach(row => {
        row.addEventListener('click', () => openModal(row));
        row.addEventListener('keydown', event => {
            if (event.target !== row) return;
            if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); openModal(row); }
        });
    });
    document.querySelectorAll('[data-close-modal]').forEach(el => el.addEventListener('click', closeModal));
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && !modal.hidden) closeModal(); });
    [search, role, status].forEach(el => el.addEventListener(el === search ? 'input' : 'change', filterRows));
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (saving || !openedRow) return;
        saving = true;
        save.disabled = true;
        save.textContent = 'Saving...';
        error.hidden = true;
        try {
            const response = await fetch(window.location.pathname, { method: 'POST', credentials: 'same-origin', body: new FormData(form), headers: { 'Accept': 'application/json' }, cache: 'no-store' });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Unable to save changes.');
            const updated = result.user;
            const record = getRecord(openedRow);
            Object.assign(record, updated);
            openedRow.dataset.record = JSON.stringify(record);
            openedRow.dataset.status = updated.status;
            const pill = openedRow.querySelector('[data-status-pill]');
            pill.className = 'users-pill ' + updated.status;
            pill.textContent = updated.status.charAt(0).toUpperCase() + updated.status.slice(1);
            saving = false;
            closeModal();
            filterRows();
        } catch (err) {
            error.textContent = err.message || 'Unable to save changes.';
            error.hidden = false;
        } finally {
            saving = false;
            save.disabled = false;
            save.textContent = 'Save Account Status';
        }
    });
    filterRows();
})();
</script>
</body>
</html>
