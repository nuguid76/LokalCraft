<?php
require_once __DIR__ . '/seller_auth.php';
$activePage = 'pricing';
const MAX_COSTING_IMAGE_BYTES = 5242880;
if (empty($_SESSION['costing_csrf'])) {
    $_SESSION['costing_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['costing_csrf'];
function jsonResponse(bool $success, string $message, array $extra = []): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra));
    exit;
}
function hasValidCostingCsrf(): bool
{
    $sessionToken = (string)($_SESSION['costing_csrf'] ?? '');
    $requestToken = (string)($_POST['csrf_token'] ?? '');
    return $sessionToken !== ''
        && $requestToken !== ''
        && hash_equals($sessionToken, $requestToken);
}
function loadCraftCategories(mysqli $conn): array
{
    $categories = [];
    $result = $conn->query(
        'SELECT category_name
         FROM craft_categories
         WHERE TRIM(category_name) <> ""
         ORDER BY category_name ASC, category_id ASC'
    );
    if (!$result) {
        throw new RuntimeException('Unable to load craft categories.');
    }
    while ($row = $result->fetch_assoc()) {
        $name = trim((string)($row['category_name'] ?? ''));
        if ($name !== '') {
            $categories[] = $name;
        }
    }
    $result->free();
    return array_values(array_unique($categories));
}
function saveCostingImage(array $file, int $sellerId): string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Please upload a clear product image for market comparison.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The product image could not be uploaded. Please try again.');
    }
    if (($file['size'] ?? 0) > MAX_COSTING_IMAGE_BYTES) {
        throw new RuntimeException('Product image must be 5 MB or smaller.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Product image must be JPG, PNG, or WEBP.');
    }
    $uploadDir = __DIR__ . '/../uploads/costing';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Unable to create the costing image folder.');
    }
    $filename = sprintf(
        'seller_%d_costing_%s_%s.%s',
        $sellerId,
        date('Ymd_His'),
        bin2hex(random_bytes(5)),
        $allowed[$mime]
    );
    $destination = $uploadDir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Unable to save the product image.');
    }
    return 'uploads/costing/' . $filename;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_costing') {
    $savedImagePath = '';
    try {
        $sellerId = (int)$sellerId;
        if (!hasValidCostingCsrf()) {
            jsonResponse(false, 'Your session token expired. Please refresh the page and try again.');
        }
        $productTitle = trim((string)($_POST['product_title'] ?? ''));
        $craftCategory = trim((string)($_POST['craft_category'] ?? ''));
        $allowedCategories = loadCraftCategories($conn);
        if ($productTitle === '') {
            jsonResponse(false, 'Please enter the product name.');
        }
        if (!$allowedCategories) {
            jsonResponse(false, 'No craft categories are available right now. Please contact the administrator.');
        }
        if (!in_array($craftCategory, $allowedCategories, true)) {
            jsonResponse(false, 'Please select a valid craft category.');
        }
        $laborCost = round(max(0, (float)($_POST['labor_cost'] ?? 0)), 2);
        $packagingCost = round(max(0, (float)($_POST['packaging_cost'] ?? 0)), 2);
        $profitMargin = max(0, min(89.99, (float)($_POST['profit_margin'] ?? 0)));
        $materialIds = $_POST['saved_material_id'] ?? [];
        $materialQuantities = $_POST['material_quantity'] ?? [];
        $overheadNames = $_POST['overhead_name'] ?? [];
        $overheadCosts = $_POST['overhead_cost'] ?? [];
        if (!is_array($materialIds) || !is_array($materialQuantities)) {
            jsonResponse(false, 'Invalid material data.');
        }
        if (!is_array($overheadNames) || !is_array($overheadCosts)) {
            jsonResponse(false, 'Invalid indirect expense data.');
        }
        $materials = [];
        $totalMaterialCost = 0.0;
        $materialLookup = $conn->prepare(
            'SELECT material_id, material_name, reference_quantity, unit, reference_price
             FROM saved_materials
             WHERE material_id = ? AND seller_id = ?
             LIMIT 1'
        );
        $materialCount = max(count($materialIds), count($materialQuantities));
        for ($i = 0; $i < $materialCount; $i++) {
            $materialId = (int)($materialIds[$i] ?? 0);
            $quantityUsed = max(0, (float)($materialQuantities[$i] ?? 0));
            if ($materialId <= 0 && $quantityUsed <= 0) {
                continue;
            }
            if ($materialId <= 0) {
                $materialLookup->close();
                jsonResponse(false, 'Please select a material from your Materials Library.');
            }
            if ($quantityUsed <= 0) {
                $materialLookup->close();
                jsonResponse(false, 'Quantity used must be greater than 0 for every selected material.');
            }
            $materialLookup->bind_param('ii', $materialId, $sellerId);
            $materialLookup->execute();
            $material = $materialLookup->get_result()->fetch_assoc();
            if (!$material) {
                $materialLookup->close();
                jsonResponse(false, 'One of the selected materials was not found in your Materials Library.');
            }
            $referenceQuantity = (float)$material['reference_quantity'];
            $referencePrice = (float)$material['reference_price'];
            if ($referenceQuantity <= 0) {
                $materialLookup->close();
                jsonResponse(false, 'A saved material has an invalid reference quantity. Please update it in Materials Library.');
            }
            $unitCost = $referencePrice / $referenceQuantity;
            $materialTotal = round($quantityUsed * $unitCost, 2);
            $totalMaterialCost += $materialTotal;
            $materials[] = [
                'saved_material_id' => (int)$material['material_id'],
                'material_name' => (string)$material['material_name'],
                'quantity' => $quantityUsed,
                'unit' => (string)$material['unit'],
                'unit_cost' => $unitCost,
                'total_cost' => $materialTotal,
            ];
        }
        $materialLookup->close();
        if (!$materials) {
            jsonResponse(false, 'Please select at least one material from your Materials Library.');
        }
        // "Other Direct Expenses" was removed from the simplified costing flow.
        // Keep the legacy database column compatible by storing zero.
        $otherDirectCost = 0.0;
        $overheads = [];
        $overheadCost = 0.0;
        $overheadCount = max(count($overheadNames), count($overheadCosts));
        for ($i = 0; $i < $overheadCount; $i++) {
            $name = trim((string)($overheadNames[$i] ?? ''));
            $cost = round(max(0, (float)($overheadCosts[$i] ?? 0)), 2);
            if ($name === '' && $cost <= 0) {
                continue;
            }
            if ($name === '') {
                jsonResponse(false, 'Every indirect expense must have a name.');
            }
            $overheads[] = ['name' => $name, 'cost' => $cost];
            $overheadCost += $cost;
        }
        $totalCost = round($totalMaterialCost + $laborCost + $packagingCost + $otherDirectCost + $overheadCost, 2);
        // Margin is a percentage of selling price, excluding any order-level fees.
        // Selling price = total cost / (1 - target margin).
        $marginDecimal = $profitMargin / 100;
        $pricingDenominator = 1 - $marginDecimal;
        $suggestedPrice = $totalCost > 0
            ? round($totalCost / $pricingDenominator, 2)
            : 0.0;
        $desiredProfit = round(max(0, $suggestedPrice - $totalCost), 2);
        $savedImagePath = saveCostingImage($_FILES['product_image'] ?? [], $sellerId);
        $conn->begin_transaction();
        $costingStmt = $conn->prepare(
            'INSERT INTO products_costing
            (seller_id, product_title, craft_category, product_image, labor_cost, packaging_cost, other_direct_cost, overhead_cost, desired_profit, total_material_cost, total_cost, suggested_price, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $costingStmt->bind_param(
            'isssdddddddd',
            $sellerId,
            $productTitle,
            $craftCategory,
            $savedImagePath,
            $laborCost,
            $packagingCost,
            $otherDirectCost,
            $overheadCost,
            $desiredProfit,
            $totalMaterialCost,
            $totalCost,
            $suggestedPrice
        );
        $costingStmt->execute();
        $costingId = (int)$conn->insert_id;
        $costingStmt->close();
        $materialStmt = $conn->prepare(
            'INSERT INTO costing_materials
            (costing_id, saved_material_id, material_name, quantity, unit, unit_cost, total_cost)
            VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($materials as $material) {
            $savedMaterialId = $material['saved_material_id'];
            $materialName = $material['material_name'];
            $quantity = $material['quantity'];
            $unit = $material['unit'];
            $unitCost = $material['unit_cost'];
            $materialTotal = $material['total_cost'];
            $materialStmt->bind_param(
                'iisdsdd',
                $costingId,
                $savedMaterialId,
                $materialName,
                $quantity,
                $unit,
                $unitCost,
                $materialTotal
            );
            $materialStmt->execute();
        }
        $materialStmt->close();
        $expenseStmt = $conn->prepare(
            'INSERT INTO costing_overheads (costing_id, expense_type, overhead_name, cost)
             VALUES (?, ?, ?, ?)'
        );
        $expenseType = 'overhead';
        foreach ($overheads as $expense) {
            $name = $expense['name'];
            $cost = $expense['cost'];
            $expenseStmt->bind_param('issd', $costingId, $expenseType, $name, $cost);
            $expenseStmt->execute();
        }
        $expenseStmt->close();
        $marketPreview = $_SESSION['market_comparison_preview'] ?? null;
        if (is_array($marketPreview)) {
            $previewName = strtolower(trim((string)($marketPreview['product_name'] ?? '')));
            $previewCategory = strtolower(trim((string)($marketPreview['craft_category'] ?? '')));
            $previewItems = $marketPreview['items'] ?? [];
            if (
                $previewName === strtolower($productTitle)
                && $previewCategory === strtolower($craftCategory)
                && is_array($previewItems)
                && count($previewItems) > 0
            ) {
                try {
                    $comparisonStmt = $conn->prepare(
                        'INSERT INTO costing_market_comparison (costing_id, checked_at) VALUES (?, NOW())'
                    );
                    $comparisonStmt->bind_param('i', $costingId);
                    $comparisonStmt->execute();
                    $comparisonId = (int)$conn->insert_id;
                    $comparisonStmt->close();
                    $marketItemStmt = $conn->prepare(
                        'INSERT INTO costing_market_items
                         (comparison_id, source_name, product_name, price, image_url, product_url, rating, review_count)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    foreach (array_slice($previewItems, 0, 12) as $marketItem) {
                        if (!is_array($marketItem)) {
                            continue;
                        }
                        $sourceName = trim((string)($marketItem['source_name'] ?? ''));
                        $marketProductName = trim((string)($marketItem['product_name'] ?? ''));
                        $marketPrice = max(0, (float)($marketItem['price'] ?? 0));
                        $imageUrl = trim((string)($marketItem['image_url'] ?? ''));
                        $productUrl = trim((string)($marketItem['product_url'] ?? ''));
                        $rating = max(0, (float)($marketItem['rating'] ?? 0));
                        $reviewCount = max(0, (int)($marketItem['review_count'] ?? 0));
                        if ($sourceName === '' || $marketProductName === '' || $marketPrice <= 0) {
                            continue;
                        }
                        $marketItemStmt->bind_param(
                            'issdssdi',
                            $comparisonId,
                            $sourceName,
                            $marketProductName,
                            $marketPrice,
                            $imageUrl,
                            $productUrl,
                            $rating,
                            $reviewCount
                        );
                        $marketItemStmt->execute();
                    }
                    $marketItemStmt->close();
                } catch (Throwable $comparisonSaveError) {
                    // The costing itself should still be saved even if the optional market snapshot cannot be stored.
                }
            }
        }
        $conn->commit();
        jsonResponse(true, 'Calculation saved successfully.', [
            'costing_id' => $costingId,
            'product_title' => $productTitle,
            'product_image' => $savedImagePath,
            'total_material_cost' => round($totalMaterialCost, 2),
            'labor_cost' => round($laborCost, 2),
            'packaging_cost' => round($packagingCost, 2),
            'other_direct_cost' => round($otherDirectCost, 2),
            'overhead_cost' => round($overheadCost, 2),
            'total_cost' => round($totalCost, 2),
            'desired_profit' => round($desiredProfit, 2),
            'profit_margin' => round($profitMargin, 2),
            'suggested_price' => round($suggestedPrice, 2),
        ]);
    } catch (Throwable $e) {
        if (isset($conn) && $conn instanceof mysqli) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
            }
        }
        if ($savedImagePath !== '') {
            $fullPath = __DIR__ . '/../' . $savedImagePath;
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }
        if ($e instanceof RuntimeException) {
            jsonResponse(false, $e->getMessage());
        }
        error_log('LokalCraft costing save error: ' . $e->getMessage());
        jsonResponse(false, 'Unable to save the calculation right now. Please try again.');
    }
}
$savedMaterials = [];
$craftCategories = [];
$pageError = '';
try {
    $sellerId = (int)$sellerId;
    $craftCategories = loadCraftCategories($conn);
    if (!$craftCategories) {
        throw new RuntimeException('No craft categories are available right now.');
    }
    $materialStmt = $conn->prepare(
        'SELECT material_id, material_name, reference_quantity, unit, reference_price
         FROM saved_materials
         WHERE seller_id = ?
         ORDER BY material_name ASC, material_id DESC'
    );
    $materialStmt->bind_param('i', $sellerId);
    $materialStmt->execute();
    $result = $materialStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['material_id'] = (int)$row['material_id'];
        $row['reference_quantity'] = (float)$row['reference_quantity'];
        $row['reference_price'] = (float)$row['reference_price'];
        $savedMaterials[] = $row;
    }
    $materialStmt->close();
} catch (Throwable $e) {
    $pageError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>LokalCraft | Costing & Pricing</title>
    <style>
        :root {
            --bg: #FFF8ED;
            --panel: #ffffff;
            --line: #e7ded5;
            --text: #332c27;
            --muted: #7b746e;
            --soft: #fbf8f5;
            --orange: #ea8a3a;
            --orange-dark: #c96d20;
            --cta-gradient: linear-gradient(135deg, #ea8a3a 0%, #dc7c2e 55%, #c96d20 100%);
            --cta-hover: linear-gradient(135deg, #df7d2e 0%, #b85e1d 100%);
            --orange-soft: #fff1e3;
            --cream: #fffaf5;
            --brown: #5a4032;
            --brown-dark: #3f2c23;
            --green: #2f8f62;
            --green-soft: #edf8f1;
            --red: #c94f45;
            --red-soft: #fff1ef;
            --shadow: 0 8px 22px rgba(76, 50, 32, 0.06);
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Segoe UI", Arial, sans-serif; background: var(--bg); color: var(--text); }
        .page {
            display: block !important;
            min-height: 100vh;
            background: var(--bg);
        }
        /* Match Seller Dashboard: document scrolling, not a nested fixed scroll region. */
        body .page > main.content.pricing-main {
            position: relative !important;
            inset: auto !important;
            top: auto !important;
            right: auto !important;
            bottom: auto !important;
            left: auto !important;
            width: auto !important;
            max-width: none !important;
            min-width: 0 !important;
            height: auto !important;
            min-height: 100vh !important;
            margin: 0 0 0 250px !important;
            padding: var(--pricing-header-bottom, 70px) 0 30px !important;
            overflow: visible !important;
            background: transparent !important;
        }
        .calculator-wrap {
            width: 100%;
            max-width: 1600px;
            min-width: 0;
            margin: 0 auto;
            padding: 8px 26px 24px;
        }
        .seller-header-tools .filter-box input,
        .seller-header-tools .filter-box select {
            width: 100%;
            min-height: 0;
            height: auto;
            margin: 0;
            padding: 0;
            border: 0;
            border-radius: 0;
            background: transparent;
            box-shadow: none;
        }
        .heading-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
        h1 { margin: 0; font-size: 2.1rem; font-weight: 800; letter-spacing: -0.7px; }
        .subtitle { margin: 4px 0 0; color: var(--muted); font-size: 0.95rem; }
        .heading-actions { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
        .shortcut-button, .nav-button, .add-button, .remove-button {
            border: 1px solid var(--line); border-radius: 7px; background: #fff; color: #4f4f4f;
            padding: 9px 12px; font-size: 0.76rem; font-weight: 700; cursor: pointer;
        }
        .shortcut-button:hover, .nav-button:hover { background: #faf8f6; }
        .progress-card, .calculator-card { border: 1px solid var(--line); border-radius: 12px; box-shadow: var(--shadow); }
        .progress-card { padding: 14px 18px 16px; background: rgba(255,255,255,.88); }
        .calculator-card { margin-top: 22px; padding: 20px; background: rgba(255,255,255,.92); }
        .progress-top { display: flex; align-items: center; gap: 12px; font-size: 0.78rem; color: #4d4d4d; }
        .progress-track { flex: 1; max-width: 320px; height: 6px; border-radius: 999px; background: #d7d3cf; overflow: hidden; }
        .progress-fill { width: 25%; height: 100%; background: linear-gradient(90deg, var(--orange), #f2a55e); transition: width .2s ease; }
        .progress-percent { margin-left: auto; color: #737373; font-size: .72rem; }
        .step-status { margin-top: 16px; color: #656565; font-size: .76rem; }
        .step { display: none; }
        .step.active { display: block; }
        .step h2 { margin: 0 0 18px; font-size: 1.16rem; }
        .section-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 24px 0 10px; }
        .section-heading h3 { margin: 0; font-size: .88rem; }
        .label-with-tip { display: inline-flex; align-items: center; gap: 6px; }
        .info-tip-wrap { position: relative; display: inline-flex; }
        .info-tip {
            width: 19px; height: 19px; padding: 0; border-radius: 50%; border: 1px solid #bbb5af;
            background: #fff; color: #69645f; font: 700 11px/1 "Segoe UI", Arial, sans-serif; cursor: help;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .info-tip-wrap > .tooltip-box { display: none !important; }
        .floating-tooltip {
            position: fixed;
            z-index: 250000;
            display: none;
            width: max-content;
            max-width: min(280px, calc(100vw - 24px));
            padding: 10px 11px;
            border-radius: 8px;
            background: #242424;
            color: #fff;
            font-size: .72rem;
            line-height: 1.45;
            font-weight: 400;
            box-shadow: 0 8px 24px rgba(0,0,0,.18);
            pointer-events: none;
            overflow-wrap: break-word;
        }
        .floating-tooltip.visible { display: block; }
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 14px; }
        .product-grid { display: grid; grid-template-columns: minmax(220px,.75fr) 1fr 1fr; gap: 14px; align-items: stretch; }
        .calculator-wrap label { display: block; color: #4a4a4a; font-size: .75rem; font-weight: 700; }
        .calculator-wrap input,
        .calculator-wrap select { width: 100%; margin-top: 6px; padding: 10px; border: 1px solid transparent; border-radius: 7px; background: #f1f1f1; color: #343434; font: inherit; font-size: .82rem; outline: none; }
        .calculator-wrap input:focus,
        .calculator-wrap select:focus { border-color: #e9c480; background: #fff; box-shadow: 0 0 0 3px rgba(230,141,45,.07); }
        .image-upload { min-height: 190px; border: 1px dashed #d8c9b9; border-radius: 10px; background: #fcfaf8; padding: 12px; display: flex; flex-direction: column; gap: 10px; min-width: 0; }
        .image-preview { min-height: 120px; border-radius: 8px; background: #efedeb; overflow: hidden; display: grid; place-items: center; color: #8c8680; font-size: .76rem; text-align: center; padding: 12px; }
        .image-preview img { width: 100%; height: 170px; object-fit: cover; display: block; }
        /* Same choose-inside-box workflow as Seller Application. Desktop preview dimensions stay unchanged. */
        .image-upload [hidden] { display: none !important; }
        .image-upload #product-image { position: absolute !important; width: 1px !important; height: 1px !important; min-width: 0 !important; min-height: 0 !important; margin: 0 !important; padding: 0 !important; border: 0 !important; opacity: 0; clip-path: inset(50%); overflow: hidden; pointer-events: none; }
        .image-upload .product-image-chooser { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 5px; width: 100%; min-height: 170px; padding: 12px; border: 1px dashed #d8c9b9; border-radius: 10px; background: #fff; text-align: center; color: #9c6335; cursor: pointer; }
        .product-image-chooser svg { width: 25px; height: 25px; }
        .product-image-chooser strong { color: #5c5147; font-size: .77rem; font-weight: 750; }
        .product-image-chooser small { color: #95877b; font-size: .7rem; }
        .product-image-chooser:hover, .product-image-chooser:focus-visible { background: #fffaf6; border-color: #db995d; }
        .product-image-chooser:focus-visible, .product-image-selected:focus-visible, .product-image-tools button:focus-visible { outline: 3px solid rgba(233,139,64,.35); outline-offset: 2px; }
        .product-image-selected { position: relative; min-width: 0; cursor: pointer; }
        .product-image-selected #image-preview { display: grid; width: 100%; cursor: zoom-in; }
        .product-image-meta { position: absolute; bottom: 9px; left: 8px; right: 8px; min-width: 0; pointer-events: none; }
        .product-image-name, .product-image-size { display: none; }
        .product-image-tools { display: flex; gap: 6px; justify-content: center; pointer-events: auto; }
        .product-image-tools button { padding: 5px 8px; border: 1px solid #e5d9ce; border-radius: 7px; background: rgba(255,255,255,.97); color: #7d5740; font-family: inherit; font-size: .68rem; font-weight: 700; cursor: pointer; }
        .product-image-tools button:hover { background: #fff2e6; }
        .material-row {
            display: grid;
            grid-template-columns: minmax(240px,1.45fr) minmax(145px,.72fr) minmax(110px,.55fr) minmax(140px,.7fr) 40px;
            gap: 12px;
            align-items: start;
            margin-top: 10px;
            padding: 12px;
            border: 1px solid #e7e2dd;
            border-radius: 10px;
            background: #fbfaf9;
        }
        .material-row > label { min-width: 0; }
        .material-row input,
        .material-row select { min-height: 40px; height: 40px; }
        .material-meta {
            margin-top: 6px;
            min-height: 18px;
            color: #7b756f;
            font-size: .7rem;
            line-height: 1.35;
            overflow-wrap: anywhere;
        }
        .material-row .remove-button {
            width: 40px;
            height: 40px;
            margin-top: 24px;
            align-self: start;
        }
        .expense-row { display: grid; grid-template-columns: minmax(0,1fr) minmax(140px,.5fr) 38px; gap: 10px; align-items: end; margin-top: 10px; }
        .add-button { border: 0; background: var(--orange-dark); color: #fff; }
        .add-button:disabled { opacity: .45; cursor: not-allowed; }
        .remove-button { height: 39px; padding: 0; border-color: #f0c6c6; background: #fff7f7; color: #d95f5f; }
        .library-empty { padding: 14px; border: 1px solid #ecd5b8; background: #fff9f0; border-radius: 8px; color: #6e6255; font-size: .78rem; line-height: 1.5; }
        .library-empty strong, .library-empty span { display: block; }
        .library-empty strong { margin-bottom: 4px; color: #5a4032; }
        .library-empty button { display: inline-flex; margin: 9px 0 0; border: 0; background: transparent; color: #b86112; font: inherit; font-weight: 800; padding: 0; cursor: pointer; text-decoration: underline; }
        .material-actions { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
        .secondary-add { border: 1px solid #e2c49e; background: #fff9f0; color: #a85d19; }
.market-box { margin-top: 18px; border: 1px dashed #cfc7bf; border-radius: 10px; padding: 16px; background: #faf9f8; }
        .market-title { display: flex; align-items: center; gap: 7px; font-size: .88rem; font-weight: 800; }
        .market-state { margin-top: 9px; color: #77716c; font-size: .76rem; line-height: 1.5; }
        .market-state.loading { color: #8a5a1f; }
        .market-state.error { color: var(--red); }
        .market-overview { display: none; margin-top: 14px; }
        .market-overview.show { display: block; }
        .market-overview-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 8px; }
        .market-stat { padding: 12px; border: 1px solid #e4ded8; border-radius: 9px; background: #fff; }
        .market-stat-label { color: var(--muted); font-size: .69rem; margin-bottom: 5px; }
        .market-stat-value { font-size: .96rem; font-weight: 800; color: #333; }
        .market-context { min-height: 18px; margin-top: 7px; color: #918981; font-size: .64rem; line-height: 1.4; }
        .market-position { display: inline-flex; align-items: center; margin-right: 7px; padding: 3px 7px; border-radius: 999px; font-size: .61rem; font-weight: 800; }
        .market-position.below { background: #eef7ff; color: #346d9b; }
        .market-position.near { background: #f0faf3; color: #28784b; }
        .market-position.above { background: #fff5f2; color: #a24d3d; }
        .fighter-meta { display: block; margin-top: 4px; color: #8f877f; font-size: .58rem; line-height: 1.35; }
        .market-results { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 12px; margin-top: 14px; }
.navigation { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 24px; }
        .nav-button.next { background: var(--orange); border-color: var(--orange); color: #fff; }
        .nav-button:disabled { opacity: .45; cursor: not-allowed; }
        .save-status { margin-top: 12px; padding: 0; font-size: .76rem; display: none; }
        .save-status.visible { display: block; }
        .save-status.success { color: var(--green); }
        .save-status.error { color: var(--red); }
        .page-error { margin-bottom: 14px; padding: 10px 12px; border-radius: 8px; background: #fff3f3; border: 1px solid #efc5c5; color: #a23d3d; font-size: .78rem; }
        @media (max-width: 1050px) {
            .product-grid { grid-template-columns: 1fr 1fr; }
            .image-upload { grid-row: span 2; }
            .material-row { grid-template-columns: 1fr 1fr; }
            .material-row .remove-button { grid-column: 1 / -1; width: 100%; margin-top: 0; }
            .remove-button { width: 100%; }

            .market-results { grid-template-columns: repeat(2,minmax(0,1fr)); }
        }
        @media (max-width: 760px) {
            body .page > main.content.pricing-main {
                width: 100% !important;
                margin-left: 0 !important;
            }
            .calculator-wrap { padding: 8px 14px 24px; }
            .heading-row { flex-direction: column; }
            .heading-actions { justify-content: flex-start; }
            .progress-top { flex-wrap: wrap; }
            .progress-track { max-width: none; width: 100%; }
            .form-grid, .product-grid, .material-row, .expense-row, .navigation { grid-template-columns: 1fr; }
            .market-results { grid-template-columns: 1fr; }
            .image-upload { grid-row: auto; }
        }
        /* Guided craft-costing layout */
        .step-path {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 7px;
            margin-top: 14px;
        }
        .step-path-item {
            min-width: 0;
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 8px 9px;
            border: 1px solid #e5e0db;
            border-radius: 9px;
            background: #fbfaf9;
            color: #8a837d;
            font-size: .66rem;
            font-weight: 700;
        }
        .step-path-item b {
            width: 21px;
            height: 21px;
            display: grid;
            place-items: center;
            flex: 0 0 21px;
            border-radius: 50%;
            background: #ebe7e2;
            color: #77706a;
            font-size: .61rem;
        }
        .step-path-item.active {
            border-color: #edc996;
            background: #fff8ee;
            color: #8c531c;
        }
        .step-path-item.active b {
            background: var(--orange);
            color: #fff;
        }
        .step-kicker,
        .eyebrow {
            display: inline-block;
            margin-bottom: 7px;
            color: #b36a24;
            font-size: .62rem;
            font-weight: 800;
            letter-spacing: .07em;
        }
        .step-help {
            max-width: 760px;
            margin: -9px 0 20px;
            color: var(--muted);
            font-size: .78rem;
            line-height: 1.55;
        }
        .formula-note,
        .expense-example {
            margin-top: 16px;
            padding: 11px 13px;
            border: 1px solid #eadfd4;
            border-radius: 9px;
            background: #fffaf4;
            color: #716356;
            font-size: .72rem;
            line-height: 1.5;
        }
        .compact-heading {
            margin-top: 10px;
        }
.cost-input-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .cost-input-card {
            display: grid;
            grid-template-columns: 38px 1fr;
            gap: 11px 12px;
            padding: 16px;
            border: 1px solid #e5e0db;
            border-radius: 12px;
            background: #fbfaf9;
        }
        .cost-input-card label {
            grid-column: 1 / -1;
        }
        .cost-input-card h3 {
            margin: 0;
            font-size: .88rem;
        }
        .cost-input-card p {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: .69rem;
            line-height: 1.45;
        }
        .cost-input-icon {
            width: 38px;
            height: 38px;
            display: grid;
            place-items: center;
            border-radius: 10px;
            background: var(--orange-soft);
            color: var(--orange-dark);
            font-size: .67rem;
            font-weight: 900;
        }
.smart-market-box {
            position: relative;
            overflow: hidden;
            border: 1px solid #dfd9d3;
            background:
                radial-gradient(circle at top right, rgba(230,141,45,.12), transparent 34%),
                #faf9f8;
        }
        .smart-market-box::before {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(110deg, transparent 35%, rgba(255,255,255,.55) 50%, transparent 65%);
            transform: translateX(-120%);
        }
        .smart-market-box.analyzing::before {
            animation: marketScan 1.5s linear infinite;
        }
        @keyframes marketScan {
            to { transform: translateX(120%); }
        }
        .smart-market-head {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
        }
        .smart-market-brand {
            display: flex;
            align-items: center;
            gap: 11px;
        }
        .smart-market-brand p {
            margin: 4px 0 0;
            color: #756f69;
            font-size: .68rem;
            line-height: 1.45;
        }
        .smart-orb {
            width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 3px;
            flex: 0 0 42px;
            border-radius: 12px;
            background: #fff1e3;
            box-shadow: inset 0 0 0 1px #f0d1ad;
        }
        .smart-orb span {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: var(--orange);
            animation: smartPulse 1.1s ease-in-out infinite;
        }
        .smart-orb span:nth-child(2) { animation-delay: .14s; }
        .smart-orb span:nth-child(3) { animation-delay: .28s; }
        @keyframes smartPulse {
            0%, 100% { transform: translateY(0); opacity: .45; }
            50% { transform: translateY(-5px); opacity: 1; }
        }
        .smart-badge {
            display: inline-flex;
            margin-left: 5px;
            padding: 4px 7px;
            border-radius: 999px;
            background: #26231f;
            color: #fff;
            font-size: .56rem;
            font-weight: 800;
            vertical-align: middle;
        }
        .market-state {
            position: relative;
            z-index: 1;
        }
        .market-state.loading::before {
            content: "●";
            display: inline-block;
            margin-right: 7px;
            color: var(--orange);
            animation: stateBlink .85s ease-in-out infinite;
        }
        @keyframes stateBlink {
            50% { opacity: .25; }
        }
        .own-stat {
            border-color: #c9e7d2;
            background: #f4fbf6;
        }
        .matchup-label {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            gap: 10px;
            margin-top: 15px;
            color: #8a837d;
            font-size: .62rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .matchup-label span:last-child {
            text-align: right;
        }
        .matchup-label b {
            color: var(--orange-dark);
        }
        .matchup-results {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
        }
        .market-matchup {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 44px minmax(0, 1fr);
            gap: 10px;
            align-items: stretch;
            padding: 11px;
            border: 1px solid #e4ded8;
            border-radius: 12px;
            background: #fff;
            animation: matchupIn .28s ease both;
        }
        @keyframes matchupIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .fighter-card {
            min-width: 0;
            display: grid;
            grid-template-columns: 64px 1fr;
            gap: 10px;
            align-items: center;
            padding: 8px;
            border-radius: 9px;
            background: #faf9f7;
        }
        .fighter-card.own-fighter {
            border: 1px solid #edd1b1;
            background: #fff9f2;
        }
        .fighter-card.rival-fighter {
            border: 1px solid #e4e0dc;
        }
        .fighter-image {
            width: 64px;
            height: 64px;
            display: grid;
            place-items: center;
            overflow: hidden;
            border-radius: 8px;
            background: #ece8e4;
            color: #8b837c;
            font-size: .58rem;
            text-align: center;
        }
        .fighter-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .fighter-name {
            color: #35312d;
            font-size: .72rem;
            font-weight: 800;
            line-height: 1.35;
            overflow-wrap: anywhere;
        }
        .fighter-source {
            display: block;
            margin-top: 3px;
            color: #918981;
            font-size: .58rem;
        }
        .fighter-price {
            margin-top: 6px;
            color: var(--orange-dark);
            font-size: .88rem;
            font-weight: 900;
        }
        .vs-badge {
            align-self: center;
            justify-self: center;
            width: 38px;
            height: 38px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: #26231f;
            color: #fff;
            font-size: .62rem;
            font-weight: 900;
            box-shadow: 0 5px 12px rgba(0,0,0,.12);
        }
        .matchup-result {
            grid-column: 1 / -1;
            padding: 8px 10px;
            border-radius: 8px;
            background: #f6f4f2;
            color: #716a63;
            font-size: .64rem;
            line-height: 1.45;
        }
        .matchup-result.below {
            background: #eef7ff;
            color: #346d9b;
        }
        .matchup-result.near {
            background: #f0faf3;
            color: #28784b;
        }
        .matchup-result.above {
            background: #fff5f2;
            color: #a24d3d;
        }
        @media (max-width: 980px) {
            .step-path { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .cost-input-grid { grid-template-columns: 1fr; }
}
        @media (max-width: 700px) {
            .smart-market-head { flex-direction: column; }
            .market-matchup { grid-template-columns: 1fr; }
            .vs-badge { width: 34px; height: 34px; }
            .fighter-card { grid-template-columns: 56px 1fr; }
            .fighter-image { width: 56px; height: 56px; }
            .matchup-label { display: none; }
}
        /* Persistent Yarn & Worth-inspired costing layout */
        .calculator-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 350px;
            gap: 22px;
            align-items: start;
        }
        .calculator-workspace {
            min-width: 0;
        }
        .calculator-workspace .calculator-card {
            margin-top: 18px;
        }
        .cost-sidebar {
            position: sticky;
            top: calc(var(--pricing-header-bottom, 70px) + 12px);
            align-self: start;
        }
        .cost-sidebar-card {
            padding: 24px 22px;
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 18px;
            background:
                radial-gradient(circle at top right, rgba(234,138,58,.34), transparent 34%),
                linear-gradient(145deg, #5a4032 0%, #3f2c23 100%);
            color: #fff;
            box-shadow: 0 20px 42px rgba(67, 43, 29, .18);
        }
        .cost-sidebar-kicker {
            color: rgba(255,255,255,.72);
            font-size: .67rem;
            font-weight: 800;
            letter-spacing: .08em;
        }
        .cost-sidebar-title {
            margin-top: 9px;
            color: rgba(255,255,255,.78);
            font-size: .78rem;
            font-weight: 700;
        }
        .cost-sidebar-price {
            margin-top: 4px;
            font-size: clamp(2.3rem, 4vw, 3.7rem);
            font-weight: 800;
            letter-spacing: -1.6px;
        }
        .cost-sidebar-rule {
            height: 1px;
            margin: 20px 0;
            background: rgba(255,255,255,.2);
        }
        .cost-sidebar-section-label {
            margin: 15px 0 8px;
            color: rgba(255,255,255,.58);
            font-size: .61rem;
            font-weight: 800;
            letter-spacing: .07em;
            text-transform: uppercase;
        }
        .cost-sidebar-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 7px 0;
            color: rgba(255,255,255,.76);
            font-size: .74rem;
        }
        .cost-sidebar-row strong {
            color: #fff;
            font-size: .78rem;
        }
        .cost-sidebar-row.subtotal {
            margin-top: 4px;
            padding-top: 10px;
            border-top: 1px dashed rgba(255,255,255,.17);
            font-weight: 800;
        }
        .cost-sidebar-row.total {
            font-size: .82rem;
            font-weight: 800;
        }
        .cost-sidebar-row.total strong {
            color: #ffc98f;
            font-size: .92rem;
        }
        .cost-sidebar-note {
            margin-top: 18px;
            padding: 12px 13px;
            border-radius: 10px;
            background: rgba(255,255,255,.1);
            color: rgba(255,255,255,.72);
            font-size: .64rem;
            line-height: 1.5;
        }
        .step-path {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
        .direct-cost-divider {
            height: 1px;
            margin: 22px 0 18px;
            background: #eee8e2;
        }
        .labor-fields {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            grid-column: 1 / -1;
        }
        .labor-fields small,
        .full-card-field small,
        .profit-margin-card label small {
            display: block;
            margin-top: 6px;
            color: #898179;
            font-size: .62rem;
            line-height: 1.4;
        }
        .full-card-field {
            grid-column: 1 / -1;
        }
.indirect-guide {
            margin-bottom: 18px;
            padding: 14px;
            border: 1px solid #e4ddd6;
            border-radius: 11px;
            background: #fbfaf8;
        }
        .indirect-guide strong,
        .indirect-guide span {
            display: block;
        }
        .indirect-guide strong {
            color: #403b36;
            font-size: .76rem;
        }
        .indirect-guide span {
            margin-top: 3px;
            color: #857d76;
            font-size: .64rem;
        }
        .indirect-chips {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
            margin-top: 11px;
        }
        .indirect-chip,
        .margin-preset {
            border: 1px solid #dfd8d1;
            border-radius: 999px;
            padding: 7px 10px;
            background: #fff;
            color: #665e57;
            font: inherit;
            font-size: .62rem;
            font-weight: 800;
            cursor: pointer;
        }
        .indirect-chip:hover,
        .margin-preset:hover,
        .margin-preset.active {
            border-color: #e4b576;
            background: #fff5e9;
            color: #aa5d19;
        }
        .profit-margin-card {
            display: grid;
            gap: 16px;
            padding: 18px;
            border: 1px solid #e2dcd6;
            border-radius: 13px;
            background: #fbfaf8;
        }
        .profit-margin-card h3 {
            margin: 0;
            font-size: 1rem;
        }
        .profit-margin-card p {
            margin: 6px 0 0;
            color: #777069;
            font-size: .71rem;
            line-height: 1.55;
        }
        .percentage-input {
            position: relative;
        }
        .percentage-input input {
            padding-right: 42px;
        }
        .percentage-input span {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #777069;
            font-size: .82rem;
            font-weight: 800;
            pointer-events: none;
        }
        .margin-presets {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
        }
.profit-formula-note {
            color: #827970;
            font-size: .62rem;
            line-height: 1.45;
        }
        .smart-market-box {
            margin-top: 18px;
        }
        @media (max-width: 1100px) {
            .calculator-layout {
                grid-template-columns: minmax(0, 1fr) 310px;
            }
        }
        @media (max-width: 900px) {
            .calculator-layout {
                grid-template-columns: 1fr;
            }
            .cost-sidebar {
                position: static;
                order: 2;
            }
            .calculator-workspace {
                order: 1;
            }
            .cost-sidebar-card {
                border-radius: 14px;
            }
        }
        @media (max-width: 700px) {
            .step-path {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .labor-fields {
                grid-template-columns: 1fr;
            }
        }
        .shortcut-button:hover,
        .nav-button:hover {
            border-color: #e8c19d;
            background: var(--orange-soft);
            color: var(--orange-dark);
        }
        .nav-button.next {
            background: linear-gradient(135deg, var(--orange), #dc7c2e);
            border-color: var(--orange);
            box-shadow: 0 7px 16px rgba(234,138,58,.18);
        }
        .nav-button.next:hover {
            background: linear-gradient(135deg, #df7d2e, var(--orange-dark));
            color: #fff;
        }
        .add-button {
            background: var(--orange);
        }
        .add-button:hover {
            background: var(--orange-dark);
        }
        .secondary-add {
            border-color: #efcaa5;
            background: var(--orange-soft);
            color: var(--orange-dark);
        }
        .calculator-wrap input:focus,
        .calculator-wrap select:focus {
            border-color: #e6a261;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(234,138,58,.12);
        }
        .step-kicker,
        .eyebrow {
            color: var(--orange-dark);
        }
        .step-path-item.active {
            border-color: #edc291;
            background: var(--orange-soft);
            color: #8b4e1b;
        }
        .step-path-item.active b {
            background: var(--orange);
        }
        .cost-highlight,
.indirect-chip:hover,
        .margin-preset:hover,
        .margin-preset.active {
            border-color: #e7aa6c;
            background: var(--orange-soft);
            color: var(--orange-dark);
        }
        .smart-orb {
            background: var(--orange-soft);
            box-shadow: inset 0 0 0 1px #efc89f;
        }
        /* Field-specific reminders and validation */
        .field-error {
            border-color: #dc6a5f !important;
            background: #fff8f7 !important;
            box-shadow: 0 0 0 3px rgba(201,79,69,.10) !important;
        }
        .image-upload.field-error {
            border-style: solid;
            border-color: #dc6a5f !important;
            background: #fff8f7 !important;
        }
        .material-row.field-error,
        .expense-row.field-error {
            border-color: #dc6a5f !important;
            background: #fff8f7 !important;
        }
        .field-error-message {
            display: flex;
            align-items: flex-start;
            gap: 6px;
            margin-top: 6px;
            color: #b9463d;
            font-size: .66rem;
            font-weight: 700;
            line-height: 1.4;
        }
        .field-error-message::before {
            content: "!";
            width: 16px;
            height: 16px;
            display: grid;
            place-items: center;
            flex: 0 0 16px;
            margin-top: 1px;
            border-radius: 50%;
            background: #c94f45;
            color: #fff;
            font-size: .58rem;
            font-weight: 900;
        }
        .step-error-summary {
            display: none;
            margin: 0 0 16px;
            padding: 11px 12px;
            border: 1px solid #edbbb5;
            border-radius: 9px;
            background: var(--red-soft);
            color: #a9433a;
            font-size: .7rem;
            line-height: 1.45;
        }
        .step-error-summary.visible {
            display: block;
        }
        /* Clean aligned rows for materials and indirect expenses */
        #materials,
        #overheads {
            display: grid;
            gap: 10px;
        }
        .material-row {
            grid-template-columns:
                minmax(220px, 1.45fr)
                minmax(120px, .62fr)
                minmax(90px, .42fr)
                minmax(125px, .62fr)
                38px;
            gap: 10px;
            align-items: end;
            margin-top: 0;
            padding: 12px;
            border: 1px solid #e7ded5;
            border-radius: 10px;
            background: #fff;
        }
        .material-row > label {
            min-width: 0;
        }
        .material-row input,
        .material-row select {
            width: 100%;
            min-height: 40px;
            height: 40px;
        }
        .material-row .material-meta {
            margin-top: 5px;
            min-height: 0;
            font-size: .65rem;
            line-height: 1.3;
        }
        .material-row .remove-button {
            width: 38px;
            height: 40px;
            margin: 0;
            align-self: end;
        }
        .expense-row {
            grid-template-columns: minmax(0, 1.4fr) minmax(150px, .65fr) 38px;
            gap: 10px;
            align-items: end;
            margin-top: 0;
            padding: 12px;
            border: 1px solid #e7ded5;
            border-radius: 10px;
            background: #fff;
        }
        .expense-row input {
            min-height: 40px;
            height: 40px;
        }
        .expense-row .remove-button {
            width: 38px;
            height: 40px;
            margin: 0;
        }
        @media (max-width: 1050px) {
            .material-row {
                grid-template-columns: 1fr 1fr;
                align-items: start;
            }
            .expense-row {
                grid-template-columns: 1fr 1fr;
            }
            .material-row .remove-button,
            .expense-row .remove-button {
                grid-column: 1 / -1;
                width: 100%;
            }
        }
        @media (max-width: 700px) {
            .material-row,
            .expense-row {
                grid-template-columns: 1fr;
            }
            .material-row .remove-button,
            .expense-row .remove-button {
                grid-column: auto;
            }
        }
        /* Keep every step inside its own column */
        .calculator-layout {
            grid-template-columns: minmax(0, 1fr) minmax(300px, 320px);
            gap: 18px;
            align-items: start;
        }
        .calculator-workspace,
        .calculator-workspace .calculator-card,
        .calculator-workspace .step,
        .product-grid,
        .cost-input-grid,
        .cost-input-card,
        .section-heading,
        .material-actions {
            min-width: 0;
            max-width: 100%;
        }
        .calculator-workspace .calculator-card {
            width: 100%;
        }
        .section-heading {
            flex-wrap: wrap;
        }
        .material-actions {
            flex-wrap: wrap;
        }
        #materials,
        #overheads {
            width: 100%;
            min-width: 0;
        }
        .material-row {
            width: 100%;
            min-width: 0;
            grid-template-columns:
                minmax(0, 1.35fr)
                minmax(92px, .58fr)
                minmax(70px, .42fr)
                minmax(96px, .62fr)
                36px;
            gap: 9px;
        }
        .material-row > *,
        .material-row > label,
        .expense-row > *,
        .expense-row > label {
            min-width: 0;
        }
        .material-row input,
        .material-row select,
        .expense-row input {
            max-width: 100%;
        }
        .material-row .remove-button {
            width: 36px;
        }
        .expense-row {
            width: 100%;
            min-width: 0;
            grid-template-columns: minmax(0, 1fr) minmax(120px, .55fr) 36px;
            gap: 9px;
        }
        .expense-row .remove-button {
            width: 36px;
        }
        .labor-fields {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        /* Softer LokalCraft cost breakdown for easier reading */
        .cost-sidebar {
            position: sticky;
            top: calc(var(--pricing-header-bottom, 70px) + 12px);
            align-self: start;
            min-width: 0;
        }
        .cost-sidebar-card {
            padding: 21px 20px;
            border: 1px solid #e7cdb9;
            border-radius: 16px;
            background:
                radial-gradient(circle at top right, rgba(234,138,58,.12), transparent 34%),
                linear-gradient(145deg, #fff9f4 0%, #f4e7dc 100%);
            color: #4b392f;
            box-shadow: 0 14px 30px rgba(80, 51, 31, .09);
        }
        .cost-sidebar-kicker {
            color: #a86b3d;
        }
        .cost-sidebar-title {
            color: #70594a;
        }
        .cost-sidebar-price {
            color: var(--orange-dark);
            font-size: clamp(2.15rem, 3.2vw, 3.15rem);
            letter-spacing: -1.1px;
        }
        .cost-sidebar-rule {
            background: #dcc4b1;
        }
        .cost-sidebar-section-label {
            color: #9b6d4b;
        }
        .cost-sidebar-row {
            color: #665247;
        }
        .cost-sidebar-row strong {
            color: #3e312a;
        }
        .cost-sidebar-row.subtotal {
            border-top-color: #d8bcaa;
            color: #514036;
        }
        .cost-sidebar-row.total {
            color: #49362b;
        }
        .cost-sidebar-row.total strong {
            color: var(--orange-dark);
        }
        .cost-sidebar-row.pricing-profit-row,
        .cost-sidebar-row.seller-payout-row {
            margin-top: 8px;
            padding: 11px 12px;
            border: 1px solid #efd4b8;
            border-radius: 9px;
            background: #fff2e4;
            color: #855126;
            font-weight: 700;
        }
        .cost-sidebar-row.pricing-profit-row strong,
        .cost-sidebar-row.seller-payout-row strong {
            color: var(--orange-dark);
        }
        .cost-sidebar-row.platform-fee-row strong {
            color: #9d5b39;
        }
        .cost-sidebar-row .label-with-tip { display: block; min-width: 0; overflow-wrap: break-word; }
        .cost-sidebar-row .info-tip-wrap { vertical-align: middle; margin-left: 3px; }
        .cost-sidebar-row strong { flex-shrink: 0; white-space: nowrap; }
        .cost-sidebar-note {
            background: rgba(255,255,255,.58);
            border: 1px solid #ead5c5;
            color: #766054;
        }
        /* Keep the two direct-cost cards comfortably inside Step 2 */
        @media (max-width: 1280px) {
            .calculator-layout {
                grid-template-columns: minmax(0, 1fr) minmax(285px, 305px);
                gap: 16px;
            }
            .material-row {
                grid-template-columns:
                    minmax(0, 1.25fr)
                    minmax(86px, .55fr)
                    minmax(66px, .4fr)
                    minmax(90px, .58fr)
                    34px;
                gap: 8px;
            }
            .material-row .remove-button,
            .expense-row .remove-button {
                width: 34px;
            }
        }
        @media (max-width: 1080px) {
            .calculator-layout {
                grid-template-columns: 1fr;
            }
            .cost-sidebar {
                position: static;
                order: 2;
                margin-top: 18px;
            }
            .calculator-workspace {
                order: 1;
            }
            .cost-sidebar-card {
                max-width: none;
            }
        }
        @media (max-width: 760px) {
            .material-row,
            .expense-row,
            .labor-fields {
                grid-template-columns: 1fr;
            }
            .material-row .remove-button,
            .expense-row .remove-button {
                width: 100%;
                grid-column: auto;
            }
        }
        /* Clickable market comparison details */
        .fighter-card.rival-fighter {
            position: relative;
            cursor: pointer;
            transition:
                border-color .18s ease,
                background .18s ease,
                box-shadow .18s ease,
                transform .18s ease;
        }
        .fighter-card.rival-fighter::after {
            content: "View details";
            position: absolute;
            right: 8px;
            bottom: 7px;
            padding: 3px 6px;
            border-radius: 999px;
            background: var(--orange-soft);
            color: var(--orange-dark);
            font-size: .53rem;
            font-weight: 800;
            opacity: .88;
        }
        .fighter-card.rival-fighter:hover,
        .fighter-card.rival-fighter:focus-visible {
            border-color: #e7af78;
            background: #fffaf4;
            box-shadow: 0 7px 18px rgba(108, 72, 45, .08);
            transform: translateY(-1px);
            outline: none;
        }
        .market-detail-modal {
            position: fixed;
            inset: 0;
            z-index: 240000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 22px;
        }
        .market-detail-modal.open {
            display: flex;
        }
        .market-detail-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(44, 34, 27, .48);
            backdrop-filter: blur(3px);
        }
        .market-detail-panel {
            position: relative;
            z-index: 1;
            width: min(760px, 100%);
            max-height: calc(100vh - 44px);
            overflow-y: auto;
            border: 1px solid #ead9cb;
            border-radius: 16px;
            background: #fffdfb;
            box-shadow: 0 28px 80px rgba(57, 39, 27, .22);
        }
        .market-detail-close {
            position: absolute;
            top: 12px;
            right: 12px;
            z-index: 2;
            width: 36px;
            height: 36px;
            display: grid;
            place-items: center;
            border: 1px solid #e4d8ce;
            border-radius: 50%;
            background: rgba(255,255,255,.94);
            color: #55483f;
            font-size: 1.3rem;
            line-height: 1;
            cursor: pointer;
        }
        .market-detail-close:hover {
            border-color: #e7ad76;
            background: var(--orange-soft);
            color: var(--orange-dark);
        }
        .market-detail-grid {
            display: grid;
            grid-template-columns: minmax(260px, .95fr) minmax(0, 1.05fr);
            min-height: 390px;
        }
        .market-detail-image-wrap {
            min-height: 390px;
            display: grid;
            place-items: center;
            overflow: hidden;
            background:
                radial-gradient(circle at top left, rgba(234,138,58,.11), transparent 32%),
                #f3eee9;
        }
        .market-detail-image {
            width: 100%;
            height: 100%;
            max-height: 520px;
            object-fit: contain;
            display: none;
            padding: 16px;
        }
        .market-detail-image.visible {
            display: block;
        }
        .market-detail-image-empty {
            padding: 20px;
            color: #94887f;
            font-size: .76rem;
            text-align: center;
        }
        .market-detail-content {
            min-width: 0;
            padding: 48px 28px 28px;
        }
        .market-detail-source {
            display: inline-flex;
            padding: 5px 8px;
            border-radius: 999px;
            background: var(--orange-soft);
            color: var(--orange-dark);
            font-size: .6rem;
            font-weight: 800;
        }
        .market-detail-content h2 {
            margin: 12px 0 6px;
            color: #392f29;
            font-size: 1.35rem;
            line-height: 1.3;
            overflow-wrap: anywhere;
        }
        .market-detail-price {
            color: var(--orange-dark);
            font-size: 1.65rem;
            font-weight: 900;
        }
        .market-detail-meta {
            margin-top: 10px;
            color: #7b7068;
            font-size: .7rem;
            line-height: 1.5;
        }
        .market-detail-category-row {
            margin-top: 15px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px 10px;
            color: #82766d;
            font-size: .76rem;
        }
        .market-detail-category-label { font-weight: 700; }
        .market-detail-category { color: #524138; font-weight: 800; overflow-wrap: anywhere; }
        .market-detail-meta:empty { display: none; }
        .market-detail-reviews {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 14px;
            padding: 11px 0;
            border-top: 1px solid #eee5dc;
            border-bottom: 1px solid #eee5dc;
        }
        .market-review-stars {
            display: inline-block;
            position: relative;
            color: #d7cbc0;
            font-family: Arial, sans-serif;
            font-size: 1.15rem;
            letter-spacing: 2px;
            line-height: 1;
            white-space: nowrap;
        }
        .market-review-stars-fill {
            position: absolute;
            inset: 0 auto 0 0;
            width: 0;
            overflow: hidden;
            color: #e9933f;
            white-space: nowrap;
        }
        .market-review-score { color: #4e3c2e; font-size: .78rem; font-weight: 850; }
        .market-review-count { color: #81736a; font-size: .72rem; }
        .market-detail-image[role="button"]:focus-visible {
            outline: 3px solid var(--orange);
            outline-offset: -5px;
            border-radius: 12px;
        }
        .market-detail-comparison {
            margin-top: 20px;
            padding: 13px 14px;
            border: 1px solid #ead9cb;
            border-radius: 10px;
            background: #fff8f1;
        }
        .market-detail-comparison span,
        .market-detail-comparison strong {
            display: block;
        }
        .market-detail-comparison span {
            color: #8a7d74;
            font-size: .62rem;
            font-weight: 700;
        }
        .market-detail-comparison strong {
            margin-top: 5px;
            color: #53453c;
            font-size: .75rem;
            line-height: 1.5;
        }
@media (max-width: 700px) {
            .market-detail-modal {
                padding: 12px;
            }
            .market-detail-grid {
                grid-template-columns: 1fr;
            }
            .market-detail-image-wrap {
                min-height: 250px;
                max-height: 320px;
            }
            .market-detail-content {
                padding: 22px 18px 20px;
            }
            .market-detail-close {
                top: 8px;
                right: 8px;
            }
            .fighter-card.rival-fighter::after {
                position: static;
                grid-column: 1 / -1;
                justify-self: start;
                margin-top: 2px;
            }
        }
        .fighter-card.rival-fighter {
            width: 100%;
            border: 1px solid #e4e0dc;
            font: inherit;
            color: inherit;
            text-align: left;
        }
        /* Final Step 2 material-row alignment */
        #materials {
            display: grid;
            gap: 10px;
            width: 100%;
            min-width: 0;
        }
        #materials .material-row {
            width: 100%;
            min-width: 0;
            display: grid;
            grid-template-columns:
                minmax(0, 1.45fr)
                minmax(105px, .58fr)
                minmax(80px, .42fr)
                minmax(110px, .60fr)
                38px;
            grid-template-areas:
                "material quantity unit cost remove"
                "meta meta meta meta .";
            gap: 7px 10px;
            align-items: end;
            margin: 0;
            padding: 12px;
            border: 1px solid #e7ded5;
            border-radius: 10px;
            background: #fff;
        }
        #materials .material-field {
            min-width: 0;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            margin: 0;
        }
        #materials .material-field-select {
            grid-area: material;
        }
        #materials .material-field-quantity {
            grid-area: quantity;
        }
        #materials .material-field-unit {
            grid-area: unit;
        }
        #materials .material-field-cost {
            grid-area: cost;
        }
        #materials .material-field-title {
            min-height: 22px;
            display: flex;
            align-items: center;
            margin: 0 0 6px;
            color: #4a4a4a;
            font-size: .72rem;
            font-weight: 700;
            line-height: 1.2;
        }
        #materials .material-field input,
        #materials .material-field select {
            width: 100%;
            min-width: 0;
            height: 40px;
            min-height: 40px;
            margin: 0;
        }
        #materials .material-unit,
        #materials .material-cost {
            background: #f7f4f1;
            color: #665e57;
        }
        #materials .remove-material {
            grid-area: remove;
            width: 38px;
            height: 40px;
            min-width: 38px;
            margin: 0;
            align-self: end;
        }
        #materials .material-row-meta {
            grid-area: meta;
            min-width: 0;
            min-height: 18px;
            margin: 0;
            padding: 1px 2px 0;
            color: #81776f;
            font-size: .62rem;
            line-height: 1.35;
            overflow-wrap: anywhere;
        }
        @media (max-width: 1160px) and (min-width: 1081px) {
            #materials .material-row {
                grid-template-columns: minmax(0, 1fr) minmax(105px, .55fr) 38px;
                grid-template-areas:
                    "material quantity remove"
                    "unit cost ."
                    "meta meta .";
            }
        }
        @media (max-width: 760px) {
            #materials .material-row {
                grid-template-columns: 1fr;
                grid-template-areas:
                    "material"
                    "quantity"
                    "unit"
                    "cost"
                    "meta"
                    "remove";
            }
            #materials .remove-material {
                width: 100%;
            }
        }

/* Keep the existing step-path appearance; only previously reached steps are interactive. */
.step-path-item { font: inherit; text-align: left; cursor: pointer; transition: border-color .15s, background .15s; }
.step-path-item:not(:disabled):hover,
.step-path-item:not(:disabled):focus-visible { border-color: #e5b77c; background: #fff6eb; outline-offset: 2px; }
.step-path-item:disabled { cursor: not-allowed; opacity: .68; }
.step-help.small-help { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: -8px; margin-bottom: 12px; }
.market-context[hidden] { display: none; }
.market-info-tip { display: inline-flex; margin: 0 0 0 2px; vertical-align: middle; }
/* Four comparable prices in one row, with the seller price across the full row below. */
.market-overview { max-width: 100%; overflow: visible; }
.market-overview-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    min-width: 0;
    gap: 8px;
}
.market-stat {
    display: flex;
    min-width: 0;
    min-height: 66px;
    flex-direction: column;
    justify-content: center;
    padding: 10px 11px;
}
.market-stat.own-stat {
    grid-column: 1 / -1;
    min-height: 58px;
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border-color: #eacda8;
    background: #fff8ef;
}
.market-stat-label { margin-bottom: 5px; font-size: .65rem; line-height: 1.2; }
.market-stat-value { font-size: 1rem; line-height: 1.25; overflow-wrap: anywhere; }
.market-stat.own-stat .market-stat-label { margin-bottom: 0; }
.market-stat.own-stat .market-stat-value { color: var(--orange-dark); font-size: 1.1rem; }
@media (max-width: 620px) {
    .market-overview-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}




.market-detail-image.visible { cursor: zoom-in; }
.market-photo-view { margin-top: 12px; border: 1px solid #e8c4a3; border-radius: 9px; background: #fff7ef; color: #ac6325; padding: 9px 12px; font-weight: 700; cursor: pointer; }
.market-photo-view[hidden] { display: none; }
.market-photo-view { display: inline-flex; margin-right: 5px; text-decoration: none; }
.market-lightbox { position: fixed; inset: 0; z-index: 300000; display: none; align-items: center; justify-content: center; padding: 60px 20px 22px; background: rgba(24,20,18,.93); }
.market-lightbox.open { display: flex; }
.market-lightbox img { max-width: 100%; max-height: 100%; object-fit: contain; }
.market-lightbox-close { position: absolute; top: 12px; right: 16px; color: #fff; border: 1px solid rgba(255,255,255,.4); border-radius: 50%; background: #3b342e; width: 40px; height: 40px; cursor: pointer; font-size: 1.6rem; }
.guide-popup {
    position: fixed;
    z-index: 290002;
    display: flex;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 10px;
    width: min(380px, calc(100vw - 28px));
    max-width: calc(100vw - 28px);
    padding: 14px 15px;
    border: 1px solid var(--guide-arrow-border, #a6dab9);
    border-left: 4px solid var(--guide-accent, #359a63);
    border-radius: 13px;
    background: #fff;
    color: #40372f;
    box-shadow: 0 14px 40px rgba(38,27,17,.20);
    font-size: .80rem;
    line-height: 1.45;
    animation: savedGuideIn .28s ease-out both;
}
.guide-popup[hidden] { display: none; }
.guide-popup.guide-pop { animation: savedGuideIn .28s ease-out both; }
.guide-popup::before,
.guide-popup::after {
    content: "";
    position: absolute;
    left: var(--guide-arrow-left, 50%);
    width: 0;
    height: 0;
    transform: translateX(-50%);
    border-left: 9px solid transparent;
    border-right: 9px solid transparent;
    pointer-events: none;
}
.guide-popup.guide-below::before { top: -10px; border-bottom: 10px solid var(--guide-arrow-border, #a6dab9); }
.guide-popup.guide-below::after { top: -8px; border-bottom: 8px solid #fff; }
.guide-popup.guide-above::before { bottom: -10px; border-top: 10px solid var(--guide-arrow-border, #a6dab9); }
.guide-popup.guide-above::after { bottom: -8px; border-top: 8px solid #fff; }
.guide-popup-copy { display: grid; gap: 4px; flex: 1 1 190px; min-width: 0; padding-right: 22px; }
.guide-popup-copy strong { color: #47372b; font-size: .85rem; }
.guide-popup-copy span { color: #74695f; font-size: .75rem; }
.guide-popup a { display: inline-flex; align-items: center; justify-content: center; min-height: 34px; padding: 7px 10px; border-radius: 8px; background: var(--orange-soft); color: var(--orange-dark); font-weight: 800; text-decoration: none; }
.guide-popup a:hover { background: #ffe7d1; }
.guide-popup button { position: absolute; top: 7px; right: 7px; display: inline-flex; align-items: center; justify-content: center; flex: 0 0 28px; width: 28px; height: 28px; margin: 0; border: 0; border-radius: 7px; background: transparent; color: #766c63; cursor: pointer; }
.guide-popup button:hover { background: #f6f0ea; }
.costing-saved-toast { --guide-arrow-border: #a6dab9; --guide-accent: #359a63; }
.materials-library-guide { --guide-arrow-border: #edc58f; --guide-accent: #d78332; }
.saved-guide-highlight,
.library-guide-highlight {
    position: relative !important;
    z-index: 290001 !important;
    outline: 3px solid rgba(234,138,58,.68) !important;
    outline-offset: 3px !important;
    box-shadow: 0 0 0 8px rgba(234,138,58,.16), 0 8px 22px rgba(92,56,29,.20) !important;
    animation: savedButtonPulse 1.2s ease-in-out infinite;
}
@keyframes savedGuideIn {
    from { opacity: 0; transform: translateY(7px) scale(.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
@keyframes savedButtonPulse {
    0%, 100% { outline-offset: 3px; box-shadow: 0 0 0 5px rgba(234,138,58,.12), 0 8px 22px rgba(92,56,29,.16); }
    50% { outline-offset: 5px; box-shadow: 0 0 0 9px rgba(234,138,58,.22), 0 8px 22px rgba(92,56,29,.20); }
}
@media (max-width:760px) {
    .guide-popup { gap: 8px; padding: 12px; font-size: .76rem; }
    .guide-popup-copy { flex-basis: 150px; }
    .guide-popup-copy strong { font-size: .82rem; }
    .guide-popup-copy span { font-size: .72rem; }
    .guide-popup a { flex: 1 1 100%; }
}
@media (prefers-reduced-motion: reduce) {
    .guide-popup, .saved-guide-highlight, .library-guide-highlight { animation: none !important; }
}

        /* Seller Calculator finishing layer: consistent typography and warm LokalCraft palette. */
        .progress-card {
            border-color: #ead9c9;
            border-radius: 15px;
            background: #fffdfb;
            box-shadow: 0 9px 24px rgba(95, 57, 29, .045);
        }
        .progress-top, .step-status {
            color: #74594a;
        }
        .progress-fill {
            background: linear-gradient(90deg, #e98b40, #f3aa65);
        }
        .step-path {
            grid-template-columns: repeat(4, minmax(0,1fr));
            gap: 8px;
        }
        .step-path-item {
            font-family: "Segoe UI", Arial, sans-serif;
            font-size: .70rem;
            font-weight: 700;
            line-height: 1.25;
            color: #845841;
            text-align: left;
            border: 1px solid #eedbca;
            border-radius: 10px;
            padding: 8px 9px;
            min-height: 40px;
            background: #fff8f1;
            transition: border-color .15s, box-shadow .15s, background .15s;
        }
        .step-path-item b {
            flex: 0 0 21px;
            width: 21px;
            height: 21px;
            color: #b46c2e;
            font-size: .62rem;
            font-weight: 800;
            border-radius: 7px;
            background: #ffead6;
        }
        .step-path-item.active {
            border-color: #efa96c;
            background: #ffe7d1;
            color: #9b531c;
            box-shadow: inset 0 0 0 1px rgba(234,138,58,.12);
        }
        .step-path-item.active b { background: #e98b40; color: #fff; }
        .step-path-item:disabled {
            opacity: 1;
            color: #a59b94;
            border-color: #ebe7e3;
            background: #f7f5f3;
            cursor: not-allowed;
        }
        .step-path-item:disabled b { color: #a59b94; background: #e9e5e1; }
        .step-path-item:not(:disabled):not(.active):hover,
        .step-path-item:not(:disabled):focus-visible {
            border-color: #eba970;
            background: #fff0e0;
            box-shadow: 0 0 0 2px rgba(234,138,58,.09);
        }
        .calculator-card { border-color: #e9dcd1; border-radius: 14px; }
        /* Soft, light LokalCraft cost summary with natural page scrolling. */
        .cost-sidebar-card {
            background: linear-gradient(160deg, #fffefd 0%, #fff8f1 100%);
            border-color: #ecdfd2;
            box-shadow: 0 12px 28px rgba(102,57,29,.055);
            max-height: none;
            overflow: visible;
        }
        .cost-sidebar-row.pricing-profit-row,
        .cost-sidebar-row.seller-payout-row { background: #fff4e8; border-color: #efdccc; }
        .cost-sidebar-note { background: #fffdfa; border-color: #efe2d6; }
        .cost-sidebar-price { color: #cd6b1f; }
        .cost-sidebar-section-label, .cost-sidebar-kicker { color: #a75e2b; }
        .info-tip {
            font-family: "Segoe UI", Arial, sans-serif;
            border-color: #e6b98c;
            color: #a75f2c;
            background: #fff9f3;
            transition: background .15s, border-color .15s;
        }
        .info-tip:hover, .info-tip:focus-visible {
            background: #ffebd8;
            border-color: #e98b40;
            outline-color: #e98b40;
        }
        .floating-tooltip {
            max-width: min(260px, calc(100vw - 24px));
            background: #413026;
            color: #fffaf5;
            border: 1px solid #805439;
            border-radius: 9px;
            padding: 10px 12px;
            font-family: "Segoe UI", Arial, sans-serif;
            font-size: .72rem;
            font-weight: 400;
            line-height: 1.45;
            box-shadow: 0 10px 30px rgba(51,35,24,.22);
        }
        .remove-button,
        .market-detail-close,
        .market-lightbox-close,
        #costing-toast-close {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-family: "Segoe UI", Arial, sans-serif;
            line-height: 1;
            transition: border-color .15s, background .15s, color .15s;
        }
        .remove-button svg,
        .market-detail-close svg,
        .market-lightbox-close svg,
        #costing-toast-close svg {
            display: block;
            flex-shrink: 0;
        }
        .remove-button { border-radius: 9px; }
        .remove-button:hover { border-color: #d97e72; background: #ffefee; color: #ae4038; }
        #costing-toast-close { min-width: 28px; min-height: 28px; padding: 4px; border-radius: 7px; }
        #costing-toast-close:hover { background: #edf6ed; color: #285c3b; }
        @media (max-width: 760px) {
            .step-path { grid-template-columns: repeat(2, minmax(0,1fr)); }
            .step-path-item { font-size: .70rem; }
            #materials .remove-material,
            .expense-row .remove-button {
                width: 38px;
                min-width: 38px;
                height: 38px;
                justify-self: end;
                border-radius: 9px;
            }
            .expense-row .remove-button { grid-column: 1 / -1; }
        }
        /* Profile-style shortcut buttons: same color, radius and hover as Edit Profile. */
        .heading-actions .shortcut-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 43px;
            padding: 10px 17px;
            border: 1px solid #d77d32;
            border-radius: 12px;
            background: #e98b40;
            color: #fff;
            font-family: "Segoe UI", Arial, sans-serif;
            font-size: .83rem;
            font-weight: 750;
            box-shadow: 0 5px 14px rgba(202,111,36,.13);
            transition: background .16s ease, border-color .16s ease, transform .16s ease;
        }
        .heading-actions .shortcut-button:hover {
            border-color: #c8752d;
            background: #cd722c;
            color: #fff;
            transform: translateY(-1px);
        }
        .heading-actions .shortcut-button:focus-visible {
            outline: 3px solid #f6bc8b;
            outline-offset: 3px;
        }
        .heading-actions .shortcut-button svg {
            width: 17px;
            height: 17px;
            flex: 0 0 17px;
        }
        /* Allow the five-column material table and breakdown to size independently. */
        .calculator-wrap :is(input, select, button, .material-row, .expense-row) {
            scroll-margin-top: calc(var(--pricing-header-bottom, 70px) + 18px);
        }
        .calculator-layout > *, .calculator-workspace { min-width: 0; }
        .calculator-workspace :is(.step, .calculator-card, .progress-card) { min-width: 0; }
        @media (max-width: 1080px) {
            .cost-sidebar-card {
                max-height: none;
                overflow: visible;
            }
        }
        @media (max-width: 840px) and (min-width: 761px) {
            .calculator-wrap { padding: 8px 16px 24px; }
        }
        @media (max-width: 760px) {
            .heading-actions { width: 100%; }
            .heading-actions .shortcut-button { flex: 1 1 auto; }
            .calculator-wrap { padding: 8px 12px 24px; }
        }
        @media (max-width: 390px) {
            .heading-actions .shortcut-button { padding: 10px 11px; font-size: .72rem; gap: 5px; }
            .heading-actions .shortcut-button svg { width: 15px; height: 15px; flex-basis: 15px; }
        }
        /* Only the range of comparable listings is shown beside Similar Products. */
        .market-overview { margin-top: 14px; }
        .market-range {
            flex: 0 1 auto;
            min-width: 0;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
            text-align: right;
            padding: 7px 11px;
            border: 1px solid #efd8c2;
            border-radius: 10px;
            background: #fff4e9;
        }
        .market-range-label {
            color: #986b49;
            font-size: .59rem;
            font-weight: 800;
            letter-spacing: .025em;
            text-transform: uppercase;
        }
        .market-range-price {
            color: #a75f22;
            font-size: .84rem;
            font-weight: 850;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }
        .market-own-card {
            display: flex; align-items: center; gap: 13px; min-width: 0;
            padding: 13px; border: 1px solid #efd0b2; border-radius: 12px;
            background: linear-gradient(105deg, #fffdfb 0%, #fff4e8 100%);
        }
        .market-own-image, .market-similar-image {
            display: grid; place-items: center; flex: 0 0 auto; overflow: hidden;
            width: 68px; height: 68px; border-radius: 10px;
            background: #f2eae2; color: #8b8076; font-size: .63rem; text-align: center;
        }
        .market-own-image img, .market-similar-image img {
            display: block; width: 100%; height: 100%; object-fit: cover;
        }
        .market-own-copy { display: flex; flex: 1 1 auto; flex-direction: column; gap: 5px; min-width: 0; }
        .market-own-eyebrow { color: var(--orange-dark); font-size: .6rem; font-weight: 900; letter-spacing: .07em; }
        .market-own-name { font-size: .88rem; line-height: 1.35; overflow-wrap: anywhere; }
        .market-own-category { color: var(--muted); font-size: .67rem; }
        .market-own-price-block { display: flex; flex: 0 0 auto; flex-direction: column; align-items: flex-end; gap: 5px; text-align: right; }
        .market-own-price-block > span { color: var(--muted); font-size: .62rem; }
        .market-own-price-block strong { color: var(--orange-dark); font-size: 1.1rem; white-space: nowrap; }
        .market-list-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin: 20px 0 10px; min-width: 0; }
        .market-list-head[hidden], .market-load-more[hidden], #market-see-more[hidden] { display: none !important; }
        .market-list-head h3 { margin: 0 0 4px; color: #43372e; font-size: .88rem; }
        .market-list-count, .market-list-note { color: #93877d; font-size: .65rem; }
        .market-similar-results.market-results { display: grid; grid-template-columns: minmax(0,1fr); gap: 9px; margin-top: 0; }
        .market-similar-card {
            display: flex; align-items: center; gap: 12px; width: 100%; min-width: 0;
            padding: 11px 12px; border: 1px solid #e8dfd6; border-radius: 12px;
            background: #fff; font: inherit; color: inherit; text-align: left; cursor: pointer;
            transition: border-color .15s ease, background .15s ease, box-shadow .15s ease;
        }
        .market-similar-card:hover, .market-similar-card:focus-visible {
            border-color: #e5af7e; background: #fffaf5;
            box-shadow: 0 5px 15px rgba(94,63,37,.065); outline: none;
        }
        .market-similar-image { width: 64px; height: 64px; border-radius: 9px; }
        .market-similar-copy { display: flex; flex: 1 1 auto; flex-direction: column; align-items: flex-start; gap: 4px; min-width: 0; }
        .market-similar-name { font-size: .78rem; font-weight: 800; line-height: 1.3; overflow-wrap: anywhere; }
        .market-similar-source { color: #8d837a; font-size: .64rem; }
        .market-match-pill, .market-match-unknown {
            display: inline-flex; padding: 4px 7px; border-radius: 6px;
            background: #fff0e2; color: #ad5c20; font-size: .61rem; font-weight: 800;
        }
        .market-match-unknown { background: #f4f1ee; color: #80766e; }
        .market-similar-right { display: flex; flex: 0 0 auto; flex-direction: column; align-items: flex-end; gap: 7px; text-align: right; }
        .market-similar-price { color: var(--orange-dark); font-size: .88rem; white-space: nowrap; }
        .market-similar-view { display: inline-flex; align-items: center; gap: 3px; color: #9f6a40; font-size: .62rem; font-weight: 700; }
        .market-load-more { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-top: 12px; }
        #market-visible-count { color: #8a8078; font-size: .65rem; }
        .market-more-button {
            min-height: 38px; padding: 9px 17px; border: 1px solid #e5b98e;
            border-radius: 10px; background: #fff4e9; color: #ab5d20;
            font: inherit; font-size: .73rem; font-weight: 800; cursor: pointer;
        }
        .market-more-button:hover, .market-more-button:focus-visible { background: #ffe9d5; border-color: #d99658; }
        @media (max-width: 620px) {
            .market-list-head > div:first-child { flex: 1 1 0; min-width: 0; }
            .market-range { padding: 7px 8px; max-width: 60%; }
            .market-range-label { font-size: .53rem; }
            .market-range-price { font-size: clamp(.66rem, 2.75vw, .82rem); white-space: normal; overflow-wrap: anywhere; }
            .market-own-card { flex-wrap: wrap; gap: 10px; padding: 11px; }
            .market-own-image { width: 55px; height: 55px; }
            .market-own-price-block { flex: 1 0 100%; flex-direction: row; justify-content: space-between; align-items: baseline; padding-top: 9px; border-top: 1px solid #f3e0cf; }
            .market-list-note { display: none; }
            .market-similar-card { gap: 9px; padding: 9px; }
            .market-similar-image { width: 54px; height: 54px; }
            .market-similar-name { font-size: .72rem; }
            .market-similar-price { font-size: .75rem; }
            .market-similar-view { font-size: .58rem; }
            .market-match-pill { font-size: .57rem; }
        }
        /* Keep competitor names readable even on 320px phones. */
        @media (max-width: 420px) {
            .market-similar-card {
                display: grid;
                grid-template-columns: 48px minmax(0, 1fr);
                align-items: start;
                column-gap: 9px;
                row-gap: 5px;
            }
            .market-similar-image {
                grid-column: 1;
                grid-row: 1 / 3;
                width: 48px;
                height: 48px;
            }
            .market-similar-copy {
                grid-column: 2;
                width: 100%;
            }
            .market-similar-right {
                grid-column: 2;
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
                gap: 7px;
                text-align: left;
                min-width: 0;
            }
            .market-similar-view { white-space: nowrap; }
        }

        /* Keep the app's form styling in Safari/iOS (including Dark Mode), not the native Apple controls. */
        .calculator-wrap { color-scheme: light; }
        .calculator-wrap input:not([type="file"]):not([type="checkbox"]):not([type="radio"]):not([type="hidden"]),
        .calculator-wrap select,
        .calculator-wrap textarea {
            -webkit-appearance: none;
            appearance: none;
            box-sizing: border-box;
            font-family: inherit;
            font-weight: 400;
            color: #343434;
            background-color: #f1f1f1;
            border: 1px solid transparent;
            border-radius: 8px;
            background-clip: padding-box;
            -webkit-text-size-adjust: 100%;
            max-width: 100%;
            min-width: 0;
        }
        .calculator-wrap select {
            padding-right: 34px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23837569' stroke-width='2.3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 11px center;
            background-size: 16px 16px;
        }
        .calculator-wrap input[type="number"]::-webkit-inner-spin-button,
        .calculator-wrap input[type="number"]::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        .calculator-wrap input[type="number"] { -moz-appearance: textfield; }
        .calculator-wrap input::placeholder,
        .calculator-wrap textarea::placeholder { color: #8c8680; opacity: 1; }
        .calculator-wrap input[readonly] { background-color: #f7f4f1; color: #665e57; cursor: default; }
        .calculator-wrap input:not([type="file"]):focus,
        .calculator-wrap select:focus,
        .calculator-wrap textarea:focus {
            border-color: #e6a261;
            background-color: #fff;
            box-shadow: 0 0 0 3px rgba(234,138,58,.12);
            outline: none;
        }
        .calculator-wrap select:disabled,
        .calculator-wrap input:disabled { opacity: 1; color: #81776f; cursor: not-allowed; }

        /* Focused mobile usability adjustments: preserve desktop layouts and calculations. */
        .product-image-lightbox[hidden] { display: none !important; }
        .product-image-lightbox { position: fixed; inset: 0; z-index: 300000; display: grid; place-items: center; padding: 58px 18px 22px; background: rgba(20,16,13,.9); }
        .product-image-lightbox img { display: block; max-width: 100%; max-height: calc(100dvh - 90px); object-fit: contain; border-radius: 8px; }
        #product-image-lightbox-close { position: absolute; right: 16px; top: 12px; width: 42px; height: 42px; border: 0; border-radius: 50%; color: #fff; background: #55483f; font-size: 28px; cursor: pointer; }
        #image-preview { border: 0; font: inherit; }
        #image-preview:disabled { cursor: default; }
        .row-added { animation: costingRowAdded .32s ease-out both; }
        @keyframes costingRowAdded { from { opacity: .45; transform: translateY(-9px); background-color: #fff1df; } to { opacity: 1; transform: translateY(0); } }
        @media (prefers-reduced-motion: reduce) { .row-added { animation: none; } }
        @media (max-width: 760px) {
            .heading-row { flex-direction: row; align-items: center; gap: 8px; }
            .heading-row h1 { font-size: 1.3rem; line-height: 1.25; letter-spacing: -.3px; }
            .heading-actions { width: auto; flex: 0 0 auto; margin-left: auto; flex-wrap: nowrap; gap: 6px; }
            .heading-actions .shortcut-button { flex: 0 0 40px; width: 40px; height: 40px; min-height: 40px; padding: 0; border-radius: 10px; }
            .heading-actions .shortcut-button svg { width: 19px; height: 19px; flex-basis: 19px; }
            .heading-actions .shortcut-text { display: none; }
            .step-path { grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 7px; }
            .step-path-item { justify-content: center; padding: 8px 3px; font-size: 0; gap: 0; min-height: 40px; }
            .step-path-item b { font-size: 13px; width: 25px; height: 25px; flex: 0 0 25px; }
            .product-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 12px; }
            .product-grid > .image-upload { grid-row: auto; min-height: 0; }
            /* Compact Step 1 fields; retain 16px text to prevent iOS focus zoom. */
            .product-grid > label { display: block; min-width: 0; font-size: 13px; font-weight: 600; }
            .product-grid > label > .label-with-tip { margin-bottom: 0; }
            .calculator-wrap .product-grid #product-title,
            .calculator-wrap .product-grid #craft-category {
                display: block;
                width: 100%;
                height: 46px;
                min-height: 46px;
                max-height: 46px;
                margin-top: 6px;
                padding: 0 13px;
                border-radius: 8px;
                font-size: 16px !important;
                font-weight: 500;
                line-height: normal;
            }
            .calculator-wrap .product-grid #craft-category { padding-right: 34px; }

            .image-upload { padding: 10px; }
            .product-image-chooser { min-height: 96px; }
            .product-image-selected { display: flex; align-items: center; gap: 9px; min-height: 67px; padding: 8px; border: 1px solid #eaded2; border-radius: 10px; background: #fff; }
            .product-image-selected:hover { border-color: #dba87e; background: #fff8f0; }
            .product-image-selected #image-preview { flex: 0 0 50px; width: 50px; height: 50px; min-height: 50px; max-height: 50px; padding: 0; border-radius: 8px; background: #f4ede5; }
            .product-image-selected #image-preview img { display: block; width: 50px; height: 50px; border-radius: 8px; object-fit: cover; }
            .product-image-meta { position: static; display: block; flex: 1; min-width: 0; pointer-events: auto; }
            .product-image-name { display: block; color: #5d5147; font-size: .72rem; font-weight: 750; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .product-image-size { display: block; margin-top: 2px; color: #87796d; font-size: .68rem; }
            .product-image-tools { justify-content: flex-start; gap: 5px; margin-top: 5px; flex-wrap: wrap; }
            .product-image-tools button { padding: 4px 7px; font-size: .68rem; }
            #materials .material-row { grid-template-columns: repeat(2, minmax(0, 1fr)); grid-template-areas: 'material material' 'quantity quantity' 'unit cost' 'remove remove'; gap: 8px; }
            #materials .material-field-unit, #materials .material-field-cost { min-width: 0; }
            #materials .remove-material { grid-area: remove; justify-self: end; width: 40px; height: 40px; }
            .labor-fields { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
            .labor-fields > label { min-width: 0; overflow-wrap: anywhere; }
            .expense-row, #overheads .expense-row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(90px, .55fr) 38px; align-items: end; gap: 7px; }
            .expense-row > label { min-width: 0; overflow-wrap: anywhere; }
            .expense-row .remove-button, #overheads .expense-row .remove-button { grid-column: auto; width: 38px; height: 42px; align-self: end; }
            /* Uniform, compact mobile controls across all four steps. Keep 16px for iOS Safari. */
            .calculator-wrap input:not([type="file"]):not([type="checkbox"]):not([type="radio"]):not([type="hidden"]),
            .calculator-wrap select,
            .calculator-wrap textarea {
                box-sizing: border-box;
                width: 100%;
                min-width: 0;
                height: 46px;
                min-height: 46px;
                max-height: 46px;
                margin-top: 6px;
                padding: 0 12px;
                border-radius: 8px;
                font-size: 16px !important;
                font-weight: 400;
                line-height: normal;
            }
            .calculator-wrap textarea {
                height: auto;
                min-height: 46px;
                max-height: none;
                padding: 11px 12px;
                resize: vertical;
            }
            .calculator-wrap select { padding-right: 34px; }
            .calculator-wrap .material-row, .calculator-wrap .expense-row { min-width: 0; }
            .calculator-wrap .material-row > label, .calculator-wrap .expense-row > label { min-width: 0; }
            .calculator-layout { grid-template-columns: minmax(0, 1fr); }
            .cost-sidebar { position: static; order: 2; }
            .calculator-workspace { order: 1; }
        }
        @media (max-width: 360px) {
            .heading-row h1 { font-size: 1.16rem; }
            .expense-row, #overheads .expense-row { grid-template-columns: minmax(0, 1fr) 90px; }
            .expense-row .remove-button, #overheads .expense-row .remove-button { grid-column: 2; justify-self: end; }
        }
        /* Match the CTA buttons already used in Seller Products. */
        .heading-actions .shortcut-button,
        .calculator-wrap .nav-button.next,
        .calculator-wrap .add-button:not(.secondary-add),
        .calculator-wrap .market-more-button {
            border-color: var(--orange-dark);
            background: var(--cta-gradient);
            color: #fff;
            box-shadow: 0 7px 16px rgba(234,138,58,.18);
        }
        .heading-actions .shortcut-button:hover:not(:disabled),
        .calculator-wrap .nav-button.next:hover:not(:disabled),
        .calculator-wrap .add-button:not(.secondary-add):hover:not(:disabled),
        .calculator-wrap .market-more-button:hover:not(:disabled) {
            border-color: #b85e1d;
            background: var(--cta-hover);
            color: #fff;
        }
        .calculator-wrap .margin-preset.active {
            border-color: var(--orange-dark);
            background: var(--cta-gradient);
            color: #fff;
        }
    </style>
</head>
<body>
<div class="page">
    <?php require __DIR__ . '/seller_nav.php'; ?>
    <main class="content pricing-main">
        <?php require __DIR__ . '/seller_search.php'; ?>
        <div class="calculator-wrap">
            <div id="product-image-lightbox" class="product-image-lightbox" hidden role="dialog" aria-modal="true" aria-label="Product image preview"><button type="button" id="product-image-lightbox-close" aria-label="Close image preview">×</button><img id="product-image-lightbox-img" alt="Full product image"></div>
            <div class="heading-row">
                <h1>Costing &amp; Pricing</h1>
                <div class="heading-actions">
                    <button class="shortcut-button" id="saved-calculations-button" type="button" aria-label="Saved Calculations" title="Saved Calculations"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg><span class="shortcut-text">Saved Calculations</span></button>
                    <button class="shortcut-button" id="materials-library-button" type="button" aria-label="Materials Library" title="Materials Library"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5a2 2 0 0 1 2-2h12v17H6a2 2 0 0 1 0-4h12"/><path d="M4 5v13a2 2 0 0 0 2 2M8 8h6"/></svg><span class="shortcut-text">Materials Library</span></button>
                </div>
            </div>
            <?php if ($pageError !== ''): ?>
                <div class="page-error"><?= htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <div class="calculator-layout">
                <div class="calculator-workspace">
                    <div class="progress-card" aria-label="Pricing progress">
                        <div class="progress-top">
                            <span>Step <strong id="step-number">1</strong> of 4</span>
                            <div class="progress-track"><div class="progress-fill" id="progress-fill"></div></div>
                            <span class="progress-percent" id="progress-percent">25% Complete</span>
                        </div>
                        <div class="step-status" id="step-status">Add the product details first</div>
                        <nav class="step-path" aria-label="Costing steps">
                            <button type="button" class="step-path-item active" data-path-step="1" aria-current="step"><b>1</b> Product</button>
                            <button type="button" class="step-path-item" data-path-step="2" disabled><b>2</b> Direct Costs</button>
                            <button type="button" class="step-path-item" data-path-step="3" disabled><b>3</b> Indirect Costs</button>
                            <button type="button" class="step-path-item" data-path-step="4" disabled><b>4</b> Profit &amp; Market</button>
                        </nav>
                    </div>
                    <div class="calculator-card">
                        <section class="step active" data-step="1">
                            <div class="step-kicker">STEP 1 · PRODUCT DETAILS</div>
                            <h2>Tell us what product you are costing</h2>
                            <p class="step-help">Start with the product information. LokalCraft will use these details later for the market comparison.</p>
                            <div class="step-error-summary" data-step-error-summary="1"></div>
                            <div class="product-grid">
                                <div class="image-upload">
                                    <div class="label-with-tip">
                                        <strong style="font-size:.75rem">Product Image</strong>
                                        <span class="info-tip-wrap">
                                            <button class="info-tip" type="button" aria-label="Product image information">i</button>
                                            <span class="tooltip-box">Upload a clear JPG, PNG, or WEBP image up to 5 MB. This image will also appear in your market comparison.</span>
                                        </span>
                                    </div>
                                    <input id="product-image" type="file" accept="image/jpeg,image/png,image/webp" aria-label="Choose product image" />
                                    <label for="product-image" id="product-image-chooser" class="product-image-chooser" tabindex="0">
                                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4m-4 4 4-4 4 4M4 16v4h16v-4"/></svg>
                                        <strong>Choose Image</strong>
                                        <small>JPG, PNG, WEBP · Max 5 MB</small>
                                    </label>
                                    <div class="product-image-selected" id="product-image-selected" role="button" tabindex="0" aria-label="View uploaded product image" hidden>
                                        <button type="button" class="image-preview" id="image-preview" aria-label="View full product image" disabled></button>
                                        <div class="product-image-meta">
                                            <strong class="product-image-name" id="product-image-name"></strong>
                                            <small class="product-image-size" id="product-image-size"></small>
                                            <div class="product-image-tools">
                                                <button type="button" id="product-image-view">View</button>
                                                <button type="button" id="product-image-change">Change</button>
                                                <button type="button" id="product-image-remove">Remove</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <label>
                                    <span class="label-with-tip">Product Name
                                        <span class="info-tip-wrap">
                                            <button class="info-tip" type="button" aria-label="Product name information">i</button>
                                            <span class="tooltip-box">Use a clear product name such as Crochet Tulip Bouquet, Beaded Bracelet, Woven Basket, or Wooden Tray.</span>
                                        </span>
                                    </span>
                                    <input id="product-title" type="text" maxlength="255" placeholder="e.g. Crochet Tulip Bouquet" />
                                </label>
                                <label>
                                    <span class="label-with-tip">Craft Category
                                        <span class="info-tip-wrap">
                                            <button class="info-tip" type="button" aria-label="Craft category information">i</button>
                                            <span class="tooltip-box">Choose the category that best matches the product so the market comparison can avoid unrelated items.</span>
                                        </span>
                                    </span>
                                    <select id="craft-category">
                                        <option value="">Select category</option>
                                        <?php foreach ($craftCategories as $category): ?>
                                            <option value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </div>
                            <div class="formula-note">
                                <strong>Next:</strong> calculate the costs directly connected to making one finished product.
                            </div>
                        </section>
                        <section class="step" data-step="2">
                            <div class="step-kicker">STEP 2 · DIRECT COSTS</div>
                            <h2>Calculate the materials, labor, and packaging</h2>
                            <p class="step-help small-help">Materials, time, and packaging for one product. <span class="info-tip-wrap"><button class="info-tip" type="button" aria-label="About direct costs">i</button><span class="tooltip-box">Add the materials and costs used to make one finished item.</span></span></p>
                            <div class="step-error-summary" data-step-error-summary="2"></div>
                            <div class="section-heading compact-heading">
                                <h3 class="label-with-tip">A. Direct Materials
                                    <span class="info-tip-wrap">
                                        <button class="info-tip" type="button" aria-label="Direct materials information">i</button>
                                        <span class="tooltip-box">Direct materials are the physical materials used to make the product, such as yarn, beads, fabric, wood, rattan, clay, or other craft supplies.</span>
                                    </span>
                                </h3>
                                <div class="material-actions">
                                    <button class="add-button" id="add-material" type="button">+ Add Material</button>
                                </div>
                            </div>
                            <?php if (count($savedMaterials) === 0): ?>
                                <div class="library-empty" id="library-empty-message" role="status" aria-live="polite">
                                    <strong>No saved materials yet.</strong>
                                    <span>Add at least one material in Materials Library first. Then return here to calculate the direct material cost for your product.</span>
                                    <button type="button" id="empty-add-material">Open Materials Library</button>
                                </div>
                            <?php endif; ?>
                            <div id="materials"></div>
                            <div class="direct-cost-divider"></div>
                            <div class="cost-input-grid">
                                <article class="cost-input-card labor-card">
                                    <div class="cost-input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></div>
                                    <div>
                                        <h3 class="label-with-tip">B. Labor <span class="info-tip-wrap"><button class="info-tip" type="button" aria-label="About labor">i</button><span class="tooltip-box">Time spent × your hourly rate = labor cost.</span></span></h3>
                                    </div>
                                    <div class="labor-fields">
                                        <label>
                                            Hours Spent
                                            <input id="labor-hours" type="number" min="0" step="0.1" placeholder="0.0" />
                                            <small>How long you spent making this product</small>
                                        </label>
                                        <label>
                                            Your Hourly Rate (₱)
                                            <input id="hourly-rate" type="number" min="0" step="0.1" placeholder="0.0" />
                                            <small>What you want to earn per hour</small>
                                        </label>
                                    </div>
                                </article>
                                <article class="cost-input-card">
                                    <div class="cost-input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 3 8l9 5 9-5-9-5ZM3 8v9l9 5 9-5V8M12 13v9"/></svg></div>
                                    <div>
                                        <h3 class="label-with-tip">C. Packaging <span class="info-tip-wrap"><button class="info-tip" type="button" aria-label="About packaging">i</button><span class="tooltip-box">Cost of wrapping or packing one product.</span></span></h3>
                                    </div>
                                    <label class="full-card-field">
                                        Packaging Cost (₱)
                                        <input id="packaging-cost" type="number" min="0" step="0.01" placeholder="e.g. 25" />
                                        <small>Box, pouch, ribbon, label, sticker, paper bag, tape, or other packaging used for one unit.</small>
                                    </label>
                                </article>
                            </div>
                        </section>
                        <section class="step" data-step="3">
                            <div class="step-kicker">STEP 3 · INDIRECT COSTS</div>
                            <h2>Add the indirect costs that support production</h2>
                            <p class="step-help small-help">Extra business costs per product. <span class="info-tip-wrap"><button class="info-tip" type="button" aria-label="About indirect costs">i</button><span class="tooltip-box">Enter only the share of each business expense for one handmade item, not the entire monthly bill.</span></span></p>
                            <div class="step-error-summary" data-step-error-summary="3"></div>
                            <div class="indirect-guide">
                                <div>
                                    <strong>Common craft expenses</strong>
                                </div>
                                <div class="indirect-chips">
                                    <button type="button" class="indirect-chip" data-overhead-preset="Electricity">Electricity</button>
                                    <button type="button" class="indirect-chip" data-overhead-preset="Internet">Internet</button>
                                    <button type="button" class="indirect-chip" data-overhead-preset="Tool Wear">Tool Wear</button>
                                    <button type="button" class="indirect-chip" data-overhead-preset="Workspace Rent">Workspace Rent</button>
                                    <button type="button" class="indirect-chip" data-overhead-preset="Machine Maintenance">Machine Maintenance</button>
                                    <button type="button" class="indirect-chip" data-overhead-preset="Online Shop Ads">Online Ads</button>
                                </div>
                            </div>
                            <div class="section-heading compact-heading">
                                <h3 class="label-with-tip">Indirect Costs (Overheads)
                                    <span class="info-tip-wrap">
                                        <button class="info-tip" type="button" aria-label="Indirect costs information">i</button>
                                        <span class="tooltip-box">Enter the share of each indirect expense that you want to assign to one product. The seller decides which expenses apply and how much should be allocated.</span>
                                    </span>
                                </h3>
                                <button class="add-button" id="add-overhead" type="button">+ Add Custom Expense</button>
                            </div>
                            <div id="overheads"></div>
                            <div class="formula-note">
                                <strong>Tip:</strong> Do not include the entire monthly bill when costing a single product. Only allocate a reasonable portion of the monthly expense that is attributable to that product.
                            </div>
                        </section>
                        <section class="step" data-step="4">
                            <div class="step-kicker">STEP 4 · PROFIT &amp; MARKET COMPARISON</div>
                            <h2>Set your desired profit margin, then compare</h2>
                            <p class="step-help small-help">Set profit and compare prices. <span class="info-tip-wrap"><button class="info-tip" type="button" aria-label="About price comparison">i</button><span class="tooltip-box">Your costs and profit set the suggested price. Similar listings are for comparison only.</span></span></p>
                            <div class="step-error-summary" data-step-error-summary="4"></div>
                            <div class="profit-margin-card">
                                <div>
                                    <h3 class="label-with-tip">Desired Profit Margin (%) <span class="info-tip-wrap"><button class="info-tip" type="button" aria-label="About profit margin">i</button><span class="tooltip-box">Your profit as a percentage of the selling price. No platform fee is added here.</span></span></h3>
                                </div>
                                <label>
                                    Desired Profit Margin
                                    <div class="percentage-input">
                                        <input id="profit-margin" type="number" min="0" max="90" step="1" placeholder="e.g. 30" />
                                        <span>%</span>
                                    </div>
                                    <small>Choose a percentage based on your costs, effort, product quality, and target earnings.</small>
                                </label>
                                <div class="margin-presets" aria-label="Quick profit margin choices">
                                    <button type="button" class="margin-preset" data-margin="20">20%</button>
                                    <button type="button" class="margin-preset" data-margin="30">30%</button>
                                    <button type="button" class="margin-preset" data-margin="40">40%</button>
                                </div>
                            </div>
                            <div class="market-box smart-market-box" id="market-box">
                                <div class="smart-market-head">
                                    <div class="smart-market-brand">
                                        <div class="smart-orb" aria-hidden="true">
                                            <span></span><span></span><span></span>
                                        </div>
                                        <div>
                                            <div class="market-title">
                                                Market Comparison
                                                <span class="info-tip-wrap market-info-tip">
                                                    <button class="info-tip" type="button" aria-label="About market comparison">i</button>
                                                    <span class="tooltip-box">Compare with similar listings as a guide. Your costing determines your suggested price. <span id="market-context">Matches use images, product names, and categories when available.</span></span>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="market-state" id="market-state">
                                    Market comparison will begin automatically on this step.
                                </div>
                                <div class="market-overview" id="market-overview">
<div class="market-own-card">
                                        <div class="market-own-image" id="market-own-image" aria-hidden="true">Your photo</div>
                                        <div class="market-own-copy">
                                            <span class="market-own-eyebrow">YOUR PRODUCT</span>
                                            <strong class="market-own-name" id="market-own-name">Your Product</strong>
                                            <span class="market-own-category" id="market-own-category"></span>
                                        </div>
                                        <div class="market-own-price-block">
                                            <span>Suggested Price</span>
                                            <strong id="market-your-price">₱0.00</strong>
                                        </div>
                                    </div>
                                </div>
                                <div class="market-list-head" id="market-list-head" hidden>
                                    <div>
                                        <h3>Similar Products</h3>
                                        <span id="market-list-count" class="market-list-count"></span>
                                    </div>
                                    <div class="market-range" id="market-range" aria-label="Competitor price range">
                                        <span class="market-range-label">Competitor Price Range</span>
                                        <strong class="market-range-price" id="market-range-price">—</strong>
                                    </div>
                                </div>
                                <div class="market-results market-similar-results" id="market-results"></div>
                                <div class="market-load-more" id="market-load-more" hidden>
                                    <span id="market-visible-count" role="status" aria-live="polite"></span>
                                    <button class="market-more-button" id="market-see-more" type="button">See More</button>
                                </div>
                            </div>
                        </section>
                        <div class="navigation">
                            <button class="nav-button" id="back-button" type="button" disabled>← Back</button>
                            <button class="nav-button next" id="next-button" type="button">Next →</button>
                        </div>
                        <div class="save-status" id="save-status" aria-live="polite"></div>
                    </div>
                </div>
                <aside class="cost-sidebar" aria-label="Cost breakdown">
                    <div class="cost-sidebar-card">
                        <div class="cost-sidebar-kicker">COST BREAKDOWN</div>
                        <div class="cost-sidebar-title label-with-tip">Suggested Selling Price <span class="info-tip-wrap"><button class="info-tip" type="button" aria-label="About price calculation">i</button><span class="tooltip-box">Total cost plus your desired profit. Optional add-ons are priced separately.</span></span></div>
                        <div class="cost-sidebar-price" id="sidebar-price">₱0.00</div>
                        <div class="cost-sidebar-rule"></div>
                        <div class="cost-sidebar-section-label">Direct Costs</div>
                        <div class="cost-sidebar-row">
                            <span>Materials</span>
                            <strong id="sidebar-material">₱0.00</strong>
                        </div>
                        <div class="cost-sidebar-row">
                            <span>Labor</span>
                            <strong id="sidebar-labor">₱0.00</strong>
                        </div>
                        <div class="cost-sidebar-row">
                            <span>Packaging</span>
                            <strong id="sidebar-packaging">₱0.00</strong>
                        </div>
                        <div class="cost-sidebar-row subtotal">
                            <span>Total Direct Cost</span>
                            <strong id="sidebar-direct">₱0.00</strong>
                        </div>
                        <div class="cost-sidebar-section-label">Indirect Costs</div>
                        <div class="cost-sidebar-row">
                            <span>Overhead</span>
                            <strong id="sidebar-overhead">₱0.00</strong>
                        </div>
                        <div class="cost-sidebar-rule"></div>
                        <div class="cost-sidebar-section-label">Price Summary</div>
                        <div class="cost-sidebar-row total">
                            <span>Total Cost</span>
                            <strong id="sidebar-total-cost">₱0.00</strong>
                        </div>
                        <div class="cost-sidebar-row">
                            <span>Profit Margin</span>
                            <strong id="sidebar-margin">0%</strong>
                        </div>
                        <div class="cost-sidebar-row pricing-profit-row">
                            <span>Profit</span>
                            <strong id="sidebar-profit">₱0.00</strong>
                        </div>
                        <div class="cost-sidebar-row platform-fee-row">
                            <span class="label-with-tip">Estimated Platform Fee (5%) <span class="info-tip-wrap"><button class="info-tip" type="button" aria-label="About estimated platform fee">i</button><span class="tooltip-box">Estimated 5% of your suggested selling price. This fee is not included in your costing formula.</span></span></span>
                            <strong id="sidebar-platform-fee">−₱0.00</strong>
                        </div>
                        <div class="cost-sidebar-row seller-payout-row">
                            <span>Estimated Amount You'll Receive</span>
                            <strong id="sidebar-seller-payout">₱0.00</strong>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </main>
</div>
<div class="market-detail-modal" id="market-detail-modal" aria-hidden="true">
    <div class="market-detail-backdrop" id="market-detail-backdrop"></div>
    <div
        class="market-detail-panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="market-detail-title"
    >
        <button
            class="market-detail-close"
            id="market-detail-close"
            type="button"
            aria-label="Close product details"
        >
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
        <div class="market-detail-grid">
            <div class="market-detail-image-wrap">
                <img
                    id="market-detail-image"
                    class="market-detail-image"
                    src=""
                    alt=""
                >
                <div
                    class="market-detail-image-empty"
                    id="market-detail-image-empty"
                >
                    No product image available
                </div>
            </div>
            <div class="market-detail-content">
                <span
                    class="market-detail-source"
                    id="market-detail-source"
                >
                    Marketplace
                </span>
                <h2 id="market-detail-title">
                    Similar Product
                </h2>
                <div
                    class="market-detail-price"
                    id="market-detail-price"
                >
                    ₱0.00
                </div>
                <div class="market-detail-category-row">
                    <span class="market-detail-category-label">Category</span>
                    <strong class="market-detail-category" id="market-detail-category">Category unavailable</strong>
                </div>
                <div class="market-detail-reviews" id="market-detail-reviews" aria-label="Product rating and reviews">
                    <span class="market-review-stars" aria-hidden="true">★★★★★<span class="market-review-stars-fill" id="market-review-stars-fill">★★★★★</span></span>
                    <span class="market-review-score" id="market-review-score">Not rated</span>
                    <span class="market-review-count" id="market-review-count">(0) reviews</span>
                </div>
                <div class="market-detail-meta" id="market-detail-meta"></div>
                <a class="market-photo-view" id="market-listing-link" target="_blank" rel="noopener noreferrer" hidden>View Listing</a>
                <div class="market-detail-comparison">
                    <span>Price Difference</span>
                    <strong id="market-detail-comparison-text">
                        —
                    </strong>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="market-lightbox" id="market-lightbox" aria-hidden="true">
    <button type="button" class="market-lightbox-close" id="market-lightbox-close" aria-label="Close full image"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    <img id="market-lightbox-image" alt="Full product image">
</div>
<div class="costing-saved-toast guide-popup" id="costing-saved-toast" role="status" aria-live="polite" hidden>
    <div class="guide-popup-copy"><strong>Calculation saved!</strong><span>Use the highlighted Saved Calculations button to find this product costing anytime.</span></div>
    <a href="saved_calculation.php">View Saved Calculations</a>
    <button id="costing-toast-close" type="button" aria-label="Close notification"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
</div>
<div class="materials-library-guide guide-popup" id="materials-library-guide" role="status" aria-live="polite" hidden>
    <div class="guide-popup-copy"><strong>Materials Library needed</strong><span id="materials-library-guide-message">Save at least one material in Materials Library first, then return here to add it to your product costing.</span></div>
    <a href="saved_materials.php">Open Materials Library</a>
    <button id="materials-library-guide-close" type="button" aria-label="Close Materials Library notice"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
</div>
<div class="floating-tooltip" id="floating-tooltip" role="tooltip" aria-hidden="true"></div>
<script>
(() => {
    // Same automatic clearance approach as Seller Dashboard.
    // The shared Seller Search bar is fixed; the Calculator itself stays in page flow.
    const pricingMain = document.querySelector('main.pricing-main');
    const sellerSearchHeader = pricingMain?.querySelector('.seller-header-tools');
    function syncPricingHeaderSpace() {
        if (!pricingMain) return;
        const header = sellerSearchHeader || document.querySelector('.seller-header-tools');
        const rect = header?.getBoundingClientRect();
        const visible = header && getComputedStyle(header).display !== 'none' && rect?.height > 0;
        const fallback = window.innerWidth <= 760 ? 76 : 70;
        const headerBottom = visible ? Math.max(0, rect.bottom) : fallback;
        pricingMain.style.setProperty('--pricing-header-bottom', `${Math.ceil(headerBottom)}px`);
    }
    syncPricingHeaderSpace();
    window.addEventListener('load', syncPricingHeaderSpace);
    window.addEventListener('resize', syncPricingHeaderSpace);
    if (sellerSearchHeader && typeof ResizeObserver !== 'undefined') {
        const headerResizeObserver = new ResizeObserver(syncPricingHeaderSpace);
        headerResizeObserver.observe(sellerSearchHeader);
    }
    const savedMaterials = <?= json_encode($savedMaterials, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const csrfToken = <?= json_encode($csrfToken) ?>;
    const sellerDraftId = <?= (int)$sellerId ?>;
    const materialMap = new Map(savedMaterials.map(material => [String(material.material_id), material]));
    const stepNames = {
        1: 'Add the product details first',
        2: 'Calculate direct materials, direct labor, and packaging',
        3: 'Add the indirect costs (overheads) that support production',
        4: 'Set the desired profit margin and review the market comparison'
    };
    const MIN_STEP = 1;
    const MAX_STEP = 4;
    const stepPathButtons = [...document.querySelectorAll('.step-path-item')];
    let currentStep = MIN_STEP;
    let highestVisitedStep = MIN_STEP;
    let lastSavedCostingId = null;
    let previewUrl = '';
    let marketComparison = { key: '', state: 'idle', items: [], method: 'none' };
    let marketRequestController = null;
    let marketRefreshTimer = null;
    let marketComparisonLoading = false;
    let latestPricing = { suggestedPrice: 0 };
    const ESTIMATED_PLATFORM_FEE_RATE = 0.05;
    const formatMoney = value => `₱${Number(value || 0).toFixed(2)}`;
    const roundMoney = value => Math.round((Number(value) + Number.EPSILON) * 100) / 100;
    const getNumber = selector => Math.max(0, Number(document.querySelector(selector)?.value || 0));
    const getProfitMargin = () => Math.min(
        89.99,
        Math.max(0, Number(document.getElementById('profit-margin')?.value || 0))
    );
    function infoLabel(text, tooltip) {
        return `<span class="label-with-tip">${text}<span class="info-tip-wrap"><button class="info-tip" type="button" aria-label="${text} information">i</button><span class="tooltip-box">${tooltip}</span></span></span>`;
    }
    function materialOptions(selected = '') {
        const options = ['<option value="">Select saved material</option>'];
        savedMaterials.forEach(material => {
            const isSelected = String(material.material_id) === String(selected) ? ' selected' : '';
            options.push(`<option value="${material.material_id}"${isSelected}>${escapeHtml(material.material_name)}</option>`);
        });
        return options.join('');
    }
    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
    }
    function marketIdentityKey() {
        const title = document.getElementById('product-title').value.trim().toLowerCase();
        const category = document.getElementById('craft-category').value.trim().toLowerCase();
        const file = document.getElementById('product-image').files[0];
        const imageKey = file ? `${file.name}|${file.size}|${file.lastModified}` : 'no-image';
        return `${title}|${category}|${imageKey}`;
    }
    function setMarketState(message, type = '') {
        const state = document.getElementById('market-state');
        const box = document.getElementById('market-box');
        state.textContent = message;
        state.className = `market-state ${type}`.trim();
        box?.classList.toggle('analyzing', type === 'loading');
    }
    const MARKET_PAGE_SIZE = 5;
    let marketVisibleCount = MARKET_PAGE_SIZE;
    function clearMarketDisplay() {
        document.getElementById('market-overview').classList.remove('show');
        document.getElementById('market-results').replaceChildren();
        document.getElementById('market-list-head').hidden = true;
        document.getElementById('market-load-more').hidden = true;
        marketVisibleCount = MARKET_PAGE_SIZE;
        const context = document.getElementById('market-context');
        if (context) context.textContent = 'Matches use images, product names, and categories when available.';
    }
    function median(values) {
        if (!values.length) return 0;
        const sorted = [...values].sort((a, b) => a - b);
        const middle = Math.floor(sorted.length / 2);
        return sorted.length % 2
            ? sorted[middle]
            : (sorted[middle - 1] + sorted[middle]) / 2;
    }
    function marketMethodLabel(method) {
        if (method === 'image_first') return 'Image-assisted comparison';
        if (method === 'text_fallback') return 'Text-based fallback comparison';
        return 'Available listing comparison';
    }
    function updateMarketOverview(items = marketComparison.items) {
        const overview = document.getElementById('market-overview');
        if (!Array.isArray(items) || !items.length) {
            overview.classList.remove('show');
            return;
        }
        const comparablePrices = items
            .map(item => Number(item.price))
            .filter(price => Number.isFinite(price) && price > 0);
        const rangePrice = document.getElementById('market-range-price');
        rangePrice.textContent = comparablePrices.length
            ? `${formatMoney(Math.min(...comparablePrices))} – ${formatMoney(Math.max(...comparablePrices))}`
            : 'Not available';
        const ownTitle = document.getElementById('product-title').value.trim() || 'Your Product';
        document.getElementById('market-own-name').textContent = ownTitle;
        document.getElementById('market-own-category').textContent =
            document.getElementById('craft-category').selectedOptions[0]?.textContent?.trim() || '';
        document.getElementById('market-your-price').textContent =
            formatMoney(Number(latestPricing.suggestedPrice || 0));
        const ownImage = document.getElementById('market-own-image');
        ownImage.replaceChildren();
        if (previewUrl) {
            const img = document.createElement('img');
            img.src = previewUrl;
            img.alt = '';
            ownImage.appendChild(img);
        } else {
            ownImage.textContent = 'Your photo';
        }
        overview.classList.add('show');
    }
    const marketDetailModal = document.getElementById('market-detail-modal');
    const marketDetailImage = document.getElementById('market-detail-image');
    const marketDetailImageEmpty = document.getElementById('market-detail-image-empty');
    const marketDetailSource = document.getElementById('market-detail-source');
    const marketDetailTitle = document.getElementById('market-detail-title');
    const marketDetailPrice = document.getElementById('market-detail-price');
    const marketDetailCategory = document.getElementById('market-detail-category');
    const marketReviewStarsFill = document.getElementById('market-review-stars-fill');
    const marketReviewScore = document.getElementById('market-review-score');
    const marketReviewCount = document.getElementById('market-review-count');
    const marketDetailMeta = document.getElementById('market-detail-meta');
    const marketDetailComparisonText = document.getElementById('market-detail-comparison-text');
    function closeMarketDetail() {
        if (!marketDetailModal) return;
        marketDetailModal.classList.remove('open');
        marketDetailModal.setAttribute('aria-hidden', 'true');
        if (marketDetailImage) {
            marketDetailImage.classList.remove('visible');
            marketDetailImage.removeAttribute('src');
            marketDetailImage.alt = '';
        }
        if (marketDetailImageEmpty) {
            marketDetailImageEmpty.style.display = 'block';
        }
    }
    function openMarketDetail(item) {
        if (!marketDetailModal || !item) return;
        const ownPrice = Number(latestPricing.suggestedPrice || 0);
        const rivalPrice = Number(item.price || 0);
        const difference = Math.abs(ownPrice - rivalPrice);
        marketDetailSource.textContent = item.source_name || 'Marketplace';
        marketDetailTitle.textContent = item.product_name || 'Similar Product';
        marketDetailPrice.textContent = formatMoney(rivalPrice);
        const meta = [];
        const matchPercent = Number(
            item.match_percent
            ?? (Number(item.match_score || 0) * 100)
        );
        if (Number.isFinite(matchPercent) && matchPercent > 0) {
            meta.push(`${Math.round(matchPercent)}% match`);
        }
        // Display only the category actually returned for this competitor.
        // Never substitute the seller's chosen category for a missing product field.
        const productCategory = String(
            item.craft_category ?? item.category_name ?? item.category ?? ''
        ).trim();
        marketDetailCategory.textContent = productCategory || 'Category unavailable';
        const rawRating = Number(item.rating);
        const rating = Number.isFinite(rawRating) ? Math.max(0, Math.min(5, rawRating)) : 0;
        const rawCount = Number(item.review_count);
        const reviewCount = Number.isFinite(rawCount) ? Math.max(0, Math.floor(rawCount)) : 0;
        // Unknown/no-review products must not look like a verified five-star product.
        const hasReviews = reviewCount > 0 && rating > 0;
        marketReviewStarsFill.style.width = hasReviews ? `${rating / 5 * 100}%` : '0%';
        marketReviewScore.textContent = hasReviews ? `${rating.toFixed(1)} / 5` : 'Not rated';
        marketReviewCount.textContent = `(${reviewCount}) review${reviewCount === 1 ? '' : 's'}`;
        const saveCount = Number(item.save_count || 0);
        if (saveCount > 0) {
            meta.push(`${saveCount} save${saveCount === 1 ? '' : 's'}`);
        }
        const sold = Number(item.sold_count ?? item.sales_count ?? 0);
        if (Number.isFinite(sold) && sold > 0) meta.push(`${sold} sold`);
        marketDetailMeta.textContent = meta.length
            ? meta.join(' · ')
            : '';
        if (ownPrice < rivalPrice) {
            marketDetailComparisonText.textContent =
                `Your suggested price is ${formatMoney(difference)} lower than this listing.`;
        } else if (ownPrice > rivalPrice) {
            marketDetailComparisonText.textContent =
                `Your suggested price is ${formatMoney(difference)} higher than this listing.`;
        } else {
            marketDetailComparisonText.textContent =
                'Your suggested price matches this listing.';
        }
        if (item.image_url) {
            marketDetailImage.src = item.image_url;
            marketDetailImage.alt = item.product_name || 'Similar product';
            marketDetailImage.classList.add('visible');
            marketDetailImageEmpty.style.display = 'none';
            marketDetailImage.onerror = () => {
                marketDetailImage.classList.remove('visible');
                marketDetailImage.removeAttribute('src');
                marketDetailImage.tabIndex = -1;
                marketDetailImage.removeAttribute('role');
                marketDetailImage.removeAttribute('aria-label');
                marketDetailImageEmpty.style.display = 'block';
            };
        } else {
            marketDetailImage.classList.remove('visible');
            marketDetailImage.removeAttribute('src');
            marketDetailImageEmpty.style.display = 'block';
        }
        // The product image itself opens the full-size image.
        const imageAvailable = Boolean(item.image_url);
        marketDetailImage.tabIndex = imageAvailable ? 0 : -1;
        if (imageAvailable) {
            marketDetailImage.setAttribute('role', 'button');
            marketDetailImage.setAttribute('aria-label', 'Enlarge product image');
        } else {
            marketDetailImage.removeAttribute('role');
            marketDetailImage.removeAttribute('aria-label');
        }
        const listingLink = document.getElementById('market-listing-link');
        listingLink.hidden = true;
        listingLink.removeAttribute('href');
        if (item.product_url) {
            try {
                const url = new URL(String(item.product_url), window.location.href);
                if (url.protocol === 'https:' || url.protocol === 'http:') {
                    listingLink.href = url.href;
                    listingLink.hidden = false;
                }
            } catch (_) {}
        }
        marketDetailModal.classList.add('open');
        marketDetailModal.setAttribute('aria-hidden', 'false');
        document.getElementById('market-detail-close')?.focus();
    }
    function marketMatchPercent(item) {
        const raw = item.match_percent ?? item.match_percentage ?? item.match_score ?? null;
        if (raw === null || raw === '' || !Number.isFinite(Number(raw))) return null;
        const value = Number(raw);
        // A 0–1 score is a fraction; a value above 1 is already a percentage.
        return Math.max(0, Math.min(100, Math.round(value > 0 && value <= 1 ? value * 100 : value)));
    }
    function renderMarketCards(items) {
        const container = document.getElementById('market-results');
        container.replaceChildren();
        const listHead = document.getElementById('market-list-head');
        const loadMore = document.getElementById('market-load-more');
        const results = Array.isArray(items) ? items : [];
        listHead.hidden = results.length === 0;
        loadMore.hidden = results.length === 0;
        if (!results.length) return;

        const shown = results.slice(0, marketVisibleCount);
        document.getElementById('market-list-count').textContent =
            `${results.length} comparable listing${results.length === 1 ? '' : 's'}`;
        shown.forEach(item => {
            const price = Number(item.price || 0);
            const matchPercent = marketMatchPercent(item);
            const name = String(item.product_name || 'Similar product');
            const source = String(item.source_name || 'Marketplace');
            const card = document.createElement('button');
            card.type = 'button';
            card.className = 'market-similar-card';
            card.setAttribute('aria-label', `View details for ${name}`);
            const image = item.image_url
                ? `<img src="${escapeHtml(item.image_url)}" alt="" loading="lazy">`
                : '<span>No image</span>';
            const match = matchPercent === null
                ? '<span class="market-match-unknown">Similar listing</span>'
                : `<span class="market-match-pill">${matchPercent}% match</span>`;
            card.innerHTML = `
                <span class="market-similar-image">${image}</span>
                <span class="market-similar-copy">
                    <strong class="market-similar-name">${escapeHtml(name)}</strong>
                    <span class="market-similar-source">${escapeHtml(source)}</span>
                    ${match}
                </span>
                <span class="market-similar-right">
                    <strong class="market-similar-price">${formatMoney(price)}</strong>
                    <span class="market-similar-view">View details <svg aria-hidden="true" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg></span>
                </span>
            `;
            card.addEventListener('click', () => openMarketDetail(item));
            container.appendChild(card);
        });
        document.getElementById('market-visible-count').textContent =
            `Showing ${shown.length} of ${results.length}`;
        const button = document.getElementById('market-see-more');
        const remaining = results.length - shown.length;
        button.hidden = remaining <= 0;
        button.textContent = remaining > 0
            ? `See More (${Math.min(MARKET_PAGE_SIZE, remaining)} more)`
            : 'See More';
    }
    document.getElementById('market-see-more').addEventListener('click', () => {
        if (marketComparison.state !== 'results') return;
        marketVisibleCount += MARKET_PAGE_SIZE;
        renderMarketCards(marketComparison.items);
    });
    function renderMarketComparison(data) {
        clearMarketDisplay();
        const method = String(data?.comparison_method || 'none');
        const context = document.getElementById('market-context');
        if (data.state === 'results' && Array.isArray(data.items) && data.items.length > 0) {
            marketComparison = {
                key: marketIdentityKey(),
                state: 'results',
                items: data.items,
                method
            };
            marketVisibleCount = MARKET_PAGE_SIZE;
            setMarketState(`Analysis complete — ${data.items.length} comparable listing${data.items.length === 1 ? '' : 's'} found.`);
            if (context) {
                const categoryLabel = String(
                    data.category
                    || document.getElementById('craft-category').value
                    || ''
                ).trim();
                const scope = String(data.source_scope || '').trim();
                const parts = [
                    marketMethodLabel(method),
                    categoryLabel ? `Category: ${categoryLabel}` : '',
                    `${data.items.length} listing${data.items.length === 1 ? '' : 's'} used`,
                    scope
                ].filter(Boolean);
                context.textContent = parts.join(' · ');
            }
            renderMarketCards(data.items);
            updateMarketOverview(data.items);
            return;
        }
        if (data.state === 'no_matches') {
            marketComparison = { key: marketIdentityKey(), state: 'no_matches', items: [], method };
            setMarketState('Analysis completed, but no sufficiently similar listings were found.');
            if (context) {
                const categoryLabel = String(
                    data.category
                    || document.getElementById('craft-category').value
                    || ''
                ).trim();
                context.textContent = `${marketMethodLabel(method)}${categoryLabel ? ` · Category: ${categoryLabel}` : ''} · Your cost-based suggested price is still available for saving.`;
            }
            return;
        }
        marketComparison = { key: marketIdentityKey(), state: 'unavailable', items: [], method: 'none' };
        setMarketState(
            'Market comparison is temporarily unavailable. You can still review your cost-based suggested price and save the calculation.',
            'error'
        );
    }
    function invalidateMarketComparison() {
        marketComparison = { key: '', state: 'idle', items: [], method: 'none' };
        clearMarketDisplay();
        if (marketRequestController) {
            marketRequestController.abort();
            marketRequestController = null;
        }
        if (marketRefreshTimer) {
            window.clearTimeout(marketRefreshTimer);
            marketRefreshTimer = null;
        }
        if (currentStep === 4) {
            marketRefreshTimer = window.setTimeout(() => loadMarketComparison(true), 500);
        }
    }
    async function loadMarketComparison(force = false) {
        if (currentStep !== 4) return;
        const title = document.getElementById('product-title').value.trim();
        const category = document.getElementById('craft-category').value;
        const image = document.getElementById('product-image').files[0];
        const key = marketIdentityKey();
        if (!title || !category || !image) {
            clearMarketDisplay();
            setMarketState('Complete the product image, product name, and craft category to start market comparison.');
            return;
        }
        if (!force && marketComparison.key === key && ['results', 'no_matches'].includes(marketComparison.state)) {
            if (marketComparison.state === 'results') {
                renderMarketCards(marketComparison.items);
                updateMarketOverview(marketComparison.items);
            }
            return;
        }
        if (marketRequestController) {
            marketRequestController.abort();
        }
        marketRequestController = new AbortController();
        marketComparisonLoading = true;
        document.getElementById('next-button').disabled = true;
        clearMarketDisplay();
        setMarketState('Analyzing your product details and available comparable listings...', 'loading');
        try {
            const form = new FormData();
            form.append('csrf_token', csrfToken);
            form.append('product_name', title);
            form.append('craft_category', category);
            form.append('suggested_price', String(calculate().suggestedPrice));
            form.append('product_image', image);
            const response = await fetch('market_compare.php', {
                method: 'POST',
                body: form,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: marketRequestController.signal
            });
            const rawResponse = await response.text();
            let data;
            try {
                data = JSON.parse(rawResponse);
            } catch (parseError) {
                throw new Error('Market comparison returned an invalid server response. Please try again.');
            }
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Market comparison could not be completed.');
            }
            renderMarketComparison(data);
        } catch (error) {
            if (error.name !== 'AbortError') {
                marketComparison = { key, state: 'unavailable', items: [], method: 'none' };
                clearMarketDisplay();
                setMarketState(error.message || 'Market comparison is temporarily unavailable. You can still save your cost-based calculation.', 'error');
            }
        } finally {
            marketComparisonLoading = false;
            if (currentStep === 4 && !lastSavedCostingId) {
                document.getElementById('next-button').disabled = false;
            }
            marketRequestController = null;
        }
    }
    const floatingTooltip = document.getElementById('floating-tooltip');
    let activeTooltipWrap = null;
    let tooltipPinned = false;
    function addMaterialRow({ focus = true, atTop = true } = {}) {
        if (savedMaterials.length === 0) {
            if (focus && !restoringDraft && !discardingDraft) {
                promptMaterialsLibrary(
                    'Your Materials Library is empty. Save at least one material there first, then return here to select it for this product.'
                );
            }
            return null;
        }
        const row = document.createElement('div');
        row.className = 'material-row';
        if (focus) row.classList.add('row-added');
        row.innerHTML = `
            <label class="material-field material-field-select">
                <span class="material-field-title">
                    ${infoLabel('Material Name', 'Choose a material from your library.')}
                </span>
                <select class="material-select">${materialOptions()}</select>
            </label>
            <label class="material-field material-field-quantity">
                <span class="material-field-title">
                    ${infoLabel('Quantity Used', 'Enter only the amount used to make this one product, using the saved material unit.')}
                </span>
                <input class="material-quantity" type="number" min="0" step="0.01" placeholder="e.g. 1" />
            </label>
            <label class="material-field material-field-unit">
                <span class="material-field-title">Unit</span>
                <input class="material-unit" type="text" value="—" readonly />
            </label>
            <label class="material-field material-field-cost">
                <span class="material-field-title">Material Cost</span>
                <input class="material-cost" type="text" value="₱0.00" readonly />
            </label>
            <button class="remove-button remove-material" type="button" aria-label="Remove material"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
        `;
        const list = document.getElementById('materials');
        if (atTop) list.prepend(row); else list.appendChild(row);
        bindTooltips(row);
        calculate();
        if (focus) { row.querySelector('.material-select')?.focus({ preventScroll: true }); row.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
        queueDraftSave();
        return row;
    }
    function addExpenseRow(containerId, type, presetName = '') {
        const row = document.createElement('div');
        row.className = 'expense-row row-added';
        row.innerHTML = `
            <label>Indirect Expense Name
                <input class="overhead-name" type="text" value="${escapeHtml(presetName)}" placeholder="e.g. Electricity, maintenance, admin cost" />
            </label>
            <label>Allocated Cost per Product (₱)
                <input class="overhead-cost" type="number" min="0" step="0.01" placeholder="e.g. 25" />
            </label>
            <button class="remove-button remove-expense" type="button" aria-label="Remove indirect expense"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
        `;
        document.getElementById(containerId).prepend(row);
        calculate();
        queueDraftSave();
        const costInput = row.querySelector('.overhead-cost');
        if (presetName && costInput) costInput.focus({ preventScroll: true });
        else row.querySelector('.overhead-name')?.focus({ preventScroll: true });
        row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    function calculate() {
        let materialTotal = 0;
        document.querySelectorAll('.material-row').forEach(row => {
            const material = materialMap.get(row.querySelector('.material-select')?.value || '');
            const quantityUsed = Math.max(0, Number(row.querySelector('.material-quantity')?.value || 0));
            const unitField = row.querySelector('.material-unit');
            const costField = row.querySelector('.material-cost');
            if (!material) {
                unitField.value = '—';
                costField.value = formatMoney(0);
                return;
            }
            const referenceQuantity = Math.max(0, Number(material.reference_quantity || 0));
            const referencePrice = Math.max(0, Number(material.reference_price || 0));
            const unitCost = referenceQuantity > 0 ? referencePrice / referenceQuantity : 0;
            const rowCost = roundMoney(quantityUsed * unitCost);
            materialTotal += rowCost;
            unitField.value = material.unit || '—';
            costField.value = formatMoney(rowCost);
        });
        const laborHours = getNumber('#labor-hours');
        const hourlyRate = getNumber('#hourly-rate');
        const laborCost = roundMoney(laborHours * hourlyRate);
        const packagingCost = roundMoney(getNumber('#packaging-cost'));
        const directCost = roundMoney(materialTotal + laborCost + packagingCost);
        let overheadCost = 0;
        document.querySelectorAll('.overhead-cost').forEach(input => {
            overheadCost += roundMoney(Math.max(0, Number(input.value || 0)));
        });
        const totalCost = roundMoney(directCost + overheadCost);
        const profitMargin = getProfitMargin();
        const marginDecimal = profitMargin / 100;
        const pricingDenominator = 1 - marginDecimal;
        const suggestedPrice = totalCost > 0 ? roundMoney(totalCost / pricingDenominator) : 0;
        const profitAmount = roundMoney(Math.max(0, suggestedPrice - totalCost));
        // Informational only: this estimate does not change the saved costing formula.
        const estimatedPlatformFee = roundMoney(suggestedPrice * ESTIMATED_PLATFORM_FEE_RATE);
        const estimatedSellerPayout = roundMoney(suggestedPrice - estimatedPlatformFee);
        latestPricing = {
            materialTotal,
            laborHours,
            hourlyRate,
            laborCost,
            packagingCost,
            directCost,
            overheadCost,
            totalCost,
            profitMargin,
            profitAmount,
            suggestedPrice
        };
        const setMoney = (id, value) => {
            const element = document.getElementById(id);
            if (element) element.textContent = formatMoney(value);
        };
        setMoney('sidebar-material', materialTotal);
        setMoney('sidebar-labor', laborCost);
        setMoney('sidebar-packaging', packagingCost);
        setMoney('sidebar-direct', directCost);
        setMoney('sidebar-overhead', overheadCost);
        setMoney('sidebar-total-cost', totalCost);
        setMoney('sidebar-profit', profitAmount);
        setMoney('sidebar-price', suggestedPrice);
        const sidebarPlatformFee = document.getElementById('sidebar-platform-fee');
        if (sidebarPlatformFee) sidebarPlatformFee.textContent = `−${formatMoney(estimatedPlatformFee)}`;
        setMoney('sidebar-seller-payout', estimatedSellerPayout);
        const sidebarMargin = document.getElementById('sidebar-margin');
        if (sidebarMargin) sidebarMargin.textContent = `${profitMargin.toFixed(0)}%`;
        document.querySelectorAll('.margin-preset').forEach(button => {
            button.classList.toggle(
                'active',
                Number(button.dataset.margin) === Number(profitMargin)
            );
        });
        if (currentStep === 4 && marketComparison.state === 'results' && marketComparison.items.length > 0) {
            updateMarketOverview(marketComparison.items);
            renderMarketCards(marketComparison.items);
        }
        return {
            materialTotal,
            laborHours,
            hourlyRate,
            laborCost,
            packagingCost,
            directCost,
            overheadCost,
            totalCost,
            profitMargin,
            profitAmount,
            suggestedPrice
        };
    }
    function normalizeStep(step) {
        const number = Number(step);
        return Number.isFinite(number)
            ? Math.max(MIN_STEP, Math.min(MAX_STEP, Math.floor(number)))
            : MIN_STEP;
    }
    function updateStepPath() {
        stepPathButtons.forEach(button => {
            const step = Number(button.dataset.pathStep);
            const active = step === currentStep;
            const unlocked = step <= highestVisitedStep;
            button.disabled = !unlocked;
            button.classList.toggle('active', active);
            if (active) button.setAttribute('aria-current', 'step');
            else button.removeAttribute('aria-current');
        });
    }
    // Forward progress is unlocked only by Next after validating the current step.
    // Visiting an earlier step never locks steps that were already reached.
    function showStep(step, { unlock = false } = {}) {
        const destination = normalizeStep(step);
        if (unlock) highestVisitedStep = Math.max(highestVisitedStep, destination);
        if (destination > highestVisitedStep) return false;

        clearStepErrors(currentStep);
        currentStep = destination;
        document.querySelectorAll('.step').forEach(section => {
            section.classList.toggle('active', Number(section.dataset.step) === currentStep);
        });
        updateStepPath();
        const progress = currentStep * 25;
        document.getElementById('progress-fill').style.width = `${progress}%`;
        document.getElementById('step-number').textContent = currentStep;
        document.getElementById('progress-percent').textContent = `${progress}% Complete`;
        document.getElementById('step-status').textContent = stepNames[currentStep];
        document.getElementById('back-button').disabled = currentStep === MIN_STEP;
        const nextButton = document.getElementById('next-button');
        nextButton.textContent = currentStep === MAX_STEP ? 'Save Calculation' : 'Next →';
        nextButton.disabled = currentStep === MAX_STEP ? marketComparisonLoading : false;
        showStatus('');
        calculate();
        if (currentStep === MAX_STEP) loadMarketComparison();
        return true;
    }
    function getErrorAnchor(target) {
        if (!target) return null;
        if (target.matches?.('#product-image')) {
            return target.closest('.image-upload') || target;
        }
        return target;
    }
    function clearFieldError(target) {
        if (!target) return;
        const anchor = getErrorAnchor(target);
        anchor?.classList.remove('field-error');
        target.classList?.remove('field-error');
        const key = target.id || target.dataset.validationKey;
        if (key) {
            document.querySelectorAll(`[data-field-error-for="${CSS.escape(key)}"]`).forEach(node => node.remove());
        }
    }
    function clearStepErrors(step = currentStep) {
        const section = document.querySelector(`.step[data-step="${step}"]`);
        if (!section) return;
        section.querySelectorAll('.field-error').forEach(node => {
            node.classList.remove('field-error');
        });
        section.querySelectorAll('.field-error-message').forEach(node => {
            node.remove();
        });
        const summary = section.querySelector('[data-step-error-summary]');
        if (summary) {
            summary.classList.remove('visible');
            summary.textContent = '';
        }
    }
    function showFieldError(target, message, options = {}) {
        if (!target) return false;
        const anchor = getErrorAnchor(target);
        const key = target.id || target.dataset.validationKey || `field-${Date.now()}`;
        target.dataset.validationKey = key;
        anchor?.classList.add('field-error');
        target.classList?.add('field-error');
        document.querySelectorAll(`[data-field-error-for="${CSS.escape(key)}"]`).forEach(node => node.remove());
        const error = document.createElement('div');
        error.className = 'field-error-message';
        error.dataset.fieldErrorFor = key;
        error.textContent = message;
        const insertAfter = options.insertAfter || anchor || target;
        insertAfter.insertAdjacentElement('afterend', error);
        if (options.summary !== false) {
            const section = target.closest('.step') || anchor?.closest('.step');
            const summary = section?.querySelector('[data-step-error-summary]');
            if (summary) {
                summary.textContent = 'Please check the highlighted field before continuing.';
                summary.classList.add('visible');
            }
        }
        if (options.focus !== false) {
            requestAnimationFrame(() => {
                const focusTarget = target.matches?.('input, select, textarea, button')
                    ? target
                    : target.querySelector?.('input, select, textarea, button');
                focusTarget?.focus({ preventScroll: true });
                (anchor || target).scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
            });
        }
        return false;
    }
    function markMaterialRowError(row, message, target = null) {
        if (!row) return false;
        row.dataset.validationKey = row.dataset.validationKey || `material-${Math.random().toString(36).slice(2)}`;
        row.classList.add('field-error');
        const key = row.dataset.validationKey;
        document.querySelectorAll(`[data-field-error-for="${CSS.escape(key)}"]`).forEach(node => node.remove());
        const error = document.createElement('div');
        error.className = 'field-error-message';
        error.dataset.fieldErrorFor = key;
        error.textContent = message;
        row.insertAdjacentElement('afterend', error);
        const summary = row.closest('.step')?.querySelector('[data-step-error-summary]');
        if (summary) {
            summary.textContent = 'Please complete the highlighted material row before continuing.';
            summary.classList.add('visible');
        }
        requestAnimationFrame(() => {
            (target || row.querySelector('select, input'))?.focus({ preventScroll: true });
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
        return false;
    }
    function showStatus(message, type = '') {
        const status = document.getElementById('save-status');
        if (!message) {
            status.className = 'save-status';
            status.textContent = '';
            return;
        }
        status.className = `save-status visible ${type}`.trim();
        status.textContent = message;
    }
    function validateCurrentStep() {
        clearStepErrors(currentStep);
        if (currentStep === 1) {
            const image = document.getElementById('product-image');
            const title = document.getElementById('product-title');
            const category = document.getElementById('craft-category');
            if (!image.files[0]) {
                return showFieldError(
                    image,
                    'Please upload a product image before continuing.'
                );
            }
            if (!title.value.trim()) {
                return showFieldError(
                    title,
                    'Enter the product name, for example: Crochet Tulip Bouquet.'
                );
            }
            if (!category.value) {
                return showFieldError(
                    category,
                    'Select the craft category that best matches this product.'
                );
            }
        }
        if (currentStep === 2) {
            if (savedMaterials.length === 0) {
                const message = 'Your Materials Library is empty. Open Materials Library and save at least one material before adding direct materials to this costing.';
                const emptyState = document.getElementById('library-empty-message');
                if (emptyState) {
                    clearFieldError(emptyState);
                    showFieldError(emptyState, message, { focus: false });
                } else {
                    showFieldError(
                        document.getElementById('materials-library-button'),
                        message,
                        { focus: false }
                    );
                }
                showMaterialsLibraryGuide(message);
                return false;
            }
            const rows = [...document.querySelectorAll('.material-row')];
            if (rows.length === 0) {
                const addButton = document.getElementById('add-material');
                return showFieldError(
                    addButton,
                    'Add at least one direct material used for this product.'
                );
            }
            for (const row of rows) {
                const select = row.querySelector('.material-select');
                const quantity = row.querySelector('.material-quantity');
                if (!select?.value) {
                    return markMaterialRowError(
                        row,
                        'Select a material for this row.',
                        select
                    );
                }
                if (Number(quantity?.value || 0) <= 0) {
                    return markMaterialRowError(
                        row,
                        'Enter how much of this material is used for one product.',
                        quantity
                    );
                }
            }
            const hours = document.getElementById('labor-hours');
            const rate = document.getElementById('hourly-rate');
            if (Number(hours.value || 0) <= 0) {
                return showFieldError(
                    hours,
                    'Enter the number of hours spent making one product.'
                );
            }
            if (Number(rate.value || 0) <= 0) {
                return showFieldError(
                    rate,
                    'Enter your hourly labor rate in pesos.'
                );
            }
        }
        if (currentStep === 3) {
            const expenseRows = [...document.querySelectorAll('#overheads .expense-row')];
            for (const row of expenseRows) {
                const name = row.querySelector('.overhead-name');
                const cost = row.querySelector('.overhead-cost');
                const hasName = Boolean(name?.value.trim());
                const hasCost = Number(cost?.value || 0) > 0;
                if (!hasName && hasCost) {
                    row.dataset.validationKey = row.dataset.validationKey || `overhead-${Math.random().toString(36).slice(2)}`;
                    return markMaterialRowError(
                        row,
                        'Enter the name of this indirect expense.',
                        name
                    );
                }
                if (hasName && !hasCost) {
                    row.dataset.validationKey = row.dataset.validationKey || `overhead-${Math.random().toString(36).slice(2)}`;
                    return markMaterialRowError(
                        row,
                        'Enter the amount allocated to one product, or remove this row.',
                        cost
                    );
                }
            }
        }
        if (currentStep === 4) {
            const marginInput = document.getElementById('profit-margin');
            const margin = getProfitMargin();
            if (margin <= 0) {
                return showFieldError(
                    marginInput,
                    'Enter your desired profit margin. You can type your own percentage or use a quick option.'
                );
            }
            if (Number(marginInput.value) >= 90) {
                return showFieldError(
                    marginInput,
                    'Choose a profit margin below 90%.'
                );
            }
        }
        return true;
    }
    function collectFormData() {
        const data = new FormData();
        data.append('action', 'save_costing');
        data.append('csrf_token', csrfToken);
        data.append('product_title', document.getElementById('product-title').value.trim());
        data.append('craft_category', document.getElementById('craft-category').value);
        const totals = calculate();
        data.append('labor_cost', String(totals.laborCost));
        data.append('packaging_cost', String(totals.packagingCost));
        data.append('profit_margin', String(totals.profitMargin));
        const image = document.getElementById('product-image').files[0];
        if (image) data.append('product_image', image);
        document.querySelectorAll('.material-row').forEach(row => {
            data.append('saved_material_id[]', row.querySelector('.material-select')?.value || '');
            data.append('material_quantity[]', row.querySelector('.material-quantity')?.value || '0');
        });
        document.querySelectorAll('#overheads .expense-row').forEach(row => {
            data.append('overhead_name[]', row.querySelector('.overhead-name')?.value.trim() || '');
            data.append('overhead_cost[]', row.querySelector('.overhead-cost')?.value || '0');
        });
        return data;
    }
    async function saveCalculation() {
        if (!validateEveryStep()) return;
        const button = document.getElementById('next-button');
        button.disabled = true;
        button.textContent = 'Saving...';
        showStatus('Saving costing to database...');
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: collectFormData(),
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();
            if (!data.success) throw new Error(data.message || 'Unable to save calculation.');
            lastSavedCostingId = data.costing_id;
            await clearDraft();
            resetCosting();
            showSavedToast();
        } catch (error) {
            showStatus(error.message || 'Unable to save calculation.', 'error');
            button.disabled = false;
            button.textContent = 'Save Calculation';
        }
    }
    // Keep unfinished costing only when visiting the two Costing & Pricing subpages.
    const draftKey = `lokalcraft.costing.draft.v2.${sellerDraftId}`;
    const imageKey = `costing-image-${sellerDraftId}`;
    const allowedReturnKey = `lokalcraft.costing.allowed-return.${sellerDraftId}`;
    const costingSubpages = new Set(['saved_calculation.php', 'saved_materials.php']);
    let draftTimer = null;
    let restoringDraft = false;
    let discardingDraft = false;
    let imageWrite = Promise.resolve();
    function pageName(url) {
        if (!url) return '';
        try {
            const parsed = new URL(url, window.location.href);
            const currentFolder = window.location.pathname.slice(0, window.location.pathname.lastIndexOf('/') + 1);
            const destinationFolder = parsed.pathname.slice(0, parsed.pathname.lastIndexOf('/') + 1);
            if (parsed.origin !== window.location.origin || destinationFolder !== currentFolder) return '';
            return decodeURIComponent(parsed.pathname.split('/').pop() || '').toLowerCase();
        } catch (_) { return ''; }
    }
    function setAllowedReturn(allowed) {
        try {
            if (allowed) sessionStorage.setItem(allowedReturnKey, '1');
            else sessionStorage.removeItem(allowedReturnKey);
        } catch (_) {}
    }
    function hasAllowedReturn() {
        try { return sessionStorage.getItem(allowedReturnKey) === '1'; }
        catch (_) { return false; }
    }
    function mayRestoreDraft() {
        const navigation = performance.getEntriesByType?.('navigation')?.[0];
        const navigationType = navigation?.type || '';
        // Refreshing the Calculator is not a change to another Seller module.
        if (navigationType === 'reload') return true;
        const referrer = pageName(document.referrer);
        if (costingSubpages.has(referrer) || referrer === 'calculator.php') return true;
        // Browser Back can reuse the Calculator's original referrer, not the subpage.
        if (navigationType === 'back_forward' && hasAllowedReturn()) return true;
        // No referrer can occur with browser privacy settings; require a safe-return marker.
        if (!document.referrer && hasAllowedReturn()) return true;
        return false;
    }
    function readStoredDraft() {
        // New drafts survive page changes; migrate existing same-tab drafts.
        try {
            const saved = localStorage.getItem(draftKey);
            if (saved) return saved;
        } catch (_) {}
        try { return sessionStorage.getItem(draftKey); } catch (_) { return null; }
    }
    function saveStoredDraft(json) {
        try {
            localStorage.setItem(draftKey, json);
            try { sessionStorage.removeItem(draftKey); } catch (_) {}
            return;
        } catch (_) {}
        try { sessionStorage.setItem(draftKey, json); } catch (error) {
            console.warn('Costing draft storage is unavailable:', error);
        }
    }
    function removeStoredDraft() {
        try { localStorage.removeItem(draftKey); } catch (_) {}
        try { sessionStorage.removeItem(draftKey); } catch (_) {}
    }
    const storedImageDb = () => new Promise((resolve, reject) => {
        if (!window.indexedDB) return resolve(null);
        const request = indexedDB.open('lokalcraft-costing-drafts', 1);
        request.onupgradeneeded = () => {
            if (!request.result.objectStoreNames.contains('images')) {
                request.result.createObjectStore('images');
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
        request.onblocked = () => reject(new Error('Image storage is busy.'));
    });
    function queueImageWrite(file) {
        imageWrite = Promise.resolve(imageWrite)
            .catch(() => {})
            .then(() => persistDraftImage(file));
    }
    async function persistDraftImage(file) {
        try {
            const db = await storedImageDb();
            if (!db) return;
            try {
                await new Promise((resolve, reject) => {
                    const transaction = db.transaction('images', 'readwrite');
                    const store = transaction.objectStore('images');
                    if (file) store.put(file, imageKey); else store.delete(imageKey);
                    transaction.oncomplete = resolve;
                    transaction.onerror = () => reject(transaction.error);
                    transaction.onabort = () => reject(transaction.error);
                });
            } finally { db.close(); }
        } catch (error) {
            console.warn('Image draft could not be stored:', error);
        }
    }
    async function restoreDraftImage() {
        try {
            const stored = readStoredDraft();
            if (!stored || discardingDraft) return;
            const draft = JSON.parse(stored);
            if (draft && draft.imagePresent === false) return;
            const db = await storedImageDb();
            if (!db) return;
            let file;
            try {
                file = await new Promise((resolve, reject) => {
                    const request = db.transaction('images', 'readonly').objectStore('images').get(imageKey);
                    request.onsuccess = () => resolve(request.result || null);
                    request.onerror = () => reject(request.error);
                });
            } finally { db.close(); }
            const input = document.getElementById('product-image');
            // Don't override a newer image the seller may have chosen.
            if (!file || !readStoredDraft() || input.files.length) return;
            const dt = new DataTransfer();
            dt.items.add(file instanceof File ? file : new File([file], 'product-image.jpg', { type: file.type || 'image/jpeg' }));
            input.files = dt.files;
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl = URL.createObjectURL(input.files[0]);
            document.getElementById('image-preview').innerHTML = `<img src="${previewUrl}" alt="Product preview">`;
            syncProductImageDisplay();
        } catch (error) {
            console.warn('Image draft could not be restored:', error);
        }
    }
    function writeDraft() {
        if (restoringDraft || discardingDraft || lastSavedCostingId) return;
        const materialTouched = [...document.querySelectorAll('#materials .material-row')].some(row =>
            Boolean(row.querySelector('.material-select')?.value)
            || Number(row.querySelector('.material-quantity')?.value || 0) > 0
        );
        const expenseTouched = [...document.querySelectorAll('#overheads .expense-row')].some(row =>
            Boolean(row.querySelector('.overhead-name')?.value.trim())
            || Number(row.querySelector('.overhead-cost')?.value || 0) > 0
        );
        const hasWork = Boolean(document.getElementById('product-title').value.trim())
            || Boolean(document.getElementById('craft-category').value)
            || Boolean(document.getElementById('product-image').files.length)
            || materialTouched || expenseTouched
            || ['labor-hours','hourly-rate','packaging-cost','profit-margin'].some(id =>
                Number(document.getElementById(id).value || 0) > 0
            );
        if (!hasWork) {
            removeStoredDraft();
            return;
        }
        const data = {
            version: 2,
            step: currentStep,
            highestVisitedStep,
            productTitle: document.getElementById('product-title').value,
            category: document.getElementById('craft-category').value,
            imagePresent: Boolean(document.getElementById('product-image').files.length),
            materials: [...document.querySelectorAll('#materials .material-row')].map(row => ({
                id: row.querySelector('.material-select')?.value || '',
                quantity: row.querySelector('.material-quantity')?.value ?? ''
            })),
            laborHours: document.getElementById('labor-hours').value,
            hourlyRate: document.getElementById('hourly-rate').value,
            packaging: document.getElementById('packaging-cost').value,
            margin: document.getElementById('profit-margin').value,
            overheads: [...document.querySelectorAll('#overheads .expense-row')].map(row => ({
                name: row.querySelector('.overhead-name')?.value || '',
                cost: row.querySelector('.overhead-cost')?.value ?? ''
            }))
        };
        saveStoredDraft(JSON.stringify(data));
    }
    function queueDraftSave() {
        if (restoringDraft || discardingDraft || lastSavedCostingId) return;
        window.clearTimeout(draftTimer);
        draftTimer = window.setTimeout(writeDraft, 180);
    }
    async function clearDraft() {
        window.clearTimeout(draftTimer);
        removeStoredDraft();
        setAllowedReturn(false);
        // Image storage is separate; don't block a successful calculation indefinitely.
        await Promise.race([
            Promise.resolve(imageWrite).catch(() => {}),
            new Promise(resolve => window.setTimeout(resolve, 900))
        ]);
        void persistDraftImage(null);
    }
    function discardCostingDraft() {
        discardingDraft = true;
        window.clearTimeout(draftTimer);
        removeStoredDraft();
        setAllowedReturn(false);
        // Serialize deletion after any pending image write to avoid leaving a stale photo.
        imageWrite = Promise.resolve(imageWrite)
            .catch(() => {})
            .then(() => persistDraftImage(null));
    }
    function handleNavigation(destination) {
        const destinationPage = pageName(destination);
        if (destinationPage === 'calculator.php') return;
        if (costingSubpages.has(destinationPage)) {
            setAllowedReturn(true);
            writeDraft();
        } else {
            discardCostingDraft();
        }
    }
    async function navigateWithDraft(destination) {
        handleNavigation(destination);
        // An unavailable/blocked image database must never trap the seller on this page.
        await Promise.race([
            Promise.resolve(imageWrite).catch(() => {}),
            new Promise(resolve => window.setTimeout(resolve, 700))
        ]);
        window.location.assign(destination);
    }
    function restoreDraft() {
        const raw = readStoredDraft();
        if (!raw) return false;
        let draft;
        try { draft = JSON.parse(raw); } catch (_) { return false; }
        if (!draft || draft.version !== 2) return false;
        restoringDraft = true;
        try {
            document.getElementById('product-title').value = draft.productTitle || '';
            document.getElementById('craft-category').value = draft.category || '';
            document.getElementById('labor-hours').value = Number(draft.laborHours || 0) === 0 ? '' : draft.laborHours;
            document.getElementById('hourly-rate').value = Number(draft.hourlyRate || 0) === 0 ? '' : draft.hourlyRate;
            document.getElementById('packaging-cost').value = Number(draft.packaging || 0) === 0 ? '' : draft.packaging;
            document.getElementById('profit-margin').value = Number(draft.margin || 0) === 0 ? '' : draft.margin;
            document.getElementById('materials').replaceChildren();
            for (const item of (Array.isArray(draft.materials) ? draft.materials : [])) {
                const row = addMaterialRow({ focus: false, atTop: false });
                if (!row) break;
                const select = row.querySelector('.material-select');
                select.value = materialMap.has(String(item.id)) ? String(item.id) : '';
                row.querySelector('.material-quantity').value = Number(item.quantity || 0) === 0 ? '' : item.quantity;
            }
            if (!document.querySelector('#materials .material-row') && savedMaterials.length) {
                addMaterialRow({ focus: false, atTop: false });
            }
            document.getElementById('overheads').replaceChildren();
            for (const item of (Array.isArray(draft.overheads) ? draft.overheads : [])) {
                addExpenseRow('overheads', 'overhead');
                const row = document.querySelector('#overheads .expense-row:last-child');
                row.querySelector('.overhead-name').value = item.name || '';
                row.querySelector('.overhead-cost').value = Number(item.cost || 0) === 0 ? '' : item.cost;
            }
            calculate();
            const restoredStep = normalizeStep(draft.step);
            highestVisitedStep = Math.max(
                restoredStep,
                normalizeStep(draft.highestVisitedStep ?? restoredStep)
            );
            showStep(restoredStep);
        } finally {
            restoringDraft = false;
        }
        // Restore the image independently so a slow IndexedDB cannot block the form.
        void restoreDraftImage();
        return true;
    }
    function resetCosting() {
        document.getElementById('product-title').value = '';
        document.getElementById('craft-category').value = '';
        document.getElementById('product-image').value = '';
        document.getElementById('image-preview').replaceChildren();
        syncProductImageDisplay();
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = '';
        for (const id of ['labor-hours','hourly-rate','packaging-cost','profit-margin']) {
            document.getElementById(id).value = '';
        }
        document.getElementById('materials').replaceChildren();
        if (savedMaterials.length) addMaterialRow({ focus: false, atTop: false });
        document.getElementById('overheads').replaceChildren();
        marketComparison = { key: '', state: 'idle', items: [], method: 'none' };
        lastSavedCostingId = null;
        highestVisitedStep = MIN_STEP;
        calculate();
        showStep(MIN_STEP);
        showStatus('');
    }
    function positionGuidePopup(popup, target) {
        if (!popup || popup.hidden || !target || !document.body.contains(target)) return;
        const viewportWidth = document.documentElement.clientWidth || window.innerWidth;
        const edge = 14;
        popup.style.width = `${Math.max(0, Math.min(380, viewportWidth - edge * 2))}px`;
        popup.style.left = '0px';
        popup.style.top = '0px';
        popup.style.right = 'auto';
        popup.style.bottom = 'auto';
        const targetRect = target.getBoundingClientRect();
        const popupRect = popup.getBoundingClientRect();
        let left = targetRect.left + targetRect.width / 2 - popupRect.width / 2;
        left = Math.max(edge, Math.min(left, viewportWidth - popupRect.width - edge));
        let top = targetRect.bottom + 13;
        let placement = 'below';
        if (top + popupRect.height > window.innerHeight - edge) {
            top = targetRect.top - popupRect.height - 13;
            placement = 'above';
        }
        top = Math.max(edge, Math.min(top, window.innerHeight - popupRect.height - edge));
        const arrowLeft = Math.max(18, Math.min(targetRect.left + targetRect.width / 2 - left, popupRect.width - 18));
        popup.style.setProperty('--guide-arrow-left', `${Math.round(arrowLeft)}px`);
        popup.style.left = `${Math.round(left)}px`;
        popup.style.top = `${Math.round(top)}px`;
        popup.classList.toggle('guide-below', placement === 'below');
        popup.classList.toggle('guide-above', placement === 'above');
    }
    function hideSavedToast() {
        const toast = document.getElementById('costing-saved-toast');
        toast.hidden = true;
        toast.classList.remove('guide-below', 'guide-above', 'guide-pop');
        ['left', 'top', 'right', 'bottom', 'width'].forEach(property => toast.style.removeProperty(property));
        document.getElementById('saved-calculations-button')?.classList.remove('saved-guide-highlight');
        window.clearTimeout(showSavedToast.timer);
    }
    function showSavedToast() {
        hideMaterialsLibraryGuide();
        const toast = document.getElementById('costing-saved-toast');
        const target = document.getElementById('saved-calculations-button');
        toast.hidden = false;
        target.classList.add('saved-guide-highlight');
        target.scrollIntoView({
            behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
            block: 'center',
            inline: 'nearest'
        });
        window.requestAnimationFrame(() => {
            positionGuidePopup(toast, target);
            toast.classList.remove('guide-pop');
            void toast.offsetWidth;
            toast.classList.add('guide-pop');
        });
        window.clearTimeout(showSavedToast.timer);
        showSavedToast.timer = window.setTimeout(hideSavedToast, 10000);
    }
    function hideMaterialsLibraryGuide() {
        const guide = document.getElementById('materials-library-guide');
        guide.hidden = true;
        guide.classList.remove('guide-below', 'guide-above', 'guide-pop');
        ['left', 'top', 'right', 'bottom', 'width'].forEach(property => guide.style.removeProperty(property));
        document.getElementById('materials-library-button')?.classList.remove('library-guide-highlight');
        window.clearTimeout(showMaterialsLibraryGuide.timer);
    }
    function showMaterialsLibraryGuide(message = 'Save at least one material in Materials Library first, then return here to add it to your product costing.') {
        hideSavedToast();
        const guide = document.getElementById('materials-library-guide');
        const target = document.getElementById('materials-library-button');
        document.getElementById('materials-library-guide-message').textContent = message;
        guide.hidden = false;
        target.classList.add('library-guide-highlight');
        target.scrollIntoView({
            behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
            block: 'center',
            inline: 'nearest'
        });
        window.requestAnimationFrame(() => {
            positionGuidePopup(guide, target);
            guide.classList.remove('guide-pop');
            void guide.offsetWidth;
            guide.classList.add('guide-pop');
        });
        window.clearTimeout(showMaterialsLibraryGuide.timer);
        showMaterialsLibraryGuide.timer = window.setTimeout(hideMaterialsLibraryGuide, 12000);
    }
    function promptMaterialsLibrary(message) {
        const emptyState = document.getElementById('library-empty-message');
        if (emptyState) {
            clearFieldError(emptyState);
            showFieldError(emptyState, message, { focus: false });
        }
        showMaterialsLibraryGuide(message);
    }
    function repositionVisibleGuides() {
        const savedToast = document.getElementById('costing-saved-toast');
        if (!savedToast.hidden) {
            positionGuidePopup(savedToast, document.getElementById('saved-calculations-button'));
        }
        const libraryGuide = document.getElementById('materials-library-guide');
        if (!libraryGuide.hidden) {
            positionGuidePopup(libraryGuide, document.getElementById('materials-library-button'));
        }
    }
    window.addEventListener('resize', repositionVisibleGuides);
    window.addEventListener('scroll', repositionVisibleGuides, { passive: true });
    // Re-check all visited steps before saving, including edits made after going back.
    function validateEveryStep() {
        const priorStep = currentStep;
        for (let step = 1; step <= 4; step++) {
            if (step !== currentStep) showStep(step);
            if (!validateCurrentStep()) return false;
        }
        if (currentStep !== priorStep) showStep(priorStep);
        return true;
    }
    function hideFloatingTooltip() {
        activeTooltipWrap = null;
        tooltipPinned = false;
        floatingTooltip.classList.remove('visible');
        floatingTooltip.setAttribute('aria-hidden', 'true');
        floatingTooltip.textContent = '';
        floatingTooltip.style.left = '-9999px';
        floatingTooltip.style.top = '-9999px';
    }
    function positionFloatingTooltip() {
        if (!activeTooltipWrap || !floatingTooltip.classList.contains('visible')) return;
        const button = activeTooltipWrap.querySelector('.info-tip');
        if (!button || !document.body.contains(button)) {
            hideFloatingTooltip();
            return;
        }
        const trigger = button.getBoundingClientRect();
        const viewportWidth = window.innerWidth;
        const viewportHeight = window.innerHeight;
        const gap = 9;
        const edge = 12;
        const rightSpace = Math.max(0, viewportWidth - edge - trigger.right - gap);
        const leftSpace = Math.max(0, trigger.left - gap - edge);
        const usableMin = viewportWidth <= 360 ? 128 : 145;
        let placement;
        let availableWidth;
        // Tooltips sit next to their own info button, not over the input or below it.
        if (rightSpace >= usableMin) {
            placement = 'right';
            availableWidth = rightSpace;
        } else if (leftSpace >= usableMin) {
            placement = 'left';
            availableWidth = leftSpace;
        } else {
            placement = rightSpace >= leftSpace ? 'right' : 'left';
            availableWidth = Math.max(rightSpace, leftSpace);
        }
        if (availableWidth < 110) {
            placement = trigger.top >= 90 ? 'top' : 'bottom';
            availableWidth = viewportWidth - edge * 2;
        }
        floatingTooltip.style.maxWidth = `${Math.min(260, availableWidth)}px`;
        floatingTooltip.style.left = '0px';
        floatingTooltip.style.top = '0px';
        const tip = floatingTooltip.getBoundingClientRect();
        let left = trigger.right + gap;
        let top = trigger.top + (trigger.height - tip.height) / 2;
        if (placement === 'left') {
            left = trigger.left - tip.width - gap;
        } else if (placement === 'top' || placement === 'bottom') {
            left = trigger.left + (trigger.width - tip.width) / 2;
            top = placement === 'top' ? trigger.top - tip.height - gap : trigger.bottom + gap;
        }
        left = Math.max(edge, Math.min(left, viewportWidth - tip.width - edge));
        top = Math.max(edge, Math.min(top, viewportHeight - tip.height - edge));
        floatingTooltip.style.left = `${Math.round(left)}px`;
        floatingTooltip.style.top = `${Math.round(top)}px`;
        floatingTooltip.dataset.placement = placement;
    }
    function showFloatingTooltip(wrap, pinned = false) {
        const source = wrap.querySelector('.tooltip-box');
        const message = source?.textContent?.trim();
        if (!message) return;
        activeTooltipWrap = wrap;
        tooltipPinned = pinned;
        floatingTooltip.textContent = message;
        floatingTooltip.setAttribute('aria-hidden', 'false');
        floatingTooltip.classList.add('visible');
        positionFloatingTooltip();
    }
    function bindTooltips(scope = document) {
        scope.querySelectorAll('.info-tip-wrap').forEach(wrap => {
            if (wrap.dataset.bound === '1') return;
            wrap.dataset.bound = '1';
            const button = wrap.querySelector('.info-tip');
            let timer = null;
            wrap.addEventListener('mouseenter', () => {
                if (tooltipPinned && activeTooltipWrap !== wrap) return;
                timer = window.setTimeout(() => showFloatingTooltip(wrap, false), 160);
            });
            wrap.addEventListener('mouseleave', () => {
                if (timer) window.clearTimeout(timer);
                timer = null;
                if (!tooltipPinned && activeTooltipWrap === wrap) hideFloatingTooltip();
            });
            button?.addEventListener('click', event => {
                event.preventDefault();
                event.stopPropagation();
                if (tooltipPinned && activeTooltipWrap === wrap) {
                    hideFloatingTooltip();
                    return;
                }
                showFloatingTooltip(wrap, true);
            });
        });
    }
    document.addEventListener('click', event => {
        if (!event.target.closest('.info-tip-wrap')) {
            hideFloatingTooltip();
        }
    });
    document.addEventListener('scroll', () => {
        if (activeTooltipWrap) positionFloatingTooltip();
    }, true);
    window.addEventListener('resize', () => {
        if (activeTooltipWrap) positionFloatingTooltip();
    });

    const imageInput = document.getElementById('product-image');
    const imagePreviewButton = document.getElementById('image-preview');
    const imageChooser = document.getElementById('product-image-chooser');
    const selectedImage = document.getElementById('product-image-selected');
    const imageLightbox = document.getElementById('product-image-lightbox');
    const imageLightboxImg = document.getElementById('product-image-lightbox-img');
    const imageLightboxClose = document.getElementById('product-image-lightbox-close');
    function syncProductImageDisplay() {
        const file = imageInput.files[0];
        const hasPreview = Boolean(file && imagePreviewButton.querySelector('img'));
        imageChooser.hidden = hasPreview;
        selectedImage.hidden = !hasPreview;
        imagePreviewButton.disabled = !hasPreview;
        document.getElementById('product-image-name').textContent = hasPreview ? file.name : '';
        document.getElementById('product-image-size').textContent = hasPreview
            ? (file.size < 1048576 ? `${Math.max(1, Math.round(file.size / 1024))} KB` : `${(file.size / 1048576).toFixed(2)} MB`)
            : '';
    }
    // Restore an IndexedDB draft image without changing the existing draft logic.
    new MutationObserver(syncProductImageDisplay).observe(imagePreviewButton, { childList: true, subtree: true });
    imageChooser.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            imageInput.click();
        }
    });
    function openProductImageLightbox() {
        const img = imagePreviewButton.querySelector('img');
        if (!img) return;
        imageLightboxImg.src = img.src;
        imageLightbox.hidden = false;
        imageLightboxClose.focus();
    }
    imagePreviewButton.addEventListener('click', openProductImageLightbox);
    selectedImage.addEventListener('click', event => {
        if (!event.target.closest('.product-image-tools, #image-preview')) openProductImageLightbox();
    });
    selectedImage.addEventListener('keydown', event => {
        if ((event.key === 'Enter' || event.key === ' ') && event.target === selectedImage) {
            event.preventDefault();
            openProductImageLightbox();
        }
    });
    document.getElementById('product-image-view').addEventListener('click', openProductImageLightbox);
    document.getElementById('product-image-change').addEventListener('click', () => imageInput.click());
    document.getElementById('product-image-remove').addEventListener('click', () => {
        imageInput.value = '';
        imagePreviewButton.replaceChildren();
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = '';
        syncProductImageDisplay();
        queueImageWrite(null);
        queueDraftSave();
        invalidateMarketComparison();
        clearFieldError(imageInput);
        showStatus('');
        imageChooser.focus();
    });
    function closeProductImageLightbox() { imageLightbox.hidden = true; imageLightboxImg.removeAttribute('src'); imagePreviewButton.focus(); }
    imageLightboxClose.addEventListener('click', closeProductImageLightbox);
    imageLightbox.addEventListener('click', event => { if (event.target === imageLightbox) closeProductImageLightbox(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && !imageLightbox.hidden) closeProductImageLightbox(); });
    imageInput.addEventListener('change', event => {
        const file = event.target.files[0];
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = '';
        imagePreviewButton.replaceChildren();
        syncProductImageDisplay();
        invalidateMarketComparison();
        clearFieldError(event.target);
        if (!file) { queueImageWrite(null); queueDraftSave(); return; }
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
            event.target.value = '';
            queueImageWrite(null);
            return showFieldError(event.target, 'Use a JPG, PNG, or WEBP image only.');
        }
        if (file.size > 5 * 1024 * 1024) {
            event.target.value = '';
            queueImageWrite(null);
            return showFieldError(event.target, 'The product image must be 5 MB or smaller.');
        }
        queueImageWrite(file);
        queueDraftSave();
        previewUrl = URL.createObjectURL(file);
        imagePreviewButton.innerHTML = `<img src="${previewUrl}" alt="Product preview" />`;
        syncProductImageDisplay();
        clearFieldError(event.target);
        showStatus('');
        invalidateMarketComparison();
    });
    document.getElementById('add-material').addEventListener('click', addMaterialRow);
    document.getElementById('add-overhead').addEventListener('click', () => addExpenseRow('overheads', 'overhead'));
    document.getElementById('materials').addEventListener('click', event => {
        const button = event.target.closest('.remove-material');
        if (!button) return;
        button.closest('.material-row')?.remove();
        calculate();
        queueDraftSave();
    });
    document.getElementById('overheads').addEventListener('click', event => {
        const button = event.target.closest('.remove-expense');
        if (!button) return;
        button.closest('.expense-row')?.remove();
        calculate();
        queueDraftSave();
    });
    document.querySelector('.calculator-card').addEventListener('input', () => { calculate(); queueDraftSave(); });
    document.querySelector('.calculator-card').addEventListener('change', () => { calculate(); queueDraftSave(); });
    document.getElementById('product-title').addEventListener('input', invalidateMarketComparison);
    document.getElementById('craft-category').addEventListener('change', invalidateMarketComparison);
    document.getElementById('product-title').addEventListener('input', event => {
        if (event.target.value.trim()) clearFieldError(event.target);
    });
    document.getElementById('craft-category').addEventListener('change', event => {
        if (event.target.value) clearFieldError(event.target);
    });
    document.getElementById('labor-hours').addEventListener('input', event => {
        if (Number(event.target.value || 0) > 0) clearFieldError(event.target);
    });
    document.getElementById('hourly-rate').addEventListener('input', event => {
        if (Number(event.target.value || 0) > 0) clearFieldError(event.target);
    });
    document.getElementById('profit-margin').addEventListener('input', event => {
        const value = Number(event.target.value || 0);
        if (value > 0 && value < 90) clearFieldError(event.target);
    });
    document.getElementById('materials').addEventListener('input', event => {
        const row = event.target.closest('.material-row');
        if (!row) return;
        const select = row.querySelector('.material-select');
        const quantity = row.querySelector('.material-quantity');
        if (select?.value && Number(quantity?.value || 0) > 0) {
            row.classList.remove('field-error');
            const key = row.dataset.validationKey;
            if (key) {
                document.querySelectorAll(`[data-field-error-for="${CSS.escape(key)}"]`).forEach(node => node.remove());
            }
        }
    });
    document.getElementById('materials').addEventListener('change', event => {
        const row = event.target.closest('.material-row');
        if (!row) return;
        const select = row.querySelector('.material-select');
        const quantity = row.querySelector('.material-quantity');
        if (select?.value && Number(quantity?.value || 0) > 0) {
            row.classList.remove('field-error');
            const key = row.dataset.validationKey;
            if (key) {
                document.querySelectorAll(`[data-field-error-for="${CSS.escape(key)}"]`).forEach(node => node.remove());
            }
        }
    });
    document.getElementById('overheads').addEventListener('input', event => {
        const row = event.target.closest('.expense-row');
        if (!row) return;
        const name = row.querySelector('.overhead-name')?.value.trim();
        const cost = Number(row.querySelector('.overhead-cost')?.value || 0);
        if ((!name && cost <= 0) || (name && cost > 0)) {
            row.classList.remove('field-error');
            const key = row.dataset.validationKey;
            if (key) {
                document.querySelectorAll(`[data-field-error-for="${CSS.escape(key)}"]`).forEach(node => node.remove());
            }
        }
    });
    const marketLightbox = document.getElementById('market-lightbox');
    const openMarketLightbox = () => {
        if (!marketDetailImage?.src || !marketDetailImage.classList.contains('visible')) return;
        document.getElementById('market-lightbox-image').src = marketDetailImage.src;
        marketLightbox.classList.add('open');
        marketLightbox.setAttribute('aria-hidden', 'false');
    };
    const closeMarketLightbox = () => {
        marketLightbox.classList.remove('open');
        marketLightbox.setAttribute('aria-hidden', 'true');
        document.getElementById('market-lightbox-image').removeAttribute('src');
    };
    marketDetailImage?.addEventListener('click', openMarketLightbox);
    marketDetailImage?.addEventListener('keydown', event => {
        if ((event.key === 'Enter' || event.key === ' ') && marketDetailImage.classList.contains('visible')) {
            event.preventDefault();
            openMarketLightbox();
        }
    });
    document.getElementById('market-lightbox-close')?.addEventListener('click', closeMarketLightbox);
    marketLightbox?.addEventListener('click', event => { if (event.target === marketLightbox) closeMarketLightbox(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && marketLightbox.classList.contains('open')) closeMarketLightbox(); });
    document.getElementById('market-detail-close')?.addEventListener('click', closeMarketDetail);
    document.getElementById('market-detail-backdrop')?.addEventListener('click', closeMarketDetail);
    document.addEventListener('keydown', event => {
        if (
            event.key === 'Escape'
            && !marketLightbox.classList.contains('open')
            && marketDetailModal?.classList.contains('open')
        ) {
            closeMarketDetail();
        }
    });
    document.getElementById('back-button').addEventListener('click', () => {
        if (currentStep > MIN_STEP && showStep(currentStep - 1)) queueDraftSave();
    });
    stepPathButtons.forEach(button => {
        button.addEventListener('click', () => {
            if (button.disabled) return;
            if (showStep(Number(button.dataset.pathStep))) queueDraftSave();
        });
    });
    document.getElementById('next-button').addEventListener('click', async () => {
        if (!validateCurrentStep()) return;
        if (currentStep < MAX_STEP) {
            showStep(currentStep + 1, { unlock: true });
            queueDraftSave();
            return;
        }
        await saveCalculation();
    });
    document.querySelectorAll('.indirect-chip').forEach(button => {
        button.addEventListener('click', () => {
            addExpenseRow(
                'overheads',
                'overhead',
                button.dataset.overheadPreset || ''
            );
        });
    });
    document.querySelectorAll('.margin-preset').forEach(button => {
        button.addEventListener('click', () => {
            const marginInput = document.getElementById('profit-margin');
            marginInput.value = button.dataset.margin || '0';
            calculate();
            queueDraftSave();
        });
    });
    document.getElementById('saved-calculations-button').addEventListener('click', () => navigateWithDraft('saved_calculation.php'));
    document.getElementById('materials-library-button').addEventListener('click', () => navigateWithDraft('saved_materials.php'));
    document.getElementById('empty-add-material')?.addEventListener('click', () => navigateWithDraft('saved_materials.php'));
    // Includes links from seller_nav.php and other shared Seller menus.
    document.addEventListener('click', event => {
        const link = event.target.closest?.('a[href]');
        if (!link || event.defaultPrevented || (event.button !== 0 && event.button !== undefined)) return;
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        if (link.target && link.target.toLowerCase() !== '_self') return;
        const url = new URL(link.href, window.location.href);
        if (url.origin === window.location.origin
            && url.pathname === window.location.pathname
            && url.search === window.location.search) return; // Same-page/hash link.
        handleNavigation(url.href);
    }, true);
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (form.target && form.target.toLowerCase() !== '_self') return;
        const url = new URL(form.action || window.location.href, window.location.href);
        if (url.pathname !== window.location.pathname || url.origin !== window.location.origin) {
            handleNavigation(url.href);
        }
    }, true);
    bindTooltips();
    window.addEventListener('pagehide', writeDraft);
    window.addEventListener('beforeunload', writeDraft);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') writeDraft();
    });
    window.addEventListener('pageshow', event => {
        // Browser Back may return a cached page with an outdated Materials Library.
        // Refresh PHP material data, then restore the saved costing draft.
        if (event.persisted) window.location.reload();
    });
    document.getElementById('costing-toast-close')?.addEventListener('click', hideSavedToast);
    document.getElementById('materials-library-guide-close')?.addEventListener('click', hideMaterialsLibraryGuide);
    restoringDraft = true;
    if (savedMaterials.length > 0) addMaterialRow({ focus: false });
    restoringDraft = false;
    calculate();
    showStep(1);
    if (mayRestoreDraft()) {
        restoreDraft();
    } else {
        // A visit from Dashboard, Orders, Products, etc. starts a fresh calculation.
        discardCostingDraft();
        // Allow this new Calculator session to save a fresh draft as the user works.
        discardingDraft = false;
    }
})();
</script>
</body>
</html>
