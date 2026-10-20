<?php
/**
 * CLI migration: affiliate hub profile columns (Issue #160).
 *
 * Adds cover_banner_path, bio, meta_wiki_url, telegram_url, and twitter_url
 * to the organizations table. This script is a convenience shortcut for
 * environments with shell access; production (Hostinger) is covered by the
 * idempotent organizations_ensure_hub_schema() that runs on every web request.
 *
 * Usage: php scripts/migrate_affiliate_hub_profile.php
 */

if (PHP_SAPI !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

echo "Running affiliate hub profile migration (issue #160)...\n";
organizations_ensure_hub_schema($pdo);
echo "Done. organizations table now has hub profile columns.\n";
