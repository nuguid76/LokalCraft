<?php
require_once __DIR__ . '/seller_auth.php';
$activePage = 'pricing';
if (empty($_SESSION['saved_costing_csrf'])) {
    $_SESSION['saved_costing_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['saved_costing_csrf'];
function jsonResponse(bool $success, string $message, array $extra = []): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra));
    exit;
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
function saveOptionalCostingImage(array $file, int $sellerId): string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The product image could not be uploaded. Please try again.');
    }
    if (!isset($file['size']) || !is_numeric($file['size']) || (int)$file['size'] < 1) {
        throw new RuntimeException('Please choose a valid product image.');
    }
    if ((int)$file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Product image must be 5 MB or smaller.');
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Invalid product image upload. Please try again.');
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
function deleteCostingImage(string $path): void
{
    $path = trim((string)$path);
    if (!preg_match('~\Auploads/costing/[A-Za-z0-9_-]+\.(?:jpg|png|webp)\z~i', $path)) {
        return;
    }
    $root = realpath(__DIR__ . '/../uploads/costing');
    if ($root === false) return;
    $fullPath = realpath($root . DIRECTORY_SEPARATOR . basename($path));
    if ($fullPath !== false && str_starts_with($fullPath, $root . DIRECTORY_SEPARATOR) && is_file($fullPath)) {
        @unlink($fullPath);
    }
}
function formatMoney($value): string
{
    return '₱' . number_format((float)$value, 2);
}
function formatCategory($category): string
{
    $category = trim((string)$category);
    if ($category === '') {
        return 'Uncategorized';
    }
    if (preg_match('/[A-Z&]/', $category)) {
        return $category;
    }
    return ucwords(str_replace(['_', '-'], ' ', $category));
}
function costingImageUrl(?string $path): string
{
    $path = trim((string)$path);
    return $path === '' ? '' : '../' . ltrim($path, '/');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_costing') {
    $newImagePath = '';
    $oldImagePath = '';
    try {
        $postedToken = $_POST['csrf_token'] ?? null;
        if (!is_string($postedToken) || !hash_equals((string)($_SESSION['saved_costing_csrf'] ?? ''), $postedToken)) {
            jsonResponse(false, 'Your session token expired. Please refresh the page and try again.');
        }
        $costingId = (int)($_POST['costing_id'] ?? 0);
        if ($costingId <= 0) {
            jsonResponse(false, 'Invalid costing record.');
        }
        $ownershipStmt = $conn->prepare(
            'SELECT costing_id, product_image
             FROM products_costing
             WHERE costing_id = ? AND seller_id = ?
             LIMIT 1'
        );
        $ownershipStmt->bind_param('ii', $costingId, $sellerId);
        $ownershipStmt->execute();
        $ownedCosting = $ownershipStmt->get_result()->fetch_assoc();
        $ownershipStmt->close();
        if (!$ownedCosting) {
            jsonResponse(false, 'This saved calculation was not found.');
        }
        $oldImagePath = (string)($ownedCosting['product_image'] ?? '');
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
        // Saved records store only the final labor total, not the original hour/rate inputs.
        // Use the same hours × hourly rate calculation as calculator.php on every update.
        $hoursRaw = $_POST['labor_hours'] ?? null;
        $rateRaw = $_POST['hourly_rate'] ?? null;
        // Both fields may be blank for a product with no labor charge.
        if (!is_scalar($hoursRaw) || !is_scalar($rateRaw)) {
            jsonResponse(false, 'Enter valid labor hours and hourly rate.');
        }
        $hoursText = trim((string)$hoursRaw);
        $rateText = trim((string)$rateRaw);
        if (($hoursText !== '' && !is_numeric($hoursText)) || ($rateText !== '' && !is_numeric($rateText))) {
            jsonResponse(false, 'Enter valid labor hours and hourly rate.');
        }
        $laborHours = $hoursText === '' ? 0.0 : (float)$hoursText;
        $hourlyRate = $rateText === '' ? 0.0 : (float)$rateText;
        if (!is_finite($laborHours) || !is_finite($hourlyRate) || $laborHours < 0 || $hourlyRate < 0) {
            jsonResponse(false, 'Labor hours and hourly rate must be zero or greater.');
        }
        if (($laborHours > 0 && $hourlyRate <= 0) || ($hourlyRate > 0 && $laborHours <= 0)) {
            jsonResponse(false, 'Enter both labor hours and your hourly rate.');
        }
        $laborCost = round($laborHours * $hourlyRate, 2);
        if (!is_finite($laborCost)) {
            jsonResponse(false, 'Labor cost is too large.');
        }
        $packagingCost = round(max(0, (float)($_POST['packaging_cost'] ?? 0)), 2);
        $rawProfitMargin = $_POST['profit_margin'] ?? null;
        if (!is_scalar($rawProfitMargin) || trim((string)$rawProfitMargin) === '' || !is_numeric($rawProfitMargin)) {
            jsonResponse(false, 'Enter your target profit margin.');
        }
        $profitMargin = (float)$rawProfitMargin;
        if (!is_finite($profitMargin) || $profitMargin < 0 || $profitMargin >= 90) {
            jsonResponse(false, 'Choose a target profit margin from 0% to below 90%.');
        }
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
                jsonResponse(false, 'One of the selected materials is no longer available in your Materials Library.');
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
            jsonResponse(false, 'Please keep at least one material in this calculation.');
        }
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
            if ($cost <= 0) {
                jsonResponse(false, 'Every indirect expense must have an allocated cost greater than 0.');
            }
            $overheads[] = ['name' => $name, 'cost' => $cost];
            $overheadCost += $cost;
        }
        // Same product costing formula as calculator.php; any order-level fees are separate.
        $totalCost = round($totalMaterialCost + $laborCost + $packagingCost + $otherDirectCost + $overheadCost, 2);
        $marginDecimal = $profitMargin / 100;
        $pricingDenominator = 1 - $marginDecimal;
        $suggestedPrice = $totalCost > 0 ? round($totalCost / $pricingDenominator, 2) : 0.0;
        $desiredProfit = round(max(0, $suggestedPrice - $totalCost), 2);
        $newImagePath = saveOptionalCostingImage($_FILES['product_image'] ?? [], $sellerId);
        $imageToSave = $newImagePath !== '' ? $newImagePath : $oldImagePath;
        $conn->begin_transaction();
        $updateStmt = $conn->prepare(
            'UPDATE products_costing
             SET product_title = ?, craft_category = ?, product_image = ?, labor_cost = ?, packaging_cost = ?, other_direct_cost = ?, overhead_cost = ?, desired_profit = ?, total_material_cost = ?, total_cost = ?, suggested_price = ?
             WHERE costing_id = ? AND seller_id = ?'
        );
        $updateStmt->bind_param(
            'sssddddddddii',
            $productTitle,
            $craftCategory,
            $imageToSave,
            $laborCost,
            $packagingCost,
            $otherDirectCost,
            $overheadCost,
            $desiredProfit,
            $totalMaterialCost,
            $totalCost,
            $suggestedPrice,
            $costingId,
            $sellerId
        );
        $updateStmt->execute();
        $updateStmt->close();
        $deleteMaterials = $conn->prepare('DELETE FROM costing_materials WHERE costing_id = ?');
        $deleteMaterials->bind_param('i', $costingId);
        $deleteMaterials->execute();
        $deleteMaterials->close();
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
        $deleteExpenses = $conn->prepare('DELETE FROM costing_overheads WHERE costing_id = ?');
        $deleteExpenses->bind_param('i', $costingId);
        $deleteExpenses->execute();
        $deleteExpenses->close();
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
        $conn->commit();
        if ($newImagePath !== '' && $oldImagePath !== '' && $oldImagePath !== $newImagePath) {
            deleteCostingImage($oldImagePath);
        }
        jsonResponse(true, 'Saved calculation updated successfully.', [
            'costing_id' => $costingId,
            'product_title' => $productTitle,
            'total_material_cost' => round($totalMaterialCost, 2),
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
        if ($newImagePath !== '') {
            deleteCostingImage($newImagePath);
        }
        if (!($e instanceof RuntimeException)) error_log('LokalCraft saved costing update error: ' . $e->getMessage());
        jsonResponse(false, $e instanceof RuntimeException ? $e->getMessage() : 'Unable to update the calculation right now. Please try again.');
    }
}
$costings = [];
$savedMaterials = [];
$craftCategories = [];
$errorMessage = '';
try {
    $craftCategories = loadCraftCategories($conn);
    $materialStmt = $conn->prepare(
        'SELECT material_id, material_name, reference_quantity, unit, reference_price
         FROM saved_materials
         WHERE seller_id = ?
         ORDER BY material_name ASC, material_id ASC'
    );
    $materialStmt->bind_param('i', $sellerId);
    $materialStmt->execute();
    $materialResult = $materialStmt->get_result();
    while ($row = $materialResult->fetch_assoc()) {
        $savedMaterials[] = $row;
    }
    $materialStmt->close();
    $stmt = $conn->prepare(
        'SELECT
            pc.costing_id,
            pc.product_title,
            pc.craft_category,
            pc.product_image,
            (
                SELECT p.product_photo
                FROM products p
                WHERE p.seller_id = pc.seller_id
                  AND LOWER(TRIM(p.product_name)) = LOWER(TRIM(pc.product_title))
                ORDER BY
                    CASE
                        WHEN LOWER(TRIM(p.craft_category)) = LOWER(TRIM(pc.craft_category)) THEN 0
                        ELSE 1
                    END,
                    p.product_id DESC
                LIMIT 1
            ) AS fallback_product_photo,
            pc.labor_cost,
            pc.packaging_cost,
            pc.other_direct_cost,
            pc.overhead_cost,
            pc.desired_profit,
            pc.total_material_cost,
            pc.total_cost,
            pc.suggested_price,
            pc.created_at
         FROM products_costing pc
         WHERE pc.seller_id = ?
         ORDER BY pc.created_at DESC, pc.costing_id DESC'
    );
    $stmt->bind_param('i', $sellerId);
    $stmt->execute();
    $result = $stmt->get_result();
    $materialDetailStmt = $conn->prepare(
        'SELECT saved_material_id, material_name, quantity, unit, unit_cost, total_cost
         FROM costing_materials
         WHERE costing_id = ?
         ORDER BY material_id ASC'
    );
    $expenseDetailStmt = $conn->prepare(
        'SELECT expense_type, overhead_name, cost
         FROM costing_overheads
         WHERE costing_id = ?
         ORDER BY overhead_id ASC'
    );
    while ($row = $result->fetch_assoc()) {
        $costingId = (int)$row['costing_id'];
        $row['materials'] = [];
        $row['overheads'] = [];
        $materialDetailStmt->bind_param('i', $costingId);
        $materialDetailStmt->execute();
        $materialsResult = $materialDetailStmt->get_result();
        while ($materialRow = $materialsResult->fetch_assoc()) {
            $row['materials'][] = $materialRow;
        }
        $expenseDetailStmt->bind_param('i', $costingId);
        $expenseDetailStmt->execute();
        $expenseResult = $expenseDetailStmt->get_result();
        while ($expenseRow = $expenseResult->fetch_assoc()) {
            $row['overheads'][] = $expenseRow;
        }
        $storedSuggestedPrice = (float)($row['suggested_price'] ?? 0);
        $storedDesiredProfit = (float)($row['desired_profit'] ?? 0);
        // The saved profit is net of any historical fee. Do not count an old fee as profit.
        // This also matches new no-fee costings: margin = desired_profit / selling_price.
        $row['profit_margin'] = $storedSuggestedPrice > 0
            ? round(max(0, min(89.99, ($storedDesiredProfit / $storedSuggestedPrice) * 100)), 2)
            : 0;
        $costings[] = $row;
    }
    $materialDetailStmt->close();
    $expenseDetailStmt->close();
    $stmt->close();
} catch (Throwable $e) {
    $errorMessage = 'Unable to load saved calculations.';
}
$savedMaterialsJson = json_encode($savedMaterials, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LokalCraft | Saved Calculations</title>
    <style>
        :root {
            --bg: #f3f3f3;
            --panel: #ffffff;
            --line: #dedad6;
            --text: #2d2d2d;
            --muted: #707070;
            --orange: #e68d2d;
            --orange-dark: #d7771a;
            --orange-soft: #fff0da;
            --green: #1b9a5e;
            --red: #c94141;
            --soft: #f9f8f7;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Segoe UI", Arial, sans-serif; background: var(--bg); color: var(--text); }
        body.modal-open { overflow: hidden; }
        .page {
            display: block !important;
            min-height: 100vh;
            background: #f5f3f1;
        }
        /* Use the document scroll, like the Seller Dashboard, instead of
           trapping this page in a fixed-position scrolling container. */
        body .page > main.content.saved-costing-main {
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
            padding: var(--saved-costing-header-bottom, 70px) 0 30px !important;
            overflow: visible !important;
            background: transparent !important;
        }
        .page-wrap {
            width: 100%;
            max-width: 1180px;
            min-width: 0;
            margin: 0 auto;
            padding: 8px 26px 20px;
        }
        .page-wrap :is(.costing-card, .back-button) {
            scroll-margin-top: calc(var(--saved-costing-header-bottom, 70px) + 16px);
        }
        .seller-header-tools .filter-box input,
        .seller-header-tools .filter-box select {
            width: 100%;
            min-width: 0;
            min-height: 0;
            height: auto;
            margin: 0;
            padding: 0;
            border: 0;
            border-radius: 0;
            background: transparent;
            box-shadow: none;
            font: inherit;
            outline: none;
        }
        .heading { display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; margin-bottom: 18px; }
        h1 { margin: 0; font-size: 2.1rem; font-weight: 800; letter-spacing: -0.7px; }
        /* Match the Seller Profile Edit Profile action. */
        .back-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            flex: 0 0 auto;
            min-height: 46px;
            padding: 11px 18px;
            border: 1px solid #e98736;
            border-radius: 13px;
            background: #ee8b39;
            color: #fff;
            font-size: .82rem;
            font-weight: 750;
            line-height: 1.2;
            text-decoration: none;
            white-space: nowrap;
            box-shadow: 0 7px 17px rgba(224, 119, 37, .12);
            transition: background .16s ease, box-shadow .16s ease;
        }
        .back-button:hover {
            border-color: #d97a2c;
            background: #dc7b2b;
            color: #fff;
            box-shadow: 0 8px 19px rgba(224, 119, 37, .18);
        }
        .back-button:focus-visible {
            outline: 3px solid #f9c89d;
            outline-offset: 3px;
        }
        .back-button svg {
            width: 18px;
            height: 18px;
            flex: 0 0 18px;
        }
        .toolbar { margin-bottom: 16px; }
        .count { color: var(--muted); font-size: .78rem; }
        .error-message { margin-bottom: 16px; padding: 11px 13px; border: 1px solid #efc0c0; border-radius: 8px; background: #fff3f3; color: var(--red); font-size: .78rem; }
        .costing-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; }
        .costing-card {
            display: flex; flex-direction: column; min-width: 0; background: rgba(255,255,255,.8);
            border: 1px solid #e8e4df; border-radius: 16px; overflow: hidden;
            box-shadow: 0 10px 20px rgba(18,13,9,.04); transition: transform .2s ease, box-shadow .2s ease;
            cursor: pointer;
        }
        .costing-card:hover { transform: translateY(-3px); box-shadow: 0 16px 24px rgba(18,13,9,.08); }
        .costing-card:focus-visible { outline: 3px solid rgba(230,141,45,.28); outline-offset: 2px; }
        .costing-image-wrap { position: relative; width: 100%; aspect-ratio: 1 / .82; background: #eae4df; overflow: hidden; }
        .costing-image { width: 100%; height: 100%; object-fit: cover; display: block; }
        .placeholder-image { width: 100%; height: 100%; display: grid; place-items: center; color: #97908a; background: linear-gradient(135deg,#ece7e2,#e2dbd4); font-size: 2rem; }
        .saved-tag { position: absolute; top: 10px; right: 10px; padding: 5px 8px; border-radius: 999px; background: rgba(255,255,255,.94); color: var(--green); font-size: .64rem; font-weight: 800; }
        .costing-body { padding: 14px 14px 15px; display: flex; flex-direction: column; flex: 1; }
        .category-badge { align-self: flex-start; display: inline-flex; padding: 5px 8px; border-radius: 999px; background: var(--orange-soft); color: #c86d1d; font-size: .65rem; font-weight: 800; margin-bottom: 8px; }
        .costing-name { font-size: 1rem; font-weight: 800; line-height: 1.3; margin-bottom: 7px; }
        .costing-price { font-size: 1.02rem; font-weight: 800; margin-bottom: 9px; }
        .costing-meta { color: var(--muted); font-size: .72rem; line-height: 1.55; margin-top: auto; }
        .costing-meta strong { color: #4c4c4c; }
        .costing-date { margin-top: 8px; color: #8a8179; font-size: .68rem; }
        .empty-state { padding: 70px 20px; border: 1px solid var(--line); border-radius: 14px; background: rgba(255,255,255,.55); text-align: center; }
        .empty-state h2 { margin: 0 0 8px; font-size: 1.15rem; }
        .empty-state p { margin: 0; color: var(--muted); font-size: .8rem; }
        .costing-modal { position: fixed; inset: 0; z-index: 100000; display: none; align-items: center; justify-content: center; padding: 26px; }
        .costing-modal.is-open { display: flex; }
        .modal-backdrop { position: absolute; inset: 0; background: rgba(18,24,32,.58); backdrop-filter: blur(2px); }
        .modal-panel { position: relative; z-index: 1; width: min(980px,100%); max-height: calc(100vh - 52px); overflow: auto; background: #f7f8fa; border-radius: 14px; box-shadow: 0 24px 70px rgba(0,0,0,.24); padding: 26px; }
        .modal-close { position: absolute; top: 14px; right: 14px; width: 36px; height: 36px; border: 1px solid #d9dde2; border-radius: 50%; background: rgba(255,255,255,.96); color: #4a4f55; font-size: 20px; line-height: 1; cursor: pointer; display: grid; place-items: center; z-index: 2; }
        .modal-title { margin: 0; padding-right: 48px; font-size: 1.45rem; }
        .modal-subtitle { margin: 5px 0 20px; color: var(--muted); font-size: .8rem; }
        .modal-image-wrap { width: 100%; aspect-ratio: 1/1; border-radius: 10px; overflow: hidden; background: #e7e9ec; border: 1px solid #dedfe2; }
        .modal-image-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; }
.form-grid { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 12px; }
        .costing-modal label { display: block; color: #4a4a4a; font-size: .74rem; font-weight: 700; }
        .costing-modal input,
        .costing-modal select { width: 100%; margin-top: 6px; padding: 10px 11px; border: 1px solid #dedede; border-radius: 7px; background: #fff; color: #333; font: inherit; font-size: .8rem; outline: none; }
        .costing-modal input:focus,
        .costing-modal select:focus { border-color: #e9c480; box-shadow: 0 0 0 3px rgba(230,141,45,.07); }
        .costing-modal input[type="file"] { padding: 8px; background: #fff; }
.row-list { display: grid; gap: 10px; }
        .material-row { display: grid; grid-template-columns: minmax(0,1.25fr) minmax(120px,.55fr) minmax(100px,.48fr) 38px; gap: 9px; align-items: end; }
        .expense-row { display: grid; grid-template-columns: minmax(0,1fr) minmax(130px,.48fr) 38px; gap: 9px; align-items: end; }
        .material-cost-display { margin-top: 6px; min-height: 38px; display: flex; align-items: center; padding: 9px 10px; border-radius: 7px; background: #f0efed; color: #555; font-size: .77rem; font-weight: 700; }
        .remove-row { height: 38px; border: 1px solid #f1caca; border-radius: 7px; background: #fff6f6; color: #c84d4d; cursor: pointer; }
        .add-row { border: 0; border-radius: 7px; background: #fff0da; color: #bf681b; padding: 8px 11px; font-size: .72rem; font-weight: 800; cursor: pointer; }
.modal-actions { display: flex; justify-content: flex-end; gap: 9px; margin-top: 20px; }
        .button { border: 1px solid var(--line); border-radius: 7px; background: #fff; color: #4f4f4f; padding: 10px 14px; font: inherit; font-size: .76rem; font-weight: 800; cursor: pointer; }
        .button.primary { border-color: var(--orange); background: var(--orange); color: #fff; }
        .button.primary:hover { background: var(--orange-dark); }
        .button:disabled { opacity: .55; cursor: not-allowed; }
        .modal-status { min-height: 20px; margin-top: 10px; font-size: .75rem; }
        .modal-status.error { color: var(--red); }
        .modal-status.success { color: var(--green); }
        .library-warning { padding: 11px 12px; border: 1px solid #efd29a; border-radius: 8px; background: #fff9ec; color: #7a633f; font-size: .74rem; line-height: 1.45; }
        .library-warning a { color: #b96518; font-weight: 800; }
        @media (max-width: 1100px) { .costing-grid { grid-template-columns: repeat(3,minmax(0,1fr)); } }
        @media (max-width: 900px) { .costing-grid { grid-template-columns: repeat(2,minmax(0,1fr)); } }
        @media (max-width: 760px) {
            body .page > main.content.saved-costing-main {
                width: 100% !important;
                margin-left: 0 !important;
            }
            .page-wrap { padding: 8px 12px 20px; }
            .heading { flex-direction: column; align-items: flex-start; }
            .costing-grid { grid-template-columns: repeat(2,minmax(0,1fr)); gap: 10px; }
            .costing-body { padding: 11px; }
            .costing-name { font-size: .9rem; }
            .costing-meta { font-size: .67rem; }
            .costing-modal { padding: 12px; }
            .modal-panel { padding: 20px 15px; max-height: calc(100vh - 24px); }
            .form-grid, .material-row, .expense-row { grid-template-columns: 1fr; }
            .remove-row { width: 100%; }
}
        @media (max-width: 420px) { .costing-grid { grid-template-columns: 1fr; } }
        :root {
            --bg: #f6f3ef;
            --panel: #ffffff;
            --line: #e7ded5;
            --text: #332c27;
            --muted: #7b746e;
            --orange: #ea8a3a;
            --orange-dark: #c96d20;
            --orange-soft: #fff1e3;
            --green: #2f8f62;
            --red: #c94f45;
            --soft: #fbf8f5;
        }
        .page {
            background: linear-gradient(180deg, #f8f5f1 0%, #f3eee9 100%);
        }
        .costing-card {
            background: rgba(255,255,255,.94);
            border-color: #eadfd6;
            box-shadow: 0 8px 22px rgba(76,50,32,.05);
        }
        .costing-card:hover {
            box-shadow: 0 14px 30px rgba(76,50,32,.09);
        }
        .category-badge {
            background: var(--orange-soft);
            color: var(--orange-dark);
        }
        .costing-price {
            color: var(--orange-dark);
        }
        .costing-modal {
            padding: 22px;
            z-index: 100000;
        }
        .modal-backdrop {
            background: rgba(50, 38, 30, .50);
            backdrop-filter: blur(3px);
        }
        .modal-panel {
            width: min(1180px, 100%);
            max-height: calc(100vh - 44px);
            padding: 0;
            overflow: auto;
            border: 1px solid #eadfd6;
            border-radius: 18px;
            background: #fffdfb;
            box-shadow: 0 28px 80px rgba(65, 43, 29, .20);
        }
        .modal-header {
            position: sticky;
            top: 0;
            z-index: 12;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            padding: 18px 22px 16px;
            border-bottom: 1px solid #eee4dc;
            background: rgba(255,253,251,.96);
            backdrop-filter: blur(8px);
        }
        .modal-eyebrow {
            display: inline-block;
            color: var(--orange-dark);
            font-size: .61rem;
            font-weight: 900;
            letter-spacing: .08em;
        }
        .modal-title {
            margin: 4px 0 0;
            padding: 0;
            color: #342b25;
            font-size: 1.35rem;
        }
        .modal-subtitle {
            margin: 5px 0 0;
            max-width: 700px;
            color: var(--muted);
            font-size: .73rem;
            line-height: 1.5;
        }
        .modal-close {
            position: static;
            flex: 0 0 38px;
            width: 38px;
            height: 38px;
            border-color: #e4d8ce;
            background: #fff;
            color: #55483f;
        }
        .modal-close:hover {
            border-color: #e9b27c;
            background: var(--orange-soft);
            color: var(--orange-dark);
        }
        .edit-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(285px, 315px);
            gap: 20px;
            align-items: start;
            padding: 20px 22px 10px;
        }
        .edit-main {
            min-width: 0;
        }
        .edit-section {
            margin-top: 18px;
            padding: 18px;
            border: 1px solid #eadfd6;
            border-radius: 13px;
            background: #fff;
        }
        .edit-section.first-section {
            margin-top: 0;
        }
        .edit-section-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 15px;
        }
        .edit-section-heading > div:first-child {
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }
        .section-number {
            width: 31px;
            height: 31px;
            display: grid;
            place-items: center;
            flex: 0 0 31px;
            border-radius: 9px;
            background: var(--orange-soft);
            color: var(--orange-dark);
            font-size: .61rem;
            font-weight: 900;
        }
        .edit-section-heading h3 {
            margin: 0;
            color: #3d342e;
            font-size: .9rem;
        }
        .edit-section-heading p {
            margin: 4px 0 0;
            color: #857d76;
            font-size: .66rem;
            line-height: 1.45;
        }
        .product-edit-grid {
            display: grid;
            grid-template-columns: 210px minmax(0, 1fr);
            gap: 16px;
            align-items: start;
        }
        .modal-image-wrap {
            width: 100%;
            aspect-ratio: 1 / 1;
            border: 1px solid #e5ddd5;
            border-radius: 11px;
            overflow: hidden;
            background: #f3eee9;
        }
        .modal-image-wrap img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            background: #f7f3ef;
        }
        .product-fields small,
        .profit-editor small {
            display: block;
            margin-top: 6px;
            color: #8c837c;
            font-size: .6rem;
            line-height: 1.4;
        }
        .costing-modal input,
        .costing-modal select {
            border-color: #e2d8d0;
            background: #fff;
        }
        .costing-modal input:focus,
        .costing-modal select:focus {
            border-color: #e7a563;
            box-shadow: 0 0 0 3px rgba(234,138,58,.11);
        }
        .row-list {
            display: grid;
            gap: 9px;
        }
        .material-row {
            display: grid;
            grid-template-columns: minmax(0,1.35fr) minmax(110px,.55fr) minmax(110px,.5fr) 38px;
            gap: 9px;
            align-items: end;
            padding: 11px;
            border: 1px solid #e9dfd7;
            border-radius: 10px;
            background: #fcfaf8;
        }
        .expense-row {
            display: grid;
            grid-template-columns: minmax(0,1fr) minmax(140px,.45fr) 38px;
            gap: 9px;
            align-items: end;
            padding: 11px;
            border: 1px solid #e9dfd7;
            border-radius: 10px;
            background: #fcfaf8;
        }
        .material-cost-display {
            background: #f1ede9;
            color: #554d47;
        }
        .remove-row {
            width: 38px;
            background: #fff7f6;
            border-color: #efcbc7;
            color: #be534b;
        }
        .add-row {
            border: 1px solid #efc79e;
            background: var(--orange-soft);
            color: var(--orange-dark);
        }
        .add-row:hover,
        .expense-preset:hover,
        .margin-preset:hover,
        .margin-preset.active {
            border-color: #e4a364;
            background: #ffe8d2;
            color: #a95717;
        }
        .expense-presets,
        .margin-presets {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .expense-preset,
        .margin-preset {
            border: 1px solid #e4dbd3;
            border-radius: 999px;
            padding: 7px 10px;
            background: #fff;
            color: #6b625b;
            font: inherit;
            font-size: .61rem;
            font-weight: 800;
            cursor: pointer;
        }
        .allocation-note,
        .formula-help {
            margin-top: 12px;
            padding: 10px 11px;
            border: 1px solid #edd8c3;
            border-radius: 9px;
            background: #fff8f1;
            color: #79685b;
            font-size: .63rem;
            line-height: 1.5;
        }
        .profit-editor {
            display: grid;
            gap: 11px;
        }
        .percentage-input {
            position: relative;
        }
        .percentage-input input {
            padding-right: 42px;
        }
        .percentage-input > span {
            position: absolute;
            top: 50%;
            right: 14px;
            transform: translateY(-50%);
            color: #756d66;
            font-size: .8rem;
            font-weight: 800;
            pointer-events: none;
        }
        .modal-footer {
            position: sticky;
            bottom: 0;
            z-index: 11;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            padding: 13px 22px;
            border-top: 1px solid #eee4dc;
            background: rgba(255,253,251,.97);
            backdrop-filter: blur(8px);
        }
        .modal-status {
            min-height: 0;
            margin: 0;
            font-size: .69rem;
        }
        .modal-actions {
            margin: 0;
            flex-shrink: 0;
        }
        .button.primary {
            border-color: var(--orange);
            background: var(--orange);
        }
        .button.primary:hover {
            background: var(--orange-dark);
        }
        .field-error {
            border-color: #d9685e !important;
            background: #fff8f7 !important;
            box-shadow: 0 0 0 3px rgba(201,79,69,.08) !important;
        }
        .field-error-message {
            margin-top: 5px;
            color: #b6473e;
            font-size: .6rem;
            font-weight: 700;
            line-height: 1.35;
        }
        @media (max-width: 980px) {
            .edit-layout {
                grid-template-columns: 1fr;
            }
            .modal-breakdown {
                position: static;
            }
        }
        @media (max-width: 720px) {
            .costing-modal {
                padding: 10px;
            }
            .modal-panel {
                max-height: calc(100vh - 20px);
                border-radius: 14px;
            }
            .modal-header,
            .edit-layout,
            .modal-footer {
                padding-left: 15px;
                padding-right: 15px;
            }
            .product-edit-grid,
            .form-grid,
            .material-row,
            .expense-row {
                grid-template-columns: 1fr;
            }
            .remove-row {
                width: 100%;
            }
            .modal-footer {
                align-items: stretch;
                flex-direction: column;
            }
            .modal-actions {
                width: 100%;
            }
            .modal-actions .button {
                flex: 1;
            }
        }

        /* Shared pricing typography and toolbar (matches calculator.php). */
        .heading { flex-direction: row; align-items: center; gap: 8px; }
        .heading h1 { color: #332c27; font-family: "Segoe UI", Arial, sans-serif; font-size: 2.1rem; font-weight: 800; letter-spacing: -.7px; line-height: 1.25; }
        .back-button { display: inline-flex; flex: 0 0 auto; width: auto; height: 43px; min-height: 43px; padding: 0 14px; align-items: center; justify-content: center; gap: 7px; border: 1px solid #d77d32; border-radius: 12px; background: #e98b40; color: #fff; box-shadow: 0 5px 14px rgba(202,111,36,.13); }
        .back-button .back-label { font-size: .78rem; font-weight: 750; white-space: nowrap; }
        .back-button svg { width: 18px; height: 18px; flex: 0 0 18px; }
        .back-button:hover { border-color: #c8752d; background: #cd722c; color: #fff; }
        /* Seller Application-inspired inputs without heavy text. */
        .costing-modal label { font-size: .81rem; font-weight: 700; color: #625548; }
        .costing-modal input:not([type=file]), .costing-modal select { min-width: 0; min-height: 45px; padding: 10px 12px; border: 1px solid #e5d9cd; border-radius: 11px; background: #fdfbf9; color: #483c32; font-family: inherit; font-size: .91rem; font-weight: 400; }
        .costing-modal input:focus, .costing-modal select:focus { border-color: #d99a67; background: #fff; box-shadow: 0 0 0 3px rgba(233,139,64,.1); }
        .product-fields, .material-row, .expense-row, .edit-layout, .modal-panel { min-width: 0; }
        .material-row > *, .expense-row > * { min-width: 0; }
        .material-cost-display { min-height: 45px; border-radius: 11px; background: #f6f1ec; }
        /* Product image chooser lives inside the unchanged 1:1 desktop image square. */
        .image-editor { min-width: 0; }
        .image-editor [hidden] { display: none !important; }
        .image-editor .saved-image-input { position: absolute !important; width: 1px !important; height: 1px !important; min-width: 0 !important; min-height: 0 !important; padding: 0 !important; margin: 0 !important; opacity: 0; clip-path: inset(50%); border: 0; overflow: hidden; pointer-events: none; }
        .modal-image-wrap { position: relative; display: flex; align-items: center; justify-content: center; aspect-ratio: 1 / 1; width: 100%; border-radius: 11px; border: 1px solid #e5ddd5; background: #f3eee9; overflow: hidden; }
        .saved-image-display { display: block; width: 100%; height: 100%; border: 0; background: transparent; cursor: zoom-in; padding: 0; }
        .saved-image-display[hidden] { display: none !important; }
        .saved-image-display img { display: block; width: 100%; height: 100%; object-fit: contain; }
        .saved-image-chooser { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 5px; padding: 12px; border: 1px dashed #d8c9b9; border-radius: 10px; background: #fff; color: #9c6335; text-align: center; cursor: pointer; }
        .saved-image-chooser svg { width: 25px; height: 25px; }
        .saved-image-chooser strong { font-size: .77rem; font-weight: 750; color: #5c5147; }
        .saved-image-chooser small { font-size: .7rem; color: #95877b; }
        .image-editor.has-image .saved-image-chooser { inset: auto 8px 8px; width: calc(100% - 16px); min-height: 34px; padding: 6px; border: 1px solid #e5d9ce; border-radius: 8px; background: rgba(255,255,255,.96); flex-direction: row; gap: 7px; }
        .image-editor.has-image .saved-image-chooser svg { width: 15px; height: 15px; flex: 0 0 15px; }
        .image-editor.has-image .saved-image-chooser small { display: none; }
        .saved-image-chooser:hover, .saved-image-chooser:focus-visible { border-color: #db995d; background: #fffaf6; }
        .saved-image-selected { display: none; }
        .saved-image-guidance { display: block; margin-top: 6px; color: #8c837c; font-size: .65rem; }
        .saved-image-selected:focus-visible, .saved-image-chooser:focus-visible, .saved-image-tools button:focus-visible, .saved-image-display:focus-visible { outline: 3px solid rgba(233,139,64,.35); outline-offset: 2px; }
        /* Shared COST BREAKDOWN structure and final warm/light skin from calculator.php. */
        .modal-breakdown { position: sticky; top: 96px; align-self: start; min-width: 0; }
        .cost-sidebar-card { padding: 21px 20px; border: 1px solid #ecdfd2; border-radius: 16px; background: linear-gradient(160deg, #fffefd 0%, #fff8f1 100%); color: #4b392f; box-shadow: 0 12px 28px rgba(102,57,29,.055); max-height: none; overflow: visible; }
        .cost-sidebar-kicker { color: #a75e2b; font-size: .67rem; font-weight: 800; letter-spacing: .08em; }
        .cost-sidebar-title { margin-top: 9px; color: #70594a; font-size: .78rem; font-weight: 700; }
        .cost-sidebar-price { margin-top: 4px; color: #cd6b1f; font-size: clamp(2.15rem, 3.2vw, 3.15rem); font-weight: 800; letter-spacing: -1.1px; overflow-wrap: anywhere; }
        .cost-sidebar-rule { height: 1px; margin: 20px 0; background: #dcc4b1; }
        .cost-sidebar-section-label { margin: 15px 0 8px; color: #a75e2b; font-size: .61rem; font-weight: 800; letter-spacing: .07em; text-transform: uppercase; }
        .cost-sidebar-row { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 7px 0; color: #665247; font-size: .74rem; }
        .cost-sidebar-row strong { color: #3e312a; font-size: .78rem; white-space: nowrap; }
        .cost-sidebar-row.subtotal { margin-top: 4px; padding-top: 10px; border-top: 1px dashed #d8bcaa; font-weight: 800; color: #514036; }
        .cost-sidebar-row.total { color: #49362b; font-size: .82rem; font-weight: 800; }
        .cost-sidebar-row.total strong { color: #c96d20; font-size: .92rem; }
        .cost-sidebar-row.pricing-profit-row { margin-top: 8px; padding: 11px 12px; border: 1px solid #efdccc; border-radius: 9px; background: #fff4e8; color: #855126; font-weight: 700; }
        .cost-sidebar-row.pricing-profit-row strong { color: #c96d20; }
        /* Fast, visible additions without changing stored order. */
        .row-added { animation: costingSavedRow .3s ease-out both; }
        @keyframes costingSavedRow { from { opacity: .45; transform: translateY(-8px); background-color: #fff1df; } to { opacity: 1; transform: translateY(0); } }
        /* Product viewer is above edit modal and restores modal state on close. */
        .saved-image-viewer[hidden] { display: none !important; }
        .saved-image-viewer { position: fixed; inset: 0; z-index: 300001; display: flex; align-items: center; justify-content: center; padding: 58px 18px 22px; background: rgba(20,16,13,.92); }
        .saved-image-viewer img { max-width: 100%; max-height: calc(100dvh - 90px); object-fit: contain; border-radius: 8px; }
        .saved-image-viewer-close { position: absolute; top: 12px; right: 16px; width: 42px; height: 42px; border: 0; border-radius: 50%; color: #fff; background: #55483f; font-size: 28px; cursor: pointer; }
        @media (max-width: 980px) { .modal-breakdown { position: static; order: 2; } }
        @media (max-width: 760px) {
            .heading { flex-direction: row; align-items: center; gap: 8px; }
            .heading h1 { font-size: 1.3rem; letter-spacing: -.3px; }
            .back-button { flex: 0 0 40px; width: 40px; height: 40px; min-height: 40px; padding: 0; border-radius: 10px; }
            .back-button .back-label { display: none; }
            .costing-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 9px; }
            .costing-card { min-width: 0; }
            .costing-body { padding: 10px; }
            .costing-name { font-size: .81rem; overflow-wrap: anywhere; }
            .costing-price { font-size: .91rem; }
            .category-badge { font-size: .6rem; max-width: 100%; overflow-wrap: anywhere; }
            .costing-date { overflow-wrap: anywhere; }
            .modal-panel { max-height: calc(100dvh - 20px); }
            .costing-modal input:not([type=file]), .costing-modal select { font-size: 16px !important; font-weight: 400; max-width: 100%; }
            .modal-image-wrap { min-height: 96px; aspect-ratio: auto; }
            .image-editor.has-image .modal-image-wrap { display: none; }
            .saved-image-selected:not([hidden]) { display: flex; align-items: center; min-width: 0; min-height: 67px; padding: 8px; gap: 9px; border: 1px solid #eaded2; border-radius: 10px; background: #fff; cursor: zoom-in; }
            .saved-image-selected:hover { background: #fff8f0; border-color: #dba87e; }
            .saved-image-thumb { display: block; flex: 0 0 50px; width: 50px; height: 50px; padding: 0; border: 0; border-radius: 8px; overflow: hidden; background: #f4ede5; cursor: zoom-in; }
            .saved-image-thumb img { display: block; width: 100%; height: 100%; object-fit: cover; }
            .saved-image-meta { flex: 1; min-width: 0; }
            .saved-image-name { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #5d5147; font-size: .72rem; font-weight: 750; }
            .saved-image-size { display: block; margin-top: 2px; color: #87796d; font-size: .68rem; }
            .saved-image-tools { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 5px; }
            .saved-image-tools button { padding: 4px 7px; border: 1px solid #e5d9ce; border-radius: 7px; background: #fff; color: #7d5740; font: inherit; font-size: .68rem; font-weight: 700; cursor: pointer; }
            .saved-image-guidance { display: none; }
            /* Material select full-width; quantity and price share one row without horizontal scrolling. */
            .material-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); grid-template-areas: 'material material' 'quantity cost' 'remove remove'; gap: 8px; }
            .material-row > label:nth-child(1) { grid-area: material; }
            .material-row > label:nth-child(2) { grid-area: quantity; }
            .material-row > label:nth-child(3) { grid-area: cost; }
            .material-row > .remove-row { grid-area: remove; justify-self: end; width: 40px; min-width: 40px; height: 40px; }
            .material-cost-display { min-width: 0; overflow-wrap: anywhere; padding: 8px; font-size: .74rem; }
            .expense-row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(90px, .48fr); gap: 8px; }
            .expense-row > label:first-child { min-width: 0; }
            .expense-row > .remove-row { grid-column: 2; justify-self: end; width: 40px; height: 40px; }
        }
        @media (max-width: 360px) { .heading h1 { font-size: 1.16rem; } }
        @media (prefers-reduced-motion: reduce) { .row-added { animation: none; } }

        /* Consistent LokalCraft controls in Safari / iOS, including Safari dark-mode preferences. */
        .costing-modal { color-scheme: light; }
        .costing-modal input:not([type="file"]):not([type="hidden"]),
        .costing-modal select {
            -webkit-appearance: none;
            appearance: none;
            box-sizing: border-box;
            max-width: 100%;
            min-width: 0;
            border: 1px solid #e5d9cd;
            border-radius: 11px;
            background-color: #fdfbf9;
            color: #483c32;
            font-family: inherit;
            font-weight: 400;
            background-clip: padding-box;
            -webkit-text-size-adjust: 100%;
        }
        .costing-modal select {
            padding-right: 34px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23837569' stroke-width='2.3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 11px center;
            background-size: 16px 16px;
        }
        .costing-modal input[type="number"]::-webkit-inner-spin-button,
        .costing-modal input[type="number"]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        .costing-modal input[type="number"] { -moz-appearance: textfield; }
        .costing-modal input::placeholder { color: #8c8680; opacity: 1; }
        .costing-modal input:not([type="file"]):focus,
        .costing-modal select:focus { border-color: #d99a67; background-color: #fff; box-shadow: 0 0 0 3px rgba(233,139,64,.1); outline: none; }
        .costing-modal input:disabled, .costing-modal select:disabled { opacity: 1; color: #81776f; }
        /* Labor and packaging match the direct-cost cards in calculator.php. */
        .product-fields .saved-labor-card,
        .product-fields .saved-packaging-card {
            grid-column: 1 / -1;
            min-width: 0;
            display: grid;
            gap: 11px;
            padding: 14px;
            border: 1px solid #e5e0db;
            border-radius: 12px;
            background: #fbfaf9;
        }
        .saved-cost-field-heading { display: flex; align-items: center; gap: 10px; }
        .saved-cost-field-heading h4 { margin: 0; font-size: .88rem; color: #403b36; font-weight: 800; }
        .saved-cost-field-icon { width: 38px; height: 38px; flex: 0 0 38px; display: grid; place-items: center; border-radius: 10px; background: var(--orange-soft); color: var(--orange-dark); }
        .saved-labor-fields { min-width: 0; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; align-items: end; }
        .saved-labor-fields label { min-width: 0; }
        /* Same quick overhead choices as the main calculator. */
        .indirect-guide { margin-bottom: 14px; padding: 14px; border: 1px solid #e4ddd6; border-radius: 11px; background: #fbfaf8; }
        .indirect-guide > strong { display: block; color: #403b36; font-size: .76rem; }
        .indirect-chips { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 11px; }
        .indirect-chip { border: 1px solid #dfd8d1; border-radius: 999px; padding: 7px 10px; background: #fff; color: #665e57; font-family: inherit; font-size: .62rem; font-weight: 800; cursor: pointer; }
        .indirect-chip:hover, .indirect-chip:focus-visible { border-color: #e4b576; background: #fff5e9; color: #aa5d19; }
        @media (max-width: 760px) {
            .saved-labor-fields { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .product-fields .saved-labor-card, .product-fields .saved-packaging-card { padding: 12px; }
        }
    </style>
</head>
<body>
<div class="page">
    <?php require __DIR__ . '/seller_nav.php'; ?>
    <main class="content saved-costing-main">
        <?php require __DIR__ . '/seller_search.php'; ?>
        <div class="page-wrap">
            <div class="heading">
                <div>
                    <h1>Saved Calculations</h1>
                </div>
                <a class="back-button" href="calculator.php" aria-label="Back to Pricing & Costing" title="Back to Pricing & Costing">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/><path d="M9 12h12"/></svg>
                    <span class="back-label">Back</span>
                </a>
            </div>
            <?php if ($errorMessage !== ''): ?>
                <div class="error-message"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <div class="toolbar">
                <div class="count"><?= count($costings) ?> saved calculation<?= count($costings) === 1 ? '' : 's' ?></div>
            </div>
            <?php if (!$costings): ?>
                <div class="empty-state">
                    <h2>No Saved Calculations Yet</h2>
                    <p>Your saved product costings will appear here after you save a calculation.</p>
                </div>
            <?php else: ?>
                <div class="costing-grid" id="costing-grid">
                    <?php foreach ($costings as $costing): ?>
                        <?php
                        $storedCostingImage = trim((string)($costing['product_image'] ?? ''));
                        $fallbackProductPhoto = trim((string)($costing['fallback_product_photo'] ?? ''));
                        $displayImagePath = $storedCostingImage !== '' ? $storedCostingImage : $fallbackProductPhoto;
                        $imageUrl = costingImageUrl($displayImagePath);
                        $payload = $costing;
                        $payload['image_url'] = $imageUrl;
                        $payload['image_source'] = $storedCostingImage !== '' ? 'costing' : ($fallbackProductPhoto !== '' ? 'product' : 'none');
                        $payload['category_label'] = formatCategory($costing['craft_category']);
                        ?>
                        <article
                            class="costing-card"
                            tabindex="0"
                            role="button"
                            aria-label="Open <?= htmlspecialchars($costing['product_title'], ENT_QUOTES, 'UTF-8') ?> costing"
                            data-costing="<?= htmlspecialchars(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <div class="costing-image-wrap">
                                <?php if ($imageUrl !== ''): ?>
                                    <img class="costing-image" src="<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($costing['product_title'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
                                <?php else: ?>
                                    <div class="placeholder-image" aria-label="No product image">🧺</div>
                                <?php endif; ?>
                                <span class="saved-tag">Saved Costing</span>
                            </div>
                            <div class="costing-body">
                                <span class="category-badge"><?= htmlspecialchars(formatCategory($costing['craft_category']), ENT_QUOTES, 'UTF-8') ?></span>
                                <div class="costing-name"><?= htmlspecialchars($costing['product_title'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="costing-price"><?= formatMoney($costing['suggested_price']) ?></div>
                                <div class="costing-meta">
                                    Total Cost: <strong><?= formatMoney($costing['total_cost']) ?></strong><br>
                                    Profit Margin: <strong><?= htmlspecialchars(rtrim(rtrim(number_format((float)($payload['profit_margin'] ?? 0), 2, '.', ''), '0'), '.'), ENT_QUOTES, 'UTF-8') ?>%</strong>
                                </div>
                                <div class="costing-date"><?= htmlspecialchars(date('M d, Y h:i A', strtotime($costing['created_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<div id="saved-image-viewer" class="saved-image-viewer" hidden role="dialog" aria-modal="true" aria-label="Full product image preview">
    <button type="button" id="saved-image-viewer-close" class="saved-image-viewer-close" aria-label="Close image preview">×</button>
    <img id="saved-image-viewer-img" alt="Full product image">
</div>
<div class="costing-modal" id="costing-modal" aria-hidden="true">
    <div class="modal-backdrop" id="modal-backdrop"></div>
    <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <div class="modal-header">
            <div>
                <span class="modal-eyebrow">SAVED COSTING</span>
                <h2 class="modal-title" id="modal-title">Edit Calculation</h2>

            </div>
            <button class="modal-close" id="modal-close" type="button" aria-label="Close">&times;</button>
        </div>
        <form id="edit-form" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update_costing">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="costing_id" id="costing-id" value="">
            <div class="edit-layout">
                <div class="edit-main">
                    <section class="edit-section first-section">
                        <div class="edit-section-heading">
                            <div>
                                <span class="section-number">01</span>
                                <div>
                                    <h3>Product Details</h3>

                                </div>
                            </div>
                        </div>
                        <div class="product-edit-grid">
                            <div class="image-editor" id="image-editor">
                                <div class="modal-image-wrap" id="modal-image-wrap">
                                    <button class="saved-image-display" id="saved-image-display" type="button" aria-label="View full product image" hidden></button>
                                    <label class="saved-image-chooser" id="saved-image-chooser" for="product-image" tabindex="0">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4m-4 4 4-4 4 4M4 16v4h16v-4"/></svg>
                                        <strong id="saved-image-choose-text">Choose Image</strong>
                                        <small id="saved-image-choose-help">JPG, PNG, WEBP · Max 5 MB</small>
                                    </label>
                                </div>
                                <div class="saved-image-selected" id="saved-image-selected" role="button" tabindex="0" aria-label="View full product image" hidden>
                                    <button type="button" class="saved-image-thumb" id="saved-image-thumb" aria-label="View full product image"></button>
                                    <div class="saved-image-meta">
                                        <strong class="saved-image-name" id="saved-image-name"></strong>
                                        <small class="saved-image-size" id="saved-image-size"></small>
                                        <div class="saved-image-tools">
                                            <button type="button" id="saved-image-view">View</button>
                                            <button type="button" id="saved-image-change">Change</button>
                                            <button type="button" id="saved-image-remove" hidden>Remove</button>
                                        </div>
                                    </div>
                                </div>
                                <input type="file" name="product_image" id="product-image" class="saved-image-input" accept="image/jpeg,image/png,image/webp">
                                <small class="saved-image-guidance">Choose a new image to replace the current one.</small>
                            </div>
                            <div class="form-grid product-fields">
                                <label>
                                    Product Name
                                    <input type="text" name="product_title" id="product-title" maxlength="255" required>
                                </label>
                                <label>
                                    Craft Category
                                    <select name="craft_category" id="craft-category" required>
                                        <option value="">Select category</option>
                                        <?php foreach ($craftCategories as $category): ?>
                                            <option value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <div class="saved-labor-card">
                                    <div class="saved-cost-field-heading">
                                        <span class="saved-cost-field-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
                                        <h4>Labor</h4>
                                    </div>
                                    <div class="saved-labor-fields">
                                        <label>
                                            Hours Spent
                                            <input type="number" name="labor_hours" id="labor-hours" min="0" step="0.1" placeholder="0.0" inputmode="decimal">
                                        </label>
                                        <label>
                                            Your Hourly Rate (₱)
                                            <input type="number" name="hourly_rate" id="hourly-rate" min="0" step="0.1" placeholder="0.0" inputmode="decimal">
                                        </label>
                                    </div>
                                </div>
                                <div class="saved-packaging-card">
                                    <div class="saved-cost-field-heading">
                                        <span class="saved-cost-field-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 3 8l9 5 9-5-9-5ZM3 8v9l9 5 9-5V8M12 13v9"/></svg></span>
                                        <h4>Packaging</h4>
                                    </div>
                                    <label>
                                        Packaging Cost (₱)
                                        <input type="number" name="packaging_cost" id="packaging-cost" min="0" step="0.01" placeholder="e.g. 25" inputmode="decimal">
                                    </label>
                                </div>
                            </div>
                        </div>
                    </section>
                    <section class="edit-section">
                        <div class="edit-section-heading">
                            <div>
                                <span class="section-number">02</span>
                                <div>
                                    <h3>Direct Materials</h3>
                                </div>
                            </div>
                            <button class="add-row" id="add-material" type="button">+ Add Material</button>
                        </div>
                        <?php if (!$savedMaterials): ?>
                            <div class="library-warning">
                                You do not have materials in your Materials Library.
                                Add them first in <a href="saved_materials.php">Materials Library</a>.
                            </div>
                        <?php endif; ?>
                        <div class="row-list" id="materials-list"></div>
                    </section>
                    <section class="edit-section">
                        <div class="edit-section-heading indirect-heading">
                            <div>
                                <span class="section-number">03</span>
                                <div>
                                    <h3>Indirect Expenses (Overhead)</h3>
                                </div>
                            </div>
                            <button class="add-row" id="add-overhead" type="button">+ Add Custom Expense</button>
                        </div>
                        <div class="indirect-guide">
                            <strong>Common craft expenses</strong>
                            <div class="indirect-chips">
                                <button type="button" class="indirect-chip" data-overhead-preset="Electricity">Electricity</button>
                                <button type="button" class="indirect-chip" data-overhead-preset="Internet">Internet</button>
                                <button type="button" class="indirect-chip" data-overhead-preset="Tool Wear">Tool Wear</button>
                                <button type="button" class="indirect-chip" data-overhead-preset="Workspace Rent">Workspace Rent</button>
                                <button type="button" class="indirect-chip" data-overhead-preset="Sewing Machine Maintenance">Machine Care</button>
                                <button type="button" class="indirect-chip" data-overhead-preset="Online Shop Ads">Online Ads</button>
                            </div>
                        </div>
                        <div class="row-list" id="overhead-list"></div>

                    </section>
                    <section class="edit-section">
                        <div class="edit-section-heading">
                            <div>
                                <span class="section-number">04</span>
                                <div>
                                    <h3>Target Profit Margin</h3>
                                </div>
                            </div>
                        </div>
                        <div class="profit-editor">
                            <label>
                                Desired Profit Margin
                                <div class="percentage-input">
                                    <input type="number" name="profit_margin" id="profit-margin" min="0" max="89.99" step="any" placeholder="e.g. 30">
                                    <span>%</span>
                                </div>

                            </label>
                            <div class="margin-presets">
                                <button type="button" class="margin-preset" data-margin="20">20%</button>
                                <button type="button" class="margin-preset" data-margin="30">30%</button>
                                <button type="button" class="margin-preset" data-margin="40">40%</button>
                            </div>

                        </div>
                    </section>
                </div>
                <aside class="modal-breakdown cost-sidebar" aria-label="Cost breakdown">
                    <div class="cost-sidebar-card">
                        <div class="cost-sidebar-kicker">COST BREAKDOWN</div>
                        <div class="cost-sidebar-title">Suggested Selling Price</div>
                        <div class="cost-sidebar-price" id="sum-price">₱0.00</div>
                        <div class="cost-sidebar-rule"></div>
                        <div class="cost-sidebar-section-label">Direct Costs</div>
                        <div class="cost-sidebar-row"><span>Materials</span><strong id="sum-materials">₱0.00</strong></div>
                        <div class="cost-sidebar-row"><span>Labor</span><strong id="sum-labor">₱0.00</strong></div>
                        <div class="cost-sidebar-row"><span>Packaging</span><strong id="sum-packaging">₱0.00</strong></div>
                        <div class="cost-sidebar-row subtotal"><span>Total Direct Cost</span><strong id="sum-direct-total">₱0.00</strong></div>
                        <div class="cost-sidebar-section-label">Indirect Costs</div>
                        <div class="cost-sidebar-row"><span>Overhead</span><strong id="sum-overhead">₱0.00</strong></div>
                        <div class="cost-sidebar-rule"></div>
                        <div class="cost-sidebar-section-label">Price Summary</div>
                        <div class="cost-sidebar-row total"><span>Total Cost</span><strong id="sum-total-cost">₱0.00</strong></div>
                        <div class="cost-sidebar-row"><span>Profit Margin</span><strong id="sum-margin">0%</strong></div>
                        <div class="cost-sidebar-row pricing-profit-row"><span>Profit</span><strong id="sum-profit">₱0.00</strong></div>
                    </div>
                </aside>
            </div>
            <div class="modal-footer">
                <div class="modal-status" id="modal-status" aria-live="polite"></div>
                <div class="modal-actions">
                    <button class="button" id="cancel-edit" type="button">Cancel</button>
                    <button class="button primary" id="save-edit" type="submit">Save Changes</button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
(() => {
    // Keep Saved Calculations below the shared, fixed Seller Search header.
    const savedMain = document.querySelector('main.saved-costing-main');
    const searchHeader = savedMain?.querySelector('.seller-header-tools');
    const syncHeaderSpace = () => {
        if (!savedMain) return;
        const header = searchHeader || document.querySelector('.seller-header-tools');
        const rect = header?.getBoundingClientRect();
        const isVisible = !!header && getComputedStyle(header).display !== 'none' && rect.height > 0;
        const fallback = window.innerWidth <= 760 ? 76 : 70;
        const headerBottom = isVisible ? Math.max(0, rect.bottom) : fallback;
        savedMain.style.setProperty('--saved-costing-header-bottom', `${Math.ceil(headerBottom)}px`);
    };
    syncHeaderSpace();
    window.addEventListener('load', syncHeaderSpace);
    window.addEventListener('resize', syncHeaderSpace);
    if (searchHeader && typeof ResizeObserver !== 'undefined') {
        new ResizeObserver(syncHeaderSpace).observe(searchHeader);
    }
})();
(() => {
    const savedMaterials = <?= $savedMaterialsJson ?: '[]' ?>;
    const materialMap = new Map(
        savedMaterials.map(item => [String(item.material_id), item])
    );
    const formatMoney = value =>
        `₱${Number(value || 0).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        })}`;
    const modal = document.getElementById('costing-modal');
    const modalBackdrop = document.getElementById('modal-backdrop');
    const modalClose = document.getElementById('modal-close');
    const cancelEdit = document.getElementById('cancel-edit');
    const form = document.getElementById('edit-form');
    const status = document.getElementById('modal-status');
    const productImageInput = document.getElementById('product-image');
    let selectedCosting = null;
    let imagePreviewUrl = null;
    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }
    function setStatus(message = '', type = '') {
        status.textContent = message;
        status.className = `modal-status ${type}`.trim();
    }
    const imageEditor = document.getElementById('image-editor');
    const imageDisplay = document.getElementById('saved-image-display');
    const imageChooser = document.getElementById('saved-image-chooser');
    const imageSelected = document.getElementById('saved-image-selected');
    const imageThumb = document.getElementById('saved-image-thumb');
    const imageName = document.getElementById('saved-image-name');
    const imageSize = document.getElementById('saved-image-size');
    const imageRemove = document.getElementById('saved-image-remove');
    const imageViewer = document.getElementById('saved-image-viewer');
    const imageViewerImg = document.getElementById('saved-image-viewer-img');
    let activeImageSrc = '';
    let previewTrigger = null;
    function formatFileSize(bytes) {
        return bytes >= 1024 * 1024 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.ceil(bytes / 1024))} KB`;
    }
    function renderModalImage(src, filename = '', detail = '') {
        activeImageSrc = String(src || '');
        const hasImage = Boolean(activeImageSrc);
        imageEditor.classList.toggle('has-image', hasImage);
        imageDisplay.hidden = !hasImage;
        imageSelected.hidden = !hasImage;
        imageChooser.querySelector('#saved-image-choose-text').textContent = hasImage ? 'Change Image' : 'Choose Image';
        if (hasImage) {
            const safeSrc = escapeHtml(activeImageSrc);
            imageDisplay.innerHTML = `<img src="${safeSrc}" alt="Product image preview">`;
            imageThumb.innerHTML = `<img src="${safeSrc}" alt="Product image thumbnail">`;
            imageName.textContent = filename || 'Product image';
            imageSize.textContent = detail || 'Saved image';
        } else {
            imageDisplay.replaceChildren();
            imageThumb.replaceChildren();
            imageName.textContent = '';
            imageSize.textContent = '';
        }
        imageRemove.hidden = !productImageInput.files?.length;
    }
    function openImageViewer(trigger) {
        if (!activeImageSrc) return;
        previewTrigger = trigger || null;
        imageViewerImg.src = activeImageSrc;
        imageViewer.hidden = false;
        document.getElementById('saved-image-viewer-close').focus();
    }
    function closeImageViewer() {
        if (imageViewer.hidden) return;
        imageViewer.hidden = true;
        imageViewerImg.removeAttribute('src');
        if (previewTrigger?.isConnected) previewTrigger.focus({preventScroll:true});
        previewTrigger = null;
    }
    function restoreSavedImage() {
        const src = selectedCosting?.image_url || '';
        let fileName = 'Saved product image';
        try { fileName = src ? decodeURIComponent(src.split('/').pop().split('?')[0]) : ''; }
        catch { fileName = 'Saved product image'; }
        renderModalImage(src, fileName, 'Saved image');
    }
    imageChooser.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            productImageInput.click();
        }
    });
    [imageDisplay, imageThumb, document.getElementById('saved-image-view')].forEach(button => {
        button.addEventListener('click', () => openImageViewer(button));
    });
    imageSelected.addEventListener('click', event => {
        if (!event.target.closest('.saved-image-tools, .saved-image-thumb')) openImageViewer(imageSelected);
    });
    imageSelected.addEventListener('keydown', event => {
        if ((event.key === 'Enter' || event.key === ' ') && event.target === imageSelected) {
            event.preventDefault(); openImageViewer(imageSelected);
        }
    });
    document.getElementById('saved-image-change').addEventListener('click', () => productImageInput.click());
    function discardPendingImage() {
        productImageInput.value = '';
        if (imagePreviewUrl) {
            URL.revokeObjectURL(imagePreviewUrl);
            imagePreviewUrl = null;
        }
        restoreSavedImage();
    }
    imageRemove.addEventListener('click', () => {
        discardPendingImage();
        clearFieldError(productImageInput);
    });
    document.getElementById('saved-image-viewer-close').addEventListener('click', closeImageViewer);
    imageViewer.addEventListener('click', event => { if (event.target === imageViewer) closeImageViewer(); });
    function clearFieldError(input) {
        if (!input) return;
        input.classList.remove('field-error');
        const label = input.closest('label');
        label?.querySelectorAll('.field-error-message').forEach(node => node.remove());
    }
    function showFieldError(input, message) {
        if (!input) return false;
        clearFieldError(input);
        input.classList.add('field-error');
        const error = document.createElement('div');
        error.className = 'field-error-message';
        error.textContent = message;
        const label = input.closest('label');
        if (label) {
            label.appendChild(error);
        } else {
            input.insertAdjacentElement('afterend', error);
        }
        input.focus({ preventScroll: true });
        input.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });
        return false;
    }
    function clearValidation() {
        form.querySelectorAll('.field-error').forEach(node => {
            node.classList.remove('field-error');
        });
        form.querySelectorAll('.field-error-message').forEach(node => {
            node.remove();
        });
    }
    function materialOptions(selectedId = '') {
        let html = '<option value="">Select material</option>';
        savedMaterials.forEach(item => {
            const selected = String(item.material_id) === String(selectedId) ? ' selected' : '';
            html += `<option value="${Number(item.material_id)}"${selected}>${escapeHtml(item.material_name)}</option>`;
        });
        return html;
    }
    function addMaterialRow(data = {}, isNew = false) {
        const row = document.createElement('div');
        row.className = 'material-row';
        row.innerHTML = `
            <label>
                Material Name
                <select
                    class="material-select"
                    name="saved_material_id[]"
                    required
                >
                    ${materialOptions(data.saved_material_id || '')}
                </select>
            </label>
            <label>
                Quantity Used
                <input
                    class="material-quantity"
                    name="material_quantity[]"
                    type="number"
                    min="0.01"
                    step="0.01"
                    inputmode="decimal"
                    placeholder="e.g. 1.00"
                    value="${Number(data.quantity || 0) || ''}"
                    required
                >
            </label>
            <label>
                Material Cost
                <div class="material-cost-display">₱0.00</div>
            </label>
            <button
                class="remove-row"
                type="button"
                aria-label="Remove material"
            >
                ×
            </button>
        `;
        const list = document.getElementById('materials-list');
        if (isNew) {
            row.classList.add('row-added');
            list.prepend(row);
        } else {
            list.appendChild(row);
        }
        updateMaterialRow(row);
    }
    function addExpenseRow(data = {}, isNew = false) {
        const row = document.createElement('div');
        row.className = 'expense-row';
        row.innerHTML = `
            <label>
                Indirect Expense
                <input
                    class="expense-name"
                    name="overhead_name[]"
                    type="text"
                    value="${escapeHtml(data.overhead_name || '')}"
                    placeholder="e.g. Electricity"
                >
            </label>
            <label>
                Allocated Cost (₱)
                <input
                    class="expense-cost"
                    name="overhead_cost[]"
                    type="number"
                    min="0"
                    step="0.01"
                    value="${Number(data.cost || 0) > 0 ? Number(data.cost).toFixed(2) : ''}"
                    placeholder="0.00"
                >
            </label>
            <button
                class="remove-row"
                type="button"
                aria-label="Remove expense"
            >
                ×
            </button>
        `;
        const list = document.getElementById('overhead-list');
        if (isNew) {
            row.classList.add('row-added');
            list.prepend(row);
        } else {
            list.appendChild(row);
        }
    }
    function updateMaterialRow(row) {
        const select = row.querySelector('.material-select');
        const quantity = Math.max(
            0,
            Number(row.querySelector('.material-quantity')?.value || 0)
        );
        const material = materialMap.get(
            String(select?.value || '')
        );
        let total = 0;
        if (material && Number(material.reference_quantity) > 0) {
            const unitCost =
                Number(material.reference_price)
                / Number(material.reference_quantity);
            total = Math.round((unitCost * quantity + Number.EPSILON) * 100) / 100;
        }
        row.querySelector('.material-cost-display').textContent =
            formatMoney(total);
        return total;
    }
    function calculate() {
        const roundMoney = value => Math.round((Number(value) + Number.EPSILON) * 100) / 100;
        let materials = 0;
        document
            .querySelectorAll('#materials-list .material-row')
            .forEach(row => {
                materials += updateMaterialRow(row);
            });
        const labor = roundMoney(Math.max(0, Number(document.getElementById('labor-hours').value || 0))
            * Math.max(0, Number(document.getElementById('hourly-rate').value || 0)));
        const packaging = roundMoney(Math.max(
            0,
            Number(document.getElementById('packaging-cost').value || 0)
        ));
        let overhead = 0;
        document
            .querySelectorAll('#overhead-list .expense-cost')
            .forEach(input => {
                overhead += roundMoney(Math.max(0, Number(input.value || 0)));
            });
        const directTotal = roundMoney(materials + labor + packaging);
        const totalCost = roundMoney(directTotal + overhead);
        const marginInput = document.getElementById('profit-margin');
        const margin = Math.min(
            89.99,
            Math.max(
                0,
                Number(marginInput.value || 0)
            )
        );
        const pricingDenominator = 1 - margin / 100;
        const suggestedPrice = totalCost > 0 && pricingDenominator > 0
            ? roundMoney(totalCost / pricingDenominator) : 0;
        const profitAmount = roundMoney(Math.max(0, suggestedPrice - totalCost));
        document.getElementById('sum-materials').textContent =
            formatMoney(materials);
        document.getElementById('sum-labor').textContent =
            formatMoney(labor);
        document.getElementById('sum-packaging').textContent =
            formatMoney(packaging);
        document.getElementById('sum-direct-total').textContent =
            formatMoney(directTotal);
        document.getElementById('sum-overhead').textContent =
            formatMoney(overhead);
        document.getElementById('sum-total-cost').textContent =
            formatMoney(totalCost);
        document.getElementById('sum-margin').textContent =
            `${Number(margin.toFixed(2))}%`;
        document.getElementById('sum-profit').textContent =
            formatMoney(profitAmount);
        document.getElementById('sum-price').textContent =
            formatMoney(suggestedPrice);
        document.querySelectorAll('.margin-preset').forEach(button => {
            button.classList.toggle(
                'active',
                Number(button.dataset.margin) === Number(margin)
            );
        });
        return {
            materials,
            labor,
            packaging,
            overhead,
            directTotal,
            totalCost,
            margin,
            profitAmount,
            suggestedPrice
        };
    }
    function validateForm() {
        clearValidation();
        const title = document.getElementById('product-title');
        const category = document.getElementById('craft-category');
        const laborHours = document.getElementById('labor-hours');
        const hourlyRate = document.getElementById('hourly-rate');
        const packaging = document.getElementById('packaging-cost');
        const margin = document.getElementById('profit-margin');
        if (!title.value.trim()) {
            return showFieldError(
                title,
                'Enter the product name.'
            );
        }
        if (!category.value) {
            return showFieldError(
                category,
                'Select a craft category.'
            );
        }
        const hours = Number(laborHours.value || 0);
        const rate = Number(hourlyRate.value || 0);
        if (!Number.isFinite(hours) || hours < 0) return showFieldError(laborHours, 'Enter valid labor hours.');
        if (!Number.isFinite(rate) || rate < 0) return showFieldError(hourlyRate, 'Enter a valid hourly rate.');
        if (hours > 0 && rate <= 0) return showFieldError(hourlyRate, 'Enter your hourly labor rate.');
        if (rate > 0 && hours <= 0) return showFieldError(laborHours, 'Enter the hours spent making this product.');
        if (Number(packaging.value || 0) < 0) {
            return showFieldError(
                packaging,
                'Packaging cost cannot be negative.'
            );
        }
        const materialRows = [
            ...document.querySelectorAll(
                '#materials-list .material-row'
            )
        ];
        if (!materialRows.length) {
            setStatus(
                'Please keep at least one material in the calculation.',
                'error'
            );
            document.getElementById('add-material').focus();
            return false;
        }
        for (const row of materialRows) {
            const select = row.querySelector('.material-select');
            const quantity = row.querySelector('.material-quantity');
            if (!select.value) {
                return showFieldError(
                    select,
                    'Select a material.'
                );
            }
            if (Number(quantity.value || 0) <= 0) {
                return showFieldError(
                    quantity,
                    'Quantity used must be greater than 0.'
                );
            }
        }
        const expenseRows = [
            ...document.querySelectorAll(
                '#overhead-list .expense-row'
            )
        ];
        for (const row of expenseRows) {
            const name = row.querySelector('.expense-name');
            const cost = row.querySelector('.expense-cost');
            const hasName = Boolean(name.value.trim());
            const hasCost = Number(cost.value || 0) > 0;
            if (!hasName && !hasCost) {
                continue;
            }
            if (!hasName) {
                return showFieldError(
                    name,
                    'Enter the indirect expense name.'
                );
            }
            if (!hasCost) {
                return showFieldError(
                    cost,
                    'Enter the amount allocated to one product.'
                );
            }
        }
        if (margin.value.trim() === '') {
            return showFieldError(
                margin,
                'Enter your target profit margin, or choose a quick percentage.'
            );
        }
        const marginValue = Number(margin.value);
        if (!Number.isFinite(marginValue) || marginValue < 0 || marginValue >= 90) {
            return showFieldError(
                margin,
                'Use a target profit margin from 0% to below 90%.'
            );
        }
        return true;
    }
    function openModal(costing) {
        selectedCosting = costing;
        setStatus('');
        clearValidation();
        form.reset();
        if (imagePreviewUrl) {
            URL.revokeObjectURL(imagePreviewUrl);
            imagePreviewUrl = null;
        }
        document.getElementById('costing-id').value =
            costing.costing_id;
        document.getElementById('product-title').value =
            costing.product_title || '';
        document.getElementById('craft-category').value =
            costing.craft_category || '';
        // Legacy saved records contain only labor_cost, so display their unchanged
        // total as 1 hour × that hourly rate. Hour/rate details are not persisted.
        const savedLaborTotal = Number(costing.labor_cost || 0);
        document.getElementById('labor-hours').value = savedLaborTotal > 0 ? '1' : '';
        document.getElementById('hourly-rate').value = savedLaborTotal > 0 ? savedLaborTotal.toFixed(2) : '';
        document.getElementById('packaging-cost').value =
            Number(costing.packaging_cost || 0) > 0 ? Number(costing.packaging_cost).toFixed(2) : '';
        const savedMargin = Number(costing.profit_margin || 0);
        const hasSavedPrice = Number(costing.suggested_price || 0) > 0;
        document.getElementById('profit-margin').value =
            savedMargin > 0 ? Number(savedMargin.toFixed(2)).toString()
            : (hasSavedPrice ? '0' : '');
        productImageInput.value = '';
        restoreSavedImage();
        const materialsList =
            document.getElementById('materials-list');
        const overheadList =
            document.getElementById('overhead-list');
        materialsList.innerHTML = '';
        overheadList.innerHTML = '';
        (costing.materials || []).forEach(material => addMaterialRow(material));
        if (
            !(costing.materials || []).length
            && savedMaterials.length
        ) {
            addMaterialRow();
        }
        (costing.overheads || []).forEach(expense => addExpenseRow(expense));
        calculate();
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
        modal.querySelector('.modal-panel')?.scrollTo({
            top: 0,
            behavior: 'instant'
        });
    }
    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
        closeImageViewer();
        selectedCosting = null;
        setStatus('');
        clearValidation();
        if (imagePreviewUrl) {
            URL.revokeObjectURL(imagePreviewUrl);
            imagePreviewUrl = null;
        }
    }
    document.querySelectorAll('.costing-card').forEach(card => {
        const open = () => {
            try {
                openModal(
                    JSON.parse(card.dataset.costing)
                );
            } catch (error) {
                console.error(
                    'Unable to open saved costing.',
                    error
                );
            }
        };
        card.addEventListener('click', open);
        card.addEventListener('keydown', event => {
            if (
                event.key === 'Enter'
                || event.key === ' '
            ) {
                event.preventDefault();
                open();
            }
        });
    });
    modalClose.addEventListener('click', closeModal);
    modalBackdrop.addEventListener('click', closeModal);
    cancelEdit.addEventListener('click', closeModal);
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        if (!imageViewer.hidden) { closeImageViewer(); return; }
        if (modal.classList.contains('is-open')) closeModal();
    });
    document
        .getElementById('add-material')
        .addEventListener('click', () => {
            if (!savedMaterials.length) {
                setStatus(
                    'Add a material in Materials Library first.',
                    'error'
                );
                return;
            }
            addMaterialRow({}, true);
            calculate();
        });
    document
        .getElementById('add-overhead')
        .addEventListener('click', () => {
            addExpenseRow({}, true);
        });
    document
        .querySelectorAll('.indirect-chip')
        .forEach(button => {
            button.addEventListener('click', () => {
                addExpenseRow({
                    overhead_name: button.dataset.overheadPreset || ''
                }, true);
                document.querySelector('#overhead-list .expense-row .expense-cost')?.focus();
            });
        });
    document
        .querySelectorAll('.margin-preset')
        .forEach(button => {
            button.addEventListener('click', () => {
                document.getElementById(
                    'profit-margin'
                ).value = button.dataset.margin || '0';
                calculate();
            });
        });
    document
        .getElementById('materials-list')
        .addEventListener('click', event => {
            const button =
                event.target.closest('.remove-row');
            if (!button) return;
            button.closest('.material-row')?.remove();
            calculate();
        });
    document
        .getElementById('overhead-list')
        .addEventListener('click', event => {
            const button =
                event.target.closest('.remove-row');
            if (!button) return;
            button.closest('.expense-row')?.remove();
            calculate();
        });
    document.getElementById('materials-list').addEventListener('change', event => {
        if (!event.target.matches('.material-quantity')) return;
        const input = event.target;
        if (input.value.trim() === '') return;
        const value = Number(input.value);
        if (Number.isFinite(value) && value >= 0) {
            input.value = value.toFixed(2);
            calculate();
        }
    });
    form.addEventListener('input', event => {
        clearFieldError(event.target);
        calculate();
    });
    form.addEventListener('change', event => {
        clearFieldError(event.target);
        calculate();
    });
    productImageInput.addEventListener('change', () => {
        const file = productImageInput.files?.[0];
        if (!file) { discardPendingImage(); return; }
        if (
            ![
                'image/jpeg',
                'image/png',
                'image/webp'
            ].includes(file.type)
        ) {
            discardPendingImage();
            showFieldError(
                productImageInput,
                'Use a JPG, PNG, or WEBP image.'
            );
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            discardPendingImage();
            showFieldError(
                productImageInput,
                'The product image must be 5 MB or smaller.'
            );
            return;
        }
        if (imagePreviewUrl) {
            URL.revokeObjectURL(imagePreviewUrl);
        }
        imagePreviewUrl = URL.createObjectURL(file);
        renderModalImage(imagePreviewUrl, file.name, formatFileSize(file.size));
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        setStatus('');
        if (!validateForm()) {
            return;
        }
        const saveButton =
            document.getElementById('save-edit');
        saveButton.disabled = true;
        saveButton.textContent = 'Saving...';
        setStatus(
            'Updating saved calculation...'
        );
        try {
            const response = await fetch(
                window.location.href,
                {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        'X-Requested-With':
                            'XMLHttpRequest'
                    }
                }
            );
            const data = await response.json();
            if (!data.success) {
                throw new Error(
                    data.message
                    || 'Unable to update saved calculation.'
                );
            }
            setStatus(
                'Saved calculation updated successfully.',
                'success'
            );
            window.setTimeout(
                () => window.location.reload(),
                650
            );
        } catch (error) {
            setStatus(
                error.message
                || 'Unable to update saved calculation.',
                'error'
            );
        } finally {
            saveButton.disabled = false;
            saveButton.textContent = 'Save Changes';
        }
    });
})();
</script>
</body>
</html>
