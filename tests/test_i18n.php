<?php
/**
 * DCW Certificate Portal — i18n Test Suite
 *
 * Tests language detection, session/cookie persistence, silent English
 * fallback, parameter interpolation, and JSON bundle integrity.
 *
 * Run with: php tests/test_i18n.php
 *
 * @package DCW\Tests
 */

// Bootstrap (no DB needed for i18n tests)
define('K_PATH_FONTS', __DIR__ . '/../vendor/tecnickcom/tcpdf/fonts/');
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

require_once __DIR__ . '/../helpers/i18n.php';

// ─────────────────────────────────────────────────────────────────────────────
// Minimal test harness
// ─────────────────────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function assert_equals(string $testName, $expected, $actual): void
{
    global $passed, $failed;
    if ($expected === $actual) {
        echo "[PASS] {$testName}\n";
        $passed++;
    } else {
        echo "[FAIL] {$testName}\n";
        echo "       Expected: " . var_export($expected, true) . "\n";
        echo "       Got:      " . var_export($actual, true) . "\n";
        $failed++;
    }
}

function assert_true(string $testName, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] {$testName}\n";
        $passed++;
    } else {
        echo "[FAIL] {$testName}\n";
        $failed++;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Helper: reset global state between tests
// ─────────────────────────────────────────────────────────────────────────────

function reset_lang_state(): void
{
    // Clear bundle cache, lang cache, and superglobals so each test starts clean
    $GLOBALS['dcw_i18n_cache'] = [];
    i18n_reset_lang_cache();
    unset($_SESSION['lang'], $_COOKIE['dcw_lang'], $_GET['lang']);
}

// Start a fake session for testing
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "==============================\n";
echo "DCW i18n Test Suite\n";
echo "==============================\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// Test 1: Default language is 'en' when nothing is set
// ─────────────────────────────────────────────────────────────────────────────
reset_lang_state();
unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);

assert_equals(
    'Default language is en when nothing set',
    'en',
    i18n_get_lang()
);

// ─────────────────────────────────────────────────────────────────────────────
// Test 2: ?lang= query-string override works and sets session
// ─────────────────────────────────────────────────────────────────────────────
reset_lang_state();
$_GET['lang'] = 'es';

assert_equals(
    '?lang=es query-string sets active language',
    'es',
    i18n_get_lang()
);
assert_equals(
    '?lang=es also writes to $_SESSION[lang]',
    'es',
    $_SESSION['lang'] ?? null
);

// ─────────────────────────────────────────────────────────────────────────────
// Test 3: ?lang= with unsupported code falls back to default
// ─────────────────────────────────────────────────────────────────────────────
reset_lang_state();
$_GET['lang'] = 'zz';

assert_equals(
    '?lang=zz (unsupported) falls back to en',
    'en',
    i18n_get_lang()
);

// ─────────────────────────────────────────────────────────────────────────────
// Test 4: ?lang= with path traversal attempt is rejected
// ─────────────────────────────────────────────────────────────────────────────
reset_lang_state();
$_GET['lang'] = '../../../etc/passwd';

assert_equals(
    'Path traversal in ?lang= rejected, falls back to en',
    'en',
    i18n_get_lang()
);

// ─────────────────────────────────────────────────────────────────────────────
// Test 5: Session language is respected
// ─────────────────────────────────────────────────────────────────────────────
reset_lang_state();
$_SESSION['lang'] = 'es';

assert_equals(
    'Session lang=es is respected',
    'es',
    i18n_get_lang()
);

// ─────────────────────────────────────────────────────────────────────────────
// Test 6: Browser Accept-Language header detection
// ─────────────────────────────────────────────────────────────────────────────
reset_lang_state();
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'es-ES,es;q=0.9,en;q=0.8';

assert_equals(
    'Browser Accept-Language header es-ES detected as es',
    'es',
    i18n_get_lang()
);
unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);

// ─────────────────────────────────────────────────────────────────────────────
// Test 7: __() returns English string when lang is en
// ─────────────────────────────────────────────────────────────────────────────
reset_lang_state();
$_SESSION['lang'] = 'en';
$GLOBALS['dcw_i18n_cache'] = [];

$result = __('page.claim.title');
assert_equals(
    "__('page.claim.title') returns English string",
    'Claim Certificate',
    $result
);

// ─────────────────────────────────────────────────────────────────────────────
// Test 8: __() returns Spanish string when lang is es
// ─────────────────────────────────────────────────────────────────────────────
reset_lang_state();
$_SESSION['lang'] = 'es';
$GLOBALS['dcw_i18n_cache'] = [];

$result = __('page.claim.title');
assert_equals(
    "__('page.claim.title') returns Spanish string",
    'Obtener certificado',
    $result
);

// ─────────────────────────────────────────────────────────────────────────────
// Test 9: __() interpolates {placeholder} params correctly
// ─────────────────────────────────────────────────────────────────────────────
reset_lang_state();
$_SESSION['lang'] = 'en';
$GLOBALS['dcw_i18n_cache'] = [];

$result = __('footer.copyright', ['year' => '2025']);
assert_true(
    '__() replaces {year} placeholder correctly',
    strpos($result, '2025') !== false
);

// ─────────────────────────────────────────────────────────────────────────────
// Test 10: __() silently falls back to English for missing key in Spanish
// ─────────────────────────────────────────────────────────────────────────────
reset_lang_state();
$_SESSION['lang'] = 'es';
$GLOBALS['dcw_i18n_cache'] = [];

// Temporarily inject a fake missing key
$GLOBALS['dcw_i18n_cache']['es'] = [];  // empty Spanish bundle
$GLOBALS['dcw_i18n_cache']['en'] = ['test.fallback.key' => 'Fallback Value'];

$result = __('test.fallback.key');
assert_equals(
    '__() silently falls back to English when key missing in Spanish',
    'Fallback Value',
    $result
);

// ─────────────────────────────────────────────────────────────────────────────
// Test 11: __() returns raw key as last resort (no crash)
// ─────────────────────────────────────────────────────────────────────────────
reset_lang_state();
$_SESSION['lang'] = 'en';
$GLOBALS['dcw_i18n_cache'] = ['en' => [], 'es' => []];

$result = __('nonexistent.key.xyz');
assert_equals(
    '__() returns raw key when not found in any bundle',
    'nonexistent.key.xyz',
    $result
);

// ─────────────────────────────────────────────────────────────────────────────
// Test 12: __e() HTML-escapes output
// ─────────────────────────────────────────────────────────────────────────────
reset_lang_state();
$_SESSION['lang'] = 'en';
$GLOBALS['dcw_i18n_cache'] = ['en' => ['test.xss' => '<script>alert(1)</script>']];

$result = __e('test.xss');
assert_equals(
    '__e() HTML-escapes dangerous characters',
    '&lt;script&gt;alert(1)&lt;/script&gt;',
    $result
);

// ─────────────────────────────────────────────────────────────────────────────
// Test 13: en.json and es.json have matching keys
// ─────────────────────────────────────────────────────────────────────────────
reset_lang_state();
$GLOBALS['dcw_i18n_cache'] = [];

$enBundle = json_decode(file_get_contents(I18N_DIR . '/en.json'), true);
$esBundle = json_decode(file_get_contents(I18N_DIR . '/es.json'), true);
unset($enBundle['@metadata'], $esBundle['@metadata']);

$enKeys = array_keys($enBundle);
$esKeys = array_keys($esBundle);
$missingInEs = array_diff($enKeys, $esKeys);
$extraInEs   = array_diff($esKeys, $enKeys);

assert_true(
    'es.json has no missing keys compared to en.json (count: ' . count($missingInEs) . ' missing)',
    empty($missingInEs)
);
assert_true(
    'es.json has no extra keys not in en.json (count: ' . count($extraInEs) . ' extra)',
    empty($extraInEs)
);

// ─────────────────────────────────────────────────────────────────────────────
// Test 14: qqq.json documents every key in en.json
// ─────────────────────────────────────────────────────────────────────────────
$qqqBundle = json_decode(file_get_contents(I18N_DIR . '/qqq.json'), true);
unset($qqqBundle['@metadata']);

$missingInQqq = array_diff($enKeys, array_keys($qqqBundle));

assert_true(
    'qqq.json documents every key in en.json (undocumented: ' . count($missingInQqq) . ')',
    empty($missingInQqq)
);

// ─────────────────────────────────────────────────────────────────────────────
// Test 15: All JSON files parse without errors
// ─────────────────────────────────────────────────────────────────────────────
foreach (['en', 'es', 'qqq'] as $lang) {
    $raw = file_get_contents(I18N_DIR . "/{$lang}.json");
    json_decode($raw, true);
    assert_equals(
        "i18n/{$lang}.json is valid JSON",
        JSON_ERROR_NONE,
        json_last_error()
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// Test 16: i18n_get_dir() returns ltr for en and es
// ─────────────────────────────────────────────────────────────────────────────
reset_lang_state();
$_SESSION['lang'] = 'en';
assert_equals('i18n_get_dir() returns ltr for en', 'ltr', i18n_get_dir());

reset_lang_state();
$_SESSION['lang'] = 'es';
assert_equals('i18n_get_dir() returns ltr for es', 'ltr', i18n_get_dir());

// ─────────────────────────────────────────────────────────────────────────────
// Results
// ─────────────────────────────────────────────────────────────────────────────
echo "\n==============================\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "==============================\n";

exit($failed > 0 ? 1 : 0);
