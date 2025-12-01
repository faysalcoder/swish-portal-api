<?php
namespace App\Models;

use PDO;
use PDOException;

class Wing extends BaseModel
{
    protected string $table = 'wings';
    protected array $fillable = [
        'name',
        'icon',
        'created_at',
        'updated_at'
    ];

    /**
     * Convenience wrapper: delete by id and allow additional cleanup if needed.
     *
     * Returns true if deleted, false otherwise.
     * If there are foreign key constraints, this will throw a PDOException which the controller can catch.
     */
    public function deleteById(int $id): bool
    {
        try {
            return $this->delete($id);
        } catch (PDOException $e) {
            // Re-throw so controllers can detect constraint errors and return proper HTTP status.
            throw $e;
        }
    }

    /**
     * (Optional) Example helper to check for child records before deleting.
     * Returns an associative array with counts of related rows.
     */
    public function relatedCounts(int $id): array
    {
        $counts = [
            'subwings' => 0,
            'users' => 0,
        ];

        // check sub_wings table if exists
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) AS cnt FROM `sub_wings` WHERE `wing_id` = :id");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $counts['subwings'] = (int)($row['cnt'] ?? 0);
        } catch (PDOException $e) {
            $counts['subwings'] = 0;
        }

        // check users table
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) AS cnt FROM `users` WHERE `wing_id` = :id");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $counts['users'] = (int)($row['cnt'] ?? 0);
        } catch (PDOException $e) {
            $counts['users'] = 0;
        }

        return $counts;
    }
}
