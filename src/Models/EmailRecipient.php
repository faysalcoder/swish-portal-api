<?php
declare(strict_types=1);

namespace App\Models;

use App\Database\Connection;
use PDO;
use PDOException;

class EmailRecipient extends BaseModel
{
    protected string $table = 'email_recipients';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'email_id',
        'recipient_user_id',
        'recipient_email',
        'delivered',
        'error_message',
        'created_at',
        'updated_at'
    ];

    /**
     * Create a single recipient row (returns id).
     * Defensive: normalize recipient_user_id and ensure referenced user exists.
     */
    public function createRecipient(array $data): int
    {
        if (empty($data['created_at'])) $data['created_at'] = date('Y-m-d H:i:s');
        if (!isset($data['delivered'])) $data['delivered'] = 0;

        // normalize recipient_user_id
        if (isset($data['recipient_user_id'])) {
            $data['recipient_user_id'] = $this->normalizeAndValidateUserId($data['recipient_user_id']);
        } else {
            $data['recipient_user_id'] = null;
        }

        return $this->create($data);
    }

    /**
     * Add multiple recipients for an email (bulk insert via repeated create).
     *
     * $recipients: array of ['email' => 'a@b.c', 'user_id' => 123|null]
     */
    public function addBulkRecipients(int $emailId, array $recipients): void
    {
        if (empty($recipients)) return;

        // We'll validate user ids in a single prepared statement
        $pdo = null;
        try {
            $pdo = Connection::get();
            $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
        } catch (PDOException $e) {
            // If connection fails, we still attempt to insert with recipient_user_id => null
            $stmt = null;
        }

        foreach ($recipients as $r) {
            $rawUserId = $r['user_id'] ?? null;

            $recipientUserId = null;
            if ($rawUserId !== null && $rawUserId !== '') {
                // numeric check
                if (is_numeric($rawUserId) && (int)$rawUserId > 0 && $stmt !== null) {
                    try {
                        $stmt->execute([(int)$rawUserId]);
                        $found = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($found) {
                            $recipientUserId = (int)$rawUserId;
                        } else {
                            $recipientUserId = null;
                        }
                    } catch (\Throwable $e) {
                        // on any DB error treat as null
                        $recipientUserId = null;
                    }
                } else {
                    $recipientUserId = null;
                }
            }

            $row = [
                'email_id' => $emailId,
                'recipient_user_id' => $recipientUserId,
                'recipient_email' => $r['email'],
                'delivered' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ];
            $this->create($row);
        }
    }

    /**
     * Mark recipient row delivered or store error.
     */
    public function markDelivered(int $recipientRowId, ?string $errorMessage = null): bool
    {
        $data = [
            'delivered' => $errorMessage ? 0 : 1,
            'error_message' => $errorMessage,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        return $this->update($recipientRowId, $data);
    }

    /**
     * Get recipients rows for an email id.
     */
    public function getByEmailId(int $emailId, int $limit = 1000, int $offset = 0): array
    {
        return $this->where(['email_id' => $emailId], $limit, $offset, ['id' => 'ASC']);
    }

    /**
     * Find single recipient row.
     */
    public function findRecipient(int $id): ?array
    {
        return $this->find($id);
    }

    /**
     * Helper: normalize and ensure user exists, returns int or null
     */
    private function normalizeAndValidateUserId($raw): ?int
    {
        if ($raw === null || $raw === '') return null;
        if (!is_numeric($raw)) return null;
        $id = (int)$raw;
        if ($id <= 0) return null;

        try {
            $pdo = Connection::get();
            $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $found = $stmt->fetch(PDO::FETCH_ASSOC);
            return $found ? $id : null;
        } catch (PDOException $e) {
            // On DB error, be defensive: return null to avoid FK violation
            return null;
        }
    }
}
