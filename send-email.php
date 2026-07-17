require_once 'config.php';
require_once 'helpers.php';
// Include PHPMailer and FPDI
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use setasign\Fpdi\Tcpdf\Fpdi;
require 'vendor/autoload.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    echo json_encode(['success' => false, 'message' => 'Bad Request']);
    exit;
}

$certId = trim($_POST['id']);

// Define explicit font path for TCPDF
define('K_PATH_FONTS', __DIR__ . '/vendor/tecnickcom/tcpdf/fonts/');

// 1. Fetch Participant and Event Specific Data
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

if (!$certData || empty($certData['email'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid certificate or missing recipient email.']);
    exit;
}

$recipientEmail = $certData['email'];
$fullName = $certData['full_name'];

// 2. Generate PDF in memory
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

$templatePath = __DIR__ . '/uploads/templates/' . $certData['template_file'];
if (file_exists($templatePath)) {
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

    function renderElementForEmail($pdf, $settings, $text, $linkUrl = '') {
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
    $protocolPdf = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $domainNamePdf = $_SERVER['HTTP_HOST'];
    $basePathPdf = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    if ($basePathPdf === '/') $basePathPdf = '';
    $verifyUrlPdf = $protocolPdf . $domainNamePdf . $basePathPdf . '/verify/' . $certId;

    if (is_array($visualSettings)) {
        if (isset($visualSettings['name'])) {
            renderElementForEmail($pdf, $visualSettings['name'], $fullName);
        }
        if (isset($visualSettings['certid'])) {
            renderElementForEmail($pdf, $visualSettings['certid'], $certId, $verifyUrlPdf);
        }
        if (isset($visualSettings['date'])) {
            renderElementForEmail($pdf, $visualSettings['date'], $issueDate);
        }
        if (isset($visualSettings['custom_text']) && !empty($certData['custom_certificate_text'])) {
            renderElementForEmail($pdf, $visualSettings['custom_text'], $certData['custom_certificate_text']);
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
            
            $style = array('border' => 0, 'padding' => 0, 'fgcolor' => $fgColor, 'bgcolor' => false);
            $pdf->write2DBarcode($verifyUrlPdf, 'QRCODE,L', $qx, $qy, $qsize, $qsize, $style, 'N');
            $pdf->Link($qx, $qy, $qsize, $qsize, $verifyUrlPdf);
        }
    }
}
$pdfString = $pdf->Output('', 'S');

// 3. Rebuild Verification URL Context for Email
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$baseDir = str_replace('\\', '/', dirname($_SERVER['PHP_SELF']));
if ($baseDir === '/')
    $baseDir = '';
$verifyUrl = $protocol . $_SERVER['HTTP_HOST'] . $baseDir . '/verify/' . $certId;

// 4. Construct LinkedIn Pre-fill Parameters
$eventName = urlencode($certData['event_name']);
$issueYear = date('Y', strtotime($issueSource));
$issueMonth = date('n', strtotime($issueSource));
$organizationId = '92536649'; // DCW LinkedIn Organization ID
$linkedInAddUrl = "https://www.linkedin.com/profile/add?startTask=CERTIFICATION_NAME&name={$eventName}&organizationId={$organizationId}&issueYear={$issueYear}&issueMonth={$issueMonth}&certUrl=" . urlencode($verifyUrl) . "&certId=" . urlencode($certId);

// 5. Fire SMTP via Hostinger
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = $_ENV['SMTP_HOST'];
    $mail->SMTPAuth = filter_var($_ENV['SMTP_AUTH'], FILTER_VALIDATE_BOOLEAN);
    $mail->Username = $_ENV['SMTP_USER'];
    $mail->Password = $_ENV['SMTP_PASS'];
    $mail->SMTPSecure = $_ENV['SMTP_SECURE'];
    $mail->Port = $_ENV['SMTP_PORT'];

    $mail->setFrom($_ENV['SMTP_USER'], 'Deoband Community Wikimedia');
    $mail->addAddress($recipientEmail, $fullName);
    $mail->isHTML(true);
    $mail->Subject = "Verified Credential: " . $certData['event_name'];

    // Attach PDF
    // sanitizeForFilename() is defined in config.php and shared with download.php
    // so the emailed attachment name matches the manually-downloaded filename format.
    $safeFullName = sanitizeForFilename($fullName);
    $safeEventName = sanitizeForFilename($certData['event_name']);
    $filename = "{$safeFullName} - {$safeEventName} - Certificate.pdf";
    $mail->addStringAttachment($pdfString, $filename);

    // Professional HTML Email Template Layout
    $mail->Body = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f4f6f8; margin: 0; padding: 0; -webkit-font-smoothing: antialiased; }
            .email-wrapper { max-width: 600px; margin: 40px auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
            .email-header { background-color: #106b9a; padding: 32px 24px; text-align: center; }
            .email-header img { height: 50px; width: auto; }
            .email-body { padding: 40px 32px; color: #1e293b; line-height: 1.6; }
            h1 { font-size: 22px; color: #0f172a; margin-top: 0; font-weight: 700; }
            p { font-size: 15px; color: #475569; margin-bottom: 24px; }
            .meta-box { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 32px; }
            .meta-item { font-size: 14px; margin-bottom: 8px; font-family: monospace; color: #0f172a; }
            .meta-item strong { font-family: sans-serif; color: #475569; }
            .btn-linkedin { display: inline-block; background-color: #0a66c2; color: #ffffff !important; text-decoration: none; padding: 14px 28px; border-radius: 8px; font-size: 15px; font-weight: 600; text-align: center; margin-bottom: 20px; }
            .verify-link { font-size: 13px; color: #106b9a; text-decoration: none; word-break: break-all; }
            .email-footer { background-color: #0f172a; padding: 24px; text-align: center; font-size: 12px; color: #94a3b8; }
            .email-footer a { color: #38bdf8; text-decoration: none; }
        </style>
    </head>
    <body>
        <div class="email-wrapper">
            <div class="email-header">
                <img src="' . htmlspecialchars($protocol . $_SERVER['HTTP_HOST'] . $baseDir . '/assets/DCW_logo.png') . '" alt="Deoband Community Wikimedia Logo">
            </div>
            <div class="email-body">
                <h1>Congratulations, ' . htmlspecialchars($fullName) . '!</h1>
                <p>Your official certificate for <strong>' . htmlspecialchars($certData['event_name']) . '</strong> has been securely issued and archived. We have attached your certificate to this email.</p>
                
                <div class="meta-box">
                    <div class="meta-item"><strong>Credential ID:</strong> ' . htmlspecialchars($certId) . '</div>
                    <div class="meta-item"><strong>Verification Status:</strong> Permanent Record Active</div>
                </div>

                <p style="text-align: center; margin-bottom: 12px;"><strong>Share Your Achievement</strong></p>
                <p style="text-align: center; margin-bottom: 24px; font-size: 14px;">Add this credential directly to your LinkedIn profile to showcase it to your network:</p>
                
                <div style="text-align: center;">
                    <a href="' . htmlspecialchars($linkedInAddUrl) . '" target="_blank" class="btn-linkedin">
                        Add to LinkedIn Profile
                    </a>
                </div>

                <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 32px 0;">
                <p style="font-size: 13px; margin-bottom: 4px;"><strong>Direct Verification Record URL:</strong></p>
                <a href="' . htmlspecialchars($verifyUrl) . '" class="verify-link" target="_blank">' . $verifyUrl . '</a>
            </div>
            <div class="email-footer">
                &copy; ' . date('Y') . ' <a href="https://dcwwiki.org/">Deoband Community Wikimedia</a>. All Rights Reserved.
            </div>
        </div>
    </body>
    </html>';

    $mail->send();
    
    // Log success
    $stmtLog = $pdo->prepare("INSERT INTO email_logs (certificate_id, recipient_email, status, error_message) VALUES (?, ?, 'Success', NULL)");
    $stmtLog->execute([$certId, $recipientEmail]);

    echo json_encode(['success' => true, 'message' => 'Notification email fired successfully.']);
} catch (Exception $e) {
    // Log failure
    $errorMsg = $mail->ErrorInfo;
    $stmtLog = $pdo->prepare("INSERT INTO email_logs (certificate_id, recipient_email, status, error_message) VALUES (?, ?, 'Failed', ?)");
    $stmtLog->execute([$certId, $recipientEmail, $errorMsg]);

    echo json_encode(['success' => false, 'message' => "Mail dispatch failed: {$errorMsg}"]);
}