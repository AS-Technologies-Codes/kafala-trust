<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/db.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['dashboard_csrf'])) {
    $_SESSION['dashboard_csrf'] = bin2hex(random_bytes(32));
}

// Allowed program categories (add or remove names here to change the dropdown).
// Keep this list in sync with PROGRAM_CATEGORIES in kifala_api.php.
$programCategories = [
    'Food',
    'Health',
    'Education',
    'Welfare',
    'Social Welfare',
    'Family Support',
    'Legal Assistance'
];

$pdo = getPdoConnection();
$perPage = 10;
$flash = $_SESSION['program_flash'] ?? null;
unset($_SESSION['program_flash']);

// Handle add, update and delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['dashboard_csrf'];

    if (
        !is_string($token) ||
        !hash_equals($sessionToken, $token)
    ) {
        $_SESSION['program_flash'] = [
            'type' => 'danger',
            'message' => 'Session expired. Please refresh and try again.'
        ];
        header('Location: programs.php');
        exit;
    }

    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_program') {
            $id = filter_var(
                $_POST['id'] ?? 0,
                FILTER_VALIDATE_INT
            );

            $title = trim($_POST['title'] ?? '');
            $short = trim($_POST['short_description'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $category = trim((string)($_POST['category'] ?? ''));
            $status = $_POST['status'] ?? 'active';

            // "Current Active Program" checkbox (unchecked = not sent).
            $isFeatured = ($_POST['is_featured'] ?? '') === '1';

            if ($title === '' || $short === '' || $description === '') {
                throw new RuntimeException(
                    'Title, short description and description are required.'
                );
            }

            if ($category === '') {
                throw new RuntimeException('Please select a category.');
            }

            if (!in_array($category, $programCategories, true)) {
                throw new RuntimeException('Invalid category selected.');
            }

            if (
                $id === false || $id < 0 ||
                !in_array($status, ['active', 'inactive'], true)
            ) {
                throw new RuntimeException('Invalid program details.');
            }

            if ($isFeatured && $status !== 'active') {
                throw new RuntimeException(
                    'Only an active program can be set as the current program.'
                );
            }

            $image = null;

            // If editing, retain the existing image unless a new one is uploaded.
            if ($id > 0) {
                $oldImageStmt = $pdo->prepare(
                    "SELECT image FROM programs WHERE id = ?"
                );
                $oldImageStmt->execute([$id]);
                $existingProgram = $oldImageStmt->fetch(PDO::FETCH_ASSOC);

                if (!$existingProgram) {
                    throw new RuntimeException('Program not found.');
                }

                $image = $existingProgram['image'] ?? null;
            }

            if (
                isset($_FILES['image_file']) &&
                $_FILES['image_file']['error'] !== UPLOAD_ERR_NO_FILE
            ) {
                $file = $_FILES['image_file'];

                if ($file['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Image upload failed. Please try again.');
                }

                // Maximum file size: 5 MB
                if ($file['size'] > 5 * 1024 * 1024) {
                    throw new RuntimeException('Image must be smaller than 5 MB.');
                }

                $imageInfo = @getimagesize($file['tmp_name']);

                if ($imageInfo === false) {
                    throw new RuntimeException('Please select a valid image.');
                }

                $mime = $imageInfo['mime'] ?? '';

                $allowedTypes = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                ];

                if (!isset($allowedTypes[$mime])) {
                    throw new RuntimeException(
                        'Only JPG, PNG and WEBP images are allowed.'
                    );
                }

                $uploadDirectory = __DIR__ . '/uploads/programs/';

                if (
                    !is_dir($uploadDirectory) &&
                    !mkdir($uploadDirectory, 0755, true) &&
                    !is_dir($uploadDirectory)
                ) {
                    throw new RuntimeException('Could not create the image upload folder.');
                }

                if (!is_writable($uploadDirectory)) {
                    throw new RuntimeException('Image upload folder is not writable.');
                }

                $fileName = bin2hex(random_bytes(16))
                    . '.' . $allowedTypes[$mime];

                if (!move_uploaded_file(
                    $file['tmp_name'],
                    $uploadDirectory . $fileName
                )) {
                    throw new RuntimeException('Could not save the uploaded image.');
                }

                // Relative path saved in the database
                $image = 'uploads/programs/' . $fileName;
            }

            // Image is mandatory: a new upload, or the existing image when editing.
            if ($image === null || trim((string) $image) === '') {
                throw new RuntimeException('Please attach a program image.');
            }

            if (strlen((string) $image) > 2048) {
                throw new RuntimeException('Image path is too long.');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    "UPDATE programs
                     SET title = ?, short_description = ?,
                         description = ?, image = ?, category = ?, status = ?
                     WHERE id = ?"
                );
                $stmt->execute([
                    $title, $short, $description,
                    $image !== '' ? $image : null, $category, $status, $id
                ]);

                $programId = (int) $id;
                $message = 'Program updated successfully.';
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO programs
                     (title, short_description, description, image, category, status)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $title, $short, $description,
                    $image !== '' ? $image : null, $category, $status
                ]);

                // Must be read right after the INSERT.
                $programId = (int) $pdo->lastInsertId();
                $message = 'Program created successfully.';
            }

            // Only ONE program can be the current (featured) program.
            // A single UPDATE switches the flag atomically.
            if ($isFeatured) {
                $pdo->prepare(
                    'UPDATE programs SET is_featured = (id = ?) WHERE is_featured = 1 OR id = ?'
                )->execute([$programId, $programId]);
            } else {
                $pdo->prepare(
                    'UPDATE programs SET is_featured = 0 WHERE id = ?'
                )->execute([$programId]);
            }

            $_SESSION['program_flash'] = [
                'type' => 'success',
                'message' => $message
            ];

        } elseif ($action === 'delete_program') {
            $id = filter_var(
                $_POST['id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if ($id === false || $id === null || $id < 1) {
                throw new RuntimeException('Invalid program ID.');
            }

            $stmt = $pdo->prepare("DELETE FROM programs WHERE id = ?");
            $stmt->execute([$id]);

            $_SESSION['program_flash'] = [
                'type' => 'success',
                'message' => $stmt->rowCount()
                    ? 'Program deleted successfully.'
                    : 'Program was not found.'
            ];

        } else {
            throw new RuntimeException('Invalid action.');
        }

    } catch (Throwable $ex) {
        error_log('Programs page error: ' . $ex->getMessage());

        $_SESSION['program_flash'] = [
            'type' => 'danger',
            'message' => $ex instanceof RuntimeException
                ? $ex->getMessage()
                : 'Unable to complete the operation. Please check the database.'
        ];
    }

    header('Location: programs.php');
    exit;
}

// Search, status and category filters
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$categoryFilter = (string)($_GET['category'] ?? 'all');

if (!in_array($statusFilter, ['all', 'active', 'inactive'], true)) {
    $statusFilter = 'all';
}

// category filter: all | none (uncategorized) | one of the categories
if (
    $categoryFilter !== 'all' &&
    $categoryFilter !== 'none' &&
    !in_array($categoryFilter, $programCategories, true)
) {
    $categoryFilter = 'all';
}

// Shared filters for count and data queries
$where = " WHERE 1=1 ";
$params = [];

if ($search !== '') {
    $where .= " AND (title LIKE ? OR short_description LIKE ?) ";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

if ($statusFilter !== 'all') {
    $where .= " AND status = ? ";
    $params[] = $statusFilter;
}

if ($categoryFilter === 'none') {
    $where .= " AND (category IS NULL OR category = '') ";
} elseif ($categoryFilter !== 'all') {
    $where .= " AND category = ? ";
    $params[] = $categoryFilter;
}

// Count matching records
$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM programs" . $where
);
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalRecords / $perPage));


$page = filter_var(
    $_GET['page'] ?? 1,
    FILTER_VALIDATE_INT
);
$page = ($page === false || $page < 1) ? 1 : $page;
$page = min($page, $totalPages);

$offset = ($page - 1) * $perPage;

// Fetch only the current page
$sql = "SELECT id, title, short_description, description,
               image, category, status, is_featured, created_at
        FROM programs
        " . $where . "
        ORDER BY created_at DESC, id DESC
        LIMIT ? OFFSET ?";

$stmt = $pdo->prepare($sql);

// Bind search and status filter parameters
foreach ($params as $i => $value) {
    $stmt->bindValue($i + 1, $value, PDO::PARAM_STR);
}

// Bind pagination parameters after filter parameters
$limitPosition = count($params) + 1;
$offsetPosition = count($params) + 2;

$stmt->bindValue($limitPosition, $perPage, PDO::PARAM_INT);
$stmt->bindValue($offsetPosition, $offset, PDO::PARAM_INT);

$stmt->execute();

$programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$activeCount = 0;
$inactiveCount = 0;

foreach ($programs as $program) {
    if (($program['status'] ?? '') === 'active') {
        $activeCount++;
    } else {
        $inactiveCount++;
    }
}

// Edit record
$editProgram = null;
$editId = filter_var(
    $_GET['edit'] ?? null,
    FILTER_VALIDATE_INT
);

if ($editId !== false && $editId !== null && $editId > 0) {
    $editStmt = $pdo->prepare(
        "SELECT id, title, short_description, description, image, category, status, is_featured
         FROM programs WHERE id = ?"
    );
    $editStmt->execute([$editId]);
    $editProgram = $editStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$editProgram) {
        $flash = [
            'type' => 'warning',
            'message' => 'The selected program was not found.'
        ];
    }
}

$pageTitle = 'Programs';
$activePage = 'programs';
require __DIR__ . '/includes/header.php';

// Keep filters when changing pages
$pageUrl = function (int $number) use ($search, $statusFilter, $categoryFilter): string {
    return 'programs.php?' . http_build_query([
        'search' => $search,
        'status' => $statusFilter,
        'category' => $categoryFilter,
        'page' => $number
    ]);
};
?>

<style>
.program-hero {
    background: linear-gradient(120deg, #247a67, #2a9d7f);
    color: #fff;
    padding: 25px;
    border-radius: 17px;
    margin-bottom: 24px;
}
.program-hero h1 { font-size: 25px; font-weight: 750; margin-bottom: 7px; }
.program-hero p { margin: 0; opacity: .9; font-size: 14px; }
.program-stat {
    background: #fff;
    border: 1px solid #dceee8;
    border-radius: 14px;
    padding: 19px;
    height: 100%;
}
.stat-number { font-size: 27px; font-weight: 750; color: #247a67; }
.stat-label { color: #71838a; font-size: 13px; }
.program-card {
    background: #fff;
    border: 1px solid #dceee8;
    border-radius: 15px;
    overflow: hidden;
}
.program-card-head {
    padding: 19px 22px;
    border-bottom: 1px solid #eaf2ef;
}
.program-thumb {
    width: 60px;
    height: 54px;
    border-radius: 9px;
    object-fit: cover;
    background: #e9f7ef;
    border: 1px solid #dceee8;
}
.program-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    color: #247a67;
    font-size: 22px;
}
.program-title { color: #247a67; font-weight: 700; }
.program-description {
    color: #71838a;
    font-size: 12px;
    max-width: 320px;
}
.program-status {
    display: inline-block;
    border-radius: 30px;
    padding: 5px 11px;
    font-size: 12px;
    font-weight: 700;
}
.program-status.active { background: #e4f7ec; color: #217e52; }
.program-status.inactive { background: #eef0f2; color: #5e6870; }
.program-category {
    display: inline-block;
    border-radius: 30px;
    padding: 5px 12px;
    font-size: 12px;
    font-weight: 700;
    background: #e8f1fb;
    color: #2a62a8;
    white-space: nowrap;
}
.program-category.food { background: #fff3e0; color: #b86a00; }
.program-category.health { background: #fde8e8; color: #b54747; }
.program-category.education { background: #e8f1fb; color: #2a62a8; }
.program-category.welfare { background: #e4f7ec; color: #217e52; }
.program-category.socialwelfare { background: #f1e8fb; color: #6a3aa8; }
.program-category.familysupport { background: #e0f4f7; color: #1b7a8a; }
.program-category.legalassistance { background: #fdf3d8; color: #8a6a00; }
.program-category.none { background: #eef0f2; color: #71838a; font-weight: 600; }
.btn-kt { background: #2a9d7f; border-color: #2a9d7f; color: #fff; }
.btn-kt:hover { background: #217e67; border-color: #217e67; color: #fff; }
.program-form-label { font-size: 13px; font-weight: 700; margin-bottom: 7px; }
.program-form .form-control, .program-form .form-select {
    border-color: #dceee8;
    border-radius: 9px;
    padding: 10px 12px;
}
.pagination { margin-bottom: 0; }
.pagination .page-link { color: #247a67; border-color: #dceee8; }
.pagination .page-item.active .page-link {
    background: #2a9d7f; border-color: #2a9d7f; color: #fff;
}
.pagination .page-item.disabled .page-link { color: #a0aaa7; }
@media (max-width: 575px) {
    .program-hero { padding: 20px; }
    .program-hero h1 { font-size: 21px; }
    .program-card-head { padding: 15px; }
}


/* Fix Add/Edit Program modal scrolling */
#programModal .modal-dialog {
    height: calc(100vh - 2rem);
    max-height: calc(100vh - 2rem);
    margin-top: 1rem;
    margin-bottom: 1rem;
}

#programModal .modal-content {
    height: 100%;
    max-height: 100%;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

#programModal .program-form {
    display: flex;
    flex-direction: column;
    flex: 1 1 auto;
    min-height: 0;
    overflow: hidden;
}

#programModal .modal-header,
#programModal .modal-footer {
    flex-shrink: 0;
}

#programModal .modal-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto !important;
    overflow-x: hidden;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
}

@media (max-width: 575px) {
    #programModal .modal-dialog {
        height: calc(100vh - 1rem);
        max-height: calc(100vh - 1rem);
        margin: 0.5rem;
    }
}
</style>

<?php if ($flash): ?>
<div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show">
    <?= e($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<section class="program-hero">
    <div class="d-flex flex-column flex-md-row justify-content-between
                align-items-md-center gap-3">
        <div>
            <h1>Our Programs</h1>
            <p>Manage programs and keep your community informed.</p>
        </div>
        <button class="btn btn-light fw-semibold" type="button"
                data-bs-toggle="modal" data-bs-target="#programModal">
            + Add New Program
        </button>
    </div>
</section>

<section class="row g-3 mb-4">
    <div class="col-12 col-sm-4">
        <div class="program-stat">
            <div class="stat-label">Matching Programs</div>
            <div class="stat-number"><?= e($totalRecords) ?></div>
            <div class="small text-secondary">Across all matching pages</div>
        </div>
    </div>
    <div class="col-6 col-sm-4">
        <div class="program-stat">
            <div class="stat-label">Active on this page</div>
            <div class="stat-number"><?= e($activeCount) ?></div>
        </div>
    </div>
    <div class="col-6 col-sm-4">
        <div class="program-stat">
            <div class="stat-label">Inactive on this page</div>
            <div class="stat-number"><?= e($inactiveCount) ?></div>
        </div>
    </div>
</section>

<section class="program-card">
    <div class="program-card-head">
        <div class="d-flex flex-column flex-lg-row justify-content-between
                    align-items-lg-center gap-3">
            <div>
                <h2 class="h5 fw-bold mb-1">All Programs</h2>
                <div class="small text-secondary">
                    Search, filter, edit and manage programs.
                </div>
            </div>

            <form method="GET" class="d-flex flex-column flex-sm-row gap-2">
                <input type="search" name="search" class="form-control"
                       placeholder="Search programs..."
                       value="<?= e($search) ?>" aria-label="Search programs">

                <select name="category" class="form-select" aria-label="Category">
                    <option value="all" <?= $categoryFilter === 'all' ? 'selected' : '' ?>>All categories</option>
                    <?php foreach ($programCategories as $categoryOption): ?>
                        <option value="<?= e($categoryOption) ?>"
                            <?= $categoryFilter === $categoryOption ? 'selected' : '' ?>>
                            <?= e($categoryOption) ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="none" <?= $categoryFilter === 'none' ? 'selected' : '' ?>>Uncategorized</option>
                </select>

                <select name="status" class="form-select" aria-label="Status">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All statuses</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>

                <button class="btn btn-kt" type="submit">Filter</button>
                <a class="btn btn-outline-secondary" href="programs.php">Reset</a>
            </form>
        </div>
    </div>

    <?php if (!$programs): ?>
        <div class="text-center py-5 px-3">
            <div class="program-placeholder rounded-circle mx-auto mb-3"
                 style="width:68px;height:68px;background:#e9f7ef;">▤</div>
            <h3 class="h5 fw-bold">No programs found</h3>
            <p class="text-secondary small">Try another filter or add a program.</p>
            <button class="btn btn-kt" data-bs-toggle="modal"
                    data-bs-target="#programModal" type="button">
                Add Program
            </button>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Program</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($programs as $program): ?>
                    <?php
                    $image = trim((string)($program['image'] ?? ''));
                    $status = (string)($program['status'] ?? 'inactive');
                    $categoryName = trim((string)($program['category'] ?? ''));
                    $categoryClass = $categoryName !== ''
                        ? strtolower(preg_replace('/[^a-z0-9]+/i', '', $categoryName))
                        : 'none';
                    ?>
                    <tr>
                        <td class="ps-3" style="min-width:250px">
                            <div class="d-flex align-items-center gap-3">
                                <?php if ($image !== ''): ?>
                                    <img class="program-thumb"
                                         src="<?= e($image) ?>" alt="" loading="lazy"
                                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                                    <div class="program-thumb program-placeholder"
                                         style="display:none">▤</div>
                                <?php else: ?>
                                    <div class="program-thumb program-placeholder">▤</div>
                                <?php endif; ?>
                                <div>
                                    <div class="program-title">
                                        <?= e($program['title']) ?>
                                        <?php if (!empty($program['is_featured'])): ?>
                                            <span class="badge bg-warning text-dark ms-1">★ Current</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="program-description">
                                        <?= e(mb_strimwidth((string)$program['short_description'], 0, 90, '...')) ?>
                                    </div>
                                    <div class="small text-secondary mt-1">
                                        ID: <?= e($program['id']) ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="program-category <?= e($categoryClass) ?>">
                                <?= e($categoryName !== '' ? $categoryName : 'Uncategorized') ?>
                            </span>
                        </td>
                        <td>
                            <span class="program-status <?= $status === 'active' ? 'active' : 'inactive' ?>">
                                <?= e(ucfirst($status)) ?>
                            </span>
                        </td>
                        <td class="text-nowrap small text-secondary">
                            <?= e(!empty($program['created_at'])
                                ? date('d M Y', strtotime($program['created_at']))
                                : '-') ?>
                        </td>
                        <td class="text-end pe-3 text-nowrap">
                            <a class="btn btn-sm btn-outline-success"
                               href="programs.php?edit=<?= e($program['id']) ?>">
                                Edit
                            </a>
                            <form method="POST" class="d-inline"
                                  onsubmit="return confirm('Delete this program? This action cannot be undone.');">
                                <input type="hidden" name="csrf_token"
                                       value="<?= e($_SESSION['dashboard_csrf']) ?>">
                                <input type="hidden" name="action" value="delete_program">
                                <input type="hidden" name="id" value="<?= e($program['id']) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php
        $startRecord = $totalRecords ? $offset + 1 : 0;
        $endRecord = min($offset + $perPage, $totalRecords);
        ?>
        <div class="d-flex flex-column flex-md-row justify-content-between
                    align-items-center gap-3 px-3 py-3 border-top">
            <div class="small text-secondary">
                Showing <?= e($startRecord) ?>–<?= e($endRecord) ?>
                of <?= e($totalRecords) ?> programs
                <span class="mx-1">·</span>
                Page <?= e($page) ?> of <?= e($totalPages) ?>
            </div>

            <nav aria-label="Programs pagination">
                <ul class="pagination pagination-sm flex-wrap justify-content-center">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link"
                           href="<?= e($pageUrl(max(1, $page - 1))) ?>"
                           <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
                            Previous
                        </a>
                    </li>

                    <?php
                    $firstPage = max(1, $page - 2);
                    $lastPage = min($totalPages, $page + 2);
                    ?>

                    <?php if ($firstPage > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?= e($pageUrl(1)) ?>">1</a>
                        </li>
                        <?php if ($firstPage > 2): ?>
                            <li class="page-item disabled">
                                <span class="page-link">…</span>
                            </li>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $firstPage; $i <= $lastPage; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e($pageUrl($i)) ?>"
                               <?= $i === $page ? 'aria-current="page"' : '' ?>>
                                <?= e($i) ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($lastPage < $totalPages): ?>
                        <?php if ($lastPage < $totalPages - 1): ?>
                            <li class="page-item disabled">
                                <span class="page-link">…</span>
                            </li>
                        <?php endif; ?>
                        <li class="page-item">
                            <a class="page-link" href="<?= e($pageUrl($totalPages)) ?>">
                                <?= e($totalPages) ?>
                            </a>
                        </li>
                    <?php endif; ?>

                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link"
                           href="<?= e($pageUrl(min($totalPages, $page + 1))) ?>"
                           <?= $page >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
                            Next
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</section>

<!-- Add / Edit Program Modal -->
<div class="modal fade" id="programModal" tabindex="-1"
     aria-labelledby="programModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 rounded-4 shadow">
            <form method="POST" class="program-form" enctype="multipart/form-data">
                <div class="modal-header px-4 py-3">
                    <div>
                        <h2 class="modal-title fs-5 fw-bold" id="programModalTitle">
                            <?= $editProgram ? 'Edit Program' : 'Add New Program' ?>
                        </h2>
                        <div class="small text-secondary">Enter the program details.</div>
                    </div>
                    <button type="button" class="btn-close"
                            data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <input type="hidden" name="csrf_token"
                           value="<?= e($_SESSION['dashboard_csrf']) ?>">
                    <input type="hidden" name="action" value="save_program">
                    <input type="hidden" name="id"
                           value="<?= e($editProgram['id'] ?? 0) ?>">

                    <div class="mb-3">
                        <label for="title" class="program-form-label">Program Title *</label>
                        <input class="form-control" id="title" name="title"
                               maxlength="255" required
                               value="<?= e($editProgram['title'] ?? '') ?>"
                               placeholder="e.g. Education for All">
                    </div>

                    <div class="mb-3">
                        <label for="category" class="program-form-label">Category *</label>
                        <select class="form-select" id="category" name="category" required>
                            <option value="">Select a category</option>
                            <?php foreach ($programCategories as $categoryOption): ?>
                                <option value="<?= e($categoryOption) ?>"
                                    <?= ($editProgram['category'] ?? '') === $categoryOption ? 'selected' : '' ?>>
                                    <?= e($categoryOption) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            Choose what this program is about.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="short_description" class="program-form-label">Short Description *</label>
                        <textarea class="form-control" id="short_description"
                                  name="short_description" rows="2" required><?= e($editProgram['short_description'] ?? '') ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="program-form-label">Full Description *</label>
                        <textarea class="form-control" id="description"
                                  name="description" rows="5" required><?= e($editProgram['description'] ?? '') ?></textarea>
                    </div>


                  <div class="mb-3">
                      <?php $hasExistingImage = !empty($editProgram['image']); ?>
                      <label for="image_file" class="program-form-label">
                          Program Image <span class="text-danger">*</span>
                      </label>

                      <?php if (!empty($editProgram['image'])): ?>
                          <div class="mb-3">
                              <img
                                  src="<?= e($editProgram['image']) ?>"
                                  alt="Current program image"
                                  style="width:150px;height:100px;object-fit:cover;
                                         border-radius:10px;border:1px solid #dceee8;"
                              >
                              <div class="small text-secondary mt-1">
                                  Current image. Select another image to replace it.
                              </div>
                          </div>
                      <?php endif; ?>

                      <input
                          type="file"
                          class="form-control"
                          id="image_file"
                          name="image_file"
                          accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                          <?= $hasExistingImage ? '' : 'required' ?>
                      >

                      <div class="form-text">
                          <?php if ($hasExistingImage): ?>
                              JPG, PNG or WEBP. Maximum size: 5 MB. Leave empty to keep the current image.
                          <?php else: ?>
                              Required. JPG, PNG or WEBP. Maximum size: 5 MB.
                          <?php endif; ?>
                      </div>

                      <div class="mt-3">
                          <img
                              id="imagePreview"
                              alt="Selected image preview"
                              style="display:none;max-width:200px;max-height:140px;
                              object-fit:cover;border-radius:10px;"
                          >
                      </div>
                  </div>

                    <div class="mb-3">
                        <label for="status" class="program-form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="active"
                                <?= ($editProgram['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>
                                Active
                            </option>
                            <option value="inactive"
                                <?= ($editProgram['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>
                                Inactive
                            </option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox"
                                   id="is_featured" name="is_featured" value="1"
                                   <?= !empty($editProgram['is_featured']) ? 'checked' : '' ?>>
                            <label class="form-check-label program-form-label" for="is_featured">
                                Current Active Program (show in website hero section)
                            </label>
                        </div>
                        <div class="form-text">
                            Only one program can be current. Selecting this removes the
                            current mark from the previous program. Only an Active program
                            can be current.
                        </div>
                    </div>
                </div>

                <div class="modal-footer px-4 py-3">
                    <button type="button" class="btn btn-light"
                            data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-kt px-4">
                        <?= $editProgram ? 'Save Changes' : 'Create Program' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('image_file')?.addEventListener('change', function () {
    const preview = document.getElementById('imagePreview');
    const file = this.files && this.files[0];

    if (!file) {
        preview.removeAttribute('src');
        preview.style.display = 'none';
        return;
    }

    preview.src = URL.createObjectURL(file);
    preview.style.display = 'block';
});
</script>

<script>
// Inactive programs cannot be the current program: untick and lock the switch.
(function () {
    const status = document.getElementById('status');
    const featured = document.getElementById('is_featured');

    if (!status || !featured) return;

    function sync() {
        const inactive = status.value !== 'active';

        if (inactive) featured.checked = false;
        featured.disabled = inactive;
    }

    status.addEventListener('change', sync);
    sync();
})();
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    <?php if ($editProgram): ?>
    const modalElement = document.getElementById('programModal');
    if (modalElement && window.bootstrap) {
        bootstrap.Modal.getOrCreateInstance(modalElement).show();
    }
    <?php endif; ?>
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>