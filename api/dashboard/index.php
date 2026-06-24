<?php
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../services/DashboardService.php';

requireRole('coach', 'admin');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

$service = new DashboardService();
$result  = $service->summary($_SESSION['user_id']);

respond($result['status'], $result['body']);
