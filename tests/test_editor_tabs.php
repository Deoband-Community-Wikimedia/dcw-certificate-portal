<?php
/**
 * Test Suite: Visual Editor Inspector Tabs & Sticky Viewport Layout
 * 
 * Verifies i18n keys for inspector tabs and collapsible color panel.
 */

require_once __DIR__ . '/../helpers/i18n.php';

$en = json_decode(file_get_contents(__DIR__ . '/../i18n/en.json'), true);
$es = json_decode(file_get_contents(__DIR__ . '/../i18n/es.json'), true);
$qqq = json_decode(file_get_contents(__DIR__ . '/../i18n/qqq.json'), true);

$passed = 0;
$failed = 0;

function assert_test($condition, $name) {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] $name\n";
        $passed++;
    } else {
        echo "  [FAIL] $name\n";
        $failed++;
    }
}

echo "=== Running Visual Editor Inspector Tabs Unit Tests ===\n\n";

$keys = [
    'admin.editor.tab.properties',
    'admin.editor.tab.layers',
    'admin.editor.color.custom-toggle',
    'admin.editor.color.save-current',
    'admin.editor.color.brand-hint',
    'admin.editor.color.none-yet',
];

echo "Test 1: i18n Key Verification...\n";
foreach ($keys as $key) {
    assert_test(isset($en[$key]), "en.json defines '$key'");
    assert_test(isset($es[$key]), "es.json defines '$key'");
    assert_test(isset($qqq[$key]), "qqq.json documents '$key'");
}

echo "\n=======================================================\n";
echo "SUMMARY: PASS: $passed | FAIL: $failed\n";
echo "=======================================================\n";

if ($failed > 0) {
    exit(1);
}
