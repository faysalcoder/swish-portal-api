<?php
namespace App\Models;

use PDO;

class UserRecoveryToken extends BaseModel
{
    protected string $table = 'user_recovery_tokens';
    protected array $fillable = ['user_id', 'token_hash', 'status', 'validity', 'created_at'];
    protected string $primaryKey = 'id';

    public function createTokenHash(int $userId, string $tokenHash, string $validity): int
    {
        return $this->create([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'status' => 'unused',
            'validity' => $validity,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function findValidByRawToken(string $rawToken, string $secret): ?array
    {
        $hash = hash_hmac('sha256', $rawToken, $secret);
        $rows = $this->where([
            'token_hash' => $hash,
            'status' => 'unused'
        ], 1);

        if (empty($rows)) return null;
        $row = $rows[0];

        if (!empty($row['validity']) && strtotime($row['validity']) < time()) {
            return null;
        }
        return $row;
    }

    public function markUsed(int $id): bool
    {
        return $this->update($id, ['status' => 'used']);
    }
}
