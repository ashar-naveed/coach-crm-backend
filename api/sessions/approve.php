<?php
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../services/SessionService.php';

requireRole('coach', 'admin');

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) respond(400, ['success' => false, 'message' => 'Invalid session ID']);

$service = new SessionService();
$result  = $service->approve($_SESSION['user_id'], $id);
respond($result['status'], $result['body']);
