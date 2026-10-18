<?php
require_once __DIR__ . '/config/database.php';
$artisanCount = 0;
if (isset($conn) && $conn instanceof mysqli) {
    try {
        $result = $conn->query(
            "SELECT COUNT(*) AS artisan_count
             FROM users AS u
             WHERE u.SellerOrNot = 1
               AND u.account_status = 'active'
               AND EXISTS (
                   SELECT 1
                   FROM seller_applications AS sa
                   WHERE sa.user_id = u.id
                     AND sa.status = 'approved'
               )"
        );
        if ($result instanceof mysqli_result) {
            $row = $result->fetch_assoc();
            $artisanCount = (int)($row['artisan_count'] ?? 0);
            $result->free();
        } else {
            error_log('LokalCraft homepage artisan count query failed.');
        }
    } catch (Throwable $e) {
        error_log('LokalCraft homepage artisan count query failed: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="Discover authentic Filipino handicrafts from local artisans in Taguig through LokalCraft.">
    <title>LokalCraft — Filipino Handicrafts | Artisan Heritage</title>
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
            background: url('uploads/website background.png') center / cover no-repeat fixed;
        }
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background-color: rgba(255, 248, 235, 0.60);
            pointer-events: none;
            z-index: 0;
        }
        .container {
            max-width: 1380px;
            margin: 0 auto;
            padding: 0 32px;
            position: relative;
            z-index: 1;
        }
        .hero {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 48px;
            padding: 60px 0 48px;
        }
        .hero-left {
            flex: 1.2;
            min-width: 280px;
        }
        .hero-headline {
            font-size: 3.6rem;
            font-weight: 800;
            line-height: 1.2;
            letter-spacing: -0.02em;
            color: #2B1E12;
            margin-bottom: 20px;
            text-shadow: 0 1px 2px rgba(255, 245, 225, 0.5);
        }
        .hero-headline span {
            background: linear-gradient(120deg, #F3E1CE 0%, #F3E1CE 40%, rgba(243, 225, 206, 0.4) 70%);
            padding: 0 8px;
            border-radius: 10px;
            box-decoration-break: clone;
            -webkit-box-decoration-break: clone;
        }
        .hero-description {
            font-size: 1.1rem;
            color: #4A351F;
            line-height: 1.5;
            margin-bottom: 32px;
            max-width: 520px;
            font-weight: 500;
        }
        .button-group {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 28px;
        }
        .btn-become-seller,
        .btn-browse {
            font-family: inherit;
            font-weight: 600;
            font-size: 1rem;
            border-radius: 60px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            transition: background-color 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
        }
        .btn-become-seller {
            background-color: #B85C28;
            border: 0;
            color: #fff;
            padding: 14px 38px;
            box-shadow: 0 6px 14px rgba(160, 80, 35, 0.3);
        }
        .btn-become-seller:hover,
        .btn-become-seller:focus-visible {
            background-color: #94481F;
            transform: translateY(-2px);
        }
        .btn-browse {
            background-color: rgba(255, 250, 240, 0.85);
            backdrop-filter: blur(4px);
            border: 1.5px solid #C87A48;
            color: #B85C28;
            padding: 12px 34px;
        }
        .btn-browse:hover,
        .btn-browse:focus-visible {
            background-color: #FFF3E6;
            border-color: #A55122;
        }
        .hero-right {
            flex: 1;
            min-width: 280px;
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .image-wrapper {
            position: relative;
            width: 100%;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 25px 35px -12px rgba(0, 0, 0, 0.3);
            background: #E6D5C0;
            border: 2px solid rgba(255, 245, 225, 0.7);
        }
        .artisan-image {
            width: 100%;
            height: auto;
            display: block;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        .artisan-image:hover {
            transform: scale(1.02);
        }
        .stat-badge {
            position: absolute;
            top: 20px;
            right: -20px;
            background: rgba(255, 252, 240, 0.96);
            backdrop-filter: blur(1px);
            padding: 12px 22px;
            border-radius: 10px;
            box-shadow: 0 12px 22px rgba(0, 0, 0, 0.12);
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid #FFDEC2;
            z-index: 2;
            flex-wrap: nowrap;
        }
        .stat-number-large {
            font-size: 2rem;
            font-weight: 800;
            color: #B85C28;
            line-height: 1;
        }
        .stat-label-large {
            font-weight: 600;
            color: #3F2E1F;
            font-size: 0.9rem;
            letter-spacing: -0.2px;
        }
        .stat-icon {
            font-size: 1.9rem;
        }
        .features-section {
            padding: 40px 0 30px;
        }
        .features-grid {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 32px;
            margin: 30px 0 20px;
        }
        .feature-card {
            background: rgba(255, 252, 245, 0.88);
            backdrop-filter: blur(8px);
            border-radius: 32px;
            padding: 34px 24px;
            text-align: center;
            flex: 1 1 260px;
            box-shadow: 0 12px 26px -12px rgba(0, 0, 0, 0.1);
            transition: transform 0.25s ease, background-color 0.25s ease, border-color 0.25s ease;
            border: 1px solid rgba(220, 180, 135, 0.5);
        }
        .feature-card:hover {
            transform: translateY(-5px);
            background: rgba(255, 253, 250, 0.96);
            border-color: #DBAF7A;
        }
        .feature-icon {
            font-size: 2.7rem;
            margin-bottom: 20px;
        }
        .feature-card h3 {
            font-size: 1.45rem;
            font-weight: 700;
            margin-bottom: 12px;
            color: #2B1E12;
        }
        .feature-card p {
            color: #5A3F28;
            font-size: 0.95rem;
            line-height: 1.45;
        }
        .footer-note {
            text-align: center;
            color: #7B5B3E;
            font-size: 0.8rem;
            padding-bottom: 24px;
        }
        @media (max-width: 880px) {
            .stat-badge {
                position: relative;
                top: auto;
                right: auto;
                margin-top: 16px;
                align-self: flex-start;
                background: rgba(255, 252, 240, 0.98);
            }
            .hero-right {
                align-items: flex-start;
            }
        }
        @media (max-width: 780px) {
            body {
                background-attachment: scroll;
                background-position: center center;
            }
            .container {
                padding: 0 24px;
            }
            .hero-headline {
                font-size: 2.7rem;
            }
            .hero {
                flex-direction: column;
            }
            .hero-left,
            .hero-right {
                width: 100%;
                min-width: 0;
            }
        }
        @media (max-width: 550px) {
            .container {
                padding: 0 14px;
            }
            .hero {
                gap: 30px;
                padding: 36px 0 30px;
            }
            .hero-headline {
                font-size: 2.2rem;
            }
            .button-group {
                gap: 14px;
            }
            .btn-become-seller,
            .btn-browse {
                max-width: 100%;
            }
            .features-grid {
                gap: 18px;
            }
        }
    </style>
</head>
<body>
<?php
$publicActive = 'home';
require __DIR__ . '/public_nav.php';
?>
<main class="container">
    <section class="hero" aria-labelledby="heroTitle">
        <div class="hero-left">
            <h1 class="hero-headline" id="heroTitle">Discover Authentic <span>Filipino Handicrafts</span></h1>
            <p class="hero-description">Connect with local artisans in Taguig and bring home unique, handmade treasures. Support local craftsmanship and preserve Filipino heritage.</p>
            <div class="button-group">
                <a href="become_a_seller.php" class="btn-become-seller">Become a Seller</a>
                <a href="browse_products.php" class="btn-browse">Browse Products</a>
            </div>
        </div>
        <div class="hero-right">
            <div class="image-wrapper">
                <img
                    class="artisan-image"
                    src="uploads/artisan-hero.jpg"
                    alt="Filipino artisan weaving traditional handicraft"
                    fetchpriority="high"
                >
            </div>
            <div class="stat-badge" aria-label="<?php echo $artisanCount; ?> local artisans">
                <span class="stat-icon" aria-hidden="true">🧑‍🎨</span>
                <span class="stat-number-large"><?php echo $artisanCount; ?></span>
                <span class="stat-label-large">Local Artisans</span>
            </div>
        </div>
    </section>
    <section class="features-section" aria-label="Why shop at LokalCraft">
        <div class="features-grid">
            <article class="feature-card">
                <div class="feature-icon" aria-hidden="true">🪢</div>
                <h3>100% handmade</h3>
                <p>100% handmade by local Filipino artisans</p>
            </article>
            <article class="feature-card">
                <div class="feature-icon" aria-hidden="true">📍</div>
                <h3>Direct from artisans</h3>
                <p>Direct from artisans across Taguig</p>
            </article>
            <article class="feature-card">
                <div class="feature-icon" aria-hidden="true">🤝</div>
                <h3>Empower local</h3>
                <p>Empower local communities and artisans</p>
            </article>
        </div>
    </section>
    <footer class="footer-note">🌾 LokalCraft — Preserving Filipino heritage, one craft at a time</footer>
</main>
</body>
</html>
