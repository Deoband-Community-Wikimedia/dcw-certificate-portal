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
    SELECT p.full_name, e.name as event_name, e.custom_verification_text, ep.created_at, er.role_name
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

$issueDate = date('F j, Y', strtotime($certData['created_at']));
$roleName = $certData['role_name'] ? " as " . htmlspecialchars($certData['role_name']) : "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="https://dcwwiki.org/images/5/56/DCW_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credential Verification - <?= htmlspecialchars($certData['full_name']) ?> - Deoband Community Wikimedia</title>
    <meta name="title" content="Verified Credential: <?= htmlspecialchars($certData['full_name']) ?> - <?= htmlspecialchars($certData['event_name']) ?>">
    <meta name="description" content="This official credential was securely issued. Verify the authenticity of this certificate online.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://<?= htmlspecialchars($_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) ?>">
    <meta property="og:title" content="Verified Credential: <?= htmlspecialchars($certData['full_name']) ?> - <?= htmlspecialchars($certData['event_name']) ?>">
    <meta property="og:description" content="This official credential was securely issued. Verify the authenticity of this certificate online.">
    <meta property="og:image" content="https://<?= htmlspecialchars($_SERVER['HTTP_HOST']) . $basePath ?>/assets/DCW_logo.png">

    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="https://<?= htmlspecialchars($_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) ?>">
    <meta property="twitter:title" content="Verified Credential: <?= htmlspecialchars($certData['full_name']) ?> - <?= htmlspecialchars($certData['event_name']) ?>">
    <meta property="twitter:description" content="This official credential was securely issued. Verify the authenticity of this certificate online.">
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
            max-width: 1000px;
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
        }
        .brand-name {
            font-size: 18px;
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
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .header-nav {
            display: flex;
            gap: 20px;
        }
        .header-nav a {
            text-decoration: none;
            color: #475569;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.2s ease;
        }
        .header-nav a:hover {
            color: var(--primary-color);
        }

        .main-wrapper {
            display: flex;
            gap: 30px;
            align-items: stretch;
            justify-content: center;
            width: 100%;
            max-width: 1000px;
            margin: 50px auto;
            padding: 0 20px;
            box-sizing: border-box;
            flex: 1;
        }

        @media (max-width: 768px) {
            .main-wrapper { 
                flex-direction: column; 
                margin: 30px auto;
                align-items: stretch;
            }
            .header-container {
                flex-direction: column;
                text-align: center;
            }
            .header-brand {
                flex-direction: column;
                align-items: center;
                gap: 8px;
            }
            .header-nav {
                justify-content: center;
                width: 100%;
                border-top: 1px solid #f1f5f9;
                padding-top: 10px;
            }
            .footer-brand {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
            .footer-middle {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            .footer-links {
                justify-content: center;
            }
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
            text-align: center;
        }

        .verification-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: #ecfdf5;
            color: var(--success-color);
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 24px;
            border: 1.5px solid #a7f3d0;
            width: 100%;
            box-sizing: border-box;
        }

        .meta {
            font-size: 15px;
            color: #64748b;
            margin-bottom: 30px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 24px;
            line-height: 1.6;
            text-align: center;
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

        /* Premium Footer Styling */
        .site-footer {
            background-color: #0f172a;
            color: #94a3b8;
            padding: 40px 20px 30px;
            border-top: 1px solid #1e293b;
            margin-top: auto;
            font-size: 14px;
        }
        .footer-container {
            max-width: 1000px;
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
        .footer-blurb a {
            color: #38bdf8;
            text-decoration: none;
            font-weight: 500;
        }
        .footer-blurb a:hover {
            text-decoration: underline;
        }
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
        .footer-socials {
            display: flex;
            gap: 15px;
        }
        .footer-socials a {
            color: #94a3b8;
            transition: color 0.2s ease, transform 0.2s ease;
        }
        .footer-socials a:hover {
            color: #ffffff;
            transform: translateY(-2px);
        }
        .footer-links {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        .footer-links a {
            color: #94a3b8;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s ease;
        }
        .footer-links a:hover {
            color: #ffffff;
        }
        .footer-bottom {
            text-align: center;
            font-size: 13px;
            color: #64748b;
        }
        .footer-bottom a {
            color: #38bdf8;
            text-decoration: none;
            font-weight: 500;
        }
        .footer-bottom a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

    <header class="site-header">
        <div class="header-container">
            <div class="header-brand">
                <a href="https://dcwwiki.org/" class="brand-link" target="_blank">
                    <img src="<?= $basePath ?>/assets/DCW_logo.png" alt="DCW Logo" class="brand-logo">
                    <span class="brand-name">Deoband Community Wikimedia</span>
                </a>
                <span class="portal-badge">Certificate Portal</span>
            </div>
            <nav class="header-nav">
                <a href="https://dcwwiki.org/About" target="_blank">About</a>
                <a href="https://dcwwiki.org/Programs" target="_blank">Programs</a>
                <a href="https://dcwwiki.org/Partnerships" target="_blank">Partnerships</a>
                <a href="https://dcwwiki.org/News" target="_blank">News</a>
                <a href="https://dcwwiki.org/Vision_%26_Objectives" target="_blank">Vision</a>
            </nav>
        </div>
    </header>

    <div class="main-wrapper">
        <div class="container preview-container">
            <div class="preview-box">
                <canvas id="pdf-preview"></canvas>
            </div>
        </div>

        <div class="container">
            <h1>Verified Credential</h1>
            
            <div class="verification-badge">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2zm-1.8 15.4L6 13.2l1.4-1.4 2.8 2.8 7.6-7.6 1.4 1.4-9 9z"/></svg>
                Official Credential Verified
            </div>
            
            <div class="meta">
                <?= !empty($certData['custom_verification_text']) ? htmlspecialchars($certData['custom_verification_text']) : "This digital credential has been securely issued and verified by Deoband Community Wikimedia." ?>
            </div>

            <div class="detail-row">
                <div class="detail-label">Recipient Name</div>
                <div class="detail-value" style="font-size: 18px; color: var(--primary-color); font-weight: 700;"><?= htmlspecialchars($certData['full_name']) ?></div>
            </div>

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

    <footer class="site-footer">
        <div class="footer-container">
            <div class="footer-brand">
                <img src="<?= $basePath ?>/assets/DCW_logo.png" alt="DCW Logo" class="footer-logo">
                <div class="footer-blurb">
                    Deoband Community Wikimedia is an independent affiliate of the Wikimedia Foundation with a focus on global Muslim academia and scholarship. All website content is released under the <a href="https://creativecommons.org/licenses/by-sa/4.0/" target="_blank">Creative Commons Attribution-ShareAlike License</a> unless otherwise stated.
                </div>
            </div>
            <div class="footer-middle">
                <div class="footer-socials">
                    <!-- Aafi bhai add  links over here also -->
                    <a href="https://wikis.world/@dcwwiki" target="_blank" title="Follow us on Mastodon">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M23.268 5.313c-.35-2.578-2.617-4.61-5.304-4.96C14.94.003 12 .003 12 .003s-2.94 0-5.964.35C3.352.703 1.085 2.735.736 5.313.382 7.912.35 10.825.35 12c0 1.175.032 4.088.386 6.687.35 2.578 2.617 4.61 5.304 4.96 3.023.35 5.96.35 5.96.35s2.937 0 5.96-.35c2.687-.35 4.954-2.735 5.304-4.96.354-2.6.386-5.512.386-6.687 0-1.175-.032-4.088-.386-6.687zM17.42 16.295h-2.316v-6.398c0-1.298-.553-1.956-1.656-1.956-1.22 0-1.83.79-1.83 2.37v3.473H9.3v-3.473c0-1.58-.61-2.37-1.83-2.37-1.103 0-1.656.658-1.656 1.956v6.398H3.502v-6.398c0-2.368 1.517-3.565 3.966-3.565 1.442 0 2.54.55 3.25 1.626L12 9.548l1.282-1.616c.71-1.077 1.808-1.626 3.25-1.626 2.45 0 3.966 1.197 3.966 3.565v6.398z"/></svg>
                    </a>
                    <a href="https://www.facebook.com/dcwwiki" target="_blank" title="Follow us on Facebook">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M22 12c0-5.52-4.48-10-10-10S2 6.48 2 12c0 4.84 3.44 8.87 8 9.8V15H8v-3h2V9.5C10 7.57 11.57 6 13.5 6H16v3h-2c-.55 0-1 .45-1 1v2h3v3h-3v6.95c4.56-.93 8-4.96 8-9.75z"/></svg>
                    </a>
                    <a href="https://www.instagram.com/dcwwiki/" target="_blank" title="Follow us on Instagram">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.051.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
                    </a>
                    <a href="https://www.linkedin.com/company/deoband-community-wikimedia" target="_blank" title="Follow us on LinkedIn">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.779-1.75-1.75s.784-1.75 1.75-1.75 1.75.779 1.75 1.75-.784 1.75-1.75 1.75zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"/></svg>
                    </a>
                    <a href="https://twitter.com/dcwwiki" target="_blank" title="Follow us on X">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                    </a>
                    <a href="https://www.youtube.com/@dcwwiki" target="_blank" title="Follow us on YouTube">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M23.498 6.163a3.003 3.003 0 00-2.11-2.11C19.517 3.545 12 3.545 12 3.545s-7.516 0-9.387.507a3.003 3.003 0 00-2.11 2.11C0 8.033 0 12 0 12s0 3.967.502 5.837a3.003 3.003 0 002.11 2.11c1.871.507 9.387.507 9.387.507s7.517 0 9.387-.507a3.003 3.003 0 002.11-2.11C24 15.967 24 12 24 12s0-3.967-.502-5.837zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
                    </a>
                </div>
                <div class="footer-links">
                    <a href="https://dcwwiki.org/Subscribe" target="_blank">Subscribe</a>
                    <a href="https://dcwwiki.org/Membership" target="_blank">Become a member</a>
                    <a href="https://dcwwiki.org/Friendly_space_policy" target="_blank">Friendly space policy</a>
                    <a href="https://dcwwiki.org/Contact" target="_blank">Contact</a>
                </div>
            </div>
            <div class="footer-bottom">
                &copy; <?= date('Y') ?> <a href="https://dcwwiki.org/" target="_blank">Deoband Community Wikimedia</a>. All Rights Reserved.
            </div>
        </div>
    </footer>
</body>
</html>
</body>
</html>
