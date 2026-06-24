<?php
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../services/NotificationService.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

$service = new NotificationService();
$result  = $service->list($_SESSION['user_id']);

respond($result['status'], $result['body']);
