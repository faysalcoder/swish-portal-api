<?php
namespace App\Models;

use PDO;

class HelpdeskTicket extends BaseModel
{
    protected string $table = 'helpdesk_tickets';
    protected array $fillable = [
        'title',
        'user_id',
        'details',
        'doc',
        'request_category',
        'assigned_by',
        'assigned_to',
        'status',
        'priority',
        'request_time',
        'last_update_time',
        'resolve_time',
        'created_at',
        'updated_at',
        'deleted_at',
        'trashed_at'
    ];

    /**
     * Search title/details (simple text search) and return data + total
     * @return array ['data' => [...], 'total' => int]
     */
    public function searchWithCount(string $q, int $limit = 50, int $offset = 0, bool $showTrashed = false): array
    {
        $like = '%' . $q . '%';

        // Data query
        $sql = "SELECT ht.*,
                    u1.name as user_name,
                    u1.email as user_email,
                    u1.designation as user_designation,
                    u2.name as assigned_by_name,
                    u2.email as assigned_by_email,
                    u3.name as assigned_to_name,
                    u3.email as assigned_to_email
                FROM `{$this->table}` ht
                LEFT JOIN users u1 ON ht.user_id = u1.id
                LEFT JOIN users u2 ON ht.assigned_by = u2.id
                LEFT JOIN users u3 ON ht.assigned_to = u3.id
                WHERE (ht.`title` LIKE :q OR ht.`details` LIKE :q)";

        if (!$showTrashed) {
            $sql .= " AND ht.trashed_at IS NULL AND ht.deleted_at IS NULL";
        }

        $sql .= " ORDER BY ht.request_time DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':q', $like, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Count
        $countSql = "SELECT COUNT(*) AS cnt FROM `{$this->table}` ht WHERE (ht.`title` LIKE :q OR ht.`details` LIKE :q)";
        if (!$showTrashed) {
            $countSql .= " AND ht.trashed_at IS NULL AND ht.deleted_at IS NULL";
        }
        $cstmt = $this->db->prepare($countSql);
        $cstmt->bindValue(':q', $like, PDO::PARAM_STR);
        $cstmt->execute();
        $total = (int)$cstmt->fetch(PDO::FETCH_ASSOC)['cnt'];

        return ['data' => $data, 'total' => $total];
    }

    /**
     * Fetch rows with filters and joins. Returns array of rows.
     * Filters is associative: ['assigned_to' => 123, 'status' => 'open', ...]
     */
    public function fetchWithFilters(array $filters = [], int $limit = 100, int $offset = 0, bool $showTrashed = false): array
    {
        $whereParts = [];
        $bindings = [];

        if (!$showTrashed) {
            $whereParts[] = "ht.trashed_at IS NULL AND ht.deleted_at IS NULL";
        }

        foreach ($filters as $k => $v) {
            // Allowed fields only (safety)
            if (!in_array($k, ['assigned_to', 'assigned_by', 'status', 'priority', 'user_id'])) {
                continue;
            }
            if ($v === null) {
                $whereParts[] = "ht.`{$k}` IS NULL";
            } else {
                $param = ':filter_' . $k;
                $whereParts[] = "ht.`{$k}` = {$param}";
                $bindings[$param] = $v;
            }
        }

        $whereSql = count($whereParts) ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

        $sql = "SELECT ht.*,
                    u1.name as user_name,
                    u1.email as user_email,
                    u1.designation as user_designation,
                    u2.name as assigned_by_name,
                    u2.email as assigned_by_email,
                    u3.name as assigned_to_name,
                    u3.email as assigned_to_email
                FROM `{$this->table}` ht
                LEFT JOIN users u1 ON ht.user_id = u1.id
                LEFT JOIN users u2 ON ht.assigned_by = u2.id
                LEFT JOIN users u3 ON ht.assigned_to = u3.id
                {$whereSql}
                ORDER BY ht.request_time DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);

        // bind filters
        foreach ($bindings as $param => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($param, $value, $type);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * Count rows for a given filters set
     */
    public function countWithFilters(array $filters = [], bool $showTrashed = false): int
    {
        $whereParts = [];
        $bindings = [];

        if (!$showTrashed) {
            $whereParts[] = "ht.trashed_at IS NULL AND ht.deleted_at IS NULL";
        }

        foreach ($filters as $k => $v) {
            if (!in_array($k, ['assigned_to', 'assigned_by', 'status', 'priority', 'user_id'])) {
                continue;
            }
            if ($v === null) {
                $whereParts[] = "ht.`{$k}` IS NULL";
            } else {
                $param = ':filter_' . $k;
                $whereParts[] = "ht.`{$k}` = {$param}";
                $bindings[$param] = $v;
            }
        }

        $whereSql = count($whereParts) ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

        $countSql = "SELECT COUNT(*) AS cnt FROM `{$this->table}` ht {$whereSql}";

        $stmt = $this->db->prepare($countSql);
        foreach ($bindings as $param => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($param, $value, $type);
        }
        $stmt->execute();
        $total = (int)$stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        return $total;
    }

    /**
     * Move ticket to trash (soft trashed_at)
     */
    public function moveToTrash(int $id): bool
    {
        $data = [
            'trashed_at' => date('Y-m-d H:i:s'),
            'last_update_time' => date('Y-m-d H:i:s'),
            'status' => 'trash'
        ];
        return $this->update($id, $data);
    }

    /**
     * Restore ticket from trash
     */
    public function restoreFromTrash(int $id): bool
    {
        $data = [
            'trashed_at' => null,
            'last_update_time' => date('Y-m-d H:i:s'),
            'status' => 'open'
        ];
        return $this->update($id, $data);
    }

    /**
     * Permanently delete trashed tickets older than $days
     */
    public function purgeTrashedOlderThanDays(int $days = 30): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $sqlSelect = "SELECT id, doc FROM `{$this->table}` WHERE trashed_at IS NOT NULL AND trashed_at < :cutoff";
        $stmt = $this->db->prepare($sqlSelect);
        $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->db->beginTransaction();
        $deleted = 0;
        try {
            $sqlDelete = "DELETE FROM `{$this->table}` WHERE trashed_at IS NOT NULL AND trashed_at < :cutoff";
            $stmtDel = $this->db->prepare($sqlDelete);
            $stmtDel->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
            $stmtDel->execute();
            $deleted = $stmtDel->rowCount();

            foreach ($rows as $r) {
                if (!empty($r['doc']) && file_exists(__DIR__ . '/../../public/' . $r['doc'])) {
                    @unlink(__DIR__ . '/../../public/' . $r['doc']);
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log('purgeTrashedOlderThanDays error: ' . $e->getMessage());
        }
        return $deleted;
    }

    /**
     * Get users for assignment
     */
    public function getUsersForAssignment(): array
    {
        $sql = "SELECT id, name, email, role, designation FROM users WHERE deleted_at IS NULL ORDER BY name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Existing create/update/delete wrappers use parent::create/update/delete and internal $this->db
    public function create(array $data): int
    {
        if (empty($data['title'])) {
            throw new \Exception('Title is required');
        }
        if (empty($data['user_id'])) {
            throw new \Exception('User ID is required');
        }
        $now = date('Y-m-d H:i:s');
        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $data['updated_at'] ?? $now;
        $data['last_update_time'] = $data['last_update_time'] ?? $now;

        return parent::create($data);
    }

    public function delete(int $id): bool
    {
        return $this->update($id, ['deleted_at' => date('Y-m-d H:i:s')]);
    }

    public function findWithRelations(int $id): ?array
    {
        $sql = "SELECT ht.*,
                    u1.name as user_name, u1.email as user_email, u1.designation as user_designation,
                    u2.name as assigned_by_name, u2.email as assigned_by_email,
                    u3.name as assigned_to_name, u3.email as assigned_to_email
                FROM `{$this->table}` ht
                LEFT JOIN users u1 ON ht.user_id = u1.id
                LEFT JOIN users u2 ON ht.assigned_by = u2.id
                LEFT JOIN users u3 ON ht.assigned_to = u3.id
                WHERE ht.id = :id AND ht.deleted_at IS NULL
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}