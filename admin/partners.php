<?php
session_start();

require_once __DIR__ . '/config/db.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = getPdoConnection();

if (!$pdo instanceof PDO) {
    die('Database connection failed.');
}

$uploadDir = __DIR__ . '/uploads/partners/';
$uploadUrl = 'https://kifalatrust.org/admin/uploads/partners/';

if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        die('Partners upload directory could not be created.');
    }
}

if (empty($_SESSION['csrf_token_partners'])) {
    $_SESSION['csrf_token_partners'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token_partners'];

function partnerRedirect(): void
{
    header('Location: partners.php');
    exit;
}

function partnerFlash(string $type, string $message): void
{
    $_SESSION['partner_flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function partnerEscape($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function deletePartnerLogo(?string $logoUrl): void
{
    if (!$logoUrl) {
        return;
    }

    $path = parse_url($logoUrl, PHP_URL_PATH);
    $prefix = '/admin/uploads/partners/';

    if (!$path || strpos($path, $prefix) !== 0) {
        return;
    }

    $filename = basename($path);

    if ($filename === '' || $filename === '.' || $filename === '..') {
        return;
    }

    $filePath = __DIR__ . '/uploads/partners/' . $filename;

    if (is_file($filePath)) {
        @unlink($filePath);
    }
}

/**
 * SVG files can contain scripts, so they are checked strictly.
 */
function partnerValidateSvg(string $svg): void
{
    if ($svg === '' || strlen($svg) > 512 * 1024) {
        throw new RuntimeException('SVG logo must be smaller than 500 KB.');
    }

    $unsafe = '/<!DOCTYPE|<!ENTITY|<script|<foreignObject|<iframe|<embed|<object|<\?php'
        . '|\son[a-z]+\s*=|javascript:|data:text\/html|@import|url\(\s*["\']?\s*https?:/i';

    if (preg_match($unsafe, $svg)) {
        throw new RuntimeException('This SVG contains unsafe content.');
    }

    if (preg_match('/href\s*=\s*["\']\s*(?!#|data:image\/)/i', $svg)) {
        throw new RuntimeException('SVG must not link to external resources.');
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
        throw new RuntimeException('Please upload a valid SVG file.');
    }
}

/**
 * Optional website. Adds https:// when no scheme is typed.
 */
function partnerNormalizeWebsite(string $value): ?string
{
    $url = trim($value);

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
        throw new RuntimeException(
            'Website URL must be a valid http or https link.'
        );
    }

    if (strlen($url) > 500) {
        throw new RuntimeException('Website URL is too long.');
    }

    return $url;
}

/*
|--------------------------------------------------------------------------
| Handle POST Actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        partnerFlash(
            'danger',
            'Invalid request token. Please refresh the page.'
        );

        partnerRedirect();
    }

    $action = $_POST['action'] ?? '';
    $uploadedNewFile = null;

    try {

        /*
        |--------------------------------------------------------------------------
        | Add / Update
        |--------------------------------------------------------------------------
        */

        if ($action === 'save') {

            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
            $id = $id === false ? 0 : (int)$id;

            $name = trim((string)($_POST['name'] ?? ''));
            $websiteInput = (string)($_POST['website_url'] ?? '');
            $orderInput = trim((string)($_POST['display_order'] ?? ''));
            $status = $_POST['status'] ?? 'active';

            if ($name === '') {
                throw new RuntimeException('Partner / vendor name is required.');
            }

            if (mb_strlen($name) > 255) {
                throw new RuntimeException(
                    'Name must not exceed 255 characters.'
                );
            }

            if (!in_array($status, ['active', 'inactive'], true)) {
                throw new RuntimeException('Please select a valid status.');
            }

            $website = partnerNormalizeWebsite($websiteInput);

            $existing = null;

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'SELECT * FROM partners WHERE id = ? LIMIT 1'
                );
                $stmt->execute([$id]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$existing) {
                    throw new RuntimeException('Partner record not found.');
                }
            }

            // Display order: empty = keep current (edit) or next number (add).
            if ($orderInput === '') {
                if ($existing) {
                    $displayOrder = (int)$existing['display_order'];
                } else {
                    $displayOrder = (int)$pdo->query(
                        'SELECT COALESCE(MAX(display_order), 0) + 1 FROM partners'
                    )->fetchColumn();
                }
            } else {
                $displayOrder = filter_var($orderInput, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 0, 'max_range' => 99999]
                ]);

                if ($displayOrder === false) {
                    throw new RuntimeException(
                        'Display order must be a whole number between 0 and 99999.'
                    );
                }

                $displayOrder = (int)$displayOrder;
            }

            /*
            |--------------------------------------------------------------------------
            | Logo upload (PNG, SVG, JPG, WEBP)
            |--------------------------------------------------------------------------
            */

            $logoUrl = $existing ? (string)($existing['logo'] ?? '') : '';

            $file = $_FILES['logo_file'] ?? null;

            if (
                $file &&
                ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
            ) {

                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Logo upload failed.');
                }

                if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
                    throw new RuntimeException(
                        'Logo size must not exceed 2 MB.'
                    );
                }

                $tmpName = $file['tmp_name'] ?? '';

                if (!$tmpName || !is_uploaded_file($tmpName)) {
                    throw new RuntimeException('Invalid uploaded logo.');
                }

                $originalExt = strtolower(pathinfo(
                    (string)($file['name'] ?? ''),
                    PATHINFO_EXTENSION
                ));

                $imageInfo = @getimagesize($tmpName);

                if ($imageInfo !== false) {
                    $allowedTypes = [
                        'image/jpeg' => 'jpg',
                        'image/png'  => 'png',
                        'image/webp' => 'webp'
                    ];

                    $mimeType = (string)($imageInfo['mime'] ?? '');

                    if (!isset($allowedTypes[$mimeType])) {
                        throw new RuntimeException(
                            'Only PNG, SVG, JPG and WEBP logos are allowed.'
                        );
                    }

                    $extension = $allowedTypes[$mimeType];

                } else {
                    // getimagesize() cannot read SVG, so check it separately.
                    if ($originalExt !== 'svg') {
                        throw new RuntimeException(
                            'Please upload a valid logo (PNG, SVG, JPG or WEBP).'
                        );
                    }

                    partnerValidateSvg((string)file_get_contents($tmpName));
                    $extension = 'svg';
                }

                $filename = bin2hex(random_bytes(16)) . '.' . $extension;
                $destination = $uploadDir . $filename;

                if (!move_uploaded_file($tmpName, $destination)) {
                    throw new RuntimeException('Could not save uploaded logo.');
                }

                $uploadedNewFile = $destination;
                $logoUrl = $uploadUrl . $filename;

            } elseif ($logoUrl === '') {

                throw new RuntimeException('Please select a logo to upload.');
            }

            /*
            |--------------------------------------------------------------------------
            | Update Existing Record
            |--------------------------------------------------------------------------
            */

            if ($id > 0) {

                $stmt = $pdo->prepare(
                    'UPDATE partners
                     SET name = ?,
                         logo = ?,
                         website_url = ?,
                         display_order = ?,
                         status = ?,
                         updated_at = NOW()
                     WHERE id = ?'
                );

                $stmt->execute([
                    $name,
                    $logoUrl,
                    $website,
                    $displayOrder,
                    $status,
                    $id
                ]);

                if (
                    $existing &&
                    !empty($existing['logo']) &&
                    $existing['logo'] !== $logoUrl
                ) {
                    deletePartnerLogo($existing['logo']);
                }

                partnerFlash('success', 'Partner updated successfully.');

            } else {

                /*
                |--------------------------------------------------------------------------
                | Insert New Record
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare(
                    'INSERT INTO partners
                    (
                        name,
                        logo,
                        website_url,
                        display_order,
                        status,
                        created_at,
                        updated_at
                    )
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
                );

                $stmt->execute([
                    $name,
                    $logoUrl,
                    $website,
                    $displayOrder,
                    $status
                ]);

                partnerFlash('success', 'Partner added successfully.');
            }

        } elseif ($action === 'delete') {

            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);

            if (!$id || $id < 1) {
                throw new RuntimeException('Invalid partner ID.');
            }

            $stmt = $pdo->prepare(
                'SELECT logo FROM partners WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                throw new RuntimeException('Partner not found.');
            }

            $stmt = $pdo->prepare('DELETE FROM partners WHERE id = ?');
            $stmt->execute([$id]);

            deletePartnerLogo($item['logo'] ?? '');

            partnerFlash('success', 'Partner deleted successfully.');

        } elseif ($action === 'toggle_status') {

            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);

            if (!$id || $id < 1) {
                throw new RuntimeException('Invalid partner ID.');
            }

            $stmt = $pdo->prepare(
                "UPDATE partners
                 SET status = CASE
                    WHEN status = 'active' THEN 'inactive'
                    ELSE 'active'
                 END,
                 updated_at = NOW()
                 WHERE id = ?"
            );

            $stmt->execute([$id]);

            if ($stmt->rowCount() > 0) {
                partnerFlash('success', 'Partner status updated successfully.');
            } else {
                partnerFlash('warning', 'No status change was made.');
            }

        } else {

            throw new RuntimeException('Invalid action.');
        }

    } catch (Throwable $e) {

        if ($uploadedNewFile && is_file($uploadedNewFile)) {
            @unlink($uploadedNewFile);
        }

        error_log('Partners error: ' . $e->getMessage());

        partnerFlash(
            'danger',
            $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to complete the operation. Please check the database.'
        );
    }

    partnerRedirect();
}

/*
|--------------------------------------------------------------------------
| Search / Filter / Pagination
|--------------------------------------------------------------------------
*/

$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = $_GET['status'] ?? 'all';

if (!in_array($statusFilter, ['all', 'active', 'inactive'], true)) {
    $statusFilter = 'all';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(name LIKE ? OR website_url LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

if ($statusFilter !== 'all') {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM partners $whereSql");
$countStmt->execute($params);

$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));

$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare(
    "SELECT id, name, logo, website_url, display_order,
            status, created_at, updated_at
     FROM partners
     $whereSql
     ORDER BY display_order ASC, id ASC
     LIMIT $perPage OFFSET $offset"
);

$listStmt->execute($params);

$partners = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$flash = $_SESSION['partner_flash'] ?? null;
unset($_SESSION['partner_flash']);

$baseQuery = $_GET;
unset($baseQuery['page']);

function partnerPageUrl(int $pageNumber, array $baseQuery): string
{
    $baseQuery['page'] = $pageNumber;

    return 'partners.php?' . http_build_query($baseQuery);
}

$pageTitle = 'Partners';
$activePage = 'partners';

include __DIR__ . '/includes/header.php';
?>
<style>
.partner-page {
    padding: 24px;
    color: #263238;
}

.partner-page .program-hero {
    background: linear-gradient(135deg, #247a67, #2a9d7f);
    color: #fff;
    border-radius: 16px;
    padding: 28px 30px;
    margin-bottom: 24px;
    box-shadow: 0 8px 24px rgba(36, 122, 103, .15);
}

.partner-page .program-hero h1 {
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 7px;
}

.partner-page .program-hero p {
    color: rgba(255,255,255,.85);
    margin-bottom: 0;
}

.partner-page .btn-kt {
    background: #2a9d7f;
    border: 1px solid #2a9d7f;
    color: #fff;
    border-radius: 8px;
    padding: 10px 18px;
    font-weight: 600;
}

.partner-page .btn-kt:hover {
    background: #247a67;
    border-color: #247a67;
    color: #fff;
}

.partner-page .program-hero .btn-kt {
    background: #fff;
    color: #247a67;
    border-color: #fff;
}

.partner-page .stat-card {
    background: #fff;
    border: 1px solid #dceee8;
    border-radius: 13px;
    padding: 20px;
    height: 100%;
    box-shadow: 0 3px 14px rgba(15,23,42,.035);
}

.partner-page .stat-label {
    color: #718096;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 8px;
}

.partner-page .stat-number {
    color: #247a67;
    font-size: 27px;
    font-weight: 700;
}

.partner-page .stat-icon {
    width: 45px;
    height: 45px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #e7f6f0;
    color: #247a67;
    font-size: 19px;
}

.partner-page .partner-card {
    background: #fff;
    border: 1px solid #dceee8;
    border-radius: 14px;
    box-shadow: 0 4px 18px rgba(15,23,42,.045);
    overflow: hidden;
}

.partner-page .partner-card .card-header {
    background: #fff;
    border-bottom: 1px solid #e5f0ec;
    padding: 20px;
}

.partner-page .partner-card .card-footer {
    background: #fff;
    border-top: 1px solid #e5f0ec;
    padding: 16px 20px;
}

.partner-page .form-label {
    color: #374151;
    font-size: 13px;
    font-weight: 600;
}

.partner-page .form-control,
.partner-page .form-select {
    min-height: 43px;
    border: 1px solid #d9e5e0;
    border-radius: 8px;
    box-shadow: none;
}

.partner-page .form-control:focus,
.partner-page .form-select:focus {
    border-color: #2a9d7f;
    box-shadow: 0 0 0 3px rgba(42,157,127,.12);
}

.partner-page .btn-outline-kt {
    color: #247a67;
    border: 1px solid #b8ddd1;
    background: #fff;
    border-radius: 8px;
}

.partner-page .btn-outline-kt:hover {
    background: #e7f6f0;
    color: #1d6657;
}

.partner-page .table thead th {
    background: #f3faf7;
    color: #547166;
    border-bottom: 1px solid #dceee8;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .45px;
    padding: 15px 13px;
    white-space: nowrap;
}

.partner-page .table tbody td {
    padding: 14px 13px;
    border-bottom: 1px solid #edf4f1;
    vertical-align: middle;
}

.partner-page .table tbody tr:hover {
    background: #f8fcfa;
}

.partner-logo {
    width: 96px;
    height: 58px;
    object-fit: contain;
    padding: 5px;
    background: #fff;
    border: 1px solid #dceee8;
    border-radius: 9px;
}

.partner-website {
    display: inline-block;
    max-width: 220px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #247a67;
    font-size: 13px;
    text-decoration: none;
}

.partner-website:hover {
    text-decoration: underline;
}

.partner-order {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 30px;
    padding: 0 10px;
    border-radius: 8px;
    background: #f3faf7;
    border: 1px solid #dceee8;
    color: #247a67;
    font-weight: 700;
    font-size: 13px;
}

.partner-badge {
    display: inline-block;
    padding: 6px 11px;
    border-radius: 30px;
    font-size: 11px;
    font-weight: 700;
}

.partner-badge.active {
    color: #19734f;
    background: #e1f5e9;
}

.partner-badge.inactive {
    color: #b54747;
    background: #fde8e8;
}

.partner-page .action-buttons .btn {
    width: 33px;
    height: 33px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.partner-page .btn-outline-primary {
    color: #247a67;
    border-color: #b8ddd1;
}

.partner-page .btn-outline-primary:hover {
    background: #247a67;
    color: #fff;
}

.partner-page .pagination {
    gap: 4px;
    align-items: center;
}

.partner-page .pagination .page-link {
    color: #247a67;
    border: 1px solid #dceee8;
    border-radius: 7px;
    margin: 0;
    min-width: 35px;
    text-align: center;
    padding: 7px 11px;
    transition: .2s ease;
}

.partner-page .pagination .page-item.active .page-link {
    background: linear-gradient(135deg, #247a67, #2a9d7f);
    border-color: #247a67;
    color: #fff;
}

.partner-page .pagination .page-link:hover {
    background: #e7f6f0;
    color: #1d6657;
}

.partner-page .pagination .page-item.disabled .page-link {
    color: #a0aaa6;
    background: #f5f7f6;
    pointer-events: none;
}

.partner-empty {
    text-align: center;
    padding: 55px 20px;
    color: #718096;
}

.partner-empty i {
    color: #83b9a7;
}

.partner-preview-wrap {
    border: 1px dashed #c8ded5;
    border-radius: 10px;
    min-height: 140px;
    padding: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f8fcfa;
}

.partner-preview-wrap img {
    max-width: 100%;
    max-height: 120px;
    object-fit: contain;
}

#partnerModal .modal-dialog {
    height: calc(100vh - 2rem);
    max-height: calc(100vh - 2rem);
    margin-top: 1rem;
    margin-bottom: 1rem;
}

#partnerModal .modal-content {
    height: 100%;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border: 0;
    border-radius: 14px;
}

#partnerModal #partnerForm {
    display: flex;
    flex-direction: column;
    flex: 1 1 auto;
    min-height: 0;
    overflow: hidden;
}

#partnerModal .modal-header,
#partnerViewModal .modal-header {
    background: linear-gradient(135deg, #247a67, #2a9d7f);
    color: #fff;
    padding: 18px 24px;
}

#partnerModal .modal-header .btn-close,
#partnerViewModal .modal-header .btn-close {
    filter: brightness(0) invert(1);
}

#partnerModal .modal-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    padding: 24px;
}

#partnerModal .modal-footer {
    flex-shrink: 0;
    background: #f8fcfa;
    border-top: 1px solid #e5f0ec;
    padding: 15px 24px;
}

#partnerViewModal .view-logo {
    max-width: 100%;
    max-height: 220px;
    object-fit: contain;
    padding: 10px;
    background: #fff;
    border: 1px solid #dceee8;
    border-radius: 10px;
}

@media (max-width: 767px) {
    .partner-page { padding: 14px; }
    .partner-page .program-hero { padding: 22px 18px; }
    .partner-page .program-hero h1 { font-size: 23px; }
    .partner-page .partner-card .card-header,
    .partner-page .partner-card .card-footer { padding: 15px; }
}

@media (max-width: 575px) {
    #partnerModal .modal-dialog {
        height: calc(100vh - 1rem);
        max-height: calc(100vh - 1rem);
        margin: .5rem;
    }

    #partnerModal .modal-body { padding: 16px; }
}
</style>

<div class="partner-page">

    <div class="program-hero">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1><i class="fas fa-handshake me-2"></i> Partners &amp; Vendors</h1>
                <p>Manage partner logos shown on the website and control their display order.</p>
            </div>

            <button type="button" class="btn btn-kt" id="addPartnerBtn">
                <i class="fas fa-plus me-2"></i> Add Partner
            </button>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-4 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Matching Partners</div>
                        <div class="stat-number"><?= $totalRecords ?></div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-handshake"></i></div>
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Active on This Page</div>
                        <div class="stat-number">
                            <?= count(array_filter($partners, fn($item) => ($item['status'] ?? '') === 'active')) ?>
                        </div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Inactive on This Page</div>
                        <div class="stat-number">
                            <?= count(array_filter($partners, fn($item) => ($item['status'] ?? '') === 'inactive')) ?>
                        </div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-eye-slash"></i></div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= partnerEscape($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= partnerEscape($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card partner-card">

        <div class="card-header">
            <form method="GET" action="partners.php" class="row g-3 align-items-end">

                <div class="col-lg-6 col-md-6">
                    <label class="form-label" for="partnerSearch">Search Partners</label>
                    <input id="partnerSearch" type="text" name="search" class="form-control"
                           placeholder="Search by name or website..."
                           value="<?= partnerEscape($search) ?>">
                </div>

                <div class="col-lg-3 col-md-3">
                    <label class="form-label" for="partnerStatusFilter">Status</label>
                    <select id="partnerStatusFilter" name="status" class="form-select">
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

                <div class="col-lg-3 col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-kt flex-grow-1">
                        <i class="fas fa-search me-1"></i> Search
                    </button>
                    <a href="partners.php" class="btn btn-outline-kt">Reset</a>
                </div>

            </form>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4">#</th>
                            <th>Logo</th>
                            <th>Partner / Vendor</th>
                            <th>Website</th>
                            <th>Order</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (empty($partners)): ?>
                        <tr>
                            <td colspan="8">
                                <div class="partner-empty">
                                    <i class="fas fa-handshake fa-3x mb-3"></i>
                                    <h5>No partners found</h5>
                                    <p class="mb-0">Try changing your filters or add a new partner.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>

                        <?php foreach ($partners as $index => $item): ?>
                            <?php
                            $itemJson = json_encode(
                                $item,
                                JSON_HEX_APOS |
                                JSON_HEX_QUOT |
                                JSON_HEX_TAG |
                                JSON_HEX_AMP
                            );

                            $itemJson = $itemJson === false ? '{}' : $itemJson;

                            $websiteHost = '';

                            if (!empty($item['website_url'])) {
                                $websiteHost = (string)parse_url($item['website_url'], PHP_URL_HOST);
                            }
                            ?>

                            <tr>
                                <td class="ps-4"><?= $offset + $index + 1 ?></td>

                                <td>
                                    <a href="<?= partnerEscape($item['logo']) ?>"
                                       target="_blank" rel="noopener noreferrer">
                                        <img src="<?= partnerEscape($item['logo']) ?>"
                                             class="partner-logo"
                                             alt="<?= partnerEscape($item['name']) ?>"
                                             loading="lazy">
                                    </a>
                                </td>

                                <td><strong><?= partnerEscape($item['name']) ?></strong></td>

                                <td>
                                    <?php if (!empty($item['website_url'])): ?>
                                        <a class="partner-website"
                                           href="<?= partnerEscape($item['website_url']) ?>"
                                           target="_blank" rel="noopener noreferrer"
                                           title="<?= partnerEscape($item['website_url']) ?>">
                                            <i class="fas fa-external-link-alt me-1"></i>
                                            <?= partnerEscape($websiteHost ?: $item['website_url']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="partner-order"><?= (int)$item['display_order'] ?></span>
                                </td>

                                <td>
                                    <span class="partner-badge <?= partnerEscape($item['status']) ?>">
                                        <?= ucfirst(partnerEscape($item['status'])) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= !empty($item['created_at'])
                                        ? partnerEscape(date('d M Y', strtotime($item['created_at'])))
                                        : '—' ?>
                                </td>

                                <td class="text-end pe-4">
                                    <div class="d-inline-flex gap-1 action-buttons">

                                        <!-- View -->
                                        <button type="button"
                                                class="btn btn-sm btn-outline-info viewPartnerBtn"
                                                data-item="<?= partnerEscape($itemJson) ?>"
                                                title="View Details" aria-label="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>

                                        <!-- Edit -->
                                        <button type="button"
                                                class="btn btn-sm btn-outline-primary editPartnerBtn"
                                                data-item="<?= partnerEscape($itemJson) ?>"
                                                title="Edit" aria-label="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>

                                        <!-- Activate / Deactivate -->
                                        <form method="POST" action="partners.php" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= partnerEscape($csrfToken) ?>">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">

                                            <button type="submit"
                                                    class="btn btn-sm <?= $item['status'] === 'active' ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                                    title="<?= $item['status'] === 'active' ? 'Deactivate' : 'Activate' ?>"
                                                    aria-label="<?= $item['status'] === 'active' ? 'Deactivate' : 'Activate' ?>">
                                                <i class="fas <?= $item['status'] === 'active' ? 'fa-toggle-on' : 'fa-toggle-off' ?>"></i>
                                            </button>
                                        </form>

                                        <!-- Delete -->
                                        <form method="POST" action="partners.php" class="d-inline deletePartnerForm">
                                            <input type="hidden" name="csrf_token" value="<?= partnerEscape($csrfToken) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">

                                            <button type="submit"
                                                    class="btn btn-sm btn-outline-danger"
                                                    title="Delete" aria-label="Delete">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>

                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-3">

            <small class="text-muted">
                Showing <?= $totalRecords > 0 ? $offset + 1 : 0 ?>
                –<?= min($offset + $perPage, $totalRecords) ?>
                of <?= $totalRecords ?> matching partners
            </small>

            <?php if ($totalPages > 1): ?>
                <nav aria-label="Partners pagination">
                    <ul class="pagination pagination-sm mb-0">

                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link"
                               href="<?= partnerEscape(partnerPageUrl(1, $baseQuery)) ?>"
                               aria-label="First page" title="First page">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                        </li>

                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link"
                               href="<?= partnerEscape(partnerPageUrl(max(1, $page - 1), $baseQuery)) ?>"
                               aria-label="Previous page">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        ?>

                        <?php if ($startPage > 1): ?>
                            <li class="page-item">
                                <a class="page-link"
                                   href="<?= partnerEscape(partnerPageUrl(1, $baseQuery)) ?>">1</a>
                            </li>

                            <?php if ($startPage > 2): ?>
                                <li class="page-item disabled">
                                    <span class="page-link">…</span>
                                </li>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                <?php if ($p === $page): ?>
                                    <span class="page-link" aria-current="page"><?= $p ?></span>
                                <?php else: ?>
                                    <a class="page-link"
                                       href="<?= partnerEscape(partnerPageUrl($p, $baseQuery)) ?>">
                                        <?= $p ?>
                                    </a>
                                <?php endif; ?>
                            </li>
                        <?php endfor; ?>

                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <li class="page-item disabled">
                                    <span class="page-link">…</span>
                                </li>
                            <?php endif; ?>

                            <li class="page-item">
                                <a class="page-link"
                                   href="<?= partnerEscape(partnerPageUrl($totalPages, $baseQuery)) ?>">
                                    <?= $totalPages ?>
                                </a>
                            </li>
                        <?php endif; ?>

                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link"
                               href="<?= partnerEscape(partnerPageUrl(min($totalPages, $page + 1), $baseQuery)) ?>"
                               aria-label="Next page">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>

                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link"
                               href="<?= partnerEscape(partnerPageUrl($totalPages, $baseQuery)) ?>"
                               aria-label="Last page" title="Last page">
                                <i class="fas fa-angle-double-right"></i>
                            </a>
                        </li>

                    </ul>
                </nav>
            <?php endif; ?>

        </div>
    </div>
</div>

<!-- Add / Edit Partner Modal -->
<div class="modal fade" id="partnerModal" tabindex="-1"
     aria-labelledby="partnerModalLabel" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">

            <form method="POST" action="partners.php"
                  enctype="multipart/form-data" id="partnerForm">

                <input type="hidden" name="csrf_token"
                       value="<?= partnerEscape($csrfToken) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="partnerId" value="0">

                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold" id="partnerModalLabel">
                            Add Partner
                        </h5>
                        <small class="text-white-50">Add a partner or vendor logo</small>
                    </div>

                    <button type="button" class="btn-close"
                            data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">

                    <div class="mb-3">
                        <label for="partnerName" class="form-label">
                            Partner / Vendor Name <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control"
                               name="name" id="partnerName"
                               maxlength="255" required
                               placeholder="e.g. ABC Foundation">
                    </div>

                    <div class="mb-3">
                        <label for="partnerLogo" class="form-label">
                            Logo Image <span class="text-danger" id="partnerLogoStar">*</span>
                        </label>
                        <input type="file" class="form-control"
                               name="logo_file" id="partnerLogo"
                               accept=".png,.svg,.jpg,.jpeg,.webp,image/png,image/svg+xml,image/jpeg,image/webp">
                        <small class="text-muted" id="partnerLogoHelp">
                            PNG, SVG, JPG or WEBP. Maximum size 2 MB.
                        </small>
                    </div>

                    <div class="partner-preview-wrap mb-3">
                        <span class="text-muted" id="partnerLogoPlaceholder">
                            <i class="fas fa-image me-2"></i>
                            Logo preview will appear here
                        </span>
                        <img src="" alt="Logo preview"
                             id="partnerLogoPreview" class="d-none">
                    </div>

                    <div class="mb-3">
                        <label for="partnerWebsite" class="form-label">
                            Website URL <span class="text-muted fw-normal">(optional)</span>
                        </label>
                        <input type="text" class="form-control"
                               name="website_url" id="partnerWebsite"
                               maxlength="500" inputmode="url"
                               placeholder="https://www.example.com">
                        <small class="text-muted">
                            The logo will link to this website.
                        </small>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="partnerOrder" class="form-label">Display Order</label>
                            <input type="number" class="form-control"
                                   name="display_order" id="partnerOrder"
                                   min="0" max="99999" step="1"
                                   placeholder="Auto (next number)">
                            <small class="text-muted">
                                Lower numbers appear first. Leave empty to add at the end.
                            </small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label d-block">Status</label>

                            <input type="hidden" name="status" value="inactive">

                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox"
                                       role="switch" name="status" value="active"
                                       id="partnerStatus" checked>
                                <label class="form-check-label" for="partnerStatus"
                                       id="partnerStatusLabel">Active</label>
                            </div>
                            <small class="text-muted">Inactive partners are hidden on the website.</small>
                        </div>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light border"
                            data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-kt px-4"
                            id="partnerSaveBtn">
                        <i class="fas fa-save me-2"></i> Save Partner
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>

<!-- View Partner Modal -->
<div class="modal fade" id="partnerViewModal" tabindex="-1"
     aria-labelledby="partnerViewModalLabel" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4 overflow-hidden">

            <div class="modal-header">
                <div>
                    <h5 class="modal-title fw-bold" id="partnerViewModalLabel">
                        <i class="fas fa-eye me-2"></i> Partner Details
                    </h5>
                    <small class="text-white-50">Preview partner</small>
                </div>
                <button type="button" class="btn-close"
                        data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-4 text-center">
                <div id="partnerViewLogo" class="mb-4"></div>
                <h5 id="partnerViewName" class="fw-bold"></h5>

                <div class="mb-3">
                    <a id="partnerViewWebsite" href="#" target="_blank"
                       rel="noopener noreferrer" class="partner-website"></a>
                </div>

                <div class="d-flex flex-wrap gap-2 justify-content-center align-items-center">
                    <span class="badge bg-light text-dark border" id="partnerViewOrder"></span>
                    <span class="partner-badge" id="partnerViewStatus"></span>
                    <small class="text-muted" id="partnerViewDate"></small>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-kt"
                        data-bs-dismiss="modal">Close</button>
            </div>

        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const modalElement = document.getElementById('partnerModal');
    const partnerModal = new bootstrap.Modal(modalElement);

    const viewModalElement = document.getElementById('partnerViewModal');
    const partnerViewModal = new bootstrap.Modal(viewModalElement);

    const form = document.getElementById('partnerForm');

    const idInput = document.getElementById('partnerId');
    const nameInput = document.getElementById('partnerName');
    const logoInput = document.getElementById('partnerLogo');
    const logoStar = document.getElementById('partnerLogoStar');
    const logoHelp = document.getElementById('partnerLogoHelp');
    const websiteInput = document.getElementById('partnerWebsite');
    const orderInput = document.getElementById('partnerOrder');
    const statusInput = document.getElementById('partnerStatus');
    const statusLabel = document.getElementById('partnerStatusLabel');

    const logoPreview = document.getElementById('partnerLogoPreview');
    const logoPlaceholder = document.getElementById('partnerLogoPlaceholder');

    const modalTitle = document.getElementById('partnerModalLabel');
    const saveButton = document.getElementById('partnerSaveBtn');

    const MAX_LOGO_SIZE = 2 * 1024 * 1024;
    const ALLOWED_TYPES = [
        'image/png',
        'image/svg+xml',
        'image/jpeg',
        'image/webp'
    ];

    let originalLogoUrl = '';

    function resetLogoPreview() {
        logoPreview.src = '';
        logoPreview.classList.add('d-none');
        logoPlaceholder.classList.remove('d-none');
    }

    function showLogoPreview(url) {
        logoPreview.src = url;
        logoPreview.classList.remove('d-none');
        logoPlaceholder.classList.add('d-none');
    }

    function updateStatusLabel() {
        statusLabel.textContent = statusInput.checked ? 'Active' : 'Inactive';
    }

    // The logo is required when adding, or when the partner has no logo yet.
    function updateLogoRequirement() {
        const required = idInput.value === '0' || !originalLogoUrl;

        logoInput.required = required;
        logoStar.classList.toggle('d-none', !required);

        logoHelp.textContent = required
            ? 'PNG, SVG, JPG or WEBP. Maximum size 2 MB.'
            : 'PNG, SVG, JPG or WEBP. Maximum size 2 MB. Leave empty to keep the current logo.';
    }

    function openAddModal() {
        form.reset();

        idInput.value = '0';
        originalLogoUrl = '';

        modalTitle.textContent = 'Add Partner';

        saveButton.innerHTML = '<i class="fas fa-save me-2"></i> Save Partner';
        saveButton.disabled = false;

        resetLogoPreview();
        statusInput.checked = true;
        updateStatusLabel();
        updateLogoRequirement();

        partnerModal.show();
    }

    document.getElementById('addPartnerBtn')
        .addEventListener('click', openAddModal);

    /*
    |--------------------------------------------------------------------------
    | Edit Button
    |--------------------------------------------------------------------------
    */

    document.querySelectorAll('.editPartnerBtn').forEach(function (button) {

        button.addEventListener('click', function () {

            let item;

            try {
                item = JSON.parse(this.dataset.item);
            } catch (error) {
                alert('Could not load partner.');
                return;
            }

            form.reset();

            idInput.value = item.id;
            nameInput.value = item.name || '';
            websiteInput.value = item.website_url || '';
            orderInput.value = item.display_order ?? '';
            statusInput.checked = (item.status || 'active') === 'active';

            originalLogoUrl = item.logo || '';

            modalTitle.textContent = 'Edit Partner';

            saveButton.innerHTML = '<i class="fas fa-save me-2"></i> Update Partner';
            saveButton.disabled = false;

            if (originalLogoUrl) {
                showLogoPreview(originalLogoUrl);
            } else {
                resetLogoPreview();
            }

            updateStatusLabel();
            updateLogoRequirement();

            partnerModal.show();
        });
    });

    statusInput.addEventListener('change', updateStatusLabel);

    /*
    |--------------------------------------------------------------------------
    | Logo preview / validation
    |--------------------------------------------------------------------------
    */

    logoInput.addEventListener('change', function () {

        if (!this.files.length) {
            if (originalLogoUrl) {
                showLogoPreview(originalLogoUrl);
            } else {
                resetLogoPreview();
            }

            return;
        }

        const file = this.files[0];

        if (file.size > MAX_LOGO_SIZE) {
            alert('Logo size must not exceed 2 MB.');
            this.value = '';
            return;
        }

        const isSvgByName = /\.svg$/i.test(file.name);

        if (!ALLOWED_TYPES.includes(file.type) && !isSvgByName) {
            alert('Only PNG, SVG, JPG and WEBP logos are allowed.');
            this.value = '';
            return;
        }

        const reader = new FileReader();

        reader.onload = function (event) {
            showLogoPreview(event.target.result);
        };

        reader.readAsDataURL(file);
    });

    /*
    |--------------------------------------------------------------------------
    | Form validation
    |--------------------------------------------------------------------------
    */

    form.addEventListener('submit', function (event) {

        if (!nameInput.value.trim()) {
            event.preventDefault();
            alert('Please enter the partner / vendor name.');
            nameInput.focus();
            return;
        }

        if (logoInput.required && !logoInput.files.length) {
            event.preventDefault();
            alert('Please select a logo.');
            logoInput.focus();
            return;
        }

        const orderValue = orderInput.value.trim();

        if (orderValue !== '' && !/^\d{1,5}$/.test(orderValue)) {
            event.preventDefault();
            alert('Display order must be a whole number between 0 and 99999.');
            orderInput.focus();
            return;
        }

        saveButton.disabled = true;

        saveButton.innerHTML =
            '<span class="spinner-border spinner-border-sm me-2"></span> Saving...';
    });

    /*
    |--------------------------------------------------------------------------
    | Delete confirmation
    |--------------------------------------------------------------------------
    */

    document.querySelectorAll('.deletePartnerForm').forEach(function (deleteForm) {

        deleteForm.addEventListener('submit', function (event) {

            if (!confirm('Are you sure you want to delete this partner?')) {
                event.preventDefault();
            }
        });
    });

    /*
    |--------------------------------------------------------------------------
    | View button
    |--------------------------------------------------------------------------
    */

    document.querySelectorAll('.viewPartnerBtn').forEach(function (button) {

        button.addEventListener('click', function () {

            let item;

            try {
                item = JSON.parse(this.dataset.item);
            } catch (error) {
                alert('Could not load partner details.');
                return;
            }

            const logoContainer = document.getElementById('partnerViewLogo');
            logoContainer.replaceChildren();

            const image = document.createElement('img');
            image.src = item.logo || '';
            image.alt = item.name || 'Partner logo';
            image.className = 'view-logo';
            logoContainer.appendChild(image);

            document.getElementById('partnerViewName').textContent = item.name || '';

            const websiteLink = document.getElementById('partnerViewWebsite');

            if (item.website_url) {
                websiteLink.href = item.website_url;
                websiteLink.textContent = item.website_url;
                websiteLink.classList.remove('d-none');
            } else {
                websiteLink.removeAttribute('href');
                websiteLink.textContent = 'No website';
                websiteLink.classList.add('d-none');
            }

            document.getElementById('partnerViewOrder').textContent =
                'Order: ' + (item.display_order ?? 0);

            const statusElement = document.getElementById('partnerViewStatus');
            statusElement.textContent = item.status || '';
            statusElement.className =
                'partner-badge ' + (item.status === 'active' ? 'active' : 'inactive');

            document.getElementById('partnerViewDate').textContent =
                item.created_at || '';

            partnerViewModal.show();
        });
    });

    modalElement.addEventListener('hidden.bs.modal', function () {
        saveButton.disabled = false;
    });

    viewModalElement.addEventListener('hidden.bs.modal', function () {
        document.getElementById('partnerViewLogo').replaceChildren();
    });

    updateStatusLabel();
    updateLogoRequirement();
});
</script>

<?php
include __DIR__ . '/includes/footer.php';
?>