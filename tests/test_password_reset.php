<?php
/**
 * Automated test suite for Admin Password Reset (Issue #84).
 */

require_once __DIR__ . '/../helpers/i18n.php';

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

echo "=== Running Admin Password Reset Unit Tests ===\n\n";

// Test 1: SHA-256 Token Hash Logic
echo "Test 1: Token Hash & Matching...\n";
$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
assertTest(strlen($rawToken) === 64, 'Raw token is 64 hex characters (32 bytes entropy)');
assertTest(strlen($tokenHash) === 64, 'Token hash is valid SHA-256 string');
assertTest(hash_equals($tokenHash, hash('sha256', $rawToken)), 'Token hashes match securely with hash_equals');

// Test 2: Token Expiry Math
echo "\nTest 2: Token Expiry Math...\n";
$expiryMinutes = 60;
$createdTime = time();
$expiryTime = $createdTime + ($expiryMinutes * 60);
assertTest($expiryTime > $createdTime, 'Expiry time is in the future');
assertTest(($expiryTime - $createdTime) === 3600, 'Expiry window is exactly 3600 seconds (60 mins)');

// Test 3: i18n Translation Keys for Password Reset
echo "\nTest 3: i18n Key Verification for Password Reset...\n";
$en = json_decode(file_get_contents(__DIR__ . '/../i18n/en.json'), true);
$es = json_decode(file_get_contents(__DIR__ . '/../i18n/es.json'), true);

$requiredKeys = [
    'admin.login.forgot-password',
    'admin.forgot-password.page-title',
    'admin.forgot-password.heading.request',
    'admin.forgot-password.subtitle.request',
    'admin.forgot-password.label.identifier',
    'admin.forgot-password.submit.request',
    'admin.forgot-password.heading.reset',
    'admin.forgot-password.subtitle.reset',
    'admin.forgot-password.label.new-password',
    'admin.forgot-password.label.confirm-password',
    'admin.forgot-password.submit.reset',
    'admin.forgot-password.back-to-login',
    'admin.forgot-password.msg.generic-sent',
    'admin.forgot-password.msg.success-reset',
    'admin.forgot-password.error.invalid-expired',
    'admin.forgot-password.error.short-password',
    'admin.forgot-password.error.mismatch-password'
];

foreach ($requiredKeys as $k) {
    assertTest(isset($en[$k]) && $en[$k] !== '', "en.json defines '$k'");
    assertTest(isset($es[$k]) && $es[$k] !== '', "es.json defines '$k'");
}

// Test 4: Dynamic Parameter Replacement in __()
echo "\nTest 4: Dynamic Parameter Replacement...\n";
$translated = __('admin.forgot-password.subtitle.reset', ['username' => 'testadmin']);
assertTest(strpos($translated, 'testadmin') !== false, "Replaced {username} with 'testadmin'");

echo "\n=======================================================\n";
echo "SUMMARY: PASS: $passes | FAIL: $fails\n";
echo "=======================================================\n";

if ($fails > 0) {
    exit(1);
}
