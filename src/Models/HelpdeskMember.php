<?php
namespace App\Models;

use PDO;
use PDOException;

class HelpdeskMember extends BaseModel
{
    // table name
    protected string $table = 'helpdesk_members';

    // allowed fillable columns
    protected array $fillable = [
        'user_id',
        'category',
        'created_at',
        'updated_at'
    ];

    /**
     * Find a row by user_id
     *
     * @param int $userId
     * @return array|null
     */
    public function findByUserId(int $userId): ?array
    {
        $sql = "SELECT * FROM `{$this->table}` WHERE `user_id` = :user_id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * List by category (optional)
     *
     * @param string $category
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function listByCategory(string $category, int $limit = 100, int $offset = 0): array
    {
        $sql = "SELECT * FROM `{$this->table}` WHERE `category` = :category LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':category', $category);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
