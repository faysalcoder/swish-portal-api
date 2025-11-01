<?php
namespace App\Controllers;

use Dotenv\Dotenv;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\User;

class BaseController
{
    use JsonResponseTrait;

    protected string $jwtSecret;
    protected string $jwtAlg = 'HS256';

    // Keep this nullable so child classes don't redeclare with incompatible types
    protected ?User $userModel = null;

    public function __construct()
    {
        // load .env (safe to call multiple times)
        $root = __DIR__ . '/../../';
        if (file_exists($root . '.env')) {
            Dotenv::createImmutable($root)->load();
        }
        $this->jwtSecret = $_ENV['JWT_SECRET'] ?? ($_ENV['JWT_KEY'] ?? '');
        $this->userModel = new User();
    }

    /**
     * Returns user array or null. Does NOT exit on failure.
     */
    protected function getAuthenticatedUser(): ?array
    {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
        if (!$hdr) return null;
        if (preg_match('/Bearer\s+(.*)$/i', $hdr, $matches)) {
            $token = trim($matches[1]);
        } else {
            return null;
        }
        if (!$token || !$this->jwtSecret) return null;
        try {
            $decoded = JWT::decode($token, new Key($this->jwtSecret, $this->jwtAlg));
            $sub = $decoded->sub ?? null;
            if (!$sub) return null;
            $user = $this->userModel->find((int)$sub);
            return $user ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function requireAuth(): array
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            $this->error('Unauthorized', 401);
        }
        return $user;
    }

    protected function generateJwtForUser(array $user, int $ttlSeconds = 3600): string
    {
        $now = time();
        $payload = [
            'iss' => $_ENV['JWT_ISSUER'] ?? ($_ENV['APP_URL'] ?? 'http://localhost'),
            'aud' => $_ENV['JWT_AUD'] ?? ($_ENV['APP_URL'] ?? 'http://localhost'),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttlSeconds,
            'sub' => (int)$user['id'],
            'email' => $user['email'] ?? null,
        ];
        return JWT::encode($payload, $this->jwtSecret, $this->jwtAlg);
    }

    /**
     * Simple helper to get JSON request body as array
     */
    protected function jsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
