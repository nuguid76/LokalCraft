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
  background: #FFF8ED;
  color: #A55325;
  box-shadow: 0 2px 6px rgba(81,39,27,.18);
  font-size: .67rem;
  font-weight: 800;
  line-height: 1;
  font-variant-numeric: tabular-nums;
}
#buyerSidebar .buyer-nav-unread[hidden] { display: none !important; }
.sidebar {
  box-sizing: border-box;
  width: 240px;
  position: fixed;
  inset: 0 auto 0 0;
  height: 100vh;
  overflow-y: auto;
  scrollbar-width: none;
  z-index: 120;
  background: linear-gradient(135deg, #A55325 0%, #C2692E 100%);
  display: flex;
  flex-direction: column;
  padding: 18px 18px 16px;
  transition: transform .28s cubic-bezier(.22,.61,.36,1), box-shadow .28s ease;
}
.sidebar::-webkit-scrollbar { display: none; }
/* CTA-inspired circular lines, decorative only and behind sidebar controls. */
#buyerSidebar::before,
#buyerSidebar::after {
  content: '';
  position: absolute;
  width: 210px;
  height: 210px;
  border: 1px solid rgba(255, 255, 255, .22);
  border-radius: 50%;
  pointer-events: none;
  z-index: 0;
}
#buyerSidebar::before { top: -96px; left: -116px; }
#buyerSidebar::after { right: -116px; bottom: -108px; }
#buyerSidebar > .brand,
#buyerSidebar > .nav,
#buyerSidebar > .become-seller-card {
  position: relative;
  z-index: 1;
}
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
  color: #FFF8ED;
  font-size: .84rem;
  font-weight: 600;
  transition: background-color .2s ease, color .2s ease;
  min-height: 44px;
}
.nav-item:hover,
.nav-item:focus-visible,
.nav-item.active {
  background: #FFF8ED;
  color: #523323;
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
  color: #FFF8ED;
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
  background: #F3E1CE;
  color: #A55325;
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
  background: #FFF8ED;
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
  z-index: 1000000;
  display: grid;
  place-items: center;
  padding: 20px;
  background: #FFF8ED;
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
.role-transition-screen.show .role-transition-card { transform: translateY(0) scale(1); }
.role-transition-logo {
  display: block;
  width: min(220px, 68vw);
  height: auto;
  margin-bottom: 8px;
  overflow: visible;
  filter: drop-shadow(0 7px 14px rgba(201,109,32,.10));
}
.role-transition-title { color: #4A3426; font-size: .9rem; font-weight: 800; }
.role-transition-subtitle { margin-top: 4px; color: #8A6D58; font-size: .66rem; }
.role-transition-loader {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-height: 8px;
  margin-top: 12px;
}
.role-transition-loader span {
  width: 6px;
  height: 6px;
  border-radius: 999px;
  background: #D77A2F;
  opacity: .35;
  animation: lokalcraftRoleDot 1s ease-in-out infinite;
}
.role-transition-loader span:nth-child(2) { animation-delay: .14s; }
.role-transition-loader span:nth-child(3) { animation-delay: .28s; }
@keyframes lokalcraftRoleDot {
  0%, 60%, 100% { opacity: .3; transform: translateY(0) scale(.88); }
  30% { opacity: 1; transform: translateY(-3px) scale(1); }
}
body.role-transitioning .content,
body.role-transitioning .main,
body.role-transitioning .sidebar {
  opacity: .55;
  transform: translateX(8px);
  transition: opacity .24s ease, transform .24s ease;
}
@media (prefers-reduced-motion: reduce) {
  .role-transition-screen,
  .role-transition-card,
  .role-transition-loader span,
  body.role-transitioning .content,
  body.role-transitioning .main,
  body.role-transitioning .sidebar { transition: none !important; }
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
.nav-item.active { background: #FFF8ED; color: #523323; }
.nav-item.active { box-shadow: none; }
.become-seller-card {
  border-radius: 18px;
  padding: 13px 11px;
}
.become-seller-icon { border-radius: 12px; }
.role-transition-card { border-radius: 22px; }
@media (max-width: 840px) {
  .sidebar {
    border-radius: 0 24px 24px 0 !important;
  }
}
</style>

<aside class="sidebar" id="buyerSidebar" aria-label="Buyer sidebar">
  <div class="brand">
    <a class="buyer-brand-link" href="foryou.php" aria-label="LokalCraft Marketplace home">
      <!-- Screenshot-matched fixed SVG outlines; identical lettering on every device. -->
      <svg class="buyer-brand-wordmark" xmlns="http://www.w3.org/2000/svg" viewBox="23 6 234 76" width="234" height="76" aria-hidden="true" focusable="false">
        <path fill="#FFF8ED" fill-rule="evenodd" d="M187.50,40.50 187.00,41.50 187.00,46.25 187.25,46.50 188.25,46.50 191.00,44.75 192.75,44.50 194.00,43.75 195.75,43.75 197.25,44.50 198.25,45.50 198.50,46.25 197.75,47.00 193.25,47.25 191.75,48.00 190.00,48.00 186.75,50.00 185.25,52.50 185.25,56.25 186.25,58.50 187.50,59.75 189.50,60.75 195.00,60.75 197.75,59.25 198.50,59.50 198.50,60.50 199.00,60.75 206.75,60.75 207.25,60.50 207.25,47.50 206.75,44.50 205.50,42.00 203.25,40.00 202.50,40.00 200.50,39.00 192.25,39.00Z M198.25,51.25 198.50,53.00 198.00,54.25 196.00,56.00 194.75,55.75 193.50,54.25 193.75,52.50 195.50,51.50 197.50,51.00Z M169.25,39.00 168.75,39.50 168.75,52.25 169.00,52.50 168.75,60.25 169.25,60.75 178.00,60.75 178.50,60.25 178.50,49.25 178.75,48.50 180.00,46.75 182.00,45.75 183.00,45.75 185.00,46.75 185.75,46.50 186.00,39.50 185.50,39.00 182.00,39.00 179.00,41.00 178.00,39.00Z M149.50,32.00 146.75,34.00 145.00,36.00 143.25,39.00 142.25,42.25 142.25,50.00 144.00,54.25 145.00,55.75 147.75,58.25 150.50,59.75 151.50,59.75 153.50,60.75 163.25,60.75 167.00,59.50 167.00,53.00 167.25,52.75 167.00,52.25 165.25,52.00 161.75,53.00 159.00,53.00 156.00,52.00 154.25,50.75 152.75,48.75 152.25,46.25 152.75,42.00 156.00,38.75 159.00,37.75 161.75,37.75 165.25,38.75 167.00,38.50 167.25,38.00 167.00,37.50 167.00,32.25 167.25,32.00 167.00,31.25 166.25,30.50 164.50,30.00 154.75,30.00Z M36.50,30.25 36.25,30.75 36.50,31.00 36.50,60.50 37.00,60.75 56.50,60.75 57.00,60.25 56.75,55.50 57.50,54.75 60.00,58.00 61.75,59.25 63.50,59.75 64.50,60.50 73.75,60.75 75.75,59.75 76.50,59.75 78.50,58.50 81.25,55.25 82.50,51.75 82.50,47.50 81.50,44.75 80.00,42.75 78.00,41.00 73.50,39.00 65.75,39.00 63.75,40.00 62.75,40.00 59.75,42.00 57.25,45.75 56.75,48.25 56.75,51.50 57.25,53.50 56.75,54.00 47.00,54.00 46.25,53.25 46.25,31.00 46.50,30.75 45.75,30.00 37.00,30.00Z M69.00,45.75 71.00,46.00 72.50,47.50 72.75,48.25 72.50,52.00 71.50,53.25 70.00,54.00 68.50,53.75 67.00,52.50 66.50,51.50 66.50,48.00 67.75,46.50Z M215.00,29.75 213.00,31.75 212.25,33.00 211.50,35.50 211.50,38.25 210.75,39.00 208.25,39.00 207.75,39.50 208.00,44.50 208.25,44.75 210.75,44.75 211.50,45.50 211.50,60.50 212.00,60.75 220.75,60.75 221.25,60.00 221.00,59.75 221.00,45.50 222.00,44.75 227.75,44.75 228.50,45.50 228.75,55.25 229.25,57.00 231.75,59.75 233.75,60.75 241.25,60.75 242.00,60.50 242.75,59.50 242.75,54.50 242.25,54.00 240.00,54.00 238.50,52.75 238.25,52.25 238.25,45.50 239.00,44.75 242.25,44.75 242.75,44.25 242.75,39.50 242.25,39.00 239.00,39.00 238.25,38.25 238.25,32.25 236.50,32.00 234.50,33.00 232.75,33.00 230.75,34.00 229.00,34.25 228.50,34.75 228.50,38.25 227.75,39.00 222.00,39.00 221.00,38.25 221.25,36.50 222.75,35.00 224.75,34.75 225.50,35.25 226.50,35.25 226.75,34.50 226.75,28.75 226.50,28.25 226.00,28.00 218.75,28.00Z M131.50,28.00 131.00,28.75 131.25,29.25 131.25,60.50 131.50,60.75 140.25,60.75 140.75,60.25 140.75,49.25 140.50,49.00 140.75,28.50 140.25,28.00Z M83.75,28.50 83.75,46.50 84.00,46.75 83.75,60.25 84.25,60.75 93.00,60.75 93.50,60.25 93.50,53.25 94.00,52.75 94.75,53.25 97.50,60.00 98.25,60.75 108.00,60.75 108.50,60.25 108.00,59.50 108.25,59.00 111.25,60.75 116.75,60.75 119.50,59.25 120.25,59.75 120.75,60.75 128.75,60.75 129.25,60.00 129.00,59.50 129.00,46.50 127.75,42.50 125.50,40.25 122.25,39.00 114.00,39.00 111.75,40.00 109.75,40.25 109.00,41.25 109.00,46.25 109.50,46.75 113.00,44.75 113.75,44.75 116.00,43.75 117.75,43.75 119.25,44.50 120.25,45.50 120.25,46.50 119.50,47.00 115.00,47.25 113.00,48.00 111.75,48.00 109.50,49.25 107.50,51.50 107.00,53.25 107.25,55.50 107.00,56.25 106.50,56.50 105.75,55.75 104.50,52.75 102.75,50.00 102.50,48.50 103.50,47.25 105.75,43.00 106.50,42.25 107.00,40.75 108.00,39.75 107.75,39.00 97.75,39.00 96.25,41.50 96.25,42.25 95.25,43.75 94.75,45.50 94.00,46.00 93.50,45.50 93.50,28.50 93.00,28.00 84.25,28.00Z M120.25,51.50 120.00,54.00 118.25,55.75 117.00,56.00 115.50,54.50 115.75,52.25 116.25,51.75 118.25,51.50 119.25,51.00Z"/>
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
    <svg class="role-transition-logo" xmlns="http://www.w3.org/2000/svg" viewBox="23 6 234 76" role="img" aria-label="LokalCraft" focusable="false">
      <defs>
        <linearGradient id="buyerNavTransitionLogoGradient" x1="0%" y1="0%" x2="100%" y2="0%">
          <stop offset="0%" stop-color="#C96D20"/>
          <stop offset="52%" stop-color="#EA8A3A"/>
          <stop offset="100%" stop-color="#F2B36F"/>
        </linearGradient>
      </defs>
      <path fill="url(#buyerNavTransitionLogoGradient)" fill-rule="evenodd" d="M187.50,40.50 187.00,41.50 187.00,46.25 187.25,46.50 188.25,46.50 191.00,44.75 192.75,44.50 194.00,43.75 195.75,43.75 197.25,44.50 198.25,45.50 198.50,46.25 197.75,47.00 193.25,47.25 191.75,48.00 190.00,48.00 186.75,50.00 185.25,52.50 185.25,56.25 186.25,58.50 187.50,59.75 189.50,60.75 195.00,60.75 197.75,59.25 198.50,59.50 198.50,60.50 199.00,60.75 206.75,60.75 207.25,60.50 207.25,47.50 206.75,44.50 205.50,42.00 203.25,40.00 202.50,40.00 200.50,39.00 192.25,39.00Z M198.25,51.25 198.50,53.00 198.00,54.25 196.00,56.00 194.75,55.75 193.50,54.25 193.75,52.50 195.50,51.50 197.50,51.00Z M169.25,39.00 168.75,39.50 168.75,52.25 169.00,52.50 168.75,60.25 169.25,60.75 178.00,60.75 178.50,60.25 178.50,49.25 178.75,48.50 180.00,46.75 182.00,45.75 183.00,45.75 185.00,46.75 185.75,46.50 186.00,39.50 185.50,39.00 182.00,39.00 179.00,41.00 178.00,39.00Z M149.50,32.00 146.75,34.00 145.00,36.00 143.25,39.00 142.25,42.25 142.25,50.00 144.00,54.25 145.00,55.75 147.75,58.25 150.50,59.75 151.50,59.75 153.50,60.75 163.25,60.75 167.00,59.50 167.00,53.00 167.25,52.75 167.00,52.25 165.25,52.00 161.75,53.00 159.00,53.00 156.00,52.00 154.25,50.75 152.75,48.75 152.25,46.25 152.75,42.00 156.00,38.75 159.00,37.75 161.75,37.75 165.25,38.75 167.00,38.50 167.25,38.00 167.00,37.50 167.00,32.25 167.25,32.00 167.00,31.25 166.25,30.50 164.50,30.00 154.75,30.00Z M36.50,30.25 36.25,30.75 36.50,31.00 36.50,60.50 37.00,60.75 56.50,60.75 57.00,60.25 56.75,55.50 57.50,54.75 60.00,58.00 61.75,59.25 63.50,59.75 64.50,60.50 73.75,60.75 75.75,59.75 76.50,59.75 78.50,58.50 81.25,55.25 82.50,51.75 82.50,47.50 81.50,44.75 80.00,42.75 78.00,41.00 73.50,39.00 65.75,39.00 63.75,40.00 62.75,40.00 59.75,42.00 57.25,45.75 56.75,48.25 56.75,51.50 57.25,53.50 56.75,54.00 47.00,54.00 46.25,53.25 46.25,31.00 46.50,30.75 45.75,30.00 37.00,30.00Z M69.00,45.75 71.00,46.00 72.50,47.50 72.75,48.25 72.50,52.00 71.50,53.25 70.00,54.00 68.50,53.75 67.00,52.50 66.50,51.50 66.50,48.00 67.75,46.50Z M215.00,29.75 213.00,31.75 212.25,33.00 211.50,35.50 211.50,38.25 210.75,39.00 208.25,39.00 207.75,39.50 208.00,44.50 208.25,44.75 210.75,44.75 211.50,45.50 211.50,60.50 212.00,60.75 220.75,60.75 221.25,60.00 221.00,59.75 221.00,45.50 222.00,44.75 227.75,44.75 228.50,45.50 228.75,55.25 229.25,57.00 231.75,59.75 233.75,60.75 241.25,60.75 242.00,60.50 242.75,59.50 242.75,54.50 242.25,54.00 240.00,54.00 238.50,52.75 238.25,52.25 238.25,45.50 239.00,44.75 242.25,44.75 242.75,44.25 242.75,39.50 242.25,39.00 239.00,39.00 238.25,38.25 238.25,32.25 236.50,32.00 234.50,33.00 232.75,33.00 230.75,34.00 229.00,34.25 228.50,34.75 228.50,38.25 227.75,39.00 222.00,39.00 221.00,38.25 221.25,36.50 222.75,35.00 224.75,34.75 225.50,35.25 226.50,35.25 226.75,34.50 226.75,28.75 226.50,28.25 226.00,28.00 218.75,28.00Z M131.50,28.00 131.00,28.75 131.25,29.25 131.25,60.50 131.50,60.75 140.25,60.75 140.75,60.25 140.75,49.25 140.50,49.00 140.75,28.50 140.25,28.00Z M83.75,28.50 83.75,46.50 84.00,46.75 83.75,60.25 84.25,60.75 93.00,60.75 93.50,60.25 93.50,53.25 94.00,52.75 94.75,53.25 97.50,60.00 98.25,60.75 108.00,60.75 108.50,60.25 108.00,59.50 108.25,59.00 111.25,60.75 116.75,60.75 119.50,59.25 120.25,59.75 120.75,60.75 128.75,60.75 129.25,60.00 129.00,59.50 129.00,46.50 127.75,42.50 125.50,40.25 122.25,39.00 114.00,39.00 111.75,40.00 109.75,40.25 109.00,41.25 109.00,46.25 109.50,46.75 113.00,44.75 113.75,44.75 116.00,43.75 117.75,43.75 119.25,44.50 120.25,45.50 120.25,46.50 119.50,47.00 115.00,47.25 113.00,48.00 111.75,48.00 109.50,49.25 107.50,51.50 107.00,53.25 107.25,55.50 107.00,56.25 106.50,56.50 105.75,55.75 104.50,52.75 102.75,50.00 102.50,48.50 103.50,47.25 105.75,43.00 106.50,42.25 107.00,40.75 108.00,39.75 107.75,39.00 97.75,39.00 96.25,41.50 96.25,42.25 95.25,43.75 94.75,45.50 94.00,46.00 93.50,45.50 93.50,28.50 93.00,28.00 84.25,28.00Z M120.25,51.50 120.00,54.00 118.25,55.75 117.00,56.00 115.50,54.50 115.75,52.25 116.25,51.75 118.25,51.50 119.25,51.00Z"/>
    </svg>
    <div class="role-transition-title" id="buyerRoleTransitionTitle">Switching Portal</div>
    <div class="role-transition-subtitle" id="buyerRoleTransitionSubtitle">Preparing Seller Portal...</div>
    <div class="role-transition-loader" aria-hidden="true"><span></span><span></span><span></span></div>
  </div>
</div>
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
  let buyerRoleSwitchBusy = false;

  function showRoleTransition(target) {
    const isSeller = target === 'seller';
    if (roleTransitionTitle) roleTransitionTitle.textContent = 'Switching Portal';
    if (roleTransitionSubtitle) {
      roleTransitionSubtitle.textContent = isSeller
        ? 'Preparing Seller Portal...'
        : 'Preparing Buyer Portal...';
    }
    try { sessionStorage.setItem('lokalcraftRoleTransition', target); } catch (error) {}
    closeBuyerSidebar();
    document.body.classList.add('role-transitioning');
    roleTransition?.classList.add('show');
    roleTransition?.setAttribute('aria-hidden', 'false');
  }

  document.addEventListener('click', function (event) {
    const button = event.target.closest('#buyerRoleSwitch');
    if (!button) return;
    event.preventDefault();
    event.stopPropagation();
    if (buyerRoleSwitchBusy || document.body.classList.contains('role-transitioning')) return;
    const form = document.getElementById(button.dataset.roleForm || 'buyerRoleSwitchForm');
    if (!form || !roleTransition) return;
    buyerRoleSwitchBusy = true;
    button.disabled = true;
    showRoleTransition('seller');
    window.setTimeout(() => form.submit(), 650);
  });

  function playRoleEnterAnimation() {
    let target = null;
    try {
      target = sessionStorage.getItem('lokalcraftRoleTransition');
      sessionStorage.removeItem('lokalcraftRoleTransition');
    } catch (error) {
    }
    if (target !== 'buyer' || !roleTransition) return;
    if (roleTransitionTitle) roleTransitionTitle.textContent = 'Switching Portal';
    if (roleTransitionSubtitle) roleTransitionSubtitle.textContent = 'Buyer Portal is ready.';
    document.body.classList.add('role-transitioning');
    roleTransition.classList.add('show');
    roleTransition.setAttribute('aria-hidden', 'false');
    requestAnimationFrame(() => requestAnimationFrame(() => {
      window.setTimeout(() => {
        document.body.classList.remove('role-transitioning');
        roleTransition.classList.remove('show');
        roleTransition.setAttribute('aria-hidden', 'true');
      }, 180);
    }));
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
