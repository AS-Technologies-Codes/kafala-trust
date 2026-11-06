<?php
session_start();

require_once __DIR__ . '/config/db.php';

// Match the fixed token configured in api.php.
$apiToken = getenv('KIFALA_API_TOKEN');

if (!$apiToken && defined('KIFALA_API_TOKEN')) {
    $apiToken = KIFALA_API_TOKEN;
}

$error = '';
$notice = '';

if (isset($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    $notice = 'Your password has been updated. Sign in with your new password.';
}

// CSRF protection for login form.
if (empty($_SESSION['login_csrf_token'])) {
    $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrfToken) ||
        !hash_equals($_SESSION['login_csrf_token'], $csrfToken)
    ) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } elseif ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } else {
        $pdo = null;

        try {
            $pdo = getPdoConnection();

            $stmt = $pdo->prepare(
                "SELECT id, name, email, password
                 FROM admin_users
                 WHERE email = ?
                 LIMIT 1"
            );

            $stmt->execute([$email]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($admin && password_verify($password, $admin['password'])) {
                session_regenerate_id(true);

                $_SESSION['admin_id'] = (int)$admin['id'];
                $_SESSION['admin_name'] = $admin['name'];
                $_SESSION['admin_email'] = $admin['email'];

                // Same fixed token used by the unchanged api.php.
                if ($apiToken) {
                    $_SESSION['api_token'] = $apiToken;
                }

                $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));

                header('Location: dashboard.php');
                exit;
            }

            $error = 'Invalid email or password.';
        } catch (Throwable $e) {
            error_log('Admin login error: ' . $e->getMessage());
            $error = 'Login could not be completed. Please try again.';
        } finally {
            if ($pdo instanceof PDO) {
                closePdoConnection($pdo);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | Kifala Trust</title>

    <!-- Favicon: Kifala Trust emblem -->
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon-32.png">
    <link rel="icon" type="image/png" sizes="512x512" href="assets/img/favicon-512.png">
    <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
    <meta name="theme-color" content="#0b9444">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
          rel="stylesheet">
    <link href="assets/css/auth.css" rel="stylesheet">
</head>
<body>

<main class="login-card">
    <img
        src="assets/img/kifala_trust_logo.png"
        alt="Kifala Trust – Sahara unka, jo be-sahara hain"
        class="brand"
        width="280"
        height="113"
    >
    <h1 class="visually-hidden-title">Kifala Trust</h1>
    <p class="subtitle">Admin Panel &middot; Sign in to continue</p>

    <?php if ($notice !== ''): ?>
        <div class="alert alert-success" role="status">
            <?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars(
                $_SESSION['login_csrf_token'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
        >

        <div class="mb-3">
            <label for="email" class="form-label">Email Address</label>
            <input
                type="email"
                class="form-control"
                id="email"
                name="email"
                placeholder="admin@example.com"
                value="<?= htmlspecialchars(
                    $_POST['email'] ?? '',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
                autocomplete="username"
                required
            >
        </div>

        <div class="mb-4">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <label for="password" class="form-label mb-0">Password</label>
                <a href="forgot_password.php" class="auth-link">Forgot Password?</a>
            </div>
            <div class="password-wrap">
                <input
                    type="password"
                    class="form-control"
                    id="password"
                    name="password"
                    placeholder="Enter your password"
                    autocomplete="current-password"
                    required
                >
                <button
                    type="button"
                    class="toggle-password"
                    id="togglePassword"
                    aria-controls="password"
                >Show</button>
            </div>
        </div>

        <button type="submit" class="btn login-btn">Sign In</button>
    </form>

    <p class="footer-text">
        &copy; <?= date('Y') ?> Kifala Trust. All rights reserved.
    </p>
</main>

<script>
document.getElementById('togglePassword').addEventListener('click', function () {
    const input = document.getElementById('password');
    const isPassword = input.type === 'password';

    input.type = isPassword ? 'text' : 'password';
    this.textContent = isPassword ? 'Hide' : 'Show';
});
</script>

</body>
</html>
