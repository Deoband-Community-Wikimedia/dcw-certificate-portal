<?php
/**
 * Test Suite: Controller Tenant Scoping & Anti-IDOR Security Isolation (Issue #147 Phase 2)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../helpers.php';

echo "=== Running Controller Tenant Scoping & Anti-IDOR Test Suite ===\n\n";
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

// Setup in-memory test database
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Create schema
$pdo->exec("
    CREATE TABLE organizations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        slug TEXT NOT NULL,
        cert_code TEXT NOT NULL,
        is_active INTEGER DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        category TEXT,
        linkedin_caption TEXT,
        custom_verification_text TEXT,
        cert_prefix TEXT NOT NULL DEFAULT 'DCW',
        certificate_issue_date TEXT,
        completion_date TEXT,
        description TEXT,
        partners TEXT,
        organization_id INTEGER NOT NULL DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE event_roles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id INTEGER NOT NULL,
        role_name TEXT NOT NULL,
        template_file TEXT NOT NULL,
        visual_settings TEXT,
        rotation INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE participants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        email TEXT NOT NULL,
        organization_id INTEGER NOT NULL DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(organization_id, email)
    );

    CREATE TABLE event_participants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        participant_id INTEGER NOT NULL,
        event_id INTEGER NOT NULL,
        role_id INTEGER NOT NULL,
        certificate_id TEXT UNIQUE,
        custom_certificate_text TEXT,
        issue_date TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
");

// Seed test organizations
$pdo->exec("
    INSERT INTO organizations (id, name, slug, cert_code, is_active) VALUES 
    (1, 'Deoband Community Wikimedia', 'dcw', 'DCW', 1),
    (2, 'Wikimedia Capacity Exchange', 'capx', 'WMCAPX', 1),
    (3, 'Inactive Org', 'inactive', 'INACT', 0);
");

// Seed events
$pdo->exec("
    INSERT INTO events (id, name, cert_prefix, organization_id) VALUES 
    (101, 'DCW Annual Conference 2026', 'DCW', 1),
    (102, 'DCW Edit-a-thon 2026', 'DCW', 1),
    (201, 'CapX Capacity Building Workshop', 'WMCAPX', 2);
");

// Seed roles
$pdo->exec("
    INSERT INTO event_roles (id, event_id, role_name, template_file) VALUES 
    (1, 101, 'Speaker', 'dcw/speaker.pdf'),
    (2, 201, 'Lead Trainer', 'capx/trainer.pdf');
");

// Seed participants & event_participants
$pdo->exec("
    INSERT INTO participants (id, full_name, email, organization_id) VALUES 
    (1, 'Alice DCW', 'alice@example.com', 1),
    (2, 'Bob CapX', 'bob@example.com', 2);

    INSERT INTO event_participants (participant_id, event_id, role_id, certificate_id) VALUES 
    (1, 101, 1, 'DCW-A1B2C3D4'),
    (2, 201, 2, 'WMCAPX-X9Y8Z7W6');
");

// -----------------------------------------------------------------------------
// Test 1: Anti-IDOR Security Guard (verify_event_access)
// -----------------------------------------------------------------------------
echo "Test 1: Anti-IDOR Access Control Across Tenants...\n";

// Case A: Org 1 admin
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_org_id'] = 1;
$_SESSION['admin_role'] = 'org_admin';

assertTest(verify_event_access($pdo, 101) !== null, 'Org 1 admin can access Org 1 event (101)');
assertTest(verify_event_access($pdo, 102) !== null, 'Org 1 admin can access Org 1 event (102)');
assertTest(verify_event_access($pdo, 201) === null, 'IDOR blocked: Org 1 admin CANNOT access Org 2 event (201)');
assertTest(verify_event_access($pdo, 9999) === null, 'Non-existent event returns null');
assertTest(verify_event_access($pdo, 0) === null, 'Invalid event ID 0 returns null');
assertTest(verify_event_access($pdo, -5) === null, 'Negative event ID returns null');

// Case B: Org 2 admin
$_SESSION['admin_org_id'] = 2;
$_SESSION['admin_role'] = 'org_admin';

assertTest(verify_event_access($pdo, 201) !== null, 'Org 2 admin can access Org 2 event (201)');
assertTest(verify_event_access($pdo, 101) === null, 'IDOR blocked: Org 2 admin CANNOT access Org 1 event (101)');
assertTest(verify_event_access($pdo, 102) === null, 'IDOR blocked: Org 2 admin CANNOT access Org 1 event (102)');

// Case C: Super admin
$_SESSION['admin_role'] = 'super_admin';
assertTest(verify_event_access($pdo, 101) !== null, 'Super admin can access Org 1 event (101)');
assertTest(verify_event_access($pdo, 201) !== null, 'Super admin can access Org 2 event (201)');

// -----------------------------------------------------------------------------
// Test 2: Dashboard Query Scoping & Tenant Isolation
// -----------------------------------------------------------------------------
echo "\nTest 2: Dashboard Event Listing & Analytics Scoping...\n";

function simulateDashboardQuery(PDO $pdo, int $orgFilter = 0): array {
    $whereClauses = [];
    $params = [];

    if (!is_super_admin()) {
        $whereClauses[] = "e.organization_id = ?";
        $params[] = get_current_tenant_id();
    } elseif ($orgFilter > 0) {
        $whereClauses[] = "e.organization_id = ?";
        $params[] = $orgFilter;
    }

    $whereSql = !empty($whereClauses) ? " WHERE " . implode(" AND ", $whereClauses) : "";
    $stmt = $pdo->prepare("SELECT e.*, o.name as org_name, o.cert_code as org_code FROM events e LEFT JOIN organizations o ON e.organization_id = o.id $whereSql ORDER BY e.id ASC");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Org 1 admin dashboard
$_SESSION['admin_role'] = 'org_admin';
$_SESSION['admin_org_id'] = 1;
$org1Events = simulateDashboardQuery($pdo);
assertTest(count($org1Events) === 2, 'Org 1 admin sees exactly 2 events');
assertTest($org1Events[0]['id'] == 101 && $org1Events[1]['id'] == 102, 'Org 1 admin only sees Org 1 events');

// Org 2 admin dashboard
$_SESSION['admin_org_id'] = 2;
$org2Events = simulateDashboardQuery($pdo);
assertTest(count($org2Events) === 1, 'Org 2 admin sees exactly 1 event');
assertTest($org2Events[0]['id'] == 201, 'Org 2 admin only sees event 201');

// Super admin dashboard (no filter)
$_SESSION['admin_role'] = 'super_admin';
$superAllEvents = simulateDashboardQuery($pdo, 0);
assertTest(count($superAllEvents) === 3, 'Super admin sees all 3 events across all organizations');

// Super admin dashboard filtered to Org 2
$superFilteredEvents = simulateDashboardQuery($pdo, 2);
assertTest(count($superFilteredEvents) === 1, 'Super admin filter to Org 2 returns exactly 1 event');
assertTest($superFilteredEvents[0]['organization_id'] == 2, 'Super admin filter matches Org 2');

// -----------------------------------------------------------------------------
// Test 3: Dashboard Tenant-Scoped Analytics Queries
// -----------------------------------------------------------------------------
echo "\nTest 3: Dashboard Analytics Isolation...\n";

function simulateAnalytics(PDO $pdo, int $orgFilter = 0): array {
    if (!is_super_admin()) {
        $tenantId = get_current_tenant_id();
        $st1 = $pdo->prepare("SELECT COUNT(*) FROM events WHERE organization_id = ?");
        $st1->execute([$tenantId]);
        $totalEvents = $st1->fetchColumn();

        $st2 = $pdo->prepare("SELECT COUNT(DISTINCT ep.participant_id) FROM event_participants ep JOIN events e ON ep.event_id = e.id WHERE e.organization_id = ?");
        $st2->execute([$tenantId]);
        $totalParticipants = $st2->fetchColumn();

        $st3 = $pdo->prepare("SELECT COUNT(*) FROM event_participants ep JOIN events e ON ep.event_id = e.id WHERE e.organization_id = ? AND ep.certificate_id IS NOT NULL");
        $st3->execute([$tenantId]);
        $totalCerts = $st3->fetchColumn();
    } elseif ($orgFilter > 0) {
        $st1 = $pdo->prepare("SELECT COUNT(*) FROM events WHERE organization_id = ?");
        $st1->execute([$orgFilter]);
        $totalEvents = $st1->fetchColumn();

        $st2 = $pdo->prepare("SELECT COUNT(DISTINCT ep.participant_id) FROM event_participants ep JOIN events e ON ep.event_id = e.id WHERE e.organization_id = ?");
        $st2->execute([$orgFilter]);
        $totalParticipants = $st2->fetchColumn();

        $st3 = $pdo->prepare("SELECT COUNT(*) FROM event_participants ep JOIN events e ON ep.event_id = e.id WHERE e.organization_id = ? AND ep.certificate_id IS NOT NULL");
        $st3->execute([$orgFilter]);
        $totalCerts = $st3->fetchColumn();
    } else {
        $totalEvents = $pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
        $totalParticipants = $pdo->query("SELECT COUNT(DISTINCT participant_id) FROM event_participants")->fetchColumn();
        $totalCerts = $pdo->query("SELECT COUNT(*) FROM event_participants WHERE certificate_id IS NOT NULL")->fetchColumn();
    }

    return ['events' => (int)$totalEvents, 'participants' => (int)$totalParticipants, 'certs' => (int)$totalCerts];
}

$_SESSION['admin_role'] = 'org_admin';
$_SESSION['admin_org_id'] = 1;
$statsOrg1 = simulateAnalytics($pdo);
assertTest($statsOrg1['events'] === 2, 'Org 1 analytics reports 2 events');
assertTest($statsOrg1['participants'] === 1, 'Org 1 analytics reports 1 participant');
assertTest($statsOrg1['certs'] === 1, 'Org 1 analytics reports 1 certificate');

$_SESSION['admin_org_id'] = 2;
$statsOrg2 = simulateAnalytics($pdo);
assertTest($statsOrg2['events'] === 1, 'Org 2 analytics reports 1 event');
assertTest($statsOrg2['participants'] === 1, 'Org 2 analytics reports 1 participant');
assertTest($statsOrg2['certs'] === 1, 'Org 2 analytics reports 1 certificate');

$_SESSION['admin_role'] = 'super_admin';
$statsSuperAll = simulateAnalytics($pdo, 0);
assertTest($statsSuperAll['events'] === 3, 'Super admin analytics reports 3 events across all tenants');
assertTest($statsSuperAll['participants'] === 2, 'Super admin analytics reports 2 participants across all tenants');
assertTest($statsSuperAll['certs'] === 2, 'Super admin analytics reports 2 certificates across all tenants');

// -----------------------------------------------------------------------------
// Test 4: Participant Isolation Across Tenants (Shared Emails)
// -----------------------------------------------------------------------------
echo "\nTest 4: Multi-Tenant Participant Email Isolation...\n";

// Insert participant in Org 1 with email 'common@user.org'
$stPart = $pdo->prepare("INSERT INTO participants (full_name, email, organization_id) VALUES (?, ?, ?)");
$stPart->execute(['Common User (Org 1)', 'common@user.org', 1]);
$org1UserId = $pdo->lastInsertId();

// Insert participant in Org 2 with the SAME email 'common@user.org'
$stPart->execute(['Common User (Org 2)', 'common@user.org', 2]);
$org2UserId = $pdo->lastInsertId();

assertTest($org1UserId !== $org2UserId, 'Different tenant records created for the same email address');

$fetchPart = $pdo->prepare("SELECT full_name FROM participants WHERE email = ? AND organization_id = ?");
$fetchPart->execute(['common@user.org', 1]);
assertTest($fetchPart->fetchColumn() === 'Common User (Org 1)', 'Org 1 correctly resolves its participant record');

$fetchPart->execute(['common@user.org', 2]);
assertTest($fetchPart->fetchColumn() === 'Common User (Org 2)', 'Org 2 correctly resolves its participant record');

// -----------------------------------------------------------------------------
// Test 5: Role & Certificate Child Entity IDOR Protection
// -----------------------------------------------------------------------------
echo "\nTest 5: Child Entity (Roles & Certificates) Anti-IDOR Protection...\n";

// Role ID 2 belongs to Event 201 (Org 2)
$stRole = $pdo->prepare("SELECT event_id FROM event_roles WHERE id = ?");
$stRole->execute([2]);
$roleEventId = (int)$stRole->fetchColumn();

$_SESSION['admin_role'] = 'org_admin';
$_SESSION['admin_org_id'] = 1;
assertTest(verify_event_access($pdo, $roleEventId) === null, 'Org 1 admin cannot access role belonging to Org 2');

$_SESSION['admin_org_id'] = 2;
assertTest(verify_event_access($pdo, $roleEventId) !== null, 'Org 2 admin can access role belonging to Org 2');

// Certificate WMCAPX-X9Y8Z7W6 belongs to Event 201 (Org 2)
$stCert = $pdo->prepare("SELECT event_id FROM event_participants WHERE certificate_id = ?");
$stCert->execute(['WMCAPX-X9Y8Z7W6']);
$certEventId = (int)$stCert->fetchColumn();

$_SESSION['admin_org_id'] = 1;
assertTest(verify_event_access($pdo, $certEventId) === null, 'Org 1 admin cannot trigger actions on Org 2 certificate');

$_SESSION['admin_org_id'] = 2;
assertTest(verify_event_access($pdo, $certEventId) !== null, 'Org 2 admin can trigger actions on Org 2 certificate');

// -----------------------------------------------------------------------------
// Test 6: Static File Audit of Anti-IDOR Guards
// -----------------------------------------------------------------------------
echo "\nTest 6: Static Code Audit for Controller Security Guards...\n";

$filesToCheck = [
    'admin/dashboard.php' => ['verify_event_access', 'organization_id'],
    'admin/create_event.php' => ['get_current_tenant_id', 'organization_id'],
    'admin/edit_event.php' => ['verify_event_access', 'msg=unauthorized'],
    'admin/manage_roles.php' => ['verify_event_access', 'msg=unauthorized'],
    'admin/manage_participants.php' => ['verify_event_access', 'msg=unauthorized'],
    'admin/preview_event.php' => ['verify_event_access', 'msg=unauthorized'],
    'admin/preview_pdf.php' => ['verify_event_access', '403'],
    'admin/send-notification.php' => ['verify_event_access', '403']
];

foreach ($filesToCheck as $filePath => $requiredTokens) {
    $fullPath = __DIR__ . '/../' . $filePath;
    assertTest(file_exists($fullPath), "File exists: $filePath");
    $content = file_get_contents($fullPath);
    foreach ($requiredTokens as $token) {
        assertTest(strpos($content, $token) !== false, "$filePath contains security guard '$token'");
    }
}

// -----------------------------------------------------------------------------
// Summary
// -----------------------------------------------------------------------------
echo "\n=== Controller Isolation Test Summary ===\n";
echo "Total Passed: $passes\n";
echo "Total Failed: $fails\n";

if ($fails > 0) {
    echo "FAILED: Some tests failed!\n";
    exit(1);
}

echo "SUCCESS: All controller tenant isolation and anti-IDOR tests passed (100%)!\n";
exit(0);
