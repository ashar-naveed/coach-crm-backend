<?php
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../config/database.php';

requireRole('client');

$db = Database::getConnection();

$stmt = $db->prepare("
    SELECT u.id as user_id, u.name, u.email,
           'Coach' as job_title,
           'Mumkin Coaching' as organization
    FROM users u
    JOIN coach_assignments ca ON ca.coach_id = u.id
    JOIN client_profiles cp ON cp.id = ca.client_profile_id
    WHERE cp.user_id = ?
    LIMIT 1
");
$stmt->execute([$_SESSION['user_id']]);
$coach = $stmt->fetch();

respond(200, ['success' => true, 'data' => $coach ? [$coach] : []]);