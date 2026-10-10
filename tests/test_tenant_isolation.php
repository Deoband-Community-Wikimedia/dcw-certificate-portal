<?php
/**
 * Tenant isolation / IDOR test (issue #149)
 *
 * Self-contained: uses an in-memory SQLite database and helpers.php only, so it
 * runs in CI without MySQL or config.php.
 *
 * Usage (from project root):  php tests/test_tenant_isolation.php
 */
require_once __DIR__ . '/../helpers.php';

$failures = 0;

function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . " - $name\n";
    if (!$ok) {
        $failures++;
    }
}

function set_session(?int $orgId, bool $super = false): void
{
    $_SESSION = [
        'admin_logged_in' => true,
        'admin_org_id'    => $orgId,
        'admin_role'      => $super ? 'super_admin' : 'org_admin',
    ];
}

/**
 * Runs a query whose WHERE clause uses the {scope} placeholder, filled in by
 * tenant_scope_clause() (the same helper the admin controllers use).
 * Scope params are bound first, then any $extra params.
 */
function scoped_column(PDO $pdo, string $sql, string $column, string $scopeColumn, array $extra = []): array
{
    $params = [];
    $scope = tenant_scope_clause($scopeColumn, $params);
    $sql = str_replace('{scope}', $scope !== '' ? $scope : '1 = 1', $sql);
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, $extra));
    $values = array_column($stmt->fetchAll(), $column);
    sort($values);
    return $values;
}

// ---- In-memory schema (only the columns the checks need) ----
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("CREATE TABLE admin_users (id INTEGER PRIMARY KEY, username TEXT, organization_id INTEGER)");
$pdo->exec("CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, admin_username TEXT, action_type TEXT, details TEXT, organization_id INTEGER)");
$pdo->exec("CREATE TABLE events (id INTEGER PRIMARY KEY, organization_id INTEGER, name TEXT)");
$pdo->exec("CREATE TABLE event_participants (id INTEGER PRIMARY KEY, event_id INTEGER, certificate_id TEXT)");
$pdo->exec("CREATE TABLE email_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, certificate_id TEXT, recipient_email TEXT)");

// ---- Fixtures: tenant 1 (A) and tenant 2 (B) ----
$pdo->exec("INSERT INTO admin_users (id, username, organization_id) VALUES (1, 'a_admin', 1), (2, 'b_admin', 2)");
$pdo->exec("INSERT INTO audit_logs (admin_username, action_type, details, organization_id) VALUES ('a_admin', 'TEST', 'A entry', 1), ('b_admin', 'TEST', 'B entry', 2)");
$pdo->exec("INSERT INTO events (id, organization_id, name) VALUES (1, 1, 'Event A'), (2, 2, 'Event B')");
$pdo->exec("INSERT INTO event_participants (id, event_id, certificate_id) VALUES (1, 1, 'A-CERT'), (2, 2, 'B-CERT')");
$pdo->exec("INSERT INTO email_logs (certificate_id, recipient_email) VALUES ('A-CERT', 'a@example.com'), ('B-CERT', 'b@example.com')");

$emailBase = "SELECT el.recipient_email FROM email_logs el
    LEFT JOIN event_participants ep ON el.certificate_id = ep.certificate_id
    LEFT JOIN events e ON ep.event_id = e.id
    WHERE {scope}";

// ---- Org admin A (tenant 1) ----
set_session(1);

check('org_admin A sees only tenant A audit logs',
    scoped_column($pdo, "SELECT details FROM audit_logs WHERE {scope}", 'details', 'organization_id') === ['A entry']);

check('org_admin A user list does not include tenant B admin',
    scoped_column($pdo, "SELECT username FROM admin_users WHERE {scope}", 'username', 'organization_id') === ['a_admin']);

check('org_admin A is blocked from tenant B admin user', admin_user_in_tenant($pdo, 2) === false);
check('org_admin A can access own tenant admin user', admin_user_in_tenant($pdo, 1) === true);

check('org_admin A sees only tenant A email logs',
    scoped_column($pdo, $emailBase, 'recipient_email', 'e.organization_id') === ['a@example.com']);

check('org_admin A gets no email logs when filtering by tenant B event_id',
    scoped_column($pdo, $emailBase . " AND ep.event_id = ?", 'recipient_email', 'e.organization_id', [2]) === []);

check('org_admin A cannot open tenant B event', verify_event_access($pdo, 2) === null);
check('org_admin A can open own tenant event', verify_event_access($pdo, 1) !== null);

// ---- Org admin B (tenant 2) ----
set_session(2);
check('org_admin B can access own tenant admin user', admin_user_in_tenant($pdo, 2) === true);
check('org_admin B is blocked from tenant A admin user', admin_user_in_tenant($pdo, 1) === false);

// ---- Org admin without a tenant in session: never unrestricted ----
// get_current_tenant_id() falls back to the default tenant, so the scope must
// still be a real filter with a bound value.
set_session(null);
$params = [];
$scope = tenant_scope_clause('organization_id', $params);
check('org_admin without tenant in session is still scoped', $scope !== '' && count($params) === 1);

// ---- Logged-out session is never a super admin ----
$_SESSION = ['admin_role' => 'super_admin'];
check('non-logged-in session is not super_admin', is_super_admin() === false);

// ---- Super admin: unrestricted ----
set_session(null, true);
$params = [];
check('super_admin has no scope restriction', tenant_scope_clause('organization_id', $params) === '' && $params === []);
check('super_admin sees audit logs of every tenant',
    scoped_column($pdo, "SELECT details FROM audit_logs WHERE {scope}", 'details', 'organization_id') === ['A entry', 'B entry']);
check('super_admin can access any tenant admin user', admin_user_in_tenant($pdo, 2) === true);
check('super_admin can open any tenant event', verify_event_access($pdo, 2) !== null);

echo $failures === 0 ? "\nAll tests passed.\n" : "\n$failures test(s) failed.\n";
exit($failures > 0 ? 1 : 0);