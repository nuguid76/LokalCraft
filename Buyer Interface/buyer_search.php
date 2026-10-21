<?php
require_once __DIR__ . '/buyer_auth.php';
$searchPlaceholder = $searchPlaceholder ?? 'Search products...';
$headerUserName = trim((string)($buyerNavFullName ?? ''));
if ($headerUserName === '') $headerUserName = trim((string)($userName ?? 'Buyer'));
$headerUserEmail = trim((string)($buyerNavEmail ?? ''));
$headerCanSwitchToSeller = !empty($buyerNavIsSeller);
$nameParts = preg_split('/\s+/', $headerUserName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
if (count($nameParts) >= 2) {
    $headerInitials =
        strtoupper(substr($nameParts[0], 0, 1))
        . strtoupper(substr($nameParts[count($nameParts) - 1], 0, 1));
} else {
    $headerInitials = strtoupper(substr($headerUserName, 0, 2));
}
if ($headerInitials === '') {
    $headerInitials = 'BU';
}
if (empty($_SESSION['buyer_logout_csrf'])) {
    $_SESSION['buyer_logout_csrf'] = bin2hex(random_bytes(32));
}
?>
<style>
  .buyer-header-tools {
    position: fixed;
    top: 0;
    right: 0;
    left: 240px;
    z-index: 90;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin: 0;
    padding: 10px 26px;
    border-bottom: 1px solid #e2e1de;
    background: rgba(255,255,255,.96);
    box-shadow: 0 3px 10px rgba(0,0,0,.03);
  }
  .content { padding-top: 78px !important; }
  .page > .content.buyer-header-search-only { padding-top: 78px !important; }
  .buyer-search-layout {
    flex: 1;
    min-width: 0;
  }
  .filters {
    width: 100%;
    max-width: 560px;
    display: grid;
    grid-template-columns: 1fr;
    gap: 10px;
    margin: 0;
    padding: 0;
    border: 0;
    box-shadow: none;
    background: transparent;
  }
  .buyer-header-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
  }
  .buyer-notification {
    width: 38px;
    height: 38px;
    display: grid;
    place-items: center;
    border: 1px solid #e2e1de;
    border-radius: 10px;
    background: #fff;
    color: #6e6e73;
    cursor: pointer;
    font-size: 1rem;
  }
  .buyer-notification:hover { background: #f7f4f1; }
  .buyer-notification-icon { line-height: 1; }
  .buyer-notifications { position: relative; flex: 0 0 auto; }
  .buyer-notification { position: relative; }
  .buyer-notification-icon { display: grid; place-items: center; }
  .buyer-notification-icon svg { width: 20px; height: 20px; }
  .buyer-notification-count {
    position: absolute;
    top: -7px;
    right: -7px;
    min-width: 19px;
    height: 19px;
    padding: 0 4px;
    display: grid;
    place-items: center;
    border: 2px solid white;
    border-radius: 999px;
    background: #df782c;
    color: white;
    font-size: 10px;
    font-weight: 800;
    line-height: 1;
    font-variant-numeric: tabular-nums;
  }
  .buyer-notification-count[hidden], .buyer-notification-panel[hidden],
  .buyer-notification-status[hidden], .buyer-notification-list[hidden],
  .buyer-notification-mark-all[hidden], .buyer-notification-item-dot[hidden] { display: none !important; }
  .buyer-notification-panel {
    position: fixed;
    z-index: 950;
    top: 68px;
    right: 20px;
    width: min(390px, calc(100vw - 24px));
    max-height: min(540px, calc(100dvh - 24px));
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid #e9dfd5;
    border-radius: 20px;
    background: #fffdfa;
    color: #392c24;
    box-shadow: 0 20px 55px rgba(48, 31, 15, .18);
    font-family: inherit;
  }
  .buyer-notification-panel-heading {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    justify-content: space-between;
    padding: 18px 18px 15px;
    border-bottom: 1px solid #f0e7df;
    background: linear-gradient(125deg, #fffaf4, #fff5e9);
  }
  .buyer-notification-panel-title { min-width: 0; }
  .buyer-notification-panel-eyebrow {
    display: block;
    color: #b16c31;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .09em;
  }
  .buyer-notification-panel-heading h2 {
    margin: 4px 0 3px;
    color: #382921;
    font-size: 1.02rem;
    font-weight: 800;
    line-height: 1.35;
  }
  .buyer-notification-panel-heading p {
    margin: 0;
    color: #95867a;
    font-size: .72rem;
    line-height: 1.45;
  }
  .buyer-notification-panel-close {
    width: 28px;
    height: 28px;
    flex: 0 0 28px;
    border: 1px solid #eddfd3;
    border-radius: 9px;
    background: #fffdfa;
    color: #806c5b;
    cursor: pointer;
    font-size: 20px;
    line-height: 1;
  }
  .buyer-notification-panel-close:hover { background: #f9efe6; }
  .buyer-notification-panel-toolbar {
    display: flex;
    gap: 10px;
    align-items: center;
    justify-content: space-between;
    min-height: 46px;
    padding: 8px 17px;
    border-bottom: 1px solid #f2ebe5;
  }
  .buyer-notification-panel-subtitle { color: #786b60; font-size: .72rem; font-weight: 700; }
  .buyer-notification-mark-all {
    padding: 6px 8px;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: #bb6b2c;
    cursor: pointer;
    font-size: .7rem;
    font-weight: 800;
  }
  .buyer-notification-mark-all:hover { background: #fff0e1; }
  .buyer-notification-mark-all:disabled { opacity: .6; cursor: wait; }
  .buyer-notification-list { min-height: 0; overflow-y: auto; overscroll-behavior: contain; }
  .buyer-notification-item {
    display: flex;
    gap: 11px;
    align-items: flex-start;
    width: 100%;
    padding: 14px 16px;
    border: 0;
    border-bottom: 1px solid #f2ebe5;
    background: #fffdfa;
    font-family: inherit;
    text-align: left;
    cursor: pointer;
    transition: background .16s ease;
  }
  .buyer-notification-item:last-child { border-bottom: 0; }
  .buyer-notification-item.is-unread { background: #fff8f0; }
  .buyer-notification-item:hover, .buyer-notification-item:focus-visible { background: #f9eee4; }
  .buyer-notification-item:focus-visible, .buyer-notification:focus-visible,
  .buyer-notification-panel-close:focus-visible, .buyer-notification-mark-all:focus-visible {
    outline: 2px solid #d9863c;
    outline-offset: 2px;
  }
  .buyer-notification-item:disabled { cursor: default; opacity: .6; }
  /* Only the status SVG carries color: no colored icon tile beside the notification. */
  .buyer-notification-item-icon {
    display: grid;
    place-items: center;
    flex: 0 0 36px;
    width: 36px;
    height: 36px;
    background: transparent;
    color: #A55325;
  }
  .buyer-notification-item-icon svg {
    display: block;
    width: 25px;
    height: 25px;
    stroke-width: 2.6;
  }
  .buyer-notification-item.is-success .buyer-notification-item-icon { color: #008A34; }
  .buyer-notification-item.is-rejected .buyer-notification-item-icon { color: #E00020; }
  .buyer-notification-item.is-payment .buyer-notification-item-icon { color: #C2410C; }
  .buyer-notification-item.is-reminder .buyer-notification-item-icon { color: #B45309; }
  .buyer-notification-item.is-progress .buyer-notification-item-icon { color: #1D4ED8; }
  .buyer-notification-item-details { display: block; min-width: 0; flex: 1; }
  .buyer-notification-item-title {
    display: block;
    margin: 1px 0 4px;
    overflow-wrap: anywhere;
    color: #392f29;
    font-size: .79rem;
    font-weight: 800;
    line-height: 1.35;
  }
  .buyer-notification-item-message {
    display: -webkit-box;
    overflow: hidden;
    overflow-wrap: anywhere;
    color: #81766d;
    font-size: .72rem;
    line-height: 1.48;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
  }
  /* Rejected orders: display the seller's complete reason right in the notification. */
  .buyer-notification-item.is-rejected .buyer-notification-item-message {
    display: block;
    overflow: visible;
    -webkit-line-clamp: unset;
    white-space: pre-line;
  }
  .buyer-notification-item-date { display: block; margin-top: 7px; color: #aa9a8e; font-size: .65rem; }
  .buyer-notification-item-dot {
    flex: 0 0 7px;
    width: 7px;
    height: 7px;
    margin-top: 5px;
    border-radius: 50%;
    background: #dc842f;
  }
  .buyer-notification-status {
    padding: 32px 20px;
    color: #8b7d71;
    text-align: center;
    font-size: .78rem;
    line-height: 1.6;
  }
  .buyer-notification-status p { margin: 0; }
  .buyer-notification-retry {
    margin-top: 13px;
    padding: 9px 15px;
    border: 1px solid #e8bb96;
    border-radius: 10px;
    background: #fff5eb;
    color: #ab642a;
    cursor: pointer;
    font: inherit;
    font-weight: 800;
  }
  .buyer-notification-retry:hover { background: #ffe8d4; }
  .buyer-notification-panel-footer {
    padding: 10px 15px;
    border-top: 1px solid #f0e7df;
    background: #fffaf5;
    color: #a39284;
    font-size: .65rem;
    text-align: center;
  }
  @media (max-width: 560px) {
    .buyer-notification-panel { border-radius: 17px; }
    .buyer-notification-panel-heading { padding: 15px; }
    .buyer-notification-item { padding: 12px 13px; }
  }

  .buyer-account { position: relative; }
  .buyer-avatar-button {
    width: 40px;
    height: 40px;
    border: 1px solid rgba(116, 46, 18, .22);
    border-radius: 50%;
    background: linear-gradient(135deg, #A55325, #C2692E);
    color: #FFF8ED;
    font-weight: 800;
    box-shadow: inset 0 1px 1px rgba(255,248,237,.14),
                0 2px 6px rgba(102,46,19,.16);
    transition: box-shadow .18s ease;
    cursor: pointer;
  }
  .buyer-avatar-button:hover {
    box-shadow: inset 0 1px 1px rgba(255,248,237,.18),
                0 3px 8px rgba(102,46,19,.20);
  }
  .buyer-account-menu {
    position: absolute;
    top: calc(100% + 11px);
    right: 0;
    display: none;
    width: min(300px, calc(100vw - 20px));
    max-width: calc(100vw - 20px);
    padding: 0;
    border: 1px solid #e9dfd5;
    border-radius: 17px;
    background: #fffdfa;
    box-shadow: 0 18px 44px rgba(48, 31, 15, .16);
    z-index: 150;
    overflow: hidden;
  }
  .buyer-account-menu[hidden] { display: none !important; }
  .buyer-account-menu.open { display: block; }
  .buyer-account-heading {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 15px 14px 13px;
    border-bottom: 1px solid #f0e7df;
    background: #fffaf4;
  }
  .buyer-account-avatar {
    display: grid;
    place-items: center;
    flex: 0 0 42px;
    width: 42px;
    height: 42px;
    border: 1px solid rgba(116, 46, 18, .22);
    border-radius: 50%;
    background: linear-gradient(135deg, #A55325, #C2692E);
    color: #FFF8ED;
    box-shadow: inset 0 1px 1px rgba(255,248,237,.14),
                0 2px 6px rgba(102,46,19,.14);
    font-size: .76rem;
    font-weight: 800;
  }
  .buyer-account-heading-copy { min-width: 0; flex: 1; }
  .buyer-account-name {
    display: block;
    overflow: hidden;
    color: #352a24;
    font-size: .83rem;
    font-weight: 800;
    white-space: nowrap;
    text-overflow: ellipsis;
  }
  .buyer-account-email {
    display: block;
    margin-top: 3px;
    overflow: hidden;
    color: #8b8178;
    font-size: .71rem;
    white-space: nowrap;
    text-overflow: ellipsis;
  }
  .buyer-account-role {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-top: 7px;
    color: #a96b36;
    font-size: .65rem;
    font-weight: 700;
  }
  .buyer-account-role svg { width: 13px; height: 13px; }
  .buyer-account-items { display: grid; gap: 3px; padding: 8px; }
  .buyer-account-item {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    min-height: 50px;
    padding: 9px 10px;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: #4b4038;
    text-decoration: none;
    text-align: left;
    cursor: pointer;
    font: inherit;
  }
  .buyer-account-item:hover, .buyer-account-item:focus-visible {
    background: #f8f0e8;
    outline: none;
  }
  .buyer-account-item-icon {
    display: grid;
    place-items: center;
    flex: 0 0 32px;
    width: 32px;
    height: 32px;
    border-radius: 9px;
    background: #f4eee8;
    color: #8f6952;
  }
  .buyer-account-item-icon svg { width: 18px; height: 18px; }
  .buyer-account-item-copy { min-width: 0; flex: 1; }
  .buyer-account-item-copy strong { display: block; font-size: .77rem; font-weight: 750; }
  .buyer-account-item-copy small {
    display: block;
    margin-top: 3px;
    color: #95877d;
    font-size: .66rem;
    line-height: 1.3;
  }
  .buyer-account-chevron { color: #b0a095; font-size: 1rem; }
  /* Switch role uses the same neutral appearance as other items until hovered/focused. */
  .buyer-account-switch { background: transparent; }
  .buyer-account-switch:hover, .buyer-account-switch:focus-visible { background: #ffe9d4; }
  .buyer-account-switch:hover .buyer-account-item-icon,
  .buyer-account-switch:focus-visible .buyer-account-item-icon { background: #ffe3c9; color: #bc742c; }
  .buyer-account-switch:hover .buyer-account-item-copy strong,
  .buyer-account-switch:focus-visible .buyer-account-item-copy strong { color: #a65f24; }
  .buyer-account-footer { margin: 0 8px 8px; padding-top: 7px; border-top: 1px solid #f0e7df; }
  .buyer-account-logout .buyer-account-item-icon { background: #fff0ef; color: #b76b60; }
  .filter-box {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #f6f5f3;
    border: 1px solid #e5e2df;
    border-radius: 12px;
    padding: 8px 11px;
    min-height: 40px;
  }
  .filter-box.search { width: 100%; }
  .buyer-menu-toggle {
    display: none;
    flex: 0 0 auto;
    width: 40px;
    height: 40px;
    padding: 0;
    border: 1px solid #e2e1de;
    border-radius: 10px;
    background: #fff;
    cursor: pointer;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(0,0,0,.03);
  }
  .buyer-menu-toggle:hover { background: #f7f4f1; }
  .buyer-menu-lines {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    width: 18px;
  }
  .buyer-menu-lines span {
    display: block;
    width: 18px;
    height: 2px;
    border-radius: 2px;
    background: #484848;
  }
  .search-icon {
    display: grid;
    place-items: center;
    flex: 0 0 26px;
    width: 26px;
    height: 32px;
    padding: 0;
    border: 0;
    background: transparent;
    color: #7b7b7b;
    cursor: pointer;
  }
  .search-icon svg { display: block; width: 20px; height: 20px; }
  .search-icon .search-close-glyph { display: none; }
  .search-icon:hover { color: #616161; }
  .search-icon:focus-visible { outline: 2px solid #929292; outline-offset: 2px; border-radius: 8px; }
  .filter-box input {
    background: transparent;
    border: none;
    width: 100%;
    min-width: 0;
    font-size: 16px;
    color: #3b3b3b;
    outline: none;
    font-family: inherit;
  }
  body.buyer-modal-open { overflow: hidden; }
  .buyer-logout-modal {
    position: fixed;
    inset: 0;
    z-index: 2147483647;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(35,27,21,.45);
    backdrop-filter: blur(2px);
  }
  .buyer-logout-modal[hidden] { display: none !important; }
  .buyer-logout-modal.open { display: flex; }
  .buyer-logout-card {
    width: min(390px, 100%);
    padding: 28px 24px 24px;
    border: 1px solid #eadfd6;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 22px 55px rgba(30,20,10,.18);
    text-align: center;
  }
  .buyer-logout-icon {
    width: 46px;
    height: 46px;
    margin: 0 auto 14px;
    display: grid;
    place-items: center;
    border-radius: 14px;
    background: #fff0de;
    color: #d77a27;
    font-size: 1.35rem;
    font-weight: 800;
  }
  .buyer-logout-card h2 {
    margin: 0;
    color: #2f2a26;
    font-size: 1.08rem;
  }
  .buyer-logout-card p {
    margin: 8px 0 20px;
    color: #77706a;
    font-size: .84rem;
    line-height: 1.5;
  }
  .buyer-logout-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
  }
  .buyer-logout-actions form { margin: 0; }
  .buyer-logout-cancel,
  .buyer-logout-confirm {
    min-height: 40px;
    padding: 9px 15px;
    border-radius: 10px;
    font: inherit;
    font-size: .8rem;
    font-weight: 700;
    cursor: pointer;
  }
  .buyer-logout-cancel {
    border: 1px solid #ddd6d0;
    background: #fff;
    color: #5a534d;
  }
  .buyer-logout-confirm {
    border: 1px solid #d36f24;
    background: #e68636;
    color: #fff;
  }
  .buyer-logout-cancel:hover { background: #f7f4f1; }
  .buyer-logout-confirm:hover { background: #d8772d; }
  .buyer-logout-confirm:disabled {
    opacity: .65;
    cursor: wait;
  }
  @media (max-width: 840px) {
    .buyer-header-tools {
      left: 0;
      z-index: 110;
      align-items: center;
      gap: 8px;
      padding: 9px 12px;
    }
    .content { padding-top: 76px !important; }
    .page > .content.buyer-header-search-only { padding-top: 76px !important; }
    .buyer-menu-toggle {
      display: inline-flex;
      margin-top: 0;
    }
    .buyer-search-layout {
      flex: 1;
      min-width: 0;
    }
    .filters {
      width: 100%;
      max-width: none;
      grid-template-columns: minmax(0, 1fr);
    }
    .filter-box.search {
      min-width: 0;
      min-height: 40px;
    }
    .filter-box input { min-width: 0; font-size: 16px; }
    .buyer-header-actions {
      gap: 7px;
      padding-top: 1px;
    }
    .buyer-notification,
    .buyer-avatar-button {
      width: 36px;
      height: 36px;
      flex-shrink: 0;
    }
  }
  @media (max-width: 560px) {
    .buyer-logout-actions { flex-direction: column-reverse; }
    .buyer-logout-actions form,
    .buyer-logout-cancel,
    .buyer-logout-confirm {
      width: 100%;
    }
  }
  @media (min-width: 841px) {
    .buyer-menu-toggle { display: none !important; }
  }
  .buyer-header-tools {
    border-bottom: 1px solid rgba(226,225,222,.92);
    border-radius: 0 0 22px 22px;
    background: rgba(255,255,255,.94);
    box-shadow: 0 8px 24px rgba(47,31,19,.055);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
  }
  .filter-box {
    border-radius: 16px;
    background: #f8f6f3;
    transition: border-color .18s ease, background .18s ease, box-shadow .18s ease;
  }
  .filter-box:focus-within {
    border-color: #e5e2df;
    background: #f8f6f3;
    box-shadow: none;
  }
  .buyer-menu-toggle,
  .buyer-notification {
    border-radius: 14px;
  }
  .buyer-logout-card {
    border-radius: 24px;
    padding: 30px 26px 26px;
  }
  .buyer-logout-icon {
    border-radius: 16px;
  }
  .buyer-logout-cancel,
  .buyer-logout-confirm {
    border-radius: 12px;
  }
  @media (max-width: 840px) {
    .buyer-header-tools {
      border-radius: 0 0 18px 18px;
    }
  }
  /* Marketplace header follows the For You page's exact creamy-white background. */
  .buyer-header-tools.marketplace-header { background: #FFF8ED; }

  /* Compact search on phones: icon first, animated input on tap. */
  @media (max-width: 600px) {
    .buyer-search-layout { display: flex; justify-content: flex-end; }
    .buyer-search-layout .filters {
      display: flex;
      flex: 0 1 auto;
      width: 40px;
      min-width: 0;
      max-width: 100%;
      transition: width .28s cubic-bezier(.2,.75,.25,1);
    }
    .buyer-search-layout .filter-box.search {
      width: 100%;
      min-width: 0;
      height: 40px;
      min-height: 40px;
      padding: 0;
      gap: 0;
      border-color: transparent;
      background: transparent;
      box-shadow: none;
      overflow: hidden;
      transition: background .2s ease, border-color .2s ease;
    }
    .buyer-search-layout .search-icon {
      flex: 0 0 40px;
      width: 40px;
      height: 40px;
    }
    .buyer-search-layout .filter-box input {
      flex: 1 1 0;
      width: 0;
      min-width: 0;
      padding: 0;
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
      transition: opacity .16s ease;
    }
    .buyer-search-layout.is-expanded .filters { width: 100%; }
    .buyer-search-layout.is-expanded .filter-box.search {
      background: #f8f6f3;
      border-color: #e5e2df;
    }
    .buyer-search-layout.is-expanded .filter-box input {
      opacity: 1;
      visibility: visible;
      pointer-events: auto;
    }
    .buyer-search-layout.is-expanded .search-magnify-glyph { display: none; }
    .buyer-search-layout.is-expanded .search-icon .search-close-glyph { display: block; }
  }
  @media (prefers-reduced-motion: reduce) {
    .buyer-search-layout .filters, .buyer-search-layout .filter-box.search,
    .buyer-search-layout .filter-box input, .buyer-avatar-button {
      transition: none;
    }
  }
</style>

<div class="buyer-header-tools <?php echo ($activePage ?? '') === 'marketplace' ? 'marketplace-header' : ''; ?>">
  <button
    class="buyer-menu-toggle"
    id="buyerSidebarToggle"
    type="button"
    aria-label="Open navigation menu"
    aria-expanded="false"
    aria-controls="buyerSidebar"
  >
    <span class="buyer-menu-lines" aria-hidden="true"><span></span><span></span><span></span></span>
  </button>
  <div class="buyer-search-layout">
    <div class="filters" id="buyerFilters">
      <div class="filter-box search">
        <button class="search-icon" id="buyerSearchToggle" type="button"
          aria-label="Focus search" aria-controls="productSearch" aria-expanded="false">
          <svg class="search-magnify-glyph" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.35" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 4.5 4.5"/></svg>
          <svg class="search-close-glyph" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.35" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 5l14 14M19 5 5 19"/></svg>
        </button>
        <input
          id="productSearch"
          type="search"
          placeholder="<?php echo htmlspecialchars($searchPlaceholder, ENT_QUOTES, 'UTF-8'); ?>"
          aria-label="Search"
          autocomplete="off"
          enterkeyhint="search"
        >
      </div>
    </div>
  </div>
  <div class="buyer-header-actions">
    <div class="buyer-notifications" id="buyerNotifications">
      <button class="buyer-notification" id="buyerNotificationsToggle" type="button"
        aria-label="Notifications" aria-haspopup="dialog" aria-expanded="false"
        aria-controls="buyerNotificationsPanel" title="Notifications">
        <span class="buyer-notification-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"
            stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/>
            <path d="M10 21h4"/>
          </svg>
        </span>
        <span class="buyer-notification-count" id="buyerNotificationCount" hidden>0</span>
      </button>
    </div>
    <div class="buyer-account">
      <button class="buyer-avatar-button" id="buyerAvatarButton" type="button"
        aria-label="Open account menu" aria-haspopup="menu" aria-controls="buyerAccountMenu" aria-expanded="false">
        <?php echo htmlspecialchars($headerInitials, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
      </button>
      <div class="buyer-account-menu" id="buyerAccountMenu" hidden role="menu" aria-label="Buyer account menu">
        <div class="buyer-account-heading" role="presentation">
          <div class="buyer-account-avatar" aria-hidden="true"><?php echo htmlspecialchars($headerInitials, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
          <div class="buyer-account-heading-copy">
            <strong class="buyer-account-name" title="<?php echo htmlspecialchars($headerUserName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?php echo htmlspecialchars($headerUserName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong>
            <?php if ($headerUserEmail !== ''): ?><span class="buyer-account-email" title="<?php echo htmlspecialchars($headerUserEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?php echo htmlspecialchars($headerUserEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span><?php endif; ?>
            <span class="buyer-account-role"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 10h16l-2-6H6zM5 10v10h14V10M9 20v-6h6v6"/></svg> Buyer Account</span>
          </div>
        </div>
        <div class="buyer-account-items">
          <a class="buyer-account-item" href="profile.php" role="menuitem">
            <span class="buyer-account-item-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></span>
            <span class="buyer-account-item-copy"><strong>My Profile</strong><small>Manage your account information</small></span>
            <span class="buyer-account-chevron" aria-hidden="true">›</span>
          </a>
          <?php if ($headerCanSwitchToSeller): ?>
          <a class="buyer-account-item buyer-account-switch" id="buyerRoleSwitch"
             href="../Seller%20Interface/seller_dashboard.php" data-role-target="seller" role="menuitem">
            <span class="buyer-account-item-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7h14l-3-3M21 7l-3 3M17 17H3l3 3M3 17l3-3"/></svg></span>
            <span class="buyer-account-item-copy"><strong>Switch to Seller</strong><small>Manage your LokalCraft shop</small></span>
            <span class="buyer-account-chevron" aria-hidden="true">↗</span>
          </a>
          <?php endif; ?>
        </div>
        <div class="buyer-account-footer">
          <button class="buyer-account-item buyer-account-logout" type="button" role="menuitem" id="buyerLogoutButton">
            <span class="buyer-account-item-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5M15 16l4-4-4-4M19 12H9"/></svg></span>
            <span class="buyer-account-item-copy"><strong>Log Out</strong></span>
          </button>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- Buyer notification popup (shared header; buyer-only API) -->
<section class="buyer-notification-panel" id="buyerNotificationsPanel" role="dialog"
  aria-modal="false" aria-labelledby="buyerNotificationsTitle" hidden>
  <div class="buyer-notification-panel-heading">
    <div class="buyer-notification-panel-title">
      <span class="buyer-notification-panel-eyebrow">YOUR UPDATES</span>
      <h2 id="buyerNotificationsTitle">Notifications</h2>
      <p>Stay updated on your orders and applications.</p>
    </div>
    <button type="button" class="buyer-notification-panel-close" id="buyerNotificationsClose"
      aria-label="Close notifications" title="Close notifications">&times;</button>
  </div>
  <div class="buyer-notification-panel-toolbar">
    <span class="buyer-notification-panel-subtitle" id="buyerNotificationsSummary">Recent updates</span>
    <button type="button" class="buyer-notification-mark-all" id="buyerNotificationsMarkAll" hidden>
      Mark all as read
    </button>
  </div>
  <div class="buyer-notification-status" id="buyerNotificationStatus" role="status" aria-live="polite">
    Loading notifications...
  </div>
  <div class="buyer-notification-list" id="buyerNotificationList" hidden></div>
  <div class="buyer-notification-panel-footer">Showing your 20 most recent notifications</div>
</section>
<template id="buyerNotificationItemTemplate">
  <button class="buyer-notification-item" type="button">
    <span class="buyer-notification-item-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
        stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="6" width="18" height="15" rx="2"/><path d="M3 10h18M8 3v3M16 3v3"/>
      </svg>
    </span>
    <span class="buyer-notification-item-details">
      <span class="buyer-notification-item-title"></span>
      <span class="buyer-notification-item-message"></span>
      <span class="buyer-notification-item-date"></span>
    </span>
    <span class="buyer-notification-item-dot" aria-label="Unread"></span>
  </button>
</template>

<div class="buyer-logout-modal" id="buyerLogoutModal" hidden aria-hidden="true">
  <div class="buyer-logout-card" role="dialog" aria-modal="true" aria-labelledby="buyerLogoutTitle">
    <div class="buyer-logout-icon" aria-hidden="true">↪</div>
    <h2 id="buyerLogoutTitle">Log out of LokalCraft?</h2>
    <p>Are you sure you want to log out of your account?</p>
    <div class="buyer-logout-actions">
      <button type="button" class="buyer-logout-cancel" id="buyerLogoutCancel">No, Stay</button>
      <form action="logout.php" method="POST" id="buyerLogoutForm">
        <input
          type="hidden"
          name="csrf_token"
          value="<?php echo htmlspecialchars($_SESSION['buyer_logout_csrf'], ENT_QUOTES, 'UTF-8'); ?>"
        >
        <button type="submit" class="buyer-logout-confirm">Yes, Logout</button>
      </form>
    </div>
  </div>
</div>
<script>
(function () {
  document.querySelector('.buyer-header-tools')?.closest('.content')?.classList.add('buyer-header-search-only');
  const productSearch = document.getElementById('productSearch');
  const buyerSearchToggle = document.getElementById('buyerSearchToggle');
  const buyerSearchLayout = document.querySelector('.buyer-search-layout');
  const compactBuyerSearch = window.matchMedia('(max-width: 600px)');
  function applyBuyerSearch() {
    const searchTerm = String(productSearch?.value || '').trim().toLowerCase();
    const cards = document.querySelectorAll('.product-grid .product-card');
    if (!cards.length) return;
    let visible = 0;
    cards.forEach((card) => {
      const name = String(card.dataset.name || '').trim().toLowerCase();
      const seller = String(card.dataset.seller || '').trim().toLowerCase();
      const matches = !searchTerm || `${name} ${seller}`.includes(searchTerm);
      card.hidden = !matches;
      card.style.display = matches ? '' : 'none';
      if (matches) visible++;
    });
    const noResults = document.getElementById('buyerNoResults');
    if (noResults) noResults.hidden = visible !== 0;
  }
  if (document.querySelector('.buyer-header-tools')?.classList.contains('marketplace-header')) {
    productSearch?.addEventListener('input', applyBuyerSearch);
  }
  function closeCompactBuyerSearch() {
    if (!buyerSearchLayout?.classList.contains('is-expanded')) return;
    buyerSearchLayout.classList.remove('is-expanded');
    productSearch?.blur();
    // A hidden search must not leave product cards filtered without any visible query.
    if (productSearch && productSearch.value !== '') {
      productSearch.value = '';
      applyBuyerSearch();
    }
    buyerSearchToggle?.setAttribute('aria-label', 'Open search');
    buyerSearchToggle?.setAttribute('aria-expanded', 'false');
  }
  buyerSearchToggle?.addEventListener('click', () => {
    if (!compactBuyerSearch.matches) {
      productSearch?.focus();
      return;
    }
    if (buyerSearchLayout?.classList.contains('is-expanded')) {
      closeCompactBuyerSearch();
      return;
    }
    buyerSearchLayout?.classList.add('is-expanded');
    buyerSearchToggle.setAttribute('aria-label', 'Close search');
    buyerSearchToggle.setAttribute('aria-expanded', 'true');
    window.requestAnimationFrame(() => productSearch?.focus({preventScroll: true}));
  });
  productSearch?.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && compactBuyerSearch.matches) {
      event.preventDefault();
      closeCompactBuyerSearch();
      buyerSearchToggle?.focus();
    }
  });
  document.addEventListener('pointerdown', (event) => {
    if (compactBuyerSearch.matches && buyerSearchLayout?.classList.contains('is-expanded')
        && !buyerSearchLayout.contains(event.target)) closeCompactBuyerSearch();
  });
  const syncCompactBuyerSearch = () => {
    if (!compactBuyerSearch.matches) {
      buyerSearchLayout?.classList.remove('is-expanded');
      buyerSearchToggle?.setAttribute('aria-label', 'Focus search');
      buyerSearchToggle?.removeAttribute('aria-expanded');
      return;
    }
    buyerSearchToggle?.setAttribute('aria-label', 'Open search');
    buyerSearchToggle?.setAttribute('aria-expanded', 'false');
  };
  if (compactBuyerSearch.addEventListener) compactBuyerSearch.addEventListener('change', syncCompactBuyerSearch);
  else compactBuyerSearch.addListener(syncCompactBuyerSearch);
  syncCompactBuyerSearch();
  // Shared Buyer notifications: reads from buyer_notifications.php; does not create order events.
  const notificationToggle = document.getElementById('buyerNotificationsToggle');
  const notificationRoot = document.getElementById('buyerNotifications');
  const notificationPanel = document.getElementById('buyerNotificationsPanel');
  const notificationClose = document.getElementById('buyerNotificationsClose');
  const notificationCount = document.getElementById('buyerNotificationCount');
  const notificationSummary = document.getElementById('buyerNotificationsSummary');
  const notificationMarkAll = document.getElementById('buyerNotificationsMarkAll');
  const notificationList = document.getElementById('buyerNotificationList');
  const notificationStatus = document.getElementById('buyerNotificationStatus');
  const notificationTemplate = document.getElementById('buyerNotificationItemTemplate');
  const notificationEndpoint = 'buyer_notifications.php';
  let notificationCsrf = '';
  let notificationUnreadCount = 0;
  let notificationLoading = false;
  let notificationActionPending = false;

  // Keep the popup outside the fixed header's stacking/overflow context.
  if (notificationPanel && notificationPanel.parentElement !== document.body) {
    document.body.appendChild(notificationPanel);
  }

  function updateNotificationCount(count) {
    const total = Math.max(0, Number(count) || 0);
    notificationUnreadCount = total;
    if (notificationCount) {
      notificationCount.hidden = total === 0;
      notificationCount.textContent = total > 99 ? '99+' : String(total);
    }
    notificationToggle?.setAttribute('aria-label', total === 0
      ? 'Notifications, no unread updates'
      : `Notifications, ${total} unread`);
    if (notificationSummary) {
      notificationSummary.textContent = total === 0
        ? 'Recent updates'
        : `${total} unread ${total === 1 ? 'notification' : 'notifications'}`;
    }
    if (notificationMarkAll) {
      notificationMarkAll.hidden = total === 0;
      notificationMarkAll.disabled = total > 0 && !notificationCsrf;
    }
  }

  function positionBuyerNotifications() {
    if (!notificationPanel || notificationPanel.hidden || !notificationToggle) return;
    const rect = notificationToggle.getBoundingClientRect();
    const panelWidth = notificationPanel.getBoundingClientRect().width;
    const left = Math.max(12, Math.min(rect.right - panelWidth, window.innerWidth - panelWidth - 12));
    const below = window.innerHeight - rect.bottom - 18;
    notificationPanel.style.left = `${left}px`;
    if (below >= 230 || below >= rect.top) {
      notificationPanel.style.top = `${rect.bottom + 10}px`;
      notificationPanel.style.bottom = 'auto';
      notificationPanel.style.maxHeight = `${Math.max(130, below)}px`;
    } else {
      notificationPanel.style.top = 'auto';
      notificationPanel.style.bottom = `${window.innerHeight - rect.top + 10}px`;
      notificationPanel.style.maxHeight = `${Math.max(130, rect.top - 22)}px`;
    }
  }

  function closeBuyerNotifications(restoreFocus = false) {
    if (!notificationPanel || notificationPanel.hidden) return;
    notificationPanel.hidden = true;
    notificationToggle?.setAttribute('aria-expanded', 'false');
    if (restoreFocus) notificationToggle?.focus({preventScroll: true});
  }

  function showNotificationStatus(message, retry = false) {
    if (!notificationStatus || !notificationList) return;
    notificationList.hidden = true;
    notificationStatus.hidden = false;
    notificationStatus.replaceChildren();
    const text = document.createElement('p');
    text.textContent = message;
    notificationStatus.appendChild(text);
    if (retry) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'buyer-notification-retry';
      button.textContent = 'Try again';
      button.addEventListener('click', () => { void loadBuyerNotifications(); });
      notificationStatus.appendChild(button);
    }
  }

  async function notificationRequest(action, body = null) {
    const options = {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}};
    let url = `${notificationEndpoint}?action=${encodeURIComponent(action)}`;
    if (body) {
      options.method = 'POST';
      options.body = new URLSearchParams({action, csrf_token: notificationCsrf, ...body});
    }
    const response = await fetch(url, options);
    let result;
    try { result = await response.json(); }
    catch (_) { throw new Error('Notifications are unavailable. Please try again.'); }
    if (!response.ok || !result?.success) {
      throw new Error(result?.message || 'Notifications are unavailable. Please try again.');
    }
    return result;
  }

  function notificationDateLabel(raw) {
    // Database DATETIME/TIMESTAMP text has no timezone offset; display as stored local time.
    const value = String(raw || '');
    const match = value.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    if (!match) return value;
    const [, year, month, day, hour, minute] = match;
    const date = new Date(Number(year), Number(month) - 1, Number(day), Number(hour), Number(minute));
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString(undefined, {month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'});
  }

  function notificationVisual(item) {
    const label = `${String(item.type || '')} ${String(item.title || '')}`.toLowerCase();
    if (/reject|declin|cancel|fail/.test(label)) return {status: 'rejected', icon: 'rejected'};
    if (/pay.?now|remind|payment.?due|awaiting.?payment|pending.?payment/.test(label))
      return {status: 'reminder', icon: 'reminder'};
    if (/payment|qr.?ph|paid|receipt/.test(label)) {
      if (/confirm|success|received|completed|paid/.test(label)) return {status: 'success', icon: 'paid'};
      return {status: 'payment', icon: 'payment'};
    }
    if (/deliver|courier|ship/.test(label)) return {status: 'progress', icon: 'delivery'};
    if (/prepar|process|pack/.test(label)) return {status: 'progress', icon: 'progress'};
    if (/approv|complet|accept|ready|received|confirm/.test(label))
      return {status: 'success', icon: 'accepted'};
    return {status: 'info', icon: 'info'};
  }
  function renderBuyerNotifications(items) {
    if (!notificationList || !notificationTemplate || !notificationStatus) return;
    notificationList.replaceChildren();
    if (!Array.isArray(items) || items.length === 0) {
      showNotificationStatus('No notifications yet. Order and application updates will appear here.');
      return;
    }
    const fragment = document.createDocumentFragment();
    items.forEach((item) => {
      const button = notificationTemplate.content.firstElementChild.cloneNode(true);
      const visual = notificationVisual(item);
      button.classList.add(`is-${visual.status}`);
      if (!item.read) button.classList.add('is-unread');
      button.querySelector('.buyer-notification-item-title').textContent = String(item.title || 'Order update');
      button.querySelector('.buyer-notification-item-message').textContent = String(item.message || '');
      button.querySelector('.buyer-notification-item-date').textContent = notificationDateLabel(item.created_at);
      const unreadDot = button.querySelector('.buyer-notification-item-dot');
      if (unreadDot) unreadDot.hidden = Boolean(item.read);
      if (item.can_open === false) button.disabled = true;
      else button.addEventListener('click', () => { void openBuyerNotification(item.id, button); });
      fragment.appendChild(button);
    });
    notificationList.appendChild(fragment);
    notificationStatus.hidden = true;
    notificationList.hidden = false;
  }

  async function loadBuyerNotifications() {
    if (notificationLoading || !notificationPanel || notificationPanel.hidden) return;
    notificationLoading = true;
    if (!notificationList || notificationList.hidden) showNotificationStatus('Loading notifications...');
    try {
      const result = await notificationRequest('list');
      notificationCsrf = String(result.csrf_token || '');
      updateNotificationCount(result.unread_count);
      if (!notificationPanel.hidden) renderBuyerNotifications(result.notifications);
    } catch (error) {
      if (!notificationPanel.hidden) showNotificationStatus(error.message, true);
    } finally {
      notificationLoading = false;
    }
  }

  async function refreshBuyerNotificationCount() {
    if (document.hidden || notificationActionPending) return;
    try {
      const result = await notificationRequest('count');
      const previousCount = notificationUnreadCount;
      updateNotificationCount(result.unread_count);
      if (!notificationPanel?.hidden && previousCount !== notificationUnreadCount) {
        void loadBuyerNotifications();
      }
    } catch (_) {
      // A background polling failure should not disturb the existing header.
    }
  }

  async function openBuyerNotification(id, button) {
    if (notificationActionPending || !notificationCsrf) return;
    notificationActionPending = true;
    button.disabled = true;
    try {
      const result = await notificationRequest('mark_read', {notification_id: String(id)});
      if (typeof result.url !== 'string' || result.url.trim() === '') {
        const wasUnread = button.classList.contains('is-unread');
        button.classList.remove('is-unread');
        const dot = button.querySelector('.buyer-notification-item-dot');
        if (dot) dot.hidden = true;
        button.querySelector('.buyer-notification-item-message').textContent =
          'This linked record is no longer available.';
        if (wasUnread) updateNotificationCount(notificationUnreadCount - 1);
        return;
      }
      const destination = new URL(result.url, window.location.href);
      // Redirect only to a same-origin page in the existing Buyer Interface directory.
      const buyerDirectory = new URL('./', window.location.href).pathname;
      if (destination.origin !== window.location.origin || !destination.pathname.startsWith(buyerDirectory)) {
        throw new Error('The notification destination could not be verified.');
      }
      window.location.assign(destination.href);
    } catch (error) {
      showNotificationStatus(error.message, true);
    } finally {
      notificationActionPending = false;
      button.disabled = false;
    }
  }

  async function markAllBuyerNotifications() {
    if (notificationActionPending || !notificationCsrf || notificationUnreadCount === 0) return;
    notificationActionPending = true;
    notificationMarkAll.disabled = true;
    try {
      await notificationRequest('mark_all_read', {});
      updateNotificationCount(0);
      if (!notificationPanel.hidden) await loadBuyerNotifications();
    } catch (error) {
      showNotificationStatus(error.message, true);
    } finally {
      notificationActionPending = false;
      notificationMarkAll.disabled = false;
    }
  }

  notificationToggle?.addEventListener('click', (event) => {
    event.stopPropagation();
    if (!notificationPanel) return;
    if (!notificationPanel.hidden) {
      closeBuyerNotifications();
      return;
    }
    // Open only one header popup at a time.
    closeAccountMenu();
    notificationPanel.hidden = false;
    notificationToggle.setAttribute('aria-expanded', 'true');
    positionBuyerNotifications();
    void loadBuyerNotifications();
  });
  notificationClose?.addEventListener('click', () => closeBuyerNotifications(true));
  notificationMarkAll?.addEventListener('click', () => { void markAllBuyerNotifications(); });
  document.addEventListener('click', (event) => {
    if (notificationPanel?.hidden) return;
    if (!notificationRoot?.contains(event.target) && !notificationPanel?.contains(event.target)) {
      closeBuyerNotifications();
    }
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !notificationPanel?.hidden) {
      event.preventDefault();
      closeBuyerNotifications(true);
    }
  });
  window.addEventListener('resize', positionBuyerNotifications);
  window.addEventListener('scroll', positionBuyerNotifications, {passive: true});
  if (notificationToggle) {
    void refreshBuyerNotificationCount();
    window.setInterval(() => { void refreshBuyerNotificationCount(); }, 45000);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) void refreshBuyerNotificationCount();
    });
  }

  const buyerAvatarButton = document.getElementById('buyerAvatarButton');
  const buyerAccountMenu = document.getElementById('buyerAccountMenu');
  const accountMenuItems = () => [...(buyerAccountMenu?.querySelectorAll('[role="menuitem"]') || [])];
  function closeAccountMenu(restoreFocus = false) {
    buyerAccountMenu?.classList.remove('open');
    if (buyerAccountMenu) buyerAccountMenu.hidden = true;
    buyerAvatarButton?.setAttribute('aria-expanded', 'false');
    if (restoreFocus) buyerAvatarButton?.focus({preventScroll: true});
  }
  function openAccountMenu(focusFirst = false) {
    closeBuyerNotifications();
    if (buyerAccountMenu) buyerAccountMenu.hidden = false;
    buyerAccountMenu?.classList.add('open');
    buyerAvatarButton?.setAttribute('aria-expanded', 'true');
    if (focusFirst) accountMenuItems()[0]?.focus({preventScroll: true});
  }
  buyerAvatarButton?.addEventListener('click', event => {
    event.stopPropagation();
    buyerAccountMenu?.classList.contains('open') ? closeAccountMenu() : openAccountMenu();
  });
  buyerAvatarButton?.addEventListener('keydown', event => {
    if (event.key === 'ArrowDown' || event.key === 'Enter' && !buyerAccountMenu?.classList.contains('open')) {
      event.preventDefault();
      openAccountMenu(true);
    }
  });
  buyerAccountMenu?.addEventListener('keydown', event => {
    const items = accountMenuItems();
    const index = items.indexOf(document.activeElement);
    if (event.key === 'Escape') {
      event.preventDefault();
      closeAccountMenu(true);
    } else if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
      event.preventDefault();
      const next = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1
        : (index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
      items[next]?.focus({preventScroll: true});
    }
  });
  document.addEventListener('click', event => {
    if (!event.target.closest('.buyer-account')) closeAccountMenu();
  });
  const logoutButton = document.getElementById('buyerLogoutButton');
  const logoutModal = document.getElementById('buyerLogoutModal');
  const logoutCancel = document.getElementById('buyerLogoutCancel');
  const logoutForm = document.getElementById('buyerLogoutForm');
  if (logoutModal && logoutModal.parentElement !== document.body) {
    document.body.appendChild(logoutModal);
  }
  function openLogoutModal() {
    if (!logoutModal) return;
    closeAccountMenu();
    logoutModal.hidden = false;
    logoutModal.classList.add('open');
    logoutModal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('buyer-modal-open');
    window.setTimeout(() => logoutCancel?.focus(), 0);
  }
  function closeLogoutModal() {
    if (!logoutModal) return;
    logoutModal.classList.remove('open');
    logoutModal.hidden = true;
    logoutModal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('buyer-modal-open');
  }
  logoutButton?.addEventListener('click', openLogoutModal);
  logoutCancel?.addEventListener('click', closeLogoutModal);
  logoutModal?.addEventListener('click', (event) => {
    if (event.target === logoutModal) closeLogoutModal();
  });
  logoutForm?.addEventListener('submit', () => {
    const submitButton = logoutForm.querySelector('button[type="submit"]');
    if (submitButton) {
      submitButton.disabled = true;
      submitButton.textContent = 'Logging out...';
    }
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && logoutModal?.classList.contains('open')) {
      closeLogoutModal();
      buyerAvatarButton?.focus({preventScroll: true});
    } else if (event.key === 'Escape' && buyerAccountMenu?.classList.contains('open')) {
      closeAccountMenu(true);
    }
  });
})();
</script>

