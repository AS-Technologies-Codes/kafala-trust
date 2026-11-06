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

if (empty($_SESSION['csrf_token_newsletter'])) {
    $_SESSION['csrf_token_newsletter'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token_newsletter'];

function newsletterRedirect(): void
{
    header('Location: newsletter.php');
    exit;
}

function newsletterFlash(string $type, string $message): void
{
    $_SESSION['newsletter_flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function newsletterEscape($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

const NEWSLETTER_DB_TIMEZONE = 'UTC';              // database ka timezone
const NEWSLETTER_DISPLAY_TIMEZONE = 'Asia/Karachi'; // jo timezone dikhana hai

function newsletterFormatDate($value, string $format = 'd M Y, h:i A'): string
{
    if (empty($value)) {
        return '—';
    }

    try {
        $date = new DateTime((string)$value, new DateTimeZone(NEWSLETTER_DB_TIMEZONE));
        $date->setTimezone(new DateTimeZone(NEWSLETTER_DISPLAY_TIMEZONE));

        return $date->format($format);
    } catch (Throwable $e) {
        return '—';
    }
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
        newsletterFlash(
            'danger',
            'Invalid request token. Please refresh the page.'
        );

        newsletterRedirect();
    }

    $action = $_POST['action'] ?? '';

    try {

        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);

        if (!$id || $id < 1) {
            throw new RuntimeException('Invalid subscriber ID.');
        }

        if ($action === 'toggle_status') {

            $stmt = $pdo->prepare(
                "UPDATE newsletter_subscribers
                 SET status = CASE
                        WHEN status = 'subscribed' THEN 'unsubscribed'
                        ELSE 'subscribed'
                     END,
                     subscribed_at = CASE
                        WHEN status = 'unsubscribed' THEN NOW()
                        ELSE subscribed_at
                     END,
                     unsubscribed_at = CASE
                        WHEN status = 'subscribed' THEN NOW()
                        ELSE NULL
                     END
                 WHERE id = ?"
            );

            $stmt->execute([$id]);

            newsletterFlash(
                $stmt->rowCount() > 0 ? 'success' : 'warning',
                $stmt->rowCount() > 0
                    ? 'Subscriber status updated successfully.'
                    : 'No status change was made.'
            );

        } elseif ($action === 'delete') {

            $stmt = $pdo->prepare(
                'DELETE FROM newsletter_subscribers WHERE id = ?'
            );

            $stmt->execute([$id]);

            newsletterFlash(
                $stmt->rowCount() > 0 ? 'success' : 'warning',
                $stmt->rowCount() > 0
                    ? 'Subscriber deleted successfully.'
                    : 'Subscriber was not found.'
            );

        } else {

            throw new RuntimeException('Invalid action.');
        }

    } catch (Throwable $e) {

        error_log('Newsletter error: ' . $e->getMessage());

        newsletterFlash(
            'danger',
            $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to complete the operation. Please check the database.'
        );
    }

    newsletterRedirect();
}

/*
|--------------------------------------------------------------------------
| Search / Filter
|--------------------------------------------------------------------------
*/

$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = $_GET['status'] ?? 'all';

if (!in_array($statusFilter, ['all', 'subscribed', 'unsubscribed'], true)) {
    $statusFilter = 'all';
}

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(email LIKE ? OR name LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

if ($statusFilter !== 'all') {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/*
|--------------------------------------------------------------------------
| CSV Export (uses the current search / status filter)
|--------------------------------------------------------------------------
*/

if (($_GET['export'] ?? '') === 'csv') {

    $exportStmt = $pdo->prepare(
        "SELECT email, name, status, source, ip_address,
                subscribed_at, unsubscribed_at
         FROM newsletter_subscribers
         $whereSql
         ORDER BY subscribed_at DESC, id DESC
         LIMIT 50000"
    );

    $exportStmt->execute($params);

    // Stops spreadsheet programs from running text like =HYPERLINK(...)
    $safeCell = function ($value): string {
        $value = (string)($value ?? '');

        return ($value !== '' && strpos("=+-@\t\r", $value[0]) !== false)
            ? "'" . $value
            : $value;
    };

    header('Content-Type: text/csv; charset=utf-8');
    header(
        'Content-Disposition: attachment; filename="newsletter-subscribers-'
        . date('Y-m-d') . '.csv"'
    );

    $out = fopen('php://output', 'w');

    // UTF-8 BOM so Excel shows names correctly.
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'Email', 'Name', 'Status', 'Source',
        'IP Address', 'Subscribed At', 'Unsubscribed At'
    ]);

    while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $safeCell($row['email']),
            $safeCell($row['name']),
            $row['status'],
            $safeCell($row['source']),
            $row['ip_address'],
            newsletterFormatDate($row['subscribed_at'], 'Y-m-d H:i:s'),
            $row['unsubscribed_at'] ? newsletterFormatDate($row['unsubscribed_at'], 'Y-m-d H:i:s') : ''
        ]);
    }

    fclose($out);
    exit;
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM newsletter_subscribers $whereSql"
);
$countStmt->execute($params);

$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));

$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare(
    "SELECT id, email, name, status, source, ip_address,
            subscribed_at, unsubscribed_at
     FROM newsletter_subscribers
     $whereSql
     ORDER BY subscribed_at DESC, id DESC
     LIMIT $perPage OFFSET $offset"
);

$listStmt->execute($params);

$subscribers = $listStmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Overall statistics (all subscribers, not only this page)
|--------------------------------------------------------------------------
*/

$stats = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status = 'subscribed'), 0) AS subscribed,
        COALESCE(SUM(status = 'unsubscribed'), 0) AS unsubscribed,
        COALESCE(SUM(subscribed_at >= (NOW() - INTERVAL 7 DAY)), 0) AS last_7_days
     FROM newsletter_subscribers"
)->fetch(PDO::FETCH_ASSOC);

$flash = $_SESSION['newsletter_flash'] ?? null;
unset($_SESSION['newsletter_flash']);

$baseQuery = $_GET;
unset($baseQuery['page'], $baseQuery['export']);

function newsletterPageUrl(int $pageNumber, array $baseQuery): string
{
    $baseQuery['page'] = $pageNumber;

    return 'newsletter.php?' . http_build_query($baseQuery);
}

$exportQuery = $baseQuery;
$exportQuery['export'] = 'csv';

$pageTitle = 'Newsletter';
$activePage = 'newsletter';

include __DIR__ . '/includes/header.php';
?>
<style>
.newsletter-page {
    padding: 24px;
    color: #263238;
}

.newsletter-page .program-hero {
    background: linear-gradient(135deg, #247a67, #2a9d7f);
    color: #fff;
    border-radius: 16px;
    padding: 28px 30px;
    margin-bottom: 24px;
    box-shadow: 0 8px 24px rgba(36, 122, 103, .15);
}

.newsletter-page .program-hero h1 {
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 7px;
}

.newsletter-page .program-hero p {
    color: rgba(255,255,255,.85);
    margin-bottom: 0;
}

.newsletter-page .btn-kt {
    background: #2a9d7f;
    border: 1px solid #2a9d7f;
    color: #fff;
    border-radius: 8px;
    padding: 10px 18px;
    font-weight: 600;
}

.newsletter-page .btn-kt:hover {
    background: #247a67;
    border-color: #247a67;
    color: #fff;
}

.newsletter-page .program-hero .btn-kt {
    background: #fff;
    color: #247a67;
    border-color: #fff;
}

.newsletter-page .stat-card {
    background: #fff;
    border: 1px solid #dceee8;
    border-radius: 13px;
    padding: 20px;
    height: 100%;
    box-shadow: 0 3px 14px rgba(15,23,42,.035);
}

.newsletter-page .stat-label {
    color: #718096;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 8px;
}

.newsletter-page .stat-number {
    color: #247a67;
    font-size: 27px;
    font-weight: 700;
}

.newsletter-page .stat-icon {
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

.newsletter-page .newsletter-card {
    background: #fff;
    border: 1px solid #dceee8;
    border-radius: 14px;
    box-shadow: 0 4px 18px rgba(15,23,42,.045);
    overflow: hidden;
}

.newsletter-page .newsletter-card .card-header {
    background: #fff;
    border-bottom: 1px solid #e5f0ec;
    padding: 20px;
}

.newsletter-page .newsletter-card .card-footer {
    background: #fff;
    border-top: 1px solid #e5f0ec;
    padding: 16px 20px;
}

.newsletter-page .form-label {
    color: #374151;
    font-size: 13px;
    font-weight: 600;
}

.newsletter-page .form-control,
.newsletter-page .form-select {
    min-height: 43px;
    border: 1px solid #d9e5e0;
    border-radius: 8px;
    box-shadow: none;
}

.newsletter-page .form-control:focus,
.newsletter-page .form-select:focus {
    border-color: #2a9d7f;
    box-shadow: 0 0 0 3px rgba(42,157,127,.12);
}

.newsletter-page .btn-outline-kt {
    color: #247a67;
    border: 1px solid #b8ddd1;
    background: #fff;
    border-radius: 8px;
}

.newsletter-page .btn-outline-kt:hover {
    background: #e7f6f0;
    color: #1d6657;
}

.newsletter-page .table thead th {
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

.newsletter-page .table tbody td {
    padding: 14px 13px;
    border-bottom: 1px solid #edf4f1;
    vertical-align: middle;
}

.newsletter-page .table tbody tr:hover {
    background: #f8fcfa;
}

.subscriber-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: #e7f6f0;
    color: #247a67;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.subscriber-email {
    font-weight: 600;
    overflow-wrap: anywhere;
}

.newsletter-badge {
    display: inline-block;
    padding: 6px 11px;
    border-radius: 30px;
    font-size: 11px;
    font-weight: 700;
}

.newsletter-badge.subscribed {
    color: #19734f;
    background: #e1f5e9;
}

.newsletter-badge.unsubscribed {
    color: #b54747;
    background: #fde8e8;
}

.newsletter-page .action-buttons .btn {
    height: 33px;
    padding: 0 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 600;
    white-space: nowrap;
}

.newsletter-page .pagination {
    gap: 4px;
    align-items: center;
}

.newsletter-page .pagination .page-link {
    color: #247a67;
    border: 1px solid #dceee8;
    border-radius: 7px;
    margin: 0;
    min-width: 35px;
    text-align: center;
    padding: 7px 11px;
    transition: .2s ease;
}

.newsletter-page .pagination .page-item.active .page-link {
    background: linear-gradient(135deg, #247a67, #2a9d7f);
    border-color: #247a67;
    color: #fff;
}

.newsletter-page .pagination .page-link:hover {
    background: #e7f6f0;
    color: #1d6657;
}

.newsletter-page .pagination .page-item.disabled .page-link {
    color: #a0aaa6;
    background: #f5f7f6;
    pointer-events: none;
}

.newsletter-empty {
    text-align: center;
    padding: 55px 20px;
    color: #718096;
}

.newsletter-empty i {
    color: #83b9a7;
}

@media (max-width: 767px) {
    .newsletter-page { padding: 14px; }
    .newsletter-page .program-hero { padding: 22px 18px; }
    .newsletter-page .program-hero h1 { font-size: 23px; }
    .newsletter-page .newsletter-card .card-header,
    .newsletter-page .newsletter-card .card-footer { padding: 15px; }
}
</style>

<div class="newsletter-page">

    <div class="program-hero">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1><i class="fas fa-envelope-open-text me-2"></i> Newsletter Subscribers</h1>
                <p>People who subscribed to your newsletter from the website.</p>
            </div>

            <a href="newsletter.php?<?= newsletterEscape(http_build_query($exportQuery)) ?>"
               class="btn btn-kt">
                <i class="fas fa-file-csv me-2"></i> Export CSV
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Total Subscribers</div>
                        <div class="stat-number"><?= (int)$stats['total'] ?></div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Subscribed</div>
                        <div class="stat-number"><?= (int)$stats['subscribed'] ?></div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Unsubscribed</div>
                        <div class="stat-number"><?= (int)$stats['unsubscribed'] ?></div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-user-slash"></i></div>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">New in Last 7 Days</div>
                        <div class="stat-number"><?= (int)$stats['last_7_days'] ?></div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-user-plus"></i></div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= newsletterEscape($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= newsletterEscape($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card newsletter-card">

        <div class="card-header">
            <form method="GET" action="newsletter.php" class="row g-3 align-items-end">

                <div class="col-lg-6 col-md-6">
                    <label class="form-label" for="newsletterSearch">Search Subscribers</label>
                    <input id="newsletterSearch" type="text" name="search" class="form-control"
                           placeholder="Search by email or name..."
                           value="<?= newsletterEscape($search) ?>">
                </div>

                <div class="col-lg-3 col-md-3">
                    <label class="form-label" for="newsletterStatusFilter">Status</label>
                    <select id="newsletterStatusFilter" name="status" class="form-select">
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
                        <option value="subscribed" <?= $statusFilter === 'subscribed' ? 'selected' : '' ?>>Subscribed</option>
                        <option value="unsubscribed" <?= $statusFilter === 'unsubscribed' ? 'selected' : '' ?>>Unsubscribed</option>
                    </select>
                </div>

                <div class="col-lg-3 col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-kt flex-grow-1">
                        <i class="fas fa-search me-1"></i> Search
                    </button>
                    <a href="newsletter.php" class="btn btn-outline-kt">Reset</a>
                </div>

            </form>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4">#</th>
                            <th>Subscriber</th>
                            <th>Source</th>
                            <th>Status</th>
                            <th>Subscribed On</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (empty($subscribers)): ?>
                        <tr>
                            <td colspan="6">
                                <div class="newsletter-empty">
                                    <i class="fas fa-envelope-open-text fa-3x mb-3"></i>
                                    <h5>No subscribers found</h5>
                                    <p class="mb-0">Subscribers from the website will appear here.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>

                        <?php foreach ($subscribers as $index => $item): ?>
                            <?php
                            $initial = strtoupper(
                                mb_substr(
                                    trim((string)($item['name'] ?: $item['email'])),
                                    0,
                                    1
                                )
                            );

                            $isSubscribed = $item['status'] === 'subscribed';
                            ?>

                            <tr>
                                <td class="ps-4"><?= $offset + $index + 1 ?></td>

                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <span class="subscriber-avatar"><?= newsletterEscape($initial) ?></span>
                                        <div>
                                            <div class="subscriber-email"
                                                 title="IP: <?= newsletterEscape($item['ip_address'] ?: 'unknown') ?>">
                                                <?= newsletterEscape($item['email']) ?>
                                            </div>
                                            <small class="text-muted">
                                                <?= newsletterEscape($item['name'] ?: 'No name provided') ?>
                                            </small>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <?php if (!empty($item['source'])): ?>
                                        <span class="badge bg-light text-dark border">
                                            <?= newsletterEscape($item['source']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="newsletter-badge <?= newsletterEscape($item['status']) ?>">
                                        <?= ucfirst(newsletterEscape($item['status'])) ?>
                                    </span>
                                    <?php if (!$isSubscribed && !empty($item['unsubscribed_at'])): ?>
                                       <div class="small text-muted mt-1">
                                           <?= newsletterEscape(newsletterFormatDate($item['unsubscribed_at'], 'd M Y')) ?>
                                       </div>
                                    <?php endif; ?>                                   
                                </td>

                                <td class="text-nowrap">
                                         <?= newsletterEscape(newsletterFormatDate($item['subscribed_at'])) ?>
                                 </td>

                                <td class="text-end pe-4">
                                    <div class="d-inline-flex gap-1 action-buttons">

                                        <!-- Subscribe / Unsubscribe -->
                                <form method="POST" action="newsletter.php" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= newsletterEscape($csrfToken) ?>">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">

                                    <button type="submit"
                                            class="btn btn-sm <?= $isSubscribed ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                            title="<?= $isSubscribed ? 'Mark as unsubscribed' : 'Mark as subscribed' ?>">
                                        <i class="fas <?= $isSubscribed ? 'fa-user-slash' : 'fa-user-check' ?>"></i>
                                        <span><?= $isSubscribed ? 'Unsubscribe' : 'Subscribe' ?></span>
                                    </button>
                                </form>

                                <!-- Delete -->
                                 <form method="POST" action="newsletter.php" class="d-inline deleteSubscriberForm">
                                     <input type="hidden" name="csrf_token" value="<?= newsletterEscape($csrfToken) ?>">
                                     <input type="hidden" name="action" value="delete">
                                     <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">

                                     <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
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

        <!-- Pagination -->
        <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-3">

            <small class="text-muted">
                Showing <?= $totalRecords > 0 ? $offset + 1 : 0 ?>
                –<?= min($offset + $perPage, $totalRecords) ?>
                of <?= $totalRecords ?> matching subscribers
            </small>

            <?php if ($totalPages > 1): ?>
                <nav aria-label="Subscribers pagination">
                    <ul class="pagination pagination-sm mb-0">

                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link"
                               href="<?= newsletterEscape(newsletterPageUrl(1, $baseQuery)) ?>"
                               aria-label="First page" title="First page">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                        </li>

                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link"
                               href="<?= newsletterEscape(newsletterPageUrl(max(1, $page - 1), $baseQuery)) ?>"
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
                                   href="<?= newsletterEscape(newsletterPageUrl(1, $baseQuery)) ?>">1</a>
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
                                       href="<?= newsletterEscape(newsletterPageUrl($p, $baseQuery)) ?>">
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
                                   href="<?= newsletterEscape(newsletterPageUrl($totalPages, $baseQuery)) ?>">
                                    <?= $totalPages ?>
                                </a>
                            </li>
                        <?php endif; ?>

                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link"
                               href="<?= newsletterEscape(newsletterPageUrl(min($totalPages, $page + 1), $baseQuery)) ?>"
                               aria-label="Next page">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>

                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link"
                               href="<?= newsletterEscape(newsletterPageUrl($totalPages, $baseQuery)) ?>"
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.deleteSubscriberForm').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!confirm('Are you sure you want to delete this subscriber?')) {
                event.preventDefault();
            }
        });
    });
});
</script>

<?php
include __DIR__ . '/includes/footer.php';
?>