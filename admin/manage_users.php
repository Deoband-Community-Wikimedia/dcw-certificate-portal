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
        
        if (empty($newUsername) || empty($newPassword)) {
            $error = "Username and password are required.";
        } elseif (strlen($newPassword) < 6) {
            $error = "Password must be at least 6 characters long.";
        } else {
            // Check if username exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE username = ?");
            $stmt->execute([$newUsername]);
            if ($stmt->fetchColumn() > 0) {
                $error = "Username already exists. Please choose another.";
            } else {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $insertStmt = $pdo->prepare("INSERT INTO admin_users (username, password_hash) VALUES (?, ?)");
                if ($insertStmt->execute([$newUsername, $newHash])) {
                    log_audit_action($pdo, 'Created Admin', "New Admin User: {$newUsername}");
                    $success = "New admin user '{$newUsername}' created successfully.";
                } else {
                    $error = "Failed to create user. Please try again.";
                }
            }
        }
    }
}

// Fetch all admins for display
$stmtAdmins = $pdo->query("SELECT id, username FROM admin_users ORDER BY id ASC");
$allAdmins = $stmtAdmins->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
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
                    <input type="password" name="current_password" required>
                </div>
                
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" required>
                </div>
                
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" required>
                </div>
                
                <button type="submit" class="btn" style="width: 100%;">Update Password</button>
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
                    <label>Password</label>
                    <input type="password" name="create_password" required>
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
</body>
</html>
