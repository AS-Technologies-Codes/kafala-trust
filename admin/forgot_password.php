<?php
session_start();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/PHPMailer/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

if (isset($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Base URL used in the reset email. Set KIFALA_APP_URL (e.g. https://admin.example.org)
// so the link can't be influenced by a spoofed Host header.
function appBaseUrl(): string
{
    $configured = getenv('KIFALA_APP_URL');
    if ($configured) {
        return rtrim($configured, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $scheme . '://' . $host . $dir;
}

// Sends the reset email through SMTP (settings in config/mail.php).
function sendResetEmail(string $toEmail, string $toName, string $link): bool
{
    $cfg = require __DIR__ . '/config/mail.php';

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $cfg['host'];
        $mail->Port       = $cfg['port'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['username'];
        $mail->Password   = $cfg['password'];
        $mail->SMTPSecure = $cfg['encryption'] === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 15;

        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($toEmail, $toName);

        $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');

        $mail->isHTML(true);
        $mail->Subject = 'Reset your Kifala Trust admin password';
        $mail->Body    = "<p>Hello {$safeName},</p>"
            . "<p>We received a request to reset your Kifala Trust admin password. "
            . "Click the button below to choose a new password. The link expires in 30 minutes and works once.</p>"
            . "<p><a href=\"{$safeLink}\" style=\"display:inline-block;background:#0b9444;color:#ffffff;"
            . "padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold;\">Reset password</a></p>"
            . "<p>Or copy this link into your browser:<br>{$safeLink}</p>"
            . "<p>If you didn't request this, you can ignore this email.</p>"
            . "<p>Kifala Trust</p>";
        $mail->AltBody = "Hello {$toName},\n\nReset your Kifala Trust admin password (expires in 30 minutes, works once):\n{$link}\n\nIf you didn't request this, ignore this email.";

        return $mail->send();
    } catch (Throwable $e) {
        error_log('Password reset SMTP error: ' . $e->getMessage());
        return false;
    }
}

$error = '';
$sent = false;

if (empty($_SESSION['forgot_csrf_token'])) {
    $_SESSION['forgot_csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!is_string($csrfToken) || !hash_equals($_SESSION['forgot_csrf_token'], $csrfToken)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $pdo = null;

        try {
            $pdo = getPdoConnection();

            $stmt = $pdo->prepare(
                "SELECT id, name, email FROM admin_users WHERE email = ? LIMIT 1"
            );
            $stmt->execute([$email]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($admin) {
                // Throttle: one request per minute per account.
                $recent = $pdo->prepare(
                    "SELECT COUNT(*) FROM password_resets
                     WHERE admin_id = ? AND created_at > (NOW() - INTERVAL 1 MINUTE)"
                );
                $recent->execute([(int)$admin['id']]);

                if ((int)$recent->fetchColumn() === 0) {
                    // Invalidate older unused tokens.
                    $pdo->prepare(
                        "DELETE FROM password_resets WHERE admin_id = ? AND used_at IS NULL"
                    )->execute([(int)$admin['id']]);

                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);

                    $pdo->prepare(
                        "INSERT INTO password_resets (admin_id, token_hash, expires_at)
                         VALUES (?, ?, (NOW() + INTERVAL 30 MINUTE))"
                    )->execute([(int)$admin['id'], $tokenHash]);

                    $link = appBaseUrl() . '/reset_password.php?token=' . urlencode($token);
                    sendResetEmail($admin['email'], (string)$admin['name'], $link);
                }
            }

            // Same response whether or not the account exists (prevents email enumeration).
            $sent = true;
            $_SESSION['forgot_csrf_token'] = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            error_log('Forgot password error: ' . $e->getMessage());
            $error = 'Your request could not be completed. Please try again.';
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
    <title>Forgot Password | Kifala Trust</title>

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

    <h1 class="page-title">Forgot your password?</h1>
    <p class="subtitle">Enter your admin email and we'll send you a link to reset it.</p>

    <?php if ($sent): ?>
        <div class="alert alert-success" role="status">
            If an account exists for that email, a reset link is on its way. It expires in 30 minutes.
        </div>
        <a href="login.php" class="btn login-btn d-flex align-items-center justify-content-center text-decoration-none">Back to sign in</a>
    <?php else: ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="forgot_password.php">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars($_SESSION['forgot_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

            <div class="mb-3">
                <label for="email" class="form-label">Email Address</label>
                <input type="email" class="form-control" id="email" name="email"
                       placeholder="admin@example.com" autocomplete="username"
                       value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <button type="submit" class="btn login-btn">Send reset link</button>
        </form>

        <p class="text-center mt-3 mb-0"><a href="login.php" class="auth-link">Back to sign in</a></p>
    <?php endif; ?>

    <p class="footer-text">&copy; <?= date('Y') ?> Kifala Trust. All rights reserved.</p>
</main>

</body>
</html>
