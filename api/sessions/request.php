<?php
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../services/SessionService.php';

requireRole('client');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

$data    = getJsonBody();
$service = new SessionService();
$result  = $service->clientRequest($_SESSION['user_id'], $data);

respond($result['status'], $result['body']);
