<?php
require_once __DIR__ . '/jwt.php';

// Handle CORS preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: https://mellifluous-kangaroo-74a94a.netlify.app');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Credentials: true');
    http_response_code(200);
    exit;
}

function respond(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: https://mellifluous-kangaroo-74a94a.netlify.app');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Credentials: true');
    echo json_encode($body);
    exit;
}

function requireAuth(): array
{
    $token = getBearerToken();
    if (!$token) {
        respond(401, ['success' => false, 'message' => 'Not authenticated']);
    }

    $payload = jwtDecode($token);
    if (!$payload || empty($payload['user_id'])) {
        respond(401, ['success' => false, 'message' => 'Not authenticated']);
    }

    // Populate $_SESSION-like access for backward compatibility with existing code
    $_SESSION['user_id'] = $payload['user_id'];
    $_SESSION['role'] = $payload['role'];

    return $payload;
}

function requireRole(string ...$roles): array
{
    $payload = requireAuth();
    if (!in_array($payload['role'], $roles)) {
        respond(403, ['success' => false, 'message' => 'Access denied']);
    }
    return $payload;
}

function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}
