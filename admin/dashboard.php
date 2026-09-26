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

    $deleteId = (int)$_POST['delete_event_id'];

    // Anti-IDOR guard: Ensure user has permission to manage this event
    $eventToDelete = verify_event_access($pdo, $deleteId);
    if (!$eventToDelete) {
        header("Location: dashboard.php?msg=auth_error");
        exit;
    }

    $passcode = trim($_POST['super_admin_passcode'] ?? '');
    if ($passcode !== SUPER_ADMIN_PASSCODE) {
        // Redirect with error
        header("Location: dashboard.php?msg=auth_error");
        exit;
    }

    $deletedEventName = $eventToDelete['name'] ?: 'Unknown';

    $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
    $stmt->execute([$deleteId]);

    log_audit_action($pdo, 'Deleted Event', "Event ID: {$deleteId}, Name: {$deletedEventName}");

    header("Location: dashboard.php?msg=deleted");
    exit;
}

$limit = 10;
$page = isset($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$search = trim($_GET['search'] ?? '');
$orgFilter = isset($_GET['org_id']) && (int)$_GET['org_id'] > 0 ? (int)$_GET['org_id'] : 0;

$whereClauses = [];
$params = [];

if (!is_super_admin()) {
    $whereClauses[] = "e.organization_id = ?";
    $params[] = get_current_tenant_id();
} elseif ($orgFilter > 0) {
    $whereClauses[] = "e.organization_id = ?";
    $params[] = $orgFilter;
}

if ($search !== '') {
    $whereClauses[] = "e.name LIKE ?";
    $params[] = "%$search%";
}

$searchQuery = !empty($whereClauses) ? " WHERE " . implode(" AND ", $whereClauses) : "";

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM events e" . $searchQuery);
$stmtCount->execute($params);
$totalRecords = $stmtCount->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

$stmt = $pdo->prepare("
    SELECT e.*, o.name as org_name, o.cert_code as org_code 
    FROM events e 
    LEFT JOIN organizations o ON e.organization_id = o.id 
    $searchQuery 
    ORDER BY e.created_at DESC 
    LIMIT " . (int)$limit . " OFFSET " . (int)$offset
);
$stmt->execute($params);
$events = $stmt->fetchAll();

// Analytics Queries (scoped by active tenant or filter)
if (!is_super_admin()) {
    $activeTenantId = get_current_tenant_id();

    $stmtEv = $pdo->prepare("SELECT COUNT(*) FROM events WHERE organization_id = ?");
    $stmtEv->execute([$activeTenantId]);
    $totalEvents = $stmtEv->fetchColumn();

    $stmtPart = $pdo->prepare("
        SELECT COUNT(DISTINCT ep.participant_id) 
        FROM event_participants ep
        JOIN events e ON ep.event_id = e.id
        WHERE e.organization_id = ?
    ");
    $stmtPart->execute([$activeTenantId]);
    $totalParticipants = $stmtPart->fetchColumn();

    $stmtCert = $pdo->prepare("
        SELECT COUNT(*) 
        FROM event_participants ep
        JOIN events e ON ep.event_id = e.id
        WHERE e.organization_id = ? AND ep.certificate_id IS NOT NULL
    ");
    $stmtCert->execute([$activeTenantId]);
    $totalCerts = $stmtCert->fetchColumn();
} elseif ($orgFilter > 0) {
    $stmtEv = $pdo->prepare("SELECT COUNT(*) FROM events WHERE organization_id = ?");
    $stmtEv->execute([$orgFilter]);
    $totalEvents = $stmtEv->fetchColumn();

    $stmtPart = $pdo->prepare("
        SELECT COUNT(DISTINCT ep.participant_id) 
        FROM event_participants ep
        JOIN events e ON ep.event_id = e.id
        WHERE e.organization_id = ?
    ");
    $stmtPart->execute([$orgFilter]);
    $totalParticipants = $stmtPart->fetchColumn();

    $stmtCert = $pdo->prepare("
        SELECT COUNT(*) 
        FROM event_participants ep
        JOIN events e ON ep.event_id = e.id
        WHERE e.organization_id = ? AND ep.certificate_id IS NOT NULL
    ");
    $stmtCert->execute([$orgFilter]);
    $totalCerts = $stmtCert->fetchColumn();
} else {
    $totalEvents = $pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
    $totalParticipants = $pdo->query("SELECT COUNT(DISTINCT participant_id) FROM event_participants")->fetchColumn();
    $totalCerts = $pdo->query("SELECT COUNT(*) FROM event_participants WHERE certificate_id IS NOT NULL")->fetchColumn();
}

// Fetch all organizations for super admin filter dropdown
$allOrganizations = [];
if (is_super_admin()) {
    $allOrganizations = $pdo->query("SELECT id, name, cert_code FROM organizations WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
}
$currentOrg = get_organization($pdo, get_current_tenant_id());

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="../assets/DCW_logo.png">
    <meta charset="UTF-8">
    <title><?= __e('admin.dashboard.page-title') ?></title>
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
    <?php i18n_lang_switcher_css(); ?>
</head>
<body>

<div class="navbar">
    <div style="display: flex; align-items: center; gap: 15px;">
        <img src="../assets/DCW_logo.png" alt="DCW Logo" width="35" height="35" decoding="async" style="height: 35px; width: 35px; background: white; padding: 2px; border-radius: 50%;">
        <span style="font-size: 18px; font-weight: bold; letter-spacing: 0.5px;"><?= __e('admin.dashboard.nav-title') ?></span>
        <?php if (is_super_admin()): ?>
            <span style="background: #fef3c7; color: #92400e; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Super Admin</span>
        <?php elseif ($currentOrg): ?>
            <span style="background: #e0f2fe; color: #0369a1; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600;"><?= htmlspecialchars($currentOrg['name']) ?> (<?= htmlspecialchars($currentOrg['cert_code']) ?>)</span>
        <?php endif; ?>
    </div>
    <div style="display: flex; align-items: center; gap: 15px;">
        <?php i18n_lang_switcher(); ?>
        <a href="email_logs.php"><?= __e('admin.common.nav.email-logs') ?></a>
        <a href="#" onclick="return viewAuditLogs();"><?= __e('admin.common.nav.audit-logs') ?></a>
        <a href="manage_users.php"><?= __e('admin.common.nav.manage-users') ?></a>
        <a href="organization_settings.php"><?= __e('admin.common.nav.org-settings') ?></a>
        <a href="logout.php"><?= __e('admin.common.nav.logout') ?></a>
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
                <div class="stat-label"><?= __e('admin.dashboard.stat.total-events') ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" width="28" height="28"><path fill="currentColor" d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>
            </div>
            <div class="stat-details">
                <div class="stat-value"><?= number_format($totalCerts) ?></div>
                <div class="stat-label"><?= __e('admin.dashboard.stat.issued-certs') ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" width="28" height="28"><path fill="currentColor" d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
            </div>
            <div class="stat-details">
                <div class="stat-value"><?= number_format($totalParticipants) ?></div>
                <div class="stat-label"><?= __e('admin.dashboard.stat.total-participants') ?></div>
            </div>
        </div>
    </div>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
        <h2 style="margin: 0;"><?= __e('admin.dashboard.heading') ?></h2>
        <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
            <form method="GET" style="display: flex; gap: 10px; align-items: center;">
                <?php if (is_super_admin() && !empty($allOrganizations)): ?>
                    <select name="org_id" onchange="this.form.submit()" style="padding: 9px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; background: white;">
                        <option value="0"><?= __('admin.dashboard.filter.all-organizations') ?: 'All Organizations' ?></option>
                        <?php foreach ($allOrganizations as $org): ?>
                            <option value="<?= $org['id'] ?>" <?= $orgFilter === (int)$org['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($org['name']) ?> (<?= htmlspecialchars($org['cert_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="<?= __e('admin.dashboard.search-placeholder') ?>" style="width: 230px;">
                <button type="submit" class="btn"><?= __e('admin.common.search') ?></button>
                <?php if($search !== '' || $orgFilter > 0): ?>
                    <a href="dashboard.php" class="btn btn-red"><?= __e('admin.common.clear') ?></a>
                <?php endif; ?>
            </form>
            <a href="create_event.php" class="btn"><?= __e('admin.dashboard.create-new') ?></a>
        </div>
    </div>

    <?php if (count($events) > 0): ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th><?= __e('admin.dashboard.table.id') ?></th>
                        <th><?= __e('admin.dashboard.table.name') ?></th>
                        <?php if (is_super_admin()): ?>
                            <th><?= __('admin.dashboard.table.organization') ?: 'Organization' ?></th>
                        <?php endif; ?>
                        <th><?= __e('admin.dashboard.table.created-at') ?></th>
                        <th style="text-align: right;"><?= __e('admin.dashboard.table.actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $event): ?>
                        <tr>
                            <td><?= htmlspecialchars($event['id']) ?></td>
                            <td>
                                <a href="edit_event.php?id=<?= $event['id'] ?>" class="event-link" title="<?= __e('admin.dashboard.action.edit-event') ?>">
                                    <?= htmlspecialchars($event['name']) ?>
                                </a>
                            </td>
                            <?php if (is_super_admin()): ?>
                                <td>
                                    <span class="badge" style="background:#f1f5f9; color:#334155; padding: 3px 8px; border-radius: 4px; font-size: 12px; font-weight: 500;">
                                        <?= htmlspecialchars($event['org_name'] ?? 'DCW') ?> (<?= htmlspecialchars($event['org_code'] ?? 'DCW') ?>)
                                    </span>
                                </td>
                            <?php endif; ?>
                            <td><?= htmlspecialchars($event['created_at']) ?></td>
                            <td class="actions" style="text-align: right;">
                                <div class="action-menu-container">
                                    <button type="button" class="kebab-btn" onclick="toggleMenu(this, <?= $event['id'] ?>)" title="<?= __e('admin.dashboard.table.actions') ?>">
                                        <svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>
                                    </button>
                                    <div class="dropdown-menu" id="menu-<?= $event['id'] ?>">
                                        <a href="edit_event.php?id=<?= $event['id'] ?>" class="dropdown-item">
                                            <svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                            <?= __e('admin.dashboard.action.edit-event') ?>
                                        </a>
                                        <a href="manage_roles.php?event_id=<?= $event['id'] ?>" class="dropdown-item">
                                            <svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"/></svg>
                                            <?= __e('admin.dashboard.action.manage-roles') ?>
                                        </a>
                                        <a href="manage_participants.php?id=<?= $event['id'] ?>" class="dropdown-item">
                                            <svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                                            <?= __e('admin.dashboard.action.manage-participants') ?>
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
            $pageParams = [];
            if ($search !== '') $pageParams['search'] = $search;
            if ($orgFilter > 0) $pageParams['org_id'] = $orgFilter;
            $searchParam = !empty($pageParams) ? '&' . http_build_query($pageParams) : '';
            ?>
            <a href="?page=<?= max(1, $page - 1) . $searchParam ?>" class="page-btn <?= ($page <= 1) ? 'disabled' : '' ?>"><?= __e('admin.dashboard.pagination.prev') ?></a>

            <?php for($i = 1; $i <= max(1, $totalPages); $i++): ?>
                <a href="?page=<?= $i . $searchParam ?>" class="page-btn <?= ($i == $page) ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>

            <a href="?page=<?= min(max(1, $totalPages), $page + 1) . $searchParam ?>" class="page-btn <?= ($page >= max(1, $totalPages)) ? 'disabled' : '' ?>"><?= __e('admin.dashboard.pagination.next') ?></a>
        </div>

    <?php else: ?>
        <p><?= __e('admin.dashboard.no-events') ?></p>
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
        let code = prompt(<?= json_encode(__('admin.dashboard.prompt.audit-passcode')) ?>);
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
    window.flashMessage = <?= json_encode(__('admin.dashboard.flash.deleted')) ?>;
    window.flashMessageType = 'success';
</script>
<?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'auth_error'): ?>
<script>
    window.flashMessage = <?= json_encode(__('admin.common.security-error-invalid-passcode')) ?>;
    window.flashMessageType = 'error';
</script>
<?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'unauthorized'): ?>
<script>
    window.flashMessage = <?= json_encode(__('admin.dashboard.flash.unauthorized') ?: 'Access denied. You do not have permission to view or manage that event.') ?>;
    window.flashMessageType = 'error';
</script>
<?php endif; ?>
</body>
</html>
