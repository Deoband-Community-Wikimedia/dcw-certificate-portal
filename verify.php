<?php
require_once 'config.php';

$certId = trim($_GET['id'] ?? $_GET['cert'] ?? '');
if (!$certId && isset($_SERVER['PATH_INFO'])) {
    $certId = trim($_SERVER['PATH_INFO'], '/');
}

if (!$certId) {
    header("Location: index.php");
    exit;
}

$basePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
if ($basePath === '/') $basePath = '';

$stmt = $pdo->prepare("
    SELECT p.full_name, e.name as event_name, e.certificate_issue_date, ep.created_at, er.role_name
    FROM event_participants ep
    JOIN participants p ON ep.participant_id = p.id
    JOIN events e ON ep.event_id = e.id
    LEFT JOIN event_roles er ON ep.role_id = er.id
    WHERE ep.certificate_id = ?
");
$stmt->execute([$certId]);
$certData = $stmt->fetch();

if (!$certData) {
    die(header("HTTP/1.0 404 Not Found"));
}

$issueSource = !empty($certData['certificate_issue_date']) ? $certData['certificate_issue_date'] : $certData['created_at'];
$issueDate = date('F j, Y', strtotime($issueSource));
$roleName = $certData['role_name'] ? " as " . htmlspecialchars($certData['role_name']) : "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="https://dcwwiki.org/images/5/56/DCW_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- Primary Meta Tags -->
    <title>Credential Verification - <?= htmlspecialchars($certData['full_name']) ?></title>
    <meta name="title" content="Verified Credential: <?= htmlspecialchars($certData['full_name']) ?> - <?= htmlspecialchars($certData['event_name']) ?>">
    <meta name="description" content="This official credential was securely issued by DCW. Verify the authenticity of this certificate online.">

    <!-- Open Graph / Facebook / LinkedIn -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://<?= htmlspecialchars($_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) ?>">
    <meta property="og:title" content="Verified Credential: <?= htmlspecialchars($certData['full_name']) ?> - <?= htmlspecialchars($certData['event_name']) ?>">
    <meta property="og:description" content="This official credential was securely issued by DCW. Verify the authenticity of this certificate online.">
    <meta property="og:image" content="https://<?= htmlspecialchars($_SERVER['HTTP_HOST']) . $basePath ?>/assets/DCW_logo.png">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="https://<?= htmlspecialchars($_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) ?>">
    <meta property="twitter:title" content="Verified Credential: <?= htmlspecialchars($certData['full_name']) ?> - <?= htmlspecialchars($certData['event_name']) ?>">
    <meta property="twitter:description" content="This official credential was securely issued by DCW. Verify the authenticity of this certificate online.">
    <meta property="twitter:image" content="https://<?= htmlspecialchars($_SERVER['HTTP_HOST']) . $basePath ?>/assets/DCW_logo.png">

    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

        :root {
            --primary-color: #106b9a;
            --primary-hover: #0c567a;
            --success-color: #059669;
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

        .top-nav {
            background-color: var(--card-bg);
            border-bottom: 1px solid var(--border-color);
            padding: 15px 30px;
            display: flex;
            align-items: center;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }

        .top-nav img {
            height: 45px;
            margin-right: 15px;
        }

        .top-nav .nav-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--primary-color);
        }

        .main-wrapper {
            display: flex;
            gap: 30px;
            align-items: flex-start;
            justify-content: center;
            width: 100%;
            max-width: 1000px;
            margin: 50px auto;
            padding: 0 20px;
            box-sizing: border-box;
            flex: 1;
        }

        @media (max-width: 768px) {
            .main-wrapper { flex-direction: column; margin: 30px auto; }
            .top-nav { justify-content: center; }
        }

        .container {
            background-color: var(--card-bg);
            padding: 40px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            width: 100%;
            box-sizing: border-box;
            flex: 1;
        }

        .preview-container {
            flex: 1.5;
            background-color: #f8fafc;
        }

        .preview-box {
            width: 100%;
            border-radius: 8px;
            border: 1.5px solid var(--border-color);
            background: #ffffff;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 300px;
            overflow: hidden;
        }
        canvas#pdf-preview {
            width: 100%;
            height: auto;
            display: block;
        }

        h1 {
            color: #0f172a;
            margin-top: 0;
            margin-bottom: 8px;
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .verification-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #ecfdf5;
            color: var(--success-color);
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 24px;
            border: 1.5px solid #a7f3d0;
        }

        .meta {
            font-size: 15px;
            color: #64748b;
            margin-bottom: 30px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 24px;
        }

        .detail-row {
            display: flex;
            flex-direction: column;
            margin-bottom: 20px;
        }
        
        .detail-label {
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
            font-weight: 600;
            margin-bottom: 6px;
        }
        .detail-value {
            font-size: 16px;
            color: #1e293b;
            font-weight: 600;
        }

        .btn-primary {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            background-color: var(--primary-color);
            color: white;
            padding: 16px 24px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            margin-top: 24px;
            text-align: center;
            width: 100%;
            box-sizing: border-box;
            transition: all 0.2s ease;
            border: none;
        }
        .btn-primary:hover {
            background-color: var(--primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 4px 6px rgba(16, 107, 154, 0.2);
        }
        .btn-primary:active {
            transform: translateY(0);
        }
    </style>
</head>
<body>

    <div class="top-nav">
        <a href="https://dcwwiki.org/" target="_blank">
            <img src="<?= $basePath ?>/assets/DCW_logo.png" alt="DCW Logo" width="45" height="45" decoding="async">
        </a>
        <div class="nav-title">Credential Verification</div>
    </div>

    <div class="main-wrapper">
        <div class="container preview-container">
            <div class="preview-box">
                <canvas id="pdf-preview"></canvas>
            </div>
        </div>

        <div class="container">
            <div class="verification-badge">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2zm-1.8 15.4L6 13.2l1.4-1.4 2.8 2.8 7.6-7.6 1.4 1.4-9 9z"/></svg>
                Official Credential Verified
            </div>
            
            <h1><?= htmlspecialchars($certData['full_name']) ?></h1>
            <div class="meta">This credential was securely issued by Deoband Community Wikimedia.</div>

            <div class="detail-row">
                <div class="detail-label">Credential ID</div>
                <div class="detail-value" style="font-family: monospace; font-size: 15px; background: #f1f5f9; padding: 6px 10px; border-radius: 6px; display: inline-block; width: fit-content; border: 1px solid var(--border-color);"><?= htmlspecialchars($certId) ?></div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Event Name</div>
                <div class="detail-value"><?= htmlspecialchars($certData['event_name']) ?></div>
            </div>

            <?php if ($roleName): ?>
            <div class="detail-row">
                <div class="detail-label">Role</div>
                <div class="detail-value"><?= htmlspecialchars($certData['role_name']) ?></div>
            </div>
            <?php endif; ?>

            <div class="detail-row">
                <div class="detail-label">Issue Date</div>
                <div class="detail-value"><?= $issueDate ?></div>
            </div>

            <a href="<?= $basePath ?>/download.php?id=<?= htmlspecialchars($certId) ?>" class="btn-primary">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                Download Original PDF
            </a>
        </div>
    </div>

    <script>
        const pdfUrl = '<?= $basePath ?>/download.php?id=<?= htmlspecialchars($certId) ?>&preview=1';
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';

        pdfjsLib.getDocument(pdfUrl).promise.then(pdf => {
            pdf.getPage(1).then(page => {
                const canvas = document.getElementById('pdf-preview');
                const ctx = canvas.getContext('2d');
                
                const viewport = page.getViewport({ scale: 2.0 });
                canvas.width = viewport.width;
                canvas.height = viewport.height;

                page.render({
                    canvasContext: ctx,
                    viewport: viewport
                });
            });
        }).catch(err => {
            console.error("Error loading PDF preview:", err);
            document.getElementById('pdf-preview').style.display = 'none';
        });
    </script>

    <div style="text-align: center; padding: 20px; margin-top: 40px; color: #64748b; font-size: 14px;">
        &copy; <?= date('Y') ?> <a href="https://dcwwiki.org/" target="_blank" style="color: var(--primary-color); text-decoration: none; font-weight: 500;">Deoband Community Wikimedia</a>. All Rights Reserved.
    </div>
</body>
</html>
