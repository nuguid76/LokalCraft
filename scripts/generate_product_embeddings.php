<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
set_time_limit(0);
require_once __DIR__ . '/../config/database.php';
const DEFAULT_IMAGE_SERVICE_URL = 'http://127.0.0.1:5001';
function line(string $message = ''): void
{
    echo $message . PHP_EOL;
}
function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, '[ERROR] ' . $message . PHP_EOL);
    exit($code);
}
function serviceUrl(): string
{
    $configured = trim((string)getenv('LOKALCRAFT_IMAGE_SERVICE_URL'));
    if ($configured === '') {
        $configured = trim((string)getenv('IMAGE_SERVICE_URL'));
    }
    return rtrim($configured !== '' ? $configured : DEFAULT_IMAGE_SERVICE_URL, '/');
}
function parseProductId(array $arguments): ?int
{
    foreach ($arguments as $argument) {
        if (!str_starts_with($argument, '--product-id=')) {
            continue;
        }
        $raw = substr($argument, strlen('--product-id='));
        if ($raw === '' || !ctype_digit($raw) || (int)$raw <= 0) {
            fail('Invalid --product-id value. Example: --product-id=123');
        }
        return (int)$raw;
    }
    return null;
}
function httpJson(string $url, array $options = []): array
{
    if (!function_exists('curl_init')) {
        fail('PHP cURL is not enabled. Enable extension=curl in php.ini, then try again.');
    }
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize cURL.');
    }
    $defaults = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: LokalCraft-Embedding-Generator/1.0',
        ],
    ];
    curl_setopt_array($ch, $options + $defaults);
    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Image service request failed: ' . $error);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        throw new RuntimeException("Image service returned invalid JSON (HTTP {$status}).");
    }
    if ($status < 200 || $status >= 300 || empty($data['success'])) {
        $message = trim((string)($data['message'] ?? 'Unknown image service error.'));
        throw new RuntimeException("Image service error (HTTP {$status}): {$message}");
    }
    return $data;
}
function checkImageService(): array
{
    $health = httpJson(serviceUrl() . '/health');
    if (strtolower(trim((string)($health['status'] ?? ''))) !== 'ready') {
        throw new RuntimeException('Image service is not ready.');
    }
    return $health;
}
function createEmbedding(string $imagePath): array
{
    if (!is_file($imagePath) || !is_readable($imagePath)) {
        throw new RuntimeException('Product image is not readable.');
    }
    $mime = function_exists('mime_content_type')
        ? (string)(mime_content_type($imagePath) ?: 'application/octet-stream')
        : 'application/octet-stream';
    return httpJson(serviceUrl() . '/embed', [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'image' => new CURLFile($imagePath, $mime, basename($imagePath)),
        ],
    ]);
}
function resolveProductImagePath(string $storedPath): ?string
{
    $projectRoot = realpath(__DIR__ . '/..');
    if ($projectRoot === false) {
        return null;
    }
    $storedPath = trim(str_replace('\\', '/', $storedPath));
    if ($storedPath === '' || preg_match('~^https?://~i', $storedPath)) {
        return null;
    }
    $clean = preg_replace('~^(?:\./|\.\./)+~', '', $storedPath) ?? $storedPath;
    $clean = ltrim($clean, '/');
    $candidates = [
        $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $clean),
    ];
    if (!str_contains($clean, '/')) {
        $candidates[] = $projectRoot
            . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'products'
            . DIRECTORY_SEPARATOR . basename($clean);
    }
    foreach ($candidates as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }
        $real = realpath($candidate);
        if ($real === false) {
            continue;
        }
        $rootPrefix = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($real, $rootPrefix)) {
            continue;
        }
        return $real;
    }
    return null;
}
function currentEmbedding(mysqli $conn, int $productId): ?array
{
    $stmt = $conn->prepare(
        'SELECT image_hash, model_version, JSON_LENGTH(embedding) AS dimensions
         FROM product_image_embeddings
         WHERE product_id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to read the current product embedding.');
    }
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
function validateEmbeddingResponse(
    array $response,
    string $expectedModel,
    int $expectedDimensions
): array {
    $embedding = $response['embedding'] ?? null;
    $modelVersion = trim((string)($response['model'] ?? ''));
    $reportedDimensions = (int)($response['dimensions'] ?? 0);
    if ($modelVersion === '' || $modelVersion !== $expectedModel) {
        throw new RuntimeException('Embedding response used an unexpected model version.');
    }
    if (!is_array($embedding) || !$embedding) {
        throw new RuntimeException('No embedding vector was returned.');
    }
    if ($reportedDimensions <= 0 || $reportedDimensions !== count($embedding)) {
        throw new RuntimeException('Embedding dimensions do not match the returned vector.');
    }
    if ($expectedDimensions > 0 && $reportedDimensions !== $expectedDimensions) {
        throw new RuntimeException(
            "Embedding dimension mismatch. Expected {$expectedDimensions}, got {$reportedDimensions}."
        );
    }
    $clean = [];
    $sumSquares = 0.0;
    foreach ($embedding as $value) {
        if (!is_int($value) && !is_float($value)) {
            throw new RuntimeException('Embedding contains a non-numeric value.');
        }
        $number = (float)$value;
        if (!is_finite($number)) {
            throw new RuntimeException('Embedding contains an invalid numeric value.');
        }
        $clean[] = $number;
        $sumSquares += $number * $number;
    }
    $norm = sqrt($sumSquares);
    if ($norm < 0.95 || $norm > 1.05) {
        throw new RuntimeException(
            'Embedding vector is not normalized as expected.'
        );
    }
    return [
        'embedding' => $clean,
        'model' => $modelVersion,
        'dimensions' => $reportedDimensions,
        'processing_ms' => max(0.0, (float)($response['processing_ms'] ?? 0)),
    ];
}
function saveEmbedding(
    mysqli $conn,
    int $productId,
    array $embedding,
    string $imageHash,
    string $modelVersion
): void {
    $embeddingJson = json_encode(
        array_values($embedding),
        JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
    );
    if ($embeddingJson === false) {
        throw new RuntimeException("Unable to encode embedding for product {$productId}.");
    }
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
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare product embedding save.');
    }
    $stmt->bind_param(
        'isss',
        $productId,
        $embeddingJson,
        $imageHash,
        $modelVersion
    );
    $stmt->execute();
    $stmt->close();
}
function deleteEmbedding(mysqli $conn, int $productId): void
{
    $stmt = $conn->prepare(
        'DELETE FROM product_image_embeddings WHERE product_id = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to delete stale product embedding.');
    }
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $stmt->close();
}
try {
    $arguments = $argv ?? [];
    $force = in_array('--force', $arguments, true);
    $pruneMissing = in_array('--prune-missing', $arguments, true);
    $productIdFilter = parseProductId($arguments);
    line('LokalCraft Product Image Embedding Generator');
    line('--------------------------------------------');
    line('Image service: ' . serviceUrl());
    line('Force rebuild: ' . ($force ? 'YES' : 'NO'));
    line('Prune missing : ' . ($pruneMissing ? 'YES' : 'NO'));
    line(
        'Product scope : '
        . ($productIdFilter !== null ? "#{$productIdFilter}" : 'ALL PRODUCTS')
    );
    $health = checkImageService();
    $serviceModel = trim((string)($health['model'] ?? ''));
    $serviceDimensions = (int)($health['dimensions'] ?? 0);
    if ($serviceModel === '') {
        throw new RuntimeException('Image service did not report a model version.');
    }
    if ($serviceDimensions <= 0) {
        throw new RuntimeException('Image service did not report valid embedding dimensions.');
    }
    line('CLIP model: ' . $serviceModel);
    line('Dimensions: ' . $serviceDimensions);
    line('');
    if ($productIdFilter !== null) {
        $stmt = $conn->prepare(
            "SELECT product_id, product_name, product_photo
             FROM products
             WHERE product_id = ?
               AND product_photo IS NOT NULL
               AND TRIM(product_photo) <> ''
             LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare product query.');
        }
        $stmt->bind_param('i', $productIdFilter);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query(
            "SELECT product_id, product_name, product_photo
             FROM products
             WHERE product_photo IS NOT NULL
               AND TRIM(product_photo) <> ''
             ORDER BY product_id ASC"
        );
        if (!$result) {
            throw new RuntimeException('Unable to load products.');
        }
        $stmt = null;
    }
    $checked = 0;
    $saved = 0;
    $skipped = 0;
    $missing = 0;
    $failed = 0;
    $staleDeleted = 0;
    while ($product = $result->fetch_assoc()) {
        $checked++;
        $productId = (int)$product['product_id'];
        $productName = trim((string)$product['product_name']);
        $storedPhoto = trim((string)$product['product_photo']);
        $label = "#{$productId}" . ($productName !== '' ? " {$productName}" : '');
        $imagePath = resolveProductImagePath($storedPhoto);
        if ($imagePath === null) {
            $existing = currentEmbedding($conn, $productId);
            if ($pruneMissing && $existing) {
                deleteEmbedding($conn, $productId);
                $staleDeleted++;
                line("[PRUNED]  {$label} - image file is missing.");
            } else {
                line("[MISSING] {$label} - {$storedPhoto}");
            }
            $missing++;
            continue;
        }
        $imageHash = hash_file('sha256', $imagePath);
        if ($imageHash === false) {
            $failed++;
            line("[FAILED]  {$label} - unable to hash image.");
            continue;
        }
        $existing = currentEmbedding($conn, $productId);
        if (
            !$force
            && $existing
            && hash_equals((string)($existing['image_hash'] ?? ''), $imageHash)
            && (string)($existing['model_version'] ?? '') === $serviceModel
            && (int)($existing['dimensions'] ?? 0) === $serviceDimensions
        ) {
            $skipped++;
            line("[SKIP]    {$label} - image/model unchanged.");
            continue;
        }
        try {
            $validated = validateEmbeddingResponse(
                createEmbedding($imagePath),
                $serviceModel,
                $serviceDimensions
            );
            saveEmbedding(
                $conn,
                $productId,
                $validated['embedding'],
                $imageHash,
                $validated['model']
            );
            $saved++;
            $timing = $validated['processing_ms'] > 0
                ? ' - ' . number_format($validated['processing_ms'], 2) . ' ms'
                : '';
            line(
                "[SAVED]   {$label} - "
                . $validated['dimensions']
                . ' dimensions - '
                . basename($imagePath)
                . $timing
            );
        } catch (Throwable $e) {
            $failed++;
            line("[FAILED]  {$label} - " . $e->getMessage());
        }
    }
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    if ($productIdFilter !== null && $checked === 0) {
        line("[NOT FOUND] No product with an image was found for #{$productIdFilter}.");
    }
    line('');
    line('Finished.');
    line("Products checked : {$checked}");
    line("Saved/updated    : {$saved}");
    line("Skipped unchanged: {$skipped}");
    line("Missing images   : {$missing}");
    line("Stale deleted    : {$staleDeleted}");
    line("Failed           : {$failed}");
    exit($failed > 0 ? 2 : 0);
} catch (Throwable $e) {
    fail($e->getMessage());
}
