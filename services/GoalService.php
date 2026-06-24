<?php

require_once __DIR__ . '/../config/database.php';

class GoalService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // ----------------------------------------------------------------
    // GET /clients/{id}/goals
    // ----------------------------------------------------------------
    public function list(int $coachId, int $clientProfileId): array
    {
        if (!$this->coachOwnsClient($coachId, $clientProfileId)) {
            return ['status' => 403, 'body' => ['success' => false, 'message' => 'Access denied']];
        }

        $stmt = $this->db->prepare("
            SELECT id, client_id, title, description, success_definition,
                   timeline_months, progress_percentage, status, created_at
            FROM goals
            WHERE client_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$clientProfileId]);

        return ['status' => 200, 'body' => ['success' => true, 'data' => $stmt->fetchAll()]];
    }

    // ----------------------------------------------------------------
    // POST /clients/{id}/goals
    // ----------------------------------------------------------------
    public function create(int $coachId, int $clientProfileId, array $data): array
    {
        if (!$this->coachOwnsClient($coachId, $clientProfileId)) {
            return ['status' => 403, 'body' => ['success' => false, 'message' => 'Access denied']];
        }

        $title             = trim($data['title']              ?? '');
        $description       = trim($data['description']        ?? '');
        $successDefinition = trim($data['success_definition'] ?? '');
        $timelineMonths    = isset($data['timeline_months']) ? (int) $data['timeline_months'] : null;

        $errors = [];
        if ($title === '')       $errors['title']           = 'Title is required';
        if (strlen($title) > 200) $errors['title']          = 'Title must be 200 characters or fewer';
        if ($timelineMonths !== null && $timelineMonths <= 0)
                                 $errors['timeline_months'] = 'Timeline must be a positive number of months';

        if (!empty($errors)) {
            return ['status' => 400, 'body' => ['success' => false, 'message' => 'Validation failed', 'data' => $errors]];
        }

        $stmt = $this->db->prepare("
            INSERT INTO goals
                (client_id, title, description, success_definition, timeline_months, progress_percentage, status)
            VALUES (?, ?, ?, ?, ?, 0, 'active')
        ");
        $stmt->execute([
            $clientProfileId,
            $title,
            $description ?: null,
            $successDefinition ?: null,
            $timelineMonths,
        ]);

        return [
            'status' => 201,
            'body'   => ['success' => true, 'message' => 'Goal created', 'data' => ['id' => (int) $this->db->lastInsertId()]],
        ];
    }

    // ----------------------------------------------------------------
    // PUT /goals/{id}
    // Partial update with two auto-consistency rules:
    //   1. status = completed  → progress_percentage forced to 100
    //   2. progress_percentage = 100 + status is active → status promoted to completed
    // ----------------------------------------------------------------
    public function update(int $coachId, int $goalId, array $data): array
    {
        if (!$this->coachOwnsGoal($coachId, $goalId)) {
            return ['status' => 403, 'body' => ['success' => false, 'message' => 'Access denied']];
        }

        $validStatuses = ['active', 'completed', 'cancelled'];
        $allowed       = ['title', 'description', 'success_definition', 'timeline_months', 'progress_percentage', 'status'];

        // Collect only the fields that were sent
        $incoming = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $incoming[$field] = $data[$field];
            }
        }

        if (empty($incoming)) {
            return ['status' => 400, 'body' => ['success' => false, 'message' => 'No valid fields provided']];
        }

        // Validate individual fields
        $errors = [];
        if (isset($incoming['title'])) {
            if (trim($incoming['title']) === '') $errors['title'] = 'Title cannot be empty';
            if (strlen($incoming['title']) > 200) $errors['title'] = 'Title must be 200 characters or fewer';
        }
        if (isset($incoming['timeline_months']) && (int) $incoming['timeline_months'] <= 0) {
            $errors['timeline_months'] = 'Timeline must be a positive number of months';
        }
        if (isset($incoming['progress_percentage'])) {
            $pct = (int) $incoming['progress_percentage'];
            if ($pct < 0 || $pct > 100) $errors['progress_percentage'] = 'Progress must be between 0 and 100';
        }
        if (isset($incoming['status']) && !in_array($incoming['status'], $validStatuses)) {
            $errors['status'] = 'Status must be active, completed, or cancelled';
        }

        if (!empty($errors)) {
            return ['status' => 400, 'body' => ['success' => false, 'message' => 'Validation failed', 'data' => $errors]];
        }

        // Auto-consistency rule 1: completing a goal forces 100%
        if (isset($incoming['status']) && $incoming['status'] === 'completed') {
            $incoming['progress_percentage'] = 100;
        }

        // Auto-consistency rule 2: hitting 100% on an active goal promotes it to completed
        if (isset($incoming['progress_percentage']) && (int) $incoming['progress_percentage'] === 100) {
            if (!isset($incoming['status'])) {
                // Only promote if caller didn't explicitly set another status
                $current = $this->fetchGoalField($goalId, 'status');
                if ($current === 'active') {
                    $incoming['status'] = 'completed';
                }
            }
        }

        // Build dynamic SET clause
        $fields = [];
        $values = [];
        foreach ($incoming as $field => $value) {
            $fields[] = "{$field} = ?";
            $values[] = $value;
        }
        $values[] = $goalId;

        $stmt = $this->db->prepare('UPDATE goals SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($values);

        return ['status' => 200, 'body' => ['success' => true, 'message' => 'Goal updated']];
    }

    // ----------------------------------------------------------------
    // DELETE /goals/{id}
    // Hard delete — action_items and progress_logs cascade automatically.
    // ----------------------------------------------------------------
    public function delete(int $coachId, int $goalId): array
    {
        if (!$this->coachOwnsGoal($coachId, $goalId)) {
            return ['status' => 403, 'body' => ['success' => false, 'message' => 'Access denied']];
        }

        $stmt = $this->db->prepare('DELETE FROM goals WHERE id = ?');
        $stmt->execute([$goalId]);

        if ($stmt->rowCount() === 0) {
            return ['status' => 404, 'body' => ['success' => false, 'message' => 'Goal not found']];
        }

        return ['status' => 200, 'body' => ['success' => true, 'message' => 'Goal deleted']];
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

    private function coachOwnsClient(int $coachId, int $clientProfileId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM coach_assignments WHERE coach_id = ? AND client_profile_id = ?'
        );
        $stmt->execute([$coachId, $clientProfileId]);
        return (bool) $stmt->fetch();
    }

    private function fetchGoalField(int $goalId, string $field): mixed
    {
        $stmt = $this->db->prepare("SELECT {$field} FROM goals WHERE id = ?");
        $stmt->execute([$goalId]);
        $row = $stmt->fetch();
        return $row ? $row[$field] : null;
    }
}
