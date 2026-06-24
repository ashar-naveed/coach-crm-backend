<?php
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../services/MessageService.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

$service = new MessageService();
$result  = $service->conversations($_SESSION['user_id']);

respond($result['status'], $result['body']);
