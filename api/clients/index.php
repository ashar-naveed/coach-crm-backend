<?php
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../services/ClientService.php';

requireRole('coach', 'admin');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

$service = new ClientService();
$result  = $service->list($_SESSION['user_id']);

respond($result['status'], $result['body']);
