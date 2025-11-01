<?php
namespace App\Models;

use App\Database\Connection;
use PDO;
use PDOException;

abstract class BaseModel
{
    protected string $table = '';
    protected string $primaryKey = 'id';
    protected array $fillable = []; // columns allowed for mass assignment; empty = allow all
    protected PDO $db;

    public function __construct()
    {
        // Initialize PDO connection once for all models
        $this->db = Connection::get();
        if (!$this->db instanceof PDO) {
            throw new PDOException('Database connection not available.');
        }
    }

    /**
     * Public accessor for the PDO connection.
     * Use this if you need raw queries from controllers or other places.
     */
    public function db(): PDO
    {
        return $this->db;
    }

    public function find(int $id): ?array
    {
        $sql = "SELECT * FROM `{$this->table}` WHERE `{$this->primaryKey}` = :id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

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

    public function create(array $data): int
    {
        $data = $this->onlyFillable($data);
        if (empty($data)) {
            throw new PDOException('No data to insert.');
        }

        $cols = array_keys($data);
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $sql = "INSERT INTO `{$this->table}` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $placeholders) . ")";
        $stmt = $this->db->prepare($sql);

        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->execute();

        return (int)$this->db->lastInsertId();
    }

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

    public function delete(int $id): bool
    {
        $sql = "DELETE FROM `{$this->table}` WHERE `{$this->primaryKey}` = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }

    protected function onlyFillable(array $data): array
    {
        if (empty($this->fillable)) return $data;
        return array_intersect_key($data, array_flip($this->fillable));
    }

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
