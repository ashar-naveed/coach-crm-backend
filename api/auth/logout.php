<?php

require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../services/AuthService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

$service = new AuthService();
$result  = $service->logout();

respond($result['status'], $result['body']);
