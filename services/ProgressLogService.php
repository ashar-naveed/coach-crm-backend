<?php

require_once __DIR__ . '/../config/database.php';

class ProgressLogService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // ----------------------------------------------------------------
    // GET /goals/{id}/progress-logs
    // Ordered oldest → newest for chart rendering.
    // ----------------------------------------------------------------
    public function list(int $coachId, int $goalId): array
    {
        if (!$this->coachOwnsGoal($coachId, $goalId)) {
            return ['status' => 403, 'body' => ['success' => false, 'message' => 'Access denied']];
        }

        $stmt = $this->db->prepare("
            SELECT id, goal_id, progress_percentage, confidence_level, behavior_notes, created_at
            FROM progress_logs
            WHERE goal_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$goalId]);

        return ['status' => 200, 'body' => ['success' => true, 'data' => $stmt->fetchAll()]];
    }

    // ----------------------------------------------------------------
    // POST /goals/{id}/progress-logs
    // Appends a log entry and syncs goals.progress_percentage.
    // If progress hits 100, goal status is promoted to completed.
    // Both writes are wrapped in a transaction.
    // ----------------------------------------------------------------
    public function create(int $coachId, int $goalId, array $data): array
    {
        if (!$this->coachOwnsGoal($coachId, $goalId)) {
            return ['status' => 403, 'body' => ['success' => false, 'message' => 'Access denied']];
        }

        // Validation
        $errors = [];

        if (!isset($data['progress_percentage'])) {
            $errors['progress_percentage'] = 'Progress percentage is required';
        } else {
            $pct = (int) $data['progress_percentage'];
            if ($pct < 0 || $pct > 100) {
                $errors['progress_percentage'] = 'Progress percentage must be between 0 and 100';
            }
        }

        if (!isset($data['confidence_level'])) {
            $errors['confidence_level'] = 'Confidence level is required';
        } else {
            $confidence = (int) $data['confidence_level'];
            if ($confidence < 1 || $confidence > 10) {
                $errors['confidence_level'] = 'Confidence level must be between 1 and 10';
            }
        }

        if (!empty($errors)) {
            return ['status' => 400, 'body' => ['success' => false, 'message' => 'Validation failed', 'data' => $errors]];
        }

        $pct           = (int) $data['progress_percentage'];
        $confidence    = (int) $data['confidence_level'];
        $behaviorNotes = trim($data['behavior_notes'] ?? '') ?: null;

        $this->db->beginTransaction();
        try {
            // 1. Append the history record
            $stmt = $this->db->prepare("
                INSERT INTO progress_logs (goal_id, progress_percentage, confidence_level, behavior_notes)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$goalId, $pct, $confidence, $behaviorNotes]);
            $logId = (int) $this->db->lastInsertId();

            // 2. Sync goal's current progress
            if ($pct === 100) {
                // Hitting 100% promotes an active goal to completed
                $stmt = $this->db->prepare("
                    UPDATE goals
                    SET progress_percentage = 100,
                        status = CASE WHEN status = 'active' THEN 'completed' ELSE status END
                    WHERE id = ?
                ");
            } else {
                $stmt = $this->db->prepare(
                    'UPDATE goals SET progress_percentage = ? WHERE id = ?'
                );
            }
            $stmt->execute($pct === 100 ? [$goalId] : [$pct, $goalId]);

            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log('ProgressLogService::create failed: ' . $e->getMessage());
            return ['status' => 500, 'body' => ['success' => false, 'message' => 'Failed to save progress log']];
        }

        return [
            'status' => 201,
            'body'   => [
                'success' => true,
                'message' => 'Progress logged',
                'data'    => ['id' => $logId],
            ],
        ];
    }

    // ----------------------------------------------------------------
    // Private helpers
    // ----------------------------------------------------------------

    private function coachOwnsGoal(int $coachId, int $goalId): bool
    {
        $stmt = $this->db->prepare("
            SELECT g.id
            FROM goals g
            JOIN client_profiles cp ON cp.id = g.client_id
            JOIN coach_assignments ca ON ca.client_profile_id = cp.id
            WHERE g.id = ? AND ca.coach_id = ?
        ");
        $stmt->execute([$goalId, $coachId]);
        return (bool) $stmt->fetch();
    }
}
