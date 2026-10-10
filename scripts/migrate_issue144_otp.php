<?php
/**
 * DCW Certificate Portal - Database Migration Tool for Issue #144 (OTP & Verification Security)
 *
 * Usage:
 *   php scripts/migrate_issue144_otp.php
 *
 * Idempotently creates certificate_otps and verification_logs tables.
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../helpers/otp.php';

echo "=======================================================\n";
echo " DCW Certificate Portal - Issue #144 OTP Migration Tool\n";
echo "=======================================================\n\n";

try {
    echo "[-] Creating / verifying 'certificate_otps' table...\n";
    otp_ensure_schema($pdo);
    echo "    -> 'certificate_otps' and 'verification_logs' tables verified successfully.\n";

    echo "\n[+] Migration completed successfully!\n";
    exit(0);
} catch (Exception $e) {
    echo "\n[!] Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
