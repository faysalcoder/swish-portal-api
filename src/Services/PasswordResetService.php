<?php
namespace App\Services;

use App\Models\User;
use App\Models\UserRecoveryToken;
use App\Database\Connection;
use PDO;
use Throwable;

class PasswordResetService
{
    protected User $userModel;
    protected UserRecoveryToken $tokenModel;
    protected string $secret;
    protected int $ttlSeconds;

    public function __construct()
    {
        $this->userModel = new User();
        $this->tokenModel = new UserRecoveryToken();
        $this->secret = (string) ($_ENV['JWT_SECRET'] ?? '');
        $this->ttlSeconds = (int) ($_ENV['PASSWORD_RESET_TTL'] ?? 3600);
        if ($this->ttlSeconds <= 0) $this->ttlSeconds = 3600;
    }

    public function createResetTokenForEmail(string $email): string
    {
        $user = $this->userModel->findByEmail($email);
        if (!$user) throw new \RuntimeException('User not found');

        if ($this->secret === '') throw new \RuntimeException('Server secret not configured');

        $raw = bin2hex(random_bytes(32));
        $hash = hash_hmac('sha256', $raw, $this->secret);
        $validity = date('Y-m-d H:i:s', time() + $this->ttlSeconds);

        $this->tokenModel->createTokenHash((int)$user['id'], $hash, $validity);

        return $raw;
    }

    public function validateToken(string $rawToken): ?array
    {
        if ($rawToken === '' || $this->secret === '') return null;
        return $this->tokenModel->findValidByRawToken($rawToken, $this->secret);
    }

    public function resetPasswordWithToken(string $rawToken, string $newPassword): bool
    {
        $pdo = Connection::get();
        if (!$pdo instanceof PDO) throw new \RuntimeException('DB connection not available');

        $tokenRow = $this->validateToken($rawToken);
        if (!$tokenRow) throw new \RuntimeException('Invalid or expired token');

        $userId = (int)$tokenRow['user_id'];
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);

        try {
            $pdo->beginTransaction();

            $ok = $this->userModel->update($userId, ['password' => $hashedPassword]);
            if (!$ok) {
                $pdo->rollBack();
                throw new \RuntimeException('Failed to update password');
            }

            $this->tokenModel->markUsed((int)$tokenRow['id']);

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function createResetUrlForEmail(string $email, string $resetPath = '/reset_password.php'): array
    {
        $raw = $this->createResetTokenForEmail($email);
        $appUrl = rtrim($_ENV['APP_URL'] ?? 'http://localhost', '/');
        $resetUrl = $appUrl . $resetPath . '?token=' . rawurlencode($raw);
        return ['raw_token' => $raw, 'reset_url' => $resetUrl];
    }
}
