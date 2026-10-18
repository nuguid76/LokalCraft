<?php
session_start();
require_once __DIR__ . '/../config/database.php';

$activePage = 'dashboard';

function dashboardEsc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dashboardMoney($amount): string
{
    return '₱' . number_format((float)$amount, 2);
}

function dashboardWhen($value): string
{
    if (!$value) return 'Date unavailable';
    $timestamp = strtotime((string)$value);
    return $timestamp ? date('M j, Y · g:i A', $timestamp) : 'Date unavailable';
}

function dashboardCount(mysqli $conn, string $sql): int
{
    $result = $conn->query($sql);
    if (!$result) throw new RuntimeException('Unable to load dashboard metric.');
    $row = $result->fetch_assoc();
    $result->free();
    return (int)($row['total'] ?? 0);
}

function loadDashboard(mysqli $conn): array
{
    $stats = [
        'users' => dashboardCount($conn, 'SELECT COUNT(*) AS total FROM users WHERE COALESCE(adminOrNot, 0) <> 1'),

        'sellers' => dashboardCount($conn, "
            SELECT COUNT(*) AS total FROM users u
            WHERE u.sellerOrNot = 1
              AND COALESCE(u.adminOrNot, 0) <> 1
              AND LOWER(COALESCE(u.account_status, 'active')) = 'active'
              AND EXISTS (
                  SELECT 1 FROM seller_applications sa
                  WHERE sa.user_id = u.id
                    AND LOWER(sa.status) = 'approved'
              )
        "),

        'orders' => dashboardCount($conn, 'SELECT COUNT(*) AS total FROM orders'),

        'pending_approvals' => dashboardCount(
            $conn,
            "SELECT COUNT(*) AS total FROM seller_applications WHERE LOWER(status) = 'pending'"
        ),
    ];

    $paidResult = $conn->query("
        SELECT COALESCE(SUM(p.amount), 0) AS total
        FROM payments p
        INNER JOIN (
            SELECT order_id, MAX(payment_id) AS latest_payment_id
            FROM payments
            GROUP BY order_id
        ) latest ON latest.latest_payment_id = p.payment_id
        INNER JOIN orders o ON o.order_id = p.order_id
        WHERE LOWER(p.payment_status) = 'paid'
          AND LOWER(TRIM(o.order_status)) NOT IN ('cancelled', 'canceled')
    ");

    if (!$paidResult) {
        throw new RuntimeException('Unable to load payment totals.');
    }

    $stats['paid_value'] = (float)(($paidResult->fetch_assoc())['total'] ?? 0);
    $paidResult->free();

    $pendingResult = $conn->query("
        SELECT
            sa.application_id,
            sa.submitted_at,

            CONCAT_WS(
                ' ',
                NULLIF(TRIM(u.firstname), ''),
                NULLIF(TRIM(u.lastname), '')
            ) AS applicant,

            (
                SELECT b.businessname
                FROM businessinfo b
                WHERE b.user_id = sa.user_id
                ORDER BY b.business_id DESC
                LIMIT 1
            ) AS business_name,

            (
                SELECT b.craftcategory
                FROM businessinfo b
                WHERE b.user_id = sa.user_id
                ORDER BY b.business_id DESC
                LIMIT 1
            ) AS category

        FROM seller_applications sa
        INNER JOIN users u ON u.id = sa.user_id
        WHERE LOWER(sa.status) = 'pending'
        ORDER BY sa.submitted_at DESC, sa.application_id DESC
        LIMIT 15
    ");

    if (!$pendingResult) {
        throw new RuntimeException('Unable to load seller applications.');
    }

    $approvals = [];

    while ($row = $pendingResult->fetch_assoc()) {
        $applicant = trim((string)($row['applicant'] ?? ''));
        $business = trim((string)($row['business_name'] ?? ''));
        $category = trim((string)($row['category'] ?? ''));

        $approvals[] = [
            'name' => $business !== ''
                ? $business
                : ($applicant !== '' ? $applicant : 'Seller application'),

            'detail' => implode(' · ', array_filter([$applicant, $category])),

            'when' => dashboardWhen($row['submitted_at'] ?? null),
        ];
    }

    $pendingResult->free();

    $activityResult = $conn->query("
        SELECT event_type, event_at
        FROM (
            SELECT
                'order' AS event_type,
                ordered_at AS event_at
            FROM orders

            UNION ALL

            SELECT
                'payment',
                paid_at
            FROM payments
            WHERE LOWER(payment_status) = 'paid'
              AND paid_at IS NOT NULL

            UNION ALL

            SELECT
                'application',
                submitted_at
            FROM seller_applications

            UNION ALL

            SELECT
                'approval',
                reviewed_at
            FROM seller_applications
            WHERE LOWER(status) = 'approved'
              AND reviewed_at IS NOT NULL

            UNION ALL

            SELECT
                'rejection',
                reviewed_at
            FROM seller_applications
            WHERE LOWER(status) = 'rejected'
              AND reviewed_at IS NOT NULL
        ) events

        WHERE event_at IS NOT NULL
        ORDER BY event_at DESC
        LIMIT 15
    ");

    if (!$activityResult) {
        throw new RuntimeException('Unable to load recent activity.');
    }

    $eventLabels = [
        'order' => 'New order placed',
        'payment' => 'Payment marked paid',
        'application' => 'Seller application submitted',
        'approval' => 'Seller application approved',
        'rejection' => 'Seller application rejected',
    ];

    $activity = [];

    while ($row = $activityResult->fetch_assoc()) {
        $activity[] = [
            'title' => $eventLabels[(string)$row['event_type']] ?? 'Platform activity',
            'when' => dashboardWhen($row['event_at'] ?? null),
        ];
    }

    $activityResult->free();

    return [
        'stats' => $stats,
        'approvals' => $approvals,
        'activity' => $activity,
        'updated_at' => date('g:i A'),
    ];
}

$adminId = 0;

foreach (['user_id', 'admin_id', 'logged_id', 'id'] as $sessionKey) {
    $value = $_SESSION[$sessionKey] ?? null;

    if (filter_var($value, FILTER_VALIDATE_INT) && (int)$value > 0) {
        $adminId = (int)$value;
        break;
    }
}

if ($adminId <= 0) {
    header('Location: ../login.php');
    exit;
}

try {
    $adminStmt = $conn->prepare("
        SELECT firstname, lastname, adminOrNot, account_status
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    if (!$adminStmt) {
        throw new RuntimeException('Admin access check unavailable.');
    }

    $adminStmt->bind_param('i', $adminId);
    $adminStmt->execute();

    $admin = $adminStmt->get_result()->fetch_assoc();
    $adminStmt->close();

    if (
        !$admin ||
        (int)$admin['adminOrNot'] !== 1 ||
        strtolower((string)$admin['account_status']) === 'disabled'
    ) {
        http_response_code(403);
        exit('Access denied.');
    }

} catch (Throwable $e) {
    error_log('LokalCraft dashboard authentication error: ' . $e->getMessage());

    http_response_code(503);
    exit('Dashboard temporarily unavailable.');
}

$userName = trim(
    (string)$admin['firstname'] . ' ' . (string)$admin['lastname']
) ?: 'Admin';

header('Cache-Control: private, no-store, max-age=0');

$data = [
    'stats' => [
        'users' => null,
        'sellers' => null,
        'orders' => null,
        'paid_value' => null,
        'pending_approvals' => null
    ],
    'approvals' => [],
    'activity' => [],
    'updated_at' => '—'
];

$errorMessage = '';

try {
    $data = loadDashboard($conn);
} catch (Throwable $e) {
    error_log('LokalCraft dashboard query error: ' . $e->getMessage());

    $errorMessage = 'Dashboard data is temporarily unavailable. Please refresh or check the server log.';
}

if (isset($_GET['dashboard_data'])) {
    header('Content-Type: application/json; charset=utf-8');

    http_response_code($errorMessage === '' ? 200 : 503);

    echo json_encode(
        $errorMessage === ''
            ? ['success' => true, 'data' => $data]
            : ['success' => false, 'message' => $errorMessage],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />

    <title>LokalCraft | Admin Dashboard</title>

    <style>
        :root {
            --dash-bg: #faf9f7;
            --dash-card: #fff;
            --dash-text: #3d3935;
            --dash-muted: #89837e;
            --dash-line: #eee9e4;
            --dash-orange: #e99048;
            --dash-orange-soft: #fff1e3;
            --dash-shadow: 0 5px 22px rgba(46, 35, 20, .035);
        }
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font-family: "Segoe UI", Arial, sans-serif; color: var(--dash-text); background: var(--dash-bg); }
        .page { display: flex; min-height: 100vh; background: var(--dash-bg); }
        .content { flex: 1; min-width: 0; max-width: 100%; padding: 30px 30px 50px; }
        .dashboard-header { margin-bottom: 20px; }
        .dashboard-header h1 { margin: 0 0 5px; color: #3c3834; font-size: clamp(1.45rem, 2.2vw, 1.9rem); font-weight: 750; letter-spacing: -.5px; line-height: 1.2; }
        .dashboard-header p { margin: 0; color: var(--dash-muted); font-size: .84rem; line-height: 1.5; }
        .dashboard-toolbar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 19px; }
        .sync-label { color: var(--dash-muted); font-size: .74rem; line-height: 1.5; }
        .refresh-button { display: inline-flex; align-items: center; justify-content: center; min-height: 35px; border: 1px solid #eadfd4; border-radius: 9px; background: #fff; color: #986c48; padding: 7px 12px; font: inherit; font-size: .75rem; font-weight: 650; cursor: pointer; transition: background .16s ease, border-color .16s ease; }
        .refresh-button:hover { background: #fff4e9; border-color: #ebd0b4; }
        .refresh-button:disabled { opacity: .55; cursor: wait; }
        .refresh-button:focus-visible { outline: 3px solid #e9ad74; outline-offset: 2px; }
        .error-box { padding: 12px 14px; margin-bottom: 18px; border: 1px solid #f2d2ce; border-radius: 11px; background: #fff5f4; color: #a34e47; font-size: .8rem; line-height: 1.5; }
        .error-box[hidden] { display: none; }
        .stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 22px; }
        .stat-card { min-width: 0; min-height: 130px; display: flex; justify-content: space-between; align-items: flex-start; gap: 9px; padding: 19px; border: 1px solid var(--dash-line); border-radius: 15px; background: var(--dash-card); box-shadow: var(--dash-shadow); }
        .stat-card > div:first-child { flex: 1; min-width: 0; }
        .stat-card .label { margin-bottom: 8px; color: #89837e; font-size: .8rem; line-height: 1.35; font-weight: 550; }
        .stat-card .value { margin-bottom: 8px; color: #46403a; font-size: clamp(1.35rem, 2vw, 1.85rem); font-weight: 750; line-height: 1.18; letter-spacing: -.6px; overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }
        .stat-note { color: #aaa19a; font-size: .68rem; line-height: 1.42; }
        .stat-icon { width: 38px; height: 38px; flex: 0 0 38px; display: grid; place-items: center; border-radius: 11px; background: #fff0e4; color: #d78a45; }
        .stat-icon svg { width: 19px; height: 19px; stroke: currentColor; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; fill: none; }
        .stat-card.green .stat-icon { background: #eaf7ee; color: #5eaf80; }
        .stat-card.yellow .stat-icon { background: #fff6e2; color: #cda24e; }
        .stat-card.purple .stat-icon { background: #f2eeff; color: #9e8ad2; }
        .bottom-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: start; gap: 18px; }
        .panel { min-width: 0; padding: 18px 20px 10px; border: 1px solid var(--dash-line); border-radius: 15px; background: var(--dash-card); box-shadow: var(--dash-shadow); }
        .panel-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin: 0 0 9px; }
        .panel-title { margin: 0; color: #48413c; font-size: .99rem; font-weight: 700; letter-spacing: -.15px; line-height: 1.35; }
        .panel-note { margin-top: 4px; color: #aaa19a; font-size: .69rem; line-height: 1.4; }
        .count-badge { flex: 0 0 auto; padding: 5px 9px; border-radius: 999px; background: #fff0e3; color: #ac7548; font-size: .74rem; font-weight: 700; font-variant-numeric: tabular-nums; }
        .approval-list, .activity-list { list-style: none; margin: 0; padding: 0; }
        .approval-item, .activity-item { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; padding: 12px 0; border-top: 1px solid #f1eeeb; }
        .approval-item:first-child, .activity-item:first-child { border-top: none; }
        .item-main { flex: 1; min-width: 0; }
        .item-name, .event-title { color: #4a4540; font-size: .81rem; font-weight: 650; line-height: 1.45; overflow-wrap: anywhere; }
        .item-sub, .event-time { margin-top: 3px; color: #9a918b; font-size: .72rem; line-height: 1.45; overflow-wrap: anywhere; }
        .status-pill { flex-shrink: 0; margin-top: 1px; padding: 5px 9px; border-radius: 999px; background: #fff6e8; color: #a97d45; font-size: .68rem; font-weight: 650; white-space: nowrap; }
        .activity-item { justify-content: flex-start; align-items: flex-start; }
        .activity-dot { width: 7px; height: 7px; margin: 6px 4px 0 2px; flex-shrink: 0; border-radius: 50%; background: #eab17b; }
        .empty-list { padding: 21px 0; color: #958d87; font-size: .8rem; }
        .dashboard-footnote { margin: 17px 1px 0; color: #9b938d; font-size: .7rem; line-height: 1.6; }
        @media (max-width: 1170px) {
            .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .stat-card { min-height: 119px; }
        }
        @media (max-width: 950px) {
            .bottom-grid { grid-template-columns: minmax(0, 1fr); }
            .content { padding: 24px 22px 45px; }
        }
        @media (max-width: 840px) {
            .content { padding: 22px 17px 80px; }
            .dashboard-header h1 { font-size: 1.55rem; }
        }
        @media (max-width: 560px) {
            .content { padding-left: 13px; padding-right: 13px; }
            .dashboard-toolbar { align-items: flex-start; }
            .sync-label { flex: 1 1 175px; font-size: .69rem; }
            .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-bottom: 16px; }
            .stat-card { padding: 12px; border-radius: 12px; min-height: 133px; flex-direction: column-reverse; align-items: flex-start; justify-content: flex-end; gap: 8px; }
            .stat-card > div:first-child { width: 100%; flex: 1; }
            .stat-icon { width: 28px; height: 28px; flex-basis: 28px; border-radius: 8px; }
            .stat-icon svg { width: 16px; height: 16px; }
            .stat-card .label { margin-bottom: 5px; font-size: .71rem; }
            .stat-card .value { font-size: clamp(1.08rem, 4.6vw, 1.5rem); margin-bottom: 5px; }
            .stat-note { font-size: .63rem; }
            .panel { padding: 15px 14px 9px; border-radius: 13px; }
            .panel-title { font-size: .92rem; }
            .item-name, .event-title { font-size: .78rem; }
            .item-sub, .event-time { font-size: .68rem; }
            .status-pill { font-size: .63rem; padding: 4px 7px; }
        }
        @media (max-width: 355px) {
            .content { padding-left: 10px; padding-right: 10px; }
            .stats { gap: 7px; }
            .stat-card { padding: 10px; }
            .stat-card .value { font-size: 1rem; }
        }
    </style>
</head>

<body>

<div class="page">

    <?php include 'admin_nav.php'; ?>

    <main class="content">

        <div class="dashboard-header">
            <h1>Admin Dashboard</h1>
            <p>Overview of LokalCraft platform</p>
        </div>

        <div class="dashboard-toolbar">

            <span class="sync-label" id="lastUpdated">
                Last updated:
                <?php echo dashboardEsc($data['updated_at']); ?>
                · Automatically refreshes every 30 seconds
            </span>

            <button
                type="button"
                class="refresh-button"
                id="refreshDashboard"
            >
                ↻ Refresh data
            </button>

        </div>

        <div
            class="error-box"
            id="dashboardError"
            <?php echo $errorMessage === '' ? 'hidden' : ''; ?>
        >
            <?php echo dashboardEsc($errorMessage); ?>
        </div>

        <section class="stats" aria-label="Platform statistics">

            <div class="stat-card blue">
                <div>
                    <div class="label">Total Users</div>

                    <div class="value" id="statUsers">
                        <?php
                        echo $data['stats']['users'] === null
                            ? '—'
                            : number_format($data['stats']['users']);
                        ?>
                    </div>

                    <div class="stat-note">Buyers and sellers only</div>
                </div>

                <div class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.2"/><path d="M3 20c.5-3.2 2.5-5 6-5s5.5 1.8 6 5M16 5.5a3 3 0 0 1 0 5.7M18 15.5c1.5.8 2.4 2.1 2.8 4.5"/></svg></div>
            </div>

            <div class="stat-card green">
                <div>
                    <div class="label">Active Sellers</div>

                    <div class="value" id="statSellers">
                        <?php
                        echo $data['stats']['sellers'] === null
                            ? '—'
                            : number_format($data['stats']['sellers']);
                        ?>
                    </div>

                    <div class="stat-note">
                        Approved and active accounts
                    </div>
                </div>

                <div class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 9h16l-1 12H5L4 9Z"/><path d="M8 9V7a4 4 0 0 1 8 0v2"/></svg></div>
            </div>

            <div class="stat-card yellow">
                <div>
                    <div class="label">Total Orders</div>

                    <div class="value" id="statOrders">
                        <?php
                        echo $data['stats']['orders'] === null
                            ? '—'
                            : number_format($data['stats']['orders']);
                        ?>
                    </div>

                    <div class="stat-note">All order records</div>
                </div>

                <div class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 4h2l2.2 11h11.5l2-8H6"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/></svg></div>
            </div>

            <div class="stat-card purple">
                <div>
                    <div class="label">Paid Order Value</div>

                    <div class="value" id="statPaidValue">
                        <?php
                        echo $data['stats']['paid_value'] === null
                            ? '—'
                            : dashboardMoney($data['stats']['paid_value']);
                        ?>
                    </div>

                    <div class="stat-note">
                        Latest paid payments, excluding cancelled orders
                    </div>
                </div>

                <div class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 19h16M5 16l5-5 4 3 6-7M15 7h5v5"/></svg></div>
            </div>

        </section>

        <div class="bottom-grid">

            <section class="panel" aria-labelledby="approvalsTitle">

                <div class="panel-head">

                    <div>
                        <h2 class="panel-title" id="approvalsTitle">Pending Seller Approvals</h2>
                        <p class="panel-note">Showing latest 15 applications</p>
                    </div>

                    <span class="count-badge" id="pendingCount" title="Total pending seller applications">
                        <?php
                        echo $data['stats']['pending_approvals'] === null
                            ? '—'
                            : (int)$data['stats']['pending_approvals'];
                        ?>
                    </span>

                </div>

                <ul class="approval-list" id="approvalList">

                    <?php if (!$data['approvals']): ?>

                        <li class="empty-list">
                            <?php
                            echo $errorMessage === ''
                                ? 'No pending applications.'
                                : 'Unable to load applications.';
                            ?>
                        </li>

                    <?php else: ?>

                        <?php foreach ($data['approvals'] as $application): ?>

                            <li class="approval-item">

                                <div class="item-main">

                                    <div class="item-name">
                                        <?php echo dashboardEsc($application['name']); ?>
                                    </div>

                                    <div class="item-sub">
                                        <?php echo dashboardEsc($application['detail']); ?>
                                    </div>

                                    <div class="item-sub">
                                        <?php echo dashboardEsc($application['when']); ?>
                                    </div>

                                </div>

                                <span class="status-pill">Pending</span>

                            </li>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </ul>

            </section>

            <section class="panel" aria-labelledby="activityTitle">

                <div class="panel-head">
                    <div>
                        <h2 class="panel-title" id="activityTitle">Recent Activity</h2>
                        <p class="panel-note">Showing latest 15 events</p>
                    </div>
                </div>

                <ul class="activity-list" id="activityList">

                    <?php if (!$data['activity']): ?>

                        <li class="empty-list">
                            <?php
                            echo $errorMessage === ''
                                ? 'No recent recorded activity.'
                                : 'Unable to load activity.';
                            ?>
                        </li>

                    <?php else: ?>

                        <?php foreach ($data['activity'] as $event): ?>

                            <li class="activity-item">

                                <span
                                    class="activity-dot"
                                    aria-hidden="true"
                                ></span>

                                <div class="item-main">

                                    <div class="event-title">
                                        <?php echo dashboardEsc($event['title']); ?>
                                    </div>

                                    <div class="event-time">
                                        <?php echo dashboardEsc($event['when']); ?>
                                    </div>

                                </div>

                            </li>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </ul>

            </section>

        </div>

        <p class="dashboard-footnote">
            Paid Order Value is the sum of the most recent paid payment
            for each non-cancelled order. It is not LokalCraft commission
            or platform revenue. Activity is based on recorded timestamps,
            not a full audit log.
        </p>

    </main>

</div>

<script>
(() => {
    const refreshButton = document.getElementById('refreshDashboard');
    const errorBox = document.getElementById('dashboardError');

    let inFlight = false;

    const numberFormat = new Intl.NumberFormat('en-PH');

    const moneyFormat = new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP'
    });

    function node(tag, className, value) {
        const element = document.createElement(tag);

        if (className) {
            element.className = className;
        }

        if (value !== undefined) {
            element.textContent = value;
        }

        return element;
    }

    function updateApprovals(items) {
        const list = document.getElementById('approvalList');

        list.replaceChildren();

        if (!items.length) {
            list.append(
                node('li', 'empty-list', 'No pending applications.')
            );
            return;
        }

        items.forEach(item => {
            const li = node('li', 'approval-item');
            const main = node('div', 'item-main');

            main.append(
                node('div', 'item-name', item.name),
                node('div', 'item-sub', item.detail),
                node('div', 'item-sub', item.when)
            );

            li.append(
                main,
                node('span', 'status-pill', 'Pending')
            );

            list.append(li);
        });
    }

    function updateActivity(items) {
        const list = document.getElementById('activityList');

        list.replaceChildren();

        if (!items.length) {
            list.append(
                node('li', 'empty-list', 'No recent recorded activity.')
            );
            return;
        }

        items.forEach(item => {
            const li = node('li', 'activity-item');

            const dot = node('span', 'activity-dot');
            dot.setAttribute('aria-hidden', 'true');

            const main = node('div', 'item-main');

            main.append(
                node('div', 'event-title', item.title),
                node('div', 'event-time', item.when)
            );

            li.append(dot, main);

            list.append(li);
        });
    }

    async function refreshDashboard() {
        if (inFlight || document.hidden) {
            return;
        }

        inFlight = true;
        refreshButton.disabled = true;

        try {
            const url = new URL(window.location.href);

            url.searchParams.set('dashboard_data', '1');

            const response = await fetch(url.toString(), {
                credentials: 'same-origin',
                cache: 'no-store'
            });

            if (response.status === 401 || response.status === 403) {
                window.location.href = '../login.php';
                return;
            }

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(
                    result.message || 'Could not refresh dashboard.'
                );
            }

            const data = result.data;

            document.getElementById('statUsers').textContent =
                numberFormat.format(data.stats.users);

            document.getElementById('statSellers').textContent =
                numberFormat.format(data.stats.sellers);

            document.getElementById('statOrders').textContent =
                numberFormat.format(data.stats.orders);

            document.getElementById('statPaidValue').textContent =
                moneyFormat.format(data.stats.paid_value);

            document.getElementById('pendingCount').textContent =
                numberFormat.format(data.stats.pending_approvals);

            updateApprovals(data.approvals);
            updateActivity(data.activity);

            document.getElementById('lastUpdated').textContent =
                'Last updated: ' +
                data.updated_at +
                ' · Automatically refreshes every 30 seconds';

            errorBox.hidden = true;

        } catch (error) {
            errorBox.textContent =
                error.message || 'Unable to update dashboard.';

            errorBox.hidden = false;

        } finally {
            inFlight = false;
            refreshButton.disabled = false;
        }
    }

    refreshButton.addEventListener('click', refreshDashboard);

    window.setInterval(refreshDashboard, 30000);
})();
</script>

</body>
</html>
