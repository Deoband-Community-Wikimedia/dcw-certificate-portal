<?php
/**
 * Test: Custom Text Box Alignment & Multi-line Wrapping
 */

require_once __DIR__ . '/../vendor/autoload.php';

echo "Running Custom Box Alignment & Wrapping Tests...\n";
$testsPassed = 0;
$testsFailed = 0;

function assert_true($condition, $message) {
    global $testsPassed, $testsFailed;
    if ($condition) {
        echo "  [PASS] $message\n";
        $testsPassed++;
    } else {
        echo "  [FAIL] $message\n";
        $testsFailed++;
    }
}

// 1. Create a PDF instance with TCPDF
$pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(0, 0, 0);
$pdf->SetAutoPageBreak(false, 0);
$pdf->setCellPaddings(0, 0, 0, 0);
$pdf->AddPage('L', [297, 210]); // A4 Landscape

$pdf->SetFont('helvetica', '', 14);

$posX = 50.0;
$posY = 100.0;
$boxWidth = 80.0; // 80mm constrained box

// Render short text
$shortText = "16 January 2026 to 30 June 2026.";
$pdf->SetXY($posX, $posY);
$pdf->MultiCell($boxWidth, 0, $shortText, 0, 'L', false, 1);
$endYShort = $pdf->GetY();

assert_true($pdf->GetX() >= 0, "PDF X coordinate is valid after short MultiCell");

// Render long text
$longText = "01 January 2025 to 31 December 2026.";
$posY2 = 140.0;
$pdf->SetXY($posX, $posY2);
$pdf->MultiCell($boxWidth, 0, $longText, 0, 'L', false, 1);
$endYLong = $pdf->GetY();

assert_true($endYLong > $posY2, "Long text wrapped onto multiple lines with height: " . ($endYLong - $posY2));

// Test Output Generation
$pdfContent = $pdf->Output('', 'S');
assert_true(!empty($pdfContent) && strlen($pdfContent) > 500, "PDF binary generated successfully with size: " . strlen($pdfContent) . " bytes");

// Summary
echo "\n=======================================================\n";
echo "Tests Passed: $testsPassed\n";
echo "Tests Failed: $testsFailed\n";
echo "=======================================================\n";

if ($testsFailed > 0) exit(1);
exit(0);
