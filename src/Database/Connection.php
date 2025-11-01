<?php
// src/Database/Connection.php
declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOException;

class Connection
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        // Attempt to read from environment
        $host = $_ENV['DB_HOST'] ?? ($_ENV['DB_HOST'] ?? '127.0.0.1');
        $port = $_ENV['DB_PORT'] ?? '3306';
        $db   = $_ENV['DB_NAME'] ?? '';
        $user = $_ENV['DB_USER'] ?? 'root';
        $pass = $_ENV['DB_PASS'] ?? '';
        $charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";

        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$instance = $pdo;
            return $pdo;
        } catch (PDOException $e) {
            // Provide a clear error in development; in production, avoid leaking credentials
            $message = 'Database connection failed';
            if (($_ENV['APP_ENV'] ?? 'production') !== 'production') {
                $message .= ': ' . $e->getMessage();
            }
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
    }
}
