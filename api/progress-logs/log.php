<?php
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../config/database.php';

requireRole('coach', 'admin');

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) respond(400, ['success' => false, 'message' => 'Invalid ID']);

$db   = Database::getConnection();

// Verify ownership through goal → client → coach
$stmt = $db->prepare("
    SELECT pl.id FROM progress_logs pl
    JOIN goals g ON g.id = pl.goal_id
    JOIN client_profiles cp ON cp.id = g.client_id
    JOIN coach_assignments ca ON ca.client_profile_id = cp.id
    WHERE pl.id = ? AND ca.coach_id = ?
");
$stmt->execute([$id, $_SESSION['user_id']]);
if (!$stmt->fetch()) {
    respond(403, ['success' => false, 'message' => 'Access denied']);
}

$stmt = $db->prepare('DELETE FROM progress_logs WHERE id = ?');
$stmt->execute([$id]);

respond(200, ['success' => true, 'message' => 'Progress entry deleted']);