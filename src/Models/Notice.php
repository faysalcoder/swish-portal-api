<?php
namespace App\Models;

class Notice extends BaseModel
{
    // Use the real table name in your database. I set 'notices' to match your controller SQL.
    protected string $table = 'notices';

    protected array $fillable = [
        'title',
        'notice_type',
        'notice_note',
        'timestamp',
        'valid_till',
        'user_id',
        'created_at',
        'updated_at'
    ];

    /**
     * Find notice by ID
     */
    public function findById(int $id): ?array
    {
        return $this->find($id);
    }

    /**
     * Get notices with optional conditions (default: latest first)
     */
    public function getAll(array $conditions = [], int $limit = 100, int $offset = 0): array
    {
        return $this->where($conditions, $limit, $offset, ['timestamp' => 'DESC']);
    }

    /**
     * Create a new notice
     */
    public function createNotice(array $data): int
    {
        // ensure timestamp if not provided
        if (empty($data['timestamp'])) $data['timestamp'] = date('Y-m-d H:i:s');
        return $this->create($data);
    }

    /**
     * Update an existing notice
     */
    public function updateNotice(int $id, array $data): bool
    {
        return $this->update($id, $data);
    }

    /**
     * Delete a notice
     */
    public function deleteNotice(int $id): bool
    {
        return $this->delete($id);
    }
}
