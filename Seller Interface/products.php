<?php
require_once __DIR__ . '/seller_auth.php';
$activePage = 'products';
const MAX_PRODUCT_IMAGE_BYTES = 5242880;
const DEFAULT_IMAGE_SERVICE_URL = 'http://127.0.0.1:5001';
if (empty($_SESSION['seller_products_csrf'])) {
    $_SESSION['seller_products_csrf'] = bin2hex(random_bytes(32));
}
$message = '';
$messageType = '';
$products = [];
$savedCostings = [];
$customizationsByProduct = [];
$craftCategories = [];
$statusOptions = ['Available', 'Out of Stock', 'Archived'];
$reviewsReady = false;
$reviewsByProduct = [];
$reviewSummaryByProduct = [];
function redirectProducts(): never
{
    header('Location: products.php');
    exit;
}
function inventoryProductStatus(string $requestedStatus, int $stock, int $reservedStock = 0): string
{
    if ($requestedStatus === 'Archived') {
        return 'Archived';
    }
    return $stock > $reservedStock ? 'Available' : 'Out of Stock';
}
function syncSellerInventoryStatuses(mysqli $conn, int $sellerId): void
{
    // Reconcile stock-derived statuses on page load; never automatically unarchive.
    $stmt = $conn->prepare('
        UPDATE products
        SET status = CASE
            WHEN COALESCE(stock, 0) <= COALESCE(reserved_stock, 0)
                THEN \'Out of Stock\'
            ELSE \'Available\'
        END
        WHERE seller_id = ?
          AND status <> \'Archived\'
          AND (
            (COALESCE(stock, 0) <= COALESCE(reserved_stock, 0)
                AND status <> \'Out of Stock\')
            OR
            (COALESCE(stock, 0) > COALESCE(reserved_stock, 0)
                AND status <> \'Available\')
          )
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to refresh inventory statuses.');
    }
    $stmt->bind_param('i', $sellerId);
    $stmt->execute();
    $stmt->close();
}
/** Read the admin moderation notices without treating a seller's own archive as an admin removal. */
function loadSellerAdminRemovalDates(mysqli $conn, int $sellerId): array
{
    $dates = [];
    $noticePattern = '[LokalCraft Seller Notice] Seller #' . $sellerId . ' Product #%';
    $legacyPattern = '[LokalCraft Moderation] Product #%';
    $stmt = $conn->prepare(
        'SELECT m.message_text, m.sent_at
         FROM messages m
         INNER JOIN users moderator ON moderator.id = m.sender_id AND moderator.AdminOrNot = 1
         WHERE (m.sender_id = m.receiver_id AND m.message_text LIKE ?)
            OR (m.receiver_id = ? AND m.message_text LIKE ?)
         ORDER BY m.sent_at DESC, m.message_id DESC'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to check product moderation records.');
    }
    $stmt->bind_param('sis', $noticePattern, $sellerId, $legacyPattern);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $body = (string)$row['message_text'];
        $productId = 0;
        if (preg_match('/^\[LokalCraft Seller Notice\] Seller #(\d+) Product #(\d+) removed from marketplace\. Reason:/', $body, $match)
            && (int)$match[1] === $sellerId) {
            $productId = (int)$match[2];
        } elseif (preg_match('/^\[LokalCraft Moderation\] Product #(\d+)\b/', $body, $match)) {
            $productId = (int)$match[1];
        }
        if ($productId > 0 && !isset($dates[$productId])) {
            $dates[$productId] = (string)$row['sent_at'];
        }
    }
    $stmt->close();
    return $dates;
}
function isActiveAdminRemoval(string $status, ?string $imageUpdatedAt, ?string $adminRemovedAt): bool
{
    return $status === 'Archived'
        && $adminRemovedAt !== null
        && ($imageUpdatedAt === null || strcmp($imageUpdatedAt, $adminRemovedAt) <= 0);
}
function lockProductReservedStock(
    mysqli $conn,
    int $productId,
    int $sellerId,
    int $newStock
): int {
    $stmt = $conn->prepare('
        SELECT COALESCE(reserved_stock, 0) AS reserved_stock
        FROM products
        WHERE product_id = ? AND seller_id = ?
        LIMIT 1 FOR UPDATE
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to check reserved inventory.');
    }
    $stmt->bind_param('ii', $productId, $sellerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        throw new RuntimeException('Product not found.');
    }
    $reservedStock = max(0, (int)$row['reserved_stock']);
    if ($newStock < $reservedStock) {
        throw new RuntimeException(
            'Stock cannot be lower than the ' . $reservedStock . ' unit(s) currently reserved.'
        );
    }
    return $reservedStock;
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
function uploadProductPhoto(array $file, int $sellerId): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Unable to upload the product photo.');
    }
    if (($file['size'] ?? 0) > MAX_PRODUCT_IMAGE_BYTES) {
        throw new RuntimeException('Product photo must not exceed 5 MB.');
    }
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)$finfo->file($file['tmp_name']);
    if (!isset($allowedTypes[$mimeType])) {
        throw new RuntimeException('Product photo must be JPG, PNG, or WEBP.');
    }
    $uploadDirectory = __DIR__ . '/../uploads/products';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('Unable to create the product upload folder.');
    }
    $fileName = sprintf(
        'seller_%d_product_%s_%s.%s',
        $sellerId,
        date('Ymd_His'),
        bin2hex(random_bytes(6)),
        $allowedTypes[$mimeType]
    );
    $destination = $uploadDirectory . '/' . $fileName;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Unable to save the product photo.');
    }
    return 'uploads/products/' . $fileName;
}
function deleteProductPhoto(?string $photoPath): void
{
    $photoPath = trim((string)$photoPath);
    if ($photoPath === '' || !str_starts_with($photoPath, 'uploads/products/')) {
        return;
    }
    $filePath = __DIR__ . '/../' . $photoPath;
    if (is_file($filePath)) {
        @unlink($filePath);
    }
}
function productPhotoAbsolutePath(string $storedPath): string
{
    $storedPath = trim(str_replace('\\', '/', $storedPath));
    if ($storedPath === '' || !str_starts_with($storedPath, 'uploads/products/')) {
        throw new RuntimeException('Product image path is invalid.');
    }
    $path = __DIR__ . '/../' . $storedPath;
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Product image could not be read.');
    }
    return $path;
}
function imageServiceUrl(): string
{
    $configured = trim((string)getenv('LOKALCRAFT_IMAGE_SERVICE_URL'));
    if ($configured === '') {
        $configured = trim((string)getenv('IMAGE_SERVICE_URL'));
    }
    return rtrim($configured !== '' ? $configured : DEFAULT_IMAGE_SERVICE_URL, '/');
}
function nsfwServiceUrl(): string
{
    $configured = trim((string)getenv('LOKALCRAFT_NSFW_SERVICE_URL'));
    if ($configured === '') {
        $configured = trim((string)getenv('NSFW_SERVICE_URL'));
    }
    return rtrim(
        $configured !== '' ? $configured : 'http://127.0.0.1:5002',
        '/'
    );
}
function imageServiceRequest(string $url, array $options = []): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('AI image processing is not available on this server.');
    }
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('AI image processing could not be started.');
    }
    $defaults = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: LokalCraft-Products/1.0',
        ],
    ];
    curl_setopt_array($ch, $options + $defaults);
    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        error_log('LokalCraft image service cURL error: ' . $error);
        throw new RuntimeException('AI image processing is temporarily unavailable. Please try again.');
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        error_log('LokalCraft image service returned invalid JSON. HTTP ' . $status);
        throw new RuntimeException('AI image processing returned an invalid response.');
    }
    if ($status < 200 || $status >= 300 || empty($data['success'])) {
        error_log(
            'LokalCraft image service error. HTTP ' . $status . ': ' .
            trim((string)($data['message'] ?? 'Unknown error'))
        );
        throw new RuntimeException('AI image processing could not be completed. Please try again.');
    }
    return $data;
}
function moderateProductImage(string $storedPhotoPath): void
{
    $imagePath = productPhotoAbsolutePath($storedPhotoPath);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)($finfo->file($imagePath) ?: 'application/octet-stream');
    $response = imageServiceRequest(nsfwServiceUrl() . '/moderate', [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'image' => new CURLFile($imagePath, $mime, basename($imagePath)),
        ],
    ]);
    if (!array_key_exists('safe', $response)) {
        throw new RuntimeException(
            'The image safety check returned an invalid response. Please try again.'
        );
    }
    if (!(bool)$response['safe']) {
        throw new RuntimeException(
            'This product image cannot be uploaded because it was classified as unsafe. Please use another product image.'
        );
    }
}
function moderateTemporaryProductUpload(array $file): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Please choose a product image.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Unable to read the selected product image.');
    }
    if (($file['size'] ?? 0) > MAX_PRODUCT_IMAGE_BYTES) {
        throw new RuntimeException('Product photo must not exceed 5 MB.');
    }
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('The selected product image could not be verified.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmpPath);
    if (!isset($allowedTypes[$mime])) {
        throw new RuntimeException('Product photo must be JPG, PNG, or WEBP.');
    }
    $response = imageServiceRequest(nsfwServiceUrl() . '/moderate', [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'image' => new CURLFile(
                $tmpPath,
                $mime,
                basename((string)($file['name'] ?? 'product-image'))
            ),
        ],
    ]);
    if (!array_key_exists('safe', $response)) {
        throw new RuntimeException(
            'The image safety check returned an invalid response. Please try again.'
        );
    }
    return [
        'safe' => (bool)$response['safe'],
        'classification' => strtolower(trim((string)($response['classification'] ?? ''))),
        'score' => isset($response['score']) ? (float)$response['score'] : null,
    ];
}
function createProductEmbeddingData(string $storedPhotoPath): array
{
    $imagePath = productPhotoAbsolutePath($storedPhotoPath);
    $health = imageServiceRequest(imageServiceUrl() . '/health');
    if (strtolower(trim((string)($health['status'] ?? ''))) !== 'ready') {
        throw new RuntimeException('AI image processing is not ready. Please try again.');
    }
    $expectedModel = trim((string)($health['model'] ?? ''));
    $expectedDimensions = (int)($health['dimensions'] ?? 0);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)($finfo->file($imagePath) ?: 'application/octet-stream');
    $response = imageServiceRequest(imageServiceUrl() . '/embed', [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'image' => new CURLFile($imagePath, $mime, basename($imagePath)),
        ],
    ]);
    $embedding = $response['embedding'] ?? null;
    $model = trim((string)($response['model'] ?? ''));
    $dimensions = (int)($response['dimensions'] ?? 0);
    if ($expectedModel === '' || $model !== $expectedModel) {
        throw new RuntimeException('AI image processing used an unexpected model.');
    }
    if (!is_array($embedding) || !$embedding || $dimensions !== count($embedding)) {
        throw new RuntimeException('AI image processing returned an invalid embedding.');
    }
    if ($expectedDimensions > 0 && $dimensions !== $expectedDimensions) {
        throw new RuntimeException('AI image processing returned an unexpected embedding size.');
    }
    $clean = [];
    $sumSquares = 0.0;
    foreach ($embedding as $value) {
        if (!is_int($value) && !is_float($value)) {
            throw new RuntimeException('AI image processing returned invalid embedding values.');
        }
        $number = (float)$value;
        if (!is_finite($number)) {
            throw new RuntimeException('AI image processing returned invalid embedding values.');
        }
        $clean[] = $number;
        $sumSquares += $number * $number;
    }
    $norm = sqrt($sumSquares);
    if ($norm < 0.95 || $norm > 1.05) {
        throw new RuntimeException('AI image processing returned an invalid normalized embedding.');
    }
    $hash = hash_file('sha256', $imagePath);
    if ($hash === false) {
        throw new RuntimeException('Unable to fingerprint the product image.');
    }
    return [
        'embedding' => $clean,
        'image_hash' => $hash,
        'model_version' => $model,
    ];
}
function saveProductEmbedding(mysqli $conn, int $productId, array $embeddingData): void
{
    $embeddingJson = json_encode(
        array_values($embeddingData['embedding']),
        JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
    );
    if ($embeddingJson === false) {
        throw new RuntimeException('Unable to encode the product image embedding.');
    }
    $imageHash = (string)$embeddingData['image_hash'];
    $modelVersion = (string)$embeddingData['model_version'];
    $stmt = $conn->prepare(
        'INSERT INTO product_image_embeddings
            (product_id, embedding, image_hash, model_version, updated_at)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            embedding = VALUES(embedding),
            image_hash = VALUES(image_hash),
            model_version = VALUES(model_version),
            updated_at = NOW()'
    );
    $stmt->bind_param('isss', $productId, $embeddingJson, $imageHash, $modelVersion);
    $stmt->execute();
    $stmt->close();
}
function formatMoney(float $value): string
{
    return '₱' . number_format($value, 2);
}
/** Validate the optional seller-defined add-ons before changing any product data. */
function parseProductCustomizations($rawOptions): array
{
    if (!is_array($rawOptions) || count($rawOptions) > 5) {
        throw new RuntimeException('You can add up to 5 customization options per product.');
    }
    $options = [];
    $names = [];
    foreach ($rawOptions as $rawOption) {
        if (!is_array($rawOption) || !is_string($rawOption['name'] ?? null)) {
            throw new RuntimeException('Enter a valid name for each customization option.');
        }
        $name = trim((string)preg_replace('/\s+/u', ' ', $rawOption['name']));
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : preg_match_all('/./us', $name);
        if ($name === '' || $nameLength === false || $nameLength > 100 || preg_match('/[\x00-\x1f\x7f]/u', $name)) {
            throw new RuntimeException('Each customization name must be between 1 and 100 valid characters.');
        }
        $nameKey = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        if (isset($names[$nameKey])) {
            throw new RuntimeException('Customization names must be unique for each product.');
        }
        $names[$nameKey] = true;
        $price = $rawOption['price'] ?? null;
        if (!is_scalar($price) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', (string)$price)) {
            throw new RuntimeException('Enter a valid customization price from ₱0.00 to ₱99,999,999.99 (up to 2 decimal places).');
        }
        $requiresText = $rawOption['requires_text'] ?? '0';
        if (!in_array($requiresText, ['0', '1', 0, 1], true)) {
            throw new RuntimeException('Invalid customization instruction setting.');
        }
        $options[] = [
            'name' => $name,
            'price' => number_format((float)$price, 2, '.', ''),
            'requires_text' => (int)$requiresText,
        ];
    }
    return $options;
}

/** Persist all options within the product's existing DB transaction. */
function saveProductCustomizations(mysqli $conn, int $productId, array $options): void
{
    $existing = [];
    $stmt = $conn->prepare(
        'SELECT option_id, option_name FROM product_customization_options WHERE product_id = ? FOR UPDATE'
    );
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $existing[(string)$row['option_name']] = (int)$row['option_id'];
    }
    $stmt->close();

    $nextNames = array_column($options, 'name');
    $deleteStmt = $conn->prepare('DELETE FROM product_customization_options WHERE option_id = ? AND product_id = ?');
    foreach ($existing as $oldName => $optionId) {
        if (!in_array($oldName, $nextNames, true)) {
            $deleteStmt->bind_param('ii', $optionId, $productId);
            $deleteStmt->execute();
        }
    }
    $deleteStmt->close();

    $updateStmt = $conn->prepare(
        'UPDATE product_customization_options SET additional_price = ?, requires_text = ?, is_active = 1, sort_order = ? WHERE option_id = ? AND product_id = ?'
    );
    $insertStmt = $conn->prepare(
        'INSERT INTO product_customization_options (product_id, option_name, additional_price, requires_text, is_active, sort_order) VALUES (?, ?, ?, ?, 1, ?)'
    );
    foreach ($options as $index => $option) {
        $name = $option['name'];
        $price = $option['price'];
        $requiresText = $option['requires_text'];
        if (isset($existing[$name])) {
            $optionId = $existing[$name];
            $updateStmt->bind_param('siiii', $price, $requiresText, $index, $optionId, $productId);
            $updateStmt->execute();
        } else {
            $insertStmt->bind_param('issii', $productId, $name, $price, $requiresText, $index);
            $insertStmt->execute();
        }
    }
    $updateStmt->close();
    $insertStmt->close();
}

function maskReviewBuyer(string $firstName, string $lastName): string
{
    $firstName = trim($firstName);
    $lastName = trim($lastName);
    if ($firstName === '' && $lastName === '') {
        return 'Buyer';
    }
    if ($lastName === '') {
        return $firstName;
    }
    $initial = function_exists('mb_substr')
        ? mb_strtoupper(mb_substr($lastName, 0, 1))
        : strtoupper(substr($lastName, 0, 1));
    return ($firstName !== '' ? $firstName : 'Buyer') . ' ' . $initial . '.';
}
function reviewDate($value): string
{
    $timestamp = strtotime((string)$value);
    return $timestamp ? date('M d, Y', $timestamp) : '';
}
function formatCategory(string $category): string
{
    $category = trim($category);
    if ($category === '') {
        return 'Uncategorized';
    }
    if (preg_match('/[A-Z&]/', $category)) {
        return $category;
    }
    return ucwords(str_replace(['_', '-'], ' ', $category));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'moderate_product_image') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    try {
        $csrfToken = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals((string)($_SESSION['seller_products_csrf'] ?? ''), $csrfToken)) {
            throw new RuntimeException('Session expired. Refresh the page and try again.');
        }
        $result = moderateTemporaryProductUpload($_FILES['product_photo'] ?? []);
        if (!$result['safe']) {
            echo json_encode([
                'success' => true,
                'safe' => false,
                'message' => 'This image was flagged as unsafe because it may contain inappropriate or explicit content. Please choose a different product image.',
            ]);
            exit;
        }
        echo json_encode([
            'success' => true,
            'safe' => true,
            'message' => 'Image passed the safety check.',
        ]);
        exit;
    } catch (Throwable $e) {
        $rawMessage = trim($e->getMessage());
        $safePrefixes = [
            'Please choose a product image.',
            'Unable to read the selected product image.',
            'Product photo must not exceed 5 MB.',
            'The selected product image could not be verified.',
            'Product photo must be JPG, PNG, or WEBP.',
            'Session expired.',
            'AI image processing is temporarily unavailable.',
            'AI image processing returned an invalid response.',
            'AI image processing could not be completed.',
            'The image safety check returned an invalid response.',
        ];
        $safeMessage = false;
        foreach ($safePrefixes as $prefix) {
            if (str_starts_with($rawMessage, $prefix)) {
                $safeMessage = true;
                break;
            }
        }
        if (!$safeMessage) {
            error_log('LokalCraft live product moderation error: ' . $rawMessage);
        }
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'safe' => false,
            'message' => $safeMessage
                ? $rawMessage
                : 'The image safety check could not be completed. Please try another image.',
        ]);
        exit;
    }
}
try {
    $sellerId = (int)$sellerId;
    $craftCategories = loadCraftCategories($conn);
    if (!$craftCategories) {
        throw new RuntimeException('No craft categories are available right now.');
    }
    try {
        $reviewTableCheck = $conn->query("SHOW TABLES LIKE 'reviews'");
        $reviewsReady = $reviewTableCheck && $reviewTableCheck->num_rows > 0;
        if ($reviewTableCheck) {
            $reviewTableCheck->free();
        }
    } catch (Throwable $reviewCheckError) {
        $reviewsReady = false;
        error_log('LokalCraft review table check error: ' . $reviewCheckError->getMessage());
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrfToken = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals((string)($_SESSION['seller_products_csrf'] ?? ''), $csrfToken)) {
            throw new RuntimeException('Session expired. Refresh the page and try again.');
        }
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'create_product') {
            $customOptions = parseProductCustomizations($_POST['custom_options'] ?? []);
            $productName = trim((string)($_POST['product_name'] ?? ''));
            $craftCategory = trim((string)($_POST['craft_category'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $cost = max(0, (float)($_POST['cost'] ?? 0));
            $stock = max(0, (int)($_POST['stock'] ?? 0));
            $status = trim((string)($_POST['status'] ?? 'Available'));
            if ($productName === '') {
                throw new RuntimeException('Please enter the product name.');
            }
            if (!in_array($craftCategory, $craftCategories, true)) {
                throw new RuntimeException('Please select a valid craft category.');
            }
            if (!in_array($status, $statusOptions, true)) {
                $status = $statusOptions[0];
            }
            $status = inventoryProductStatus($status, $stock);
            $photoPath = uploadProductPhoto($_FILES['product_photo'] ?? [], $sellerId);
            if ($photoPath === null) {
                throw new RuntimeException('Please upload a product photo.');
            }
            try {
                moderateProductImage($photoPath);
            } catch (Throwable $moderationError) {
                deleteProductPhoto($photoPath);
                if ($moderationError instanceof RuntimeException) {
                    throw $moderationError;
                }
                error_log('LokalCraft product moderation error: ' . $moderationError->getMessage());
                throw new RuntimeException('The product image safety check could not be completed. Please try again.');
            }
            try {
                $embeddingData = createProductEmbeddingData($photoPath);
            } catch (Throwable $embeddingError) {
                deleteProductPhoto($photoPath);
                error_log('LokalCraft product embedding error: ' . $embeddingError->getMessage());
                throw new RuntimeException('The product image could not be prepared for visual matching. Please try again.');
            }
            try {
                $conn->begin_transaction();
                $stmt = $conn->prepare(
                    'INSERT INTO products
                    (seller_id, product_photo, product_name, craft_category, description, cost, stock, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
                );
                $stmt->bind_param(
                    'issssdis',
                    $sellerId,
                    $photoPath,
                    $productName,
                    $craftCategory,
                    $description,
                    $cost,
                    $stock,
                    $status
                );
                $stmt->execute();
                $productId = (int)$conn->insert_id;
                $stmt->close();
                saveProductEmbedding($conn, $productId, $embeddingData);
                saveProductCustomizations($conn, $productId, $customOptions);
                $conn->commit();
            } catch (Throwable $createError) {
                try {
                    $conn->rollback();
                } catch (Throwable $rollbackError) {
                }
                deleteProductPhoto($photoPath);
                throw $createError;
            }
            $_SESSION['product_message'] = 'Product created successfully.';
            $_SESSION['product_message_type'] = 'success';
            redirectProducts();
        }
        if ($action === 'update_product') {
            $customOptions = parseProductCustomizations($_POST['custom_options'] ?? []);
            $productId = (int)($_POST['product_id'] ?? 0);
            $productName = trim((string)($_POST['product_name'] ?? ''));
            $craftCategory = trim((string)($_POST['craft_category'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $cost = max(0, (float)($_POST['cost'] ?? 0));
            $stock = max(0, (int)($_POST['stock'] ?? 0));
            $status = trim((string)($_POST['status'] ?? 'Available'));
            if ($productId <= 0 || $productName === '') {
                throw new RuntimeException('Please complete the required product fields.');
            }
            if (!in_array($craftCategory, $craftCategories, true)) {
                throw new RuntimeException('Please select a valid craft category.');
            }
            if (!in_array($status, $statusOptions, true)) {
                $status = $statusOptions[0];
            }
            $requestedStatus = $status;
            $photoStmt = $conn->prepare(
                'SELECT product_photo, COALESCE(reserved_stock, 0) AS reserved_stock
                 FROM products
                 WHERE product_id = ? AND seller_id = ?
                 LIMIT 1'
            );
            $photoStmt->bind_param('ii', $productId, $sellerId);
            $photoStmt->execute();
            $existingProduct = $photoStmt->get_result()->fetch_assoc();
            $photoStmt->close();
            if (!$existingProduct) {
                throw new RuntimeException('Product not found.');
            }
            $reservedStock = max(0, (int)($existingProduct['reserved_stock'] ?? 0));
            if ($stock < $reservedStock) {
                throw new RuntimeException(
                    'Stock cannot be lower than the ' . $reservedStock . ' unit(s) currently reserved.'
                );
            }
            $newPhotoPath = uploadProductPhoto($_FILES['product_photo'] ?? [], $sellerId);
            $oldPhotoPath = trim((string)($existingProduct['product_photo'] ?? ''));
            if ($newPhotoPath === null && $oldPhotoPath === '') {
                throw new RuntimeException('Please upload a product photo.');
            }
            if ($newPhotoPath !== null) {
                try {
                    moderateProductImage($newPhotoPath);
                } catch (Throwable $moderationError) {
                    deleteProductPhoto($newPhotoPath);
                    if ($moderationError instanceof RuntimeException) {
                        throw $moderationError;
                    }
                    error_log('LokalCraft product moderation update error: ' . $moderationError->getMessage());
                    throw new RuntimeException('The new product image safety check could not be completed. Please try again.');
                }
                try {
                    $embeddingData = createProductEmbeddingData($newPhotoPath);
                } catch (Throwable $embeddingError) {
                    deleteProductPhoto($newPhotoPath);
                    error_log('LokalCraft product embedding update error: ' . $embeddingError->getMessage());
                    throw new RuntimeException('The new product image could not be prepared for visual matching. Please try again.');
                }
                try {
                    $conn->begin_transaction();
                    $currentReserved = lockProductReservedStock($conn, $productId, $sellerId, $stock);
                    $status = inventoryProductStatus($requestedStatus, $stock, $currentReserved);
                    $stmt = $conn->prepare(
                        'UPDATE products
                         SET product_photo = ?, product_name = ?, craft_category = ?, description = ?, cost = ?, stock = ?, status = ?
                         WHERE product_id = ? AND seller_id = ?'
                    );
                    $stmt->bind_param(
                        'ssssdisii',
                        $newPhotoPath,
                        $productName,
                        $craftCategory,
                        $description,
                        $cost,
                        $stock,
                        $status,
                        $productId,
                        $sellerId
                    );
                    $stmt->execute();
                    $stmt->close();
                    saveProductEmbedding($conn, $productId, $embeddingData);
                    saveProductCustomizations($conn, $productId, $customOptions);
                    $conn->commit();
                } catch (Throwable $updateImageError) {
                    try {
                        $conn->rollback();
                    } catch (Throwable $rollbackError) {
                    }
                    deleteProductPhoto($newPhotoPath);
                    throw $updateImageError;
                }
                if ($oldPhotoPath !== '' && $oldPhotoPath !== $newPhotoPath) {
                    deleteProductPhoto($oldPhotoPath);
                }
            } else {
                $conn->begin_transaction();
                try {
                    $currentReserved = lockProductReservedStock($conn, $productId, $sellerId, $stock);
                    $removalDates = loadSellerAdminRemovalDates($conn, $sellerId);
                    $currentStmt = $conn->prepare(
                        'SELECT p.status, e.updated_at AS image_updated_at
                         FROM products p
                         LEFT JOIN product_image_embeddings e ON e.product_id = p.product_id
                         WHERE p.product_id = ? AND p.seller_id = ? LIMIT 1'
                    );
                    $currentStmt->bind_param('ii', $productId, $sellerId);
                    $currentStmt->execute();
                    $currentProduct = $currentStmt->get_result()->fetch_assoc();
                    $currentStmt->close();
                    if (!$currentProduct) {
                        throw new RuntimeException('Product not found.');
                    }
                    if ($requestedStatus !== 'Archived' && isActiveAdminRemoval(
                        (string)$currentProduct['status'],
                        $currentProduct['image_updated_at'],
                        $removalDates[$productId] ?? null
                    )) {
                        throw new RuntimeException('Admin removed this listing. Upload a new product photo before making it available.');
                    }
                    $status = inventoryProductStatus($requestedStatus, $stock, $currentReserved);
                    $stmt = $conn->prepare(
                        'UPDATE products
                         SET product_name = ?, craft_category = ?, description = ?, cost = ?, stock = ?, status = ?
                         WHERE product_id = ? AND seller_id = ?'
                    );
                    $stmt->bind_param(
                        'sssdisii',
                        $productName,
                        $craftCategory,
                        $description,
                        $cost,
                        $stock,
                        $status,
                        $productId,
                        $sellerId
                    );
                    $stmt->execute();
                    $stmt->close();
                    saveProductCustomizations($conn, $productId, $customOptions);
                    $conn->commit();
                } catch (Throwable $updateError) {
                    $conn->rollback();
                    throw $updateError;
                }
            }
            $_SESSION['product_message'] = 'Product updated successfully.';
            $_SESSION['product_message_type'] = 'success';
            redirectProducts();
        }
        if ($action === 'delete_product') {
            $productId = (int)($_POST['product_id'] ?? 0);
            if ($productId <= 0) {
                throw new RuntimeException('Invalid product.');
            }
            $stmt = $conn->prepare(
                'SELECT product_photo FROM products WHERE product_id = ? AND seller_id = ? LIMIT 1'
            );
            $stmt->bind_param('ii', $productId, $sellerId);
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$product) {
                throw new RuntimeException('Product not found.');
            }
            $stmt = $conn->prepare(
                'DELETE FROM products WHERE product_id = ? AND seller_id = ?'
            );
            $stmt->bind_param('ii', $productId, $sellerId);
            $stmt->execute();
            $stmt->close();
            deleteProductPhoto($product['product_photo'] ?? null);
            $_SESSION['product_message'] = 'Product deleted successfully.';
            $_SESSION['product_message_type'] = 'success';
            redirectProducts();
        }
    }
    if (isset($_SESSION['product_message'])) {
        $message = (string)$_SESSION['product_message'];
        $messageType = (string)($_SESSION['product_message_type'] ?? 'success');
        unset($_SESSION['product_message'], $_SESSION['product_message_type']);
    }
    syncSellerInventoryStatuses($conn, $sellerId);
    $adminRemovalDates = loadSellerAdminRemovalDates($conn, $sellerId);
    $productStmt = $conn->prepare(
        'SELECT
            p.product_id,
            p.product_photo,
            p.product_name,
            p.craft_category,
            p.description,
            p.cost,
            p.stock,
            COALESCE(p.reserved_stock, 0) AS reserved_stock,
            GREATEST(p.stock - COALESCE(p.reserved_stock, 0), 0) AS available_stock,
            CASE
                WHEN p.status = \'Archived\' THEN \'Archived\'
                WHEN COALESCE(p.stock, 0) <= COALESCE(p.reserved_stock, 0) THEN \'Out of Stock\'
                ELSE \'Available\'
            END AS status,
            p.created_at,
            e.updated_at AS image_updated_at
         FROM products p
         LEFT JOIN product_image_embeddings e ON e.product_id = p.product_id
         WHERE p.seller_id = ?
         ORDER BY p.created_at DESC, p.product_id DESC'
    );
    $productStmt->bind_param('i', $sellerId);
    $productStmt->execute();
    $productResult = $productStmt->get_result();
    while ($row = $productResult->fetch_assoc()) {
        $productId = (int)$row['product_id'];
        $row['admin_removed'] = isActiveAdminRemoval(
            (string)$row['status'],
            $row['image_updated_at'],
            $adminRemovalDates[$productId] ?? null
        );
        $row['average_rating'] = 0.0;
        $row['review_count'] = 0;
        $products[] = $row;
        $reviewSummaryByProduct[$productId] = [
            'average_rating' => 0.0,
            'review_count' => 0,
        ];
    }
    $productStmt->close();
    $customStmt = $conn->prepare(
        'SELECT o.product_id, o.option_id, o.option_name, o.additional_price, o.requires_text
         FROM product_customization_options o
         INNER JOIN products p ON p.product_id = o.product_id
         WHERE p.seller_id = ? AND o.is_active = 1
         ORDER BY o.product_id, o.sort_order, o.option_id'
    );
    $customStmt->bind_param('i', $sellerId);
    $customStmt->execute();
    $customResult = $customStmt->get_result();
    while ($option = $customResult->fetch_assoc()) {
        $customizationsByProduct[(int)$option['product_id']][] = [
            'option_id' => (int)$option['option_id'],
            'name' => (string)$option['option_name'],
            'price' => (string)$option['additional_price'],
            'requires_text' => (int)$option['requires_text'],
        ];
    }
    $customStmt->close();
    if ($reviewsReady) {
        $summaryStmt = $conn->prepare(
            'SELECT
                product_id,
                COUNT(*) AS review_count,
                COALESCE(AVG(rating), 0) AS average_rating
             FROM reviews
             WHERE seller_id = ?
             GROUP BY product_id'
        );
        if ($summaryStmt) {
            $summaryStmt->bind_param('i', $sellerId);
            $summaryStmt->execute();
            $summaryResult = $summaryStmt->get_result();
            while ($summary = $summaryResult->fetch_assoc()) {
                $reviewSummaryByProduct[(int)$summary['product_id']] = [
                    'average_rating' => round((float)$summary['average_rating'], 1),
                    'review_count' => (int)$summary['review_count'],
                ];
            }
            $summaryStmt->close();
        }
        foreach ($products as &$productRow) {
            $productId = (int)$productRow['product_id'];
            $summary = $reviewSummaryByProduct[$productId] ?? [
                'average_rating' => 0.0,
                'review_count' => 0,
            ];
            $productRow['average_rating'] = (float)$summary['average_rating'];
            $productRow['review_count'] = (int)$summary['review_count'];
        }
        unset($productRow);
        $reviewStmt = $conn->prepare(
            'SELECT
                r.review_id,
                r.product_id,
                r.order_id,
                r.rating,
                r.review_text,
                r.created_at,
                u.firstname,
                u.lastname
             FROM reviews r
             LEFT JOIN users u ON u.id = r.buyer_id
             WHERE r.seller_id = ?
             ORDER BY r.created_at DESC, r.review_id DESC'
        );
        if ($reviewStmt) {
            $reviewStmt->bind_param('i', $sellerId);
            $reviewStmt->execute();
            $reviewResult = $reviewStmt->get_result();
            while ($review = $reviewResult->fetch_assoc()) {
                $productId = (int)$review['product_id'];
                $reviewsByProduct[$productId][] = [
                    'review_id' => (int)$review['review_id'],
                    'order_id' => (int)$review['order_id'],
                    'rating' => (int)$review['rating'],
                    'review_text' => trim((string)($review['review_text'] ?? '')),
                    'created_at' => (string)$review['created_at'],
                    'date_label' => reviewDate($review['created_at']),
                    'buyer_name' => maskReviewBuyer(
                        (string)($review['firstname'] ?? ''),
                        (string)($review['lastname'] ?? '')
                    ),
                ];
            }
            $reviewStmt->close();
        }
    }
    $costingStmt = $conn->prepare(
        'SELECT costing_id, product_title, craft_category, suggested_price
         FROM products_costing
         WHERE seller_id = ?
         ORDER BY created_at DESC, costing_id DESC'
    );
    $costingStmt->bind_param('i', $sellerId);
    $costingStmt->execute();
    $costingResult = $costingStmt->get_result();
    while ($row = $costingResult->fetch_assoc()) {
        $savedCostings[] = $row;
    }
    $costingStmt->close();
} catch (Throwable $e) {
    if ($e instanceof mysqli_sql_exception) {
        error_log('LokalCraft products database error: ' . $e->getMessage());
        $message = 'Unable to update or load products right now. Please try again.';
    } elseif ($e instanceof RuntimeException) {
        $message = $e->getMessage();
    } else {
        error_log('LokalCraft products error: ' . $e->getMessage());
        $message = 'Unable to update or load products right now. Please try again.';
    }
    $messageType = 'error';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>LokalCraft | Products</title>
  <style>
:root { --bg:#FFF8ED; --panel:#fff; --line:#e7ded5; --text:#332c27; --muted:#7b746e; --soft:#fbf8f5; --orange:#ea8a3a; --orange-dark:#c96d20; --orange-soft:#fff1e3; --cta-gradient:linear-gradient(135deg,#ea8a3a 0%,#dc7c2e 55%,#c96d20 100%); --cta-hover:linear-gradient(135deg,#df7d2e 0%,#b85e1d 100%); --brown:#5a4032; --brown-dark:#3f2c23; --green:#2f8f62; --green-soft:#edf8f1; --red:#c94f45; --red-soft:#fff1ef; --shadow:0 8px 22px rgba(76,50,32,.06); }
* { box-sizing:border-box; }
body { margin:0; font-family:"Segoe UI",Arial,sans-serif; background:var(--bg); color:var(--text); min-height:100vh; }
body.modal-open { overflow:hidden; }
.page { display:block!important; min-height:100vh; background:var(--bg); }
/* Match Seller Dashboard: the page scrolls naturally below the shared fixed search bar.
   Specificity intentionally overrides seller_nav.php and seller_search.php defaults. */
body .page > main.content.products-main {
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
    padding: var(--products-header-offset, 70px) 0 28px !important;
    overflow: visible !important;
    background: transparent !important;
}
.products-search-shell { min-width: 0; }
.products-wrap { width:100%; max-width:1600px; margin:0 auto; padding:8px 26px 24px; }
.page-header { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:18px; }
.page-title { margin:0; color:var(--text); font-size:2.1rem; font-weight:800; letter-spacing:-.7px; }
.add-btn { min-height:40px; border:1px solid var(--orange-dark); border-radius:8px; background:var(--cta-gradient); color:#fff; padding:9px 14px; font:inherit; font-size:.78rem; font-weight:800; cursor:pointer; box-shadow:0 7px 16px rgba(234,138,58,.18); }
.add-btn:hover { background:var(--cta-hover); }
.message { margin-bottom:16px; padding:11px 13px; border-radius:9px; font-size:.78rem; line-height:1.45; }
.message.success { border:1px solid #bee6ca; background:var(--green-soft); color:#27834f; }
.message.error { border:1px solid #efc0c0; background:var(--red-soft); color:var(--red); }
.product-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; }
.product-card { display:flex; flex-direction:column; min-width:0; overflow:hidden; border:1px solid #e7e2dd; border-radius:14px; background:rgba(255,255,255,.94); box-shadow:var(--shadow); cursor:pointer; outline:none; transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease; }
.product-card:hover,.product-card:focus-visible { transform:translateY(-2px); border-color:#e5c7a7; box-shadow:0 14px 28px rgba(76,50,32,.09); }
.product-card:focus-visible { outline:3px solid rgba(234,138,58,.18); outline-offset:2px; }
.product-image-wrap { position:relative; width:100%; aspect-ratio:1/.82; overflow:hidden; background:#eee9e4; }
.product-image { width:100%; height:100%; display:block; object-fit:cover; }
.placeholder-image { width:100%; height:100%; display:grid; place-items:center; background:linear-gradient(135deg,#ece7e2,#e2dbd4); color:#97908a; font-size:2rem; }
.product-card-status { position:absolute; top:10px; left:10px; }
.stock-tag { position:absolute; top:10px; right:10px; padding:5px 8px; border:1px solid rgba(255,255,255,.92); border-radius:999px; background:rgba(255,255,255,.95); color:var(--green); font-size:.62rem; font-weight:800; white-space:nowrap; }
.product-body { display:flex; flex:1; flex-direction:column; padding:14px 14px 15px; }
.category { align-self:flex-start; display:inline-flex; max-width:100%; margin-bottom:8px; padding:5px 8px; overflow:hidden; border-radius:999px; background:var(--orange-soft); color:var(--orange-dark); font-size:.63rem; font-weight:800; line-height:1.2; text-overflow:ellipsis; white-space:nowrap; }
.product-name { min-width:0; margin-bottom:7px; overflow:hidden; color:#342f2b; font-size:.98rem; font-weight:800; line-height:1.35; text-overflow:ellipsis; white-space:nowrap; }
.product-card-price { color:var(--orange-dark); font-size:1.02rem; font-weight:900; }
.product-card-hint { margin-top:9px; color:#8a837d; font-size:.61rem; line-height:1.35; }
.status { display:inline-flex; padding:5px 8px; border-radius:999px; font-size:.59rem; font-weight:800; text-transform:capitalize; white-space:nowrap; box-shadow:0 2px 8px rgba(0,0,0,.04); }
.status.available { background:var(--green-soft); color:var(--green); }
.status.out-of-stock { background:var(--orange-soft); color:var(--orange-dark); }
.status.archived { background:#f2f0ee; color:#77716b; }
.empty-state { padding:68px 20px; border:1px dashed #d4cbc3; border-radius:14px; background:rgba(255,255,255,.72); text-align:center; }
.empty-state h2 { margin:0 0 7px; font-size:1.15rem; }
.empty-state p { margin:0 0 18px; color:var(--muted); font-size:.8rem; }
.modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 120000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 22px;
    background: rgba(50,38,30,.50);
    backdrop-filter: blur(3px);
}
.modal-overlay.show { display: flex; }
.modal {
    position: relative;
    width: min(1120px,100%);
    max-height: calc(100vh - 44px);
    overflow: auto;
    border: 1px solid #eadfd6;
    border-radius: 18px;
    background: #fffdfb;
    box-shadow: 0 28px 80px rgba(65,43,29,.20);
}
.product-view-modal { width: min(980px,100%); }
.reviews-modal { width: min(760px,100%); }
.confirm-modal { width: min(470px,100%); }
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
.modal-header-copy { min-width: 0; }
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
    font-weight: 800;
}
.modal-subtitle {
    margin: 5px 0 0;
    max-width: 700px;
    color: var(--muted);
    font-size: .73rem;
    line-height: 1.5;
}
.close-modal {
    position: static;
    flex: 0 0 38px;
    width: 38px;
    height: 38px;
    display: grid;
    place-items: center;
    padding: 0;
    border: 1px solid #e4d8ce;
    border-radius: 50%;
    background: #fff;
    color: #55483f;
    font-size: 20px;
    line-height: 1;
    cursor: pointer;
}
.close-modal:hover {
    border-color: #e9b27c;
    background: var(--orange-soft);
    color: var(--orange-dark);
}
.product-form-shell {
    display: grid;
    grid-template-columns: minmax(0,1fr) minmax(280px,320px);
    gap: 20px;
    align-items: start;
    padding: 20px 22px 10px;
}
.product-form-layout {
    display: grid;
    gap: 18px;
    min-width: 0;
}
.product-listing-preview {
    position: sticky;
    top: 96px;
    min-width: 0;
}
.listing-preview-card {
    overflow: hidden;
    border: 1px solid #e7cdb9;
    border-radius: 15px;
    background:
        radial-gradient(circle at top right, rgba(234,138,58,.12), transparent 35%),
        linear-gradient(145deg,#fff9f4 0%,#f4e7dc 100%);
    box-shadow: 0 12px 28px rgba(80,51,31,.08);
}
.listing-preview-head {
    padding: 16px 16px 12px;
    border-bottom: 1px solid #ead5c5;
}
.listing-preview-kicker {
    color: #a86b3d;
    font-size: .61rem;
    font-weight: 900;
    letter-spacing: .08em;
}
.listing-preview-head h3 {
    margin: 5px 0 0;
    color: #4c382d;
    font-size: .95rem;
}
.listing-preview-media {
    position: relative;
    width: 100%;
    aspect-ratio: 1 / .78;
    overflow: hidden;
    background: #efe5dc;
}
.listing-preview-media img {
    width: 100%;
    height: 100%;
    display: none;
    object-fit: cover;
}
.listing-preview-media img.show { display: block; }
.listing-preview-placeholder {
    width: 100%;
    height: 100%;
    display: grid;
    place-items: center;
    padding: 18px;
    color: #9a887b;
    font-size: .72rem;
    text-align: center;
}
.listing-preview-placeholder.hidden { display: none; }
.listing-preview-status {
    position: absolute;
    top: 10px;
    left: 10px;
}
.listing-preview-stock {
    position: absolute;
    top: 10px;
    right: 10px;
    padding: 5px 8px;
    border: 1px solid rgba(255,255,255,.9);
    border-radius: 999px;
    background: rgba(255,255,255,.94);
    color: var(--green);
    font-size: .6rem;
    font-weight: 800;
}
.listing-preview-body { padding: 15px 16px 17px; }
.listing-preview-category {
    display: inline-flex;
    max-width: 100%;
    padding: 5px 8px;
    overflow: hidden;
    border-radius: 999px;
    background: var(--orange-soft);
    color: var(--orange-dark);
    font-size: .61rem;
    font-weight: 800;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.listing-preview-name {
    margin-top: 10px;
    color: #3e312a;
    font-size: 1rem;
    font-weight: 900;
    line-height: 1.35;
    overflow-wrap: anywhere;
}
.listing-preview-price {
    margin-top: 7px;
    color: var(--orange-dark);
    font-size: 1.35rem;
    font-weight: 900;
}
.product-section,
.product-view-section,
.reviews-section {
    padding: 18px;
    border: 1px solid #eadfd6;
    border-radius: 13px;
    background: #fff;
}
.quick-custom-list { display:grid; gap:10px; }
.quick-custom-row { padding:12px; border:1px solid #eadfd6; border-radius:10px; background:#fcfaf8; }
.quick-custom-head { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:10px; }
.quick-custom-head strong { color:#514136; font-size:.74rem; }
.quick-custom-remove { padding:5px 8px; border:1px solid #e6d7ce; border-radius:7px; background:#fff; color:#a55340; font:inherit; font-size:.7rem; font-weight:700; cursor:pointer; }
.quick-custom-remove:hover { background:#fff1ef; border-color:#ebc6c1; }
.quick-custom-fields { display:grid; grid-template-columns:minmax(0,1.6fr) minmax(140px,1fr); gap:10px; }
.quick-custom-check { display:flex!important; align-items:center; gap:8px; margin-top:12px; font-size:.69rem!important; font-weight:600!important; }
#product-modal .quick-custom-check input { width:17px; height:17px; min-height:17px; margin:0; flex:0 0 17px; accent-color:var(--orange); }
.quick-custom-add { min-height:37px; margin-top:11px; padding:7px 12px; border:1px solid var(--orange-dark); border-radius:8px; background:var(--cta-gradient); color:#fff; font:inherit; font-size:.74rem; font-weight:800; cursor:pointer; box-shadow:0 5px 12px rgba(234,138,58,.14); }
.quick-custom-add:hover:not(:disabled) { background:var(--cta-hover); }
.quick-custom-add:disabled { opacity:.55; cursor:not-allowed; }
.quick-custom-count { color:var(--muted); font-size:.67rem; font-weight:700; }
.quick-custom-error { display:none; margin-top:5px; color:var(--red); font-size:.65rem; line-height:1.35; }
.quick-custom-error.visible { display:block; }
#product-modal .quick-custom-invalid { border-color:var(--red)!important; background:#fff8f7!important; }
@media (max-width:580px) { .quick-custom-fields { grid-template-columns:1fr; } }
.product-section-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 15px;
}
.product-section-heading > div:first-child {
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
.product-section-heading h3 {
    margin: 0;
    color: #3d342e;
    font-size: .9rem;
}
.field-title-row, .quick-custom-heading-tools {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.field-title-row { min-height: 20px; }
.quick-custom-heading-tools { flex: 0 0 auto; }
.help-tip { position: relative; display: inline-flex; flex: 0 0 auto; }
.help-tip > summary {
    display: grid;
    place-items: center;
    width: 19px;
    height: 19px;
    padding: 0;
    border: 1px solid #dfc7b4;
    border-radius: 50%;
    background: #fff9f3;
    color: #9b643f;
    font: 700 12px/1 "Segoe UI", Arial, sans-serif;
    list-style: none;
    cursor: pointer;
}
.help-tip > summary::-webkit-details-marker { display: none; }
.help-tip > summary:focus-visible { outline: 2px solid var(--orange); outline-offset: 2px; }
.help-tip-copy {
    position: absolute;
    z-index: 20;
    top: calc(100% + 8px);
    right: 0;
    width: min(260px, calc(100vw - 54px));
    padding: 10px 12px;
    border: 1px solid #e7d5c7;
    border-radius: 9px;
    background: #fffdfb;
    color: #5d5148;
    box-shadow: 0 10px 28px rgba(54,39,25,.14);
    font-size: .68rem;
    font-weight: 500;
    line-height: 1.45;
}
.help-tip--up .help-tip-copy { top: auto; bottom: calc(100% + 8px); }
.form-grid {
    display: grid;
    grid-template-columns: repeat(2,minmax(0,1fr));
    gap: 14px;
}
.full { grid-column: 1 / -1; }
#product-modal label {
    display: block;
    color: #4a4a4a;
    font-size: .74rem;
    font-weight: 700;
}
#product-modal input,
#product-modal select,
#product-modal textarea {
    width: 100%;
    margin-top: 6px;
    padding: 10px 11px;
    border: 1px solid #e2d8d0;
    border-radius: 7px;
    background: #fff;
    color: #343434;
    font: inherit;
    font-size: .8rem;
    outline: none;
}
#product-modal input,
#product-modal select { min-height: 40px; }
#product-modal textarea { min-height: 118px; resize: vertical; }
#product-modal input:focus,
#product-modal select:focus,
#product-modal textarea:focus {
    border-color: #e7a563;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(234,138,58,.11);
}
.costing-note {
    margin-top: 7px;
    color: #8c837c;
    font-size: .62rem;
    line-height: 1.45;
}
.saved-costing-box {
    padding: 14px;
    border: 1px solid #eadfd6;
    border-radius: 10px;
    background: #fcfaf8;
}
.photo-editor {
    display: grid;
    grid-template-columns: 210px minmax(0,1fr);
    gap: 16px;
    align-items: start;
}
.photo-preview {
    width: 100%;
    aspect-ratio: 1 / 1;
    min-height: 210px;
    display: grid;
    place-items: center;
    overflow: hidden;
    padding: 0;
    border: 1px solid #e5ddd5;
    border-radius: 11px;
    background: #f3eee9;
}
.preview-image {
    width: 100%;
    height: 100%;
    min-height: 210px;
    display: none;
    object-fit: contain;
    background: #f7f3ef;
}
.preview-image.show { display: block; }
.photo-empty {
    color: #9b928a;
    font-size: .72rem;
    text-align: center;
    padding: 14px;
}
.photo-fields { min-width: 0; }
.modal-footer {
    position: sticky;
    bottom: 0;
    z-index: 11;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 9px;
    padding: 13px 22px;
    border-top: 1px solid #eee4dc;
    background: rgba(255,253,251,.97);
    backdrop-filter: blur(8px);
}
.cancel-btn,
.submit-btn {
    min-height: 40px;
    border-radius: 8px;
    padding: 9px 14px;
    font: inherit;
    font-size: .76rem;
    font-weight: 800;
    cursor: pointer;
}
.cancel-btn {
    border: 1px solid var(--line);
    background: #fff;
    color: #59544f;
}
.cancel-btn:hover {
    border-color: #e8c19d;
    background: var(--orange-soft);
    color: var(--orange-dark);
}
.submit-btn {
    border: 1px solid var(--orange-dark);
    background: var(--cta-gradient);
    color: #fff;
    box-shadow: 0 7px 16px rgba(234,138,58,.18);
}
.submit-btn:hover:not(:disabled) { background: var(--cta-hover); }
.submit-btn:disabled { opacity: .6; cursor: wait; }
.product-view-body {
    padding: 20px 22px 10px;
}
.product-view-layout {
    display: grid;
    grid-template-columns: 250px minmax(0,1fr);
    gap: 20px;
    align-items: start;
}
.product-view-media {
    overflow: hidden;
    width: 100%;
    aspect-ratio: 1 / 1;
    border: 1px solid #e5ddd5;
    border-radius: 11px;
    background: #f3eee9;
}
.product-view-image {
    width: 100%;
    height: 100%;
    display: none;
    object-fit: contain;
    background: #f7f3ef;
}
.product-view-image.show { display: block; }
.product-view-placeholder {
    width: 100%;
    height: 100%;
    min-height: 250px;
    display: grid;
    place-items: center;
    color: #b3aba4;
    font-size: 2.2rem;
}
.product-view-placeholder.hidden { display: none; }
.product-view-badges {
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
    margin-bottom: 10px;
}
.product-view-name {
    margin: 0;
    color: #2d2926;
    font-size: 1.35rem;
    font-weight: 800;
    line-height: 1.3;
}
.product-view-price {
    margin-top: 8px;
    color: var(--orange-dark);
    font-size: 1.35rem;
    font-weight: 900;
}
.product-view-description {
    margin-top: 12px;
    color: #655e58;
    font-size: .75rem;
    line-height: 1.6;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
.product-view-stats {
    display: grid;
    grid-template-columns: repeat(4,minmax(0,1fr));
    gap: 8px;
    margin-top: 16px;
}
.product-view-stat {
    padding: 10px;
    border: 1px solid #eee7e0;
    border-radius: 9px;
    background: #fbfaf8;
}
.product-view-stat span {
    display: block;
    color: #9a928b;
    font-size: .54rem;
    font-weight: 800;
    text-transform: uppercase;
}
.product-view-stat strong {
    display: block;
    margin-top: 4px;
    color: #4d4742;
    font-size: .72rem;
}
.product-view-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 16px;
}
.view-action {
    min-height: 38px;
    padding: 8px 12px;
    border: 1px solid #ded7d0;
    border-radius: 8px;
    background: #fff;
    color: #625b55;
    font: inherit;
    font-size: .67rem;
    font-weight: 800;
    cursor: pointer;
}
.view-action.primary {
    border-color: var(--orange-dark);
    background: var(--cta-gradient);
    color: #fff;
    box-shadow: 0 5px 12px rgba(234,138,58,.14);
}
.view-action.primary:hover { background: var(--cta-hover); }
.view-action.danger {
    border-color: #efcece;
    background: #fff6f6;
    color: #bd4c4c;
}
.view-action.danger:hover { background: #ffeded; }
.reviews-body {
    padding: 20px 22px 22px;
}
.reviews-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 14px;
    padding: 13px 14px;
    border: 1px solid #edd8c3;
    border-radius: 10px;
    background: #fff8f1;
}
.reviews-summary-score {
    color: #2f2a26;
    font-size: 1.35rem;
    font-weight: 800;
}
.reviews-summary-meta {
    margin-top: 3px;
    color: #827a73;
    font-size: .67rem;
}
.rating-stars {
    color: #e3a22e;
    letter-spacing: 1px;
    white-space: nowrap;
}
.reviews-list { display: grid; gap: 10px; }
.review-card {
    padding: 12px 13px;
    border: 1px solid #ece7e2;
    border-radius: 10px;
    background: #fff;
}
.review-card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 7px;
}
.review-buyer { color: #37322e; font-size: .75rem; font-weight: 800; }
.review-order { margin-top: 2px; color: #928a84; font-size: .61rem; }
.review-rating {
    color: #e3a22e;
    font-size: .78rem;
    letter-spacing: 1px;
    white-space: nowrap;
}
.review-text {
    color: #625b55;
    font-size: .7rem;
    line-height: 1.55;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
.review-date { margin-top: 7px; color: #a09992; font-size: .59rem; }
.reviews-empty {
    padding: 34px 12px;
    text-align: center;
    color: #8c857f;
    font-size: .73rem;
}
.confirm-body {
    padding: 20px 22px 4px;
}
.confirm-copy {
    margin: 0;
    color: #625d58;
    font-size: .82rem;
    line-height: 1.55;
}
.confirm-copy strong { color: #333; }
.confirm-warning {
    margin-top: 13px;
    padding: 10px 11px;
    border: 1px solid #efcbc7;
    border-radius: 9px;
    background: #fff7f6;
    color: #9f4a44;
    font-size: .67rem;
    line-height: 1.45;
}
.delete-confirm-form {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 16px 22px 20px;
}
.delete-confirm-btn {
    min-height: 40px;
    padding: 9px 14px;
    border: 1px solid #c94b4b;
    border-radius: 8px;
    background: #c94b4b;
    color: #fff;
    font: inherit;
    font-size: .76rem;
    font-weight: 800;
    cursor: pointer;
}
.delete-confirm-btn:hover { background: #ad3f3f; border-color: #ad3f3f; }
.image-safety-status {
    margin-top: 9px;
    padding: 9px 10px;
    border: 1px solid #e7ded5;
    border-radius: 8px;
    background: #fbfaf8;
    color: #7b746e;
    font-size: .64rem;
    font-weight: 700;
    line-height: 1.4;
}
.image-safety-status.checking {
    border-color: #efd2b4;
    background: #fff8f1;
    color: #9a642f;
}
.image-safety-status.safe {
    border-color: #bfe3ca;
    background: var(--green-soft);
    color: #27834f;
}
.image-safety-status.unsafe,
.image-safety-status.error {
    border-color: #efc0c0;
    background: var(--red-soft);
    color: var(--red);
}
.submit-btn.safety-blocked,
.submit-btn.safety-blocked:hover {
    border-color: #d9d2cc;
    background: #d9d2cc;
    color: #817a74;
    opacity: 1;
    cursor: not-allowed;
}
.image-warning-overlay {
    position: fixed;
    inset: 0;
    z-index: 180000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(44,31,24,.52);
    backdrop-filter: blur(3px);
}
.image-warning-overlay.show {
    display: flex;
}
.image-warning-modal {
    width: min(430px,100%);
    padding: 25px 23px 22px;
    border: 1px solid #efc6c2;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 24px 70px rgba(35,22,16,.22);
    text-align: center;
}
.image-warning-icon {
    width: 46px;
    height: 46px;
    display: grid;
    place-items: center;
    margin: 0 auto 12px;
    border-radius: 50%;
    background: var(--red-soft);
    color: var(--red);
    font-size: 1.35rem;
    font-weight: 900;
}
.image-warning-modal h2 {
    margin: 0;
    color: #342d29;
    font-size: 1.05rem;
}
.image-warning-modal p {
    margin: 8px 0 18px;
    color: #736b65;
    font-size: .76rem;
    line-height: 1.55;
}
.image-warning-close {
    min-height: 40px;
    padding: 9px 14px;
    border: 1px solid var(--orange-dark);
    border-radius: 8px;
    background: var(--cta-gradient);
    color: #fff;
    font: inherit;
    font-size: .72rem;
    font-weight: 800;
    cursor: pointer;
}
.image-warning-close:hover {
    background: var(--cta-hover);
}
@media (max-width:1180px) { .product-grid { grid-template-columns:repeat(3,minmax(0,1fr)); } }
@media (max-width:940px) { .product-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } .product-view-stats { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (max-width:760px) {
body .page > main.content.products-main {
    width: 100% !important;
    margin-left: 0 !important;
    padding-top: var(--products-header-offset, 76px) !important;
}
.products-wrap { max-width:none; padding:8px 12px 24px; }
.page-header { align-items:stretch; flex-direction:column; }
.add-btn { width:100%; min-height:44px; }
.product-view-layout { grid-template-columns:1fr; }
.product-view-media,.product-view-image,.product-view-placeholder { min-height:240px; max-height:320px; }
.form-grid { grid-template-columns:1fr; }
.full { grid-column:auto; }
.modal-overlay { align-items:flex-end; padding:10px; }
.modal { width:100%; max-height:calc(100vh - 20px); border-radius:16px 16px 10px 10px; }
.modal-header { padding:15px; }
.modal-body { padding:15px; }
.modal-footer { padding:12px 15px 15px; }
#product-modal input,#product-modal select,#product-modal textarea { font-size:16px; }
.cancel-btn,.submit-btn { flex:1; min-height:44px; }
}
@media (max-width:580px) {
.product-section-heading { flex-wrap:wrap; }
.quick-custom-heading-tools { margin-left:41px; }
.page-title { font-size:1.8rem; }
.product-grid { grid-template-columns:1fr; gap:12px; }
.product-image-wrap { aspect-ratio:16/10; }
.product-view-stats { grid-template-columns:1fr 1fr; }
.product-view-actions { flex-direction:column; }
.view-action,#view-delete-form,#view-delete-form .view-action { width:100%; }
.reviews-summary { align-items:flex-start; flex-direction:column; }
}
@media (max-width:920px) {
.product-form-shell { grid-template-columns:1fr; }
.product-listing-preview { position:static; }
}
@media (max-width:720px) {
.product-form-shell,.product-view-body,.reviews-body { padding-left:15px; padding-right:15px; }
.product-form-shell { padding-top:15px; }
.product-form-layout { gap:14px; }
.product-section,.product-view-section,.reviews-section { padding:15px; }
.photo-editor,.product-view-layout { grid-template-columns:1fr; }
.photo-preview { max-width:320px; min-height:220px; }
.modal-footer { align-items:stretch; flex-direction:column-reverse; padding-left:15px; padding-right:15px; }
.modal-footer .cancel-btn,.modal-footer .submit-btn { width:100%; min-height:44px; }
.delete-confirm-form { flex-direction:column-reverse; padding-left:15px; padding-right:15px; }
.delete-confirm-form .cancel-btn,.delete-confirm-form .delete-confirm-btn { width:100%; min-height:44px; }
}
@media (max-width:760px) {
    .products-wrap {
        width: 100%;
        max-width: none;
        margin: 0 auto;
    }
    .page-header {
        align-items: center;
        flex-direction: row;
        gap: 10px;
        margin-bottom: 14px;
    }
    .page-title {
        font-size: 1.65rem;
    }
    .add-btn {
        width: auto;
        min-height: 38px;
        flex: 0 0 auto;
        padding: 8px 10px;
        font-size: .68rem;
    }
    .product-grid {
        grid-template-columns: repeat(2,minmax(0,1fr)) !important;
        gap: 10px !important;
    }
    .product-card {
        min-width: 0;
        border-radius: 12px;
    }
    .product-image-wrap {
        aspect-ratio: 1 / .88 !important;
    }
    .product-card-status {
        top: 7px;
        left: 7px;
    }
    .stock-tag {
        top: 7px;
        right: 7px;
        max-width: calc(100% - 14px);
        padding: 4px 6px;
        font-size: .5rem;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .status {
        padding: 4px 6px;
        font-size: .49rem;
    }
    .product-body {
        padding: 9px 9px 10px;
    }
    .category {
        max-width: 100%;
        margin-bottom: 6px;
        padding: 4px 6px;
        font-size: .5rem;
    }
    .product-name {
        min-height: 2.5em;
        margin-bottom: 5px;
        display: -webkit-box;
        overflow: hidden;
        font-size: .72rem;
        line-height: 1.25;
        white-space: normal;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
    }
    .product-card-price {
        font-size: .83rem;
    }
    .product-card-hint {
        margin-top: 6px;
        font-size: .5rem;
        line-height: 1.3;
    }
}
@media (max-width:580px) {
    .product-grid {
        grid-template-columns: repeat(2,minmax(0,1fr)) !important;
        gap: 8px !important;
    }
    .product-image-wrap {
        aspect-ratio: 1 / .9 !important;
    }
    .product-body {
        padding: 8px;
    }
    .category {
        margin-bottom: 5px;
        padding: 4px 5px;
        font-size: .47rem;
    }
    .product-name {
        min-height: 2.45em;
        font-size: .68rem;
    }
    .product-card-price {
        font-size: .8rem;
    }
    .product-card-hint {
        display: none;
    }
}
@media (max-width:400px) {
    .products-wrap {
        padding-left: 9px;
        padding-right: 9px;
    }
    .page-header {
        align-items: flex-start;
    }
    .page-title {
        font-size: 1.5rem;
    }
    .add-btn {
        min-height: 36px;
        padding: 7px 9px;
        font-size: .62rem;
    }
    .product-grid {
        gap: 7px !important;
    }
    .product-body {
        padding: 7px;
    }
    .product-name {
        font-size: .64rem;
    }
    .product-card-price {
        font-size: .76rem;
    }
    .stock-tag,
    .status,
    .category {
        font-size: .45rem;
    }
}
</style>
</head>
<body>
  <div class="page">
    <?php require __DIR__ . '/seller_nav.php'; ?>
<main class="content products-main">
      <div class="products-search-shell" id="productsSearchShell"><?php require __DIR__ . '/seller_search.php'; ?></div>
      <div class="products-wrap">
        <div class="page-header">
          <div>
            <h1 class="page-title">Products</h1>
          </div>
          <button class="add-btn" id="open-add-product" type="button">+ Add Product</button>
        </div>
        <?php if ($message !== ''): ?>
          <div class="message <?= htmlspecialchars($messageType) ?>">
            <?= htmlspecialchars($message) ?>
          </div>
        <?php endif; ?>
        <?php if (count($products) === 0): ?>
          <div class="empty-state">
            <h2>No Products Yet</h2>
            <p>Create your first product and add it to your LokalCraft inventory.</p>
            <button class="add-btn" id="open-empty-add" type="button">+ Add Product</button>
          </div>
        <?php else: ?>
          <div class="product-grid">
            <?php foreach ($products as $product): ?>
              <?php
                $productStatusClass = strtolower(str_replace(' ', '-', (string)$product['status']));
                $productStatusLabel = (string)$product['status'] === 'Archived'
                  ? (!empty($product['admin_removed']) ? 'Removed by Admin' : 'Seller Archived')
                  : (string)$product['status'];
                $description = trim((string)($product['description'] ?? ''));
              ?>
              <article
                class="product-card"
                tabindex="0"
                role="button"
                aria-label="View <?= htmlspecialchars($product['product_name'], ENT_QUOTES) ?>"
                data-product-id="<?= (int)$product['product_id'] ?>"
                data-product-name="<?= htmlspecialchars($product['product_name'], ENT_QUOTES) ?>"
                data-category="<?= htmlspecialchars($product['craft_category'], ENT_QUOTES) ?>"
                data-category-label="<?= htmlspecialchars(formatCategory($product['craft_category']), ENT_QUOTES) ?>"
                data-description="<?= htmlspecialchars($description, ENT_QUOTES) ?>"
                data-cost="<?= htmlspecialchars((string)$product['cost'], ENT_QUOTES) ?>"
                data-stock="<?= (int)$product['stock'] ?>"
                data-available-stock="<?= (int)$product['available_stock'] ?>"
                data-reserved-stock="<?= (int)$product['reserved_stock'] ?>"
                data-status="<?= htmlspecialchars($product['status'], ENT_QUOTES) ?>"
                data-admin-removed="<?= !empty($product['admin_removed']) ? '1' : '0' ?>"
                data-photo="<?= htmlspecialchars($product['product_photo'] ?? '', ENT_QUOTES) ?>"
                data-average-rating="<?= number_format((float)$product['average_rating'], 1, '.', '') ?>"
                data-review-count="<?= (int)$product['review_count'] ?>"
              >
                <div class="product-image-wrap">
                  <?php if (!empty($product['product_photo'])): ?>
                    <img
                      class="product-image"
                      src="../<?= htmlspecialchars($product['product_photo']) ?>"
                      alt="<?= htmlspecialchars($product['product_name']) ?>"
                      loading="lazy"
                    >
                  <?php else: ?>
                    <div class="placeholder-image" aria-label="No product image">🛍</div>
                  <?php endif; ?>
                  <div class="product-card-status">
                    <span class="status <?= htmlspecialchars($productStatusClass) ?>">
                      <?= htmlspecialchars($productStatusLabel) ?>
                    </span>
                  </div>
                  <span class="stock-tag">
                    <?= (int)$product['available_stock'] ?> available
                  </span>
                </div>
                <div class="product-body">
                  <span class="category">
                    <?= htmlspecialchars(formatCategory($product['craft_category'])) ?>
                  </span>
                  <div class="product-name">
                    <?= htmlspecialchars($product['product_name']) ?>
                  </div>
                  <div class="product-card-price">
                    <?= formatMoney((float)$product['cost']) ?>
                  </div>
                  <div class="product-card-hint">Tap to view and manage</div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </main>
  </div>
  <div class="modal-overlay" id="view-product-modal" aria-hidden="true">
<div class="modal product-view-modal" role="dialog" aria-modal="true" aria-labelledby="view-product-name">
<div class="modal-header">
<div class="modal-header-copy">
<span class="modal-eyebrow">PRODUCT</span>
<div class="modal-title">Product Details</div>
</div>
<button class="close-modal" id="close-view-product" type="button" aria-label="Close product details">&times;</button>
</div>
<div class="product-view-body">
<section class="product-view-section">
<div class="product-view-layout">
<div class="product-view-media">
<img id="view-product-image" class="product-view-image" alt="">
<div id="view-product-placeholder" class="product-view-placeholder" aria-hidden="true">🖼</div>
</div>
<div>
<div class="product-view-badges">
<span class="category" id="view-product-category"></span>
<span class="status" id="view-product-status"></span>
</div>
<h2 class="product-view-name" id="view-product-name"></h2>
<div class="product-view-price" id="view-product-price"></div>
<div class="product-view-description" id="view-product-description"></div>
<div class="product-view-stats">
<div class="product-view-stat"><span>Available</span><strong id="view-product-available">0</strong></div>
<div class="product-view-stat"><span>Reserved</span><strong id="view-product-reserved">0</strong></div>
<div class="product-view-stat"><span>Total Stock</span><strong id="view-product-total">0</strong></div>
<div class="product-view-stat"><span>Rating</span><strong id="view-product-rating">No reviews</strong></div>
</div>
<div class="product-view-actions">
<button class="view-action primary" id="view-edit-product" type="button">Edit Product</button>
<button class="view-action" id="view-product-reviews" type="button">View Reviews</button>
<button class="view-action danger" id="view-delete-product" type="button">Delete Product</button>
</div>
</div>
</div>
</section>
</div>
</div>
</div>
<div class="modal-overlay" id="product-modal" aria-hidden="true">
<div class="modal product-form-modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
<div class="modal-header">
<div class="modal-header-copy">
<span class="modal-eyebrow">PRODUCT LISTING</span>
<div class="modal-title" id="modal-title">Add Product</div>
</div>
<button class="close-modal" id="close-modal" type="button" aria-label="Close product form">&times;</button>
</div>
<form id="product-form" method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['seller_products_csrf'], ENT_QUOTES) ?>">
<input type="hidden" name="action" id="form-action" value="create_product">
<input type="hidden" name="product_id" id="form-product-id" value="">
<div class="product-form-shell">
<div class="product-form-layout">
<section class="product-section">
<div class="product-section-heading">
<div><span class="section-number">01</span><div><h3>Saved Calculation</h3></div></div>
</div>
<div class="saved-costing-box">
<label class="full">
<span id="saved-costing-label">Use Saved Calculation</span>
<select id="saved-costing">
<option id="saved-costing-default" value="">Create product without saved calculation</option>
<?php foreach ($savedCostings as $costing): ?>
<option value="<?= (int)$costing['costing_id'] ?>" data-name="<?= htmlspecialchars($costing['product_title'], ENT_QUOTES) ?>" data-category="<?= htmlspecialchars($costing['craft_category'], ENT_QUOTES) ?>" data-price="<?= htmlspecialchars((string)$costing['suggested_price'], ENT_QUOTES) ?>"><?= htmlspecialchars($costing['product_title']) ?> — <?= formatMoney((float)$costing['suggested_price']) ?></option>
<?php endforeach; ?>
</select>
</label>
</div>
</section>
<section class="product-section">
<div class="product-section-heading">
<div><span class="section-number">02</span><div><h3>Product Information</h3></div></div>
</div>
<div class="form-grid">
<label>Product Name<input id="product-name" name="product_name" type="text" maxlength="255" placeholder="e.g. Handwoven Rattan Basket" required></label>
<label>Craft Category<select id="craft-category" name="craft_category" required><option value="">Select category</option><?php foreach ($craftCategories as $category): ?><option value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
<label class="full">Description<textarea id="product-description" name="description" maxlength="2000" placeholder="Describe your handmade product..."></textarea></label>
</div>
</section>
<section class="product-section">
<div class="product-section-heading">
<div><span class="section-number">03</span><div><h3>Price & Inventory</h3></div></div>
</div>
<div class="form-grid">
<label>Price (₱)<input id="product-cost" name="cost" type="number" min="0" step="0.01" value="0" required></label>
<div class="stock-field">
<div class="field-title-row"><label for="product-stock">Stock</label><details class="help-tip"><summary aria-label="Stock help" title="Stock help">i</summary><div class="help-tip-copy">Stock cannot be set below units reserved for active orders.</div></details></div>
<input id="product-stock" name="stock" type="number" min="0" step="1" value="0" required>
</div>
<label class="full">Status<select id="product-status" name="status"><?php foreach ($statusOptions as $option): ?><option value="<?= htmlspecialchars($option, ENT_QUOTES) ?>"><?= htmlspecialchars($option) ?></option><?php endforeach; ?></select><div class="costing-note" id="inventory-status-note" role="status" aria-live="polite">0 available · 0 reserved</div></label>
</div>
</section>
<section class="product-section" id="quick-customizations-section">
<div class="product-section-heading">
<div><span class="section-number">04</span><div><h3>Quick Customizations (Optional)</h3></div></div>
<div class="quick-custom-heading-tools"><div class="quick-custom-count" id="quick-custom-count" aria-live="polite">0 of 5 options</div><details class="help-tip"><summary aria-label="Customization pricing help" title="Customization pricing help">i</summary><div class="help-tip-copy">Additional prices are per unit and do not change the base price. Enable “Require buyer details” for engraving or personalized text.</div></details></div>
</div>
<div class="quick-custom-list" id="quick-custom-list"></div>
<button type="button" class="quick-custom-add" id="quick-custom-add">+ Add Customization</button>
</section>
<section class="product-section">
<div class="product-section-heading">
<div><span class="section-number">05</span><div><h3>Product Image</h3></div></div>
</div>
<div class="photo-editor">
<div class="photo-preview">
<img id="preview-image" class="preview-image" alt="Product preview">
<div class="photo-empty" id="photo-empty">Product image preview</div>
</div>
<div class="photo-fields">
<div class="field-title-row"><label for="product-photo">Product Photo</label><details class="help-tip help-tip--up"><summary aria-label="Product photo requirements" title="Photo requirements">i</summary><div class="help-tip-copy" id="product-photo-note">JPG, PNG, WEBP, or supported iPhone photos. Large photos are optimized to 5 MB.</div></details></div>
<input id="product-photo" name="product_photo" type="file" accept="image/jpeg,image/png,image/webp">
<div class="image-safety-status idle" id="product-image-safety" role="status" aria-live="polite">Choose an image to run the safety check.</div>
</div>
</div>
</section>
</div>
<aside class="product-listing-preview" aria-label="Listing preview">
<div class="listing-preview-card">
<div class="listing-preview-head">
<div class="listing-preview-kicker">LISTING PREVIEW</div>
<h3>Buyer View</h3>
</div>
<div class="listing-preview-media">
<img id="listing-preview-image" alt="">
<div class="listing-preview-placeholder" id="listing-preview-placeholder">Upload a product image to preview the listing.</div>
<div class="listing-preview-status"><span class="status available" id="listing-preview-status">Available</span></div>
<div class="listing-preview-stock" id="listing-preview-stock">0 available</div>
</div>
<div class="listing-preview-body">
<div class="listing-preview-category" id="listing-preview-category">Select category</div>
<div class="listing-preview-name" id="listing-preview-name">Product Name</div>
<div class="listing-preview-price" id="listing-preview-price">₱0.00</div>
</div>
</div>
</aside>
</div>
<div class="modal-footer">
<button class="cancel-btn" id="cancel-modal" type="button">Cancel</button>
<button class="submit-btn" id="submit-product" type="submit">Save Product</button>
</div>
</form>
</div>
</div>
<div class="image-warning-overlay" id="image-warning-overlay" aria-hidden="true">
<div class="image-warning-modal" role="dialog" aria-modal="true" aria-labelledby="image-warning-title">
<div class="image-warning-icon" aria-hidden="true">!</div>
<h2 id="image-warning-title">Image Not Allowed</h2>
<p id="image-warning-message">This product image did not pass the safety check.</p>
<button type="button" class="image-warning-close" id="image-warning-close">Choose Another Image</button>
</div>
</div>
<div class="modal-overlay" id="reviews-modal" aria-hidden="true">
<div class="modal reviews-modal" role="dialog" aria-modal="true" aria-labelledby="reviews-modal-title">
<div class="modal-header">
<div class="modal-header-copy">
<span class="modal-eyebrow">CUSTOMER FEEDBACK</span>
<div class="modal-title" id="reviews-modal-title">Product Reviews</div>
<p class="modal-subtitle" id="reviews-product-name"></p>
</div>
<button class="close-modal" id="close-reviews-modal" type="button" aria-label="Close reviews">&times;</button>
</div>
<div class="reviews-body">
<section class="reviews-section">
<div class="reviews-summary">
<div><div class="reviews-summary-score" id="reviews-average">0.0 ★</div><div class="reviews-summary-meta" id="reviews-count">No reviews yet</div></div>
<div class="rating-stars" id="reviews-summary-stars" aria-hidden="true"></div>
</div>
<div class="reviews-list" id="reviews-list"></div>
</section>
</div>
</div>
</div>
<div class="modal-overlay" id="delete-product-modal" aria-hidden="true">
<div class="modal confirm-modal" role="dialog" aria-modal="true" aria-labelledby="delete-product-title">
<div class="modal-header">
<div class="modal-header-copy">
<span class="modal-eyebrow">PRODUCT ACTION</span>
<div class="modal-title" id="delete-product-title">Delete Product?</div>
</div>
<button class="close-modal" id="close-delete-product" type="button" aria-label="Close delete confirmation">&times;</button>
</div>
<div class="confirm-body">
<p class="confirm-copy">Are you sure you want to permanently delete <strong id="delete-product-name">this product</strong>?</p>
<div class="confirm-warning">This will permanently delete the product. It cannot be undone.</div>
</div>
<form method="post" id="view-delete-form" class="delete-confirm-form">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['seller_products_csrf'], ENT_QUOTES) ?>">
<input type="hidden" name="action" value="delete_product">
<input type="hidden" name="product_id" id="view-delete-product-id" value="">
<button class="cancel-btn" id="delete-product-cancel" type="button">Cancel</button>
<button class="delete-confirm-btn" id="delete-product-confirm" type="submit">Delete Product</button>
</form>
</div>
</div>
<script>
    const modal = document.getElementById('product-modal');
    const modalTitle = document.getElementById('modal-title');
    const productForm = document.getElementById('product-form');
    const formAction = document.getElementById('form-action');
    const formProductId = document.getElementById('form-product-id');
    const savedCosting = document.getElementById('saved-costing');
    const savedCostingLabel = document.getElementById('saved-costing-label');
    const savedCostingDefault = document.getElementById('saved-costing-default');
    const productName = document.getElementById('product-name');
    const craftCategory = document.getElementById('craft-category');
    const productCost = document.getElementById('product-cost');
    const productStock = document.getElementById('product-stock');
    const productStatus = document.getElementById('product-status');
    const inventoryStatusNote = document.getElementById('inventory-status-note');
    let editingReservedStock = 0;
    let editingAdminRemoved = false;
    const productDescription = document.getElementById('product-description');
    const productPhoto = document.getElementById('product-photo');
    const previewImage = document.getElementById('preview-image');
    const productPhotoNote = document.getElementById('product-photo-note');
    const photoEmpty = document.getElementById('photo-empty');
    const submitProduct = document.getElementById('submit-product');
    const productImageSafety = document.getElementById('product-image-safety');
    const imageWarningOverlay = document.getElementById('image-warning-overlay');
    const imageWarningMessage = document.getElementById('image-warning-message');
    const imageWarningClose = document.getElementById('image-warning-close');
    const productCsrfToken = productForm?.querySelector('input[name="csrf_token"]')?.value || '';
    let imageSafetyState = 'idle';
    let imageSafetyRequestId = 0;
    let imageSelectionRequestId = 0;
    const maxProductImageBytes = <?= (int)MAX_PRODUCT_IMAGE_BYTES ?>;
    const maxOriginalProductImageBytes = 32 * 1024 * 1024;
    let preparedProductPhoto = null;
    const listingPreviewImage = document.getElementById('listing-preview-image');
    const listingPreviewPlaceholder = document.getElementById('listing-preview-placeholder');
    const listingPreviewStatus = document.getElementById('listing-preview-status');
    const listingPreviewStock = document.getElementById('listing-preview-stock');
    const listingPreviewCategory = document.getElementById('listing-preview-category');
    const listingPreviewName = document.getElementById('listing-preview-name');
    const listingPreviewPrice = document.getElementById('listing-preview-price');
    const viewModal = document.getElementById('view-product-modal');
    const closeViewProduct = document.getElementById('close-view-product');
    const viewProductImage = document.getElementById('view-product-image');
    const viewProductPlaceholder = document.getElementById('view-product-placeholder');
    const viewProductCategory = document.getElementById('view-product-category');
    const viewProductStatus = document.getElementById('view-product-status');
    const viewProductName = document.getElementById('view-product-name');
    const viewProductPrice = document.getElementById('view-product-price');
    const viewProductDescription = document.getElementById('view-product-description');
    const viewProductAvailable = document.getElementById('view-product-available');
    const viewProductReserved = document.getElementById('view-product-reserved');
    const viewProductRating = document.getElementById('view-product-rating');
    const viewProductTotal = document.getElementById('view-product-total');
    const viewDeleteForm = document.getElementById('view-delete-form');
    const viewDeleteProductId = document.getElementById('view-delete-product-id');
    const viewEditProduct = document.getElementById('view-edit-product');
    const viewProductReviews = document.getElementById('view-product-reviews');
    const viewDeleteProduct = document.getElementById('view-delete-product');
    const deleteModal = document.getElementById('delete-product-modal');
    const closeDeleteProduct = document.getElementById('close-delete-product');
    const deleteProductCancel = document.getElementById('delete-product-cancel');
    const deleteProductName = document.getElementById('delete-product-name');
    const reviewsModal = document.getElementById('reviews-modal');
    const closeReviewsModal = document.getElementById('close-reviews-modal');
    const reviewsProductName = document.getElementById('reviews-product-name');
    const reviewsAverage = document.getElementById('reviews-average');
    const reviewsCount = document.getElementById('reviews-count');
    const reviewsSummaryStars = document.getElementById('reviews-summary-stars');
    const reviewsList = document.getElementById('reviews-list');
    const reviewsByProduct = <?= json_encode($reviewsByProduct, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const customizationsByProduct = <?= json_encode($customizationsByProduct, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}' ?>;
    const quickCustomList = document.getElementById('quick-custom-list');
    const quickCustomAdd = document.getElementById('quick-custom-add');
    const quickCustomCount = document.getElementById('quick-custom-count');
    const maxQuickCustomizations = 5;
    let activeProductCard = null;
    function formatPeso(value) {
      return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        minimumFractionDigits: 2
      }).format(Number(value || 0));
    }
    function escapeHtml(value) {
      return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    }
    function syncQuickCustomizations() {
      const rows = [...quickCustomList.querySelectorAll('.quick-custom-row')];
      rows.forEach((row, index) => {
        row.querySelector('.quick-custom-title').textContent = `Customization ${index + 1}`;
        row.querySelector('.quick-custom-name').name = `custom_options[${index}][name]`;
        row.querySelector('.quick-custom-price').name = `custom_options[${index}][price]`;
        row.querySelector('.quick-custom-requires').name = `custom_options[${index}][requires_text]`;
      });
      quickCustomCount.textContent = `${rows.length} of ${maxQuickCustomizations} options`;
      quickCustomAdd.disabled = rows.length >= maxQuickCustomizations;
    }
    function addQuickCustomization(item = {}) {
      if (quickCustomList.children.length >= maxQuickCustomizations) return;
      const row = document.createElement('div');
      row.className = 'quick-custom-row';
      const textRequired = Number(item.requires_text || 0) === 1;
      const price = item.price === undefined ? '0.00' : String(item.price);
      row.innerHTML = `
        <div class="quick-custom-head">
          <strong class="quick-custom-title"></strong>
          <button type="button" class="quick-custom-remove" aria-label="Remove customization">Remove</button>
        </div>
        <div class="quick-custom-fields">
          <label>Option Name
            <input class="quick-custom-name" type="text" maxlength="100" placeholder="e.g. Name Engraving" value="${escapeHtml(item.name || '')}">
            <span class="quick-custom-error" data-error-for="name"></span>
          </label>
          <label>Additional Price (₱)
            <input class="quick-custom-price" type="text" inputmode="decimal" maxlength="11" placeholder="0.00" value="${escapeHtml(price)}">
            <span class="quick-custom-error" data-error-for="price"></span>
          </label>
        </div>
        <label class="quick-custom-check"><input class="quick-custom-requires" type="checkbox" value="1" ${textRequired ? 'checked' : ''}>Require buyer details (e.g. name to engrave)</label>
      `;
      quickCustomList.appendChild(row);
      row.querySelector('.quick-custom-remove').addEventListener('click', () => {
        row.remove();
        syncQuickCustomizations();
        validateQuickCustomizations(true, false);
      });
      row.querySelectorAll('input').forEach((field) => {
        field.addEventListener('input', () => {
          if (quickCustomList.querySelector('.quick-custom-error.visible')) validateQuickCustomizations(true, false);
        });
      });
      syncQuickCustomizations();
    }
    function resetQuickCustomizations(options = []) {
      quickCustomList.replaceChildren();
      options.slice(0, maxQuickCustomizations).forEach(addQuickCustomization);
      syncQuickCustomizations();
    }
    function validateQuickCustomizations(showErrors = true, focusInvalid = true) {
      const rows = [...quickCustomList.querySelectorAll('.quick-custom-row')];
      const seen = new Set();
      let firstInvalid = null;
      rows.forEach((row) => {
        const nameInput = row.querySelector('.quick-custom-name');
        const priceInput = row.querySelector('.quick-custom-price');
        const name = nameInput.value.trim().replace(/\s+/g, ' ');
        const price = priceInput.value.trim();
        const key = name.toLocaleLowerCase();
        const nameError = !name
          ? 'Enter the customization name.'
          : (name.length > 100 ? 'Use up to 100 characters.' : (seen.has(key) ? 'This customization name is already used.' : ''));
        if (name) seen.add(key);
        const priceError = !/^\d{1,8}(?:\.\d{1,2})?$/.test(price)
          ? 'Enter a valid price (₱0–₱99,999,999.99, up to 2 decimals).'
          : '';
        [[nameInput, nameError, 'name'], [priceInput, priceError, 'price']].forEach(([field, message, fieldName]) => {
          const errorElement = row.querySelector(`[data-error-for="${fieldName}"]`);
          field.classList.toggle('quick-custom-invalid', showErrors && !!message);
          field.setAttribute('aria-invalid', showErrors && !!message ? 'true' : 'false');
          errorElement.textContent = showErrors ? message : '';
          errorElement.classList.toggle('visible', showErrors && !!message);
          if (message && !firstInvalid) firstInvalid = field;
        });
      });
      if (firstInvalid && showErrors && focusInvalid) firstInvalid.focus();
      return !firstInvalid;
    }
    quickCustomAdd?.addEventListener('click', () => {
      addQuickCustomization();
      quickCustomList.lastElementChild?.querySelector('.quick-custom-name')?.focus();
    });
    function starText(rating) {
      const safe = Math.max(0, Math.min(5, Math.round(Number(rating) || 0)));
      return '★'.repeat(safe) + '☆'.repeat(5 - safe);
    }
    function setPageScrollLock() {
      const anyOpen = [modal, viewModal, reviewsModal, deleteModal, imageWarningOverlay].some((item) => item?.classList.contains('show'));
      document.body.style.overflow = anyOpen ? 'hidden' : '';
    }
    function openProductView(card) {
      if (!card) return;
      activeProductCard = card;
      const photo = card.dataset.photo || '';
      const category = card.dataset.categoryLabel || 'Uncategorized';
      const status = card.dataset.status || 'Available';
      const statusClass = status.toLowerCase().replaceAll(' ', '-');
      const average = Number(card.dataset.averageRating || 0);
      const reviewCount = Number(card.dataset.reviewCount || 0);
      viewProductName.textContent = card.dataset.productName || 'Product';
      viewProductCategory.textContent = category;
      viewProductPrice.textContent = formatPeso(card.dataset.cost || 0);
      viewProductDescription.textContent = card.dataset.description || 'No description provided.';
      viewProductAvailable.textContent = `${Number(card.dataset.availableStock || 0)} unit${Number(card.dataset.availableStock || 0) === 1 ? '' : 's'}`;
      viewProductReserved.textContent = `${Number(card.dataset.reservedStock || 0)} unit${Number(card.dataset.reservedStock || 0) === 1 ? '' : 's'}`;
      viewProductTotal.textContent = `${Number(card.dataset.stock || 0)} unit${Number(card.dataset.stock || 0) === 1 ? '' : 's'}`;
      viewProductRating.textContent = reviewCount > 0
        ? `${average.toFixed(1)} ★ (${reviewCount})`
        : 'No reviews';
      viewDeleteProductId.value = card.dataset.productId || '';
      viewProductStatus.className = `status ${statusClass}`;
      viewProductStatus.textContent = status === 'Archived'
        ? (card.dataset.adminRemoved === '1' ? 'Removed by Admin' : 'Seller Archived')
        : status;
      if (photo) {
        viewProductImage.src = '../' + photo;
        viewProductImage.alt = card.dataset.productName || 'Product';
        viewProductImage.classList.add('show');
        viewProductPlaceholder.classList.add('hidden');
      } else {
        viewProductImage.removeAttribute('src');
        viewProductImage.classList.remove('show');
        viewProductPlaceholder.classList.remove('hidden');
      }
      viewModal.classList.add('show');
      viewModal.setAttribute('aria-hidden', 'false');
      setPageScrollLock();
    }
    function closeProductView() {
      viewModal.classList.remove('show');
      viewModal.setAttribute('aria-hidden', 'true');
      setPageScrollLock();
    }
    function openReviewsModal(source) {
      const productId = Number(source?.dataset.productId || 0);
      const productNameValue = source?.dataset.productName || 'Product';
      const average = Number(source?.dataset.averageRating || 0);
      const count = Number(source?.dataset.reviewCount || 0);
      const reviews = reviewsByProduct[String(productId)] || reviewsByProduct[productId] || [];
      reviewsProductName.textContent = productNameValue;
      reviewsAverage.textContent = count > 0 ? average.toFixed(1) + ' ★' : 'No rating yet';
      reviewsCount.textContent = count === 1 ? '1 customer review' : count + ' customer reviews';
      reviewsSummaryStars.textContent = count > 0 ? starText(average) : '☆☆☆☆☆';
      if (!reviews.length) {
        reviewsList.innerHTML = '<div class="reviews-empty">No customer reviews for this product yet.</div>';
      } else {
        reviewsList.innerHTML = reviews.map((review) => {
          const comment = review.review_text
            ? escapeHtml(review.review_text)
            : '<em>Rated without a written comment.</em>';
          return `
            <article class="review-card">
              <div class="review-card-head">
                <div>
                  <div class="review-buyer">${escapeHtml(review.buyer_name || 'Buyer')}</div>
                  <div class="review-order">Order #${Number(review.order_id || 0)}</div>
                </div>
                <div class="review-rating" aria-label="${Number(review.rating || 0)} out of 5 stars">${starText(review.rating)}</div>
              </div>
              <div class="review-text">${comment}</div>
              <div class="review-date">${escapeHtml(review.date_label || '')}</div>
            </article>
          `;
        }).join('');
      }
      reviewsModal.classList.add('show');
      reviewsModal.setAttribute('aria-hidden', 'false');
      setPageScrollLock();
    }
    function closeReviews() {
      reviewsModal.classList.remove('show');
      reviewsModal.setAttribute('aria-hidden', 'true');
      setPageScrollLock();
    }
    function openDeleteProductModal() {
      if (!activeProductCard || !viewDeleteProductId.value) return;
      deleteProductName.textContent = activeProductCard.dataset.productName || 'this product';
      deleteModal.classList.add('show');
      deleteModal.setAttribute('aria-hidden', 'false');
      setPageScrollLock();
    }
    function closeDeleteProductModal() {
      deleteModal.classList.remove('show');
      deleteModal.setAttribute('aria-hidden', 'true');
      setPageScrollLock();
    }
    function setImageSafetyState(state, message) {
      imageSafetyState = state;
      if (productImageSafety) {
        productImageSafety.className = `image-safety-status ${state}`;
        productImageSafety.textContent = message;
      }
      const selectedFile = productPhoto?.files?.[0] || null;
      const requiresNewImage = formAction.value === 'create_product' || productPhoto?.required;
      const blocked =
        state === 'checking'
        || state === 'unsafe'
        || state === 'error'
        || (requiresNewImage && !selectedFile && state !== 'existing');
      submitProduct.disabled = blocked;
      submitProduct.classList.toggle('safety-blocked', blocked);
    }
    function openImageWarning(message) {
      if (!imageWarningOverlay || !imageWarningMessage) return;
      imageWarningMessage.textContent = message;
      imageWarningOverlay.classList.add('show');
      imageWarningOverlay.setAttribute('aria-hidden', 'false');
      setPageScrollLock();
      window.setTimeout(() => imageWarningClose?.focus(), 0);
    }
    function closeImageWarning() {
      imageWarningOverlay?.classList.remove('show');
      imageWarningOverlay?.setAttribute('aria-hidden', 'true');
      setPageScrollLock();
      productPhoto?.focus();
    }
    async function prepareProductPhoto(file) {
      const acceptedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'image/avif'];
      const knownExtension = /\.(jpe?g|png|webp|heic|heif|avif)$/i.test(file.name || '');
      if (!acceptedTypes.includes(file.type) && !(file.type === '' && knownExtension)) {
        throw new Error('Choose a JPG, PNG, WEBP, or supported iPhone photo.');
      }
      if (!file.size || file.size > maxOriginalProductImageBytes) {
        throw new Error('This photo is too large to prepare. Please choose an image under 32 MB.');
      }
      // Preserve smaller PNG/WEBP originals (including transparent backgrounds).
      // Re-encode JPEGs and supported iPhone formats so Pillow receives standard JPEG bytes.
      if ((file.type === 'image/png' || file.type === 'image/webp') && file.size <= maxProductImageBytes) {
        return file;
      }
      const objectUrl = URL.createObjectURL(file);
      let decodedImage;
      try {
        decodedImage = await new Promise((resolve, reject) => {
          const img = new Image();
          img.onload = () => resolve(img);
          img.onerror = () => reject(new Error('This photo could not be opened. Please export it as JPG and try again.'));
          img.src = objectUrl;
        });
      } finally {
        URL.revokeObjectURL(objectUrl);
      }
      const width = decodedImage.naturalWidth;
      const height = decodedImage.naturalHeight;
      if (!width || !height) {
        throw new Error('This photo has invalid dimensions. Please choose another image.');
      }
      const scale = Math.min(1, 2400 / Math.max(width, height));
      const canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(width * scale));
      canvas.height = Math.max(1, Math.round(height * scale));
      const context = canvas.getContext('2d');
      if (!context || typeof canvas.toBlob !== 'function') {
        throw new Error('Photo preparation is not supported on this browser. Please choose a JPG image.');
      }
      context.fillStyle = '#ffffff';
      context.fillRect(0, 0, canvas.width, canvas.height);
      context.drawImage(decodedImage, 0, 0, canvas.width, canvas.height);
      for (const quality of [0.90, 0.82, 0.72, 0.60]) {
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
        if (blob && blob.type === 'image/jpeg' && blob.size > 0 && blob.size <= maxProductImageBytes) {
          return new File([blob], 'product-photo.jpg', { type: 'image/jpeg', lastModified: Date.now() });
        }
      }
      throw new Error('The photo could not be reduced to 5 MB. Please choose a smaller image.');
    }
    // Safari fallback: replace the file in the actual multipart submission if the
    // browser cannot assign a generated File to the native file input.
    productForm?.addEventListener('formdata', (event) => {
      if (preparedProductPhoto && productPhoto?.files?.length) {
        event.formData.set('product_photo', preparedProductPhoto, preparedProductPhoto.name);
      }
    });
    async function checkSelectedProductImage(file) {
      const requestId = ++imageSafetyRequestId;
      if (!file) {
        setImageSafetyState(
          editingAdminRemoved ? 'existing' : formAction.value === 'update_product' && !productPhoto.required ? 'existing' : 'idle',
          editingAdminRemoved
            ? 'Admin removed this listing. Choose a new photo to relist.'
            : formAction.value === 'update_product' && !productPhoto.required
            ? 'Current saved image will be kept.'
            : 'Choose an image to run the safety check.'
        );
        return;
      }
      if (file.size > maxProductImageBytes) {
        const message = 'Product photo must not exceed 5 MB.';
        setImageSafetyState('error', message);
        openImageWarning(message);
        return;
      }
      const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
      if (!allowedTypes.includes(file.type)) {
        const message = 'Only JPG, PNG, or WEBP product images are allowed.';
        setImageSafetyState('error', message);
        openImageWarning(message);
        return;
      }
      setImageSafetyState('checking', 'Checking image safety...');
      const body = new FormData();
      body.append('action', 'moderate_product_image');
      body.append('csrf_token', productCsrfToken);
      body.append('product_photo', file, file.name);
      try {
        const response = await fetch('products.php', {
          method: 'POST',
          body,
          credentials: 'same-origin',
          headers: {
            'Accept': 'application/json'
          }
        });
        const payload = await response.json().catch(() => null);
        if (requestId !== imageSafetyRequestId) return;
        if (!response.ok || !payload || payload.success !== true) {
          const message = payload?.message || 'The image safety check could not be completed. Please try another image.';
          setImageSafetyState('error', message);
          openImageWarning(message);
          return;
        }
        if (payload.safe !== true) {
          const message = payload.message || 'This image was classified as unsafe. Please choose another product image.';
          setImageSafetyState('unsafe', 'Unsafe image detected. Choose another image.');
          openImageWarning(message);
          return;
        }
        setImageSafetyState('safe', 'Safe image. You can save this product.');
      } catch (error) {
        if (requestId !== imageSafetyRequestId) return;
        const message = 'The image safety service is unavailable right now. Please try again.';
        setImageSafetyState('error', message);
        openImageWarning(message);
      }
    }
    function updateListingPreview() {
      const requiresReplacement = editingAdminRemoved && !productPhoto?.files?.[0];
      Array.from(productStatus?.options || []).forEach((option) => {
        if (option.value !== 'Archived') option.disabled = requiresReplacement;
      });
      if (requiresReplacement) productStatus.value = 'Archived';
      const name = productName.value.trim() || 'Product Name';
      const category = craftCategory.value || 'Select category';
      const price = Number(productCost.value || 0);
      const stock = Math.max(0, Number(productStock.value || 0));
      const available = Math.max(0, stock - editingReservedStock);
      const status = productStatus.value === 'Archived'
        ? 'Archived'
        : (available > 0 ? 'Available' : 'Out of Stock');
      // Keep the dropdown and preview aligned with what PHP will save.
      productStatus.value = status;
      const statusClass = status.toLowerCase().replaceAll(' ', '-');
      if (inventoryStatusNote) {
        inventoryStatusNote.textContent = requiresReplacement
          ? 'Removed by Admin · Upload a new photo to relist'
          : status === 'Archived'
          ? `Archived · ${available} available · ${editingReservedStock} reserved`
          : `${available} available · ${editingReservedStock} reserved`;
      }
      listingPreviewName.textContent = name;
      listingPreviewCategory.textContent = category;
      listingPreviewPrice.textContent = formatPeso(price);
      listingPreviewStock.textContent = `${available} available`;
      listingPreviewStatus.className = `status ${statusClass}`;
      listingPreviewStatus.textContent = status === 'Archived' && editingAdminRemoved
        ? 'Removed by Admin'
        : status;
      if (previewImage.classList.contains('show') && previewImage.getAttribute('src')) {
        listingPreviewImage.src = previewImage.src;
        listingPreviewImage.alt = name;
        listingPreviewImage.classList.add('show');
        listingPreviewPlaceholder.classList.add('hidden');
      } else {
        listingPreviewImage.removeAttribute('src');
        listingPreviewImage.classList.remove('show');
        listingPreviewPlaceholder.classList.remove('hidden');
      }
    }
    function configureSavedCalculation(mode) {
      savedCosting.value = '';
      if (mode === 'edit') {
        savedCostingLabel.textContent = 'Reapply Saved Calculation';
        savedCostingDefault.textContent = 'Keep current product details';
      } else {
        savedCostingLabel.textContent = 'Use Saved Calculation';
        savedCostingDefault.textContent = 'Create product without saved calculation';
      }
    }
    function openCreateModal() {
      ++imageSelectionRequestId;
      ++imageSafetyRequestId;
      preparedProductPhoto = null;
      activeProductCard = null;
      editingAdminRemoved = false;
      modalTitle.textContent = 'Add Product';
      formAction.value = 'create_product';
      formProductId.value = '';
      productForm.reset();
      resetQuickCustomizations();
      editingReservedStock = 0;
      productStock.min = '0';
      productStatus.value = 'Available';
      productCost.value = '0';
      productStock.value = '0';
      productPhoto.required = true;
      if (productPhotoNote) productPhotoNote.textContent = 'JPG, PNG, WEBP, or supported iPhone photos. Large photos are optimized to 5 MB.';
      configureSavedCalculation('create');
      previewImage.removeAttribute('src');
      previewImage.classList.remove('show');
      if (photoEmpty) photoEmpty.style.display = 'block';
      updateListingPreview();
      submitProduct.textContent = 'Save Product';
      setImageSafetyState('idle', 'Choose an image to run the safety check.');
      modal.classList.add('show');
      modal.setAttribute('aria-hidden', 'false');
      setPageScrollLock();
    }
    function openEditModal(source) {
      if (!source) return;
      ++imageSelectionRequestId;
      ++imageSafetyRequestId;
      preparedProductPhoto = null;
      activeProductCard = source;
      editingAdminRemoved = source.dataset.adminRemoved === '1';
      modalTitle.textContent = 'Edit Product';
      formAction.value = 'update_product';
      formProductId.value = source.dataset.productId || '';
      productName.value = source.dataset.productName || '';
      craftCategory.value = source.dataset.category || '';
      productCost.value = source.dataset.cost || '0';
      productStock.value = source.dataset.stock || '0';
      editingReservedStock = Math.max(0, Number(source.dataset.reservedStock || 0));
      productStock.min = String(editingReservedStock);
      productStatus.value = source.dataset.status || productStatus.options[0]?.value || '';
      productDescription.value = source.dataset.description || '';
      resetQuickCustomizations(customizationsByProduct[source.dataset.productId] || []);
      productPhoto.value = '';
      productPhoto.required = !(source.dataset.photo || '');
      if (productPhotoNote) productPhotoNote.textContent = editingAdminRemoved
        ? 'Admin removed this listing. Upload a new image to make it available again. Large photos are optimized to 5 MB.'
        : source.dataset.photo
          ? 'Leave blank to keep the current image. Large photos are optimized to 5 MB.'
          : 'Image required. Large photos are optimized to 5 MB.';
      configureSavedCalculation('edit');
      if (source.dataset.photo) {
        previewImage.src = '../' + source.dataset.photo;
        previewImage.classList.add('show');
        if (photoEmpty) photoEmpty.style.display = 'none';
      } else {
        previewImage.removeAttribute('src');
        previewImage.classList.remove('show');
        if (photoEmpty) photoEmpty.style.display = 'block';
      }
      updateListingPreview();
      submitProduct.textContent = 'Update Product';
      setImageSafetyState(
        source.dataset.photo ? 'existing' : 'idle',
        editingAdminRemoved
          ? 'Upload a new photo to make this admin-removed listing available again.'
          : source.dataset.photo
          ? 'Current saved image will be kept unless you choose a new one.'
          : 'Choose an image to run the safety check.'
      );
      closeProductView();
      modal.classList.add('show');
      modal.setAttribute('aria-hidden', 'false');
      setPageScrollLock();
    }
    function closeModal() {
      ++imageSelectionRequestId;
      ++imageSafetyRequestId;
      preparedProductPhoto = null;
      modal.classList.remove('show');
      modal.setAttribute('aria-hidden', 'true');
      setPageScrollLock();
    }
    document.getElementById('open-add-product')?.addEventListener('click', openCreateModal);
    document.getElementById('open-empty-add')?.addEventListener('click', openCreateModal);
    document.getElementById('close-modal')?.addEventListener('click', closeModal);
    document.getElementById('cancel-modal')?.addEventListener('click', closeModal);
    modal?.addEventListener('click', (event) => {
      if (event.target === modal) closeModal();
    });
    closeViewProduct?.addEventListener('click', closeProductView);
    viewModal?.addEventListener('click', (event) => {
      if (event.target === viewModal) closeProductView();
    });
    closeReviewsModal?.addEventListener('click', closeReviews);
    reviewsModal?.addEventListener('click', (event) => {
      if (event.target === reviewsModal) closeReviews();
    });
    document.querySelectorAll('.product-card').forEach((card) => {
      card.addEventListener('click', () => {
        openProductView(card);
      });
      card.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          openProductView(card);
        }
      });
    });
    viewEditProduct?.addEventListener('click', () => {
      if (activeProductCard) openEditModal(activeProductCard);
    });
    viewProductReviews?.addEventListener('click', () => {
      if (!activeProductCard) return;
      closeProductView();
      openReviewsModal(activeProductCard);
    });
    viewDeleteProduct?.addEventListener('click', () => {
      if (!activeProductCard || !viewDeleteProductId.value) return;
      closeProductView();
      openDeleteProductModal();
    });
    closeDeleteProduct?.addEventListener('click', closeDeleteProductModal);
    deleteProductCancel?.addEventListener('click', closeDeleteProductModal);
    deleteModal?.addEventListener('click', (event) => {
      if (event.target === deleteModal) closeDeleteProductModal();
    });
    viewDeleteForm?.addEventListener('submit', (event) => {
      if (!activeProductCard || !viewDeleteProductId.value) {
        event.preventDefault();
      }
    });
    savedCosting?.addEventListener('change', () => {
      const option = savedCosting.options[savedCosting.selectedIndex];
      if (!option || !option.value) {
        return;
      }
      productName.value = option.dataset.name || '';
      craftCategory.value = option.dataset.category || '';
      productCost.value = Number(option.dataset.price || 0).toFixed(2);
      updateListingPreview();
    });
    productPhoto?.addEventListener('change', async () => {
      const selectionId = ++imageSelectionRequestId;
      ++imageSafetyRequestId;
      preparedProductPhoto = null;
      const file = productPhoto.files?.[0] || null;
      updateListingPreview();
      if (!file) {
        if (formAction.value === 'update_product' && activeProductCard?.dataset.photo) {
          previewImage.src = '../' + activeProductCard.dataset.photo;
          previewImage.classList.add('show');
          if (photoEmpty) photoEmpty.style.display = 'none';
        } else {
          previewImage.removeAttribute('src');
          previewImage.classList.remove('show');
          if (photoEmpty) photoEmpty.style.display = 'block';
        }
        updateListingPreview();
        checkSelectedProductImage(null);
        return;
      }
      setImageSafetyState('checking', 'Preparing product photo...');
      try {
        const readyFile = await prepareProductPhoto(file);
        if (selectionId !== imageSelectionRequestId) return;
        preparedProductPhoto = readyFile;
        if (readyFile !== file) {
          let replacedInput = false;
          try {
            const transfer = new DataTransfer();
            transfer.items.add(readyFile);
            productPhoto.files = transfer.files;
            replacedInput = productPhoto.files?.[0]?.name === readyFile.name
              && productPhoto.files[0].size === readyFile.size;
          } catch (error) {
            // Native file-input assignment is unavailable on some Safari versions.
          }
          if (!replacedInput) {
            const submission = new FormData(productForm);
            const submittedImage = submission.get('product_photo');
            if (!(submittedImage instanceof File)
                || submittedImage.name !== readyFile.name
                || submittedImage.size !== readyFile.size) {
              throw new Error('Your browser cannot prepare this photo for upload. Please export it as JPG and try again.');
            }
          }
        }
        const reader = new FileReader();
        reader.onload = (event) => {
          if (selectionId !== imageSelectionRequestId) return;
          previewImage.src = event.target.result;
          previewImage.classList.add('show');
          if (photoEmpty) photoEmpty.style.display = 'none';
          updateListingPreview();
        };
        reader.readAsDataURL(readyFile);
        checkSelectedProductImage(readyFile);
      } catch (error) {
        if (selectionId !== imageSelectionRequestId) return;
        preparedProductPhoto = null;
        productPhoto.value = '';
        if (formAction.value === 'update_product' && activeProductCard?.dataset.photo) {
          previewImage.src = '../' + activeProductCard.dataset.photo;
          previewImage.classList.add('show');
          if (photoEmpty) photoEmpty.style.display = 'none';
        } else {
          previewImage.removeAttribute('src');
          previewImage.classList.remove('show');
          if (photoEmpty) photoEmpty.style.display = 'block';
        }
        updateListingPreview();
        const message = error instanceof Error ? error.message : 'The selected photo could not be prepared.';
        setImageSafetyState('error', message);
        openImageWarning(message);
      }
    });
    [productName, craftCategory, productCost, productStock, productStatus].forEach((field) => {
      field?.addEventListener('input', updateListingPreview);
      field?.addEventListener('change', updateListingPreview);
    });
    imageWarningClose?.addEventListener('click', closeImageWarning);
    imageWarningOverlay?.addEventListener('click', (event) => {
      if (event.target === imageWarningOverlay) closeImageWarning();
    });
    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') return;
      if (imageWarningOverlay?.classList.contains('show')) {
        closeImageWarning();
      } else if (deleteModal?.classList.contains('show')) {
        closeDeleteProductModal();
      } else if (reviewsModal?.classList.contains('show')) {
        closeReviews();
      } else if (modal?.classList.contains('show')) {
        closeModal();
      } else if (viewModal?.classList.contains('show')) {
        closeProductView();
      }
    });
    productForm?.addEventListener('submit', (event) => {
      if (!validateQuickCustomizations(true)) {
        event.preventDefault();
        return;
      }
      const selectedFile = productPhoto?.files?.[0] || null;
      if (editingAdminRemoved && productStatus.value !== 'Archived' && !selectedFile) {
        event.preventDefault();
        openImageWarning('Upload a new product photo before making this admin-removed listing available.');
        return;
      }
      if (selectedFile && imageSafetyState !== 'safe') {
        event.preventDefault();
        const message = imageSafetyState === 'checking'
          ? 'Please wait for the image safety check to finish.'
          : 'The selected image must pass the safety check before this product can be saved.';
        openImageWarning(message);
        return;
      }
      if (formAction.value === 'create_product' && !selectedFile) {
        event.preventDefault();
        openImageWarning('Please choose a product image and wait for it to pass the safety check.');
        return;
      }
      submitProduct.disabled = true;
      submitProduct.classList.remove('safety-blocked');
      submitProduct.textContent = formAction.value === 'update_product'
        ? 'Updating...'
        : 'Saving...';
    });
  </script>
  <script>
    // Keep Products directly below the actual shared Seller search bar height.
    (function () {
      const main = document.querySelector('main.products-main');
      const searchShell = document.getElementById('productsSearchShell');
      if (!main || !searchShell) return;
      const header = searchShell.querySelector('.seller-header-tools');
      function syncProductsHeaderSpace() {
        if (!header) return;
        const style = window.getComputedStyle(header);
        if (style.display === 'none') return;
        const rect = header.getBoundingClientRect();
        if (!rect.height) return;
        // In-flow headers already occupy space; fixed/sticky headers do not.
        const offset = (style.position === 'fixed' || style.position === 'sticky')
          ? Math.max(0, Math.ceil(rect.bottom))
          : 0;
        main.style.setProperty('--products-header-offset', offset + 'px');
      }
      syncProductsHeaderSpace();
      window.addEventListener('load', syncProductsHeaderSpace);
      window.addEventListener('resize', syncProductsHeaderSpace);
      if (typeof ResizeObserver !== 'undefined' && header) {
        new ResizeObserver(syncProductsHeaderSpace).observe(header);
      }
    })();
  </script>
</body>
</html>
