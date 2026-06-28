<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

$passcode = trim($_POST['super_admin_passcode'] ?? '');

// If they submitted a passcode, check it and unlock session
if ($passcode !== '') {
    if ($passcode === SUPER_ADMIN_PASSCODE) {
        $_SESSION['audit_unlocked'] = true;
    } else {
        header("Location: dashboard.php?msg=auth_error");
        exit;
    }
}

// If session isn't unlocked, they are unauthorized
if (!isset($_SESSION['audit_unlocked']) || $_SESSION['audit_unlocked'] !== true) {
    header("Location: dashboard.php?msg=auth_error");
    exit;
}

$limit = 20;
$page = isset($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$stmtCount = $pdo->query("SELECT COUNT(*) FROM audit_logs");
$totalRecords = $stmtCount->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

$stmt = $pdo->prepare("SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset);
$stmt->execute();
$logs = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Audit Logs</title>
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
</head>
<body>

<div class="navbar">
    <div style="display: flex; align-items: center; gap: 15px;">
        <img src="../assets/DCW_logo.png" alt="DCW Logo" width="35" height="35" decoding="async" style="height: 35px; width: 35px; background: white; padding: 2px; border-radius: 50%;">
        <span style="font-size: 18px; font-weight: bold; letter-spacing: 0.5px;">Admin Panel - Audit Logs</span>
    </div>
    <div>
        <a href="dashboard.php" style="margin-right: 15px;">Dashboard</a>
        <a href="manage_users.php" style="margin-right: 15px;">Manage Users</a>
        <a href="logout.php">Logout</a>
    </div>
</div>

<div class="container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0;">Security Audit Logs</h2>
        <a href="dashboard.php" class="btn" style="background: #6c757d;">Back</a>
    </div>

    <?php if (count($logs) > 0): ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Admin Username</th>
                        <th>Action Type</th>
                        <th>Details</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= htmlspecialchars($log['id']) ?></td>
                            <td style="font-weight: bold; color: #106b9a;"><?= htmlspecialchars($log['admin_username']) ?></td>
                            <td><span style="background: #f1f5f9; padding: 4px 8px; border-radius: 4px; font-size: 13px; font-weight: 600; border: 1px solid #e2e8f0;"><?= htmlspecialchars($log['action_type']) ?></span></td>
                            <td><?= htmlspecialchars($log['details']) ?></td>
                            <td style="color: #64748b; font-size: 13px;"><?= htmlspecialchars($log['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="pagination">
            <a href="?page=<?= max(1, $page - 1) ?>" class="page-btn <?= ($page <= 1) ? 'disabled' : '' ?>">Prev</a>
            
            <?php for($i = 1; $i <= max(1, $totalPages); $i++): ?>
                <a href="?page=<?= $i ?>" class="page-btn <?= ($i == $page) ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            
            <a href="?page=<?= min(max(1, $totalPages), $page + 1) ?>" class="page-btn <?= ($page >= max(1, $totalPages)) ? 'disabled' : '' ?>">Next</a>
        </div>
    <?php else: ?>
        <p>No audit logs found yet. Actions like editing or deleting events will appear here.</p>
    <?php endif; ?>
</div>

</body>
</html>
