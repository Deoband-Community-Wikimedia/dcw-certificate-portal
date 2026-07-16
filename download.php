<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Define explicit font path to avoid Linux path resolution bugs
define('K_PATH_FONTS', __DIR__ . '/vendor/tecnickcom/tcpdf/fonts/');

if (!isset($_GET['id'])) {
    die("Certificate ID is required.");
}

$certId = trim($_GET['id']);
$preview = isset($_GET['preview']) && $_GET['preview'] == 1;

require_once 'config.php';
require_once 'vendor/autoload.php';

use setasign\Fpdi\Tcpdf\Fpdi;

// 1. Verify certificate ID exists
$stmt = $pdo->prepare("
    SELECT ep.*, p.full_name, p.email, e.name as event_name, e.certificate_issue_date, er.template_file, er.visual_settings, er.rotation 
    FROM event_participants ep
    JOIN participants p ON ep.participant_id = p.id
    JOIN events e ON ep.event_id = e.id
    JOIN event_roles er ON ep.role_id = er.id
    WHERE ep.certificate_id = ?
");
$stmt->execute([$certId]);
$certData = $stmt->fetch();

if (!$certData) {
    die("Invalid Certificate ID.");
}

$fullName = $certData['full_name'];
$visualSettingsStr = !empty($certData['visual_settings']) ? $certData['visual_settings'] : '{}';
$visualSettings = json_decode($visualSettingsStr, true);
$rotation = (int)($certData['rotation'] ?? 0);

$dateFormat = 'F j, Y';
if (isset($visualSettings['date']['date_format'])) {
    $dateFormat = $visualSettings['date']['date_format'];
}
$issueSource = !empty($certData['certificate_issue_date']) ? $certData['certificate_issue_date'] : $certData['created_at'];
$issueDate = date($dateFormat, strtotime($issueSource));

$pdf = new Fpdi();
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(0, 0, 0);
$pdf->SetAutoPageBreak(false, 0);
$pdf->setCellPaddings(0, 0, 0, 0);

// Load Template to determine size
$templatePath = __DIR__ . '/uploads/templates/' . $certData['template_file'];
if (!file_exists($templatePath)) {
    die("Template file missing.");
}
$pdf->setSourceFile($templatePath);
$tplIdx = $pdf->importPage(1);
$size = $pdf->getTemplateSize($tplIdx);

$w = $size['width'];
$h = $size['height'];

if ($rotation == 90 || $rotation == 270) {
    $w = $size['height'];
    $h = $size['width'];
}

$orientation = ($w > $h) ? 'L' : 'P';
$pdf->AddPage($orientation, [$w, $h]);

// Apply rotation to the template if needed
if ($rotation != 0) {
    $pdf->StartTransform();
    $pdf->Rotate(-$rotation, $w / 2, $h / 2);
    $pdf->useTemplate($tplIdx, ($w / 2) - ($size['width'] / 2), ($h / 2) - ($size['height'] / 2), $size['width'], $size['height']);
    $pdf->StopTransform();
} else {
    $pdf->useTemplate($tplIdx, 0, 0, $w, $h);
}

// Function to render text element
function renderElement($pdf, $settings, $text, $linkUrl = '') {
    if (!isset($settings['enabled']) || !$settings['enabled']) return;

    $fontName = $settings['font_name'] ?? 'helvetica';
    if (!empty($settings['font_file'])) {
        $fontPath = __DIR__ . '/uploads/fonts/' . $settings['font_file'];
        if (file_exists($fontPath)) {
            $compiledFont = TCPDF_FONTS::addTTFfont($fontPath, 'TrueTypeUnicode', '', 96);
            if ($compiledFont !== false) {
                $fontName = $compiledFont;
            }
        }
    }

    $fontSize = $settings['font_size'] ?? 12;
    $pdf->SetFont($fontName, '', $fontSize);

    $colorStr = $settings['text_color'] ?? '0,0,0';
    if (strpos($colorStr, '#') === 0) {
        $hex = ltrim($colorStr, '#');
        if (strlen($hex) == 3) {
            $r = hexdec(str_repeat(substr($hex, 0, 1), 2));
            $g = hexdec(str_repeat(substr($hex, 1, 1), 2));
            $b = hexdec(str_repeat(substr($hex, 2, 1), 2));
        } else {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }
    } else {
        $parts = explode(',', $colorStr);
        $r = (int)($parts[0] ?? 0);
        $g = (int)($parts[1] ?? 0);
        $b = (int)($parts[2] ?? 0);
    }
    $pdf->SetTextColor($r, $g, $b);

    $posX = $settings['pos_x'];
    $posY = $settings['pos_y'];
    $align = $settings['text_align'] ?? 'L';
    $boxWidth = isset($settings['box_width']) ? (float)$settings['box_width'] : 0;

    if ($boxWidth > 0) {
        $pdf->SetXY($posX, $posY);
        $pdf->MultiCell($boxWidth, 0, $text, 0, $align, false, 1);
        if ($linkUrl !== '') {
            $pdf->Link($posX, $posY, $boxWidth, $pdf->GetY() - $posY, $linkUrl);
        }
    } else {
        $strWidth = $pdf->GetStringWidth($text);
        if ($align === 'C') {
            $pdf->SetXY($posX - ($strWidth / 2), $posY);
        } elseif ($align === 'R') {
            $pdf->SetXY($posX - $strWidth, $posY);
        } else {
            $pdf->SetXY($posX, $posY);
        }
        $pdf->Cell($strWidth, 0, $text, 0, 0, 'L', false, $linkUrl);
    }
}

// Calculate verification URL early for hyperlinks
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$domainName = $_SERVER['HTTP_HOST'];
$basePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
if ($basePath === '/') $basePath = '';
$verifyUrl = $protocol . $domainName . $basePath . '/verify/' . $certId;

// Render the elements and QR code
if (is_array($visualSettings)) {
    if (isset($visualSettings['name'])) {
        renderElement($pdf, $visualSettings['name'], $fullName);
    }
    if (isset($visualSettings['certid'])) {
        renderElement($pdf, $visualSettings['certid'], $certId, $verifyUrl);
    }
    if (isset($visualSettings['date'])) {
        renderElement($pdf, $visualSettings['date'], $issueDate);
    }
    if (isset($visualSettings['custom_text']) && !empty($certData['custom_certificate_text'])) {
        renderElement($pdf, $visualSettings['custom_text'], $certData['custom_certificate_text']);
    }
    if (isset($visualSettings['qrcode']) && !empty($visualSettings['qrcode']['enabled'])) {
        
        $qr = $visualSettings['qrcode'];
        $qx = (float)$qr['pos_x'];
        $qy = (float)$qr['pos_y'];
        $qsize = (float)($qr['font_size'] ?? 30);
        
        $qcolorStr = $qr['text_color'] ?? '0,0,0';
        $qcolorArr = explode(',', $qcolorStr);
        if (count($qcolorArr) === 3) {
            $fgColor = array((int)$qcolorArr[0], (int)$qcolorArr[1], (int)$qcolorArr[2]);
        } else {
            $fgColor = array(0,0,0);
        }
        
        $style = array(
            'border' => 0,
            'padding' => 0,
            'fgcolor' => $fgColor,
            'bgcolor' => false, //transparent
        );
        $pdf->write2DBarcode($verifyUrl, 'QRCODE,L', $qx, $qy, $qsize, $qsize, $style, 'N');
        $pdf->Link($qx, $qy, $qsize, $qsize, $verifyUrl);
    }
}

// Output
$filename = "certificate-" . preg_replace('/[^a-z0-9]+/', '-', strtolower($fullName)) . ".pdf";

if ($preview) {
    // Show inline in browser for previews
    $pdf->Output($filename, 'I');
} else {
    // Force download
    $pdf->Output($filename, 'D');
}
