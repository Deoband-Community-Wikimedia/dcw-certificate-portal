<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

$error = '';
$success = '';

$eventId = $_GET['id'] ?? null;
if (!$eventId) {
    header("Location: dashboard.php");
    exit;
}

// Fetch current event details
$stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
$stmt->execute([$eventId]);
$event = $stmt->fetch();

if (!$event) {
    die("Event not found.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    verify_csrf_token($csrf);

    $eventName = trim($_POST['name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    if ($category === '' || !in_array($category, event_categories(), true)) {
        $category = null;
    }
    $linkedinCaption = trim($_POST['linkedin_caption'] ?? '');
    $customVerificationText = trim($_POST['custom_verification_text'] ?? '');
    $certificateIssueDate = trim($_POST['certificate_issue_date'] ?? '');
    if ($certificateIssueDate === '') {
        $certificateIssueDate = null;
    }
    $completionDate = trim($_POST['completion_date'] ?? '');
    if ($completionDate === '') {
        $completionDate = null;
    }

    $passcode = trim($_POST['super_admin_passcode'] ?? '');

    $description = trim($_POST['description'] ?? '');
    if ($description === '') $description = null;
    $partners = trim($_POST['partners'] ?? '');
    if ($partners === '') $partners = null;

    $nameChanged = ($eventName !== $event['name']);

    if ($nameChanged && $passcode !== SUPER_ADMIN_PASSCODE) {
        $error = __('admin.edit-event.error.passcode-required-for-name-change');
    } elseif (!$eventName) {
        $error = __('admin.event-form.error.name-required');
    } else {
        $oldEventName = $event['name'];

        $stmtUpdate = $pdo->prepare("UPDATE events SET name = ?, category = ?, linkedin_caption = ?, custom_verification_text = ?, certificate_issue_date = ?, completion_date = ?, description = ?, partners = ? WHERE id = ?");
        $stmtUpdate->execute([$eventName, $category, $linkedinCaption, $customVerificationText, $certificateIssueDate, $completionDate, $description, $partners, $eventId]);

        if ($nameChanged) {
            syncEventTemplateFolder($pdo, $eventId, $oldEventName);
        }

        log_audit_action($pdo, 'Edited Event', "Event ID: {$eventId}, New Name: {$eventName}");

        $success = __('admin.edit-event.success.updated');
        // Refresh event data
        $event['name'] = $eventName;
        $event['category'] = $category;
        $event['linkedin_caption'] = $linkedinCaption;
        $event['custom_verification_text'] = $customVerificationText;
        $event['certificate_issue_date'] = $certificateIssueDate;
        $event['completion_date'] = $completionDate;
        $event['description'] = $description;
        $event['partners'] = $partners;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="../assets/DCW_logo.png">
    <meta charset="UTF-8">
    <title><?= __e('admin.edit-event.page-title') ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="navbar">
    <div style="display: flex; align-items: center; gap: 15px;">
        <img src="../assets/DCW_logo.png" alt="DCW Logo" width="35" height="35" decoding="async" style="height: 35px; width: 35px; background: white; padding: 2px; border-radius: 50%;">
        <span style="font-size: 18px; font-weight: bold; letter-spacing: 0.5px;"><?= __e('admin.edit-event.nav-title') ?></span>
    </div>
    <div>
        <a href="dashboard.php"><?= __e('admin.common.nav.dashboard') ?></a>
        <a href="manage_users.php" style="margin-right: 15px;"><?= __e('admin.common.nav.manage-users') ?></a>
        <a href="logout.php"><?= __e('admin.common.nav.logout') ?></a>
    </div>
</div>

<div class="container" style="max-width: 600px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0;"><?= __e('admin.edit-event.heading') ?></h2>
        <a href="dashboard.php" class="btn" style="background: #6c757d;"><?= __e('admin.common.back') ?></a>
    </div>

    <form method="POST" action="" onsubmit="return confirmEdit();">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
        <div class="form-group">
            <label><?= __e('admin.event-form.label.name') ?></label>
            <input type="text" name="name" id="eventNameInput" required value="<?= htmlspecialchars($event['name']) ?>">
        </div>

        <div class="form-group">
            <label><?= __e('admin.event-form.label.category') ?></label>
            <select name="category" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;">
                <option value=""><?= __e('admin.event-form.option.uncategorised') ?></option>
                <?php foreach (event_categories() as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>" <?= (($event['category'] ?? '') === $cat) ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
                <?php endforeach; ?>
            </select>
            <div style="font-size: 11px; color: #777; margin-top: 5px;">
                <?= __e('admin.event-form.hint.category') ?>
            </div>
        </div>

        <div class="form-group">
            <label><?= __e('admin.event-form.label.cert-prefix') ?> <span style="font-size: 11px; color: #999; font-weight: normal;">(<?= __e('admin.edit-event.hint.cert-prefix-readonly') ?>)</span></label>
            <input type="text" name="cert_prefix" value="<?= htmlspecialchars($event['cert_prefix'] ?? 'DCW') ?>" readonly style="background-color: #e9ecef; color: #6c757d; cursor: not-allowed; text-transform: uppercase;">
        </div>

        <div class="form-group">
            <label><?= __e('admin.event-form.label.issue-date') ?></label>
            <input type="date" name="certificate_issue_date" value="<?= htmlspecialchars($event['certificate_issue_date'] ?? '') ?>">
            <div style="font-size: 11px; color: #777; margin-top: 5px;">
                <?= __e('admin.event-form.hint.issue-date') ?>
            </div>
        </div>

        <div class="form-group">
            <label><?= __e('admin.event-form.label.completion-date') ?></label>
            <input type="date" name="completion_date" value="<?= htmlspecialchars($event['completion_date'] ?? '') ?>">
            <div style="font-size: 11px; color: #777; margin-top: 5px;">
                <?= __e('admin.event-form.hint.completion-date') ?>
            </div>
        </div>

        <div class="form-group">
            <label><?= __e('admin.event-form.label.verification-text') ?></label>
            <textarea name="custom_verification_text" rows="3" placeholder="<?= __e('admin.event-form.placeholder.verification-text') ?>" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-family: inherit; resize: vertical;"><?= htmlspecialchars($event['custom_verification_text'] ?? '') ?></textarea>
            <div style="font-size: 11px; color: #777; margin-top: 5px;">
                <?= __e('admin.event-form.hint.verification-text-prefix') ?> <em><?= __e('admin.event-form.default.verification-text') ?></em>
            </div>
        </div>

        <div class="form-group">
            <label><?= __e('admin.event-form.label.linkedin-caption') ?></label>
            <textarea name="linkedin_caption" rows="4" placeholder="<?= __e('admin.event-form.placeholder.linkedin-caption') ?>" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-family: inherit; resize: vertical;"><?= htmlspecialchars($event['linkedin_caption'] ?? '') ?></textarea>
            <div style="font-size: 11px; color: #777; margin-top: 5px;">
                <?= __e('admin.event-form.hint.linkedin-caption') ?>
            </div>
        </div>

        <div class="form-group">
            <label><?= __e('admin.event-form.label.description') ?></label>
            <textarea name="description" rows="3" maxlength="1000" placeholder="<?= __e('admin.event-form.placeholder.description') ?>" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-family: inherit; resize: vertical;"><?= htmlspecialchars($event['description'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
            <label><?= __e('admin.event-form.label.partners') ?></label>
            <input type="text" name="partners" maxlength="255" placeholder="<?= __e('admin.event-form.placeholder.partners') ?>" value="<?= htmlspecialchars($event['partners'] ?? '') ?>">
            <div style="font-size: 11px; color: #777; margin-top: 5px;">
                <?= __e('admin.event-form.hint.partners') ?>
            </div>
        </div>

        <input type="hidden" name="super_admin_passcode" id="edit_passcode" value="">
        <button type="submit" class="btn" style="width: 100%; margin-bottom: 15px;"><?= __e('admin.common.save-changes') ?></button>
    </form>

    <form method="POST" action="dashboard.php" id="deleteForm" onsubmit="return confirmDelete();">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
        <input type="hidden" name="delete_event_id" value="<?= htmlspecialchars($eventId) ?>">
        <input type="hidden" name="super_admin_passcode" id="delete_passcode" value="">
        <button type="submit" class="btn btn-red" style="width: 100%;"><?= __e('admin.edit-event.delete-button') ?></button>
    </form>
</div>

<script src="script.js"></script>
<script>
    const originalEventName = <?= json_encode($event['name']) ?>;

    function confirmEdit() {
        const currentName = document.getElementById('eventNameInput').value.trim();

        if (currentName !== originalEventName) {
            let code = prompt(<?= json_encode(__('admin.edit-event.prompt.passcode-name-change')) ?>);
            if (code) {
                document.getElementById('edit_passcode').value = code;
                return true;
            }
            alert(<?= json_encode(__('admin.edit-event.alert.save-cancelled')) ?>);
            return false;
        }

        return true; // Name didn't change, allow save without passcode
    }

    function confirmDelete() {
        if (!confirm(<?= json_encode(__('admin.edit-event.confirm.delete')) ?>)) {
            return false;
        }

        let code = prompt(<?= json_encode(__('admin.common.prompt.passcode-deletion')) ?>);
        if (code) {
            document.getElementById('delete_passcode').value = code;
            return true;
        }

        alert(<?= json_encode(__('admin.common.alert.passcode-cancelled')) ?>);
        return false;
    }
</script>
<?php if ($error): ?>
<script>
    window.flashMessage = <?= json_encode($error) ?>;
    window.flashMessageType = 'error';
</script>
<?php elseif ($success): ?>
<script>
    window.flashMessage = <?= json_encode($success) ?>;
    window.flashMessageType = 'success';
</script>
<?php endif; ?>
</body>
</html>
