<?php
declare(strict_types=1);

namespace App\Models;

use DateTime;
use PDO;
use PDOException;

class Email extends BaseModel
{
    protected string $table = 'emails';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'subject',
        'requester_id',
        'requester_email',
        'email_body',
        'send_time',
        'status',
        'created_at',
        'updated_at'
    ];

    /**
     * Create new email record (returns new id).
     */
    public function createEmail(array $data): int
    {
        if (empty($data['created_at'])) $data['created_at'] = date('Y-m-d H:i:s');
        if (empty($data['updated_at'])) $data['updated_at'] = date('Y-m-d H:i:s');
        if (empty($data['status'])) $data['status'] = 'pending';

        // normalize requester_id: only allow positive ints, otherwise null
        if (isset($data['requester_id'])) {
            // if numeric and > 0 keep as int, otherwise null
            $data['requester_id'] = (is_numeric($data['requester_id']) && (int)$data['requester_id'] > 0)
                ? (int)$data['requester_id']
                : null;
        } else {
            $data['requester_id'] = null;
        }

        return $this->create($data);
    }

    /**
     * Update an email record (wrapper).
     */
    public function updateEmail(int $id, array $data): bool
    {
        if (!isset($data['updated_at'])) $data['updated_at'] = date('Y-m-d H:i:s');

        if (isset($data['requester_id'])) {
            $data['requester_id'] = (is_numeric($data['requester_id']) && (int)$data['requester_id'] > 0)
                ? (int)$data['requester_id']
                : null;
        }

        return $this->update($id, $data);
    }

    /**
     * Mark email as sent and set send_time.
     */
    public function markSent(int $id): bool
    {
        return $this->update($id, [
            'status' => 'sent',
            'send_time' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Mark email as failed.
     */
    public function markFailed(int $id): bool
    {
        return $this->update($id, [
            'status' => 'failed',
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Find email by id (instance wrapper).
     */
    public function findById(int $id): ?array
    {
        return $this->find($id);
    }

    /**
     * Recent emails with pagination.
     */
    public function recent(int $limit = 50, int $offset = 0): array
    {
        return $this->all($limit, $offset, ['created_at' => 'DESC']);
    }
}
