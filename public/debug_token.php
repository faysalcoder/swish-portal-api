<?php
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

if (php_sapi_name() !== 'cli') header('Content-Type: text/plain; charset=utf-8');

$raw = $_GET['token'] ?? '';
if (!$raw) {
    echo "Usage: debug_token.php?token=RAW_TOKEN\n";
    exit;
}

$secret = $_ENV['JWT_SECRET'] ?? '';
echo "Raw token: $raw\n";
echo "JWT_SECRET set? " . (!empty($secret) ? 'YES' : 'NO') . "\n";
echo "Computed hash (hmac-sha256): " . hash_hmac('sha256', $raw, $secret) . "\n\n";

// load models (adjust if your autoload + classes differ)
require_once __DIR__ . '/../src/Database/Connection.php';
require_once __DIR__ . '/../src/Models/BaseModel.php';
require_once __DIR__ . '/../src/Models/UserRecoveryToken.php';

$tm = new \App\Models\UserRecoveryToken();
$row = $tm->findValidByRawToken($raw, $secret);

echo "DB lookup result: \n";
var_export($row);
echo "\n";
