<?php

require_once __DIR__ . '/../config/database.php';

class ActionItemService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // ----------------------------------------------------------------
    // GET /goals/{id}/action-items
    // Sorted: pending → delayed → done, then by due_date ASC.
    // ----------------------------------------------------------------
    public function list(int $coachId, int $goalId): array
    {
        if (!$this->coachOwnsGoal($coachId, $goalId)) {
            return ['status' => 403, 'body' => ['success' => false, 'message' => 'Access denied']];
        }

        $stmt = $this->db->prepare("
            SELECT
                ai.id,
                ai.goal_id,
                ai.created_by,
                u.name AS created_by_name,
                ai.title,
                ai.description,
                ai.due_date,
                ai.status,
                ai.completed_at,
                ai.created_at
            FROM action_items ai
            JOIN users u ON u.id = ai.created_by
            WHERE ai.goal_id = ?
            ORDER BY
                CASE ai.status
                    WHEN 'pending' THEN 1
                    WHEN 'delayed' THEN 2
                    WHEN 'done'    THEN 3
                END,
                ai.due_date ASC
        ");
        $stmt->execute([$goalId]);

        return ['status' => 200, 'body' => ['success' => true, 'data' => $stmt->fetchAll()]];
    }

    // ----------------------------------------------------------------
    // POST /goals/{id}/action-items
    // ----------------------------------------------------------------
    public function create(int $coachId, int $goalId, array $data): array
    {
        if (!$this->coachOwnsGoal($coachId, $goalId)) {
            return ['status' => 403, 'body' => ['success' => false, 'message' => 'Access denied']];
        }

        $title       = trim($data['title']       ?? '');
        $description = trim($data['description'] ?? '');
        $dueDate     = $data['due_date']          ?? null;

        $errors = [];
        if ($title === '')         $errors['title']    = 'Title is required';
        if (strlen($title) > 200)  $errors['title']    = 'Title must be 200 characters or fewer';
        if ($dueDate !== null && !$this->isValidDate($dueDate)) {
                                   $errors['due_date'] = 'Invalid date format. Use YYYY-MM-DD';
        }

        if (!empty($errors)) {
            return ['status' => 400, 'body' => ['success' => false, 'message' => 'Validation failed', 'data' => $errors]];
        }

        $stmt = $this->db->prepare("
            INSERT INTO action_items (goal_id, created_by, title, description, due_date, status)
            VALUES (?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([
            $goalId,
            $coachId,
            $title,
            $description ?: null,
            $dueDate ?: null,
        ]);

        return [
            'status' => 201,
            'body'   => [
                'success' => true,
                'message' => 'Action item created',
                'data'    => ['id' => (int) $this->db->lastInsertId()],
            ],
        ];
    }

    // ----------------------------------------------------------------
    // PUT /action-items/{id}
    // Update title, description, due_date only.
    // completed_at is never directly editable — managed by patchStatus().
    // ----------------------------------------------------------------
    public function update(int $coachId, int $actionItemId, array $data): array
    {
        if (!$this->coachOwnsActionItem($coachId, $actionItemId)) {
            return ['status' => 403, 'body' => ['success' => false, 'message' => 'Access denied']];
        }

        $allowed = ['title', 'description', 'due_date'];
        $fields  = [];
        $values  = [];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) continue;

            if ($field === 'title') {
                $val = trim($data['title']);
                if ($val === '')        return ['status' => 400, 'body' => ['success' => false, 'message' => 'Title cannot be empty']];
                if (strlen($val) > 200) return ['status' => 400, 'body' => ['success' => false, 'message' => 'Title must be 200 characters or fewer']];
                $fields[] = 'title = ?';
                $values[] = $val;
                continue;
            }

            if ($field === 'due_date' && $data['due_date'] !== null && !$this->isValidDate($data['due_date'])) {
                return ['status' => 400, 'body' => ['success' => false, 'message' => 'Invalid date format. Use YYYY-MM-DD']];
            }

            $fields[] = "{$field} = ?";
            $values[] = $data[$field] ?: null;
        }

        if (empty($fields)) {
            return ['status' => 400, 'body' => ['success' => false, 'message' => 'No valid fields provided']];
        }

        $values[] = $actionItemId;
        $stmt = $this->db->prepare('UPDATE action_items SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($values);

        return ['status' => 200, 'body' => ['success' => true, 'message' => 'Action item updated']];
    }

    // ----------------------------------------------------------------
    // PATCH /action-items/{id}/status
    // Business rules:
    //   status → done     : completed_at = CURRENT_TIMESTAMP
    //   status → anything else : completed_at = NULL
    // ----------------------------------------------------------------
    public function patchStatus(int $coachId, int $actionItemId, array $data): array
    {
        if (!$this->coachOwnsActionItem($coachId, $actionItemId)) {
            return ['status' => 403, 'body' => ['success' => false, 'message' => 'Access denied']];
        }

        $status = $data['status'] ?? '';

        if (!in_array($status, ['pending', 'done', 'delayed'])) {
            return ['status' => 400, 'body' => ['success' => false, 'message' => 'Status must be pending, done, or delayed']];
        }

        if ($status === 'done') {
            $stmt = $this->db->prepare(
                "UPDATE action_items SET status = 'done', completed_at = CURRENT_TIMESTAMP WHERE id = ?"
            );
            $stmt->execute([$actionItemId]);

            // Fetch completed_at to return in response
            $row = $this->db->prepare('SELECT completed_at FROM action_items WHERE id = ?');
            $row->execute([$actionItemId]);
            $completedAt = $row->fetch()['completed_at'];

            return [
                'status' => 200,
                'body'   => [
                    'success' => true,
                    'message' => 'Status updated',
                    'data'    => ['status' => 'done', 'completed_at' => $completedAt],
                ],
            ];
        }

        // pending or delayed — clear completed_at
        $stmt = $this->db->prepare(
            'UPDATE action_items SET status = ?, completed_at = NULL WHERE id = ?'
        );
        $stmt->execute([$status, $actionItemId]);

        return [
            'status' => 200,
            'body'   => [
                'success' => true,
                'message' => 'Status updated',
                'data'    => ['status' => $status, 'completed_at' => null],
            ],
        ];
    }

    // ----------------------------------------------------------------
    // DELETE /action-items/{id}
    // ----------------------------------------------------------------
    public function delete(int $coachId, int $actionItemId): array
    {
        if (!$this->coachOwnsActionItem($coachId, $actionItemId)) {
            return ['status' => 403, 'body' => ['success' => false, 'message' => 'Access denied']];
        }

        $stmt = $this->db->prepare('DELETE FROM action_items WHERE id = ?');
        $stmt->execute([$actionItemId]);

        if ($stmt->rowCount() === 0) {
            return ['status' => 404, 'body' => ['success' => false, 'message' => 'Action item not found']];
        }

        return ['status' => 200, 'body' => ['success' => true, 'message' => 'Action item deleted']];
    }

    // ----------------------------------------------------------------
    // Private helpers
    // ----------------------------------------------------------------

    private function coachOwnsActionItem(int $coachId, int $actionItemId): bool
    {
        $stmt = $this->db->prepare("
            SELECT ai.id
            FROM action_items ai
            JOIN goals g ON g.id = ai.goal_id
            JOIN client_profiles cp ON cp.id = g.client_id
            JOIN coach_assignments ca ON ca.client_profile_id = cp.id
            WHERE ai.id = ? AND ca.coach_id = ?
        ");
        $stmt->execute([$actionItemId, $coachId]);
        return (bool) $stmt->fetch();
    }

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

    private function isValidDate(string $date): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }
}
