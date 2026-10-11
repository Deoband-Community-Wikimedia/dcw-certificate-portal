<?php
/**
 * DCW Certificate Portal - Community Hubs & Automated Onboarding Migration Tool
 *
 * Usage:
 *   php scripts/migrate_community_hubs.php
 *
 * This script safely and idempotently provisions the database schema for:
 * 1. Community Hubs & Direct Claim routes: ensures events.slug exists and backfills slugs.
 * 2. Automated Partner Onboarding: creates admin_invitations table.
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

echo "=======================================================\n";
echo " DCW Certificate Portal - Community Hubs Migration Tool \n";
echo "=======================================================\n\n";

try {
    invitation_ensure_schema($pdo);
    echo "[✓] Ensured admin_invitations and events.slug schema structures exist.\n";

    // Backfill any empty event slugs
    $stmt = $pdo->query("SELECT id, name, slug FROM events WHERE slug IS NULL OR slug = ''");
    $eventsToBackfill = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $backfilledCount = 0;
    foreach ($eventsToBackfill as $ev) {
        $uniqueSlug = generate_unique_event_slug($pdo, $ev['name'], (int)$ev['id']);
        $up = $pdo->prepare("UPDATE events SET slug = ? WHERE id = ?");
        $up->execute([$uniqueSlug, (int)$ev['id']]);
        $backfilledCount++;
        echo "  - Event #{$ev['id']} ('{$ev['name']}') assigned slug: '{$uniqueSlug}'\n";
    }

    echo "\n[✓] Migration complete! Backfilled {$backfilledCount} event slug(s).\n";
    echo "=======================================================\n";
    exit(0);
} catch (Exception $e) {
    echo "\n[✗] Migration error: " . $e->getMessage() . "\n";
    exit(1);
}
