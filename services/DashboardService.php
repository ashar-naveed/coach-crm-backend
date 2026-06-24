<?php

require_once __DIR__ . '/../config/database.php';

class DashboardService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // ----------------------------------------------------------------
    // GET /dashboard
    // Read-only aggregation. No mutations. No transactions.
    // Seven independent queries — one per card on the coach homepage.
    // ----------------------------------------------------------------
    public function summary(int $coachId): array
    {
        return [
            'status' => 200,
            'body'   => [
                'success' => true,
                'data'    => [
                    'active_clients'        => $this->activeClients($coachId),
                    'completed_engagements' => $this->completedEngagements($coachId),
                    'active_goals'          => $this->goalsByStatus($coachId, 'active'),
                    'completed_goals'       => $this->goalsByStatus($coachId, 'completed'),
                    'pending_action_items'  => $this->pendingActionItems($coachId),
                    'upcoming_sessions'     => $this->upcomingSessions($coachId),
                    'unread_notifications'  => $this->unreadNotifications($coachId),
                ],
            ],
        ];
    }

    // ----------------------------------------------------------------
    // Private: one method per dashboard card
    // ----------------------------------------------------------------

    private function activeClients(int $coachId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS total
            FROM client_profiles cp
            JOIN coach_assignments ca ON ca.client_profile_id = cp.id
            WHERE ca.coach_id = ?
              AND cp.is_active = 1
        ");
        $stmt->execute([$coachId]);
        return (int) $stmt->fetch()['total'];
    }

    private function completedEngagements(int $coachId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS total
            FROM client_profiles cp
            JOIN coach_assignments ca ON ca.client_profile_id = cp.id
            WHERE ca.coach_id = ?
              AND cp.is_active = 0
        ");
        $stmt->execute([$coachId]);
        return (int) $stmt->fetch()['total'];
    }

    private function goalsByStatus(int $coachId, string $status): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS total
            FROM goals g
            JOIN client_profiles cp ON cp.id = g.client_id
            JOIN coach_assignments ca ON ca.client_profile_id = cp.id
            WHERE ca.coach_id = ?
              AND g.status = ?
        ");
        $stmt->execute([$coachId, $status]);
        return (int) $stmt->fetch()['total'];
    }

    private function pendingActionItems(int $coachId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS total
            FROM action_items ai
            JOIN goals g ON g.id = ai.goal_id
            JOIN client_profiles cp ON cp.id = g.client_id
            JOIN coach_assignments ca ON ca.client_profile_id = cp.id
            WHERE ca.coach_id = ?
              AND ai.status = 'pending'
        ");
        $stmt->execute([$coachId]);
        return (int) $stmt->fetch()['total'];
    }

    private function upcomingSessions(int $coachId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS total
            FROM sessions s
            JOIN client_profiles cp ON cp.id = s.client_profile_id
            JOIN coach_assignments ca ON ca.client_profile_id = cp.id
            WHERE ca.coach_id = ?
              AND s.status = 'approved'
              AND s.session_datetime >= NOW()
        ");
        $stmt->execute([$coachId]);
        return (int) $stmt->fetch()['total'];
    }

    private function unreadNotifications(int $coachId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS total
            FROM notifications
            WHERE user_id = ?
              AND is_read = 0
        ");
        $stmt->execute([$coachId]);
        return (int) $stmt->fetch()['total'];
    }
}
