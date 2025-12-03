<?php
namespace App\Models;

use PDO;
use DateTimeImmutable;
use DateTimeZone;

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
        'assigned_to', // will contain primary assignee (first of many) for backward compatibility
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
     * Return current datetime in Asia/Dhaka as Y-m-d H:i:s
     */
    protected function now(): string
    {
        $tz = new DateTimeZone('Asia/Dhaka');
        return (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
    }

    /**
     * --- existing methods (searchWithCount, fetchWithFilters, countWithFilters, etc.)
     * We'll reuse these but post-process fetched rows to attach assigned users.
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
                    u2.email as assigned_by_email
                FROM `{$this->table}` ht
                LEFT JOIN users u1 ON ht.user_id = u1.id
                LEFT JOIN users u2 ON ht.assigned_by = u2.id
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

        // attach assignments
        $this->attachAssignmentsToRows($data);

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
                    u2.email as assigned_by_email
                FROM `{$this->table}` ht
                LEFT JOIN users u1 ON ht.user_id = u1.id
                LEFT JOIN users u2 ON ht.assigned_by = u2.id
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

        // attach assignments
        $this->attachAssignmentsToRows($rows);

        return $rows;
    }

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
        $now = $this->now();
        $data = [
            'trashed_at' => $now,
            'last_update_time' => $now,
            'status' => 'trash'
        ];
        return $this->update($id, $data);
    }

    /**
     * Restore ticket from trash
     */
    public function restoreFromTrash(int $id): bool
    {
        $now = $this->now();
        $data = [
            'trashed_at' => null,
            'last_update_time' => $now,
            'status' => 'open'
        ];
        return $this->update($id, $data);
    }

    /**
     * Permanently delete trashed tickets older than $days
     */
    public function purgeTrashedOlderThanDays(int $days = 30): int
    {
        $tz = new DateTimeZone('Asia/Dhaka');
        $cutoff = (new DateTimeImmutable('now', $tz))->modify("-{$days} days")->format('Y-m-d H:i:s');

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

    /**
     * Override create to support multiple assigned_to values (array)
     */
    public function create(array $data): int
    {
        if (empty($data['title'])) {
            throw new \Exception('Title is required');
        }
        if (empty($data['user_id'])) {
            throw new \Exception('User ID is required');
        }
        $now = $this->now();
        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $data['updated_at'] ?? $now;
        $data['last_update_time'] = $data['last_update_time'] ?? $now;
        if (empty($data['request_time'])) {
            $data['request_time'] = $now;
        }

        // If assigned_to provided as array, use first as primary and remove full array before parent::create
        $assignments = null;
        if (isset($data['assigned_to']) && is_array($data['assigned_to'])) {
            $assignments = $data['assigned_to'];
            $data['assigned_to'] = count($assignments) ? (int)$assignments[0] : null;
        } elseif (isset($data['assigned_to']) && $data['assigned_to'] === '') {
            $data['assigned_to'] = null;
        }

        // call parent create
        $newId = parent::create($data);

        // write assignments if provided
        if ($newId && is_array($assignments)) {
            $assignedBy = isset($data['assigned_by']) ? (int)$data['assigned_by'] : null;
            $this->setAssignments((int)$newId, $assignments, $assignedBy);
        }

        return $newId;
    }

    /**
     * Override update to support assigned_to array - FIXED for unassignment
     */
    public function update(int $id, array $data): bool
    {
        // Handle assigned_to array: update primary assigned_to column to first element, and set assignments table
        $assignments = null;
        if (array_key_exists('assigned_to', $data)) {
            if (is_array($data['assigned_to'])) {
                // If it's an array (including empty array), process it
                $assignments = $data['assigned_to'];
                $data['assigned_to'] = count($assignments) ? (int)$assignments[0] : null;
            } elseif ($data['assigned_to'] === '' || $data['assigned_to'] === null) {
                // Explicit unassignment - set to null and assignments to empty array
                $data['assigned_to'] = null;
                $assignments = [];
            }
        }

        // ensure updated_at / last_update_time use Dhaka now if not provided
        $now = $this->now();
        if (!array_key_exists('updated_at', $data) || $data['updated_at'] === null) {
            $data['updated_at'] = $now;
        }
        if (!array_key_exists('last_update_time', $data) || $data['last_update_time'] === null) {
            $data['last_update_time'] = $now;
        }

        $ok = parent::update($id, $data);

        // Always call setAssignments when assignments is an array (including empty for unassignment)
        if ($ok && is_array($assignments)) {
            $assignedBy = isset($data['assigned_by']) ? (int)$data['assigned_by'] : null;
            $this->setAssignments($id, $assignments, $assignedBy);
        }

        return $ok;
    }

    /**
     * Attach assignments to fetched rows (adds assigned_to_users array to each row)
     * modifies $rows by reference
     */
    protected function attachAssignmentsToRows(array &$rows): void
    {
        if (empty($rows)) return;
        $ids = array_map(function ($r) { return (int)$r['id']; }, $rows);
        $assignMap = $this->getAssignmentsForTickets($ids); // returns ticket_id => array of users
        foreach ($rows as &$r) {
            $tid = (int)$r['id'];
            $r['assigned_to_users'] = $assignMap[$tid] ?? [];
            // keep 'assigned_to' for backwards compat (first id) - already present from table
        }
    }

    /**
     * Get assignments for multiple tickets.
     * Returns associative: ticket_id => [ ['id'=>..., 'name'=>..., 'email'=>..., 'designation'=>...], ... ]
     */
    public function getAssignmentsForTickets(array $ticketIds): array
    {
        $out = [];
        if (empty($ticketIds)) return $out;

        // build placeholders
        $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
        $sql = "SELECT ta.ticket_id, u.id as user_id, u.name, u.email, u.designation
                FROM ticket_assignments ta
                JOIN users u ON ta.user_id = u.id
                WHERE ta.ticket_id IN ({$placeholders})
                ORDER BY ta.id ASC";
        $stmt = $this->db->prepare($sql);

        $i = 1;
        foreach ($ticketIds as $tid) {
            $stmt->bindValue($i++, (int)$tid, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $tid = (int)$r['ticket_id'];
            if (!isset($out[$tid])) $out[$tid] = [];
            $out[$tid][] = [
                'id' => (int)$r['user_id'],
                'name' => $r['name'],
                'email' => $r['email'],
                'designation' => $r['designation']
            ];
        }

        return $out;
    }

    /**
     * Set assignments for a ticket (replace existing)
     * $userIds is array of int (user ids) - can be empty to unassign all
     */
    public function setAssignments(int $ticketId, array $userIds, ?int $assignedBy = null): bool
    {
        // sanitize ids
        $uids = array_values(array_filter(array_map('intval', $userIds), function($v){ return $v > 0; }));
        try {
            $this->db->beginTransaction();

            // delete existing assignments
            $stmtDel = $this->db->prepare("DELETE FROM ticket_assignments WHERE ticket_id = :ticket_id");
            $stmtDel->bindValue(':ticket_id', $ticketId, PDO::PARAM_INT);
            $stmtDel->execute();

            // Only insert if there are users to assign
            if (!empty($uids)) {
                $insSql = "INSERT INTO ticket_assignments (ticket_id, user_id, assigned_by, created_at) VALUES ";
                $vals = [];
                $params = [];
                foreach ($uids as $uid) {
                    $vals[] = "(?, ?, ?, ?)";
                    $params[] = $ticketId;
                    $params[] = $uid;
                    // assigned_by may be null => store null
                    $params[] = $assignedBy;
                    $params[] = $this->now();
                }
                $insSql .= implode(',', $vals);
                $stmtIns = $this->db->prepare($insSql);
                // bind positional params
                $k = 1;
                foreach ($params as $p) {
                    if (is_int($p)) {
                        $stmtIns->bindValue($k++, $p, PDO::PARAM_INT);
                    } elseif ($p === null) {
                        $stmtIns->bindValue($k++, null, PDO::PARAM_NULL);
                    } else {
                        $stmtIns->bindValue($k++, $p, PDO::PARAM_STR);
                    }
                }
                $stmtIns->execute();
            }

            // update primary assigned_to column on helpdesk_tickets to first assigned id (or null if unassigned)
            $primary = !empty($uids) ? (int)$uids[0] : null;
            $stmtUpd = $this->db->prepare("UPDATE `{$this->table}` SET assigned_to = :primary WHERE id = :id");
            if ($primary === null) {
                $stmtUpd->bindValue(':primary', null, PDO::PARAM_NULL);
            } else {
                $stmtUpd->bindValue(':primary', $primary, PDO::PARAM_INT);
            }
            $stmtUpd->bindValue(':id', $ticketId, PDO::PARAM_INT);
            $stmtUpd->execute();

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log('setAssignments error: ' . $e->getMessage());
            return false;
        }
    }

    public function delete(int $id): bool
    {
        return $this->update($id, ['deleted_at' => $this->now()]);
    }

    public function findWithRelations(int $id): ?array
    {
        $sql = "SELECT ht.*,
                    u1.name as user_name, u1.email as user_email, u1.designation as user_designation,
                    u2.name as assigned_by_name, u2.email as assigned_by_email
                FROM `{$this->table}` ht
                LEFT JOIN users u1 ON ht.user_id = u1.id
                LEFT JOIN users u2 ON ht.assigned_by = u2.id
                WHERE ht.id = :id AND ht.deleted_at IS NULL
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        // attach assignments
        $assignMap = $this->getAssignmentsForTickets([$id]);
        $row['assigned_to_users'] = $assignMap[$id] ?? [];

        return $row ?: null;
    }
}