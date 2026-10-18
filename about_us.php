<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>About LokalCraft — Filipino Handicrafts | Artisan Heritage</title>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }
    body {
      font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, sans-serif;
      position: relative;
      min-height: 100vh;
      background: url('uploads/website background.png');
      background-size: cover;
      background-position: center;
      background-attachment: fixed;
      background-repeat: no-repeat;
    }
    body::before {
      content: "";
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(255, 248, 235, 0.60);
      backdrop-filter: blur(1px);
      z-index: -1;
    }
    .container {
      max-width: 1380px;
      margin: 0 auto;
      padding: 0 32px;
      position: relative;
      z-index: 2;
    }
    .navbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: nowrap;
      padding: 20px 0;
      gap: 20px;
      border-bottom: 1px solid rgba(160, 110, 70, 0.25);
      backdrop-filter: blur(2px);
      position: sticky;
      top: 0;
      z-index: 100;
    }
    .logo {
      font-size: 1.9rem;
      font-weight: 800;
      letter-spacing: -0.5px;
      background: linear-gradient(135deg, #A55325, #C2692E);
      background-clip: text;
      -webkit-background-clip: text;
      color: transparent;
      text-shadow: 0 1px 2px rgba(0,0,0,0.05);
      text-decoration: none;
    }
    .nav-right {
      display: flex;
      align-items: center;
      gap: 22px;
      white-space: nowrap;
    }
    .nav-link,
    .nav-button {
      color: #3F2E1F;
      font-size: 1rem;
      font-weight: 600;
      text-decoration: none;
      border: none;
      background: none;
      font-family: inherit;
      cursor: pointer;
      transition: color 0.2s ease;
    }
    .nav-link:hover,
    .nav-button:hover {
      color: #B45824;
    }
    .signup-button {
      background: #B85C28;
      color: #fff;
      border: none;
      border-radius: 10px;
      padding: 10px 18px;
      font-weight: 700;
      font-family: inherit;
      cursor: pointer;
      transition: 0.2s ease;
    }
    .signup-button:hover {
      background: #94481F;
      transform: translateY(-1px);
    }
    .about-page {
      padding: 50px 0 0;
    }
    .page-heading {
      text-align: center;
      max-width: 860px;
      margin: 0 auto;
      padding: 12px 0 34px;
    }
    .page-heading h1 {
      font-size: clamp(2.6rem, 5vw, 4rem);
      line-height: 1.1;
      font-weight: 800;
      letter-spacing: -1.2px;
      color: #111827;
      margin-bottom: 12px;
    }
    .page-heading h1 span {
      background: linear-gradient(120deg, #F3E1CE 0%, #F3E1CE 45%, rgba(243,225,206,0.42) 75%);
      padding: 0 10px;
      border-radius: 12px;
    }
    .page-heading p {
      color: #4A6180;
      font-size: 1.05rem;
      line-height: 1.5;
      font-weight: 500;
    }
    .tabs-wrap {
      display: flex;
      background: rgba(234, 233, 239, 0.92);
      border-radius: 14px;
      padding: 3px;
      margin: 0 auto 30px;
      max-width: 1180px;
      gap: 3px;
      box-shadow: inset 0 1px 0 rgba(255,255,255,.8);
    }
    .tab-button {
      flex: 1;
      border: none;
      background: transparent;
      color: #111827;
      padding: 12px 10px;
      border-radius: 11px;
      font-family: inherit;
      font-size: 0.92rem;
      font-weight: 700;
      cursor: pointer;
      transition: all .2s ease;
    }
    .tab-button:hover {
      background: rgba(255,255,255,.58);
    }
    .tab-button.active {
      background: rgba(255,255,255,.98);
      box-shadow: 0 3px 10px rgba(80,50,25,.07);
    }
    .tab-panel {
      display: none;
      max-width: 1180px;
      margin: 0 auto;
      padding-bottom: 50px;
    }
    .tab-panel.active {
      display: block;
      animation: fadeIn .18s ease;
    }
    @keyframes fadeIn {
      from { opacity: .3; transform: translateY(3px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .mission-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0,1fr));
      gap: 24px;
    }
    .content-card {
      background: rgba(255, 252, 245, .93);
      border: 1px solid rgba(220, 180, 135, .70);
      border-radius: 24px;
      padding: 28px 26px;
      box-shadow: 0 14px 26px -14px rgba(0,0,0,.08);
    }
    .card-heading {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 24px;
    }
    .icon-circle {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      display: grid;
      place-items: center;
      background: #FFF0BF;
      color: #F28A00;
      font-size: 1.35rem;
      flex: 0 0 44px;
    }
    .content-card h2 {
      font-size: 1.35rem;
      font-weight: 800;
      color: #0E1726;
    }
    .content-card p {
      color: #3D5979;
      line-height: 1.7;
      font-size: 0.95rem;
    }
    .platform-card {
      background: rgba(255,255,255,.86);
      border: 1px solid rgba(200, 198, 194, .65);
      border-radius: 22px;
      padding: 30px;
      box-shadow: 0 12px 24px -18px rgba(0,0,0,.08);
    }
    .platform-card h2,
    .help-title,
    .values-title {
      color: #0E1726;
      text-align: center;
      font-size: 1.7rem;
      font-weight: 800;
      margin-bottom: 24px;
    }
    .platform-copy {
      display: grid;
      gap: 14px;
      color: #3D5979;
      font-size: 0.96rem;
      line-height: 1.7;
    }
    .platform-copy strong {
      color: #E86F00;
    }
    .help-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0,1fr));
      gap: 18px;
    }
    .help-card {
      background: rgba(255,255,255,.9);
      border: 1px solid rgba(200,198,194,.68);
      border-radius: 20px;
      padding: 22px 18px 24px;
      text-align: center;
      min-height: 260px;
      display: flex;
      flex-direction: column;
      align-items: center;
    }
    .help-icon {
      width: 52px;
      height: 52px;
      border-radius: 50%;
      background: #FFF0BF;
      color: #F28A00;
      display: grid;
      place-items: center;
      font-size: 1.45rem;
      margin-bottom: 22px;
    }
    .help-card h3 {
      font-size: 1rem;
      color: #0E1726;
      margin-bottom: 16px;
      font-weight: 800;
    }
    .help-card p {
      color: #4F6582;
      line-height: 1.55;
      font-size: 0.84rem;
    }
    .values-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0,1fr));
      gap: 24px;
      max-width: 960px;
      margin: 0 auto;
    }
    .value-card {
      background: rgba(255,255,255,.9);
      border: 1px solid rgba(200,198,194,.68);
      border-radius: 22px;
      padding: 25px 20px 26px;
      text-align: center;
      min-height: 240px;
    }
    .value-number {
      width: 48px;
      height: 48px;
      border-radius: 50%;
      background: #E87500;
      color: #fff;
      display: grid;
      place-items: center;
      margin: 0 auto 28px;
      font-weight: 800;
      font-size: 1.05rem;
    }
    .value-card h3 {
      color: #0E1726;
      font-size: 1rem;
      margin-bottom: 20px;
      font-weight: 800;
    }
    .value-card p {
      color: #4F6582;
      line-height: 1.55;
      font-size: 0.85rem;
    }
    .cta-section {
      margin: 0 -32px;
      background: linear-gradient(135deg, #F28A00, #F07B00);
      padding: 54px 32px 62px;
      text-align: center;
      color: #fff;
    }
    .cta-section h2 {
      font-size: 1.9rem;
      font-weight: 800;
      margin-bottom: 14px;
    }
    .cta-section p {
      font-size: 0.98rem;
      margin-bottom: 24px;
    }
    .cta-actions {
      display: flex;
      justify-content: center;
      gap: 12px;
      flex-wrap: wrap;
    }
    .cta-button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-decoration: none;
      min-width: 138px;
      padding: 12px 18px;
      border-radius: 10px;
      background: #fff;
      color: #E86F00;
      border: 1px solid rgba(255,255,255,.9);
      font-weight: 700;
      font-size: 0.9rem;
      transition: 0.2s ease;
    }
    .cta-button:hover {
      transform: translateY(-2px);
      box-shadow: 0 7px 16px rgba(110,45,0,.15);
    }
    .cta-button.secondary {
      background: transparent;
      color: #fff;
      border-color: rgba(255,255,255,.95);
    }
    .footer-note {
      text-align: center;
      color: #7B5B3E;
      font-size: 0.8rem;
      padding: 18px 0 26px;
    }
    @media (max-width: 950px) {
      .help-grid {
        grid-template-columns: repeat(2, minmax(0,1fr));
      }
      .mission-grid {
        grid-template-columns: 1fr;
      }
      .values-grid {
        grid-template-columns: repeat(3, minmax(0,1fr));
      }
    }
    @media (max-width: 760px) {
      body {
        background-attachment: scroll;
        background-position: center center;
      }
      .container {
        padding: 0 20px;
      }
      .navbar {
        padding: 16px 0;
      }
      .logo {
        font-size: 1.55rem;
      }
      .nav-right {
        gap: 12px;
      }
      .nav-link,
      .nav-button {
        font-size: 0.88rem;
      }
      .signup-button {
        padding: 8px 13px;
      }
      .about-page {
        padding-top: 28px;
      }
      .tabs-wrap {
        flex-wrap: wrap;
        border-radius: 12px;
      }
      .tab-button {
        flex: 1 1 48%;
      }
      .values-grid {
        grid-template-columns: 1fr;
      }
      .cta-section {
        margin: 0 -20px;
        padding-left: 20px;
        padding-right: 20px;
      }
    }
    @media (max-width: 560px) {
      .help-grid {
        grid-template-columns: 1fr;
      }
      .platform-card,
      .content-card {
        padding: 22px 18px;
      }
      .page-heading h1 {
        font-size: 2.4rem;
      }
      .page-heading p {
        font-size: 0.93rem;
      }
    }
  </style>
</head>
<body>
  <div class="container">
<?php $publicActive = "about"; require __DIR__ . '/public_nav.php'; ?>
    <main class="about-page">
      <section class="page-heading">
        <h1>About <span>LokalCraft</span></h1>
        <p>Empowering Taguig's handicraft artisanshdhdhdhdhd through technology and community</p>
      </section>
      <div class="tabs-wrap">
        <button class="tab-button active" type="button" data-tab="mission">Mission &amp; Vision</button>
        <button class="tab-button" type="button" data-tab="platform">About Platform</button>
        <button class="tab-button" type="button" data-tab="help">How We Help</button>
        <button class="tab-button" type="button" data-tab="values">Core Values</button>
      </div>
      <section class="tab-panel active" id="mission">
        <div class="mission-grid">
          <article class="content-card">
            <div class="card-heading">
              <div class="icon-circle">◎</div>
              <h2>Mission</h2>
            </div>
            <p>Ang aming misyon ay maging isang accessible at user-friendly na platform para sa mga handicraft small businesses sa Taguig. Layunin naming tulungan ang mga lokal na artisan na makapag-organize ng kanilang negosyo, matukoy ang tamang presyo ng kanilang mga produkto, at makakonekta sa mga customer na naghahanap ng authentic at quality handicrafts.</p>
          </article>
          <article class="content-card">
            <div class="card-heading">
              <div class="icon-circle">♡</div>
              <h2>Vision</h2>
            </div>
            <p>Ang aming vision ay makita ang bawat handicraft artisan sa Taguig na umuunlad at kumikita ng sustainable income sa pamamagitan ng kanilang craft. Nais naming maging pangunahing platform para sa lokal na handicrafts, kung saan ang tradisyonal na sining at modernong teknolohiya ay nagsasama upang lumikha ng thriving community ng artisans at buyers.</p>
          </article>
        </div>
      </section>
      <section class="tab-panel" id="platform">
        <article class="platform-card">
          <h2>Tungkol sa Platform</h2>
          <div class="platform-copy">
            <p>Ang LokalCraft ay isang comprehensive e-commerce platform na specially designed para sa mga handicraft sellers at buyers sa Taguig City. Nag-ugat ang platform na ito sa pangangailangan ng mga small-scale artisans na kulang sa suporta pagdating sa pricing, marketing, at online presence.</p>
            <p>Isa sa mga pangunahing problema ng mga handicraft sellers ay ang difficulty sa pag-determine ng tamang selling price. Maraming artisans ay hindi alam kung paano i-calculate ang cost ng materials, labor, at overhead expenses, na nagreresulta sa mababang kita o overpricing. Ang LokalCraft ay nag-aalok ng advanced <strong>Costing/Pricing Calculator</strong> na tumutulong sa sellers na systematically compute ang production costs at mag-set ng profitable yet competitive prices.</p>
            <p>Sa pamamagitan ng platform na ito, ang mga artisans ay makaka-focus sa kanilang craft habang ang sistema ay tumutulong sa business side - mula sa pricing, inventory management, order tracking, hanggang sa customer communication.</p>
          </div>
        </article>
      </section>
      <section class="tab-panel" id="help">
        <h2 class="help-title">Paano Namin Tinutulungan ang Handicraft Businesses</h2>
        <div class="help-grid">
          <article class="help-card">
            <div class="help-icon">▣</div>
            <h3>Pricing Calculator</h3>
            <p>Makatulong sa mga artisan na matukoy ang tamang presyo ng kanilang produkto base sa materials, labor, at iba pang gastoso.</p>
          </article>
          <article class="help-card">
            <div class="help-icon">▤</div>
            <h3>Online Marketplace</h3>
            <p>Magkaroon ng digital presence at makarang sa mas malawak na market sa buong Taguig at kalapit na lugar.</p>
          </article>
          <article class="help-card">
            <div class="help-icon">↗</div>
            <h3>Business Growth</h3>
            <p>Suportahan ang paglaki ng negosyo sa pamamagitan ng organized product at order management system.</p>
          </article>
          <article class="help-card">
            <div class="help-icon">♧</div>
            <h3>Direct Connection</h3>
            <p>Direktang koneksyon sa pagitan ng buyers at sellers para sa mas mabilis na komunikasyon at negosasyon.</p>
          </article>
        </div>
      </section>
      <section class="tab-panel" id="values">
        <h2 class="values-title">Aming mga Halaga</h2>
        <div class="values-grid">
          <article class="value-card">
            <div class="value-number">1</div>
            <h3>Community First</h3>
            <p>Unahin namin ang kapakanan ng lokal na komunidad at ang sustainable growth ng bawat artisan.</p>
          </article>
          <article class="value-card">
            <div class="value-number">2</div>
            <h3>Transparency</h3>
            <p>Transparent pricing at walang hidden fees para sa sellers at buyers.</p>
          </article>
          <article class="value-card">
            <div class="value-number">3</div>
            <h3>Empowerment</h3>
            <p>Bigyan ng tools at knowledge ang mga artisans upang sila ay maging independent at mas confident sa kanilang negosyo.</p>
          </article>
        </div>
      </section>
    </main>
    <section class="cta-section">
      <h2>Ready to Grow Your Handicraft Business?</h2>
      <p>Sumali sa LokalCraft community at simulan ang iyong journey towards sustainable success.</p>
      <div class="cta-actions">
        <a href="become_a_seller.php" class="cta-button">Get Started Today</a>
        <a href="index.php" class="cta-button secondary">Back Home</a>
      </div>
    </section>
    <div class="footer-note">
      🌾 LokalCraft — Preserving Filipino heritage, one craft at a time
    </div>
  </div>
  <script>
    const tabs = document.querySelectorAll('.tab-button');
    const panels = document.querySelectorAll('.tab-panel');
    function activateTab(tabName) {
      tabs.forEach(tab => {
        tab.classList.toggle('active', tab.dataset.tab === tabName);
      });
      panels.forEach(panel => {
        panel.classList.toggle('active', panel.id === tabName);
      });
    }
    tabs.forEach(tab => {
      tab.addEventListener('click', () => {
        activateTab(tab.dataset.tab);
      });
    });
  </script>
</body>
</html>
