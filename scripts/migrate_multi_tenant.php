<?php
/**
 * DCW Certificate Portal - Multi-Tenant Database Migration Tool
 *
 * Usage:
 *   php scripts/migrate_multi_tenant.php
 *
 * This script safely and idempotently upgrades existing single-tenant databases
 * to the multi-tenant architecture (#147). Existing events, participants, and
 * admins are safely migrated to organization_id = 1 (Deoband Community Wikimedia).
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

echo "=======================================================\n";
echo " DCW Certificate Portal - Multi-Tenant Migration Tool  \n";
echo "=======================================================\n\n";

/**
 * Check if a column exists in a given table.
 */
function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM information_schema.columns 
        WHERE table_schema = DATABASE() 
          AND table_name = ? 
          AND column_name = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Check if an index exists on a given table.
 */
function indexExists(PDO $pdo, string $table, string $index): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM information_schema.statistics 
        WHERE table_schema = DATABASE() 
          AND table_name = ? 
          AND index_name = ?
    ");
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Check if a foreign key constraint already exists on a given table column.
 */
function foreignKeyOnColumnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM information_schema.key_column_usage 
        WHERE table_schema = DATABASE() 
          AND table_name = ? 
          AND column_name = ? 
          AND referenced_table_name IS NOT NULL
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    // -------------------------------------------------------------------------
    // 1. Create organizations table if missing
    // -------------------------------------------------------------------------
    echo "[-] Step 1: Checking 'organizations' table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS organizations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(50) NOT NULL UNIQUE,
            cert_code VARCHAR(20) NOT NULL UNIQUE,
            logo_path VARCHAR(255) NULL,
            home_url VARCHAR(255) NULL,
            about_url VARCHAR(255) NULL,
            contact_email VARCHAR(255) NULL,
            linkedin_org_id VARCHAR(50) NULL,
            social_links TEXT NULL,
            smtp_host VARCHAR(255) NULL,
            smtp_port INT NULL,
            smtp_user VARCHAR(255) NULL,
            smtp_pass_encrypted TEXT NULL,
            smtp_secure VARCHAR(10) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "    -> 'organizations' table is verified.\n";

    // -------------------------------------------------------------------------
    // 2. Ensure default DCW organization (ID: 1) exists
    // -------------------------------------------------------------------------
    echo "[-] Step 2: Seeding default DCW organization (ID: 1)...\n";
    $stmt = $pdo->prepare("
        INSERT INTO organizations (id, name, slug, cert_code, logo_path, home_url, about_url, contact_email, linkedin_org_id, is_active)
        VALUES (1, 'Deoband Community Wikimedia', 'dcw', 'DCW', 'assets/DCW_logo.png', 'https://dcwwiki.org/', 'https://dcwwiki.org/About', 'moderator@dcwwiki.org', '92536649', 1)
        ON DUPLICATE KEY UPDATE id=id
    ");
    $stmt->execute();
    echo "    -> Default organization (DCW, ID: 1) verified.\n";

    // -------------------------------------------------------------------------
    // 3. Migrate 'admin_users'
    // -------------------------------------------------------------------------
    echo "[-] Step 3: Checking 'admin_users' table...\n";
    if (!columnExists($pdo, 'admin_users', 'organization_id')) {
        echo "    -> Adding 'organization_id' column to 'admin_users'...\n";
        $pdo->exec("ALTER TABLE admin_users ADD COLUMN organization_id INT NULL AFTER id");
    }
    if (!columnExists($pdo, 'admin_users', 'role')) {
        echo "    -> Adding 'role' column to 'admin_users'...\n";
        $pdo->exec("ALTER TABLE admin_users ADD COLUMN role ENUM('super_admin', 'org_admin') NOT NULL DEFAULT 'org_admin' AFTER username");
    }
    // Update default admin to super_admin and org 1
    $pdo->exec("UPDATE admin_users SET role = 'super_admin', organization_id = 1 WHERE username = 'admin'");
    
    // Ensure any non-null organization_id in admin_users points to a valid organization
    $pdo->exec("UPDATE admin_users SET organization_id = 1 WHERE organization_id IS NOT NULL AND organization_id NOT IN (SELECT id FROM organizations)");

    // Add FK constraint if missing on organization_id
    if (!foreignKeyOnColumnExists($pdo, 'admin_users', 'organization_id')) {
        echo "    -> Adding foreign key on admin_users(organization_id)...\n";
        $pdo->exec("ALTER TABLE admin_users ADD CONSTRAINT fk_admin_users_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL");
    }

    // -------------------------------------------------------------------------
    // 4. Migrate 'events'
    // -------------------------------------------------------------------------
    echo "[-] Step 4: Checking 'events' table...\n";
    if (!columnExists($pdo, 'events', 'organization_id')) {
        echo "    -> Adding 'organization_id' column to 'events'...\n";
        $pdo->exec("ALTER TABLE events ADD COLUMN organization_id INT NOT NULL DEFAULT 1 AFTER id");
    }
    // Backfill any NULLs, 0s, or orphan IDs to 1
    $pdo->exec("UPDATE events SET organization_id = 1 WHERE organization_id IS NULL OR organization_id = 0 OR organization_id NOT IN (SELECT id FROM organizations)");

    if (!indexExists($pdo, 'events', 'idx_events_org_id')) {
        echo "    -> Adding index idx_events_org_id...\n";
        $pdo->exec("ALTER TABLE events ADD INDEX idx_events_org_id (organization_id)");
    }
    if (!foreignKeyOnColumnExists($pdo, 'events', 'organization_id')) {
        echo "    -> Adding foreign key on events(organization_id)...\n";
        $pdo->exec("ALTER TABLE events ADD CONSTRAINT fk_events_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE");
    }

    // -------------------------------------------------------------------------
    // 5. Migrate 'participants' (Transition global email UNIQUE to composite)
    // -------------------------------------------------------------------------
    echo "[-] Step 5: Checking 'participants' table...\n";
    if (!columnExists($pdo, 'participants', 'organization_id')) {
        echo "    -> Adding 'organization_id' column to 'participants'...\n";
        $pdo->exec("ALTER TABLE participants ADD COLUMN organization_id INT NOT NULL DEFAULT 1 AFTER id");
    }
    // Backfill any NULLs, 0s, or orphan IDs to 1
    $pdo->exec("UPDATE participants SET organization_id = 1 WHERE organization_id IS NULL OR organization_id = 0 OR organization_id NOT IN (SELECT id FROM organizations)");

    // Dynamically find and drop any legacy UNIQUE index on email that is not the composite key
    $stmtIdx = $pdo->prepare("
        SELECT index_name 
        FROM information_schema.statistics 
        WHERE table_schema = DATABASE() 
          AND table_name = 'participants' 
          AND column_name = 'email' 
          AND non_unique = 0 
          AND index_name != 'unique_org_participant'
    ");
    $stmtIdx->execute();
    $oldIndexes = array_unique($stmtIdx->fetchAll(PDO::FETCH_COLUMN));
    foreach ($oldIndexes as $oldIdx) {
        echo "    -> Dropping legacy UNIQUE index '{$oldIdx}' on 'participants'...\n";
        $pdo->exec("ALTER TABLE participants DROP INDEX `{$oldIdx}`");
    }

    // Add composite UNIQUE key (organization_id, email)
    if (!indexExists($pdo, 'participants', 'unique_org_participant')) {
        echo "    -> Adding composite UNIQUE key 'unique_org_participant' (organization_id, email)...\n";
        $pdo->exec("ALTER TABLE participants ADD UNIQUE KEY unique_org_participant (organization_id, email)");
    }

    if (!foreignKeyOnColumnExists($pdo, 'participants', 'organization_id')) {
        echo "    -> Adding foreign key on participants(organization_id)...\n";
        $pdo->exec("ALTER TABLE participants ADD CONSTRAINT fk_participants_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE");
    }

    // -------------------------------------------------------------------------
    // 6. Migrate 'audit_logs'
    // -------------------------------------------------------------------------
    echo "[-] Step 6: Checking 'audit_logs' table...\n";
    if (!columnExists($pdo, 'audit_logs', 'organization_id')) {
        echo "    -> Adding 'organization_id' column to 'audit_logs'...\n";
        $pdo->exec("ALTER TABLE audit_logs ADD COLUMN organization_id INT NULL AFTER id");
    }
    // Backfill NULLs or orphan IDs to 1
    $pdo->exec("UPDATE audit_logs SET organization_id = 1 WHERE organization_id IS NULL OR (organization_id IS NOT NULL AND organization_id NOT IN (SELECT id FROM organizations))");

    if (!indexExists($pdo, 'audit_logs', 'idx_audit_logs_org_id')) {
        echo "    -> Adding index idx_audit_logs_org_id...\n";
        $pdo->exec("ALTER TABLE audit_logs ADD INDEX idx_audit_logs_org_id (organization_id)");
    }

    if (!foreignKeyOnColumnExists($pdo, 'audit_logs', 'organization_id')) {
        echo "    -> Adding foreign key on audit_logs(organization_id)...\n";
        $pdo->exec("ALTER TABLE audit_logs ADD CONSTRAINT fk_audit_logs_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL");
    }

    echo "\n=======================================================\n";
    echo " SUCCESS: Multi-tenant schema migration completed!     \n";
    echo "=======================================================\n";

} catch (\Exception $e) {
    echo "\nERROR during migration: " . $e->getMessage() . "\n";
    exit(1);
}
