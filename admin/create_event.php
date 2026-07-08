<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
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
    
    $eventName = trim($_POST['name'] ?? '');
    $linkedinCaption = trim($_POST['linkedin_caption'] ?? '');
    $customVerificationText = trim($_POST['custom_verification_text'] ?? '');
    $certPrefix = trim($_POST['cert_prefix'] ?? 'DCW');
    if ($certPrefix === '') $certPrefix = 'DCW';
    $certificateIssueDate = trim($_POST['certificate_issue_date'] ?? '');
    if ($certificateIssueDate === '') {
        $certificateIssueDate = null;
    }
    $description = trim($_POST['description'] ?? '');
    if ($description === '') $description = null;
    $partners = trim($_POST['partners'] ?? '');
    if ($partners === '') $partners = null;

    if (!$eventName) {
        $error = "Event name is required.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO events (name, linkedin_caption, custom_verification_text, cert_prefix) VALUES (?, ?, ?, ?)");
        $stmt->execute([$eventName, $linkedinCaption, $customVerificationText, $certPrefix]);
        $stmt = $pdo->prepare("INSERT INTO events (name, linkedin_caption, cert_prefix, certificate_issue_date, description, partners) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$eventName, $linkedinCaption, $certPrefix, $certificateIssueDate, $description, $partners]);
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
    <link rel="icon" type="image/png" href="https://dcwwiki.org/images/5/56/DCW_logo.png">
    <meta charset="UTF-8">
    <title>Create Event</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="navbar">
    <div style="display: flex; align-items: center; gap: 15px;">
        <img src="../assets/DCW_logo.png" alt="DCW Logo" width="35" height="35" decoding="async" style="height: 35px; width: 35px; background: white; padding: 2px; border-radius: 50%;">
        <span style="font-size: 18px; font-weight: bold; letter-spacing: 0.5px;">Admin Panel - Create Event</span>
    </div>
    <div>
        <a href="dashboard.php">Dashboard</a>
        <a href="manage_users.php" style="margin-right: 15px;">Manage Users</a>
        <a href="logout.php">Logout</a>
    </div>
</div>

<div class="container" style="max-width: 600px;">
    <h2>Create New Event</h2>
    
    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
        <div class="form-group">
            <label>Event Name</label>
            <input type="text" name="name" required placeholder="e.g. Annual Conference 2026">
        </div>
        
        <div class="form-group">
            <label>Certificate Prefix</label>
            <input type="text" name="cert_prefix" placeholder="e.g. DCW26" value="DCW" style="text-transform: uppercase;">
            <div style="font-size: 11px; color: #777; margin-top: 5px;">
                Used for generating participant IDs (e.g., DCW26-K9X4M7).
            </div>
        </div>

        <div class="form-group">
            <label>Certificate Issue Date (Optional)</label>
            <input type="date" name="certificate_issue_date" value="">
            <div style="font-size: 11px; color: #777; margin-top: 5px;">
                Leave empty to use the event creation date.
            </div>
        </div>
        
        <div class="form-group">
            <label>Custom Certificate Verification Text (Optional)</label>
            <textarea name="custom_verification_text" rows="3" placeholder="e.g. This digital credential was securely issued by our partner organization, [Partner Name], and verified by Deoband Community Wikimedia." style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-family: inherit; resize: vertical;"></textarea>
            <div style="font-size: 11px; color: #777; margin-top: 5px;">
                If left blank, defaults to: <em>This digital credential has been securely issued and verified by Deoband Community Wikimedia.</em>
            </div>
        </div>
        
        <div class="form-group">
            <label>LinkedIn Share Message (Optional)</label>
            <textarea name="linkedin_caption" rows="4" placeholder="e.g. I'm thrilled to announce I've completed the {EVENT_NAME} workshop! Check out my verified credential here: {URL} #DCW2026" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-family: inherit; resize: vertical;"></textarea>
            <div style="font-size: 11px; color: #777; margin-top: 5px;">
                Use <strong>{EVENT_NAME}</strong> and <strong>{URL}</strong> as placeholders. They will be automatically replaced when the participant shares their certificate.
            </div>
        </div>
        
        <div class="form-group">
            <label>Event Description (Optional)</label>
            <textarea name="description" rows="3" maxlength="1000" placeholder="Briefly describe what this event was about. This will be shown on the verification page." style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-family: inherit; resize: vertical;"></textarea>
        </div>

        <div class="form-group">
            <label>Organising Partners (Optional)</label>
            <input type="text" name="partners" maxlength="255" placeholder="e.g. Wikimedia Foundation">
            <div style="font-size: 11px; color: #777; margin-top: 5px;">
                If provided, the credential will say it was issued in partnership with these partners.
            </div>
        </div>
        
        <button type="submit" class="btn" style="width: 100%;">Create Event</button>
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
