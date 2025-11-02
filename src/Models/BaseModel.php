<?php
namespace App\Models;

use App\Database\Connection;
use PDO;
use PDOException;

/**
 * BaseModel - small ORM-style base class for simple CRUD
 *
 * Child models should set:
 *   protected string $table = 'your_table';
 *   protected array $fillable = ['col1','col2', ...]; // or leave empty to allow all
 */
abstract class BaseModel
{
    protected string $table = '';
    protected string $primaryKey = 'id';
    protected array $fillable = []; // columns allowed for mass assignment; empty = allow all
    protected PDO $db;

    /**
     * Construct and initialize PDO connection.
     *
     * @throws PDOException if connection is not available
     */
    public function __construct()
    {
        $this->db = Connection::get();
        if (!$this->db instanceof PDO) {
            throw new PDOException('Database connection not available.');
        }
    }

    /**
     * Backwards-compatible accessor used by older controllers:
     * $this->model->db()
     *
     * @return PDO
     */
    public function db(): PDO
    {
        return $this->db;
    }

    /**
     * Find a row by primary key.
     *
     * @param int $id
     * @return array|null
     */
    public function find(int $id): ?array
    {
        $sql = "SELECT * FROM `{$this->table}` WHERE `{$this->primaryKey}` = :id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Get all rows with optional ordering.
     *
     * @param int $limit
     * @param int $offset
     * @param array $orderBy example: ['created_at' => 'DESC']
     * @return array
     */
    public function all(int $limit = 100, int $offset = 0, array $orderBy = []): array
    {
        $orderSql = $this->buildOrderBy($orderBy);
        $sql = "SELECT * FROM `{$this->table}` {$orderSql} LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Simple equality WHERE helper.
     *
     * @param array $conditions e.g. ['user_id' => 5, 'status' => 'active']
     * @param int $limit
     * @param int $offset
     * @param array $orderBy
     * @return array
     */
    public function where(array $conditions = [], int $limit = 100, int $offset = 0, array $orderBy = []): array
    {
        $whereSql = '';
        $params = [];

        if (!empty($conditions)) {
            $w = [];
            foreach ($conditions as $k => $v) {
                $col = preg_replace('#[^a-zA-Z0-9_]#', '', $k);
                $param = ':' . $col;
                $w[] = "`{$col}` = {$param}";
                $params[$param] = $v;
            }
            $whereSql = 'WHERE ' . implode(' AND ', $w);
        }

        $orderSql = $this->buildOrderBy($orderBy);
        $sql = "SELECT * FROM `{$this->table}` {$whereSql} {$orderSql} LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);

        foreach ($params as $p => $v) {
            $stmt->bindValue($p, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Insert record. Returns new ID.
     *
     * @param array $data
     * @return int
     * @throws PDOException
     */
    public function create(array $data): int
    {
        $data = $this->onlyFillable($data);
        if (empty($data)) {
            throw new PDOException('No data to insert.');
        }

        $cols = array_keys($data);
        $placeholders = array_map(function ($c) { return ':' . $c; }, $cols);
        $sql = "INSERT INTO `{$this->table}` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $placeholders) . ")";
        $stmt = $this->db->prepare($sql);

        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->execute();

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update record by primary key. Returns boolean success.
     *
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function update(int $id, array $data): bool
    {
        $data = $this->onlyFillable($data);
        if (empty($data)) return false;

        $sets = [];
        foreach ($data as $k => $v) {
            $col = preg_replace('#[^a-zA-Z0-9_]#', '', $k);
            $sets[] = "`{$col}` = :{$col}";
        }

        $sql = "UPDATE `{$this->table}` SET " . implode(', ', $sets) . " WHERE `{$this->primaryKey}` = :id";
        $stmt = $this->db->prepare($sql);
        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Delete by primary key.
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        $sql = "DELETE FROM `{$this->table}` WHERE `{$this->primaryKey}` = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Only keep fillable keys. If $fillable is empty, return original data.
     *
     * @param array $data
     * @return array
     */
    protected function onlyFillable(array $data): array
    {
        if (empty($this->fillable)) return $data;
        return array_intersect_key($data, array_flip($this->fillable));
    }

    /**
     * Build ORDER BY clause from array
     *
     * @param array $orderBy
     * @return string
     */
    protected function buildOrderBy(array $orderBy): string
    {
        if (empty($orderBy)) return '';
        $parts = [];
        foreach ($orderBy as $col => $dir) {
            $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';
            $col = preg_replace('#[^a-zA-Z0-9_]#', '', $col);
            $parts[] = "`{$col}` {$dir}";
        }
        return !empty($parts) ? 'ORDER BY ' . implode(', ', $parts) : '';
    }
}
