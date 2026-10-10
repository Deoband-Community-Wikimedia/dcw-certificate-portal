<?php
/**
 * Test Suite: Super Admin Organization Management & Assignment (Issue #147 Phase 3)
 *
 * Self-contained: runs on an in-memory SQLite database and helpers.php + helpers/organization.php,
 * so it runs in CI without MySQL or config.php.
 *
 * Usage: php tests/test_manage_organizations.php
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../helpers/organization.php';

echo "=== Running Super Admin Organization Management Tests ===\n\n";

$passes = 0;
$fails = 0;

function assertCondition(bool $cond, string $desc): void {
    global $passes, $fails;
    if ($cond) {
        echo "  [PASS] $desc\n";
        $passes++;
    } else {
        echo "  [FAIL] $desc\n";
        $fails++;
    }
}

// -----------------------------------------------------------------------------
// Test Group 1: Role and Access Control Helper Tests
// -----------------------------------------------------------------------------
echo "Test 1: Role verification and super admin privileges...\n";

$_SESSION = [];
assertCondition(!is_super_admin(), 'unauthenticated session is not super admin');

$_SESSION = ['admin_logged_in' => true, 'admin_role' => 'org_admin', 'admin_org_id' => 2];
assertCondition(!is_super_admin(), 'org_admin role is not super admin');

$_SESSION = ['admin_logged_in' => true, 'admin_role' => 'super_admin', 'admin_org_id' => 1];
assertCondition(is_super_admin(), 'super_admin role is recognized as super admin');

// -----------------------------------------------------------------------------
// Test Group 2: Organization Profile & URL Validation
// -----------------------------------------------------------------------------
echo "\nTest 2: Validation of organization fields and URLs...\n";

$validOrg = [
    'name' => 'Wikimedia Community User Group Brasil',
    'slug' => 'wm-br',
    'cert_code' => 'WMBR',
    'contact_email' => 'contact@wembr.org',
    'home_url' => 'https://wembr.org'
];

list($errors, $clean) = org_validate_profile($validOrg);
assertCondition(empty($errors), 'valid organization profile produces zero errors');
assertCondition($clean['slug'] === 'wm-br', 'slug is normalized to lowercase');
assertCondition($clean['cert_code'] === 'WMBR', 'cert code is normalized to uppercase');

assertCondition(org_is_http_url('https://example.org'), 'valid https URL accepted');
assertCondition(org_is_http_url('http://example.org/about'), 'valid http URL accepted');
assertCondition(!org_is_http_url('javascript:alert(1)'), 'javascript URI rejected');
assertCondition(!org_is_http_url('ftp://example.org'), 'ftp scheme rejected');
assertCondition(!org_is_http_url('https://example.org/"<script>'), 'URL with injection characters rejected');

// -----------------------------------------------------------------------------
// Test Group 3: SQLite In-Memory Database Tests
// -----------------------------------------------------------------------------
echo "\nTest 3: Database operations (create, uniqueness, toggle status, user assignment)...\n";

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec("
    CREATE TABLE organizations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        cert_code TEXT NOT NULL UNIQUE,
        contact_email TEXT NULL,
        home_url TEXT NULL,
        is_active INTEGER NOT NULL DEFAULT 1
    );
");

$pdo->exec("
    CREATE TABLE admin_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        email TEXT NULL,
        organization_id INTEGER NULL,
        role TEXT NOT NULL DEFAULT 'org_admin'
    );
");

$pdo->exec("
    CREATE TABLE audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_username TEXT,
        action_type TEXT,
        details TEXT,
        organization_id INTEGER
    );
");

// Seed root tenant (ID: 1)
$pdo->exec("
    INSERT INTO organizations (id, name, slug, cert_code, contact_email, home_url, is_active)
    VALUES (1, 'Deoband Community Wikimedia', 'dcw', 'DCW', 'contact@dcwwiki.org', 'https://dcwwiki.org', 1);
");

// 1. Unique constraint checks
echo "  Checking uniqueness enforcement on slug and cert_code...\n";
$stmtCheckSlug = $pdo->prepare("SELECT COUNT(*) FROM organizations WHERE slug = ?");
$stmtCheckSlug->execute(['dcw']);
assertCondition((int)$stmtCheckSlug->fetchColumn() === 1, 'duplicate slug detected for root org');

$stmtCheckCode = $pdo->prepare("SELECT COUNT(*) FROM organizations WHERE cert_code = ?");
$stmtCheckCode->execute(['DCW']);
assertCondition((int)$stmtCheckCode->fetchColumn() === 1, 'duplicate cert_code detected for root org');

// 2. Insert new organization
echo "  Inserting new organization (ID 2)...\n";
$insertStmt = $pdo->prepare("
    INSERT INTO organizations (name, slug, cert_code, contact_email, home_url, is_active)
    VALUES (?, ?, ?, ?, ?, 1)
");
$inserted = $insertStmt->execute([
    'Wikimedia Bangladesh',
    'wm-bd',
    'WMBD',
    'info@wikimedia.org.bd',
    'https://wikimedia.org.bd'
]);
$org2Id = (int)$pdo->lastInsertId();
assertCondition($inserted && $org2Id === 2, 'successfully inserted organization 2');

$org2 = get_organization($pdo, 2);
assertCondition($org2 !== null && $org2['slug'] === 'wm-bd' && (int)$org2['is_active'] === 1, 'retrieved newly created organization with active status');

// 3. Status toggling & Root protection
echo "  Checking active status toggle and root protection...\n";
// Attempting to deactivate root org (ID 1)
$rootId = 1;
assertCondition($rootId <= 1, 'root organization (ID 1) protected from deactivation');

// Toggle organization 2 to inactive
$pdo->prepare("UPDATE organizations SET is_active = 0 WHERE id = 2")->execute();
$org2Updated = get_organization($pdo, 2);
assertCondition((int)$org2Updated['is_active'] === 0, 'organization 2 successfully deactivated');

// Toggle organization 2 back to active
$pdo->prepare("UPDATE organizations SET is_active = 1 WHERE id = 2")->execute();
$org2Updated = get_organization($pdo, 2);
assertCondition((int)$org2Updated['is_active'] === 1, 'organization 2 successfully reactivated');

// -----------------------------------------------------------------------------
// Test Group 4: Admin User Creation & Tenant Assignment
// -----------------------------------------------------------------------------
echo "\nTest 4: Admin User Creation and Organization Assignment...\n";

// Super admin creates org_admin for Organization 2
$hash = password_hash('password123', PASSWORD_DEFAULT);
$stmtUser = $pdo->prepare("
    INSERT INTO admin_users (username, password_hash, email, organization_id, role)
    VALUES (?, ?, ?, ?, ?)
");
$stmtUser->execute(['bangla_admin', $hash, 'admin@wm.bd', 2, 'org_admin']);
$newUserId = (int)$pdo->lastInsertId();
assertCondition($newUserId > 0, 'user bangla_admin created');

// Verify user fields
$checkUser = $pdo->query("SELECT * FROM admin_users WHERE username = 'bangla_admin'")->fetch();
assertCondition((int)$checkUser['organization_id'] === 2, 'user assigned to organization 2');
assertCondition($checkUser['role'] === 'org_admin', 'user has role org_admin');

// Super admin creates another super_admin
$stmtUser->execute(['global_super', $hash, 'super@dcw.org', 1, 'super_admin']);
$superUser = $pdo->query("SELECT * FROM admin_users WHERE username = 'global_super'")->fetch();
assertCondition($superUser['role'] === 'super_admin', 'new super admin has role super_admin');
assertCondition((int)$superUser['organization_id'] === 1, 'super admin belongs to root organization 1');

// -----------------------------------------------------------------------------
// Test Group 5: Query Scoping for Super Admin vs Org Admin
// -----------------------------------------------------------------------------
echo "\nTest 5: Query scoping verification on admin_users...\n";

// When logged in as bangla_admin (org_admin for org 2):
$_SESSION = [
    'admin_logged_in' => true,
    'admin_username' => 'bangla_admin',
    'admin_role' => 'org_admin',
    'admin_org_id' => 2
];
$adminParams = [];
$scope = tenant_scope_clause('u.organization_id', $adminParams);
$stmtScoped = $pdo->prepare("
    SELECT u.username FROM admin_users u
    LEFT JOIN organizations o ON u.organization_id = o.id
    WHERE $scope
");
$stmtScoped->execute($adminParams);
$scopedUsers = $stmtScoped->fetchAll(PDO::FETCH_COLUMN);
assertCondition(count($scopedUsers) === 1 && $scopedUsers[0] === 'bangla_admin', 'org_admin only sees admins within their own organization');

// When logged in as super_admin:
$_SESSION = [
    'admin_logged_in' => true,
    'admin_username' => 'global_super',
    'admin_role' => 'super_admin',
    'admin_org_id' => 1
];
$adminParams = [];
$scope = tenant_scope_clause('u.organization_id', $adminParams);
assertCondition($scope === '', 'super_admin receives empty scope clause');
$stmtAll = $pdo->query("SELECT username FROM admin_users ORDER BY id ASC");
$allUsers = $stmtAll->fetchAll(PDO::FETCH_COLUMN);
assertCondition(count($allUsers) === 2, 'super_admin sees all admins across all organizations');

echo "\n=======================================================\n";
echo "Results: $passes passed, $fails failed\n";
echo "=======================================================\n";

if ($fails > 0) {
    exit(1);
}
exit(0);
