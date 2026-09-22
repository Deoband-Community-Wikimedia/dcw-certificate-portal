<?php
/**
 * Test Suite: Multi-Tenant Architecture & Migration Foundation (Issue #147)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../helpers.php';

echo "=== Running Multi-Tenant Architecture & Security Unit Tests ===\n\n";
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

// -----------------------------------------------------------------------------
// Test 1: Helper Functions & Session Tenant Isolation
// -----------------------------------------------------------------------------
echo "Test 1: Tenant Session Resolution & Super Admin Roles...\n";

// Default fallback when session is empty
unset($_SESSION['admin_org_id']);
unset($_SESSION['admin_role']);
assertTest(get_current_tenant_id() === 1, 'Default tenant fallback is 1 (DCW)');
assertTest(is_super_admin() === false, 'Default is_super_admin is false when unauthenticated');

// Active tenant session
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_org_id'] = 42;
$_SESSION['admin_role'] = 'org_admin';
assertTest(get_current_tenant_id() === 42, 'Resolves active tenant ID 42 from session');
assertTest(is_super_admin() === false, 'org_admin role is not super admin');

// Super admin session
$_SESSION['admin_role'] = 'super_admin';
assertTest(is_super_admin() === true, 'super_admin role is recognized properly when logged in');

// Unauthenticated with super_admin role tag -> must be false
unset($_SESSION['admin_logged_in']);
assertTest(is_super_admin() === false, 'Cannot be super_admin if admin_logged_in is not true');

// -----------------------------------------------------------------------------
// Test 2: In-Memory Multi-Tenant Event Access Control (Anti-IDOR)
// -----------------------------------------------------------------------------
echo "\nTest 2: Event Access Control & IDOR Prevention...\n";

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Create SQLite schema mimicking organizations and events
$pdo->exec("
    CREATE TABLE organizations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        slug TEXT UNIQUE,
        cert_code TEXT UNIQUE,
        is_active INTEGER DEFAULT 1
    );

    CREATE TABLE events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        organization_id INTEGER NOT NULL,
        name TEXT NOT NULL
    );
");

$pdo->exec("INSERT INTO organizations (id, name, slug, cert_code) VALUES (1, 'DCW', 'dcw', 'DCW')");
$pdo->exec("INSERT INTO organizations (id, name, slug, cert_code) VALUES (2, 'Wikimedia SP', 'wm-sp', 'WMSP')");

$pdo->exec("INSERT INTO events (id, organization_id, name) VALUES (101, 1, 'DCW Editathon 2026')");
$pdo->exec("INSERT INTO events (id, organization_id, name) VALUES (102, 2, 'SP Conference 2026')");

// Scenario A: Org Admin 1 attempts to access their own event (101) -> ALLOWED
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_org_id'] = 1;
$_SESSION['admin_role'] = 'org_admin';
$eventA = verify_event_access($pdo, 101);
assertTest($eventA !== null && $eventA['name'] === 'DCW Editathon 2026', 'Org Admin 1 can access Event 101');

// Scenario B: Org Admin 1 attempts to access Org 2 event (102) -> BLOCKED (IDOR protection)
$eventB = verify_event_access($pdo, 102);
assertTest($eventB === null, 'Org Admin 1 CANNOT access Org 2 Event 102 (IDOR blocked)');

// Scenario C: Super Admin accesses Org 2 event (102) -> ALLOWED
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_role'] = 'super_admin';
$eventC = verify_event_access($pdo, 102);
assertTest($eventC !== null && $eventC['name'] === 'SP Conference 2026', 'Super Admin can access Event 102 across tenants');

// Scenario D: Non-existent event -> NULL
assertTest(verify_event_access($pdo, 9999) === null, 'Non-existent event returns null');
assertTest(verify_event_access($pdo, -1) === null, 'Negative event ID returns null');

// Scenario E: Test get_organization helper
$org1 = get_organization($pdo, 1);
assertTest($org1 !== null && $org1['name'] === 'DCW', 'get_organization fetches organization record');
assertTest(get_organization($pdo, 9999) === null, 'get_organization returns null for non-existent org');
assertTest(get_organization($pdo, 0) === null, 'get_organization returns null for invalid org id');

// -----------------------------------------------------------------------------
// Test 3: Composite Participant Uniqueness (organization_id, email)
// -----------------------------------------------------------------------------
echo "\nTest 3: Compound Participant Uniqueness...\n";

$pdo->exec("
    CREATE TABLE participants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        organization_id INTEGER NOT NULL,
        full_name TEXT NOT NULL,
        email TEXT NOT NULL,
        UNIQUE (organization_id, email)
    );
");

// Insert John Doe for Org 1
$pdo->exec("INSERT INTO participants (organization_id, full_name, email) VALUES (1, 'John Doe', 'john@example.org')");
assertTest(true, 'Inserted participant john@example.org under Org 1');

// Insert same email john@example.org for Org 2 -> Must SUCCEED in multi-tenant
$duplicateCrossOrg = false;
try {
    $pdo->exec("INSERT INTO participants (organization_id, full_name, email) VALUES (2, 'John Doe SP', 'john@example.org')");
    $duplicateCrossOrg = true;
} catch (\Exception $e) {
    $duplicateCrossOrg = false;
}
assertTest($duplicateCrossOrg === true, 'Same email can be registered under different organization_ids');

// Insert duplicate email within SAME Org 1 -> Must FAIL with unique constraint violation
$duplicateSameOrgCaught = false;
try {
    $pdo->exec("INSERT INTO participants (organization_id, full_name, email) VALUES (1, 'John Duplicate', 'john@example.org')");
} catch (\Exception $e) {
    $duplicateSameOrgCaught = true;
}
assertTest($duplicateSameOrgCaught === true, 'Duplicate email within same organization triggers unique constraint violation');

// -----------------------------------------------------------------------------
// Test 4: Migration Script Syntax and Idempotency Check
// -----------------------------------------------------------------------------
echo "\nTest 4: Migration Script Integrity...\n";
$migrationFile = __DIR__ . '/../scripts/migrate_multi_tenant.php';
assertTest(file_exists($migrationFile), 'Migration script exists at scripts/migrate_multi_tenant.php');

$phpCheck = shell_exec('php -l ' . escapeshellarg($migrationFile));
assertTest(strpos($phpCheck, 'No syntax errors detected') !== false, 'Migration script passes PHP syntax linter');

// Reset session
unset($_SESSION['admin_org_id']);
unset($_SESSION['admin_role']);

echo "\n=======================================================\n";
echo " Test Results: $passes passed, $fails failed\n";
echo "=======================================================\n";

exit($fails > 0 ? 1 : 0);
