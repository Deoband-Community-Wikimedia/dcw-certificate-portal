<?php
/**
 * Automated test suite for Event Categories & Custom Verification (Issue #59).
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../helpers/i18n.php';

$passes = 0;
$fails = 0;

function assertTest(bool $condition, string $desc): void {
    global $passes, $fails;
    if ($condition) {
        echo "  [PASS] $desc\n";
        $passes++;
    } else {
        echo "  [FAIL] $desc\n";
        $fails++;
    }
}

echo "=== Running Event Categories & Verification Text Unit Tests ===\n\n";

// Test 1: event_categories() canonical list
echo "Test 1: Canonical Event Categories...\n";
$categories = event_categories();
assertTest(is_array($categories), 'event_categories() returns array');
assertTest(in_array('Conference', $categories, true), 'Categories include Conference');
assertTest(in_array('Workshop', $categories, true), 'Categories include Workshop');
assertTest(in_array('Photography Competition', $categories, true), 'Categories include Photography Competition');
assertTest(in_array('Editathon', $categories, true), 'Categories include Editathon');
assertTest(in_array('Internship', $categories, true), 'Categories include Internship');
assertTest(in_array('Learning Course', $categories, true), 'Categories include Learning Course');
assertTest(in_array('Testing Event', $categories, true), 'Categories include Testing Event');
assertTest(in_array('Other', $categories, true), 'Categories include Other');

// Test 2: Category dropdown bucketing logic
echo "\nTest 2: Dropdown Category Bucketing...\n";
$mockEvents = [
    ['id' => 1, 'name' => 'Annual Conference', 'category' => 'Conference'],
    ['id' => 2, 'name' => 'Spring Internship', 'category' => 'Internship'],
    ['id' => 3, 'name' => 'Wiki Editathon', 'category' => 'Editathon'],
    ['id' => 4, 'name' => 'General Meetup', 'category' => null],
];

$eventsByCategory = [];
$hasCategorisedEvents = false;
foreach ($mockEvents as $e) {
    if (!empty($e['category'])) {
        $hasCategorisedEvents = true;
        $cat = $e['category'];
    } else {
        $cat = 'Other Events';
    }
    $eventsByCategory[$cat][] = $e;
}

assertTest($hasCategorisedEvents === true, 'Categorised events detected correctly');
assertTest(isset($eventsByCategory['Conference']), 'Conference bucket exists');
assertTest(isset($eventsByCategory['Internship']), 'Internship bucket exists');
assertTest(isset($eventsByCategory['Other Events']), 'Uncategorised landed in Other Events');
assertTest(count($eventsByCategory['Conference']) === 1, 'Conference has 1 event');

// Test 3: Custom verification placeholders
echo "\nTest 3: Verification Text Placeholder Expansion...\n";
$customTemplate = "This certifies that {name} completed the {category} '{event}' on {completion_date}.";
$replaced = str_replace(
    ['{name}', '{event}', '{category}', '{issue_date}', '{date}', '{completion_date}'],
    ['Zaid Sayyed', 'Advanced MediaWiki Dev', 'Learning Course', '2026-08-22', '2026-08-22', '2026-08-20'],
    $customTemplate
);
assertTest(strpos($replaced, 'Zaid Sayyed') !== false, '{name} replaced correctly');
assertTest(strpos($replaced, 'Learning Course') !== false, '{category} replaced correctly');
assertTest(strpos($replaced, 'Advanced MediaWiki Dev') !== false, '{event} replaced correctly');
assertTest(strpos($replaced, '2026-08-20') !== false, '{completion_date} replaced correctly');

// Test 4: i18n Translation Keys for Event Categories
echo "\nTest 4: i18n Key Verification...\n";
$en = json_decode(file_get_contents(__DIR__ . '/../i18n/en.json'), true);
$es = json_decode(file_get_contents(__DIR__ . '/../i18n/es.json'), true);

$requiredKeys = [
    'admin.event-form.label.category',
    'admin.event-form.option.uncategorised',
    'admin.event-form.hint.category',
    'admin.event-form.label.completion-date',
    'admin.event-form.hint.completion-date',
    'category.conference',
    'category.workshop',
    'category.photography-competition',
    'category.editathon',
    'category.internship',
    'category.learning-course',
    'category.testing-event',
    'category.other'
];

foreach ($requiredKeys as $k) {
    assertTest(isset($en[$k]) && $en[$k] !== '', "en.json defines '$k'");
    assertTest(isset($es[$k]) && $es[$k] !== '', "es.json defines '$k'");
}

// Test 5: event_category_label() helper
echo "\nTest 5: event_category_label() Helper...\n";
$_SESSION['lang'] = 'en';
i18n_reset_lang_cache();
assertTest(event_category_label('Workshop') === 'Workshop', "English label for 'Workshop' is 'Workshop'");
assertTest(event_category_label('Photography Competition') === 'Photography Competition', "English label for 'Photography Competition' is 'Photography Competition'");

$_SESSION['lang'] = 'es';
i18n_reset_lang_cache();
assertTest(event_category_label('Workshop') === 'Taller', "Spanish label for 'Workshop' is 'Taller'");
assertTest(event_category_label('Conference') === 'Conferencia', "Spanish label for 'Conference' is 'Conferencia'");
assertTest(event_category_label('Photography Competition') === 'Concurso de Fotografía', "Spanish label for 'Photography Competition' is 'Concurso de Fotografía'");
assertTest(event_category_label('Unknown Custom Category') === 'Unknown Custom Category', "Fallback for unknown category returns raw string");

// Reset language back
unset($_SESSION['lang']);
i18n_reset_lang_cache();

echo "\n=======================================================\n";
echo "SUMMARY: PASS: $passes | FAIL: $fails\n";
echo "=======================================================\n";

if ($fails > 0) {
    exit(1);
}
