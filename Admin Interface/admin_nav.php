<?php
/**
 * LokalCraft Admin navigation
 * Include inside <div class="page"> on each admin page.
 * The including page is responsible for authenticating the admin.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$activePage = $activePage ?? 'dashboard';
$adminName = trim((string)($adminName ?? $userName ?? $_SESSION['admin_name'] ?? 'Admin User'));
if ($adminName === '') {
    $adminName = 'Admin User';
}

$navParts = preg_split('/\s+/u', $adminName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
$navFirst = $navParts[0] ?? 'A';
$navLast = count($navParts) > 1 ? $navParts[count($navParts) - 1] : '';
$initialFirst = strtoupper(substr($navFirst, 0, 1));
$initialLast = $navLast !== '' ? strtoupper(substr($navLast, 0, 1)) : '';
$adminInitials = $initialFirst . $initialLast;

if (empty($_SESSION['admin_logout_csrf'])) {
    $_SESSION['admin_logout_csrf'] = bin2hex(random_bytes(32));
}
$logoutCsrf = $_SESSION['admin_logout_csrf'];

$navItems = [
    ['id' => 'dashboard',        'label' => 'Dashboard',           'href' => 'dashboard.php',        'icon' => 'dashboard'],
    ['id' => 'users',            'label' => 'Users',               'href' => 'users.php',            'icon' => 'users'],
    ['id' => 'seller_approvals', 'label' => 'Seller Approvals',    'href' => 'seller_approvals.php', 'icon' => 'verified'],
    ['id' => 'product_listing',  'label' => 'Product Listing',    'href' => 'product_listing.php',  'icon' => 'package'],
    ['id' => 'monitoring',       'label' => 'Order Monitoring',   'href' => 'monitoring.php',       'icon' => 'orders'],
    ['id' => 'wallet',           'label' => 'Platform Wallet',    'href' => 'admin_wallet.php',     'icon' => 'wallet'],
    ['id' => 'reports',          'label' => 'Reports & Analytics','href' => 'report.php',          'icon' => 'reports'],
];

/** Purely decorative icon paths. */
function adminNavIcon(string $icon): string
{
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'users'     => '<circle cx="9" cy="8" r="3.5"/><path d="M3.5 19c.7-3.2 2.7-5 5.5-5s4.8 1.8 5.5 5"/><path d="M16 6.5a3 3 0 0 1 0 5.8"/><path d="M17 14.2c2 .5 3.2 1.9 3.7 4.1"/>',
        'verified'  => '<path d="M12 3 19 6v5c0 4.6-2.8 7.8-7 10-4.2-2.2-7-5.4-7-10V6l7-3Z"/><path d="m9 12 2 2 4-4"/>',
        'package'   => '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/><path d="m4.5 7.8 7.5 4.2 7.5-4.2"/><path d="M12 12v9"/>',
        'orders'    => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h5M8 16h4"/><circle cx="17" cy="16" r="2.5"/>',
        'wallet'    => '<path d="M4 7h14a3 3 0 0 1 3 3v8H6a2 2 0 0 1-2-2V7Z"/><path d="M4 7V5a2 2 0 0 1 2-2h11v4"/><path d="M16 12h5v4h-5a2 2 0 1 1 0-4Z"/>',
        'reports'   => '<path d="M4 20V11M10 20V5M16 20v-7M22 20H2"/>',
        'chevron'   => '<path d="m7 10 5 5 5-5"/>',
        'logout'    => '<path d="M10 17l5-5-5-5M15 12H3M13 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/>',
        'menu'      => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close'     => '<path d="M6 6l12 12M18 6 6 18"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
        . ($paths[$icon] ?? '') . '</svg>';
}

function adminNavEsc($str): string
{
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!-- Desktop uses the fixed sidebar; mobile uses this header + sliding sidebar. -->
<header class="admin-mobile-header" aria-label="Admin mobile header">
    <button class="admin-menu-toggle" id="adminMenuToggle" type="button"
            aria-label="Open admin navigation" aria-controls="adminSidebar" aria-expanded="false">
        <?php echo adminNavIcon('menu'); ?>
    </button>
    <div class="admin-mobile-brand">
        <span class="admin-brand-mark">L</span>
        <span class="admin-brand-text">LokalCraft<small>Admin Portal</small></span>
    </div>
    <div class="admin-mobile-profile admin-profile-holder" data-admin-profile-holder>
        <button class="admin-mobile-avatar admin-profile-trigger" type="button"
                data-admin-profile-trigger aria-expanded="false" aria-haspopup="true"
                aria-controls="adminMobileProfileMenu" aria-label="Open admin profile menu">
            <?php echo adminNavEsc($adminInitials); ?>
        </button>
        <div class="admin-profile-menu admin-mobile-profile-menu" id="adminMobileProfileMenu" data-admin-profile-menu hidden>
            <strong><?php echo adminNavEsc($adminName); ?></strong>
            <span class="admin-profile-menu-caption">Administrator</span>
            <form method="POST" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?php echo adminNavEsc($logoutCsrf); ?>">
                <button type="submit" class="admin-profile-logout"><?php echo adminNavIcon('logout'); ?> Sign out</button>
            </form>
        </div>
    </div>
</header>
<div class="admin-sidebar-overlay" id="adminSidebarOverlay" aria-hidden="true"></div>

<aside class="admin-sidebar" id="adminSidebar" aria-label="Admin navigation">
    <div class="admin-sidebar-top">
        <div class="admin-brand">
            <span class="admin-brand-mark">L</span>
            <span class="admin-brand-text">LokalCraft<small>Admin Portal</small></span>
        </div>
        <button class="admin-sidebar-close" id="adminSidebarClose" type="button" aria-label="Close admin navigation">
            <?php echo adminNavIcon('close'); ?>
        </button>
    </div>
    <nav class="admin-nav" aria-label="Admin pages">
        <?php foreach ($navItems as $item): ?>
            <?php $isActive = $activePage === $item['id']; ?>
            <a class="admin-nav-item<?php echo $isActive ? ' active' : ''; ?>"
               href="<?php echo adminNavEsc($item['href']); ?>"
               <?php echo $isActive ? 'aria-current="page"' : ''; ?>>
                <span class="admin-icon"><?php echo adminNavIcon($item['icon']); ?></span>
                <span class="admin-nav-label"><?php echo adminNavEsc($item['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="admin-sidebar-footer admin-profile-holder" data-admin-profile-holder>
        <button class="admin-profile-summary admin-profile-trigger" id="adminProfileButton"
                type="button" data-admin-profile-trigger aria-expanded="false" aria-haspopup="true"
                aria-controls="adminSidebarProfileMenu">
            <span class="admin-avatar"><?php echo adminNavEsc($adminInitials); ?></span>
            <span class="admin-profile-copy">
                <span class="admin-profile-name"><?php echo adminNavEsc($adminName); ?></span>
                <span class="admin-profile-role">Administrator</span>
            </span>
            <span class="admin-profile-chevron"><?php echo adminNavIcon('chevron'); ?></span>
        </button>
        <div class="admin-profile-menu" id="adminSidebarProfileMenu" data-admin-profile-menu hidden>
            <strong><?php echo adminNavEsc($adminName); ?></strong>
            <span class="admin-profile-menu-caption">Admin Account</span>
            <form method="POST" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?php echo adminNavEsc($logoutCsrf); ?>">
                <button type="submit" class="admin-profile-logout"><?php echo adminNavIcon('logout'); ?> Sign out</button>
            </form>
        </div>
    </div>
</aside>

<style>
:root {
    --admin-sidebar-width: 250px;
    --admin-mobile-header-height: 64px;
    --admin-nav-border: #e6e1da;
    --admin-nav-bg: #fbf9f7;
    --admin-nav-orange: #ea8a3a;
    --admin-nav-text: #383838;
}
.admin-sidebar, .admin-sidebar *, .admin-mobile-header, .admin-mobile-header * { box-sizing: border-box; }
.admin-sidebar button, .admin-mobile-header button { font: inherit; }
.admin-sidebar svg, .admin-mobile-header svg { width: 20px; height: 20px; flex: 0 0 auto; }
.admin-mobile-header { display: none; }
.admin-sidebar-overlay { display: none; }

/* Desktop: fixed sidebar, no separate top navigation */
.admin-sidebar {
    width: var(--admin-sidebar-width);
    height: 100dvh;
    position: fixed;
    inset: 0 auto 0 0;
    z-index: 1250;
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    padding: 20px 15px 17px;
    border-right: 1px solid var(--admin-nav-border);
    background: var(--admin-nav-bg);
    box-shadow: 5px 0 20px rgba(32, 24, 12, .025);
}
.page > .content { margin-left: var(--admin-sidebar-width); min-width: 0; width: calc(100% - var(--admin-sidebar-width)); }
.admin-sidebar-top { display: flex; align-items: center; justify-content: space-between; }
.admin-brand, .admin-mobile-brand { display: flex; align-items: center; gap: 11px; }
.admin-brand { padding: 6px 11px 20px; width: 100%; border-bottom: 1px solid var(--admin-nav-border); }
.admin-brand-mark {
    width: 44px; height: 44px; border-radius: 14px; display: grid; place-items: center;
    background: linear-gradient(135deg, #f8ba78, #ee9242); color: #fff; font-size: 1.15rem;
    font-weight: 800; box-shadow: 0 8px 18px rgba(234,138,58,.17); flex-shrink: 0;
}
.admin-brand-text { color: #ed9347; font-size: 1.15rem; font-weight: 800; line-height: 1.05; white-space: nowrap; }
.admin-brand-text small { display: block; margin-top: 4px; color: #777; font-size: .70rem; font-weight: 500; }
.admin-sidebar-close { display: none; }
.admin-nav { display: flex; flex-direction: column; gap: 5px; margin-top: 23px; }
.admin-nav-item {
    display: flex; align-items: center; gap: 11px; text-decoration: none;
    min-height: 52px; padding: 9px 12px; border-radius: 13px;
    color: var(--admin-nav-text); font-size: .9rem; font-weight: 500;
    transition: background .15s ease, color .15s ease;
}
.admin-nav-item:hover { background: #f4ece5; }
.admin-nav-item.active { background: #f6e9dc; box-shadow: inset 3px 0 0 var(--admin-nav-orange); font-weight: 700; color: #30231a; }
.admin-icon { width: 34px; height: 34px; display: grid; place-items: center; flex: 0 0 34px; border-radius: 10px; color: #77716c; }
.admin-nav-item:hover .admin-icon { background: #efe5dc; color: #a86b33; }
.admin-nav-item.active .admin-icon { background: #fff1e3; color: #c96a1d; }
.admin-nav-label { min-width: 0; }
.admin-sidebar-footer { position: relative; margin-top: auto; padding-top: 16px; border-top: 1px solid var(--admin-nav-border); }
.admin-profile-summary {
    width: 100%; border: 1px solid transparent; border-radius: 13px;
    padding: 10px 7px; display: flex; align-items: center; gap: 10px;
    text-align: left; background: transparent; cursor: pointer; color: #333;
}
.admin-profile-summary:hover, .admin-profile-summary[aria-expanded="true"] { background: #f4ece4; border-color: #ede0d2; }
.admin-avatar, .admin-mobile-avatar {
    display: grid; place-items: center; border-radius: 50%; flex: 0 0 auto;
    background: linear-gradient(135deg, #f4c998, #d47c44); color: #fff; font-weight: 800;
}
.admin-avatar { width: 39px; height: 39px; font-size: .8rem; }
.admin-profile-copy { display: flex; flex-direction: column; align-items: flex-start; flex: 1; min-width: 0; }
.admin-profile-name { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .86rem; font-weight: 700; }
.admin-profile-role { margin-top: 3px; color: #777; font-size: .7rem; }
.admin-profile-chevron { width: 19px; color: #8a8580; }
.admin-profile-chevron svg { width: 17px; height: 17px; transition: transform .2s ease; }
.admin-profile-trigger[aria-expanded="true"] .admin-profile-chevron svg { transform: rotate(180deg); }
.admin-profile-menu {
    position: absolute; bottom: calc(100% + 8px); left: 0; right: 0;
    z-index: 1700; padding: 12px; border-radius: 14px;
    background: #fff; border: 1px solid #e8dfd5;
    box-shadow: 0 14px 36px rgba(30,20,12,.16);
}
.admin-profile-menu[hidden] { display: none !important; }
.admin-profile-menu strong { display: block; font-size: .83rem; color: #35312c; overflow-wrap: anywhere; }
.admin-profile-menu-caption { display: block; margin: 3px 0 10px; color: #8a827b; font-size: .72rem; }
.admin-profile-menu form { margin: 0; }
.admin-profile-logout {
    width: 100%; padding: 11px 12px; display: flex; align-items: center;
    gap: 10px; cursor: pointer; background: #fff7f3; color: #b75038;
    border: 1px solid #f1ded3; border-radius: 9px; font-weight: 700;
}
.admin-profile-logout:hover { background: #ffeae0; }
.admin-profile-logout svg { width: 17px; height: 17px; }
.admin-sidebar a:focus-visible, .admin-sidebar button:focus-visible, .admin-mobile-header button:focus-visible {
    outline: 3px solid #d98940; outline-offset: 2px;
}

/* Buyer-nav style responsive slide-out sidebar */
@media (max-width: 840px) {
    body.admin-menu-open { overflow: hidden; }
    .page > .content {
        margin-left: 0 !important;
        width: 100% !important;
        max-width: none !important;
        min-width: 0 !important;
        padding-top: calc(var(--admin-mobile-header-height) + 22px) !important;
        padding-bottom: max(100px, env(safe-area-inset-bottom) + 30px) !important;
    }
    .page { width: 100%; min-width: 0; flex-direction: column; }
    .admin-mobile-header {
        display: grid; grid-template-columns: 42px minmax(0,1fr) 42px;
        align-items: center; gap: 8px;
        position: fixed; inset: 0 0 auto; height: var(--admin-mobile-header-height);
        z-index: 1600; padding: 0 13px; background: rgba(251,249,247,.96);
        border-bottom: 1px solid var(--admin-nav-border); box-shadow: 0 3px 14px rgba(30,20,10,.045);
        backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
    }
    .admin-menu-toggle {
        width: 42px; height: 42px; padding: 0; border: 0; border-radius: 10px;
        background: transparent; display: grid; place-items: center; cursor: pointer; color: #51483f;
    }
    .admin-menu-toggle:hover { background: #f1e9e2; }
    .admin-mobile-brand { justify-self: center; gap: 8px; min-width: 0; }
    .admin-mobile-brand .admin-brand-mark { width: 34px; height: 34px; border-radius: 10px; font-size: .9rem; }
    .admin-mobile-brand .admin-brand-text { font-size: .85rem; }
    .admin-mobile-brand .admin-brand-text small { font-size: .6rem; }
    .admin-mobile-profile { position: relative; justify-self: end; }
    .admin-mobile-avatar {
        width: 39px; height: 39px; border: 1px solid #dcb590;
        font-size: .75rem; cursor: pointer;
    }
    .admin-mobile-profile-menu { top: calc(100% + 10px); bottom: auto; left: auto; right: 0; width: min(250px, calc(100vw - 28px)); }
    .admin-sidebar-overlay {
        display: block; position: fixed;
        inset: var(--admin-mobile-header-height) 0 0; z-index: 1400;
        background: rgba(24,18,12,.4); opacity: 0; visibility: hidden;
        transition: opacity .22s ease, visibility .22s ease;
    }
    .admin-sidebar-overlay.show { opacity: 1; visibility: visible; }
    .admin-sidebar {
        position: fixed; inset: var(--admin-mobile-header-height) auto 0 0; z-index: 1500;
        width: min(300px, 86vw);
        height: calc(100dvh - var(--admin-mobile-header-height)); min-height: 0;
        background: var(--admin-nav-bg); overflow-y: auto;
        padding: 17px 14px 20px;
        box-shadow: 14px 0 38px rgba(25,20,15,.13);
        transform: translateX(-105%); transition: transform .24s ease;
    }
    .admin-sidebar.open { transform: translateX(0); }
    .admin-sidebar-top { gap: 8px; }
    .admin-brand { padding: 4px 0 16px; }
    .admin-sidebar-close {
        width: 36px; height: 36px; display: grid; place-items: center;
        border: 1px solid var(--admin-nav-border); border-radius: 50%;
        background: #fff; cursor: pointer; color: #5d554f;
    }
    .admin-sidebar-close svg { width: 19px; height: 19px; }
    .admin-nav { margin-top: 15px; gap: 5px; }
    .admin-nav-item { min-height: 49px; }
    /* Mobile profile lives in the top-right header, never inside the sidebar. */
    .admin-sidebar .admin-sidebar-footer { display: none !important; }
}
@media (max-width: 370px) {
    .admin-mobile-header { padding: 0 8px; gap: 4px; }
    .admin-mobile-brand .admin-brand-text { font-size: .77rem; }
    .admin-mobile-brand .admin-brand-text small { font-size: .56rem; }
}
@media (prefers-reduced-motion: reduce) {
    .admin-sidebar, .admin-sidebar-overlay, .admin-nav-item { transition: none !important; }
}
</style>
<script>
(() => {
    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('adminSidebarOverlay');
    const menuToggle = document.getElementById('adminMenuToggle');
    const closeButton = document.getElementById('adminSidebarClose');
    const profileHolders = Array.from(document.querySelectorAll('[data-admin-profile-holder]'));
    const mobileQuery = window.matchMedia('(max-width: 840px)');
    if (!sidebar || !overlay || !menuToggle) return;

    function closeProfiles() {
        for (const holder of profileHolders) {
            const trigger = holder.querySelector('[data-admin-profile-trigger]');
            const menu = holder.querySelector('[data-admin-profile-menu]');
            if (!trigger || !menu) continue;
            menu.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
        }
    }

    for (const holder of profileHolders) {
        const trigger = holder.querySelector('[data-admin-profile-trigger]');
        const menu = holder.querySelector('[data-admin-profile-menu]');
        if (!trigger || !menu) continue;
        trigger.addEventListener('click', (event) => {
            event.stopPropagation();
            const nextOpen = menu.hidden;
            if (mobileQuery.matches && sidebar.classList.contains('open')) {
                setMenuOpen(false);
            }
            closeProfiles();
            menu.hidden = !nextOpen;
            trigger.setAttribute('aria-expanded', String(nextOpen));
            if (nextOpen) menu.querySelector('button')?.focus();
        });
    }

    function setMenuOpen(open) {
        const shouldOpen = Boolean(open && mobileQuery.matches);
        sidebar.classList.toggle('open', shouldOpen);
        overlay.classList.toggle('show', shouldOpen);
        overlay.setAttribute('aria-hidden', String(!shouldOpen));
        menuToggle.setAttribute('aria-expanded', String(shouldOpen));
        menuToggle.setAttribute('aria-label', shouldOpen ? 'Close admin navigation' : 'Open admin navigation');
        document.body.classList.toggle('admin-menu-open', shouldOpen);
        if (!shouldOpen) closeProfiles();
        if (shouldOpen) closeButton?.focus();
    }

    menuToggle.addEventListener('click', () => setMenuOpen(!sidebar.classList.contains('open')));
    closeButton?.addEventListener('click', () => {
        setMenuOpen(false);
        menuToggle.focus();
    });
    overlay.addEventListener('click', () => setMenuOpen(false));
    sidebar.querySelectorAll('.admin-nav-item').forEach(link => {
        link.addEventListener('click', () => setMenuOpen(false));
    });
    document.addEventListener('click', (event) => {
        if (!event.target.closest('[data-admin-profile-holder]')) closeProfiles();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            const wasOpen = sidebar.classList.contains('open');
            closeProfiles();
            if (wasOpen) { setMenuOpen(false); menuToggle.focus(); }
        }
    });
    mobileQuery.addEventListener?.('change', () => setMenuOpen(false));
})();
</script>
