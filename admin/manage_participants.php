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
        $message = __('admin.common.security-error-invalid-passcode');
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
        SELECT p.full_name, p.email, er.role_name, ep.certificate_id, p.created_at, ep.custom_certificate_text, ep.issue_date
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
    fputcsv($output, array(
        __('admin.manage-participants.csv.full-name'),
        __('admin.manage-participants.table.email'),
        __('admin.manage-participants.table.role'),
        __('admin.manage-participants.table.cert-id'),
        __('admin.manage-participants.table.added-on'),
        __('admin.manage-participants.table.custom-text'),
        __('admin.manage-participants.csv.issue-date'),
    ));
    foreach ($exportData as $row) {
        fputcsv($output, array(
            $row['full_name'],
            $row['email'],
            $row['role_name'] ?? __('admin.manage-participants.no-role-badge'),
            $row['certificate_id'] ?? __('admin.manage-participants.csv.pending'),
            $row['created_at'],
            $row['custom_certificate_text'] ?? '',
            $row['issue_date'] ?? ''
        ));
    }
    fclose($output);
    exit;
}

$newCertIds = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    verify_csrf_token($csrf);

    if (isset($_POST['action']) && $_POST['action'] === 'add_single') {
        $fullName = trim($_POST['single_name'] ?? '');
        $email = trim($_POST['single_email'] ?? '');
        $roleId = $_POST['role_id'] ?? null;
        $customText = trim($_POST['single_custom_text'] ?? '');
        $issueDateInput = trim($_POST['single_issue_date'] ?? '');

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
            $stmtLinkEvent = $pdo->prepare("INSERT IGNORE INTO event_participants (participant_id, event_id, role_id, certificate_id, custom_certificate_text, issue_date) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtLinkEvent->execute([$pid, $eventId, $roleId, $certId, $customText ?: null, $issueDateInput ?: null]);

            if ($stmtLinkEvent->rowCount() > 0) {
                $message = __('admin.manage-participants.success.added');
                $messageType = 'success';
                $newCertIds[] = $certId;
            } else {
                $message = __('admin.manage-participants.error.already-registered');
                $messageType = 'error';
            }
        } else {
            $message = __('admin.manage-participants.error.invalid-add');
            $messageType = 'error';
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'edit_single') {
        $editPid = $_POST['edit_pid'];
        $fullName = trim($_POST['single_name'] ?? '');
        $email = trim($_POST['single_email'] ?? '');
        $roleId = $_POST['role_id'] ?? null;
        $customText = trim($_POST['single_custom_text'] ?? '');
        $issueDateInput = trim($_POST['single_issue_date'] ?? '');

        if ($fullName && filter_var($email, FILTER_VALIDATE_EMAIL) && $roleId) {
            $stmtUpdate = $pdo->prepare("UPDATE participants SET full_name = ?, email = ? WHERE id = ?");
            try {
                $stmtUpdate->execute([$fullName, $email, $editPid]);

                // Update role, custom text, and issue date in event_participants
                $stmtUpdateRole = $pdo->prepare("UPDATE event_participants SET role_id = ?, custom_certificate_text = ?, issue_date = ? WHERE participant_id = ? AND event_id = ?");
                $stmtUpdateRole->execute([$roleId, $customText ?: null, $issueDateInput ?: null, $editPid, $eventId]);

                $message = __('admin.manage-participants.success.updated');
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = __('admin.manage-participants.error.email-exists');
                $messageType = 'error';
            }
        } else {
            $message = __('admin.manage-participants.error.invalid-edit');
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
                                $newCertIds[] = $certId;
                            } else {
                                $skipped++; // Duplicate linkage
                            }
                        } else {
                            $errors++; // Invalid row or Role not found
                        }
                    }
                    fclose($handle);
                    $pdo->commit();

                    $message = __('admin.manage-participants.success.csv-processed', [
                        'added' => $added,
                        'skipped' => $skipped,
                        'errors' => $errors,
                    ]);
                    $messageType = 'success';
                }
            } else {
                $message = __('admin.manage-participants.error.invalid-csv-file');
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
    SELECT p.*, ep.certificate_id, er.role_name, ep.role_id, ep.custom_certificate_text, ep.issue_date
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
    <link rel="icon" type="image/png" href="../assets/DCW_logo.png">
    <meta charset="UTF-8">
    <title><?= __('admin.manage-participants.page-title', ['event' => htmlspecialchars($event['name'])]) ?></title>
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
</head>
<body>

<div class="navbar">
    <div style="display: flex; align-items: center; gap: 15px;">
        <img src="../assets/DCW_logo.png" alt="DCW Logo" width="35" height="35" decoding="async" style="height: 35px; width: 35px; background: white; padding: 2px; border-radius: 50%;">
        <span style="font-size: 18px; font-weight: bold; letter-spacing: 0.5px;"><?= __('admin.manage-participants.nav-title', ['event' => htmlspecialchars($event['name'])]) ?></span>
    </div>
    <div>
        <a href="dashboard.php"><?= __e('admin.common.nav.dashboard') ?></a>
        <a href="manage_users.php" style="margin-right: 15px;"><?= __e('admin.common.nav.manage-users') ?></a>
        <a href="logout.php"><?= __e('admin.common.nav.logout') ?></a>
    </div>
</div>

<div class="container" style="max-width: 1200px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0;"><?= __e('admin.manage-participants.heading') ?></h2>
    </div>

    <!-- Add / Edit Single Form -->
    <div style="display: flex; gap: 20px; flex-wrap: wrap;">
        <div class="upload-box" style="flex: 1; min-width: 300px;">
            <?php
            $editParticipant = null;
            $editRoleId = null;
            if (isset($_GET['edit_pid'])) {
                $stmtEdit = $pdo->prepare("
                    SELECT p.*, ep.role_id, ep.custom_certificate_text, ep.issue_date
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
                <h3 style="margin-top:0;"><?= __e('admin.manage-participants.edit.heading') ?></h3>
                <p style="font-size: 13px; color: #555;"><?= __e('admin.manage-participants.edit.desc') ?></p>
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
                        <input type="text" name="single_custom_text" value="<?= htmlspecialchars($editParticipant['custom_certificate_text'] ?? '') ?>" placeholder="<?= __e('admin.manage-participants.placeholder.custom-text') ?>">
                    </div>
                    <div class="form-group">
                        <label style="font-size: 12px; color: #555; display: block; margin-bottom: 4px;"><?= __e('admin.event-form.label.issue-date') ?></label>
                        <input type="date" name="single_issue_date" value="<?= htmlspecialchars($editParticipant['issue_date'] ?? '') ?>">
                        <small style="color: #999; font-size: 11px;"><?= __e('admin.manage-participants.hint.issue-date-edit') ?></small>
                    </div>
                    <div class="form-group">
                        <select name="role_id" required>
                            <option value=""><?= __e('admin.manage-participants.option.select-role') ?></option>
                            <?php foreach($rolesList as $r): ?>
                                <option value="<?= $r['id'] ?>" <?= ($r['id'] == $editRoleId) ? 'selected' : '' ?>><?= htmlspecialchars($r['role_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn" style="width: 100%;"><?= __e('admin.common.save-changes') ?></button>
                    <a href="manage_participants.php?id=<?= $eventId ?>" style="display:block; text-align:center; margin-top:10px; font-size:13px; color:#777; text-decoration:none;"><?= __e('admin.common.cancel-edit') ?></a>
                </form>
            <?php else: ?>
                <h3 style="margin-top:0;"><?= __e('admin.manage-participants.add.heading') ?></h3>
                <p style="font-size: 13px; color: #555;"><?= __e('admin.manage-participants.add.desc') ?></p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                    <input type="hidden" name="action" value="add_single">
                    <div class="form-group">
                        <input type="text" name="single_name" placeholder="<?= __e('admin.manage-participants.placeholder.full-name') ?>" required>
                    </div>
                    <div class="form-group">
                        <input type="email" name="single_email" placeholder="<?= __e('admin.manage-participants.placeholder.email') ?>" required>
                    </div>
                    <div class="form-group">
                        <input type="text" name="single_custom_text" placeholder="<?= __e('admin.manage-participants.placeholder.custom-text') ?>">
                    </div>
                    <div class="form-group">
                        <label style="font-size: 12px; color: #555; display: block; margin-bottom: 4px;"><?= __e('admin.event-form.label.issue-date') ?></label>
                        <input type="date" name="single_issue_date">
                        <small style="color: #999; font-size: 11px;"><?= __e('admin.manage-participants.hint.issue-date-add') ?></small>
                    </div>
                    <div class="form-group">
                        <select name="role_id" required>
                            <option value=""><?= __e('admin.manage-participants.option.select-role') ?></option>
                            <?php foreach($rolesList as $r): ?>
                                <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['role_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn" style="width: 100%;"><?= __e('admin.manage-participants.add.submit') ?></button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Bulk Upload Form -->
        <div class="upload-box" style="flex: 1; min-width: 300px;">
            <h3 style="margin-top:0;"><?= __e('admin.manage-participants.bulk.heading') ?></h3>
            <p style="font-size: 13px; color: #555;"><?= __e('admin.manage-participants.bulk.desc-prefix') ?> <strong><?= __e('admin.manage-participants.bulk.format') ?></strong>. <?= __e('admin.manage-participants.bulk.desc-suffix') ?></p>
            <div style="background: #fffbe6; border: 1px solid #ffe58f; padding: 10px; font-size: 12px; color: #d48806; border-radius: 4px; margin-bottom: 15px;">
                <strong><?= __e('admin.manage-participants.bulk.warning-title') ?></strong> <?= __e('admin.manage-participants.bulk.warning-prefix') ?> <em><?= __e('admin.dashboard.action.manage-roles') ?></em> <?= __e('admin.manage-participants.bulk.warning-suffix') ?>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="action" value="upload_csv">
                <div class="form-group">
                    <input type="file" name="csv_file" accept=".csv" required>
                </div>
                <button type="submit" class="btn" style="width: 100%;"><?= __e('admin.manage-participants.upload-csv-submit') ?></button>
            </form>
        </div>
    </div>

    <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 10px; border-bottom: 2px solid #333; padding-bottom: 10px;">
        <h3 style="margin: 0;"><?= __('admin.manage-participants.list-heading', ['count' => count($participants)]) ?></h3>

        <form method="GET" style="display: flex; gap: 5px;">
            <input type="hidden" name="id" value="<?= $eventId ?>">
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="<?= __e('admin.manage-participants.search-placeholder') ?>" style="padding: 8px; border-radius: 4px; border: 1px solid #ccc; width: 250px;">
            <button type="submit" class="btn" style="padding: 8px 15px;"><?= __e('admin.common.search') ?></button>
            <?php if($search): ?>
                <a href="manage_participants.php?id=<?= $eventId ?>" class="btn" style="padding: 8px 15px;"><?= __e('admin.common.clear') ?></a>
            <?php endif; ?>
            <a href="manage_participants.php?id=<?= $eventId ?>&export=1" class="btn btn-green" style="padding: 8px 15px;"><?= __e('admin.manage-participants.export-csv') ?></a>
        </form>
    </div>

    <?php if (count($participants) > 0): ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th><?= __e('admin.dashboard.table.name') ?></th>
                        <th><?= __e('admin.manage-participants.table.email') ?></th>
                        <th><?= __e('admin.manage-participants.table.role') ?></th>
                        <th><?= __e('admin.manage-participants.table.custom-text') ?></th>
                        <th><?= __e('admin.manage-participants.table.cert-id') ?></th>
                        <th><?= __e('admin.manage-participants.table.added-on') ?></th>
                        <th><?= __e('admin.manage-participants.table.action') ?></th>
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
                                    <span style="color:#999; font-size:12px;"><?= __e('admin.manage-participants.no-role-badge') ?></span>
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
                            <td>
                                <?php if (!empty($p['issue_date'])): ?>
                                    <?= htmlspecialchars(date('M j, Y', strtotime($p['issue_date']))) ?>
                                    <span style="background: #e0f2fe; color: #0369a1; padding: 1px 6px; border-radius: 10px; font-size: 10px; font-weight: 600; margin-left: 4px; vertical-align: middle;"><?= __e('admin.manage-participants.custom-badge') ?></span>
                                <?php else: ?>
                                    <?= htmlspecialchars($p['created_at']) ?>
                                <?php endif; ?>
                            </td>
                            <td style="display: flex; align-items: center; gap: 15px;">
                                <a href="manage_participants.php?id=<?= $eventId ?>&edit_pid=<?= $p['id'] ?>" class="action-link" title="<?= __e('admin.manage-participants.title.edit') ?>" style="color: var(--accent-color);">
                                    <svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                </a>
                                <form method="POST" action="manage_participants.php?id=<?= $eventId ?>" style="margin:0;" id="deleteForm_<?= $p['id'] ?>" onsubmit="return confirmDelete(<?= $p['id'] ?>);">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_participant">
                                    <input type="hidden" name="delete_pid" value="<?= $p['id'] ?>">
                                    <input type="hidden" name="super_admin_passcode" id="delete_passcode_<?= $p['id'] ?>" value="">
                                    <button type="submit" class="action-link" title="<?= __e('admin.manage-participants.title.remove') ?>" style="color: var(--secondary-color); background:none; border:none; padding:0; cursor:pointer;">
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
            <a href="?id=<?= $eventId ?>&page=<?= max(1, $page - 1) . $searchParam ?>" class="page-btn <?= ($page <= 1) ? 'disabled' : '' ?>"><?= __e('admin.dashboard.pagination.prev') ?></a>

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

            <a href="?id=<?= $eventId ?>&page=<?= min(max(1, $totalPages), $page + 1) . $searchParam ?>" class="page-btn <?= ($page >= max(1, $totalPages)) ? 'disabled' : '' ?>"><?= __e('admin.dashboard.pagination.next') ?></a>
        </div>

    <?php else: ?>
        <p style="padding: 20px 0; color: #777;"><?= __e('admin.manage-participants.no-participants') ?></p>
    <?php endif; ?>
</div>

<script src="script.js"></script>
<script>
function confirmDelete(id) {
    if (!confirm(<?= json_encode(__('admin.manage-participants.confirm.remove')) ?>)) return false;
    let code = prompt(<?= json_encode(__('admin.manage-participants.prompt.passcode-removal')) ?>);
    if (code) {
        document.getElementById('delete_passcode_' + id).value = code;
        return true;
    }
    alert(<?= json_encode(__('admin.manage-participants.alert.removal-cancelled')) ?>);
    return false;
}
</script>
<?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
<script>
    window.flashMessage = <?= json_encode(__('admin.manage-participants.flash.removed')) ?>;
    window.flashMessageType = 'success';
</script>
<?php elseif ($message): ?>
<script>
    window.flashMessage = <?= json_encode($message) ?>;
    window.flashMessageType = <?= json_encode($messageType) ?>;
</script>
<?php endif; ?>

<?php if (!empty($newCertIds)): ?>
    <!-- Progress Modal/Overlay -->
    <div id="email-progress-modal" class="progress-modal-overlay">
        <div class="progress-modal-content">
            <h3><?= __e('admin.manage-participants.progress.heading') ?></h3>
            <p id="email-progress-status"><?= __e('admin.manage-participants.progress.preparing') ?></p>
            <div class="progress-bar-container">
                <div id="email-progress-bar" class="progress-bar-fill" style="width: 0%;"></div>
            </div>
            <div id="email-progress-log" class="progress-log-container"></div>
            <div class="progress-modal-footer" id="progress-modal-footer" style="display: none;">
                <button onclick="closeProgressModal()" class="btn-close-modal"><?= __e('admin.manage-participants.progress.close') ?></button>
            </div>
        </div>
    </div>

    <style>
        .progress-modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        .progress-modal-content {
            background: #ffffff;
            border-radius: 12px;
            padding: 24px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            text-align: center;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .progress-modal-content h3 {
            margin-top: 0;
            color: #0f172a;
            font-size: 18px;
            font-weight: 700;
        }
        .progress-modal-content p {
            color: #475569;
            font-size: 14px;
            margin-bottom: 16px;
            margin-top: 8px;
        }
        .progress-bar-container {
            background: #e2e8f0;
            border-radius: 6px;
            height: 12px;
            width: 100%;
            overflow: hidden;
            margin-bottom: 16px;
        }
        .progress-bar-fill {
            background: #106b9a;
            height: 100%;
            width: 0%;
            transition: width 0.3s ease;
        }
        .progress-log-container {
            max-height: 150px;
            overflow-y: auto;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 10px;
            text-align: left;
            font-size: 12px;
            font-family: monospace;
            background: #f8fafc;
            color: #1e293b;
        }
        .progress-log-item {
            margin-bottom: 4px;
            line-height: 1.4;
        }
        .progress-log-item.success { color: #15803d; }
        .progress-log-item.failed { color: #b91c1c; }
        .progress-modal-footer {
            margin-top: 16px;
        }
        .btn-close-modal {
            background-color: #106b9a;
            color: #ffffff;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .btn-close-modal:hover {
            background-color: #0c567a;
        }
    </style>

    <script>
        const newCertIds = <?= json_encode($newCertIds) ?>;
        let currentIndex = 0;

        // Translated message templates — {tokens} are substituted below at runtime,
        // same {placeholder} convention as the PHP-side __() helper.
        const I18N = {
            sending: <?= json_encode(__('admin.manage-participants.progress.sending')) ?>,
            done: <?= json_encode(__('admin.manage-participants.progress.done')) ?>,
            logSuccess: <?= json_encode(__('admin.manage-participants.progress.log-success')) ?>,
            logFailed: <?= json_encode(__('admin.manage-participants.progress.log-failed')) ?>,
            logError: <?= json_encode(__('admin.manage-participants.progress.log-error')) ?>,
        };

        function updateProgress(percentage, statusText) {
            document.getElementById('email-progress-bar').style.width = percentage + '%';
            document.getElementById('email-progress-status').innerText = statusText;
        }

        function logMessage(text, isSuccess) {
            const logContainer = document.getElementById('email-progress-log');
            const logItem = document.createElement('div');
            logItem.className = 'progress-log-item ' + (isSuccess ? 'success' : 'failed');
            logItem.innerText = text;
            logContainer.appendChild(logItem);
            logContainer.scrollTop = logContainer.scrollHeight;
        }

        async function sendNextEmail() {
            if (currentIndex >= newCertIds.length) {
                updateProgress(100, I18N.done.replace('{count}', newCertIds.length));
                document.getElementById('progress-modal-footer').style.display = 'block';
                return;
            }

            const certId = newCertIds[currentIndex];
            const currentCount = currentIndex + 1;
            const totalCount = newCertIds.length;

            updateProgress(
                Math.round((currentIndex / totalCount) * 100),
                I18N.sending.replace('{current}', currentCount).replace('{total}', totalCount)
            );

            try {
                const formData = new FormData();
                formData.append('id', certId);

                const response = await fetch('send-notification.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    logMessage(I18N.logSuccess.replace('{certId}', certId), true);
                } else {
                    logMessage(I18N.logFailed.replace('{certId}', certId).replace('{message}', data.message), false);
                }
            } catch (err) {
                logMessage(I18N.logError.replace('{certId}', certId).replace('{message}', err.message), false);
            }

            currentIndex++;
            // Small delay to prevent hammering the server / SMTP
            setTimeout(sendNextEmail, 200);
        }

        // Start processing after page loads
        window.addEventListener('DOMContentLoaded', () => {
            sendNextEmail();
        });
    </script>
<?php endif; ?>
</body>
</html>
