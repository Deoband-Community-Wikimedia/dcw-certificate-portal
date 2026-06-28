<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

$eventId = $_GET['event_id'] ?? null;
if (!$eventId) {
    header("Location: dashboard.php");
    exit;
}

// Fetch event details
$stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
$stmt->execute([$eventId]);
$event = $stmt->fetch();
if (!$event) {
    header("Location: dashboard.php");
    exit;
}

$error = '';
$success = '';

function getUniqueFilename($dir, $filename) {
    $filename = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $filename);
    $info = pathinfo($filename);
    $name = $info['filename'];
    $ext  = isset($info['extension']) ? '.' . $info['extension'] : '';
    
    $counter = 1;
    $newFilename = $filename;
    while (file_exists($dir . $newFilename)) {
        $newFilename = $name . '(' . $counter . ')' . $ext;
        $counter++;
    }
    return $newFilename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    verify_csrf_token($csrf);

    if (isset($_POST['action']) && $_POST['action'] === 'add_role') {
        $roleName = trim($_POST['role_name'] ?? '');

        if (!$roleName) {
        $error = "Role name is required.";
    } elseif (!isset($_FILES['template']) || $_FILES['template']['error'] !== UPLOAD_ERR_OK) {
        $error = "Please upload a valid PDF template.";
    } else {
        $tplDir = '../uploads/templates/';
        if (!is_dir($tplDir)) mkdir($tplDir, 0777, true);

        $templateExt = strtolower(pathinfo($_FILES['template']['name'], PATHINFO_EXTENSION));
        if ($templateExt !== 'pdf') {
            $error = "Template must be a PDF file.";
        } else {
            $templateFile = getUniqueFilename($tplDir, $_FILES['template']['name']);
            move_uploaded_file($_FILES['template']['tmp_name'], $tplDir . $templateFile);

            $stmt = $pdo->prepare("INSERT INTO event_roles (event_id, role_name, template_file) VALUES (?, ?, ?)");
            $stmt->execute([$eventId, $roleName, $templateFile]);
            $success = "Role '$roleName' added successfully.";
        }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_role') {
        $passcode = trim($_POST['super_admin_passcode'] ?? '');
        if ($passcode !== SUPER_ADMIN_PASSCODE) {
            $error = "Security Error: Invalid Super Admin Passcode.";
        } else {
            $roleId = $_POST['role_id'];
            
            // Get role name for logging
            $stmtRoleName = $pdo->prepare("SELECT role_name FROM event_roles WHERE id = ?");
            $stmtRoleName->execute([$roleId]);
            $deletedRoleName = $stmtRoleName->fetchColumn() ?: 'Unknown';
            
            // Delete role
            $stmt = $pdo->prepare("DELETE FROM event_roles WHERE id = ? AND event_id = ?");
            $stmt->execute([$roleId, $eventId]);
            
            log_audit_action($pdo, 'Deleted Role', "Role: {$deletedRoleName} from Event ID: {$eventId}");
            $success = "Role deleted successfully.";
        }
    }
}

// Fetch all roles for this event
$stmt = $pdo->prepare("SELECT * FROM event_roles WHERE event_id = ? ORDER BY created_at DESC");
$stmt->execute([$eventId]);
$roles = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Roles - <?= htmlspecialchars($event['name']) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="navbar">
    <div style="display: flex; align-items: center; gap: 15px;">
        <img src="https://dcwwiki.org/images/5/56/DCW_logo.png" alt="DCW Logo" style="height: 35px; background: white; padding: 2px; border-radius: 50%;">
        <span style="font-size: 18px; font-weight: bold; letter-spacing: 0.5px;">Admin Panel</span>
    </div>
    <div>
        <a href="dashboard.php">Dashboard</a>
        <a href="manage_users.php" style="margin-right: 15px;">Manage Users</a>
        <a href="logout.php">Logout</a>
    </div>
</div>

<div class="container">
    <h2>Manage Roles: <?= htmlspecialchars($event['name']) ?></h2>

    <div style="display: flex; gap: 20px; flex-wrap: wrap;">
        
        <!-- Add Role Form -->
        <div class="upload-box" style="flex: 1; min-width: 300px;">
            <h3>Add New Role</h3>
            <p>Create a role and upload its specific PDF template.</p>
            <form method="POST" action="manage_roles.php?event_id=<?= $eventId ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="action" value="add_role">
                <div class="form-group">
                    <label>Role Name</label>
                    <input type="text" name="role_name" required placeholder="e.g. Speaker, Attendee">
                </div>
                <div class="form-group">
                    <label>Role Template (PDF)</label>
                    <input type="file" name="template" accept="application/pdf" required>
                </div>
                <button type="submit" class="btn" style="width: 100%;">Add Role</button>
            </form>
        </div>

        <!-- Roles List -->
        <div style="flex: 2; min-width: 400px;">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Role Name</th>
                            <th>Template</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($roles) > 0): ?>
                            <?php foreach ($roles as $role): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($role['role_name']) ?></strong></td>
                                    <td><a href="../uploads/templates/<?= htmlspecialchars($role['template_file']) ?>" target="_blank">View PDF</a></td>
                                    <td style="display:flex; gap:10px;">
                                        <a href="preview_event.php?role_id=<?= $role['id'] ?>" class="btn btn-sm" title="Visual Editor">
                                            <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9c.83 0 1.5-.67 1.5-1.5 0-.39-.15-.74-.39-1.01-.23-.26-.38-.61-.38-.99 0-.83.67-1.5 1.5-1.5H16c2.76 0 5-2.24 5-5 0-4.42-4.03-8-9-8zm-5.5 9c-.83 0-1.5-.67-1.5-1.5S5.67 9 6.5 9 8 9.67 8 10.5 7.33 12 6.5 12zm3-4C8.67 8 8 7.33 8 6.5S8.67 5 9.5 5s1.5.67 1.5 1.5S10.33 8 9.5 8zm5 0c-.83 0-1.5-.67-1.5-1.5S13.67 5 14.5 5s1.5.67 1.5 1.5S15.33 8 14.5 8zm3 4c-.83 0-1.5-.67-1.5-1.5S16.67 9 17.5 9s1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>
                                        </a>
                                        <form method="POST" action="manage_roles.php?event_id=<?= $eventId ?>" style="margin:0;" id="deleteForm_<?= $role['id'] ?>" onsubmit="return confirmDelete(<?= $role['id'] ?>);">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete_role">
                                            <input type="hidden" name="role_id" value="<?= $role['id'] ?>">
                                            <input type="hidden" name="super_admin_passcode" id="delete_passcode_<?= $role['id'] ?>" value="">
                                            <button type="submit" class="btn btn-sm btn-red" title="Delete Role">
                                                <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" style="text-align: center;">No roles created yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<script src="script.js"></script>
<script>
function confirmDelete(id) {
    if (!confirm('Are you sure you want to delete this role?')) return false;
    let code = prompt("Security Check: Please enter the Super Admin Passcode to authorize this deletion:");
    if (code) {
        document.getElementById('delete_passcode_' + id).value = code;
        return true;
    }
    alert("Deletion cancelled: Passcode is required.");
    return false;
}
</script>
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
