<?php
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../services/NotificationService.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    respond(400, ['success' => false, 'message' => 'Invalid notification ID']);
}

$service = new NotificationService();
$result  = $service->markRead($_SESSION['user_id'], $id);

respond($result['status'], $result['body']);
