<?php
namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\User;

class AuthService
{
    protected string $jwtSecret;
    protected string $jwtAlg;
    protected int $defaultTtl;

    public function __construct()
    {
        $this->jwtSecret = $_ENV['JWT_SECRET'] ?? ($_ENV['JWT_KEY'] ?? '');
        $this->jwtAlg = $_ENV['JWT_ALG'] ?? 'HS256';
        $this->defaultTtl = (int)($_ENV['JWT_TTL'] ?? 3600 * 24); // default 24 hours
    }

    /**
     * Register a user. Returns created user array (without password).
     */
    public function register(array $data): array
    {
        $userModel = new User();

        if (empty($data['email']) || empty($data['password']) || empty($data['name'])) {
            throw new \InvalidArgumentException('name, email and password are required');
        }

        // check uniqueness
        if ($userModel->findByEmail($data['email'])) {
            throw new \RuntimeException('Email already registered');
        }

        $data['password'] = password_hash($data['password'], PASSWORD_BCRYPT);
        $id = $userModel->create($data);
        $user = $userModel->find($id);
        if ($user && isset($user['password'])) unset($user['password']);
        return $user;
    }

    /**
     * Attempt login. Returns array: ['token' => ..., 'user' => ...]
     */
    public function login(string $email, string $password, int $ttl = null): array
    {
        $userModel = new User();
        $user = $userModel->findByEmail($email);
        if (!$user) {
            throw new \RuntimeException('Invalid credentials');
        }
        if (!password_verify($password, $user['password'])) {
            throw new \RuntimeException('Invalid credentials');
        }
        if ($user['status'] !== 'active') {
            throw new \RuntimeException('User not active');
        }

        // optionally update last login here (controller may do it)
        $token = $this->generateTokenForUser($user, $ttl ?? $this->defaultTtl);
        if (isset($user['password'])) unset($user['password']);
        return ['token' => $token, 'user' => $user];
    }

    /**
     * Generate JWT string for a user array (must contain id and email).
     */
    public function generateTokenForUser(array $user, int $ttlSeconds): string
    {
        if (!$this->jwtSecret) {
            throw new \RuntimeException('JWT secret not configured');
        }

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
     * Verify token and return decoded payload (stdClass) or throw on failure.
     */
    public function verifyToken(string $token)
    {
        if (!$this->jwtSecret) throw new \RuntimeException('JWT secret not configured');
        try {
            $decoded = JWT::decode($token, new Key($this->jwtSecret, $this->jwtAlg));
            return $decoded;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Invalid token: ' . $e->getMessage());
        }
    }

    /**
     * Return user array from a token, or null if not found/invalid.
     */
    public function getUserFromToken(string $token): ?array
    {
        try {
            $decoded = $this->verifyToken($token);
            $sub = $decoded->sub ?? null;
            if (!$sub) return null;
            $userModel = new User();
            $user = $userModel->find((int)$sub);
            if (!$user) return null;
            if (isset($user['password'])) unset($user['password']);
            return $user;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Helper to extract raw token from Authorization header value or string.
     */
    public static function extractTokenFromHeader(?string $header): ?string
    {
        if (!$header) return null;
        if (preg_match('/Bearer\s+(.*)$/i', $header, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
