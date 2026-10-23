<?php
require_once __DIR__ . '/seller_auth.php';
header('Content-Type: application/json; charset=utf-8');
const MAX_COMPARISON_IMAGE_BYTES = 5242880; // 5 MB
const MIN_IMAGE_SIMILARITY = 0.55;
const AMBIGUOUS_IMAGE_SIMILARITY = 0.68;
const HIGH_CONFIDENCE_IMAGE_SIMILARITY = 0.80;
const VERY_STRONG_IMAGE_SIMILARITY = 0.84;
const MIN_FINAL_RELEVANCE = 0.62;
function jsonResponse(bool $success, string $message, array $extra = [], int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function imageServiceUrl(): string
{
    $configured = trim((string)getenv('LOKALCRAFT_IMAGE_SERVICE_URL'));
    if ($configured === '') {
        $configured = trim((string)getenv('IMAGE_SERVICE_URL'));
    }
    return rtrim($configured !== '' ? $configured : 'http://127.0.0.1:5001', '/');
}
function canonicalCraftCategory(mysqli $conn, string $category): ?string
{
    $category = trim($category);
    if ($category === '') {
        return null;
    }
    $stmt = $conn->prepare(
        'SELECT category_name
         FROM craft_categories
         WHERE LOWER(TRIM(category_name)) = LOWER(TRIM(?))
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to validate the craft category.');
    }
    $stmt->bind_param('s', $category);
    $stmt->execute();
    $stmt->bind_result($canonical);
    $found = $stmt->fetch();
    $stmt->close();
    if (!$found) {
        return null;
    }
    $canonical = trim((string)$canonical);
    return $canonical !== '' ? $canonical : null;
}
function normalizeText(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9\s]+/i', ' ', $value) ?? '';
    $value = preg_replace('/\s+/', ' ', $value) ?? '';
    return trim($value);
}
function meaningfulTokens(string $value): array
{
    $stopWords = [
        'the', 'and', 'for', 'with', 'from', 'this', 'that', 'handmade', 'product',
        'item', 'craft', 'crafted', 'custom', 'design', 'style', 'piece', 'pcs'
    ];
    $tokens = preg_split('/\s+/', normalizeText($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $tokens = array_values(array_unique(array_filter($tokens, static function ($token) use ($stopWords) {
        return strlen($token) >= 3 && !in_array($token, $stopWords, true);
    })));
    return $tokens;
}
function nameRelevance(string $queryName, string $candidateName): float
{
    $query = normalizeText($queryName);
    $candidate = normalizeText($candidateName);
    if ($query === '' || $candidate === '') {
        return 0.0;
    }
    if ($query === $candidate) {
        return 1.0;
    }
    $queryTokens = meaningfulTokens($query);
    $candidateTokens = meaningfulTokens($candidate);
    $score = 0.0;
    if ($queryTokens && $candidateTokens) {
        $overlapCount = count(array_intersect($queryTokens, $candidateTokens));
        if ($overlapCount > 0) {
            $queryCount = count($queryTokens);
            $candidateCount = count($candidateTokens);
            $dice = (2.0 * $overlapCount) / ($queryCount + $candidateCount);
            $balancedCoverage = min(
                $overlapCount / $queryCount,
                $overlapCount / $candidateCount
            );
            $tokenScore = ($dice * 0.70) + ($balancedCoverage * 0.30);
            $score = max($score, min(1.0, $tokenScore));
        }
    }
    if (str_contains($candidate, $query) || str_contains($query, $candidate)) {
        $shorterTokenCount = min(count($queryTokens), count($candidateTokens));
        $score = max($score, $shorterTokenCount >= 2 ? 0.80 : 0.65);
    }
    similar_text($query, $candidate, $percent);
    $score = max($score, min(1.0, ((float)$percent / 100.0) * 0.55));
    return round($score, 6);
}
function categoryMatchScore(string $queryCategory, string $candidateCategory): float
{
    $query = normalizeText($queryCategory);
    $candidate = normalizeText($candidateCategory);
    if ($query === '' || $candidate === '') {
        return 0.0;
    }
    if ($query === $candidate) {
        return 1.0;
    }
    $queryTokens = meaningfulTokens($query);
    $candidateTokens = meaningfulTokens($candidate);
    if (!$queryTokens || !$candidateTokens) {
        return 0.0;
    }
    $overlap = array_values(array_intersect($queryTokens, $candidateTokens));
    if (!$overlap) {
        return 0.0;
    }
    $queryCoverage = count($overlap) / count($queryTokens);
    $candidateCoverage = count($overlap) / count($candidateTokens);
    return round(min(1.0, ($queryCoverage * 0.6) + ($candidateCoverage * 0.4)), 6);
}
function localImageUrl(?string $path): string
{
    $path = trim((string)$path);
    if ($path === '') {
        return '';
    }
    $lowerPath = strtolower($path);
    if (str_starts_with($lowerPath, 'http://') || str_starts_with($lowerPath, 'https://')) {
        return $path;
    }
    return '../' . ltrim($path, '/');
}
function validateComparisonImage(array $file): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('Product image is required for market comparison.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('The product image could not be read for market comparison.');
    }
    if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > MAX_COMPARISON_IMAGE_BYTES) {
        throw new InvalidArgumentException('Product image must be 5 MB or smaller.');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('Invalid product image upload.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowed, true)) {
        throw new InvalidArgumentException('Product image must be JPG, PNG, or WEBP.');
    }
    return [
        'tmp_path' => $file['tmp_name'],
        'mime' => $mime,
        'name' => basename((string)($file['name'] ?? 'product-image')),
    ];
}
class MarketComparisonUnsafeUploadException extends RuntimeException {}
class MarketComparisonScannerUnavailableException extends RuntimeException {}
/** Write all bytes of a ClamAV INSTREAM frame, even if the socket writes partially. */
function marketComparisonClamavWrite($socket, string $bytes): void
{
    $offset = 0;
    $length = strlen($bytes);
    while ($offset < $length) {
        $written = @fwrite($socket, substr($bytes, $offset));
        if ($written === false || $written === 0) {
            throw new MarketComparisonScannerUnavailableException('ClamAV connection interrupted.');
        }
        $offset += $written;
    }
}
/** Scan the existing temporary upload without moving or modifying the image. */
function marketComparisonClamavScanOnce(string $temporaryPath): void
{
    $socket = null;
    $fileHandle = null;
    try {
        $socket = @stream_socket_client(
            'unix:///run/clamav/clamd.ctl',
            $socketError,
            $socketErrorMessage,
            3,
            STREAM_CLIENT_CONNECT
        );
        if ($socket === false) {
            throw new MarketComparisonScannerUnavailableException('ClamAV socket is unavailable.');
        }
        stream_set_timeout($socket, 10);
        $fileHandle = @fopen($temporaryPath, 'rb');
        if ($fileHandle === false) {
            throw new MarketComparisonScannerUnavailableException('Unable to read the image for scanning.');
        }
        marketComparisonClamavWrite($socket, "zINSTREAM\0");
        while (!feof($fileHandle)) {
            $chunk = fread($fileHandle, 32768);
            if ($chunk === false || ($chunk === '' && !feof($fileHandle))) {
                throw new MarketComparisonScannerUnavailableException('Unable to stream the image for scanning.');
            }
            if ($chunk === '') {
                break;
            }
            marketComparisonClamavWrite($socket, pack('N', strlen($chunk)) . $chunk);
        }
        marketComparisonClamavWrite($socket, pack('N', 0));
        $response = @stream_get_line($socket, 4096, "\0");
        if ($response === 'stream: OK') {
            return;
        }
        if (is_string($response) && preg_match('/^stream: .+ FOUND$/D', $response) === 1) {
            throw new MarketComparisonUnsafeUploadException(
                'This image did not pass our file safety check. Please choose another image.'
            );
        }
        throw new MarketComparisonScannerUnavailableException('ClamAV returned an incomplete or failed scan.');
    } finally {
        if (is_resource($fileHandle)) {
            fclose($fileHandle);
        }
        if (is_resource($socket)) {
            fclose($socket);
        }
    }
}
function marketComparisonVerifyImageSafe(string $temporaryPath): void
{
    // Local Windows XAMPP has no ClamAV daemon; the Linux VPS must pass a real scan.
    if (PHP_OS_FAMILY === 'Windows') {
        return;
    }
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        try {
            marketComparisonClamavScanOnce($temporaryPath);
            return;
        } catch (MarketComparisonScannerUnavailableException $e) {
            error_log('LokalCraft market comparison scan unavailable: ' . $e->getMessage());
            if ($attempt < 2) {
                usleep(250000);
            }
        }
    }
    throw new MarketComparisonScannerUnavailableException(
        'Image safety verification is temporarily unavailable. Please try again shortly.'
    );
}
function requestImageEmbedding(array $image): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is not enabled.');
    }
    $serviceUrl = imageServiceUrl();
    if ($serviceUrl === '') {
        throw new RuntimeException('Image-assisted comparison service is not configured.');
    }
    $ch = curl_init($serviceUrl . '/embed');
    if ($ch === false) {
        throw new RuntimeException('Unable to connect to the image comparison service.');
    }
    $postFields = [
        'image' => new CURLFile($image['tmp_path'], $image['mime'], $image['name']),
    ];
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        error_log('LokalCraft image comparison cURL error: ' . $error);
        throw new RuntimeException('Image-assisted comparison is temporarily unavailable.');
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Image comparison service returned an invalid response.');
    }
    if ($status < 200 || $status >= 300 || empty($data['success'])) {
        throw new RuntimeException((string)($data['message'] ?? 'Image comparison service could not process the image.'));
    }
    $embedding = $data['embedding'] ?? null;
    $model = trim((string)($data['model'] ?? ''));
    if (!is_array($embedding) || count($embedding) === 0 || $model === '') {
        throw new RuntimeException('Image comparison service returned incomplete embedding data.');
    }
    foreach ($embedding as $value) {
        if (!is_int($value) && !is_float($value)) {
            throw new RuntimeException('Image comparison service returned an invalid embedding vector.');
        }
    }
    return [
        'embedding' => array_map('floatval', $embedding),
        'model' => $model,
    ];
}
function cosineSimilarity(array $a, array $b): float
{
    $count = count($a);
    if ($count === 0 || $count !== count($b)) {
        return 0.0;
    }
    $dot = 0.0;
    $normA = 0.0;
    $normB = 0.0;
    for ($i = 0; $i < $count; $i++) {
        $x = (float)$a[$i];
        $y = (float)$b[$i];
        $dot += $x * $y;
        $normA += $x * $x;
        $normB += $y * $y;
    }
    if ($normA <= 0.0 || $normB <= 0.0) {
        return 0.0;
    }
    $score = $dot / (sqrt($normA) * sqrt($normB));
    return max(-1.0, min(1.0, $score));
}
function fetchOptionalMetrics(mysqli $conn, int $productId): array
{
    $rating = 0.0;
    $reviewCount = 0;
    $saveCount = 0;
    try {
        $reviewStmt = $conn->prepare(
            'SELECT COALESCE(AVG(rating), 0) AS avg_rating, COUNT(review_id) AS review_count
             FROM reviews
             WHERE product_id = ?'
        );
        $reviewStmt->bind_param('i', $productId);
        $reviewStmt->execute();
        $row = $reviewStmt->get_result()->fetch_assoc();
        $reviewStmt->close();
        if ($row) {
            $rating = round((float)$row['avg_rating'], 1);
            $reviewCount = (int)$row['review_count'];
        }
    } catch (Throwable $e) {
        $rating = 0.0;
        $reviewCount = 0;
    }
    try {
        $saveStmt = $conn->prepare(
            'SELECT COUNT(saved_id) AS save_count
             FROM saved_products
             WHERE product_id = ?'
        );
        $saveStmt->bind_param('i', $productId);
        $saveStmt->execute();
        $row = $saveStmt->get_result()->fetch_assoc();
        $saveStmt->close();
        if ($row) {
            $saveCount = (int)$row['save_count'];
        }
    } catch (Throwable $e) {
        $saveCount = 0;
    }
    return [
        'rating' => $rating,
        'review_count' => $reviewCount,
        'save_count' => $saveCount,
    ];
}
function buildOutputItems(mysqli $conn, array $candidates): array
{
    $items = [];
    foreach (array_slice($candidates, 0, 6) as $candidate) {
        $metrics = fetchOptionalMetrics($conn, (int)$candidate['product_id']);
        $matchScore = max(0.0, min(1.0, (float)($candidate['score'] ?? 0)));
        $items[] = [
            'source_name' => 'LokalCraft',
            'product_name' => $candidate['product_name'],
            'price' => $candidate['price'],
            'image_url' => $candidate['image_url'],
            'product_url' => $candidate['product_url'],
            'rating' => $metrics['rating'],
            'review_count' => $metrics['review_count'],
            'save_count' => $metrics['save_count'],
            'match_score' => round($matchScore, 6),
            'match_percent' => (int)round($matchScore * 100),
            'image_similarity' => round(max(0.0, min(1.0, (float)($candidate['image_similarity'] ?? 0))), 6),
            'name_similarity' => round(max(0.0, min(1.0, (float)($candidate['name_similarity'] ?? 0))), 6),
            'category_similarity' => round(max(0.0, min(1.0, (float)($candidate['category_similarity'] ?? 0))), 6),
        ];
    }
    return $items;
}
function searchLokalCraftImageFirst(
    mysqli $conn,
    int $sellerId,
    string $productName,
    string $craftCategory,
    array $queryEmbedding,
    string $queryModel
): array {
    $stmt = $conn->prepare(
        "SELECT
            p.product_id,
            p.product_photo,
            p.product_name,
            p.craft_category,
            p.description,
            p.cost,
            e.embedding,
            e.model_version
         FROM products p
         INNER JOIN product_image_embeddings e
            ON e.product_id = p.product_id
         WHERE p.seller_id <> ?
           AND LOWER(p.status) = 'available'
           AND GREATEST(p.stock - p.reserved_stock, 0) > 0
         ORDER BY p.product_id DESC
         LIMIT 500"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to load comparable product embeddings.');
    }
    $stmt->bind_param('i', $sellerId);
    $stmt->execute();
    $result = $stmt->get_result();
    $candidates = [];
    while ($row = $result->fetch_assoc()) {
        if ((string)$row['model_version'] !== $queryModel) {
            continue;
        }
        $storedEmbedding = json_decode((string)$row['embedding'], true);
        if (
            !is_array($storedEmbedding)
            || count($storedEmbedding) !== count($queryEmbedding)
        ) {
            continue;
        }
        $imageSimilarity = cosineSimilarity(
            $queryEmbedding,
            array_map('floatval', $storedEmbedding)
        );
        if ($imageSimilarity < MIN_IMAGE_SIMILARITY) {
            continue;
        }
        $nameScore = nameRelevance(
            $productName,
            (string)$row['product_name']
        );
        $categoryScore = categoryMatchScore(
            $craftCategory,
            (string)$row['craft_category']
        );
        if ($imageSimilarity < AMBIGUOUS_IMAGE_SIMILARITY) {
            $supportedWeakMatch =
                $nameScore >= 0.65
                || ($nameScore >= 0.50 && $categoryScore >= 0.50);
            if (!$supportedWeakMatch) {
                continue;
            }
        } elseif ($imageSimilarity < HIGH_CONFIDENCE_IMAGE_SIMILARITY) {
            $supportedAmbiguousMatch =
                $nameScore >= 0.50
                || $categoryScore >= 0.50;
            if (!$supportedAmbiguousMatch) {
                continue;
            }
        }
        $finalScore =
            ($imageSimilarity * 0.75)
            + ($nameScore * 0.20)
            + ($categoryScore * 0.05);
        $minimumFinalRelevance =
            $imageSimilarity >= HIGH_CONFIDENCE_IMAGE_SIMILARITY
                ? 0.58
                : MIN_FINAL_RELEVANCE;
        if ($finalScore < $minimumFinalRelevance) {
            continue;
        }
        $price = max(0, (float)$row['cost']);
        if ($price <= 0) {
            continue;
        }
        $matchTier = 'related';
        if (
            $imageSimilarity >= VERY_STRONG_IMAGE_SIMILARITY
            || $finalScore >= 0.84
        ) {
            $matchTier = 'strong';
        } elseif (
            $imageSimilarity < AMBIGUOUS_IMAGE_SIMILARITY
            || $finalScore < 0.70
        ) {
            $matchTier = 'supported';
        }
        $candidates[] = [
            'score' => $finalScore,
            'image_similarity' => $imageSimilarity,
            'name_similarity' => $nameScore,
            'category_similarity' => $categoryScore,
            'match_tier' => $matchTier,
            'product_id' => (int)$row['product_id'],
            'product_name' => (string)$row['product_name'],
            'price' => round($price, 2),
            'image_url' => localImageUrl($row['product_photo'] ?? ''),
            'product_url' => '',
        ];
    }
    $stmt->close();
    usort($candidates, static function (array $a, array $b): int {
        if (abs($a['score'] - $b['score']) < 0.000001) {
            if (
                abs($a['image_similarity'] - $b['image_similarity'])
                < 0.000001
            ) {
                return $b['product_id'] <=> $a['product_id'];
            }
            return $b['image_similarity'] <=> $a['image_similarity'];
        }
        return $b['score'] <=> $a['score'];
    });
    return buildOutputItems($conn, $candidates);
}
function searchLokalCraftTextFallback(
    mysqli $conn,
    int $sellerId,
    string $productName,
    string $craftCategory
): array {
    $stmt = $conn->prepare(
        "SELECT
            product_id,
            product_photo,
            product_name,
            craft_category,
            description,
            cost
         FROM products
         WHERE seller_id <> ?
           AND LOWER(status) = 'available'
           AND GREATEST(stock - reserved_stock, 0) > 0
         ORDER BY product_id DESC
         LIMIT 300"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to load fallback comparable products.');
    }
    $stmt->bind_param('i', $sellerId);
    $stmt->execute();
    $result = $stmt->get_result();
    $queryName = normalizeText($productName);
    $queryTokens = meaningfulTokens($productName);
    $candidates = [];
    while ($row = $result->fetch_assoc()) {
        $candidateName = normalizeText((string)$row['product_name']);
        $candidateDescription = normalizeText(
            (string)($row['description'] ?? '')
        );
        $candidateTokens = meaningfulTokens(
            (string)$row['product_name'] . ' ' . $candidateDescription
        );
        $overlap = array_values(
            array_intersect($queryTokens, $candidateTokens)
        );
        $overlapCount = count($overlap);
        $overlapRatio = count($queryTokens) > 0
            ? $overlapCount / count($queryTokens)
            : 0.0;
        $exactName =
            $candidateName !== ''
            && $candidateName === $queryName;
        $containsName =
            $queryName !== ''
            && $candidateName !== ''
            && (
                str_contains($candidateName, $queryName)
                || str_contains($queryName, $candidateName)
            );
        $nameScore = nameRelevance(
            $productName,
            (string)$row['product_name']
        );
        $categoryScore = categoryMatchScore(
            $craftCategory,
            (string)$row['craft_category']
        );
        $hasMeaningfulTextMatch =
            $exactName
            || $containsName
            || $overlapCount > 0
            || $nameScore >= 0.55;
        if (!$hasMeaningfulTextMatch) {
            continue;
        }
        $score =
            ($nameScore * 0.80)
            + ($overlapRatio * 0.15)
            + ($categoryScore * 0.05);
        if ($exactName) {
            $score = min(1.0, $score + 0.08);
        } elseif ($containsName) {
            $score = min(1.0, $score + 0.04);
        }
        if ($score < 0.50) {
            continue;
        }
        $price = max(0, (float)$row['cost']);
        if ($price <= 0) {
            continue;
        }
        $candidates[] = [
            'score' => $score,
            'image_similarity' => 0.0,
            'name_similarity' => $nameScore,
            'category_similarity' => $categoryScore,
            'match_tier' => 'text_fallback',
            'product_id' => (int)$row['product_id'],
            'product_name' => (string)$row['product_name'],
            'price' => round($price, 2),
            'image_url' => localImageUrl($row['product_photo'] ?? ''),
            'product_url' => '',
        ];
    }
    $stmt->close();
    usort($candidates, static function (array $a, array $b): int {
        if (abs($a['score'] - $b['score']) < 0.000001) {
            return $b['product_id'] <=> $a['product_id'];
        }
        return $b['score'] <=> $a['score'];
    });
    return buildOutputItems($conn, $candidates);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}
try {
    $sellerId = (int)$sellerId;
    $sessionCsrf = $_SESSION['costing_csrf'] ?? null;
    $requestCsrf = $_POST['csrf_token'] ?? null;
    if (
        !is_string($sessionCsrf) || $sessionCsrf === ''
        || !is_string($requestCsrf) || $requestCsrf === ''
        || !hash_equals($sessionCsrf, $requestCsrf)
    ) {
        jsonResponse(false, 'Your session token expired. Please refresh the page and try again.', [], 403);
    }
    $productName = trim((string)($_POST['product_name'] ?? ''));
    $craftCategory = trim((string)($_POST['craft_category'] ?? ''));
    $suggestedPrice = max(0, (float)($_POST['suggested_price'] ?? 0));
    if ($productName === '') {
        jsonResponse(false, 'Product name is required for market comparison.', [], 422);
    }
    if ($craftCategory === '') {
        jsonResponse(false, 'Craft category is required for market comparison.', [], 422);
    }
    $canonicalCategory = canonicalCraftCategory($conn, $craftCategory);
    if ($canonicalCategory === null) {
        jsonResponse(false, 'The selected craft category is no longer available. Please choose another category.', [], 422);
    }
    $craftCategory = $canonicalCategory;
    try {
        $comparisonImage = validateComparisonImage($_FILES['product_image'] ?? []);
    } catch (InvalidArgumentException $e) {
        jsonResponse(false, $e->getMessage(), [], 422);
    }
    // A scanner failure is not an AI failure: never fall back to text for an unscanned image.
    try {
        marketComparisonVerifyImageSafe($comparisonImage['tmp_path']);
    } catch (MarketComparisonUnsafeUploadException $e) {
        jsonResponse(false, $e->getMessage(), [], 422);
    } catch (MarketComparisonScannerUnavailableException $e) {
        jsonResponse(false, $e->getMessage(), [], 503);
    }
    $sourcesWorked = 0;
    $items = [];
    $comparisonMethod = 'none';
    $imageServiceWorked = false;
    try {
        $queryImage = requestImageEmbedding($comparisonImage);
        $imageServiceWorked = true;
        $items = searchLokalCraftImageFirst(
            $conn,
            $sellerId,
            $productName,
            $craftCategory,
            $queryImage['embedding'],
            $queryImage['model']
        );
        $sourcesWorked++;
        if ($items) {
            $comparisonMethod = 'image_first';
        }
    } catch (Throwable $imageComparisonError) {
        error_log(
            'LokalCraft market image comparison unavailable: '
            . $imageComparisonError->getMessage()
        );
    }
    if (!$items) {
        try {
            $items = searchLokalCraftTextFallback(
                $conn,
                $sellerId,
                $productName,
                $craftCategory
            );
            $sourcesWorked++;
            $comparisonMethod = $items
                ? 'text_fallback'
                : ($imageServiceWorked ? 'image_first' : 'text_fallback');
        } catch (Throwable $fallbackError) {
            error_log(
                'LokalCraft market text fallback failed: '
                . $fallbackError->getMessage()
            );
        }
    }
    $state = 'unavailable';
    if (count($items) > 0) {
        $state = 'results';
    } elseif ($sourcesWorked > 0) {
        $state = 'no_matches';
    }
    $_SESSION['market_comparison_preview'] = [
        'product_name' => $productName,
        'craft_category' => $craftCategory,
        'suggested_price' => $suggestedPrice,
        'comparison_method' => $comparisonMethod,
        'source_scope' => 'LokalCraft active listings ranked primarily by image similarity with product-name and category support',
        'items' => $items,
        'checked_at' => time(),
    ];
    jsonResponse(true, 'Market comparison completed.', [
        'state' => $state,
        'items' => $items,
        'result_count' => count($items),
        'comparison_method' => $comparisonMethod,
        'category' => $craftCategory,
        'source_scope' => 'LokalCraft active listings ranked primarily by image similarity with product-name and category support',
    ]);
} catch (Throwable $e) {
    error_log('LokalCraft market comparison error: ' . $e->getMessage());
    jsonResponse(false, 'Market comparison could not be completed right now. Please try again.', [
        'state' => 'unavailable',
        'items' => [],
        'result_count' => 0,
        'comparison_method' => 'none',
    ], 500);
}
