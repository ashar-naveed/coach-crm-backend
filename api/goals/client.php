<?php
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../config/database.php';

requireRole('client');

$db = Database::getConnection();

$stmt = $db->prepare("
    SELECT g.id, g.title, g.description, g.progress_percentage, g.status, g.timeline_months
    FROM goals g
    JOIN client_profiles cp ON cp.id = g.client_id
    WHERE cp.user_id = ?
    ORDER BY g.status ASC, g.created_at ASC
");
$stmt->execute([$_SESSION['user_id']]);

respond(200, ['success' => true, 'data' => $stmt->fetchAll()]);
