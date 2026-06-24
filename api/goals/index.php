<?php
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../services/GoalService.php';

requireRole('coach', 'admin');

$service = new GoalService();
$method  = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $clientProfileId = (int) ($_GET['client_id'] ?? 0);
    if ($clientProfileId <= 0) {
        respond(400, ['success' => false, 'message' => 'Invalid client ID']);
    }
    $result = $service->list($_SESSION['user_id'], $clientProfileId);

} elseif ($method === 'POST') {
    $data            = getJsonBody();
    $clientProfileId = (int) ($data['client_id'] ?? 0);
    if ($clientProfileId <= 0) {
        respond(400, ['success' => false, 'message' => 'Invalid client ID']);
    }
    $result = $service->create($_SESSION['user_id'], $clientProfileId, $data);

} else {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

respond($result['status'], $result['body']);