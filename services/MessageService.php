<?php

require_once __DIR__ . '/../config/database.php';

class MessageService
{
    private PDO $db;

    private const MAX_MESSAGE_LENGTH = 2000;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // ----------------------------------------------------------------
    // GET /messages/{userId}
    // Returns the full thread between two users, oldest first.
    // Automatically marks incoming unread messages as read.
    // ----------------------------------------------------------------
    public function thread(int $currentUserId, int $otherUserId): array
    {
        if (!$this->canUsersMessage($currentUserId, $otherUserId)) {
            return ['status' => 403, 'body' => ['success' => false, 'message' => 'Messaging not permitted.']];
        }

        // Mark all unread incoming messages as read before fetching
        $markStmt = $this->db->prepare("
            UPDATE messages
            SET is_read = 1
            WHERE sender_id = ? AND receiver_id = ? AND is_read = 0
        ");
        $markStmt->execute([$otherUserId, $currentUserId]);

        $stmt = $this->db->prepare("
            SELECT id, sender_id, receiver_id, message_text, is_read, sent_at
            FROM messages
            WHERE (sender_id = ? AND receiver_id = ?)
               OR (sender_id = ? AND receiver_id = ?)
            ORDER BY sent_at ASC
        ");
        $stmt->execute([$currentUserId, $otherUserId, $otherUserId, $currentUserId]);

        return ['status' => 200, 'body' => ['success' => true, 'data' => $stmt->fetchAll()]];
    }

    // ----------------------------------------------------------------
    // POST /messages
    // Validates the 5-rule authorization before every insert.
    // ----------------------------------------------------------------
    public function send(int $senderId, array $data): array
    {
        $receiverId  = isset($data['receiver_id']) ? (int) $data['receiver_id'] : 0;
        $messageText = trim($data['message_text'] ?? '');

        // Input validation first
        $errors = [];
        if ($receiverId <= 0) {
            $errors['receiver_id'] = 'Receiver ID is required';
        }
        if ($messageText === '') {
            $errors['message_text'] = 'Message cannot be empty';
        } elseif (mb_strlen($messageText, 'UTF-8') > self::MAX_MESSAGE_LENGTH) {
            $errors['message_text'] = 'Message cannot exceed ' . self::MAX_MESSAGE_LENGTH . ' characters';
        }

        if (!empty($errors)) {
            return ['status' => 400, 'body' => ['success' => false, 'message' => 'Validation failed', 'data' => $errors]];
        }

        // Authorization: all 5 rules
        if (!$this->canUsersMessage($senderId, $receiverId)) {
            return ['status' => 403, 'body' => ['success' => false, 'message' => 'Messaging not permitted.']];
        }

        $stmt = $this->db->prepare(
            'INSERT INTO messages (sender_id, receiver_id, message_text) VALUES (?, ?, ?)'
        );
        $stmt->execute([$senderId, $receiverId, $messageText]);

        return [
            'status' => 201,
            'body'   => [
                'success' => true,
                'message' => 'Message sent',
                'data'    => ['id' => (int) $this->db->lastInsertId()],
            ],
        ];
    }

    // ----------------------------------------------------------------
    // PATCH /messages/read
    // Only the receiver may mark a message as read.
    // rowCount() == 0 means either the message doesn't exist
    // or the caller is not the receiver — same 404 response either way.
    // ----------------------------------------------------------------
    public function markRead(int $currentUserId, array $data): array
    {
        $messageId = isset($data['message_id']) ? (int) $data['message_id'] : 0;

        if ($messageId <= 0) {
            return ['status' => 400, 'body' => ['success' => false, 'message' => 'Message ID is required']];
        }

        $stmt = $this->db->prepare(
            'UPDATE messages SET is_read = 1 WHERE id = ? AND receiver_id = ?'
        );
        $stmt->execute([$messageId, $currentUserId]);

        if ($stmt->rowCount() === 0) {
            return ['status' => 404, 'body' => ['success' => false, 'message' => 'Message not found']];
        }

        return ['status' => 200, 'body' => ['success' => true, 'message' => 'Message marked as read']];
    }

    // ----------------------------------------------------------------
    // GET /messages/conversations
    // Returns each conversation partner with last message + unread count.
    // Coaches see all assigned clients; clients see their coach.
    // ----------------------------------------------------------------
    public function conversations(int $currentUserId): array
    {
        // Determine role
        $roleStmt = $this->db->prepare('SELECT role FROM users WHERE id = ?');
        $roleStmt->execute([$currentUserId]);
        $row = $roleStmt->fetch();
        if (!$row) {
            return ['status' => 401, 'body' => ['success' => false, 'message' => 'User not found']];
        }
        $role = $row['role'];

        if ($role === 'coach' || $role === 'admin') {
            // All clients assigned to this coach
            $stmt = $this->db->prepare("
                SELECT
                    u.id            AS user_id,
                    u.name,
                    cp.job_title,
                    cp.organization,
                    m.message_text  AS last_message,
                    m.sent_at       AS last_message_at,
                    (
                        SELECT COUNT(*) FROM messages
                        WHERE sender_id = u.id AND receiver_id = ? AND is_read = 0
                    ) AS unread_count
                FROM users u
                JOIN client_profiles cp ON cp.user_id = u.id
                JOIN coach_assignments ca ON ca.client_profile_id = cp.id AND ca.coach_id = ?
                LEFT JOIN messages m ON m.id = (
                    SELECT id FROM messages
                    WHERE (sender_id = u.id AND receiver_id = ?)
                       OR (sender_id = ? AND receiver_id = u.id)
                    ORDER BY sent_at DESC LIMIT 1
                )
                ORDER BY COALESCE(m.sent_at, '1970-01-01') DESC
            ");
            $stmt->execute([$currentUserId, $currentUserId, $currentUserId, $currentUserId]);
        } else {
            // Client: find their coach
            $stmt = $this->db->prepare("
                SELECT
                    u.id            AS user_id,
                    u.name,
                    '' AS job_title,
                    '' AS organization,
                    m.message_text  AS last_message,
                    m.sent_at       AS last_message_at,
                    (
                        SELECT COUNT(*) FROM messages
                        WHERE sender_id = u.id AND receiver_id = ? AND is_read = 0
                    ) AS unread_count
                FROM users u
                JOIN coach_assignments ca ON ca.coach_id = u.id
                JOIN client_profiles cp ON cp.id = ca.client_profile_id AND cp.user_id = ?
                LEFT JOIN messages m ON m.id = (
                    SELECT id FROM messages
                    WHERE (sender_id = u.id AND receiver_id = ?)
                       OR (sender_id = ? AND receiver_id = u.id)
                    ORDER BY sent_at DESC LIMIT 1
                )
                LIMIT 1
            ");
            $stmt->execute([$currentUserId, $currentUserId, $currentUserId, $currentUserId]);
        }

        return ['status' => 200, 'body' => ['success' => true, 'data' => $stmt->fetchAll()]];
    }

    // ----------------------------------------------------------------
    // Private: 5-rule authorization gate
    // Rule 1: Sender exists.
    // Rule 2: Receiver exists.
    // Rule 3: Sender != receiver.
    // Rule 4: One is a coach, the other is a client.
    // Rule 5: Coach is assigned to that client via coach_assignments.
    // ----------------------------------------------------------------
    private function canUsersMessage(int $userAId, int $userBId): bool
    {
        // Rule 3
        if ($userAId === $userBId) {
            return false;
        }

        // Rules 1 + 2 + 4: fetch both users in one query
        $stmt = $this->db->prepare(
            'SELECT id, role FROM users WHERE id IN (?, ?)'
        );
        $stmt->execute([$userAId, $userBId]);
        $users = $stmt->fetchAll();

        if (count($users) !== 2) {
            return false; // One or both don't exist
        }

        $roles = array_column($users, 'role', 'id');

        // Rule 4: exactly one coach and one client
        $roleValues = array_values($roles);
        if (!(
            (in_array('coach', $roleValues, true) && in_array('client', $roleValues, true))
        )) {
            return false;
        }

        // Identify which is coach and which is client
        $coachId   = array_search('coach',  $roles, true);
        $clientId  = array_search('client', $roles, true);

        // Rule 5: coach must be assigned to this client
        $profileStmt = $this->db->prepare(
            'SELECT id FROM client_profiles WHERE user_id = ?'
        );
        $profileStmt->execute([$clientId]);
        $profile = $profileStmt->fetch();

        if (!$profile) {
            return false;
        }

        $assignStmt = $this->db->prepare(
            'SELECT 1 FROM coach_assignments WHERE coach_id = ? AND client_profile_id = ? LIMIT 1'
        );
        $assignStmt->execute([$coachId, $profile['id']]);
        return (bool) $assignStmt->fetch();
    }
}
