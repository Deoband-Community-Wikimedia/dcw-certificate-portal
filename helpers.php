<?php
/**
 * Shared helper functions for the DCW Certificate Portal
 */

if (!function_exists('event_categories')) {
    /**
     * Canonical list of event categories/types (issue #59).
     * Used to populate dropdowns and to validate submitted values.
     *
     * @return string[]
     */
    function event_categories() {
        return [
            'Conference',
            'Workshop',
            'Photography Competition',
            'Editathon',
            'Internship',
            'Learning Course',
            'Testing Event',
            'Other',
        ];
    }
}

if (!function_exists('sanitizeForFilename')) {
    /**
     * Sanitizes a string to be safe for use as a filename.
     * Removes invalid filesystem characters (/ \ : * ? " < > |) and control characters.
     *
     * @param string $str
     * @return string
     */
    function sanitizeForFilename($str) {
        // Remove characters that are illegal in Windows/Linux/macOS filenames
        $str = preg_replace('/[\/\\\:\*\?"<>\|]/', '', $str);
        // Remove control characters (ASCII 0-31)
        $str = preg_replace('/[\x00-\x1F\x7F]/', '', $str);
        // Trim whitespace and dots
        $str = trim($str, " .");
        return $str === '' ? 'Untitled' : $str;
    }
}

if (!function_exists('sendAvailabilityEmail')) {
    /**
     * Sends an email notification to the participant that their certificate is available to claim.
     *
     * @param PDO $pdo
     * @param string $certId
     * @return array Array with success status and message/error.
     */
    function sendAvailabilityEmail($pdo, $certId) {
        // Fetch participant and event details
        $stmt = $pdo->prepare("
            SELECT ep.certificate_id, p.full_name, p.email, e.name as event_name 
            FROM event_participants ep
            JOIN participants p ON ep.participant_id = p.id
            JOIN events e ON ep.event_id = e.id
            WHERE ep.certificate_id = ?
        ");
        $stmt->execute([$certId]);
        $certData = $stmt->fetch();

        if (!$certData || empty($certData['email'])) {
            return ['success' => false, 'message' => 'Invalid certificate or missing recipient email.'];
        }

        $recipientEmail = $certData['email'];
        $fullName = $certData['full_name'];
        $eventName = $certData['event_name'];

        // Determine portal root URL dynamically
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
        $baseDir = str_replace('\\', '/', dirname($_SERVER['PHP_SELF']));
        // If run from admin panel directory, strip it
        $portalDir = preg_replace('/\/admin(\/|$)/', '/', $baseDir);
        if ($portalDir === '/') $portalDir = '';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $portalUrl = $protocol . $host . $portalDir;
        $logoUrl = $portalUrl . '/assets/DCW_logo.png';

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

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
            $mail->Subject = "Certificate Available: " . $eventName;

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
                    .btn-portal { display: inline-block; background-color: #106b9a; color: #ffffff !important; text-decoration: none; padding: 14px 28px; border-radius: 8px; font-size: 15px; font-weight: 600; text-align: center; }
                    .email-footer { background-color: #0f172a; padding: 24px; text-align: center; font-size: 12px; color: #94a3b8; }
                    .email-footer a { color: #38bdf8; text-decoration: none; }
                </style>
            </head>
            <body>
                <div class="email-wrapper">
                    <div class="email-header">
                        <img src="' . htmlspecialchars($logoUrl) . '" alt="Deoband Community Wikimedia Logo">
                    </div>
                    <div class="email-body">
                        <h1>Hello, ' . htmlspecialchars($fullName) . '!</h1>
                        <p>We are pleased to inform you that your official certificate for <strong>' . htmlspecialchars($eventName) . '</strong> is now available on the Deoband Community Wikimedia Certificate Portal.</p>
                        <p>You can claim and download your certificate by entering your registered name and email address on our portal.</p>
                        
                        <div style="text-align: center; margin: 32px 0;">
                            <a href="' . htmlspecialchars($portalUrl) . '" target="_blank" class="btn-portal">
                                Go to Certificate Portal
                            </a>
                        </div>
                        
                        <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 32px 0;">
                        <p style="font-size: 13px; color: #64748b; margin: 0;">Please ensure you use your registered email address (<strong>' . htmlspecialchars($recipientEmail) . '</strong>) when claiming the certificate.</p>
                    </div>
                    <div class="email-footer">
                        &copy; ' . date('Y') . ' <a href="https://dcwwiki.org/">Deoband Community Wikimedia</a>. All Rights Reserved.
                    </div>
                </div>
            </body>
            </html>';

            $mail->send();
            
            // Log success to email_logs
            $stmtLog = $pdo->prepare("INSERT INTO email_logs (certificate_id, recipient_email, status, error_message) VALUES (?, ?, 'Success', NULL)");
            $stmtLog->execute([$certId, $recipientEmail]);

            // Update event_participants.notification_sent status
            $stmtUpdate = $pdo->prepare("UPDATE event_participants SET notification_sent = 1 WHERE certificate_id = ?");
            $stmtUpdate->execute([$certId]);

            return ['success' => true, 'message' => 'Notification email sent successfully.'];
        } catch (\Exception $e) {
            $errorMsg = $mail->ErrorInfo ?: $e->getMessage();
            
            // Log failure to email_logs
            $stmtLog = $pdo->prepare("INSERT INTO email_logs (certificate_id, recipient_email, status, error_message) VALUES (?, ?, 'Failed', ?)");
            $stmtLog->execute([$certId, $recipientEmail, $errorMsg]);

            return ['success' => false, 'message' => 'Mail dispatch failed: ' . $errorMsg];
        }
    }
}

