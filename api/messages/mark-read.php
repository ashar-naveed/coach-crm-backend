<?php
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../config/database.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

$data      = getJsonBody();
$senderId  = (int) ($data['sender_id'] ?? 0);

if ($senderId <= 0) {
    respond(400, ['success' => false, 'message' => 'Invalid sender ID']);
}

$db = Database::getConnection();
$stmt = $db->prepare("
    UPDATE messages 
    SET is_read = 1 
    WHERE sender_id = ? AND receiver_id = ? AND is_read = 0
");
$stmt->execute([$senderId, $_SESSION['user_id']]);

respond(200, ['success' => true, 'message' => 'Messages marked as read']);