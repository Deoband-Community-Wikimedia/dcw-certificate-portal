<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../config.php';
require_once '../helpers.php';

header('Content-Type: application/json');

// Check admin authentication
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Bad Request.']);
    exit;
}

$certId = trim($_POST['id']);

// Anti-IDOR guard: Ensure certificate belongs to an event the current tenant can access
$stmtCertEvent = $pdo->prepare("SELECT event_id FROM event_participants WHERE certificate_id = ?");
$stmtCertEvent->execute([$certId]);
$certEventId = $stmtCertEvent->fetchColumn();

if (!$certEventId || !verify_event_access($pdo, (int)$certEventId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access to this certificate.']);
    exit;
}

$result = sendAvailabilityEmail($pdo, $certId);

echo json_encode($result);
