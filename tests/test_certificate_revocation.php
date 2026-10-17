<?php
/**
 * Test Suite: Certificate Revocation & Reinstatement (Issue #159)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../helpers.php';

echo "=== Running Certificate Revocation Test Suite ===\n\n";
$passes = 0;
$fails = 0;

function assertTest(bool $condition, string $desc): void {
    global $passes, $fails;
    if ($condition) {
        echo "  [PASS] $desc\n";
        $passes++;
    } else {
        echo "  [FAIL] $desc\n";
        $fails++;
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Pre-migration schema: event_participants WITHOUT the revocation columns.
$pdo->exec("
    CREATE TABLE events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        organization_id INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE participants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        email TEXT NOT NULL,
        organization_id INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE event_participants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        participant_id INTEGER NOT NULL,
        event_id INTEGER NOT NULL,
        role_id INTEGER,
        certificate_id TEXT UNIQUE
    );
    CREATE TABLE audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        organization_id INTEGER,
        admin_username TEXT NOT NULL,
        action_type TEXT NOT NULL,
        details TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    INSERT INTO events (id, name, organization_id) VALUES (101, 'DCW Event', 1), (201, 'CapX Event', 2);
    INSERT INTO participants (id, full_name, email, organization_id) VALUES (1, 'Alice', 'a@x.org', 1), (2, 'Bob', 'b@x.org', 2);
    INSERT INTO event_participants (participant_id, event_id, role_id, certificate_id) VALUES
        (1, 101, 1, 'DCW-AAAA1111'),
        (2, 201, 2, 'CAPX-BBBB2222');
");

function certRow(PDO $pdo, string $certId): array {
    $st = $pdo->prepare("SELECT * FROM event_participants WHERE certificate_id = ?");
    $st->execute([$certId]);
    return $st->fetch();
}

// -----------------------------------------------------------------------------
echo "Test 1: Idempotent schema migration...\n";
revocation_ensure_schema($pdo);
revocation_ensure_schema($pdo); // second run must not fail
$cols = array_column($pdo->query("PRAGMA table_info(event_participants)")->fetchAll(), 'name');
foreach (['status', 'revocation_reason', 'revoked_at', 'revoked_by'] as $c) {
    assertTest(in_array($c, $cols, true), "Column '$c' exists after migration");
}
assertTest(certRow($pdo, 'DCW-AAAA1111')['status'] === 'active', 'Existing certificates default to active');
assertTest(certificate_is_revoked($pdo, 'DCW-AAAA1111') === false, 'Active certificate is not revoked');
assertTest(certificate_is_revoked($pdo, 'DOES-NOT-EXIST') === false, 'Unknown certificate is not reported as revoked');

// -----------------------------------------------------------------------------
echo "\nTest 2: Mandatory reason...\n";
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_role'] = 'org_admin';
$_SESSION['admin_org_id'] = 1;
$_SESSION['admin_username'] = 'org1_admin';

$r = revoke_certificate($pdo, 101, 1, '');
assertTest($r['success'] === false && $r['error'] === 'reason_required', 'Empty reason is rejected');
$r = revoke_certificate($pdo, 101, 1, "   \n  ");
assertTest($r['success'] === false && $r['error'] === 'reason_required', 'Whitespace-only reason is rejected');
$r = revoke_certificate($pdo, 101, 1, str_repeat('x', 256));
assertTest($r['success'] === false && $r['error'] === 'reason_too_long', 'Reason over 255 chars is rejected');
assertTest(certRow($pdo, 'DCW-AAAA1111')['status'] === 'active', 'Certificate stays active after rejected attempts');

// -----------------------------------------------------------------------------
echo "\nTest 3: Revoke by owning org admin...\n";
$r = revoke_certificate($pdo, 101, 1, 'Issued in error - reissued as DCW-NEW00001');
assertTest($r['success'] === true && $r['certificate_id'] === 'DCW-AAAA1111', 'Org 1 admin revokes own certificate');
$row = certRow($pdo, 'DCW-AAAA1111');
assertTest($row['status'] === 'revoked', 'Status is revoked');
assertTest($row['revocation_reason'] === 'Issued in error - reissued as DCW-NEW00001', 'Reason stored');
assertTest($row['revoked_by'] === 'org1_admin', 'revoked_by records the admin');
assertTest(!empty($row['revoked_at']), 'revoked_at is set');
assertTest(certificate_is_revoked($pdo, 'DCW-AAAA1111') === true, 'certificate_is_revoked() returns true');

$r = revoke_certificate($pdo, 101, 1, 'Second attempt');
assertTest($r['success'] === false && $r['error'] === 'not_active', 'Revoking an already revoked certificate fails');
$r = revoke_certificate($pdo, 101, 999, 'No such participant');
assertTest($r['success'] === false && $r['error'] === 'not_found', 'Unknown participant returns not_found');

// -----------------------------------------------------------------------------
echo "\nTest 4: Cross-tenant isolation...\n";
$_SESSION['admin_org_id'] = 1;
$r = revoke_certificate($pdo, 201, 2, 'Org 1 trying to revoke Org 2');
assertTest($r['success'] === false && $r['error'] === 'unauthorized', 'Org 1 admin CANNOT revoke Org 2 certificate');
assertTest(certRow($pdo, 'CAPX-BBBB2222')['status'] === 'active', 'Org 2 certificate untouched');

$_SESSION['admin_org_id'] = 2;
$r = reinstate_certificate($pdo, 101, 1);
assertTest($r['success'] === false && $r['error'] === 'unauthorized', 'Org 2 admin CANNOT reinstate Org 1 certificate');
assertTest(certRow($pdo, 'DCW-AAAA1111')['status'] === 'revoked', 'Org 1 certificate still revoked');

$_SESSION['admin_role'] = 'super_admin';
$r = revoke_certificate($pdo, 201, 2, 'Disqualified');
assertTest($r['success'] === true, 'Super admin can revoke any tenant certificate');

// -----------------------------------------------------------------------------
echo "\nTest 5: Reinstatement...\n";
$_SESSION['admin_role'] = 'org_admin';
$_SESSION['admin_org_id'] = 1;
$r = reinstate_certificate($pdo, 101, 1);
assertTest($r['success'] === true, 'Org 1 admin reinstates own certificate');
$row = certRow($pdo, 'DCW-AAAA1111');
assertTest($row['status'] === 'active', 'Status back to active');
assertTest($row['revocation_reason'] === null && $row['revoked_at'] === null && $row['revoked_by'] === null, 'Revocation fields cleared');
$r = reinstate_certificate($pdo, 101, 1);
assertTest($r['success'] === false && $r['error'] === 'not_revoked', 'Reinstating an active certificate fails');

// -----------------------------------------------------------------------------
echo "\nTest 6: Audit trail...\n";
$logs = $pdo->query("SELECT * FROM audit_logs ORDER BY id")->fetchAll();
$types = array_column($logs, 'action_type');
assertTest(count(array_keys($types, 'Certificate Revoked')) === 2, 'Two revocations audited');
assertTest(count(array_keys($types, 'Certificate Reinstated')) === 1, 'One reinstatement audited');
assertTest($logs[0]['admin_username'] === 'org1_admin', 'Audit row records who acted');
assertTest(strpos($logs[0]['details'], 'DCW-AAAA1111') !== false && strpos($logs[0]['details'], 'Issued in error') !== false, 'Audit details include certificate ID and reason');
assertTest((int)$logs[0]['organization_id'] === 1, 'Audit row is tagged with the tenant');
$reinstateLog = array_values(array_filter($logs, fn($l) => $l['action_type'] === 'Certificate Reinstated'))[0];
assertTest(strpos($reinstateLog['details'], 'Issued in error') !== false, 'Reinstatement audit preserves the previous reason');
assertTest(count($logs) === 3, 'Rejected attempts produce no audit rows');

// -----------------------------------------------------------------------------
echo "\nTest 7: Static audit of enforcement points...\n";
$filesToCheck = [
    'helpers.php'                   => ["helpers/revocation.php", 'status'],
    'verify.php'                    => ['isRevoked', 'revoked_at', 'revocation_reason'],
    'download.php'                  => ["'revoked'", '410'],
    'send-email.php'                => ["'revoked'", '410'],
    'thumbnail.php'                 => ['certificate_is_revoked'],
    'admin/manage_participants.php' => ['revoke_certificate', 'reinstate_certificate', 'verify_event_access', 'csrf_token'],
    'scripts/migrate_certificate_revocation.php' => ['revocation_ensure_schema'],
    'index.php'                     => ['certificate_is_revoked', "'revoked'", 'page.claim.error.revoked'],
    'success.php'                   => ["'revoked'", 'verify.php?id='],
];
foreach ($filesToCheck as $filePath => $tokens) {
    $fullPath = __DIR__ . '/../' . $filePath;
    assertTest(file_exists($fullPath), "File exists: $filePath");
    $content = file_exists($fullPath) ? file_get_contents($fullPath) : '';
    foreach ($tokens as $token) {
        assertTest(strpos($content, $token) !== false, "$filePath contains '$token'");
    }
}

echo "\n=== Revocation Test Summary ===\n";
echo "Total Passed: $passes\n";
echo "Total Failed: $fails\n";

if ($fails > 0) {
    echo "FAILED: Some tests failed!\n";
    exit(1);
}
echo "SUCCESS: All certificate revocation tests passed (100%)!\n";
exit(0);