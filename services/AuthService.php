<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/jwt.php';

class AuthService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function register(array $data): array
    {
        $name  = trim($data['name']  ?? '');
        $email = trim($data['email'] ?? '');
        $pass  = $data['password']   ?? '';
        $role  = $data['role']       ?? '';

        $errors = [];
        if ($name === '')                                    $errors['name']     = 'Name is required';
        if (strlen($name) > 100)                            $errors['name']     = 'Name must be 100 characters or fewer';
        if ($email === '')                                   $errors['email']    = 'Email is required';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email']    = 'Invalid email format';
        if ($pass === '')                                    $errors['password'] = 'Password is required';
        elseif (strlen($pass) < 8)                          $errors['password'] = 'Minimum 8 characters required';
        if (!in_array($role, ['admin', 'coach', 'client'])) $errors['role']     = 'Role must be admin, coach, or client';

        if (!empty($errors)) {
            return ['status' => 400, 'body' => ['success' => false, 'message' => 'Validation failed', 'data' => $errors]];
        }

        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            return ['status' => 409, 'body' => ['success' => false, 'message' => 'An account with this email already exists']];
        }

        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $stmt = $this->db->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, $email, $hash, $role]);
        $id = (int) $this->db->lastInsertId();

        return [
            'status' => 201,
            'body'   => [
                'success' => true,
                'message' => 'Account created successfully',
                'data'    => ['id' => $id, 'name' => $name, 'email' => $email, 'role' => $role],
            ],
        ];
    }

    public function login(array $data): array
    {
        $email = trim($data['email'] ?? '');
        $pass  = $data['password']   ?? '';

        if ($email === '' || $pass === '') {
            return ['status' => 400, 'body' => ['success' => false, 'message' => 'Email and password are required']];
        }

        $stmt = $this->db->prepare('SELECT id, name, email, password, role FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($pass, $user['password'])) {
            return ['status' => 401, 'body' => ['success' => false, 'message' => 'Invalid email or password']];
        }

        $token = jwtEncode(['user_id' => $user['id'], 'role' => $user['role']]);

        unset($user['password']);

        return [
            'status' => 200,
            'body'   => ['success' => true, 'message' => 'Login successful', 'data' => $user, 'token' => $token],
        ];
    }

    public function logout(): array
    {
        // JWT is stateless - client just discards the token
        return ['status' => 200, 'body' => ['success' => true, 'message' => 'Logged out successfully']];
    }

    public function me(): array
    {
        $token = getBearerToken();
        if (!$token) {
            return ['status' => 401, 'body' => ['success' => false, 'message' => 'Not authenticated']];
        }

        $payload = jwtDecode($token);
        if (!$payload || empty($payload['user_id'])) {
            return ['status' => 401, 'body' => ['success' => false, 'message' => 'Not authenticated']];
        }

        $stmt = $this->db->prepare('SELECT id, name, email, role FROM users WHERE id = ?');
        $stmt->execute([$payload['user_id']]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['status' => 401, 'body' => ['success' => false, 'message' => 'Not authenticated']];
        }

        return ['status' => 200, 'body' => ['success' => true, 'data' => $user]];
    }
}
