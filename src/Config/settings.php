<?php
// src/Config/settings.php
declare(strict_types=1);

use Dotenv\Dotenv;

$root = __DIR__ . '/../../';

// Load .env into $_ENV / getenv
if (file_exists($root . '.env')) {
    $dotenv = Dotenv::createImmutable($root);
    $dotenv->safeLoad();
}

// Basic settings with sensible defaults
$config = [
    'app_env' => $_ENV['APP_ENV'] ?? 'production',
    'app_url' => $_ENV['APP_URL'] ?? 'http://localhost',
    'db' => [
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port' => (int)($_ENV['DB_PORT'] ?? 3306),
        'name' => $_ENV['DB_NAME'] ?? '',
        'user' => $_ENV['DB_USER'] ?? 'root',
        'pass' => $_ENV['DB_PASS'] ?? '',
        'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
    ],
    'jwt' => [
        'secret' => $_ENV['JWT_SECRET'] ?? '',
        'issuer' => $_ENV['JWT_ISSUER'] ?? ($_ENV['APP_URL'] ?? 'http://localhost'),
        'aud' => $_ENV['JWT_AUD'] ?? ($_ENV['APP_URL'] ?? 'http://localhost'),
        'alg' => $_ENV['JWT_ALG'] ?? 'HS256',
    ],
    'upload_dir' => $_ENV['UPLOAD_DIR'] ?? $root . 'storage/uploads',
    'max_upload_size' => (int)($_ENV['MAX_UPLOAD_SIZE'] ?? 10 * 1024 * 1024),
];

// ensure upload dir exists and is absolute path
$upload = $config['upload_dir'];
if (!is_dir($upload)) {
    @mkdir($upload, 0755, true);
}
$config['upload_dir'] = realpath($upload) ?: $upload;

return $config;
