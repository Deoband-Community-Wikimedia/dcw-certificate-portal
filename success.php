<?php
require_once 'config.php';

if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit;
}

$certId = trim($_GET['id']);

$stmt = $pdo->prepare("
    SELECT p.full_name, e.name as event_name, e.linkedin_caption, ep.created_at
    FROM event_participants ep
    JOIN participants p ON ep.participant_id = p.id
    JOIN events e ON ep.event_id = e.id
    WHERE ep.certificate_id = ?
");
$stmt->execute([$certId]);
$certData = $stmt->fetch();

if (!$certData) {
    die("Invalid Certificate ID");
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$baseDir = str_replace('\\', '/', dirname($_SERVER['PHP_SELF']));
if ($baseDir === '/') $baseDir = '';
$verifyUrl = $protocol . $_SERVER['HTTP_HOST'] . $baseDir . '/verify/' . $certId;

$eventName = urlencode($certData['event_name']);
$issueYear = date('Y', strtotime($certData['created_at']));
$issueMonth = date('n', strtotime($certData['created_at']));
$orgNameEncoded = urlencode('Deoband Community Wikimedia');
$linkedInAddUrl = "https://www.linkedin.com/profile/add?startTask=CERTIFICATION_NAME&name={$eventName}&organizationName={$orgNameEncoded}&issueYear={$issueYear}&issueMonth={$issueMonth}&certUrl=" . urlencode($verifyUrl) . "&certId=" . urlencode($certId);

$dbCaption = $certData['linkedin_caption'] ?? '';
$customCaption = trim($dbCaption) !== '' ? str_replace(['{EVENT_NAME}', '{URL}'], [$certData['event_name'], $verifyUrl], $dbCaption) : "I just earned my certificate for completing {$certData['event_name']}! Check out my verified credential here: {$verifyUrl}";

$linkedInShareDesktop = "https://www.linkedin.com/feed/?shareActive=true&text=" . rawurlencode($customCaption);
$linkedInShareMobile = "https://www.linkedin.com/sharing/share-offsite/?url=" . urlencode($verifyUrl);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Certificate - <?= htmlspecialchars($certData['event_name']) ?></title>
    <!-- PDF.js for crisp previews -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
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
            margin-bottom: 12px;
            font-size: 26px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        p {
            font-size: 15px;
            color: #64748b;
            line-height: 1.6;
            margin-bottom: 30px;
        }

        .btn-primary, .btn-linkedin, .btn-linkedin-outline {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px 20px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            margin-bottom: 20px;
            width: 100%;
            box-sizing: border-box;
            transition: all 0.2s ease;
            border: none;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        .btn-primary:hover {
            background-color: var(--primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 4px 6px rgba(16, 107, 154, 0.2);
        }

        .btn-linkedin {
            background-color: #0a66c2;
            color: white;
            margin-bottom: 16px;
        }
        .btn-linkedin:hover { 
            background-color: #004182;
            transform: translateY(-1px);
            box-shadow: 0 4px 6px rgba(10, 102, 194, 0.2);
        }

        .btn-linkedin-outline {
            background-color: transparent;
            color: #0a66c2;
            border: 1.5px solid #0a66c2;
            margin-bottom: 30px;
        }
        .btn-linkedin-outline:hover { 
            background-color: #f3f6f8; 
            transform: translateY(-1px);
        }

        .form-group { margin-bottom: 24px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 14px; color: #475569; }
        .input-group { display: flex; border: 1.5px solid #cbd5e1; border-radius: 8px; overflow: hidden; }
        .input-group input { 
            flex: 1; padding: 14px 16px; border: none; border-right: 1.5px solid #cbd5e1;
            font-family: monospace; background: #f8fafc; color: #0f172a; font-size: 15px; outline: none; box-sizing: border-box;
        }
        .input-group button {
            background: #f1f5f9; border: none; padding: 0 20px; 
            cursor: pointer; font-weight: 600; font-size: 14px; color: #475569; transition: background 0.2s; box-sizing: border-box;
        }
        .input-group button:hover { background: #e2e8f0; color: #0f172a; }
    </style>
</head>
<body>

    <div class="top-nav">
        <a href="https://dcwwiki.org/" target="_blank">
            <img src="assets/DCW_logo.png" alt="DCW Logo" width="45" height="45" decoding="async">
        </a>
        <div class="nav-title">Official Credential</div>
    </div>

    <div class="main-wrapper">
        <div class="container preview-container">
            <div class="preview-box">
                <canvas id="pdf-preview"></canvas>
            </div>
        </div>

        <div class="container">
            <h1>Congratulations, <?= htmlspecialchars($certData['full_name']) ?>!</h1>
            <p>Your official certificate for <strong><?= htmlspecialchars($certData['event_name']) ?></strong> has been successfully generated and permanently recorded in our system.</p>

            <a href="download.php?id=<?= htmlspecialchars($certId) ?>" class="btn-primary">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                Download PDF
            </a>

            <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 20px 0;">

            <h3 style="margin-top: 0; color: #0f172a; font-size: 18px; font-weight: 700;">Add to your Profile</h3>
            <p style="font-size: 14px; margin-bottom: 24px;">Showcase your achievement to your professional network. We've pre-filled all the information for you!</p>

            <a href="<?= $linkedInAddUrl ?>" target="_blank" class="btn-linkedin">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M4.98 3.5c0 1.381-1.11 2.5-2.48 2.5s-2.48-1.119-2.48-2.5c0-1.38 1.11-2.5 2.48-2.5s2.48 1.12 2.48 2.5zm.02 4.5h-5v16h5v-16zm7.982 0h-4.968v16h4.969v-8.399c0-4.67 6.029-5.052 6.029 0v8.399h4.988v-10.131c0-7.88-8.922-7.593-11.018-3.714v-2.155z"/></svg>
                Add to LinkedIn Profile
            </a>

            <a href="<?= $linkedInShareDesktop ?>" target="_blank" class="btn-linkedin-outline" onclick="return handleLinkedInShare(event, '<?= $linkedInShareMobile ?>');">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M4.98 3.5c0 1.381-1.11 2.5-2.48 2.5s-2.48-1.119-2.48-2.5c0-1.38 1.11-2.5 2.48-2.5s2.48 1.12 2.48 2.5zm.02 4.5h-5v16h5v-16zm7.982 0h-4.968v16h4.969v-8.399c0-4.67 6.029-5.052 6.029 0v8.399h4.988v-10.131c0-7.88-8.922-7.593-11.018-3.714v-2.155z"/></svg>
                Post on LinkedIn
            </a>

            <div class="form-group" style="margin-top: 20px;">
                <label>Credential ID</label>
                <div class="input-group">
                    <input type="text" id="certId" value="<?= htmlspecialchars($certId) ?>" readonly>
                    <button type="button" onclick="copyText('certId', this)" title="Copy Credential ID" style="display:flex; align-items:center; justify-content:center; padding: 0 15px;">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label>Verification URL</label>
                <div class="input-group">
                    <input type="text" id="verifyUrl" value="<?= htmlspecialchars($verifyUrl) ?>" readonly>
                    <button type="button" onclick="copyText('verifyUrl', this)" title="Copy Verification URL" style="display:flex; align-items:center; justify-content:center; padding: 0 15px;">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function handleLinkedInShare(event, mobileUrl) {
            // Check if user is on a mobile device
            if (/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)) {
                event.preventDefault();
                // Use the official mobile-supported share endpoint (strips custom text but attaches the preview card reliably)
                window.open(mobileUrl, '_blank');
                return false;
            }
            return true;
        }

        function copyText(inputId, btn) {
            const input = document.getElementById(inputId);
            input.select();
            input.setSelectionRange(0, 99999); 
            document.execCommand("copy");
            
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z"/></svg>';
            setTimeout(() => { btn.innerHTML = originalHTML; }, 2000);
        }

        const pdfUrl = 'download.php?id=<?= htmlspecialchars($certId) ?>&preview=1';
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
</body>
</html>
