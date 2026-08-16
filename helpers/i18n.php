<?php
/**
 * DCW Internationalization (i18n) Core Engine
 * 
 * Provides zero-database, JSON-driven translation support for the DCW Certificate Portal.
 * Handles automatic language detection, session caching, and secure fallback mechanisms.
 * 
 * @author Zaid (Creator & Architecture)
 * @version 1.0.0
 * @link https://github.com/Deoband-Community-Wikimedia/dcw-certificate-portal
 *
 * Implements MediaWiki-standard JSON translation format compatible with
 * Translatewiki.net. Provides multi-tier language detection, 30-day cookie
 * persistence, session storage, silent English fallback, and XSS-safe output.
 *
 * Usage:
 *   __('key')                         → Translated string
 *   __('key', ['name' => 'Zaid'])     → Translated string with {name} replaced
 *   __e('key')                        → HTML-escaped translated string
 *   i18n_lang_switcher()              → Renders the 🌐 language switcher dropdown
 *
 * @package DCW\i18n
 * @see     https://www.mediawiki.org/wiki/Localisation
 */

// ─────────────────────────────────────────────────────────────────────────────
// Configuration
// ─────────────────────────────────────────────────────────────────────────────

/** Default language when nothing else can be detected. */
define('I18N_DEFAULT_LANG', 'en');

/** Cookie name for persisted language preference (30 days). */
define('I18N_COOKIE_NAME', 'dcw_lang');

/** Cookie lifetime in seconds (30 days). */
define('I18N_COOKIE_TTL', 30 * 24 * 60 * 60);

/** Absolute path to the i18n/ directory containing *.json bundles. */
define('I18N_DIR', dirname(__DIR__) . '/i18n');

/**
 * Supported languages: code → [ 'name', 'dir' ]
 * Add new languages here as translation bundles are contributed.
 */
$GLOBALS['dcw_supported_languages'] = [
    'en' => ['name' => 'English',  'dir' => 'ltr'],
    'es' => ['name' => 'Español',  'dir' => 'ltr'],
];

// ─────────────────────────────────────────────────────────────────────────────
// In-memory cache (populated once per request per language)
// ─────────────────────────────────────────────────────────────────────────────
/** In-memory resolved language cache (one value per request). */
$GLOBALS['dcw_i18n_lang_resolved'] = null;

/** In-memory bundle cache (populated once per request per language). */
$GLOBALS['dcw_i18n_cache'] = [];

// ─────────────────────────────────────────────────────────────────────────────
// Internal helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Validate a language code against the supported-language whitelist.
 * Only codes matching /^[a-z]{2,3}(-[a-z0-9]+)?$/ and present in the
 * supported list are accepted, preventing path-traversal attacks.
 *
 * @param  string $code Raw language code candidate.
 * @return bool
 */
function i18n_is_supported(string $code): bool
{
    if (!preg_match('/^[a-z]{2,3}(-[a-z0-9]+)?$/', $code)) {
        return false;
    }
    return array_key_exists($code, $GLOBALS['dcw_supported_languages']);
}

/**
 * Load and cache a language bundle from its JSON file.
 * Falls back to an empty array on missing or malformed files.
 *
 * @param  string $lang Validated language code.
 * @return array<string, string>
 */
function i18n_load(string $lang): array
{
    if (isset($GLOBALS['dcw_i18n_cache'][$lang])) {
        return $GLOBALS['dcw_i18n_cache'][$lang];
    }

    $file = I18N_DIR . '/' . $lang . '.json';

    if (!is_file($file)) {
        $GLOBALS['dcw_i18n_cache'][$lang] = [];
        return [];
    }

    $raw = file_get_contents($file);
    if ($raw === false) {
        $GLOBALS['dcw_i18n_cache'][$lang] = [];
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        error_log("DCW i18n: failed to decode {$file}");
        $GLOBALS['dcw_i18n_cache'][$lang] = [];
        return [];
    }

    // Strip MediaWiki meta-key (@metadata) if present
    unset($decoded['@metadata']);

    $GLOBALS['dcw_i18n_cache'][$lang] = $decoded;
    return $decoded;
}

// ─────────────────────────────────────────────────────────────────────────────
// Public API
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Detect and return the active language for this request.
 *
 * Priority chain:
 *   1. ?lang=XX  query-string override (sets session + 30-day cookie)
 *   2. $_SESSION['lang']
 *   3. $_COOKIE['dcw_lang']
 *   4. Browser Accept-Language header (first matching supported language)
 *   5. I18N_DEFAULT_LANG ('en')
 *
 * @return string Validated language code.
 */
function i18n_get_lang(): string
{
    // Return cached result so setcookie() / session writes only happen once per request.
    if ($GLOBALS['dcw_i18n_lang_resolved'] !== null) {
        return $GLOBALS['dcw_i18n_lang_resolved'];
    }

    // 1. Query-string override
    if (!empty($_GET['lang'])) {
        $candidate = strtolower(trim($_GET['lang']));
        if (i18n_is_supported($candidate)) {
            $_SESSION['lang'] = $candidate;
            setcookie(
                I18N_COOKIE_NAME,
                $candidate,
                [
                    'expires'  => time() + I18N_COOKIE_TTL,
                    'path'     => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                ]
            );
            $GLOBALS['dcw_i18n_lang_resolved'] = $candidate;
            return $candidate;
        }
    }

    // 2. Session
    if (!empty($_SESSION['lang']) && i18n_is_supported($_SESSION['lang'])) {
        $GLOBALS['dcw_i18n_lang_resolved'] = $_SESSION['lang'];
        return $_SESSION['lang'];
    }

    // 3. Cookie
    if (!empty($_COOKIE[I18N_COOKIE_NAME]) && i18n_is_supported($_COOKIE[I18N_COOKIE_NAME])) {
        $_SESSION['lang'] = $_COOKIE[I18N_COOKIE_NAME];
        $GLOBALS['dcw_i18n_lang_resolved'] = $_COOKIE[I18N_COOKIE_NAME];
        return $_COOKIE[I18N_COOKIE_NAME];
    }

    // 4. Browser Accept-Language header
    if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $accepted = preg_split('/\s*,\s*/', strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE']));
        foreach ($accepted as $tag) {
            $code = preg_replace('/;.*$/', '', $tag);
            $code = preg_replace('/-.*$/', '', $code);
            $code = trim($code);
            if (i18n_is_supported($code)) {
                $GLOBALS['dcw_i18n_lang_resolved'] = $code;
                return $code;
            }
        }
    }

    // 5. Default
    $GLOBALS['dcw_i18n_lang_resolved'] = I18N_DEFAULT_LANG;
    return I18N_DEFAULT_LANG;
}

/**
 * Reset the resolved language cache.
 * Used in automated tests to simulate a fresh request between test cases.
 */
function i18n_reset_lang_cache(): void
{
    $GLOBALS['dcw_i18n_lang_resolved'] = null;
}

/**
 * Return the text direction ('ltr' or 'rtl') for the active language.
 *
 * @return string 'ltr' or 'rtl'
 */
function i18n_get_dir(): string
{
    $lang = i18n_get_lang();
    return $GLOBALS['dcw_supported_languages'][$lang]['dir'] ?? 'ltr';
}

/**
 * Translate a message key, substituting named {placeholders}.
 *
 * Lookup order:
 *   1. Active language bundle (e.g. es.json)
 *   2. English bundle (en.json) — silent fallback
 *   3. The raw key itself — last-resort fallback (never breaks the page)
 *
 * @param  string               $key    Message key (e.g. 'page.claim.title').
 * @param  array<string,string> $params Placeholder replacements.
 * @return string
 */
function __(string $key, array $params = []): string
{
    $lang  = i18n_get_lang();
    $bundle = i18n_load($lang);

    // Fallback to English if key is missing in the active language
    if (!isset($bundle[$key]) && $lang !== I18N_DEFAULT_LANG) {
        $bundle = i18n_load(I18N_DEFAULT_LANG);
    }

    $message = $bundle[$key] ?? $key;

    // Substitute {placeholder} tokens
    if (!empty($params)) {
        foreach ($params as $placeholder => $value) {
            $message = str_replace('{' . $placeholder . '}', $value, $message);
        }
    }

    return $message;
}

/**
 * Translate and HTML-escape a message key (safe for output inside HTML).
 *
 * Use this instead of __() whenever the value is rendered directly in HTML
 * attributes or element content that might contain user-supplied params.
 *
 * @param  string               $key    Message key.
 * @param  array<string,string> $params Placeholder replacements (NOT escaped before substitution;
 *                                      they are HTML values — escape them before passing if needed).
 * @return string HTML-safe translated string.
 */
function __e(string $key, array $params = []): string
{
    return htmlspecialchars(__($key, $params), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Return the list of supported languages with their metadata.
 *
 * @return array<string, array{name: string, dir: string}>
 */
function i18n_get_supported_languages(): array
{
    return $GLOBALS['dcw_supported_languages'];
}

/**
 * Render the 🌐 language switcher dropdown for navigation headers.
 *
 * Outputs a semantic <div> with accessible ARIA roles. The current page URL
 * is preserved when switching language via ?lang=XX.
 *
 * @return void (echos HTML directly)
 */
function i18n_lang_switcher(): void
{
    $currentLang = i18n_get_lang();
    $languages   = i18n_get_supported_languages();
    $currentName = $languages[$currentLang]['name'] ?? 'Language';

    // Build base URL preserving existing query params (minus 'lang')
    $params = $_GET;
    unset($params['lang']);
    $baseQuery = http_build_query($params);
    $baseUrl   = strtok($_SERVER['REQUEST_URI'] ?? '', '?');

    echo '<div class="lang-switcher" role="navigation" aria-label="Language selector">';
    echo '<button class="lang-switcher-btn" '
        . 'aria-haspopup="true" aria-expanded="false" '
        . 'onclick="this.parentElement.classList.toggle(\'open\')" '
        . 'type="button">';
    echo '🌐 ' . htmlspecialchars($currentName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    echo ' <span class="lang-switcher-caret" aria-hidden="true">▾</span>';
    echo '</button>';
    echo '<ul class="lang-switcher-menu" role="menu">';

    foreach ($languages as $code => $meta) {
        $url = $baseUrl . '?lang=' . urlencode($code);
        if ($baseQuery) {
            $url .= '&' . $baseQuery;
        }
        $active = ($code === $currentLang) ? ' class="active" aria-current="true"' : '';
        echo '<li role="none">';
        echo '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" '
            . 'role="menuitem"' . $active . '>';
        echo htmlspecialchars($meta['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        echo '</a>';
        echo '</li>';
    }

    echo '</ul>';
    echo '</div>';
}

/**
 * Inline CSS for the language switcher dropdown.
 * Include once in the <head> of any page using i18n_lang_switcher().
 *
 * @return void
 */
function i18n_lang_switcher_css(): void
{
    echo <<<'CSS'
<style>
/* ── Language Switcher ────────────────────────────────────── */
.lang-switcher {
    position: relative;
    display: inline-block;
    font-family: inherit;
}
.lang-switcher-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 6px 12px;
    font-size: 13px;
    font-weight: 500;
    color: #475569;
    cursor: pointer;
    transition: background 0.2s ease, color 0.2s ease;
    white-space: nowrap;
}
.lang-switcher-btn:hover,
.lang-switcher.open .lang-switcher-btn {
    background: #e2e8f0;
    color: #1e293b;
}
.lang-switcher-caret {
    font-size: 10px;
    transition: transform 0.2s ease;
}
.lang-switcher.open .lang-switcher-caret {
    transform: rotate(180deg);
}
.lang-switcher-menu {
    display: none;
    position: absolute;
    right: 0;
    top: calc(100% + 6px);
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    list-style: none;
    margin: 0;
    padding: 6px;
    min-width: 130px;
    z-index: 200;
}
.lang-switcher.open .lang-switcher-menu {
    display: block;
}
.lang-switcher-menu li a {
    display: block;
    padding: 8px 12px;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 500;
    color: #475569;
    text-decoration: none;
    transition: background 0.15s ease, color 0.15s ease;
}
.lang-switcher-menu li a:hover {
    background: #f1f5f9;
    color: #1e293b;
}
.lang-switcher-menu li a.active {
    background: #eff6ff;
    color: #106b9a;
    font-weight: 600;
}
/* Close dropdown when clicking outside */
</style>
<script>
document.addEventListener('click', function(e) {
    document.querySelectorAll('.lang-switcher.open').forEach(function(el) {
        if (!el.contains(e.target)) el.classList.remove('open');
    });
});
</script>
CSS;
}
