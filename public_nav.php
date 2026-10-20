<?php
$publicActive = isset($publicActive) && is_string($publicActive) ? $publicActive : '';
?>
<header class="public-header">
  <div class="public-header-inner">
    <a href="index.php" class="public-logo">LokalCraft</a>
    <button class="public-menu-toggle" id="publicMenuToggle" type="button"
            aria-label="Open menu" aria-expanded="false" aria-controls="publicNav">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" aria-hidden="true" focusable="false">
        <path class="menu-line menu-line-top" d="M4 6h16"/>
        <path class="menu-line menu-line-mid" d="M4 12h16"/>
        <path class="menu-line menu-line-bottom" d="M4 18h16"/>
      </svg>
    </button>
    <nav class="public-nav" id="publicNav" aria-label="Public navigation">
      <a href="index.php" class="public-nav-link <?php echo $publicActive === 'home' ? 'active' : ''; ?>">Home</a>
      <a href="about_us.php" class="public-nav-link <?php echo $publicActive === 'about' ? 'active' : ''; ?>">About Us</a>
      <div class="public-auth">
        <a href="login.php" class="public-auth-link <?php echo $publicActive === 'login' ? 'active' : ''; ?>">Login</a>
        <a href="signup.php" class="public-auth-link <?php echo $publicActive === 'signup' ? 'active' : ''; ?>">Sign Up</a>
      </div>
    </nav>
  </div>
</header>
<style>
  /* Shared public header: fluid sizing for normal desktop and browser zoom. */
  .public-header,
  .public-header * { box-sizing: border-box; }
  .public-header { width: 100%; position: sticky; top: 0; z-index: 1000; background: transparent; border: 0; box-shadow: none; }
  .public-header-inner {
    width: min(1600px, calc(100% - 64px));
    min-height: 82px;
    margin: 0 auto;
    padding: 20px 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: clamp(12px, 1.5vw, 20px);
    flex-wrap: nowrap;
    border-bottom: 1px solid rgba(160,110,70,.25);
    backdrop-filter: blur(2px);
    position: relative;
    min-width: 0;
  }
  .public-logo {
    flex: 0 0 auto;
    font-size: clamp(1.65rem, 2.25vw, 1.9rem);
    line-height: 1;
    font-weight: 800;
    letter-spacing: -.5px;
    background: linear-gradient(135deg,#A55325,#C2692E);
    background-clip: text;
    -webkit-background-clip: text;
    color: transparent;
    text-decoration: none;
    text-shadow: 0 1px 2px rgba(0,0,0,.05);
    white-space: nowrap;
  }
  .public-nav {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: clamp(12px, 1.75vw, 24px);
    flex-wrap: nowrap;
    white-space: nowrap;
    min-width: 0;
  }
  .public-nav-link,.public-auth-link {
    text-decoration: none;
    font-family: inherit;
    font-weight: 500;
    color: #3F2E1F;
    font-size: 1rem;
    transition: color .2s ease;
  }
  .public-nav-link:hover,.public-auth-link:hover,.public-nav-link.active,.public-auth-link.active { color: #B45824; }
  .public-auth { display: flex; align-items: center; gap: clamp(12px, 1.5vw, 20px); flex: 0 0 auto; }
  .public-menu-toggle { display: none; width: 42px; height: 42px; padding: 9px; border: 0; border-radius: 10px; background: transparent; cursor: pointer; align-items: center; justify-content: center; flex-shrink: 0; color: #3F2E1F; }
  .public-menu-toggle svg { width: 25px; height: 25px; overflow: visible; }
  .public-menu-toggle .menu-line { transform-origin: 12px 12px; transition: transform .25s ease, opacity .2s ease; }
  .public-menu-toggle:hover { background: rgba(255,255,255,.35); }
  .public-menu-toggle.active .menu-line-top { transform: translateY(6px) rotate(45deg); }
  .public-menu-toggle.active .menu-line-mid { opacity: 0; }
  .public-menu-toggle.active .menu-line-bottom { transform: translateY(-6px) rotate(-45deg); }
  @media (max-width: 900px) {
    .public-header-inner { width: min(100% - 40px,1600px); }
    .public-nav { gap: 18px; }
    .public-nav-link,.public-auth-link { font-size: .9rem; }
    .public-auth { gap: 16px; }
  }
  @media (max-width: 700px) {
    .public-header-inner { width: min(100% - 28px,1600px); min-height: 70px; padding: 16px 0; gap: 12px; }
    .public-logo { font-size: 1.5rem; }
    .public-menu-toggle { display: flex; margin-left: auto; position: relative; z-index: 1002; }
    .public-nav {
      display: none;
      position: absolute;
      top: calc(100% + 8px);
      right: 0;
      width: min(220px, calc(100vw - 28px));
      max-height: calc(100dvh - 86px);
      overflow-y: auto;
      padding: 14px;
      background: rgba(255,250,240,.98);
      border: 1px solid rgba(160,110,70,.18);
      border-radius: 16px;
      box-shadow: 0 12px 30px rgba(63,46,31,.15);
      backdrop-filter: blur(12px);
      flex-direction: column;
      align-items: stretch;
      gap: 0;
      white-space: normal;
      z-index: 1001;
    }
    .public-nav.open { display: flex; }
    .public-nav-link { display: block !important; width: 100%; padding: 12px 13px; border-radius: 9px; font-size: .95rem; }
    .public-nav-link:hover,.public-nav-link.active { background: rgba(180,88,36,.08); }
    .public-auth { display: flex; flex-direction: column; align-items: stretch; gap: 0; margin-top: 5px; padding-top: 5px; border-top: 1px solid rgba(160,110,70,.15); }
    .public-auth-link { display: block; width: 100%; padding: 12px 13px; border-radius: 9px; font-size: .95rem; }
    .public-auth-link:hover,.public-auth-link.active { background: rgba(180,88,36,.08); }
  }
  @media (max-width: 380px) {
    .public-header-inner { width: calc(100% - 22px); }
    .public-logo { font-size: 1.35rem; }
    .public-nav { width: min(205px, calc(100vw - 22px)); }
  }
  @media (prefers-reduced-motion: reduce) {
    .public-menu-toggle .menu-line { transition-duration: .01ms; }
  }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const menuToggle = document.getElementById('publicMenuToggle');
  const publicNav = document.getElementById('publicNav');
  if (!menuToggle || !publicNav) return;
  function closePublicMenu() {
    publicNav.classList.remove('open');
    menuToggle.classList.remove('active');
    menuToggle.setAttribute('aria-expanded', 'false');
    menuToggle.setAttribute('aria-label', 'Open menu');
  }
  menuToggle.addEventListener('click', function () {
    const isOpen = publicNav.classList.toggle('open');
    menuToggle.classList.toggle('active', isOpen);
    menuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    menuToggle.setAttribute('aria-label', isOpen ? 'Close menu' : 'Open menu');
  });
  document.addEventListener('click', function (event) {
    if (!publicNav.contains(event.target) && !menuToggle.contains(event.target)) closePublicMenu();
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && publicNav.classList.contains('open')) {
      closePublicMenu();
      menuToggle.focus();
    }
  });
  window.addEventListener('resize', function () {
    if (window.innerWidth > 700) closePublicMenu();
  });
});
</script>
