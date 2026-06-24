<?php
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../services/ActionItemService.php';

requireRole('coach', 'admin');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    respond(400, ['success' => false, 'message' => 'Invalid action item ID']);
}

$service = new ActionItemService();
$method  = $_SERVER['REQUEST_METHOD'];

if ($method === 'PUT') {
    $data   = getJsonBody();
    $result = $service->update($_SESSION['user_id'], $id, $data);

} elseif ($method === 'DELETE') {
    $result = $service->delete($_SESSION['user_id'], $id);

} else {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

respond($result['status'], $result['body']);
