<?php

require_once __DIR__ . '/../config/database.php';

class ClientService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // ----------------------------------------------------------------
    // GET /clients
    // All clients assigned to the authenticated coach.
    // ----------------------------------------------------------------
    public function list(int $coachId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                cp.id,
                cp.user_id,
                u.name,
                u.email,
                cp.phone,
                cp.organization,
                cp.job_title,
                cp.coaching_type,
                cp.start_date,
                cp.lifecycle_stage,
                cp.is_active
            FROM client_profiles cp
            JOIN users u ON u.id = cp.user_id
            JOIN coach_assignments ca ON ca.client_profile_id = cp.id
            WHERE ca.coach_id = ?
            ORDER BY u.name ASC
        ");
        $stmt->execute([$coachId]);
        $clients = $stmt->fetchAll();

        foreach ($clients as &$c) {
            $c['is_active'] = (bool) $c['is_active'];
        }

        return ['status' => 200, 'body' => ['success' => true, 'data' => $clients]];
    }

    // ----------------------------------------------------------------
    // GET /clients/{id}
    // Single client profile with context notes.
    // ----------------------------------------------------------------
    public function get(int $coachId, int $clientProfileId): array
    {
        if (!$this->coachOwnsClient($coachId, $clientProfileId)) {
            return ['status' => 403, 'body' => ['success' => false, 'message' => 'Access denied']];
        }

        $stmt = $this->db->prepare("
            SELECT
                cp.id,
                cp.user_id,
                u.name,
                u.email,
                cp.phone,
                cp.organization,
                cp.job_title,
                cp.coaching_type,
                cp.start_date,
                cp.lifecycle_stage,
                cp.is_active
            FROM client_profiles cp
            JOIN users u ON u.id = cp.user_id
            WHERE cp.id = ?
        ");
        $stmt->execute([$clientProfileId]);
        $client = $stmt->fetch();

        if (!$client) {
            return ['status' => 404, 'body' => ['success' => false, 'message' => 'Client not found']];
        }

        $client['is_active'] = (bool) $client['is_active'];
        $client['context']   = $this->getContextRows($clientProfileId);

        return ['status' => 200, 'body' => ['success' => true, 'data' => $client]];
    }

    // ----------------------------------------------------------------
    // POST /clients
    // Create user (role=client) + client_profiles row + coach_assignment.
    // ----------------------------------------------------------------
    public function create(int $coachId, array $data): array
    {
        $name           = trim($data['name']           ?? '');
        $email          = trim($data['email']          ?? '');
        $pass           = $data['password']             ?? '';
        $phone          = trim($data['phone']           ?? '');
        $organization   = trim($data['organization']   ?? '');
        $jobTitle       = trim($data['job_title']      ?? '');
        $coachingType   = trim($data['coaching_type']  ?? '');
        $startDate      = $data['start_date']           ?? null;
        $lifecycleStage = $data['lifecycle_stage']      ?? 'Discovery';

        $validStages = ['Discovery', 'Goal Setting', 'Active Coaching', 'Midpoint Review', 'Closure'];

        $errors = [];
        if ($name === '')                                    $errors['name']     = 'Name is required';
        if ($email === '')                                   $errors['email']    = 'Email is required';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email']    = 'Invalid email format';
        if ($pass === '')                                    $errors['password'] = 'Password is required';
        elseif (strlen($pass) < 8)                          $errors['password'] = 'Minimum 8 characters required';
        if (!in_array($lifecycleStage, $validStages))       $errors['lifecycle_stage'] = 'Invalid lifecycle stage';

        if (!empty($errors)) {
            return ['status' => 400, 'body' => ['success' => false, 'message' => 'Validation failed', 'data' => $errors]];
        }

        // Duplicate email check
        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            return ['status' => 409, 'body' => ['success' => false, 'message' => 'An account with this email already exists']];
        }

        // Three inserts — wrap in a transaction so they succeed or fail together
        $this->db->beginTransaction();
        try {
            // 1. users row
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            $stmt = $this->db->prepare(
                'INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, "client")'
            );
            $stmt->execute([$name, $email, $hash]);
            $userId = (int) $this->db->lastInsertId();

            // 2. client_profiles row
            $stmt = $this->db->prepare("
                INSERT INTO client_profiles
                    (user_id, organization, job_title, coaching_type, start_date, lifecycle_stage, phone)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $organization, $jobTitle, $coachingType, $startDate ?: null, $lifecycleStage, $phone ?: null]);
            $profileId = (int) $this->db->lastInsertId();

            // 3. coach_assignments row
            $stmt = $this->db->prepare(
                'INSERT INTO coach_assignments (coach_id, client_profile_id, is_primary, permission) VALUES (?, ?, 1, "edit")'
            );
            $stmt->execute([$coachId, $profileId]);

            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log('ClientService::create failed: ' . $e->getMessage());
            return ['status' => 500, 'body' => ['success' => false, 'message' => 'Failed to create client']];
        }

        return [
            'status' => 201,
            'body'   => [
                'success' => true,
                'message' => 'Client created successfully',
                'data'    => ['id' => $profileId, 'user_id' => $userId, 'name' => $name, 'email' => $email],
            ],
        ];
    }

    // ----------------------------------------------------------------
    // PUT /clients/{id}
    // Update client_profiles fields. Does not touch email or password.
    // ----------------------------------------------------------------
    public function update(int $coachId, int $clientProfileId, array $data): array
    {
        if (!$this->coachOwnsClient($coachId, $clientProfileId)) {
            return ['status' => 403, 'body' => ['success' => false, 'message' => 'Access denied']];
        }

        $validStages = ['Discovery', 'Goal Setting', 'Active Coaching', 'Midpoint Review', 'Closure'];

        // Only update fields that were sent
        $allowed = ['phone', 'organization', 'job_title', 'coaching_type', 'start_date', 'lifecycle_stage', 'is_active'];
        $fields  = [];
        $values  = [];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) continue;

            if ($field === 'lifecycle_stage' && !in_array($data[$field], $validStages)) {
                return ['status' => 400, 'body' => ['success' => false, 'message' => 'Invalid lifecycle stage']];
            }

            $fields[] = "{$field} = ?";
            $values[] = ($field === 'is_active') ? ($data[$field] ? 1 : 0) : $data[$field];
        }

        if (empty($fields)) {
            return ['status' => 400, 'body' => ['success' => false, 'message' => 'No valid fields provided']];
        }

        $values[] = $clientProfileId;
        $sql      = 'UPDATE client_profiles SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt     = $this->db->prepare($sql);
        $stmt->execute($values);

        return ['status' => 200, 'body' => ['success' => true, 'message' => 'Client updated successfully']];
    }

    // ----------------------------------------------------------------
    // DELETE /clients/{id}
    // Soft-delete: set is_active = false, lifecycle_stage = Closure.
    // ----------------------------------------------------------------
    public function deactivate(int $coachId, int $clientProfileId): array
    {
        if (!$this->coachOwnsClient($coachId, $clientProfileId)) {
            return ['status' => 403, 'body' => ['success' => false, 'message' => 'Access denied']];
        }

        $stmt = $this->db->prepare(
            "UPDATE client_profiles SET is_active = 0, lifecycle_stage = 'Closure' WHERE id = ?"
        );
        $stmt->execute([$clientProfileId]);

        return ['status' => 200, 'body' => ['success' => true, 'message' => 'Client deactivated']];
    }

    // ----------------------------------------------------------------
    // GET /clients/{id}/context
    // ----------------------------------------------------------------
    public function getContext(int $coachId, int $clientProfileId): array
    {
        if (!$this->coachOwnsClient($coachId, $clientProfileId)) {
            return ['status' => 403, 'body' => ['success' => false, 'message' => 'Access denied']];
        }

        return ['status' => 200, 'body' => ['success' => true, 'data' => $this->getContextRows($clientProfileId)]];
    }

    // ----------------------------------------------------------------
    // PUT /clients/{id}/context
    // Upsert: one record per category per client.
    // ----------------------------------------------------------------
    public function upsertContext(int $coachId, int $clientProfileId, array $data): array
    {
        if (!$this->coachOwnsClient($coachId, $clientProfileId)) {
            return ['status' => 403, 'body' => ['success' => false, 'message' => 'Access denied']];
        }

        $category = trim($data['category'] ?? '');
        $content  = trim($data['content']  ?? '');

        if ($category === '') {
            return ['status' => 400, 'body' => ['success' => false, 'message' => 'Category is required']];
        }
        if ($content === '') {
            return ['status' => 400, 'body' => ['success' => false, 'message' => 'Content is required']];
        }
        if (strlen($category) > 50) {
            return ['status' => 400, 'body' => ['success' => false, 'message' => 'Category must be 50 characters or fewer']];
        }

        // INSERT ... ON DUPLICATE KEY UPDATE respects UNIQUE(client_id, category)
        $stmt = $this->db->prepare("
            INSERT INTO client_contexts (client_id, category, content)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE content = VALUES(content), updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$clientProfileId, $category, $content]);

        return ['status' => 200, 'body' => ['success' => true, 'message' => 'Context saved']];
    }

    // ----------------------------------------------------------------
    // Private helpers
    // ----------------------------------------------------------------

    private function coachOwnsClient(int $coachId, int $clientProfileId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM coach_assignments WHERE coach_id = ? AND client_profile_id = ?'
        );
        $stmt->execute([$coachId, $clientProfileId]);
        return (bool) $stmt->fetch();
    }

    private function getContextRows(int $clientProfileId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, category, content, updated_at FROM client_contexts WHERE client_id = ? ORDER BY category ASC'
        );
        $stmt->execute([$clientProfileId]);
        return $stmt->fetchAll();
    }
}
