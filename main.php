<?php
require_once __DIR__ . '/config/database.php';
$artisanCount = 0;
$featuredProducts = [];

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
// Only six eligible public listings are loaded for the homepage preview.
if (isset($conn) && $conn instanceof mysqli) {
    try {
        $featuredResult = $conn->query(
            "SELECT p.product_id, p.product_photo, p.product_name, p.craft_category,
                    p.description, p.cost, p.stock, p.status,
                    u.firstname, u.lastname, b.businessname, l.barangay, l.city
             FROM products AS p
             INNER JOIN users AS u ON u.id = p.seller_id
             LEFT JOIN businessinfo AS b ON b.user_id = p.seller_id
             LEFT JOIN locationinfo AS l ON l.user_id = p.seller_id
             WHERE LOWER(p.status) IN ('available', 'out of stock')
               AND u.SellerOrNot = 1
               AND u.account_status = 'active'
               AND EXISTS (
                   SELECT 1 FROM seller_applications AS sa
                   WHERE sa.user_id = p.seller_id AND sa.status = 'approved'
               )
             ORDER BY RAND()
             LIMIT 6"
        );
        if ($featuredResult instanceof mysqli_result) {
            while ($productRow = $featuredResult->fetch_assoc()) {
                $featuredProducts[] = $productRow;
            }
            $featuredResult->free();
        } else {
            error_log('LokalCraft homepage product preview query failed.');
        }
    } catch (Throwable $e) {
        error_log('LokalCraft homepage product preview query failed: ' . $e->getMessage());
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

    /* About Us: continuous sections, no hidden panels or tab controls. */
    .about-page { padding: 50px 0 0; }
    .page-heading { text-align: center; max-width: 860px; margin: 0 auto; padding: 12px 0 34px; }
    .page-heading h2 { font-size: clamp(2.6rem, 5vw, 4rem); line-height: 1.1; font-weight: 800; letter-spacing: -1.2px; color: #2B1E12; margin-bottom: 12px; }
    .page-heading h2 span { background: linear-gradient(120deg,#F3E1CE 0%,#F3E1CE 45%,rgba(243,225,206,.42) 75%); padding: 0 10px; border-radius: 12px; }
    .page-heading p { color: #654C36; font-size: 1.05rem; line-height: 1.5; font-weight: 500; }
    .about-block { max-width: 1180px; margin: 0 auto 38px; scroll-margin-top: 0; }
    .about-block:last-child { margin-bottom: 0; }
    .mission-grid { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 24px; }
    .content-card { background: rgba(255,252,245,.93); border: 1px solid rgba(220,180,135,.70); border-radius: 24px; padding: 28px 26px; box-shadow: 0 14px 26px -14px rgba(0,0,0,.08); }
    .card-heading { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
    .icon-circle { width: 44px; height: 44px; border-radius: 50%; display: grid; place-items: center; background: #FFF0DF; color: #A55325; flex: 0 0 44px; }
    .icon-circle svg { width: 24px; height: 24px; }
    .content-card h2 { font-size: 1.35rem; font-weight: 800; color: #2B1E12; }
    .content-card p { color: #62472F; line-height: 1.7; font-size: .95rem; }
    .platform-card { background: rgba(255,252,245,.91); border: 1px solid rgba(220,180,135,.65); border-radius: 22px; padding: 30px; box-shadow: 0 12px 24px -18px rgba(0,0,0,.08); }
    .platform-card h2,.help-title,.values-title { color: #2B1E12; text-align: center; font-size: 1.7rem; font-weight: 800; margin-bottom: 24px; }
    .platform-copy { display: grid; gap: 14px; color: #62472F; font-size: .96rem; line-height: 1.7; }
    .platform-copy strong { color: #A55325; }
    .help-grid { display: grid; grid-template-columns: repeat(4,minmax(0,1fr)); gap: 18px; }
    .help-card { background: rgba(255,252,245,.9); border: 1px solid rgba(220,180,135,.65); border-radius: 20px; padding: 22px 18px 24px; text-align: center; min-height: 260px; display: flex; flex-direction: column; align-items: center; }
    .help-icon { width: 52px; height: 52px; border-radius: 50%; background: #FFF0DF; color: #A55325; display: grid; place-items: center; margin-bottom: 22px; }
    .help-icon svg { width: 27px; height: 27px; }
    .help-card h3 { font-size: 1rem; color: #2B1E12; margin-bottom: 16px; font-weight: 800; }
    .help-card p { color: #62472F; line-height: 1.55; font-size: .84rem; }
    .values-grid { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 24px; max-width: 960px; margin: 0 auto; }
    .value-card { background: rgba(255,252,245,.9); border: 1px solid rgba(220,180,135,.65); border-radius: 22px; padding: 25px 20px 26px; text-align: center; min-height: 240px; }
    .value-number { width: 48px; height: 48px; border-radius: 50%; background: #B85C28; color: #fff; display: grid; place-items: center; margin: 0 auto 28px; font-weight: 800; font-size: 1.05rem; }
    .value-card h3 { color: #2B1E12; font-size: 1rem; margin-bottom: 20px; font-weight: 800; }
    .value-card p { color: #62472F; line-height: 1.55; font-size: .85rem; }
    .cta-section { margin: 0 -32px; background: linear-gradient(135deg,#A55325,#C2692E); padding: 54px 32px 62px; text-align: center; color: #fff; }
    .cta-section h2 { font-size: 1.9rem; font-weight: 800; margin-bottom: 14px; }
    .cta-section p { font-size: .98rem; margin-bottom: 24px; }
    .cta-actions { display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; }
    .cta-button { display: inline-flex; align-items: center; justify-content: center; text-decoration: none; min-width: 138px; padding: 12px 18px; border-radius: 10px; background: #fff; color: #A55325; border: 1px solid rgba(255,255,255,.9); font-weight: 700; font-size: .9rem; transition: transform .2s ease, box-shadow .2s ease; }
    .cta-button:hover { transform: translateY(-2px); box-shadow: 0 7px 16px rgba(80,40,12,.2); }
    .cta-button.secondary { background: transparent; color: #fff; border-color: rgba(255,255,255,.95); }

    /* Browse Products: maximum six cards in a single horizontal track. */
    .browse-section { padding: 58px 0 56px; scroll-margin-top: 0; }
    .browse-heading { text-align: center; max-width: 780px; margin: 0 auto 26px; }
    .browse-heading h2 { color: #2B1E12; font-size: clamp(2rem,4vw,3rem); font-weight: 800; line-height: 1.2; margin-bottom: 10px; }
    .browse-heading p { color: #62472F; font-size: .98rem; line-height: 1.55; }
    .products-track { display: flex; gap: 18px; overflow-x: auto; overflow-y: hidden; overscroll-behavior-inline: contain; scroll-snap-type: x proximity; scroll-behavior: smooth; padding: 6px 2px 18px; scrollbar-width: thin; scrollbar-color: #B85C28 rgba(225,190,153,.4); -webkit-overflow-scrolling: touch; }
    .products-track:focus-visible { outline: 3px solid #B85C28; outline-offset: 3px; }
    .products-track::-webkit-scrollbar { height: 8px; }
    .products-track::-webkit-scrollbar-thumb { background: #B85C28; border-radius: 20px; }
    .products-track::-webkit-scrollbar-track { background: rgba(225,190,153,.4); border-radius: 20px; }
    .preview-card { flex: 0 0 clamp(238px,26vw,286px); min-width: 0; background: rgba(255,252,245,.93); border: 1px solid rgba(220,180,135,.65); border-radius: 20px; overflow: hidden; box-shadow: 0 12px 24px rgba(80,50,25,.08); scroll-snap-align: start; display: flex; flex-direction: column; }
    .preview-image { height: 185px; position: relative; background: #E8D5C0; }
    .preview-image img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .preview-placeholder { width: 100%; height: 100%; display: grid; place-items: center; color: #A06435; }
    .preview-placeholder svg { width: 44px; height: 44px; }
    .preview-category { position: absolute; top: 10px; left: 10px; max-width: calc(100% - 20px); padding: 5px 9px; border-radius: 30px; background: rgba(255,252,245,.96); color: #A55325; font-size: .7rem; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .preview-body { padding: 16px; flex: 1; display: flex; flex-direction: column; min-width: 0; }
    .preview-name { color: #2B1E12; font-size: 1.06rem; font-weight: 800; line-height: 1.3; margin-bottom: 4px; overflow-wrap: anywhere; }
    .preview-seller,.preview-location { color: #72543C; font-size: .77rem; margin-bottom: 6px; overflow-wrap: anywhere; }
    .preview-location { display: flex; align-items: center; gap: 5px; }
    .preview-location svg { width: 14px; height: 14px; flex: none; }
    .preview-description { color: #62472F; font-size: .81rem; line-height: 1.45; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; line-clamp: 2; overflow: hidden; margin: 8px 0 12px; }
    .preview-bottom { margin-top: auto; display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
    .preview-price { color: #B85C28; font-size: 1.08rem; font-weight: 800; }
    .preview-stock { color: #456643; background: #EDF5E9; border-radius: 24px; padding: 5px 8px; font-size: .69rem; font-weight: 700; }
    .preview-button { width: 100%; display: block; text-align: center; text-decoration: none; padding: 11px 10px; background: #B85C28; color: #fff; border-radius: 11px; font-family: inherit; font-size: .83rem; font-weight: 700; border: 0; }
    .preview-button:hover { background: #94481F; }
    .preview-button:disabled { opacity: .55; cursor: not-allowed; }
    .browse-empty { width: 100%; padding: 40px 20px; border-radius: 20px; text-align: center; background: rgba(255,252,245,.86); color: #62472F; border: 1px solid rgba(220,180,135,.65); }
    .browse-empty h3 { color: #2B1E12; margin-bottom: 8px; }
    .browse-empty p { line-height: 1.6; }
    @media (max-width: 950px) { .help-grid { grid-template-columns: repeat(2,minmax(0,1fr)); } .mission-grid { grid-template-columns: 1fr; } }
    @media (max-width: 780px) { .about-page { padding-top: 28px; } .values-grid { grid-template-columns: 1fr; } .cta-section { margin: 0 -24px; padding-left: 24px; padding-right: 24px; } .preview-card { flex-basis: 260px; } }
    @media (max-width: 560px) { .help-grid { grid-template-columns: 1fr; } .platform-card,.content-card { padding: 22px 18px; } .page-heading h2 { font-size: clamp(2.2rem,9vw,2.6rem); } .page-heading p { font-size: .93rem; } .products-track { gap: 12px; } .preview-card { flex-basis: min(82vw,280px); } .browse-section { padding: 43px 0 40px; } }
    @media (max-width: 550px) { .cta-section { margin: 0 -14px; padding-left: 14px; padding-right: 14px; } }

    /* Joined Home -> About scrolling experience. No external animation libraries. */
    html { scroll-behavior: smooth; scroll-padding-top: 94px; }
    body { overflow-x: hidden; overflow-x: clip; }
    .scroll-progress {
      position: fixed; left: 0; top: 0; right: 0; height: 3px; z-index: 1300;
      pointer-events: none; transform: scaleX(0); transform-origin: left;
      background: linear-gradient(90deg, #b85c28, #ed9b49 55%, #e4bb73);
      will-change: transform;
    }
    #home, #about { scroll-margin-top: 0; }
    #home { isolation: isolate; }
    .hero { min-height: min(75svh, 735px); position: relative; }
    .hero-right { --photo-shift: 0px; }
    .image-wrapper { transform: translate3d(0, var(--photo-shift), 0); transition: box-shadow .45s ease; }
    .hero-right:hover .image-wrapper { box-shadow: 0 30px 46px -15px rgba(0,0,0,.3); }
    .stat-badge { animation: badgeBreathe 5s ease-in-out infinite; }
    @keyframes badgeBreathe { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }
    .stat-icon svg { display: block; width: 30px; height: 30px; color: #A55325; }
    .feature-icon svg { display: block; width: 43px; height: 43px; margin: 0 auto; color: #B85C28; }
    .footer-icon { display: inline-flex; vertical-align: -4px; margin-right: 3px; }
    .footer-icon svg { width: 17px; height: 17px; }
    .story-bridge {
      position: relative; min-height: 220px; padding: 60px 22px 52px;
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      gap: 11px; text-align: center; overflow: hidden; isolation: isolate;
      margin: 28px -32px 0;
    }
    .story-bridge::before {
      content: ''; position: absolute; z-index: -1; inset: 0;
      background: linear-gradient(180deg, rgba(255,249,237,0) 0%, rgba(245,226,202,.8) 52%, rgba(255,248,237,.28) 100%);
      border-radius: 45% 45% 0 0 / 19% 19% 0 0;
    }
    .story-bridge::after {
      content: ''; position: absolute; width: 240px; height: 240px;
      border: 1px solid rgba(188,102,44,.17); border-radius: 50%;
      left: 50%; bottom: -197px; transform: translateX(-50%);
    }
    .story-eyebrow { font-size: .72rem; color: #b85c28; letter-spacing: .24em; font-weight: 800; }
    .story-bridge h2 { font-size: clamp(1.2rem, 2.5vw, 1.8rem); line-height: 1.3; color: #3b281a; max-width: 700px; letter-spacing: -.02em; }
    .story-scroll-cue { display: inline-flex; align-items: center; gap: 6px; margin-top: 6px; color: #a25b30; font-size: .75rem; text-decoration: none; font-weight: 700; }
    .story-scroll-cue svg { width: 17px; height: 17px; animation: cueDrop 2.1s ease-in-out infinite; }
    @keyframes cueDrop { 0%, 100% { transform: translateY(0); } 55% { transform: translateY(7px); } }
    .about-section { position: relative; padding-top: 28px; }
    .about-section::before {
      content: ''; position: absolute; pointer-events: none; z-index: -1;
      top: 20px; left: -80px; width: 230px; height: 230px;
      border-radius: 50%; background: radial-gradient(circle, rgba(237,171,104,.20), transparent 70%);
    }
    .about-section .page-heading { padding-top: 16px; }
    .about-section .page-heading h2 { color: #2b1e12; }
    .about-section .page-heading p { color: #654c36; }
    .story-scroll-cue:focus-visible, .public-nav-link:focus-visible, .preview-button:focus-visible, .btn-browse:focus-visible {
      outline: 3px solid rgba(184,92,40,.52); outline-offset: 3px;
    }
    .about-section .content-card, .about-section .platform-card, .about-section .help-card, .about-section .value-card {
      transition: transform .4s cubic-bezier(.16,1,.3,1), box-shadow .4s ease, border-color .4s ease;
    }
    @media (hover: hover) {
      .about-section .content-card:hover, .about-section .platform-card:hover, .about-section .help-card:hover, .about-section .value-card:hover {
        transform: translateY(-7px); box-shadow: 0 19px 35px -17px rgba(112,62,27,.23); border-color: rgba(193,129,74,.65);
      }
    }
    .cta-section { position: relative; overflow: hidden; }
    .cta-section::before, .cta-section::after {
      content: ''; position: absolute; pointer-events: none; width: 210px; height: 210px;
      border: 1px solid rgba(255,255,255,.22); border-radius: 50%;
    }
    .cta-section::before { left: -100px; top: -115px; }
    .cta-section::after { right: -100px; bottom: -145px; }
    .cta-section > * { position: relative; z-index: 1; }
    /* Progressive enhancement: sections are never hidden without a working observer. */
    html.motion-ready .reveal:not(.is-visible) {
      opacity: 0; filter: blur(7px);
      transform: translate3d(0, 43px, 0) scale(.975);
    }
    html.motion-ready .reveal[data-reveal="left"]:not(.is-visible) { transform: translate3d(-42px, 10px, 0) scale(.98); }
    html.motion-ready .reveal[data-reveal="right"]:not(.is-visible) { transform: translate3d(42px, 10px, 0) scale(.98); }
    html.motion-ready .reveal {
      transition: opacity .95s cubic-bezier(.16,1,.3,1),
                  transform 1.05s cubic-bezier(.16,1,.3,1),
                  filter .9s cubic-bezier(.16,1,.3,1);
      transition-delay: var(--reveal-delay, 0ms);
    }
    html.motion-ready .reveal.is-visible { opacity: 1; transform: translate3d(0,0,0) scale(1); filter: blur(0); }
    @media (max-width: 780px) {
      .hero { min-height: 0; }
      .hero-right { --photo-shift: 0px !important; }
      .story-bridge { margin-left: -24px; margin-right: -24px; min-height: 210px; }
    }
    @media (max-width: 550px) {
      .story-bridge { margin-left: -14px; margin-right: -14px; padding: 47px 22px; }
      .story-bridge h2 { max-width: 320px; }
    }
    @media (prefers-reduced-motion: reduce) {
      html, .products-track { scroll-behavior: auto; }
      *, *::before, *::after { animation-duration: .01ms !important; animation-iteration-count: 1 !important; transition-duration: .01ms !important; }
      html.motion-ready .reveal, html.motion-ready .reveal:not(.is-visible) { opacity: 1; filter: none; transform: none; }
      .hero-right { --photo-shift: 0px !important; }
    }

    </style>
</head>
<body>
<?php
$publicActive = 'home';
require __DIR__ . '/public_nav.php';
?>
<div class="scroll-progress" id="scrollProgress" aria-hidden="true"></div>
<main class="container" id="home">
    <section class="hero" aria-labelledby="heroTitle">
        <div class="hero-left reveal" data-reveal="left">
            <h1 class="hero-headline" id="heroTitle">Discover Authentic <span>Filipino Handicrafts</span></h1>
            <p class="hero-description">Connect with local artisans in Taguig and bring home unique, handmade treasures. Support local craftsmanship and preserve Filipino heritage.</p>
            <div class="button-group">
                <a href="become_a_seller.php" class="btn-become-seller">Become a Seller</a>
                <a href="#browse-products" class="btn-browse">Browse Products</a>
            </div>
        </div>
        <div class="hero-right reveal" data-reveal="right">
            <div class="image-wrapper">
                <img
                    class="artisan-image"
                    src="uploads/artisan-hero.jpg"
                    alt="Filipino artisan weaving traditional handicraft"
                    fetchpriority="high"
                >
            </div>
            <div class="stat-badge" aria-label="<?php echo $artisanCount; ?> local artisans">
                <span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="13.5" cy="6.5" r="1.5"/><path d="M15 11.4V9a4 4 0 0 0-8 0v2.4M7 20v-4a5 5 0 0 1 10 0v4"/><path d="M4 20h16M17.5 5l1-2m.5 6 2 1"/></svg></span>
                <span class="stat-number-large"><?php echo $artisanCount; ?></span>
                <span class="stat-label-large">Local Artisans</span>
            </div>
        </div>
    </section>
    <section class="features-section" aria-label="Why shop at LokalCraft">
        <div class="features-grid">
            <article class="feature-card reveal" data-reveal="up">
                <div class="feature-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg></div>
                <h3>100% handmade</h3>
                <p>100% handmade by local Filipino artisans</p>
            </article>
            <article class="feature-card reveal" data-reveal="up">
                <div class="feature-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg></div>
                <h3>Direct from artisans</h3>
                <p>Direct from artisans across Taguig</p>
            </article>
            <article class="feature-card reveal" data-reveal="up">
                <div class="feature-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m2 12 4-4 4 2 4-2 4 4"/><path d="m6 17 4 3c.8.6 1.5.6 2 0l6-6M2 13l-1 1 4 5 2-2m14-4 1 1-4 5-2-2"/></svg></div>
                <h3>Empower local</h3>
                <p>Empower local communities and artisans</p>
            </article>
        </div>
    </section>
<section class="story-bridge" aria-label="Discover LokalCraft">
  <p class="story-eyebrow reveal" data-reveal="up">
    SUPPORTING LOCAL ARTISANS
  </p>

  <h2 class="reveal" data-reveal="up">
    Helping Handicraft Businesses Grow
  </h2>

  <a class="story-scroll-cue reveal"
     href="#about"
     data-reveal="up"
     aria-label="Learn About LokalCraft">
    Learn About LokalCraft
    <svg viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round"
         aria-hidden="true">
      <path d="M12 4v15m-6-6 6 6 6-6"/>
    </svg>
  </a>
</section>
    <section class="about-section" id="about" aria-label="About LokalCraft">
      <div class="about-page">

      <section class="page-heading reveal" data-reveal="up">
        <h2>About <span>LokalCraft</span></h2>
        <p>Empowering Taguig's handicraft artisans through technology and community</p>
      </section>

      <section class="about-block" id="mission" aria-label="Mission and Vision">
        <div class="mission-grid">
          <article class="content-card reveal" data-reveal="up">
            <div class="card-heading">
              <div class="icon-circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/></svg></div>
              <h2>Mission</h2>
            </div>
            <p>Our mission is to provide a simple and easy-to-use platform for small handicraft businesses in Taguig. We help local artisans manage their businesses, set fair product prices, and connect with customers looking for quality handmade products.</p>
          </article>
          <article class="content-card reveal" data-reveal="up">
            <div class="card-heading">
              <div class="icon-circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20.8 8.8c0 4.3-8.8 10.5-8.8 10.5S3.2 13.1 3.2 8.8A4.8 4.8 0 0 1 12 6.2a4.8 4.8 0 0 1 8.8 2.6Z"/></svg></div>
              <h2>Vision</h2>
            </div>
            <p>Our vision is to help artisans in Taguig grow their businesses and earn a steady income from their crafts. We want LokalCraft to be a trusted place where Filipino traditions and modern technology bring artisans and buyers together.</p>
          </article>
        </div>
      </section>
      <section class="about-block" id="platform">
        <article class="platform-card reveal" data-reveal="up">
          <h2>About the Platform</h2>
          <div class="platform-copy">
            <p>LokalCraft is an online marketplace for handicraft sellers and buyers in Taguig City. It was created to support small artisans who need help with product pricing, promotion, and selling online.</p>
            <p>Setting the right price can be difficult for handmade products. Sellers need to consider materials, labor, and other costs. LokalCraft has a <strong>Costing and Pricing Calculator</strong> that helps them understand their costs and choose reasonable selling prices.</p>
            <p>With LokalCraft, artisans can focus on making their products while using the platform to manage prices, stocks, orders, and customer messages.</p>
          </div>
        </article>
      </section>
      <section class="about-block" id="help">
        <h2 class="help-title reveal">How We Help Handicraft Businesses</h2>
        <div class="help-grid">
          <article class="help-card reveal" data-reveal="up">
            <div class="help-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="5" y="2.5" width="14" height="19" rx="2"/><path d="M8 7h8M8 11h1m3 0h1m3 0h.01M8 15h1m3 0h1m3 0h.01M8 19h1m3 0h1m3 0h.01"/></svg></div>
            <h3>Pricing Calculator</h3>
            <p>Helps artisans set product prices based on materials, labor, and other costs.</p>
          </article>
          <article class="help-card reveal" data-reveal="up">
            <div class="help-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m3 9 2-6h14l2 6v2a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0V9Z"/><path d="M5 14v7h14v-7M9 21v-6h6v6"/></svg></div>
            <h3>Online Marketplace</h3>
            <p>Gives local artisans a place to show their products and reach more buyers in Taguig and nearby areas.</p>
          </article>
          <article class="help-card reveal" data-reveal="up">
            <div class="help-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m3 17 6-6 4 4 8-8M15 7h6v6"/></svg></div>
            <h3>Business Growth</h3>
            <p>Helps sellers keep their products and orders organized as their business grows.</p>
          </article>
          <article class="help-card reveal" data-reveal="up">
            <div class="help-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 11.5a8 8 0 0 1-8 8 8.5 8.5 0 0 1-4-.9L3 20l1.4-4.8A8 8 0 1 1 20 11.5Z"/><path d="M8 11h8M8 14h5"/></svg></div>
            <h3>Direct Connection</h3>
            <p>Connects buyers and sellers so they can talk directly about products and orders.</p>
          </article>
        </div>
      </section>
      <section class="about-block" id="values">
        <h2 class="values-title reveal">Our Core Values</h2>
        <div class="values-grid">
          <article class="value-card reveal" data-reveal="up">
            <div class="value-number">1</div>
            <h3>Community First</h3>
            <p>We support local communities and help artisans build businesses that can grow over time.</p>
          </article>
          <article class="value-card reveal" data-reveal="up">
            <div class="value-number">2</div>
            <h3>Transparency</h3>
            <p>We believe in clear pricing and showing buyers and sellers the fees that apply.</p>
          </article>
          <article class="value-card reveal" data-reveal="up">
            <div class="value-number">3</div>
            <h3>Empowerment</h3>
            <p>We give artisans tools and information to manage their businesses with confidence.</p>
          </article>
        </div>
      </section>
      </div>
    </section>
    <section class="browse-section" id="browse-products" aria-labelledby="browseTitle">
      <div class="browse-heading reveal" data-reveal="up">
        <h2 id="browseTitle">Browse Products</h2>
        <p>Explore handmade products from local artisans. Swipe or scroll sideways to see more.</p>
      </div>
      <div class="products-track reveal" data-reveal="up" tabindex="0" role="region" aria-label="Featured handicraft products, scroll horizontally">
        <?php if (empty($featuredProducts)): ?>
          <div class="browse-empty">
            <h3>No products available yet.</h3>
            <p>New handcrafted products will appear here when sellers add them.</p>
          </div>
        <?php else: ?>
          <?php foreach ($featuredProducts as $product): ?>
            <?php
              $previewName = trim((string)($product['product_name'] ?? '')) ?: 'Handcrafted Product';
              $previewCategory = trim((string)($product['craft_category'] ?? '')) ?: 'Handicrafts';
              $previewSeller = trim((string)($product['businessname'] ?? ''));
              if ($previewSeller === '') {
                  $previewSeller = trim((string)($product['firstname'] ?? '') . ' ' . (string)($product['lastname'] ?? ''));
              }
              if ($previewSeller === '') $previewSeller = 'Local Seller';
              $previewLocation = implode(', ', array_filter([
                  trim((string)($product['barangay'] ?? '')),
                  trim((string)($product['city'] ?? ''))
              ]));
              $previewDescription = trim((string)($product['description'] ?? '')) ?: 'Handcrafted by a local Filipino artisan.';
              $previewPhoto = trim((string)($product['product_photo'] ?? ''));
              $previewStock = (int)($product['stock'] ?? 0);
              $previewStatus = strtolower(trim((string)($product['status'] ?? 'available')));
              $previewSoldOut = $previewStock <= 0 || $previewStatus === 'out of stock';
            ?>
            <article class="preview-card">
              <div class="preview-image">
                <?php if ($previewPhoto !== ''): ?>
                  <img src="<?php echo htmlspecialchars($previewPhoto, ENT_QUOTES, 'UTF-8'); ?>"
                       alt="<?php echo htmlspecialchars($previewName, ENT_QUOTES, 'UTF-8'); ?>"
                       loading="lazy"
                       onerror="this.style.display='none'; this.nextElementSibling.style.display='grid';">
                <?php endif; ?>
                <div class="preview-placeholder" style="<?php echo $previewPhoto !== '' ? 'display:none;' : 'display:grid;'; ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m5 10 4-7m10 7-4-7M3 10h18l-2 11H5L3 10ZM9 14v4m6-4v4"/></svg></div>
                <span class="preview-category"><?php echo htmlspecialchars($previewCategory, ENT_QUOTES, 'UTF-8'); ?></span>
              </div>
              <div class="preview-body">
                <h3 class="preview-name"><?php echo htmlspecialchars($previewName, ENT_QUOTES, 'UTF-8'); ?></h3>
                <p class="preview-seller">by <?php echo htmlspecialchars($previewSeller, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php if ($previewLocation !== ''): ?>
                  <p class="preview-location"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg><span><?php echo htmlspecialchars($previewLocation, ENT_QUOTES, 'UTF-8'); ?></span></p>
                <?php endif; ?>
                <p class="preview-description"><?php echo htmlspecialchars($previewDescription, ENT_QUOTES, 'UTF-8'); ?></p>
                <div class="preview-bottom">
                  <span class="preview-price">₱<?php echo number_format((float)($product['cost'] ?? 0), 2); ?></span>
                  <span class="preview-stock"><?php echo $previewSoldOut ? 'Out of Stock' : $previewStock . ' available'; ?></span>
                </div>
                <?php if ($previewSoldOut): ?>
                  <button class="preview-button" type="button" disabled>Out of Stock</button>
                <?php else: ?>
                  <a href="login.php" class="preview-button">Login to Purchase</a>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>
    <section class="cta-section reveal" data-reveal="up">
      <h2>Ready to Grow Your Handicraft Business?</h2>
      <p>Join the LokalCraft community and share your handmade products with more people.</p>
      <div class="cta-actions">
        <a href="become_a_seller.php" class="cta-button">Get Started Today</a>
        <a href="#home" class="cta-button secondary">Back Home</a>
      </div>
    </section>

    <footer class="footer-note"><span class="footer-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 22V6M12 19C8 18 6 15 6 12c4 0 6 2 6 5M12 15c4-1 6-4 6-7-4 0-6 2-6 5M12 10C9 9 7 6 7 3c3 0 5 2 5 5M12 6c1-2 2-3 4-4 0 3-1 5-4 6"/></svg></span> LokalCraft — Preserving Filipino heritage, one craft at a time</footer>
</main>

<script>
(function () {
  'use strict';
  const home = document.getElementById('home');
  const about = document.getElementById('about');
  const browse = document.getElementById('browse-products');
  if (!home || !about || !browse) return;
  const reduceMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
  const progress = document.getElementById('scrollProgress');
  const header = document.querySelector('.public-header');
  const heroRight = document.querySelector('.hero-right');
  const nav = document.getElementById('publicNav');
  const menuToggle = document.getElementById('publicMenuToggle');
  const homeLink = nav ? nav.querySelector('a[href="index.php"]') : null;
  const aboutLink = nav ? nav.querySelector('a[href="about_us.php"]') : null;
  const logo = document.querySelector('a.public-logo');
  let observer = null;
  let scrollQueued = false;
  function closeMenu() {
    if (!nav || !menuToggle) return;
    nav.classList.remove('open');
    menuToggle.classList.remove('active');
    menuToggle.setAttribute('aria-expanded', 'false');
    menuToggle.setAttribute('aria-label', 'Open menu');
  }
  function goTo(section) {
    closeMenu();
    const target = section === 'about' ? about : section === 'browse-products' ? browse : home;
    const desired = '#' + section;
    if (window.history && window.history.replaceState && window.location.hash !== desired) {
      window.history.replaceState(null, '', desired);
    }
    target.scrollIntoView({behavior: reduceMotionQuery.matches ? 'auto' : 'smooth', block: 'start'});
  }
  [[homeLink, 'home'], [aboutLink, 'about'], [logo, 'home']].forEach(function(pair) {
    const link = pair[0];
    if (!link) return;
    link.setAttribute('href', '#' + pair[1]);
    link.addEventListener('click', function(event) {
      if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      goTo(pair[1]);
    });
  });
  document.querySelectorAll('a[href="#home"], a[href="#about"], a[href="#browse-products"]').forEach(function(link) {
    if (link === homeLink || link === aboutLink || link === logo) return;
    link.addEventListener('click', function(event) {
      if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      const section = link.getAttribute('href').slice(1);
      goTo(section);
    });
  });
  function setNav(section) {
    [[homeLink, 'home'], [aboutLink, 'about']].forEach(function(pair) {
      if (!pair[0]) return;
      const selected = section === pair[1];
      pair[0].classList.toggle('active', selected);
      if (selected) pair[0].setAttribute('aria-current', 'page');
      else pair[0].removeAttribute('aria-current');
    });
  }
  function updateScroll() {
    scrollQueued = false;
    const scrollY = window.scrollY || window.pageYOffset || 0;
    const available = Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
    if (progress) progress.style.transform = 'scaleX(' + Math.min(1, scrollY / available) + ')';
    const threshold = header ? header.getBoundingClientRect().height + 130 : 200;
    setNav(about.getBoundingClientRect().top <= threshold ? 'about' : 'home');
    if (heroRight && !reduceMotionQuery.matches && window.innerWidth > 780) {
      const next = Math.min(32, Math.max(-14, scrollY * .08));
      heroRight.style.setProperty('--photo-shift', next.toFixed(1) + 'px');
    }
  }
  function queueScroll() {
    if (scrollQueued) return;
    scrollQueued = true;
    window.requestAnimationFrame(updateScroll);
  }
  window.addEventListener('scroll', queueScroll, {passive: true});
  window.addEventListener('resize', queueScroll, {passive: true});
  function revealStagger() {
    document.querySelectorAll('.features-grid, .mission-grid, .help-grid, .values-grid').forEach(function(grid) {
      Array.from(grid.children).forEach(function(el, i) {
        if (el.classList.contains('reveal')) el.style.setProperty('--reveal-delay', Math.min(i, 5) * 125 + 'ms');
      });
    });
  }
  function showAll() {
    document.documentElement.classList.remove('motion-ready');
    document.querySelectorAll('.reveal').forEach(function(el) { el.classList.add('is-visible'); });
    if (observer) { observer.disconnect(); observer = null; }
  }
  function enableReveals() {
    if (reduceMotionQuery.matches || !('IntersectionObserver' in window)) { showAll(); return; }
    document.documentElement.classList.add('motion-ready');
    observer = new IntersectionObserver(function(entries) {
      entries.forEach(function(entry) {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      });
    }, {root: null, rootMargin: '0px 0px -8% 0px', threshold: .07});
    document.querySelectorAll('.reveal').forEach(function(el) { observer.observe(el); });
  }
  revealStagger();
  enableReveals();
  if (reduceMotionQuery.addEventListener) reduceMotionQuery.addEventListener('change', function() {
    if (reduceMotionQuery.matches) showAll();
  });
  queueScroll();
  // Direct visits from About Us and reloaded #about links arrive at the right section.
  if (window.location.hash === '#about' || window.location.hash === '#browse-products') {
    const target = window.location.hash === '#about' ? about : browse;
    window.requestAnimationFrame(function() { target.scrollIntoView({behavior: 'auto', block: 'start'}); queueScroll(); });
  }
})();
</script>
</body>
</html>
