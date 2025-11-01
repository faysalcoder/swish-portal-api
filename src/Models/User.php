<?php
namespace App\Models;

class User extends BaseModel
{
    protected string $table = 'users';
    protected array $fillable = [
        'wing_id',
        'subw_id',
        'name',
        'email',
        'phone',
        'employee_id',
        'location_id',
        'designation',
        'profile_img',
        'role',
        'password',
        'status',
        'created_at',
        'updated_at',
        'last_login'
    ];

    /**
     * Find user by ID
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    /**
     * Find user by email
     */
    public function findByEmail(string $email): ?array
    {
        $rows = $this->where(['email' => $email], 1, 0);
        return $rows[0] ?? null;
    }

    /**
     * Update user password
     */
    public function updatePassword(int $userId, string $hashedPassword): bool
    {
        $stmt = $this->db->prepare("UPDATE {$this->table} SET password = :password WHERE id = :id");
        return $stmt->execute([
            ':password' => $hashedPassword,
            ':id' => $userId
        ]);
    }
}
