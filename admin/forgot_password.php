<?php
/**
 * Admin password reset (issue #84).
 *
 * Two stages, both handled here:
 *   1. Request stage  - admin submits their username/email, we email a one-time link.
 *   2. Reset stage     - admin follows the link (?token=...) and sets a new password.
 *
 * Security notes:
 *   - Only a SHA-256 hash of the token is stored; the raw token lives only in the email link.
 *   - Tokens expire after 60 minutes and are single-use (cleared on success).
 *   - The request stage always shows the same generic message to avoid user/email enumeration.
 */
session_start();
require_once '../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

// Already logged in? No reason to be here.
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header("Location: dashboard.php");
    exit;
}

const RESET_TTL_MINUTES = 60;
const GENERIC_REQUEST_MSG = "If an account with a registered email exists for that identifier, a password reset link has been sent. Please check your inbox.";

$error = '';
$success = '';
$mode = 'request';          // 'request' | 'reset'
$rawToken = trim($_GET['token'] ?? $_POST['token'] ?? '');

/**
 * Resolves an admin row from a raw reset token (valid + unexpired), or null.
 */
function findAdminByResetToken(PDO $pdo, string $rawToken) {
    if ($rawToken === '') {
        return null;
    }
    $tokenHash = hash('sha256', $rawToken);
    $stmt = $pdo->prepare("SELECT id, username FROM admin_users WHERE reset_token_hash = ? AND reset_expires_at IS NOT NULL AND reset_expires_at > NOW()");
    $stmt->execute([$tokenHash]);
    return $stmt->fetch() ?: null;
}

if ($rawToken !== '') {
    $mode = 'reset';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    // ---- Stage 1: request a reset link ----
    if ($action === 'request_reset') {
        $identifier = trim($_POST['identifier'] ?? '');

        if ($identifier === '') {
            $error = "Please enter your username or email address.";
        } else {
            $stmt = $pdo->prepare("SELECT id, username, email FROM admin_users WHERE username = ? OR email = ?");
            $stmt->execute([$identifier, $identifier]);
            $admin = $stmt->fetch();

            if ($admin && !empty($admin['email'])) {
                // Generate a one-time token; store only its hash.
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);

                $upd = $pdo->prepare("UPDATE admin_users SET reset_token_hash = ?, reset_expires_at = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?");
                $upd->execute([$tokenHash, RESET_TTL_MINUTES, $admin['id']]);

                $resetUrl = adminPortalBaseUrl() . '/admin/forgot_password.php?token=' . urlencode($token);
                // Best-effort send; we still show the generic message regardless of the result.
                sendAdminResetEmail($admin['email'], $admin['username'], $resetUrl);
                log_audit_action($pdo, 'Password Reset Requested', "Admin User: {$admin['username']}");
            }

            // Same message whether or not the account/email existed (no enumeration).
            $success = GENERIC_REQUEST_MSG;
            $mode = 'request';
        }

    // ---- Stage 2: set a new password using the token ----
    } elseif ($action === 'do_reset') {
        $mode = 'reset';
        $admin = findAdminByResetToken($pdo, $rawToken);
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!$admin) {
            $error = "This reset link is invalid or has expired. Please request a new one.";
            $mode = 'request';
        } elseif (strlen($newPassword) < 6) {
            $error = "Password must be at least 6 characters long.";
        } elseif ($newPassword !== $confirmPassword) {
            $error = "The two passwords do not match.";
        } else {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE admin_users SET password_hash = ?, reset_token_hash = NULL, reset_expires_at = NULL WHERE id = ?");
            $upd->execute([$newHash, $admin['id']]);
            log_audit_action($pdo, 'Password Reset Completed', "Admin User: {$admin['username']}");

            $success = "Your password has been reset. You can now log in with your new password.";
            $mode = 'done';
        }
    }
}

// For a fresh GET with a token, validate it up-front so we can show a clear error.
if ($mode === 'reset' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!findAdminByResetToken($pdo, $rawToken)) {
        $error = "This reset link is invalid or has expired. Please request a new one.";
        $mode = 'request';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" href="../assets/DCW_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Admin</title>
    <style>
        body { font-family: sans-serif; background: #f4f5f7; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .login-card { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); width: 100%; max-width: 400px; box-sizing: border-box; }
        .login-card h2 { margin-top: 0; margin-bottom: 8px; text-align: center; color: #333; }
        .subtitle { text-align: center; color: #777; font-size: 13px; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; color: #555; font-size: 14px; }
        .form-group input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        .btn { width: 100%; padding: 10px; background: #106b9a; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; margin-top: 10px; font-weight: bold; transition: background-color 0.2s; }
        .btn:hover { background: #0c567a; }
        .error { color: #d9534f; background-color: #f9f2f2; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 14px; text-align: center; }
        .success { color: #256d2c; background-color: #eef8ee; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 14px; text-align: center; }
        .link-row { text-align: center; margin-top: 18px; font-size: 13px; }
        .link-row a { color: #106b9a; text-decoration: none; }
        .link-row a:hover { text-decoration: underline; }
        .password-wrapper { position: relative; display: block; }
        .password-toggle { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #64748b; padding: 0; display: flex; align-items: center; }
    </style>
</head>

<body>
    <div class="login-card">
        <div style="text-align: center; margin-bottom: 10px;">
            <img src="../assets/DCW_logo.png" alt="DCW Logo"
                style="height: 90px; background: white; padding: 5px; border-radius: 50%; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
        </div>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if ($mode === 'done'): ?>
            <h2>All Set</h2>
            <div class="link-row"><a href="login.php">&larr; Back to Login</a></div>

        <?php elseif ($mode === 'reset'): ?>
            <h2>Set a New Password</h2>
            <p class="subtitle">Choose a strong password you haven't used before.</p>
            <form method="POST" action="forgot_password.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="action" value="do_reset">
                <input type="hidden" name="token" value="<?= htmlspecialchars($rawToken) ?>">
                <div class="form-group">
                    <label>New Password</label>
                    <div class="password-wrapper">
                        <input type="password" name="new_password" id="np" required minlength="6">
                        <button type="button" class="password-toggle" onclick="tp('np')"><svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg></button>
                    </div>
                </div>
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <div class="password-wrapper">
                        <input type="password" name="confirm_password" id="cp" required minlength="6">
                        <button type="button" class="password-toggle" onclick="tp('cp')"><svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg></button>
                    </div>
                </div>
                <button type="submit" class="btn">Update Password</button>
            </form>

        <?php else: /* request */ ?>
            <h2>Forgot Password</h2>
            <p class="subtitle">Enter your username or registered email and we'll send you a reset link.</p>
            <form method="POST" action="forgot_password.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="action" value="request_reset">
                <div class="form-group">
                    <label>Username or Email</label>
                    <input type="text" name="identifier" required autofocus>
                </div>
                <button type="submit" class="btn">Send Reset Link</button>
            </form>
            <div class="link-row"><a href="login.php">&larr; Back to Login</a></div>
        <?php endif; ?>
    </div>
    <script>function tp(id){const i=document.getElementById(id);i.type=i.type==='password'?'text':'password';}</script>
</body>

</html>
