<?php
declare(strict_types=1);

namespace App\Models;

class Notice extends BaseModel
{
    protected string $table = 'notices';

    protected array $fillable = [
        'title',
        'notice_type',
        'notice_note',
        'file_url',
        'timestamp',
        'valid_till',
        'user_id',
        'created_at',
        'updated_at'
    ];

    /**
     * Create notice wrapper — returns inserted id (int)
     */
    public function createNotice(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $data['timestamp']  = $data['timestamp']  ?? $now;
        $data['created_at'] = $data['created_at'] ?? $now;
        return (int)$this->create($data);
    }

    /**
     * Update notice wrapper — returns bool
     */
    public function updateNotice(int $id, array $data): bool
    {
        $data['updated_at'] = $data['updated_at'] ?? date('Y-m-d H:i:s');
        return $this->update($id, $data);
    }

    /**
     * Return ALL notices (including expired)
     * No pagination — frontend will handle filtering/paging.
     */
    public function getAllActive(): array
    {
        try {
            $sql = "SELECT * FROM `{$this->table}` 
                    ORDER BY `created_at` DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute();

            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return $rows ?: [];
        } catch (\Throwable $e) {
            error_log('Notice::getAllActive error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get notices by user id (no pagination)
     */
    public function getByUserId(int $userId): array
    {
        try {
            $sql = "SELECT * FROM `{$this->table}` WHERE `user_id` = :uid ORDER BY `created_at` DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':uid', $userId, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return $rows ?: [];
        } catch (\Throwable $e) {
            error_log('Notice::getByUserId error: ' . $e->getMessage());
            return [];
        }
    }
}
