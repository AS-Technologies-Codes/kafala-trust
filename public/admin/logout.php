<?php
session_start();

/*
 * Logout only works through a POST request with a valid CSRF token
 * (the "Sign out" button in includes/header.php). A plain link or an
 * image tag on another site cannot log the admin out.
 */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    !empty($_SESSION['dashboard_csrf']) &&
    is_string($_POST['csrf_token'] ?? null) &&
    hash_equals($_SESSION['dashboard_csrf'], $_POST['csrf_token'])
) {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $p['path'],
            $p['domain'],
            $p['secure'],
            $p['httponly']
        );
    }

    session_destroy();
    header('Location: login.php');
    exit;
}

// Anything else (direct visit, bad token): go back to the right page.
header('Location: ' . (!empty($_SESSION['admin_id']) ? 'dashboard.php' : 'login.php'));
exit;