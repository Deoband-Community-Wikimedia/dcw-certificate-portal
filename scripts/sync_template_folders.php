<?php
/**
 * DCW Certificate Portal - Event Template Folder Synchronization Tool
 *
 * Usage:
 *   php scripts/sync_template_folders.php
 *
 * This script synchronizes template folders in `uploads/templates/` with current event names
 * and ensures all `event_roles.template_file` database records point to the correct folder paths.
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

echo "=======================================================\n";
echo " DCW Certificate Portal - Template Folder Sync Utility \n";
echo "=======================================================\n\n";

try {
    $stmt = $pdo->query("SELECT id, name FROM events ORDER BY id ASC");
    $events = $stmt->fetchAll();
} catch (\Exception $e) {
    echo "ERROR: Failed to query database: " . $e->getMessage() . "\n";
    exit(1);
}

if (empty($events)) {
    echo "No events found in database.\n";
    exit(0);
}

echo "Found " . count($events) . " event(s). Starting folder synchronization...\n\n";

$totalRenamed = 0;
$totalMoved = 0;
$totalRolesUpdated = 0;
$totalDirsRemoved = 0;

foreach ($events as $event) {
    $eventId = (int)$event['id'];
    $eventName = $event['name'];
    $expectedFolder = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $eventName);

    echo "[-] Checking Event #{$eventId}: '{$eventName}' (Folder: '{$expectedFolder}')\n";

    $res = syncEventTemplateFolder($pdo, $eventId);

    $totalRenamed += $res['folders_renamed'];
    $totalMoved += $res['files_moved'];
    $totalRolesUpdated += $res['roles_updated'];
    $totalDirsRemoved += $res['directories_removed'];

    if ($res['folders_renamed'] > 0 || $res['files_moved'] > 0 || $res['roles_updated'] > 0 || $res['directories_removed'] > 0) {
        echo "    -> Synchronized:\n";
        if ($res['folders_renamed'] > 0) echo "       * Folders renamed: {$res['folders_renamed']}\n";
        if ($res['files_moved'] > 0) echo "       * Template files moved: {$res['files_moved']}\n";
        if ($res['roles_updated'] > 0) echo "       * Database role records updated: {$res['roles_updated']}\n";
        if ($res['directories_removed'] > 0) echo "       * Obsolete directories removed: {$res['directories_removed']}\n";
    } else {
        echo "    -> Already in sync.\n";
    }
}

echo "\n=======================================================\n";
echo " Summary of Sync Operations:\n";
echo "   Folders Renamed:         {$totalRenamed}\n";
echo "   Template Files Moved:    {$totalMoved}\n";
echo "   Role Records Updated:    {$totalRolesUpdated}\n";
echo "   Old Directories Removed: {$totalDirsRemoved}\n";
echo "=======================================================\n";
echo "Template folder synchronization complete.\n";
exit(0);
