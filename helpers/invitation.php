<?php
/**
 * DCW Certificate Portal - Multi-Tenant Community Onboarding & Invitation Helpers
 *
 * Provides cryptographic invitation tokens, tenant provisioning, slug auto-generation,
 * collision disambiguation, reserved slug guards, and welcome invitation emails.
 */

if (file_exists(__DIR__ . '/i18n.php')) {
    require_once __DIR__ . '/i18n.php';
}

if (!defined('RESERVED_SLUGS')) {
    define('RESERVED_SLUGS', [
        'admin', 'api', 'verify', 'download', 'assets', 'uploads', 'c', 'e',
        'login', 'logout', 'dashboard', 'success', 'public', 'static', 'i18n',
        'helpers', 'tests', 'scripts', 'vendor', 'index', 'home', 'settings',
        'preview', 'auth', 'mail', 'notification'
    ]);
}

if (!function_exists('invitation_ensure_schema')) {
    /**
     * Ensures admin_invitations table exists and events table has the slug column.
     * Driver-aware: supports both MySQL and SQLite for seamless production and testing.
     *
     * @param PDO $pdo
     * @return void
     */
    function invitation_ensure_schema(PDO $pdo): void {
        static $checkedPdos = [];
        $pdoHash = spl_object_hash($pdo);
        if (isset($checkedPdos[$pdoHash])) {
            return;
        }

        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS admin_invitations (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        organization_id INTEGER NOT NULL,
                        email TEXT NOT NULL,
                        username TEXT NOT NULL,
                        invite_token_hash TEXT NOT NULL,
                        expires_at DATETIME NOT NULL,
                        is_accepted INTEGER NOT NULL DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    );
                ");

                // Check if slug column exists on events in SQLite
                $cols = $pdo->query("PRAGMA table_info(events)")->fetchAll(PDO::FETCH_ASSOC);
                $hasSlug = false;
                foreach ($cols as $col) {
                    if (($col['name'] ?? '') === 'slug') {
                        $hasSlug = true;
                        break;
                    }
                }
                if (!$hasSlug) {
                    $pdo->exec("ALTER TABLE events ADD COLUMN slug TEXT NULL");
                }
            } else {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS admin_invitations (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        organization_id INT NOT NULL,
                        email VARCHAR(255) NOT NULL,
                        username VARCHAR(100) NOT NULL,
                        invite_token_hash VARCHAR(255) NOT NULL,
                        expires_at DATETIME NOT NULL,
                        is_accepted TINYINT(1) NOT NULL DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_inv_org (organization_id),
                        INDEX idx_inv_email (email),
                        INDEX idx_inv_token (invite_token_hash),
                        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");

                // Check if slug column exists on events in MySQL
                $stmt = $pdo->query("SHOW COLUMNS FROM events LIKE 'slug'");
                if (!$stmt->fetch()) {
                    $pdo->exec("ALTER TABLE events ADD COLUMN slug VARCHAR(100) NULL AFTER name, ADD INDEX idx_events_slug (slug)");
                }
            }
            $checkedPdos[$pdoHash] = true;
        } catch (\Exception $ignored) {
            // Defensive fail-safe
        }
    }
}

if (!function_exists('is_reserved_slug')) {
    /**
     * Checks if a slug conflicts with reserved portal routes or keywords.
     *
     * @param string $slug
     * @return bool
     */
    function is_reserved_slug(string $slug): bool {
        return in_array(strtolower(trim($slug)), RESERVED_SLUGS, true);
    }
}

if (!function_exists('slugify')) {
    /**
     * Converts arbitrary string into a URL-friendly lowercase slug.
     *
     * @param string $text
     * @return string
     */
    function slugify(string $text): string {
        $accents = [
            'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a',
            'È'=>'E', 'É'=>'E', 'Ê'=>'E', 'Ë'=>'E', 'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e',
            'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i',
            'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O', 'Õ'=>'O', 'Ö'=>'O', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o',
            'Ù'=>'U', 'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ü'=>'u',
            'Ñ'=>'N', 'ñ'=>'n', 'Ç'=>'C', 'ç'=>'c', 'Ý'=>'Y', 'ý'=>'y', 'ÿ'=>'y'
        ];
        $text = strtr($text, $accents);
        if (function_exists('iconv')) {
            $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($trans !== false && $trans !== '') {
                $text = $trans;
            }
        }
        $text = preg_replace('/[\'"`]/', '', $text);
        $text = preg_replace('/[^a-zA-Z0-9]+/', '-', $text);
        $text = strtolower(trim($text, '-'));
        return $text !== '' ? $text : 'community';
    }
}

if (!function_exists('generate_unique_org_slug')) {
    /**
     * Generates a collision-free organization slug, automatically disambiguating
     * if collisions exist (e.g. wmsp -> wmsp-2) and avoiding reserved routes.
     *
     * @param PDO $pdo
     * @param string $name
     * @param int|null $excludeOrgId
     * @return string
     */
    function generate_unique_org_slug(PDO $pdo, string $name, ?int $excludeOrgId = null): string {
        invitation_ensure_schema($pdo);
        $base = slugify($name);
        if (is_reserved_slug($base)) {
            $base .= '-org';
        }

        $slug = $base;
        $counter = 2;

        while (true) {
            if (is_reserved_slug($slug)) {
                $slug = $base . '-' . $counter;
                $counter++;
                continue;
            }

            $sql = "SELECT COUNT(*) FROM organizations WHERE slug = ?";
            $params = [$slug];
            if ($excludeOrgId !== null && $excludeOrgId > 0) {
                $sql .= " AND id != ?";
                $params[] = $excludeOrgId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->fetchColumn() == 0) {
                return $slug;
            }

            $slug = $base . '-' . $counter;
            $counter++;
        }
    }
}

if (!function_exists('generate_unique_org_cert_code')) {
    /**
     * Generates a collision-free uppercase certificate code prefix (e.g. WMSP).
     * Derives from uppercase initials or clean alphanumeric truncation.
     *
     * @param PDO $pdo
     * @param string $name
     * @param int|null $excludeOrgId
     * @return string
     */
    function generate_unique_org_cert_code(PDO $pdo, string $name, ?int $excludeOrgId = null): string {
        invitation_ensure_schema($pdo);
        $words = preg_split('/[\s_-]+/', trim($name));
        $initials = '';
        foreach ($words as $w) {
            $clean = preg_replace('/[^a-zA-Z0-9]/', '', $w);
            if ($clean !== '') {
                $initials .= strtoupper($clean[0]);
            }
        }

        if (strlen($initials) < 2) {
            $cleanName = preg_replace('/[^a-zA-Z0-9]/', '', strtoupper($name));
            $initials = substr($cleanName, 0, 4);
            if (strlen($initials) < 2) {
                $initials = 'ORG';
            }
        }

        $base = substr($initials, 0, 8);
        $code = $base;
        $counter = 2;

        while (true) {
            $sql = "SELECT COUNT(*) FROM organizations WHERE cert_code = ?";
            $params = [$code];
            if ($excludeOrgId !== null && $excludeOrgId > 0) {
                $sql .= " AND id != ?";
                $params[] = $excludeOrgId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->fetchColumn() == 0) {
                return $code;
            }

            $code = $base . $counter;
            $counter++;
        }
    }
}

if (!function_exists('generate_unique_event_slug')) {
    /**
     * Generates a collision-free event slug, automatically disambiguating if needed.
     *
     * @param PDO $pdo
     * @param string $name
     * @param int|null $excludeEventId
     * @return string
     */
    function generate_unique_event_slug(PDO $pdo, string $name, ?int $excludeEventId = null): string {
        invitation_ensure_schema($pdo);
        $base = slugify($name);
        if (is_reserved_slug($base)) {
            $base .= '-event';
        }

        $slug = $base;
        $counter = 2;

        while (true) {
            if (is_reserved_slug($slug)) {
                $slug = $base . '-' . $counter;
                $counter++;
                continue;
            }

            $sql = "SELECT COUNT(*) FROM events WHERE slug = ?";
            $params = [$slug];
            if ($excludeEventId !== null && $excludeEventId > 0) {
                $sql .= " AND id != ?";
                $params[] = $excludeEventId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->fetchColumn() == 0) {
                return $slug;
            }

            $slug = $base . '-' . $counter;
            $counter++;
        }
    }
}

if (!function_exists('create_admin_invitation')) {
    /**
     * Creates a secure cryptographic invitation record for an organization lead.
     *
     * @param PDO $pdo
     * @param int $orgId
     * @param string $email
     * @param string $username
     * @param int $ttlHours Default 48 hours
     * @return array{success: bool, raw_token?: string, token_hash?: string, invite_id?: int, email?: string, username?: string, expires_at?: string, error?: string}
     */
    function create_admin_invitation(PDO $pdo, int $orgId, string $email, string $username = '', int $ttlHours = 48): array {
        invitation_ensure_schema($pdo);

        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'invalid-email'];
        }

        // Auto-generate clean username from email if not provided
        if (trim($username) === '') {
            $parts = explode('@', $email);
            $baseUser = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($parts[0]));
            if ($baseUser === '') {
                $baseUser = 'lead';
            }
            $username = $baseUser;
            $uCounter = 2;
            while (true) {
                $st = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE username = ?");
                $st->execute([$username]);
                if ($st->fetchColumn() == 0) {
                    break;
                }
                $username = $baseUser . $uCounter;
                $uCounter++;
            }
        }

        $rawToken = bin2hex(random_bytes(32)); // 64 hex characters (256-bit entropy)
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + ($ttlHours * 3600));

        $stmt = $pdo->prepare("
            INSERT INTO admin_invitations (organization_id, email, username, invite_token_hash, expires_at, is_accepted)
            VALUES (?, ?, ?, ?, ?, 0)
        ");
        $stmt->execute([$orgId, $email, $username, $tokenHash, $expiresAt]);
        $inviteId = (int)$pdo->lastInsertId();

        return [
            'success'    => true,
            'invite_id'  => $inviteId,
            'raw_token'  => $rawToken,
            'token_hash' => $tokenHash,
            'email'      => $email,
            'username'   => $username,
            'expires_at' => $expiresAt
        ];
    }
}

if (!function_exists('find_invitation_by_token')) {
    /**
     * Finds and validates an invitation by raw token.
     * Checks unexpired and unaccepted status.
     *
     * @param PDO $pdo
     * @param string $rawToken
     * @return array|null
     */
    function find_invitation_by_token(PDO $pdo, string $rawToken): ?array {
        invitation_ensure_schema($pdo);
        if (trim($rawToken) === '') {
            return null;
        }

        $tokenHash = hash('sha256', trim($rawToken));
        $now = date('Y-m-d H:i:s');

        $stmt = $pdo->prepare("
            SELECT ai.*, o.name as org_name, o.slug as org_slug, o.cert_code as org_code, o.logo_path as org_logo, o.is_active as org_active
            FROM admin_invitations ai
            JOIN organizations o ON ai.organization_id = o.id
            WHERE ai.invite_token_hash = ? AND ai.is_accepted = 0 AND ai.expires_at > ?
        ");
        $stmt->execute([$tokenHash, $now]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

if (!function_exists('accept_admin_invitation')) {
    /**
     * Completes partner lead setup: creates org_admin in admin_users and marks invite accepted.
     *
     * @param PDO $pdo
     * @param int $inviteId
     * @param string $password
     * @param string $displayName Optional username override
     * @return array{success: bool, user_id?: int, username?: string, organization_id?: int, error?: string}
     */
    function accept_admin_invitation(PDO $pdo, int $inviteId, string $password, string $displayName = ''): array {
        invitation_ensure_schema($pdo);

        if (strlen($password) < 6) {
            return ['success' => false, 'error' => 'short-password'];
        }

        $stmt = $pdo->prepare("SELECT * FROM admin_invitations WHERE id = ?");
        $stmt->execute([$inviteId]);
        $invite = $stmt->fetch();

        if (!$invite || (int)$invite['is_accepted'] === 1 || strtotime($invite['expires_at']) < time()) {
            return ['success' => false, 'error' => 'invalid-or-expired'];
        }

        $orgId = (int)$invite['organization_id'];
        $chosenUser = trim($displayName) !== '' ? trim($displayName) : $invite['username'];
        $chosenUser = preg_replace('/[^a-zA-Z0-9_-]/', '', $chosenUser);
        if ($chosenUser === '') {
            $chosenUser = $invite['username'];
        }

        // Ensure username is unique in admin_users
        $finalUsername = $chosenUser;
        $counter = 2;
        while (true) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE username = ?");
            $chk->execute([$finalUsername]);
            if ($chk->fetchColumn() == 0) {
                break;
            }
            $finalUsername = $chosenUser . $counter;
            $counter++;
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // Insert new org_admin
        $insert = $pdo->prepare("
            INSERT INTO admin_users (organization_id, username, password_hash, email, role)
            VALUES (?, ?, ?, ?, 'org_admin')
        ");
        $insert->execute([$orgId, $finalUsername, $passwordHash, $invite['email']]);
        $newUserId = (int)$pdo->lastInsertId();

        // Mark invite accepted
        $mark = $pdo->prepare("UPDATE admin_invitations SET is_accepted = 1 WHERE id = ?");
        $mark->execute([$inviteId]);

        return [
            'success'         => true,
            'user_id'         => $newUserId,
            'username'        => $finalUsername,
            'organization_id' => $orgId
        ];
    }
}

if (!function_exists('resend_admin_invitation')) {
    /**
     * Resends an invitation for an organization, renewing token and dispatching email.
     *
     * @param PDO $pdo
     * @param int $orgId
     * @param string|null $recipientEmail
     * @return array{success: bool, error?: string}
     */
    function resend_admin_invitation(PDO $pdo, int $orgId, ?string $recipientEmail = null): array {
        invitation_ensure_schema($pdo);

        $org = get_organization($pdo, $orgId);
        if (!$org) {
            return ['success' => false, 'error' => 'org-not-found'];
        }

        $email = trim((string)$recipientEmail);
        if ($email === '') {
            // Check for previous unaccepted invite email
            $stmt = $pdo->prepare("SELECT email, username FROM admin_invitations WHERE organization_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$orgId]);
            $prev = $stmt->fetch();
            if ($prev && !empty($prev['email'])) {
                $email = $prev['email'];
            } elseif (!empty($org['contact_email'])) {
                $email = $org['contact_email'];
            }
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'invalid-email'];
        }

        $invite = create_admin_invitation($pdo, $orgId, $email);
        if (!$invite['success']) {
            return $invite;
        }

        $baseUrl = adminPortalBaseUrl();
        $inviteUrl = $baseUrl . '/admin/accept_invite.php?token=' . urlencode($invite['raw_token']);

        $mailRes = sendAdminInvitationEmail($pdo, $email, $org['name'], $inviteUrl, $invite['username']);
        return [
            'success'   => true,
            'invite'    => $invite,
            'mail_sent' => $mailRes['success'],
            'message'   => $mailRes['message'] ?? ''
        ];
    }
}

if (!function_exists('sendAdminInvitationEmail')) {
    /**
     * Dispatches a branded invitation email to the newly provisioned community lead.
     *
     * @param PDO $pdo
     * @param string $recipientEmail
     * @param string $orgName
     * @param string $inviteUrl
     * @param string $username
     * @return array{success: bool, message: string}
     */
    function sendAdminInvitationEmail($pdo, $recipientEmail, $orgName, $inviteUrl, $username = '') {
        $trans = function(string $key, array $params = [], string $fallback = '') {
            if (function_exists('__')) {
                $res = __($key, $params);
                if ($res !== $key) {
                    return $res;
                }
            }
            if (!empty($params)) {
                foreach ($params as $k => $v) {
                    $fallback = str_replace('{' . $k . '}', (string)$v, $fallback);
                }
            }
            return $fallback ?: $key;
        };

        $portalUrl = adminPortalBaseUrl();
        $portalName = defined('ORG_NAME') && ORG_NAME !== '' ? ORG_NAME : $trans('site.name', [], 'Deoband Community Wikimedia');
        $orgHome = defined('ORG_URL_HOME') ? ORG_URL_HOME : 'https://dcwwiki.org/';
        $logoUrl = $portalUrl . '/assets/DCW_logo.png';

        if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            $composerAutoload = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($composerAutoload)) {
                require_once $composerAutoload;
            }
        }

        if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            return ['success' => false, 'message' => 'PHPMailer is not installed.'];
        }

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        try {
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';

            if (!empty($_ENV['SMTP_HOST'])) {
                $mail->isSMTP();
                $mail->Host = $_ENV['SMTP_HOST'];
                $mail->SMTPAuth = filter_var($_ENV['SMTP_AUTH'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $mail->Username = $_ENV['SMTP_USER'] ?? '';
                $mail->Password = $_ENV['SMTP_PASS'] ?? '';
                $mail->SMTPSecure = $_ENV['SMTP_SECURE'] ?? '';
                $mail->Port = (int)($_ENV['SMTP_PORT'] ?? 587);
                $mail->setFrom($_ENV['SMTP_USER'] ?? 'no-reply@dcwwiki.org', $portalName);
            } else {
                $mail->isMail();
                $mail->setFrom('no-reply@dcwwiki.org', $portalName);
            }

            $mail->addAddress($recipientEmail, $username ?: $orgName);
            $mail->isHTML(true);
            $mail->Subject = $trans('email.invitation.subject', ['org' => $orgName], "You're invited to manage {$orgName} on the Certificate Portal");

            $lang = function_exists('i18n_get_lang') ? htmlspecialchars(i18n_get_lang(), ENT_QUOTES | ENT_HTML5, 'UTF-8') : 'en';
            $dir = function_exists('i18n_get_dir') ? i18n_get_dir() : 'ltr';

            $mail->Body = '
            <!DOCTYPE html>
            <html lang="' . $lang . '" dir="' . $dir . '">
            <head>
                <meta charset="utf-8">
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f4f6f8; margin: 0; padding: 0; }
                    .email-wrapper { max-width: 600px; margin: 30px auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; }
                    .email-header { background: linear-gradient(135deg, #106b9a, #0c567a); padding: 32px 24px; text-align: center; color: white; }
                    .email-header img { height: 48px; width: auto; margin-bottom: 12px; }
                    .email-header h2 { margin: 0; font-size: 20px; font-weight: 600; letter-spacing: -0.01em; }
                    .email-body { padding: 36px 32px; color: #1e293b; line-height: 1.6; }
                    h1 { font-size: 22px; color: #0f172a; margin-top: 0; font-weight: 700; }
                    p { font-size: 15px; color: #475569; margin-bottom: 20px; }
                    .highlight-box { background: #f8fafc; border-left: 4px solid #106b9a; padding: 16px; border-radius: 4px; margin: 24px 0; }
                    .btn-action { display: inline-block; background-color: #106b9a; color: #ffffff !important; text-decoration: none; padding: 14px 28px; border-radius: 8px; font-size: 15px; font-weight: 600; text-align: center; }
                    .email-footer { background-color: #0f172a; padding: 24px; text-align: center; font-size: 12px; color: #94a3b8; }
                    .email-footer a { color: #38bdf8; text-decoration: none; }
                </style>
            </head>
            <body>
                <div class="email-wrapper">
                    <div class="email-header">
                        <img src="' . htmlspecialchars($logoUrl) . '" alt="' . htmlspecialchars($portalName) . ' Logo">
                        <h2>' . htmlspecialchars($portalName) . '</h2>
                    </div>
                    <div class="email-body">
                        <h1>' . htmlspecialchars($trans('email.invitation.heading', ['org' => $orgName], "Welcome to the Certificate Portal, {$orgName}")) . '</h1>
                        <p>' . htmlspecialchars($trans('email.invitation.body', ['org' => $orgName, 'portal' => $portalName], "You have been designated as an Administrator for {$orgName} on the {$portalName}.")) . '</p>
                        
                        <div class="highlight-box">
                            <strong>' . htmlspecialchars($trans('email.invitation.details-heading', [], "Your Invitation Details")) . '</strong><br>
                            &bull; ' . htmlspecialchars($trans('email.invitation.detail-org', [], "Organization")) . ': <strong>' . htmlspecialchars($orgName) . '</strong><br>
                            &bull; ' . htmlspecialchars($trans('email.invitation.detail-role', [], "Role")) . ': <strong>' . htmlspecialchars($trans('admin.users.role.org-admin', [], "Organization Admin")) . '</strong><br>
                            &bull; ' . htmlspecialchars($trans('email.invitation.detail-email', [], "Registered Email")) . ': ' . htmlspecialchars($recipientEmail) . '
                        </div>

                        <p>' . htmlspecialchars($trans('email.invitation.instructions', [], "Click the button below to set up your administrator account and choose your secure password:")) . '</p>

                        <div style="text-align: center; margin: 32px 0;">
                            <a href="' . htmlspecialchars($inviteUrl) . '" target="_blank" class="btn-action">' . htmlspecialchars($trans('email.invitation.btn-setup', [], "Accept Invitation & Set Password")) . '</a>
                        </div>

                        <p style="font-size: 13px; color: #64748b;">' . htmlspecialchars($trans('email.invitation.expiry-notice', ['hours' => 48], "This secure link will expire in 48 hours.")) . '</p>
                        <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 24px 0;">
                        <p style="font-size: 12px; color: #94a3b8; margin: 0;">' . htmlspecialchars($trans('email.invitation.fallback-link', [], "If the button does not work, copy and paste this link into your browser:")) . '<br><a href="' . htmlspecialchars($inviteUrl) . '" style="color: #0284c7; word-break: break-all;">' . htmlspecialchars($inviteUrl) . '</a></p>
                    </div>
                    <div class="email-footer">
                        ' . $trans('email.common.footer.copyright', ['year' => date('Y'), 'org' => '<a href="' . htmlspecialchars($orgHome) . '">' . htmlspecialchars($portalName) . '</a>'], "&copy; " . date('Y') . " " . htmlspecialchars($portalName)) . '
                    </div>
                </div>
            </body>
            </html>';

            $mail->AltBody = "Hello,\n\nYou have been invited to manage {$orgName} on the {$portalName}.\n\nComplete your account setup and choose your password using this link (valid for 48 hours):\n{$inviteUrl}\n\nIf you did not expect this invitation, you can safely ignore this email.";

            $mail->send();

            // Log to email_logs
            if (isset($pdo) && $pdo instanceof PDO) {
                try {
                    $logStmt = $pdo->prepare("
                        INSERT INTO email_logs (recipient_email, status, trigger_type, error_message)
                        VALUES (?, 'sent', 'invitation', NULL)
                    ");
                    $logStmt->execute([$recipientEmail]);
                } catch (\Exception $ignored) {}
            }

            return ['success' => true, 'message' => 'Invitation email sent successfully.'];
        } catch (\Exception $e) {
            if (isset($pdo) && $pdo instanceof PDO) {
                try {
                    $logStmt = $pdo->prepare("
                        INSERT INTO email_logs (recipient_email, status, trigger_type, error_message)
                        VALUES (?, 'failed', 'invitation', ?)
                    ");
                    $logStmt->execute([$recipientEmail, $e->getMessage()]);
                } catch (\Exception $ignored) {}
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
