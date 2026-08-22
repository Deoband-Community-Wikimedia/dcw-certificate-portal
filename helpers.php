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

if (!function_exists('getUniqueFilename')) {
    /**
     * Generates a unique filename in the target directory by appending numbers if collisions exist.
     *
     * @param string $dir Target directory with trailing slash
     * @param string $filename Original file name
     * @return string
     */
    function getUniqueFilename($dir, $filename) {
        $filename = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $filename);
        $info = pathinfo($filename);
        $name = $info['filename'];
        $ext  = isset($info['extension']) ? '.' . $info['extension'] : '';
        
        $counter = 1;
        $newFilename = $filename;
        while (file_exists($dir . $newFilename)) {
            $newFilename = $name . '(' . $counter . ')' . $ext;
            $counter++;
        }
        return $newFilename;
    }
}

if (!function_exists('syncEventTemplateFolder')) {
    /**
     * Synchronizes and renames template folders on disk and updates template_file paths in database.
     *
     * @param PDO $pdo
     * @param int $eventId
     * @param string|null $oldEventName Previous event name if renamed in current request
     * @return array Summary of actions performed
     */
    function syncEventTemplateFolder($pdo, $eventId, $oldEventName = null) {
        $summary = [
            'event_id' => $eventId,
            'folders_renamed' => 0,
            'files_moved' => 0,
            'roles_updated' => 0,
            'directories_removed' => 0
        ];

        // Fetch current event info
        $stmt = $pdo->prepare("SELECT id, name FROM events WHERE id = ?");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch();
        if (!$event) {
            return $summary;
        }

        $expectedFolderName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $event['name']);
        $tplBaseDir = rtrim(__DIR__ . '/uploads/templates', '/\\') . '/';
        $expectedDir = $tplBaseDir . $expectedFolderName . '/';

        // 1. If oldEventName is explicitly provided and differs from expected
        if (!empty($oldEventName)) {
            $oldFolderName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $oldEventName);
            if ($oldFolderName !== $expectedFolderName) {
                $oldDir = $tplBaseDir . $oldFolderName . '/';
                if (is_dir($oldDir)) {
                    if (!is_dir($expectedDir)) {
                        // Direct rename of entire directory
                        if (@rename(rtrim($oldDir, '/\\'), rtrim($expectedDir, '/\\'))) {
                            $summary['folders_renamed']++;
                        }
                    } else {
                        // Target already exists, move files over
                        $items = @scandir($oldDir) ?: [];
                        foreach ($items as $item) {
                            if ($item === '.' || $item === '..') continue;
                            $src = $oldDir . $item;
                            if (is_file($src)) {
                                $destFilename = file_exists($expectedDir . $item) ? getUniqueFilename($expectedDir, $item) : $item;
                                if (@rename($src, $expectedDir . $destFilename)) {
                                    $summary['files_moved']++;
                                    // Update DB if filename changed
                                    if ($destFilename !== $item) {
                                        $stmtUpd = $pdo->prepare("UPDATE event_roles SET template_file = ? WHERE event_id = ? AND template_file = ?");
                                        $stmtUpd->execute([$expectedFolderName . '/' . $destFilename, $eventId, $oldFolderName . '/' . $item]);
                                    }
                                }
                            }
                        }
                        $remaining = array_diff(@scandir($oldDir) ?: [], ['.', '..']);
                        if (empty($remaining)) {
                            if (@rmdir($oldDir)) {
                                $summary['directories_removed']++;
                            }
                        }
                    }
                }

                // Update database references for oldFolderName
                $stmtRolesOld = $pdo->prepare("SELECT id, template_file FROM event_roles WHERE event_id = ?");
                $stmtRolesOld->execute([$eventId]);
                $rolesOld = $stmtRolesOld->fetchAll();
                foreach ($rolesOld as $r) {
                    if (strpos($r['template_file'], $oldFolderName . '/') === 0) {
                        $newTf = $expectedFolderName . '/' . substr($r['template_file'], strlen($oldFolderName . '/'));
                        $stmtUpd = $pdo->prepare("UPDATE event_roles SET template_file = ? WHERE id = ?");
                        $stmtUpd->execute([$newTf, $r['id']]);
                        $summary['roles_updated']++;
                    }
                }
            }
        }

        // 2. Reconcile all roles for this event against expected folder structure
        $stmtRoles = $pdo->prepare("SELECT id, template_file FROM event_roles WHERE event_id = ?");
        $stmtRoles->execute([$eventId]);
        $roles = $stmtRoles->fetchAll();

        foreach ($roles as $role) {
            $tf = $role['template_file'];
            if (empty($tf)) continue;

            if (strpos($tf, '/') !== false) {
                list($currentFolder, $filename) = explode('/', $tf, 2);
                if ($currentFolder !== $expectedFolderName) {
                    $srcDir = $tplBaseDir . $currentFolder . '/';
                    $srcFile = $srcDir . $filename;
                    $destFile = $expectedDir . $filename;

                    if (!is_dir($expectedDir)) {
                        @mkdir($expectedDir, 0777, true);
                    }

                    $finalFilename = $filename;
                    if (file_exists($srcFile)) {
                        if (file_exists($destFile)) {
                            if (md5_file($srcFile) === md5_file($destFile)) {
                                @unlink($srcFile);
                            } else {
                                $finalFilename = getUniqueFilename($expectedDir, $filename);
                                @rename($srcFile, $expectedDir . $finalFilename);
                                $summary['files_moved']++;
                            }
                        } else {
                            if (@rename($srcFile, $destFile)) {
                                $summary['files_moved']++;
                            }
                        }
                    }

                    // Clean up src directory if empty
                    if (is_dir($srcDir)) {
                        $rem = array_diff(@scandir($srcDir) ?: [], ['.', '..']);
                        if (empty($rem)) {
                            if (@rmdir($srcDir)) {
                                $summary['directories_removed']++;
                            }
                        }
                    }

                    // Update DB record
                    $newTemplateFile = $expectedFolderName . '/' . $finalFilename;
                    if ($newTemplateFile !== $tf) {
                        $stmtUpd = $pdo->prepare("UPDATE event_roles SET template_file = ? WHERE id = ?");
                        $stmtUpd->execute([$newTemplateFile, $role['id']]);
                        $summary['roles_updated']++;
                    }
                }
            }
        }

        // 3. Known legacy aliases check (e.g. Devs_Dummy_Event -> DCW_Dummy_Testing_Event)
        $legacyAliases = [
            'DCW_Dummy_Testing_Event' => ['Devs_Dummy_Event', 'Devs_Dummy', 'DCW_Dummy']
        ];
        if (isset($legacyAliases[$expectedFolderName])) {
            foreach ($legacyAliases[$expectedFolderName] as $legacyFolder) {
                $legacyDir = $tplBaseDir . $legacyFolder . '/';
                if (is_dir($legacyDir)) {
                    if (!is_dir($expectedDir)) {
                        @mkdir($expectedDir, 0777, true);
                    }
                    $items = @scandir($legacyDir) ?: [];
                    foreach ($items as $item) {
                        if ($item === '.' || $item === '..') continue;
                        $src = $legacyDir . $item;
                        if (is_file($src)) {
                            $destFilename = file_exists($expectedDir . $item) ? getUniqueFilename($expectedDir, $item) : $item;
                            if (@rename($src, $expectedDir . $destFilename)) {
                                $summary['files_moved']++;
                                // Update any roles still referencing this legacy path
                                $stmtUpd = $pdo->prepare("UPDATE event_roles SET template_file = ? WHERE event_id = ? AND template_file = ?");
                                $stmtUpd->execute([$expectedFolderName . '/' . $destFilename, $eventId, $legacyFolder . '/' . $item]);
                            }
                        }
                    }
                    $rem = array_diff(@scandir($legacyDir) ?: [], ['.', '..']);
                    if (empty($rem)) {
                        if (@rmdir($legacyDir)) {
                            $summary['directories_removed']++;
                        }
                    }
                }
            }
        }

        return $summary;
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
            $stmtLog = $pdo->prepare("INSERT INTO email_logs (certificate_id, recipient_email, status, trigger_type, error_message) VALUES (?, ?, 'Success', 'notification', NULL)");
            $stmtLog->execute([$certId, $recipientEmail]);

            // Update event_participants.notification_sent status
            $stmtUpdate = $pdo->prepare("UPDATE event_participants SET notification_sent = 1 WHERE certificate_id = ?");
            $stmtUpdate->execute([$certId]);

            return ['success' => true, 'message' => 'Notification email sent successfully.'];
        } catch (\Exception $e) {
            $errorMsg = $mail->ErrorInfo ?: $e->getMessage();
            
            // Log failure to email_logs
            $stmtLog = $pdo->prepare("INSERT INTO email_logs (certificate_id, recipient_email, status, trigger_type, error_message) VALUES (?, ?, 'Failed', 'notification', ?)");
            $stmtLog->execute([$certId, $recipientEmail, $errorMsg]);

            return ['success' => false, 'message' => 'Mail dispatch failed: ' . $errorMsg];
        }
    }
}

if (!function_exists('adminPortalBaseUrl')) {
    /**
     * Builds the public base URL of the portal (works from the /admin directory too).
     *
     * @return string e.g. https://example.org/certs
     */
    function adminPortalBaseUrl() {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
        $baseDir = str_replace('\\', '/', dirname($_SERVER['PHP_SELF']));
        // Strip the /admin segment so links point at the portal root.
        $portalDir = preg_replace('/\/admin(\/|$)/', '/', $baseDir);
        $portalDir = rtrim($portalDir, '/');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $protocol . $host . $portalDir;
    }
}

if (!function_exists('sendAdminResetEmail')) {
    /**
     * Emails a password-reset link to an admin user.
     *
     * @param string $recipientEmail
     * @param string $username
     * @param string $resetUrl   Fully-qualified reset link (includes the raw token).
     * @return array{success: bool, message: string}
     */
    function sendAdminResetEmail($recipientEmail, $username, $resetUrl, $pdo = null) {
        if ($pdo === null) {
            global $pdo;
        }
        $portalUrl = adminPortalBaseUrl();
        $orgName = defined('ORG_NAME') ? ORG_NAME : 'Deoband Community Wikimedia';
        $orgUrl = defined('ORG_URL_HOME') ? ORG_URL_HOME : 'https://dcwwiki.org/';
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

            $mail->setFrom($_ENV['SMTP_USER'], $orgName);
            $mail->addAddress($recipientEmail, $username);
            $mail->isHTML(true);
            $mail->Subject = __('email.password-reset.subject', ['org' => $orgName]);

            $mail->Body = '
            <!DOCTYPE html>
            <html lang="' . htmlspecialchars(i18n_get_lang(), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" dir="' . i18n_get_dir() . '">
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
                        <img src="' . htmlspecialchars($logoUrl) . '" alt="' . htmlspecialchars($orgName) . ' Logo">
                    </div>
                    <div class="email-body">
                        <h1>' . htmlspecialchars(__('email.password-reset.heading')) . '</h1>
                        <p>' . htmlspecialchars(__('email.password-reset.body', ['username' => $username, 'org' => $orgName])) . '</p>
                        <p>' . htmlspecialchars(__('email.password-reset.instructions', ['minutes' => 60])) . '</p>
                        <div style="text-align: center; margin: 32px 0;">
                            <a href="' . htmlspecialchars($resetUrl) . '" target="_blank" class="btn-portal">' . htmlspecialchars(__('email.password-reset.btn-reset')) . '</a>
                        </div>
                        <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 32px 0;">
                        <p style="font-size: 13px; color: #64748b; margin: 0;">' . htmlspecialchars(__('email.password-reset.disclaimer')) . '</p>
                    </div>
                    <div class="email-footer">
                        ' . __('email.common.footer.copyright', ['year' => date('Y'), 'org' => '<a href="' . htmlspecialchars($orgUrl) . '">' . htmlspecialchars($orgName) . '</a>']) . '
                    </div>
                </div>
            </body>
            </html>';

            $mail->AltBody = "Hello {$username},\n\nReset your DCW Admin password using this link (valid for 60 minutes):\n{$resetUrl}\n\nIf you did not request this, ignore this email.";

            $mail->send();

            // Log success to email_logs
            if (isset($pdo) && $pdo instanceof PDO) {
                try {
                    $stmtLog = $pdo->prepare("INSERT INTO email_logs (certificate_id, recipient_email, status, trigger_type, error_message) VALUES (NULL, ?, 'Success', 'password_reset', NULL)");
                    $stmtLog->execute([$recipientEmail]);
                } catch (\Exception $e) {}
            }

            return ['success' => true, 'message' => 'Reset email sent successfully.'];
        } catch (\Exception $e) {
            $errorMsg = $mail->ErrorInfo ?: $e->getMessage();

            // Log failure to email_logs
            if (isset($pdo) && $pdo instanceof PDO) {
                try {
                    $stmtLog = $pdo->prepare("INSERT INTO email_logs (certificate_id, recipient_email, status, trigger_type, error_message) VALUES (NULL, ?, 'Failed', 'password_reset', ?)");
                    $stmtLog->execute([$recipientEmail, $errorMsg]);
                } catch (\Exception $ignored) {}
            }

            return ['success' => false, 'message' => 'Mail dispatch failed: ' . $errorMsg];
        }
    }
}

