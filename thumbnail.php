<?php
// Start execution time configuration if needed, since PDF generation + conversion might take a moment
set_time_limit(30);

require_once __DIR__ . '/config.php';

$certId = trim($_GET['id'] ?? '');

// Fallback to default logo if no certificate ID is provided
if (!$certId) {
    serveDefaultLogo();
}

// Check if dynamic thumbnails are enabled
if (!defined('DYNAMIC_THUMBNAILS_ENABLED') || !DYNAMIC_THUMBNAILS_ENABLED) {
    serveDefaultLogo();
}

// User-Agent Bot check (LinkedIn, Twitter/X, Facebook, Slack, Telegram, WhatsApp)
// Also allow manual preview via query param for testing/admin purposes
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isBot = preg_match('/LinkedInBot|Twitterbot|facebookexternalhit|Slackbot|TelegramBot|WhatsApp/i', $userAgent);
$isPreview = isset($_GET['preview']) && $_GET['preview'] == 1;

if (($isBot || $isPreview) && extension_loaded('imagick')) {
    try {
        // Set up parameters for download.php to run silently
        $_GET['id'] = $certId;
        $outputAsString = true;
        
        // Include download.php to obtain the raw PDF data
        $pdfData = include __DIR__ . '/download.php';
        
        if (!empty($pdfData)) {
            $imagick = new Imagick();
            // Set resolution before reading the PDF for high quality
            $imagick->setResolution(150, 150);
            $imagick->readImageBlob($pdfData);
            $imagick->setIteratorIndex(0); // Focus on the first page
            $imagick->setImageFormat('png');
            
            // Stream the PNG
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=86400');
            echo $imagick->getImageBlob();
            
            $imagick->clear();
            $imagick->destroy();
            exit;
        }
    } catch (Exception $e) {
        error_log("Dynamic thumbnail generation failed: " . $e->getMessage());
    }
}

// Fallback: serve default logo
serveDefaultLogo();

function serveDefaultLogo() {
    $logoPath = __DIR__ . '/assets/DCW_logo.png';
    if (file_exists($logoPath)) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        readfile($logoPath);
        exit;
    } else {
        header('HTTP/1.1 404 Not Found');
        echo "Default logo not found.";
        exit;
    }
}
