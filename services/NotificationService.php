<?php

require_once __DIR__ . '/../config/database.php';

class NotificationService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // ----------------------------------------------------------------
    // GET /notifications
    // Returns all notifications for the current user, newest first.
    // ----------------------------------------------------------------
    public function list(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, title, message, is_read, created_at
            FROM notifications
            WHERE user_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$userId]);

        $notifications = $stmt->fetchAll();

        foreach ($notifications as &$n) {
            $n['is_read'] = (bool) $n['is_read'];
        }

        return ['status' => 200, 'body' => ['success' => true, 'data' => $notifications]];
    }

    // ----------------------------------------------------------------
    // PATCH /notifications/{id}/read
    // Only the recipient may mark their own notification as read.
    // rowCount() == 0 covers both non-existent and not-owned cases.
    // ----------------------------------------------------------------
    public function markRead(int $userId, int $notificationId): array
    {
        $stmt = $this->db->prepare(
            'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$notificationId, $userId]);

        if ($stmt->rowCount() === 0) {
            return ['status' => 404, 'body' => ['success' => false, 'message' => 'Notification not found']];
        }

        return ['status' => 200, 'body' => ['success' => true, 'message' => 'Notification marked as read']];
    }

    // ----------------------------------------------------------------
    // PATCH /notifications/read-all
    // Marks every unread notification for the current user as read.
    // Returns the count so React can clear the unread badge immediately.
    // ----------------------------------------------------------------
    public function markAllRead(int $userId): array
    {
        $stmt = $this->db->prepare(
            'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0'
        );
        $stmt->execute([$userId]);

        $updated = $stmt->rowCount();

        return [
            'status' => 200,
            'body'   => [
                'success' => true,
                'message' => 'Notifications marked as read',
                'data'    => ['updated' => $updated],
            ],
        ];
    }

    // ----------------------------------------------------------------
    // Private helpers
    // ----------------------------------------------------------------

    private function notificationBelongsToUser(int $notificationId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM notifications WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$notificationId, $userId]);
        return (bool) $stmt->fetch();
    }
}
