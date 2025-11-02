<?php
namespace App\Models;

class Notice extends BaseModel
{
    // Use plural 'notices' if your DB table is named 'notices' (controller used 'notices')
    protected string $table = 'notices';

    protected array $fillable = [
        'title',
        'notice_type',   // 'file' or 'url' (as you defined earlier)
        'notice_note',
        'timestamp',     // created timestamp
        'valid_till',
        'user_id',
        'created_at',
        'updated_at'
    ];

    /**
     * Convenience wrapper for create
     */
    public function createNotice(array $data): int
    {
        // ensure timestamps if not provided
        if (empty($data['timestamp'])) $data['timestamp'] = date('Y-m-d H:i:s');
        if (empty($data['created_at'])) $data['created_at'] = date('Y-m-d H:i:s');
        return $this->create($data);
    }

    public function updateNotice(int $id, array $data): bool
    {
        if (!isset($data['updated_at'])) $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->update($id, $data);
    }

    public function getActive(int $limit = 100, int $offset = 0): array
    {
        // returns notices where valid_till is null OR valid_till >= now
        $now = date('Y-m-d H:i:s');
        $sql = "SELECT * FROM `{$this->table}` WHERE `valid_till` IS NULL OR `valid_till` >= :now ORDER BY `created_at` DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':now', $now);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
