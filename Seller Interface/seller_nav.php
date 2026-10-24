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

<style>

:root {

  --seller-orange: #A55325;

  --seller-orange-dark: #873F1B;

  --seller-orange-soft: #FFF8ED;

  --seller-text: #55504b;

  --seller-muted: #8d8782;

  --seller-line: #eadfd6;

}



#sellerSidebar.sidebar {

  box-sizing: border-box;

  width: 250px;

  flex: 0 0 250px;

  position: fixed;

  inset: 0 auto 0 0;

  height: 100vh;

  overflow-y: auto;

  overflow-x: hidden;

  scrollbar-width: none;

  z-index: 120;

  display: flex;

  flex-direction: column;

  padding: 18px 18px 16px;

  border: 0;

  border-radius: 0 24px 24px 0;

  background: linear-gradient(135deg, #A55325 0%, #C2692E 100%);

  box-shadow: none;

  transition: transform .25s ease, box-shadow .25s ease, opacity .24s ease;

}



#sellerSidebar::-webkit-scrollbar { display: none; }

/* Decorative rings stay behind the navigation and never intercept taps. */

#sellerSidebar::before,

#sellerSidebar::after {

  content: '';

  position: absolute;

  width: 210px;

  height: 210px;

  border: 1px solid rgba(255, 255, 255, .22);

  border-radius: 50%;

  pointer-events: none;

  z-index: 0;

}



#sellerSidebar::before { top: -96px; left: -116px; }

#sellerSidebar::after { right: -116px; bottom: -108px; }

#sellerSidebar > .brand,

#sellerSidebar > .nav { position: relative; z-index: 1; }

#sellerSidebar .brand {

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

  box-shadow: none;

}



#sellerSidebar .seller-brand-link {

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



#sellerSidebar .seller-brand-link:hover { filter: brightness(1.045); }

#sellerSidebar .seller-brand-link:focus-visible { outline: 2px solid #FFF8ED; outline-offset: 3px; }

#sellerSidebar .seller-brand-wordmark {

  display: block;

  flex: 0 1 auto;

  width: 100%;

  max-width: 240px;

  height: auto;

  overflow: visible;

}



#sellerSidebar .nav {

  display: flex;

  flex-direction: column;

  gap: 8px;

  margin-top: 12px;

}



#sellerSidebar .nav-item {

  display: flex;

  align-items: center;

  gap: 10px;

  min-height: 44px;

  margin-right: -18px;

  padding: 12px 13px;

  border: 0;

  border-radius: 24px 0 0 24px;

  background: transparent;

  color: #FFF8ED;

  font-size: .84rem;

  font-weight: 600;

  text-decoration: none;

  transition: background-color .2s ease, color .2s ease;

}



#sellerSidebar .nav-item:hover,

#sellerSidebar .nav-item:focus-visible,

#sellerSidebar .nav-item.active {

  background: #FFF8ED;

  color: #523323;

}



#sellerSidebar .nav-item.active { font-weight: 700; box-shadow: none; }

#sellerSidebar .nav-item .icon {

  display: inline-grid;

  place-items: center;

  flex: 0 0 30px;

  width: 30px;

  height: 30px;

  border-radius: 9px;

  background: rgba(255,247,232,.15);

  color: #FFF8ED;

  transition: background-color .18s ease, color .18s ease;

}



#sellerSidebar .nav-item .icon svg { display: block; width: 18px; height: 18px; }

#sellerSidebar .nav-item:hover .icon,

#sellerSidebar .nav-item:focus-visible .icon,

#sellerSidebar .nav-item.active .icon { background: #F3E1CE; color: #A55325; }

#sellerSidebar .nav-item:focus-visible { outline: 2px solid #fff3db; outline-offset: 2px; }

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

  background: #FFF8ED;

  color: #A55325;

  font-size: .59rem;

  font-weight: 800;

  line-height: 1;

}



#sellerSidebar .nav-item.active .seller-message-badge { background: #A55325; color: #FFF8ED; }

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



.role-transition-screen.show .role-transition-card {

  transform: translateY(0) scale(1);

}



.role-transition-logo {

  display: block;

  width: min(220px, 68vw);

  height: auto;

  margin-bottom: 8px;

  overflow: visible;

  filter: drop-shadow(0 7px 14px rgba(201,109,32,.10));

}



.role-transition-title {

  color: #4a3426;

  font-size: .9rem;

  font-weight: 800;

}



.role-transition-subtitle {

  margin-top: 4px;

  color: #8a6d58;

  font-size: .66rem;

}

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

body.role-transitioning .sidebar {

  transform: translateX(-22px);

  opacity: 0;

}

body.role-transitioning .main,

body.role-transitioning .content {

  transform: translateX(8px);

  opacity: .55;

  transition: transform .24s ease, opacity .24s ease;

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

  .role-transition-card,

  .role-transition-loader span {

    transition: none !important;

    animation: none !important;

  }

}



@media (max-width: 760px) {

  #sellerSidebar.sidebar {

    width: min(82vw, 300px) !important;

    flex: 0 0 auto !important;

    position: fixed !important;

    inset: 0 auto 0 0 !important;

    height: 100vh !important;

    min-height: 100vh !important;

    max-height: none !important;

    overflow-y: auto !important;

    border-right: 0 !important;

    border-bottom: 0 !important;

    border-radius: 0 24px 24px 0 !important;

    transform: translateX(-105%);

    box-shadow: 16px 0 32px rgba(30,20,10,.14);

  }

  #sellerSidebar.sidebar.seller-mobile-open {

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

<aside class="sidebar" id="sellerSidebar">

  <div class="brand">

    <a class="seller-brand-link" href="seller_dashboard.php" aria-label="LokalCraft Seller dashboard">

      <svg class="seller-brand-wordmark" xmlns="http://www.w3.org/2000/svg" viewBox="23 6 234 76" width="234" height="76" aria-hidden="true" focusable="false">
        <path fill="#FFF8ED" fill-rule="evenodd" d="M187.50,40.50 187.00,41.50 187.00,46.25 187.25,46.50 188.25,46.50 191.00,44.75 192.75,44.50 194.00,43.75 195.75,43.75 197.25,44.50 198.25,45.50 198.50,46.25 197.75,47.00 193.25,47.25 191.75,48.00 190.00,48.00 186.75,50.00 185.25,52.50 185.25,56.25 186.25,58.50 187.50,59.75 189.50,60.75 195.00,60.75 197.75,59.25 198.50,59.50 198.50,60.50 199.00,60.75 206.75,60.75 207.25,60.50 207.25,47.50 206.75,44.50 205.50,42.00 203.25,40.00 202.50,40.00 200.50,39.00 192.25,39.00Z M198.25,51.25 198.50,53.00 198.00,54.25 196.00,56.00 194.75,55.75 193.50,54.25 193.75,52.50 195.50,51.50 197.50,51.00Z M169.25,39.00 168.75,39.50 168.75,52.25 169.00,52.50 168.75,60.25 169.25,60.75 178.00,60.75 178.50,60.25 178.50,49.25 178.75,48.50 180.00,46.75 182.00,45.75 183.00,45.75 185.00,46.75 185.75,46.50 186.00,39.50 185.50,39.00 182.00,39.00 179.00,41.00 178.00,39.00Z M149.50,32.00 146.75,34.00 145.00,36.00 143.25,39.00 142.25,42.25 142.25,50.00 144.00,54.25 145.00,55.75 147.75,58.25 150.50,59.75 151.50,59.75 153.50,60.75 163.25,60.75 167.00,59.50 167.00,53.00 167.25,52.75 167.00,52.25 165.25,52.00 161.75,53.00 159.00,53.00 156.00,52.00 154.25,50.75 152.75,48.75 152.25,46.25 152.75,42.00 156.00,38.75 159.00,37.75 161.75,37.75 165.25,38.75 167.00,38.50 167.25,38.00 167.00,37.50 167.00,32.25 167.25,32.00 167.00,31.25 166.25,30.50 164.50,30.00 154.75,30.00Z M36.50,30.25 36.25,30.75 36.50,31.00 36.50,60.50 37.00,60.75 56.50,60.75 57.00,60.25 56.75,55.50 57.50,54.75 60.00,58.00 61.75,59.25 63.50,59.75 64.50,60.50 73.75,60.75 75.75,59.75 76.50,59.75 78.50,58.50 81.25,55.25 82.50,51.75 82.50,47.50 81.50,44.75 80.00,42.75 78.00,41.00 73.50,39.00 65.75,39.00 63.75,40.00 62.75,40.00 59.75,42.00 57.25,45.75 56.75,48.25 56.75,51.50 57.25,53.50 56.75,54.00 47.00,54.00 46.25,53.25 46.25,31.00 46.50,30.75 45.75,30.00 37.00,30.00Z M69.00,45.75 71.00,46.00 72.50,47.50 72.75,48.25 72.50,52.00 71.50,53.25 70.00,54.00 68.50,53.75 67.00,52.50 66.50,51.50 66.50,48.00 67.75,46.50Z M215.00,29.75 213.00,31.75 212.25,33.00 211.50,35.50 211.50,38.25 210.75,39.00 208.25,39.00 207.75,39.50 208.00,44.50 208.25,44.75 210.75,44.75 211.50,45.50 211.50,60.50 212.00,60.75 220.75,60.75 221.25,60.00 221.00,59.75 221.00,45.50 222.00,44.75 227.75,44.75 228.50,45.50 228.75,55.25 229.25,57.00 231.75,59.75 233.75,60.75 241.25,60.75 242.00,60.50 242.75,59.50 242.75,54.50 242.25,54.00 240.00,54.00 238.50,52.75 238.25,52.25 238.25,45.50 239.00,44.75 242.25,44.75 242.75,44.25 242.75,39.50 242.25,39.00 239.00,39.00 238.25,38.25 238.25,32.25 236.50,32.00 234.50,33.00 232.75,33.00 230.75,34.00 229.00,34.25 228.50,34.75 228.50,38.25 227.75,39.00 222.00,39.00 221.00,38.25 221.25,36.50 222.75,35.00 224.75,34.75 225.50,35.25 226.50,35.25 226.75,34.50 226.75,28.75 226.50,28.25 226.00,28.00 218.75,28.00Z M131.50,28.00 131.00,28.75 131.25,29.25 131.25,60.50 131.50,60.75 140.25,60.75 140.75,60.25 140.75,49.25 140.50,49.00 140.75,28.50 140.25,28.00Z M83.75,28.50 83.75,46.50 84.00,46.75 83.75,60.25 84.25,60.75 93.00,60.75 93.50,60.25 93.50,53.25 94.00,52.75 94.75,53.25 97.50,60.00 98.25,60.75 108.00,60.75 108.50,60.25 108.00,59.50 108.25,59.00 111.25,60.75 116.75,60.75 119.50,59.25 120.25,59.75 120.75,60.75 128.75,60.75 129.25,60.00 129.00,59.50 129.00,46.50 127.75,42.50 125.50,40.25 122.25,39.00 114.00,39.00 111.75,40.00 109.75,40.25 109.00,41.25 109.00,46.25 109.50,46.75 113.00,44.75 113.75,44.75 116.00,43.75 117.75,43.75 119.25,44.50 120.25,45.50 120.25,46.50 119.50,47.00 115.00,47.25 113.00,48.00 111.75,48.00 109.50,49.25 107.50,51.50 107.00,53.25 107.25,55.50 107.00,56.25 106.50,56.50 105.75,55.75 104.50,52.75 102.75,50.00 102.50,48.50 103.50,47.25 105.75,43.00 106.50,42.25 107.00,40.75 108.00,39.75 107.75,39.00 97.75,39.00 96.25,41.50 96.25,42.25 95.25,43.75 94.75,45.50 94.00,46.00 93.50,45.50 93.50,28.50 93.00,28.00 84.25,28.00Z M120.25,51.50 120.00,54.00 118.25,55.75 117.00,56.00 115.50,54.50 115.75,52.25 116.25,51.75 118.25,51.50 119.25,51.00Z"/>
      </svg>

    </a>

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

    ><span class="icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v17M7 21h10M5 7h14M12 3a2 2 0 0 1 2 2M12 3a2 2 0 0 0-2 2"/><path d="m5 7-3 7h6L5 7Zm14 0-3 7h6l-3-7Z"/></svg></span>Costing &amp; Pricing</a>

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

    <svg class="role-transition-logo" xmlns="http://www.w3.org/2000/svg" viewBox="23 6 234 76" role="img" aria-label="LokalCraft" focusable="false">
      <defs>
        <linearGradient id="sellerTransitionLogoGradient" x1="0%" y1="0%" x2="100%" y2="0%">
          <stop offset="0%" stop-color="#C96D20"/>
          <stop offset="52%" stop-color="#EA8A3A"/>
          <stop offset="100%" stop-color="#F2B36F"/>
        </linearGradient>
      </defs>
      <path fill="url(#sellerTransitionLogoGradient)" fill-rule="evenodd" d="M187.50,40.50 187.00,41.50 187.00,46.25 187.25,46.50 188.25,46.50 191.00,44.75 192.75,44.50 194.00,43.75 195.75,43.75 197.25,44.50 198.25,45.50 198.50,46.25 197.75,47.00 193.25,47.25 191.75,48.00 190.00,48.00 186.75,50.00 185.25,52.50 185.25,56.25 186.25,58.50 187.50,59.75 189.50,60.75 195.00,60.75 197.75,59.25 198.50,59.50 198.50,60.50 199.00,60.75 206.75,60.75 207.25,60.50 207.25,47.50 206.75,44.50 205.50,42.00 203.25,40.00 202.50,40.00 200.50,39.00 192.25,39.00Z M198.25,51.25 198.50,53.00 198.00,54.25 196.00,56.00 194.75,55.75 193.50,54.25 193.75,52.50 195.50,51.50 197.50,51.00Z M169.25,39.00 168.75,39.50 168.75,52.25 169.00,52.50 168.75,60.25 169.25,60.75 178.00,60.75 178.50,60.25 178.50,49.25 178.75,48.50 180.00,46.75 182.00,45.75 183.00,45.75 185.00,46.75 185.75,46.50 186.00,39.50 185.50,39.00 182.00,39.00 179.00,41.00 178.00,39.00Z M149.50,32.00 146.75,34.00 145.00,36.00 143.25,39.00 142.25,42.25 142.25,50.00 144.00,54.25 145.00,55.75 147.75,58.25 150.50,59.75 151.50,59.75 153.50,60.75 163.25,60.75 167.00,59.50 167.00,53.00 167.25,52.75 167.00,52.25 165.25,52.00 161.75,53.00 159.00,53.00 156.00,52.00 154.25,50.75 152.75,48.75 152.25,46.25 152.75,42.00 156.00,38.75 159.00,37.75 161.75,37.75 165.25,38.75 167.00,38.50 167.25,38.00 167.00,37.50 167.00,32.25 167.25,32.00 167.00,31.25 166.25,30.50 164.50,30.00 154.75,30.00Z M36.50,30.25 36.25,30.75 36.50,31.00 36.50,60.50 37.00,60.75 56.50,60.75 57.00,60.25 56.75,55.50 57.50,54.75 60.00,58.00 61.75,59.25 63.50,59.75 64.50,60.50 73.75,60.75 75.75,59.75 76.50,59.75 78.50,58.50 81.25,55.25 82.50,51.75 82.50,47.50 81.50,44.75 80.00,42.75 78.00,41.00 73.50,39.00 65.75,39.00 63.75,40.00 62.75,40.00 59.75,42.00 57.25,45.75 56.75,48.25 56.75,51.50 57.25,53.50 56.75,54.00 47.00,54.00 46.25,53.25 46.25,31.00 46.50,30.75 45.75,30.00 37.00,30.00Z M69.00,45.75 71.00,46.00 72.50,47.50 72.75,48.25 72.50,52.00 71.50,53.25 70.00,54.00 68.50,53.75 67.00,52.50 66.50,51.50 66.50,48.00 67.75,46.50Z M215.00,29.75 213.00,31.75 212.25,33.00 211.50,35.50 211.50,38.25 210.75,39.00 208.25,39.00 207.75,39.50 208.00,44.50 208.25,44.75 210.75,44.75 211.50,45.50 211.50,60.50 212.00,60.75 220.75,60.75 221.25,60.00 221.00,59.75 221.00,45.50 222.00,44.75 227.75,44.75 228.50,45.50 228.75,55.25 229.25,57.00 231.75,59.75 233.75,60.75 241.25,60.75 242.00,60.50 242.75,59.50 242.75,54.50 242.25,54.00 240.00,54.00 238.50,52.75 238.25,52.25 238.25,45.50 239.00,44.75 242.25,44.75 242.75,44.25 242.75,39.50 242.25,39.00 239.00,39.00 238.25,38.25 238.25,32.25 236.50,32.00 234.50,33.00 232.75,33.00 230.75,34.00 229.00,34.25 228.50,34.75 228.50,38.25 227.75,39.00 222.00,39.00 221.00,38.25 221.25,36.50 222.75,35.00 224.75,34.75 225.50,35.25 226.50,35.25 226.75,34.50 226.75,28.75 226.50,28.25 226.00,28.00 218.75,28.00Z M131.50,28.00 131.00,28.75 131.25,29.25 131.25,60.50 131.50,60.75 140.25,60.75 140.75,60.25 140.75,49.25 140.50,49.00 140.75,28.50 140.25,28.00Z M83.75,28.50 83.75,46.50 84.00,46.75 83.75,60.25 84.25,60.75 93.00,60.75 93.50,60.25 93.50,53.25 94.00,52.75 94.75,53.25 97.50,60.00 98.25,60.75 108.00,60.75 108.50,60.25 108.00,59.50 108.25,59.00 111.25,60.75 116.75,60.75 119.50,59.25 120.25,59.75 120.75,60.75 128.75,60.75 129.25,60.00 129.00,59.50 129.00,46.50 127.75,42.50 125.50,40.25 122.25,39.00 114.00,39.00 111.75,40.00 109.75,40.25 109.00,41.25 109.00,46.25 109.50,46.75 113.00,44.75 113.75,44.75 116.00,43.75 117.75,43.75 119.25,44.50 120.25,45.50 120.25,46.50 119.50,47.00 115.00,47.25 113.00,48.00 111.75,48.00 109.50,49.25 107.50,51.50 107.00,53.25 107.25,55.50 107.00,56.25 106.50,56.50 105.75,55.75 104.50,52.75 102.75,50.00 102.50,48.50 103.50,47.25 105.75,43.00 106.50,42.25 107.00,40.75 108.00,39.75 107.75,39.00 97.75,39.00 96.25,41.50 96.25,42.25 95.25,43.75 94.75,45.50 94.00,46.00 93.50,45.50 93.50,28.50 93.00,28.00 84.25,28.00Z M120.25,51.50 120.00,54.00 118.25,55.75 117.00,56.00 115.50,54.50 115.75,52.25 116.25,51.75 118.25,51.50 119.25,51.00Z"/>
    </svg>

    <div class="role-transition-title">Switching Portal</div>

    <div class="role-transition-subtitle" id="sellerRoleTransitionSubtitle">Preparing Buyer Portal...</div>

    <div class="role-transition-loader" aria-hidden="true"><span></span><span></span><span></span></div>

  </div>

</div>

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

      ? 'Preparing Buyer Portal...'

      : 'Preparing Seller Portal...';

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

    }, 650);

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

    requestAnimationFrame(() => requestAnimationFrame(() => {

      window.setTimeout(() => {

        document.body.classList.remove('role-transitioning');

        sidebarElement.classList.add('role-returning');

        roleTransition.classList.remove('show');

        roleTransition.setAttribute('aria-hidden', 'true');

        window.setTimeout(() => {

          sidebarElement.classList.remove('role-returning');

        }, 320);

      }, 180);

    }));

  }

  if (document.readyState === 'loading') {

    document.addEventListener('DOMContentLoaded', playSellerRoleEnterAnimation);

  } else {

    playSellerRoleEnterAnimation();

  }

})();

</script>

<?php require_once __DIR__ . '/seller_product_warnings.php'; ?>