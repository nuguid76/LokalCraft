<?php
require_once __DIR__ . '/seller_auth.php';
$activePage = 'pricing';
$sellerId = (int)$sellerId;
if (empty($_SESSION['materials_csrf'])) {
    $_SESSION['materials_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['materials_csrf'];
function jsonResponse(bool $success, string $message, array $extra = []): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra));
    exit;
}
$allowedUnits = ['pcs', 'mm', 'cm', 'm', 'g', 'kg', 'ml', 'L', 'roll', 'pack', 'set', 'pair', 'yard', 'sheet', 'bundle', 'box', 'bottle', 'spool'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals((string)($_SESSION['materials_csrf'] ?? ''), $_POST['csrf_token'])) {
            jsonResponse(false, 'Your session token expired. Please refresh the page and try again.');
        }
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create_material') {
            $name = trim((string)($_POST['material_name'] ?? ''));
            $referenceQuantity = filter_var($_POST['reference_quantity'] ?? null, FILTER_VALIDATE_FLOAT);
            $unit = trim((string)($_POST['unit'] ?? ''));
            $referencePrice = filter_var($_POST['reference_price'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($name === '') jsonResponse(false, 'Please enter the material name.');
            if ($referenceQuantity === false || !is_finite($referenceQuantity) || $referenceQuantity <= 0) jsonResponse(false, 'Reference Quantity must be greater than 0.');
            if (!in_array($unit, $allowedUnits, true)) jsonResponse(false, 'Please select a valid unit.');
            if ($referencePrice === false || !is_finite($referencePrice) || $referencePrice <= 0) jsonResponse(false, 'Reference Price must be greater than 0.');
            $referenceQuantity = round($referenceQuantity, 2);
            $referencePrice = round($referencePrice, 2);
            if ($referenceQuantity <= 0) jsonResponse(false, 'Reference Quantity must be at least 0.01.');
            if ($referencePrice <= 0) jsonResponse(false, 'Reference Price must be at least ₱0.01.');
            $duplicate = $conn->prepare('SELECT material_id FROM saved_materials WHERE seller_id = ? AND LOWER(material_name) = LOWER(?) LIMIT 1');
            $duplicate->bind_param('is', $sellerId, $name);
            $duplicate->execute();
            $exists = $duplicate->get_result()->fetch_assoc();
            $duplicate->close();
            if ($exists) jsonResponse(false, 'You already have a material with this name.');
            $stmt = $conn->prepare(
                'INSERT INTO saved_materials (seller_id, material_name, reference_quantity, unit, reference_price)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('isdsd', $sellerId, $name, $referenceQuantity, $unit, $referencePrice);
            $stmt->execute();
            $newId = (int)$conn->insert_id;
            $stmt->close();
            jsonResponse(true, 'Material added successfully.', [
                'material' => [
                    'material_id' => $newId,
                    'material_name' => $name,
                    'reference_quantity' => $referenceQuantity,
                    'unit' => $unit,
                    'reference_price' => $referencePrice,
                ],
            ]);
        }
        if ($action === 'update_material') {
            $materialId = (int)($_POST['material_id'] ?? 0);
            $name = trim((string)($_POST['material_name'] ?? ''));
            $referenceQuantity = filter_var($_POST['reference_quantity'] ?? null, FILTER_VALIDATE_FLOAT);
            $unit = trim((string)($_POST['unit'] ?? ''));
            $referencePrice = filter_var($_POST['reference_price'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($materialId <= 0) jsonResponse(false, 'Material not found.');
            if ($name === '') jsonResponse(false, 'Please enter the material name.');
            if ($referenceQuantity === false || !is_finite($referenceQuantity) || $referenceQuantity <= 0) jsonResponse(false, 'Reference Quantity must be greater than 0.');
            if (!in_array($unit, $allowedUnits, true)) jsonResponse(false, 'Please select a valid unit.');
            if ($referencePrice === false || !is_finite($referencePrice) || $referencePrice <= 0) jsonResponse(false, 'Reference Price must be greater than 0.');
            $referenceQuantity = round($referenceQuantity, 2);
            $referencePrice = round($referencePrice, 2);
            if ($referenceQuantity <= 0) jsonResponse(false, 'Reference Quantity must be at least 0.01.');
            if ($referencePrice <= 0) jsonResponse(false, 'Reference Price must be at least ₱0.01.');
            $duplicate = $conn->prepare('SELECT material_id FROM saved_materials WHERE seller_id = ? AND LOWER(material_name) = LOWER(?) AND material_id <> ? LIMIT 1');
            $duplicate->bind_param('isi', $sellerId, $name, $materialId);
            $duplicate->execute();
            $exists = $duplicate->get_result()->fetch_assoc();
            $duplicate->close();
            if ($exists) jsonResponse(false, 'Another saved material already uses this name.');
            $stmt = $conn->prepare(
                'UPDATE saved_materials
                 SET material_name = ?, reference_quantity = ?, unit = ?, reference_price = ?
                 WHERE material_id = ? AND seller_id = ?'
            );
            $stmt->bind_param('sdsdii', $name, $referenceQuantity, $unit, $referencePrice, $materialId, $sellerId);
            $stmt->execute();
            $stmt->close();
            jsonResponse(true, 'Material updated successfully.', [
                'material' => [
                    'material_id' => $materialId,
                    'material_name' => $name,
                    'reference_quantity' => $referenceQuantity,
                    'unit' => $unit,
                    'reference_price' => $referencePrice,
                ],
            ]);
        }
        if ($action === 'delete_material') {
            $materialId = (int)($_POST['material_id'] ?? 0);
            if ($materialId <= 0) jsonResponse(false, 'Material not found.');
            $stmt = $conn->prepare('DELETE FROM saved_materials WHERE material_id = ? AND seller_id = ?');
            $stmt->bind_param('ii', $materialId, $sellerId);
            $stmt->execute();
            $deleted = $stmt->affected_rows > 0;
            $stmt->close();
            if (!$deleted) jsonResponse(false, 'Material not found or already removed.');
            jsonResponse(true, 'Material removed from Materials Library.');
        }
        jsonResponse(false, 'Invalid request.');
    } catch (Throwable $e) {
        error_log('LokalCraft materials library error: ' . $e->getMessage());
        jsonResponse(false, 'Unable to complete the request right now. Please try again.');
    }
}
$materials = [];
$pageError = '';
try {
    $stmt = $conn->prepare(
        'SELECT material_id, material_name, reference_quantity, unit, reference_price
         FROM saved_materials
         WHERE seller_id = ?
         ORDER BY material_name ASC, material_id DESC'
    );
    $stmt->bind_param('i', $sellerId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $materials[] = $row;
    }
    $stmt->close();
} catch (Throwable $e) {
    error_log('LokalCraft materials library load error: ' . $e->getMessage());
    $pageError = 'Unable to load saved materials right now.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>LokalCraft | Materials Library</title>
    <style>
:root {
    --bg: #f6f3ef;
    --panel: #ffffff;
    --line: #e7ded5;
    --text: #332c27;
    --muted: #7b746e;
    --soft: #fbf8f5;
    --orange: #ea8a3a;
    --orange-dark: #c96d20;
    --orange-soft: #fff1e3;
    --brown: #5a4032;
    --brown-dark: #3f2c23;
    --green: #2f8f62;
    --red: #c94f45;
    --shadow: 0 8px 22px rgba(76, 50, 32, .06);
}
* { box-sizing: border-box; }
body { margin: 0; font-family: "Segoe UI", Arial, sans-serif; background: var(--bg); color: var(--text); }
body.modal-open { overflow: hidden; }
.page {
    display: block !important;
    min-height: 100vh;
    background: linear-gradient(180deg, #f8f5f1 0%, #f3eee9 100%);
}
/* Follow the Seller Dashboard: scroll the document, not a fixed content pane. */
body .page > main.content.saved-materials-main {
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
    padding: var(--materials-header-bottom, 70px) 0 30px !important;
    overflow: visible !important;
    background: transparent !important;
}
.wrap {
    width: 100%;
    max-width: 1240px;
    min-width: 0;
    margin: 0 auto;
    padding: 8px 26px 20px;
}
.materials-page :is(.material-card, input, select) {
    scroll-margin-top: calc(var(--materials-header-bottom, 70px) + 14px);
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
.heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 18px;
}
h1 { margin: 0; font-size: 2.1rem; font-weight: 800; letter-spacing: -.7px; }
.subtitle { margin: 4px 0 0; color: var(--muted); font-size: .95rem; }
.top-actions { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
.materials-page button,
.materials-page .link-button,
.material-modal button {
    border: 1px solid var(--line);
    border-radius: 7px;
    background: #fff;
    color: #4f4f4f;
    padding: 9px 12px;
    font: inherit;
    font-size: .76rem;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
}
.materials-page .link-button:hover,
.materials-page button:hover,
.material-modal button:hover {
    border-color: #e8c19d;
    background: var(--orange-soft);
    color: var(--orange-dark);
}
.materials-page .primary,
.material-modal .primary {
    border-color: var(--orange);
    background: linear-gradient(135deg, var(--orange), #dc7c2e);
    color: #fff;
    box-shadow: 0 7px 16px rgba(234,138,58,.16);
}
.materials-page .primary:hover,
.material-modal .primary:hover {
    border-color: var(--orange-dark);
    background: linear-gradient(135deg, #df7d2e, var(--orange-dark));
    color: #fff;
}
/* Use the same action styling as Seller Profile's Edit Profile button. */
.materials-page a.link-button.back-to-costing {
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
    box-shadow: 0 7px 17px rgba(224,119,37,.12);
    transition: background .16s ease, box-shadow .16s ease;
}
.materials-page a.link-button.back-to-costing:hover {
    border-color: #d97a2c;
    background: #dc7b2b;
    color: #fff;
    box-shadow: 0 8px 19px rgba(224,119,37,.18);
}
.materials-page a.link-button.back-to-costing:focus-visible {
    outline: 3px solid #f9c89d;
    outline-offset: 3px;
}
.materials-page a.link-button.back-to-costing svg {
    width: 18px;
    height: 18px;
    flex: 0 0 18px;
}
.card {
    padding: 20px;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: rgba(255,255,255,.92);
    box-shadow: var(--shadow);
}
.form-grid {
    display: grid;
    grid-template-columns: minmax(220px,1.35fr) minmax(145px,.72fr) minmax(110px,.55fr) minmax(150px,.72fr) auto;
    gap: 12px;
    align-items: end;
}
.materials-page label,
.material-modal label {
    display: block;
    color: #4a4a4a;
    font-size: .75rem;
    font-weight: 700;
}
.materials-page input,
.materials-page select,
.material-modal input,
.material-modal select {
    width: 100%;
    min-height: 40px;
    margin-top: 6px;
    padding: 10px;
    border: 1px solid transparent;
    border-radius: 7px;
    background: #f1f1f1;
    color: #343434;
    font: inherit;
    font-size: .82rem;
    outline: none;
}
.materials-page input:focus,
.materials-page select:focus,
.material-modal input:focus,
.material-modal select:focus {
    border-color: #e6a261;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(234,138,58,.10);
}
.label-with-tip { display: inline-flex; align-items: center; gap: 6px; }
.info-tip-wrap { position: relative; display: inline-flex; }
.materials-page .info-tip {
    width: 19px;
    height: 19px;
    min-height: 19px;
    padding: 0;
    border-radius: 50%;
    border-color: #bbb5af;
    background: #fff;
    color: #69645f;
    font: 700 11px/1 "Segoe UI", Arial, sans-serif;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.tooltip-box {
    position: absolute;
    z-index: 50;
    left: 50%;
    bottom: calc(100% + 9px);
    transform: translateX(-50%);
    width: 260px;
    padding: 10px 11px;
    border-radius: 8px;
    background: #242424;
    color: #fff;
    font-size: .72rem;
    line-height: 1.45;
    font-weight: 400;
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    box-shadow: 0 8px 24px rgba(0,0,0,.18);
}
.tooltip-box::after {
    content: "";
    position: absolute;
    top: 100%;
    left: 50%;
    margin-left: -5px;
    border: 5px solid transparent;
    border-top-color: #242424;
}
.info-tip-wrap.show .tooltip-box { opacity: 1; visibility: visible; }
.formula-note {
    margin-top: 14px;
    padding: 11px 13px;
    border: 1px solid #eadfd4;
    border-radius: 9px;
    background: #fffaf4;
    color: #716356;
    font-size: .72rem;
    line-height: 1.5;
}
.status { margin-top: 12px; font-size: .76rem; display: none; }
.status.show { display: block; }
.status.success { color: var(--green); }
.status.error { color: var(--red); }
.section-title {
    margin: 26px 0 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.section-title h2 { margin: 0; font-size: 1rem; }
.count {
    padding: 5px 9px;
    border-radius: 999px;
    background: #eee9e4;
    color: var(--muted);
    font-size: .68rem;
    font-weight: 800;
}
.materials-grid {
    display: grid;
    grid-template-columns: repeat(3,minmax(0,1fr));
    gap: 14px;
}
.material-card {
    min-width: 0;
    padding: 16px;
    border: 1px solid #e7e2dd;
    border-radius: 12px;
    background: rgba(255,255,255,.92);
    box-shadow: 0 7px 18px rgba(76,50,32,.04);
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}
.material-card:hover {
    transform: translateY(-2px);
    border-color: #e5c7a7;
    box-shadow: 0 12px 24px rgba(76,50,32,.08);
}
.material-name {
    color: #3d342e;
    font-size: .95rem;
    font-weight: 800;
    overflow-wrap: anywhere;
}
.material-detail {
    margin-top: 8px;
    color: #746d66;
    font-size: .75rem;
    line-height: 1.55;
}
.unit-cost {
    margin-top: 12px;
    padding: 10px 11px;
    border: 1px solid #f0d4b6;
    border-radius: 9px;
    background: var(--orange-soft);
    color: var(--orange-dark);
    font-size: .8rem;
    font-weight: 900;
}
.card-actions { display: flex; gap: 8px; margin-top: 12px; }
.card-actions button { flex: 1; padding: 8px 10px; }
.materials-page .danger {
    color: #b94c4c;
    background: #fff7f7;
    border-color: #efcdcd;
}
.materials-page .danger:hover {
    color: #a33d3d;
    background: #fff0ef;
    border-color: #e7b8b4;
}
.empty {
    padding: 38px 18px;
    text-align: center;
    color: #77716c;
    border: 1px dashed #ccc4bc;
    border-radius: 12px;
    background: rgba(255,255,255,.64);
}
.page-error {
    margin-bottom: 14px;
    padding: 10px 12px;
    border: 1px solid #efc5c5;
    border-radius: 8px;
    background: #fff3f3;
    color: #a23d3d;
    font-size: .78rem;
}
.material-modal {
    position: fixed;
    inset: 0;
    z-index: 120000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 22px;
}
.material-modal.open { display: flex; }
.material-modal-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(50,38,30,.50);
    backdrop-filter: blur(3px);
}
.material-modal-panel {
    position: relative;
    z-index: 1;
    width: min(620px,100%);
    max-height: calc(100vh - 44px);
    overflow-y: auto;
    padding: 20px;
    border: 1px solid #eadfd6;
    border-radius: 14px;
    background: #fffdfb;
    box-shadow: 0 24px 70px rgba(65,43,29,.20);
}
.material-modal-panel.confirm-panel { width: min(440px,100%); }
.material-modal-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 16px;
}
.material-modal-header h2 { margin: 0; color: #3d342e; font-size: 1.15rem; }
.material-modal-header p {
    margin: 5px 0 0;
    color: var(--muted);
    font-size: .76rem;
    line-height: 1.45;
}
.material-modal .material-modal-close {
    width: 34px;
    height: 34px;
    min-height: 34px;
    flex: 0 0 34px;
    padding: 0;
    border-radius: 50%;
    font-size: 20px;
    line-height: 1;
}
.modal-grid { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 12px; }
.modal-grid .full { grid-column: 1 / -1; }
.modal-preview {
    margin-top: 12px;
    padding: 10px 11px;
    border: 1px solid #eadfd4;
    border-radius: 9px;
    background: #fffaf4;
    color: #716356;
    font-size: .75rem;
}
.modal-status { min-height: 18px; margin-top: 10px; font-size: .75rem; }
.modal-status.error { color: var(--red); }
.modal-status.success { color: var(--green); }
.modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; }
.material-modal .danger-confirm {
    border-color: #c94b4b;
    background: #c94b4b;
    color: #fff;
}
.material-modal .danger-confirm:hover {
    border-color: #ad3f3f;
    background: #ad3f3f;
    color: #fff;
}
.delete-copy { margin: 0; color: #625d58; font-size: .82rem; line-height: 1.55; }
.delete-copy strong { color: #333; }
@media (max-width: 1080px) {
    .form-grid { grid-template-columns: repeat(2,minmax(0,1fr)); }
    .form-grid .primary { min-height: 40px; }
    .materials-grid { grid-template-columns: repeat(2,minmax(0,1fr)); }
}
@media (max-width: 760px) {
    body .page > main.content.saved-materials-main {
        width: 100% !important;
        margin-left: 0 !important;
    }
    .wrap { padding: 8px 12px 20px; }
    .heading { flex-direction: column; }
    .top-actions { width: 100%; justify-content: flex-start; }
    .form-grid,
    .materials-grid,
    .modal-grid { grid-template-columns: 1fr; }
    .modal-grid .full { grid-column: auto; }
    .materials-page .primary { min-height: 44px; }
    .tooltip-box {
        left: 0;
        transform: none;
        width: min(260px, calc(100vw - 54px));
    }
    .tooltip-box::after { left: 10px; margin-left: 0; }
    .material-modal { padding: 14px; }
    .modal-actions { flex-direction: column-reverse; }
    .modal-actions button { width: 100%; min-height: 44px; }
}
@media (max-width: 420px) {
    h1 { font-size: 1.75rem; }
}
/* Align Materials Library with Pricing & Costing and Saved Calculations. */
.tooltip-box { display: none; }
.info-tip-wrap.show .tooltip-box { display: block; }
.heading { align-items: center; gap: 8px; }
.heading h1 { color: #332c27; font-family: "Segoe UI", Arial, sans-serif; font-size: 2.1rem; font-weight: 800; letter-spacing: -.7px; line-height: 1.25; }
.top-actions { align-items: center; flex-wrap: nowrap; gap: 8px; }
.materials-page a.link-button.back-to-costing { width: auto; height: 43px; min-height: 43px; padding: 0 14px; border-color: #d77d32; border-radius: 12px; background: #e98b40; color: #fff; box-shadow: 0 5px 14px rgba(202,111,36,.13); gap: 7px; }
.materials-page a.link-button.back-to-costing .back-label { font-size: .78rem; font-weight: 750; white-space: nowrap; }
.materials-page a.link-button.back-to-costing:hover { border-color: #c8752d; background: #cd722c; color: #fff; }
.card, .material-card { border-color: #eadfd6; background: rgba(255,255,255,.94); box-shadow: 0 8px 22px rgba(76,50,32,.05); }
.material-card { display: flex; flex-direction: column; min-width: 0; cursor: pointer; }
.material-card:focus-visible { outline: 3px solid rgba(234,138,58,.35); outline-offset: 2px; }
.material-name { overflow-wrap: anywhere; }
.material-detail { overflow-wrap: anywhere; }
.unit-cost { overflow-wrap: anywhere; }
.card-actions { margin-top: auto; padding-top: 12px; }
.materials-page label, .material-modal label { font-size: .81rem; font-weight: 700; color: #625548; }
.materials-page input, .materials-page select, .material-modal input, .material-modal select { min-width: 0; min-height: 45px; padding: 10px 12px; border: 1px solid #e5d9cd; border-radius: 11px; background: #fdfbf9; color: #483c32; font-family: inherit; font-size: .91rem; font-weight: 400; }
.materials-page input:focus, .materials-page select:focus, .material-modal input:focus, .material-modal select:focus { border-color: #d99a67; background: #fff; box-shadow: 0 0 0 3px rgba(233,139,64,.1); }
.form-grid > label, .modal-grid > label { min-width: 0; }
.material-modal-panel { overscroll-behavior: contain; }
@media (max-width: 760px) {
    .heading { flex-direction: row; align-items: center; gap: 8px; }
    .heading h1 { font-size: 1.3rem; letter-spacing: -.3px; }
    .top-actions { width: auto; flex: 0 0 auto; justify-content: flex-end; margin-left: auto; }
    .materials-page a.link-button.back-to-costing { flex: 0 0 40px; width: 40px; height: 40px; min-height: 40px; padding: 0; border-radius: 10px; }
    .materials-page a.link-button.back-to-costing .back-label { display: none; }
    .form-grid { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 11px 9px; }
    .form-grid .material-name-field, .form-grid .material-price-field, .form-grid #save-material { grid-column: 1 / -1; }
    .form-grid .material-quantity-field, .form-grid .material-unit-field { grid-column: span 1; min-width: 0; }
    .materials-page .form-grid input, .materials-page .form-grid select, .material-modal input, .material-modal select { font-size: 16px !important; font-weight: 400; max-width: 100%; }
    .materials-page .primary { min-height: 44px; }
    .materials-grid { grid-template-columns: repeat(2,minmax(0,1fr)); gap: 9px; }
    .material-card { min-width: 0; padding: 11px; }
    .material-name { font-size: .82rem; line-height: 1.35; }
    .material-detail { font-size: .7rem; line-height: 1.5; }
    .unit-cost { font-size: .72rem; padding: 8px 9px; }
    .card-actions { gap: 6px; }
    .card-actions button { min-width: 0; padding: 9px 5px; font-size: .71rem; }
    /* Hidden tooltips must not expand document width on narrow screens. */
    .tooltip-box { display: none; left: 0; transform: none; }
    .info-tip-wrap.show .tooltip-box { display: block; }
    .modal-grid { grid-template-columns: repeat(2,minmax(0,1fr)); }
    .modal-grid .full { grid-column: 1 / -1; }
}
@media (max-width: 360px) {
    .heading h1 { font-size: 1.16rem; }
    .material-card { padding: 9px; }
    .card-actions button { font-size: .68rem; }
}

/* Keep app-defined form chrome in Safari/iOS instead of platform-default controls. */
.materials-page #material-form,
.material-modal { color-scheme: light; }
.materials-page #material-form input:not([type="hidden"]),
.materials-page #material-form select,
.material-modal input:not([type="hidden"]),
.material-modal select {
    -webkit-appearance: none;
    appearance: none;
    box-sizing: border-box;
    min-width: 0;
    max-width: 100%;
    border: 1px solid #e5d9cd;
    border-radius: 11px;
    background-color: #fdfbf9;
    background-clip: padding-box;
    color: #483c32;
    font-family: inherit;
    font-weight: 400;
    -webkit-text-size-adjust: 100%;
}
.materials-page #material-form select,
.material-modal select {
    padding-right: 34px;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23837569' stroke-width='2.3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 11px center;
    background-size: 16px 16px;
}
.materials-page #material-form input[type="number"]::-webkit-inner-spin-button,
.materials-page #material-form input[type="number"]::-webkit-outer-spin-button,
.material-modal input[type="number"]::-webkit-inner-spin-button,
.material-modal input[type="number"]::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
.materials-page #material-form input[type="number"],
.material-modal input[type="number"] { -moz-appearance: textfield; }
.materials-page #material-form input::placeholder,
.material-modal input::placeholder { color: #8c8680; opacity: 1; }
.materials-page #material-form input:focus,
.materials-page #material-form select:focus,
.material-modal input:focus,
.material-modal select:focus {
    border-color: #d99a67;
    background-color: #fff;
    box-shadow: 0 0 0 3px rgba(233,139,64,.10);
}
.materials-page #material-form input:-webkit-autofill,
.material-modal input:-webkit-autofill {
    -webkit-text-fill-color: #483c32;
    -webkit-box-shadow: 0 0 0 1000px #fdfbf9 inset;
    border-color: #e5d9cd;
}
/* Keep the modal inside the visible viewport when Safari's bars/keyboard change height. */
.material-modal { max-height: 100dvh; }
.material-modal-panel { max-height: calc(100dvh - 44px); overscroll-behavior: contain; }
@media (max-width: 760px) {
    .material-modal-panel { max-height: calc(100dvh - 28px); }
}
</style>
</head>
<body>
<div class="page">
    <?php require __DIR__ . '/seller_nav.php'; ?>
    <main class="content saved-materials-main">
        <?php require __DIR__ . '/seller_search.php'; ?>
        <div class="wrap materials-page">
            <div class="heading">
                <div>
                    <h1>Materials Library</h1>
                </div>
                <div class="top-actions">
                    <a class="link-button back-to-costing" href="calculator.php">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/><path d="M9 12h12"/></svg>
                        <span class="back-label">Back</span>
                    </a>
                </div>
            </div>
            <?php if ($pageError !== ''): ?>
                <div class="page-error"><?= htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <div class="card">
                <form id="material-form">
                    <div class="form-grid">
                        <label class="material-name-field">Material Name
                            <input id="material-name" type="text" maxlength="150" placeholder="e.g. Cotton Yarn" required />
                        </label>
                        <label class="material-quantity-field">
                            <span class="label-with-tip">Reference Quantity
                                <span class="info-tip-wrap">
                                    <button class="info-tip" type="button" aria-label="Reference quantity information">i</button>
                                    <span class="tooltip-box">Enter how much material you receive for the saved reference price. Example: if ₱100 buys 200 meters of yarn, enter 200.</span>
                                </span>
                            </span>
                            <input id="reference-quantity" type="number" min="0.01" step="0.01" placeholder="200" required />
                        </label>
                        <label class="material-unit-field">
                            <span class="label-with-tip">Unit
                                <span class="info-tip-wrap">
                                    <button class="info-tip" type="button" aria-label="Unit information">i</button>
                                    <span class="tooltip-box">Choose the unit you normally use when measuring this material. The costing calculator will ask for quantity used in this same unit.</span>
                                </span>
                            </span>
                            <select id="unit" required>
                                <option value="">Select unit</option>
                                <?php foreach ($allowedUnits as $unit): ?>
                                    <option value="<?= htmlspecialchars($unit, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($unit, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="material-price-field">
                            <span class="label-with-tip">Reference Price (₱)
                                <span class="info-tip-wrap">
                                    <button class="info-tip" type="button" aria-label="Reference price information">i</button>
                                    <span class="tooltip-box">Enter the amount you paid for the reference quantity. The calculator will divide this price by the reference quantity to get the cost per unit.</span>
                                </span>
                            </span>
                            <input id="reference-price" type="number" min="0.01" step="0.01" placeholder="100.00" required />
                        </label>
                        <button class="primary" id="save-material" type="submit">Save Material</button>
                    </div>
                    <div class="formula-note" id="formula-preview">Unit cost preview: —</div>
                    <div class="status" id="status" aria-live="polite"></div>
                </form>
            </div>
            <div class="section-title">
                <h2>Saved Materials</h2>
                <span class="count" id="material-count"><?= count($materials) ?> material<?= count($materials) === 1 ? '' : 's' ?></span>
            </div>
            <div class="empty" id="empty-state"<?= $materials ? ' style="display:none"' : '' ?>>No saved materials yet. Add your first material above, then return to Pricing &amp; Costing.</div>
            <div class="materials-grid" id="materials-grid">
                <?php foreach ($materials as $material):
                    $qty = (float)$material['reference_quantity'];
                    $price = (float)$material['reference_price'];
                    $unitCost = $qty > 0 ? $price / $qty : 0;
                ?>
                    <article class="material-card" role="button" tabindex="0"
                             aria-label="Edit <?= htmlspecialchars($material['material_name'], ENT_QUOTES, 'UTF-8') ?> material"
                             data-id="<?= (int)$material['material_id'] ?>"
                             data-name="<?= htmlspecialchars($material['material_name'], ENT_QUOTES, 'UTF-8') ?>"
                             data-quantity="<?= number_format($qty, 2, '.', '') ?>"
                             data-unit="<?= htmlspecialchars($material['unit'], ENT_QUOTES, 'UTF-8') ?>"
                             data-price="<?= number_format($price, 2, '.', '') ?>">
                        <div class="material-name"><?= htmlspecialchars($material['material_name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="material-detail">
                            Reference: <?= number_format($qty, 2) ?> <?= htmlspecialchars($material['unit'], ENT_QUOTES, 'UTF-8') ?><br />
                            Reference Price: ₱<?= number_format($price, 2) ?>
                        </div>
                        <div class="unit-cost">₱<?= number_format($unitCost, 2) ?> / <?= htmlspecialchars($material['unit'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="card-actions">
                            <button class="edit-material" type="button">Edit</button>
                            <button class="danger delete-material" type="button">Delete</button>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </main>
</div>
<div class="material-modal" id="edit-modal" aria-hidden="true">
    <div class="material-modal-backdrop" data-close-edit></div>
    <div class="material-modal-panel" role="dialog" aria-modal="true" aria-labelledby="edit-modal-title">
        <div class="material-modal-header">
            <div>
                <h2 id="edit-modal-title">Edit Material</h2>
                <p>Update this saved material without moving its values back into the Create Material form.</p>
            </div>
            <button class="material-modal-close" id="edit-modal-close" type="button" aria-label="Close">&times;</button>
        </div>
        <input type="hidden" id="edit-material-id" value="">
        <div class="modal-grid">
            <label class="full">Material Name
                <input id="edit-material-name" type="text" maxlength="150" required>
            </label>
            <label>Reference Quantity
                <input id="edit-reference-quantity" type="number" min="0.01" step="0.01" required>
            </label>
            <label>Unit
                <select id="edit-unit" required>
                    <option value="">Select unit</option>
                    <?php foreach ($allowedUnits as $unit): ?>
                        <option value="<?= htmlspecialchars($unit, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($unit, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="full">Reference Price (₱)
                <input id="edit-reference-price" type="number" min="0.01" step="0.01" required>
            </label>
        </div>
        <div class="modal-preview" id="edit-preview">Unit cost preview: —</div>
        <div class="modal-status" id="edit-status" aria-live="polite"></div>
        <div class="modal-actions">
            <button type="button" id="edit-cancel">Cancel</button>
            <button class="primary" type="button" id="edit-save">Save Changes</button>
        </div>
    </div>
</div>
<div class="material-modal" id="delete-modal" aria-hidden="true">
    <div class="material-modal-backdrop" data-close-delete></div>
    <div class="material-modal-panel confirm-panel" role="dialog" aria-modal="true" aria-labelledby="delete-modal-title">
        <div class="material-modal-header">
            <div>
                <h2 id="delete-modal-title">Delete Material?</h2>
                <p>This action removes the material from your Materials Library.</p>
            </div>
            <button class="material-modal-close" id="delete-modal-close" type="button" aria-label="Close">&times;</button>
        </div>
        <p class="delete-copy">Are you sure you want to delete <strong id="delete-material-name">this material</strong>? Existing saved costings will not be recalculated.</p>
        <div class="modal-status" id="delete-status" aria-live="polite"></div>
        <div class="modal-actions">
            <button type="button" id="delete-cancel">Cancel</button>
            <button class="danger-confirm" type="button" id="delete-confirm">Delete Material</button>
        </div>
    </div>
</div>
<script>
(() => {
    // Align Materials Library below the fixed shared Seller Search header.
    const materialsMain = document.querySelector('main.saved-materials-main');
    const searchHeader = materialsMain?.querySelector('.seller-header-tools');
    const syncHeaderSpace = () => {
        if (!materialsMain) return;
        const header = searchHeader || document.querySelector('.seller-header-tools');
        const rect = header?.getBoundingClientRect();
        const visible = !!header && getComputedStyle(header).display !== 'none' && rect.height > 0;
        const fallback = window.innerWidth <= 760 ? 76 : 70;
        const bottom = visible ? Math.max(0, rect.bottom) : fallback;
        materialsMain.style.setProperty('--materials-header-bottom', `${Math.ceil(bottom)}px`);
    };
    syncHeaderSpace();
    window.addEventListener('load', syncHeaderSpace);
    window.addEventListener('resize', syncHeaderSpace);
    if (searchHeader && typeof ResizeObserver !== 'undefined') {
        new ResizeObserver(syncHeaderSpace).observe(searchHeader);
    }
})();
(() => {
    const csrfToken = <?= json_encode($csrfToken) ?>;
    const form = document.getElementById('material-form');
    const nameInput = document.getElementById('material-name');
    const qtyInput = document.getElementById('reference-quantity');
    const unitInput = document.getElementById('unit');
    const priceInput = document.getElementById('reference-price');
    const saveButton = document.getElementById('save-material');
    const status = document.getElementById('status');
    const materialsGrid = document.getElementById('materials-grid');
    const emptyState = document.getElementById('empty-state');
    const materialCount = document.getElementById('material-count');
    const editModal = document.getElementById('edit-modal');
    const editId = document.getElementById('edit-material-id');
    const editName = document.getElementById('edit-material-name');
    const editQty = document.getElementById('edit-reference-quantity');
    const editUnit = document.getElementById('edit-unit');
    const editPrice = document.getElementById('edit-reference-price');
    const editStatus = document.getElementById('edit-status');
    const editSave = document.getElementById('edit-save');
    const deleteModal = document.getElementById('delete-modal');
    const deleteName = document.getElementById('delete-material-name');
    const deleteStatus = document.getElementById('delete-status');
    const deleteConfirm = document.getElementById('delete-confirm');
    let pendingDeleteCard = null;
    let editCloseTimer = null;
    // Each popup gets one same-page history entry so mobile/browser Back closes just the top popup.
    const modalStack = [];
    const modalStateKey = '__lokalcraft_materials_popup';
    let popClosePending = false;
    let modalSequence = 0;
    const numberFormatter = new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
    const formatNumber = value => numberFormatter.format(Number(value || 0));
    const formatMoney = value => `₱${formatNumber(value)}`;
    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>'"]/g, char => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#039;',
            '"': '&quot;'
        }[char]));
    }
    function showStatus(message, type = '') {
        status.className = message ? `status show ${type}`.trim() : 'status';
        status.textContent = message;
    }
    function setModalStatus(element, message = '', type = '') {
        element.className = `modal-status ${type}`.trim();
        element.textContent = message;
    }
    function updateCreatePreview() {
        const qty = Number(qtyInput.value || 0);
        const price = Number(priceInput.value || 0);
        const unit = unitInput.value || 'unit';
        const unitCost = qty > 0 ? price / qty : 0;
        document.getElementById('formula-preview').textContent = qty > 0 && price > 0
            ? `Unit cost preview: ${formatMoney(unitCost)} per ${unit}`
            : 'Unit cost preview: —';
    }
    function updateEditPreview() {
        const qty = Number(editQty.value || 0);
        const price = Number(editPrice.value || 0);
        const unit = editUnit.value || 'unit';
        const unitCost = qty > 0 ? price / qty : 0;
        document.getElementById('edit-preview').textContent = qty > 0 && price > 0
            ? `Unit cost preview: ${formatMoney(unitCost)} per ${unit}`
            : 'Unit cost preview: —';
    }
    function normalizeReferenceInput(input) {
        const raw = input.value.trim();
        if (raw === '') return;
        const value = Number(raw);
        if (Number.isFinite(value) && value >= 0) input.value = value.toFixed(2);
    }
    [qtyInput, priceInput, editQty, editPrice].forEach(input => {
        input.addEventListener('keydown', event => {
            if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
                event.preventDefault();
                const currentCents = Number.isFinite(Number(input.value)) ? Math.round(Number(input.value) * 100) : 0;
                const change = event.key === 'ArrowUp' ? 1 : -1;
                input.value = (Math.max(1, currentCents + change) / 100).toFixed(2);
                input.dispatchEvent(new Event('input', { bubbles: true }));
            } else if (event.key === 'Enter') {
                normalizeReferenceInput(input);
            }
        });
        input.addEventListener('change', () => normalizeReferenceInput(input));
        input.addEventListener('blur', () => normalizeReferenceInput(input));
    });
    function resetCreateForm() {
        form.reset();
        showStatus('');
        updateCreatePreview();
    }
    async function send(data) {
        data.append('csrf_token', csrfToken);
        const response = await fetch('./saved_materials.php', {
            method: 'POST',
            body: data,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const payload = await response.json();
        return payload;
    }
    function currentMaterialCount() {
        return materialsGrid.querySelectorAll('.material-card').length;
    }
    function updateCountAndEmptyState() {
        const count = currentMaterialCount();
        materialCount.textContent = `${count} material${count === 1 ? '' : 's'}`;
        emptyState.style.display = count === 0 ? 'block' : 'none';
    }
    function fillCard(card, material) {
        const qty = Number(material.reference_quantity || 0);
        const price = Number(material.reference_price || 0);
        const unitCost = qty > 0 ? price / qty : 0;
        card.dataset.id = String(material.material_id);
        card.setAttribute('role', 'button');
        card.tabIndex = 0;
        card.setAttribute('aria-label', `Edit ${String(material.material_name || '')} material`);
        card.dataset.name = String(material.material_name || '');
        card.dataset.quantity = Number.isFinite(qty) ? qty.toFixed(2) : '';
        card.dataset.unit = String(material.unit || '');
        card.dataset.price = Number.isFinite(price) ? price.toFixed(2) : '';
        card.innerHTML = `
            <div class="material-name">${escapeHtml(material.material_name)}</div>
            <div class="material-detail">
                Reference: ${formatNumber(qty)} ${escapeHtml(material.unit)}<br>
                Reference Price: ${formatMoney(price)}
            </div>
            <div class="unit-cost">${formatMoney(unitCost)} / ${escapeHtml(material.unit)}</div>
            <div class="card-actions">
                <button class="edit-material" type="button">Edit</button>
                <button class="danger delete-material" type="button">Delete</button>
            </div>
        `;
    }
    function upsertMaterialCard(material) {
        let card = materialsGrid.querySelector(`.material-card[data-id="${String(material.material_id)}"]`);
        if (!card) {
            card = document.createElement('article');
            card.className = 'material-card';
        }
        fillCard(card, material);
        const materialName = String(material.material_name || '');
        const followingCard = Array.from(materialsGrid.querySelectorAll('.material-card'))
            .find(other => other !== card && other.dataset.name.localeCompare(materialName, undefined, {sensitivity: 'base'}) > 0);
        materialsGrid.insertBefore(card, followingCard || null);
        updateCountAndEmptyState();
        return card;
    }
    function clearEditCloseTimer() {
        if (editCloseTimer !== null) {
            window.clearTimeout(editCloseTimer);
            editCloseTimer = null;
        }
    }
    function dismissPopup(entry) {
        const { modal, restoreFocus } = entry;
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        modal.style.removeProperty('z-index');
        if (modal === editModal) {
            clearEditCloseTimer();
            setModalStatus(editStatus);
        } else if (modal === deleteModal) {
            setModalStatus(deleteStatus);
            pendingDeleteCard = null;
            deleteConfirm.disabled = false;
            deleteConfirm.textContent = 'Delete Material';
        }
        if (modalStack.length === 0) document.body.classList.remove('modal-open');
        if (restoreFocus && restoreFocus.isConnected && typeof restoreFocus.focus === 'function') {
            restoreFocus.focus({ preventScroll: true });
        }
    }
    function openModal(modal) {
        if (modalStack.some(entry => entry.modal === modal)) return;
        const entry = {
            modal,
            restoreFocus: document.activeElement,
            token: `materials-${++modalSequence}`,
            historyManaged: false
        };
        modalStack.push(entry);
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        modal.style.zIndex = String(120000 + modalStack.length);
        document.body.classList.add('modal-open');
        try {
            const state = { ...(window.history.state || {}), [modalStateKey]: entry.token };
            window.history.pushState(state, '', window.location.href);
            entry.historyManaged = true;
        } catch (error) {
            // Embedded previews may disallow history updates; the X/Cancel controls still work.
        }
    }
    function closeModal(modal) {
        const entry = modalStack[modalStack.length - 1];
        if (!entry || entry.modal !== modal || popClosePending) return;
        if (entry.historyManaged && window.history.state?.[modalStateKey] === entry.token) {
            popClosePending = true;
            window.history.back();
        } else {
            modalStack.pop();
            dismissPopup(entry);
        }
    }
    window.addEventListener('popstate', () => {
        popClosePending = false;
        // Browser/Android Back closes exactly one popup and keeps the Materials Library page.
        const entry = modalStack.pop();
        if (entry) dismissPopup(entry);
    });
    function openEdit(card) {
        clearEditCloseTimer();
        editId.value = card.dataset.id || '';
        editName.value = card.dataset.name || '';
        editQty.value = card.dataset.quantity ? Number(card.dataset.quantity).toFixed(2) : '';
        editUnit.value = card.dataset.unit || '';
        editPrice.value = card.dataset.price ? Number(card.dataset.price).toFixed(2) : '';
        setModalStatus(editStatus);
        updateEditPreview();
        openModal(editModal);
        window.setTimeout(() => {
            if (editModal.classList.contains('open')) editName.focus({ preventScroll: true });
        }, 50);
    }
    function closeEdit() {
        closeModal(editModal);
    }
    function openDelete(card) {
        pendingDeleteCard = card;
        deleteName.textContent = card.dataset.name || 'this material';
        setModalStatus(deleteStatus);
        openModal(deleteModal);
    }
    function closeDelete() {
        closeModal(deleteModal);
    }
    form.addEventListener('submit', async event => {
        event.preventDefault();
        normalizeReferenceInput(qtyInput);
        normalizeReferenceInput(priceInput);
        const data = new FormData();
        data.append('action', 'create_material');
        data.append('material_name', nameInput.value.trim());
        data.append('reference_quantity', qtyInput.value);
        data.append('unit', unitInput.value);
        data.append('reference_price', priceInput.value);
        saveButton.disabled = true;
        saveButton.textContent = 'Saving...';
        showStatus('Saving material...');
        try {
            const result = await send(data);
            if (!result.success || !result.material) {
                throw new Error(result.message || 'Unable to save material.');
            }
            upsertMaterialCard(result.material);
            form.reset();
            updateCreatePreview();
            showStatus(result.message || 'Material added successfully.', 'success');
        } catch (error) {
            showStatus(error.message || 'Unable to save material.', 'error');
        } finally {
            saveButton.disabled = false;
            saveButton.textContent = 'Save Material';
        }
    });
    editSave.addEventListener('click', async () => {
        normalizeReferenceInput(editQty);
        normalizeReferenceInput(editPrice);
        const name = editName.value.trim();
        const quantity = Number(editQty.value || 0);
        const unit = editUnit.value;
        const price = Number(editPrice.value || 0);
        if (!editId.value) return setModalStatus(editStatus, 'Material not found.', 'error');
        if (!name) return setModalStatus(editStatus, 'Please enter the material name.', 'error');
        if (!Number.isFinite(quantity) || quantity <= 0) return setModalStatus(editStatus, 'Reference Quantity must be greater than 0.', 'error');
        if (!unit) return setModalStatus(editStatus, 'Please select a unit.', 'error');
        if (!Number.isFinite(price) || price <= 0) return setModalStatus(editStatus, 'Reference Price must be greater than 0.', 'error');
        const data = new FormData();
        data.append('action', 'update_material');
        data.append('material_id', editId.value);
        data.append('material_name', name);
        data.append('reference_quantity', String(quantity));
        data.append('unit', unit);
        data.append('reference_price', String(price));
        editSave.disabled = true;
        editSave.textContent = 'Saving...';
        setModalStatus(editStatus, 'Saving changes...');
        try {
            const result = await send(data);
            if (!result.success || !result.material) {
                throw new Error(result.message || 'Unable to update material.');
            }
            upsertMaterialCard(result.material);
            setModalStatus(editStatus, result.message || 'Material updated successfully.', 'success');
            editCloseTimer = window.setTimeout(() => { editCloseTimer = null; closeEdit(); }, 550);
        } catch (error) {
            setModalStatus(editStatus, error.message || 'Unable to update material.', 'error');
        } finally {
            editSave.disabled = false;
            editSave.textContent = 'Save Changes';
        }
    });
    deleteConfirm.addEventListener('click', async () => {
        if (!pendingDeleteCard) return;
        const card = pendingDeleteCard;
        const data = new FormData();
        data.append('action', 'delete_material');
        data.append('material_id', card.dataset.id || '');
        deleteConfirm.disabled = true;
        deleteConfirm.textContent = 'Deleting...';
        setModalStatus(deleteStatus, 'Removing material...');
        try {
            const result = await send(data);
            if (!result.success) {
                throw new Error(result.message || 'Unable to remove material.');
            }
            card.remove();
            updateCountAndEmptyState();
            closeDelete();
            showStatus(result.message || 'Material removed from Materials Library.', 'success');
        } catch (error) {
            setModalStatus(deleteStatus, error.message || 'Unable to remove material.', 'error');
            deleteConfirm.disabled = false;
            deleteConfirm.textContent = 'Delete Material';
        }
    });
    materialsGrid.addEventListener('click', event => {
        const card = event.target.closest('.material-card');
        if (!card) return;
        if (event.target.closest('.edit-material')) {
            openEdit(card);
            return;
        }
        if (event.target.closest('.delete-material')) {
            openDelete(card);
            return;
        }
        if (!event.target.closest('button, a, input, select, textarea')) openEdit(card);
    });
    materialsGrid.addEventListener('keydown', event => {
        if ((event.key === 'Enter' || event.key === ' ') && event.target.classList.contains('material-card')) {
            event.preventDefault();
            openEdit(event.target);
        }
    });
    document.getElementById('edit-modal-close').addEventListener('click', closeEdit);
    document.getElementById('edit-cancel').addEventListener('click', closeEdit);
    editModal.querySelector('[data-close-edit]').addEventListener('click', closeEdit);
    document.getElementById('delete-modal-close').addEventListener('click', closeDelete);
    document.getElementById('delete-cancel').addEventListener('click', closeDelete);
    deleteModal.querySelector('[data-close-delete]').addEventListener('click', closeDelete);
    [qtyInput, priceInput, unitInput].forEach(input => {
        input.addEventListener('input', updateCreatePreview);
        input.addEventListener('change', updateCreatePreview);
    });
    [editQty, editPrice, editUnit].forEach(input => {
        input.addEventListener('input', updateEditPreview);
        input.addEventListener('change', updateEditPreview);
    });
    function bindTooltips() {
        const fitTooltipToScreen = wrap => {
            const box = wrap.querySelector('.tooltip-box');
            if (!box) return;
            const baseTransform = window.innerWidth <= 760 ? '' : 'translateX(-50%) ';
            box.style.transform = baseTransform || 'none';
            const rect = box.getBoundingClientRect();
            const offset = rect.right > window.innerWidth - 12
                ? window.innerWidth - 12 - rect.right
                : rect.left < 12 ? 12 - rect.left : 0;
            box.style.transform = `${baseTransform}translateX(${Math.round(offset)}px)`;
        };
        document.querySelectorAll('.info-tip-wrap').forEach(wrap => {
            const button = wrap.querySelector('.info-tip');
            let timer = null;
            wrap.addEventListener('mouseenter', () => {
                timer = window.setTimeout(() => { wrap.classList.add('show'); fitTooltipToScreen(wrap); }, 2700);
            });
            wrap.addEventListener('mouseleave', () => {
                if (timer) window.clearTimeout(timer);
                timer = null;
                if (!wrap.dataset.pinned) wrap.classList.remove('show');
            });
            button?.addEventListener('click', event => {
                event.stopPropagation();
                const willShow = !wrap.classList.contains('show') || !wrap.dataset.pinned;
                document.querySelectorAll('.info-tip-wrap').forEach(other => {
                    other.classList.remove('show');
                    delete other.dataset.pinned;
                });
                if (willShow) {
                    wrap.classList.add('show');
                    wrap.dataset.pinned = '1';
                    fitTooltipToScreen(wrap);
                }
            });
        });
    }
    document.addEventListener('click', event => {
        if (!event.target.closest('.info-tip-wrap')) {
            document.querySelectorAll('.info-tip-wrap').forEach(wrap => {
                wrap.classList.remove('show');
                delete wrap.dataset.pinned;
            });
        }
    });
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        const top = modalStack[modalStack.length - 1];
        if (top) {
            event.preventDefault();
            closeModal(top.modal);
        }
    });
    bindTooltips();
    updateCreatePreview();
    updateCountAndEmptyState();
})();
</script>
</body>
</html>
