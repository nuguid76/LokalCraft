<?php
require_once __DIR__ . '/seller_auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$activePage = $activePage ?? 'dashboard';
$userName = $userName ?? ($_SESSION['seller_name'] ?? ($_SESSION['user_name'] ?? 'Seller'));
$businessName = $businessName ?? ($_SESSION['seller_business_name'] ?? 'Seller Portal');
if (empty($_SESSION['seller_role_switch_csrf'])) {
    $_SESSION['seller_role_switch_csrf'] = bin2hex(random_bytes(32));
}
$roleSwitchCsrf = $_SESSION['seller_role_switch_csrf'];
function sellerNavUnreadCount(mysqli $conn, int $sellerUserId): int
{
    if ($sellerUserId <= 0) {
        return 0;
    }
    $stmt = $conn->prepare('
        SELECT COUNT(*) AS unread_count
        FROM messages m
        LEFT JOIN orders o ON o.order_id = m.order_id
        WHERE m.receiver_id = ?
          AND m.sender_id <> ?
          AND m.is_read = 0
          AND (
                (
                    m.order_id IS NOT NULL
                    AND o.seller_id = ?
                    AND o.buyer_id = m.sender_id
                )
                OR
                (
                    m.order_id IS NULL
                    AND (
                        SELECT CASE
                            WHEN first_message.sender_id = m.sender_id
                             AND first_message.receiver_id = ?
                            THEN 1
                            ELSE 0
                        END
                        FROM messages first_message
                        WHERE first_message.order_id IS NULL
                          AND (
                                (
                                    first_message.sender_id = m.sender_id
                                    AND first_message.receiver_id = ?
                                )
                                OR
                                (
                                    first_message.sender_id = ?
                                    AND first_message.receiver_id = m.sender_id
                                )
                          )
                        ORDER BY first_message.message_id ASC
                        LIMIT 1
                    ) = 1
                )
          )
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare seller unread message count.');
    }
    $stmt->bind_param(
        'iiiiii',
        $sellerUserId,
        $sellerUserId,
        $sellerUserId,
        $sellerUserId,
        $sellerUserId,
        $sellerUserId
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return max(0, (int)($row['unread_count'] ?? 0));
}
$sellerUnreadMessageCount = 0;
try {
    $sellerUnreadMessageCount = sellerNavUnreadCount($conn, (int)$sellerId);
} catch (Throwable $e) {
    error_log('LokalCraft seller unread message count error: ' . $e->getMessage());
    $sellerUnreadMessageCount = 0;
}
if (isset($_GET['seller_nav_unread'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode([
        'success' => true,
        'unread_count' => $sellerUnreadMessageCount
    ]);
    exit;
}
$sellerUnreadMessageLabel = $sellerUnreadMessageCount > 99
    ? '99+'
    : (string)$sellerUnreadMessageCount;
?>
<aside class="sidebar" id="sellerSidebar">
  <div class="brand">
    <div class="brand-mark">L</div>
    <div class="brand-text">LokalCraft<span class="brand-sub">Seller Portal</span></div>
  </div>
  <nav class="nav" aria-label="Seller navigation">
    <a
      class="nav-item <?php echo $activePage === 'dashboard' ? 'active' : ''; ?>"
      href="seller_dashboard.php"
      <?php echo $activePage === 'dashboard' ? 'aria-current="page"' : ''; ?>
    ><span class="icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="8" height="8" rx="1.7"/><rect x="13" y="3" width="8" height="5" rx="1.7"/><rect x="13" y="10" width="8" height="11" rx="1.7"/><rect x="3" y="13" width="8" height="8" rx="1.7"/></svg></span>Dashboard</a>
    <a
      class="nav-item <?php echo $activePage === 'products' ? 'active' : ''; ?>"
      href="products.php"
      <?php echo $activePage === 'products' ? 'aria-current="page"' : ''; ?>
    ><span class="icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12V5a2 2 0 0 1 2-2h7l9 9-9 9-9-9Z"/><circle cx="8" cy="8" r="1.35"/></svg></span>Products</a>
    <a
      class="nav-item <?php echo $activePage === 'orders' ? 'active' : ''; ?>"
      href="orders_seller.php"
      <?php echo $activePage === 'orders' ? 'aria-current="page"' : ''; ?>
    ><span class="icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3h14v18l-3-2-4 2-4-2-3 2V3Z"/><path d="M9 8h6M9 12h6M9 16h3"/></svg></span>Orders</a>
    <a
      class="nav-item <?php echo $activePage === 'wallet' ? 'active' : ''; ?>"
      href="seller_wallet.php"
      <?php echo $activePage === 'wallet' ? 'aria-current="page"' : ''; ?>
    ><span class="icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7V5.5A2.5 2.5 0 0 1 6.5 3H18"/><rect x="3" y="7" width="18" height="14" rx="2.5"/><path d="M17 12h4v5h-4a2.5 2.5 0 0 1 0-5Z"/><circle cx="17.5" cy="14.5" r=".55" fill="currentColor" stroke="none"/><text x="9" y="16.5" font-size="8" font-family="Arial, sans-serif" stroke="none" fill="currentColor" font-weight="700">L</text></svg></span>Wallet</a>
    <a
      class="nav-item <?php echo $activePage === 'pricing' ? 'active' : ''; ?>"
      href="calculator.php"
      <?php echo $activePage === 'pricing' ? 'aria-current="page"' : ''; ?>
    ><span class="icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v17M7 21h10M5 7h14M12 3a2 2 0 0 1 2 2M12 3a2 2 0 0 0-2 2"/><path d="m5 7-3 7h6L5 7Zm14 0-3 7h6l-3-7Z"/></svg></span>Pricing &amp; Costing</a>
    <a class="nav-item <?php echo $activePage === 'messages' ? 'active' : ''; ?>" href="messages_seller.php" <?php echo $activePage === 'messages' ? 'aria-current="page"' : ''; ?> aria-label="Messages<?php echo $sellerUnreadMessageCount > 0 ? ', ' . $sellerUnreadMessageCount . ' unread in Seller Portal' : ''; ?>"><span class="icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11.5a8.5 8.5 0 0 1-8.5 8.5 9 9 0 0 1-4.4-1.1L3 20l1.15-4.3A8.5 8.5 0 1 1 20 11.5Z"/><path d="M8 11.5h8M8 15h5"/></svg></span><span class="nav-label">Messages</span><span class="seller-message-badge" id="sellerMessageUnreadBadge" title="Unread messages in Seller Portal" <?php echo $sellerUnreadMessageCount > 0 ? '' : 'hidden'; ?>><?php echo htmlspecialchars($sellerUnreadMessageLabel, ENT_QUOTES, 'UTF-8'); ?></span></a>
  </nav>
  <form id="sellerRoleSwitchForm" method="POST" action="switch_to_buyer.php" hidden>
    <input
      type="hidden"
      name="csrf_token"
      value="<?php echo htmlspecialchars($roleSwitchCsrf, ENT_QUOTES, 'UTF-8'); ?>"
    >
  </form>
</aside>
<div class="seller-sidebar-overlay" id="sellerSidebarOverlay" aria-hidden="true"></div>
<div class="role-transition-screen" id="sellerRoleTransition" aria-hidden="true">
  <div class="role-transition-card">
    <div class="role-transition-mark">L</div>
    <div class="role-transition-title">Switching Portal</div>
    <div class="role-transition-subtitle" id="sellerRoleTransitionSubtitle">Opening Buyer Portal...</div>
  </div>
</div>
<style>
:root {
  --seller-orange: #ea8a3a;
  --seller-orange-dark: #c96d2a;
  --seller-orange-soft: #fff3e8;
  --seller-text: #55504b;
  --seller-muted: #8d8782;
  --seller-line: #e7e4e1;
}
.sidebar {
  width: 250px;
  flex: 0 0 250px;
  position: fixed;
  inset: 0 auto 0 0;
  height: 100vh;
  overflow-y: auto;
  z-index: 120;
  display: flex;
  flex-direction: column;
  padding: 14px 10px 10px;
  border-right: 1px solid var(--seller-line);
  border-radius: 0 28px 28px 0;
  background: #fff;
  box-shadow: 8px 0 28px rgba(47,31,19,.055);
  transition: transform .25s ease, box-shadow .25s ease, opacity .24s ease;
}
.brand {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 5px 5px 17px;
  border-bottom: 1px solid #ececec;
}
.brand-mark {
  width: 34px;
  height: 34px;
  display: grid;
  place-items: center;
  border-radius: 10px;
  background: linear-gradient(135deg, #f7b875, #ea8a3a);
  color: #fff;
  font-weight: 800;
  box-shadow: 0 7px 15px rgba(234,138,58,.18);
}
.brand-text {
  color: var(--seller-orange);
  font-size: 1rem;
  font-weight: 800;
  line-height: 1.1;
}
.brand-sub {
  display: block;
  margin-top: 3px;
  color: #929292;
  font-size: .63rem;
  font-weight: 500;
}
.nav {
  display: flex;
  flex-direction: column;
  gap: 5px;
  margin-top: 18px;
}
.nav-item {
  min-height: 40px;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 9px 11px;
  border: 1px solid transparent;
  border-radius: 10px;
  color: #747474;
  font-size: .82rem;
  font-weight: 600;
  text-decoration: none;
  transition: background .18s ease, color .18s ease, border-color .18s ease, box-shadow .18s ease;
}
.nav-item:hover {
  border-color: transparent;
  background: #f8f0e8;
  color: #4a3023;
}
.nav-item.active {
  border-color: #f2d9c5;
  background: #fff0e2;
  color: #a45e27;
}
#sellerSidebar .nav-item .icon {
  display: inline-grid;
  place-items: center;
  width: 33px;
  height: 33px;
  flex: 0 0 33px;
  border-radius: 10px;
  background: #f5efe9;
  color: #8f6952;
}
#sellerSidebar .nav-item .icon svg { width: 19px; height: 19px; display: block; }
#sellerSidebar .nav-item:hover .icon { background: #f3e6d8; color: #ae713f; }
#sellerSidebar .nav-item.active .icon { background: #ffe2c7; color: #bb6f2f; }
#sellerSidebar .nav-item:focus-visible { outline: 2px solid #e1a16c; outline-offset: 2px; }
.nav-label {
  min-width: 0;
  flex: 1;
}
.seller-message-badge {
  min-width: 21px;
  height: 21px;
  display: inline-grid;
  place-items: center;
  flex: 0 0 auto;
  padding: 0 6px;
  border-radius: 999px;
  background: var(--seller-orange);
  color: #fff;
  font-size: .59rem;
  font-weight: 800;
  line-height: 1;
}
.seller-message-badge[hidden] {
  display: none !important;
}
.secondary-nav {
  margin-top: 0;
}
.sidebar-footer {
  margin-top: auto;
  padding: 12px 4px 0;
  border-top: 1px solid #ececec;
}
.profile-summary {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
}
.avatar {
  width: 30px;
  height: 30px;
  display: grid;
  place-items: center;
  border-radius: 50%;
  background: #f6d7a9;
  color: #a9682b;
  font-size: .68rem;
  font-weight: 800;
}
.profile-name {
  color: #505050;
  font-size: .7rem;
  font-weight: 700;
}
.profile-role {
  max-width: 150px;
  overflow: hidden;
  margin-top: 2px;
  color: #999;
  font-size: .59rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.logout {
  width: 100%;
  padding: 7px;
  border: 1px solid #e7e7e7;
  border-radius: 8px;
  background: #fff;
  color: #555;
  font-size: .68rem;
  cursor: pointer;
}
.sidebar ~ .content,
.sidebar ~ .main {
  position: fixed !important;
  top: 0 !important;
  right: 0 !important;
  bottom: 0 !important;
  left: 250px !important;
  width: auto !important;
  max-width: none !important;
  height: auto !important;
  min-height: 100vh !important;
  margin: 0 !important;
  overflow-y: auto !important;
  overflow-x: hidden !important;
}
.page,
.app {
  display: block !important;
  min-height: 100vh !important;
}
.seller-sidebar-overlay {
  display: none;
  position: fixed;
  inset: 0;
  z-index: 115;
  background: rgba(25,20,16,.38);
  backdrop-filter: blur(1.5px);
  -webkit-backdrop-filter: blur(1.5px);
}
body.seller-menu-open {
  overflow: hidden;
}
.role-transition-screen {
  position: fixed;
  inset: 0;
  z-index: 1000000;
  display: grid;
  place-items: center;
  background: rgba(247,245,242,.96);
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
  transition: opacity .24s ease, visibility .24s ease;
}
.role-transition-screen.show {
  opacity: 1;
  visibility: visible;
  pointer-events: auto;
}
.role-transition-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  transform: translateY(8px) scale(.98);
  transition: transform .24s ease;
}
.role-transition-screen.show .role-transition-card {
  transform: translateY(0) scale(1);
}
.role-transition-mark {
  width: 44px;
  height: 44px;
  display: grid;
  place-items: center;
  margin-bottom: 10px;
  border-radius: 12px;
  background: linear-gradient(135deg, #f7b875, #ea8a3a);
  color: #fff;
  font-size: 1.15rem;
  font-weight: 800;
  box-shadow: 0 8px 18px rgba(234,138,58,.18);
}
.role-transition-title {
  color: #3c3028;
  font-size: .9rem;
  font-weight: 800;
}
.role-transition-subtitle {
  margin-top: 4px;
  color: #8a817a;
  font-size: .66rem;
}
body.role-transitioning .sidebar {
  transform: translateX(-22px);
  opacity: 0;
}
body.role-transitioning .main,
body.role-transitioning .content {
  transform: translateX(8px);
  opacity: .55;
}
.sidebar.role-returning {
  animation: sellerRoleSidebarEnter .3s ease both;
}
@keyframes sellerRoleSidebarEnter {
  from {
    opacity: 0;
    transform: translateX(-24px);
  }
  to {
    opacity: 1;
    transform: translateX(0);
  }
}
@media (prefers-reduced-motion: reduce) {
  .role-transition-screen,
  .role-transition-card {
    transition: none !important;
  }
}
@media (max-width: 760px) {
  .sidebar {
    width: min(82vw, 300px) !important;
    flex: 0 0 auto !important;
    position: fixed !important;
    inset: 0 auto 0 0 !important;
    height: 100vh !important;
    min-height: 100vh !important;
    max-height: none !important;
    overflow-y: auto !important;
    border-right: 1px solid var(--seller-line) !important;
    border-bottom: 0 !important;
    border-radius: 0 24px 24px 0 !important;
    transform: translateX(-105%);
    box-shadow: 16px 0 32px rgba(30,20,10,.14);
  }
  .sidebar.seller-mobile-open {
    transform: translateX(0);
  }
  .seller-sidebar-overlay.seller-mobile-open {
    display: block;
  }
  .sidebar ~ .content,
  .sidebar ~ .main {
    box-sizing: border-box !important;
    position: relative !important;
    top: auto !important;
    right: auto !important;
    bottom: auto !important;
    left: auto !important;
    width: 100% !important;
    max-width: none !important;
    height: auto !important;
    min-height: 100vh !important;
    margin: 0 !important;
    overflow-y: visible !important;
    overflow-x: visible !important;
  }
  .page,
  .app {
    display: flex !important;
    flex-direction: column !important;
    min-height: 100vh !important;
  }
}
</style>
<script>
(function () {
  const sidebar = document.getElementById('sellerSidebar');
  const sellerMessageUnreadBadge = document.getElementById('sellerMessageUnreadBadge');
  window.setSellerUnreadMessageCount = function (count) {
    if (!sellerMessageUnreadBadge) return;
    const normalizedCount = Math.max(0, Number.parseInt(count, 10) || 0);
    if (normalizedCount <= 0) {
      sellerMessageUnreadBadge.hidden = true;
      sellerMessageUnreadBadge.textContent = '';
      return;
    }
    sellerMessageUnreadBadge.hidden = false;
    sellerMessageUnreadBadge.textContent = normalizedCount > 99 ? '99+' : String(normalizedCount);
  };
  let sellerUnreadPollTimer = null;
  let sellerUnreadRequestRunning = false;
  async function refreshSellerUnreadMessageCount() {
    if (sellerUnreadRequestRunning || document.hidden) return;
    sellerUnreadRequestRunning = true;
    try {
      const response = await fetch(
        'seller_nav.php?seller_nav_unread=1',
        {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: {
            'Accept': 'application/json'
          }
        }
      );
      if (!response.ok) return;
      const payload = await response.json();
      if (payload && payload.success === true) {
        window.setSellerUnreadMessageCount(payload.unread_count || 0);
      }
    } catch (error) {
    } finally {
      sellerUnreadRequestRunning = false;
    }
  }
  function startSellerUnreadPolling() {
    if (sellerUnreadPollTimer) {
      window.clearInterval(sellerUnreadPollTimer);
    }
    refreshSellerUnreadMessageCount();
    sellerUnreadPollTimer = window.setInterval(
      refreshSellerUnreadMessageCount,
      3000
    );
  }
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
      refreshSellerUnreadMessageCount();
    }
  });
  window.addEventListener('focus', refreshSellerUnreadMessageCount);
  startSellerUnreadPolling();
  const overlay = document.getElementById('sellerSidebarOverlay');
  function openSellerSidebar() {
    if (!sidebar || !overlay) return;
    sidebar.classList.add('seller-mobile-open');
    overlay.classList.add('seller-mobile-open');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.classList.add('seller-menu-open');
    const toggle = document.getElementById('sellerSidebarToggle');
    if (toggle) {
      toggle.setAttribute('aria-expanded', 'true');
      toggle.setAttribute('aria-label', 'Close navigation menu');
    }
  }
  function closeSellerSidebar() {
    if (!sidebar || !overlay) return;
    sidebar.classList.remove('seller-mobile-open');
    overlay.classList.remove('seller-mobile-open');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('seller-menu-open');
    const toggle = document.getElementById('sellerSidebarToggle');
    if (toggle) {
      toggle.setAttribute('aria-expanded', 'false');
      toggle.setAttribute('aria-label', 'Open navigation menu');
    }
  }
  window.openSellerSidebar = openSellerSidebar;
  window.closeSellerSidebar = closeSellerSidebar;
  document.addEventListener('click', function (event) {
    const toggle = event.target.closest('#sellerSidebarToggle');
    if (toggle) {
      event.preventDefault();
      const isOpen = sidebar?.classList.contains('seller-mobile-open');
      isOpen ? closeSellerSidebar() : openSellerSidebar();
      return;
    }
    if (event.target === overlay) {
      closeSellerSidebar();
      return;
    }
    const navLink = event.target.closest('#sellerSidebar .nav-item');
    if (navLink && window.matchMedia('(max-width: 760px)').matches) {
      closeSellerSidebar();
    }
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeSellerSidebar();
    }
  });
  window.addEventListener('resize', function () {
    if (!window.matchMedia('(max-width: 760px)').matches) {
      closeSellerSidebar();
    }
  });
  const roleTransition = document.getElementById('sellerRoleTransition');
  const roleTransitionSubtitle = document.getElementById('sellerRoleTransitionSubtitle');
  function showSellerRoleTransition(target, form) {
    if (!form) return;
    if (!roleTransition) {
      form.submit();
      return;
    }
    roleTransitionSubtitle.textContent = target === 'buyer'
      ? 'Opening Buyer Portal...'
      : 'Opening Seller Portal...';
    try {
      sessionStorage.setItem('lokalcraftRoleTransition', target);
    } catch (error) {
    }
    closeSellerSidebar();
    document.body.classList.add('role-transitioning');
    roleTransition.classList.add('show');
    roleTransition.setAttribute('aria-hidden', 'false');
    window.setTimeout(() => {
      form.submit();
    }, 230);
  }
  // seller_search.php renders this button later; delegate without losing the POST + CSRF flow.
  let sellerRoleSwitchBusy = false;
  document.addEventListener('click', function (event) {
    const button = event.target.closest('#sellerRoleSwitch');
    if (!button) return;
    event.preventDefault();
    if (sellerRoleSwitchBusy) return;
    const formId = button.dataset.roleForm || 'sellerRoleSwitchForm';
    const form = document.getElementById(formId);
    if (!form) return;
    sellerRoleSwitchBusy = true;
    button.disabled = true;
    showSellerRoleTransition('buyer', form);
  });
  function playSellerRoleEnterAnimation() {
    let target = null;
    try {
      target = sessionStorage.getItem('lokalcraftRoleTransition');
      sessionStorage.removeItem('lokalcraftRoleTransition');
    } catch (error) {
    }
    if (target !== 'seller') return;
    const sidebarElement = document.getElementById('sellerSidebar');
    if (!sidebarElement || !roleTransition) return;
    document.body.classList.add('role-transitioning');
    roleTransition.classList.add('show');
    roleTransition.setAttribute('aria-hidden', 'false');
    requestAnimationFrame(() => {
      document.body.classList.remove('role-transitioning');
      sidebarElement.classList.add('role-returning');
      window.setTimeout(() => {
        roleTransition.classList.remove('show');
        roleTransition.setAttribute('aria-hidden', 'true');
        sidebarElement.classList.remove('role-returning');
      }, 220);
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', playSellerRoleEnterAnimation);
  } else {
    playSellerRoleEnterAnimation();
  }
})();
</script>
<?php require_once __DIR__ . '/seller_product_warnings.php'; ?>
