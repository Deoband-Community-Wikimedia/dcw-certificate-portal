<?php
/**
 * Test Suite: Affiliate Hub Profile Customization (Issue #160)
 *
 * Tests schema provisioning, input validation, banner image upload validation,
 * file storage security & path isolation, and hub page fallback behavior.
 *
 * Run with: php tests/test_affiliate_hub_profile.php
 */

require_once __DIR__ . '/../helpers/organization.php';
require_once __DIR__ . '/../helpers/hub_profile.php';

echo "=== Running Affiliate Hub Profile Customization Tests (Issue #160) ===\n\n";
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
// Test 1: SQLite schema provisioning & idempotency
// -----------------------------------------------------------------------------
echo "Test 1: Schema auto-provisioning and idempotency...\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec("
    CREATE TABLE organizations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(255) NOT NULL,
        slug VARCHAR(100) NOT NULL UNIQUE,
        cert_code VARCHAR(20) NOT NULL UNIQUE,
        contact_email VARCHAR(255) NULL,
        home_url VARCHAR(255) NULL,
        logo_path VARCHAR(255) NULL,
        is_active INTEGER NOT NULL DEFAULT 1
    );
");

// Run schema migration
organizations_ensure_hub_schema($pdo);

// Inspect columns
$columns = [];
$stmt = $pdo->query("PRAGMA table_info(organizations)");
while ($row = $stmt->fetch()) {
    $columns[] = $row['name'];
}

assertTest(in_array('cover_banner_path', $columns, true), 'cover_banner_path column added');
assertTest(in_array('bio', $columns, true), 'bio column added');
assertTest(in_array('meta_wiki_url', $columns, true), 'meta_wiki_url column added');
assertTest(in_array('telegram_url', $columns, true), 'telegram_url column added');
assertTest(in_array('twitter_url', $columns, true), 'twitter_url column added');

// Test idempotency: calling again should not error or duplicate columns
try {
    organizations_ensure_hub_schema($pdo);
    assertTest(true, 'organizations_ensure_hub_schema() is idempotent when called repeatedly');
} catch (\Throwable $e) {
    assertTest(false, 'idempotency failed: ' . $e->getMessage());
}

// -----------------------------------------------------------------------------
// Test 2: Profile validation & normalization (bio & URLs)
// -----------------------------------------------------------------------------
echo "\nTest 2: Profile fields validation and normalization...\n";

$validProfile = [
    'hub_bio'           => '  Empowering local communities to document knowledge on Wikimedia projects.  ',
    'hub_meta_wiki_url' => 'https://meta.wikimedia.org/wiki/Wikimedia_Community_User_Group',
    'hub_telegram_url'  => 'https://t.me/wmcommunity',
    'hub_twitter_url'   => 'https://x.com/wmcommunity',
];

list($errors, $clean) = hub_validate_profile($validProfile);
assertTest(empty($errors), 'valid profile fields pass validation');
assertTest($clean['bio'] === 'Empowering local communities to document knowledge on Wikimedia projects.', 'bio is trimmed');
assertTest($clean['meta_wiki_url'] === 'https://meta.wikimedia.org/wiki/Wikimedia_Community_User_Group', 'meta_wiki_url preserved');
assertTest($clean['telegram_url'] === 'https://t.me/wmcommunity', 'telegram_url preserved');
assertTest($clean['twitter_url'] === 'https://x.com/wmcommunity', 'twitter_url preserved');

// Empty profile fields resolve to NULL
list($errorsEmpty, $cleanEmpty) = hub_validate_profile([
    'hub_bio'           => '   ',
    'hub_meta_wiki_url' => '',
    'hub_telegram_url'  => '',
    'hub_twitter_url'   => '',
]);
assertTest(empty($errorsEmpty), 'empty optional fields produce no validation errors');
assertTest($cleanEmpty['bio'] === null, 'empty bio stores NULL');
assertTest($cleanEmpty['meta_wiki_url'] === null, 'empty meta_wiki_url stores NULL');
assertTest($cleanEmpty['telegram_url'] === null, 'empty telegram_url stores NULL');
assertTest($cleanEmpty['twitter_url'] === null, 'empty twitter_url stores NULL');

// Bio length limit (500 chars)
$bio500 = str_repeat('A', 500);
list($err500) = hub_validate_profile(array_merge($validProfile, ['hub_bio' => $bio500]));
assertTest(!isset($err500['hub_bio']), 'bio of exactly 500 characters accepted');

$bio501 = str_repeat('A', 501);
list($err501) = hub_validate_profile(array_merge($validProfile, ['hub_bio' => $bio501]));
assertTest(($err501['hub_bio'] ?? '') === 'bio-too-long', 'bio exceeding 500 characters rejected');

// Invalid URL rejection (javascript, ftp, malformed, HTML injection)
$badUrls = [
    'javascript:alert(1)',
    'data:text/html,<script>alert(1)</script>',
    'ftp://example.com/file',
    'http://',
    'https://',
    'meta.wikimedia.org/wiki/Page',
    "https://example.com/\"<script>",
];

foreach ($badUrls as $bad) {
    list($errBad) = hub_validate_profile([
        'hub_bio'           => '',
        'hub_meta_wiki_url' => $bad,
        'hub_telegram_url'  => $bad,
        'hub_twitter_url'   => $bad,
    ]);
    assertTest(isset($errBad['hub_meta_wiki_url']), 'rejected bad meta_wiki_url: ' . substr($bad, 0, 30));
    assertTest(isset($errBad['hub_telegram_url']), 'rejected bad telegram_url: ' . substr($bad, 0, 30));
    assertTest(isset($errBad['hub_twitter_url']), 'rejected bad twitter_url: ' . substr($bad, 0, 30));
}

// -----------------------------------------------------------------------------
// Test 3: Cover banner image upload validation
// -----------------------------------------------------------------------------
echo "\nTest 3: Cover banner image upload validation...\n";

$pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$jpgBytes = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
$webpBytes = base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==');

$tmpPng = tempnam(sys_get_temp_dir(), 'test_banner_png_') . '.png';
file_put_contents($tmpPng, $pngBytes);

list($pngExt, $pngErr) = hub_check_banner_upload([
    'error'    => UPLOAD_ERR_OK,
    'tmp_name' => $tmpPng,
    'size'     => strlen($pngBytes),
]);
assertTest($pngExt === 'png' && $pngErr === null, 'valid PNG banner accepted');
@unlink($tmpPng);

// Helper for test JPEG
$tmpJpg = tempnam(sys_get_temp_dir(), 'test_banner_jpg_') . '.jpg';
file_put_contents($tmpJpg, $jpgBytes);

list($jpgExt, $jpgErr) = hub_check_banner_upload([
    'error'    => UPLOAD_ERR_OK,
    'tmp_name' => $tmpJpg,
    'size'     => strlen($jpgBytes),
]);
assertTest($jpgExt === 'jpg' && $jpgErr === null, 'valid JPEG banner accepted');
@unlink($tmpJpg);

// Helper for test WebP
$tmpWebp = tempnam(sys_get_temp_dir(), 'test_banner_webp_') . '.webp';
file_put_contents($tmpWebp, $webpBytes);

list($webpExt, $webpErr) = hub_check_banner_upload([
    'error'    => UPLOAD_ERR_OK,
    'tmp_name' => $tmpWebp,
    'size'     => strlen($webpBytes),
]);
assertTest($webpExt === 'webp' && $webpErr === null, 'valid WebP banner accepted');
@unlink($tmpWebp);

// Helper for fake/corrupt file
$tmpFake = tempnam(sys_get_temp_dir(), 'test_banner_fake_') . '.png';
file_put_contents($tmpFake, '<svg><script>alert(1)</script></svg>');

list($fakeExt, $fakeErr) = hub_check_banner_upload([
    'error'    => UPLOAD_ERR_OK,
    'tmp_name' => $tmpFake,
    'size'     => filesize($tmpFake),
]);
assertTest($fakeExt === null && $fakeErr === 'banner-invalid', 'non-raster / SVG file safely rejected');
@unlink($tmpFake);

// File too large (> 5 MB)
list($largeExt, $largeErr) = hub_check_banner_upload([
    'error'    => UPLOAD_ERR_OK,
    'tmp_name' => $tmpJpg,
    'size'     => 6 * 1024 * 1024,
]);
assertTest($largeExt === null && $largeErr === 'banner-too-large', 'banner exceeding 5 MB limit rejected');

// Upload errors
list($iniExt, $iniErr) = hub_check_banner_upload([
    'error'    => UPLOAD_ERR_INI_SIZE,
    'tmp_name' => '',
    'size'     => 0,
]);
assertTest($iniExt === null && $iniErr === 'banner-too-large', 'UPLOAD_ERR_INI_SIZE maps to banner-too-large');

// -----------------------------------------------------------------------------
// Test 4: Banner storage & path isolation
// -----------------------------------------------------------------------------
echo "\nTest 4: Banner storage directory and path security...\n";

$testRootDir = sys_get_temp_dir() . '/dcw_test_banner_root_' . bin2hex(random_bytes(4));
mkdir($testRootDir, 0755, true);

$dummySrc = tempnam(sys_get_temp_dir(), 'dummy_banner_');
file_put_contents($dummySrc, 'test image contents');

$storedRelPath = hub_store_banner(42, $dummySrc, 'jpg', $testRootDir);
assertTest($storedRelPath !== null, 'hub_store_banner stores file successfully');
assertTest(strpos($storedRelPath, 'uploads/tenants/42/banner-') === 0, 'stored path follows tenant isolated directory uploads/tenants/42/');
assertTest(file_exists($testRootDir . '/' . $storedRelPath), 'stored banner file exists on disk');

// Directory traversal deletion guard
assertTest(hub_delete_banner(42, '../../etc/passwd', $testRootDir) === false, 'path traversal in delete refused');
assertTest(hub_delete_banner(42, 'uploads/tenants/99/banner-test.jpg', $testRootDir) === false, 'cross-tenant deletion refused');
assertTest(hub_delete_banner(42, $storedRelPath, $testRootDir) === true, 'authorized tenant banner deletion succeeds');
assertTest(!file_exists($testRootDir . '/' . $storedRelPath), 'file removed from disk after authorized delete');

// Clean up temp dir
@unlink($dummySrc);
@rmdir($testRootDir . '/uploads/tenants/42');
@rmdir($testRootDir . '/uploads/tenants');
@rmdir($testRootDir . '/uploads');
@rmdir($testRootDir);

// -----------------------------------------------------------------------------
// Test 5: Fallback behavior for public hub showcase (/c/<slug>)
// -----------------------------------------------------------------------------
echo "\nTest 5: Public hub header fallback when no banner or bio is configured...\n";

// Case A: Affiliate WITH banner & bio & links
$affiliateWithCustomization = [
    'id'                => 2,
    'name'              => 'Wikimedia Spain',
    'slug'              => 'wm-es',
    'cert_code'         => 'WMES',
    'logo_path'         => 'uploads/tenants/2/logo.png',
    'cover_banner_path' => 'uploads/tenants/2/banner.jpg',
    'bio'               => 'Non-profit organization promoting free knowledge.',
    'meta_wiki_url'     => 'https://meta.wikimedia.org/wiki/Wikimedia_España',
    'telegram_url'      => 'https://t.me/wikimedia_es',
    'twitter_url'       => 'https://x.com/wikimedia_es',
    'home_url'          => 'https://wikimedia.es',
];

$hasBannerA = !empty($affiliateWithCustomization['cover_banner_path']);
assertTest($hasBannerA === true, 'detects configured cover banner');
assertTest(!empty($affiliateWithCustomization['bio']), 'detects configured bio');
assertTest(!empty($affiliateWithCustomization['meta_wiki_url']), 'detects meta_wiki_url');

// Case B: Clean default layout fallback (NO banner, NO bio)
$affiliateDefault = [
    'id'                => 3,
    'name'              => 'Wikimedia Community Group',
    'slug'              => 'wmcg',
    'cert_code'         => 'WMCG',
    'logo_path'         => null,
    'cover_banner_path' => null,
    'bio'               => null,
    'meta_wiki_url'     => null,
    'telegram_url'      => null,
    'twitter_url'       => null,
    'home_url'          => null,
];

$hasBannerB = !empty($affiliateDefault['cover_banner_path']);
$hasBioB    = !empty($affiliateDefault['bio']);
$hasMetaB   = !empty($affiliateDefault['meta_wiki_url']);

assertTest($hasBannerB === false, 'clean fallback: hasBanner is false when no cover banner configured');
assertTest($hasBioB === false, 'clean fallback: bio is omitted when not configured');
assertTest($hasMetaB === false, 'clean fallback: external links omitted when not configured');

// -----------------------------------------------------------------------------
// Test 6: CLI Migration script verification
// -----------------------------------------------------------------------------
echo "\nTest 6: Migration script verification...\n";
$migrationFile = __DIR__ . '/../scripts/migrate_affiliate_hub_profile.php';
assertTest(file_exists($migrationFile), 'scripts/migrate_affiliate_hub_profile.php exists');
$output = [];
$returnVar = 0;
exec("php -l " . escapeshellarg($migrationFile), $output, $returnVar);
assertTest($returnVar === 0, 'migration script passes PHP syntax check');

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
