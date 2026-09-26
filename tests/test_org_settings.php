<?php
/**
 * Test Suite: Organization profile helpers (Issue #148)
 *
 * DB-free: exercises validation, social-link handling and logo file handling
 * in helpers/organization.php.
 *
 * Run with: php tests/test_org_settings.php
 */

require_once __DIR__ . '/../helpers/organization.php';

echo "=== Running Organization Settings Helper Tests ===\n\n";
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

$validInput = [
    'name' => '  Wikimedia São Paulo ',
    'slug' => ' WM-SP ',
    'cert_code' => ' wmsp ',
    'contact_email' => ' hello@example.org ',
    'social_linkedin' => 'https://www.linkedin.com/company/wmsp',
    'social_x' => '',
];

// -----------------------------------------------------------------------------
echo "Test 1: Valid profile is accepted and normalised...\n";
list($errors, $clean) = org_validate_profile($validInput);
assertTest($errors === [], 'no errors for a valid profile');
assertTest($clean['name'] === 'Wikimedia São Paulo', 'name is trimmed');
assertTest($clean['slug'] === 'wm-sp', 'slug is trimmed and lowercased');
assertTest($clean['cert_code'] === 'WMSP', 'cert code is trimmed and uppercased');
assertTest($clean['contact_email'] === 'hello@example.org', 'email is trimmed');
assertTest($clean['social_links'] === '{"linkedin":"https://www.linkedin.com/company/wmsp"}', 'only filled social links are stored, as JSON without escaped slashes');

// -----------------------------------------------------------------------------
echo "\nTest 2: Name, slug and code validation...\n";
list($e) = org_validate_profile(array_merge($validInput, ['name' => '   ']));
assertTest(($e['name'] ?? '') === 'name-invalid', 'blank name rejected');
list($e) = org_validate_profile(array_merge($validInput, ['name' => str_repeat('a', 256)]));
assertTest(($e['name'] ?? '') === 'name-invalid', '256-char name rejected');

foreach (['a', 'has space', 'under_score', '-lead', 'trail-', 'dou--ble', 'ünï', str_repeat('a', 51), ''] as $bad) {
    list($e) = org_validate_profile(array_merge($validInput, ['slug' => $bad]));
    assertTest(($e['slug'] ?? '') === 'slug-invalid', 'slug rejected: ' . var_export($bad, true));
}
foreach (['ab', 'a1', 'wiki-media-2', str_repeat('a', 50)] as $good) {
    list($e) = org_validate_profile(array_merge($validInput, ['slug' => $good]));
    assertTest(!isset($e['slug']), 'slug accepted: ' . $good);
}

foreach (['A', 'W-M', 'WM SP', 'WM_SP', str_repeat('A', 21), ''] as $bad) {
    list($e) = org_validate_profile(array_merge($validInput, ['cert_code' => $bad]));
    assertTest(($e['cert_code'] ?? '') === 'cert-code-invalid', 'cert code rejected: ' . var_export($bad, true));
}
list($e) = org_validate_profile(array_merge($validInput, ['cert_code' => str_repeat('A', 20)]));
assertTest(!isset($e['cert_code']), '20-char cert code accepted');

// -----------------------------------------------------------------------------
echo "\nTest 3: Contact email is optional but must be valid when given...\n";
list($e, $c) = org_validate_profile(array_merge($validInput, ['contact_email' => '']));
assertTest(!isset($e['contact_email']) && $c['contact_email'] === null, 'empty email allowed and stored as NULL');
list($e) = org_validate_profile(array_merge($validInput, ['contact_email' => 'not-an-email']));
assertTest(($e['contact_email'] ?? '') === 'contact-email-invalid', 'malformed email rejected');

// -----------------------------------------------------------------------------
echo "\nTest 4: Social links only accept http(s) URLs...\n";
foreach (['javascript:alert(1)', 'data:text/html,x', 'ftp://example.org/x', 'linkedin.com/company/x', 'https://', "https://a.org/\"><script>", str_repeat('x', 301)] as $bad) {
    list($e) = org_validate_profile(array_merge($validInput, ['social_github' => $bad]));
    assertTest(($e['social_github'] ?? '') === 'social-url-invalid', 'rejected: ' . substr($bad, 0, 40));
}
list($e, $c) = org_validate_profile(array_merge($validInput, ['social_github' => 'http://github.com/wmsp', 'social_youtube' => 'https://youtube.com/@wmsp']));
assertTest($e === [], 'http and https both accepted');
assertTest(array_keys(json_decode($c['social_links'], true)) === ['linkedin', 'youtube', 'github'], 'stored in platform display order');
list($e, $c) = org_validate_profile(array_merge($validInput, ['social_linkedin' => '', 'social_x' => '']));
assertTest($c['social_links'] === null, 'no links stores NULL rather than "[]"');
list($e, $c) = org_validate_profile(array_merge($validInput, ['social_myspace' => 'https://myspace.com/x']));
assertTest(strpos((string)$c['social_links'], 'myspace') === false, 'unknown platforms are ignored');

// -----------------------------------------------------------------------------
echo "\nTest 5: Decoding stored social links is tolerant...\n";
assertTest(org_decode_social_links(null) === [], 'NULL -> []');
assertTest(org_decode_social_links('') === [], 'empty -> []');
assertTest(org_decode_social_links('not json') === [], 'garbage -> []');
assertTest(org_decode_social_links('"a string"') === [], 'non-object JSON -> []');
assertTest(org_decode_social_links('{"x":"https://x.com/a","bogus":"https://b","github":123}') === ['x' => 'https://x.com/a'], 'unknown keys and non-string values dropped');

// -----------------------------------------------------------------------------
echo "\nTest 6: Logo upload validation reads file contents...\n";
$tmp = sys_get_temp_dir() . '/org_settings_test_' . bin2hex(random_bytes(4));
mkdir($tmp);
register_shutdown_function(function () use ($tmp) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($tmp);
});

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
$make = function (string $name, string $bytes) use ($tmp): array {
    $path = $tmp . '/' . $name;
    file_put_contents($path, $bytes);
    return ['name' => $name, 'tmp_name' => $path, 'size' => strlen($bytes), 'error' => UPLOAD_ERR_OK];
};

list($ext, $err) = org_check_logo_upload($make('logo.png', $png));
assertTest($ext === 'png' && $err === null, 'real PNG accepted');

// The extension a browser sends is irrelevant; the content decides.
list($ext, $err) = org_check_logo_upload($make('logo.jpg', $png));
assertTest($ext === 'png', 'PNG content named .jpg is still treated as PNG');
list($ext, $err) = org_check_logo_upload($make('evil.png', "<?php echo 'pwned'; ?>"));
assertTest($ext === null && $err === 'logo-invalid', 'PHP source renamed .png rejected');
list($ext, $err) = org_check_logo_upload($make('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'));
assertTest($ext === null && $err === 'logo-invalid', 'SVG rejected');
list($ext, $err) = org_check_logo_upload($make('logo.gif', $gif));
assertTest($ext === null && $err === 'logo-invalid', 'GIF rejected (not an allowed type)');

$big = $make('big.png', $png);
$big['size'] = ORG_LOGO_MAX_BYTES + 1;
list($ext, $err) = org_check_logo_upload($big);
assertTest($ext === null && $err === 'logo-too-large', 'file over the size limit rejected');
list($ext, $err) = org_check_logo_upload(['error' => UPLOAD_ERR_INI_SIZE]);
assertTest($err === 'logo-too-large', 'PHP upload_max_filesize error mapped to too-large');
list($ext, $err) = org_check_logo_upload(['error' => UPLOAD_ERR_PARTIAL]);
assertTest($err === 'logo-upload-failed', 'partial upload reported as failed');

// -----------------------------------------------------------------------------
echo "\nTest 7: Logos are stored per organization and only deleted from there...\n";
$root = $tmp . '/site';
mkdir($root . '/assets', 0755, true);
file_put_contents($root . '/assets/DCW_logo.png', $png);

$src = $make('upload.png', $png)['tmp_name'];
$stored = org_store_logo(7, $src, 'png', $root);
assertTest($stored !== null && preg_match('#^uploads/tenants/7/logo-[0-9a-f]{16}\.png$#', $stored) === 1, 'stored under uploads/tenants/<org id>/ with a random name');
assertTest(is_file($root . '/' . $stored), 'file exists on disk');

$src2 = $make('upload2.png', $png)['tmp_name'];
$stored2 = org_store_logo(7, $src2, 'png', $root);
assertTest($stored2 !== $stored, 'two uploads never share a filename');
$other = org_store_logo(8, $make('upload3.png', $png)['tmp_name'], 'png', $root);
assertTest(strpos($other, 'uploads/tenants/8/') === 0, 'another organization gets its own folder');

assertTest(org_delete_logo(7, 'assets/DCW_logo.png', $root) === false && is_file($root . '/assets/DCW_logo.png'), 'bundled default logo is never deleted');
assertTest(org_delete_logo(7, $other, $root) === false && is_file($root . '/' . $other), "cannot delete another organization's logo");
assertTest(org_delete_logo(7, 'uploads/tenants/7/../8/' . basename($other), $root) === false && is_file($root . '/' . $other), 'path traversal refused');
assertTest(org_delete_logo(7, null, $root) === false && org_delete_logo(7, '', $root) === false, 'NULL / empty path is a no-op');
assertTest(org_delete_logo(7, $stored, $root) === true && !is_file($root . '/' . $stored), 'own logo is deleted');
assertTest(org_delete_logo(7, $stored, $root) === false, 'deleting a missing file is harmless');

// -----------------------------------------------------------------------------
echo "\n=======================================================\n";
echo "Total: " . ($passes + $fails) . "  |  Passes: $passes  |  Fails: $fails\n";
echo "=======================================================\n";
exit($fails > 0 ? 1 : 0);
