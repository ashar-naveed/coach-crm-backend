<?php
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../services/GoalService.php';
require_once __DIR__ . '/../../config/database.php';

requireRole('coach', 'admin');

$id     = (int) ($_GET['id'] ?? 0);
$method = $_SERVER['REQUEST_METHOD'];

if ($id <= 0) respond(400, ['success' => false, 'message' => 'Invalid goal ID']);

$db = Database::getConnection();

// Verify coach owns this goal
$stmt = $db->prepare("
    SELECT g.id FROM goals g
    JOIN client_profiles cp ON cp.id = g.client_id
    JOIN coach_assignments ca ON ca.client_profile_id = cp.id
    WHERE g.id = ? AND ca.coach_id = ?
");
$stmt->execute([$id, $_SESSION['user_id']]);
if (!$stmt->fetch()) {
    respond(403, ['success' => false, 'message' => 'Access denied']);
}

if ($method === 'GET') {
    $stmt = $db->prepare("
        SELECT g.id, g.client_id, g.title, g.description, g.success_definition,
               g.timeline_months, g.progress_percentage, g.status, g.created_at
        FROM goals g WHERE g.id = ?
    ");
    $stmt->execute([$id]);
    $goal = $stmt->fetch();
    respond(200, ['success' => true, 'data' => $goal]);

} elseif ($method === 'DELETE') {
    $db->prepare('DELETE FROM action_items WHERE goal_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM progress_logs WHERE goal_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM goals WHERE id = ?')->execute([$id]);
    respond(200, ['success' => true, 'message' => 'Goal deleted']);

} else {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}