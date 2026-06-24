<?php

require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../services/AuthService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

$service = new AuthService();
$result  = $service->me();

respond($result['status'], $result['body']);
