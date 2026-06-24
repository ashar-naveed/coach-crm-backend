<?php
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../config/database.php';

requireRole('coach', 'admin');

$clientProfileId = (int) ($_GET['id'] ?? 0);
$category        = trim($_GET['category'] ?? '');
$method          = $_SERVER['REQUEST_METHOD'];

if ($clientProfileId <= 0) {
    respond(400, ['success' => false, 'message' => 'Invalid client ID']);
}

// Verify coach owns this client
$db   = Database::getConnection();
$stmt = $db->prepare('SELECT id FROM coach_assignments WHERE coach_id = ? AND client_profile_id = ? LIMIT 1');
$stmt->execute([$_SESSION['user_id'], $clientProfileId]);
if (!$stmt->fetch()) {
    respond(403, ['success' => false, 'message' => 'Access denied']);
}

if ($method === 'GET') {
    $stmt = $db->prepare('SELECT id, category, content, updated_at FROM client_contexts WHERE client_id = ? ORDER BY category ASC');
    $stmt->execute([$clientProfileId]);
    respond(200, ['success' => true, 'data' => $stmt->fetchAll()]);

} elseif ($method === 'PUT') {
    $data     = json_decode(file_get_contents('php://input'), true) ?? [];
    $cat      = trim($data['category'] ?? '');
    $content  = trim($data['content']  ?? '');
    if ($cat === '' || $content === '') {
        respond(400, ['success' => false, 'message' => 'Category and content are required']);
    }
    $stmt = $db->prepare("
        INSERT INTO client_contexts (client_id, category, content)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE content = VALUES(content), updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$clientProfileId, $cat, $content]);
    respond(200, ['success' => true, 'message' => 'Context saved']);

} elseif ($method === 'DELETE') {
    if ($category === '') {
        respond(400, ['success' => false, 'message' => 'Category is required']);
    }
    $stmt = $db->prepare('DELETE FROM client_contexts WHERE client_id = ? AND category = ?');
    $stmt->execute([$clientProfileId, $category]);
    respond(200, ['success' => true, 'message' => 'Context note deleted']);

} else {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}
