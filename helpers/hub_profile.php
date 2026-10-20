<?php
/**
 * Affiliate hub profile helpers (issue #160).
 *
 * Handles the cover banner upload and public-profile fields
 * (bio, meta_wiki_url, telegram_url, twitter_url) that affiliate
 * admins can set for their community hub at /c/<slug>.
 *
 * Functions are idempotent and safe to call on every web request.
 * Kept dependency-free (no session, no database) so they are fully
 * unit-testable in isolation.
 */

/** Maximum size accepted for a cover banner, in bytes (5 MB). */
if (!defined('HUB_BANNER_MAX_BYTES')) {
    define('HUB_BANNER_MAX_BYTES', 5 * 1024 * 1024);
}

/** Maximum pixel dimension (width or height) of a cover banner. */
if (!defined('HUB_BANNER_MAX_DIM')) {
    define('HUB_BANNER_MAX_DIM', 6000);
}

/** Maximum length of the bio/mission statement. */
if (!defined('HUB_BIO_MAX_LEN')) {
    define('HUB_BIO_MAX_LEN', 500);
}

if (!function_exists('organizations_ensure_hub_schema')) {
    /**
     * Idempotently adds the hub-profile columns to organizations.
     *
     * Runs at most once per PDO connection (static cache). Wraps all
     * ALTER statements in a try-catch so a permission error on a read-only
     * replica is logged but never crashes the page.
     *
     * Compatible with MySQL 5.7+ and SQLite (used in tests).
     */
    function organizations_ensure_hub_schema(PDO $pdo): void {
        static $checked = [];
        $key = spl_object_hash($pdo);
        if (isset($checked[$key])) {
            return;
        }

        $columns = [
            'cover_banner_path' => 'VARCHAR(255) NULL',
            'bio'               => 'TEXT NULL',
            'meta_wiki_url'     => 'VARCHAR(255) NULL',
            'telegram_url'      => 'VARCHAR(255) NULL',
            'twitter_url'       => 'VARCHAR(255) NULL',
        ];

        try {
            foreach ($columns as $name => $definition) {
                try {
                    $pdo->query("SELECT $name FROM organizations LIMIT 0")->closeCursor();
                } catch (PDOException $e) {
                    $pdo->exec("ALTER TABLE organizations ADD COLUMN $name $definition");
                }
            }
            $checked[$key] = true;
        } catch (\Exception $e) {
            error_log('organizations_ensure_hub_schema failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('hub_validate_profile')) {
    /**
     * Validate and normalise the hub-profile form fields.
     *
     * @param array $input  Raw $_POST fields: bio, meta_wiki_url,
     *                      telegram_url, twitter_url.
     * @return array{0: array<string,string>, 1: array<string,mixed>}
     *         [errors, clean].  errors maps a field key to an i18n
     *         error-code suffix; clean holds column-name => value pairs.
     */
    function hub_validate_profile(array $input): array {
        $errors = [];
        $clean  = [];

        // Bio: optional, 500 chars max.
        $bio = trim((string)($input['hub_bio'] ?? ''));
        if ($bio !== '' && mb_strlen($bio) > HUB_BIO_MAX_LEN) {
            $errors['hub_bio'] = 'bio-too-long';
        }
        $clean['bio'] = $bio === '' ? null : $bio;

        // URL fields – each must be an absolute http/https URL when set.
        $urlFields = [
            'hub_meta_wiki_url' => 'meta_wiki_url',
            'hub_telegram_url'  => 'telegram_url',
            'hub_twitter_url'   => 'twitter_url',
        ];
        foreach ($urlFields as $formKey => $column) {
            $url = trim((string)($input[$formKey] ?? ''));
            if ($url === '') {
                $clean[$column] = null;
                continue;
            }
            // Reuse org_is_http_url if available (helpers/organization.php),
            // otherwise fall back to a simple validation.
            $valid = function_exists('org_is_http_url')
                ? org_is_http_url($url)
                : (mb_strlen($url) <= 300 && filter_var($url, FILTER_VALIDATE_URL) !== false
                   && in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true));
            if (!$valid) {
                $errors[$formKey] = 'url-invalid';
                $clean[$column]   = null;
            } else {
                $clean[$column] = $url;
            }
        }

        return [$errors, $clean];
    }
}

if (!function_exists('hub_check_banner_upload')) {
    /**
     * Validate an uploaded cover banner by inspecting its actual contents.
     *
     * Accepts JPEG, PNG, and WebP only (same policy as org logos).
     * SVG is refused: it may embed script.
     *
     * @param array $file  One entry from $_FILES.
     * @return array{0: ?string, 1: ?string}  [extension, errorCode];
     *                                         exactly one is null.
     */
    function hub_check_banner_upload(array $file): array {
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return [null, 'banner-too-large'];
        }
        if ($err !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
            return [null, 'banner-upload-failed'];
        }
        if (($file['size'] ?? 0) > HUB_BANNER_MAX_BYTES) {
            return [null, 'banner-too-large'];
        }

        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            return [null, 'banner-invalid'];
        }

        $extByType = [
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_WEBP => 'webp',
        ];
        if (!isset($extByType[$info[2]])) {
            return [null, 'banner-invalid'];
        }

        if ($info[0] < 1 || $info[1] < 1
            || $info[0] > HUB_BANNER_MAX_DIM
            || $info[1] > HUB_BANNER_MAX_DIM) {
            return [null, 'banner-invalid'];
        }

        return [$extByType[$info[2]], null];
    }
}

if (!function_exists('hub_banner_dir')) {
    /** Site-root-relative directory for one organization's banner. */
    function hub_banner_dir(int $orgId): string {
        return 'uploads/tenants/' . $orgId . '/';
    }
}

if (!function_exists('hub_store_banner')) {
    /**
     * Move a validated banner into the organization's upload folder and
     * return the site-root-relative path to store in organizations.cover_banner_path.
     *
     * Uses a random file name so stale CDN/browser caches are busted
     * automatically and one org cannot overwrite another's file.
     */
    function hub_store_banner(int $orgId, string $tmpPath, string $ext, string $rootDir): ?string {
        $relDir = hub_banner_dir($orgId);
        $absDir = rtrim($rootDir, '/') . '/' . $relDir;

        if (!is_dir($absDir) && !mkdir($absDir, 0755, true) && !is_dir($absDir)) {
            return null;
        }

        $filename = 'banner-' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($tmpPath, $absDir . $filename)) {
            // CLI tests use rename() instead of move_uploaded_file().
            if (PHP_SAPI !== 'cli' || !@rename($tmpPath, $absDir . $filename)) {
                return null;
            }
        }
        return $relDir . $filename;
    }
}

if (!function_exists('hub_delete_banner')) {
    /**
     * Delete a previously stored banner only when it lives inside the
     * organization's own upload folder.  Path-traversal attempts and
     * cross-tenant paths are silently refused.
     */
    function hub_delete_banner(int $orgId, ?string $bannerPath, string $rootDir): bool {
        if ($bannerPath === null || $bannerPath === '') {
            return false;
        }
        $pattern = '#^' . preg_quote(hub_banner_dir($orgId), '#') . '[A-Za-z0-9._-]+$#';
        if (!preg_match($pattern, $bannerPath) || strpos($bannerPath, '..') !== false) {
            return false;
        }
        $abs = rtrim($rootDir, '/') . '/' . $bannerPath;
        return is_file($abs) && @unlink($abs);
    }
}
