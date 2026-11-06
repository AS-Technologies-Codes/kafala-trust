<?php

/*
|--------------------------------------------------------------------------
| Kifala Trust API
|--------------------------------------------------------------------------
| File: public_html/admin/kifala_api.php
| Database: admin/config/db.php
| Uploads: admin/uploads/programs, admin/uploads/gallery, admin/uploads/partners
|
| Changes in this version:
|  - NEW: per-donation bank accounts (table donation_bank_details).
|    Each donation can have its OWN bank account. If it has none, the shared
|    default account (bank_details, id = 1) is used.
|    Every donation row now returns:
|        has_custom_bank, custom_bank, bank_details (effective), bank_source
|  - Donation payment types (payment_types column, currently "bank_transfer").
|  - "current" (featured) program flag: programs.is_featured.
|--------------------------------------------------------------------------
| Required table (run once):
|
|   CREATE TABLE donation_bank_details (
|       id INT AUTO_INCREMENT PRIMARY KEY,
|       donation_id INT NOT NULL UNIQUE,   -- same type as donations.id
|       bank_name VARCHAR(150) NOT NULL,
|       account_title VARCHAR(150) NOT NULL,
|       account_number VARCHAR(50) NOT NULL,
|       iban VARCHAR(50) NULL,
|       branch_name VARCHAR(150) NULL,
|       swift_code VARCHAR(20) NULL,
|       instructions VARCHAR(1000) NULL,
|       created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
|       updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
|   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config/db.php';

/*
|--------------------------------------------------------------------------
| Headers / CORS
|--------------------------------------------------------------------------
| Optionally define KIFALA_ALLOWED_ORIGIN (env or constant), e.g.
| https://kifalatrust.org  to restrict browser access. Default: "*".
|--------------------------------------------------------------------------
*/

$allowedOrigin = getenv('KIFALA_ALLOWED_ORIGIN');

if (!$allowedOrigin && defined('KIFALA_ALLOWED_ORIGIN')) {
    $allowedOrigin = KIFALA_ALLOWED_ORIGIN;
}

if (!$allowedOrigin) {
    $allowedOrigin = '*';
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($allowedOrigin !== '*') {
    header('Vary: Origin');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$programUploadPath = __DIR__ . '/uploads/programs/';
$galleryUploadPath = __DIR__ . '/uploads/gallery/';
$partnerUploadPath = __DIR__ . '/uploads/partners/';

$programBaseUrl = 'https://kifalatrust.org/admin/uploads/programs/';
$galleryBaseUrl = 'https://kifalatrust.org/admin/uploads/gallery/';
$partnerBaseUrl = 'https://kifalatrust.org/admin/uploads/partners/';

const DEFAULT_PRESET_AMOUNTS = '100,200,500,1000,10000';

// Allowed program categories (change this list to add/remove categories).
// Keep in sync with $programCategories in programs.php.
const PROGRAM_CATEGORIES = [
    'Food',
    'Health',
    'Education',
    'Welfare',
    'Social Welfare',
    'Family Support',
    'Legal Assistance'
];

// Allowed donation payment types. To add a new one later, add a line here,
// e.g. 'online_card' => 'Online / Card'.
const PAYMENT_TYPES = [
    'bank_transfer' => 'Bank Transfer'
];

const DEFAULT_PAYMENT_TYPES = 'bank_transfer';

// Bank account fields and their maximum lengths
// (used for the default account AND the per-donation accounts).
const BANK_FIELD_LIMITS = [
    'bank_name'      => 150,
    'account_title'  => 150,
    'account_number' => 50,
    'iban'           => 50,
    'branch_name'    => 150,
    'swift_code'     => 20,
    'instructions'   => 1000
];

function sendJson(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
}

try {
    $pdo = getPdoConnection();
} catch (Throwable $e) {
    error_log('Kifala API database connection error: ' . $e->getMessage());
    sendJson(['status' => 'error', 'message' => 'Database connection failed'], 500);
    exit;
}

/*
|--------------------------------------------------------------------------
| Route selection (read once, used for auth and routing)
|--------------------------------------------------------------------------
*/

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($action === '') {
    // JSON body: {"action": "..."}
    $rawBody = file_get_contents('php://input');

    if ($rawBody !== false && $rawBody !== '') {
        $jsonBody = json_decode($rawBody, true);

        if (is_array($jsonBody) && isset($jsonBody['action'])) {
            $action = (string)$jsonBody['action'];
        }
    }
}

/*
|--------------------------------------------------------------------------
| API Authentication
|--------------------------------------------------------------------------
| Configure KIFALA_API_TOKEN in hosting environment or config/db.php.
| Never expose this secret in public React/frontend JavaScript.
| Actions listed in $publicActions do not need a token.
|--------------------------------------------------------------------------
*/

$publicActions = [
    'subscribe_newsletter'
];

if (!in_array($action, $publicActions, true)) {
    $secretToken = getenv('KIFALA_API_TOKEN');

    if (!$secretToken && defined('KIFALA_API_TOKEN')) {
        $secretToken = KIFALA_API_TOKEN;
    }

    if (!$secretToken) {
        sendJson([
            'status' => 'error',
            'message' => 'API token is not configured on the server'
        ], 500);

        closePdoConnection($pdo);
        exit;
    }

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = '';

    foreach ($headers as $key => $value) {
        if (strtolower((string)$key) === 'authorization') {
            $authHeader = trim((string)$value);
            break;
        }
    }

    if ($authHeader === '') {
        $authHeader = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    }

    if ($authHeader === '') {
        $authHeader = trim((string)($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    }

    if (!hash_equals('Bearer ' . (string)$secretToken, $authHeader)) {
        sendJson(['status' => 'error', 'message' => 'Unauthorized'], 401);
        closePdoConnection($pdo);
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function requireMethod(string $method): void
{
    $actual = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($actual !== strtoupper($method)) {
        header('Allow: ' . strtoupper($method));
        throw new RuntimeException(
            'Method not allowed. Use ' . strtoupper($method) . '.',
            405
        );
    }
}

function requestInput(): array
{
    static $parsed = null;

    if ($parsed !== null) {
        return $parsed;
    }

    $parsed = $_POST ?: [];
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));

    if (strpos($contentType, 'application/json') !== false || empty($parsed)) {
        $raw = file_get_contents('php://input');

        if ($raw !== false && $raw !== '') {
            $json = json_decode($raw, true);

            if (is_array($json)) {
                $parsed = $json;
            }
        }
    }

    return $parsed;
}

function inputValue(string $key, $default = null)
{
    $input = requestInput();
    return $input[$key] ?? $_GET[$key] ?? $default;
}

function validPositiveInt($value, string $label): int
{
    $number = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1]
    ]);

    if ($number === false) {
        throw new InvalidArgumentException("Valid {$label} is required");
    }

    return (int)$number;
}

function validateStatus($value, array $allowed, string $label = 'status'): string
{
    $value = (string)$value;

    if (!in_array($value, $allowed, true)) {
        throw new InvalidArgumentException(
            'Invalid ' . $label . '. Allowed: ' . implode(', ', $allowed)
        );
    }

    return $value;
}

/**
 * "1", "true", "on", "yes" => true. Everything else => false.
 */
function parseBool($value): bool
{
    return in_array(strtolower((string)$value), ['1', 'true', 'on', 'yes'], true);
}

function paginationParams(): array
{
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
    $limit = filter_var($_GET['limit'] ?? 10, FILTER_VALIDATE_INT);

    $page = ($page === false || $page < 1) ? 1 : (int)$page;
    $limit = ($limit === false || $limit < 1) ? 10 : (int)$limit;
    $limit = min($limit, 100);

    return [$page, $limit, ($page - 1) * $limit];
}

function paginatedFetch(
    PDO $pdo,
    string $selectSql,
    string $countSql,
    array $params
): array {
    [$page, $limit, $offset] = paginationParams();

    $count = $pdo->prepare($countSql);
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $stmt = $pdo->prepare(
        $selectSql . ' LIMIT ' . $limit . ' OFFSET ' . $offset
    );
    $stmt->execute($params);

    return [
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_records' => $total,
            'total_pages' => (int)ceil($total / max($limit, 1))
        ]
    ];
}

/*
|--------------------------------------------------------------------------
| Public Media URLs
|--------------------------------------------------------------------------
*/

function publicMediaUrl($value, string $baseUrl): ?string
{
    if ($value === null || trim((string)$value) === '') {
        return null;
    }

    $value = trim((string)$value);

    if (filter_var($value, FILTER_VALIDATE_URL)) {
        return $value;
    }

    $value = str_replace('\\', '/', $value);
    $value = ltrim($value, '/');

    // Handle old paths saved in the database.
    $value = preg_replace(
        '#^(?:(?:dist/)?admin/)?uploads/(?:programs|gallery|partners)/#i',
        '',
        $value
    );

    $filename = basename($value);

    if ($filename === '' || $filename === '.' || $filename === '/') {
        return null;
    }

    return rtrim($baseUrl, '/') . '/' . rawurlencode($filename);
}

function normalizeProgramRows(array $rows, string $baseUrl): array
{
    foreach ($rows as &$row) {
        $row['image'] = publicMediaUrl($row['image'] ?? null, $baseUrl);
        $row['is_featured'] = (bool)($row['is_featured'] ?? false);
    }

    unset($row);
    return $rows;
}

function normalizeGalleryRows(array $rows, string $baseUrl): array
{
    foreach ($rows as &$row) {
        if (strtolower((string)($row['media_type'] ?? '')) === 'image') {
            $row['media_url'] = publicMediaUrl(
                $row['media_url'] ?? null,
                $baseUrl
            );
        }

        // Keep program_id numeric (or null) for the frontend.
        if (array_key_exists('program_id', $row)) {
            $row['program_id'] = $row['program_id'] !== null
                ? (int)$row['program_id']
                : null;
        }
    }

    unset($row);
    return $rows;
}

/*
|--------------------------------------------------------------------------
| Image Upload Validation
|--------------------------------------------------------------------------
*/

function uploadImage(string $field, string $uploadDir): string
{
    if (
        !isset($_FILES[$field]) ||
        (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
    ) {
        throw new InvalidArgumentException('Please select an image to upload');
    }

    if ((int)$_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Image upload failed');
    }

    if ((int)$_FILES[$field]['size'] > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('Image size must not exceed 5 MB');
    }

    $tmp = $_FILES[$field]['tmp_name'];

    if (!is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('Invalid uploaded file');
    }

    $imageInfo = @getimagesize($tmp);

    if ($imageInfo === false) {
        throw new InvalidArgumentException('Please upload a valid image');
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif'
    ];

    $mime = (string)($imageInfo['mime'] ?? '');

    if (!isset($allowed[$mime])) {
        throw new InvalidArgumentException(
            'Only JPG, PNG, WEBP and GIF images are allowed'
        );
    }

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Could not create upload directory');
        }
    }

    if (!is_writable($uploadDir)) {
        throw new RuntimeException('Upload directory is not writable');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $destination = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($tmp, $destination)) {
        throw new RuntimeException('Could not save uploaded image');
    }

    return $filename;
}

function validateYoutubeUrl(string $url): string
{
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('A valid YouTube URL is required');
    }

    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));

    $allowedHosts = [
        'youtube.com',
        'www.youtube.com',
        'm.youtube.com',
        'music.youtube.com',
        'youtu.be',
        'www.youtu.be',
        'youtube-nocookie.com',
        'www.youtube-nocookie.com'
    ];

    if (!in_array($host, $allowedHosts, true)) {
        throw new InvalidArgumentException('Please provide a valid YouTube URL');
    }

    return $url;
}

/*
|--------------------------------------------------------------------------
| Gallery media type + program helpers
|--------------------------------------------------------------------------
| admin/gallery.php stores YouTube items as media_type = 'video'.
| The API accepts both 'video' and 'youtube' and always stores 'video'.
|--------------------------------------------------------------------------
*/

function validateMediaType($value): string
{
    $type = strtolower(trim((string)$value));

    if ($type === 'youtube') {
        $type = 'video';
    }

    if (!in_array($type, ['image', 'video'], true)) {
        throw new InvalidArgumentException(
            'Invalid media type. Allowed: image, video (youtube)'
        );
    }

    return $type;
}

/**
 * Validate a program category. Matching is case-insensitive and the
 * canonical spelling from PROGRAM_CATEGORIES is returned.
 * Empty value => null (unless $required is true).
 */
function validateProgramCategory($value, bool $required = false): ?string
{
    $value = trim((string)($value ?? ''));

    if ($value === '') {
        if ($required) {
            throw new InvalidArgumentException('Category is required');
        }

        return null;
    }

    foreach (PROGRAM_CATEGORIES as $category) {
        if (strcasecmp($category, $value) === 0) {
            return $category;
        }
    }

    throw new InvalidArgumentException(
        'Invalid category. Allowed: ' . implode(', ', PROGRAM_CATEGORIES)
    );
}

function isVideoType($value): bool
{
    return in_array(strtolower((string)$value), ['video', 'youtube'], true);
}

function resolveProgramId(
    PDO $pdo,
    $value,
    ?int $currentProgramId = null,
    bool $required = true
): ?int {
    if ($value === null || trim((string)$value) === '') {
        if ($required) {
            throw new InvalidArgumentException('Please select a program');
        }

        return null;
    }

    $programId = validPositiveInt($value, 'program ID');

    $stmt = $pdo->prepare('SELECT status FROM programs WHERE id = ?');
    $stmt->execute([$programId]);
    $status = $stmt->fetchColumn();

    if ($status === false) {
        throw new RuntimeException('Program not found', 404);
    }

    // An item may keep its old program even if that program is now inactive,
    // but a NEW inactive program cannot be selected.
    if ($status !== 'active' && $programId !== $currentProgramId) {
        throw new InvalidArgumentException('Please select an active program');
    }

    return $programId;
}

/**
 * Only ONE program can be the "current" (featured) program at a time.
 * A single UPDATE statement is used, so the switch is atomic.
 *   $featured = true  => this program becomes the only featured one
 *   $featured = false => this program is un-featured
 */
function setFeaturedProgram(PDO $pdo, int $programId, bool $featured = true): void
{
    if ($featured) {
        $stmt = $pdo->prepare(
            'UPDATE programs SET is_featured = (id = ?) WHERE is_featured = 1 OR id = ?'
        );
        $stmt->execute([$programId, $programId]);
        return;
    }

    $stmt = $pdo->prepare('UPDATE programs SET is_featured = 0 WHERE id = ?');
    $stmt->execute([$programId]);
}

/*
|--------------------------------------------------------------------------
| Partner helpers (logo upload, website URL, display order)
|--------------------------------------------------------------------------
*/

/**
 * SVG files can contain scripts, so they are checked strictly.
 */
function validateSvgContent(string $svg): void
{
    if ($svg === '' || strlen($svg) > 512 * 1024) {
        throw new InvalidArgumentException('SVG logo must be smaller than 500 KB');
    }

    $unsafe = '/<!DOCTYPE|<!ENTITY|<script|<foreignObject|<iframe|<embed|<object|<\?php'
        . '|\son[a-z]+\s*=|javascript:|data:text\/html|@import|url\(\s*["\']?\s*https?:/i';

    if (preg_match($unsafe, $svg)) {
        throw new InvalidArgumentException('This SVG contains unsafe content');
    }

    // Only internal (#id) or embedded image references are allowed.
    if (preg_match('/href\s*=\s*["\']\s*(?!#|data:image\/)/i', $svg)) {
        throw new InvalidArgumentException('SVG must not link to external resources');
    }

    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadXML($svg, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (
        !$loaded ||
        !$dom->documentElement ||
        strtolower($dom->documentElement->localName) !== 'svg'
    ) {
        throw new InvalidArgumentException('Please upload a valid SVG file');
    }
}

function uploadPartnerLogo(string $field, string $uploadDir): string
{
    if (
        !isset($_FILES[$field]) ||
        (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
    ) {
        throw new InvalidArgumentException('Please select a logo to upload');
    }

    if ((int)$_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Logo upload failed');
    }

    if ((int)$_FILES[$field]['size'] > 2 * 1024 * 1024) {
        throw new InvalidArgumentException('Logo size must not exceed 2 MB');
    }

    $tmp = $_FILES[$field]['tmp_name'];

    if (!is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('Invalid uploaded file');
    }

    $originalExt = strtolower(pathinfo(
        (string)($_FILES[$field]['name'] ?? ''),
        PATHINFO_EXTENSION
    ));

    $imageInfo = @getimagesize($tmp);

    if ($imageInfo !== false) {
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp'
        ];

        $mime = (string)($imageInfo['mime'] ?? '');

        if (!isset($allowed[$mime])) {
            throw new InvalidArgumentException(
                'Only PNG, SVG, JPG and WEBP logos are allowed'
            );
        }

        $extension = $allowed[$mime];
    } else {
        // getimagesize() cannot read SVG, so check it separately.
        if ($originalExt !== 'svg') {
            throw new InvalidArgumentException(
                'Please upload a valid logo (PNG, SVG, JPG or WEBP)'
            );
        }

        validateSvgContent((string)file_get_contents($tmp));
        $extension = 'svg';
    }

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Could not create upload directory');
        }
    }

    if (!is_writable($uploadDir)) {
        throw new RuntimeException('Upload directory is not writable');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($tmp, $destination)) {
        throw new RuntimeException('Could not save uploaded logo');
    }

    return $filename;
}

function deletePartnerLogoFile(?string $logoUrl, string $uploadDir): void
{
    if (!$logoUrl) {
        return;
    }

    $path = (string)parse_url($logoUrl, PHP_URL_PATH);

    if (strpos($path, '/admin/uploads/partners/') !== 0) {
        return;
    }

    $filename = basename($path);

    if ($filename === '' || $filename === '.' || $filename === '..') {
        return;
    }

    $file = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $filename;

    if (is_file($file)) {
        @unlink($file);
    }
}

/**
 * Optional website URL. Adds https:// when no scheme is typed.
 */
function normalizePartnerWebsite($value): ?string
{
    $url = trim((string)($value ?? ''));

    if ($url === '') {
        return null;
    }

    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
        $url = 'https://' . $url;
    }

    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    $host = (string)parse_url($url, PHP_URL_HOST);

    if (
        !in_array($scheme, ['http', 'https'], true) ||
        $host === '' ||
        !filter_var($url, FILTER_VALIDATE_URL)
    ) {
        throw new InvalidArgumentException(
            'Website URL must be a valid http or https link'
        );
    }

    if (strlen($url) > 500) {
        throw new InvalidArgumentException('Website URL is too long');
    }

    return $url;
}

/**
 * Display order index. Empty value => $default, or next free number.
 */
function partnerDisplayOrder(PDO $pdo, $value, ?int $default = null): int
{
    if ($value === null || trim((string)$value) === '') {
        if ($default !== null) {
            return $default;
        }

        return (int)$pdo->query(
            'SELECT COALESCE(MAX(display_order), 0) + 1 FROM partners'
        )->fetchColumn();
    }

    $order = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 0, 'max_range' => 99999]
    ]);

    if ($order === false) {
        throw new InvalidArgumentException(
            'Display order must be a whole number between 0 and 99999'
        );
    }

    return (int)$order;
}

function normalizePartnerRows(array $rows, string $baseUrl): array
{
    foreach ($rows as &$row) {
        $row['logo'] = publicMediaUrl($row['logo'] ?? null, $baseUrl);
        $row['display_order'] = (int)($row['display_order'] ?? 0);
    }

    unset($row);
    return $rows;
}

/*
|--------------------------------------------------------------------------
| Donation helpers
|--------------------------------------------------------------------------
*/

function donationAmounts($totalInput, $raisedInput): array
{
    if (
        !is_numeric($totalInput) ||
        !is_numeric($raisedInput) ||
        !is_finite((float)$totalInput) ||
        !is_finite((float)$raisedInput) ||
        (float)$totalInput < 0 ||
        (float)$raisedInput < 0
    ) {
        throw new InvalidArgumentException(
            'Donation target and raised amount must be valid non-negative numbers'
        );
    }

    $total = round((float)$totalInput, 2);
    $raised = round((float)$raisedInput, 2);

    if ($total > 9999999999.99 || $raised > 9999999999.99) {
        throw new InvalidArgumentException('Donation amount exceeds the allowed limit');
    }

    $percentage = $total > 0 ? round(($raised / $total) * 100, 2) : 0;

    return [$total, $raised, $percentage];
}

/**
 * Validate, de-duplicate, sort and format comma-separated preset amounts.
 * "500, 100,200,100" => "100,200,500"
 */
function donationPresetAmounts($input): string
{
    $raw = trim((string)($input ?? ''));

    if ($raw === '') {
        return DEFAULT_PRESET_AMOUNTS;
    }

    $parts = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY);
    $values = [];

    foreach ($parts as $part) {
        $part = trim($part);

        if (
            !preg_match('/^\d+(\.\d{1,2})?$/', $part) ||
            (float)$part <= 0 ||
            (float)$part > 9999999999.99
        ) {
            throw new InvalidArgumentException(
                'Preset amounts must be positive numbers separated by commas (e.g. 100, 200, 500)'
            );
        }

        $values[] = round((float)$part, 2);
    }

    $values = array_values(array_unique($values));
    sort($values, SORT_NUMERIC);

    if (count($values) > 8) {
        throw new InvalidArgumentException('You can add a maximum of 8 preset amounts');
    }

    $formatted = array_map(function ($v) {
        return floor($v) == $v
            ? (string)(int)$v
            : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }, $values);

    $csv = implode(',', $formatted);

    if (strlen($csv) > 255) {
        throw new InvalidArgumentException('Preset amounts list is too long');
    }

    return $csv;
}

/**
 * "100,200,500" => [100, 200, 500]
 */
function presetAmountsList(?string $csv): array
{
    $csv = trim((string)$csv) !== '' ? (string)$csv : DEFAULT_PRESET_AMOUNTS;
    $list = [];

    foreach (explode(',', $csv) as $item) {
        $item = trim($item);

        if ($item === '' || !is_numeric($item)) {
            continue;
        }

        $number = (float)$item;
        $list[] = floor($number) == $number ? (int)$number : $number;
    }

    return $list;
}

/**
 * Custom amount settings: [allow (0/1), minimum amount].
 */
function donationCustomSettings($allowInput, $minInput): array
{
    $allow = in_array(strtolower((string)$allowInput), ['1', 'true', 'on', 'yes'], true) ? 1 : 0;

    $minRaw = ($minInput === null || trim((string)$minInput) === '') ? '1' : $minInput;

    if (
        !is_numeric($minRaw) ||
        !is_finite((float)$minRaw) ||
        (float)$minRaw <= 0 ||
        (float)$minRaw > 9999999999.99
    ) {
        throw new InvalidArgumentException(
            'Minimum custom amount must be a positive number'
        );
    }

    return [$allow, round((float)$minRaw, 2)];
}

/**
 * Validate payment types. Accepts an array or a comma-separated string and
 * returns a clean comma-separated string, e.g. "bank_transfer".
 * Empty value => default payment type.
 */
function donationPaymentTypes($input): string
{
    $items = is_array($input)
        ? $input
        : preg_split('/\s*,\s*/', trim((string)($input ?? '')), -1, PREG_SPLIT_NO_EMPTY);

    if (!$items) {
        return DEFAULT_PAYMENT_TYPES;
    }

    $clean = [];

    foreach ($items as $item) {
        $item = strtolower(trim((string)$item));

        if ($item === '') {
            continue;
        }

        if (!isset(PAYMENT_TYPES[$item])) {
            throw new InvalidArgumentException(
                'Invalid payment type. Allowed: ' . implode(', ', array_keys(PAYMENT_TYPES))
            );
        }

        $clean[$item] = true;
    }

    if (!$clean) {
        return DEFAULT_PAYMENT_TYPES;
    }

    return implode(',', array_keys($clean));
}

/**
 * "bank_transfer" => [['value' => 'bank_transfer', 'label' => 'Bank Transfer']]
 */
function paymentTypesList(?string $csv): array
{
    $csv = trim((string)$csv) !== '' ? (string)$csv : DEFAULT_PAYMENT_TYPES;
    $list = [];

    foreach (explode(',', $csv) as $type) {
        $type = trim($type);

        if (isset(PAYMENT_TYPES[$type])) {
            $list[] = ['value' => $type, 'label' => PAYMENT_TYPES[$type]];
        }
    }

    return $list;
}

/*
|--------------------------------------------------------------------------
| Bank account helpers
|--------------------------------------------------------------------------
| DEFAULT account  : table bank_details (id = 1), shared by all donations.
| PER-DONATION     : table donation_bank_details (one optional row per
|                    donation). When present it overrides the default.
|--------------------------------------------------------------------------
*/

/**
 * Shared (default) bank account details.
 */
function fetchBankDetails(PDO $pdo): array
{
    $fields = array_keys(BANK_FIELD_LIMITS);

    $row = $pdo->query(
        'SELECT ' . implode(', ', $fields) . ' FROM bank_details WHERE id = 1'
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    $out = [];

    foreach ($fields as $field) {
        $out[$field] = (string)($row[$field] ?? '');
    }

    $out['is_configured'] = $out['bank_name'] !== '' && $out['account_number'] !== '';

    return $out;
}

/**
 * Validate bank fields (used for the default account AND per-donation
 * accounts). Returns a clean array with every field as a trimmed string.
 */
function validateBankInput($src): array
{
    if (!is_array($src)) {
        $src = [];
    }

    $data = [];

    foreach (BANK_FIELD_LIMITS as $field => $max) {
        $value = trim((string)($src[$field] ?? ''));

        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException(
                ucfirst(str_replace('_', ' ', $field)) . " must not exceed {$max} characters"
            );
        }

        $data[$field] = $value;
    }

    if (
        $data['bank_name'] === '' ||
        $data['account_title'] === '' ||
        $data['account_number'] === ''
    ) {
        throw new InvalidArgumentException(
            'Bank name, account title and account number are required'
        );
    }

    if (!preg_match('/^[A-Za-z0-9\- ]{4,50}$/', $data['account_number'])) {
        throw new InvalidArgumentException('Account number format is invalid');
    }

    if ($data['iban'] !== '' && !preg_match('/^[A-Za-z0-9 ]{8,50}$/', $data['iban'])) {
        throw new InvalidArgumentException('IBAN format is invalid');
    }

    return $data;
}

/**
 * Insert or update the own bank account of one donation.
 */
function upsertDonationBank(PDO $pdo, int $donationId, array $d): void
{
    $nullable = function ($v) {
        return $v !== '' ? $v : null;
    };

    $stmt = $pdo->prepare(
        'INSERT INTO donation_bank_details
            (donation_id, bank_name, account_title, account_number, iban,
             branch_name, swift_code, instructions)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            bank_name = VALUES(bank_name),
            account_title = VALUES(account_title),
            account_number = VALUES(account_number),
            iban = VALUES(iban),
            branch_name = VALUES(branch_name),
            swift_code = VALUES(swift_code),
            instructions = VALUES(instructions)'
    );

    $stmt->execute([
        $donationId,
        $d['bank_name'],
        $d['account_title'],
        $d['account_number'],
        $nullable($d['iban']),
        $nullable($d['branch_name']),
        $nullable($d['swift_code']),
        $nullable($d['instructions'])
    ]);
}

/**
 * Remove the own bank account of a donation (it falls back to the default).
 */
function deleteDonationBank(PDO $pdo, int $donationId): void
{
    $pdo->prepare('DELETE FROM donation_bank_details WHERE donation_id = ?')
        ->execute([$donationId]);
}

/**
 * Adds these keys to every donation row (ONE query for the whole list):
 *   has_custom_bank : bool   - donation has its own account
 *   custom_bank     : array  - own account fields (empty strings if none)
 *   bank_details    : array  - EFFECTIVE account (own if set, else default)
 *   bank_source     : string - 'donation' or 'default'
 */
function attachBankDetails(PDO $pdo, array $rows): array
{
    $default = fetchBankDetails($pdo);
    $fields = array_keys(BANK_FIELD_LIMITS);

    $ids = [];

    foreach ($rows as $r) {
        if (!empty($r['donation_id'])) {
            $ids[] = (int)$r['donation_id'];
        }
    }

    $custom = [];

    if ($ids) {
        $ids = array_values(array_unique($ids));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $pdo->prepare(
            'SELECT donation_id, ' . implode(', ', $fields) .
            ' FROM donation_bank_details WHERE donation_id IN (' . $placeholders . ')'
        );
        $stmt->execute($ids);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $item = [];

            foreach ($fields as $f) {
                $item[$f] = (string)($c[$f] ?? '');
            }

            $item['is_configured'] = $item['bank_name'] !== '' && $item['account_number'] !== '';
            $custom[(int)$c['donation_id']] = $item;
        }
    }

    $emptyCustom = array_fill_keys($fields, '');
    $emptyCustom['is_configured'] = false;

    foreach ($rows as &$row) {
        $did = (int)($row['donation_id'] ?? 0);
        $has = $did > 0 && isset($custom[$did]);

        $row['has_custom_bank'] = $has;
        $row['custom_bank'] = $has ? $custom[$did] : $emptyCustom;
        $row['bank_details'] = $has ? $custom[$did] : $default;
        $row['bank_source'] = $has ? 'donation' : 'default';
    }

    unset($row);
    return $rows;
}

/**
 * Effective bank info for one donation id (used in add/update responses).
 */
function effectiveDonationBank(PDO $pdo, int $donationId): array
{
    return attachBankDetails($pdo, [['donation_id' => $donationId]])[0];
}

/**
 * Normalise donation rows (types + media URL + preset list + payment types).
 */
function normalizeDonationRows(array $rows, string $programBaseUrl): array
{
    foreach ($rows as &$row) {
        $row['program_image'] = publicMediaUrl(
            $row['program_image'] ?? null,
            $programBaseUrl
        );

        $row['preset_amounts'] = trim((string)($row['preset_amounts'] ?? '')) !== ''
            ? (string)$row['preset_amounts']
            : DEFAULT_PRESET_AMOUNTS;

        $row['preset_amounts_list'] = presetAmountsList($row['preset_amounts']);
        $row['allow_custom_amount'] = (bool)($row['allow_custom_amount'] ?? true);
        $row['min_custom_amount'] = (float)($row['min_custom_amount'] ?? 1);

        $row['payment_types'] = trim((string)($row['payment_types'] ?? '')) !== ''
            ? (string)$row['payment_types']
            : DEFAULT_PAYMENT_TYPES;

        $row['payment_types_list'] = paymentTypesList($row['payment_types']);
    }

    unset($row);
    return $rows;
}

/*
|--------------------------------------------------------------------------
| Routes
|--------------------------------------------------------------------------
*/

$response = ['status' => 'error', 'message' => 'Unknown action'];

try {
    switch ($action) {

        // ================================================================
        // PROGRAMS: LIST
        // ================================================================

        case 'get_programs':
            requireMethod('GET');

            $where = [];
            $params = [];

            $status = trim((string)($_GET['status'] ?? ''));

            if ($status !== '') {
                validateStatus($status, ['active', 'inactive']);
                $where[] = 'status = ?';
                $params[] = $status;
            }

            $categoryFilter = trim((string)($_GET['category'] ?? ''));

            if ($categoryFilter !== '') {
                $where[] = 'category = ?';
                $params[] = validateProgramCategory($categoryFilter, true);
            }

            $search = trim((string)($_GET['search'] ?? ''));

            if ($search !== '') {
                $where[] = '(title LIKE ? OR short_description LIKE ? OR description LIKE ?)';
                $term = '%' . $search . '%';
                array_push($params, $term, $term, $term);
            }

            $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

            $result = paginatedFetch(
                $pdo,
                'SELECT id, title, short_description, description, image,
                        category, status, is_featured, created_at, updated_at
                 FROM programs' . $whereSql . ' ORDER BY created_at DESC',
                'SELECT COUNT(*) FROM programs' . $whereSql,
                $params
            );

            $result['data'] = normalizeProgramRows(
                $result['data'],
                $programBaseUrl
            );

            $response = [
                'status' => 'success',
                'message' => 'Programs fetched successfully'
            ] + $result;
            break;

        // ================================================================
        // PROGRAMS: ACTIVE LIST (for dropdowns, e.g. gallery form)
        // ================================================================

        case 'get_active_programs':
            requireMethod('GET');

            $stmt = $pdo->prepare(
                "SELECT id, title, category
                 FROM programs
                 WHERE status = 'active'
                 ORDER BY title ASC"
            );
            $stmt->execute();

            $response = [
                'status' => 'success',
                'message' => 'Active programs fetched successfully',
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ];
            break;

        case 'get_program':
            requireMethod('GET');

            $id = validPositiveInt(inputValue('id'), 'program ID');

            $stmt = $pdo->prepare(
                'SELECT id, title, short_description, description, image,
                        category, status, is_featured, created_at, updated_at
                 FROM programs WHERE id = ?'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new RuntimeException('Program not found', 404);
            }

            $row['image'] = publicMediaUrl(
                $row['image'] ?? null,
                $programBaseUrl
            );
            $row['is_featured'] = (bool)($row['is_featured'] ?? false);

            $response = [
                'status' => 'success',
                'message' => 'Program fetched successfully',
                'data' => $row
            ];
            break;

        case 'save_program':
            requireMethod('POST');

            $input = requestInput();

            $title = trim((string)($input['title'] ?? ''));
            $shortDescription = trim((string)($input['short_description'] ?? ''));
            $description = trim((string)($input['description'] ?? ''));
            $status = validateStatus(
                $input['status'] ?? 'active',
                ['active', 'inactive']
            );

            if ($title === '' || $shortDescription === '' || $description === '') {
                throw new InvalidArgumentException(
                    'Title, Short Description and Description are required'
                );
            }

            $category = validateProgramCategory($input['category'] ?? null);

            $isFeatured = parseBool($input['is_featured'] ?? '0');

            if ($isFeatured && $status !== 'active') {
                throw new InvalidArgumentException(
                    'Only an active program can be the current program'
                );
            }

            $imageUrl = null;

            if (
                isset($_FILES['image']) &&
                (int)$_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE
            ) {
                $filename = uploadImage('image', $programUploadPath);
                $imageUrl = $programBaseUrl . $filename;
            } elseif (!empty($input['image'])) {
                $provided = trim((string)$input['image']);

                if (!filter_var($provided, FILTER_VALIDATE_URL)) {
                    throw new InvalidArgumentException('Image must be a valid URL');
                }

                $imageUrl = $provided;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO programs
                    (title, short_description, description, image, category, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
            );

            $stmt->execute([
                $title,
                $shortDescription,
                $description,
                $imageUrl,
                $category,
                $status
            ]);

            $newId = (int)$pdo->lastInsertId();

            if ($isFeatured) {
                setFeaturedProgram($pdo, $newId, true);
            }

            $response = [
                'status' => 'success',
                'message' => 'Program created successfully',
                'data' => [
                    'id' => $newId,
                    'image' => $imageUrl,
                    'category' => $category,
                    'is_featured' => $isFeatured
                ]
            ];

            http_response_code(201);
            break;

        case 'update_program':
            requireMethod('POST');

            $input = requestInput();

            $id = validPositiveInt($input['id'] ?? null, 'program ID');
            $title = trim((string)($input['title'] ?? ''));
            $shortDescription = trim((string)($input['short_description'] ?? ''));
            $description = trim((string)($input['description'] ?? ''));
            $status = validateStatus(
                $input['status'] ?? '',
                ['active', 'inactive']
            );

            if ($title === '' || $shortDescription === '' || $description === '') {
                throw new InvalidArgumentException(
                    'Title, Short Description and Description are required'
                );
            }

            $stmt = $pdo->prepare(
                'SELECT id, image, category, is_featured FROM programs WHERE id = ?'
            );
            $stmt->execute([$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                throw new RuntimeException('Program not found', 404);
            }

            // Category: keep the existing one when the field is not sent.
            $category = array_key_exists('category', $input)
                ? validateProgramCategory($input['category'])
                : ($existing['category'] ?? null);

            // Featured flag: only changed when the field is sent.
            $featuredSent = array_key_exists('is_featured', $input);
            $isFeatured = $featuredSent
                ? parseBool($input['is_featured'])
                : (bool)($existing['is_featured'] ?? false);

            if ($featuredSent && $isFeatured && $status !== 'active') {
                throw new InvalidArgumentException(
                    'Only an active program can be the current program'
                );
            }

            $imageUrl = publicMediaUrl(
                $existing['image'] ?? null,
                $programBaseUrl
            );

            if (
                isset($_FILES['image']) &&
                (int)$_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE
            ) {
                $filename = uploadImage('image', $programUploadPath);
                $imageUrl = $programBaseUrl . $filename;
            } elseif (array_key_exists('image', $input)) {
                $provided = trim((string)$input['image']);

                if ($provided === '') {
                    $imageUrl = null;
                } elseif (filter_var($provided, FILTER_VALIDATE_URL)) {
                    $imageUrl = $provided;
                } else {
                    throw new InvalidArgumentException('Image must be a valid URL');
                }
            }

            $stmt = $pdo->prepare(
                'UPDATE programs
                 SET title = ?, short_description = ?, description = ?,
                     image = ?, category = ?, status = ?, updated_at = NOW()
                 WHERE id = ?'
            );

            $stmt->execute([
                $title,
                $shortDescription,
                $description,
                $imageUrl,
                $category,
                $status,
                $id
            ]);

            if ($status !== 'active') {
                // An inactive program cannot stay the current program.
                setFeaturedProgram($pdo, $id, false);
                $isFeatured = false;
            } elseif ($featuredSent) {
                setFeaturedProgram($pdo, $id, $isFeatured);
            }

            $response = [
                'status' => 'success',
                'message' => 'Program updated successfully',
                'data' => [
                    'id' => $id,
                    'image' => $imageUrl,
                    'category' => $category,
                    'is_featured' => $isFeatured
                ]
            ];
            break;

        case 'delete_program':
            requireMethod('POST');

            $id = validPositiveInt(inputValue('id'), 'program ID');

            $stmt = $pdo->prepare('SELECT id FROM programs WHERE id = ?');
            $stmt->execute([$id]);

            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('Program not found', 404);
            }

            $stmt = $pdo->prepare('DELETE FROM programs WHERE id = ?');
            $stmt->execute([$id]);

            $response = [
                'status' => 'success',
                'message' => 'Program deleted successfully',
                'data' => ['id' => $id]
            ];
            break;

        // ================================================================
        // GALLERY: LIST (JOIN programs)
        // ================================================================

        case 'get_gallery':
            requireMethod('GET');

            $where = [];
            $params = [];

            $status = trim((string)($_GET['status'] ?? 'active'));

            if ($status === '') {
                $status = 'active';
            }

            if ($status !== 'all') {
                validateStatus($status, ['active', 'inactive']);
                $where[] = 'g.status = ?';
                $params[] = $status;
            }

            $mediaType = trim((string)($_GET['media_type'] ?? ''));

            if ($mediaType !== '') {
                $where[] = 'g.media_type = ?';
                $params[] = validateMediaType($mediaType);
            }

            $programFilter = trim((string)($_GET['program_id'] ?? ''));

            if ($programFilter !== '') {
                $where[] = 'g.program_id = ?';
                $params[] = validPositiveInt($programFilter, 'program ID');
            }

            $search = trim((string)($_GET['search'] ?? ''));

            if ($search !== '') {
                $where[] = '(g.title LIKE ? OR g.description LIKE ? OR p.title LIKE ?)';
                $term = '%' . $search . '%';
                array_push($params, $term, $term, $term);
            }

            $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

            $result = paginatedFetch(
                $pdo,
                'SELECT g.id, g.program_id, p.title AS program_title,
                        g.title, g.description, g.media_type, g.media_url,
                        g.status, g.created_at, g.updated_at
                 FROM gallery g
                 LEFT JOIN programs p ON p.id = g.program_id'
                 . $whereSql . ' ORDER BY g.created_at DESC',
                'SELECT COUNT(*)
                 FROM gallery g
                 LEFT JOIN programs p ON p.id = g.program_id' . $whereSql,
                $params
            );

            $result['data'] = normalizeGalleryRows(
                $result['data'],
                $galleryBaseUrl
            );

            $response = [
                'status' => 'success',
                'message' => 'Gallery fetched successfully'
            ] + $result;
            break;

        case 'get_gallery_item':
            requireMethod('GET');

            $id = validPositiveInt(inputValue('id'), 'gallery ID');

            $stmt = $pdo->prepare(
                'SELECT g.id, g.program_id, p.title AS program_title,
                        g.title, g.description, g.media_type, g.media_url,
                        g.status, g.created_at, g.updated_at
                 FROM gallery g
                 LEFT JOIN programs p ON p.id = g.program_id
                 WHERE g.id = ?'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new RuntimeException('Gallery item not found', 404);
            }

            $rows = normalizeGalleryRows([$row], $galleryBaseUrl);

            $response = [
                'status' => 'success',
                'message' => 'Gallery item fetched successfully',
                'data' => $rows[0]
            ];
            break;

        case 'save_gallery':
            requireMethod('POST');

            $input = requestInput();

            $title = trim((string)($input['title'] ?? ''));
            $description = trim((string)($input['description'] ?? ''));
            $mediaType = validateMediaType($input['media_type'] ?? '');
            $status = validateStatus(
                $input['status'] ?? 'active',
                ['active', 'inactive']
            );

            if ($title === '') {
                throw new InvalidArgumentException('Title is required');
            }

            $programId = resolveProgramId(
                $pdo,
                $input['program_id'] ?? null,
                null,
                true
            );

            if ($mediaType === 'image') {
                $filename = uploadImage('media_file', $galleryUploadPath);
                $mediaUrl = $galleryBaseUrl . $filename;
            } else {
                $mediaUrl = validateYoutubeUrl(
                    trim((string)($input['media_url'] ?? ''))
                );
            }

            $stmt = $pdo->prepare(
                'INSERT INTO gallery
                    (program_id, title, description, media_type, media_url, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
            );

            $stmt->execute([
                $programId,
                $title,
                $description,
                $mediaType,
                $mediaUrl,
                $status
            ]);

            $response = [
                'status' => 'success',
                'message' => 'Gallery item created successfully',
                'data' => [
                    'id' => (int)$pdo->lastInsertId(),
                    'program_id' => $programId,
                    'media_url' => $mediaUrl
                ]
            ];

            http_response_code(201);
            break;

        case 'update_gallery':
            requireMethod('POST');

            $input = requestInput();

            $id = validPositiveInt($input['id'] ?? null, 'gallery ID');
            $title = trim((string)($input['title'] ?? ''));
            $description = trim((string)($input['description'] ?? ''));
            $mediaType = validateMediaType($input['media_type'] ?? '');
            $status = validateStatus(
                $input['status'] ?? '',
                ['active', 'inactive']
            );

            if ($title === '') {
                throw new InvalidArgumentException('Title is required');
            }

            $stmt = $pdo->prepare(
                'SELECT id, program_id, media_type, media_url FROM gallery WHERE id = ?'
            );
            $stmt->execute([$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                throw new RuntimeException('Gallery item not found', 404);
            }

            $currentProgramId = $existing['program_id'] !== null
                ? (int)$existing['program_id']
                : null;

            // Old items without a program may stay without one;
            // every other item must have a program.
            $programId = resolveProgramId(
                $pdo,
                $input['program_id'] ?? null,
                $currentProgramId,
                $currentProgramId !== null
            );

            if ($mediaType === 'image') {
                $hasNewFile = isset($_FILES['media_file'])
                    && (int)$_FILES['media_file']['error'] !== UPLOAD_ERR_NO_FILE;

                if ($hasNewFile) {
                    $filename = uploadImage('media_file', $galleryUploadPath);
                    $mediaUrl = $galleryBaseUrl . $filename;
                } elseif (strtolower((string)$existing['media_type']) === 'image') {
                    $mediaUrl = publicMediaUrl(
                        $existing['media_url'],
                        $galleryBaseUrl
                    );
                } else {
                    throw new InvalidArgumentException(
                        'Please upload an image when changing media type to image'
                    );
                }
            } else {
                $youtubeInput = trim((string)($input['media_url'] ?? ''));

                if ($youtubeInput === '' && isVideoType($existing['media_type'])) {
                    $youtubeInput = (string)$existing['media_url'];
                }

                $mediaUrl = validateYoutubeUrl($youtubeInput);
            }

            $stmt = $pdo->prepare(
                'UPDATE gallery
                 SET program_id = ?, title = ?, description = ?, media_type = ?,
                     media_url = ?, status = ?, updated_at = NOW()
                 WHERE id = ?'
            );

            $stmt->execute([
                $programId,
                $title,
                $description,
                $mediaType,
                $mediaUrl,
                $status,
                $id
            ]);

            $response = [
                'status' => 'success',
                'message' => 'Gallery item updated successfully',
                'data' => [
                    'id' => $id,
                    'program_id' => $programId,
                    'media_url' => $mediaUrl
                ]
            ];
            break;

        case 'delete_gallery':
            requireMethod('POST');

            $id = validPositiveInt(inputValue('id'), 'gallery ID');

            $stmt = $pdo->prepare('SELECT id FROM gallery WHERE id = ?');
            $stmt->execute([$id]);

            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('Gallery item not found', 404);
            }

            $stmt = $pdo->prepare('DELETE FROM gallery WHERE id = ?');
            $stmt->execute([$id]);

            $response = [
                'status' => 'success',
                'message' => 'Gallery item deleted successfully',
                'data' => ['id' => $id]
            ];
            break;

        // ================================================================
        // PARTNERS / VENDORS
        // ================================================================

        case 'get_partners':
            requireMethod('GET');

            $where = [];
            $params = [];

            $status = trim((string)($_GET['status'] ?? 'active'));

            if ($status === '') {
                $status = 'active';
            }

            if ($status !== 'all') {
                validateStatus($status, ['active', 'inactive']);
                $where[] = 'status = ?';
                $params[] = $status;
            }

            $search = trim((string)($_GET['search'] ?? ''));

            if ($search !== '') {
                $where[] = '(name LIKE ? OR website_url LIKE ?)';
                $term = '%' . $search . '%';
                array_push($params, $term, $term);
            }

            $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

            $result = paginatedFetch(
                $pdo,
                'SELECT id, name, logo, website_url, display_order,
                        status, created_at, updated_at
                 FROM partners' . $whereSql . ' ORDER BY display_order ASC, id ASC',
                'SELECT COUNT(*) FROM partners' . $whereSql,
                $params
            );

            $result['data'] = normalizePartnerRows($result['data'], $partnerBaseUrl);

            $response = [
                'status' => 'success',
                'message' => 'Partners fetched successfully'
            ] + $result;
            break;

        case 'get_partner':
            requireMethod('GET');

            $id = validPositiveInt(inputValue('id'), 'partner ID');

            $stmt = $pdo->prepare(
                'SELECT id, name, logo, website_url, display_order,
                        status, created_at, updated_at
                 FROM partners WHERE id = ?'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new RuntimeException('Partner not found', 404);
            }

            $rows = normalizePartnerRows([$row], $partnerBaseUrl);

            $response = [
                'status' => 'success',
                'message' => 'Partner fetched successfully',
                'data' => $rows[0]
            ];
            break;

        case 'save_partner':
            requireMethod('POST');

            $input = requestInput();

            $name = trim((string)($input['name'] ?? ''));

            if ($name === '') {
                throw new InvalidArgumentException('Partner name is required');
            }

            if (mb_strlen($name) > 255) {
                throw new InvalidArgumentException('Partner name must not exceed 255 characters');
            }

            $website = normalizePartnerWebsite($input['website_url'] ?? null);
            $status = validateStatus(
                $input['status'] ?? 'active',
                ['active', 'inactive']
            );
            $order = partnerDisplayOrder($pdo, $input['display_order'] ?? null);

            $filename = uploadPartnerLogo('logo', $partnerUploadPath);
            $logoUrl = $partnerBaseUrl . $filename;

            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO partners
                        (name, logo, website_url, display_order, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
                );

                $stmt->execute([$name, $logoUrl, $website, $order, $status]);
            } catch (Throwable $insertError) {
                deletePartnerLogoFile($logoUrl, $partnerUploadPath);
                throw $insertError;
            }

            $response = [
                'status' => 'success',
                'message' => 'Partner created successfully',
                'data' => [
                    'id' => (int)$pdo->lastInsertId(),
                    'logo' => $logoUrl,
                    'website_url' => $website,
                    'display_order' => $order
                ]
            ];

            http_response_code(201);
            break;

        case 'update_partner':
            requireMethod('POST');

            $input = requestInput();

            $id = validPositiveInt($input['id'] ?? null, 'partner ID');

            $stmt = $pdo->prepare('SELECT * FROM partners WHERE id = ?');
            $stmt->execute([$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                throw new RuntimeException('Partner not found', 404);
            }

            $name = trim((string)($input['name'] ?? ''));

            if ($name === '') {
                throw new InvalidArgumentException('Partner name is required');
            }

            if (mb_strlen($name) > 255) {
                throw new InvalidArgumentException('Partner name must not exceed 255 characters');
            }

            $website = array_key_exists('website_url', $input)
                ? normalizePartnerWebsite($input['website_url'])
                : ($existing['website_url'] ?? null);

            $status = validateStatus(
                $input['status'] ?? $existing['status'],
                ['active', 'inactive']
            );

            $order = partnerDisplayOrder(
                $pdo,
                $input['display_order'] ?? null,
                (int)$existing['display_order']
            );

            $logoUrl = publicMediaUrl($existing['logo'] ?? null, $partnerBaseUrl);
            $newLogoUrl = null;

            if (
                isset($_FILES['logo']) &&
                (int)$_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE
            ) {
                $filename = uploadPartnerLogo('logo', $partnerUploadPath);
                $newLogoUrl = $partnerBaseUrl . $filename;
                $logoUrl = $newLogoUrl;
            }

            try {
                $stmt = $pdo->prepare(
                    'UPDATE partners
                     SET name = ?, logo = ?, website_url = ?, display_order = ?,
                         status = ?, updated_at = NOW()
                     WHERE id = ?'
                );

                $stmt->execute([$name, $logoUrl, $website, $order, $status, $id]);
            } catch (Throwable $updateError) {
                deletePartnerLogoFile($newLogoUrl, $partnerUploadPath);
                throw $updateError;
            }

            // The old logo file is no longer needed once a new one is saved.
            if ($newLogoUrl !== null) {
                deletePartnerLogoFile($existing['logo'] ?? null, $partnerUploadPath);
            }

            $response = [
                'status' => 'success',
                'message' => 'Partner updated successfully',
                'data' => [
                    'id' => $id,
                    'logo' => $logoUrl,
                    'website_url' => $website,
                    'display_order' => $order
                ]
            ];
            break;

        case 'delete_partner':
            requireMethod('POST');

            $id = validPositiveInt(inputValue('id'), 'partner ID');

            $stmt = $pdo->prepare('SELECT id, logo FROM partners WHERE id = ?');
            $stmt->execute([$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                throw new RuntimeException('Partner not found', 404);
            }

            $stmt = $pdo->prepare('DELETE FROM partners WHERE id = ?');
            $stmt->execute([$id]);

            deletePartnerLogoFile($existing['logo'] ?? null, $partnerUploadPath);

            $response = [
                'status' => 'success',
                'message' => 'Partner deleted successfully',
                'data' => ['id' => $id]
            ];
            break;

        // ================================================================
        // CONTACT ENQUIRIES
        // ================================================================

        case 'get_contact_enquiries':
            requireMethod('GET');

            $where = [];
            $params = [];

            $status = trim((string)($_GET['status'] ?? ''));

            if ($status !== '') {
                validateStatus(
                    $status,
                    ['new', 'read', 'replied'],
                    'enquiry status'
                );

                $where[] = 'status = ?';
                $params[] = $status;
            }

            $search = trim((string)($_GET['search'] ?? ''));

            if ($search !== '') {
                $where[] = '(name LIKE ? OR email LIKE ? OR phone LIKE ? OR subject LIKE ? OR message LIKE ?)';
                $term = '%' . $search . '%';
                array_push($params, $term, $term, $term, $term, $term);
            }

            $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

            $result = paginatedFetch(
                $pdo,
                'SELECT id, name, email, phone, subject, message, status, created_at
                 FROM contact_enquiries' . $whereSql . ' ORDER BY created_at DESC',
                'SELECT COUNT(*) FROM contact_enquiries' . $whereSql,
                $params
            );

            $response = [
                'status' => 'success',
                'message' => 'Contact enquiries fetched successfully'
            ] + $result;
            break;

        case 'delete_contact_enquiry':
            requireMethod('POST');

            $id = validPositiveInt(inputValue('id'), 'enquiry ID');

            $stmt = $pdo->prepare('DELETE FROM contact_enquiries WHERE id = ?');
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Contact enquiry not found', 404);
            }

            $response = [
                'status' => 'success',
                'message' => 'Contact enquiry deleted successfully',
                'data' => ['id' => $id]
            ];
            break;

        case 'update_contact_enquiry_status':
            requireMethod('POST');

            $id = validPositiveInt(inputValue('id'), 'enquiry ID');

            $status = validateStatus(
                inputValue('status', ''),
                ['new', 'read', 'replied'],
                'enquiry status'
            );

            $stmt = $pdo->prepare('SELECT id FROM contact_enquiries WHERE id = ?');
            $stmt->execute([$id]);

            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('Contact enquiry not found', 404);
            }

            $stmt = $pdo->prepare(
                'UPDATE contact_enquiries SET status = ? WHERE id = ?'
            );
            $stmt->execute([$status, $id]);

            $response = [
                'status' => 'success',
                'message' => 'Contact enquiry status updated successfully',
                'data' => ['id' => $id, 'status' => $status]
            ];
            break;

        // ================================================================
        // DONATIONS: PUBLIC LIST
        // ================================================================
        // Every row contains "bank_details" = the EFFECTIVE bank account
        // (the donation's own account, or the default one).
        // The top-level "bank_details" is always the default account.

        case 'get_donations':
            requireMethod('GET');

            $sql = "
                SELECT
                    p.id AS program_id,
                    p.title AS program_title,
                    p.short_description AS program_description,
                    p.description AS program_full_description,
                    p.image AS program_image,
                    p.category AS program_category,
                    p.status AS program_status,
                    d.id AS donation_id,
                    d.donation_heading,
                    COALESCE(d.total_donations, 0) AS total_donations,
                    COALESCE(d.raise_donations, 0) AS raise_donations,
                    COALESCE(d.preset_amounts, '" . DEFAULT_PRESET_AMOUNTS . "') AS preset_amounts,
                    COALESCE(d.allow_custom_amount, 1) AS allow_custom_amount,
                    COALESCE(d.min_custom_amount, 1) AS min_custom_amount,
                    COALESCE(d.payment_types, '" . DEFAULT_PAYMENT_TYPES . "') AS payment_types,
                    CASE
                        WHEN COALESCE(d.total_donations, 0) > 0
                        THEN ROUND(COALESCE(d.raise_donations, 0) / d.total_donations * 100, 2)
                        ELSE 0
                    END AS percent_donations
                FROM programs p
                LEFT JOIN donations d ON p.id = d.program_id
                WHERE p.status = 'active'
                ORDER BY p.created_at DESC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $rows = attachBankDetails($pdo, normalizeDonationRows(
                $stmt->fetchAll(PDO::FETCH_ASSOC),
                $programBaseUrl
            ));

            $response = [
                'status' => 'success',
                'message' => 'Programs and donation details fetched successfully',
                'data' => $rows,
                'bank_details' => fetchBankDetails($pdo)
            ];
            break;

        // ================================================================
        // DONATIONS: ADMIN LIST
        // ================================================================

        case 'admin_get_donations':
            requireMethod('GET');

            $sql = "
                SELECT
                    p.id AS program_id,
                    p.title AS program_title,
                    p.short_description AS program_description,
                    p.image AS program_image,
                    p.category AS program_category,
                    p.status AS program_status,
                    d.id AS donation_id,
                    d.donation_heading,
                    COALESCE(d.total_donations, 0) AS total_donations,
                    COALESCE(d.raise_donations, 0) AS raise_donations,
                    COALESCE(d.preset_amounts, '" . DEFAULT_PRESET_AMOUNTS . "') AS preset_amounts,
                    COALESCE(d.allow_custom_amount, 1) AS allow_custom_amount,
                    COALESCE(d.min_custom_amount, 1) AS min_custom_amount,
                    COALESCE(d.payment_types, '" . DEFAULT_PAYMENT_TYPES . "') AS payment_types,
                    CASE
                        WHEN COALESCE(d.total_donations, 0) > 0
                        THEN ROUND(COALESCE(d.raise_donations, 0) / d.total_donations * 100, 2)
                        ELSE 0
                    END AS percent_donations,
                    d.created_at AS donation_created_at
                FROM programs p
                LEFT JOIN donations d ON p.id = d.program_id
                ORDER BY p.created_at DESC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $rows = attachBankDetails($pdo, normalizeDonationRows(
                $stmt->fetchAll(PDO::FETCH_ASSOC),
                $programBaseUrl
            ));

            $response = [
                'status' => 'success',
                'message' => 'Admin donation details fetched successfully',
                'data' => $rows,
                'bank_details' => fetchBankDetails($pdo)
            ];
            break;

        // ================================================================
        // DONATIONS: ADD
        // ================================================================
        // Optional per-donation bank account:
        //   use_custom_bank = 1
        //   custom_bank[bank_name], custom_bank[account_title],
        //   custom_bank[account_number], custom_bank[iban], ...

        case 'add_donation':
            requireMethod('POST');

            $input = requestInput();
            $programId = validPositiveInt(
                $input['program_id'] ?? null,
                'program ID'
            );

            $heading = trim((string)($input['donation_heading'] ?? ''));

            if ($heading === '') {
                throw new InvalidArgumentException('Donation heading is required');
            }

            [$total, $raised, $percentage] = donationAmounts(
                $input['total_donations'] ?? null,
                $input['raise_donations'] ?? null
            );

            $presets = donationPresetAmounts($input['preset_amounts'] ?? '');

            [$allowCustom, $minCustom] = donationCustomSettings(
                array_key_exists('allow_custom_amount', $input)
                    ? $input['allow_custom_amount']
                    : '1',
                $input['min_custom_amount'] ?? null
            );

            $paymentTypes = donationPaymentTypes($input['payment_types'] ?? null);

            $useCustomBank = parseBool($input['use_custom_bank'] ?? '0');
            $customBank = $useCustomBank
                ? validateBankInput($input['custom_bank'] ?? [])
                : null;

            $stmt = $pdo->prepare('SELECT id FROM programs WHERE id = ?');
            $stmt->execute([$programId]);

            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('Program not found', 404);
            }

            $stmt = $pdo->prepare(
                'SELECT id FROM donations WHERE program_id = ? LIMIT 1'
            );
            $stmt->execute([$programId]);

            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException(
                    'This program already has a donation record. Use update_donation instead.',
                    409
                );
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'INSERT INTO donations
                    (program_id, donation_heading, total_donations, raise_donations,
                     percent_donations, preset_amounts, allow_custom_amount,
                     min_custom_amount, payment_types)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $programId,
                $heading,
                $total,
                $raised,
                $percentage,
                $presets,
                $allowCustom,
                $minCustom,
                $paymentTypes
            ]);

            $newDonationId = (int)$pdo->lastInsertId();

            if ($customBank !== null) {
                upsertDonationBank($pdo, $newDonationId, $customBank);
            }

            $pdo->commit();

            $response = [
                'status' => 'success',
                'message' => 'Donation details added successfully',
                'data' => [
                    'donation_id' => $newDonationId,
                    'program_id' => $programId,
                    'donation_heading' => $heading,
                    'total_donations' => $total,
                    'raise_donations' => $raised,
                    'percent_donations' => $percentage,
                    'preset_amounts' => $presets,
                    'preset_amounts_list' => presetAmountsList($presets),
                    'allow_custom_amount' => (bool)$allowCustom,
                    'min_custom_amount' => $minCustom,
                    'payment_types' => $paymentTypes,
                    'payment_types_list' => paymentTypesList($paymentTypes),
                    'has_custom_bank' => $customBank !== null,
                    'bank_details' => effectiveDonationBank($pdo, $newDonationId)['bank_details']
                ]
            ];

            http_response_code(201);
            break;

        // ================================================================
        // DONATIONS: UPDATE
        // ================================================================
        // use_custom_bank = 1  => save/replace the donation's own account
        // use_custom_bank = 0  => remove own account (back to default)
        // field not sent       => bank settings stay unchanged

        case 'update_donation':
            requireMethod('POST');

            $input = requestInput();

            $donationId = validPositiveInt(
                $input['donation_id'] ?? null,
                'donation ID'
            );

            $programId = validPositiveInt(
                $input['program_id'] ?? null,
                'program ID'
            );

            $heading = trim((string)($input['donation_heading'] ?? ''));

            if ($heading === '') {
                throw new InvalidArgumentException('Donation heading is required');
            }

            [$total, $raised, $percentage] = donationAmounts(
                $input['total_donations'] ?? null,
                $input['raise_donations'] ?? null
            );

            $presets = donationPresetAmounts($input['preset_amounts'] ?? '');

            [$allowCustom, $minCustom] = donationCustomSettings(
                array_key_exists('allow_custom_amount', $input)
                    ? $input['allow_custom_amount']
                    : '1',
                $input['min_custom_amount'] ?? null
            );

            $paymentTypes = donationPaymentTypes($input['payment_types'] ?? null);

            $bankSent = array_key_exists('use_custom_bank', $input);
            $useCustomBank = $bankSent && parseBool($input['use_custom_bank']);
            $customBank = $useCustomBank
                ? validateBankInput($input['custom_bank'] ?? [])
                : null;

            $stmt = $pdo->prepare('SELECT id FROM donations WHERE id = ?');
            $stmt->execute([$donationId]);

            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('Donation record not found', 404);
            }

            $stmt = $pdo->prepare('SELECT id FROM programs WHERE id = ?');
            $stmt->execute([$programId]);

            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('Program not found', 404);
            }

            $stmt = $pdo->prepare(
                'SELECT id FROM donations WHERE program_id = ? AND id != ? LIMIT 1'
            );
            $stmt->execute([$programId, $donationId]);

            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException(
                    'This program already has another donation record',
                    409
                );
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'UPDATE donations
                 SET program_id = ?, donation_heading = ?, total_donations = ?,
                     raise_donations = ?, percent_donations = ?, preset_amounts = ?,
                     allow_custom_amount = ?, min_custom_amount = ?, payment_types = ?
                 WHERE id = ?'
            );

            $stmt->execute([
                $programId,
                $heading,
                $total,
                $raised,
                $percentage,
                $presets,
                $allowCustom,
                $minCustom,
                $paymentTypes,
                $donationId
            ]);

            if ($bankSent) {
                if ($customBank !== null) {
                    upsertDonationBank($pdo, $donationId, $customBank);
                } else {
                    // Back to the default bank account.
                    deleteDonationBank($pdo, $donationId);
                }
            }

            $pdo->commit();

            $effective = effectiveDonationBank($pdo, $donationId);

            $response = [
                'status' => 'success',
                'message' => 'Donation details updated successfully',
                'data' => [
                    'donation_id' => $donationId,
                    'program_id' => $programId,
                    'donation_heading' => $heading,
                    'total_donations' => $total,
                    'raise_donations' => $raised,
                    'percent_donations' => $percentage,
                    'preset_amounts' => $presets,
                    'preset_amounts_list' => presetAmountsList($presets),
                    'allow_custom_amount' => (bool)$allowCustom,
                    'min_custom_amount' => $minCustom,
                    'payment_types' => $paymentTypes,
                    'payment_types_list' => paymentTypesList($paymentTypes),
                    'has_custom_bank' => $effective['has_custom_bank'],
                    'bank_details' => $effective['bank_details']
                ]
            ];
            break;

        // ================================================================
        // DONATIONS: DELETE
        // ================================================================

        case 'delete_donation':
            requireMethod('POST');

            $donationId = validPositiveInt(
                inputValue('donation_id'),
                'donation ID'
            );

            $pdo->beginTransaction();

            // Remove the donation's own bank account first (if any).
            deleteDonationBank($pdo, $donationId);

            $stmt = $pdo->prepare('DELETE FROM donations WHERE id = ?');
            $stmt->execute([$donationId]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Donation record not found', 404);
            }

            $pdo->commit();

            $response = [
                'status' => 'success',
                'message' => 'Donation details deleted successfully',
                'data' => ['donation_id' => $donationId]
            ];
            break;

        // ================================================================
        // DONATIONS: SINGLE RECORD
        // ================================================================
        // Usage:
        //   ?action=get_donation&program_id=5     (by program)
        //   ?action=get_donation&donation_id=12   (by donation record)

        case 'get_donation':
            requireMethod('GET');

            $donationIdParam = trim((string)($_GET['donation_id'] ?? ''));
            $programIdParam = trim((string)($_GET['program_id'] ?? ''));

            if ($donationIdParam === '' && $programIdParam === '') {
                throw new InvalidArgumentException(
                    'Provide donation_id or program_id'
                );
            }

            if ($donationIdParam !== '') {
                $lookupSql = 'd.id = ?';
                $lookupValue = validPositiveInt($donationIdParam, 'donation ID');
            } else {
                $lookupSql = 'p.id = ?';
                $lookupValue = validPositiveInt($programIdParam, 'program ID');
            }

            $stmt = $pdo->prepare("
                SELECT
                    p.id AS program_id,
                    p.title AS program_title,
                    p.short_description AS program_description,
                    p.description AS program_full_description,
                    p.image AS program_image,
                    p.category AS program_category,
                    p.status AS program_status,
                    d.id AS donation_id,
                    d.donation_heading,
                    COALESCE(d.total_donations, 0) AS total_donations,
                    COALESCE(d.raise_donations, 0) AS raise_donations,
                    COALESCE(d.preset_amounts, '" . DEFAULT_PRESET_AMOUNTS . "') AS preset_amounts,
                    COALESCE(d.allow_custom_amount, 1) AS allow_custom_amount,
                    COALESCE(d.min_custom_amount, 1) AS min_custom_amount,
                    COALESCE(d.payment_types, '" . DEFAULT_PAYMENT_TYPES . "') AS payment_types,
                    CASE
                        WHEN COALESCE(d.total_donations, 0) > 0
                        THEN ROUND(COALESCE(d.raise_donations, 0) / d.total_donations * 100, 2)
                        ELSE 0
                    END AS percent_donations,
                    d.created_at AS donation_created_at
                FROM programs p
                LEFT JOIN donations d ON d.program_id = p.id
                WHERE {$lookupSql}
                LIMIT 1
            ");
            $stmt->execute([$lookupValue]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new RuntimeException('Donation record not found', 404);
            }

            $rows = attachBankDetails(
                $pdo,
                normalizeDonationRows([$row], $programBaseUrl)
            );

            $response = [
                'status' => 'success',
                'message' => 'Donation fetched successfully',
                'data' => $rows[0],
                'bank_details' => fetchBankDetails($pdo)
            ];
            break;

        // ================================================================
        // HERO: CURRENT PROGRAM + DONATION INFO + BANK DETAILS
        // ================================================================
        // Returns the program marked as "current" (is_featured = 1).
        // If none is marked, the latest active program is returned so the
        // hero section is never empty. To disable this fallback, change the
        // WHERE clause to:  p.status = 'active' AND p.is_featured = 1

        case 'get_featured_program':
            requireMethod('GET');

            $stmt = $pdo->prepare("
                SELECT
                    p.id AS program_id,
                    p.title AS program_title,
                    p.short_description AS program_description,
                    p.description AS program_full_description,
                    p.image AS program_image,
                    p.category AS program_category,
                    p.status AS program_status,
                    p.is_featured,
                    d.id AS donation_id,
                    d.donation_heading,
                    COALESCE(d.total_donations, 0) AS total_donations,
                    COALESCE(d.raise_donations, 0) AS raise_donations,
                    COALESCE(d.preset_amounts, '" . DEFAULT_PRESET_AMOUNTS . "') AS preset_amounts,
                    COALESCE(d.allow_custom_amount, 1) AS allow_custom_amount,
                    COALESCE(d.min_custom_amount, 1) AS min_custom_amount,
                    COALESCE(d.payment_types, '" . DEFAULT_PAYMENT_TYPES . "') AS payment_types,
                    CASE
                        WHEN COALESCE(d.total_donations, 0) > 0
                        THEN ROUND(COALESCE(d.raise_donations, 0) / d.total_donations * 100, 2)
                        ELSE 0
                    END AS percent_donations
                FROM programs p
                LEFT JOIN donations d ON d.program_id = p.id
                WHERE p.status = 'active'
                ORDER BY p.is_featured DESC, p.created_at DESC
                LIMIT 1
            ");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $data = null;

            if ($row) {
                $rows = attachBankDetails(
                    $pdo,
                    normalizeDonationRows([$row], $programBaseUrl)
                );
                $data = $rows[0];
                $data['is_featured'] = (bool)$data['is_featured'];
            }

            $response = [
                'status' => 'success',
                'message' => 'Current program fetched successfully',
                'data' => $data,
                'bank_details' => fetchBankDetails($pdo)
            ];
            break;

        // ================================================================
        // BANK DETAILS (DEFAULT ACCOUNT)
        // ================================================================

        case 'get_bank_details':
            requireMethod('GET');

            $response = [
                'status' => 'success',
                'message' => 'Bank details fetched successfully',
                'data' => fetchBankDetails($pdo)
            ];
            break;

        case 'save_bank_details':
            requireMethod('POST');

            $data = validateBankInput(requestInput());

            $nullable = function ($v) {
                return $v !== '' ? $v : null;
            };

            $stmt = $pdo->prepare(
                'INSERT INTO bank_details
                    (id, bank_name, account_title, account_number, iban,
                     branch_name, swift_code, instructions)
                 VALUES (1, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    bank_name = VALUES(bank_name),
                    account_title = VALUES(account_title),
                    account_number = VALUES(account_number),
                    iban = VALUES(iban),
                    branch_name = VALUES(branch_name),
                    swift_code = VALUES(swift_code),
                    instructions = VALUES(instructions)'
            );

            $stmt->execute([
                $data['bank_name'],
                $data['account_title'],
                $data['account_number'],
                $nullable($data['iban']),
                $nullable($data['branch_name']),
                $nullable($data['swift_code']),
                $nullable($data['instructions'])
            ]);

            $response = [
                'status' => 'success',
                'message' => 'Bank details saved successfully',
                'data' => fetchBankDetails($pdo)
            ];
            break;

        // ================================================================
        // NEWSLETTER: PUBLIC SUBSCRIBE (no token required)
        // ================================================================

        case 'subscribe_newsletter':
            requireMethod('POST');

            $input = requestInput();

            $email = strtolower(trim((string)($input['email'] ?? '')));
            $name = trim((string)($input['name'] ?? ''));

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException(
                    'Please provide a valid email address'
                );
            }

            if (strlen($email) > 255) {
                throw new InvalidArgumentException(
                    'Email must not exceed 255 characters'
                );
            }

            if (mb_strlen($name) > 150) {
                throw new InvalidArgumentException(
                    'Name must not exceed 150 characters'
                );
            }

            $source = trim((string)($input['source'] ?? 'website'));

            if ($source === '') {
                $source = 'website';
            }

            // Keep the source value within the database column limit.
            $source = mb_substr($source, 0, 100);

            // Use the web server's direct remote address.
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

            $stmt = $pdo->prepare(
                'SELECT id, status
                 FROM newsletter_subscribers
                 WHERE email = ?
                 LIMIT 1'
            );
            $stmt->execute([$email]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $subscriberId = (int)$existing['id'];

                if ($existing['status'] === 'subscribed') {
                    $response = [
                        'status' => 'success',
                        'message' => 'This email is already subscribed',
                        'data' => [
                            'id' => $subscriberId,
                            'email' => $email
                        ]
                    ];

                    break;
                }

                // Reactivate an existing unsubscribed email.
                $stmt = $pdo->prepare(
                    "UPDATE newsletter_subscribers
                     SET name = ?,
                         status = 'subscribed',
                         source = ?,
                         ip_address = ?,
                         subscribed_at = NOW(),
                         unsubscribed_at = NULL
                     WHERE id = ?"
                );

                $stmt->execute([
                    $name !== '' ? $name : null,
                    $source,
                    $ipAddress,
                    $subscriberId
                ]);

                $response = [
                    'status' => 'success',
                    'message' => 'You have successfully subscribed',
                    'data' => [
                        'id' => $subscriberId,
                        'email' => $email,
                        'status' => 'subscribed'
                    ]
                ];

                break;
            }

            $stmt = $pdo->prepare(
                "INSERT INTO newsletter_subscribers
                    (email, name, status, source, ip_address,
                     subscribed_at, unsubscribed_at, created_at, updated_at)
                 VALUES (?, ?, 'subscribed', ?, ?, NOW(), NULL, NOW(), NOW())"
            );

            $stmt->execute([
                $email,
                $name !== '' ? $name : null,
                $source,
                $ipAddress
            ]);

            $response = [
                'status' => 'success',
                'message' => 'You have successfully subscribed',
                'data' => [
                    'id' => (int)$pdo->lastInsertId(),
                    'email' => $email,
                    'status' => 'subscribed'
                ]
            ];

            http_response_code(201);
            break;

        // ================================================================
        // NEWSLETTER: GET SUBSCRIBERS
        // ================================================================

        case 'get_newsletter_subscribers':
            requireMethod('GET');

            $where = [];
            $params = [];

            $status = trim((string)($_GET['status'] ?? 'all'));

            if (!in_array($status, ['all', 'subscribed', 'unsubscribed'], true)) {
                throw new InvalidArgumentException('Invalid newsletter status');
            }

            if ($status !== 'all') {
                $where[] = 'status = ?';
                $params[] = $status;
            }

            $search = trim((string)($_GET['search'] ?? ''));

            if ($search !== '') {
                $where[] = '(email LIKE ? OR name LIKE ?)';
                $term = '%' . $search . '%';
                $params[] = $term;
                $params[] = $term;
            }

            $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

            $result = paginatedFetch(
                $pdo,
                'SELECT id, email, name, status, source, ip_address,
                        subscribed_at, unsubscribed_at, created_at, updated_at
                 FROM newsletter_subscribers'
                 . $whereSql . ' ORDER BY subscribed_at DESC, id DESC',
                'SELECT COUNT(*) FROM newsletter_subscribers' . $whereSql,
                $params
            );

            $response = [
                'status' => 'success',
                'message' => 'Newsletter subscribers fetched successfully'
            ] + $result;
            break;

        // ================================================================
        // NEWSLETTER: STATISTICS
        // ================================================================

        case 'get_newsletter_stats':
            requireMethod('GET');

            $stats = $pdo->query(
                "SELECT
                    COUNT(*) AS total,
                    COALESCE(SUM(status = 'subscribed'), 0) AS subscribed,
                    COALESCE(SUM(status = 'unsubscribed'), 0) AS unsubscribed,
                    COALESCE(SUM(subscribed_at >= (NOW() - INTERVAL 7 DAY)), 0) AS last_7_days
                 FROM newsletter_subscribers"
            )->fetch(PDO::FETCH_ASSOC);

            $response = [
                'status' => 'success',
                'message' => 'Newsletter statistics fetched successfully',
                'data' => [
                    'total' => (int)$stats['total'],
                    'subscribed' => (int)$stats['subscribed'],
                    'unsubscribed' => (int)$stats['unsubscribed'],
                    'last_7_days' => (int)$stats['last_7_days']
                ]
            ];
            break;

        // ================================================================
        // NEWSLETTER: UPDATE STATUS
        // ================================================================

        case 'update_newsletter_status':
            requireMethod('POST');

            $input = requestInput();

            $id = validPositiveInt($input['id'] ?? null, 'subscriber ID');

            $status = validateStatus(
                $input['status'] ?? '',
                ['subscribed', 'unsubscribed'],
                'newsletter status'
            );

            $stmt = $pdo->prepare(
                'SELECT id, status FROM newsletter_subscribers WHERE id = ?'
            );
            $stmt->execute([$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                throw new RuntimeException('Subscriber not found', 404);
            }

            if ($existing['status'] === $status) {
                $response = [
                    'status' => 'success',
                    'message' => 'Subscriber already has this status',
                    'data' => ['id' => $id, 'status' => $status]
                ];

                break;
            }

            if ($status === 'subscribed') {
                $stmt = $pdo->prepare(
                    "UPDATE newsletter_subscribers
                     SET status = 'subscribed',
                         subscribed_at = NOW(),
                         unsubscribed_at = NULL
                     WHERE id = ?"
                );
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE newsletter_subscribers
                     SET status = 'unsubscribed',
                         unsubscribed_at = NOW()
                     WHERE id = ?"
                );
            }

            $stmt->execute([$id]);

            $response = [
                'status' => 'success',
                'message' => 'Subscriber status updated successfully',
                'data' => ['id' => $id, 'status' => $status]
            ];
            break;

        // ================================================================
        // NEWSLETTER: DELETE SUBSCRIBER
        // ================================================================

        case 'delete_newsletter_subscriber':
            requireMethod('POST');

            $input = requestInput();

            $id = validPositiveInt($input['id'] ?? null, 'subscriber ID');

            $stmt = $pdo->prepare(
                'DELETE FROM newsletter_subscribers WHERE id = ?'
            );
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Subscriber not found', 404);
            }

            $response = [
                'status' => 'success',
                'message' => 'Subscriber deleted successfully',
                'data' => ['id' => $id]
            ];
            break;

        default:
            $response = [
                'status' => 'error',
                'message' => 'Unknown action'
            ];
            http_response_code(400);
            break;
    }
} catch (Throwable $e) {
    // Undo any half-finished donation/bank save.
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $code = (int)$e->getCode();

    if ($e instanceof PDOException) {
        error_log('Kifala API database error: ' . $e->getMessage());

        $response = [
            'status' => 'error',
            'message' => 'A database error occurred'
        ];

        http_response_code(500);
    } else {
        $httpCode = in_array($code, [404, 405, 409], true) ? $code : 400;

        $response = [
            'status' => 'error',
            'message' => $e->getMessage()
        ];

        http_response_code($httpCode);
    }
}

echo json_encode(
    $response,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

closePdoConnection($pdo);