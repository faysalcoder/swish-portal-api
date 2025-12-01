<?php
namespace App\Models;

use App\Models\BaseModel;

class RaciRole extends BaseModel
{
    protected string $table = 'raci_roles';
    protected array $fillable = ['raci_id', 'role', 'user_id', 'created_at'];

    /**
     * Get all roles for a given RACI ID.
     */
    public function byRaci(int $raciId): array
    {
        return $this->where(['raci_id' => $raciId], 500, 0, ['id' => 'ASC']);
    }

    /**
     * Create with duplicate protection.
     * Duplicate = same raci_id + role + user_id
     */
    public function createUnique(array $data): int
    {
        $raci_id = (int)$data['raci_id'];
        $role = strtoupper(substr(trim((string)$data['role']), 0, 1));
        $user_id = (int)$data['user_id'];

        // Check existing
        $existing = $this->where([
            'raci_id' => $raci_id,
            'role' => $role,
            'user_id' => $user_id
        ], 1, 0);

        if (!empty($existing)) {
            return (int)$existing[0]['id']; // return existing id
        }

        // Insert new
        return parent::create([
            'raci_id' => $raci_id,
            'role' => $role,
            'user_id' => $user_id,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
}
