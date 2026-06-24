<?php

require_once __DIR__ . '/../config/database.php';

class SessionNoteService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // ----------------------------------------------------------------
    // GET /sessions/{id}/notes
    // Returns the note or data: null — never 404.
    // The session exists; it simply may not be documented yet.
    // ----------------------------------------------------------------
    public function get(int $coachId, int $sessionId): array
    {
        if (!$this->coachOwnsSession($coachId, $sessionId)) {
            return ['status' => 403, 'body' => ['success' => false, 'message' => 'Access denied']];
        }

        $stmt = $this->db->prepare("
            SELECT id, session_id, key_insights, decisions, commitments,
                   coach_observations, next_focus, created_at
            FROM session_notes
            WHERE session_id = ?
        ");
        $stmt->execute([$sessionId]);
        $note = $stmt->fetch() ?: null;

        return ['status' => 200, 'body' => ['success' => true, 'data' => $note]];
    }

    // ----------------------------------------------------------------
    // POST /sessions/{id}/notes
    // Business rules enforced before touching the database:
    //   1. Session must exist and belong to this coach.
    //   2. Session must have status = completed.
    //   3. A note must not already exist (pre-empt the UNIQUE constraint).
    // ----------------------------------------------------------------
    public function create(int $coachId, int $sessionId, array $data): array
    {
        if (!$this->coachOwnsSession($coachId, $sessionId)) {
            return ['status' => 403, 'body' => ['success' => false, 'message' => 'Access denied']];
        }

        if (!$this->sessionIsCompleted($sessionId)) {
            return [
                'status' => 400,
                'body'   => ['success' => false, 'message' => 'Session notes can only be added to completed sessions'],
            ];
        }

        if ($this->noteExists($sessionId)) {
            return [
                'status' => 409,
                'body'   => ['success' => false, 'message' => 'A note already exists for this session. Use PUT to update it.'],
            ];
        }

        $keyInsights      = trim($data['key_insights']      ?? '');
        $decisions        = trim($data['decisions']         ?? '');
        $commitments      = trim($data['commitments']       ?? '');
        $coachObs         = trim($data['coach_observations'] ?? '');
        $nextFocus        = trim($data['next_focus']        ?? '');

        if ($keyInsights === '') {
            return [
                'status' => 400,
                'body'   => ['success' => false, 'message' => 'Validation failed', 'data' => ['key_insights' => 'Key insights are required']],
            ];
        }

        $stmt = $this->db->prepare("
            INSERT INTO session_notes
                (session_id, key_insights, decisions, commitments, coach_observations, next_focus)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $sessionId,
            $keyInsights,
            $decisions  ?: null,
            $commitments ?: null,
            $coachObs   ?: null,
            $nextFocus  ?: null,
        ]);

        return [
            'status' => 201,
            'body'   => [
                'success' => true,
                'message' => 'Session note saved',
                'data'    => ['id' => (int) $this->db->lastInsertId()],
            ],
        ];
    }

    // ----------------------------------------------------------------
    // PUT /sessions/{id}/notes
    // Partial update — send only the fields that changed.
    // session_id and created_at are never modifiable.
    // ----------------------------------------------------------------
    public function update(int $coachId, int $sessionId, array $data): array
    {
        if (!$this->coachOwnsSession($coachId, $sessionId)) {
            return ['status' => 403, 'body' => ['success' => false, 'message' => 'Access denied']];
        }

        if (!$this->noteExists($sessionId)) {
            return ['status' => 404, 'body' => ['success' => false, 'message' => 'No note found for this session. Use POST to create one.']];
        }

        $allowed = ['key_insights', 'decisions', 'commitments', 'coach_observations', 'next_focus'];
        $fields  = [];
        $values  = [];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            if ($field === 'key_insights' && trim($data[$field]) === '') {
                return ['status' => 400, 'body' => ['success' => false, 'message' => 'Key insights cannot be empty']];
            }

            $fields[] = "{$field} = ?";
            $values[] = trim($data[$field]) ?: null;
        }

        if (empty($fields)) {
            return ['status' => 400, 'body' => ['success' => false, 'message' => 'No valid fields provided']];
        }

        $values[] = $sessionId;
        $stmt = $this->db->prepare(
            'UPDATE session_notes SET ' . implode(', ', $fields) . ' WHERE session_id = ?'
        );
        $stmt->execute($values);

        return ['status' => 200, 'body' => ['success' => true, 'message' => 'Session note updated']];
    }

    // ----------------------------------------------------------------
    // Private helpers
    // ----------------------------------------------------------------

    private function coachOwnsSession(int $coachId, int $sessionId): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1
            FROM sessions s
            JOIN client_profiles cp ON cp.id = s.client_profile_id
            JOIN coach_assignments ca ON ca.client_profile_id = cp.id
            WHERE s.id = ? AND ca.coach_id = ?
            LIMIT 1
        ");
        $stmt->execute([$sessionId, $coachId]);
        return (bool) $stmt->fetch();
    }

    private function sessionIsCompleted(int $sessionId): bool
    {
        $stmt = $this->db->prepare("SELECT status FROM sessions WHERE id = ?");
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch();
        return $row && $row['status'] === 'completed';
    }

    private function noteExists(int $sessionId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM session_notes WHERE session_id = ? LIMIT 1");
        $stmt->execute([$sessionId]);
        return (bool) $stmt->fetch();
    }
}
