<?php
declare(strict_types=1);

namespace App\Models;

class EmailAttachment extends BaseModel
{
    protected string $table = 'email_attachments';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'email_id',
        'original_name',
        'stored_name',
        'mime_type',
        'size',
        'created_at',
        'updated_at'
    ];

    /**
     * Add attachment metadata (returns inserted id).
     */
    public function addAttachment(int $emailId, string $originalName, string $storedName, ?string $mimeType = null, ?int $size = null): int
    {
        $data = [
            'email_id' => $emailId,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'mime_type' => $mimeType,
            'size' => $size,
            'created_at' => date('Y-m-d H:i:s')
        ];
        return $this->create($data);
    }

    /**
     * Get attachments for an email id.
     */
    public function getByEmailId(int $emailId, int $limit = 1000, int $offset = 0): array
    {
        return $this->where(['email_id' => $emailId], $limit, $offset, ['id' => 'ASC']);
    }

    /**
     * Find attachment by id.
     */
    public function findAttachment(int $id): ?array
    {
        return $this->find($id);
    }
}
