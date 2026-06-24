<?php
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../services/MessageService.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

$otherUserId = (int) ($_GET['user_id'] ?? 0);
if ($otherUserId <= 0) {
    respond(400, ['success' => false, 'message' => 'Invalid user ID']);
}

$service = new MessageService();
$result  = $service->thread($_SESSION['user_id'], $otherUserId);

respond($result['status'], $result['body']);
