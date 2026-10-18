<?php
require_once __DIR__ . '/config/database.php';
$products = [];
if (isset($conn) && $conn instanceof mysqli) {
    try {
        $sql = "
            SELECT
                p.product_id,
                p.product_photo,
                p.product_name,
                p.craft_category,
                p.description,
                p.cost,
                p.stock,
                p.status,
                p.created_at,
                u.firstname,
                u.lastname,
                b.businessname,
                l.barangay,
                l.city
            FROM products AS p
            INNER JOIN users AS u ON u.id = p.seller_id
            LEFT JOIN businessinfo AS b ON b.user_id = p.seller_id
            LEFT JOIN locationinfo AS l ON l.user_id = p.seller_id
            WHERE LOWER(p.status) IN ('available', 'out of stock')
              AND u.sellerOrNot = 1
              AND u.account_status = 'active'
              AND EXISTS (
                  SELECT 1
                  FROM seller_applications AS sa
                  WHERE sa.user_id = p.seller_id
                    AND sa.status = 'approved'
              )
            ORDER BY RAND()
        ";
        $result = $conn->query($sql);
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $products[] = $row;
            }
            $result->free();
        } else {
            error_log('LokalCraft browse products query failed.');
        }
    } catch (Throwable $e) {
        error_log('LokalCraft browse products query failed: ' . $e->getMessage());
    }
}
$productCount = count($products);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>LokalCraft — Browse Products</title>
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
      background: linear-gradient(135deg, #A55325, #C2692E);
      background-clip: text;
      -webkit-background-clip: text;
      color: transparent;
    }
    .nav-right {
      display: flex;
      align-items: center;
      gap: 24px;
    }
    .nav-link,
    .nav-button {
      border: none;
      background: none;
      color: #3F2E1F;
      text-decoration: none;
      font: inherit;
      font-size: 0.98rem;
      font-weight: 600;
      cursor: pointer;
    }
    .nav-link:hover,
    .nav-button:hover {
      color: #B45824;
    }
    .page {
      padding: 52px 0 70px;
    }
    .page-heading {
      text-align: center;
      max-width: 760px;
      margin: 0 auto 30px;
    }
    .page-heading h1 {
      font-size: clamp(2.2rem, 4vw, 3.5rem);
      line-height: 1.15;
      font-weight: 800;
      margin-bottom: 14px;
    }
    .page-heading h1 span {
      background: linear-gradient(120deg, #F3E1CE 0%, #F3E1CE 40%, rgba(243,225,206,0.4) 70%);
      padding: 0 8px;
      border-radius: 10px;
    }
    .page-heading p {
      color: #5A3F28;
      font-size: 1.05rem;
      line-height: 1.55;
    }
    .product-count {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-top: 14px;
      padding: 8px 14px;
      border: 1px solid #E7CDB0;
      border-radius: 999px;
      background: rgba(255, 252, 245, 0.90);
      color: #8B5C39;
      font-size: 0.88rem;
      font-weight: 700;
    }
    .products-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 22px;
    }
    .product-card {
      background: rgba(255, 252, 245, 0.92);
      border: 1px solid rgba(220, 180, 135, 0.60);
      border-radius: 24px;
      overflow: hidden;
      box-shadow: 0 16px 28px rgba(80, 50, 25, 0.08);
      transition: transform 0.2s ease, box-shadow 0.2s ease;
      display: flex;
      flex-direction: column;
    }
    .product-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 20px 32px rgba(80, 50, 25, 0.12);
    }
    .product-image {
      height: 220px;
      position: relative;
      background: #E8D5C0;
      overflow: hidden;
    }
    .product-image img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
    .product-placeholder {
      width: 100%;
      height: 100%;
      display: grid;
      place-items: center;
      font-size: 3.4rem;
      color: #A06435;
    }
    .category-badge {
      position: absolute;
      top: 14px;
      left: 14px;
      background: rgba(255, 252, 245, 0.96);
      border: 1px solid #E7CDB0;
      color: #A55325;
      border-radius: 999px;
      padding: 6px 10px;
      font-size: 0.75rem;
      font-weight: 700;
    }
    .product-body {
      padding: 18px;
      display: flex;
      flex-direction: column;
      flex: 1;
    }
    .product-name {
      font-size: 1.08rem;
      font-weight: 800;
      margin-bottom: 5px;
    }
    .seller-name {
      color: #8B5C39;
      font-size: 0.84rem;
      margin-bottom: 7px;
    }
    .location {
      color: #6F553D;
      font-size: 0.78rem;
      margin-bottom: 11px;
    }
    .description {
      color: #62503E;
      font-size: 0.84rem;
      line-height: 1.45;
      margin-bottom: 16px;
      min-height: 48px;
    }
    .bottom-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      margin-top: auto;
      margin-bottom: 14px;
    }
    .price {
      color: #B85C28;
      font-size: 1.18rem;
      font-weight: 800;
    }
    .stock {
      color: #52724C;
      background: #EDF5E9;
      border-radius: 999px;
      padding: 6px 9px;
      font-size: 0.72rem;
      font-weight: 700;
      white-space: nowrap;
    }
    .purchase-btn {
      width: 100%;
      border: none;
      border-radius: 14px;
      background: #B85C28;
      color: #fff;
      padding: 11px 14px;
      font: inherit;
      font-size: 0.88rem;
      font-weight: 700;
      cursor: pointer;
    }
    .purchase-btn:hover {
      background: #94481F;
    }
    .empty-state {
      grid-column: 1 / -1;
      padding: 56px 20px;
      text-align: center;
      background: rgba(255, 252, 245, 0.86);
      border: 1px solid rgba(220, 180, 135, 0.60);
      border-radius: 24px;
      color: #6A5038;
    }
    .empty-state h2 {
      color: #2B1E12;
      margin-bottom: 8px;
    }
    .empty-state p {
      line-height: 1.5;
    }
    .footer {
      text-align: center;
      padding: 10px 0 30px;
      color: #7B5B3E;
      font-size: 0.8rem;
    }
    @media (max-width: 1100px) {
      .products-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }
    }
    @media (max-width: 820px) {
      body {
        background-attachment: scroll;
        background-position: center center;
      }
      .container {
        width: min(100% - 34px, 680px);
      }
      .navbar {
        padding: 16px 0;
      }
      .products-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }
    @media (max-width: 560px) {
      .container {
        width: calc(100% - 20px);
        padding: 0;
      }
      .nav-right {
        gap: 12px;
      }
      .nav-link,
      .nav-button {
        font-size: 0.85rem;
      }
      .page {
        padding-top: 34px;
      }
      .products-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
      }
      .product-card {
        border-radius: 18px;
      }
      .product-image {
        height: 150px;
      }
      .category-badge {
        top: 8px;
        left: 8px;
        max-width: calc(100% - 16px);
        padding: 4px 7px;
        font-size: 0.64rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
      .product-body {
        padding: 12px;
      }
      .product-name {
        font-size: 0.92rem;
        line-height: 1.25;
      }
      .seller-name {
        font-size: 0.74rem;
      }
      .location {
        font-size: 0.68rem;
        margin-bottom: 8px;
      }
      .description {
        font-size: 0.74rem;
        line-height: 1.35;
        min-height: 0;
        margin-bottom: 10px;
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
      }
      .bottom-row {
        align-items: flex-start;
        flex-direction: column;
        gap: 6px;
        margin-bottom: 10px;
      }
      .price {
        font-size: 1rem;
      }
      .stock {
        padding: 4px 7px;
        font-size: 0.64rem;
      }
      .purchase-btn {
        padding: 9px 8px;
        border-radius: 11px;
        font-size: 0.72rem;
      }
    }
  </style>
</head>
<body>
  <div class="container">
<?php $publicActive = 'browse'; require __DIR__ . '/public_nav.php'; ?>
    <main class="page">
      <section class="page-heading">
          <h1>Browse <span>Filipino Handicrafts</span></h1>
          <p>Take a look around and discover handmade products from different local artisans. Product order changes on each visit so every seller's listings get a chance to be seen.</p>
          <div class="product-count">
            <?php echo $productCount; ?> <?php echo $productCount === 1 ? 'product' : 'products'; ?> to explore
          </div>
        </section>
        <section class="products-grid" id="productsGrid">
        <?php if (empty($products)): ?>
          <div class="empty-state">
            <h2>No products available yet.</h2>
            <p>New handcrafted products will appear here once approved sellers add them.</p>
          </div>
        <?php else: ?>
          <?php foreach ($products as $product): ?>
            <?php
              $productName = trim((string)($product['product_name'] ?? '')) ?: 'Handcrafted Product';
              $category = trim((string)($product['craft_category'] ?? '')) ?: 'Handicrafts';
              $seller = trim((string)($product['businessname'] ?? ''));
              if ($seller === '') {
                  $seller = trim((string)($product['firstname'] ?? '') . ' ' . (string)($product['lastname'] ?? ''));
              }
              if ($seller === '') {
                  $seller = 'Local Seller';
              }
              $locationParts = array_filter([
                  trim((string)($product['barangay'] ?? '')),
                  trim((string)($product['city'] ?? ''))
              ]);
              $location = implode(', ', $locationParts);
              $description = trim((string)($product['description'] ?? ''));
              if ($description === '') {
                  $description = 'Handcrafted locally by a Filipino artisan.';
              }
              $photo = trim((string)($product['product_photo'] ?? ''));
?>
            <article class="product-card">
              <div class="product-image">
                <?php if ($photo !== ''): ?>
                  <img src="<?php echo htmlspecialchars($photo, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='grid';">
                <?php endif; ?>
                <div class="product-placeholder" style="<?php echo $photo !== '' ? 'display:none;' : 'display:grid;'; ?>">🧺</div>
                <span class="category-badge"><?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?></span>
              </div>
              <div class="product-body">
                <h2 class="product-name"><?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?></h2>
                <div class="seller-name">by <?php echo htmlspecialchars($seller, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php if ($location !== ''): ?>
                  <div class="location">📍 <?php echo htmlspecialchars($location, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <div class="description"><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="bottom-row">
                  <span class="price">₱<?php echo number_format((float)$product['cost'], 2); ?></span>
                  <?php
                      $stock = (int)($product['stock'] ?? 0);
                      $status = strtolower(trim((string)($product['status'] ?? 'available')));
                      $isOutOfStock = $stock <= 0 || $status === 'out of stock';
                    ?>
                    <span class="stock">
                      <?php echo $isOutOfStock ? 'Out of stock' : $stock . ' available'; ?>
                    </span>
                </div>
                <?php if ($isOutOfStock): ?>
                  <button class="purchase-btn" type="button" disabled style="opacity:.55;cursor:not-allowed;">Out of Stock</button>
                <?php else: ?>
                  <button class="purchase-btn" type="button" onclick="window.location.href='login.php';">Login to Purchase</button>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
<?php endif; ?>
      </section>
    </main>
    <footer class="footer">
      🌾 LokalCraft — Preserving Filipino heritage, one craft at a time
    </footer>
  </div>
</body>
</html>
