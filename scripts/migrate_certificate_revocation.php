<?php
/**
 * DCW Certificate Portal - Certificate Revocation Migration (issue #159)
 *
 * Usage:
 *   php scripts/migrate_certificate_revocation.php
 *
 * Idempotently adds status, revocation_reason, revoked_at and revoked_by
 * to event_participants. Existing certificates default to 'active'.
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

echo "=======================================================\n";
echo " DCW Certificate Portal - Revocation Migration Tool    \n";
echo "=======================================================\n\n";

try {
    revocation_ensure_schema($pdo);
    echo "[✓] event_participants now has status, revocation_reason, revoked_at, revoked_by.\n";

    $active = $pdo->query("SELECT COUNT(*) FROM event_participants WHERE status = 'active'")->fetchColumn();
    $revoked = $pdo->query("SELECT COUNT(*) FROM event_participants WHERE status = 'revoked'")->fetchColumn();
    echo "[✓] Certificates: {$active} active, {$revoked} revoked.\n";
    echo "=======================================================\n";
    exit(0);
} catch (Exception $e) {
    echo "\n[✗] Migration error: " . $e->getMessage() . "\n";
    exit(1);
}