<?php
/**
 * Bulk certificate download: every active certificate of an event (optionally one role) as a single ZIP.
 *
 * GET id=<event id> [role=<role id>]
 */
session_start();
require_once '../config.php';
revocation_ensure_schema($pdo);

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

$eventId = (int)($_GET['id'] ?? 0);
if (!$eventId) {
    header("Location: dashboard.php");
    exit;
}

// Multi-tenant (#147/#148): only an event this admin's organization owns can be exported.
$event = verify_event_access($pdo, $eventId);
if (!$event) {
    header("Location: dashboard.php?msg=unauthorized");
    exit;
}

$back = "manage_participants.php?id=" . $eventId;

// An optional role filter must be one of THIS event's roles.
$roleId = (int)($_GET['role'] ?? 0);
$roleName = null;
if ($roleId > 0) {
    $stmtRole = $pdo->prepare("SELECT role_name FROM event_roles WHERE id = ? AND event_id = ?");
    $stmtRole->execute([$roleId, $eventId]);
    $roleName = $stmtRole->fetchColumn();
    if ($roleName === false) {
        header("Location: $back&msg=zip_empty");
        exit;
    }
}

// Revoked certificates are never handed out for printing.
$sql = "
    SELECT ep.certificate_id
    FROM event_participants ep
    JOIN participants p ON ep.participant_id = p.id
    JOIN event_roles er ON ep.role_id = er.id
    WHERE ep.event_id = ? AND ep.status <> 'revoked' AND ep.certificate_id IS NOT NULL
";
$params = [$eventId];
if ($roleId > 0) {
    $sql .= " AND ep.role_id = ?";
    $params[] = $roleId;
}
$sql .= " ORDER BY p.full_name ASC, ep.certificate_id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$certIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (!$certIds) {
    header("Location: $back&msg=zip_empty");
    exit;
}

if (!class_exists('ZipArchive')) {
    error_log('Bulk certificate ZIP: the PHP zip extension is not installed.');
    header("Location: $back&msg=zip_failed");
    exit;
}

// Every access check is done. Release the session lock now: a long export would otherwise block every other
// admin page open in this browser until it finishes. $_SESSION stays readable for the audit log below.
session_write_close();

// Rendering 200+ PDFs takes a while; each one is written to disk rather than held in memory.
set_time_limit(0);

$workDir = sys_get_temp_dir() . '/certzip_' . bin2hex(random_bytes(8));
$zipPath = $workDir . '/bundle.zip';
$added = 0;
$skipped = 0;

$cleanup = function () use ($workDir) {
    foreach (glob($workDir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($workDir);
};
// Also runs if the host kills a long request, so the temp PDFs never linger in /tmp.
register_shutdown_function($cleanup);

if (!mkdir($workDir, 0700)) {
    header("Location: $back&msg=zip_failed");
    exit;
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    $cleanup();
    header("Location: $back&msg=zip_failed");
    exit;
}

foreach ($certIds as $i => $certId) {
    try {
        $certData = certificate_fetch_data($pdo, $certId);
        if (!$certData) {
            $skipped++;
            continue;
        }
        $pdf = certificate_build_pdf($certData, $certId);
        $tmpPdf = $workDir . '/' . $i . '.pdf';
        file_put_contents($tmpPdf, $pdf->Output('', 'S'));
        unset($pdf);

        $entryName = str_replace(' ', '_', sanitizeForFilename($certData['full_name'])) . '_' . $certId . '.pdf';
        $zip->addFile($tmpPdf, $entryName);
        $added++;
    } catch (Throwable $e) {
        error_log("Bulk certificate ZIP: skipped {$certId}: " . $e->getMessage());
        $skipped++;
    }
}

// ZipArchive only reads the temp PDFs on close(), so they must stay on disk until then.
$closed = $added > 0 && $zip->close();
if (!$closed) {
    $cleanup();
    header("Location: $back&msg=zip_failed");
    exit;
}

$slug = trim((string)($event['slug'] ?? '')) !== '' ? $event['slug'] : $event['name'];
$roleLabel = $roleName !== null ? $roleName : 'All Roles';
$zipName = str_replace(' ', '_', sanitizeForFilename($slug)) . '_' . str_replace(' ', '_', sanitizeForFilename($roleLabel)) . '_certificates.zip';

log_audit_action(
    $pdo,
    'Bulk Certificate Download',
    "Event ID: {$eventId}, Role: {$roleLabel}, Certificates: {$added}" . ($skipped ? ", Skipped: {$skipped}" : '')
);

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/zip');
header('Content-Length: ' . filesize($zipPath));
header("Content-Disposition: attachment; filename=\"" . addcslashes($zipName, "\"\\") . "\"; filename*=UTF-8''" . rawurlencode($zipName));
header('Cache-Control: private, no-store');
readfile($zipPath);
$cleanup();
exit;
