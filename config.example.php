<?php
require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad(); // Use safeLoad so it doesn't crash in production if .env is missing and variables are set in server environment

// Set application identification headers
header("X-Project-Creator: Zaidusyy");
header("X-Powered-By: DCW Certificate Engine");

// Load shared helpers
require_once __DIR__ . '/helpers.php';

// Load internationalisation engine
require_once __DIR__ . '/helpers/i18n.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prime language detection early (before any HTML output) so that
// setcookie() and $_SESSION writes happen here, not mid-template.
i18n_get_lang();


$host = $_ENV['DB_HOST'] ?? 'localhost';
$db   = $_ENV['DB_NAME'] ?? 'certificate_system';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';
$charset = 'utf8mb4';

// Organization URLs & Assets (Configurable via .env for forks to prevent merge conflicts)
define('ORG_LOGO_PATH', $_ENV['ORG_LOGO_PATH'] ?? 'assets/logo.png');
define('ORG_URL_HOME', $_ENV['ORG_URL_HOME'] ?? 'https://dcwwiki.org/');
define('ORG_URL_ABOUT', $_ENV['ORG_URL_ABOUT'] ?? 'https://dcwwiki.org/About');
define('ORG_URL_PROGRAMS', $_ENV['ORG_URL_PROGRAMS'] ?? 'https://dcwwiki.org/Programs');
define('ORG_URL_PARTNERSHIPS', $_ENV['ORG_URL_PARTNERSHIPS'] ?? 'https://dcwwiki.org/Partnerships');
define('ORG_URL_NEWS', $_ENV['ORG_URL_NEWS'] ?? 'https://dcwwiki.org/News');
define('ORG_URL_VISION', $_ENV['ORG_URL_VISION'] ?? 'https://dcwwiki.org/Vision_%26_Objectives');
define('ORG_URL_MASTODON', $_ENV['ORG_URL_MASTODON'] ?? 'https://wikis.world/@dcwwiki');
define('ORG_URL_FACEBOOK', $_ENV['ORG_URL_FACEBOOK'] ?? 'https://www.facebook.com/dcwwiki');
define('ORG_URL_INSTAGRAM', $_ENV['ORG_URL_INSTAGRAM'] ?? 'https://www.instagram.com/dcwwiki/');
define('ORG_URL_LINKEDIN', $_ENV['ORG_URL_LINKEDIN'] ?? 'https://www.linkedin.com/company/deoband-community-wikimedia');
define('ORG_URL_TWITTER', $_ENV['ORG_URL_TWITTER'] ?? 'https://twitter.com/dcwwiki');
define('ORG_URL_YOUTUBE', $_ENV['ORG_URL_YOUTUBE'] ?? 'https://www.youtube.com/@dcwwiki');
define('ORG_URL_SUBSCRIBE', $_ENV['ORG_URL_SUBSCRIBE'] ?? 'https://dcwwiki.org/Subscribe');
define('ORG_URL_MEMBERSHIP', $_ENV['ORG_URL_MEMBERSHIP'] ?? 'https://dcwwiki.org/Membership');
define('ORG_URL_POLICY', $_ENV['ORG_URL_POLICY'] ?? 'https://dcwwiki.org/Friendly_space_policy');
define('ORG_URL_CONTACT', $_ENV['ORG_URL_CONTACT'] ?? 'https://dcwwiki.org/Contact');
define('ORG_EMAIL_MODERATOR', $_ENV['ORG_EMAIL_MODERATOR'] ?? 'moderator@dcwwiki.org');


$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_PERSISTENT         => true,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed.");
}

// Security Helpers
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        die('Security Error: CSRF token validation failed. Please go back and refresh the page.');
    }
    return true;
}

// Security Configuration
define('SUPER_ADMIN_PASSCODE', $_ENV['SUPER_ADMIN_PASSCODE'] ?? '1234');

// Dynamic Thumbnails Configuration (For Social Sharing Previews)
define('DYNAMIC_THUMBNAILS_ENABLED', filter_var($_ENV['DYNAMIC_THUMBNAILS_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN));

// Audit Log Helper
function log_audit_action($pdo, $action, $details = '') {
    if (!isset($_SESSION['admin_username'])) {
        return; // Don't log if not authenticated
    }
    
    $stmt = $pdo->prepare("INSERT INTO audit_logs (admin_username, action_type, details) VALUES (?, ?, ?)");
    $stmt->execute([
        $_SESSION['admin_username'],
        substr($action, 0, 50),
        substr($details, 0, 255)
    ]);
}
?>
