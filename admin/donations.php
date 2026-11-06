<?php
/*
|--------------------------------------------------------------------------
| DONATION MANAGEMENT
|--------------------------------------------------------------------------
| API authentication, listing, filtering, pagination, add, update and delete.
| Includes: preset donation amounts + custom (donor-entered) amount settings
| + payment types (Bank Transfer) + shared (default) bank account details
| + OPTIONAL separate bank account for each donation.
*/

// Start session before any HTML output.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Load server configuration so the API token constant is available if defined.
require_once __DIR__ . '/config/db.php';

$apiUrl = 'https://kifalatrust.org/admin/kifala_api.php';

// Use the same token configuration as kifala_api.php.
$apiToken = getenv('KIFALA_API_TOKEN');

if (!$apiToken && defined('KIFALA_API_TOKEN')) {
    $apiToken = KIFALA_API_TOKEN;
}

const DONATION_DEFAULT_PRESETS = '100,200,500,1000,10000';
const DONATION_DEFAULT_PAYMENT_TYPES = 'bank_transfer';

// Bank form fields: name => [label, required].
// Used by BOTH the default "Bank Details" modal and the per-donation form.
$bankFields = [
    'bank_name'      => ['Bank Name', true],
    'account_title'  => ['Account Title', true],
    'account_number' => ['Account Number', true],
    'iban'           => ['IBAN', false],
    'branch_name'    => ['Branch Name', false],
    'swift_code'     => ['SWIFT Code', false]
];

/*
|--------------------------------------------------------------------------
| API REQUEST
|--------------------------------------------------------------------------
*/

function donationApiRequest($apiUrl, $action, $method = 'GET', $data = [])
{
    global $apiToken;

    $method = strtoupper($method);

    if (empty($apiToken)) {
        return [
            'status' => 'error',
            'message' => 'API token is not configured. Check KIFALA_API_TOKEN in the server configuration.',
            'data' => []
        ];
    }

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

    $url = $apiUrl;

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false
    ];

    if ($method === 'GET') {
        $query = array_merge($data, ['action' => $action]);
        $url .= '?' . http_build_query($query);
    } else {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';

        $options[CURLOPT_HTTPHEADER] = $headers;
        // http_build_query also handles nested arrays: custom_bank[bank_name]=...
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

    if ($httpCode >= 400) {
        if (empty($decoded['message'])) {
            $decoded['message'] = 'API request failed. HTTP status: ' . $httpCode;
        }

        $decoded['status'] = $decoded['status'] ?? 'error';
    }

    return $decoded;
}

/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function donationH($value)
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function donationMoney($value)
{
    return number_format((float) ($value ?? 0), 2);
}

function donationFlash($type, $message)
{
    $_SESSION['donation_flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function donationPageUrl($page)
{
    $params = $_GET;
    $params['page'] = $page;

    return 'donations.php?' . http_build_query($params);
}

/*
|--------------------------------------------------------------------------
| FLASH MESSAGE
|--------------------------------------------------------------------------
*/

$flash = $_SESSION['donation_flash'] ?? null;
unset($_SESSION['donation_flash']);

$actionMessage = null;
$bankDetails = [];

/*
|--------------------------------------------------------------------------
| HANDLE ADD, UPDATE, DELETE AND BANK DETAILS
|--------------------------------------------------------------------------
| Process POST before including header.php to avoid redirect/header issues.
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedAction = $_POST['form_action'] ?? '';

    $allowedActions = [
        'add_donation',
        'update_donation',
        'delete_donation',
        'save_bank_details'
    ];

    if (in_array($postedAction, $allowedActions, true)) {
        $payload = [];

        if (
            $postedAction === 'add_donation' ||
            $postedAction === 'update_donation'
        ) {
            $payload = [
                'program_id' => trim((string) ($_POST['program_id'] ?? '')),
                'donation_heading' => trim(
                    (string) ($_POST['donation_heading'] ?? '')
                ),
                'total_donations' => trim(
                    (string) ($_POST['total_donations'] ?? '')
                ),
                'raise_donations' => trim(
                    (string) ($_POST['raise_donations'] ?? '')
                ),
                'preset_amounts' => trim(
                    (string) ($_POST['preset_amounts'] ?? '')
                ),
                // Unchecked checkbox is not sent by the browser.
                'allow_custom_amount' => isset($_POST['allow_custom_amount'])
                    ? '1'
                    : '0',
                'min_custom_amount' => trim(
                    (string) ($_POST['min_custom_amount'] ?? '')
                ),
                // Checkbox group: payment_types[] => "bank_transfer,..."
                'payment_types' => implode(
                    ',',
                    array_map('strval', (array) ($_POST['payment_types'] ?? []))
                ),
                // Separate bank account for this donation (optional).
                // OFF => the API removes the own account and uses the default.
                'use_custom_bank' => isset($_POST['use_custom_bank']) ? '1' : '0',
                'custom_bank' => array_map(
                    function ($v) {
                        return trim((string) $v);
                    },
                    (array) ($_POST['custom_bank'] ?? [])
                )
            ];

            if ($postedAction === 'update_donation') {
                $payload['donation_id'] = trim(
                    (string) ($_POST['donation_id'] ?? '')
                );
            }
        }

        if ($postedAction === 'delete_donation') {
            $payload = [
                'donation_id' => trim(
                    (string) ($_POST['donation_id'] ?? '')
                )
            ];
        }

        if ($postedAction === 'save_bank_details') {
            foreach ([
                'bank_name',
                'account_title',
                'account_number',
                'iban',
                'branch_name',
                'swift_code',
                'instructions'
            ] as $bankField) {
                $payload[$bankField] = trim((string) ($_POST[$bankField] ?? ''));
            }
        }

        $result = donationApiRequest(
            $apiUrl,
            $postedAction,
            'POST',
            $payload
        );

        $success = ($result['status'] ?? '') === 'success';

        $message = $result['message'] ??
            ($success
                ? 'Operation completed successfully.'
                : 'Operation failed.');

        donationFlash(
            $success ? 'success' : 'danger',
            $message
        );

        header('Location: donations.php', true, 303);
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| FETCH DONATIONS
|--------------------------------------------------------------------------
*/

$result = donationApiRequest($apiUrl, 'admin_get_donations');

$donations = [];

if (
    ($result['status'] ?? '') === 'success' &&
    isset($result['data']) &&
    is_array($result['data'])
) {
    $donations = $result['data'];

    // Top-level bank_details = the DEFAULT account.
    $bankDetails = is_array($result['bank_details'] ?? null)
        ? $result['bank_details']
        : [];
} else {
    $actionMessage = $result['message'] ??
        'Unable to load donation records.';
}

/*
|--------------------------------------------------------------------------
| SEARCH AND FILTER
|--------------------------------------------------------------------------
*/

$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? 'all');

$validFilters = ['all', 'configured', 'not_configured'];

if (!in_array($statusFilter, $validFilters, true)) {
    $statusFilter = 'all';
}

$filteredDonations = array_filter(
    $donations,
    function ($item) use ($search, $statusFilter) {
        $title = (string) ($item['program_title'] ?? '');
        $heading = (string) ($item['donation_heading'] ?? '');
        $programId = (string) ($item['program_id'] ?? '');

        $matchesSearch = $search === '' ||
            stripos($title, $search) !== false ||
            stripos($heading, $search) !== false ||
            stripos($programId, $search) !== false;

        $hasDonation = !empty($item['donation_id']);

        if ($statusFilter === 'configured') {
            $matchesStatus = $hasDonation;
        } elseif ($statusFilter === 'not_configured') {
            $matchesStatus = !$hasDonation;
        } else {
            $matchesStatus = true;
        }

        return $matchesSearch && $matchesStatus;
    }
);

$filteredDonations = array_values($filteredDonations);

/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/

$perPage = 10;
$totalRecords = count($filteredDonations);
$totalPages = max(1, (int) ceil($totalRecords / $perPage));

$currentPage = filter_input(
    INPUT_GET,
    'page',
    FILTER_VALIDATE_INT
);

$currentPage = ($currentPage && $currentPage > 0)
    ? $currentPage
    : 1;

$currentPage = min($currentPage, $totalPages);

$offset = ($currentPage - 1) * $perPage;

$pageDonations = array_slice(
    $filteredDonations,
    $offset,
    $perPage
);

$showingFrom = $totalRecords > 0 ? $offset + 1 : 0;
$showingTo = min($offset + $perPage, $totalRecords);

/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/

$totalPrograms = count($donations);
$configuredCount = 0;
$notConfiguredCount = 0;
$ownBankCount = 0;
$totalTarget = 0;
$totalRaised = 0;

foreach ($donations as $item) {
    if (!empty($item['donation_id'])) {
        $configuredCount++;
        $totalTarget += (float) ($item['total_donations'] ?? 0);
        $totalRaised += (float) ($item['raise_donations'] ?? 0);

        if (!empty($item['has_custom_bank'])) {
            $ownBankCount++;
        }
    } else {
        $notConfiguredCount++;
    }
}

$overallPercentage = $totalTarget > 0
    ? round(($totalRaised / $totalTarget) * 100, 2)
    : 0;

// Include the page header only after POST redirects have been handled.
require __DIR__ . '/includes/header.php';
?>

<style>
.donation-page {
    padding-bottom: 30px;
}

.donation-hero {
    background: linear-gradient(135deg, #116149, #218c68);
    border-radius: 16px;
    color: #fff;
    padding: 26px;
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 18px;
    flex-wrap: wrap;
}

.donation-hero h2 {
    margin: 0 0 8px;
    font-size: 25px;
    font-weight: 700;
}

.donation-hero p {
    margin: 0;
    opacity: .9;
}

.donation-stat,
.donation-card {
    border: 0;
    border-radius: 14px;
    box-shadow: 0 4px 18px rgba(0, 0, 0, .06);
}

.donation-stat {
    height: 100%;
}

.donation-stat .stat-label {
    color: #6c757d;
    font-size: 13px;
    margin-bottom: 8px;
}

.donation-stat .stat-value {
    color: #202936;
    font-size: 25px;
    font-weight: 700;
    overflow-wrap: anywhere;
}

.donation-stat .stat-icon {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}

.donation-card {
    overflow: hidden;
}

.donation-card .card-header {
    background: #fff;
    border-bottom: 1px solid #edf0f2;
    padding: 18px 20px;
}

.donation-card .card-body {
    padding: 20px;
}

.donation-table {
    vertical-align: middle;
}

.donation-table thead th {
    background: #f6f8fa;
    color: #536171;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .4px;
    white-space: nowrap;
    padding: 14px 12px;
}

.donation-table tbody td {
    padding: 14px 12px;
}

.program-image {
    width: 56px;
    height: 56px;
    object-fit: cover;
    border-radius: 10px;
    border: 1px solid #e7eaee;
    background: #f8f9fa;
}

.program-image-placeholder {
    width: 56px;
    height: 56px;
    flex-shrink: 0;
    border-radius: 10px;
    background: #e9f5ef;
    color: #16734e;
    display: flex;
    justify-content: center;
    align-items: center;
    font-size: 21px;
}

.donation-progress {
    height: 8px;
    min-width: 100px;
    background: #e9ecef;
    border-radius: 20px;
    overflow: hidden;
}

.donation-progress .progress-bar {
    border-radius: 20px;
}

.donation-actions {
    display: flex;
    gap: 6px;
    align-items: center;
    flex-wrap: nowrap;
}

.donation-actions .btn {
    min-width: 34px;
}

.donation-empty {
    padding: 45px 15px;
    text-align: center;
    color: #7a8591;
}

.donation-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    padding-top: 18px;
}

.donation-pagination .pagination {
    margin: 0;
    flex-wrap: wrap;
}

.donation-pagination .page-link {
    color: #16734e;
}

.donation-pagination .page-item.active .page-link {
    background: #16734e;
    border-color: #16734e;
    color: #fff;
}

.donation-modal-label {
    font-weight: 600;
    font-size: 13px;
    margin-bottom: 7px;
}

.donation-required {
    color: #dc3545;
}

.preset-chip {
    display: inline-block;
    padding: 5px 11px;
    border-radius: 30px;
    background: #fff;
    border: 1px solid #d5e3dc;
    color: #16734e;
    font-size: 12px;
    font-weight: 600;
}

.bank-source-note {
    font-size: 12px;
    line-height: 1.5;
    margin-top: 6px;
}

/* Fix modal scrolling (Donation + Bank Details forms) */
#donationModal .modal-dialog,
#bankModal .modal-dialog {
    height: calc(100vh - 2rem);
    max-height: calc(100vh - 2rem);
    margin-top: 1rem;
    margin-bottom: 1rem;
}

#donationModal .modal-content,
#bankModal .modal-content {
    height: 100%;
    max-height: 100%;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* The <form> sits between modal-content and modal-body, so it must also flex. */
#donationModal form,
#bankModal form {
    display: flex;
    flex-direction: column;
    flex: 1 1 auto;
    min-height: 0;
    overflow: hidden;
}

#donationModal .modal-header,
#donationModal .modal-footer,
#bankModal .modal-header,
#bankModal .modal-footer {
    flex-shrink: 0;
}

#donationModal .modal-body,
#bankModal .modal-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto !important;
    overflow-x: hidden;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
}

@media (max-width: 575px) {
    #donationModal .modal-dialog,
    #bankModal .modal-dialog {
        height: calc(100vh - 1rem);
        max-height: calc(100vh - 1rem);
        margin: 0.5rem;
    }
}

@media (max-width: 767px) {
    .donation-hero {
        padding: 20px;
    }

    .donation-hero h2 {
        font-size: 21px;
    }

    .donation-stat .stat-value {
        font-size: 21px;
    }

    .donation-card .card-body {
        padding: 12px;
    }

    .donation-pagination {
        align-items: flex-start;
    }
}
</style>

<div class="container-fluid donation-page">

    <!-- PAGE HEADER -->
    <div class="donation-hero">
        <div>
            <h2>
                <i class="fas fa-hand-holding-heart me-2"></i>
                Donation Management
            </h2>
            <p>
                Manage program donation targets, raised amounts, preset amounts, payment types, bank accounts and fundraising progress.
            </p>
        </div>

        <div class="d-flex gap-2 flex-wrap">
            <button type="button"
                    class="btn btn-outline-light fw-semibold"
                    data-bs-toggle="modal"
                    data-bs-target="#bankModal">
                <i class="fas fa-building-columns me-2"></i>Default Bank Details
            </button>

            <button type="button"
                    class="btn btn-light fw-semibold"
                    onclick="openAddDonationModal()">
                <i class="fas fa-plus me-2"></i>Add Donation
            </button>
        </div>
    </div>

    <!-- FLASH MESSAGE -->
    <?php if ($flash): ?>
        <div class="alert alert-<?php echo donationH($flash['type']); ?> alert-dismissible fade show"
             role="alert">
            <?php echo donationH($flash['message']); ?>
            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                    aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($actionMessage): ?>
        <div class="alert alert-danger" role="alert">
            <strong>Unable to load donations.</strong>
            <div><?php echo donationH($actionMessage); ?></div>
            <div class="small mt-1">
                Check the API URL, API token configuration and API response.
            </div>
        </div>
    <?php endif; ?>

    <?php if (empty($bankDetails['is_configured']) && !$actionMessage): ?>
        <div class="alert alert-warning" role="alert">
            <i class="fas fa-triangle-exclamation me-1"></i>
            Default bank details are not set yet. Donations that do not have their own bank account
            will show nothing to donors until you
            <a href="#" data-bs-toggle="modal" data-bs-target="#bankModal">add default bank details</a>.
        </div>
    <?php endif; ?>

    <!-- STATISTICS -->
    <div class="row g-3 mb-4">

        <div class="col-xl-3 col-md-6">
            <div class="card donation-stat">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Total Programs</div>
                        <div class="stat-value"><?php echo $totalPrograms; ?></div>
                    </div>
                    <div class="stat-icon bg-primary-subtle text-primary">
                        <i class="fas fa-layer-group"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card donation-stat">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Donation Records</div>
                        <div class="stat-value"><?php echo $configuredCount; ?></div>
                        <small class="text-muted">
                            <?php echo $ownBankCount; ?> with own bank account
                        </small>
                    </div>
                    <div class="stat-icon bg-success-subtle text-success">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card donation-stat">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Total Target</div>
                        <div class="stat-value">
                            Rs. <?php echo donationMoney($totalTarget); ?>
                        </div>
                    </div>
                    <div class="stat-icon bg-warning-subtle text-warning">
                        <i class="fas fa-bullseye"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card donation-stat">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Total Raised</div>
                        <div class="stat-value">
                            Rs. <?php echo donationMoney($totalRaised); ?>
                        </div>
                    </div>
                    <div class="stat-icon bg-info-subtle text-info">
                        <i class="fas fa-chart-line"></i>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- OVERALL PROGRESS -->
    <div class="card donation-card mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <div>
                    <h6 class="mb-1 fw-bold">Overall Fundraising Progress</h6>
                    <small class="text-muted">
                        Rs. <?php echo donationMoney($totalRaised); ?>
                        raised out of Rs. <?php echo donationMoney($totalTarget); ?>
                    </small>
                </div>

                <strong><?php echo number_format($overallPercentage, 2); ?>%</strong>
            </div>

            <div class="progress" style="height: 10px;">
                <div class="progress-bar bg-success"
                     role="progressbar"
                     style="width: <?php echo min(100, max(0, $overallPercentage)); ?>%;"
                     aria-valuenow="<?php echo min(100, max(0, $overallPercentage)); ?>"
                     aria-valuemin="0"
                     aria-valuemax="100">
                </div>
            </div>
        </div>
    </div>

    <!-- DONATION TABLE -->
    <div class="card donation-card">

        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="mb-1 fw-bold">Programs & Donations</h5>
                <small class="text-muted">
                    View and manage donation details for each program.
                </small>
            </div>

            <span class="badge bg-success-subtle text-success">
                <?php echo $totalRecords; ?> result(s)
            </span>
        </div>

        <div class="card-body">

            <!-- SEARCH / FILTER -->
            <form method="GET" action="donations.php" class="row g-3 mb-4">

                <div class="col-lg-6 col-md-6">
                    <label class="form-label donation-modal-label">Search</label>

                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-search"></i>
                        </span>

                        <input type="text"
                               name="search"
                               class="form-control"
                               placeholder="Search program, heading or ID..."
                               value="<?php echo donationH($search); ?>">
                    </div>
                </div>

                <div class="col-lg-3 col-md-4">
                    <label class="form-label donation-modal-label">Donation Status</label>

                    <select name="status" class="form-select">
                        <option value="all"
                            <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>
                            All Programs
                        </option>

                        <option value="configured"
                            <?php echo $statusFilter === 'configured' ? 'selected' : ''; ?>>
                            Donation Configured
                        </option>

                        <option value="not_configured"
                            <?php echo $statusFilter === 'not_configured' ? 'selected' : ''; ?>>
                            Not Configured
                        </option>
                    </select>
                </div>

                <div class="col-lg-3 col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-filter me-1"></i>Filter
                    </button>

                    <a href="donations.php" class="btn btn-outline-secondary">
                        <i class="fas fa-rotate-left me-1"></i>Reset
                    </a>
                </div>

            </form>

            <div class="table-responsive">
                <table class="table table-hover donation-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Program</th>
                            <th>Donation Heading</th>
                            <th>Target Amount</th>
                            <th>Raised Amount</th>
                            <th>Preset Amounts</th>
                            <th>Payment Type</th>
                            <th>Progress</th>
                            <th>Program Status</th>
                            <th>Donation Status</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (empty($pageDonations)): ?>
                        <tr>
                            <td colspan="12">
                                <div class="donation-empty">
                                    <i class="fas fa-folder-open fa-3x mb-3"></i>
                                    <h6 class="fw-bold">No donation records found</h6>
                                    <p class="mb-0">
                                        Try changing your search/filter or add donation details.
                                    </p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>

                        <?php foreach ($pageDonations as $index => $item): ?>
                            <?php
                                $programId = (int) ($item['program_id'] ?? 0);
                                $donationId = (int) ($item['donation_id'] ?? 0);
                                $programTitle = (string) ($item['program_title'] ?? '');
                                $heading = (string) ($item['donation_heading'] ?? '');
                                $image = (string) ($item['program_image'] ?? '');
                                $programStatus = (string) ($item['program_status'] ?? 'inactive');

                                $target = (float) ($item['total_donations'] ?? 0);
                                $raised = (float) ($item['raise_donations'] ?? 0);

                                $presetAmounts = (string) (
                                    $item['preset_amounts'] ?? DONATION_DEFAULT_PRESETS
                                );

                                if (trim($presetAmounts) === '') {
                                    $presetAmounts = DONATION_DEFAULT_PRESETS;
                                }

                                $allowCustom = !empty($item['allow_custom_amount']);
                                $minCustom = (float) ($item['min_custom_amount'] ?? 1);

                                $paymentTypes = (string) (
                                    $item['payment_types'] ?? DONATION_DEFAULT_PAYMENT_TYPES
                                );

                                if (trim($paymentTypes) === '') {
                                    $paymentTypes = DONATION_DEFAULT_PAYMENT_TYPES;
                                }

                                $hasCustomBank = !empty($item['has_custom_bank']);
                                $customBank = is_array($item['custom_bank'] ?? null)
                                    ? $item['custom_bank']
                                    : [];

                                $percentage = $target > 0
                                    ? round(($raised / $target) * 100, 2)
                                    : 0;

                                $percentageWidth = min(100, max(0, $percentage));

                                $editData = [
                                    'donation_id' => $donationId,
                                    'program_id' => $programId,
                                    'program_title' => $programTitle,
                                    'donation_heading' => $heading,
                                    'total_donations' => $target,
                                    'raise_donations' => $raised,
                                    'preset_amounts' => $presetAmounts,
                                    'allow_custom_amount' => $allowCustom ? 1 : 0,
                                    'min_custom_amount' => $minCustom,
                                    'payment_types' => $paymentTypes,
                                    'use_custom_bank' => $hasCustomBank ? 1 : 0,
                                    'custom_bank' => $customBank
                                ];

                                $addData = [
                                    'program_id' => $programId,
                                    'program_title' => $programTitle
                                ];
                            ?>

                            <tr>
                                <td><?php echo $offset + $index + 1; ?></td>

                                <td>
                                    <div class="d-flex align-items-center gap-3">

                                        <?php if ($image !== ''): ?>
                                            <img src="<?php echo donationH($image); ?>"
                                                 alt="<?php echo donationH($programTitle); ?>"
                                                 class="program-image"
                                                 loading="lazy"
                                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">

                                            <div class="program-image-placeholder"
                                                 style="display:none;">
                                                <i class="fas fa-hand-holding-heart"></i>
                                            </div>
                                        <?php else: ?>
                                            <div class="program-image-placeholder">
                                                <i class="fas fa-hand-holding-heart"></i>
                                            </div>
                                        <?php endif; ?>

                                        <div>
                                            <div class="fw-semibold">
                                                <?php echo donationH($programTitle ?: 'Untitled Program'); ?>
                                            </div>

                                            <small class="text-muted">
                                                Program ID: <?php echo $programId; ?>
                                            </small>
                                        </div>

                                    </div>
                                </td>

                                <td>
                                    <?php if ($donationId): ?>
                                        <?php echo donationH($heading); ?>
                                    <?php else: ?>
                                        <span class="text-muted">Not set</span>
                                    <?php endif; ?>
                                </td>

                                <td class="text-nowrap">
                                    <?php echo $donationId ? 'Rs. ' . donationMoney($target) : '—'; ?>
                                </td>

                                <td class="text-nowrap">
                                    <?php echo $donationId ? 'Rs. ' . donationMoney($raised) : '—'; ?>
                                </td>

                                <td style="min-width: 170px;">
                                    <?php if ($donationId): ?>

                                        <div class="d-flex flex-wrap gap-1 mb-1">
                                            <?php foreach (array_filter(array_map('trim', explode(',', $presetAmounts)), 'strlen') as $amt): ?>
                                                <span class="preset-chip">
                                                    Rs.<?php echo donationH($amt); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>

                                        <small class="text-muted">
                                            <?php if ($allowCustom): ?>
                                                <i class="fas fa-pen me-1"></i>Custom allowed (min Rs. <?php echo donationH(rtrim(rtrim(number_format($minCustom, 2, '.', ''), '0'), '.')); ?>)
                                            <?php else: ?>
                                                <i class="fas fa-lock me-1"></i>Custom amount off
                                            <?php endif; ?>
                                        </small>

                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td style="min-width: 190px;">
                                    <?php if ($donationId): ?>
                                        <?php foreach (($item['payment_types_list'] ?? []) as $pt): ?>
                                            <span class="badge bg-primary-subtle text-primary">
                                                <i class="fas fa-building-columns me-1"></i><?php echo donationH($pt['label'] ?? ''); ?>
                                            </span>
                                        <?php endforeach; ?>

                                        <div class="small mt-1">
                                            <?php if ($hasCustomBank): ?>
                                                <span class="badge bg-warning-subtle text-warning">
                                                    <i class="fas fa-building-columns me-1"></i>Own account:
                                                    <?php echo donationH($item['bank_details']['bank_name'] ?? ''); ?>
                                                </span>
                                                <div class="text-muted">
                                                    <?php echo donationH($item['bank_details']['account_number'] ?? ''); ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">Default account</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td style="min-width: 135px;">
                                    <?php if ($donationId): ?>
                                        <div class="d-flex justify-content-between mb-1">
                                            <small class="text-muted">Raised</small>
                                            <small class="fw-semibold">
                                                <?php echo number_format($percentage, 2); ?>%
                                            </small>
                                        </div>

                                        <div class="donation-progress">
                                            <div class="progress-bar bg-success"
                                                 style="width: <?php echo $percentageWidth; ?>%; height: 100%;">
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($programStatus === 'active'): ?>
                                        <span class="badge bg-success-subtle text-success">
                                            Active
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary">
                                            <?php echo donationH(ucfirst($programStatus)); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($donationId): ?>
                                        <span class="badge bg-success-subtle text-success">
                                            <i class="fas fa-check-circle me-1"></i>Configured
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning">
                                            <i class="fas fa-exclamation-circle me-1"></i>Not Configured
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="text-nowrap">
                                    <?php
                                        $createdAt = $item['donation_created_at'] ?? '';

                                        echo $createdAt !== ''
                                            ? donationH(date('d M Y', strtotime($createdAt)))
                                            : '—';
                                    ?>
                                </td>

                                <td>
                                    <div class="donation-actions">

                                        <?php if ($donationId): ?>

                                            <button type="button"
                                                    class="btn btn-sm btn-outline-primary"
                                                    title="Edit donation"
                                                    aria-label="Edit donation"
                                                    onclick='openEditDonationModal(<?php
                                                        echo json_encode(
                                                            $editData,
                                                            JSON_HEX_APOS |
                                                            JSON_HEX_QUOT |
                                                            JSON_HEX_TAG |
                                                            JSON_HEX_AMP
                                                        );
                                                    ?>)'>
                                                <i class="fas fa-edit"></i>
                                            </button>

                                            <button type="button"
                                                    class="btn btn-sm btn-outline-danger"
                                                    title="Delete donation"
                                                    aria-label="Delete donation"
                                                    onclick='confirmDeleteDonation(<?php
                                                        echo $donationId;
                                                    ?>, <?php
                                                        echo json_encode(
                                                            $programTitle,
                                                            JSON_HEX_APOS |
                                                            JSON_HEX_QUOT |
                                                            JSON_HEX_TAG |
                                                            JSON_HEX_AMP
                                                        );
                                                    ?>)'>
                                                <i class="fas fa-trash"></i>
                                            </button>

                                        <?php else: ?>

                                            <button type="button"
                                                    class="btn btn-sm btn-success"
                                                    title="Add donation details"
                                                    onclick='openAddDonationModal(<?php
                                                        echo json_encode(
                                                            $addData,
                                                            JSON_HEX_APOS |
                                                            JSON_HEX_QUOT |
                                                            JSON_HEX_TAG |
                                                            JSON_HEX_AMP
                                                        );
                                                    ?>)'>
                                                <i class="fas fa-plus me-1"></i>Add
                                            </button>

                                        <?php endif; ?>

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
                <div class="donation-pagination">

                    <div class="text-muted small">
                        Showing
                        <strong><?php echo $showingFrom; ?></strong>
                        to
                        <strong><?php echo $showingTo; ?></strong>
                        of
                        <strong><?php echo $totalRecords; ?></strong>
                        entries
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <nav aria-label="Donation pagination">
                            <ul class="pagination pagination-sm">

                                <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link"
                                       href="<?php echo donationH(donationPageUrl(1)); ?>">
                                        First
                                    </a>
                                </li>

                                <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link"
                                       href="<?php echo donationH(donationPageUrl(max(1, $currentPage - 1))); ?>">
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
                                           href="<?php echo donationH(donationPageUrl(1)); ?>">
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
                                           href="<?php echo donationH(donationPageUrl($p)); ?>">
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
                                           href="<?php echo donationH(donationPageUrl($totalPages)); ?>">
                                            <?php echo $totalPages; ?>
                                        </a>
                                    </li>
                                <?php endif; ?>

                                <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link"
                                       href="<?php echo donationH(donationPageUrl(min($totalPages, $currentPage + 1))); ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>

                                <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link"
                                       href="<?php echo donationH(donationPageUrl($totalPages)); ?>">
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

<!-- ADD / EDIT DONATION MODAL -->
<div class="modal fade"
     id="donationModal"
     tabindex="-1"
     aria-labelledby="donationModalTitle"
     aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">

            <form method="POST" action="donations.php" id="donationForm">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="donationModalTitle">
                        Add Donation
                    </h5>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                            aria-label="Close"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden"
                           name="form_action"
                           id="form_action"
                           value="add_donation">

                    <input type="hidden"
                           name="donation_id"
                           id="donation_id">

                    <div class="mb-3">
                        <label for="program_id" class="form-label donation-modal-label">
                            Program <span class="donation-required">*</span>
                        </label>

                        <select name="program_id"
                                id="program_id"
                                class="form-select"
                                required>

                            <option value="">Select a program</option>

                            <?php foreach ($donations as $program): ?>
                                <option value="<?php echo (int) ($program['program_id'] ?? 0); ?>">
                                    <?php
                                        echo donationH(
                                            ($program['program_title'] ?? 'Untitled Program') .
                                            ' (ID: ' .
                                            (int) ($program['program_id'] ?? 0) .
                                            ')'
                                        );
                                    ?>
                                </option>
                            <?php endforeach; ?>

                        </select>

                        <small class="text-muted">
                            Each program can have one donation record.
                        </small>
                    </div>

                    <div class="mb-3">
                        <label for="donation_heading" class="form-label donation-modal-label">
                            Donation Heading <span class="donation-required">*</span>
                        </label>

                        <input type="text"
                               name="donation_heading"
                               id="donation_heading"
                               class="form-control"
                               maxlength="255"
                               placeholder="e.g. Help Families in Need"
                               required>
                    </div>

                    <div class="mb-3">
                        <label for="total_donations" class="form-label donation-modal-label">
                            Total Donation Target (Rs.)
                            <span class="donation-required">*</span>
                        </label>

                        <input type="number"
                               name="total_donations"
                               id="total_donations"
                               class="form-control"
                               min="0"
                               max="9999999999.99"
                               step="0.01"
                               value="0"
                               required>
                    </div>

                    <div class="mb-3">
                        <label for="raise_donations" class="form-label donation-modal-label">
                            Raised Donations (Rs.)
                            <span class="donation-required">*</span>
                        </label>

                        <input type="number"
                               name="raise_donations"
                               id="raise_donations"
                               class="form-control"
                               min="0"
                               max="9999999999.99"
                               step="0.01"
                               value="0"
                               required>
                    </div>

                    <!-- PRESET AMOUNTS -->
                    <div class="mb-3">
                        <label for="preset_amounts" class="form-label donation-modal-label">
                            Preset Donation Amounts (Rs.)
                        </label>

                        <input type="text"
                               name="preset_amounts"
                               id="preset_amounts"
                               class="form-control"
                               maxlength="255"
                               value="<?php echo DONATION_DEFAULT_PRESETS; ?>"
                               placeholder="e.g. 100, 200, 500, 1000, 10000">

                        <small class="text-muted">
                            Separate amounts with commas (maximum 8). These appear as buttons on the website.
                        </small>

                        <div id="presetPreview" class="d-flex flex-wrap gap-2 mt-2"></div>
                    </div>

                    <!-- PAYMENT TYPE -->
                    <div class="mb-3">
                        <label class="form-label donation-modal-label">
                            Payment Type <span class="donation-required">*</span>
                        </label>

                        <div class="form-check">
                            <input class="form-check-input payment-type-check"
                                   type="checkbox"
                                   name="payment_types[]"
                                   id="pt_bank_transfer"
                                   value="bank_transfer"
                                   checked>

                            <label class="form-check-label" for="pt_bank_transfer">
                                Bank Transfer
                            </label>
                        </div>

                        <small class="text-muted">
                            After the donor chooses an amount, this option shows the bank account details.
                        </small>
                    </div>

                    <!-- PER-DONATION BANK ACCOUNT -->
                    <div class="mb-3">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input"
                                   type="checkbox"
                                   name="use_custom_bank"
                                   id="use_custom_bank"
                                   value="1">

                            <label class="form-check-label donation-modal-label"
                                   for="use_custom_bank">
                                Use a separate bank account for this donation
                            </label>
                        </div>

                        <small class="text-muted d-block bank-source-note" id="bankDefaultNote">
                            Off: this donation uses the default account
                            (<strong id="bankDefaultName"><?php
                                echo !empty($bankDetails['is_configured'])
                                    ? donationH(($bankDetails['bank_name'] ?? '') . ' - ' . ($bankDetails['account_number'] ?? ''))
                                    : 'not set yet';
                            ?></strong>).
                            Change it with the "Default Bank Details" button at the top.
                        </small>

                        <div id="customBankBox"
                             class="border rounded p-3 bg-light mt-2"
                             style="display:none;">

                            <?php foreach ($bankFields as $fieldName => $fieldInfo): ?>
                                <div class="mb-2">
                                    <label class="form-label donation-modal-label">
                                        <?php echo donationH($fieldInfo[0]); ?>
                                        <?php if ($fieldInfo[1]): ?>
                                            <span class="donation-required">*</span>
                                        <?php endif; ?>
                                    </label>

                                    <input type="text"
                                           class="form-control form-control-sm custom-bank-input"
                                           name="custom_bank[<?php echo donationH($fieldName); ?>]"
                                           id="cb_<?php echo donationH($fieldName); ?>"
                                           data-required="<?php echo $fieldInfo[1] ? '1' : '0'; ?>"
                                           maxlength="<?php echo $fieldName === 'swift_code' ? '20' : '150'; ?>">
                                </div>
                            <?php endforeach; ?>

                            <label class="form-label donation-modal-label">
                                Instructions for donors
                            </label>

                            <textarea class="form-control form-control-sm custom-bank-input"
                                      name="custom_bank[instructions]"
                                      id="cb_instructions"
                                      data-required="0"
                                      rows="2"
                                      maxlength="1000"
                                      placeholder="e.g. Please email the transfer receipt after payment."></textarea>
                        </div>
                    </div>

                    <!-- CUSTOM AMOUNT -->
                    <div class="mb-3">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input"
                                   type="checkbox"
                                   name="allow_custom_amount"
                                   id="allow_custom_amount"
                                   value="1"
                                   checked>

                            <label class="form-check-label donation-modal-label"
                                   for="allow_custom_amount">
                                Allow donors to enter their own amount
                            </label>
                        </div>

                        <div id="customAmountBox">
                            <label for="min_custom_amount" class="form-label donation-modal-label">
                                Minimum Custom Amount (Rs.)
                            </label>

                            <input type="number"
                                   name="min_custom_amount"
                                   id="min_custom_amount"
                                   class="form-control"
                                   min="0.01"
                                   max="9999999999.99"
                                   step="0.01"
                                   value="1">
                        </div>
                    </div>

                    <div class="alert alert-info small mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        Fundraising percentage is calculated automatically from the raised amount and target.
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button"
                            class="btn btn-light"
                            data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit"
                            class="btn btn-success"
                            id="saveDonationButton">
                        <i class="fas fa-save me-1"></i>Save Donation
                    </button>
                </div>

            </form>

        </div>
    </div>
</div>

<!-- DEFAULT BANK DETAILS MODAL -->
<div class="modal fade" id="bankModal" tabindex="-1" aria-labelledby="bankModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">

            <form method="POST" action="donations.php">

                <input type="hidden" name="form_action" value="save_bank_details">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="bankModalTitle">Default Bank Transfer Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">

                    <div class="alert alert-info small">
                        <i class="fas fa-info-circle me-1"></i>
                        This is the default account. It is shown for every donation that does not
                        have its own separate bank account.
                    </div>

                    <?php foreach ($bankFields as $fieldName => $fieldInfo): ?>
                        <div class="mb-3">
                            <label class="form-label donation-modal-label">
                                <?php echo donationH($fieldInfo[0]); ?>
                                <?php if ($fieldInfo[1]): ?>
                                    <span class="donation-required">*</span>
                                <?php endif; ?>
                            </label>

                            <input type="text"
                                   class="form-control"
                                   name="<?php echo donationH($fieldName); ?>"
                                   maxlength="150"
                                   <?php echo $fieldInfo[1] ? 'required' : ''; ?>
                                   value="<?php echo donationH($bankDetails[$fieldName] ?? ''); ?>">
                        </div>
                    <?php endforeach; ?>

                    <div class="mb-0">
                        <label class="form-label donation-modal-label">Instructions for donors</label>

                        <textarea class="form-control"
                                  name="instructions"
                                  rows="3"
                                  maxlength="1000"
                                  placeholder="e.g. Please email the transfer receipt after payment."><?php
                            echo donationH($bankDetails['instructions'] ?? '');
                        ?></textarea>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>

                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save me-1"></i>Save Bank Details
                    </button>
                </div>

            </form>

        </div>
    </div>
</div>

<!-- DELETE DONATION FORM -->
<form method="POST"
      action="donations.php"
      id="deleteDonationForm"
      class="d-none">

    <input type="hidden"
           name="form_action"
           value="delete_donation">

    <input type="hidden"
           name="donation_id"
           id="delete_donation_id">
</form>


<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const DEFAULT_PRESETS = '<?php echo DONATION_DEFAULT_PRESETS; ?>';
    const DEFAULT_PAYMENT_TYPES = '<?php echo DONATION_DEFAULT_PAYMENT_TYPES; ?>';

    const modalElement = document.getElementById('donationModal');
    const donationForm = document.getElementById('donationForm');

    const presetInput = document.getElementById('preset_amounts');
    const presetPreview = document.getElementById('presetPreview');

    const allowCustom = document.getElementById('allow_custom_amount');
    const minCustom = document.getElementById('min_custom_amount');
    const customBox = document.getElementById('customAmountBox');

    const paymentChecks = document.querySelectorAll('.payment-type-check');

    // Per-donation bank account
    const useCustomBank = document.getElementById('use_custom_bank');
    const customBankBox = document.getElementById('customBankBox');
    const bankDefaultNote = document.getElementById('bankDefaultNote');
    const customBankInputs = document.querySelectorAll('.custom-bank-input');
    const bankKeys = [
        'bank_name', 'account_title', 'account_number',
        'iban', 'branch_name', 'swift_code', 'instructions'
    ];

    /*
    |----------------------------------------------------------------------
    | Preset amounts: live preview + validation
    |----------------------------------------------------------------------
    */

    function parsePresets(raw) {
        return String(raw || '')
            .split(',')
            .map(function (v) { return v.trim(); })
            .filter(function (v) { return v !== ''; });
    }

    function renderPresetPreview() {
        presetPreview.innerHTML = '';

        parsePresets(presetInput.value).forEach(function (value) {
            const chip = document.createElement('span');
            chip.className = 'preset-chip';
            chip.textContent = 'Rs.' + value;
            presetPreview.appendChild(chip);
        });
    }

    presetInput.addEventListener('input', renderPresetPreview);

    /*
    |----------------------------------------------------------------------
    | Payment types
    |----------------------------------------------------------------------
    */

    function setPaymentTypes(csv) {
        const selected = String(csv || DEFAULT_PAYMENT_TYPES)
            .split(',')
            .map(function (s) { return s.trim(); });

        paymentChecks.forEach(function (c) {
            c.checked = selected.indexOf(c.value) !== -1;
        });
    }

    /*
    |----------------------------------------------------------------------
    | Custom amount toggle
    |----------------------------------------------------------------------
    */

    function toggleCustomBox() {
        customBox.style.display = allowCustom.checked ? '' : 'none';
    }

    allowCustom.addEventListener('change', toggleCustomBox);

    /*
    |----------------------------------------------------------------------
    | Separate bank account toggle
    |----------------------------------------------------------------------
    */

    function toggleCustomBank() {
        const on = useCustomBank.checked;

        customBankBox.style.display = on ? '' : 'none';
        bankDefaultNote.style.display = on ? 'none' : '';

        // Required fields only matter while the box is visible.
        customBankInputs.forEach(function (el) {
            el.required = on && el.dataset.required === '1';
        });
    }

    function fillCustomBank(data) {
        data = data || {};

        bankKeys.forEach(function (key) {
            const el = document.getElementById('cb_' + key);

            if (el) {
                el.value = data[key] || '';
            }
        });
    }

    useCustomBank.addEventListener('change', toggleCustomBank);

    /*
    |----------------------------------------------------------------------
    | Bootstrap modal helper
    |----------------------------------------------------------------------
    */

    function getModalInstance() {
        if (
            typeof bootstrap === 'undefined' ||
            !bootstrap.Modal
        ) {
            console.error(
                'Bootstrap 5 JavaScript is unavailable. Check the browser Network tab.'
            );

            alert('Bootstrap did not load. Please refresh the page and check the Console.');
            return null;
        }

        return bootstrap.Modal.getOrCreateInstance(modalElement);
    }

    window.openAddDonationModal = function (program = null) {
        donationForm.reset();

        document.getElementById('form_action').value = 'add_donation';
        document.getElementById('donation_id').value = '';
        document.getElementById('donationModalTitle').textContent = 'Add Donation';

        document.getElementById('saveDonationButton').innerHTML =
            '<i class="fas fa-save me-1"></i>Save Donation';

        document.getElementById('total_donations').value = '0';
        document.getElementById('raise_donations').value = '0';
        document.getElementById('program_id').disabled = false;

        document.getElementById('program_id').value =
            program && program.program_id
                ? String(program.program_id)
                : '';

        presetInput.value = DEFAULT_PRESETS;
        renderPresetPreview();

        setPaymentTypes(DEFAULT_PAYMENT_TYPES);

        allowCustom.checked = true;
        minCustom.value = '1';
        toggleCustomBox();

        useCustomBank.checked = false;
        fillCustomBank({});
        toggleCustomBank();

        const modal = getModalInstance();
        if (modal) modal.show();
    };

    window.openEditDonationModal = function (item) {
        donationForm.reset();

        document.getElementById('form_action').value = 'update_donation';
        document.getElementById('donation_id').value = item.donation_id;
        document.getElementById('donationModalTitle').textContent = 'Edit Donation';

        document.getElementById('saveDonationButton').innerHTML =
            '<i class="fas fa-save me-1"></i>Update Donation';

        document.getElementById('program_id').value = String(item.program_id);
        document.getElementById('program_id').disabled = false;
        document.getElementById('donation_heading').value = item.donation_heading || '';
        document.getElementById('total_donations').value = item.total_donations ?? 0;
        document.getElementById('raise_donations').value = item.raise_donations ?? 0;

        presetInput.value = item.preset_amounts || DEFAULT_PRESETS;
        renderPresetPreview();

        setPaymentTypes(item.payment_types);

        allowCustom.checked = Number(item.allow_custom_amount) === 1;
        minCustom.value = item.min_custom_amount ?? 1;
        toggleCustomBox();

        useCustomBank.checked = Number(item.use_custom_bank) === 1;
        fillCustomBank(item.custom_bank);
        toggleCustomBank();

        const modal = getModalInstance();
        if (modal) modal.show();
    };

    window.confirmDeleteDonation = function (donationId, programTitle) {
        const message =
            'Delete donation details for "' +
            String(programTitle || 'this program') +
            '"?\n\nThis action cannot be undone.';

        if (confirm(message)) {
            document.getElementById('delete_donation_id').value = donationId;
            document.getElementById('deleteDonationForm').submit();
        }
    };

    donationForm.addEventListener('submit', function (event) {
        const target = Number(document.getElementById('total_donations').value);
        const raised = Number(document.getElementById('raise_donations').value);
        const heading = document.getElementById('donation_heading').value.trim();
        const programId = document.getElementById('program_id').value;

        if (!programId) {
            event.preventDefault();
            alert('Please select a program.');
            return;
        }

        if (!heading) {
            event.preventDefault();
            alert('Please enter a donation heading.');
            return;
        }

        if (
            !Number.isFinite(target) ||
            !Number.isFinite(raised) ||
            target < 0 ||
            raised < 0 ||
            target > 9999999999.99 ||
            raised > 9999999999.99
        ) {
            event.preventDefault();
            alert('Please enter valid non-negative donation amounts.');
            return;
        }

        // At least one payment type is required.
        const anyPaymentType = Array.prototype.some.call(
            paymentChecks,
            function (c) { return c.checked; }
        );

        if (!anyPaymentType) {
            event.preventDefault();
            alert('Please select at least one payment type.');
            return;
        }

        // Separate bank account: required fields must be filled.
        if (useCustomBank.checked) {
            const requiredKeys = ['bank_name', 'account_title', 'account_number'];

            for (let i = 0; i < requiredKeys.length; i++) {
                const el = document.getElementById('cb_' + requiredKeys[i]);

                if (!el || el.value.trim() === '') {
                    event.preventDefault();
                    alert('Please fill Bank Name, Account Title and Account Number for this donation\'s bank account.');
                    if (el) el.focus();
                    return;
                }
            }
        }

        // Preset amounts validation (empty = default amounts are used).
        const presetList = parsePresets(presetInput.value);

        if (presetList.length > 0) {
            const validPresets =
                presetList.length <= 8 &&
                presetList.every(function (v) {
                    return /^\d+(\.\d{1,2})?$/.test(v) && Number(v) > 0;
                });

            if (!validPresets) {
                event.preventDefault();
                alert('Preset amounts must be positive numbers separated by commas (maximum 8).');
                presetInput.focus();
                return;
            }
        }

        // Minimum custom amount validation.
        if (allowCustom.checked) {
            const minValue = Number(minCustom.value);

            if (!Number.isFinite(minValue) || minValue <= 0) {
                event.preventDefault();
                alert('Minimum custom amount must be greater than 0.');
                minCustom.focus();
            }
        }
    });

    // Initial state.
    renderPresetPreview();
    toggleCustomBox();
    toggleCustomBank();
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>