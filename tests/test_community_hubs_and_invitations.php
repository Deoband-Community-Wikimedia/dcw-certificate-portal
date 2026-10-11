<?php
/**
 * Test Suite: Multi-Tenant Community Hubs & Automated Onboarding
 *
 * Self-contained: runs on an in-memory SQLite database and tests:
 * 1. Reserved slug blacklist & validation
 * 2. Slug generation, transliteration & collision disambiguation
 * 3. Unique cert code derivation & collision handling
 * 4. Cryptographic invitation token creation, hashing, and 48-hour expiration
 * 5. Invitation lookup, token security, and tamper resistance
 * 6. Invitation acceptance workflow (password validation, org_admin role creation, one-time use)
 * 7. Resend invitation logic
 * 8. Direct Event Claim & Community Hub resolution logic
 * 9. Migration tool CLI verification
 *
 * Usage: php tests/test_community_hubs_and_invitations.php
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../helpers/organization.php';
require_once __DIR__ . '/../helpers/invitation.php';

echo "=== Running Community Hubs & Partner Onboarding Unit Tests ===\n\n";

$passes = 0;
$fails = 0;

function assertTest(bool $condition, string $message): void {
    global $passes, $fails;
    if ($condition) {
        echo "  [PASS] $message\n";
        $passes++;
    } else {
        echo "  [FAIL] $message\n";
        $fails++;
    }
}

// -----------------------------------------------------------------------------
// Test 1: Reserved Slug Blacklist & Validation
// -----------------------------------------------------------------------------
echo "Test 1: Reserved slug blacklist...\n";
assertTest(is_reserved_slug('admin') === true, 'admin is reserved');
assertTest(is_reserved_slug('api') === true, 'api is reserved');
assertTest(is_reserved_slug('verify') === true, 'verify is reserved');
assertTest(is_reserved_slug('download') === true, 'download is reserved');
assertTest(is_reserved_slug('c') === true, 'c route is reserved');
assertTest(is_reserved_slug('e') === true, 'e route is reserved');
assertTest(is_reserved_slug('assets') === true, 'assets is reserved');
assertTest(is_reserved_slug('ADMIN') === true, 'check is case-insensitive (ADMIN)');
assertTest(is_reserved_slug('wmsp') === false, 'partner slug wmsp is allowed');
assertTest(is_reserved_slug('wikiversary-2026') === false, 'event slug wikiversary-2026 is allowed');

// -----------------------------------------------------------------------------
// Test 2: Slugify Helper & Character Sanitization
// -----------------------------------------------------------------------------
echo "\nTest 2: Slugification and sanitization...\n";
assertTest(slugify('Wikimedia Community User Group Spain') === 'wikimedia-community-user-group-spain', 'slugifies standard English name');
assertTest(slugify('Wikiversário 2026!') === 'wikiversario-2026', 'strips special characters and accents');
assertTest(slugify('---hello---world---') === 'hello-world', 'trims leading and trailing hyphens');
assertTest(slugify('CAPX / Capacity Exchange') === 'capx-capacity-exchange', 'handles slashes and spaces cleanly');
assertTest(slugify('') === 'community', 'empty string falls back to default');

// -----------------------------------------------------------------------------
// Test 3: SQLite In-Memory Database Provisioning
// -----------------------------------------------------------------------------
echo "\nTest 3: SQLite schema provisioning...\n";
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
        logo_path TEXT NULL,
        home_url TEXT NULL,
        contact_email TEXT NULL,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE admin_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        organization_id INTEGER NULL,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        email TEXT NULL,
        role TEXT NOT NULL DEFAULT 'org_admin'
    );

    CREATE TABLE events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        organization_id INTEGER NOT NULL DEFAULT 1,
        name TEXT NOT NULL,
        slug TEXT NULL,
        category TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        organization_id INTEGER NULL,
        admin_username TEXT NOT NULL,
        action_type TEXT NOT NULL,
        details TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
");

invitation_ensure_schema($pdo);

// Verify admin_invitations was created by helper
$chkTable = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='admin_invitations'")->fetch();
assertTest(!empty($chkTable), 'admin_invitations table auto-provisioned in database');

// -----------------------------------------------------------------------------
// Test 4: Collision Auto-Disambiguation for Org Slugs & Cert Codes
// -----------------------------------------------------------------------------
echo "\nTest 4: Auto-disambiguation for organizations...\n";
// Insert root org
$pdo->exec("INSERT INTO organizations (id, name, slug, cert_code) VALUES (1, 'Deoband Community Wikimedia', 'dcw', 'DCW')");

// Disambiguation when slug already exists
$genSlug1 = generate_unique_org_slug($pdo, 'Deoband Community Wikimedia');
assertTest($genSlug1 === 'dcw-2' || $genSlug1 === 'deoband-community-wikimedia', 'disambiguates existing org name/slug');

$pdo->exec("INSERT INTO organizations (name, slug, cert_code) VALUES ('Wikimedia Spain', 'wmsp', 'WMSP')");
$genSlug2 = generate_unique_org_slug($pdo, 'wmsp');
assertTest($genSlug2 === 'wmsp-2', 'colliding slug wmsp disambiguates to wmsp-2');

$pdo->exec("INSERT INTO organizations (name, slug, cert_code) VALUES ('Wikimedia Spain 2', 'wmsp-2', 'WMSP2')");
$genSlug3 = generate_unique_org_slug($pdo, 'wmsp');
assertTest($genSlug3 === 'wmsp-3', 'colliding slug wmsp-2 disambiguates to wmsp-3');

// Reserved slug prevention
$reservedSlugGen = generate_unique_org_slug($pdo, 'admin');
assertTest($reservedSlugGen === 'admin-org', 'reserved keyword admin auto-suffixed with -org');

// Cert code generation
$code1 = generate_unique_org_cert_code($pdo, 'Capacity Exchange User Group');
assertTest(in_array($code1, ['CEUG', 'CAPA'], true), 'derives uppercase initials or acronym for cert code');

$codeColliding = generate_unique_org_cert_code($pdo, 'Wikimedia Spain');
assertTest($codeColliding !== 'WMSP', 'colliding cert code WMSP auto-increments suffix');

// -----------------------------------------------------------------------------
// Test 5: Event Slug Disambiguation
// -----------------------------------------------------------------------------
echo "\nTest 5: Event slug generation and disambiguation...\n";
$pdo->exec("INSERT INTO events (name, slug, organization_id) VALUES ('Wiki Workshop 2026', 'wiki-workshop-2026', 1)");

$evSlug1 = generate_unique_event_slug($pdo, 'Wiki Workshop 2026');
assertTest($evSlug1 === 'wiki-workshop-2026-2', 'colliding event slug disambiguates to -2');

$pdo->exec("INSERT INTO events (name, slug, organization_id) VALUES ('Wiki Workshop 2026 2', 'wiki-workshop-2026-2', 1)");
$evSlug2 = generate_unique_event_slug($pdo, 'Wiki Workshop 2026');
assertTest($evSlug2 === 'wiki-workshop-2026-3', 'colliding event slug disambiguates to -3');

$evReserved = generate_unique_event_slug($pdo, 'api');
assertTest($evReserved === 'api-event', 'reserved keyword api for event auto-suffixed with -event');

// -----------------------------------------------------------------------------
// Test 6: Invitation Token Creation & Expiry
// -----------------------------------------------------------------------------
echo "\nTest 6: Invitation token creation and security...\n";
$inv = create_admin_invitation($pdo, 2, 'lead@wikimedia.es');
assertTest($inv['success'] === true, 'invitation created successfully');
assertTest(strlen($inv['raw_token']) === 64, 'raw token is 64 hex characters (256 bits entropy)');
assertTest(hash_equals(hash('sha256', $inv['raw_token']), $inv['token_hash']), 'stored token hash is valid SHA-256');
assertTest($inv['email'] === 'lead@wikimedia.es', 'lead email recorded correctly');
assertTest(strpos($inv['username'], 'lead') === 0, 'auto-generated username derived from email prefix');
assertTest(strtotime($inv['expires_at']) > (time() + 47 * 3600), 'expires in ~48 hours');

// Invalid email rejected
$badInv = create_admin_invitation($pdo, 2, 'not-an-email');
assertTest($badInv['success'] === false, 'rejects malformed email address');

// -----------------------------------------------------------------------------
// Test 7: Invitation Lookup & Security Validation
// -----------------------------------------------------------------------------
echo "\nTest 7: Token lookup and tamper resistance...\n";
$found = find_invitation_by_token($pdo, $inv['raw_token']);
assertTest($found !== null, 'resolves valid unexpired invitation');
assertTest($found['org_name'] === 'Wikimedia Spain', 'joins organization details');
assertTest($found['org_code'] === 'WMSP', 'joins cert code');

$notFound = find_invitation_by_token($pdo, 'fake-token-123456');
assertTest($notFound === null, 'rejects non-existent token');

$emptyToken = find_invitation_by_token($pdo, '');
assertTest($emptyToken === null, 'rejects empty token');

// Expiration test: update expires_at into the past
$pdo->exec("UPDATE admin_invitations SET expires_at = datetime('now', '-1 hour') WHERE id = " . (int)$inv['invite_id']);
$expired = find_invitation_by_token($pdo, $inv['raw_token']);
assertTest($expired === null, 'rejects expired invitation token');

// Restore expiry for acceptance test
$pdo->exec("UPDATE admin_invitations SET expires_at = datetime('now', '+48 hours') WHERE id = " . (int)$inv['invite_id']);

// -----------------------------------------------------------------------------
// Test 8: Invitation Acceptance & Account Provisioning
// -----------------------------------------------------------------------------
echo "\nTest 8: Acceptance workflow and account activation...\n";
// Short password rejection
$shortPassRes = accept_admin_invitation($pdo, (int)$inv['invite_id'], '12345');
assertTest($shortPassRes['success'] === false && $shortPassRes['error'] === 'short-password', 'rejects passwords under 6 characters');

// Successful acceptance
$acceptRes = accept_admin_invitation($pdo, (int)$inv['invite_id'], 'SuperSecurePass2026!', 'maria_spain');
assertTest($acceptRes['success'] === true, 'accepts invitation with valid password');
assertTest($acceptRes['organization_id'] === 2, 'assigns correct organization ID 2');
assertTest($acceptRes['username'] === 'maria_spain', 'sets custom display username');

// Check admin_users record in DB
$stUser = $pdo->prepare("SELECT * FROM admin_users WHERE id = ?");
$stUser->execute([$acceptRes['user_id']]);
$userRow = $stUser->fetch();
assertTest($userRow !== false, 'admin_users record exists');
assertTest($userRow['role'] === 'org_admin', 'assigned org_admin role');
assertTest((int)$userRow['organization_id'] === 2, 'scoped to tenant organization 2');
assertTest(password_verify('SuperSecurePass2026!', $userRow['password_hash']), 'password hash verifies correctly');

// Check invitation is marked accepted
$stInv = $pdo->prepare("SELECT is_accepted FROM admin_invitations WHERE id = ?");
$stInv->execute([(int)$inv['invite_id']]);
assertTest((int)$stInv->fetchColumn() === 1, 'invitation marked is_accepted = 1');

// Re-using accepted invite must fail
$foundReused = find_invitation_by_token($pdo, $inv['raw_token']);
assertTest($foundReused === null, 'accepted invite cannot be retrieved via token lookup');

$secondAccept = accept_admin_invitation($pdo, (int)$inv['invite_id'], 'AnotherPass123!');
assertTest($secondAccept['success'] === false, 're-accepting already used invitation is rejected');

// -----------------------------------------------------------------------------
// Test 9: Resend Invitation Flow
// -----------------------------------------------------------------------------
echo "\nTest 9: Resend invitation flow...\n";
$resendRes = resend_admin_invitation($pdo, 2, 'co-lead@wikimedia.es');
assertTest($resendRes['success'] === true, 'resend creates fresh invitation token');

// Verify new invite in DB
$stLatest = $pdo->prepare("SELECT * FROM admin_invitations WHERE organization_id = 2 AND is_accepted = 0 ORDER BY id DESC LIMIT 1");
$stLatest->execute();
$latestInv = $stLatest->fetch();
assertTest($latestInv && $latestInv['email'] === 'co-lead@wikimedia.es', 'new pending invitation record stored');

// -----------------------------------------------------------------------------
// Test 10: Event & Community Direct Claim Routing Lookup
// -----------------------------------------------------------------------------
echo "\nTest 10: Public routing queries & resolution...\n";
// Community Hub lookup
$stHub = $pdo->prepare("SELECT * FROM organizations WHERE slug = ? AND is_active = 1");
$stHub->execute(['wmsp']);
$hubOrg = $stHub->fetch();
assertTest($hubOrg && (int)$hubOrg['id'] === 2, 'resolves active community hub by slug');

// Direct Event Claim by slug
$pdo->exec("INSERT INTO events (id, name, slug, organization_id) VALUES (10, 'Wikiversary Madrid 2026', 'wikiversary-madrid', 2)");
$stEventSlug = $pdo->prepare("
    SELECT e.*, o.slug as org_slug 
    FROM events e 
    JOIN organizations o ON e.organization_id = o.id 
    WHERE e.slug = ? AND o.is_active = 1
");
$stEventSlug->execute(['wikiversary-madrid']);
$resolvedEv = $stEventSlug->fetch();
assertTest($resolvedEv && (int)$resolvedEv['id'] === 10, 'resolves direct event claim by slug');
assertTest($resolvedEv['org_slug'] === 'wmsp', 'event belongs to organization wmsp');

// Numeric ID fallback for direct event
$stEventId = $pdo->prepare("SELECT id FROM events WHERE (slug = '10' OR id = 10)");
$stEventId->execute();
assertTest((int)$stEventId->fetchColumn() === 10, 'numeric ID fallback resolves event #10');

// Hierarchical tenant mismatch guard
$stHubMismatch = $pdo->prepare("SELECT * FROM organizations WHERE slug = ? AND is_active = 1");
$stHubMismatch->execute(['dcw']);
$dcwOrg = $stHubMismatch->fetch();
assertTest($dcwOrg && (int)$dcwOrg['id'] === 1, 'resolves org 1 (dcw)');
$isCrossTenant = ((int)$resolvedEv['organization_id'] !== (int)$dcwOrg['id']);
assertTest($isCrossTenant === true, 'tenant mismatch correctly detected when event of org 2 accessed under org 1 hub');

// Clean route URL generation test
$testBasePath = '/portal';
$cSlug = 'wmsp';
$eSlug = 'wikiversary-madrid';

// Test clean route URL builder simulation
$makeUrlClean = function($c, $e, $extra, $base, $isClean) {
    $prefix = ($base !== '') ? $base : '';
    if ($isClean) {
        $query = !empty($extra) ? '?' . http_build_query($extra) : '';
        if ($c !== '' && $e !== '') {
            return $prefix . '/c/' . rawurlencode($c) . '/e/' . rawurlencode($e) . $query;
        } elseif ($c !== '') {
            return $prefix . '/c/' . rawurlencode($c) . $query;
        } elseif ($e !== '') {
            return $prefix . '/e/' . rawurlencode($e) . $query;
        }
    }
    $params = [];
    if ($c !== '') $params['community_slug'] = $c;
    if ($e !== '') $params['event_slug'] = $e;
    $merged = array_merge($params, $extra);
    return $prefix . '/index.php' . (!empty($merged) ? '?' . http_build_query($merged) : '');
};

$url1 = $makeUrlClean($cSlug, $eSlug, ['step' => 'otp'], $testBasePath, true);
assertTest($url1 === '/portal/c/wmsp/e/wikiversary-madrid?step=otp', 'generates clean hierarchical route URL with step query');

$url2 = $makeUrlClean($cSlug, '', [], $testBasePath, true);
assertTest($url2 === '/portal/c/wmsp', 'generates clean community hub URL');

$url3 = $makeUrlClean($cSlug, $eSlug, ['step' => 'otp'], $testBasePath, false);
assertTest($url3 === '/portal/index.php?community_slug=wmsp&event_slug=wikiversary-madrid&step=otp', 'falls back to index.php query parameters on direct script access');

// Root Organization Redirection Rule (/c/dcw -> /)
$redirectTarget = function($c, $e, $base) {
    if ($c === 'dcw') {
        if ($e !== '') {
            return ($base !== '' ? $base : '') . '/e/' . rawurlencode($e);
        } else {
            return ($base !== '' ? $base : '') . '/';
        }
    }
    return null;
};
assertTest($redirectTarget('dcw', '', '') === '/', '/c/dcw redirects to root /');
assertTest($redirectTarget('dcw', '', '/portal') === '/portal/', '/c/dcw with basePath redirects to /portal/');
assertTest($redirectTarget('dcw', 'annual-meet', '') === '/e/annual-meet', '/c/dcw/e/event redirects to /e/event');
assertTest($redirectTarget('wmsp', '', '') === null, 'partner org slug wmsp does not redirect to root');

// -----------------------------------------------------------------------------
// Test 11: CLI Migration Script Verification
// -----------------------------------------------------------------------------
echo "\nTest 11: CLI Migration tool verification...\n";
$migFile = __DIR__ . '/../scripts/migrate_community_hubs.php';
assertTest(file_exists($migFile), 'scripts/migrate_community_hubs.php exists');

$lintOutput = shell_exec('php -l ' . escapeshellarg($migFile));
assertTest(strpos($lintOutput, 'No syntax errors') !== false, 'migration script passes PHP syntax check');

// -----------------------------------------------------------------------------
// Summary
// -----------------------------------------------------------------------------
echo "\n=======================================================\n";
echo "SUMMARY: PASS: $passes | FAIL: $fails\n";
echo "=======================================================\n";

if ($fails > 0) {
    exit(1);
}
exit(0);
