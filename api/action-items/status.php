<?php
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../services/ActionItemService.php';

requireRole('coach', 'admin');

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    respond(400, ['success' => false, 'message' => 'Invalid action item ID']);
}

$data    = getJsonBody();
$service = new ActionItemService();
$result  = $service->patchStatus($_SESSION['user_id'], $id, $data);

respond($result['status'], $result['body']);
