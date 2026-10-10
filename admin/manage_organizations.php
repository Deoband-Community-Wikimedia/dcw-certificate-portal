<?php
session_start();
require_once '../config.php';
require_once '../helpers.php';
require_once '../helpers/organization.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

// Strict super admin access control
if (!is_super_admin()) {
    header("Location: dashboard.php?msg=unauthorized");
    exit;
}

$error = '';
$success = '';
$fieldErrors = [];

if (isset($_GET['created'])) {
    $success = __('admin.orgs.msg.created-success');
} elseif (isset($_GET['toggled'])) {
    $success = __('admin.orgs.msg.toggled-success');
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'create_org') {
        list($fieldErrors, $clean) = org_validate_profile($_POST);

        $homeUrl = trim($_POST['home_url'] ?? '');
        if ($homeUrl !== '') {
            if (!org_is_http_url($homeUrl)) {
                $fieldErrors['home_url'] = 'home-url-invalid';
            } else {
                $clean['home_url'] = $homeUrl;
            }
        } else {
            $clean['home_url'] = null;
        }

        // Check for duplicate slug
        if (empty($fieldErrors['slug'])) {
            $stmtSlug = $pdo->prepare("SELECT COUNT(*) FROM organizations WHERE slug = ?");
            $stmtSlug->execute([$clean['slug']]);
            if ($stmtSlug->fetchColumn() > 0) {
                $fieldErrors['slug'] = 'slug-exists';
            }
        }

        // Check for duplicate cert_code
        if (empty($fieldErrors['cert_code'])) {
            $stmtCode = $pdo->prepare("SELECT COUNT(*) FROM organizations WHERE cert_code = ?");
            $stmtCode->execute([$clean['cert_code']]);
            if ($stmtCode->fetchColumn() > 0) {
                $fieldErrors['cert_code'] = 'cert-code-exists';
            }
        }

        if (empty($fieldErrors)) {
            $insertStmt = $pdo->prepare("
                INSERT INTO organizations (name, slug, cert_code, contact_email, home_url, is_active)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            if ($insertStmt->execute([
                $clean['name'],
                $clean['slug'],
                $clean['cert_code'],
                $clean['contact_email'],
                $clean['home_url']
            ])) {
                $newOrgId = (int)$pdo->lastInsertId();
                log_audit_action($pdo, 'Created Organization', "Created: {$clean['name']} [{$clean['cert_code']}] (ID: {$newOrgId})");
                header("Location: manage_organizations.php?created=1");
                exit;
            } else {
                $error = __('admin.orgs.error.create-failed');
            }
        }

        if (!empty($fieldErrors) && empty($error)) {
            $error = __('admin.org-settings.error.fix-fields');
        }
    } elseif ($action === 'toggle_status') {
        $targetOrgId = (int)($_POST['org_id'] ?? 0);

        if ($targetOrgId <= 1) {
            $error = __('admin.orgs.error.cannot-deactivate-root');
        } else {
            $stmtOrg = $pdo->prepare("SELECT id, name, is_active FROM organizations WHERE id = ?");
            $stmtOrg->execute([$targetOrgId]);
            $targetOrg = $stmtOrg->fetch();

            if ($targetOrg) {
                $newStatus = $targetOrg['is_active'] ? 0 : 1;
                $updateStmt = $pdo->prepare("UPDATE organizations SET is_active = ? WHERE id = ?");
                $updateStmt->execute([$newStatus, $targetOrgId]);
                log_audit_action($pdo, 'Toggled Organization Status', "Org ID: {$targetOrgId}, Name: {$targetOrg['name']}, Active: {$newStatus}");
                header("Location: manage_organizations.php?toggled=1");
                exit;
            } else {
                $error = __('admin.org-settings.error.org-not-found');
            }
        }
    }
}

// Fetch all organizations with event and admin counts
$stmtOrgs = $pdo->query("
    SELECT o.*,
           (SELECT COUNT(*) FROM events e WHERE e.organization_id = o.id) AS event_count,
           (SELECT COUNT(*) FROM admin_users u WHERE u.organization_id = o.id) AS admin_count
    FROM organizations o
    ORDER BY o.id ASC
");
$allOrgs = $stmtOrgs->fetchAll();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(i18n_get_lang()) ?>" dir="<?= i18n_get_dir() ?>">
<head>
    <link rel="icon" type="image/png" href="../assets/DCW_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __e('admin.orgs.page-title') ?></title>
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
    <?php i18n_lang_switcher_css(); ?>
    <style>
        .badge-active { background: #dcfce7; color: #166534; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .badge-inactive { background: #fee2e2; color: #991b1b; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .badge-code { background: #e0f2fe; color: #0369a1; padding: 3px 8px; border-radius: 4px; font-family: monospace; font-weight: bold; font-size: 13px; }
        .field-error { color: #dc2626; font-size: 12px; margin-top: 4px; }
        .org-card-grid { display: flex; gap: 30px; flex-wrap: wrap; margin-top: 25px; }
        .org-form-card { flex: 1; min-width: 320px; background: white; padding: 25px; border-radius: 8px; border: 1px solid var(--border-color); }
        .org-table-card { flex: 2; min-width: 500px; background: white; padding: 25px; border-radius: 8px; border: 1px solid var(--border-color); }
    </style>
</head>
<body>

<div class="navbar">
    <div style="display: flex; align-items: center; gap: 15px;">
        <img src="../assets/DCW_logo.png" alt="DCW Logo" width="35" height="35" decoding="async" style="height: 35px; width: 35px; background: white; padding: 2px; border-radius: 50%;">
        <span style="font-size: 18px; font-weight: bold; letter-spacing: 0.5px;"><?= __e('admin.orgs.nav-title') ?></span>
        <span style="background: #fef3c7; color: #92400e; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; text-transform: uppercase;">Super Admin</span>
    </div>
    <div style="display: flex; align-items: center; gap: 15px;">
        <?php i18n_lang_switcher(); ?>
        <a href="dashboard.php"><?= __e('admin.common.nav.dashboard') ?></a>
        <a href="manage_users.php"><?= __e('admin.common.nav.manage-users') ?></a>
        <a href="organization_settings.php"><?= __e('admin.common.nav.org-settings') ?></a>
        <a href="logout.php"><?= __e('admin.common.nav.logout') ?></a>
    </div>
</div>

<div class="container" style="max-width: 1200px;">

    <?php if ($success): ?>
        <div style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px;">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div style="background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="org-card-grid">

        <!-- Form: Create Organization -->
        <div class="org-form-card">
            <h2><?= __e('admin.orgs.form.heading') ?></h2>
            <p style="font-size: 13px; color: #64748b; margin-top: -5px; margin-bottom: 20px;">
                <?= __e('admin.orgs.form.subheading') ?>
            </p>

            <form method="POST" action="manage_organizations.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="action" value="create_org">

                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px;">
                        <?= __e('admin.orgs.label.name') ?> <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="text" name="name" required class="form-control"
                           value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                           placeholder="e.g. Wikimedia Capacity Exchange"
                           style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box;">
                    <?php if (isset($fieldErrors['name'])): ?>
                        <div class="field-error"><?= __e('admin.org-settings.error.' . $fieldErrors['name']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px;">
                        <?= __e('admin.orgs.label.slug') ?> <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="text" name="slug" required class="form-control"
                           value="<?= htmlspecialchars($_POST['slug'] ?? '') ?>"
                           placeholder="e.g. capx"
                           style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box;">
                    <small style="color: #64748b; font-size: 11px;"><?= __e('admin.orgs.hint.slug') ?></small>
                    <?php if (isset($fieldErrors['slug'])): ?>
                        <div class="field-error">
                            <?= $fieldErrors['slug'] === 'slug-exists' ? __e('admin.orgs.error.slug-exists') : __e('admin.org-settings.error.' . $fieldErrors['slug']) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px;">
                        <?= __e('admin.orgs.label.cert-code') ?> <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="text" name="cert_code" required class="form-control"
                           value="<?= htmlspecialchars($_POST['cert_code'] ?? '') ?>"
                           placeholder="e.g. CAPX"
                           style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; text-transform: uppercase;">
                    <small style="color: #64748b; font-size: 11px;"><?= __e('admin.orgs.hint.cert-code') ?></small>
                    <?php if (isset($fieldErrors['cert_code'])): ?>
                        <div class="field-error">
                            <?= $fieldErrors['cert_code'] === 'cert-code-exists' ? __e('admin.orgs.error.cert-code-exists') : __e('admin.org-settings.error.' . $fieldErrors['cert_code']) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px;">
                        <?= __e('admin.orgs.label.contact-email') ?>
                    </label>
                    <input type="email" name="contact_email" class="form-control"
                           value="<?= htmlspecialchars($_POST['contact_email'] ?? '') ?>"
                           placeholder="contact@example.org"
                           style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box;">
                    <?php if (isset($fieldErrors['contact_email'])): ?>
                        <div class="field-error"><?= __e('admin.org-settings.error.' . $fieldErrors['contact_email']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="margin-bottom: 24px;">
                    <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px;">
                        <?= __e('admin.orgs.label.home-url') ?>
                    </label>
                    <input type="url" name="home_url" class="form-control"
                           value="<?= htmlspecialchars($_POST['home_url'] ?? '') ?>"
                           placeholder="https://capx.wikimedia.org"
                           style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box;">
                    <?php if (isset($fieldErrors['home_url'])): ?>
                        <div class="field-error"><?= __e('admin.orgs.error.home-url-invalid') ?></div>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn" style="width: 100%;">
                    <?= __e('admin.orgs.btn.create') ?>
                </button>
            </form>
        </div>

        <!-- Table: Registered Organizations -->
        <div class="org-table-card">
            <h2><?= __e('admin.orgs.table.heading') ?></h2>
            <p style="font-size: 13px; color: #64748b; margin-top: -5px; margin-bottom: 20px;">
                <?= __e('admin.orgs.table.subheading') ?>
            </p>

            <div class="table-responsive" style="margin-top: 0;">
                <table>
                    <thead>
                        <tr>
                            <th style="padding: 12px 14px; text-align: left;">ID</th>
                            <th style="padding: 12px 14px; text-align: left;"><?= __e('admin.orgs.th.name') ?></th>
                            <th style="padding: 12px 14px; text-align: left;"><?= __e('admin.orgs.th.code') ?></th>
                            <th style="padding: 12px 14px; text-align: center;"><?= __e('admin.orgs.th.counts') ?></th>
                            <th style="padding: 12px 14px; text-align: center;"><?= __e('admin.orgs.th.status') ?></th>
                            <th style="padding: 12px 14px; text-align: right;"><?= __e('admin.orgs.th.actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allOrgs as $org): ?>
                            <tr>
                                <td style="padding: 12px 14px; font-weight: bold; color: #64748b;">
                                    #<?= (int)$org['id'] ?>
                                </td>
                                <td style="padding: 12px 14px;">
                                    <div style="font-weight: 600; color: #0f172a; font-size: 14px;">
                                        <?= htmlspecialchars($org['name']) ?>
                                    </div>
                                    <div style="font-size: 12px; color: #64748b;">
                                        slug: <code><?= htmlspecialchars($org['slug']) ?></code>
                                        <?php if (!empty($org['contact_email'])): ?>
                                            &bull; <?= htmlspecialchars($org['contact_email']) ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="padding: 12px 14px;">
                                    <span class="badge-code"><?= htmlspecialchars($org['cert_code']) ?></span>
                                </td>
                                <td style="padding: 12px 14px; text-align: center; font-size: 12px; color: #475569;">
                                    <span><?= (int)$org['event_count'] ?> events</span><br>
                                    <span><?= (int)$org['admin_count'] ?> admins</span>
                                </td>
                                <td style="padding: 12px 14px; text-align: center;">
                                    <?php if ($org['is_active']): ?>
                                        <span class="badge-active"><?= __e('admin.orgs.status.active') ?></span>
                                    <?php else: ?>
                                        <span class="badge-inactive"><?= __e('admin.orgs.status.inactive') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 14px; text-align: right;">
                                    <div style="display: inline-flex; gap: 8px; align-items: center;">
                                        <a href="organization_settings.php?org_id=<?= (int)$org['id'] ?>"
                                           class="btn btn-sm" style="background: #0284c7; color: white; text-decoration: none;">
                                            <?= __e('admin.orgs.btn.profile') ?>
                                        </a>

                                        <?php if ((int)$org['id'] > 1): ?>
                                            <form method="POST" action="manage_organizations.php" style="display: inline;"
                                                  onsubmit="return confirm(<?= htmlspecialchars(json_encode($org['is_active'] ? __('admin.orgs.confirm.deactivate') : __('admin.orgs.confirm.activate')), ENT_QUOTES, 'UTF-8') ?>);">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="org_id" value="<?= (int)$org['id'] ?>">
                                                <button type="submit" class="btn btn-sm <?= $org['is_active'] ? 'btn-red' : 'btn-green' ?>">
                                                    <?= $org['is_active'] ? __e('admin.orgs.btn.deactivate') : __e('admin.orgs.btn.activate') ?>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span style="font-size: 11px; color: #94a3b8; font-style: italic;">(Default Root)</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

</div>

</body>
</html>
