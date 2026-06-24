<?php
class Database
{
    private static ?PDO $connection = null;
    private function __construct() {}
    private function __clone() {}
    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            $host    = 'mysql.railway.internal';
            $dbname  = 'railway';
            $user    = 'root';
            $pass    = 'pvozuTUqFMRwThRhfCAmvpaTfCVWFULb';
            $port    = '3306';
            $charset = 'utf8mb4';
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            try {
                self::$connection = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                error_log('Database connection failed: ' . $e->getMessage());
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Database connection failed.',
                ]);
                exit;
            }
        }
        return self::$connection;
    }
}
