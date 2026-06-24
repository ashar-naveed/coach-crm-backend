<?php
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../services/ActionItemService.php';

requireRole('coach', 'admin');

$service = new ActionItemService();
$method  = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $goalId = (int) ($_GET['goal_id'] ?? 0);
    if ($goalId <= 0) respond(400, ['success' => false, 'message' => 'Invalid goal ID']);
    $result = $service->list($_SESSION['user_id'], $goalId);

} elseif ($method === 'POST') {
    $data   = getJsonBody();
    $goalId = (int) ($data['goal_id'] ?? $_GET['goal_id'] ?? 0);
    if ($goalId <= 0) respond(400, ['success' => false, 'message' => 'Invalid goal ID']);
    $result = $service->create($_SESSION['user_id'], $goalId, $data);

} else {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

respond($result['status'], $result['body']);
