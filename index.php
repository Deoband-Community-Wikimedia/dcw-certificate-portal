<?php
require_once 'config.php';
require_once 'helpers.php';

invitation_ensure_schema($pdo);

$basePath = '';
if (isset($_SERVER['SCRIPT_NAME'])) {
    $basePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    if ($basePath === '/' || $basePath === '.') {
        $basePath = '';
    }
}

$communitySlug = trim($_GET['community_slug'] ?? '');
$eventSlug = trim($_GET['event_slug'] ?? '');

// Clean Redirect: /c/dcw (and /c/dcw/e/<event>) redirects to root or /e/<event>
if ($communitySlug === 'dcw') {
    if ($eventSlug !== '') {
        header("Location: " . ($basePath !== '' ? $basePath : '') . "/e/" . rawurlencode($eventSlug), true, 302);
    } else {
        header("Location: " . ($basePath !== '' ? $basePath : '') . "/", true, 302);
    }
    exit;
}

function buildCurrentUrl(array $extraParams = []): string {
    global $communitySlug, $eventSlug, $basePath;
    $isCleanRoute = (isset($_SERVER['REQUEST_URI']) && (strpos($_SERVER['REQUEST_URI'], '/c/') !== false || strpos($_SERVER['REQUEST_URI'], '/e/') !== false));
    $prefix = ($basePath !== '') ? $basePath : '';

    if ($isCleanRoute) {
        $query = !empty($extraParams) ? '?' . http_build_query($extraParams) : '';
        if ($communitySlug !== '' && $communitySlug !== 'dcw' && $eventSlug !== '') {
            return $prefix . '/c/' . rawurlencode($communitySlug) . '/e/' . rawurlencode($eventSlug) . $query;
        } elseif ($communitySlug !== '' && $communitySlug !== 'dcw') {
            return $prefix . '/c/' . rawurlencode($communitySlug) . $query;
        } elseif ($eventSlug !== '') {
            return $prefix . '/e/' . rawurlencode($eventSlug) . $query;
        }
    }

    $params = [];
    if ($communitySlug !== '' && $communitySlug !== 'dcw') $params['community_slug'] = $communitySlug;
    if ($eventSlug !== '') $params['event_slug'] = $eventSlug;
    $merged = array_merge($params, $extraParams);
    return $prefix . '/index.php' . (!empty($merged) ? '?' . http_build_query($merged) : '');
}

// Handle Verify Search
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify') {
    $vId = trim($_POST['verify_id'] ?? '');
    if ($vId) {
        header("Location: " . $basePath . "/verify/" . urlencode($vId));
        exit;
    }
}

// Handle OTP Verification submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_otp') {
    if (!isset($_SESSION['pending_otp'])) {
        header("Location: " . buildCurrentUrl());
        exit;
    }

    $pending = $_SESSION['pending_otp'];

    // Revoked after the code was requested (issue #159): never authorize the claim.
    if (certificate_is_revoked($pdo, $pending['certificate_id'])) {
        unset($_SESSION['pending_otp']);
        header("Location: " . buildCurrentUrl(['error' => __('page.claim.error.revoked')]));
        exit;
    }

    $code = trim($_POST['otp_code'] ?? '');

    if ($code === '' || strlen($code) < 6) {
        header("Location: " . buildCurrentUrl(['step' => 'otp', 'error' => __('otp.error.invalid-code', ['remaining' => 5])]));
        exit;
    }

    $result = otp_verify_code($pdo, $pending['certificate_id'], $pending['recipient_email'], $code);
    if ($result['success']) {
        otp_authorize_claim($pending['certificate_id']);
        $certId = $pending['certificate_id'];
        unset($_SESSION['pending_otp']);
        header("Location: " . $basePath . "/success.php?id=" . urlencode($certId));
        exit;
    } else {
        $errType = $result['error'];
        if ($errType === 'expired') {
            $errMsg = __('otp.error.expired');
        } elseif ($errType === 'too_many_attempts') {
            $errMsg = __('otp.error.too-many-attempts');
        } else {
            $errMsg = __('otp.error.invalid-code', ['remaining' => $result['attempts_remaining']]);
        }
        header("Location: " . buildCurrentUrl(['step' => 'otp', 'error' => $errMsg]));
        exit;
    }
}

// Handle OTP Resend request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend_otp') {
    if (!isset($_SESSION['pending_otp'])) {
        header("Location: " . buildCurrentUrl());
        exit;
    }

    $pending = $_SESSION['pending_otp'];

    // Revoked after the code was requested (issue #159): do not send another code.
    if (certificate_is_revoked($pdo, $pending['certificate_id'])) {
        unset($_SESSION['pending_otp']);
        header("Location: " . buildCurrentUrl(['error' => __('page.claim.error.revoked')]));
        exit;
    }

    $timeSince = time() - ($pending['last_sent_at'] ?? 0);
    if ($timeSince < 60) {
        $wait = 60 - $timeSince;
        header("Location: " . buildCurrentUrl(['step' => 'otp', 'error' => __('otp.error.cooldown', ['seconds' => $wait])]));
        exit;
    }

    $clientIp = otp_client_ip();
    $rateCheck = otp_is_rate_limited($pdo, $clientIp, $pending['recipient_email']);
    if ($rateCheck['limited']) {
        header("Location: " . buildCurrentUrl(['step' => 'otp', 'error' => __('otp.error.rate-limited')]));
        exit;
    }

    $newCode = otp_generate_code(6);
    otp_create_record($pdo, $pending['certificate_id'], $pending['recipient_email'], $newCode, $clientIp);
    $org = get_organization($pdo, (int)($pending['organization_id'] ?? 1));
    sendCertificateOtpEmail($pdo, $pending['certificate_id'], $pending['recipient_email'], $pending['full_name'], $pending['event_name'], $newCode, $org);

    $_SESSION['pending_otp']['last_sent_at'] = time();
    header("Location: " . buildCurrentUrl(['step' => 'otp', 'msg' => __('otp.msg.resent')]));
    exit;
}

// Handle OTP Cancel / Start Over
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_otp') {
    unset($_SESSION['pending_otp']);
    header("Location: " . buildCurrentUrl());
    exit;
}

// Resolve Organization & Direct Event
$rootOrg = get_organization($pdo, 1);
if (!$rootOrg) {
    $rootOrg = get_default_organization();
}

$targetOrg = null;
$hubNotFound = false;
if ($communitySlug !== '') {
    $stmtOrg = $pdo->prepare("SELECT * FROM organizations WHERE slug = ? AND is_active = 1");
    $stmtOrg->execute([$communitySlug]);
    $targetOrg = $stmtOrg->fetch();
    if (!$targetOrg) {
        $hubNotFound = true;
    } elseif ((int)$targetOrg['id'] === 1) {
        // Redirection guard: Deoband Community Wikimedia is the root portal
        if ($eventSlug !== '') {
            header("Location: " . ($basePath !== '' ? $basePath : '') . "/e/" . rawurlencode($eventSlug), true, 302);
        } else {
            header("Location: " . ($basePath !== '' ? $basePath : '') . "/", true, 302);
        }
        exit;
    }
}

$directEvent = null;
$eventNotFound = false;
if ($eventSlug !== '') {
    if ($hubNotFound) {
        $eventNotFound = true;
    } else {
        if (ctype_digit($eventSlug)) {
            $stmtEv = $pdo->prepare("
                SELECT e.*, o.name as org_name, o.slug as org_slug, o.logo_path as org_logo, o.cert_code as org_code
                FROM events e
                JOIN organizations o ON e.organization_id = o.id
                WHERE (e.slug = ? OR e.id = ?) AND o.is_active = 1
            ");
            $stmtEv->execute([$eventSlug, (int)$eventSlug]);
        } else {
            $stmtEv = $pdo->prepare("
                SELECT e.*, o.name as org_name, o.slug as org_slug, o.logo_path as org_logo, o.cert_code as org_code
                FROM events e
                JOIN organizations o ON e.organization_id = o.id
                WHERE e.slug = ? AND o.is_active = 1
            ");
            $stmtEv->execute([$eventSlug]);
        }
        $directEvent = $stmtEv->fetch();
        if (!$directEvent) {
            $eventNotFound = true;
        } else {
            if ($targetOrg) {
                // Tenant Mismatch Guard: Hierarchical route /c/<comm>/e/<event> must belong to that community
                if ((int)$directEvent['organization_id'] !== (int)$targetOrg['id']) {
                    $eventNotFound = true;
                    $directEvent = null;
                }
            } else {
                $targetOrg = get_organization($pdo, (int)$directEvent['organization_id']);
            }
        }
    }
}

// Default to root organization (DCW) when on root portal
if ($targetOrg === null && !$hubNotFound) {
    $targetOrg = $rootOrg;
}

$isDirectEventClaim = ($directEvent !== null && !$eventNotFound);
$isCommunityHub = ($targetOrg !== null && (int)$targetOrg['id'] !== 1 && !$isDirectEventClaim && !$hubNotFound);
$isRootPortal = (!$isCommunityHub && !$isDirectEventClaim && !$hubNotFound && !$eventNotFound);

// Handle initial claim form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'claim') {
    $eventId = $isDirectEventClaim ? (int)$directEvent['id'] : ($_POST['event_id'] ?? null);
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($eventId && $fullName && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare("
            SELECT ep.certificate_id, ep.event_id, ep.status, p.full_name, p.email, e.name as event_name, e.organization_id
            FROM participants p
            JOIN event_participants ep ON p.id = ep.participant_id
            JOIN events e ON ep.event_id = e.id
            WHERE p.full_name = ? AND p.email = ? AND ep.event_id = ?
        ");
        $stmt->execute([$fullName, $email, $eventId]);
        $certData = $stmt->fetch();

        // Cross-tenant guard: verify event belongs to current targetOrg
        if ($certData && $targetOrg && (int)$certData['organization_id'] !== (int)$targetOrg['id']) {
            $certData = false;
        }

        // Revoked certificates (issue #159) cannot be claimed: no OTP is sent.
        if ($certData && ($certData['status'] ?? 'active') === 'revoked') {
            header("Location: " . buildCurrentUrl(['error' => __('page.claim.error.revoked')]));
            exit;
        }

        if ($certData) {
            $clientIp = otp_client_ip();
            $rateCheck = otp_is_rate_limited($pdo, $clientIp, $email);
            if ($rateCheck['limited']) {
                header("Location: " . buildCurrentUrl(['error' => __('otp.error.rate-limited')]));
                exit;
            }

            $otpCode = otp_generate_code(6);
            otp_create_record($pdo, $certData['certificate_id'], $certData['email'], $otpCode, $clientIp);
            $org = get_organization($pdo, (int)($certData['organization_id'] ?? 1));
            sendCertificateOtpEmail($pdo, $certData['certificate_id'], $certData['email'], $certData['full_name'], $certData['event_name'], $otpCode, $org);

            $_SESSION['pending_otp'] = [
                'certificate_id'  => $certData['certificate_id'],
                'recipient_email' => $certData['email'],
                'full_name'       => $certData['full_name'],
                'event_name'      => $certData['event_name'],
                'organization_id' => (int)($certData['organization_id'] ?? 1),
                'created_at'      => time(),
                'last_sent_at'    => time(),
            ];

            header("Location: " . buildCurrentUrl(['step' => 'otp', 'msg' => __('otp.msg.sent')]));
            exit;
        } else {
            header("Location: " . buildCurrentUrl(['error' => __('page.claim.error.not-found')]));
            exit;
        }
    } else {
        header("Location: " . buildCurrentUrl(['error' => __('page.claim.error.required')]));
        exit;
    }
}

// Guard OTP verification step: if step=otp but no pending OTP session exists, redirect
$isOtpStep = (isset($_GET['step']) && $_GET['step'] === 'otp');
if ($isOtpStep && !isset($_SESSION['pending_otp'])) {
    header("Location: " . buildCurrentUrl());
    exit;
}

// Fetch active communities ordered by activity (DCW first, then most events/participants)
$stmtAllOrgs = $pdo->query("
    SELECT 
        o.id, o.name, o.slug, o.cert_code, o.logo_path,
        COUNT(DISTINCT e.id) AS event_count,
        COUNT(DISTINCT ep.participant_id) AS participant_count
    FROM organizations o
    LEFT JOIN events e ON e.organization_id = o.id
    LEFT JOIN event_participants ep ON ep.event_id = e.id
    WHERE o.is_active = 1
    GROUP BY o.id, o.name, o.slug, o.cert_code, o.logo_path
    ORDER BY (o.id = 1) DESC, event_count DESC, participant_count DESC, o.name ASC
");
$activeCommunities = $stmtAllOrgs->fetchAll();

// Fetch events scoped specifically to targetOrg
$events = [];
$eventsByCategory = [];
$hasCategorisedEvents = false;

if ($targetOrg && !$hubNotFound && !$eventNotFound) {
    $stmt = $pdo->prepare("
        SELECT e.id, e.name, e.slug, e.category, e.organization_id, o.name as org_name, o.slug as org_slug
        FROM events e
        JOIN organizations o ON e.organization_id = o.id
        WHERE e.organization_id = ? AND o.is_active = 1
        ORDER BY (e.category IS NULL), e.category ASC, e.name ASC
    ");
    $stmt->execute([(int)$targetOrg['id']]);
    $events = $stmt->fetchAll();

    foreach ($events as $e) {
        if (!empty($e['category'])) {
            $hasCategorisedEvents = true;
            $cat = $e['category'];
        } else {
            $cat = 'Other Events';
        }
        $eventsByCategory[$cat][] = $e;
    }
}

// Page branding values
$currentOrgName = $targetOrg['name'] ?? __('site.name');
$currentOrgLogo = !empty($targetOrg['logo_path']) && file_exists($targetOrg['logo_path'])
    ? $basePath . '/' . $targetOrg['logo_path']
    : $basePath . '/assets/DCW_logo.png';
$currentOrgUrl = (!empty($targetOrg['home_url']) && function_exists('org_is_http_url') && org_is_http_url($targetOrg['home_url']))
    ? $targetOrg['home_url']
    : ORG_URL_HOME;

$pageTitle = __e('site.portal-badge') . ' - ' . htmlspecialchars($currentOrgName);
if ($isDirectEventClaim && $directEvent) {
    $pageTitle = htmlspecialchars($directEvent['name']) . ' - ' . __e('page.claim.title') . ' - ' . htmlspecialchars($currentOrgName);
} elseif ($isCommunityHub && $targetOrg) {
    $pageTitle = htmlspecialchars($targetOrg['name']) . ' - ' . __e('hub.verified-affiliate');
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(i18n_get_lang(), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>" dir="<?= i18n_get_dir() ?>">
<head>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars($currentOrgLogo) ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

        :root {
            --primary-color: #106b9a;
            --primary-hover: #0c567a;
            --secondary-color: #97161b;
            --background: #f4f6f8;
            --card-bg: #ffffff;
            --text-color: #1e293b;
            --border-color: #e2e8f0;
        }

        body {
            margin: 0; padding: 0;
            font-family: 'Inter', sans-serif;
            background-color: var(--background);
            color: var(--text-color);
            display: flex; flex-direction: column; min-height: 100vh;
        }

        .site-header {
            background-color: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            padding: 12px 24px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .header-container {
            max-width: 1060px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .header-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .brand-link {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: var(--primary-color);
        }
        .brand-logo {
            height: 40px;
            width: auto;
            max-width: 160px;
            object-fit: contain;
        }
        .brand-name {
            font-size: 17px;
            font-weight: 700;
            letter-spacing: -0.01em;
            color: #0f172a;
        }
        .portal-badge {
            background-color: #f1f5f9;
            color: var(--primary-color);
            font-size: 11px;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
        }
        .header-nav {
            display: flex;
            gap: 20px;
            align-items: center;
        }
        .header-nav a {
            text-decoration: none;
            color: #475569;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.2s;
        }
        .header-nav a:hover {
            color: var(--primary-color);
        }

        .main-wrapper {
            max-width: 1060px;
            margin: 30px auto;
            padding: 0 20px;
            width: 100%;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            gap: 30px;
        }

        .columns-grid {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }

        .container {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 32px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            flex: 1;
            min-width: 300px;
            box-sizing: border-box;
        }

        h1 {
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
            margin-top: 0;
            margin-bottom: 8px;
            text-align: center;
        }
        .subtitle {
            font-size: 14px;
            color: #64748b;
            text-align: center;
            margin-bottom: 24px;
            line-height: 1.5;
        }

        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
            color: #334155;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
            outline: none;
            transition: border-color 0.2s;
            font-family: inherit;
        }
        .form-group input:focus, .form-group select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(16, 107, 154, 0.1);
        }

        .btn-submit {
            width: 100%;
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 13px 20px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background 0.2s;
        }
        .btn-submit:hover {
            background-color: var(--primary-hover);
        }
        .btn-secondary {
            background-color: #334155;
        }
        .btn-secondary:hover {
            background-color: #1e293b;
        }

        .error-message {
            background-color: #fee2e2;
            border: 1px solid #fca5a5;
            color: #991b1b;
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 20px;
        }
        .success-message {
            background-color: #dcfce7;
            border: 1px solid #86efac;
            color: #166534;
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 20px;
        }

        /* Partner Communities Dropdown */
        .comm-switcher {
            position: relative;
            display: inline-block;
            font-family: inherit;
            text-align: left;
        }
        .comm-switcher-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 13px;
            font-weight: 500;
            color: #334155;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
            transition: all 0.2s ease;
            white-space: nowrap;
            line-height: 1.4;
        }
        .comm-switcher-btn:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
            background: #f8fafc;
        }
        .comm-switcher.open .comm-switcher-btn {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(16, 107, 154, 0.15);
        }
        .comm-switcher-caret {
            font-size: 10px;
            opacity: 0.7;
            transition: transform 0.2s ease;
        }
        .comm-switcher.open .comm-switcher-caret {
            transform: rotate(180deg);
        }
        .comm-switcher-menu {
            display: none;
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.06);
            padding: 6px;
            margin: 0;
            list-style: none;
            z-index: 1000;
            min-width: 220px;
        }
        .comm-switcher.open .comm-switcher-menu {
            display: block;
        }
        .comm-switcher-menu li a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 500;
            color: #334155;
            text-decoration: none;
            border-radius: 6px;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .comm-switcher-menu li a:hover {
            background: #f1f5f9;
            color: var(--primary-color);
        }
        .comm-switcher-menu li a.active {
            background: #f0f9ff;
            color: var(--primary-color);
            font-weight: 600;
        }

        /* Clickable Community Navigation Bar */
        .community-nav-bar {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
        }
        .community-nav-label {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        .community-nav-list {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin: 0;
            padding: 0;
        }
        .community-nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 20px;
            padding: 6px 14px;
            font-size: 13px;
            font-weight: 500;
            color: #334155;
            text-decoration: none;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .community-nav-btn:hover {
            background: #f1f5f9;
            border-color: var(--primary-color);
            color: var(--primary-color);
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
        }
        .community-nav-btn.active {
            background: #106b9a;
            border-color: #106b9a;
            color: #ffffff;
            font-weight: 600;
            box-shadow: 0 2px 6px rgba(16, 107, 154, 0.25);
        }
        .community-nav-logo {
            height: 18px;
            width: auto;
            max-width: 22px;
            object-fit: contain;
            border-radius: 3px;
            background: #ffffff;
            padding: 1px;
        }
        .community-nav-count {
            font-size: 11px;
            padding: 1px 7px;
            border-radius: 10px;
            background: #e2e8f0;
            color: #475569;
            font-weight: 600;
        }
        .community-nav-btn.active .community-nav-count {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }

        .pill-btn {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 6px 14px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .pill-btn:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        /* Community Hub Showcase */
        .hub-hero {
            background: linear-gradient(135deg, #ffffff, #f8fafc);
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 24px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
        }
        .hub-identity {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        .hub-logo {
            height: 70px;
            width: auto;
            max-width: 180px;
            object-fit: contain;
            background: white;
            padding: 6px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .hub-meta h1 {
            text-align: left;
            margin: 0 0 6px 0;
            font-size: 24px;
        }
        .badge-affiliate {
            background: #dbeafe;
            color: #1d4ed8;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .hub-events-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 15px;
        }
        .event-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 22px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 16px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .event-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.06);
            border-color: #cbd5e1;
        }
        .event-card-cat {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #0284c7;
            background: #f0f9ff;
            padding: 3px 8px;
            border-radius: 4px;
            align-self: flex-start;
        }
        .event-card-title {
            font-size: 17px;
            font-weight: 700;
            color: #0f172a;
            margin: 6px 0 4px 0;
            line-height: 1.3;
        }
        .event-card-date {
            font-size: 13px;
            color: #64748b;
        }
        .btn-claim-card {
            background: #106b9a;
            color: white;
            text-decoration: none;
            padding: 10px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
            display: block;
            transition: background 0.2s;
        }
        .btn-claim-card:hover {
            background: #0c567a;
        }

        /* Direct Claim locked card */
        .direct-claim-header {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 10px;
            padding: 18px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
        }

        .otp-actions {
            display: flex;
            gap: 12px;
            margin-top: 16px;
        }
        .btn-outline {
            background: transparent;
            border: 1.5px solid #cbd5e1;
            color: #475569;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: all 0.2s ease;
            text-align: center;
            box-sizing: border-box;
            font-family: inherit;
        }
        .btn-outline:hover {
            border-color: #94a3b8;
            background: #f8fafc;
            color: #1e293b;
        }

        .site-footer {
            background-color: #0f172a;
            color: #94a3b8;
            padding: 40px 20px 30px;
            border-top: 1px solid #1e293b;
            margin-top: auto;
            font-size: 14px;
        }
        .footer-container {
            max-width: 1060px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 30px;
        }
        .footer-brand {
            display: flex;
            align-items: flex-start;
            gap: 20px;
        }
        .footer-logo {
            height: 60px;
            width: auto;
            background: #ffffff;
            padding: 4px;
            border-radius: 8px;
            flex-shrink: 0;
        }
        .footer-blurb {
            line-height: 1.6;
            color: #cbd5e1;
        }
        .footer-blurb a { color: #38bdf8; text-decoration: none; }
        .footer-middle {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            border-top: 1px solid #1e293b;
            border-bottom: 1px solid #1e293b;
            padding: 20px 0;
        }
        .footer-socials { display: flex; gap: 15px; }
        .footer-socials a { color: #94a3b8; transition: color 0.2s; }
        .footer-socials a:hover { color: #ffffff; }
        .footer-links { display: flex; gap: 20px; flex-wrap: wrap; }
        .footer-links a { color: #94a3b8; text-decoration: none; transition: color 0.2s; }
        .footer-links a:hover { color: #ffffff; }
        .footer-bottom { text-align: center; font-size: 13px; color: #64748b; }
        .footer-bottom a { color: #38bdf8; text-decoration: none; }
    </style>
    <?php i18n_lang_switcher_css(); ?>
</head>
<body>

    <!-- Unified Header -->
    <header class="site-header">
        <div class="header-container">
            <div class="header-brand">
                <a href="<?= htmlspecialchars($currentOrgUrl) ?>" class="brand-link">
                    <img src="<?= htmlspecialchars($currentOrgLogo) ?>" alt="Logo" class="brand-logo">
                    <span class="brand-name"><?= htmlspecialchars($currentOrgName) ?></span>
                </a>
                <span class="portal-badge"><?= __e('site.portal-badge') ?></span>
            </div>
            <nav class="header-nav">
                <?php if (count($activeCommunities) > 1): ?>
                    <div class="comm-switcher" role="navigation" aria-label="<?= __e('nav.partner-communities') ?>">
                        <button class="comm-switcher-btn" aria-haspopup="true" aria-expanded="false" onclick="this.parentElement.classList.toggle('open')" type="button">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                            </svg>
                            <span><?= __e('nav.partner-communities') ?></span>
                            <span class="comm-switcher-caret" aria-hidden="true">▾</span>
                        </button>
                        <ul class="comm-switcher-menu" role="menu">
                            <?php foreach ($activeCommunities as $comm): ?>
                                <?php
                                    $isDcw = ((int)$comm['id'] === 1 || $comm['slug'] === 'dcw');
                                    $commUrl = $isDcw ? ($basePath !== '' ? $basePath : '') . '/' : $basePath . '/c/' . rawurlencode($comm['slug']);
                                    $isCurrent = ($targetOrg && (int)$targetOrg['id'] === (int)$comm['id']);
                                ?>
                                <li role="none">
                                    <a href="<?= htmlspecialchars($commUrl) ?>" role="menuitem" <?= $isCurrent ? 'class="active" aria-current="true"' : '' ?>>
                                        <?php if (!empty($comm['logo_path']) && file_exists($comm['logo_path'])): ?>
                                            <img src="<?= htmlspecialchars($basePath . '/' . $comm['logo_path']) ?>" alt="" style="height: 16px; width: auto; max-width: 24px; object-fit: contain;">
                                        <?php endif; ?>
                                        <span><?= htmlspecialchars($comm['name']) ?></span>
                                        <?php if ($isCurrent): ?>
                                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                        <?php endif; ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <a href="<?= ORG_URL_ABOUT ?>" target="_blank"><?= __e('nav.about') ?></a>
                <a href="<?= ORG_URL_PROGRAMS ?>" target="_blank"><?= __e('nav.programs') ?></a>
                <a href="<?= $basePath ?>/admin/login.php"><?= __e('admin.login.page-title') ?></a>
            </nav>
            <?php i18n_lang_switcher(); ?>
        </div>
    </header>

    <div class="main-wrapper">

        <?php if ($hubNotFound): ?>
            <!-- 404: Hub Not Found -->
            <div class="container" style="text-align: center; padding: 48px 24px;">
                <div style="font-size: 40px; margin-bottom: 16px;">🏛️</div>
                <h1><?= __e('hub.not-found.title') ?></h1>
                <p class="subtitle"><?= __e('hub.not-found.desc') ?></p>
                <a href="<?= $basePath ?>/" class="btn-submit" style="display: inline-flex; width: auto; text-decoration: none;">
                    <?= __e('hub.back-to-portal') ?>
                </a>
            </div>

        <?php elseif ($eventNotFound): ?>
            <!-- 404: Event Not Found -->
            <div class="container" style="text-align: center; padding: 48px 24px;">
                <div style="font-size: 40px; margin-bottom: 16px;">📜</div>
                <h1><?= __e('direct-claim.event-not-found.title') ?></h1>
                <p class="subtitle"><?= __e('direct-claim.event-not-found.desc') ?></p>
                <a href="<?= $basePath ?>/" class="btn-submit" style="display: inline-flex; width: auto; text-decoration: none;">
                    <?= __e('hub.back-to-portal') ?>
                </a>
            </div>

        <?php elseif ($isOtpStep): ?>
            <!-- Step: OTP Verification -->
            <div class="container" style="max-width: 520px; margin: 0 auto;">
                <div style="text-align: center; margin-bottom: 15px;">
                    <svg viewBox="0 0 24 24" width="40" height="40" style="color: var(--primary-color);">
                        <path fill="currentColor" d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-1 6h2v2h-2V7zm0 4h2v6h-2v-6z"/>
                    </svg>
                </div>
                <h1><?= __e('otp.title') ?></h1>
                <div class="subtitle">
                    <?= __('otp.subtitle', ['email' => htmlspecialchars(otp_mask_email($_SESSION['pending_otp']['recipient_email'] ?? ''))]) ?>
                </div>

                <?php if (isset($_GET['msg'])): ?>
                    <div class="success-message"><?= htmlspecialchars($_GET['msg']) ?></div>
                <?php endif; ?>

                <?php if (isset($_GET['error'])): ?>
                    <div class="error-message"><?= htmlspecialchars($_GET['error']) ?></div>
                <?php endif; ?>

                <form action="" method="POST">
                    <input type="hidden" name="action" value="verify_otp">
                    <div class="form-group">
                        <label for="otp_code"><?= __e('otp.label.code') ?></label>
                        <input type="text" id="otp_code" name="otp_code" required maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" autofocus style="text-align: center; font-size: 26px; font-weight: 700; letter-spacing: 10px; font-family: monospace;">
                    </div>

                    <button type="submit" class="btn-submit">
                        <?= __e('otp.submit') ?>
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M5 12h14M12 5l7 7-7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                </form>

                <div class="otp-actions">
                    <form action="" method="POST" style="flex: 1;">
                        <input type="hidden" name="action" value="resend_otp">
                        <button type="submit" class="btn-outline"><?= __e('otp.resend') ?></button>
                    </form>
                    <form action="" method="POST" style="flex: 1;">
                        <input type="hidden" name="action" value="cancel_otp">
                        <button type="submit" class="btn-outline"><?= __e('otp.cancel') ?></button>
                    </form>
                </div>
            </div>

        <?php else: ?>
            <?php if ($isCommunityHub && $targetOrg): ?>
                <!-- Community Hub Showcase Header (/c/<slug>) -->
                <div class="hub-hero">
                    <div class="hub-identity">
                        <img src="<?= htmlspecialchars($currentOrgLogo) ?>" alt="Logo" class="hub-logo">
                        <div class="hub-meta">
                            <h1><?= htmlspecialchars($targetOrg['name']) ?></h1>
                            <span class="badge-affiliate">🛡️ <?= __e('hub.verified-affiliate') ?></span>
                            <span style="font-size: 13px; color: #64748b; margin-left: 8px;">(<?= htmlspecialchars($targetOrg['cert_code']) ?>)</span>
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <?php if (!empty($targetOrg['home_url']) && (!function_exists('org_is_http_url') || org_is_http_url($targetOrg['home_url']))): ?>
                            <a href="<?= htmlspecialchars($targetOrg['home_url']) ?>" target="_blank" class="pill-btn" style="text-decoration: none;">
                                🌐 Website
                            </a>
                        <?php endif; ?>
                        <a href="<?= $basePath ?>/" class="pill-btn" style="text-decoration: none;">
                            ← <?= htmlspecialchars($rootOrg['name'] ?? 'Deoband Community Wikimedia') ?>
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($activeCommunities)): ?>
                <!-- Clickable Affiliated Communities Bar -->
                <div class="community-nav-bar" role="navigation" aria-label="<?= __e('nav.communities') ?>">
                    <div class="community-nav-label">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                        </svg>
                        <span><?= __e('nav.communities') ?>:</span>
                    </div>
                    <div class="community-nav-list">
                        <?php foreach ($activeCommunities as $comm): ?>
                            <?php
                                $isDcw = ((int)$comm['id'] === 1 || $comm['slug'] === 'dcw');
                                $commUrl = $isDcw ? ($basePath !== '' ? $basePath : '') . '/' : $basePath . '/c/' . rawurlencode($comm['slug']);
                                $isCurrent = ($targetOrg && (int)$targetOrg['id'] === (int)$comm['id'] && !$isDirectEventClaim);
                                $commLogo = (!empty($comm['logo_path']) && (file_exists($comm['logo_path']) || file_exists(__DIR__ . '/' . $comm['logo_path'])))
                                    ? $basePath . '/' . ltrim($comm['logo_path'], '/')
                                    : $basePath . '/assets/DCW_logo.png';
                                $evCount = (int)($comm['event_count'] ?? 0);
                            ?>
                            <a href="<?= htmlspecialchars($commUrl) ?>" class="community-nav-btn <?= $isCurrent ? 'active' : '' ?>" title="<?= htmlspecialchars($comm['name']) . ($evCount > 0 ? ' (' . $evCount . ' ' . __e('hub.events') . ')' : '') ?>">
                                <img src="<?= htmlspecialchars($commLogo) ?>" alt="" class="community-nav-logo">
                                <span><?= htmlspecialchars($comm['name']) ?></span>
                                <?php if ($evCount > 0): ?>
                                    <span class="community-nav-count"><?= $evCount ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="columns-grid">
                <!-- Claim Certificate Column -->
                <div class="container">
                    <?php if ($isDirectEventClaim && $directEvent): ?>
                        <div class="direct-claim-header">
                            <div>
                                <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #0284c7;">
                                    <?= __e('direct-claim.organized-by') ?>:
                                </span>
                                <div style="font-size: 14px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px; margin-top: 2px;">
                                    <img src="<?= htmlspecialchars($currentOrgLogo) ?>" alt="Logo" style="height: 24px; width: auto;">
                                    <?php if ((int)$targetOrg['id'] === 1): ?>
                                        <a href="<?= $basePath ?>/" style="color: inherit; text-decoration: none;">
                                            <?= htmlspecialchars($directEvent['org_name']) ?>
                                        </a>
                                    <?php else: ?>
                                        <a href="<?= $basePath ?>/c/<?= urlencode($directEvent['org_slug']) ?>" style="color: inherit; text-decoration: none;">
                                            <?= htmlspecialchars($directEvent['org_name']) ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="event-card-cat"><?= htmlspecialchars(event_category_label($directEvent['category'] ?? 'Event')) ?></span>
                        </div>

                        <h1><?= htmlspecialchars($directEvent['name']) ?></h1>
                        <div class="subtitle">
                            <?= __e('page.claim.subtitle') ?>
                        </div>

                        <?php if (isset($_GET['error'])): ?>
                            <div class="error-message"><?= htmlspecialchars($_GET['error']) ?></div>
                        <?php endif; ?>

                        <form action="" method="POST">
                            <input type="hidden" name="action" value="claim">

                            <div class="form-group">
                                <label for="full_name"><?= __e('page.claim.label.name') ?></label>
                                <input type="text" id="full_name" name="full_name" required placeholder="<?= __e('page.claim.label.name-placeholder') ?>">
                            </div>

                            <div class="form-group">
                                <label for="email"><?= __e('page.claim.label.email') ?></label>
                                <input type="email" id="email" name="email" required placeholder="<?= __e('page.claim.label.email-placeholder') ?>">
                            </div>

                            <button type="submit" class="btn-submit">
                                <?= __e('page.claim.submit') ?>
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M5 12h14M12 5l7 7-7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                        </form>

                        <div style="text-align: center; margin-top: 20px;">
                            <?php if ((int)$targetOrg['id'] === 1): ?>
                                <a href="<?= $basePath ?>/" style="color: #0284c7; font-size: 13px; text-decoration: none; font-weight: 500;">
                                    ← <?= __('direct-claim.all-org-events', ['org' => htmlspecialchars($directEvent['org_name'])]) ?>
                                </a>
                            <?php else: ?>
                                <a href="<?= $basePath ?>/c/<?= urlencode($directEvent['org_slug']) ?>" style="color: #0284c7; font-size: 13px; text-decoration: none; font-weight: 500;">
                                    ← <?= __('direct-claim.all-org-events', ['org' => htmlspecialchars($directEvent['org_name'])]) ?>
                                </a>
                            <?php endif; ?>
                        </div>

                    <?php else: ?>
                        <div style="text-align: center; margin-bottom: 15px;">
                            <svg viewBox="0 0 24 24" width="40" height="40" style="color: var(--primary-color);">
                                <path fill="currentColor" d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
                            </svg>
                        </div>
                        <h1><?= __e('page.claim.title') ?></h1>
                        <div class="subtitle">
                            <?= __e('page.claim.subtitle') ?>
                        </div>

                        <?php if (isset($_GET['error'])): ?>
                            <div class="error-message"><?= htmlspecialchars($_GET['error']) ?></div>
                        <?php endif; ?>

                        <form action="" method="POST">
                            <input type="hidden" name="action" value="claim">

                            <?php if ($hasCategorisedEvents): ?>
                            <div class="form-group" id="category_group">
                                <label for="category_filter"><?= __e('page.claim.label.category') ?></label>
                                <select id="category_filter">
                                    <option value=""><?= __e('page.claim.label.category-placeholder') ?></option>
                                    <?php foreach (array_keys($eventsByCategory) as $catName): ?>
                                        <?php if ($catName !== 'Other Events'): ?>
                                            <option value="<?= htmlspecialchars($catName) ?>"><?= htmlspecialchars(event_category_label($catName)) ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php if (isset($eventsByCategory['Other Events'])): ?>
                                        <option value="Other Events"><?= htmlspecialchars(__('admin.event-form.option.uncategorised')) ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <div class="form-group">
                                <label for="event_id"><?= __e('page.claim.label.event') ?></label>
                                <select id="event_id" name="event_id" required>
                                    <option value=""><?= __e('page.claim.label.event-placeholder') ?></option>
                                    <?php if (empty($events)): ?>
                                        <option value="" disabled><?= __e('hub.no-events') ?></option>
                                    <?php else: ?>
                                        <?php foreach ($events as $e): ?>
                                            <option value="<?= htmlspecialchars($e['id']) ?>"
                                                    data-category="<?= htmlspecialchars(!empty($e['category']) ? $e['category'] : 'Other Events') ?>">
                                                <?= htmlspecialchars($e['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="full_name"><?= __e('page.claim.label.name') ?></label>
                                <input type="text" id="full_name" name="full_name" required placeholder="<?= __e('page.claim.label.name-placeholder') ?>">
                            </div>

                            <div class="form-group">
                                <label for="email"><?= __e('page.claim.label.email') ?></label>
                                <input type="email" id="email" name="email" required placeholder="<?= __e('page.claim.label.email-placeholder') ?>">
                            </div>

                            <button type="submit" class="btn-submit">
                                <?= __e('page.claim.submit') ?>
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M5 12h14M12 5l7 7-7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Verify Credential Column -->
                <div class="container" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                    <div style="text-align: center; margin-bottom: 15px;">
                        <svg viewBox="0 0 24 24" width="40" height="40" style="color: var(--secondary-color);">
                            <path fill="currentColor" d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/>
                        </svg>
                    </div>
                    <h1><?= __e('page.verify.title') ?></h1>
                    <div class="subtitle">
                        <?= __e('page.verify.label.cert-id') ?> — <?= __e('page.verify.submit') ?>
                    </div>

                    <form action="" method="POST">
                        <input type="hidden" name="action" value="verify">
                        <div class="form-group">
                            <label for="verify_id"><?= __e('page.verify.label.cert-id') ?></label>
                            <input type="text" id="verify_id" name="verify_id" required placeholder="<?= __e('page.verify.label.cert-id-placeholder') ?>">
                        </div>
                        <button type="submit" class="btn-submit btn-secondary">
                            <?= __e('page.verify.submit') ?>
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <!-- Unified Footer -->
    <footer class="site-footer">
        <div class="footer-container">
            <div class="footer-brand">
                <img src="<?= htmlspecialchars($currentOrgLogo) ?>" alt="Logo" class="footer-logo">
                <div class="footer-blurb">
                    <?= __e('footer.blurb') ?>
                </div>
            </div>
            <div class="footer-middle">
                <div class="footer-socials">
                    <a href="<?= ORG_URL_MASTODON ?>" target="_blank" title="Follow us on Mastodon">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M23.268 5.313c-.35-2.578-2.617-4.61-5.304-4.96C14.94.003 12 .003 12 .003s-2.94 0-5.964.35C3.352.703 1.085 2.735.736 5.313.382 7.912.35 10.825.35 12c0 1.175.032 4.088.386 6.687.35 2.578 2.617 4.61 5.304 4.96 3.023.35 5.96.35 5.96.35s2.937 0 5.96-.35c2.687-.35 4.954-2.735 5.304-4.96.354-2.6.386-5.512.386-6.687 0-1.175-.032-4.088-.386-6.687zM17.42 16.295h-2.316v-6.398c0-1.298-.553-1.956-1.656-1.956-1.22 0-1.83.79-1.83 2.37v3.473H9.3v-3.473c0-1.58-.61-2.37-1.83-2.37-1.103 0-1.656.658-1.656 1.956v6.398H3.502v-6.398c0-2.368 1.517-3.565 3.966-3.565 1.442 0 2.54.55 3.25 1.626L12 9.548l1.282-1.616c.71-1.077 1.808-1.626 3.25-1.626 2.45 0 3.966 1.197 3.966 3.565v6.398z"/></svg>
                    </a>
                    <a href="<?= ORG_URL_FACEBOOK ?>" target="_blank" title="Follow us on Facebook">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M22 12c0-5.52-4.48-10-10-10S2 6.48 2 12c0 4.84 3.44 8.87 8 9.8V15H8v-3h2V9.5C10 7.57 11.57 6 13.5 6H16v3h-2c-.55 0-1 .45-1 1v2h3v3h-3v6.95c4.56-.93 8-4.96 8-9.75z"/></svg>
                    </a>
                    <a href="<?= ORG_URL_INSTAGRAM ?>" target="_blank" title="Follow us on Instagram">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.051.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
                    </a>
                    <a href="<?= ORG_URL_LINKEDIN ?>" target="_blank" title="Follow us on LinkedIn">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.779-1.75-1.75s.784-1.75 1.75-1.75 1.75.779 1.75 1.75-.784 1.75-1.75 1.75zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"/></svg>
                    </a>
                </div>
                <div class="footer-links">
                    <a href="<?= ORG_URL_SUBSCRIBE ?>" target="_blank"><?= __e('footer.link.subscribe') ?></a>
                    <a href="<?= ORG_URL_MEMBERSHIP ?>" target="_blank"><?= __e('footer.link.membership') ?></a>
                    <a href="<?= ORG_URL_CONTACT ?>" target="_blank"><?= __e('footer.link.contact') ?></a>
                </div>
            </div>
            <div class="footer-bottom">
                &copy; <?= date('Y') ?> <a href="<?= htmlspecialchars($currentOrgUrl) ?>" target="_blank"><?= htmlspecialchars($currentOrgName) ?></a>. All Rights Reserved.<br>
                <span style="font-size: 12px; opacity: 0.7; display: block; margin-top: 8px;">
                    Powered by <a href="https://github.com/Deoband-Community-Wikimedia/dcw-certificate-portal" target="_blank" style="color: inherit; text-decoration: underline;">DCW Certificate Engine</a>
                </span>
            </div>
        </div>
    </footer>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const categoryFilter = document.getElementById('category_filter');
        const eventSelect = document.getElementById('event_id');
        if (eventSelect) {
            const allEventOptions = Array.from(eventSelect.querySelectorAll('option')).filter(function (opt) {
                return opt.value !== '';
            });
            const placeholderOption = eventSelect.querySelector('option[value=""]');

            function applyFilters() {
                const selectedCategory = categoryFilter ? categoryFilter.value.trim() : '';
                const currentSelectedValue = eventSelect.value;

                eventSelect.innerHTML = '';
                if (placeholderOption) {
                    eventSelect.appendChild(placeholderOption);
                }

                let matchCount = 0;
                allEventOptions.forEach(function (opt) {
                    const optCategory = opt.getAttribute('data-category') || '';
                    const matchesCat = (!selectedCategory || optCategory === selectedCategory);

                    if (matchesCat) {
                        eventSelect.appendChild(opt);
                        matchCount++;
                    }
                });

                if (matchCount === 0 && selectedCategory !== '') {
                    const noMatch = document.createElement('option');
                    noMatch.value = '';
                    noMatch.disabled = true;
                    noMatch.textContent = <?= json_encode(__('page.claim.label.no-events-category')) ?>;
                    eventSelect.appendChild(noMatch);
                }

                const stillValid = Array.from(eventSelect.options).some(function (o) {
                    return o.value === currentSelectedValue && o.value !== '';
                });
                if (stillValid) {
                    eventSelect.value = currentSelectedValue;
                } else {
                    eventSelect.value = '';
                }
            }

            if (categoryFilter) {
                categoryFilter.addEventListener('change', applyFilters);
            }
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function (e) {
            document.querySelectorAll('.comm-switcher.open, .lang-switcher.open').forEach(function (el) {
                if (!el.contains(e.target)) {
                    el.classList.remove('open');
                }
            });
        });
    });
    </script>
</body>
</html>