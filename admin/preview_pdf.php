<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    die("Unauthorized access.");
}

define('K_PATH_FONTS', dirname(__DIR__) . '/vendor/tecnickcom/tcpdf/fonts/');

require_once '../config.php';
require_once '../helpers.php';
require_once '../vendor/autoload.php';

use setasign\Fpdi\Tcpdf\Fpdi;

$roleId = $_GET['role_id'] ?? null;
if (!$roleId) {
    die("Role ID is required.");
}

$stmt = $pdo->prepare("
    SELECT er.*, e.name as event_name, e.certificate_issue_date 
    FROM event_roles er 
    JOIN events e ON er.event_id = e.id 
    WHERE er.id = ?
");
$stmt->execute([$roleId]);
$role = $stmt->fetch();

if (!$role) {
    die("Role not found.");
}

// Visual settings fallback
$defaultSettings = [
    'name' => [
        'enabled' => true,
        'pos_x' => 105, 'pos_y' => 100, 'font_size' => 40, 'box_width' => 0,
        'text_color' => '0,0,0', 'text_align' => 'C', 'font_file' => '', 'font_name' => 'alexbrush'
    ],
    'certid' => [
        'enabled' => true,
        'pos_x' => 10, 'pos_y' => 195, 'font_size' => 12, 'box_width' => 0,
        'text_color' => '0,0,0', 'text_align' => 'L', 'font_file' => '', 'font_name' => 'helvetica'
    ],
    'date' => [
        'enabled' => true,
        'pos_x' => 200, 'pos_y' => 195, 'font_size' => 12, 'box_width' => 0,
        'text_color' => '0,0,0', 'text_align' => 'R', 'font_file' => '', 'font_name' => 'helvetica',
        'date_format' => 'F j, Y'
    ],
    'qrcode' => [
        'enabled' => false,
        'pos_x' => 10, 'pos_y' => 10, 'font_size' => 30,
        'text_color' => '0,0,0', 'text_align' => 'L', 'font_file' => '', 'font_name' => ''
    ],
    'custom_text' => [
        'enabled' => false,
        'pos_x' => 100, 'pos_y' => 120, 'font_size' => 18, 'box_width' => 0,
        'text_color' => '0,0,0', 'text_align' => 'C', 'font_file' => '', 'font_name' => 'helvetica'
    ]
];

$visualSettings = !empty($role['visual_settings']) ? json_decode($role['visual_settings'], true) : $defaultSettings;

// Override with live editor settings if passed in request
if (isset($_GET['settings'])) {
    $decoded = json_decode($_GET['settings'], true);
    if (is_array($decoded)) {
        $visualSettings = $decoded;
    }
}

// Ensure all keys exist
foreach (['name', 'certid', 'date', 'qrcode', 'custom_text'] as $key) {
    if (!isset($visualSettings[$key])) {
        $visualSettings[$key] = $defaultSettings[$key];
    }
    if ($key !== 'qrcode' && !isset($visualSettings[$key]['box_width'])) {
        $visualSettings[$key]['box_width'] = 0;
    }
}

$rotation = isset($_GET['rotation']) ? (int)$_GET['rotation'] : (int)($role['rotation'] ?? 0);

$dateFormat = $visualSettings['date']['date_format'] ?? 'F j, Y';
$issueDate = date($dateFormat, strtotime($role['certificate_issue_date'] ?? 'now'));

$customTexts = [];
if (isset($_GET['custom_texts'])) {
    $decodedTexts = json_decode($_GET['custom_texts'], true);
    if (is_array($decodedTexts)) {
        $customTexts = $decodedTexts;
    }
}

$nameText = !empty($customTexts['name']) ? $customTexts['name'] : 'John Doe (Sample Name)';
$certidText = !empty($customTexts['certid']) ? $customTexts['certid'] : 'CERT-SAMPLE123';
$dateText = !empty($customTexts['date']) ? $customTexts['date'] : $issueDate;
$customText = !empty($customTexts['custom_text']) ? $customTexts['custom_text'] : "Sample Custom Certificate Text";

$pdf = new Fpdi();
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(0, 0, 0);
$pdf->SetAutoPageBreak(false, 0);
$pdf->setCellPaddings(0, 0, 0, 0);

$templatePath = dirname(__DIR__) . '/uploads/templates/' . $role['template_file'];
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

if ($rotation != 0) {
    $pdf->StartTransform();
    $pdf->Rotate(-$rotation, $w / 2, $h / 2);
    $pdf->useTemplate($tplIdx, ($w / 2) - ($size['width'] / 2), ($h / 2) - ($size['height'] / 2), $size['width'], $size['height']);
    $pdf->StopTransform();
} else {
    $pdf->useTemplate($tplIdx, 0, 0, $w, $h);
}

function renderElement($pdf, $settings, $text) {
    if (!isset($settings['enabled']) || !$settings['enabled']) return;

    $fontName = $settings['font_name'] ?? 'helvetica';
    // 'custom' means a user-uploaded TTF file should be used exclusively via font_file
    if ($fontName === 'custom') {
        $fontName = 'helvetica'; // safe fallback if font_file fails to load below
    }
    $coreFonts = ['helvetica', 'helveticab', 'helveticai', 'helveticabi', 
                  'times', 'timesb', 'timesi', 'timesbi', 
                  'courier', 'courierb', 'courieri', 'courierbi', 
                  'symbol', 'zapfdingbats'];
    $fontLoaded = false;

    if (!empty($settings['font_file'])) {
        $fontPath = dirname(__DIR__) . '/uploads/fonts/' . $settings['font_file'];
        if (file_exists($fontPath)) {
            $compiledFont = TCPDF_FONTS::addTTFfont($fontPath, 'TrueTypeUnicode', '', 96);
            if ($compiledFont !== false) {
                $fontName = $compiledFont;
                $fontLoaded = true;
            }
        }
    }

    $fontFileExists = false;
    if (defined('K_PATH_FONTS')) {
        $fontFileExists = file_exists(K_PATH_FONTS . strtolower($fontName) . '.php');
    }
    if (!$fontLoaded && !in_array(strtolower($fontName), $coreFonts) && !$fontFileExists) {
        $fontName = 'helvetica';
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
    } else {
        $strWidth = $pdf->GetStringWidth($text);
        if ($align === 'C') {
            $pdf->SetXY($posX - ($strWidth / 2), $posY);
        } elseif ($align === 'R') {
            $pdf->SetXY($posX - $strWidth, $posY);
        } else {
            $pdf->SetXY($posX, $posY);
        }
        $pdf->Cell($strWidth, 0, $text, 0, 0, 'L', false);
    }
}

// Generate a dummy verification URL
$mockVerifyUrl = 'https://dcwwiki.org/verify/mock_preview_id';

if (is_array($visualSettings)) {
    if (isset($visualSettings['name'])) {
        renderElement($pdf, $visualSettings['name'], $nameText);
    }
    if (isset($visualSettings['certid'])) {
        renderElement($pdf, $visualSettings['certid'], $certidText);
    }
    if (isset($visualSettings['date'])) {
        renderElement($pdf, $visualSettings['date'], $dateText);
    }
    if (isset($visualSettings['custom_text'])) {
        renderElement($pdf, $visualSettings['custom_text'], $customText);
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
            'bgcolor' => false,
        );
        $pdf->write2DBarcode($mockVerifyUrl, 'QRCODE,L', $qx, $qy, $qsize, $qsize, $style, 'N');
    }
}

$pdf->Output('certificate_preview.pdf', 'I');
