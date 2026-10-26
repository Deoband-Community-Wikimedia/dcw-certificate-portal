<?php
if (!defined('K_PATH_FONTS')) {
    define('K_PATH_FONTS', __DIR__ . '/vendor/tecnickcom/tcpdf/fonts/');
}

if (defined('TEST_RUNNING')) {
    return;
}

require_once 'config.php';
require_once 'helpers.php';

if (!isset($_GET['id'])) {
    die(__('page.download.error.invalid-id'));
}

$certId = trim($_GET['id']);
$preview = isset($_GET['preview']) && $_GET['preview'] == 1;

if (!defined('TEST_RUNNING') && !otp_is_claim_authorized($certId)) {
    http_response_code(403);
    die(__('otp.error.unauthorized-access'));
}

// 1. Verify certificate ID exists
$certData = certificate_fetch_data($pdo, $certId);

if (!$certData) {
    die(__('page.download.error.not-found'));
}
if (($certData['status'] ?? 'active') === 'revoked') {
    http_response_code(410);
    die(__('page.download.error.revoked'));
}

// Rendering lives in helpers/certificate_pdf.php so the admin bulk ZIP export builds identical PDFs
try {
    $pdf = certificate_build_pdf($certData, $certId);
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'no-template') {
        die(__('page.download.error.no-template'));
    }
    throw $e;
}

// Output
$filename = certificate_pdf_filename($certData);

if (isset($outputAsString) && $outputAsString) {
    return $pdf->Output($filename, 'S');
}

if ($preview) {
    // Show inline in browser for previews
    $pdf->Output($filename, 'I');
} else {
    // Force download
    $pdf->Output($filename, 'D');
}
