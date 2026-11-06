<?php
// Only loaded through includes/header.php.
if (!defined('KT_LAYOUT')) {
    http_response_code(403);
    exit('Forbidden');
}

/*
 * Sidebar links: key => [label, file].
 * The key must match the $activePage value set on each page.
 * Change the file names to match your actual pages.
 */

$navItems = [
    'dashboard' => ['Dashboard', 'dashboard.php'],
    'programs'  => ['Programs',  'programs.php'],
    'donations' => ['Donations', 'donations.php'],
    'gallery'   => ['Gallery',   'gallery.php'],
    'partners'   => ['Partners',   'partners.php'],
    'enquiries' => ['Enquiries', 'enquiries.php'],
    'newsletter' => ['Newsletter', 'newsletter.php'],
];
?>

<aside class="sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="sidebar" aria-label="Main navigation">
    <div class="brand">
        <img
        src="assets/img/kifala_trust_logo.png"
        alt="Kifala Trust – Sahara unka, jo be-sahara hain"
        class="brand"
        width="210"
        height="110"
    >
    </div>

    <nav class="mt-2">
        <?php foreach ($navItems as $key => [$label, $href]): ?>
            <?php $isActive = ($key === ($activePage ?? '')); ?>
            <a class="side-link<?= $isActive ? ' active' : '' ?>"
               href="<?= e($href) ?>"
               <?= $isActive ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
</aside>