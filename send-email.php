<?php
require_once 'config.php';
// Include PHPMailer (Adjust paths based on your installation)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'vendor/autoload.php'; 

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    echo json_encode(['success' => false, 'message' => 'Bad Request']);
    exit;
}

$certId = trim($_POST['id']);

// 1. Fetch Participant and Event Specific Data
$stmt = $pdo->prepare("
    SELECT p.full_name, p.email, e.name as event_name, e.certificate_issue_date, ep.created_at
    FROM event_participants ep
    JOIN participants p ON ep.participant_id = p.id
    JOIN events e ON ep.event_id = e.id
    WHERE ep.certificate_id = ?
");
$stmt->execute([$certId]);
$certData = $stmt->fetch();

if (!$certData || empty($certData['email'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid certificate or missing recipient email.']);
    exit;
}

// 2. Rebuild Verification URL Context
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$baseDir = str_replace('\\', '/', dirname($_SERVER['PHP_SELF']));
if ($baseDir === '/') $baseDir = '';
$verifyUrl = $protocol . $_SERVER['HTTP_HOST'] . $baseDir . '/verify/' . $certId;

// 3. Construct LinkedIn Pre-fill Parameters
$eventName = urlencode($certData['event_name']);
$issueSource = !empty($certData['certificate_issue_date']) ? $certData['certificate_issue_date'] : $certData['created_at'];
$issueYear = date('Y', strtotime($issueSource));
$issueMonth = date('n', strtotime($issueSource));
$organizationId = '92536649'; // DCW LinkedIn Organization ID

$linkedInAddUrl = "https://www.linkedin.com/profile/add?startTask=CERTIFICATION_NAME&name={$eventName}&organizationId={$organizationId}&issueYear={$issueYear}&issueMonth={$issueMonth}&certUrl=" . urlencode($verifyUrl) . "&certId=" . urlencode($certId);

// 4. Fire SMTP via Hostinger
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = SMTP_AUTH;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = SMTP_SECURE;
    $mail->Port       = SMTP_PORT;

    $mail->setFrom(SMTP_USER, 'Deoband Community Wikimedia');
    $mail->addAddress($certData['email'], $certData['full_name']);
    $mail->isHTML(true);
    $mail->Subject = "Verified Credential: " . $certData['event_name'];

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
                <img src="https://dcwwiki.org/images/5/56/DCW_logo.png" alt="Deoband Community Wikimedia Logo">
            </div>
            <div class="email-body">
                <h1>Congratulations, '.htmlspecialchars($certData['full_name']).'!</h1>
                <p>Your official certificate for <strong>'.htmlspecialchars($certData['event_name']).'</strong> has been securely issued and archived.</p>
                
                <div class="meta-box">
                    <div class="meta-item"><strong>Credential ID:</strong> '.htmlspecialchars($certId).'</div>
                    <div class="meta-item"><strong>Verification Status:</strong> Permanent Record Active</div>
                </div>

                <p style="text-align: center; margin-bottom: 12px;"><strong>Share Your Achievement</strong></p>
                <p style="text-align: center; margin-bottom: 24px; font-size: 14px;">Add this credential directly to your LinkedIn profile to showcase it to your network:</p>
                
                <div style="text-align: center;">
                    <a href="'.htmlspecialchars($linkedInAddUrl).'" target="_blank" class="btn-linkedin">
                        Add to LinkedIn Profile
                    </a>
                </div>

                <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 32px 0;">
                <p style="font-size: 13px; margin-bottom: 4px;"><strong>Direct Verification Record URL:</strong></p>
                <a href="'.htmlspecialchars($verifyUrl).'" class="verify-link" target="_blank">'.$verifyUrl.'</a>
            </div>
            <div class="email-footer">
                &copy; '.date('Y').' <a href="https://dcwwiki.org/">Deoband Community Wikimedia</a>. All Rights Reserved.
            </div>
        </div>
    </body>
    </html>';

    $mail->send();
    echo json_encode(['success' => true, 'message' => 'Notification email fired successfully.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => "Mail dispatch failed: {$mail->ErrorInfo}"]);
}
