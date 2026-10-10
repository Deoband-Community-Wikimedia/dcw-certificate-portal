<?php
/**
 * DCW Certificate Portal - Certificate Delivery OTP & Verification Logging Helpers (Issue #144)
 *
 * Implements email-based OTP verification for public certificate claiming,
 * abuse protection / rate limiting, and verification attempt auditing.
 */

if (!function_exists('otp_ensure_schema')) {
    /**
     * Ensures certificate_otps and verification_logs tables exist.
     * Driver-aware: supports both MySQL and SQLite.
     *
     * @param PDO $pdo
     * @return void
     */
    function otp_ensure_schema(PDO $pdo): void {
        static $checkedPdos = [];
        $pdoHash = spl_object_hash($pdo);
        if (isset($checkedPdos[$pdoHash])) {
            return;
        }

        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS certificate_otps (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        certificate_id TEXT NOT NULL,
                        recipient_email TEXT NOT NULL,
                        otp_hash TEXT NOT NULL,
                        expires_at DATETIME NOT NULL,
                        attempts INTEGER NOT NULL DEFAULT 0,
                        is_verified INTEGER NOT NULL DEFAULT 0,
                        ip_address TEXT NULL,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    );
                    CREATE TABLE IF NOT EXISTS verification_logs (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        certificate_id TEXT NULL,
                        ip_address TEXT NULL,
                        user_agent TEXT NULL,
                        status TEXT NOT NULL DEFAULT 'valid',
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    );
                ");
            } else {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS certificate_otps (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        certificate_id VARCHAR(50) NOT NULL,
                        recipient_email VARCHAR(255) NOT NULL,
                        otp_hash VARCHAR(255) NOT NULL,
                        expires_at DATETIME NOT NULL,
                        attempts INT NOT NULL DEFAULT 0,
                        is_verified TINYINT(1) NOT NULL DEFAULT 0,
                        ip_address VARCHAR(45) NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_cert_otps (certificate_id, recipient_email),
                        INDEX idx_expires (expires_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                    CREATE TABLE IF NOT EXISTS verification_logs (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        certificate_id VARCHAR(50) NULL,
                        ip_address VARCHAR(45) NULL,
                        user_agent VARCHAR(255) NULL,
                        status VARCHAR(20) NOT NULL DEFAULT 'valid',
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_verification_cert (certificate_id),
                        INDEX idx_verification_ip (ip_address, created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
            }
            $checkedPdos[$pdoHash] = true;
        } catch (\Exception $ignored) {
            // Keep fail-safe
        }
    }
}

if (!function_exists('otp_generate_code')) {
    /**
     * Generates a cryptographically secure numeric OTP code.
     *
     * @param int $digits
     * @return string
     */
    function otp_generate_code(int $digits = 6): string {
        $min = (int)pow(10, $digits - 1);
        $max = (int)(pow(10, $digits) - 1);
        return (string)random_int($min, $max);
    }
}

if (!function_exists('otp_hash_code')) {
    /**
     * Produces a secure hash of the OTP for storage and verification.
     *
     * @param string $code
     * @return string
     */
    function otp_hash_code(string $code): string {
        return hash('sha256', trim($code));
    }
}

if (!function_exists('otp_mask_email')) {
    /**
     * Masks an email address for safe public confirmation display.
     * Example: 'contact@wikimedia.org' -> 'c*****t@wikimedia.org'
     *
     * @param string $email
     * @return string
     */
    function otp_mask_email(string $email): string {
        $parts = explode('@', trim($email));
        if (count($parts) !== 2) {
            return '***@***';
        }
        $name = $parts[0];
        $domain = $parts[1];

        $len = strlen($name);
        if ($len <= 2) {
            $maskedName = substr($name, 0, 1) . '***';
        } else {
            $maskedName = $name[0] . str_repeat('*', min(5, $len - 2)) . $name[$len - 1];
        }

        return $maskedName . '@' . $domain;
    }
}

if (!function_exists('otp_client_ip')) {
    /**
     * Resolves the client IP address.
     *
     * @return string
     */
    function otp_client_ip(): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
    }
}

if (!function_exists('otp_is_rate_limited')) {
    /**
     * Rate limits OTP generation per IP (max 5 in 10 mins) and per email (max 3 in 10 mins)
     * to protect SMTP servers and mitigate brute-force attacks.
     *
     * @param PDO    $pdo
     * @param string $ip
     * @param string $email
     * @return array{limited: bool, reason: string}
     */
    function otp_is_rate_limited(PDO $pdo, string $ip, string $email): array {
        otp_ensure_schema($pdo);

        try {
            $tenMinutesAgo = date('Y-m-d H:i:s', time() - 600);

            // IP check
            $stmtIp = $pdo->prepare("
                SELECT COUNT(*) FROM certificate_otps
                WHERE ip_address = ? AND created_at >= ?
            ");
            $stmtIp->execute([$ip, $tenMinutesAgo]);
            if ((int)$stmtIp->fetchColumn() >= 5) {
                return ['limited' => true, 'reason' => 'ip_limit'];
            }

            // Email check
            $stmtEmail = $pdo->prepare("
                SELECT COUNT(*) FROM certificate_otps
                WHERE recipient_email = ? AND created_at >= ?
            ");
            $stmtEmail->execute([$email, $tenMinutesAgo]);
            if ((int)$stmtEmail->fetchColumn() >= 3) {
                return ['limited' => true, 'reason' => 'email_limit'];
            }
        } catch (\Exception $e) {
            // If table check fails, do not block users
        }

        return ['limited' => false, 'reason' => ''];
    }
}

if (!function_exists('otp_create_record')) {
    /**
     * Invalidates prior pending OTPs for the certificate and inserts a fresh OTP.
     *
     * @param PDO         $pdo
     * @param string      $certId
     * @param string      $email
     * @param string      $otpCode
     * @param string|null $ip
     * @param int         $expirySeconds Defaults to 600 (10 minutes)
     * @return bool
     */
    function otp_create_record(PDO $pdo, string $certId, string $email, string $otpCode, ?string $ip = null, int $expirySeconds = 600): bool {
        otp_ensure_schema($pdo);

        try {
            // Invalidate existing active OTPs for this certificate & email
            $stmtInv = $pdo->prepare("
                UPDATE certificate_otps
                SET is_verified = 2
                WHERE certificate_id = ? AND recipient_email = ? AND is_verified = 0
            ");
            $stmtInv->execute([$certId, $email]);

            $now = date('Y-m-d H:i:s');
            $expiresAt = date('Y-m-d H:i:s', time() + $expirySeconds);
            $otpHash = otp_hash_code($otpCode);

            $stmtIns = $pdo->prepare("
                INSERT INTO certificate_otps (certificate_id, recipient_email, otp_hash, expires_at, attempts, is_verified, ip_address, created_at)
                VALUES (?, ?, ?, ?, 0, 0, ?, ?)
            ");
            return $stmtIns->execute([$certId, $email, $otpHash, $expiresAt, $ip, $now]);
        } catch (\Exception $e) {
            return false;
        }
    }
}

if (!function_exists('otp_verify_code')) {
    /**
     * Verifies a submitted OTP code against active records.
     * Handles expiry and limits failed attempts to 5 max.
     *
     * @param PDO    $pdo
     * @param string $certId
     * @param string $email
     * @param string $submittedCode
     * @return array{success: bool, error: string, attempts_remaining: int}
     */
    function otp_verify_code(PDO $pdo, string $certId, string $email, string $submittedCode): array {
        otp_ensure_schema($pdo);

        try {
            $now = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare("
                SELECT id, otp_hash, attempts, expires_at
                FROM certificate_otps
                WHERE certificate_id = ? AND recipient_email = ? AND is_verified = 0
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$certId, $email]);
            $record = $stmt->fetch();

            if (!$record) {
                return ['success' => false, 'error' => 'not_found', 'attempts_remaining' => 0];
            }

            // Expiry check
            if (strtotime($record['expires_at']) < time()) {
                $pdo->prepare("UPDATE certificate_otps SET is_verified = 2 WHERE id = ?")->execute([$record['id']]);
                return ['success' => false, 'error' => 'expired', 'attempts_remaining' => 0];
            }

            // Lockout check (max 5 attempts)
            if ((int)$record['attempts'] >= 5) {
                $pdo->prepare("UPDATE certificate_otps SET is_verified = 2 WHERE id = ?")->execute([$record['id']]);
                return ['success' => false, 'error' => 'too_many_attempts', 'attempts_remaining' => 0];
            }

            $submittedHash = otp_hash_code($submittedCode);
            if (hash_equals($record['otp_hash'], $submittedHash)) {
                // Successful verification
                $pdo->prepare("UPDATE certificate_otps SET is_verified = 1 WHERE id = ?")->execute([$record['id']]);
                return ['success' => true, 'error' => '', 'attempts_remaining' => 5];
            } else {
                // Increment failed attempts
                $newAttempts = (int)$record['attempts'] + 1;
                $pdo->prepare("UPDATE certificate_otps SET attempts = ? WHERE id = ?")->execute([$newAttempts, $record['id']]);
                $remaining = max(0, 5 - $newAttempts);
                return [
                    'success' => false,
                    'error' => ($remaining === 0) ? 'too_many_attempts' : 'invalid_code',
                    'attempts_remaining' => $remaining
                ];
            }
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'db_error', 'attempts_remaining' => 0];
        }
    }
}

if (!function_exists('sendCertificateOtpEmail')) {
    /**
     * Dispatches an OTP verification email to the participant.
     * Uses the event organization's branding and settings.
     *
     * @param PDO        $pdo
     * @param string     $certId
     * @param string     $recipientEmail
     * @param string     $fullName
     * @param string     $eventName
     * @param string     $otpCode
     * @param array|null $org
     * @return array{success: bool, message: string}
     */
    function sendCertificateOtpEmail(PDO $pdo, string $certId, string $recipientEmail, string $fullName, string $eventName, string $otpCode, ?array $org = null): array {
        if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
            return ['success' => false, 'message' => 'PHPMailer is not installed.'];
        }

        $portalUrl = adminPortalBaseUrl();
        $orgName = !empty($org['name']) ? $org['name'] : (defined('ORG_NAME') && ORG_NAME !== '' ? ORG_NAME : __('site.name'));
        $logoUrl = !empty($org['logo_path']) ? $portalUrl . '/' . $org['logo_path'] : $portalUrl . '/assets/DCW_logo.png';

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        try {
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';
            $mail->isSMTP();
            $mail->Host = $_ENV['SMTP_HOST'] ?? 'localhost';
            $mail->SMTPAuth = filter_var($_ENV['SMTP_AUTH'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $mail->Username = $_ENV['SMTP_USER'] ?? '';
            $mail->Password = $_ENV['SMTP_PASS'] ?? '';
            $mail->SMTPSecure = $_ENV['SMTP_SECURE'] ?? '';
            $mail->Port = (int)($_ENV['SMTP_PORT'] ?? 587);

            $mail->setFrom($_ENV['SMTP_USER'] ?? 'no-reply@example.org', $orgName);
            $mail->addAddress($recipientEmail, $fullName);
            $mail->isHTML(true);
            $mail->Subject = __('email.otp.subject', ['code' => $otpCode, 'event' => $eventName]);

            $mail->Body = '
            <!DOCTYPE html>
            <html lang="' . htmlspecialchars(i18n_get_lang(), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" dir="' . i18n_get_dir() . '">
            <head>
                <meta charset="utf-8">
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f4f6f8; margin: 0; padding: 0; }
                    .email-wrapper { max-width: 580px; margin: 30px auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.03); }
                    .email-header { background-color: #106b9a; padding: 28px 24px; text-align: center; }
                    .email-header img { height: 48px; width: auto; }
                    .email-body { padding: 36px 30px; color: #1e293b; line-height: 1.6; }
                    h1 { font-size: 20px; color: #0f172a; margin-top: 0; font-weight: 700; }
                    p { font-size: 15px; color: #475569; margin-bottom: 20px; }
                    .otp-box { background: #f8fafc; border: 2px dashed #0284c7; border-radius: 10px; padding: 20px; text-align: center; margin: 28px 0; }
                    .otp-code { font-size: 32px; font-weight: 800; letter-spacing: 8px; color: #0369a1; font-family: monospace; }
                    .otp-expiry { font-size: 13px; color: #64748b; margin-top: 8px; }
                    .email-footer { background-color: #0f172a; padding: 20px; text-align: center; font-size: 12px; color: #94a3b8; }
                </style>
            </head>
            <body>
                <div class="email-wrapper">
                    <div class="email-header">
                        <img src="' . htmlspecialchars($logoUrl) . '" alt="' . htmlspecialchars($orgName) . ' Logo">
                    </div>
                    <div class="email-body">
                        <h1>' . htmlspecialchars(__('email.otp.heading', ['name' => $fullName])) . '</h1>
                        <p>' . htmlspecialchars(__('email.otp.body', ['event' => $eventName, 'org' => $orgName])) . '</p>
                        <div class="otp-box">
                            <div class="otp-code">' . htmlspecialchars($otpCode) . '</div>
                            <div class="otp-expiry">' . htmlspecialchars(__('email.otp.expiry-hint', ['minutes' => 10])) . '</div>
                        </div>
                        <p style="font-size: 13px; color: #64748b;">' . htmlspecialchars(__('email.otp.security-notice')) . '</p>
                    </div>
                    <div class="email-footer">
                        &copy; ' . date('Y') . ' ' . htmlspecialchars($orgName) . '. All rights reserved.
                    </div>
                </div>
            </body>
            </html>
            ';

            $mail->send();

            // Log successful delivery to email_logs
            try {
                $stmtLog = $pdo->prepare("
                    INSERT INTO email_logs (certificate_id, recipient_email, status, trigger_type, error_message)
                    VALUES (?, ?, 'Sent', 'otp', NULL)
                ");
                $stmtLog->execute([$certId, $recipientEmail]);
            } catch (\Exception $ignored) {}

            return ['success' => true, 'message' => 'Verification code sent.'];
        } catch (\Exception $e) {
            $err = $mail->ErrorInfo ?: $e->getMessage();
            try {
                $stmtLog = $pdo->prepare("
                    INSERT INTO email_logs (certificate_id, recipient_email, status, trigger_type, error_message)
                    VALUES (?, ?, 'Failed', 'otp', ?)
                ");
                $stmtLog->execute([$certId, $recipientEmail, $err]);
            } catch (\Exception $ignored) {}

            return ['success' => false, 'message' => 'Failed to send verification email: ' . $err];
        }
    }
}

if (!function_exists('verification_log_attempt')) {
    /**
     * Logs public certificate verification lookups for auditing and monitoring.
     *
     * @param PDO         $pdo
     * @param string|null $certId
     * @param string|null $ip
     * @param string|null $userAgent
     * @param string      $status 'valid', 'not_found', or 'rate_limited'
     * @return void
     */
    function verification_log_attempt(PDO $pdo, ?string $certId, ?string $ip = null, ?string $userAgent = null, string $status = 'valid'): void {
        otp_ensure_schema($pdo);

        try {
            $ip = $ip ?: otp_client_ip();
            $userAgent = substr((string)($userAgent ?: ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);

            $now = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare("
                INSERT INTO verification_logs (certificate_id, ip_address, user_agent, status, created_at)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$certId ?: null, $ip, $userAgent, $status, $now]);
        } catch (\Exception $ignored) {}
    }
}

if (!function_exists('verification_is_rate_limited')) {
    /**
     * Protects the public verification endpoint from traffic spikes and scrapers.
     * Limits an IP to 30 verification lookups per minute.
     *
     * @param PDO    $pdo
     * @param string $ip
     * @return bool
     */
    function verification_is_rate_limited(PDO $pdo, string $ip): bool {
        otp_ensure_schema($pdo);

        try {
            $oneMinuteAgo = date('Y-m-d H:i:s', time() - 60);
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM verification_logs
                WHERE ip_address = ? AND created_at >= ?
            ");
            $stmt->execute([$ip, $oneMinuteAgo]);
            return (int)$stmt->fetchColumn() >= 30;
        } catch (\Exception $e) {
            return false;
        }
    }
}

if (!function_exists('otp_authorize_claim')) {
    /**
     * Authorizes the current user session to access and download a certificate.
     *
     * @param string $certId
     * @param int    $ttl Seconds the authorization remains valid (default 3600 = 1 hour)
     * @return void
     */
    function otp_authorize_claim(string $certId, int $ttl = 3600): void {
        if (!isset($_SESSION['verified_claims']) || !is_array($_SESSION['verified_claims'])) {
            $_SESSION['verified_claims'] = [];
        }
        $_SESSION['verified_claims'][$certId] = time() + $ttl;
    }
}

if (!function_exists('otp_is_claim_authorized')) {
    /**
     * Checks if the current session is authorized to view or download a certificate.
     * Authorized if either an admin is logged in OR the participant has a valid OTP claim session.
     *
     * @param string $certId
     * @return bool
     */
    function otp_is_claim_authorized(string $certId): bool {
        // Authenticated administrators always have access
        if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
            return true;
        }

        // Participant OTP verified session check
        if (isset($_SESSION['verified_claims']) && is_array($_SESSION['verified_claims'])) {
            if (isset($_SESSION['verified_claims'][$certId])) {
                $expiresAt = (int)$_SESSION['verified_claims'][$certId];
                if ($expiresAt > time()) {
                    return true;
                }
                unset($_SESSION['verified_claims'][$certId]);
            }
        }

        return false;
    }
}

