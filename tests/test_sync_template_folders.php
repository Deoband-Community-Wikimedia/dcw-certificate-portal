<?php
/**
 * Test Suite: Template Folder Synchronization & Event Renaming
 */

require_once __DIR__ . '/../helpers.php';

echo "Running Template Folder Sync Tests...\n";
$testsPassed = 0;
$testsFailed = 0;

function assert_true($condition, $message) {
    global $testsPassed, $testsFailed;
    if ($condition) {
        echo "  [PASS] $message\n";
        $testsPassed++;
    } else {
        echo "  [FAIL] $message\n";
        $testsFailed++;
    }
}

// 1. Test getUniqueFilename
echo "\n--- Test 1: getUniqueFilename ---\n";
$tempDir = __DIR__ . '/temp_test_dir/';
if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);

file_put_contents($tempDir . 'sample.pdf', 'dummy content');
$unique1 = getUniqueFilename($tempDir, 'sample.pdf');
assert_true($unique1 === 'sample(1).pdf', "Generated first unique filename: $unique1");

file_put_contents($tempDir . 'sample(1).pdf', 'dummy content');
$unique2 = getUniqueFilename($tempDir, 'sample.pdf');
assert_true($unique2 === 'sample(2).pdf', "Generated second unique filename: $unique2");

// Cleanup temp test dir
unlink($tempDir . 'sample.pdf');
unlink($tempDir . 'sample(1).pdf');
rmdir($tempDir);


// 2. Test syncEventTemplateFolder with SQLite in-memory DB & filesystem
echo "\n--- Test 2: syncEventTemplateFolder (Rename & DB Update) ---\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Create SQLite schema mimicking events and event_roles
$pdo->exec("
    CREATE TABLE events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL
    );
    CREATE TABLE event_roles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id INTEGER NOT NULL,
        role_name TEXT NOT NULL,
        template_file TEXT NOT NULL
    );
");

// Insert test event and role
$pdo->exec("INSERT INTO events (id, name) VALUES (999, 'DCW Dummy Testing Event')");
$pdo->exec("INSERT INTO event_roles (id, event_id, role_name, template_file) VALUES (1, 999, 'Speaker', 'Devs_Dummy_Event/speaker.pdf')");
$pdo->exec("INSERT INTO event_roles (id, event_id, role_name, template_file) VALUES (2, 999, 'Attendee', 'Devs_Dummy_Event/attendee.pdf')");

// Create disk directory and files under uploads/templates/Devs_Dummy_Event/
$tplBaseDir = rtrim(dirname(__DIR__) . '/uploads/templates', '/\\') . '/';
$oldDir = $tplBaseDir . 'Devs_Dummy_Event/';
$newDir = $tplBaseDir . 'DCW_Dummy_Testing_Event/';

if (!is_dir($oldDir)) mkdir($oldDir, 0777, true);
file_put_contents($oldDir . 'speaker.pdf', 'speaker template data');
file_put_contents($oldDir . 'attendee.pdf', 'attendee template data');

// Ensure newDir does not exist initially
if (is_dir($newDir)) {
    foreach (scandir($newDir) as $f) {
        if ($f !== '.' && $f !== '..') @unlink($newDir . $f);
    }
    @rmdir($newDir);
}

// Execute sync with oldEventName passed (simulating edit_event.php)
$summary = syncEventTemplateFolder($pdo, 999, 'Devs Dummy Event');

assert_true(!is_dir($oldDir), "Old directory Devs_Dummy_Event was removed/renamed");
assert_true(is_dir($newDir), "New directory DCW_Dummy_Testing_Event exists");
assert_true(file_exists($newDir . 'speaker.pdf'), "speaker.pdf exists in new directory");
assert_true(file_exists($newDir . 'attendee.pdf'), "attendee.pdf exists in new directory");

$stmt = $pdo->query("SELECT * FROM event_roles WHERE event_id = 999 ORDER BY id ASC");
$roles = $stmt->fetchAll();
assert_true($roles[0]['template_file'] === 'DCW_Dummy_Testing_Event/speaker.pdf', "Role 1 template_file updated in DB: {$roles[0]['template_file']}");
assert_true($roles[1]['template_file'] === 'DCW_Dummy_Testing_Event/attendee.pdf', "Role 2 template_file updated in DB: {$roles[1]['template_file']}");


// 3. Test sync when new templates are uploaded while legacy folder still has files (Merge & reconcile)
echo "\n--- Test 3: syncEventTemplateFolder (Merging files & Legacy alias) ---\n";
// Re-create Devs_Dummy_Event with a leftover file
if (!is_dir($oldDir)) mkdir($oldDir, 0777, true);
file_put_contents($oldDir . 'extra_legacy.pdf', 'legacy file data');

// Run sync without oldEventName (simulating manage_roles.php or CLI script)
$summary2 = syncEventTemplateFolder($pdo, 999);

assert_true(file_exists($newDir . 'extra_legacy.pdf'), "extra_legacy.pdf was merged into DCW_Dummy_Testing_Event");
assert_true(!is_dir($oldDir), "Old directory Devs_Dummy_Event was cleaned up after merge");


// Clean up test directories
foreach (scandir($newDir) as $f) {
    if ($f !== '.' && $f !== '..') @unlink($newDir . $f);
}
@rmdir($newDir);


// Summary
echo "\n=======================================================\n";
echo "Tests Passed: $testsPassed\n";
echo "Tests Failed: $testsFailed\n";
echo "=======================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
exit(0);
