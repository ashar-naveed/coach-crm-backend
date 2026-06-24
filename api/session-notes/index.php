<?php
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../services/SessionNoteService.php';

requireRole('coach', 'admin');

$sessionId = (int) ($_GET['session_id'] ?? 0);
if ($sessionId <= 0) {
    respond(400, ['success' => false, 'message' => 'Invalid session ID']);
}

$service = new SessionNoteService();
$method  = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $result = $service->get($_SESSION['user_id'], $sessionId);

} elseif ($method === 'POST') {
    $data   = getJsonBody();
    $result = $service->create($_SESSION['user_id'], $sessionId, $data);

} elseif ($method === 'PUT') {
    $data   = getJsonBody();
    $result = $service->update($_SESSION['user_id'], $sessionId, $data);

} else {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

respond($result['status'], $result['body']);
