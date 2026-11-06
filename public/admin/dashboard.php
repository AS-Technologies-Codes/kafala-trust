<?php
$pageTitle  = 'Dashboard';
$activePage = 'dashboard';

// Session, login check, helpers, <head>, sidebar and top bar.
require __DIR__ . '/includes/header.php';

// -------------------- DATA --------------------
$error = '';
$stats = [
    'programs_total'  => 0,
    'programs_active' => 0,
    'gallery_total'   => 0,
    'gallery_images'  => 0,
    'gallery_videos'  => 0,
    'enquiries_total' => 0,
    'enquiries_new'   => 0,
    'donation_target' => 0,
    'donation_raised' => 0,
];
$recentEnquiries = [];
$recentPrograms  = [];
$donationRows    = [];

$pdo = null;
try {
    $pdo = getPdoConnection();

    $r = $pdo->query("SELECT COUNT(*) AS total, SUM(status = 'active') AS active FROM programs")->fetch(PDO::FETCH_ASSOC);
    $stats['programs_total']  = (int)($r['total'] ?? 0);
    $stats['programs_active'] = (int)($r['active'] ?? 0);

    $r = $pdo->query("SELECT COUNT(*) AS total,
                             SUM(media_type = 'image') AS images,
                             SUM(media_type = 'youtube') AS videos
                      FROM gallery")->fetch(PDO::FETCH_ASSOC);
    $stats['gallery_total']  = (int)($r['total'] ?? 0);
    $stats['gallery_images'] = (int)($r['images'] ?? 0);
    $stats['gallery_videos'] = (int)($r['videos'] ?? 0);

    $r = $pdo->query("SELECT COUNT(*) AS total, SUM(status = 'new') AS new_count FROM contact_enquiries")->fetch(PDO::FETCH_ASSOC);
    $stats['enquiries_total'] = (int)($r['total'] ?? 0);
    $stats['enquiries_new']   = (int)($r['new_count'] ?? 0);

    $r = $pdo->query("SELECT COALESCE(SUM(total_donations), 0) AS target,
                             COALESCE(SUM(raise_donations), 0) AS raised
                      FROM donations")->fetch(PDO::FETCH_ASSOC);
    $stats['donation_target'] = (float)($r['target'] ?? 0);
    $stats['donation_raised'] = (float)($r['raised'] ?? 0);

    $recentEnquiries = $pdo->query(
        "SELECT id, name, email, subject, status, created_at
         FROM contact_enquiries
         ORDER BY created_at DESC
         LIMIT 6"
    )->fetchAll(PDO::FETCH_ASSOC);

    $recentPrograms = $pdo->query(
        "SELECT id, title, status, created_at
         FROM programs
         ORDER BY created_at DESC
         LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    $donationRows = $pdo->query(
        "SELECT p.id, p.title,
                COALESCE(d.total_donations, 0) AS target,
                COALESCE(d.raise_donations, 0) AS raised
         FROM programs p
         LEFT JOIN donations d ON d.program_id = p.id
         WHERE p.status = 'active'
         ORDER BY p.created_at DESC
         LIMIT 6"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
    error_log('Dashboard error: ' . $ex->getMessage());
    $error = 'Some dashboard data could not be loaded. Please refresh the page.';
} finally {
    if ($pdo instanceof PDO) {
        closePdoConnection($pdo);
    }
}

$overallPercent = $stats['donation_target'] > 0
    ? min(100, round($stats['donation_raised'] / $stats['donation_target'] * 100, 1))
    : 0;
?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>


<!-- Donations headline -->
<section class="raised-panel mb-4" aria-label="Donation summary">
    <div class="row align-items-center g-3">
        <div class="col-md-6">
            <div class="label">Total raised across all programs</div>
            <div class="amount"><?= e(money($stats['donation_raised'])) ?></div>
            <div class="label">of <?= e(money($stats['donation_target'])) ?> target</div>
        </div>
        <div class="col-md-6">
            <div class="d-flex justify-content-between mb-2">
                <span class="label">Overall progress</span>
                <strong><?= e($overallPercent) ?>%</strong>
            </div>
            <div class="progress" role="progressbar"
                 aria-valuenow="<?= e($overallPercent) ?>" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar" style="width: <?= e($overallPercent) ?>%"></div>
            </div>
        </div>
    </div>
</section>

<!-- Stat tiles -->
<section class="row g-3 mb-4" aria-label="Summary">
    <div class="col-6 col-xl-3">
        <div class="stat">
            <div class="value"><?= e($stats['programs_total']) ?></div>
            <div class="name">Programs</div>
            <div class="meta"><?= e($stats['programs_active']) ?> active</div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat">
            <div class="value"><?= e($stats['gallery_total']) ?></div>
            <div class="name">Gallery items</div>
            <div class="meta"><?= e($stats['gallery_images']) ?> images, <?= e($stats['gallery_videos']) ?> videos</div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat">
            <div class="value"><?= e($stats['enquiries_total']) ?></div>
            <div class="name">Enquiries</div>
            <div class="meta">All time</div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat<?= $stats['enquiries_new'] > 0 ? ' alert-tile' : '' ?>">
            <div class="value"><?= e($stats['enquiries_new']) ?></div>
            <div class="name">Unread enquiries</div>
            <div class="meta"><?= $stats['enquiries_new'] > 0 ? 'Waiting for a reply' : 'All caught up' ?></div>
        </div>
    </div>
</section>

<div class="row g-3">

    <!-- Recent enquiries -->
    <div class="col-xl-7">
        <section class="panel" aria-labelledby="enq-h">
            <div class="panel-head">
                <h2 id="enq-h">Recent enquiries</h2>
                <a href="enquiries.php">View all</a>
            </div>

            <?php if (!$recentEnquiries): ?>
                <div class="empty">No enquiries yet. New messages from the contact form will show up here.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>From</th>
                                <th>Subject</th>
                                <th>Status</th>
                                <th>Received</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentEnquiries as $q): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e($q['name']) ?></div>
                                    <div class="small text-secondary"><?= e($q['email']) ?></div>
                                </td>
                                <td><?= e(mb_strimwidth((string)$q['subject'], 0, 40, '...')) ?></td>
                                <td><?= statusBadge((string)$q['status']) ?></td>
                                <td class="text-nowrap small"><?= e(niceDate($q['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <!-- Donation progress per program -->
    <div class="col-xl-5">
        <section class="panel" aria-labelledby="don-h">
            <div class="panel-head">
                <h2 id="don-h">Donation progress</h2>
                <a href="donations.php">Manage</a>
            </div>

            <?php if (!$donationRows): ?>
                <div class="empty">No active programs yet. Add a program to start tracking donations.</div>
            <?php else: ?>
                <?php foreach ($donationRows as $d):
                    $target  = (float)$d['target'];
                    $raised  = (float)$d['raised'];
                    $percent = $target > 0 ? min(100, round($raised / $target * 100, 1)) : 0;
                ?>
                    <div class="prog-row">
                        <div class="d-flex justify-content-between gap-3">
                            <span class="fw-semibold small"><?= e($d['title']) ?></span>
                            <span class="small text-secondary"><?= e($percent) ?>%</span>
                        </div>
                        <div class="progress my-2" role="progressbar"
                             aria-valuenow="<?= e($percent) ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar" style="width: <?= e($percent) ?>%"></div>
                        </div>
                        <div class="small text-secondary">
                            <?= $target > 0
                                ? e(money($raised)) . ' of ' . e(money($target))
                                : 'No donation target set' ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>

    <!-- Latest programs -->
    <div class="col-12">
        <section class="panel" aria-labelledby="prog-h">
            <div class="panel-head">
                <h2 id="prog-h">Latest programs</h2>
                <a href="programs.php">View all</a>
            </div>

            <?php if (!$recentPrograms): ?>
                <div class="empty">No programs yet. Create your first program to show it on the website.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Status</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentPrograms as $p): ?>
                            <tr>
                                <td class="fw-semibold"><?= e($p['title']) ?></td>
                                <td><?= statusBadge((string)$p['status']) ?></td>
                                <td class="text-nowrap small"><?= e(niceDate($p['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>