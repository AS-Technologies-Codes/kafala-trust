<?php
/**
 * Shared admin header.
 *
 * Before including this file, a page may set:
 *   $pageTitle   - text shown in the browser tab and top bar (default: "Dashboard")
 *   $activePage  - key of the sidebar link to highlight, e.g. 'dashboard', 'programs'
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('KT_LAYOUT', true);

require_once __DIR__ . '/../config/db.php';

// -------------------- AUTH GUARD --------------------
if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

// Do not let the browser cache an authenticated page.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// -------------------- CSRF TOKEN (used by the Sign out form -> logout.php) --------------------
if (empty($_SESSION['dashboard_csrf'])) {
    $_SESSION['dashboard_csrf'] = bin2hex(random_bytes(32));
}

// -------------------- HELPERS (available on every page) --------------------
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($amount): string
{
    $currency = defined('CURRENCY') ? CURRENCY : 'Rs';
    return $currency . ' ' . number_format((float)$amount, 0);
}

function niceDate($value): string
{
    $ts = strtotime((string)$value);
    return $ts ? date('d M Y, h:i A', $ts) : '-';
}

function statusBadge(string $status): string
{
    $map = [
        'active'   => 'success',
        'inactive' => 'secondary',
        'new'      => 'warning',
        'read'     => 'info',
        'replied'  => 'success',
    ];
    $class = $map[$status] ?? 'secondary';
    return '<span class="badge text-bg-' . $class . '">' . e(ucfirst($status)) . '</span>';
}

// -------------------- PAGE VARIABLES --------------------
$pageTitle  = $pageTitle  ?? 'Dashboard';
$activePage = $activePage ?? '';
$adminName  = $_SESSION['admin_name'] ?? 'Admin';
$adminEmail = $_SESSION['admin_email'] ?? '';
$initial    = strtoupper(mb_substr($adminName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> | Kifala Trust</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root {
            --kt-green: #2a9d7f;
            --kt-green-dark: #217e67;
            --kt-teal: #247a67;
            --kt-sky: #dff2ff;
            --kt-mint: #e9f7ef;
            --kt-border: #dceee8;
            --kt-text: #344b50;
            --kt-muted: #71838a;
            --sidebar-w: 250px;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f3f9f8;
            color: var(--kt-text);
        }

        /* ---------- Sidebar ---------- */
        .sidebar {
            width: var(--sidebar-w);
            background: #fff;
            border-right: 1px solid var(--kt-border);
        }

        @media (min-width: 992px) {
            .sidebar {
                position: fixed;
                inset: 0 auto 0 0;
                visibility: visible !important;
                transform: none !important;
            }
            .main { margin-left: var(--sidebar-w); }
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 22px 22px 18px;
        }

        .brand-logo {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: var(--kt-sky);
            color: #16836b;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .brand-name { font-weight: 700; color: var(--kt-teal); line-height: 1.1; }
        .brand-sub  { font-size: 12px; color: var(--kt-muted); }

        .side-link {
            display: block;
            padding: 11px 22px;
            margin: 2px 12px;
            border-radius: 9px;
            color: var(--kt-text);
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }
        .side-link:hover { background: var(--kt-mint); color: var(--kt-green-dark); }
        .side-link.active { background: var(--kt-green); color: #fff; }

        /* ---------- Top bar ---------- */
        .topbar {
            background: #fff;
            border-bottom: 1px solid var(--kt-border);
            padding: 14px 24px;
        }

        .avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: var(--kt-sky);
            color: #16836b;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logout-btn {
            border: 1px solid var(--kt-border);
            background: #fff;
            color: var(--kt-text);
            border-radius: 9px;
            font-size: 14px;
            font-weight: 600;
            padding: 7px 14px;
        }
        .logout-btn:hover { background: var(--kt-mint); }

        /* ---------- Shared components ---------- */
        .raised-panel {
            background: var(--kt-teal);
            color: #fff;
            border-radius: 18px;
            padding: 28px;
        }
        .raised-panel .label { font-size: 14px; opacity: .8; }
        .raised-panel .amount { font-size: 38px; font-weight: 700; line-height: 1.15; }
        .raised-panel .progress { height: 10px; background: rgba(255,255,255,.25); border-radius: 10px; }
        .raised-panel .progress-bar { background: #8be0c4; }

        .stat {
            background: #fff;
            border: 1px solid var(--kt-border);
            border-radius: 14px;
            padding: 20px;
            height: 100%;
        }
        .stat .value { font-size: 28px; font-weight: 700; color: var(--kt-teal); line-height: 1.1; }
        .stat .name  { font-size: 14px; font-weight: 600; margin-top: 6px; }
        .stat .meta  { font-size: 13px; color: var(--kt-muted); }
        .stat.alert-tile { border-color: #f2d98a; background: #fffaf0; }

        .panel {
            background: #fff;
            border: 1px solid var(--kt-border);
            border-radius: 14px;
            height: 100%;
        }
        .panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid var(--kt-border);
        }
        .panel-head h2 { font-size: 16px; font-weight: 700; margin: 0; color: var(--kt-teal); }
        .panel-head a { font-size: 13px; color: var(--kt-green-dark); text-decoration: none; font-weight: 600; }

        .table > :not(caption) > * > * { padding: .75rem 1rem; }
        .table th { font-size: 13px; color: var(--kt-muted); font-weight: 600; }
        .table td { font-size: 14px; vertical-align: middle; }

        .prog-row { padding: 14px 20px; border-bottom: 1px solid #eef5f3; }
        .prog-row:last-child { border-bottom: 0; }
        .prog-row .progress { height: 8px; }
        .prog-row .progress-bar { background: var(--kt-green); }

        .empty { padding: 28px 20px; color: var(--kt-muted); font-size: 14px; text-align: center; }

        a:focus-visible, button:focus-visible {
            outline: 3px solid rgba(42,157,127,.45);
            outline-offset: 2px;
        }
    </style>
</head>
<body>

<?php require __DIR__ . '/sidebar.php'; ?>

<div class="main">

    <header class="topbar d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-outline-secondary d-lg-none" type="button"
                    data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar">
                Menu
            </button>
            <div>
                <div class="fw-bold" style="color:var(--kt-teal);"><?= e($pageTitle) ?></div>
                <div class="small text-secondary"><?= e(date('l, d F Y')) ?></div>
            </div>
        </div>

        <div class="d-flex align-items-center gap-3">
            <div class="d-none d-sm-block text-end">
                <div class="fw-semibold small"><?= e($adminName) ?></div>
                <div class="small text-secondary"><?= e($adminEmail) ?></div>
            </div>
            <div class="avatar" aria-hidden="true"><?= e($initial) ?></div>

            <form method="POST" action="logout.php" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['dashboard_csrf']) ?>">
                <button type="submit" class="logout-btn">Sign out</button>
            </form>
        </div>
    </header>

    <main class="p-3 p-md-4">