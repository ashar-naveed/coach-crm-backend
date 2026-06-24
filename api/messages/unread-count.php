<?php
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../config/database.php';

requireAuth();

$db = Database::getConnection();

$stmt = $db->prepare("
    SELECT COUNT(*) as count
    FROM messages
    WHERE receiver_id = ? AND is_read = 0
");
$stmt->execute([$_SESSION['user_id']]);
$row = $stmt->fetch();

respond(200, ['success' => true, 'data' => ['count' => (int) $row['count']]]);