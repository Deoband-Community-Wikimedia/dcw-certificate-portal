<?php
/**
 * Tenant isolation / IDOR test (issue #149)
 *
 * Usage (from project root):  php tests/tenant_isolation_test.php
 * Run against a LOCAL / TEST database only, never production.
 *
 * Creates temporary rows (audit_logs + one admin_users row) and removes
 * them at the end, even if a check fails.
 *
 * Session keys used by helpers.php:
 *   $_SESSION['admin_logged_in'], $_SESSION['admin_org_id'], $_SESSION['admin_role']
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../config.php';

$failures = 0;

function check(string $name, bool $ok): void {
    global $failures;
    echo ($ok ? "PASS" : "FAIL") . " - $name\n";
    if (!$ok) {
        $failures++;
    }
}

function set_session(string $username, ?int $orgId, bool $super = false): void {
    $_SESSION = [
        'admin_logged_in' => true,
        'admin_username'  => $username,
        'admin_org_id'    => $orgId,
        'admin_role'      => $super ? 'super_admin' : 'org_admin',
    ];
}

$tag = 'tenant_test_' . bin2hex(random_bytes(4));
$tmpAdminId = null;
$tmpOrgIds = [];

// audit_logs / admin_users have a foreign key to organizations(id), so the test
// creates two throwaway organizations and removes them at the end.
function create_temp_org(PDO $pdo, string $tag, string $suffix): int {
    $code = strtoupper(substr(md5($tag . $suffix), 0, 10));
    $stmt = $pdo->prepare("INSERT INTO organizations (name, slug, cert_code) VALUES (?, ?, ?)");
    $stmt->execute(["Test Org $suffix", strtolower($tag . '_' . $suffix), $code]);
    return (int)$pdo->lastInsertId();
}

try {
    // ---- Fixtures ----
    $orgA = create_temp_org($pdo, $tag, 'a');
    $tmpOrgIds[] = $orgA;
    $orgB = create_temp_org($pdo, $tag, 'b');
    $tmpOrgIds[] = $orgB;

    set_session($tag . '_a', $orgA);
    log_audit_action($pdo, 'TEST', $tag . ' A');
    set_session($tag . '_b', $orgB);
    log_audit_action($pdo, 'TEST', $tag . ' B');

    $ins = $pdo->prepare("INSERT INTO admin_users (username, password_hash, email, organization_id) VALUES (?, ?, NULL, ?)");
    $ins->execute([$tag . '_victim', password_hash('x', PASSWORD_DEFAULT), $orgB]);
    $tmpAdminId = (int)$pdo->lastInsertId();

    // 1. Writes are tagged with the acting admin's tenant
    $stmt = $pdo->prepare("SELECT organization_id FROM audit_logs WHERE details = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$tag . ' A']);
    $row = $stmt->fetch();
    check('audit log write is tagged with tenant A', $row && (int)$row['organization_id'] === $orgA);

    // 2. Org admin A only sees tenant A's audit logs
    set_session($tag . '_a', $orgA);
    $params = [];
    $scope = tenant_scope_clause('organization_id', $params);
    $stmt = $pdo->prepare("SELECT DISTINCT organization_id FROM audit_logs WHERE $scope AND details LIKE ?");
    $params[] = $tag . '%';
    $stmt->execute($params);
    $seen = array_map('intval', array_column($stmt->fetchAll(), 'organization_id'));
    check('org_admin A sees only tenant A audit logs', $seen === [$orgA]);

    // 3. Cross-tenant IDOR: A cannot reach tenant B's admin user
    check('org_admin A is blocked from tenant B admin user', admin_user_in_tenant($pdo, $tmpAdminId) === false);

    // 3b. Positive control: B can reach their own admin user
    set_session($tag . '_b', $orgB);
    check('org_admin B can access own tenant admin user', admin_user_in_tenant($pdo, $tmpAdminId) === true);

    // 3c. Tenant B's users list (scoped query) does not leak into A's
    set_session($tag . '_a', $orgA);
    $params = [];
    $scope = tenant_scope_clause('organization_id', $params);
    $stmt = $pdo->prepare("SELECT id FROM admin_users WHERE $scope");
    $stmt->execute($params);
    $visibleIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));
    check('org_admin A user list does not include tenant B admin', !in_array($tmpAdminId, $visibleIds, true));

    // 4. Org admin is never unrestricted, even with no tenant in session.
    //    get_current_tenant_id() falls back to the default tenant (1), so the scope
    //    must still be a real filter with a bound value.
    set_session($tag . '_orphan', null);
    $params = [];
    $scope = tenant_scope_clause('organization_id', $params);
    check('org_admin without tenant in session is still scoped (never unrestricted)', $scope !== '' && count($params) === 1);

    // 5. Logged-out session is not treated as super admin
    $_SESSION = ['admin_role' => 'super_admin'];
    check('non-logged-in session is not super_admin', is_super_admin() === false);

    // 6. Super admin is unrestricted and can access any user
    set_session($tag . '_root', null, true);
    $params = [];
    check('super_admin has no scope restriction', tenant_scope_clause('organization_id', $params) === '' && $params === []);
    check('super_admin can access any tenant admin user', admin_user_in_tenant($pdo, $tmpAdminId) === true);
} catch (Throwable $e) {
    $failures++;
    echo "ERROR - " . $e->getMessage() . "\n";
} finally {
    // ---- Cleanup (always runs) ----
    try {
        $pdo->prepare("DELETE FROM audit_logs WHERE action_type = 'TEST' AND details LIKE ?")->execute([$tag . '%']);
        if ($tmpAdminId) {
            $pdo->prepare("DELETE FROM admin_users WHERE id = ?")->execute([$tmpAdminId]);
        }
        foreach ($tmpOrgIds as $oid) {
            $pdo->prepare("DELETE FROM organizations WHERE id = ?")->execute([$oid]);
        }
    } catch (Throwable $e) {
        echo "WARNING - cleanup failed: " . $e->getMessage() . "\n";
    }
}

echo $failures === 0 ? "\nAll tests passed.\n" : "\n$failures test(s) failed.\n";
exit($failures > 0 ? 1 : 0);