<?php

require_once __DIR__ . '/../config/database.php';

class SessionService
{
    private PDO $db;

    // Valid transitions: currentStatus => allowed targetStatuses
    private const TRANSITIONS = [
        'pending'   => ['approved', 'rejected', 'cancelled'],
        'approved'  => ['completed', 'cancelled'],
        'rejected'  => [],
        'completed' => [],
        'cancelled' => [],
    ];

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // ----------------------------------------------------------------
    // GET /sessions
    // All sessions for the coach, optionally filtered by client/status.
    // ----------------------------------------------------------------
    public function list(int $coachId, array $filters = []): array
    {
        $where  = ['s.coach_id = ?'];
        $values = [$coachId];

        if (!empty($filters['client_id'])) {
            $where[]  = 's.client_profile_id = ?';
            $values[] = (int) $filters['client_id'];
        }
        if (!empty($filters['status'])) {
            $where[]  = 's.status = ?';
            $values[] = $filters['status'];
        }

        $stmt = $this->db->prepare("
            SELECT
                s.id,
                s.client_profile_id,
                u.name    AS client_name,
                s.coach_id,
                s.requested_by,
                s.session_datetime,
                s.duration_minutes,
                s.type,
                s.location,
                s.meeting_link,
                s.status,
                s.created_at
            FROM sessions s
            JOIN client_profiles cp ON cp.id = s.client_profile_id
            JOIN users u ON u.id = cp.user_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY s.session_datetime ASC
        ");
        $stmt->execute($values);

        return ['status' => 200, 'body' => ['success' => true, 'data' => $stmt->fetchAll()]];
    }

    public function clientList(int $userId): array
   {
    $stmt = $this->db->prepare("
        SELECT s.id, s.session_datetime, s.duration_minutes, s.type,
               s.meeting_link, s.location, s.status,
               u.name as coach_name
        FROM sessions s
        JOIN client_profiles cp ON cp.id = s.client_profile_id
        JOIN coach_assignments ca ON ca.client_profile_id = cp.id
        JOIN users u ON u.id = ca.coach_id
        WHERE cp.user_id = ?
        ORDER BY s.session_datetime DESC
    ");
    $stmt->execute([$userId]);
    return ['status' => 200, 'body' => ['success' => true, 'data' => $stmt->fetchAll()]];
   }

    // ----------------------------------------------------------------
    // GET /sessions/{id}
    // Single session with note inline (null if not yet written).
    // ----------------------------------------------------------------
    public function get(int $coachId, int $sessionId): array
    {
        if (!$this->coachOwnsSession($coachId, $sessionId)) {
            return ['status' => 403, 'body' => ['success' => false, 'message' => 'Access denied']];
        }

        $stmt = $this->db->prepare("
            SELECT
                s.id,
                s.client_profile_id,
                u.name    AS client_name,
                s.coach_id,
                s.requested_by,
                s.session_datetime,
                s.duration_minutes,
                s.type,
                s.location,
                s.meeting_link,
                s.status,
                s.created_at
            FROM sessions s
            JOIN client_profiles cp ON cp.id = s.client_profile_id
            JOIN users u ON u.id = cp.user_id
            WHERE s.id = ?
        ");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch();

        if (!$session) {
            return ['status' => 404, 'body' => ['success' => false, 'message' => 'Session not found']];
        }

        // Fetch note inline — null if not yet written
        $noteStmt = $this->db->prepare("
            SELECT id, key_insights, decisions, commitments, coach_observations, next_focus, created_at
            FROM session_notes
            WHERE session_id = ?
        ");
        $noteStmt->execute([$sessionId]);
        $session['note'] = $noteStmt->fetch() ?: null;

        return ['status' => 200, 'body' => ['success' => true, 'data' => $session]];
    }

    // ----------------------------------------------------------------
    // POST /sessions  (coach creates)
    // requested_by = coach, status = approved automatically.
    // ----------------------------------------------------------------
    public function coachCreate(int $coachId, array $data): array
    {
        $validation = $this->validateSessionData($data);
        if ($validation !== null) {
            return ['status' => 400, 'body' => ['success' => false, 'message' => 'Validation failed', 'data' => $validation]];
        }

        $clientProfileId = (int) $data['client_profile_id'];

        if (!$this->coachOwnsClient($coachId, $clientProfileId)) {
            return ['status' => 403, 'body' => ['success' => false, 'message' => 'Access denied']];
        }

        $stmt = $this->db->prepare("
            INSERT INTO sessions
                (client_profile_id, coach_id, requested_by, session_datetime,
                 duration_minutes, type, location, meeting_link, status)
            VALUES (?, ?, 'coach', ?, ?, ?, ?, ?, 'approved')
        ");
        $stmt->execute([
            $clientProfileId,
            $coachId,
            $data['session_datetime'],
            (int) $data['duration_minutes'],
            $data['type'],
            $data['type'] === 'physical' ? ($data['location']     ?? null) : null,
            $data['type'] === 'online'   ? ($data['meeting_link'] ?? null) : null,
        ]);
        $sessionId = (int) $this->db->lastInsertId();

        // Notify client
        $this->createNotification(
            $this->clientUserIdFromProfile($clientProfileId),
            'Session scheduled',
            'A new session has been scheduled for ' . $this->formatDatetime($data['session_datetime']) . '.'
        );

        return [
            'status' => 201,
            'body'   => ['success' => true, 'message' => 'Session created', 'data' => ['id' => $sessionId]],
        ];
    }

    // ----------------------------------------------------------------
    // POST /sessions/request  (client requests)
    // requested_by = client, status = pending.
    // ----------------------------------------------------------------
    public function clientRequest(int $clientUserId, array $data): array
    {
        $clientProfileId = $this->profileIdFromUserId($clientUserId);
        if (!$clientProfileId) {
            return ['status' => 404, 'body' => ['success' => false, 'message' => 'Client profile not found']];
        }

        $coachId = $this->primaryCoachId($clientProfileId);
        if (!$coachId) {
            return ['status' => 404, 'body' => ['success' => false, 'message' => 'No assigned coach found']];
        }

        // Inject client_profile_id for shared validation
        $data['client_profile_id'] = $clientProfileId;
        $validation = $this->validateSessionData($data);
        if ($validation !== null) {
            return ['status' => 400, 'body' => ['success' => false, 'message' => 'Validation failed', 'data' => $validation]];
        }

        $stmt = $this->db->prepare("
            INSERT INTO sessions
                (client_profile_id, coach_id, requested_by, session_datetime,
                 duration_minutes, type, location, meeting_link, status)
            VALUES (?, ?, 'client', ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([
            $clientProfileId,
            $coachId,
            $data['session_datetime'],
            (int) $data['duration_minutes'],
            $data['type'],
            $data['type'] === 'physical' ? ($data['location']     ?? null) : null,
            $data['type'] === 'online'   ? ($data['meeting_link'] ?? null) : null,
        ]);
        $sessionId = (int) $this->db->lastInsertId();

        // Notify coach
        $coachUser = $this->userById($coachId);
        $this->createNotification(
            $coachId,
            'New session request',
            ($coachUser ? $this->clientNameFromProfile($clientProfileId) : 'A client') .
            ' has requested a session on ' . $this->formatDatetime($data['session_datetime']) . '.'
        );

        return [
            'status' => 201,
            'body'   => [
                'success' => true,
                'message' => 'Session request submitted. Awaiting coach approval.',
                'data'    => ['id' => $sessionId],
            ],
        ];
    }

    // ----------------------------------------------------------------
    // PATCH /sessions/{id}/approve
    // ----------------------------------------------------------------
    public function approve(int $coachId, int $sessionId): array
    {
        return $this->transition($coachId, $sessionId, 'approved', function (array $session) {
            $this->createNotification(
                $this->clientUserIdFromProfile($session['client_profile_id']),
                'Session confirmed',
                'Your session on ' . $this->formatDatetime($session['session_datetime']) . ' has been confirmed.'
            );
        });
    }

    // ----------------------------------------------------------------
    // PATCH /sessions/{id}/reject
    // ----------------------------------------------------------------
    public function reject(int $coachId, int $sessionId): array
    {
        return $this->transition($coachId, $sessionId, 'rejected', function (array $session) {
            $this->createNotification(
                $this->clientUserIdFromProfile($session['client_profile_id']),
                'Session request declined',
                'Your session request for ' . $this->formatDatetime($session['session_datetime']) . ' was not approved.'
            );
        });
    }

    // ----------------------------------------------------------------
    // PATCH /sessions/{id}/complete
    // Session must be approved first.
    // ----------------------------------------------------------------
    public function complete(int $coachId, int $sessionId): array
    {
        return $this->transition($coachId, $sessionId, 'completed', function (array $session) {
            $this->createNotification(
                $this->clientUserIdFromProfile($session['client_profile_id']),
                'Session completed',
                'Your session on ' . $this->formatDatetime($session['session_datetime']) . ' has been marked as completed.'
            );
        });
    }

    // ----------------------------------------------------------------
    // PATCH /sessions/{id}/cancel
    // Allowed from pending or approved only.
    // ----------------------------------------------------------------
    public function cancel(int $actorId, int $sessionId): array
    {
        return $this->transition($actorId, $sessionId, 'cancelled', function (array $session) use ($actorId) {
            // Notify the other party
            $actorIsCoach = ($actorId === $session['coach_id']);

            if ($actorIsCoach) {
                $this->createNotification(
                    $this->clientUserIdFromProfile($session['client_profile_id']),
                    'Session cancelled',
                    'Your session on ' . $this->formatDatetime($session['session_datetime']) . ' has been cancelled.'
                );
            } else {
                $this->createNotification(
                    $session['coach_id'],
                    'Session cancelled',
                    'A session on ' . $this->formatDatetime($session['session_datetime']) . ' has been cancelled by the client.'
                );
            }
        });
    }

    // ----------------------------------------------------------------
    // Private: central transition handler
    // Validates ownership, checks state machine, updates status, fires callback.
    // ----------------------------------------------------------------
    private function transition(int $actorId, int $sessionId, string $targetStatus, callable $onSuccess): array
    {
        $session = $this->fetchSession($sessionId);

        if (!$session) {
            return ['status' => 404, 'body' => ['success' => false, 'message' => 'Session not found']];
        }

        // Both coaches and clients may cancel; all other transitions are coach-only
        if ($targetStatus !== 'cancelled' && !$this->coachOwnsSession($actorId, $sessionId)) {
            return ['status' => 403, 'body' => ['success' => false, 'message' => 'Access denied']];
        }

        if (!$this->isValidTransition($session['status'], $targetStatus)) {
            return [
                'status' => 409,
                'body'   => [
                    'success' => false,
                    'message' => "Cannot transition from '{$session['status']}' to '{$targetStatus}'",
                ],
            ];
        }

        $stmt = $this->db->prepare('UPDATE sessions SET status = ? WHERE id = ?');
        $stmt->execute([$targetStatus, $sessionId]);

        $onSuccess($session);

        return ['status' => 200, 'body' => ['success' => true, 'message' => 'Session ' . $targetStatus]];
    }

    // ----------------------------------------------------------------
    // Private: state machine check
    // ----------------------------------------------------------------
    private function isValidTransition(string $current, string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$current] ?? [], true);
    }

    // ----------------------------------------------------------------
    // Private: shared input validation for coachCreate and clientRequest
    // ----------------------------------------------------------------
    private function validateSessionData(array $data): ?array
    {
        $errors = [];

        if (empty($data['session_datetime'])) {
            $errors['session_datetime'] = 'Session datetime is required';
        } elseif (!$this->isValidDatetime($data['session_datetime'])) {
            $errors['session_datetime'] = 'Invalid datetime format. Use YYYY-MM-DD HH:MM:SS';
        }

        if (empty($data['duration_minutes']) || (int) $data['duration_minutes'] <= 0) {
            $errors['duration_minutes'] = 'Duration must be a positive number of minutes';
        }

        if (empty($data['type']) || !in_array($data['type'], ['online', 'physical'], true)) {
            $errors['type'] = 'Type must be online or physical';
        } elseif ($data['type'] === 'online' && empty($data['meeting_link'])) {
            $errors['meeting_link'] = 'Meeting link is required for online sessions';
        } elseif ($data['type'] === 'physical' && empty($data['location'])) {
            $errors['location'] = 'Location is required for physical sessions';
        }

        return empty($errors) ? null : $errors;
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

    private function coachOwnsClient(int $coachId, int $clientProfileId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM coach_assignments WHERE coach_id = ? AND client_profile_id = ?'
        );
        $stmt->execute([$coachId, $clientProfileId]);
        return (bool) $stmt->fetch();
    }

    private function fetchSession(int $sessionId): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT id, client_profile_id, coach_id, session_datetime, status FROM sessions WHERE id = ?'
        );
        $stmt->execute([$sessionId]);
        return $stmt->fetch();
    }

    private function profileIdFromUserId(int $userId): int|false
    {
        $stmt = $this->db->prepare('SELECT id FROM client_profiles WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ? (int) $row['id'] : false;
    }

    private function primaryCoachId(int $clientProfileId): int|false
    {
        $stmt = $this->db->prepare(
            'SELECT coach_id FROM coach_assignments WHERE client_profile_id = ? AND is_primary = 1'
        );
        $stmt->execute([$clientProfileId]);
        $row = $stmt->fetch();
        return $row ? (int) $row['coach_id'] : false;
    }

    private function clientUserIdFromProfile(int $clientProfileId): int
    {
        $stmt = $this->db->prepare('SELECT user_id FROM client_profiles WHERE id = ?');
        $stmt->execute([$clientProfileId]);
        return (int) $stmt->fetch()['user_id'];
    }

    private function clientNameFromProfile(int $clientProfileId): string
    {
        $stmt = $this->db->prepare("
            SELECT u.name FROM users u
            JOIN client_profiles cp ON cp.user_id = u.id
            WHERE cp.id = ?
        ");
        $stmt->execute([$clientProfileId]);
        $row = $stmt->fetch();
        return $row ? $row['name'] : 'A client';
    }

    private function userById(int $userId): array|false
    {
        $stmt = $this->db->prepare('SELECT id, name FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }

    private function createNotification(int $userId, string $title, string $message): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)'
        );
        $stmt->execute([$userId, $title, $message]);
    }

    private function formatDatetime(string $datetime): string
    {
        $d = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
        return $d ? $d->format('M j, Y \a\t g:i A') : $datetime;
    }

    private function isValidDatetime(string $dt): bool
    {
        $d = DateTime::createFromFormat('Y-m-d H:i:s', $dt);
        return $d !== false && $d->format('Y-m-d H:i:s') === $dt;
    }
}
