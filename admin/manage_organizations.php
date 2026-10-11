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
    if (isset($_GET['invited'])) {
        $success = __('admin.orgs.msg.created-and-invited-success');
        if ($success === 'admin.orgs.msg.created-and-invited-success') {
            $success = 'Organization created and invitation email dispatched to community lead.';
        }
    } else {
        $success = __('admin.orgs.msg.created-success');
    }
} elseif (isset($_GET['toggled'])) {
    $success = __('admin.orgs.msg.toggled-success');
} elseif (isset($_GET['resent'])) {
    $success = __('admin.orgs.msg.invite-resent-success');
    if ($success === 'admin.orgs.msg.invite-resent-success') {
        $success = 'Invitation email has been re-sent successfully.';
    }
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

        $leadEmail = trim($_POST['lead_email'] ?? '');
        if ($leadEmail !== '') {
            if (!filter_var($leadEmail, FILTER_VALIDATE_EMAIL)) {
                $fieldErrors['lead_email'] = 'lead-email-invalid';
            }
        }

        // Check for reserved slug
        if (empty($fieldErrors['slug']) && is_reserved_slug($clean['slug'])) {
            $fieldErrors['slug'] = 'slug-reserved';
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
                $leadEmail ?: $clean['contact_email'],
                $clean['home_url']
            ])) {
                $newOrgId = (int)$pdo->lastInsertId();
                log_audit_action($pdo, 'Created Organization', "Created: {$clean['name']} [{$clean['cert_code']}] (ID: {$newOrgId})");

                $invitedParam = '';
                // Automatically create invitation and dispatch welcome email if lead_email provided
                if ($leadEmail !== '') {
                    $inv = create_admin_invitation($pdo, $newOrgId, $leadEmail);
                    if ($inv['success']) {
                        $inviteUrl = adminPortalBaseUrl() . '/admin/accept_invite.php?token=' . urlencode($inv['raw_token']);
                        sendAdminInvitationEmail($pdo, $leadEmail, $clean['name'], $inviteUrl, $inv['username']);
                        log_audit_action($pdo, 'Dispatched Partner Invitation', "Invited {$leadEmail} for Org ID: {$newOrgId}");
                        $invitedParam = '&invited=1';
                    }
                }

                header("Location: manage_organizations.php?created=1" . $invitedParam);
                exit;
            } else {
                $error = __('admin.orgs.error.create-failed');
            }
        }

        if (!empty($fieldErrors) && empty($error)) {
            $error = __('admin.org-settings.error.fix-fields');
        }
    } elseif ($action === 'resend_invite') {
        $targetOrgId = (int)($_POST['org_id'] ?? 0);
        $emailOverride = trim($_POST['lead_email'] ?? '');
        $res = resend_admin_invitation($pdo, $targetOrgId, $emailOverride ?: null);
        if ($res['success']) {
            log_audit_action($pdo, 'Resent Admin Invitation', "Resent invite for Org ID: {$targetOrgId}");
            header("Location: manage_organizations.php?resent=1");
            exit;
        } else {
            $error = 'Failed to resend invitation: ' . ($res['message'] ?? 'Please check the recipient email address.');
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

// Fetch all organizations with event, admin, and invite status
invitation_ensure_schema($pdo);
$stmtOrgs = $pdo->query("
    SELECT o.*,
           (SELECT COUNT(*) FROM events e WHERE e.organization_id = o.id) AS event_count,
           (SELECT COUNT(*) FROM admin_users u WHERE u.organization_id = o.id) AS admin_count,
           (SELECT u.username FROM admin_users u WHERE u.organization_id = o.id ORDER BY u.id ASC LIMIT 1) AS primary_admin_username,
           (SELECT u.email FROM admin_users u WHERE u.organization_id = o.id ORDER BY u.id ASC LIMIT 1) AS primary_admin_email,
           (SELECT ai.email FROM admin_invitations ai WHERE ai.organization_id = o.id AND ai.is_accepted = 0 AND ai.expires_at > CURRENT_TIMESTAMP ORDER BY ai.id DESC LIMIT 1) AS pending_invite_email,
           (SELECT ai.expires_at FROM admin_invitations ai WHERE ai.organization_id = o.id AND ai.is_accepted = 0 AND ai.expires_at > CURRENT_TIMESTAMP ORDER BY ai.id DESC LIMIT 1) AS pending_invite_expiry
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
        .badge-active { background: #dcfce7; color: #166534; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
        .badge-inactive { background: #fee2e2; color: #991b1b; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
        .badge-pending { background: #fef3c7; color: #92400e; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
        .badge-none { background: #f1f5f9; color: #64748b; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .badge-code { background: #e0f2fe; color: #0369a1; padding: 3px 8px; border-radius: 4px; font-family: monospace; font-weight: bold; font-size: 13px; }
        .field-error { color: #dc2626; font-size: 12px; margin-top: 4px; }
        .org-card-grid { display: flex; gap: 30px; flex-wrap: wrap; margin-top: 25px; }
        .org-form-card { flex: 1; min-width: 320px; background: white; padding: 25px; border-radius: 8px; border: 1px solid var(--border-color); }
        .org-table-card { flex: 2.2; min-width: 540px; background: white; padding: 25px; border-radius: 8px; border: 1px solid var(--border-color); }
        .hub-link { color: #0284c7; text-decoration: none; font-size: 12px; font-weight: 500; display: inline-flex; align-items: center; gap: 3px; }
        .hub-link:hover { text-decoration: underline; }
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

<div class="container" style="max-width: 1250px;">

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

            <form method="POST" action="manage_organizations.php" id="createOrgForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="action" value="create_org">

                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px;">
                        <?= __e('admin.orgs.label.name') ?> <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="text" id="org_name" name="name" required class="form-control"
                           value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                           placeholder="e.g. Wikimedia Community User Group Spain"
                           style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box;">
                    <?php if (isset($fieldErrors['name'])): ?>
                        <div class="field-error"><?= __e('admin.org-settings.error.' . $fieldErrors['name']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px;">
                        <?= __e('admin.orgs.label.lead-email') ?> <span style="color: #106b9a; font-size: 11px;">(<?= __e('admin.orgs.hint.lead-email') ?>)</span>
                    </label>
                    <input type="email" id="lead_email" name="lead_email" class="form-control"
                           value="<?= htmlspecialchars($_POST['lead_email'] ?? '') ?>"
                           placeholder="lead@wikimedia.es"
                           style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box;">
                    <small style="color: #64748b; font-size: 11px;"><?= __e('admin.orgs.hint.invite-dispatch') ?></small>
                    <?php if (isset($fieldErrors['lead_email'])): ?>
                        <div class="field-error"><?= __e('admin.orgs.error.lead-email-invalid') ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px;">
                        <?= __e('admin.orgs.label.slug') ?> <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="text" id="org_slug" name="slug" required class="form-control"
                           value="<?= htmlspecialchars($_POST['slug'] ?? '') ?>"
                           placeholder="e.g. wmsp"
                           style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box;">
                    <small style="color: #64748b; font-size: 11px;"><?= __e('admin.orgs.hint.slug') ?></small>
                    <?php if (isset($fieldErrors['slug'])): ?>
                        <div class="field-error">
                            <?php
                                if ($fieldErrors['slug'] === 'slug-exists') {
                                    echo __e('admin.orgs.error.slug-exists');
                                } elseif ($fieldErrors['slug'] === 'slug-reserved') {
                                    echo __e('admin.orgs.error.slug-reserved');
                                } else {
                                    echo __e('admin.org-settings.error.' . $fieldErrors['slug']);
                                }
                            ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px;">
                        <?= __e('admin.orgs.label.cert-code') ?> <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="text" id="org_code" name="cert_code" required class="form-control"
                           value="<?= htmlspecialchars($_POST['cert_code'] ?? '') ?>"
                           placeholder="e.g. WMSP"
                           style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; text-transform: uppercase;">
                    <small style="color: #64748b; font-size: 11px;"><?= __e('admin.orgs.hint.cert-code') ?></small>
                    <?php if (isset($fieldErrors['cert_code'])): ?>
                        <div class="field-error">
                            <?= $fieldErrors['cert_code'] === 'cert-code-exists' ? __e('admin.orgs.error.cert-code-exists') : __e('admin.org-settings.error.' . $fieldErrors['cert_code']) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="margin-bottom: 24px;">
                    <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px;">
                        <?= __e('admin.orgs.label.home-url') ?>
                    </label>
                    <input type="url" name="home_url" class="form-control"
                           value="<?= htmlspecialchars($_POST['home_url'] ?? '') ?>"
                           placeholder="https://es.wikimedia.org"
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
                            <th style="padding: 12px 14px; text-align: left;"><?= __e('admin.orgs.th.lead-status') ?></th>
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
                                    <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                                        slug: <code><?= htmlspecialchars($org['slug']) ?></code> &bull;
                                        <a href="../c/<?= urlencode($org['slug']) ?>" target="_blank" class="hub-link">
                                            <?= __e('admin.orgs.link.public-hub') ?>
                                            <svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor"><path d="M14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3m-2 16H5V5h7V3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7h-2v7z"/></svg>
                                        </a>
                                    </div>
                                </td>
                                <td style="padding: 12px 14px;">
                                    <span class="badge-code"><?= htmlspecialchars($org['cert_code']) ?></span>
                                </td>
                                <td style="padding: 12px 14px; font-size: 12px;">
                                    <?php if ((int)$org['admin_count'] > 0): ?>
                                        <span class="badge-active">🟢 <?= __e('admin.orgs.lead.active') ?></span>
                                        <div style="color: #475569; margin-top: 3px;">
                                            <strong><?= htmlspecialchars($org['primary_admin_username'] ?? 'admin') ?></strong>
                                            <?php if (!empty($org['primary_admin_email'])): ?>
                                                <br><span style="color: #64748b;"><?= htmlspecialchars($org['primary_admin_email']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif (!empty($org['pending_invite_email'])): ?>
                                        <span class="badge-pending">🟡 <?= __e('admin.orgs.lead.invite-pending') ?></span>
                                        <div style="color: #475569; margin-top: 3px;">
                                            <span><?= htmlspecialchars($org['pending_invite_email']) ?></span>
                                        </div>
                                        <form method="POST" action="manage_organizations.php" style="margin-top: 4px;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                            <input type="hidden" name="action" value="resend_invite">
                                            <input type="hidden" name="org_id" value="<?= (int)$org['id'] ?>">
                                            <button type="submit" class="btn btn-sm" style="background: #f59e0b; color: white; padding: 2px 8px; font-size: 11px;">
                                                <?= __e('admin.orgs.btn.resend-invite') ?>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <?php if ((int)$org['id'] === 1): ?>
                                            <span class="badge-active">🟢 Root System</span>
                                        <?php else: ?>
                                            <span class="badge-none"><?= __e('admin.orgs.lead.no-lead') ?></span>
                                            <form method="POST" action="manage_organizations.php" style="margin-top: 4px; display: flex; gap: 4px;">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                                <input type="hidden" name="action" value="resend_invite">
                                                <input type="hidden" name="org_id" value="<?= (int)$org['id'] ?>">
                                                <input type="email" name="lead_email" placeholder="lead@affiliate.org" required style="padding: 2px 6px; font-size: 11px; border: 1px solid #cbd5e1; border-radius: 4px; width: 110px;">
                                                <button type="submit" class="btn btn-sm" style="background: #106b9a; color: white; padding: 2px 8px; font-size: 11px;">
                                                    Invite
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
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

<script>
// Auto-suggest slug and cert code in real time as user types organization name
(function() {
    const nameInput = document.getElementById('org_name');
    const slugInput = document.getElementById('org_slug');
    const codeInput = document.getElementById('org_code');

    let slugTouched = false;
    let codeTouched = false;

    if (slugInput.value.trim() !== '') slugTouched = true;
    if (codeInput.value.trim() !== '') codeTouched = true;

    slugInput.addEventListener('input', () => { slugTouched = true; });
    codeInput.addEventListener('input', () => { codeTouched = true; });

    nameInput.addEventListener('input', function() {
        const val = this.value.trim();
        if (!val) return;

        // Auto-slugify
        if (!slugTouched) {
            let slug = val.toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
            if (slug) {
                slugInput.value = slug.substring(0, 40);
            }
        }

        // Auto cert-code (acronym or first 4 uppercase)
        if (!codeTouched) {
            const words = val.split(/[\s_-]+/);
            let initials = '';
            for (let w of words) {
                let clean = w.replace(/[^a-zA-Z0-9]/g, '');
                if (clean.length > 0) initials += clean[0].toUpperCase();
            }
            if (initials.length < 2) {
                let cleanVal = val.replace(/[^a-zA-Z0-9]/g, '').toUpperCase();
                initials = cleanVal.substring(0, 4);
            }
            if (initials) {
                codeInput.value = initials.substring(0, 8);
            }
        }
    });
})();
</script>

</body>
</html>
