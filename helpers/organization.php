<?php
/**
 * Organization profile helpers (#148, part of multi-tenant support #147).
 *
 * Pure validation / file-handling functions used by admin/organization_settings.php.
 * Kept in their own file (no session, no database) so they can be unit tested
 * without booting the admin panel.
 */

/** Social platforms an organization can list, in display order.
 *  The key is what gets stored in organizations.social_links (a JSON object). */
if (!defined('ORG_SOCIAL_PLATFORMS')) {
    define('ORG_SOCIAL_PLATFORMS', ['linkedin', 'x', 'facebook', 'instagram', 'youtube', 'github']);
}

/** Largest logo we accept, in bytes. */
if (!defined('ORG_LOGO_MAX_BYTES')) {
    define('ORG_LOGO_MAX_BYTES', 2 * 1024 * 1024);
}

if (!function_exists('org_decode_social_links')) {
    /**
     * Turn the stored organizations.social_links value into ['platform' => 'url'].
     * Tolerant of NULL, empty and malformed values (returns []), and drops any
     * platform we don't know about.
     */
    function org_decode_social_links(?string $raw): array {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        $links = [];
        foreach (ORG_SOCIAL_PLATFORMS as $platform) {
            if (isset($data[$platform]) && is_string($data[$platform]) && $data[$platform] !== '') {
                $links[$platform] = $data[$platform];
            }
        }
        return $links;
    }
}

if (!function_exists('org_is_http_url')) {
    /** True for an absolute http(s) URL. Rejects javascript:, data:, etc. */
    function org_is_http_url(string $url): bool {
        if (mb_strlen($url) > 300 || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        // filter_var lets quotes and angle brackets through in the path.
        // Refuse them so the value is safe even if a later page prints it
        // into an attribute without escaping.
        if (preg_match('/[\s"\'<>`\\\\]/', $url)) {
            return false;
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        return $scheme === 'http' || $scheme === 'https';
    }
}

if (!function_exists('org_validate_profile')) {
    /**
     * Validate and normalise the organization profile form.
     *
     * @param array $input Raw form fields: name, slug, cert_code, contact_email,
     *                     social_<platform>.
     * @return array{0: array<string,string>, 1: array<string,mixed>}
     *         [errors, clean]. `errors` maps a field name to an error code
     *         (the suffix of an admin.org-settings.error.* i18n key);
     *         `clean` holds the values to store, keyed by column name.
     */
    function org_validate_profile(array $input): array {
        $errors = [];
        $clean = [];

        $name = trim((string)($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            $errors['name'] = 'name-invalid';
        }
        $clean['name'] = $name;

        // Lowercase letters/digits separated by single hyphens, e.g. "wikimedia-sp".
        $slug = strtolower(trim((string)($input['slug'] ?? '')));
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) < 2 || strlen($slug) > 50) {
            $errors['slug'] = 'slug-invalid';
        }
        $clean['slug'] = $slug;

        // Short uppercase code that prefixes certificate IDs, e.g. "WMSP".
        $certCode = strtoupper(trim((string)($input['cert_code'] ?? '')));
        if (!preg_match('/^[A-Z0-9]{2,20}$/', $certCode)) {
            $errors['cert_code'] = 'cert-code-invalid';
        }
        $clean['cert_code'] = $certCode;

        $email = trim((string)($input['contact_email'] ?? ''));
        if ($email !== '' && (mb_strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $errors['contact_email'] = 'contact-email-invalid';
        }
        $clean['contact_email'] = $email === '' ? null : $email;

        $links = [];
        foreach (ORG_SOCIAL_PLATFORMS as $platform) {
            $url = trim((string)($input['social_' . $platform] ?? ''));
            if ($url === '') {
                continue;
            }
            if (!org_is_http_url($url)) {
                $errors['social_' . $platform] = 'social-url-invalid';
                continue;
            }
            $links[$platform] = $url;
        }
        $clean['social_links'] = $links
            ? json_encode($links, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;

        return [$errors, $clean];
    }
}

if (!function_exists('org_check_logo_upload')) {
    /**
     * Validate an uploaded logo by looking at the file's actual contents, not
     * its name or the browser-supplied type.
     *
     * Only PNG, JPEG and WebP are accepted. SVG is refused on purpose: it can
     * carry script and would be served straight from /uploads.
     *
     * @param array $file One entry of $_FILES.
     * @return array{0: ?string, 1: ?string} [extension, errorCode]; exactly one is null.
     */
    function org_check_logo_upload(array $file): array {
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return [null, 'logo-too-large'];
        }
        if ($err !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
            return [null, 'logo-upload-failed'];
        }
        if (($file['size'] ?? 0) > ORG_LOGO_MAX_BYTES) {
            return [null, 'logo-too-large'];
        }

        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            return [null, 'logo-invalid'];
        }

        $extByType = [
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_WEBP => 'webp',
        ];
        if (!isset($extByType[$info[2]])) {
            return [null, 'logo-invalid'];
        }

        // Guard against absurd decompression-bomb dimensions.
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > 4000 || $info[1] > 4000) {
            return [null, 'logo-invalid'];
        }

        return [$extByType[$info[2]], null];
    }
}

if (!function_exists('org_logo_dir')) {
    /** Site-root-relative directory that holds one organization's uploads. */
    function org_logo_dir(int $orgId): string {
        return 'uploads/tenants/' . $orgId . '/';
    }
}

if (!function_exists('org_store_logo')) {
    /**
     * Move an already-validated logo into the organization's own folder and
     * return the site-root-relative path to store in organizations.logo_path.
     * The name is random, so one organization can never overwrite another's
     * file and a replaced logo is never served from a stale cache.
     */
    function org_store_logo(int $orgId, string $tmpPath, string $ext, string $rootDir): ?string {
        $relDir = org_logo_dir($orgId);
        $absDir = rtrim($rootDir, '/') . '/' . $relDir;

        if (!is_dir($absDir) && !mkdir($absDir, 0755, true) && !is_dir($absDir)) {
            return null;
        }

        $filename = 'logo-' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($tmpPath, $absDir . $filename)) {
            // move_uploaded_file refuses anything that wasn't an HTTP upload;
            // fall back to rename() so CLI tests can exercise this too.
            if (PHP_SAPI !== 'cli' || !@rename($tmpPath, $absDir . $filename)) {
                return null;
            }
        }
        return $relDir . $filename;
    }
}

if (!function_exists('org_delete_logo')) {
    /**
     * Delete a previously stored logo, but only when it lives in this
     * organization's own upload folder. Anything else (the bundled
     * assets/DCW_logo.png, another organization's file, a path with ".." in
     * it) is left alone.
     */
    function org_delete_logo(int $orgId, ?string $logoPath, string $rootDir): bool {
        if ($logoPath === null || $logoPath === '') {
            return false;
        }
        $pattern = '#^' . preg_quote(org_logo_dir($orgId), '#') . '[A-Za-z0-9._-]+$#';
        if (!preg_match($pattern, $logoPath) || strpos($logoPath, '..') !== false) {
            return false;
        }
        $abs = rtrim($rootDir, '/') . '/' . $logoPath;
        return is_file($abs) && @unlink($abs);
    }
}
