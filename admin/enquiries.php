
<?php
/*
|--------------------------------------------------------------------------
| CONTACT ENQUIRIES MANAGEMENT
|--------------------------------------------------------------------------
| List, search, filter, paginate, update status and delete enquiries.
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/config/db.php';

$apiUrl = 'https://kifalatrust.org/admin/kifala_api.php';

$apiToken = getenv('KIFALA_API_TOKEN');

if (!$apiToken && defined('KIFALA_API_TOKEN')) {
    $apiToken = KIFALA_API_TOKEN;
}

/*
|--------------------------------------------------------------------------
| API REQUEST HELPER
|--------------------------------------------------------------------------
*/

function enquiryApiRequest($apiUrl, $action, $method = 'GET', $data = [])
{
    global $apiToken;

    if (empty($apiToken)) {
        return [
            'status' => 'error',
            'message' => 'API token is not configured.',
            'data' => []
        ];
    }

    $method = strtoupper($method);

    if (!in_array($method, ['GET', 'POST'], true)) {
        return [
            'status' => 'error',
            'message' => 'Unsupported HTTP method.',
            'data' => []
        ];
    }

    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . trim($apiToken)
    ];

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false
    ];

    if ($method === 'GET') {
        $url = $apiUrl . '?' . http_build_query(
            array_merge($data, ['action' => $action])
        );
    } else {
        $url = $apiUrl;

        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POSTFIELDS] = http_build_query(
            array_merge($data, ['action' => $action])
        );
    }

    $options[CURLOPT_URL] = $url;

    $ch = curl_init();

    if ($ch === false) {
        return [
            'status' => 'error',
            'message' => 'Unable to initialize cURL.',
            'data' => []
        ];
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($response === false) {
        return [
            'status' => 'error',
            'message' => 'API connection failed: ' . $curlError,
            'data' => []
        ];
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        return [
            'status' => 'error',
            'message' => 'API returned invalid JSON. HTTP status: ' . $httpCode,
            'data' => []
        ];
    }

    if ($httpCode >= 400 && empty($decoded['message'])) {
        $decoded['message'] = 'API request failed. HTTP status: ' . $httpCode;
    }

    return $decoded;
}

/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function enquiryH($value)
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function enquiryFlash($type, $message)
{
    $_SESSION['enquiries_flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function enquiryPageUrl($page)
{
    $params = $_GET;
    $params['page'] = $page;

    return 'enquiries.php?' . http_build_query($params);
}

/*
|--------------------------------------------------------------------------
| HANDLE STATUS UPDATE AND DELETE
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedAction = $_POST['form_action'] ?? '';

    $allowedActions = [
        'update_contact_enquiry_status',
        'delete_contact_enquiry'
    ];

    if (in_array($postedAction, $allowedActions, true)) {
        $payload = [
            'id' => trim((string) ($_POST['enquiry_id'] ?? ''))
        ];

        if ($postedAction === 'update_contact_enquiry_status') {
            $payload['status'] = trim(
                (string) ($_POST['new_status'] ?? '')
            );

            if (!in_array(
                $payload['status'],
                ['new', 'read', 'replied'],
                true
            )) {
                enquiryFlash('danger', 'Please select a valid enquiry status.');

                header('Location: enquiries.php', true, 303);
                exit;
            }
        }

        if (
            filter_var(
                $payload['id'],
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            ) === false
        ) {
            enquiryFlash('danger', 'Invalid enquiry ID.');

            header('Location: enquiries.php', true, 303);
            exit;
        }

        $result = enquiryApiRequest(
            $apiUrl,
            $postedAction,
            'POST',
            $payload
        );

        $success = ($result['status'] ?? '') === 'success';

        enquiryFlash(
            $success ? 'success' : 'danger',
            $result['message'] ??
                ($success
                    ? 'Operation completed successfully.'
                    : 'Operation failed.')
        );

        // Preserve current search, status filter and page.
        $returnParams = [];

        $returnSearch = trim(
            (string) ($_POST['return_search'] ?? '')
        );

        $returnStatus = (string) (
            $_POST['return_status'] ?? 'all'
        );

        $returnPage = filter_var(
            $_POST['return_page'] ?? 1,
            FILTER_VALIDATE_INT
        );

        if ($returnSearch !== '') {
            $returnParams['search'] = $returnSearch;
        }

        if (in_array(
            $returnStatus,
            ['new', 'read', 'replied'],
            true
        )) {
            $returnParams['status'] = $returnStatus;
        }

        if ($returnPage !== false && $returnPage > 1) {
            $returnParams['page'] = (int) $returnPage;
        }

        $redirectUrl = 'enquiries.php';

        if ($returnParams) {
            $redirectUrl .= '?' . http_build_query($returnParams);
        }

        header('Location: ' . $redirectUrl, true, 303);
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| FLASH MESSAGE
|--------------------------------------------------------------------------
*/

$flash = $_SESSION['enquiries_flash'] ?? null;
unset($_SESSION['enquiries_flash']);

/*
|--------------------------------------------------------------------------
| SEARCH, FILTER AND PAGINATION INPUTS
|--------------------------------------------------------------------------
*/

$search = trim((string) ($_GET['search'] ?? ''));

$statusFilter = (string) ($_GET['status'] ?? 'all');

if (!in_array(
    $statusFilter,
    ['all', 'new', 'read', 'replied'],
    true
)) {
    $statusFilter = 'all';
}

$currentPage = filter_input(
    INPUT_GET,
    'page',
    FILTER_VALIDATE_INT
);

$currentPage = ($currentPage && $currentPage > 0)
    ? $currentPage
    : 1;

$perPage = 10;

/*
|--------------------------------------------------------------------------
| FETCH ENQUIRIES
|--------------------------------------------------------------------------
*/

$apiParams = [
    'page' => $currentPage,
    'limit' => $perPage
];

if ($search !== '') {
    $apiParams['search'] = $search;
}

if ($statusFilter !== 'all') {
    $apiParams['status'] = $statusFilter;
}

$result = enquiryApiRequest(
    $apiUrl,
    'get_contact_enquiries',
    'GET',
    $apiParams
);

$enquiries = [];
$actionMessage = null;

$pagination = [
    'current_page' => $currentPage,
    'per_page' => $perPage,
    'total_records' => 0,
    'total_pages' => 0
];

if (
    ($result['status'] ?? '') === 'success' &&
    isset($result['data']) &&
    is_array($result['data'])
) {
    $enquiries = $result['data'];

    if (isset($result['pagination']) && is_array($result['pagination'])) {
        $pagination = array_merge(
            $pagination,
            $result['pagination']
        );
    }
} else {
    $actionMessage = $result['message'] ??
        'Unable to load contact enquiries.';
}

$totalRecords = (int) $pagination['total_records'];
$totalPages = (int) $pagination['total_pages'];
$currentPage = max(1, (int) $pagination['current_page']);

$showingFrom = $totalRecords > 0
    ? (($currentPage - 1) * $perPage) + 1
    : 0;

$showingTo = min(
    $currentPage * $perPage,
    $totalRecords
);

/*
|--------------------------------------------------------------------------
| STATUS HELPERS
|--------------------------------------------------------------------------
*/

function enquiryStatusLabel($status)
{
    $labels = [
        'new' => 'New',
        'read' => 'Read',
        'replied' => 'Replied'
    ];

    return $labels[$status] ?? ucfirst((string) $status);
}

function enquiryStatusClass($status)
{
    $classes = [
        'new' => 'warning',
        'read' => 'primary',
        'replied' => 'success'
    ];

    return $classes[$status] ?? 'secondary';
}

/*
|--------------------------------------------------------------------------
| LOAD ADMIN HEADER
|--------------------------------------------------------------------------
*/

require __DIR__ . '/includes/header.php';
?>

<style>
.enquiries-page {
    padding-bottom: 30px;
}

.enquiries-hero {
    background: linear-gradient(135deg, #116149, #218c68);
    color: #fff;
    border-radius: 16px;
    padding: 26px;
    margin-bottom: 24px;
}

.enquiries-hero h2 {
    font-size: 25px;
    font-weight: 700;
    margin-bottom: 8px;
}

.enquiries-hero p {
    margin: 0;
    opacity: .9;
}

.enquiries-stat,
.enquiries-card {
    border: 0;
    border-radius: 14px;
    box-shadow: 0 4px 18px rgba(0, 0, 0, .06);
}

.enquiries-stat .stat-label {
    color: #6c757d;
    font-size: 13px;
    margin-bottom: 7px;
}

.enquiries-stat .stat-value {
    font-size: 25px;
    font-weight: 700;
    color: #202936;
}

.enquiries-stat .stat-icon {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}

.enquiries-card {
    overflow: hidden;
}

.enquiries-card .card-header {
    background: #fff;
    border-bottom: 1px solid #edf0f2;
    padding: 18px 20px;
}

.enquiries-card .card-body {
    padding: 20px;
}

.enquiries-table {
    vertical-align: middle;
}

.enquiries-table thead th {
    background: #f6f8fa;
    color: #536171;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .35px;
    white-space: nowrap;
    padding: 14px 12px;
}

.enquiries-table tbody td {
    padding: 14px 12px;
}

.enquiry-message {
    max-width: 300px;
    min-width: 180px;
    overflow-wrap: anywhere;
    white-space: normal;
    color: #596575;
    font-size: 13px;
}

.enquiry-message details summary {
    cursor: pointer;
    color: #16734e;
    font-weight: 600;
    margin-top: 5px;
}

.enquiry-message details div {
    white-space: pre-wrap;
    padding-top: 8px;
    max-height: 240px;
    overflow-y: auto;
}

.enquiry-contact {
    min-width: 180px;
    overflow-wrap: anywhere;
}

.enquiry-subject {
    min-width: 170px;
    max-width: 240px;
    overflow-wrap: anywhere;
    font-weight: 600;
}

.enquiry-status-form {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 130px;
}

.enquiry-actions {
    display: flex;
    gap: 6px;
    align-items: center;
}

.enquiry-empty {
    padding: 45px 15px;
    text-align: center;
    color: #7a8591;
}

.enquiry-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    padding-top: 18px;
}

.enquiry-pagination .pagination {
    margin: 0;
    flex-wrap: wrap;
}

.enquiry-pagination .page-link {
    color: #16734e;
}

.enquiry-pagination .page-item.active .page-link {
    background: #16734e;
    border-color: #16734e;
    color: #fff;
}

@media (max-width: 767px) {
    .enquiries-hero {
        padding: 20px;
    }

    .enquiries-hero h2 {
        font-size: 21px;
    }

    .enquiries-card .card-body {
        padding: 12px;
    }

    .enquiry-pagination {
        align-items: flex-start;
    }
}
</style>

<div class="container-fluid enquiries-page">

    <!-- PAGE HEADER -->
    <div class="enquiries-hero">
        <h2>
            <i class="fas fa-envelope-open-text me-2"></i>
            Contact Enquiries
        </h2>

        <p>
            View messages received from visitors, manage enquiry statuses
            and remove unwanted enquiries.
        </p>
    </div>

    <!-- FLASH MESSAGE -->
    <?php if ($flash): ?>
        <div class="alert alert-<?php echo enquiryH($flash['type']); ?> alert-dismissible fade show"
             role="alert">
            <?php echo enquiryH($flash['message']); ?>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                    aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- API ERROR -->
    <?php if ($actionMessage): ?>
        <div class="alert alert-danger" role="alert">
            <strong>Unable to load enquiries.</strong>
            <div><?php echo enquiryH($actionMessage); ?></div>
            <small>
                Check the API URL, token configuration and contact_enquiries table.
            </small>
        </div>
    <?php endif; ?>

    <!-- SUMMARY -->
    <div class="row g-3 mb-4">

        <div class="col-xl-4 col-md-6">
            <div class="card enquiries-stat h-100">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Matching Enquiries</div>
                        <div class="stat-value">
                            <?php echo number_format($totalRecords); ?>
                        </div>
                    </div>

                    <div class="stat-icon bg-success-subtle text-success">
                        <i class="fas fa-envelope"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6">
            <div class="card enquiries-stat h-100">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Current Page</div>
                        <div class="stat-value">
                            <?php echo $totalRecords > 0 ? $currentPage : 0; ?>
                            <span class="text-muted fs-6">
                                / <?php echo $totalPages; ?>
                            </span>
                        </div>
                    </div>

                    <div class="stat-icon bg-primary-subtle text-primary">
                        <i class="fas fa-file-lines"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6">
            <div class="card enquiries-stat h-100">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Showing on This Page</div>
                        <div class="stat-value">
                            <?php echo count($enquiries); ?>
                        </div>
                    </div>

                    <div class="stat-icon bg-warning-subtle text-warning">
                        <i class="fas fa-list-check"></i>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- ENQUIRIES TABLE -->
    <div class="card enquiries-card">

        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="mb-1 fw-bold">Visitor Messages</h5>
                <small class="text-muted">
                    Search enquiries, read messages and update their status.
                </small>
            </div>

            <span class="badge bg-success-subtle text-success">
                <?php echo number_format($totalRecords); ?> result(s)
            </span>
        </div>

        <div class="card-body">

            <!-- SEARCH AND FILTER -->
            <form method="GET" action="enquiries.php" class="row g-3 mb-4">

                <div class="col-lg-6 col-md-6">
                    <label for="enquirySearch" class="form-label fw-semibold">
                        Search enquiries
                    </label>

                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-search"></i>
                        </span>

                        <input type="text"
                               id="enquirySearch"
                               name="search"
                               class="form-control"
                               placeholder="Name, email, phone, subject or message..."
                               value="<?php echo enquiryH($search); ?>">
                    </div>
                </div>

                <div class="col-lg-3 col-md-4">
                    <label for="enquiryStatus" class="form-label fw-semibold">
                        Enquiry Status
                    </label>

                    <select name="status"
                            id="enquiryStatus"
                            class="form-select">

                        <option value="all"
                            <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>
                            All Statuses
                        </option>

                        <option value="new"
                            <?php echo $statusFilter === 'new' ? 'selected' : ''; ?>>
                            New
                        </option>

                        <option value="read"
                            <?php echo $statusFilter === 'read' ? 'selected' : ''; ?>>
                            Read
                        </option>

                        <option value="replied"
                            <?php echo $statusFilter === 'replied' ? 'selected' : ''; ?>>
                            Replied
                        </option>

                    </select>
                </div>

                <div class="col-lg-3 col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-filter me-1"></i>Filter
                    </button>

                    <a href="enquiries.php"
                       class="btn btn-outline-secondary">
                        <i class="fas fa-rotate-left me-1"></i>Reset
                    </a>
                </div>

            </form>

            <!-- TABLE -->
            <div class="table-responsive">
                <table class="table table-hover enquiries-table">

                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Contact</th>
                            <th>Subject & Message</th>
                            <th>Status</th>
                            <th>Received At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (empty($enquiries)): ?>

                        <tr>
                            <td colspan="6">
                                <div class="enquiry-empty">
                                    <i class="fas fa-inbox fa-3x mb-3"></i>

                                    <h6 class="fw-bold">
                                        No enquiries found
                                    </h6>

                                    <p class="mb-0">
                                        Try another search term or status filter.
                                    </p>
                                </div>
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($enquiries as $index => $item): ?>

                            <?php
                                $id = (int) ($item['id'] ?? 0);
                                $name = (string) ($item['name'] ?? '');
                                $email = (string) ($item['email'] ?? '');
                                $phone = (string) ($item['phone'] ?? '');
                                $subject = (string) ($item['subject'] ?? '');
                                $message = (string) ($item['message'] ?? '');

                                $status = (string) ($item['status'] ?? 'new');

                                if (!in_array(
                                    $status,
                                    ['new', 'read', 'replied'],
                                    true
                                )) {
                                    $status = 'new';
                                }

                                $createdAt = (string) ($item['created_at'] ?? '');
                                $timestamp = $createdAt !== ''
                                    ? strtotime($createdAt)
                                    : false;
                            ?>

                            <tr>

                                <td>
                                    <?php echo (($currentPage - 1) * $perPage) + $index + 1; ?>
                                </td>

                                <!-- CONTACT DETAILS -->
                                <td>
                                    <div class="enquiry-contact">
                                        <div class="fw-semibold mb-1">
                                            <i class="fas fa-user text-muted me-1"></i>
                                            <?php echo enquiryH($name ?: 'Unknown'); ?>
                                        </div>

                                        <?php if ($email !== ''): ?>
                                            <div class="small mb-1">
                                                <i class="fas fa-envelope text-muted me-1"></i>

                                                <a href="mailto:<?php echo enquiryH($email); ?>">
                                                    <?php echo enquiryH($email); ?>
                                                </a>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($phone !== ''): ?>
                                            <div class="small text-muted">
                                                <i class="fas fa-phone text-muted me-1"></i>
                                                <?php echo enquiryH($phone); ?>
                                            </div>
                                        <?php endif; ?>

                                        <small class="text-muted">
                                            ID: <?php echo $id; ?>
                                        </small>
                                    </div>
                                </td>

                                <!-- SUBJECT AND MESSAGE -->
                                <td>
                                    <div class="enquiry-subject mb-2">
                                        <?php echo enquiryH($subject ?: 'No subject'); ?>
                                    </div>

                                    <div class="enquiry-message">
                                        <?php
                                            $preview = trim($message);
                                            if (mb_strlen($preview) > 100) {
                                                $preview = mb_substr($preview, 0, 100) . '...';
                                            }
                                            echo nl2br(enquiryH($preview));
                                        ?>

                                        <?php if (trim($message) !== '' && mb_strlen($message) > 100): ?>
                                            <details>
                                                <summary>Read full message</summary>
                                                <div><?php echo enquiryH($message); ?></div>
                                            </details>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- CURRENT STATUS -->
                                <td>
                                    <span class="badge bg-<?php echo enquiryStatusClass($status); ?>-subtle text-<?php echo enquiryStatusClass($status); ?>">
                                        <?php echo enquiryH(enquiryStatusLabel($status)); ?>
                                    </span>
                                </td>

                                <!-- DATE -->
                                <td class="text-nowrap">
                                    <?php if ($timestamp !== false): ?>
                                        <div>
                                            <?php echo date('d M Y', $timestamp); ?>
                                        </div>

                                        <small class="text-muted">
                                            <?php echo date('h:i A', $timestamp); ?>
                                        </small>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>

                                <!-- ACTIONS -->
                                <td>
                                    <div class="d-flex flex-column gap-2">

                                        <!-- UPDATE STATUS -->
                                        <form method="POST"
                                              action="enquiries.php"
                                              class="enquiry-status-form">

                                            <input type="hidden"
                                                   name="form_action"
                                                   value="update_contact_enquiry_status">

                                            <input type="hidden"
                                                   name="enquiry_id"
                                                   value="<?php echo $id; ?>">

                                            <input type="hidden"
                                                   name="return_search"
                                                   value="<?php echo enquiryH($search); ?>">

                                            <input type="hidden"
                                                   name="return_status"
                                                   value="<?php echo enquiryH($statusFilter); ?>">

                                            <input type="hidden"
                                                   name="return_page"
                                                   value="<?php echo $currentPage; ?>">

                                            <select name="new_status"
                                                    class="form-select form-select-sm"
                                                    aria-label="Update enquiry status">

                                                <option value="new"
                                                    <?php echo $status === 'new' ? 'selected' : ''; ?>>
                                                    New
                                                </option>

                                                <option value="read"
                                                    <?php echo $status === 'read' ? 'selected' : ''; ?>>
                                                    Read
                                                </option>

                                                <option value="replied"
                                                    <?php echo $status === 'replied' ? 'selected' : ''; ?>>
                                                    Replied
                                                </option>

                                            </select>

                                            <button type="submit"
                                                    class="btn btn-sm btn-outline-success">
                                                <i class="fas fa-save me-1"></i>Update
                                            </button>

                                        </form>

                                        <!-- DELETE -->
                                        <form method="POST"
                                              action="enquiries.php"
                                              onsubmit="return confirm('Are you sure you want to permanently delete this enquiry?');">

                                            <input type="hidden"
                                                   name="form_action"
                                                   value="delete_contact_enquiry">

                                            <input type="hidden"
                                                   name="enquiry_id"
                                                   value="<?php echo $id; ?>">

                                            <input type="hidden"
                                                   name="return_search"
                                                   value="<?php echo enquiryH($search); ?>">

                                            <input type="hidden"
                                                   name="return_status"
                                                   value="<?php echo enquiryH($statusFilter); ?>">

                                            <input type="hidden"
                                                   name="return_page"
                                                   value="<?php echo $currentPage; ?>">

                                            <button type="submit"
                                                    class="btn btn-sm btn-outline-danger w-100">
                                                <i class="fas fa-trash me-1"></i>Delete
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

            <!-- PAGINATION -->
            <?php if ($totalRecords > 0): ?>

                <div class="enquiry-pagination">

                    <div class="text-muted small">
                        Showing
                        <strong><?php echo $showingFrom; ?></strong>
                        to
                        <strong><?php echo $showingTo; ?></strong>
                        of
                        <strong><?php echo $totalRecords; ?></strong>
                        enquiries
                    </div>

                    <?php if ($totalPages > 1): ?>

                        <nav aria-label="Enquiry pagination">
                            <ul class="pagination pagination-sm">

                                <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link"
                                       href="<?php echo enquiryH(enquiryPageUrl(1)); ?>">
                                        First
                                    </a>
                                </li>

                                <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link"
                                       href="<?php echo enquiryH(enquiryPageUrl(max(1, $currentPage - 1))); ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>

                                <?php
                                    $startPage = max(1, $currentPage - 2);
                                    $endPage = min($totalPages, $currentPage + 2);
                                ?>

                                <?php if ($startPage > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link"
                                           href="<?php echo enquiryH(enquiryPageUrl(1)); ?>">
                                            1
                                        </a>
                                    </li>

                                    <?php if ($startPage > 2): ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">...</span>
                                        </li>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                                    <li class="page-item <?php echo $p === $currentPage ? 'active' : ''; ?>">
                                        <a class="page-link"
                                           href="<?php echo enquiryH(enquiryPageUrl($p)); ?>">
                                            <?php echo $p; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($endPage < $totalPages): ?>

                                    <?php if ($endPage < $totalPages - 1): ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">...</span>
                                        </li>
                                    <?php endif; ?>

                                    <li class="page-item">
                                        <a class="page-link"
                                           href="<?php echo enquiryH(enquiryPageUrl($totalPages)); ?>">
                                            <?php echo $totalPages; ?>
                                        </a>
                                    </li>

                                <?php endif; ?>

                                <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link"
                                       href="<?php echo enquiryH(enquiryPageUrl(min($totalPages, $currentPage + 1))); ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>

                                <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link"
                                       href="<?php echo enquiryH(enquiryPageUrl($totalPages)); ?>">
                                        Last
                                    </a>
                                </li>

                            </ul>
                        </nav>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
