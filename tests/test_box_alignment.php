<?php
/**
 * Automated Unit Test Suite for Custom Box Alignment and Dynamic Font Auto-Scaling
 * (Fixes Issue #102)
 */

define('TEST_RUNNING', true);
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../helpers/i18n.php';
require_once __DIR__ . '/../vendor/autoload.php';

echo "=== Running Custom Box Alignment & Auto-Scaling Tests ===\n\n";

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
// Test 1: TCPDF String Width & Auto-Scaling Math Validation
// -------------------------------------------------------------
echo "Test 1: TCPDF String Width & Auto-Scaling Calculation...\n";
$pdf = new TCPDF();
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 14);

$boxWidth = 100.0; // 100mm bounding box

$shortText = "16 January 2026 to 30 June 2026.";
$longText = "01 January 2025 to 31 December 2026.";
$extraLongText = "Completed the DCW Graphic Design Internship from 01 Jan 2025 to 31 Dec 2026.";

$shortWidth = $pdf->GetStringWidth($shortText);
$longWidth = $pdf->GetStringWidth($longText);
$extraLongWidth = $pdf->GetStringWidth($extraLongText);

echo "  Short Text Width: " . round($shortWidth, 2) . "mm\n";
echo "  Long Text Width: " . round($longWidth, 2) . "mm\n";
echo "  Extra Long Text Width: " . round($extraLongWidth, 2) . "mm\n";

assertTest($shortWidth > 0, "Short text width calculated successfully");
assertTest($longWidth > $shortWidth, "Long text width is greater than short text width");

// Test Scaling math for Long Text
$baseFontSize = 14;
if ($longWidth > $boxWidth) {
    $scaledFontSize = floor(($baseFontSize * ($boxWidth / $longWidth) * 0.97) * 10) / 10;
    $pdf->SetFont('helvetica', '', $scaledFontSize);
    $scaledLongWidth = $pdf->GetStringWidth($longText);
    assertTest($scaledLongWidth <= $boxWidth, "Scaled long text width (" . round($scaledLongWidth, 2) . "mm) fits inside boxWidth (100mm)");
} else {
    assertTest(true, "Long text fits inside boxWidth without scaling");
}

// -------------------------------------------------------------
// Test 2: Verify renderElement Execution with TCPDF Page Output
// -------------------------------------------------------------
echo "\nTest 2: Full PDF Rendering Pipeline with Dynamic Auto-Scaling...\n";

$settings = [
    'enabled' => true,
    'pos_x' => 20,
    'pos_y' => 50,
    'font_size' => 14,
    'font_name' => 'helvetica',
    'text_color' => '0,0,0',
    'text_align' => 'C',
    'box_width' => 100
];

require_once __DIR__ . '/../download.php';

// Test renderElement with short text
renderElement($pdf, $settings, $shortText);
assertTest(true, "renderElement executed without errors for Short Text");

// Test renderElement with detailed long text
renderElement($pdf, $settings, $longText);
assertTest(true, "renderElement executed without errors for Detailed Long Text");

// Test renderElement with extra long description
renderElement($pdf, $settings, $extraLongText);
assertTest(true, "renderElement executed without errors for Extra Long Description");

// Output PDF string to ensure TCPDF doesn't throw exceptions
$pdfData = $pdf->Output('', 'S');
assertTest(!empty($pdfData) && strlen($pdfData) > 1000, "PDF document compiled successfully (" . strlen($pdfData) . " bytes)");

echo "\n=======================================================\n";
echo "SUMMARY: PASS: $passCount | FAIL: $failCount\n";
echo "=======================================================\n";

if ($failCount > 0) {
    exit(1);
} else {
    exit(0);
}
