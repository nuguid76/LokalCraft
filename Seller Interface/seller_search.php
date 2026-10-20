<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['seller_logout_csrf'])) {
    $_SESSION['seller_logout_csrf'] = bin2hex(random_bytes(32));
}
$searchPlaceholder = $searchPlaceholder ?? 'Search products...';
$headerUserName = trim((string)($userName ?? ($_SESSION['seller_name'] ?? ($_SESSION['user_name'] ?? 'Seller'))));
if ($headerUserName === '') $headerUserName = 'Seller';
$headerUserEmail = '';
// Fetch the currently signed-in seller's email for the account menu.
try {
    $sellerHeaderStmt = $conn->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
    if ($sellerHeaderStmt) {
        $sellerHeaderUserId = (int)$sellerId;
        $sellerHeaderStmt->bind_param('i', $sellerHeaderUserId);
        $sellerHeaderStmt->execute();
        $sellerHeaderStmt->bind_result($sellerHeaderDbEmail);
        if ($sellerHeaderStmt->fetch()) $headerUserEmail = trim((string)$sellerHeaderDbEmail);
        $sellerHeaderStmt->close();
    }
} catch (Throwable $e) {
    error_log('LokalCraft seller header email: ' . $e->getMessage());
}
$sellerNameParts = preg_split('/\s+/u', $headerUserName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
if (count($sellerNameParts) > 1) {
    $headerInitials = strtoupper(substr($sellerNameParts[0], 0, 1) . substr(end($sellerNameParts), 0, 1));
} else {
    $headerInitials = strtoupper(substr($headerUserName, 0, 2));
}
if ($headerInitials === '') $headerInitials = 'SE';
?>
<style>
:root {
  --seller-orange: #ea8a3a;
  --seller-orange-dark: #c96d2a;
  --seller-orange-soft: #fff3e8;
  --seller-text: #3f3a36;
  --seller-muted: #7f7974;
  --seller-line: #e6e2de;
  --seller-soft: #f7f6f4;
}
.seller-header-tools {
  position: fixed;
  top: 0;
  right: 0;
  left: 250px;
  z-index: 110;
  min-height: 64px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  margin: 0;
  padding: 10px 26px;
  border-bottom: 1px solid rgba(226,225,222,.92);
  border-radius: 0 0 22px 22px;
  background: rgba(255,255,255,.97);
  box-shadow: 0 8px 24px rgba(47,31,19,.055);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
}
.page > .content,
.app > .main {
  padding-top: 78px !important;
}
.seller-menu-toggle {
  display: none;
  flex: 0 0 auto;
  width: 40px;
  height: 40px;
  padding: 0;
  border: 1px solid var(--seller-line);
  border-radius: 11px;
  background: #fff;
  cursor: pointer;
  align-items: center;
  justify-content: center;
}
.seller-menu-toggle:hover {
  border-color: #ddc3ad;
  background: #fffaf6;
}
.seller-menu-lines {
  width: 18px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
}
.seller-menu-lines span {
  display: block;
  width: 18px;
  height: 2px;
  border-radius: 999px;
  background: #58524d;
}
.seller-search-layout {
  flex: 1;
  min-width: 0;
}
.filters {
  width: min(560px, 100%);
  display: grid;
  grid-template-columns: 1fr;
  gap: 10px;
  margin: 0;
  padding: 0;
  border: 0;
  background: transparent;
  box-shadow: none;
}
.filter-box {
  min-height: 40px;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 11px;
  border: 1px solid var(--seller-line);
  border-radius: 12px;
  background: var(--seller-soft);
  transition: border-color .18s ease, background .18s ease, box-shadow .18s ease;
}
.filter-box:focus-within {
  border-color: #ddb083;
  background: #fff;
  box-shadow: 0 0 0 3px rgba(234,138,58,.08);
}
.filter-box.search {
  width: 100%;
}
.search-icon,
.caret {
  color: #8a827c;
  font-size: .95rem;
}
/* Header-only icon sizing: unaffected by page-specific product filters. */
.seller-header-tools .search-icon {
  display: inline-grid;
  place-items: center;
  width: 18px;
  height: 18px;
  flex: 0 0 18px;
  line-height: 1;
}
.seller-header-tools .search-icon svg {
  display: block;
  width: 18px;
  height: 18px;
}
.filter-box:focus-within .search-icon,
.filter-box:focus-within .caret {
  color: var(--seller-orange-dark);
}
.filter-box input,
.filter-box select {
  width: 100%;
  min-width: 0;
  border: 0;
  outline: 0;
  background: transparent;
  color: var(--seller-text);
  font: inherit;
  font-size: .84rem;
}
.filter-box input::placeholder {
  color: #99928c;
}
.filter-box select {
  appearance: none;
}
.seller-header-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}
.seller-notification {
  width: 38px;
  height: 38px;
  display: grid;
  place-items: center;
  padding: 0;
  border: 1px solid var(--seller-line);
  border-radius: 11px;
  background: #fff;
  color: #77716c;
  cursor: pointer;
  font-size: 1rem;
}
.seller-notification:hover {
  border-color: #ddc3ad;
  background: var(--seller-orange-soft);
  color: var(--seller-orange-dark);
}
.seller-account {
  position: relative;
}
.seller-avatar-button {
  width: 40px;
  height: 40px;
  border: 0;
  border-radius: 50%;
  background: linear-gradient(135deg, #f7b875, #ea8a3a);
  color: #fff;
  font-weight: 800;
  cursor: pointer;
}
.seller-avatar-button:focus-visible {
  outline: 3px solid rgba(234,138,58,.18);
  outline-offset: 2px;
}
.seller-account-menu {
  position: absolute;
  top: calc(100% + 11px);
  right: 0;
  z-index: 150;
  display: none;
  width: min(300px, calc(100vw - 20px));
  max-width: calc(100vw - 20px);
  padding: 0;
  border: 1px solid #e9dfd5;
  border-radius: 17px;
  background: #fffdfa;
  box-shadow: 0 18px 44px rgba(48,31,15,.16);
  overflow: hidden;
}
.seller-account-menu[hidden] { display: none !important; }
.seller-account-menu.open { display: block; }
.seller-account-heading {
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 15px 14px 13px;
  border-bottom: 1px solid #f0e7df;
  background: #fffaf4;
}
.seller-account-avatar {
  display: grid;
  place-items: center;
  flex: 0 0 42px;
  width: 42px;
  height: 42px;
  border-radius: 50%;
  background: linear-gradient(135deg,#f7b875,#ea8a3a);
  color: #fff;
  font-size: .76rem;
  font-weight: 800;
}
.seller-account-heading-copy { min-width: 0; flex: 1; }
.seller-account-name {
  display: block;
  overflow: hidden;
  color: #352a24;
  font-size: .83rem;
  font-weight: 800;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.seller-account-email {
  display: block;
  margin-top: 3px;
  overflow: hidden;
  color: #8b8178;
  font-size: .71rem;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.seller-account-role {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  margin-top: 7px;
  color: #a96b36;
  font-size: .65rem;
  font-weight: 700;
}
.seller-account-role svg { width: 13px; height: 13px; }
.seller-account-items { display: grid; gap: 3px; padding: 8px; }
.seller-account-item {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  min-height: 47px;
  padding: 9px 8px;
  border: 0;
  border-radius: 11px;
  background: transparent;
  color: #4a403a;
  text-align: left;
  text-decoration: none;
  font: inherit;
  cursor: pointer;
}
.seller-account-item:hover, .seller-account-item:focus-visible {
  background: #f8f0e8;
  outline: none;
}
.seller-account-item-icon {
  display: grid;
  place-items: center;
  flex: 0 0 34px;
  width: 34px;
  height: 34px;
  border-radius: 10px;
  background: #f5efe9;
  color: #8f6952;
}
.seller-account-item-icon svg { width: 19px; height: 19px; }
.seller-account-item-copy { min-width: 0; flex: 1; }
.seller-account-item-copy strong { display: block; font-size: .79rem; font-weight: 750; }
.seller-account-item-copy small { display: block; margin-top: 2px; color: #92867b; font-size: .65rem; line-height: 1.35; }
.seller-account-chevron { color: #a59a91; font-size: 1rem; }
.seller-account-switch { background: #fff4e9; }
.seller-account-switch .seller-account-item-icon { background: #ffe4cc; color: #c07639; }
.seller-account-switch:hover, .seller-account-switch:focus-visible { background: #fce9d9; }
.seller-account-switch:disabled { opacity: .55; cursor: wait; }
.seller-account-footer { border-top: 1px solid #f0e7df; padding: 8px; }
.seller-account-logout .seller-account-item-icon { color: #b45656; background: #fff0ee; }
.seller-account-logout:hover { background: #fff0ee; }
body.seller-modal-open {
  overflow: hidden;
}
.seller-logout-modal {
  position: fixed;
  inset: 0;
  z-index: 2147483647;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 20px;
  background: rgba(35,27,21,.45);
  backdrop-filter: blur(2px);
  -webkit-backdrop-filter: blur(2px);
}
.seller-logout-modal[hidden] { display: none !important; }
.seller-logout-modal.open {
  display: flex;
}
.seller-logout-card {
  width: min(390px, 100%);
  padding: 28px 24px 24px;
  border: 1px solid #eadfd6;
  border-radius: 18px;
  background: #fff;
  box-shadow: 0 22px 55px rgba(30,20,10,.18);
  text-align: center;
}
.seller-logout-icon {
  width: 46px;
  height: 46px;
  display: grid;
  place-items: center;
  margin: 0 auto 14px;
  border-radius: 14px;
  background: var(--seller-orange-soft);
  color: var(--seller-orange-dark);
  font-size: 1.35rem;
  font-weight: 800;
}
.seller-logout-card h2 {
  margin: 0;
  color: #2f2a26;
  font-size: 1.08rem;
}
.seller-logout-card p {
  margin: 8px 0 20px;
  color: #77706a;
  font-size: .84rem;
  line-height: 1.5;
}
.seller-logout-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}
.seller-logout-actions form {
  margin: 0;
}
.seller-logout-cancel,
.seller-logout-confirm {
  min-height: 40px;
  padding: 9px 15px;
  border-radius: 10px;
  font: inherit;
  font-size: .8rem;
  font-weight: 700;
  cursor: pointer;
}
.seller-logout-cancel {
  border: 1px solid #ddd6d0;
  background: #fff;
  color: #5a534d;
}
.seller-logout-confirm {
  border: 1px solid var(--seller-orange-dark);
  background: var(--seller-orange);
  color: #fff;
}
.seller-logout-confirm:hover {
  background: var(--seller-orange-dark);
}
.seller-logout-confirm:disabled {
  cursor: wait;
  opacity: .7;
}
/* Seller-only notifications; based on existing warm Seller header palette. */
.seller-notifications { position: relative; flex: 0 0 auto; }
.seller-notification { position: relative; }
.seller-notification svg { display: block; width: 20px; height: 20px; }
.seller-notification:focus-visible { outline: 3px solid rgba(234,138,58,.19); outline-offset: 2px; }
.seller-notification-count {
  position: absolute; top: -7px; right: -7px; min-width: 19px; height: 19px;
  padding: 0 4px; display: grid; place-items: center; border: 2px solid #fff;
  border-radius: 99px; background: #c66d2d; color: #fff; font-size: 10px;
  font-weight: 750; line-height: 1;
}
.seller-notification-count[hidden] { display: none !important; }
.seller-notification-panel {
  position: fixed; z-index: 170; display: flex; flex-direction: column;
  width: min(390px, calc(100vw - 20px)); max-width: calc(100vw - 20px);
  max-height: min(530px, calc(100dvh - 24px)); overflow: hidden;
  border: 1px solid #eadfd4; border-radius: 19px; background: #fffdfa;
  box-shadow: 0 20px 50px rgba(49,32,19,.19); color: var(--seller-text);
  font-family: inherit;
}
.seller-notification-panel[hidden] { display: none !important; }
.seller-notification-panel-heading {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 12px; padding: 16px 16px 12px; background: #fff8f1;
  border-bottom: 1px solid #f0e7de; flex-shrink: 0;
}
.seller-notification-panel-copy { min-width: 0; }
.seller-notification-panel-eyebrow { color: #b17c4f; font-size: 10px; font-weight: 750; letter-spacing: .075em; }
.seller-notification-panel-copy h2 { margin: 4px 0 3px; font-size: 1.04rem; font-weight: 800; color: #382e28; }
.seller-notification-panel-copy p { margin: 0; color: #8c7e73; font-size: .76rem; line-height: 1.45; }
.seller-notification-panel-close {
  flex: 0 0 auto; display: grid; place-items: center; width: 29px; height: 29px;
  border: 1px solid #eadfd4; border-radius: 9px; background: #fff; color: #86776c;
  cursor: pointer; font-size: 21px; line-height: 1;
}
.seller-notification-panel-close:hover { background: #fff0e0; color: #b46c34; }
.seller-notification-panel-toolbar {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  padding: 11px 16px; border-bottom: 1px solid #f1ece7;
  font-size: .73rem; color: #8c8179; flex-shrink: 0;
}
.seller-notification-mark-all {
  border: 0; padding: 4px 0; background: transparent; color: #bc7336;
  font: inherit; font-size: .73rem; font-weight: 750; cursor: pointer; white-space: nowrap;
}
.seller-notification-mark-all[hidden] { display: none; }
.seller-notification-mark-all:hover { color: #8e4c1d; text-decoration: underline; }
.seller-notification-mark-all:disabled { opacity: .45; cursor: default; }
.seller-notification-list { overflow-x: hidden; overflow-y: auto; min-height: 0; flex: 1 1 auto; }
.seller-notification-list[hidden] { display: none; }
.seller-notification-item {
  display: flex; align-items: flex-start; width: 100%; gap: 11px;
  padding: 13px 15px; border: 0; border-bottom: 1px solid #f2ede8;
  background: transparent; color: #4c3d33; text-align: left; font: inherit;
  cursor: pointer; transition: background .15s ease;
}
.seller-notification-item:last-child { border-bottom: 0; }
.seller-notification-item:hover, .seller-notification-item:focus-visible { background: #fff3e7; outline: none; }
.seller-notification-item.is-unread { background: #fff9f3; }
.seller-notification-item.is-unread:hover { background: #ffeddd; }
.seller-notification-item:disabled { cursor: wait; opacity: .65; }
.seller-notification-item-icon {
  display: grid; place-items: center; flex: 0 0 36px; width: 36px; height: 36px;
  border-radius: 11px; background: #ffead7; color: #b86c35;
}
.seller-notification-item.is-success .seller-notification-item-icon { background: #e9f5eb; color: #3a895a; }
.seller-notification-item.is-warning .seller-notification-item-icon { background: #fff0e8; color: #bd5945; }
.seller-notification-item-details { display: block; flex: 1; min-width: 0; }
.seller-notification-item-title { display: block; margin-bottom: 4px; font-size: .82rem; font-weight: 750; }
.seller-notification-item-message {
  display: block; color: #796f68; font-size: .74rem; line-height: 1.48;
  white-space: pre-line; overflow-wrap: anywhere;
}
.seller-notification-item-date { display: block; margin-top: 5px; color: #a3968a; font-size: .67rem; }
.seller-notification-item-dot { flex: 0 0 7px; width: 7px; height: 7px; margin-top: 6px; border-radius: 50%; background: var(--seller-orange); }
.seller-notification-item-dot[hidden] { display: none !important; }
.seller-notification-status { padding: 22px 15px; text-align: center; color: #8b7d70; font-size: .8rem; line-height: 1.5; overflow-y: auto; }
.seller-notification-status[hidden] { display: none; }
.seller-notification-status p { margin: 0; }
.seller-notification-retry {
  margin-top: 11px; padding: 7px 14px; border: 1px solid #e3c5a9; border-radius: 8px;
  background: #fff4e8; color: #ad6630; font: inherit; cursor: pointer;
}
.seller-notification-panel-footer {
  padding: 10px 14px; border-top: 1px solid #f1e8e0; background: #fffaf5;
  color: #a0958c; font-size: .68rem; text-align: center; flex-shrink: 0;
}
@media (max-width: 560px) {
  .seller-logout-actions {
    flex-direction: column-reverse;
  }
  .seller-logout-actions form,
  .seller-logout-cancel,
  .seller-logout-confirm {
    width: 100%;
  }
}
@media (max-width: 760px) {
  .seller-header-tools {
    top: 0;
    right: 0;
    left: 0;
    min-height: 58px;
    align-items: flex-start;
    gap: 8px;
    padding: 9px 12px;
    border-radius: 0 0 18px 18px;
  }
  .page > .content,
  .app > .main {
    padding-top: 108px !important;
  }
  .seller-menu-toggle {
    display: inline-flex;
    margin-top: 0;
  }
  .seller-search-layout {
    flex: 1;
    min-width: 0;
  }
  .filters {
    width: 100%;
    max-width: none;
  }
  .filter-box.search {
    min-width: 0;
    min-height: 40px;
  }
  .filter-box input,
  .filter-box select {
    min-width: 0;
    font-size: .82rem;
  }
  .seller-header-actions {
    gap: 7px;
    padding-top: 1px;
  }
  .seller-notification,
  .seller-avatar-button {
    width: 36px;
    height: 36px;
    flex-shrink: 0;
  }
}
@media (min-width: 761px) {
  .seller-menu-toggle {
    display: none !important;
  }
}
</style>
<div class="seller-header-tools">
  <button
    class="seller-menu-toggle"
    id="sellerSidebarToggle"
    type="button"
    aria-label="Open navigation menu"
    aria-expanded="false"
    aria-controls="sellerSidebar"
  >
    <span class="seller-menu-lines" aria-hidden="true"><span></span><span></span><span></span></span>
  </button>
  <div class="seller-search-layout">
    <div class="filters" id="sellerHeaderFilters">
      <div class="filter-box search">
        <span class="search-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg></span>
        <input id="productSearch" type="text" placeholder="<?php echo htmlspecialchars($searchPlaceholder); ?>" aria-label="Search products" />
      </div>
    </div>
  </div>
  <div class="seller-header-actions">
    <div class="seller-notifications" id="sellerNotifications">
      <button class="seller-notification" type="button" id="sellerNotificationsToggle"
        aria-label="Seller notifications" aria-haspopup="dialog" aria-expanded="false"
        aria-controls="sellerNotificationsPanel" title="Seller notifications">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"
          stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/>
          <path d="M10 21h4"/>
        </svg>
        <span class="seller-notification-count" id="sellerNotificationCount" hidden>0</span>
      </button>
    </div>
    <div class="seller-account">
      <button
        class="seller-avatar-button"
        id="sellerAvatarButton"
        type="button"
        aria-label="Open account menu"
        aria-haspopup="menu"
        aria-controls="sellerAccountMenu"
        aria-expanded="false"
      ><?php echo htmlspecialchars($headerInitials); ?></button>
      <div class="seller-account-menu" id="sellerAccountMenu" role="menu" aria-label="Seller account menu" hidden>
        <div class="seller-account-heading" role="presentation">
          <div class="seller-account-avatar" aria-hidden="true"><?php echo htmlspecialchars($headerInitials, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
          <div class="seller-account-heading-copy">
            <strong class="seller-account-name" title="<?php echo htmlspecialchars($headerUserName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?php echo htmlspecialchars($headerUserName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong>
            <?php if ($headerUserEmail !== ''): ?><span class="seller-account-email" title="<?php echo htmlspecialchars($headerUserEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?php echo htmlspecialchars($headerUserEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span><?php endif; ?>
            <span class="seller-account-role"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 10h16l-2-6H6zM5 10v10h14V10M9 20v-6h6v6"/></svg> Seller Account</span>
          </div>
        </div>
        <div class="seller-account-items">
          <a class="seller-account-item" href="profile_seller.php" role="menuitem">
            <span class="seller-account-item-icon" aria-hidden="true"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></span>
            <span class="seller-account-item-copy"><strong>My Profile</strong><small>Manage your seller information</small></span>
            <span class="seller-account-chevron" aria-hidden="true">›</span>
          </a>
          <button class="seller-account-item seller-account-switch" type="button" role="menuitem" id="sellerRoleSwitch" data-role-target="buyer" data-role-form="sellerRoleSwitchForm">
            <span class="seller-account-item-icon" aria-hidden="true"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7h14l-3-3M21 7l-3 3M17 17H3l3 3M3 17l3-3"/></svg></span>
            <span class="seller-account-item-copy"><strong>Switch to Buyer</strong><small>Browse and shop on LokalCraft</small></span>
            <span class="seller-account-chevron" aria-hidden="true">↗</span>
          </button>
        </div>
        <div class="seller-account-footer">
          <button class="seller-account-item seller-account-logout" type="button" role="menuitem" id="sellerLogoutButton">
            <span class="seller-account-item-icon" aria-hidden="true"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5M15 16l4-4-4-4M19 12H9"/></svg></span>
            <span class="seller-account-item-copy"><strong>Log Out</strong></span>
          </button>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- Seller-only notification popup. Messages unread counters stay separate. -->
<section class="seller-notification-panel" id="sellerNotificationsPanel" role="dialog"
  aria-modal="false" aria-labelledby="sellerNotificationsTitle" hidden>
  <div class="seller-notification-panel-heading">
    <div class="seller-notification-panel-copy">
      <span class="seller-notification-panel-eyebrow">SHOP UPDATES</span>
      <h2 id="sellerNotificationsTitle">Notifications</h2>
      <p>Updates on your orders and shop.</p>
    </div>
    <button type="button" class="seller-notification-panel-close" id="sellerNotificationsClose"
      aria-label="Close notifications" title="Close notifications">&times;</button>
  </div>
  <div class="seller-notification-panel-toolbar">
    <span id="sellerNotificationsSummary">Recent updates</span>
    <button type="button" class="seller-notification-mark-all" id="sellerNotificationsMarkAll" hidden>
      Mark all as read
    </button>
  </div>
  <div class="seller-notification-status" id="sellerNotificationStatus" role="status" aria-live="polite">
    Loading notifications...
  </div>
  <div class="seller-notification-list" id="sellerNotificationList" hidden></div>
  <div class="seller-notification-panel-footer">Showing your 20 most recent notifications</div>
</section>
<template id="sellerNotificationItemTemplate">
  <button class="seller-notification-item" type="button">
    <span class="seller-notification-item-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor"
        stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="6" width="18" height="15" rx="2"/>
        <path d="M3 10h18M8 3v3M16 3v3"/>
      </svg>
    </span>
    <span class="seller-notification-item-details">
      <span class="seller-notification-item-title"></span>
      <span class="seller-notification-item-message"></span>
      <span class="seller-notification-item-date"></span>
    </span>
    <span class="seller-notification-item-dot" aria-label="Unread"></span>
  </button>
</template>
<div class="seller-logout-modal" id="sellerLogoutModal" aria-hidden="true" hidden>
  <div class="seller-logout-card" role="dialog" aria-modal="true" aria-labelledby="sellerLogoutTitle">
    <div class="seller-logout-icon" aria-hidden="true">↪</div>
    <h2 id="sellerLogoutTitle">Log out of LokalCraft?</h2>
    <p>Are you sure you want to log out of your seller account?</p>
    <div class="seller-logout-actions">
      <button type="button" class="seller-logout-cancel" id="sellerLogoutCancel">No, Stay</button>
      <form action="logout.php" method="POST" id="sellerLogoutForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['seller_logout_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" class="seller-logout-confirm">Yes, Logout</button>
      </form>
    </div>
  </div>
</div>
<script>
(function () {
  const productSearch = document.getElementById('productSearch');
  function applySellerHeaderFilters() {
    const searchTerm = (productSearch?.value || '').trim().toLowerCase();
    document.querySelectorAll('.product-card').forEach((card) => {
      const name = (card.dataset.name || '').toLowerCase();
      const matchesSearch = !searchTerm || name.includes(searchTerm);
      card.style.display = matchesSearch ? '' : 'none';
    });
  }
  productSearch?.addEventListener('input', applySellerHeaderFilters);
  const sellerAvatarButton = document.getElementById('sellerAvatarButton');
  const sellerAccountMenu = document.getElementById('sellerAccountMenu');
  function setSellerAccountMenuOpen(isOpen) {
    if (!sellerAccountMenu) return;
    sellerAccountMenu.hidden = !isOpen;
    sellerAccountMenu.classList.toggle('open', isOpen);
    sellerAvatarButton?.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  }
  // Seller notifications: separate API, interface, CSRF, and unread count from Buyer.
  const sellerNotificationRoot = document.getElementById('sellerNotifications');
  const sellerNotificationToggle = document.getElementById('sellerNotificationsToggle');
  const sellerNotificationPanel = document.getElementById('sellerNotificationsPanel');
  const sellerNotificationCount = document.getElementById('sellerNotificationCount');
  const sellerNotificationSummary = document.getElementById('sellerNotificationsSummary');
  const sellerNotificationMarkAll = document.getElementById('sellerNotificationsMarkAll');
  const sellerNotificationList = document.getElementById('sellerNotificationList');
  const sellerNotificationStatus = document.getElementById('sellerNotificationStatus');
  const sellerNotificationTemplate = document.getElementById('sellerNotificationItemTemplate');
  const sellerNotificationClose = document.getElementById('sellerNotificationsClose');
  const sellerNotificationEndpoint = 'seller_notification.php';
  let sellerNotificationCsrf = '';
  let sellerNotificationUnread = 0;
  let sellerNotificationsLoading = false;
  let sellerNotificationActionPending = false;
  if (sellerNotificationPanel && sellerNotificationPanel.parentElement !== document.body) {
    document.body.appendChild(sellerNotificationPanel);
  }
  function updateSellerNotificationCount(count) {
    const total = Math.max(0, Number(count) || 0);
    sellerNotificationUnread = total;
    if (sellerNotificationCount) {
      sellerNotificationCount.hidden = total === 0;
      sellerNotificationCount.textContent = total > 99 ? '99+' : String(total);
    }
    sellerNotificationToggle?.setAttribute('aria-label', total === 0
      ? 'Seller notifications' : `Seller notifications, ${total} unread`);
    if (sellerNotificationSummary) {
      sellerNotificationSummary.textContent = total === 0
        ? 'No unread notifications'
        : `${total} unread ${total === 1 ? 'notification' : 'notifications'}`;
    }
    if (sellerNotificationMarkAll) {
      sellerNotificationMarkAll.hidden = total === 0;
      sellerNotificationMarkAll.disabled = total > 0 && !sellerNotificationCsrf;
    }
  }
  function positionSellerNotificationPanel() {
    if (!sellerNotificationPanel || sellerNotificationPanel.hidden || !sellerNotificationToggle) return;
    const rect = sellerNotificationToggle.getBoundingClientRect();
    const width = sellerNotificationPanel.getBoundingClientRect().width;
    const left = Math.max(10, Math.min(rect.right - width, window.innerWidth - width - 10));
    const below = window.innerHeight - rect.bottom - 18;
    sellerNotificationPanel.style.left = `${left}px`;
    if (below >= 230 || below >= rect.top) {
      sellerNotificationPanel.style.top = `${rect.bottom + 10}px`;
      sellerNotificationPanel.style.bottom = 'auto';
      sellerNotificationPanel.style.maxHeight = `${Math.max(130, below)}px`;
    } else {
      sellerNotificationPanel.style.top = 'auto';
      sellerNotificationPanel.style.bottom = `${window.innerHeight - rect.top + 10}px`;
      sellerNotificationPanel.style.maxHeight = `${Math.max(130, rect.top - 22)}px`;
    }
  }
  function closeSellerNotifications(restoreFocus = false) {
    if (!sellerNotificationPanel || sellerNotificationPanel.hidden) return;
    sellerNotificationPanel.hidden = true;
    sellerNotificationToggle?.setAttribute('aria-expanded', 'false');
    if (restoreFocus) sellerNotificationToggle?.focus({preventScroll: true});
  }
  function sellerNotificationNotice(message, retry = false) {
    if (!sellerNotificationStatus || !sellerNotificationList) return;
    sellerNotificationList.hidden = true;
    sellerNotificationStatus.hidden = false;
    sellerNotificationStatus.replaceChildren();
    const text = document.createElement('p');
    text.textContent = message;
    sellerNotificationStatus.appendChild(text);
    if (retry) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'seller-notification-retry';
      button.textContent = 'Try again';
      button.addEventListener('click', () => { void loadSellerNotifications(); });
      sellerNotificationStatus.appendChild(button);
    }
  }
  async function sellerNotificationRequest(action, body = null) {
    const options = {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}};
    const url = `${sellerNotificationEndpoint}?action=${encodeURIComponent(action)}`;
    if (body !== null) {
      options.method = 'POST';
      options.body = new URLSearchParams({action, csrf_token: sellerNotificationCsrf, ...body});
    }
    const response = await fetch(url, options);
    let data;
    try { data = await response.json(); }
    catch (_) { throw new Error('Seller notifications are unavailable. Please try again.'); }
    if (!response.ok || !data?.success) {
      throw new Error(data?.message || 'Seller notifications are unavailable. Please try again.');
    }
    return data;
  }
  function sellerNotificationDateLabel(raw) {
    const value = String(raw || '');
    const match = value.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    if (!match) return value;
    const [, year, month, day, hour, minute] = match;
    const date = new Date(Number(year), Number(month) - 1, Number(day), Number(hour), Number(minute));
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString(undefined, {month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'});
  }
  function renderSellerNotifications(items) {
    if (!sellerNotificationList || !sellerNotificationTemplate || !sellerNotificationStatus) return;
    sellerNotificationList.replaceChildren();
    if (!Array.isArray(items) || items.length === 0) {
      sellerNotificationNotice('No shop notifications yet. New order updates will appear here.');
      return;
    }
    const fragment = document.createDocumentFragment();
    items.forEach((item) => {
      const button = sellerNotificationTemplate.content.firstElementChild.cloneNode(true);
      const type = String(item.type || '').toLowerCase();
      if (type.includes('cancel') || type.includes('archive') || type.includes('reject')) {
        button.classList.add('is-warning');
      } else if (type.includes('paid') || type.includes('payment') || type.includes('confirm')) {
        button.classList.add('is-success');
      }
      if (!item.read) button.classList.add('is-unread');
      button.querySelector('.seller-notification-item-title').textContent = String(item.title || 'Shop update');
      button.querySelector('.seller-notification-item-message').textContent = String(item.message || '');
      button.querySelector('.seller-notification-item-date').textContent = sellerNotificationDateLabel(item.created_at);
      const dot = button.querySelector('.seller-notification-item-dot');
      if (dot) dot.hidden = Boolean(item.read);
      button.addEventListener('click', () => { void openSellerNotification(item.id, button); });
      fragment.appendChild(button);
    });
    sellerNotificationList.appendChild(fragment);
    sellerNotificationStatus.hidden = true;
    sellerNotificationList.hidden = false;
  }
  async function loadSellerNotifications() {
    if (sellerNotificationsLoading || sellerNotificationPanel?.hidden) return;
    sellerNotificationsLoading = true;
    if (sellerNotificationList?.hidden) sellerNotificationNotice('Loading notifications...');
    try {
      const data = await sellerNotificationRequest('list');
      sellerNotificationCsrf = String(data.csrf_token || '');
      updateSellerNotificationCount(data.unread_count);
      if (!sellerNotificationPanel.hidden) renderSellerNotifications(data.notifications);
    } catch (error) {
      if (!sellerNotificationPanel.hidden) sellerNotificationNotice(error.message, true);
    } finally {
      sellerNotificationsLoading = false;
    }
  }
  async function refreshSellerNotificationCount() {
    if (document.hidden || sellerNotificationActionPending) return;
    try {
      const data = await sellerNotificationRequest('count');
      const previous = sellerNotificationUnread;
      updateSellerNotificationCount(data.unread_count);
      if (!sellerNotificationPanel?.hidden && previous !== sellerNotificationUnread) {
        void loadSellerNotifications();
      }
    } catch (_) {
      // Background errors must not interfere with search, navigation, or account controls.
    }
  }
  async function openSellerNotification(id, button) {
    if (sellerNotificationActionPending || !sellerNotificationCsrf) return;
    sellerNotificationActionPending = true;
    button.disabled = true;
    try {
      const data = await sellerNotificationRequest('mark_read', {notification_id: String(id)});
      const wasUnread = button.classList.contains('is-unread');
      button.classList.remove('is-unread');
      const dot = button.querySelector('.seller-notification-item-dot');
      if (dot) dot.hidden = true;
      if (wasUnread) updateSellerNotificationCount(sellerNotificationUnread - 1);
      if (typeof data.url !== 'string' || data.url.trim() === '') {
        sellerNotificationNotice('This linked order is no longer available. The notification was marked as read.');
        return;
      }
      const destination = new URL(data.url, window.location.href);
      const sellerDirectory = new URL('./', window.location.href).pathname;
      if (destination.origin !== window.location.origin
          || !destination.pathname.startsWith(sellerDirectory)
          || !destination.pathname.endsWith('/messages_seller.php')) {
        throw new Error('The Seller notification destination could not be verified.');
      }
      window.location.assign(destination.href);
    } catch (error) {
      sellerNotificationNotice(error.message, true);
    } finally {
      sellerNotificationActionPending = false;
      button.disabled = false;
    }
  }
  async function markAllSellerNotifications() {
    if (sellerNotificationActionPending || !sellerNotificationCsrf || sellerNotificationUnread === 0) return;
    sellerNotificationActionPending = true;
    sellerNotificationMarkAll.disabled = true;
    try {
      await sellerNotificationRequest('mark_all_read', {});
      updateSellerNotificationCount(0);
      if (!sellerNotificationPanel.hidden) await loadSellerNotifications();
    } catch (error) {
      sellerNotificationNotice(error.message, true);
    } finally {
      sellerNotificationActionPending = false;
      if (sellerNotificationMarkAll) sellerNotificationMarkAll.disabled = sellerNotificationUnread === 0;
    }
  }
  sellerNotificationToggle?.addEventListener('click', (event) => {
    event.stopPropagation();
    if (!sellerNotificationPanel) return;
    if (!sellerNotificationPanel.hidden) {
      closeSellerNotifications();
      return;
    }
    setSellerAccountMenuOpen(false);
    sellerNotificationPanel.hidden = false;
    sellerNotificationToggle.setAttribute('aria-expanded', 'true');
    positionSellerNotificationPanel();
    void loadSellerNotifications();
  });
  sellerNotificationClose?.addEventListener('click', () => closeSellerNotifications(true));
  sellerNotificationMarkAll?.addEventListener('click', () => { void markAllSellerNotifications(); });
  document.addEventListener('click', (event) => {
    if (sellerNotificationPanel?.hidden) return;
    if (!sellerNotificationRoot?.contains(event.target)
        && !sellerNotificationPanel?.contains(event.target)) closeSellerNotifications();
  });
  window.addEventListener('resize', positionSellerNotificationPanel);
  window.addEventListener('scroll', positionSellerNotificationPanel, {passive: true});
  if (sellerNotificationToggle) {
    void refreshSellerNotificationCount();
    window.setInterval(() => { void refreshSellerNotificationCount(); }, 45000);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) void refreshSellerNotificationCount();
    });
  }
  sellerAvatarButton?.addEventListener('click', (event) => {
    event.stopPropagation();
    closeSellerNotifications();
    setSellerAccountMenuOpen(!sellerAccountMenu?.classList.contains('open'));
  });
  document.addEventListener('click', (event) => {
    if (!event.target.closest('.seller-account')) {
      setSellerAccountMenuOpen(false);
    }
  });
  const sellerLogoutButton = document.getElementById('sellerLogoutButton');
  const sellerLogoutModal = document.getElementById('sellerLogoutModal');
  const sellerLogoutCancel = document.getElementById('sellerLogoutCancel');
  const sellerLogoutForm = document.getElementById('sellerLogoutForm');
  if (sellerLogoutModal && sellerLogoutModal.parentElement !== document.body) {
    document.body.appendChild(sellerLogoutModal);
  }
  function openSellerLogoutModal() {
    if (!sellerLogoutModal) return;
    setSellerAccountMenuOpen(false);
    sellerLogoutModal.hidden = false;
    sellerLogoutModal.classList.add('open');
    sellerLogoutModal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('seller-modal-open');
    window.setTimeout(() => sellerLogoutCancel?.focus(), 0);
  }
  function closeSellerLogoutModal() {
    if (!sellerLogoutModal) return;
    sellerLogoutModal.classList.remove('open');
    sellerLogoutModal.hidden = true;
    sellerLogoutModal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('seller-modal-open');
  }
  sellerLogoutButton?.addEventListener('click', openSellerLogoutModal);
  sellerLogoutCancel?.addEventListener('click', closeSellerLogoutModal);
  sellerLogoutModal?.addEventListener('click', (event) => {
    if (event.target === sellerLogoutModal) closeSellerLogoutModal();
  });
  sellerLogoutForm?.addEventListener('submit', () => {
    const submitButton = sellerLogoutForm.querySelector('button[type="submit"]');
    if (submitButton) {
      submitButton.disabled = true;
      submitButton.textContent = 'Logging out...';
    }
  });
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (sellerLogoutModal?.classList.contains('open')) {
      closeSellerLogoutModal();
    } else if (sellerNotificationPanel && !sellerNotificationPanel.hidden) {
      closeSellerNotifications(true);
    } else if (sellerAccountMenu?.classList.contains('open')) {
      setSellerAccountMenuOpen(false);
      sellerAvatarButton?.focus();
    }
  });
})();
</script>
