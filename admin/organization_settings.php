<?php
session_start();
require_once '../config.php';
require_once '../helpers/organization.php';
require_once '../helpers/hub_profile.php';
organizations_ensure_hub_schema($pdo);

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

// An admin can only see and change their own organization, unless they
// have global super_admin privileges and pass an explicit org_id parameter.
$isSuper = is_super_admin();
$requestedOrgId = isset($_GET['org_id']) && (int)$_GET['org_id'] > 0 ? (int)$_GET['org_id'] : 0;
if ($isSuper && $requestedOrgId > 0) {
    $orgId = $requestedOrgId;
} else {
    $orgId = get_current_tenant_id();
}
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
    // Hub profile fields (issue #160)
    'hub_bio'           => $org['bio']           ?? '',
    'hub_meta_wiki_url' => $org['meta_wiki_url'] ?? '',
    'hub_telegram_url'  => $org['telegram_url']  ?? '',
    'hub_twitter_url'   => $org['twitter_url']   ?? '',
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

    // Cover banner: a new upload replaces the current one; the checkbox removes it.
    $newBannerExt = null;
    $removeBanner = !empty($_POST['remove_banner']);
    $bannerAttempted = isset($_FILES['cover_banner']) && ($_FILES['cover_banner']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($bannerAttempted) {
        list($newBannerExt, $bannerError) = hub_check_banner_upload($_FILES['cover_banner']);
        if ($bannerError) {
            $fieldErrors['cover_banner'] = $bannerError;
        }
    }

    // Hub profile fields (bio, URLs).
    list($hubErrors, $hubClean) = hub_validate_profile($_POST);
    $fieldErrors = array_merge($fieldErrors, $hubErrors);
    // Restore hub form values on validation failure.
    foreach (array_keys($form) as $k) {
        if (str_starts_with($k, 'hub_')) {
            $form[$k] = trim((string)($_POST[$k] ?? ''));
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
        // Resolve final logo path.
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
        // Resolve final banner path.
        $newBannerPath = $org['cover_banner_path'] ?? null;
        $storedNewBanner = null;
        if ($newBannerExt !== null) {
            $storedNewBanner = hub_store_banner($orgId, $_FILES['cover_banner']['tmp_name'], $newBannerExt, $rootDir);
            if ($storedNewBanner === null) {
                $fieldErrors['cover_banner'] = 'banner-upload-failed';
            } else {
                $newBannerPath = $storedNewBanner;
            }
        } elseif ($removeBanner) {
            $newBannerPath = null;
        }
    }

    if (empty($fieldErrors)) {
        try {
            $upd = $pdo->prepare("
                UPDATE organizations
                SET name = ?, slug = ?, cert_code = ?, contact_email = ?, social_links = ?, logo_path = ?,
                    cover_banner_path = ?, bio = ?, meta_wiki_url = ?, telegram_url = ?, twitter_url = ?
                WHERE id = ?
            ");
            $upd->execute([
                $clean['name'],
                $clean['slug'],
                $clean['cert_code'],
                $clean['contact_email'],
                $clean['social_links'],
                $newLogoPath,
                $newBannerPath,
                $hubClean['bio'],
                $hubClean['meta_wiki_url'],
                $hubClean['telegram_url'],
                $hubClean['twitter_url'],
                $orgId,
            ]);

            // Delete old files only after the row safely points at the new ones.
            if ($newLogoPath !== $org['logo_path']) {
                org_delete_logo($orgId, $org['logo_path'], $rootDir);
            }
            if ($newBannerPath !== ($org['cover_banner_path'] ?? null)) {
                hub_delete_banner($orgId, $org['cover_banner_path'] ?? null, $rootDir);
            }

            log_audit_action($pdo, 'Updated Organization', "Organization ID: {$orgId}");

            $redirectUrl = 'organization_settings.php?' . ($isSuper && $requestedOrgId > 0 ? "org_id={$orgId}&saved=1" : "saved=1");
            header("Location: " . $redirectUrl);
            exit;
        } catch (PDOException $e) {
            // Lost a race with another admin taking the same slug/code.
            if ($storedNewLogo !== null) {
                org_delete_logo($orgId, $storedNewLogo, $rootDir);
            }
            if ($storedNewBanner !== null) {
                hub_delete_banner($orgId, $storedNewBanner, $rootDir);
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

$logoUrl   = !empty($org['logo_path'])          ? '../' . $org['logo_path']          : null;
$bannerUrl = !empty($org['cover_banner_path']) ? '../' . $org['cover_banner_path'] : null;
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(i18n_get_lang()) ?>" dir="<?= i18n_get_dir() ?>">
<head>
    <link rel="icon" type="image/png" href="../assets/DCW_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __e('admin.org-settings.page-title') ?></title>
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
    <?php i18n_lang_switcher_css(); ?>
    <style>
        .field-error { color: #c0392b; font-size: 12px; margin-top: 4px; display: block; }
        .field-hint { color: #777; font-size: 12px; margin-top: 4px; display: block; }
        .form-group input.has-error, .form-group textarea.has-error { border-color: #c0392b; }
        /* style.css styles text/email/etc. inputs but not type=url; match them. */
        .form-group input[type="url"] { width: 100%; padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; font-family: inherit; font-size: 14px; background: #f8fafc; color: #1e293b; transition: all 0.2s; }
        .form-group input[type="url"]:focus { outline: none; border-color: var(--primary-color); background: #ffffff; box-shadow: 0 0 0 3px rgba(16, 107, 154, 0.15); }
        .form-group textarea { width: 100%; padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; font-family: inherit; font-size: 14px; background: #f8fafc; color: #1e293b; transition: all 0.2s; resize: vertical; }
        .form-group textarea:focus { outline: none; border-color: var(--primary-color); background: #ffffff; box-shadow: 0 0 0 3px rgba(16, 107, 154, 0.15); }
        .logo-preview { max-height: 80px; max-width: 200px; background: #f4f5f7; padding: 6px; border-radius: 6px; border: 1px solid #ddd; display: block; margin-bottom: 8px; }
        .banner-preview { max-width: 100%; max-height: 180px; border-radius: 8px; border: 1px solid #e2e8f0; display: block; margin-bottom: 8px; object-fit: cover; }
        .char-count { font-size: 11px; color: #94a3b8; float: right; }
    </style>
</head>
<body>

<div class="navbar">
    <div style="display: flex; align-items: center; gap: 15px;">
        <img src="../assets/DCW_logo.png" alt="DCW Logo" width="35" height="35" decoding="async" style="height: 35px; width: 35px; background: white; padding: 2px; border-radius: 50%;">
        <span style="font-size: 18px; font-weight: bold; letter-spacing: 0.5px;"><?= __e('admin.org-settings.nav-title') ?></span>
        <?php if ($isSuper): ?>
            <span style="background: #fef3c7; color: #92400e; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; text-transform: uppercase;">Super Admin</span>
        <?php endif; ?>
    </div>
    <div style="display: flex; align-items: center; gap: 15px;">
        <?php i18n_lang_switcher(); ?>
        <a href="dashboard.php"><?= __e('admin.common.nav.dashboard') ?></a>
        <?php if ($isSuper): ?>
            <a href="manage_organizations.php"><?= __e('admin.common.nav.manage-orgs') ?></a>
        <?php endif; ?>
        <a href="manage_users.php"><?= __e('admin.common.nav.manage-users') ?></a>
        <a href="logout.php"><?= __e('admin.common.nav.logout') ?></a>
    </div>
</div>

<div class="container" style="max-width: 720px;">
    <?php if ($isSuper && $requestedOrgId > 0): ?>
        <div style="background: #e0f2fe; border: 1px solid #bae6fd; color: #0369a1; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
            <span><strong>Super Admin Mode:</strong> Editing organization #<?= (int)$orgId ?> (<?= htmlspecialchars($org['name']) ?>)</span>
            <a href="manage_organizations.php" style="color: #0284c7; font-weight: 600; text-decoration: none;">&larr; Back to Organizations</a>
        </div>
    <?php endif; ?>

    <h2 style="margin-top: 0;"><?= __e('admin.org-settings.heading') ?></h2>
    <p style="font-size: 14px; color: #555;"><?= __e('admin.org-settings.intro') ?></p>

    <form method="POST" action="<?= $isSuper && $requestedOrgId > 0 ? 'organization_settings.php?org_id=' . (int)$orgId : '' ?>" enctype="multipart/form-data" class="upload-box">
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

        <!-- ── Hub Profile (Issue #160) ──────────────────────────────────── -->
        <h3 style="margin: 28px 0 5px;"><?= __e('admin.org-settings.section.hub-profile') ?></h3>
        <p style="font-size: 13px; color: #555; margin-top: 0;"><?= __e('admin.org-settings.hub-profile.desc') ?></p>

        <div class="form-group">
            <label for="hub_bio">
                <?= __e('admin.org-settings.hub-profile.label.bio') ?>
                <span class="char-count" id="bioCount"><?= mb_strlen($form['hub_bio']) ?>/500</span>
            </label>
            <textarea id="hub_bio" name="hub_bio" rows="3" maxlength="500"
                      class="<?= isset($fieldErrors['hub_bio']) ? 'has-error' : '' ?>"
                      placeholder="<?= __e('admin.org-settings.hub-profile.placeholder.bio') ?>"
                      oninput="document.getElementById('bioCount').textContent = this.value.length + '/500'"><?= htmlspecialchars($form['hub_bio']) ?></textarea>
            <span class="field-hint"><?= __e('admin.org-settings.hub-profile.hint.bio') ?></span>
            <?php if (isset($fieldErrors['hub_bio'])): ?>
                <span class="field-error"><?= __e('admin.org-settings.hub-profile.error.bio-too-long') ?></span>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label for="hub_meta_wiki_url"><?= __e('admin.org-settings.hub-profile.label.meta-wiki') ?></label>
            <input type="url" id="hub_meta_wiki_url" name="hub_meta_wiki_url" maxlength="255"
                   class="<?= isset($fieldErrors['hub_meta_wiki_url']) ? 'has-error' : '' ?>"
                   placeholder="https://meta.wikimedia.org/wiki/..."
                   value="<?= htmlspecialchars($form['hub_meta_wiki_url']) ?>">
            <?php if (isset($fieldErrors['hub_meta_wiki_url'])): ?>
                <span class="field-error"><?= __e('admin.org-settings.hub-profile.error.url-invalid') ?></span>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label for="hub_telegram_url"><?= __e('admin.org-settings.hub-profile.label.telegram') ?></label>
            <input type="url" id="hub_telegram_url" name="hub_telegram_url" maxlength="255"
                   class="<?= isset($fieldErrors['hub_telegram_url']) ? 'has-error' : '' ?>"
                   placeholder="https://t.me/yourcommunity"
                   value="<?= htmlspecialchars($form['hub_telegram_url']) ?>">
            <?php if (isset($fieldErrors['hub_telegram_url'])): ?>
                <span class="field-error"><?= __e('admin.org-settings.hub-profile.error.url-invalid') ?></span>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label for="hub_twitter_url"><?= __e('admin.org-settings.hub-profile.label.twitter') ?></label>
            <input type="url" id="hub_twitter_url" name="hub_twitter_url" maxlength="255"
                   class="<?= isset($fieldErrors['hub_twitter_url']) ? 'has-error' : '' ?>"
                   placeholder="https://x.com/yourcommunity"
                   value="<?= htmlspecialchars($form['hub_twitter_url']) ?>">
            <?php if (isset($fieldErrors['hub_twitter_url'])): ?>
                <span class="field-error"><?= __e('admin.org-settings.hub-profile.error.url-invalid') ?></span>
            <?php endif; ?>
        </div>

        <!-- Cover Banner -->
        <h3 style="margin: 25px 0 5px;"><?= __e('admin.org-settings.section.cover-banner') ?></h3>
        <div class="form-group">
            <?php if ($bannerUrl): ?>
                <img class="banner-preview" src="<?= htmlspecialchars($bannerUrl) ?>" alt="<?= __e('admin.org-settings.banner.current-alt') ?>">
                <label style="font-weight: normal; font-size: 13px; display: block; margin-bottom: 10px;">
                    <input type="checkbox" name="remove_banner" value="1">
                    <?= __e('admin.org-settings.banner.remove') ?>
                </label>
            <?php else: ?>
                <span class="field-hint" style="margin-bottom: 8px;"><?= __e('admin.org-settings.banner.none') ?></span>
            <?php endif; ?>
            <input type="file" id="cover_banner" name="cover_banner" accept="image/png,image/jpeg,image/webp">
            <span class="field-hint"><?= __e('admin.org-settings.hint.cover-banner') ?></span>
            <?php if (isset($fieldErrors['cover_banner'])): ?>
                <span class="field-error"><?= __e('admin.org-settings.hub-profile.error.' . $fieldErrors['cover_banner']) ?></span>
            <?php endif; ?>
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
