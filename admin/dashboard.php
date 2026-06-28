<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

// Handle deletion
if (isset($_POST['delete_event_id'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    verify_csrf_token($csrf);
    
    $passcode = trim($_POST['super_admin_passcode'] ?? '');
    
    if ($passcode !== SUPER_ADMIN_PASSCODE) {
        // Redirect with error
        header("Location: dashboard.php?msg=auth_error");
        exit;
    }
    
    $deleteId = $_POST['delete_event_id'];
    
    // Get event name for logging before deleting
    $stmtName = $pdo->prepare("SELECT name FROM events WHERE id = ?");
    $stmtName->execute([$deleteId]);
    $deletedEventName = $stmtName->fetchColumn() ?: 'Unknown';
    
    $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
    $stmt->execute([$deleteId]);
    
    log_audit_action($pdo, 'Deleted Event', "Event ID: {$deleteId}, Name: {$deletedEventName}");
    
    header("Location: dashboard.php?msg=deleted");
    exit;
}

$limit = 10;
$page = isset($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$searchQuery = "";
$params = [];

if ($search !== '') {
    $searchQuery = " WHERE name LIKE ?";
    $params[] = "%$search%";
}

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM events" . $searchQuery);
$stmtCount->execute($params);
$totalRecords = $stmtCount->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

$stmt = $pdo->prepare("SELECT * FROM events" . $searchQuery . " ORDER BY created_at DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset);
$stmt->execute($params);
$events = $stmt->fetchAll();

// Analytics Queries
$totalEvents = $pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
$totalParticipants = $pdo->query("SELECT COUNT(DISTINCT participant_id) FROM event_participants")->fetchColumn();
$totalCerts = $pdo->query("SELECT COUNT(*) FROM event_participants WHERE certificate_id IS NOT NULL")->fetchColumn();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
</head>
<body>

<div class="navbar">
    <div style="display: flex; align-items: center; gap: 15px;">
        <img src="../assets/DCW_logo.png" alt="DCW Logo" width="35" height="35" decoding="async" style="height: 35px; width: 35px; background: white; padding: 2px; border-radius: 50%;">
        <span style="font-size: 18px; font-weight: bold; letter-spacing: 0.5px;">Admin Panel - Certificate System</span>
    </div>
    <div>
        <a href="#" onclick="return viewAuditLogs();" style="margin-right: 15px;">Audit Logs</a>
        <a href="manage_users.php" style="margin-right: 15px;">Manage Users</a>
        <a href="logout.php">Logout</a>
    </div>
</div>

<form id="auditLogForm" method="POST" action="audit_logs.php" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
    <input type="hidden" name="super_admin_passcode" id="audit_passcode" value="">
</form>

<div class="container">
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" width="28" height="28"><path fill="currentColor" d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20a2 2 0 0 0 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zM9 14H7v-2h2v2zm4 0h-2v-2h2v2zm4 0h-2v-2h2v2zm-8 4H7v-2h2v2zm4 0h-2v-2h2v2zm4 0h-2v-2h2v2z"/></svg>
            </div>
            <div class="stat-details">
                <div class="stat-value"><?= number_format($totalEvents) ?></div>
                <div class="stat-label">Total Events</div>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" width="28" height="28"><path fill="currentColor" d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>
            </div>
            <div class="stat-details">
                <div class="stat-value"><?= number_format($totalCerts) ?></div>
                <div class="stat-label">Issued Certificates</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" width="28" height="28"><path fill="currentColor" d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
            </div>
            <div class="stat-details">
                <div class="stat-value"><?= number_format($totalParticipants) ?></div>
                <div class="stat-label">Total Participants</div>
            </div>
        </div>
    </div>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0;">Events List</h2>
        <div style="display: flex; gap: 15px;">
            <form method="GET" style="display: flex; gap: 10px;">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search events..." style="width: 250px;">
                <button type="submit" class="btn">Search</button>
                <?php if($search): ?>
                    <a href="dashboard.php" class="btn btn-red">Clear</a>
                <?php endif; ?>
            </form>
            <a href="create_event.php" class="btn">Create New Event</a>
        </div>
    </div>

    <?php if (count($events) > 0): ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Created At</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $event): ?>
                        <tr>
                            <td><?= htmlspecialchars($event['id']) ?></td>
                            <td>
                                <a href="edit_event.php?id=<?= $event['id'] ?>" class="event-link" title="Edit Event">
                                    <?= htmlspecialchars($event['name']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($event['created_at']) ?></td>
                            <td class="actions" style="text-align: right;">
                                <div class="action-menu-container">
                                    <button type="button" class="kebab-btn" onclick="toggleMenu(this, <?= $event['id'] ?>)" title="Actions">
                                        <svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>
                                    </button>
                                    <div class="dropdown-menu" id="menu-<?= $event['id'] ?>">
                                        <a href="edit_event.php?id=<?= $event['id'] ?>" class="dropdown-item">
                                            <svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                            Edit Event
                                        </a>
                                        <a href="manage_roles.php?event_id=<?= $event['id'] ?>" class="dropdown-item">
                                            <svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"/></svg>
                                            Manage Roles
                                        </a>
                                        <a href="manage_participants.php?id=<?= $event['id'] ?>" class="dropdown-item">
                                            <svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                                            Manage Participants
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="pagination">
            <?php 
            $searchParam = $search ? '&search=' . urlencode($search) : '';
            ?>
            <a href="?page=<?= max(1, $page - 1) . $searchParam ?>" class="page-btn <?= ($page <= 1) ? 'disabled' : '' ?>">Prev</a>
            
            <?php for($i = 1; $i <= max(1, $totalPages); $i++): ?>
                <a href="?page=<?= $i . $searchParam ?>" class="page-btn <?= ($i == $page) ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            
            <a href="?page=<?= min(max(1, $totalPages), $page + 1) . $searchParam ?>" class="page-btn <?= ($page >= max(1, $totalPages)) ? 'disabled' : '' ?>">Next</a>
        </div>

    <?php else: ?>
        <p>No events found. Click "Create New Event" to get started.</p>
    <?php endif; ?>
</div>

<script src="script.js"></script>
<script>
    function toggleMenu(btn, id) {
        const menus = document.querySelectorAll('.dropdown-menu');
        const isShowing = document.getElementById('menu-' + id).classList.contains('show');
        
        menus.forEach(m => m.classList.remove('show'));
        document.querySelectorAll('.kebab-btn').forEach(b => b.classList.remove('active'));
        
        if (!isShowing) {
            document.getElementById('menu-' + id).classList.add('show');
            btn.classList.add('active');
        }
    }

    function viewAuditLogs() {
        let code = prompt("Security Check: Please enter the Super Admin Passcode to view Audit Logs:");
        if (code) {
            document.getElementById('audit_passcode').value = code;
            document.getElementById('auditLogForm').submit();
            return false;
        }
        return false;
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.action-menu-container')) {
            document.querySelectorAll('.dropdown-menu').forEach(m => m.classList.remove('show'));
            document.querySelectorAll('.kebab-btn').forEach(b => b.classList.remove('active'));
        }
    });
</script>
<?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
<script>
    window.flashMessage = 'Event deleted successfully.';
    window.flashMessageType = 'success';
</script>
<?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'auth_error'): ?>
<script>
    window.flashMessage = 'Security Error: Invalid Super Admin Passcode.';
    window.flashMessageType = 'error';
</script>
<?php endif; ?>
</body>
</html>
