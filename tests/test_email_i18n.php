<?php
/**
 * Automated test suite for Email Template Localization (Issue #114).
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

echo "=== Running Email i18n Unit Tests ===\n\n";

// Test 1: i18n Key Verification for Email Templates
echo "Test 1: Email i18n Key Verification...\n";
$en = json_decode(file_get_contents(__DIR__ . '/../i18n/en.json'), true);
$es = json_decode(file_get_contents(__DIR__ . '/../i18n/es.json'), true);
$qqq = json_decode(file_get_contents(__DIR__ . '/../i18n/qqq.json'), true);

$requiredEmailKeys = [
    'email.certificate.subject',
    'email.certificate.heading',
    'email.certificate.body',
    'email.certificate.meta.id',
    'email.certificate.meta.status',
    'email.certificate.meta.status-value',
    'email.certificate.share.heading',
    'email.certificate.share.body',
    'email.certificate.share.btn-linkedin',
    'email.certificate.verify.heading',
    'email.common.footer.copyright',
    'email.password-reset.subject',
    'email.password-reset.heading',
    'email.password-reset.body',
    'email.password-reset.instructions',
    'email.password-reset.btn-reset',
    'email.password-reset.disclaimer'
];

foreach ($requiredEmailKeys as $k) {
    assertTest(isset($en[$k]) && $en[$k] !== '', "en.json defines '$k'");
    assertTest(isset($es[$k]) && $es[$k] !== '', "es.json defines '$k'");
    assertTest(isset($qqq[$k]) && $qqq[$k] !== '', "qqq.json documents '$k'");
}

// Test 2: Dynamic Subject & Body Replacements
echo "\nTest 2: Dynamic Placeholder Substitutions...\n";
$certSubject = __('email.certificate.subject', ['event' => 'Wikiversary 2026']);
assertTest(strpos($certSubject, 'Wikiversary 2026') !== false, 'Event substituted in certificate email subject');

$certHeading = __('email.certificate.heading', ['name' => 'Zaid Sayyed']);
assertTest(strpos($certHeading, 'Zaid Sayyed') !== false, 'Name substituted in certificate email heading');

$resetSubject = __('email.password-reset.subject', ['org' => 'DCW']);
assertTest(strpos($resetSubject, 'DCW') !== false, 'Org substituted in password reset subject');

$resetBody = __('email.password-reset.body', ['username' => 'admin_zaid', 'org' => 'DCW']);
assertTest(strpos($resetBody, 'admin_zaid') !== false && strpos($resetBody, 'DCW') !== false, 'Username & org substituted in password reset body');

$resetInstr = __('email.password-reset.instructions', ['minutes' => 60]);
assertTest(strpos($resetInstr, '60') !== false, 'Expiry minutes substituted in instructions');

echo "\n=======================================================\n";
echo "SUMMARY: PASS: $passes | FAIL: $fails\n";
echo "=======================================================\n";

if ($fails > 0) {
    exit(1);
}
