<?php
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../config/database.php';
 
requireRole('client');
 
$db = Database::getConnection();
 
$stmt = $db->prepare("
    SELECT ai.id, ai.title, ai.status, ai.due_date, ai.completed_at,
           g.title as goal_title
    FROM action_items ai
    JOIN goals g ON g.id = ai.goal_id
    JOIN client_profiles cp ON cp.id = g.client_id
    WHERE cp.user_id = ?
    ORDER BY 
        CASE ai.status WHEN 'pending' THEN 1 WHEN 'delayed' THEN 2 ELSE 3 END,
        ai.due_date ASC
");
$stmt->execute([$_SESSION['user_id']]);
 
respond(200, ['success' => true, 'data' => $stmt->fetchAll()]);
 