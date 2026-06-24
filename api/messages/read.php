<?php
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../services/MessageService.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

$data    = getJsonBody();
$service = new MessageService();
$result  = $service->markRead($_SESSION['user_id'], $data);

respond($result['status'], $result['body']);
