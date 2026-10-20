<?php
require_once __DIR__ . '/buyer_auth.php';
function buyerNavUnreadMessageCount(mysqli $conn, int $buyerId): int
{
    if ($buyerId <= 0) return 0;
    $stmt = $conn->prepare('
        SELECT COUNT(*)
        FROM messages m
        INNER JOIN users sender ON sender.id = m.sender_id AND sender.SellerOrNot = 1
        LEFT JOIN orders o ON o.order_id = m.order_id
        WHERE m.receiver_id = ?
          AND m.sender_id <> ?
          AND m.is_read = 0
          AND (
                (m.order_id IS NOT NULL
                 AND o.buyer_id = ? AND o.seller_id = m.sender_id)
                OR
                (m.order_id IS NULL AND (
                    EXISTS (
                        SELECT 1 FROM orders previous_order
                        WHERE previous_order.buyer_id = ?
                          AND previous_order.seller_id = m.sender_id
                    )
                    OR (
                        SELECT first_message.sender_id
                        FROM messages first_message
                        WHERE first_message.order_id IS NULL
                          AND (
                            (first_message.sender_id = ? AND first_message.receiver_id = m.sender_id)
                            OR (first_message.sender_id = m.sender_id AND first_message.receiver_id = ?)
                          )
                        ORDER BY first_message.message_id ASC
                        LIMIT 1
                    ) = ?
                ))
          )
    ');
    if (!$stmt) throw new RuntimeException('Unable to check unread messages.');
    try {
        $stmt->bind_param('iiiiiii', $buyerId, $buyerId, $buyerId, $buyerId, $buyerId, $buyerId, $buyerId);
        if (!$stmt->execute()) throw new RuntimeException('Unable to check unread messages.');
        $stmt->bind_result($unreadCount);
        $stmt->fetch();
        return max(0, (int)$unreadCount);
    } finally {
        $stmt->close();
    }
}
// One-file, authenticated AJAX endpoint for live sidebar updates.
// Direct GET: /Buyer Interface/buyer_nav.php?buyer_nav_unread=1
if (isset($_GET['buyer_nav_unread'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private, max-age=0');
    header('X-Content-Type-Options: nosniff');
    try {
        $unreadCount = buyerNavUnreadMessageCount($conn, (int)$buyerId);
        echo json_encode(['success' => true, 'unread_count' => $unreadCount]);
    } catch (Throwable $e) {
        error_log('LokalCraft buyer unread badge: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to check messages.']);
    }
    exit;
}
$buyerNavUnreadCount = 0;
try {
    $buyerNavUnreadCount = buyerNavUnreadMessageCount($conn, (int)$buyerId);
} catch (Throwable $e) {
    // A badge failure must not break navigation or other buyer pages.
    error_log('LokalCraft buyer unread badge: ' . $e->getMessage());
}
$activePage = $activePage ?? 'marketplace';
$buyerNavIsSeller = false;
$buyerNavApplicationStatus = 'unknown';
$buyerNavFullName = '';
$buyerNavEmail = '';
try {
    $buyerNavStmt = $conn->prepare('
        SELECT u.SellerOrNot, u.AdminOrNot, u.account_status,
               u.firstname, u.middlename, u.lastname, u.email,
               (SELECT LOWER(TRIM(sa.status))
                FROM seller_applications sa
                WHERE sa.user_id = u.id
                ORDER BY sa.submitted_at DESC, sa.application_id DESC
                LIMIT 1) AS application_status
        FROM users u WHERE u.id = ? LIMIT 1
    ');
    if (!$buyerNavStmt) throw new RuntimeException('Unable to load account status.');
    try {
        $buyerUserId = (int)$buyerId;
        $buyerNavStmt->bind_param('i', $buyerUserId);
        if (!$buyerNavStmt->execute()) throw new RuntimeException('Unable to load account status.');
        $buyerNavStmt->bind_result(
            $buyerNavSellerFlag, $buyerNavAdminFlag, $buyerNavAccountStatus,
            $buyerNavFirst, $buyerNavMiddle, $buyerNavLast, $buyerNavDbEmail,
            $buyerNavDbApplicationStatus
        );
        if ($buyerNavStmt->fetch()) {
            $buyerNavApplicationStatus = strtolower(trim((string)($buyerNavDbApplicationStatus ?? '')));
            if ($buyerNavApplicationStatus === '') $buyerNavApplicationStatus = 'none';
            $buyerNavFullName = trim(preg_replace('/\s+/u', ' ', trim(
                (string)$buyerNavFirst . ' ' . (string)$buyerNavMiddle . ' ' . (string)$buyerNavLast
            )) ?? '');
            $buyerNavEmail = trim((string)$buyerNavDbEmail);
            $buyerNavIsSeller = (int)$buyerNavSellerFlag === 1
                && (int)$buyerNavAdminFlag === 0
                && strtolower(trim((string)$buyerNavAccountStatus)) === 'active'
                && $buyerNavApplicationStatus === 'approved';
        }
    } finally {
        $buyerNavStmt->close();
    }
} catch (Throwable $e) {
    error_log('LokalCraft buyer navigation: ' . $e->getMessage());
    // Do not offer role switching when seller eligibility could not be verified.
    $buyerNavIsSeller = false;
    $buyerNavApplicationStatus = 'unknown';
}
$buyerNavCardTitle = 'Become a Seller';
$buyerNavCardDetail = 'Start your own LokalCraft shop';
$buyerNavCardIcon = 'store';
$buyerNavCardClass = '';
switch ($buyerNavApplicationStatus) {
    case 'pending':
        $buyerNavCardTitle = 'Application Pending';
        $buyerNavCardDetail = 'View your application status';
        $buyerNavCardIcon = 'clock';
        $buyerNavCardClass = 'pending';
        break;
    case 'rejected':
        $buyerNavCardTitle = 'Review Application';
        $buyerNavCardDetail = 'Check feedback and resubmit';
        $buyerNavCardIcon = 'file';
        $buyerNavCardClass = 'rejected';
        break;
    case 'approved':
        $buyerNavCardTitle = 'Seller Application';
        $buyerNavCardDetail = 'View your seller access status';
        $buyerNavCardIcon = 'store';
        break;
    case 'none':
        break;
    default:
        $buyerNavCardTitle = 'Seller Application';
        $buyerNavCardDetail = 'View your application status';
        $buyerNavCardIcon = 'file';
}
?>
<aside class="sidebar" id="buyerSidebar" aria-label="Buyer sidebar">
  <div class="brand">
    <a class="buyer-brand-link" href="foryou.php" aria-label="LokalCraft Marketplace home">
      <svg class="buyer-brand-wordmark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 76" width="240" height="76" aria-hidden="true" focusable="false">
        <text x="120" y="50" text-anchor="middle" fill="#ffffff" font-family="Inter, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif" font-size="37" font-weight="800" letter-spacing="-1.3">LokalCraft</text>
      </svg>
    </a>
  </div>
  <nav class="nav" aria-label="Buyer navigation">
    <a
      class="nav-item <?php echo ($activePage === 'marketplace') ? 'active' : ''; ?>"
      href="foryou.php"
      <?php echo $activePage === 'marketplace' ? 'aria-current="page"' : ''; ?>
    ><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8h14l1 12H4L5 8Z"/><path d="M9 9V6a3 3 0 0 1 6 0v3"/></svg></span><span>Marketplace</span></a>
    <a
      class="nav-item <?php echo ($activePage === 'saved') ? 'active' : ''; ?>"
      href="saved.php"
      <?php echo $activePage === 'saved' ? 'aria-current="page"' : ''; ?>
    ><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/></svg></span><span>Saved</span></a>
    <a
      class="nav-item <?php echo ($activePage === 'cart') ? 'active' : ''; ?>"
      href="cart.php"
      <?php echo $activePage === 'cart' ? 'aria-current="page"' : ''; ?>
    ><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="20" r="1"/><circle cx="19" cy="20" r="1"/><path d="M2 3h2l2.4 12.4a2 2 0 0 0 2 1.6h10a2 2 0 0 0 2-1.6L22 7H5"/></svg></span><span>Cart</span></a>
    <a
      class="nav-item <?php echo ($activePage === 'orders') ? 'active' : ''; ?>"
      href="orders.php"
      <?php echo $activePage === 'orders' ? 'aria-current="page"' : ''; ?>
    ><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3h14v18l-3-2-3 2-3-2-3 2-2-2V3Z"/><path d="M9 8h6M9 12h6M9 16h4"/></svg></span><span>Orders</span></a>
    <a
      class="nav-item <?php echo ($activePage === 'messages') ? 'active' : ''; ?>"
      href="messages.php"
      <?php echo $activePage === 'messages' ? 'aria-current="page"' : ''; ?>
    ><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5c-1.4 0-2.8-.3-4-.9L3 21l1.9-5.5A8.5 8.5 0 1 1 21 11.5Z"/></svg></span><span>Messages</span><span class="buyer-nav-unread" id="buyerNavUnreadBadge" role="status" aria-live="polite" aria-label="<?php echo (int)$buyerNavUnreadCount; ?> unread messages" <?php echo $buyerNavUnreadCount > 0 ? '' : 'hidden'; ?>><?php echo $buyerNavUnreadCount > 99 ? '99+' : (int)$buyerNavUnreadCount; ?></span></a>
  </nav>
  <?php if (!$buyerNavIsSeller): ?>
    <a class="become-seller-card<?php echo $buyerNavCardClass !== '' ? ' ' . $buyerNavCardClass : ''; ?>" href="seller_application.php">
      <span class="become-seller-icon" aria-hidden="true">
        <?php if ($buyerNavCardIcon === 'clock'): ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
        <?php elseif ($buyerNavCardIcon === 'file'): ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h9l4 4v14H6z"/><path d="M15 3v5h4M9 13h7M9 17h5"/></svg>
        <?php else: ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10h16l-2-6H6zM5 10v10h14V10M9 20v-6h6v6"/><path d="M4 10a3 3 0 0 0 4 1 3 3 0 0 0 4 0 3 3 0 0 0 4 0 3 3 0 0 0 4-1"/></svg>
        <?php endif; ?>
      </span>
      <span class="become-seller-copy">
        <strong><?php echo htmlspecialchars($buyerNavCardTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong>
        <span><?php echo htmlspecialchars($buyerNavCardDetail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
      </span>
      <span class="become-seller-arrow" aria-hidden="true">›</span>
    </a>
  <?php endif; ?>
</aside>
<div class="buyer-sidebar-overlay" id="buyerSidebarOverlay" aria-hidden="true"></div>
<div class="role-transition-screen" id="buyerRoleTransition" aria-hidden="true">
  <div class="role-transition-card">
    <div class="role-transition-mark">L</div>
    <div class="role-transition-title" id="buyerRoleTransitionTitle">Switching Portal</div>
    <div class="role-transition-subtitle" id="buyerRoleTransitionSubtitle">Opening Seller Portal...</div>
  </div>
</div>
<style>
/* Compact unread counter beside Messages; hidden when there is nothing unread. */
#buyerSidebar .buyer-nav-unread {
  margin-left: auto;
  min-width: 22px;
  height: 22px;
  padding: 0 6px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
  background: #ea8a3a;
  color: #ffffff;
  box-shadow: 0 2px 6px rgba(201,109,32,.18);
  font-size: .67rem;
  font-weight: 800;
  line-height: 1;
  font-variant-numeric: tabular-nums;
}
#buyerSidebar .buyer-nav-unread[hidden] { display: none !important; }
.sidebar {
  width: 240px;
  position: fixed;
  inset: 0 auto 0 0;
  height: 100vh;
  overflow-y: auto;
  scrollbar-width: none;
  z-index: 120;
  background: linear-gradient(165deg, #683117 0%, #50271b 53%, #381f19 100%);
  display: flex;
  flex-direction: column;
  padding: 18px 18px 16px;
  transition: transform .28s cubic-bezier(.22,.61,.36,1), box-shadow .28s ease;
}
.sidebar::-webkit-scrollbar { display: none; }
#buyerSidebar .brand {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
  width: auto;
  max-width: none;
  height: auto !important;
  min-height: 0;
  overflow: visible;
  margin: 0 -16px 12px;
  padding: 6px 0 0;
  border: 0;
  border-bottom: none !important;
  box-shadow: none;
}
#buyerSidebar .buyer-brand-link {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  max-width: 100%;
  min-width: 0;
  overflow: visible;
  border-radius: 0;
  background: transparent;
  line-height: 0;
  transition: filter .2s ease;
}
#buyerSidebar .buyer-brand-link:hover { filter: brightness(1.045); }
#buyerSidebar .buyer-brand-link:focus-visible {
  outline: 2px solid #fff3db;
  outline-offset: 3px;
}
#buyerSidebar .buyer-brand-wordmark {
  display: block;
  flex: 0 1 auto;
  width: 100%;
  max-width: 240px;
  height: auto;
  overflow: visible;
}
.nav {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 12px;
}
.nav-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 10px;
  margin-right: -18px;
  border-radius: 24px 0 0 24px;
  text-decoration: none;
  color: #fff1dd;
  font-size: .84rem;
  font-weight: 600;
  transition: background-color .2s ease, color .2s ease;
  min-height: 44px;
}
.nav-item:hover,
.nav-item:focus-visible,
.nav-item.active {
  background: #fff8eb;
  color: #33363b;
}
.nav-item.active { font-weight: 700; }
/* Compact icons retain their space when labels change color. */
.nav-item .nav-icon {
  display: grid;
  place-items: center;
  flex: 0 0 30px;
  width: 30px;
  height: 30px;
  border-radius: 9px;
  background: rgba(255, 247, 232, .15);
  color: #fff0dc;
  transition: background-color .18s ease, color .18s ease;
}
.nav-item .nav-icon svg {
  display: block;
  width: 18px;
  height: 18px;
}
.nav-item:hover .nav-icon,
.nav-item:focus-visible .nav-icon,
.nav-item.active .nav-icon {
  background: #f3e1cc;
  color: #8c4826;
}
.nav-item:focus-visible {
  outline: 2px solid #fff3db;
  outline-offset: 2px;
}
.become-seller-card {
  display: grid;
  grid-template-columns: 34px minmax(0,1fr) 14px;
  align-items: center;
  gap: 9px;
  margin-top: 20px;
  margin-bottom: 10px;
  padding: 12px 10px;
  border: 1px solid rgba(255, 252, 246, .8);
  border-radius: 12px;
  background: #fff8eb;
  color: #49382b;
  text-decoration: none;
  box-shadow: 0 9px 22px rgba(50, 22, 11, .23), 0 0 14px rgba(255, 245, 222, .12);
  transition: transform .2s ease, box-shadow .2s ease, background-color .2s ease;
}
.become-seller-card:hover,
.become-seller-card:focus-visible {
  background: #fffdf5;
  transform: translateY(-3px);
  box-shadow: 0 13px 25px rgba(50, 22, 11, .27), 0 0 16px rgba(255, 245, 222, .20);
}
.become-seller-icon {
  width: 34px;
  height: 34px;
  display: grid;
  place-items: center;
  border-radius: 10px;
  background: #f5e4d0;
  color: #a85e2f;
  font-size: 1rem;
  font-weight: 800;
}
.become-seller-icon svg { width: 21px; height: 21px; display: block; }
.become-seller-copy {
  min-width: 0;
}
.become-seller-copy strong,
.become-seller-copy span {
  display: block;
}
.become-seller-copy strong {
  font-size: .77rem;
  color: #4f443b;
}
.become-seller-copy span {
  margin-top: 2px;
  color: #8d8178;
  font-size: .66rem;
  line-height: 1.35;
}
.become-seller-arrow {
  color: #bc7a45;
  font-size: 1.2rem;
  text-align: right;
}
.sidebar + .content {
  position: fixed !important;
  top: 0 !important;
  right: 0 !important;
  bottom: 0 !important;
  left: 240px !important;
  height: auto !important;
  min-height: 100vh !important;
  width: auto !important;
  max-width: none !important;
  margin: 0 !important;
  overflow-y: auto !important;
  overflow-x: hidden !important;
}
.page {
  display: block !important;
  min-height: 100vh !important;
}
.buyer-sidebar-overlay {
  position: fixed;
  inset: 0;
  z-index: 115;
  background: rgba(25,20,16,.38);
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
  backdrop-filter: blur(1.5px);
  -webkit-backdrop-filter: blur(1.5px);
  transition: opacity .25s ease, visibility .25s ease;
}
body.buyer-menu-open {
  overflow: hidden;
}
.role-transition-screen {
  position: fixed;
  inset: 0;
  z-index: 99999;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  background: rgba(255,255,255,.96);
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
  transition: opacity .18s ease, visibility .18s ease;
}
.role-transition-screen.show {
  opacity: 1;
  visibility: visible;
  pointer-events: auto;
}
.role-transition-card {
  width: min(280px, 100%);
  padding: 24px 22px;
  border: 1px solid #e7e1dc;
  border-radius: 16px;
  background: #fff;
  text-align: center;
  box-shadow: 0 18px 45px rgba(30,20,10,.12);
  transform: translateY(8px) scale(.98);
  transition: transform .18s ease;
}
.role-transition-screen.show .role-transition-card {
  transform: translateY(0) scale(1);
}
.role-transition-mark {
  width: 44px;
  height: 44px;
  margin: 0 auto 12px;
  display: grid;
  place-items: center;
  border-radius: 12px;
  background: linear-gradient(135deg, #f7b875, #ea8a3a);
  color: #fff;
  font-weight: 800;
  font-size: 1.1rem;
}
.role-transition-title {
  color: #2d2d2d;
  font-size: .92rem;
  font-weight: 800;
}
.role-transition-subtitle {
  margin-top: 4px;
  color: #777;
  font-size: .68rem;
}
body.role-transitioning .content {
  opacity: .92;
  transition: opacity .18s ease;
}
@media (max-width: 840px) {
  .sidebar {
    width: min(82vw, 300px) !important;
    position: fixed !important;
    inset: 0 auto 0 0 !important;
    height: 100vh !important;
    min-height: 100vh !important;
    overflow-y: auto !important;
    border-right: 0 !important;
    border-bottom: none !important;
    transform: translateX(-105%);
    box-shadow: 16px 0 32px rgba(30,20,10,.14);
  }
  .sidebar.buyer-mobile-open {
    transform: translateX(0);
  }
  .buyer-sidebar-overlay.buyer-mobile-open {
    opacity: 1;
    visibility: visible;
    pointer-events: auto;
  }
  .sidebar + .content {
    position: relative !important;
    inset: auto !important;
    height: auto !important;
    min-height: 100vh !important;
    width: 100% !important;
    max-width: none !important;
    margin: 0 !important;
    overflow: visible !important;
  }
  .page {
    display: flex !important;
    flex-direction: column !important;
    min-height: 100vh !important;
  }
}
.sidebar {
  border-right: 0;
  border-radius: 0 24px 24px 0;
  box-shadow: none;
}
#buyerSidebar .brand {
  margin-bottom: 12px;
  border-bottom: none !important;
}
.nav-item {
  border-radius: 24px 0 0 24px;
  padding: 12px 13px;
}
.nav-item:hover,
.nav-item:focus-visible,
.nav-item.active { background: #fff8eb; color: #33363b; }
.nav-item.active { box-shadow: none; }
.become-seller-card {
  border-radius: 18px;
  padding: 13px 11px;
}
.become-seller-icon { border-radius: 12px; }
.role-transition-card { border-radius: 22px; }
.role-transition-mark { border-radius: 14px; }
@media (max-width: 840px) {
  .sidebar {
    border-radius: 0 24px 24px 0 !important;
  }
}
</style>
<script>
(function () {
  const sidebar = document.getElementById('buyerSidebar');
  const overlay = document.getElementById('buyerSidebarOverlay');
  function openBuyerSidebar() {
    if (!sidebar || !overlay) return;
    sidebar.classList.add('buyer-mobile-open');
    overlay.classList.add('buyer-mobile-open');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.classList.add('buyer-menu-open');
    const toggle = document.getElementById('buyerSidebarToggle');
    if (toggle) {
      toggle.setAttribute('aria-expanded', 'true');
      toggle.setAttribute('aria-label', 'Close navigation menu');
    }
  }
  function closeBuyerSidebar() {
    if (!sidebar || !overlay) return;
    sidebar.classList.remove('buyer-mobile-open');
    overlay.classList.remove('buyer-mobile-open');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('buyer-menu-open');
    const toggle = document.getElementById('buyerSidebarToggle');
    if (toggle) {
      toggle.setAttribute('aria-expanded', 'false');
      toggle.setAttribute('aria-label', 'Open navigation menu');
    }
  }
  window.openBuyerSidebar = openBuyerSidebar;
  window.closeBuyerSidebar = closeBuyerSidebar;
  document.addEventListener('click', function (event) {
    const toggle = event.target.closest('#buyerSidebarToggle');
    if (toggle) {
      event.preventDefault();
      if (window.matchMedia('(max-width: 840px)').matches) {
        const isOpen = sidebar?.classList.contains('buyer-mobile-open');
        isOpen ? closeBuyerSidebar() : openBuyerSidebar();
      }
      return;
    }
    if (event.target === overlay) {
      closeBuyerSidebar();
      return;
    }
    const sidebarLink = event.target.closest('#buyerSidebar a');
    if (
      sidebarLink
      && window.matchMedia('(max-width: 840px)').matches
    ) {
      closeBuyerSidebar();
    }
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeBuyerSidebar();
    }
  });
  window.addEventListener('resize', function () {
    if (!window.matchMedia('(max-width: 840px)').matches) {
      closeBuyerSidebar();
    }
  });
  const roleTransition = document.getElementById('buyerRoleTransition');
  const roleTransitionTitle = document.getElementById('buyerRoleTransitionTitle');
  const roleTransitionSubtitle = document.getElementById('buyerRoleTransitionSubtitle');
  function showRoleTransition(target, href) {
    if (!href || document.body.classList.contains('role-transitioning')) return;
    const isSeller = target === 'seller';
    if (roleTransitionTitle) {
      roleTransitionTitle.textContent = 'Switching Portal';
    }
    if (roleTransitionSubtitle) {
      roleTransitionSubtitle.textContent = isSeller
        ? 'Opening Seller Portal...'
        : 'Opening Buyer Portal...';
    }
    try {
      sessionStorage.setItem('lokalcraftRoleTransition', target);
    } catch (error) {
    }
    closeBuyerSidebar();
    if (roleTransition) {
      document.body.classList.add('role-transitioning');
      roleTransition.classList.add('show');
      roleTransition.setAttribute('aria-hidden', 'false');
    }
    window.setTimeout(function () {
      window.location.assign(href);
    }, 180);
  }
  // The Switch to Seller link is rendered later by buyer_search.php.
  document.addEventListener('click', function (event) {
    const link = event.target.closest('#buyerRoleSwitch');
    if (!link) return;
    event.preventDefault();
    event.stopPropagation();
    if (document.body.classList.contains('role-transitioning')) return;
    showRoleTransition('seller', link.getAttribute('href'));
  });
  function playRoleEnterAnimation() {
    let target = null;
    try {
      target = sessionStorage.getItem('lokalcraftRoleTransition');
      sessionStorage.removeItem('lokalcraftRoleTransition');
    } catch (error) {
    }
    if (target !== 'buyer') return;
    const sidebarElement = document.getElementById('buyerSidebar');
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
    document.addEventListener('DOMContentLoaded', playRoleEnterAnimation);
  } else {
    playRoleEnterAnimation();
  }
})();
</script>
<script>
// Auto-refresh the unread count without refreshing the Buyer page.
(function () {
  'use strict';
  const badge = document.getElementById('buyerNavUnreadBadge');
  if (!badge) return;
  const unreadUrl = new URL('./buyer_nav.php?buyer_nav_unread=1', window.location.href);
  let pending = false;
  function showUnreadCount(value) {
    const count = Math.max(0, Math.floor(Number(value) || 0));
    if (count === 0) {
      badge.hidden = true;
      badge.textContent = '';
      badge.setAttribute('aria-label', 'No unread messages');
      return;
    }
    badge.textContent = count > 99 ? '99+' : String(count);
    badge.setAttribute('aria-label', count + ' unread message' + (count === 1 ? '' : 's'));
    badge.hidden = false;
  }
  async function refreshUnreadCount() {
    if (pending || document.hidden) return;
    pending = true;
    try {
      const response = await fetch(unreadUrl.href, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Accept': 'application/json' }
      });
      if (!response.ok) return;
      const data = await response.json();
      if (data && data.success === true && Number.isFinite(Number(data.unread_count))) {
        showUnreadCount(data.unread_count);
      }
    } catch (error) {
      // Keep the last known count during a brief connection problem.
    } finally {
      pending = false;
    }
  }
  refreshUnreadCount();
  window.setInterval(refreshUnreadCount, 5000);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) refreshUnreadCount();
  });
  window.addEventListener('focus', refreshUnreadCount);
  window.addEventListener('online', refreshUnreadCount);
  window.addEventListener('pageshow', refreshUnreadCount);
})();
</script>
