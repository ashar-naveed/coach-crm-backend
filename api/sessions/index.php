<?php
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../services/SessionService.php';

requireAuth();

$service = new SessionService();
$method  = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if ($_SESSION['role'] === 'client') {
        $result = $service->clientList($_SESSION['user_id']);
    } else {
        requireRole('coach', 'admin');
        $filters = array_filter([
            'client_id' => (int) ($_GET['client_id'] ?? 0) ?: null,
            'status'    => $_GET['status'] ?? null,
        ]);
        $result = $service->list($_SESSION['user_id'], $filters);
    }
} elseif ($method === 'POST') {
    requireRole('coach', 'admin');
    $data   = getJsonBody();
    $result = $service->coachCreate($_SESSION['user_id'], $data);

} else {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

respond($result['status'], $result['body']);
