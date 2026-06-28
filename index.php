<?php
require_once 'config.php';

// Handle Verify Search
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify') {
    $vId = trim($_POST['verify_id'] ?? '');
    if ($vId) {
        header("Location: verify/" . urlencode($vId));
        exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'claim') {
    $eventId = $_POST['event_id'] ?? null;
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($eventId && $fullName && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare("
            SELECT ep.certificate_id 
            FROM participants p
            JOIN event_participants ep ON p.id = ep.participant_id
            WHERE p.full_name = ? AND p.email = ? AND ep.event_id = ?
        ");
        $stmt->execute([$fullName, $email, $eventId]);
        $certId = $stmt->fetchColumn();

        if ($certId) {
            header("Location: success.php?id=" . $certId);
            exit;
        } else {
            header("Location: index.php?error=" . urlencode("Verification failed. You are not registered for this event."));
            exit;
        }
    } else {
        header("Location: index.php?error=" . urlencode("Please fill in all fields correctly."));
        exit;
    }
}

// Fetch all events for the dropdown
$stmt = $pdo->query("SELECT id, name FROM events ORDER BY created_at DESC");
$events = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DCW Download Center</title>
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

        /* Clean Modern Header */
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
            .main-wrapper { 
                flex-direction: column; 
                margin: 30px auto;
            }
            .top-nav {
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

        .container h1 {
            color: #0f172a;
            margin-top: 0;
            margin-bottom: 12px;
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .subtitle {
            font-size: 15px;
            color: #64748b;
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
            color: #334155;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 14px 16px;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            box-sizing: border-box;
            font-size: 16px; /* 16px prevents iOS zoom and is highly readable */
            color: #0f172a;
            background: #ffffff;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s ease;
        }

        .form-group input::placeholder {
            color: #94a3b8;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(16, 107, 154, 0.1);
        }

        .btn-submit {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 16px 24px;
            width: 100%;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 8px;
            transition: all 0.2s ease;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
        }

        .btn-submit:hover {
            background-color: var(--primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 4px 6px rgba(16, 107, 154, 0.2);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .btn-secondary {
            background-color: var(--secondary-color);
        }
        .btn-secondary:hover {
            background-color: #7a1115;
        }

        .error-message {
            color: #b91c1c;
            background-color: #fef2f2;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 13px;
            font-weight: 500;
            border: 1px solid #fecaca;
            display: none;
        }

        <?php if (isset($_GET['error'])): ?>
            .error-message {
                display: block;
            }
        <?php endif; ?>
    </style>
</head>

<body>
    <!-- Clean Top Navigation -->
    <div class="top-nav">
        <a href="https://dcwwiki.org/" target="_blank">
            <img src="assets/DCW_logo.png" alt="DCW Logo" width="45" height="45" decoding="async">
        </a>
        <div class="nav-title">Deoband Community Wikimedia</div>
    </div>

    <div class="main-wrapper">
        <!-- Claim Certificate Column -->
        <div class="container">
            <h1>Claim Certificate</h1>
            <div class="subtitle">
                Select your event and enter your details to securely download your verified certificate.
            </div>

            <div class="error-message">
                <?php
                if (isset($_GET['error'])) {
                    echo htmlspecialchars($_GET['error']);
                }
                ?>
            </div>

            <form action="" method="POST">
                <input type="hidden" name="action" value="claim">
                <div class="form-group">
                    <label for="event_id">Select Event</label>
                    <select id="event_id" name="event_id" required>
                        <option value="">-- Choose Event --</option>
                        <?php foreach ($events as $e): ?>
                            <option value="<?= htmlspecialchars($e['id']) ?>"><?= htmlspecialchars($e['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" required placeholder="Enter your registered name">
                </div>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required placeholder="Enter your email address">
                </div>

                <button type="submit" class="btn-submit">
                    Verify & Download
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M5 12h14M12 5l7 7-7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
            </form>
        </div>

        <!-- Verify Credential Column -->
        <div class="container" style="background: #f8fafc; border: 1px solid #e2e8f0;">
            <div style="text-align: center; margin-bottom: 15px;">
                <svg viewBox="0 0 24 24" width="40" height="40" style="color: var(--secondary-color);">
                    <path fill="currentColor" d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/>
                </svg>
            </div>
            <h1 style="text-align: center;">Verify Credential</h1>
            <div class="subtitle" style="text-align: center;">
                Employers and recruiters can instantly verify a certificate by entering its secure ID.
            </div>

            <form action="" method="POST">
                <input type="hidden" name="action" value="verify">
                <div class="form-group">
                    <label for="verify_id">Certificate ID</label>
                    <input type="text" id="verify_id" name="verify_id" required placeholder="e.g. CERT-1A2B3C4D">
                </div>

                <button type="submit" class="btn-submit btn-secondary">
                    Verify Authenticity
                </button>
            </form>
        </div>
    </div>

</body>
</html>
