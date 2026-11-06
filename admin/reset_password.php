<?php
session_start();

require_once __DIR__ . '/config/db.php';

if (isset($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$token = is_string($token) ? $token : '';
$error = '';
$validToken = false;
$resetRow = null;

if (empty($_SESSION['reset_csrf_token'])) {
    $_SESSION['reset_csrf_token'] = bin2hex(random_bytes(32));
}

function findValidReset(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT id, admin_id FROM password_resets
         WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
         LIMIT 1"
    );
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

$pdo = null;

try {
    $pdo = getPdoConnection();
    $resetRow = findValidReset($pdo, $token);
    $validToken = $resetRow !== null;

    if ($validToken && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf_token'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!is_string($csrf) || !hash_equals($_SESSION['reset_csrf_token'], $csrf)) {
            $error = 'Invalid request. Please refresh the page and try again.';
        } elseif (strlen($password) < 8) {
            $error = 'Your password must be at least 8 characters.';
        } elseif (!hash_equals($password, $confirm)) {
            $error = 'The passwords do not match.';
        } else {
            $pdo->beginTransaction();

            $pdo->prepare("UPDATE admin_users SET password = ? WHERE id = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$resetRow['admin_id']]);

            // Burn this token and any others for the account.
            $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE admin_id = ? AND used_at IS NULL")
                ->execute([(int)$resetRow['admin_id']]);

            $pdo->commit();

            $_SESSION['reset_csrf_token'] = bin2hex(random_bytes(32));
            header('Location: login.php?reset=1');
            exit;
        }
    }
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Reset password error: ' . $e->getMessage());
    $error = 'Your password could not be reset. Please try again.';
} finally {
    if ($pdo instanceof PDO) {
        closePdoConnection($pdo);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>Reset Password | Kifala Trust</title>

    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon-32.png">
    <link rel="icon" type="image/png" sizes="512x512" href="assets/img/favicon-512.png">
    <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
    <meta name="theme-color" content="#0b9444">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/auth.css" rel="stylesheet">
</head>
<body>

<main class="login-card">
    <img src="assets/img/kifala_trust_logo.png" alt="Kifala Trust" class="brand" width="280" height="113">

    <?php if (!$validToken): ?>
        <h1 class="page-title">This link has expired</h1>
        <p class="subtitle">Reset links work once and expire after 30 minutes. Request a new one to continue.</p>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <a href="forgot_password.php" class="btn login-btn d-flex align-items-center justify-content-center text-decoration-none">Request a new link</a>
        <p class="text-center mt-3 mb-0"><a href="login.php" class="auth-link">Back to sign in</a></p>
    <?php else: ?>
        <h1 class="page-title">Choose a new password</h1>
        <p class="subtitle">Use at least 8 characters.</p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="POST" action="reset_password.php">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars($_SESSION['reset_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

            <div class="mb-3">
                <label for="password" class="form-label">New password</label>
                <div class="password-wrap">
                    <input type="password" class="form-control" id="password" name="password"
                           autocomplete="new-password" minlength="8" required>
                    <button type="button" class="toggle-password" id="togglePassword" aria-controls="password">Show</button>
                </div>
            </div>

            <div class="mb-4">
                <label for="confirm_password" class="form-label">Confirm new password</label>
                <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                       autocomplete="new-password" minlength="8" required>
            </div>

            <button type="submit" class="btn login-btn">Save new password</button>
        </form>
    <?php endif; ?>

    <p class="footer-text">&copy; <?= date('Y') ?> Kifala Trust. All rights reserved.</p>
</main>

<script>
const toggle = document.getElementById('togglePassword');
if (toggle) {
    toggle.addEventListener('click', function () {
        const input = document.getElementById('password');
        const confirm = document.getElementById('confirm_password');
        const show = input.type === 'password';
        input.type = confirm.type = show ? 'text' : 'password';
        this.textContent = show ? 'Hide' : 'Show';
    });
}
</script>

</body>
</html>
