<?php
session_start();
require_once '../config.php';
require_once '../helpers.php';

// Already logged in? Redirect to dashboard
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header("Location: dashboard.php");
    exit;
}

$rawToken = trim($_GET['token'] ?? $_POST['token'] ?? '');
$invite = null;
$error = '';
$success = '';

if ($rawToken !== '') {
    $invite = find_invitation_by_token($pdo, $rawToken);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invite) {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $displayName = trim($_POST['display_name'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

    if (strlen($password) < 6) {
        $error = __('admin.forgot-password.error.short-password');
    } elseif ($password !== $confirmPassword) {
        $error = __('admin.forgot-password.error.mismatch-password');
    } else {
        $result = accept_admin_invitation($pdo, (int)$invite['id'], $password, $displayName);
        if ($result['success']) {
            log_audit_action($pdo, 'Accepted Admin Invitation', "User {$result['username']} activated for Org #{$invite['organization_id']} ({$invite['org_name']})");

            // Automatically log in
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $result['user_id'];
            $_SESSION['admin_username'] = $result['username'];
            $_SESSION['admin_role'] = 'org_admin';
            $_SESSION['admin_org_id'] = $result['organization_id'];

            header("Location: dashboard.php?welcome=1");
            exit;
        } else {
            $errKey = $result['error'] ?? 'invalid-or-expired';
            $error = __('admin.invitation.error.' . $errKey);
            if ($error === 'admin.invitation.error.' . $errKey) {
                $error = 'Failed to activate account. The invitation may have expired or already been accepted.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(i18n_get_lang()) ?>" dir="<?= i18n_get_dir() ?>">
<head>
    <link rel="icon" type="image/png" href="../assets/DCW_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __e('admin.invitation.page-title') ?></title>
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
    <?php i18n_lang_switcher_css(); ?>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f4f6f8;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            box-sizing: border-box;
        }
        .invite-card {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            width: 100%;
            max-width: 480px;
            padding: 36px 32px;
            border: 1px solid #e2e8f0;
            box-sizing: border-box;
        }
        .invite-logo-wrap {
            text-align: center;
            margin-bottom: 20px;
        }
        .invite-logo {
            height: 52px;
            width: auto;
            max-width: 180px;
        }
        .badge-tenant {
            display: inline-block;
            background: #e0f2fe;
            color: #0369a1;
            padding: 4px 12px;
            border-radius: 16px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 8px;
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 6px;
        }
        .form-group input {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
            outline: none;
            transition: border-color 0.2s;
        }
        .form-group input:focus {
            border-color: #106b9a;
            box-shadow: 0 0 0 3px rgba(16, 107, 154, 0.1);
        }
        .btn-setup {
            width: 100%;
            background: #106b9a;
            color: white;
            border: none;
            padding: 13px 20px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
            margin-top: 8px;
        }
        .btn-setup:hover {
            background: #0c567a;
        }
        .msg-error {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            color: #991b1b;
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 20px;
        }
        .back-link {
            text-align: center;
            margin-top: 24px;
            font-size: 13px;
        }
        .back-link a {
            color: #106b9a;
            text-decoration: none;
            font-weight: 500;
        }
        .back-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

<div class="invite-card">
    <div style="display: flex; justify-content: flex-end; margin-bottom: 15px;">
        <?php i18n_lang_switcher(); ?>
    </div>

    <?php if (!$invite): ?>
        <!-- Invalid or Expired Token State -->
        <div style="text-align: center; padding: 20px 0;">
            <div style="width: 56px; height: 56px; border-radius: 50%; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
                <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
            </div>
            <h2 style="font-size: 20px; font-weight: 700; color: #0f172a; margin-top: 0; margin-bottom: 8px;">
                <?= __e('admin.invitation.invalid-title') ?>
            </h2>
            <p style="font-size: 14px; color: #64748b; line-height: 1.5; margin-bottom: 24px;">
                <?= __e('admin.invitation.invalid-desc') ?>
            </p>
            <a href="login.php" class="btn-setup" style="display: inline-block; text-decoration: none; text-align: center;">
                <?= __e('admin.forgot-password.back-to-login') ?>
            </a>
        </div>
    <?php else: ?>
        <!-- Valid Invitation State -->
        <div class="invite-logo-wrap">
            <?php 
                $logoSrc = !empty($invite['org_logo']) && file_exists('../' . $invite['org_logo'])
                    ? '../' . $invite['org_logo']
                    : '../assets/DCW_logo.png';
            ?>
            <img src="<?= htmlspecialchars($logoSrc) ?>" alt="Organization Logo" class="invite-logo">
            <br>
            <span class="badge-tenant"><?= htmlspecialchars($invite['org_name']) ?> (<?= htmlspecialchars($invite['org_code']) ?>)</span>
        </div>

        <h1 style="font-size: 22px; font-weight: 700; color: #0f172a; text-align: center; margin: 0 0 6px 0;">
            <?= __e('admin.invitation.welcome-heading') ?>
        </h1>
        <p style="font-size: 13px; color: #64748b; text-align: center; margin: 0 0 24px 0; line-height: 1.5;">
            <?= __('admin.invitation.welcome-subheading', ['org' => htmlspecialchars($invite['org_name'])]) ?>
        </p>

        <?php if ($error): ?>
            <div class="msg-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="accept_invite.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
            <input type="hidden" name="token" value="<?= htmlspecialchars($rawToken) ?>">

            <div class="form-group">
                <label for="lead_email"><?= __e('admin.orgs.label.contact-email') ?></label>
                <input type="email" id="lead_email" value="<?= htmlspecialchars($invite['email']) ?>" disabled style="background: #f8fafc; color: #64748b; cursor: not-allowed;">
            </div>

            <div class="form-group">
                <label for="display_name"><?= __e('admin.invitation.label.username') ?> <span style="color: #dc2626;">*</span></label>
                <input type="text" id="display_name" name="display_name" required value="<?= htmlspecialchars($invite['username']) ?>" placeholder="e.g. maria_lead">
            </div>

            <div class="form-group">
                <label for="password"><?= __e('admin.forgot-password.label.new-password') ?> <span style="color: #dc2626;">*</span></label>
                <input type="password" id="password" name="password" required minlength="6" placeholder="At least 6 characters" autocomplete="new-password">
            </div>

            <div class="form-group">
                <label for="confirm_password"><?= __e('admin.forgot-password.label.confirm-password') ?> <span style="color: #dc2626;">*</span></label>
                <input type="password" id="confirm_password" name="confirm_password" required minlength="6" placeholder="Re-enter your password" autocomplete="new-password">
            </div>

            <button type="submit" class="btn-setup">
                <?= __e('admin.invitation.btn-complete') ?>
            </button>
        </form>

        <div class="back-link">
            <a href="login.php"><?= __e('admin.forgot-password.back-to-login') ?></a>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
