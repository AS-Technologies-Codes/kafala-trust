<?php
session_start();

require_once __DIR__ . '/config/db.php';

$pdo = getPdoConnection();

if (!$pdo instanceof PDO) {
    die('Database connection failed.');
}

$uploadDir = __DIR__ . '/uploads/gallery/';
$uploadUrl = 'https://kifalatrust.org/admin/uploads/gallery/';

// Maximum number of images that can be uploaded in one go.
// Note: the server's PHP setting "max_file_uploads" (default 20) also applies.
const GALLERY_MAX_FILES = 20;

if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        die('Gallery upload directory could not be created.');
    }
}

if (empty($_SESSION['csrf_token_gallery'])) {
    $_SESSION['csrf_token_gallery'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token_gallery'];

function galleryRedirect(): void
{
    header('Location: gallery.php');
    exit;
}

function galleryFlash(string $type, string $message): void
{
    $_SESSION['gallery_flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function galleryEscape($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function galleryYouTubeId(string $url): string
{
    $parts = parse_url(trim($url));

    if (!is_array($parts)) {
        return '';
    }

    $host = strtolower($parts['host'] ?? '');
    $path = trim($parts['path'] ?? '', '/');
    $videoId = '';

    if (in_array($host, ['youtu.be', 'www.youtu.be'], true)) {
        $videoId = explode('/', $path)[0] ?? '';
    } elseif (in_array($host, [
        'youtube.com',
        'www.youtube.com',
        'm.youtube.com',
        'music.youtube.com'
    ], true)) {
        if ($path === 'watch') {
            parse_str($parts['query'] ?? '', $query);
            $videoId = $query['v'] ?? '';
        } elseif (preg_match(
            '#^(embed|shorts|live)/([A-Za-z0-9_-]+)$#',
            $path,
            $matches
        )) {
            $videoId = $matches[2];
        }
    }

    return preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId)
        ? $videoId
        : '';
}

function deleteGalleryUpload(?string $mediaUrl): void
{
    if (!$mediaUrl) {
        return;
    }

    $path = parse_url($mediaUrl, PHP_URL_PATH);
    $prefix = '/admin/uploads/gallery/';

    if (!$path || strpos($path, $prefix) !== 0) {
        return;
    }

    $filename = basename($path);

    if ($filename === '' || $filename === '.' || $filename === '..') {
        return;
    }

    $filePath = __DIR__ . '/uploads/gallery/' . $filename;

    if (is_file($filePath)) {
        @unlink($filePath);
    }
}

/**
 * Collect uploaded files from a field that may hold one file or many
 * (name="media_files[]"). Empty slots (no file chosen) are skipped.
 * Returns a list of normal single-file arrays.
 */
function galleryCollectFiles(string $field): array
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return [];
    }

    $raw = $_FILES[$field];
    $files = [];

    if (is_array($raw['name'] ?? null)) {
        foreach ($raw['name'] as $i => $name) {
            $error = $raw['error'][$i] ?? UPLOAD_ERR_NO_FILE;

            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $files[] = [
                'name'     => $name,
                'type'     => $raw['type'][$i] ?? '',
                'tmp_name' => $raw['tmp_name'][$i] ?? '',
                'error'    => $error,
                'size'     => $raw['size'][$i] ?? 0
            ];
        }
    } elseif (($raw['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $files[] = $raw;
    }

    return $files;
}

/**
 * Validate one uploaded image and move it into the gallery folder.
 * Returns ['path' => full file path, 'url' => public URL].
 */
function galleryStoreImage(array $file, string $uploadDir, string $uploadUrl): array
{
    $label = trim((string)($file['name'] ?? 'image'));

    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException(
            $label . ': file is too large for the server.'
        );
    }

    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException($label . ': image upload failed.');
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException(
            $label . ': image size must not exceed 5 MB.'
        );
    }

    $tmpName = $file['tmp_name'] ?? '';

    if (!$tmpName || !is_uploaded_file($tmpName)) {
        throw new RuntimeException($label . ': invalid uploaded image.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($tmpName);

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    if (!isset($allowedTypes[$mimeType])) {
        throw new RuntimeException(
            $label . ': only JPG, PNG and WEBP images are allowed.'
        );
    }

    if (@getimagesize($tmpName) === false) {
        throw new RuntimeException(
            $label . ': uploaded file is not a valid image.'
        );
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
    $destination = $uploadDir . $filename;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException($label . ': could not save uploaded image.');
    }

    return [
        'path' => $destination,
        'url'  => $uploadUrl . $filename
    ];
}

/**
 * Validate the selected program.
 *
 * - New items must have an ACTIVE program.
 * - When editing, the item may keep its current program even if that
 *   program is now inactive.
 * - Old items that have no program yet may stay without one.
 */
function galleryResolveProgramId(
    PDO $pdo,
    $value,
    ?int $currentProgramId,
    bool $required
): ?int {
    $value = trim((string)($value ?? ''));

    if ($value === '') {
        if ($required) {
            throw new RuntimeException('Please select a program.');
        }

        return null;
    }

    $programId = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1]
    ]);

    if ($programId === false) {
        throw new RuntimeException('Invalid program selected.');
    }

    $programId = (int)$programId;

    $stmt = $pdo->prepare('SELECT status FROM programs WHERE id = ? LIMIT 1');
    $stmt->execute([$programId]);
    $status = $stmt->fetchColumn();

    if ($status === false) {
        throw new RuntimeException('Selected program was not found.');
    }

    if ($status !== 'active' && $programId !== $currentProgramId) {
        throw new RuntimeException('Please select an active program.');
    }

    return $programId;
}

/*
|--------------------------------------------------------------------------
| Handle POST Actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // When the upload is bigger than the server's post_max_size, PHP drops
    // the whole POST body. Give a clear message instead of a token error.
    if (
        empty($_POST) &&
        empty($_FILES) &&
        (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
    ) {
        galleryFlash(
            'danger',
            'The upload is too large for the server. Select fewer or smaller images and try again.'
        );

        galleryRedirect();
    }

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        galleryFlash(
            'danger',
            'Invalid request token. Please refresh the page.'
        );

        galleryRedirect();
    }

    $action = $_POST['action'] ?? '';

    // Every file saved during this request (deleted again if anything fails).
    $uploadedNewFiles = [];

    try {

        /*
        |--------------------------------------------------------------------------
        | Add / Update
        |--------------------------------------------------------------------------
        */

        if ($action === 'save') {

            $id = filter_var(
                $_POST['id'] ?? 0,
                FILTER_VALIDATE_INT
            );

            $id = $id === false ? 0 : (int)$id;

            $title = trim((string)($_POST['title'] ?? ''));
            $description = trim(
                (string)($_POST['description'] ?? '')
            );

            $mediaType = $_POST['media_type'] ?? 'image';
            $status = $_POST['status'] ?? 'active';

            if ($title === '') {
                throw new RuntimeException(
                    'Gallery title is required.'
                );
            }

            if (mb_strlen($title) > 255) {
                throw new RuntimeException(
                    'Title must not exceed 255 characters.'
                );
            }

            if (!in_array(
                $mediaType,
                ['image', 'video'],
                true
            )) {
                throw new RuntimeException(
                    'Please select a valid media type.'
                );
            }

            if (!in_array(
                $status,
                ['active', 'inactive'],
                true
            )) {
                throw new RuntimeException(
                    'Please select a valid status.'
                );
            }

            $existing = null;

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'SELECT *
                     FROM gallery
                     WHERE id = ?
                     LIMIT 1'
                );

                $stmt->execute([$id]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$existing) {
                    throw new RuntimeException(
                        'Gallery record not found.'
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Program (dropdown of active programs)
            |--------------------------------------------------------------------------
            */

            $currentProgramId = ($existing && $existing['program_id'] !== null)
                ? (int)$existing['program_id']
                : null;

            // Required for new items and for items that already have a program.
            // Old items that never had a program can stay without one.
            $programRequired = !$existing || $currentProgramId !== null;

            $programId = galleryResolveProgramId(
                $pdo,
                $_POST['program_id'] ?? '',
                $currentProgramId,
                $programRequired
            );

            $mediaUrl = '';

            /*
             * Preserve the old media only if the selected type
             * is the same as the existing type.
             */

            if (
                $existing &&
                ($existing['media_type'] ?? '') === $mediaType
            ) {
                $mediaUrl = (string)(
                    $existing['media_url'] ?? ''
                );
            }

            // URLs of NEW images saved in this request (one gallery row each).
            $newImageUrls = [];

            /*
            |--------------------------------------------------------------------------
            | Image Upload (one or many images)
            |--------------------------------------------------------------------------
            */

            if ($mediaType === 'image') {

                $files = galleryCollectFiles('media_files');

                if (count($files) > GALLERY_MAX_FILES) {
                    throw new RuntimeException(
                        'You can upload a maximum of '
                        . GALLERY_MAX_FILES . ' images at a time.'
                    );
                }

                // Editing changes ONE existing record, so only one file.
                if ($id > 0 && count($files) > 1) {
                    throw new RuntimeException(
                        'Only one image can be uploaded when editing an item.'
                    );
                }

                if ($id === 0 && count($files) === 0) {
                    throw new RuntimeException(
                        'Please select at least one image to upload.'
                    );
                }

                foreach ($files as $file) {
                    $saved = galleryStoreImage($file, $uploadDir, $uploadUrl);

                    $uploadedNewFiles[] = $saved['path'];
                    $newImageUrls[] = $saved['url'];
                }

                if ($id > 0) {
                    if ($newImageUrls) {
                        $mediaUrl = $newImageUrls[0];
                    } elseif ($mediaUrl === '') {
                        throw new RuntimeException(
                            'Please select an image to upload.'
                        );
                    }
                }

            } else {

                /*
                |--------------------------------------------------------------------------
                | YouTube Video
                |--------------------------------------------------------------------------
                */

                $videoUrl = trim(
                    (string)($_POST['video_url'] ?? '')
                );

                if (
                    $videoUrl === '' &&
                    $existing &&
                    ($existing['media_type'] ?? '') === 'video'
                ) {
                    $videoUrl = (string)$existing['media_url'];
                }

                $videoId = galleryYouTubeId($videoUrl);

                if ($videoId === '') {
                    throw new RuntimeException(
                        'Please enter a valid YouTube video URL.'
                    );
                }

                $mediaUrl =
                    'https://www.youtube.com/watch?v=' . $videoId;
            }

            /*
            |--------------------------------------------------------------------------
            | Update Existing Record
            |--------------------------------------------------------------------------
            */

            if ($id > 0) {

                $stmt = $pdo->prepare(
                    'UPDATE gallery
                     SET program_id = ?,
                         title = ?,
                         description = ?,
                         media_type = ?,
                         media_url = ?,
                         status = ?,
                         updated_at = NOW()
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

                if (
                    $existing &&
                    !empty($existing['media_url']) &&
                    $existing['media_url'] !== $mediaUrl
                ) {
                    deleteGalleryUpload(
                        $existing['media_url']
                    );
                }

                galleryFlash(
                    'success',
                    'Gallery item updated successfully.'
                );

            } else {

                /*
                |--------------------------------------------------------------------------
                | Insert New Record(s)
                |--------------------------------------------------------------------------
                | Images: one gallery row per uploaded image (same title,
                | description, program and status). Video: a single row.
                */

                $urlsToInsert = $mediaType === 'image'
                    ? $newImageUrls
                    : [$mediaUrl];

                $stmt = $pdo->prepare(
                    'INSERT INTO gallery
                    (
                        program_id,
                        title,
                        description,
                        media_type,
                        media_url,
                        status,
                        created_at,
                        updated_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
                );

                $pdo->beginTransaction();

                foreach ($urlsToInsert as $insertUrl) {
                    $stmt->execute([
                        $programId,
                        $title,
                        $description,
                        $mediaType,
                        $insertUrl,
                        $status
                    ]);
                }

                $pdo->commit();

                $addedCount = count($urlsToInsert);

                galleryFlash(
                    'success',
                    $addedCount > 1
                        ? $addedCount . ' gallery items added successfully.'
                        : 'Gallery item added successfully.'
                );
            }

        } elseif ($action === 'delete') {

            /*
            |--------------------------------------------------------------------------
            | Delete
            |--------------------------------------------------------------------------
            */

            $id = filter_var(
                $_POST['id'] ?? 0,
                FILTER_VALIDATE_INT
            );

            if (!$id || $id < 1) {
                throw new RuntimeException(
                    'Invalid gallery ID.'
                );
            }

            $stmt = $pdo->prepare(
                'SELECT media_url
                 FROM gallery
                 WHERE id = ?
                 LIMIT 1'
            );

            $stmt->execute([$id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                throw new RuntimeException(
                    'Gallery item not found.'
                );
            }

            $stmt = $pdo->prepare(
                'DELETE FROM gallery WHERE id = ?'
            );

            $stmt->execute([$id]);

            deleteGalleryUpload($item['media_url'] ?? '');

            galleryFlash(
                'success',
                'Gallery item deleted successfully.'
            );

        } elseif ($action === 'toggle_status') {

            /*
            |--------------------------------------------------------------------------
            | Activate / Deactivate
            |--------------------------------------------------------------------------
            */

            $id = filter_var(
                $_POST['id'] ?? 0,
                FILTER_VALIDATE_INT
            );

            if (!$id || $id < 1) {
                throw new RuntimeException(
                    'Invalid gallery ID.'
                );
            }

            $stmt = $pdo->prepare(
                "UPDATE gallery
                 SET status = CASE
                    WHEN status = 'active' THEN 'inactive'
                    ELSE 'active'
                 END,
                 updated_at = NOW()
                 WHERE id = ?"
            );

            $stmt->execute([$id]);

            if ($stmt->rowCount() > 0) {
                galleryFlash(
                    'success',
                    'Gallery status updated successfully.'
                );
            } else {
                galleryFlash(
                    'warning',
                    'No status change was made.'
                );
            }

        } else {

            throw new RuntimeException('Invalid action.');
        }

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // Remove every image saved during this failed request.
        foreach ($uploadedNewFiles as $savedFile) {
            if (is_file($savedFile)) {
                @unlink($savedFile);
            }
        }

        error_log('Gallery error: ' . $e->getMessage());

        galleryFlash('danger', $e->getMessage());
    }

    galleryRedirect();
}

/*
|--------------------------------------------------------------------------
| Programs for dropdowns
|--------------------------------------------------------------------------
| $activePrograms : shown in the Add / Edit form dropdown
| $allPrograms    : shown in the list filter dropdown
|--------------------------------------------------------------------------
*/

$activePrograms = $pdo->query(
    "SELECT id, title
     FROM programs
     WHERE status = 'active'
     ORDER BY title ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$allPrograms = $pdo->query(
    "SELECT id, title, status
     FROM programs
     ORDER BY title ASC"
)->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Search / Filter / Pagination
|--------------------------------------------------------------------------
*/

$search = trim((string)($_GET['search'] ?? ''));

$statusFilter = $_GET['status'] ?? 'all';
$typeFilter = $_GET['type'] ?? 'all';
$programFilter = (string)($_GET['program'] ?? 'all');

if (!in_array(
    $statusFilter,
    ['all', 'active', 'inactive'],
    true
)) {
    $statusFilter = 'all';
}

if (!in_array(
    $typeFilter,
    ['all', 'image', 'video'],
    true
)) {
    $typeFilter = 'all';
}

// program filter: all | none | <program id>
if (
    $programFilter !== 'all' &&
    $programFilter !== 'none' &&
    !ctype_digit($programFilter)
) {
    $programFilter = 'all';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(g.title LIKE ? OR g.description LIKE ? OR p.title LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

if ($statusFilter !== 'all') {
    $where[] = 'g.status = ?';
    $params[] = $statusFilter;
}

if ($typeFilter !== 'all') {
    $where[] = 'g.media_type = ?';
    $params[] = $typeFilter;
}

if ($programFilter === 'none') {
    $where[] = 'g.program_id IS NULL';
} elseif ($programFilter !== 'all') {
    $where[] = 'g.program_id = ?';
    $params[] = (int)$programFilter;
}

$whereSql = $where
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

$countStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM gallery g
     LEFT JOIN programs p ON p.id = g.program_id
     $whereSql"
);

$countStmt->execute($params);

$totalRecords = (int)$countStmt->fetchColumn();

$totalPages = max(
    1,
    (int)ceil($totalRecords / $perPage)
);

$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare(
    "SELECT g.id, g.program_id, p.title AS program_title,
            g.title, g.description, g.media_type, g.media_url,
            g.status, g.created_at, g.updated_at
     FROM gallery g
     LEFT JOIN programs p ON p.id = g.program_id
     $whereSql
     ORDER BY g.id DESC
     LIMIT $perPage OFFSET $offset"
);

$listStmt->execute($params);

$galleryItems = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$flash = $_SESSION['gallery_flash'] ?? null;
unset($_SESSION['gallery_flash']);

$baseQuery = $_GET;
unset($baseQuery['page']);

function galleryPageUrl(
    int $pageNumber,
    array $baseQuery
): string {
    $baseQuery['page'] = $pageNumber;

    return 'gallery.php?' . http_build_query($baseQuery);
}

include __DIR__ . '/includes/header.php';
?>
<style>
.gallery-page {
    padding: 24px;
    color: #263238;
}

.gallery-page .program-hero {
    background: linear-gradient(135deg, #247a67, #2a9d7f);
    color: #fff;
    border-radius: 16px;
    padding: 28px 30px;
    margin-bottom: 24px;
    box-shadow: 0 8px 24px rgba(36, 122, 103, .15);
}

.gallery-page .program-hero h1 {
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 7px;
}

.gallery-page .program-hero p {
    color: rgba(255,255,255,.85);
    margin-bottom: 0;
}

.gallery-page .btn-kt {
    background: #2a9d7f;
    border: 1px solid #2a9d7f;
    color: #fff;
    border-radius: 8px;
    padding: 10px 18px;
    font-weight: 600;
}

.gallery-page .btn-kt:hover {
    background: #247a67;
    border-color: #247a67;
    color: #fff;
}

.gallery-page .program-hero .btn-kt {
    background: #fff;
    color: #247a67;
    border-color: #fff;
}

.gallery-page .stat-card {
    background: #fff;
    border: 1px solid #dceee8;
    border-radius: 13px;
    padding: 20px;
    height: 100%;
    box-shadow: 0 3px 14px rgba(15,23,42,.035);
}

.gallery-page .stat-label {
    color: #718096;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 8px;
}

.gallery-page .stat-number {
    color: #247a67;
    font-size: 27px;
    font-weight: 700;
}

.gallery-page .stat-icon {
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

.gallery-page .gallery-card {
    background: #fff;
    border: 1px solid #dceee8;
    border-radius: 14px;
    box-shadow: 0 4px 18px rgba(15,23,42,.045);
    overflow: hidden;
}

.gallery-page .gallery-card .card-header {
    background: #fff;
    border-bottom: 1px solid #e5f0ec;
    padding: 20px;
}

.gallery-page .gallery-card .card-footer {
    background: #fff;
    border-top: 1px solid #e5f0ec;
    padding: 16px 20px;
}

.gallery-page .form-label {
    color: #374151;
    font-size: 13px;
    font-weight: 600;
}

.gallery-page .form-control,
.gallery-page .form-select {
    min-height: 43px;
    border: 1px solid #d9e5e0;
    border-radius: 8px;
    box-shadow: none;
}

.gallery-page .form-control:focus,
.gallery-page .form-select:focus {
    border-color: #2a9d7f;
    box-shadow: 0 0 0 3px rgba(42,157,127,.12);
}

.gallery-page .btn-outline-kt {
    color: #247a67;
    border: 1px solid #b8ddd1;
    background: #fff;
    border-radius: 8px;
}

.gallery-page .btn-outline-kt:hover {
    background: #e7f6f0;
    color: #1d6657;
}

.gallery-page .table thead th {
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

.gallery-page .table tbody td {
    padding: 14px 13px;
    border-bottom: 1px solid #edf4f1;
    vertical-align: middle;
}

.gallery-page .table tbody tr:hover {
    background: #f8fcfa;
}

.gallery-thumb,
.gallery-video-preview {
    width: 76px;
    height: 58px;
    border-radius: 9px;
    object-fit: cover;
}

.gallery-video-preview {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #fff0ed;
    color: #d9534f;
    font-size: 25px;
    text-decoration: none;
}

.gallery-description {
    max-width: 260px;
    white-space: normal;
    overflow-wrap: anywhere;
    color: #718096;
    font-size: 13px;
}

.gallery-program-badge {
    display: inline-block;
    max-width: 180px;
    padding: 6px 11px;
    border-radius: 30px;
    font-size: 12px;
    font-weight: 600;
    color: #247a67;
    background: #e7f6f0;
    white-space: normal;
    overflow-wrap: anywhere;
}

.gallery-program-badge.none {
    color: #718096;
    background: #f1f3f5;
}

.gallery-badge {
    display: inline-block;
    padding: 6px 11px;
    border-radius: 30px;
    font-size: 11px;
    font-weight: 700;
}

.gallery-badge.active {
    color: #19734f;
    background: #e1f5e9;
}

.gallery-badge.inactive {
    color: #b54747;
    background: #fde8e8;
}

/* Action buttons: icon + name, so they are readable even if icons fail to load */
.gallery-page .action-buttons {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 6px;
    min-width: 230px;
}

.gallery-page .action-buttons form {
    margin: 0;
}

.gallery-page .action-buttons .btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    width: auto;
    height: auto;
    padding: 6px 11px;
    font-size: 12px;
    font-weight: 600;
    line-height: 1.2;
    white-space: nowrap;
}

.gallery-page .action-buttons .btn i {
    font-size: 12px;
}

.gallery-page .btn-outline-primary {
    color: #247a67;
    border-color: #b8ddd1;
}

.gallery-page .btn-outline-primary:hover {
    background: #247a67;
    color: #fff;
}

/* Programs-style pagination */
.gallery-page .pagination {
    gap: 4px;
    align-items: center;
}

.gallery-page .pagination .page-link {
    color: #247a67;
    border: 1px solid #dceee8;
    border-radius: 7px;
    margin: 0;
    min-width: 35px;
    text-align: center;
    padding: 7px 11px;
    transition: .2s ease;
}

.gallery-page .pagination .page-item.active .page-link {
    background: linear-gradient(135deg, #247a67, #2a9d7f);
    border-color: #247a67;
    color: #fff;
}

.gallery-page .pagination .page-link:hover {
    background: #e7f6f0;
    color: #1d6657;
}

.gallery-page .pagination .page-item.disabled .page-link {
    color: #a0aaa6;
    background: #f5f7f6;
    pointer-events: none;
}

.gallery-empty {
    text-align: center;
    padding: 55px 20px;
    color: #718096;
}

.gallery-empty i {
    color: #83b9a7;
}

.gallery-preview-wrap {
    border: 1px dashed #c8ded5;
    border-radius: 10px;
    min-height: 170px;
    padding: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f8fcfa;
}

.gallery-preview-wrap img {
    max-width: 100%;
    max-height: 240px;
    object-fit: contain;
    border-radius: 8px;
}

.gallery-preview-wrap iframe {
    width: 100%;
    height: 240px;
    border: 0;
    border-radius: 8px;
}

/* Thumbnails of the images selected for upload */
.gallery-preview-wrap .gallery-preview-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    width: 100%;
    max-height: 260px;
    overflow-y: auto;
}

.gallery-preview-wrap .gallery-preview-grid .gp-item {
    width: 92px;
    height: 92px;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid #dceee8;
    background: #fff;
}

.gallery-preview-wrap .gallery-preview-grid img {
    width: 100%;
    height: 100%;
    max-height: none;
    object-fit: cover;
    border-radius: 0;
}

#galleryModal .modal-dialog {
    height: calc(100vh - 2rem);
    max-height: calc(100vh - 2rem);
    margin-top: 1rem;
    margin-bottom: 1rem;
}

#galleryModal .modal-content {
    height: 100%;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border: 0;
    border-radius: 14px;
}

#galleryModal #galleryForm {
    display: flex;
    flex-direction: column;
    flex: 1 1 auto;
    min-height: 0;
    overflow: hidden;
}

#galleryModal .modal-header,
#galleryViewModal .modal-header {
    background: linear-gradient(135deg, #247a67, #2a9d7f);
    color: #fff;
    padding: 18px 24px;
}

#galleryModal .modal-header .btn-close,
#galleryViewModal .modal-header .btn-close {
    filter: brightness(0) invert(1);
}

#galleryModal .modal-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    padding: 24px;
}

#galleryModal .modal-footer {
    flex-shrink: 0;
    background: #f8fcfa;
    border-top: 1px solid #e5f0ec;
    padding: 15px 24px;
}

#galleryViewModal .view-media-image {
    width: 100%;
    max-height: 420px;
    object-fit: contain;
    background: #f8fcfa;
    border-radius: 10px;
}

#galleryViewModal .view-media-frame {
    width: 100%;
    height: 380px;
    border: 0;
    border-radius: 10px;
}

@media (max-width: 767px) {
    .gallery-page {
        padding: 14px;
    }

    .gallery-page .program-hero {
        padding: 22px 18px;
    }

    .gallery-page .program-hero h1 {
        font-size: 23px;
    }

    .gallery-page .gallery-card .card-header,
    .gallery-page .gallery-card .card-footer {
        padding: 15px;
    }
}

@media (max-width: 575px) {
    #galleryModal .modal-dialog {
        height: calc(100vh - 1rem);
        max-height: calc(100vh - 1rem);
        margin: .5rem;
    }

    #galleryModal .modal-body {
        padding: 16px;
    }
}
</style>
<div class="gallery-page">

    <div class="program-hero">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1><i class="fas fa-images me-2"></i> Gallery Management</h1>
                <p>Manage gallery images and videos for each program to keep your community informed.</p>
            </div>

            <button type="button" class="btn btn-kt" id="addGalleryBtn">
                <i class="fas fa-plus me-2"></i> Add Gallery
            </button>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-4 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Matching Gallery Items</div>
                        <div class="stat-number"><?= $totalRecords ?></div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-images"></i></div>
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Active on This Page</div>
                        <div class="stat-number">
                            <?= count(array_filter($galleryItems, fn($item) => ($item['status'] ?? '') === 'active')) ?>
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
                            <?= count(array_filter($galleryItems, fn($item) => ($item['status'] ?? '') === 'inactive')) ?>
                        </div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-eye-slash"></i></div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= galleryEscape($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= galleryEscape($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card gallery-card">

        <div class="card-header">
            <form method="GET" action="gallery.php" class="row g-3 align-items-end">

                <div class="col-lg-3 col-md-6">
                    <label class="form-label" for="gallerySearch">Search Gallery</label>
                    <input id="gallerySearch" type="text" name="search" class="form-control"
                           placeholder="Search title, description or program..."
                           value="<?= galleryEscape($search) ?>">
                </div>

                <div class="col-lg-3 col-md-6">
                    <label class="form-label" for="galleryProgramFilter">Program</label>
                    <select id="galleryProgramFilter" name="program" class="form-select">
                        <option value="all" <?= $programFilter === 'all' ? 'selected' : '' ?>>All Programs</option>
                        <option value="none" <?= $programFilter === 'none' ? 'selected' : '' ?>>No Program</option>
                        <?php foreach ($allPrograms as $programOption): ?>
                            <option value="<?= (int)$programOption['id'] ?>"
                                <?= $programFilter === (string)$programOption['id'] ? 'selected' : '' ?>>
                                <?= galleryEscape($programOption['title']) ?>
                                <?= $programOption['status'] !== 'active' ? ' (inactive)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-2 col-md-3">
                    <label class="form-label" for="galleryTypeFilter">Media Type</label>
                    <select id="galleryTypeFilter" name="type" class="form-select">
                        <option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>>All Types</option>
                        <option value="image" <?= $typeFilter === 'image' ? 'selected' : '' ?>>Images</option>
                        <option value="video" <?= $typeFilter === 'video' ? 'selected' : '' ?>>Videos</option>
                    </select>
                </div>

                <div class="col-lg-2 col-md-3">
                    <label class="form-label" for="galleryStatusFilter">Status</label>
                    <select id="galleryStatusFilter" name="status" class="form-select">
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

                <div class="col-lg-2 d-flex gap-2">
                    <button type="submit" class="btn btn-kt flex-grow-1">
                        <i class="fas fa-search me-1"></i> Search
                    </button>
                    <a href="gallery.php" class="btn btn-outline-kt">Reset</a>
                </div>

            </form>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4">#</th>
                            <th>Preview</th>
                            <th>Program</th>
                            <th>Title</th>
                            <th>Description</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (empty($galleryItems)): ?>
                        <tr>
                            <td colspan="9">
                                <div class="gallery-empty">
                                    <i class="fas fa-images fa-3x mb-3"></i>
                                    <h5>No gallery items found</h5>
                                    <p class="mb-0">Try changing your filters or add a new image or video.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>

                        <?php foreach ($galleryItems as $index => $item): ?>
                            <?php
                            $itemJson = json_encode(
                                $item,
                                JSON_HEX_APOS |
                                JSON_HEX_QUOT |
                                JSON_HEX_TAG |
                                JSON_HEX_AMP
                            );

                            $itemJson = $itemJson === false ? '{}' : $itemJson;
                            $isActive = ($item['status'] ?? '') === 'active';
                            ?>

                            <tr>
                                <td class="ps-4"><?= $offset + $index + 1 ?></td>

                                <td>
                                    <?php if (($item['media_type'] ?? '') === 'image'): ?>
                                        <a href="<?= galleryEscape($item['media_url']) ?>"
                                           target="_blank" rel="noopener noreferrer">
                                            <img src="<?= galleryEscape($item['media_url']) ?>"
                                                 class="gallery-thumb"
                                                 alt="<?= galleryEscape($item['title']) ?>">
                                        </a>
                                    <?php else: ?>
                                        <a href="<?= galleryEscape($item['media_url']) ?>"
                                           target="_blank" rel="noopener noreferrer"
                                           class="gallery-video-preview"
                                           aria-label="Open YouTube video">
                                            <i class="fab fa-youtube"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if (!empty($item['program_title'])): ?>
                                        <span class="gallery-program-badge">
                                            <?= galleryEscape($item['program_title']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="gallery-program-badge none">No program</span>
                                    <?php endif; ?>
                                </td>

                                <td><strong><?= galleryEscape($item['title']) ?></strong></td>

                                <td>
                                    <div class="gallery-description">
                                        <?= galleryEscape($item['description']) ?: '—' ?>
                                    </div>
                                </td>

                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <?= ucfirst(galleryEscape($item['media_type'])) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="gallery-badge <?= galleryEscape($item['status']) ?>">
                                        <?= ucfirst(galleryEscape($item['status'])) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= !empty($item['created_at'])
                                        ? galleryEscape(date('d M Y', strtotime($item['created_at'])))
                                        : '—' ?>
                                </td>

                                <td class="text-end pe-4">
                                    <div class="action-buttons">

                                        <!-- View -->
                                        <button type="button"
                                                class="btn btn-sm btn-outline-info viewGalleryBtn"
                                                data-item="<?= galleryEscape($itemJson) ?>"
                                                title="View Details">
                                            <i class="fas fa-eye"></i>
                                            <span>View</span>
                                        </button>

                                        <!-- Edit -->
                                        <button type="button"
                                                class="btn btn-sm btn-outline-primary editGalleryBtn"
                                                data-item="<?= galleryEscape($itemJson) ?>"
                                                title="Edit">
                                            <i class="fas fa-edit"></i>
                                            <span>Edit</span>
                                        </button>

                                        <!-- Activate / Deactivate -->
                                        <form method="POST" action="gallery.php">
                                            <input type="hidden" name="csrf_token" value="<?= galleryEscape($csrfToken) ?>">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">

                                            <button type="submit"
                                                    class="btn btn-sm <?= $isActive ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                                    title="<?= $isActive ? 'Deactivate' : 'Activate' ?>">
                                                <i class="fas <?= $isActive ? 'fa-toggle-on' : 'fa-toggle-off' ?>"></i>
                                                <span><?= $isActive ? 'Deactivate' : 'Activate' ?></span>
                                            </button>
                                        </form>

                                        <!-- Delete -->
                                        <form method="POST" action="gallery.php" class="deleteGalleryForm">
                                            <input type="hidden" name="csrf_token" value="<?= galleryEscape($csrfToken) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">

                                            <button type="submit"
                                                    class="btn btn-sm btn-outline-danger"
                                                    title="Delete">
                                                <i class="fas fa-trash-alt"></i>
                                                <span>Delete</span>
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

        <!-- Programs-style Pagination -->
        <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-3">

            <small class="text-muted">
                Showing <?= $totalRecords > 0 ? $offset + 1 : 0 ?>
                –<?= min($offset + $perPage, $totalRecords) ?>
                of <?= $totalRecords ?> matching items
            </small>

            <?php if ($totalPages > 1): ?>
                <nav aria-label="Gallery pagination">
                    <ul class="pagination pagination-sm mb-0">

                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link"
                               href="<?= galleryEscape(galleryPageUrl(1, $baseQuery)) ?>"
                               aria-label="First page" title="First page">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                        </li>

                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link"
                               href="<?= galleryEscape(galleryPageUrl(max(1, $page - 1), $baseQuery)) ?>"
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
                                   href="<?= galleryEscape(galleryPageUrl(1, $baseQuery)) ?>">1</a>
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
                                       href="<?= galleryEscape(galleryPageUrl($p, $baseQuery)) ?>">
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
                                   href="<?= galleryEscape(galleryPageUrl($totalPages, $baseQuery)) ?>">
                                    <?= $totalPages ?>
                                </a>
                            </li>
                        <?php endif; ?>

                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link"
                               href="<?= galleryEscape(galleryPageUrl(min($totalPages, $page + 1), $baseQuery)) ?>"
                               aria-label="Next page">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>

                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link"
                               href="<?= galleryEscape(galleryPageUrl($totalPages, $baseQuery)) ?>"
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
<!-- Add / Edit Gallery Modal -->
<div class="modal fade" id="galleryModal" tabindex="-1"
     aria-labelledby="galleryModalLabel" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">

            <form method="POST" action="gallery.php"
                  enctype="multipart/form-data" id="galleryForm">

                <input type="hidden" name="csrf_token"
                       value="<?= galleryEscape($csrfToken) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="galleryId" value="0">

                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold" id="galleryModalLabel">
                            Add Gallery Item
                        </h5>
                        <small class="text-white-50">Choose a program, then add images or a YouTube video</small>
                    </div>

                    <button type="button" class="btn-close"
                            data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">

                    <div class="mb-3">
                        <label for="galleryProgram" class="form-label">
                            Program <span class="text-danger" id="galleryProgramStar">*</span>
                        </label>
                        <select class="form-select" name="program_id"
                                id="galleryProgram" required>
                            <option value="">Select a program</option>
                            <?php foreach ($activePrograms as $program): ?>
                                <option value="<?= (int)$program['id'] ?>">
                                    <?= galleryEscape($program['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">
                            Only active programs are listed.
                        </small>
                    </div>

                    <div class="mb-3">
                        <label for="galleryTitle" class="form-label">
                            Title <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control"
                               name="title" id="galleryTitle"
                               maxlength="255" required
                               placeholder="Enter gallery title">
                    </div>

                    <div class="mb-3">
                        <label for="galleryDescription" class="form-label">
                            Description
                        </label>
                        <textarea class="form-control"
                                  name="description" id="galleryDescription"
                                  rows="3" placeholder="Enter description"></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="galleryMediaType" class="form-label">
                            Media Type <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" name="media_type"
                                id="galleryMediaType" required>
                            <option value="image">Image</option>
                            <option value="video">YouTube Video</option>
                        </select>
                    </div>

                    <div id="galleryImageSection">
                        <div class="mb-3">
                            <label for="galleryImage" class="form-label">
                                <span id="galleryImageLabelText">Select Images</span>
                                <span class="text-danger">*</span>
                            </label>
                            <input type="file" class="form-control"
                                   name="media_files[]" id="galleryImage" multiple
                                   accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                            <small class="text-muted" id="galleryImageHelp">
                                You can select multiple images at once (up to <?= GALLERY_MAX_FILES ?>).
                                JPG, PNG or WEBP, maximum 5 MB each. Each image is added as its own gallery item.
                            </small>
                            <div class="small fw-semibold mt-1" style="color:#247a67"
                                 id="galleryFileCount"></div>
                        </div>

                        <div class="gallery-preview-wrap mb-3">
                            <span class="text-muted" id="galleryImagePlaceholder">
                                <i class="fas fa-image me-2"></i>
                                Image preview will appear here
                            </span>
                            <img src="" alt="Image preview"
                                 id="galleryImagePreview" class="d-none">
                            <div id="galleryImageGrid"
                                 class="gallery-preview-grid d-none"></div>
                        </div>
                    </div>

                    <div id="galleryVideoSection" class="d-none">
                        <div class="mb-3">
                            <label for="galleryVideoUrl" class="form-label">
                                YouTube URL <span class="text-danger">*</span>
                            </label>
                            <input type="url" class="form-control"
                                   name="video_url" id="galleryVideoUrl"
                                   placeholder="https://www.youtube.com/watch?v=XXXXXXXXXXX">
                            <small class="text-muted">
                                Paste a YouTube watch, shorts, youtu.be or embed URL.
                            </small>
                        </div>

                        <div class="gallery-preview-wrap mb-3">
                            <span class="text-muted" id="galleryVideoPlaceholder">
                                <i class="fab fa-youtube me-2"></i>
                                YouTube preview will appear here
                            </span>
                            <iframe id="galleryVideoPreview" class="d-none"
                                    src="" title="YouTube preview"
                                    allowfullscreen loading="lazy"></iframe>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label for="galleryStatus" class="form-label">Status</label>
                        <select class="form-select" name="status" id="galleryStatus">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light border"
                            data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-kt px-4"
                            id="gallerySaveBtn">
                        <i class="fas fa-save me-2"></i> Save Gallery
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>

<!-- View Gallery Modal -->
<div class="modal fade" id="galleryViewModal" tabindex="-1"
     aria-labelledby="galleryViewModalLabel" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 rounded-4 overflow-hidden">

            <div class="modal-header">
                <div>
                    <h5 class="modal-title fw-bold" id="galleryViewModalLabel">
                        <i class="fas fa-eye me-2"></i> Gallery Details
                    </h5>
                    <small class="text-white-50">Preview gallery item</small>
                </div>
                <button type="button" class="btn-close"
                        data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-4">
                <div id="galleryViewMedia" class="mb-4 text-center"></div>
                <h5 id="galleryViewTitle" class="fw-bold"></h5>

                <div class="mb-2">
                    <small class="text-muted">Program:</small>
                    <span class="gallery-program-badge" id="galleryViewProgram"></span>
                </div>

                <p id="galleryViewDescription" class="text-muted"
                   style="white-space:pre-wrap"></p>

                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <span class="badge bg-light text-dark border"
                          id="galleryViewType"></span>
                    <span class="gallery-badge" id="galleryViewStatus"></span>
                    <small class="text-muted ms-auto"
                           id="galleryViewDate"></small>
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

    const MAX_FILES = <?= (int)GALLERY_MAX_FILES ?>;

    const modalElement = document.getElementById('galleryModal');
    const galleryModal = new bootstrap.Modal(modalElement);

    const viewModalElement = document.getElementById('galleryViewModal');
    const galleryViewModal = new bootstrap.Modal(viewModalElement);

    const form = document.getElementById('galleryForm');

    const idInput = document.getElementById('galleryId');
    const programInput = document.getElementById('galleryProgram');
    const programStar = document.getElementById('galleryProgramStar');
    const titleInput = document.getElementById('galleryTitle');
    const descriptionInput = document.getElementById('galleryDescription');
    const mediaTypeInput = document.getElementById('galleryMediaType');
    const statusInput = document.getElementById('galleryStatus');

    const imageInput = document.getElementById('galleryImage');
    const imageLabelText = document.getElementById('galleryImageLabelText');
    const imageHelp = document.getElementById('galleryImageHelp');
    const fileCount = document.getElementById('galleryFileCount');
    const videoInput = document.getElementById('galleryVideoUrl');

    const imageSection = document.getElementById('galleryImageSection');
    const videoSection = document.getElementById('galleryVideoSection');

    const imagePreview = document.getElementById('galleryImagePreview');
    const imageGrid = document.getElementById('galleryImageGrid');
    const imagePlaceholder = document.getElementById('galleryImagePlaceholder');

    const videoPreview = document.getElementById('galleryVideoPreview');
    const videoPlaceholder = document.getElementById('galleryVideoPlaceholder');

    const modalTitle = document.getElementById('galleryModalLabel');
    const saveButton = document.getElementById('gallerySaveBtn');

    const ADD_HELP =
        'You can select multiple images at once (up to ' + MAX_FILES + '). ' +
        'JPG, PNG or WEBP, maximum 5 MB each. Each image is added as its own gallery item.';

    const EDIT_HELP =
        'JPG, PNG or WEBP, maximum 5 MB. Leave empty to keep the existing image.';

    let originalMediaUrl = '';
    let originalMediaType = '';
    let objectUrls = [];

    /*
    |--------------------------------------------------------------------------
    | Add (multiple images) vs Edit (single image)
    |--------------------------------------------------------------------------
    */

    function setUploadMode(isEdit) {
        imageInput.multiple = !isEdit;
        imageLabelText.textContent = isEdit ? 'Select Image' : 'Select Images';
        imageHelp.textContent = isEdit ? EDIT_HELP : ADD_HELP;
    }

    /*
    |--------------------------------------------------------------------------
    | Program dropdown helpers
    |--------------------------------------------------------------------------
    */

    function removeDynamicProgramOptions() {
        programInput
            .querySelectorAll('option[data-dynamic="1"]')
            .forEach(function (option) {
                option.remove();
            });
    }

    function setProgramRequired(isRequired) {
        programInput.required = isRequired;
        programStar.classList.toggle('d-none', !isRequired);
    }

    // When editing an item whose program is no longer active, the program
    // is not in the dropdown. Add it temporarily so the item keeps it.
    function selectProgram(programId, programTitle) {
        removeDynamicProgramOptions();

        if (!programId) {
            programInput.value = '';
            return;
        }

        const value = String(programId);
        let option = Array.from(programInput.options).find(function (o) {
            return o.value === value;
        });

        if (!option) {
            option = document.createElement('option');
            option.value = value;
            option.textContent = (programTitle || ('Program #' + value)) + ' (inactive)';
            option.dataset.dynamic = '1';
            programInput.appendChild(option);
        }

        programInput.value = value;
    }

    /*
    |--------------------------------------------------------------------------
    | Image previews
    |--------------------------------------------------------------------------
    */

    function clearSelectedGrid() {
        objectUrls.forEach(function (url) {
            URL.revokeObjectURL(url);
        });

        objectUrls = [];
        imageGrid.replaceChildren();
        imageGrid.classList.add('d-none');
        fileCount.textContent = '';
    }

    function resetImagePreview() {
        clearSelectedGrid();
        imagePreview.src = '';
        imagePreview.classList.add('d-none');
        imagePlaceholder.classList.remove('d-none');
    }

    // Show thumbnails of every selected file. Returns true if any.
    function renderSelectedPreviews() {
        clearSelectedGrid();

        const files = Array.from(imageInput.files);

        if (!files.length) {
            return false;
        }

        files.forEach(function (file) {
            const url = URL.createObjectURL(file);
            objectUrls.push(url);

            const box = document.createElement('div');
            box.className = 'gp-item';

            const img = document.createElement('img');
            img.src = url;
            img.alt = file.name;

            box.appendChild(img);
            imageGrid.appendChild(box);
        });

        imageGrid.classList.remove('d-none');
        imagePreview.classList.add('d-none');
        imagePlaceholder.classList.add('d-none');

        fileCount.textContent = files.length === 1
            ? '1 image selected'
            : files.length + ' images selected';

        return true;
    }

    function resetVideoPreview() {
        videoPreview.src = '';
        videoPreview.classList.add('d-none');
        videoPlaceholder.classList.remove('d-none');
    }

    function extractYouTubeId(url) {
        try {
            const parsed = new URL(url);
            const host = parsed.hostname.toLowerCase();
            let id = '';

            if (host === 'youtu.be' || host === 'www.youtu.be') {
                id = parsed.pathname.split('/').filter(Boolean)[0] || '';
            } else if ([
                'youtube.com',
                'www.youtube.com',
                'm.youtube.com',
                'music.youtube.com'
            ].includes(host)) {
                if (parsed.pathname === '/watch') {
                    id = parsed.searchParams.get('v') || '';
                } else {
                    const match = parsed.pathname.match(
                        /^\/(?:embed|shorts|live)\/([A-Za-z0-9_-]+)/
                    );

                    if (match) {
                        id = match[1];
                    }
                }
            }

            return /^[A-Za-z0-9_-]{11}$/.test(id) ? id : '';

        } catch (error) {
            return '';
        }
    }

    function updateVideoPreview() {
        const videoId = extractYouTubeId(videoInput.value.trim());

        if (!videoId) {
            resetVideoPreview();
            return;
        }

        videoPreview.src =
            'https://www.youtube-nocookie.com/embed/' + videoId;

        videoPreview.classList.remove('d-none');
        videoPlaceholder.classList.add('d-none');
    }

    function updateMediaSections() {
        const isImage = mediaTypeInput.value === 'image';

        imageSection.classList.toggle('d-none', !isImage);
        videoSection.classList.toggle('d-none', isImage);

        const keepingExistingImage =
            idInput.value !== '0' &&
            originalMediaType === 'image' &&
            isImage &&
            !!originalMediaUrl &&
            !imageInput.files.length;

        imageInput.required = isImage && !keepingExistingImage;
        videoInput.required = !isImage;

        if (isImage) {
            resetVideoPreview();

            if (imageInput.files.length) {
                renderSelectedPreviews();
            } else if (keepingExistingImage) {
                clearSelectedGrid();
                imagePreview.src = originalMediaUrl;
                imagePreview.classList.remove('d-none');
                imagePlaceholder.classList.add('d-none');
            } else {
                resetImagePreview();
            }

        } else {
            resetImagePreview();

            if (
                idInput.value !== '0' &&
                originalMediaType === 'video' &&
                !videoInput.value.trim()
            ) {
                videoInput.value = originalMediaUrl;
            }

            updateVideoPreview();
        }
    }

    function openAddModal() {
        form.reset();

        idInput.value = '0';
        originalMediaUrl = '';
        originalMediaType = '';

        modalTitle.textContent = 'Add Gallery Item';

        saveButton.innerHTML =
            '<i class="fas fa-save me-2"></i> Save Gallery';

        saveButton.disabled = false;

        setUploadMode(false);

        resetImagePreview();
        resetVideoPreview();

        removeDynamicProgramOptions();
        programInput.value = '';
        setProgramRequired(true);

        mediaTypeInput.value = 'image';
        statusInput.value = 'active';

        updateMediaSections();
        galleryModal.show();
    }

    document.getElementById('addGalleryBtn')
        .addEventListener('click', openAddModal);

    /*
    |--------------------------------------------------------------------------
    | Edit Button
    |--------------------------------------------------------------------------
    */

    document.querySelectorAll('.editGalleryBtn').forEach(function (button) {

        button.addEventListener('click', function () {

            let item;

            try {
                item = JSON.parse(this.dataset.item);
            } catch (error) {
                alert('Could not load gallery item.');
                return;
            }

            form.reset();

            idInput.value = item.id;
            titleInput.value = item.title || '';
            descriptionInput.value = item.description || '';
            mediaTypeInput.value = item.media_type || 'image';
            statusInput.value = item.status || 'active';

            // Editing changes one record, so only one image can be chosen.
            setUploadMode(true);

            // Old items without a program may stay without one.
            selectProgram(item.program_id, item.program_title);
            setProgramRequired(!!item.program_id);

            originalMediaUrl = item.media_url || '';
            originalMediaType = item.media_type || '';

            modalTitle.textContent = 'Edit Gallery Item';

            saveButton.innerHTML =
                '<i class="fas fa-save me-2"></i> Update Gallery';

            saveButton.disabled = false;

            if (originalMediaType === 'image') {
                videoInput.value = '';
            } else {
                videoInput.value = originalMediaUrl;
            }

            updateMediaSections();
            galleryModal.show();
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Media Type Change
    |--------------------------------------------------------------------------
    */

    mediaTypeInput.addEventListener('change', function () {

        if (this.value !== originalMediaType) {

            if (this.value === 'image') {
                imageInput.value = '';
                resetImagePreview();
            } else {
                videoInput.value = '';
                resetVideoPreview();
            }

        } else if (this.value === 'video') {
            videoInput.value = originalMediaUrl;
        }

        updateMediaSections();
    });

    /*
    |--------------------------------------------------------------------------
    | Image Selection / Validation (every selected file is checked)
    |--------------------------------------------------------------------------
    */

    imageInput.addEventListener('change', function () {

        const files = Array.from(this.files);

        if (!files.length) {
            updateMediaSections();
            return;
        }

        if (files.length > MAX_FILES) {
            alert('You can upload a maximum of ' + MAX_FILES + ' images at a time.');
            this.value = '';
            updateMediaSections();
            return;
        }

        const allowed = ['image/jpeg', 'image/png', 'image/webp'];

        for (const file of files) {

            if (!allowed.includes(file.type)) {
                alert(file.name + ': only JPG, PNG and WEBP images are allowed.');
                this.value = '';
                updateMediaSections();
                return;
            }

            if (file.size > 5 * 1024 * 1024) {
                alert(file.name + ': image size must not exceed 5 MB.');
                this.value = '';
                updateMediaSections();
                return;
            }
        }

        updateMediaSections();
    });

    videoInput.addEventListener('input', updateVideoPreview);

    /*
    |--------------------------------------------------------------------------
    | Form Validation
    |--------------------------------------------------------------------------
    */

    form.addEventListener('submit', function (event) {

        if (programInput.required && !programInput.value) {
            event.preventDefault();
            alert('Please select a program.');
            programInput.focus();
            return;
        }

        if (mediaTypeInput.value === 'image') {

            const keepingExistingImage =
                idInput.value !== '0' &&
                originalMediaType === 'image' &&
                !!originalMediaUrl &&
                !imageInput.files.length;

            if (!imageInput.files.length && !keepingExistingImage) {
                event.preventDefault();
                alert('Please select at least one image.');
                imageInput.focus();
                return;
            }

        } else {

            if (!extractYouTubeId(videoInput.value.trim())) {
                event.preventDefault();
                alert('Please enter a valid YouTube video URL.');
                videoInput.focus();
                return;
            }
        }

        saveButton.disabled = true;

        saveButton.innerHTML =
            '<span class="spinner-border spinner-border-sm me-2"></span> Saving...';
    });

    /*
    |--------------------------------------------------------------------------
    | Delete Confirmation
    |--------------------------------------------------------------------------
    */

    document.querySelectorAll('.deleteGalleryForm').forEach(function (deleteForm) {

        deleteForm.addEventListener('submit', function (event) {

            if (!confirm(
                'Are you sure you want to delete this gallery item?'
            )) {
                event.preventDefault();
            }
        });
    });

    /*
    |--------------------------------------------------------------------------
    | View Button
    |--------------------------------------------------------------------------
    */

    document.querySelectorAll('.viewGalleryBtn').forEach(function (button) {

        button.addEventListener('click', function () {

            let item;

            try {
                item = JSON.parse(this.dataset.item);
            } catch (error) {
                alert('Could not load gallery details.');
                return;
            }

            const mediaContainer =
                document.getElementById('galleryViewMedia');

            mediaContainer.replaceChildren();

            if (item.media_type === 'image') {

                const image = document.createElement('img');

                image.src = item.media_url || '';
                image.alt = item.title || 'Gallery image';
                image.className = 'view-media-image';

                mediaContainer.appendChild(image);

            } else {

                const videoId = extractYouTubeId(
                    item.media_url || ''
                );

                if (videoId) {

                    const iframe = document.createElement('iframe');

                    iframe.src =
                        'https://www.youtube-nocookie.com/embed/' + videoId;

                    iframe.title = item.title || 'Gallery video';
                    iframe.className = 'view-media-frame';
                    iframe.allowFullscreen = true;
                    iframe.loading = 'lazy';

                    mediaContainer.appendChild(iframe);

                } else {

                    const link = document.createElement('a');

                    link.href = item.media_url || '#';
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                    link.textContent = 'Open video';

                    mediaContainer.appendChild(link);
                }
            }

            document.getElementById('galleryViewTitle').textContent =
                item.title || '';

            const programElement =
                document.getElementById('galleryViewProgram');

            programElement.textContent =
                item.program_title || 'No program';

            programElement.classList.toggle('none', !item.program_title);

            document.getElementById('galleryViewDescription').textContent =
                item.description || 'No description provided.';

            document.getElementById('galleryViewType').textContent =
                (item.media_type || '').toUpperCase();

            const statusElement =
                document.getElementById('galleryViewStatus');

            statusElement.textContent = item.status || '';

            statusElement.className =
                'gallery-badge ' +
                (item.status === 'active' ? 'active' : 'inactive');

            document.getElementById('galleryViewDate').textContent =
                item.created_at || '';

            galleryViewModal.show();
        });
    });

    modalElement.addEventListener('hidden.bs.modal', function () {
        videoPreview.src = '';
        clearSelectedGrid();
        saveButton.disabled = false;
    });

    viewModalElement.addEventListener('hidden.bs.modal', function () {
        document.getElementById('galleryViewMedia').replaceChildren();
    });

});
</script>

<?php
include __DIR__ . '/includes/footer.php';
?>