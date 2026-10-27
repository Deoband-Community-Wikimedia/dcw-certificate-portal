<?php
/**
 * Bulk certificate ZIP download (#158): the shared PDF renderer and the guards
 * on admin/download_certificates_zip.php.
 */

define('TEST_RUNNING', true);
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../helpers/i18n.php';
require_once __DIR__ . '/../vendor/autoload.php';

echo "=== Running Bulk Certificate ZIP Tests ===\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($condition, $message) {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] $message\n";
        $passCount++;
    } else {
        echo "  [FAIL] $message\n";
        $failCount++;
    }
}

// -------------------------------------------------------------
echo "Test 1: certificate_build_pdf renders a certificate from a role template...\n";
$templateDir = __DIR__ . '/../uploads/templates/';
if (!is_dir($templateDir)) {
    mkdir($templateDir, 0755, true);
}
$templateName = 'test_bulk_zip_' . bin2hex(random_bytes(4)) . '.pdf';
$tpl = new TCPDF('L', 'mm', 'A4');
$tpl->setPrintHeader(false);
$tpl->setPrintFooter(false);
$tpl->AddPage();
file_put_contents($templateDir . $templateName, $tpl->Output('', 'S'));

$certData = [
    'full_name' => 'Aisha Khan',
    'event_name' => 'Test Summit',
    'template_file' => $templateName,
    'visual_settings' => json_encode([
        'name' => ['enabled' => true, 'pos_x' => 148, 'pos_y' => 85, 'font_size' => 30, 'text_align' => 'C'],
        'certid' => ['enabled' => true, 'pos_x' => 20, 'pos_y' => 185, 'font_size' => 10],
    ]),
    'rotation' => 0,
    'issue_date' => '2026-01-15',
    'certificate_issue_date' => null,
    'created_at' => '2026-01-01 00:00:00',
    'custom_certificate_text' => null,
];

$pdf = certificate_build_pdf($certData, 'DCW-TEST0001');
$bytes = $pdf->Output('', 'S');
assertTest(strpos($bytes, '%PDF') === 0, 'Output is a PDF document');
assertTest(strlen($bytes) > 1000, 'PDF has content (' . strlen($bytes) . ' bytes)');

$certData['issue_date'] = '2026-02-20';
$second = certificate_build_pdf($certData, 'DCW-TEST0002')->Output('', 'S');
assertTest(strpos($second, '%PDF') === 0, 'Renderer can be called repeatedly in one process (bulk export)');

assertTest(certificate_pdf_filename($certData) === 'Aisha Khan - Test Summit - Certificate.pdf', 'Single-download filename is unchanged');

// -------------------------------------------------------------
echo "\nTest 2: a missing template raises instead of killing the request...\n";
$certData['template_file'] = 'does_not_exist.pdf';
$threw = false;
try {
    certificate_build_pdf($certData, 'DCW-TEST0003');
} catch (RuntimeException $e) {
    $threw = ($e->getMessage() === 'no-template');
}
assertTest($threw, "Missing template throws RuntimeException('no-template')");
unlink($templateDir . $templateName);

// -------------------------------------------------------------
echo "\nTest 3: static audit of the ZIP route and its entry point...\n";
$route = file_get_contents(__DIR__ . '/../admin/download_certificates_zip.php');
foreach ([
    'verify_event_access'        => 'tenant check on the event',
    'event_roles WHERE id = ? AND event_id = ?' => 'role filter must belong to the event',
    "ep.status <> 'revoked'"     => 'revoked certificates excluded',
    'log_audit_action'           => 'audit log entry',
    'ZipArchive'                 => 'uses ZipArchive',
    'admin_logged_in'            => 'requires an admin session',
    'session_write_close()'      => 'releases the session lock during the long export',
    'register_shutdown_function' => 'temp files are cleaned up even if the request is killed',
] as $token => $why) {
    assertTest(strpos($route, $token) !== false, "Route contains '$token' ($why)");
}

$participants = file_get_contents(__DIR__ . '/../admin/manage_participants.php');
assertTest(strpos($participants, 'download_certificates_zip.php') !== false, 'manage_participants.php links to the ZIP route');

// helpers.php (loaded on every page) defines K_PATH_FONTS, so every other definition must be guarded
foreach (['send-email.php', 'download.php'] as $entry) {
    $src = file_get_contents(__DIR__ . '/../' . $entry);
    assertTest(
        preg_match("/if\\s*\\(\\s*!defined\\('K_PATH_FONTS'\\)\\s*\\)\\s*\\{\\s*define\\('K_PATH_FONTS'/", $src) === 1,
        "$entry only defines K_PATH_FONTS if it is not already defined"
    );
}

$download = file_get_contents(__DIR__ . '/../download.php');
assertTest(strpos($download, 'certificate_build_pdf') !== false, 'download.php renders through the shared helper');

echo "\n=== Bulk ZIP Test Summary ===\n";
echo "Total Passed: $passCount\n";
echo "Total Failed: $failCount\n";
if ($failCount > 0) {
    echo "FAILED: Some tests failed!\n";
    exit(1);
}
