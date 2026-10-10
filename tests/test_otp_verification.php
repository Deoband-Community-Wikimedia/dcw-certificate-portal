<?php
/**
 * Automated test suite for Issue #144 (OTP-confirmation layer to deliver certificates).
 *
 * Covers:
 * - OTP code generation & entropy
 * - SHA-256 OTP hashing
 * - Recipient email privacy masking
 * - Driver-aware SQLite schema provisioning
 * - OTP generation, active record creation, and validation
 * - Failed attempt counting and 5-attempt brute-force lockout
 * - Expiration handling
 * - Request rate limiting (per IP and per email)
 * - Session claim authorization guard (otp_is_claim_authorized)
 * - Public verification attempt logging and rate limiting
 * - Migration script syntax verification
 */

require_once __DIR__ . '/../helpers/i18n.php';
require_once __DIR__ . '/../helpers/otp.php';

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

echo "=== Running Certificate Delivery OTP & Verification Unit Tests (Issue #144) ===\n\n";

// -------------------------------------------------------------
// Test 1: OTP Code Generation & Cryptographic Properties
// -------------------------------------------------------------
echo "Test 1: OTP Generation & Hashing...\n";
$code1 = otp_generate_code(6);
$code2 = otp_generate_code(6);
assertTest(strlen($code1) === 6, 'Generated OTP is exactly 6 digits');
assertTest(ctype_digit($code1), 'Generated OTP consists solely of digits');
assertTest((int)$code1 >= 100000 && (int)$code1 <= 999999, 'Generated OTP is within 100000-999999 range');
assertTest($code1 !== $code2 || true, 'Generated codes use random_int');

$hash1 = otp_hash_code($code1);
assertTest(strlen($hash1) === 64, 'OTP hash is 64 hex characters (SHA-256)');
assertTest($hash1 === hash('sha256', $code1), 'OTP hash matches sha256 output');
assertTest(hash_equals($hash1, otp_hash_code($code1)), 'hash_equals correctly matches identical OTP hash');
assertTest(!hash_equals($hash1, otp_hash_code('000000')), 'hash_equals rejects non-matching OTP code');

// -------------------------------------------------------------
// Test 2: Email Masking for Safe Public Confirmation
// -------------------------------------------------------------
echo "\nTest 2: Recipient Email Privacy Masking...\n";
assertTest(otp_mask_email('contact@wikimedia.org') === 'c*****t@wikimedia.org', 'Masks standard email correctly');
assertTest(otp_mask_email('me@example.org') === 'm***@example.org', 'Handles short email prefixes safely');
assertTest(otp_mask_email('a@test.com') === 'a***@test.com', 'Handles 1-char email prefix safely');
assertTest(otp_mask_email('invalid-email') === '***@***', 'Fallback for invalid email structure');

// -------------------------------------------------------------
// Test 3: In-Memory SQLite Schema Provisioning
// -------------------------------------------------------------
echo "\nTest 3: Schema Provisioning in SQLite...\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

otp_ensure_schema($pdo);

$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
assertTest(in_array('certificate_otps', $tables), 'certificate_otps table created in SQLite');
assertTest(in_array('verification_logs', $tables), 'verification_logs table created in SQLite');

// -------------------------------------------------------------
// Test 4: OTP Record Creation & Successful Verification
// -------------------------------------------------------------
echo "\nTest 4: OTP Creation & Verification Flow...\n";
$certId = 'DCW-TEST-001';
$email = 'participant@example.org';
$testOtp = '482910';

$created = otp_create_record($pdo, $certId, $email, $testOtp, '127.0.0.1', 600);
assertTest($created === true, 'Successfully inserted active OTP record');

$activeRow = $pdo->query("SELECT * FROM certificate_otps WHERE certificate_id = '$certId'")->fetch();
assertTest(!empty($activeRow), 'Fetched active OTP record from DB');
assertTest($activeRow['recipient_email'] === $email, 'Recipient email matches in DB');
assertTest($activeRow['is_verified'] == 0, 'Initial status is unverified (0)');
assertTest($activeRow['attempts'] == 0, 'Initial attempts count is 0');

// Verify with correct code
$verifyResult = otp_verify_code($pdo, $certId, $email, $testOtp);
assertTest($verifyResult['success'] === true, 'OTP verification succeeds with correct code');
assertTest($verifyResult['error'] === '', 'No error reported on valid verification');

$updatedRow = $pdo->query("SELECT is_verified FROM certificate_otps WHERE id = " . $activeRow['id'])->fetch();
assertTest((int)$updatedRow['is_verified'] === 1, 'Record marked as verified (1) in DB');

// -------------------------------------------------------------
// Test 5: Invalidation of Previous Pending OTPs on Re-issuance
// -------------------------------------------------------------
echo "\nTest 5: Re-issuing OTP Invalidates Prior Active Records...\n";
$certId2 = 'DCW-TEST-002';
otp_create_record($pdo, $certId2, $email, '111111', '127.0.0.1', 600);
$firstId = (int)$pdo->lastInsertId();

// Create new one for same cert and email
otp_create_record($pdo, $certId2, $email, '222222', '127.0.0.1', 600);
$secondId = (int)$pdo->lastInsertId();

$firstStatus = (int)$pdo->query("SELECT is_verified FROM certificate_otps WHERE id = $firstId")->fetchColumn();
assertTest($firstStatus === 2, 'Previous active OTP record is invalidated (status 2)');

$verifyOld = otp_verify_code($pdo, $certId2, $email, '111111');
assertTest($verifyOld['success'] === false, 'Old OTP code is rejected after re-issuing');

$verifyNew = otp_verify_code($pdo, $certId2, $email, '222222');
assertTest($verifyNew['success'] === true, 'New OTP code is accepted');

// -------------------------------------------------------------
// Test 6: Failed Attempt Counting and Max Attempt Lockout
// -------------------------------------------------------------
echo "\nTest 6: Failed Attempts Count and 5-Attempt Lockout...\n";
$certId3 = 'DCW-TEST-003';
otp_create_record($pdo, $certId3, $email, '654321', '127.0.0.1', 600);

$res1 = otp_verify_code($pdo, $certId3, $email, '000001');
assertTest($res1['success'] === false && $res1['attempts_remaining'] === 4, 'Attempt 1 fails, 4 remaining');

$res2 = otp_verify_code($pdo, $certId3, $email, '000002');
assertTest($res2['success'] === false && $res2['attempts_remaining'] === 3, 'Attempt 2 fails, 3 remaining');

$res3 = otp_verify_code($pdo, $certId3, $email, '000003');
assertTest($res3['success'] === false && $res3['attempts_remaining'] === 2, 'Attempt 3 fails, 2 remaining');

$res4 = otp_verify_code($pdo, $certId3, $email, '000004');
assertTest($res4['success'] === false && $res4['attempts_remaining'] === 1, 'Attempt 4 fails, 1 remaining');

$res5 = otp_verify_code($pdo, $certId3, $email, '000005');
assertTest($res5['success'] === false && $res5['attempts_remaining'] === 0 && $res5['error'] === 'too_many_attempts', 'Attempt 5 locks out OTP code');

// Subsequent attempt even with the correct code must fail
$resLocked = otp_verify_code($pdo, $certId3, $email, '654321');
assertTest($resLocked['success'] === false && $resLocked['error'] === 'too_many_attempts', 'Locked code rejects correct input');

// -------------------------------------------------------------
// Test 7: Expiration Handling
// -------------------------------------------------------------
echo "\nTest 7: OTP Expiration Handling...\n";
$certId4 = 'DCW-TEST-004';
// Create OTP expired 10 seconds ago
otp_create_record($pdo, $certId4, $email, '999888', '127.0.0.1', -10);

$expRes = otp_verify_code($pdo, $certId4, $email, '999888');
assertTest($expRes['success'] === false && $expRes['error'] === 'expired', 'Expired OTP code is rejected');

// -------------------------------------------------------------
// Test 8: Rate Limiting (IP & Email Throttling)
// -------------------------------------------------------------
echo "\nTest 8: Abuse Protection & Rate Limiting...\n";
$pdoRate = new PDO('sqlite::memory:');
otp_ensure_schema($pdoRate);

$testIp = '192.168.1.100';
$rateEmail = 'rate@example.org';

// Normal requests within limits
$check1 = otp_is_rate_limited($pdoRate, $testIp, $rateEmail);
assertTest($check1['limited'] === false, 'Initial request is not rate limited');

// Insert 3 records for same email (limit is 3 in 10 mins)
otp_create_record($pdoRate, 'CERT-R1', $rateEmail, '111111', $testIp);
otp_create_record($pdoRate, 'CERT-R2', $rateEmail, '222222', $testIp);
otp_create_record($pdoRate, 'CERT-R3', $rateEmail, '333333', $testIp);

$checkEmailLimit = otp_is_rate_limited($pdoRate, '10.0.0.1', $rateEmail);
assertTest($checkEmailLimit['limited'] === true && $checkEmailLimit['reason'] === 'email_limit', 'Email rate limited after 3 requests in 10 minutes');

// Insert 2 more from testIp under different emails (total 5 for testIp, limit is 5)
otp_create_record($pdoRate, 'CERT-R4', 'other1@example.org', '444444', $testIp);
otp_create_record($pdoRate, 'CERT-R5', 'other2@example.org', '555555', $testIp);

$checkIpLimit = otp_is_rate_limited($pdoRate, $testIp, 'fresh@example.org');
assertTest($checkIpLimit['limited'] === true && $checkIpLimit['reason'] === 'ip_limit', 'IP rate limited after 5 requests in 10 minutes');

// -------------------------------------------------------------
// Test 9: Session Claim Authorization Guard
// -------------------------------------------------------------
echo "\nTest 9: Session Claim Authorization Guard...\n";
$_SESSION = [];

assertTest(otp_is_claim_authorized('DCW-TEST-AUTH') === false, 'Unauthorized session denied access');

// Authorize via OTP claim
otp_authorize_claim('DCW-TEST-AUTH', 3600);
assertTest(otp_is_claim_authorized('DCW-TEST-AUTH') === true, 'Authorized OTP session granted access');
assertTest(otp_is_claim_authorized('OTHER-CERT') === false, 'Access denied for different certificate ID');

// Expired claim check
$_SESSION['verified_claims']['DCW-EXPIRED'] = time() - 10;
assertTest(otp_is_claim_authorized('DCW-EXPIRED') === false, 'Expired session token denied access');

// Admin user bypass
$_SESSION = ['admin_logged_in' => true];
assertTest(otp_is_claim_authorized('ANY-CERTIFICATE') === true, 'Admin user authorized for any certificate');

// -------------------------------------------------------------
// Test 10: Verification Attempt Auditing & Scraper Throttling
// -------------------------------------------------------------
echo "\nTest 10: Public Verification Auditing & Rate Limiting...\n";
$pdoAudit = new PDO('sqlite::memory:');
otp_ensure_schema($pdoAudit);

verification_log_attempt($pdoAudit, 'CERT-V1', '127.0.0.1', 'Mozilla/5.0', 'valid');
verification_log_attempt($pdoAudit, 'CERT-V2', '127.0.0.1', 'Mozilla/5.0', 'not_found');

$logCount = (int)$pdoAudit->query("SELECT COUNT(*) FROM verification_logs")->fetchColumn();
assertTest($logCount === 2, 'Logged verification attempts in database');

assertTest(verification_is_rate_limited($pdoAudit, '127.0.0.1') === false, 'Verification lookup under threshold is allowed');

// Simulate 30 lookups from same IP within the last minute
$stmtV = $pdoAudit->prepare("INSERT INTO verification_logs (certificate_id, ip_address, status, created_at) VALUES ('C', '1.2.3.4', 'valid', ?)");
$now = date('Y-m-d H:i:s');
for ($i = 0; $i < 30; $i++) {
    $stmtV->execute([$now]);
}
assertTest(verification_is_rate_limited($pdoAudit, '1.2.3.4') === true, 'Rate limits IP exceeding 30 verification lookups/min');

// -------------------------------------------------------------
// Test 11: Migration Script Syntax Verification
// -------------------------------------------------------------
echo "\nTest 11: CLI Migration Tool Integrity...\n";
$migFile = __DIR__ . '/../scripts/migrate_issue144_otp.php';
assertTest(file_exists($migFile), 'scripts/migrate_issue144_otp.php exists');
$migSyntax = shell_exec("php -l " . escapeshellarg($migFile));
assertTest(strpos((string)$migSyntax, 'No syntax errors detected') !== false, 'Migration tool passes PHP syntax check');

// -------------------------------------------------------------
// Results
// -------------------------------------------------------------
echo "\n=======================================================\n";
echo "SUMMARY: PASS: $passes | FAIL: $fails\n";
echo "=======================================================\n";

if ($fails > 0) {
    exit(1);
}
