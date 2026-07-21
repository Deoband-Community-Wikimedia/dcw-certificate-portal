<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    verify_csrf_token($csrf);

    if (isset($_POST['action']) && $_POST['action'] === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($newPassword !== $confirmPassword) {
            $error = "New passwords do not match.";
        } elseif (strlen($newPassword) < 6) {
            $error = "Password must be at least 6 characters long.";
        } else {
            // Verify current password for logged in admin
            $currentUsername = $_SESSION['admin_username'] ?? 'admin';
            $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ?");
            $stmt->execute([$currentUsername]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($currentPassword, $admin['password_hash'])) {
                // Hash new password
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $updateStmt = $pdo->prepare("UPDATE admin_users SET password_hash = ? WHERE username = ?");
                if ($updateStmt->execute([$newHash, $currentUsername])) {
                    log_audit_action($pdo, 'Changed Password', "Admin User: {$currentUsername}");
                    $success = "Password updated successfully.";
                } else {
                    $error = "Failed to update password. Please try again.";
                }
            } else {
                $error = "Incorrect current password.";
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'create_user') {
        $newUsername = trim($_POST['new_username'] ?? '');
        $newPassword = $_POST['create_password'] ?? '';
        $newEmail = trim($_POST['new_email'] ?? '');

        if (empty($newUsername) || empty($newPassword)) {
            $error = "Username and password are required.";
        } elseif (strlen($newPassword) < 6) {
            $error = "Password must be at least 6 characters long.";
        } elseif ($newEmail !== '' && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address (required for password resets).";
        } else {
            // Check if username exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE username = ?");
            $stmt->execute([$newUsername]);
            if ($stmt->fetchColumn() > 0) {
                $error = "Username already exists. Please choose another.";
            } else {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $insertStmt = $pdo->prepare("INSERT INTO admin_users (username, password_hash, email) VALUES (?, ?, ?)");
                if ($insertStmt->execute([$newUsername, $newHash, $newEmail ?: null])) {
                    log_audit_action($pdo, 'Created Admin', "New Admin User: {$newUsername}");
                    $success = "New admin user '{$newUsername}' created successfully.";
                } else {
                    $error = "Failed to create user. Please try again.";
                }
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'update_email') {
        // Lets the logged-in admin add/change their own recovery email so
        // they can receive password reset links (issue #84).
        $myEmail = trim($_POST['my_email'] ?? '');
        $currentUsername = $_SESSION['admin_username'] ?? '';

        if ($myEmail !== '' && !filter_var($myEmail, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            $updateStmt = $pdo->prepare("UPDATE admin_users SET email = ? WHERE username = ?");
            if ($updateStmt->execute([$myEmail ?: null, $currentUsername])) {
                log_audit_action($pdo, 'Updated Email', "Admin User: {$currentUsername}");
                $success = "Your recovery email has been updated.";
            } else {
                $error = "Failed to update email. Please try again.";
            }
        }
    }
}

// Fetch all admins for display
$stmtAdmins = $pdo->query("SELECT id, username, email FROM admin_users ORDER BY id ASC");
$allAdmins = $stmtAdmins->fetchAll();

// Current admin's email (for the recovery-email form).
$myEmail = '';
foreach ($allAdmins as $adm) {
    if ($adm['id'] == ($_SESSION['admin_id'] ?? 0)) {
        $myEmail = $adm['email'] ?? '';
        break;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="../assets/DCW_logo.png">
    <meta charset="UTF-8">
    <title>User Management</title>
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
</head>
<body>

<div class="navbar">
    <div style="display: flex; align-items: center; gap: 15px;">
        <img src="../assets/DCW_logo.png" alt="DCW Logo" width="35" height="35" decoding="async" style="height: 35px; width: 35px; background: white; padding: 2px; border-radius: 50%;">
        <span style="font-size: 18px; font-weight: bold; letter-spacing: 0.5px;">Admin Panel - User Management</span>
    </div>
    <div>
        <a href="dashboard.php">Dashboard</a>
        <a href="logout.php">Logout</a>
    </div>
</div>

<div class="container" style="max-width: 900px; display: flex; gap: 30px; flex-wrap: wrap;">
    
    <!-- Change Password Section -->
    <div style="flex: 1; min-width: 300px;">
        <h2 style="margin-top: 0;">Change My Password</h2>
        <div class="upload-box">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="action" value="change_password">
                <div class="form-group">
                    <label>Current Password</label>
                    <div class="password-wrapper"><input type="password" name="current_password" required id="current-password"><button type="button" class="password-toggle" onclick="togglePassword('current-password')"><svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg></button></div>
                </div>
                
                <div class="form-group">
                    <label>New Password</label>
                    <div class="password-wrapper"><input type="password" name="new_password" required id="new-password"><button type="button" class="password-toggle" onclick="togglePassword('new-password')"><svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg></button></div>
                </div>
                
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <div class="password-wrapper"><input type="password" name="confirm_password" required id="confirm-password"><button type="button" class="password-toggle" onclick="togglePassword('confirm-password')"><svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg></button></div>
                </div>
                
                <button type="submit" class="btn" style="width: 100%;">Update Password</button>
            </form>
        </div>

        <h2 style="margin-top: 30px;">My Recovery Email</h2>
        <div class="upload-box">
            <p style="font-size: 13px; color: #555; margin-top: 0;">Add an email to your account so you can reset your password if you ever forget it.</p>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="action" value="update_email">
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="my_email" value="<?= htmlspecialchars($myEmail) ?>" placeholder="admin@example.org">
                </div>
                <button type="submit" class="btn" style="width: 100%;">Save Recovery Email</button>
            </form>
        </div>
    </div>

    <!-- Create New User Section -->
    <div style="flex: 1; min-width: 300px;">
        <h2 style="margin-top: 0;">Create New Admin</h2>
        <div class="upload-box">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="action" value="create_user">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="new_username" required>
                </div>

                <div class="form-group">
                    <label>Email <span style="font-size: 11px; color: #999; font-weight: normal;">(recommended &mdash; needed for password resets)</span></label>
                    <input type="email" name="new_email" placeholder="admin@example.org">
                </div>

                <div class="form-group">
                    <label>Password</label>
                    <div class="password-wrapper"><input type="password" name="create_password" required id="create-password"><button type="button" class="password-toggle" onclick="togglePassword('create-password')"><svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg></button></div>
                </div>
                
                <button type="submit" class="btn" style="width: 100%;">Create Admin User</button>
            </form>
        </div>
        
        <h2 style="margin-top: 30px;">Existing Admins</h2>
        <div class="upload-box">
            <div class="table-responsive" style="margin-top: 0; border: none;">
                <table style="width: 100%; border-collapse: collapse; font-size: 14px; min-width: auto;">
                    <thead>
                        <tr>
                            <th style="padding: 10px; border-bottom: 2px solid #ddd; text-align: left;">Username</th>
                            <th style="padding: 10px; border-bottom: 2px solid #ddd; text-align: left;">Email</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($allAdmins as $adm): ?>
                            <tr>
                                <td style="padding: 10px; border-bottom: 1px solid #eee;">
                                    <?= htmlspecialchars($adm['username']) ?>
                                    <?php if ($adm['id'] == $_SESSION['admin_id']): ?>
                                        <span style="color: #28a745; font-size: 12px; font-weight: bold; margin-left: 10px;">(You)</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px; border-bottom: 1px solid #eee; color: <?= empty($adm['email']) ? '#c0392b' : '#555' ?>;">
                                    <?= !empty($adm['email']) ? htmlspecialchars($adm['email']) : '<em>Not set</em>' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script src="script.js"></script>
<?php if ($error): ?>
<script>
    window.flashMessage = <?= json_encode($error) ?>;
    window.flashMessageType = 'error';
</script>
<?php endif; ?>
<?php if ($success): ?>
<script>
    window.flashMessage = <?= json_encode($success) ?>;
    window.flashMessageType = 'success';
</script>
<?php endif; ?>
<script>function togglePassword(id){const input=document.getElementById(id);input.type=input.type==='password'?'text':'password';}</script>
</body>
</html>

