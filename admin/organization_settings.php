<?php
session_start();
require_once '../config.php';
require_once '../helpers/organization.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

// An admin can only ever see and change their own organization. The id comes
// from the session and is never read from the request, so there is nothing a
// crafted form field or URL could point at another tenant's record with.
$orgId = get_current_tenant_id();
$org = get_organization($pdo, $orgId);

if (!$org) {
    http_response_code(404);
    die(__e('admin.org-settings.error.org-not-found'));
}

$error = '';
$success = isset($_GET['saved']) ? __('admin.org-settings.success.saved') : '';
$fieldErrors = [];

// Values shown in the form; replaced with what was submitted if validation fails.
$form = [
    'name'          => $org['name'],
    'slug'          => $org['slug'],
    'cert_code'     => $org['cert_code'],
    'contact_email' => $org['contact_email'] ?? '',
];
$social = org_decode_social_links($org['social_links'] ?? null);
foreach (ORG_SOCIAL_PLATFORMS as $platform) {
    $form['social_' . $platform] = $social[$platform] ?? '';
}

$rootDir = dirname(__DIR__);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    list($fieldErrors, $clean) = org_validate_profile($_POST);

    foreach ($form as $key => $_) {
        $form[$key] = trim((string)($_POST[$key] ?? ''));
    }

    // Logo: a new upload replaces the current one; the checkbox removes it.
    $newLogoExt = null;
    $removeLogo = !empty($_POST['remove_logo']);
    $uploadAttempted = isset($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($uploadAttempted) {
        list($newLogoExt, $logoError) = org_check_logo_upload($_FILES['logo']);
        if ($logoError) {
            $fieldErrors['logo'] = $logoError;
        }
    }

    // Slug and code are UNIQUE across all organizations.
    if (empty($fieldErrors['slug'])) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM organizations WHERE slug = ? AND id <> ?");
        $stmt->execute([$clean['slug'], $orgId]);
        if ($stmt->fetchColumn() > 0) {
            $fieldErrors['slug'] = 'slug-taken';
        }
    }
    if (empty($fieldErrors['cert_code'])) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM organizations WHERE cert_code = ? AND id <> ?");
        $stmt->execute([$clean['cert_code'], $orgId]);
        if ($stmt->fetchColumn() > 0) {
            $fieldErrors['cert_code'] = 'cert-code-taken';
        }
    }

    if (empty($fieldErrors)) {
        $newLogoPath = $org['logo_path'];
        $storedNewLogo = null;

        if ($newLogoExt !== null) {
            $storedNewLogo = org_store_logo($orgId, $_FILES['logo']['tmp_name'], $newLogoExt, $rootDir);
            if ($storedNewLogo === null) {
                $fieldErrors['logo'] = 'logo-upload-failed';
            } else {
                $newLogoPath = $storedNewLogo;
            }
        } elseif ($removeLogo) {
            $newLogoPath = null;
        }
    }

    if (empty($fieldErrors)) {
        try {
            $upd = $pdo->prepare("
                UPDATE organizations
                SET name = ?, slug = ?, cert_code = ?, contact_email = ?, social_links = ?, logo_path = ?
                WHERE id = ?
            ");
            $upd->execute([
                $clean['name'],
                $clean['slug'],
                $clean['cert_code'],
                $clean['contact_email'],
                $clean['social_links'],
                $newLogoPath,
                $orgId,
            ]);

            // The old file is only removed once the row points at the new one.
            if ($newLogoPath !== $org['logo_path']) {
                org_delete_logo($orgId, $org['logo_path'], $rootDir);
            }

            log_audit_action($pdo, 'Updated Organization', "Organization ID: {$orgId}");

            header("Location: organization_settings.php?saved=1");
            exit;
        } catch (PDOException $e) {
            // Lost a race with another admin taking the same slug/code.
            if ($storedNewLogo !== null) {
                org_delete_logo($orgId, $storedNewLogo, $rootDir);
            }
            $error = __('admin.org-settings.error.save-failed');
        }
    }

    if (!$error && !empty($fieldErrors)) {
        $error = __('admin.org-settings.error.fix-fields');
    }
}

/** Resolve a field's error code to a translated message ('' when fine). */
function org_field_error(array $fieldErrors, string $field, array $params = []): string {
    return isset($fieldErrors[$field]) ? __('admin.org-settings.error.' . $fieldErrors[$field], $params) : '';
}

$logoUrl = !empty($org['logo_path']) ? '../' . $org['logo_path'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="../assets/DCW_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __e('admin.org-settings.page-title') ?></title>
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
    <style>
        .field-error { color: #c0392b; font-size: 12px; margin-top: 4px; display: block; }
        .field-hint { color: #777; font-size: 12px; margin-top: 4px; display: block; }
        .form-group input.has-error { border-color: #c0392b; }
        /* style.css styles text/email/etc. inputs but not type=url; match them. */
        .form-group input[type="url"] { width: 100%; padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; font-family: inherit; font-size: 14px; background: #f8fafc; color: #1e293b; transition: all 0.2s; }
        .form-group input[type="url"]:focus { outline: none; border-color: var(--primary-color); background: #ffffff; box-shadow: 0 0 0 3px rgba(16, 107, 154, 0.15); }
        .logo-preview { max-height: 80px; max-width: 200px; background: #f4f5f7; padding: 6px; border-radius: 6px; border: 1px solid #ddd; display: block; margin-bottom: 8px; }
    </style>
</head>
<body>

<div class="navbar">
    <div style="display: flex; align-items: center; gap: 15px;">
        <img src="../assets/DCW_logo.png" alt="DCW Logo" width="35" height="35" decoding="async" style="height: 35px; width: 35px; background: white; padding: 2px; border-radius: 50%;">
        <span style="font-size: 18px; font-weight: bold; letter-spacing: 0.5px;"><?= __e('admin.org-settings.nav-title') ?></span>
    </div>
    <div>
        <a href="dashboard.php"><?= __e('admin.common.nav.dashboard') ?></a>
        <a href="manage_users.php"><?= __e('admin.common.nav.manage-users') ?></a>
        <a href="logout.php"><?= __e('admin.common.nav.logout') ?></a>
    </div>
</div>

<div class="container" style="max-width: 720px;">
    <h2 style="margin-top: 0;"><?= __e('admin.org-settings.heading') ?></h2>
    <p style="font-size: 14px; color: #555;"><?= __e('admin.org-settings.intro') ?></p>

    <form method="POST" action="" enctype="multipart/form-data" class="upload-box">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">

        <div class="form-group">
            <label for="name"><?= __e('admin.org-settings.label.name') ?></label>
            <input type="text" id="name" name="name" maxlength="255" required
                   class="<?= isset($fieldErrors['name']) ? 'has-error' : '' ?>"
                   value="<?= htmlspecialchars($form['name']) ?>">
            <?php if ($m = org_field_error($fieldErrors, 'name')): ?><span class="field-error"><?= htmlspecialchars($m) ?></span><?php endif; ?>
        </div>

        <div class="form-group">
            <label for="slug"><?= __e('admin.org-settings.label.slug') ?></label>
            <input type="text" id="slug" name="slug" maxlength="50" required
                   class="<?= isset($fieldErrors['slug']) ? 'has-error' : '' ?>"
                   value="<?= htmlspecialchars($form['slug']) ?>">
            <span class="field-hint"><?= __e('admin.org-settings.hint.slug') ?></span>
            <?php if ($m = org_field_error($fieldErrors, 'slug')): ?><span class="field-error"><?= htmlspecialchars($m) ?></span><?php endif; ?>
        </div>

        <div class="form-group">
            <label for="cert_code"><?= __e('admin.org-settings.label.cert-code') ?></label>
            <input type="text" id="cert_code" name="cert_code" maxlength="20" required
                   class="<?= isset($fieldErrors['cert_code']) ? 'has-error' : '' ?>"
                   value="<?= htmlspecialchars($form['cert_code']) ?>">
            <span class="field-hint"><?= __e('admin.org-settings.hint.cert-code') ?></span>
            <?php if ($m = org_field_error($fieldErrors, 'cert_code')): ?><span class="field-error"><?= htmlspecialchars($m) ?></span><?php endif; ?>
        </div>

        <div class="form-group">
            <label for="contact_email"><?= __e('admin.org-settings.label.contact-email') ?></label>
            <input type="email" id="contact_email" name="contact_email" maxlength="255"
                   class="<?= isset($fieldErrors['contact_email']) ? 'has-error' : '' ?>"
                   value="<?= htmlspecialchars($form['contact_email']) ?>">
            <?php if ($m = org_field_error($fieldErrors, 'contact_email')): ?><span class="field-error"><?= htmlspecialchars($m) ?></span><?php endif; ?>
        </div>

        <h3 style="margin: 25px 0 5px;"><?= __e('admin.org-settings.section.social') ?></h3>
        <p style="font-size: 13px; color: #555; margin-top: 0;"><?= __e('admin.org-settings.social.desc') ?></p>
        <?php foreach (ORG_SOCIAL_PLATFORMS as $platform):
            $platformLabel = __('admin.org-settings.social.' . $platform);
            ?>
            <div class="form-group">
                <label for="social_<?= $platform ?>"><?= htmlspecialchars($platformLabel) ?></label>
                <input type="url" id="social_<?= $platform ?>" name="social_<?= $platform ?>" maxlength="300"
                       placeholder="https://"
                       class="<?= isset($fieldErrors['social_' . $platform]) ? 'has-error' : '' ?>"
                       value="<?= htmlspecialchars($form['social_' . $platform]) ?>">
                <?php if ($m = org_field_error($fieldErrors, 'social_' . $platform, ['platform' => $platformLabel])): ?><span class="field-error"><?= htmlspecialchars($m) ?></span><?php endif; ?>
            </div>
        <?php endforeach; ?>

        <h3 style="margin: 25px 0 5px;"><?= __e('admin.org-settings.section.logo') ?></h3>
        <div class="form-group">
            <?php if ($logoUrl): ?>
                <img class="logo-preview" src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= __e('admin.org-settings.logo.current-alt') ?>">
                <label style="font-weight: normal; font-size: 13px; display: block; margin-bottom: 10px;">
                    <input type="checkbox" name="remove_logo" value="1">
                    <?= __e('admin.org-settings.logo.remove') ?>
                </label>
            <?php else: ?>
                <span class="field-hint" style="margin-bottom: 8px;"><?= __e('admin.org-settings.logo.none') ?></span>
            <?php endif; ?>
            <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp">
            <span class="field-hint"><?= __e('admin.org-settings.hint.logo') ?></span>
            <?php if ($m = org_field_error($fieldErrors, 'logo')): ?><span class="field-error"><?= htmlspecialchars($m) ?></span><?php endif; ?>
        </div>

        <button type="submit" class="btn" style="width: 100%;"><?= __e('admin.org-settings.submit') ?></button>
    </form>
</div>

<script src="script.js"></script>
<?php if ($error): ?>
<script>
    window.flashMessage = <?= json_encode($error) ?>;
    window.flashMessageType = 'error';
</script>
<?php endif; ?>
<?php if ($success): ?>
<script>
    window.flashMessage = <?= json_encode($success) ?>;
    window.flashMessageType = 'success';
</script>
<?php endif; ?>
</body>
</html>
