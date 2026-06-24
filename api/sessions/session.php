<?php
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../services/SessionService.php';

requireRole('coach', 'admin');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    respond(400, ['success' => false, 'message' => 'Invalid session ID']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

$service = new SessionService();
$result  = $service->get($_SESSION['user_id'], $id);

respond($result['status'], $result['body']);
