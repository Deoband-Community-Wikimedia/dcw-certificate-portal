<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

$eventId = $_GET['id'] ?? null;
if (!$eventId) {
    header("Location: dashboard.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
$stmt->execute([$eventId]);
$event = $stmt->fetch();

if (!$event) {
    die("Event not found");
}

// Fetch Roles for dropdown
$stmtRoles = $pdo->prepare("SELECT id, role_name FROM event_roles WHERE event_id = ?");
$stmtRoles->execute([$eventId]);
$rolesList = $stmtRoles->fetchAll();
$roleMap = []; // useful for CSV processing
foreach($rolesList as $r) {
    $roleMap[strtolower(trim($r['role_name']))] = $r['id'];
}

$message = '';
$messageType = ''; // 'success' or 'error'

// Helper function to generate standardized Certificate ID
function generateCertId($prefix) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Excluded I, O, 0, 1 for clarity
    $randomStr = '';
    for ($i = 0; $i < 8; $i++) {
        $randomStr .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return strtoupper($prefix) . '-' . $randomStr;
}

$certPrefix = $event['cert_prefix'] ?? 'DCW';

// Handle Deletion
if (isset($_POST['action']) && $_POST['action'] === 'delete_participant') {
    $csrf = $_POST['csrf_token'] ?? '';
    verify_csrf_token($csrf);
    
    $passcode = trim($_POST['super_admin_passcode'] ?? '');
    if ($passcode !== SUPER_ADMIN_PASSCODE) {
        $message = "Security Error: Invalid Super Admin Passcode.";
        $messageType = 'error';
    } else {
        $delPid = $_POST['delete_pid'];
        
        // Log before delete
        $stmtParticipant = $pdo->prepare("SELECT full_name FROM participants WHERE id = ?");
        $stmtParticipant->execute([$delPid]);
        $deletedParticipantName = $stmtParticipant->fetchColumn() ?: 'Unknown';
        
        $stmtDel = $pdo->prepare("DELETE FROM event_participants WHERE participant_id = ? AND event_id = ?");
        $stmtDel->execute([$delPid, $eventId]);
        
        log_audit_action($pdo, 'Removed Participant', "Participant: {$deletedParticipantName} from Event ID: {$eventId}");
        
        header("Location: manage_participants.php?id=" . $eventId . "&msg=deleted");
        exit;
    }
}

// Handle Export
if (isset($_GET['export'])) {
    $stmt = $pdo->prepare("
        SELECT p.full_name, p.email, er.role_name, ep.certificate_id, p.created_at, ep.custom_certificate_text
        FROM participants p
        JOIN event_participants ep ON p.id = ep.participant_id
        LEFT JOIN event_roles er ON ep.role_id = er.id
        WHERE ep.event_id = ?
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$eventId]);
    $exportData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=participants_event_' . $eventId . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, array('Full Name', 'Email', 'Role', 'Certificate ID', 'Added On', 'Custom Text'));
    foreach ($exportData as $row) {
        fputcsv($output, array(
            $row['full_name'], 
            $row['email'], 
            $row['role_name'] ?? 'No Role', 
            $row['certificate_id'] ?? 'Pending', 
            $row['created_at'],
            $row['custom_certificate_text'] ?? ''
        ));
    }
    fclose($output);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    verify_csrf_token($csrf);

    if (isset($_POST['action']) && $_POST['action'] === 'add_single') {
        $fullName = trim($_POST['single_name'] ?? '');
        $email = trim($_POST['single_email'] ?? '');
        $roleId = $_POST['role_id'] ?? null;
        $customText = trim($_POST['single_custom_text'] ?? '');
        
        if ($fullName && filter_var($email, FILTER_VALIDATE_EMAIL) && $roleId) {
            // 1. Insert into participants
            $stmtInsertParticipant = $pdo->prepare("INSERT INTO participants (full_name, email) VALUES (?, ?) ON DUPLICATE KEY UPDATE full_name=VALUES(full_name)");
            $stmtInsertParticipant->execute([$fullName, $email]);
            
            // 2. Get participant ID
            $stmtGetParticipant = $pdo->prepare("SELECT id FROM participants WHERE email = ?");
            $stmtGetParticipant->execute([$email]);
            $pid = $stmtGetParticipant->fetchColumn();
            
            // 3. Link to event
            $certId = generateCertId($certPrefix);
            $stmtLinkEvent = $pdo->prepare("INSERT IGNORE INTO event_participants (participant_id, event_id, role_id, certificate_id, custom_certificate_text) VALUES (?, ?, ?, ?, ?)");
            $stmtLinkEvent->execute([$pid, $eventId, $roleId, $certId, $customText ?: null]);
            
            if ($stmtLinkEvent->rowCount() > 0) {
                $message = "Participant added successfully.";
                $messageType = 'success';
            } else {
                $message = "Participant is already registered for this event.";
                $messageType = 'error';
            }
        } else {
            $message = "Invalid name, email, or missing role.";
            $messageType = 'error';
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'edit_single') {
        $editPid = $_POST['edit_pid'];
        $fullName = trim($_POST['single_name'] ?? '');
        $email = trim($_POST['single_email'] ?? '');
        $roleId = $_POST['role_id'] ?? null;
        $customText = trim($_POST['single_custom_text'] ?? '');
        
        if ($fullName && filter_var($email, FILTER_VALIDATE_EMAIL) && $roleId) {
            $stmtUpdate = $pdo->prepare("UPDATE participants SET full_name = ?, email = ? WHERE id = ?");
            try {
                $stmtUpdate->execute([$fullName, $email, $editPid]);
                
                // Update role and custom text in event_participants
                $stmtUpdateRole = $pdo->prepare("UPDATE event_participants SET role_id = ?, custom_certificate_text = ? WHERE participant_id = ? AND event_id = ?");
                $stmtUpdateRole->execute([$roleId, $customText ?: null, $editPid, $eventId]);

                $message = "Participant updated successfully.";
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = "Error: Email might already exist for another participant.";
                $messageType = 'error';
            }
        } else {
            $message = "Invalid name, email, or role.";
            $messageType = 'error';
        }
    } elseif (isset($_FILES['csv_file'])) {
        if ($_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $csvMimes = ['text/x-comma-separated-values', 'text/comma-separated-values', 'application/octet-stream', 'application/vnd.ms-excel', 'application/x-csv', 'text/x-csv', 'text/csv', 'application/csv', 'application/excel', 'application/vnd.msexcel', 'text/plain'];
            
            $fileName = $_FILES['csv_file']['name'];
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if (in_array($_FILES['csv_file']['type'], $csvMimes) || $fileExt === 'csv') {
                $handle = fopen($_FILES['csv_file']['tmp_name'], "r");
                if ($handle !== FALSE) {
                    // Skip header row
                    fgetcsv($handle);
                    
                    $added = 0;
                    $skipped = 0;
                    $errors = 0;

                    $pdo->beginTransaction();
                    
                    // Prepared statements
                    $stmtInsertParticipant = $pdo->prepare("INSERT INTO participants (full_name, email) VALUES (?, ?) ON DUPLICATE KEY UPDATE full_name=VALUES(full_name)");
                    $stmtGetParticipant = $pdo->prepare("SELECT id FROM participants WHERE email = ?");
                    $stmtLinkEvent = $pdo->prepare("INSERT IGNORE INTO event_participants (participant_id, event_id, role_id, certificate_id, custom_certificate_text) VALUES (?, ?, ?, ?, ?)");

                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        $fullName = trim($data[0] ?? '');
                        $email = trim($data[1] ?? '');
                        $roleName = strtolower(trim($data[2] ?? ''));
                        $customText = trim($data[3] ?? '');

                        $roleId = $roleMap[$roleName] ?? null;

                        if ($fullName && filter_var($email, FILTER_VALIDATE_EMAIL) && $roleId) {
                            // 1. Insert into participants
                            $stmtInsertParticipant->execute([$fullName, $email]);
                            
                            // 2. Get participant ID
                            $stmtGetParticipant->execute([$email]);
                            $pid = $stmtGetParticipant->fetchColumn();
                            
                            // 3. Link to event
                            $certId = generateCertId($certPrefix);
                            $stmtLinkEvent->execute([$pid, $eventId, $roleId, $certId, $customText ?: null]);
                            
                            if ($stmtLinkEvent->rowCount() > 0) {
                                $added++;
                            } else {
                                $skipped++; // Duplicate linkage
                            }
                        } else {
                            $errors++; // Invalid row or Role not found
                        }
                    }
                    fclose($handle);
                    $pdo->commit();

                    $message = "CSV Processed. $added added. $skipped duplicates. $errors errors/missing roles.";
                    $messageType = 'success';
                }
            } else {
                $message = "Please upload a valid CSV file.";
                $messageType = 'error';
            }
        }
    }
}

// Search Logic
$search = $_GET['search'] ?? '';
$searchQuery = "";
$params = [$eventId];

if ($search !== '') {
    $searchQuery = " AND (p.full_name LIKE ? OR p.email LIKE ? OR ep.certificate_id LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Pagination logic
$limit = 50;
$page = isset($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$stmtCount = $pdo->prepare("
    SELECT COUNT(*) 
    FROM participants p
    JOIN event_participants ep ON p.id = ep.participant_id
    WHERE ep.event_id = ? $searchQuery
");
$stmtCount->execute($params);
$totalRecords = $stmtCount->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

// Fetch participants
$stmt = $pdo->prepare("
    SELECT p.*, ep.certificate_id, er.role_name, ep.role_id, ep.custom_certificate_text
    FROM participants p
    JOIN event_participants ep ON p.id = ep.participant_id
    LEFT JOIN event_roles er ON ep.role_id = er.id
    WHERE ep.event_id = ? $searchQuery
    ORDER BY p.created_at DESC
    LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
");
$stmt->execute($params);
$participants = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="https://dcwwiki.org/images/5/56/DCW_logo.png">
    <meta charset="UTF-8">
    <title>Manage Participants - <?= htmlspecialchars($event['name']) ?></title>
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
</head>
<body>

<div class="navbar">
    <div style="display: flex; align-items: center; gap: 15px;">
        <img src="../assets/DCW_logo.png" alt="DCW Logo" width="35" height="35" decoding="async" style="height: 35px; width: 35px; background: white; padding: 2px; border-radius: 50%;">
        <span style="font-size: 18px; font-weight: bold; letter-spacing: 0.5px;">Admin Panel - Participants (<?= htmlspecialchars($event['name']) ?>)</span>
    </div>
    <div>
        <a href="dashboard.php">Dashboard</a>
        <a href="manage_users.php" style="margin-right: 15px;">Manage Users</a>
        <a href="logout.php">Logout</a>
    </div>
</div>

<div class="container" style="max-width: 1200px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0;">Manage Participants</h2>
    </div>

    <!-- Add / Edit Single Form -->
    <div style="display: flex; gap: 20px; flex-wrap: wrap;">
        <div class="upload-box" style="flex: 1; min-width: 300px;">
            <?php
            $editParticipant = null;
            $editRoleId = null;
            if (isset($_GET['edit_pid'])) {
                $stmtEdit = $pdo->prepare("
                    SELECT p.*, ep.role_id, ep.custom_certificate_text
                    FROM participants p 
                    JOIN event_participants ep ON p.id = ep.participant_id
                    WHERE p.id = ? AND ep.event_id = ?
                ");
                $stmtEdit->execute([$_GET['edit_pid'], $eventId]);
                $editParticipant = $stmtEdit->fetch();
                if ($editParticipant) {
                    $editRoleId = $editParticipant['role_id'];
                }
            }
            ?>
            
            <?php if ($editParticipant): ?>
                <h3 style="margin-top:0;">Edit Participant</h3>
                <p style="font-size: 13px; color: #555;">Update participant details.</p>
                <form method="POST" action="manage_participants.php?id=<?= $eventId ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                    <input type="hidden" name="action" value="edit_single">
                    <input type="hidden" name="edit_pid" value="<?= $editParticipant['id'] ?>">
                    <div class="form-group">
                        <input type="text" name="single_name" value="<?= htmlspecialchars($editParticipant['full_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <input type="email" name="single_email" value="<?= htmlspecialchars($editParticipant['email']) ?>" required>
                    </div>
                    <div class="form-group">
                        <input type="text" name="single_custom_text" value="<?= htmlspecialchars($editParticipant['custom_certificate_text'] ?? '') ?>" placeholder="Custom Text (Optional)">
                    </div>
                    <div class="form-group">
                        <select name="role_id" required>
                            <option value="">-- Select Role --</option>
                            <?php foreach($rolesList as $r): ?>
                                <option value="<?= $r['id'] ?>" <?= ($r['id'] == $editRoleId) ? 'selected' : '' ?>><?= htmlspecialchars($r['role_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn" style="width: 100%;">Save Changes</button>
                    <a href="manage_participants.php?id=<?= $eventId ?>" style="display:block; text-align:center; margin-top:10px; font-size:13px; color:#777; text-decoration:none;">Cancel Edit</a>
                </form>
            <?php else: ?>
                <h3 style="margin-top:0;">Add Participant</h3>
                <p style="font-size: 13px; color: #555;">Manually add a single participant to this event.</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                    <input type="hidden" name="action" value="add_single">
                    <div class="form-group">
                        <input type="text" name="single_name" placeholder="Full Name" required>
                    </div>
                    <div class="form-group">
                        <input type="email" name="single_email" placeholder="Email Address" required>
                    </div>
                    <div class="form-group">
                        <input type="text" name="single_custom_text" placeholder="Custom Text (Optional)">
                    </div>
                    <div class="form-group">
                        <select name="role_id" required>
                            <option value="">-- Select Role --</option>
                            <?php foreach($rolesList as $r): ?>
                                <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['role_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn" style="width: 100%;">Add Participant</button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Bulk Upload Form -->
        <div class="upload-box" style="flex: 1; min-width: 300px;">
            <h3 style="margin-top:0;">Bulk Upload CSV</h3>
            <p style="font-size: 13px; color: #555;">Upload a CSV file containing participants. Format: <strong>Full_Name, Email, Role_Name, Custom_Text (Optional)</strong>. First row will be ignored.</p>
            <div style="background: #fffbe6; border: 1px solid #ffe58f; padding: 10px; font-size: 12px; color: #d48806; border-radius: 4px; margin-bottom: 15px;">
                <strong>Important:</strong> The roles in your CSV (e.g., Attendee, Organizer) MUST EXACTLY MATCH the roles you have already created in the <em>Manage Roles</em> section. Any rows with unrecognized roles will be skipped!
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="action" value="upload_csv">
                <div class="form-group">
                    <input type="file" name="csv_file" accept=".csv" required>
                </div>
                <button type="submit" class="btn" style="width: 100%;">Upload CSV</button>
            </form>
        </div>
    </div>

    <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 10px; border-bottom: 2px solid #333; padding-bottom: 10px;">
        <h3 style="margin: 0;">Participant List (<?= count($participants) ?>)</h3>
        
        <form method="GET" style="display: flex; gap: 5px;">
            <input type="hidden" name="id" value="<?= $eventId ?>">
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search name or email..." style="padding: 8px; border-radius: 4px; border: 1px solid #ccc; width: 250px;">
            <button type="submit" class="btn" style="padding: 8px 15px;">Search</button>
            <?php if($search): ?>
                <a href="manage_participants.php?id=<?= $eventId ?>" class="btn" style="padding: 8px 15px;">Clear</a>
            <?php endif; ?>
            <a href="manage_participants.php?id=<?= $eventId ?>&export=1" class="btn btn-green" style="padding: 8px 15px;">Export CSV</a>
        </form>
    </div>

    <?php if (count($participants) > 0): ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Custom Text</th>
                        <th>Certificate ID</th>
                        <th>Added On</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($participants as $p): ?>
                        <tr>
                            <td><?= htmlspecialchars($p['full_name']) ?></td>
                            <td><?= htmlspecialchars($p['email']) ?></td>
                            <td>
                                <?php if ($p['role_name']): ?>
                                    <span style="background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 12px; font-size: 12px; font-weight: 500;">
                                        <?= htmlspecialchars($p['role_name']) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:#999; font-size:12px;">No Role</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($p['custom_certificate_text'])): ?>
                                    <span style="background: #fdf6e3; color: #b58900; padding: 2px 8px; border-radius: 12px; font-size: 12px; border: 1px solid #eee8d5;"><?= htmlspecialchars($p['custom_certificate_text']) ?></span>
                                <?php else: ?>
                                    <span style="color:#ccc; font-style: italic; font-size:12px;">-</span>
                                <?php endif; ?>
                            </td>
                            <td><span style="font-family: monospace; background: #f4f5f7; padding: 3px 6px; border-radius: 4px; border: 1px solid #e1e4e8;"><?= htmlspecialchars($p['certificate_id']) ?></span></td>
                            <td><?= htmlspecialchars($p['created_at']) ?></td>
                            <td style="display: flex; align-items: center; gap: 15px;">
                                <a href="manage_participants.php?id=<?= $eventId ?>&edit_pid=<?= $p['id'] ?>" class="action-link" title="Edit" style="color: var(--accent-color);">
                                    <svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                </a>
                                <form method="POST" action="manage_participants.php?id=<?= $eventId ?>" style="margin:0;" id="deleteForm_<?= $p['id'] ?>" onsubmit="return confirmDelete(<?= $p['id'] ?>);">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_participant">
                                    <input type="hidden" name="delete_pid" value="<?= $p['id'] ?>">
                                    <input type="hidden" name="super_admin_passcode" id="delete_passcode_<?= $p['id'] ?>" value="">
                                    <button type="submit" class="action-link" title="Remove" style="color: var(--secondary-color); background:none; border:none; padding:0; cursor:pointer;">
                                        <svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                                    </button>
                                </form>
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
            <a href="?id=<?= $eventId ?>&page=<?= max(1, $page - 1) . $searchParam ?>" class="page-btn <?= ($page <= 1) ? 'disabled' : '' ?>">Prev</a>
            
            <?php for($i = 1; $i <= max(1, $totalPages); $i++): ?>
                <?php
                if ($totalPages > 15) {
                    if ($i != 1 && $i != $totalPages && abs($i - $page) > 2) {
                        if (abs($i - $page) == 3) echo '<span style="color:#777; margin:0 5px;">...</span>';
                        continue;
                    }
                }
                ?>
                <a href="?id=<?= $eventId ?>&page=<?= $i . $searchParam ?>" class="page-btn <?= ($i == $page) ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            
            <a href="?id=<?= $eventId ?>&page=<?= min(max(1, $totalPages), $page + 1) . $searchParam ?>" class="page-btn <?= ($page >= max(1, $totalPages)) ? 'disabled' : '' ?>">Next</a>
        </div>

    <?php else: ?>
        <p style="padding: 20px 0; color: #777;">No participants found.</p>
    <?php endif; ?>
</div>

<script src="script.js"></script>
<script>
function confirmDelete(id) {
    if (!confirm('Are you sure you want to remove this participant from this event?')) return false;
    let code = prompt("Security Check: Please enter the Super Admin Passcode to authorize this removal:");
    if (code) {
        document.getElementById('delete_passcode_' + id).value = code;
        return true;
    }
    alert("Removal cancelled: Passcode is required.");
    return false;
}
</script>
<?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
<script>
    window.flashMessage = 'Participant successfully removed.';
    window.flashMessageType = 'success';
</script>
<?php elseif ($message): ?>
<script>
    window.flashMessage = <?= json_encode($message) ?>;
    window.flashMessageType = <?= json_encode($messageType) ?>;
</script>
<?php endif; ?>
</body>
</html>
