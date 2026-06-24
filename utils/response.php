<?php

ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', '0');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_path', '/');

// Handle CORS preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: http://localhost:5173');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Credentials: true');
    http_response_code(200);
    exit;
}

function respond(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: http://localhost:5173');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Credentials: true');
    echo json_encode($body);
    exit;
}

function requireAuth(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['user_id'])) {
        respond(401, ['success' => false, 'message' => 'Not authenticated']);
    }
}

function requireRole(string ...$roles): void
{
    requireAuth();
    if (!in_array($_SESSION['role'], $roles)) {
        respond(403, ['success' => false, 'message' => 'Access denied']);
    }
}

function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}