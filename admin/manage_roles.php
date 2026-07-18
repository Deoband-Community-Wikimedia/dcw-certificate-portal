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
        $existingTemplate = $_POST['existing_template'] ?? '';
        $customTemplateName = trim($_POST['custom_template_name'] ?? '');

        if (!$roleName) {
            $error = "Role name is required.";
        } elseif (empty($existingTemplate) && (!isset($_FILES['template']) || $_FILES['template']['error'] !== UPLOAD_ERR_OK)) {
            $error = "Please upload a valid PDF template or select an existing one.";
        } else {
            $templateFile = '';
            
            if (!empty($existingTemplate)) {
                $templateFile = $existingTemplate;
            } else {
                $eventFolderName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $event['name']);
                $tplBaseDir = '../uploads/templates/';
                $eventTplDir = $tplBaseDir . $eventFolderName . '/';
                
                if (!is_dir($eventTplDir)) mkdir($eventTplDir, 0777, true);

                $templateExt = strtolower(pathinfo($_FILES['template']['name'], PATHINFO_EXTENSION));
                if ($templateExt !== 'pdf') {
                    $error = "Template must be a PDF file.";
                } else {
                    // Determine base target name (use custom name if provided)
                    if (!empty($customTemplateName)) {
                        $targetFilename = basename($customTemplateName);
                        if (strtolower(pathinfo($targetFilename, PATHINFO_EXTENSION)) !== 'pdf') {
                            $targetFilename .= '.pdf';
                        }
                    } else {
                        $targetFilename = $_FILES['template']['name'];
                    }

                    $filename = getUniqueFilename($eventTplDir, $targetFilename);
                    move_uploaded_file($_FILES['template']['tmp_name'], $eventTplDir . $filename);
                    $templateFile = $eventFolderName . '/' . $filename;
                }
            }

            if (!$error && $templateFile) {
                // Inherit layout if using an existing template
                $visualSettings = null;
                $rotation = 0;
                if (!empty($existingTemplate)) {
                    $stmtFind = $pdo->prepare("SELECT visual_settings, rotation FROM event_roles WHERE template_file = ? LIMIT 1");
                    $stmtFind->execute([$templateFile]);
                    $existingRoleData = $stmtFind->fetch();
                    if ($existingRoleData) {
                        $visualSettings = $existingRoleData['visual_settings'];
                        $rotation = $existingRoleData['rotation'];
                    }
                }

                $stmt = $pdo->prepare("INSERT INTO event_roles (event_id, role_name, template_file, visual_settings, rotation) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$eventId, $roleName, $templateFile, $visualSettings, $rotation]);
                $success = "Role '$roleName' added successfully.";
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'edit_role') {
        $editRoleId = $_POST['edit_role_id'] ?? '';
        $newRoleName = trim($_POST['role_name'] ?? '');

        if (!$newRoleName) {
            $error = "Role name is required.";
        } else {
            // Renaming only updates role_name — template_file, visual_settings, and rotation
            // are left untouched, so the certificate mapping stays intact.
            $stmt = $pdo->prepare("UPDATE event_roles SET role_name = ? WHERE id = ? AND event_id = ?");
            $stmt->execute([$newRoleName, $editRoleId, $eventId]);

            log_audit_action($pdo, 'Renamed Role', "Role ID {$editRoleId} renamed to '{$newRoleName}' for Event ID: {$eventId}");
            $success = "Role renamed to '$newRoleName' successfully.";
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_role') {
        $passcode = trim($_POST['super_admin_passcode'] ?? '');
        if ($passcode !== SUPER_ADMIN_PASSCODE) {
            $error = "Security Error: Invalid Super Admin Passcode.";
        } else {
            $roleId = $_POST['role_id'];

            // Get role details for logging and file cleanup
            $stmtRole = $pdo->prepare("SELECT role_name, template_file FROM event_roles WHERE id = ?");
            $stmtRole->execute([$roleId]);
            $roleData = $stmtRole->fetch();
            $deletedRoleName = $roleData['role_name'] ?? 'Unknown';
            $templateFile = $roleData['template_file'] ?? null;

            // Delete role
            $stmt = $pdo->prepare("DELETE FROM event_roles WHERE id = ? AND event_id = ?");
            $stmt->execute([$roleId, $eventId]);

            // Clean up the template PDF from disk, but only if no other role
            // (in this event or any other) still references the same file —
            // templates can be intentionally reused via "existing_template".
            if ($templateFile) {
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM event_roles WHERE template_file = ?");
                $stmtCheck->execute([$templateFile]);
                $stillInUse = (int) $stmtCheck->fetchColumn();

                if ($stillInUse === 0) {
                    $templatePath = '../uploads/templates/' . $templateFile;
                    if (file_exists($templatePath)) {
                        @unlink($templatePath);
                    }
                }
            }

            log_audit_action($pdo, 'Deleted Role', "Role: {$deletedRoleName} from Event ID: {$eventId}");
            $success = "Role deleted successfully.";
        }
    }
}

// Fetch all roles for this event
$stmt = $pdo->prepare("SELECT * FROM event_roles WHERE event_id = ? ORDER BY created_at DESC");
$stmt->execute([$eventId]);
$roles = $stmt->fetchAll();

// If editing a role, fetch its current data to pre-fill the form
$editRole = null;
if (isset($_GET['edit_role_id'])) {
    $stmtEditRole = $pdo->prepare("SELECT * FROM event_roles WHERE id = ? AND event_id = ?");
    $stmtEditRole->execute([$_GET['edit_role_id'], $eventId]);
    $editRole = $stmtEditRole->fetch();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png">
    <meta charset="UTF-8">
    <title>Manage Roles - <?= htmlspecialchars($event['name']) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="navbar">
    <div style="display: flex; align-items: center; gap: 15px;">
        <img src="../assets/DCW_logo.png" alt="DCW Logo" width="35" height="35" decoding="async" style="height: 35px; width: 35px; background: white; padding: 2px; border-radius: 50%;">
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

        <?php if ($editRole): ?>
        <div class="upload-box" style="flex: 1; min-width: 300px;">
            <h3>Edit Role</h3>
            <p>Rename this role. The template mapping and visual layout stay unchanged.</p>
            <form method="POST" action="manage_roles.php?event_id=<?= $eventId ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="action" value="edit_role">
                <input type="hidden" name="edit_role_id" value="<?= $editRole['id'] ?>">
                <div class="form-group">
                    <label>Role Name</label>
                    <input type="text" name="role_name" required value="<?= htmlspecialchars($editRole['role_name']) ?>">
                </div>
                <div class="form-group">
                    <label>Current Template</label>
                    <div class="help-text"><?= htmlspecialchars($editRole['template_file']) ?> (unchanged)</div>
                </div>
                <button type="submit" class="btn" style="width: 100%; margin-top: 10px;">Save Changes</button>
                <a href="manage_roles.php?event_id=<?= $eventId ?>" style="display:block; text-align:center; margin-top:10px; font-size:13px; color:#777; text-decoration:none;">Cancel Edit</a>
            </form>
        </div>
        <?php else: ?>
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
                    <label>Role Template (Upload New PDF)</label>
                    <input type="file" name="template" accept="application/pdf">
                </div>
                <div class="form-group">
                    <label>Custom Template Name (Optional)</label>
                    <input type="text" name="custom_template_name" placeholder="e.g. Senior_Engineer_Certificate">
                    <div class="help-text">Leaves original filename intact if blank. Ext (.pdf) auto-appends.</div>
                </div>
                
                <?php if (count($roles) > 0): ?>
                <div style="text-align: center; font-size: 13px; color: #777; margin: 10px 0;">-- OR --</div>
                <div class="form-group">
                    <label>Use Existing Template from this Event</label>
                    <select name="existing_template">
                        <option value="">-- Do not reuse --</option>
                        <?php 
                        // Get unique templates
                        $seenTpls = [];
                        foreach ($roles as $r): 
                            if (!in_array($r['template_file'], $seenTpls)):
                                $seenTpls[] = $r['template_file'];
                        ?>
                            <option value="<?= htmlspecialchars($r['template_file']) ?>">
                                <?= htmlspecialchars($r['role_name']) ?>'s Template
                            </option>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </select>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn" style="width: 100%; margin-top: 10px;">Add Role</button>
            </form>
        </div>
        <?php endif; ?>

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
                                    <td>
                                        <a href="../uploads/templates/<?= htmlspecialchars($role['template_file']) ?>" target="_blank">View PDF</a>
                                    </td>
                                    <td style="display:flex; gap:10px;">
                                        <a href="manage_roles.php?event_id=<?= $eventId ?>&edit_role_id=<?= $role['id'] ?>" class="btn btn-sm" title="Rename Role">
                                            <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                        </a>
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