<?php
/**
 * Automated test suite for 2-Step Cascading Dropdowns (Issue #113).
 */

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

echo "=== Running 2-Step Cascading Dropdown Unit Tests ===\n\n";

// Test 1: i18n Keys for Cascading Dropdowns
echo "Test 1: i18n Key Verification...\n";
$en = json_decode(file_get_contents(__DIR__ . '/../i18n/en.json'), true);
$es = json_decode(file_get_contents(__DIR__ . '/../i18n/es.json'), true);
$qqq = json_decode(file_get_contents(__DIR__ . '/../i18n/qqq.json'), true);

$requiredKeys = [
    'page.claim.label.category',
    'page.claim.label.category-placeholder',
    'page.claim.label.event',
    'page.claim.label.event-placeholder',
    'page.claim.label.no-events-category'
];

foreach ($requiredKeys as $k) {
    assertTest(isset($en[$k]) && $en[$k] !== '', "en.json defines '$k'");
    assertTest(isset($es[$k]) && $es[$k] !== '', "es.json defines '$k'");
    assertTest(isset($qqq[$k]) && $qqq[$k] !== '', "qqq.json documents '$k'");
}

// Test 2: Category Filter Matching Logic
echo "\nTest 2: Category Matching Logic...\n";
$mockEvents = [
    ['id' => 1, 'name' => 'Annual Conference 2026', 'category' => 'Conference'],
    ['id' => 2, 'name' => 'Spring Graphic Internship', 'category' => 'Internship'],
    ['id' => 3, 'name' => 'Wiki Editathon 2026', 'category' => 'Editathon'],
    ['id' => 4, 'name' => 'MediaWiki Workshop', 'category' => 'Workshop'],
    ['id' => 5, 'name' => 'General Community Meetup', 'category' => null],
];

function filterEventsByCategory(array $events, string $selectedCategory): array {
    if ($selectedCategory === '') {
        return $events;
    }
    return array_values(array_filter($events, function ($e) use ($selectedCategory) {
        $cat = !empty($e['category']) ? $e['category'] : 'Other Events';
        return $cat === $selectedCategory;
    }));
}

$all = filterEventsByCategory($mockEvents, '');
assertTest(count($all) === 5, "Empty filter returns all 5 events");

$conferences = filterEventsByCategory($mockEvents, 'Conference');
assertTest(count($conferences) === 1 && $conferences[0]['name'] === 'Annual Conference 2026', "Filter 'Conference' returns 1 conference");

$internships = filterEventsByCategory($mockEvents, 'Internship');
assertTest(count($internships) === 1 && $internships[0]['name'] === 'Spring Graphic Internship', "Filter 'Internship' returns 1 internship");

$others = filterEventsByCategory($mockEvents, 'Other Events');
assertTest(count($others) === 1 && $others[0]['name'] === 'General Community Meetup', "Filter 'Other Events' returns 1 un-categorized event");

$empty = filterEventsByCategory($mockEvents, 'Nonexistent Category');
assertTest(count($empty) === 0, "Filter 'Nonexistent Category' returns empty array");

echo "\n=======================================================\n";
echo "SUMMARY: PASS: $passes | FAIL: $fails\n";
echo "=======================================================\n";

if ($fails > 0) {
    exit(1);
}
