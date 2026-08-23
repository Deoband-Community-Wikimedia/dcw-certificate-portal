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

    $eventName = trim($_POST['name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    if ($category === '' || !in_array($category, event_categories(), true)) {
        $category = null;
    }
    $linkedinCaption = trim($_POST['linkedin_caption'] ?? '');
    $customVerificationText = trim($_POST['custom_verification_text'] ?? '');
    $certPrefix = trim($_POST['cert_prefix'] ?? 'DCW');
    if ($certPrefix === '') $certPrefix = 'DCW';
    $certificateIssueDate = trim($_POST['certificate_issue_date'] ?? '');
    if ($certificateIssueDate === '') {
        $certificateIssueDate = null;
    }
    $completionDate = trim($_POST['completion_date'] ?? '');
    if ($completionDate === '') {
        $completionDate = null;
    }
    $description = trim($_POST['description'] ?? '');
    if ($description === '') $description = null;
    $partners = trim($_POST['partners'] ?? '');
    if ($partners === '') $partners = null;

    if (!$eventName) {
        $error = __('admin.event-form.error.name-required');
    } else {
        $stmt = $pdo->prepare("INSERT INTO events (name, category, linkedin_caption, custom_verification_text, cert_prefix, certificate_issue_date, completion_date, description, partners) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$eventName, $category, $linkedinCaption, $customVerificationText, $certPrefix, $certificateIssueDate, $completionDate, $description, $partners]);
        $newEventId = $pdo->lastInsertId();

        log_audit_action($pdo, 'Created Event', "Event ID: {$newEventId}, Name: {$eventName}");

        header("Location: manage_roles.php?event_id=" . $newEventId);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="../assets/DCW_logo.png">
    <meta charset="UTF-8">
    <title><?= __e('admin.create-event.page-title') ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="navbar">
    <div style="display: flex; align-items: center; gap: 15px;">
        <img src="../assets/DCW_logo.png" alt="DCW Logo" width="35" height="35" decoding="async" style="height: 35px; width: 35px; background: white; padding: 2px; border-radius: 50%;">
        <span style="font-size: 18px; font-weight: bold; letter-spacing: 0.5px;"><?= __e('admin.create-event.nav-title') ?></span>
    </div>
    <div>
        <a href="dashboard.php"><?= __e('admin.common.nav.dashboard') ?></a>
        <a href="manage_users.php" style="margin-right: 15px;"><?= __e('admin.common.nav.manage-users') ?></a>
        <a href="logout.php"><?= __e('admin.common.nav.logout') ?></a>
    </div>
</div>

<div class="container" style="max-width: 600px;">
    <h2><?= __e('admin.create-event.heading') ?></h2>

    <form method="POST" action="" onsubmit="const btn = this.querySelector('button[type=submit]'); btn.disabled = true; btn.innerText = <?= json_encode(__('admin.create-event.submitting')) ?>;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
        <div class="form-group">
            <label><?= __e('admin.event-form.label.name') ?></label>
            <input type="text" name="name" required placeholder="<?= __e('admin.create-event.placeholder.name') ?>">
        </div>

        <div class="form-group">
            <label><?= __e('admin.event-form.label.category') ?></label>
            <select name="category" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;">
                <option value=""><?= __e('admin.event-form.option.uncategorised') ?></option>
                <?php foreach (event_categories() as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars(event_category_label($cat)) ?></option>
                <?php endforeach; ?>
            </select>
            <div style="font-size: 11px; color: #777; margin-top: 5px;">
                <?= __e('admin.event-form.hint.category') ?>
            </div>
        </div>

        <div class="form-group">
            <label><?= __e('admin.event-form.label.cert-prefix') ?></label>
            <input type="text" name="cert_prefix" placeholder="<?= __e('admin.event-form.placeholder.cert-prefix') ?>" value="DCW" style="text-transform: uppercase;">
            <div style="font-size: 11px; color: #777; margin-top: 5px;">
                <?= __e('admin.event-form.hint.cert-prefix') ?>
            </div>
        </div>

        <div class="form-group">
            <label><?= __e('admin.event-form.label.issue-date') ?></label>
            <input type="date" name="certificate_issue_date" value="">
            <div style="font-size: 11px; color: #777; margin-top: 5px;">
                <?= __e('admin.event-form.hint.issue-date') ?>
            </div>
        </div>

        <div class="form-group">
            <label><?= __e('admin.event-form.label.completion-date') ?></label>
            <input type="date" name="completion_date" value="">
            <div style="font-size: 11px; color: #777; margin-top: 5px;">
                <?= __e('admin.event-form.hint.completion-date') ?>
            </div>
        </div>

        <div class="form-group">
            <label><?= __e('admin.event-form.label.verification-text') ?></label>
            <textarea name="custom_verification_text" rows="3" placeholder="<?= __e('admin.event-form.placeholder.verification-text') ?>" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-family: inherit; resize: vertical;"></textarea>
            <div style="font-size: 11px; color: #777; margin-top: 5px;">
                <?= __e('admin.event-form.hint.verification-text-prefix') ?> <em><?= __e('admin.event-form.default.verification-text') ?></em>
            </div>
        </div>

        <div class="form-group">
            <label><?= __e('admin.event-form.label.linkedin-caption') ?></label>
            <textarea name="linkedin_caption" rows="4" placeholder="<?= __e('admin.event-form.placeholder.linkedin-caption') ?>" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-family: inherit; resize: vertical;"></textarea>
            <div style="font-size: 11px; color: #777; margin-top: 5px;">
                <?= __e('admin.event-form.hint.linkedin-caption') ?>
            </div>
        </div>

        <div class="form-group">
            <label><?= __e('admin.event-form.label.description') ?></label>
            <textarea name="description" rows="3" maxlength="1000" placeholder="<?= __e('admin.event-form.placeholder.description') ?>" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-family: inherit; resize: vertical;"></textarea>
        </div>

        <div class="form-group">
            <label><?= __e('admin.event-form.label.partners') ?></label>
            <input type="text" name="partners" maxlength="255" placeholder="<?= __e('admin.event-form.placeholder.partners') ?>">
            <div style="font-size: 11px; color: #777; margin-top: 5px;">
                <?= __e('admin.event-form.hint.partners') ?>
            </div>
        </div>

        <button type="submit" class="btn" style="width: 100%;"><?= __e('admin.create-event.submit') ?></button>
    </form>
</div>

<script src="script.js"></script>
<?php if ($error): ?>
<script>
    window.flashMessage = <?= json_encode($error) ?>;
    window.flashMessageType = 'error';
</script>
<?php endif; ?>
</body>
</html>
