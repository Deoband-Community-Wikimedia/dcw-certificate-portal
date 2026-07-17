<?php
// Start global session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load shared helpers
require_once __DIR__ . '/helpers.php';

// DB connection logic

$host = 'localhost';
$db   = 'certificate_system';
$user = 'root';
$pass = ''; // Default XAMPP/WAMP empty password
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false, // True security against SQL injection
    PDO::ATTR_PERSISTENT         => true,  // Optimize by reusing the same DB connection
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // Note: In a real production system, log this error securely rather than displaying it
    die("Database connection failed: " . $e->getMessage());
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
define('SUPER_ADMIN_PASSCODE', '1234');

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
