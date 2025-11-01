<?php
namespace App\Models;

class HelpdeskRequest extends BaseModel
{
    protected string $table = 'helpdesk_ticket_requests';
    protected array $fillable = ['title','user_id','note','created_at'];

    public function byUser(int $userId, int $limit = 100, int $offset = 0): array
    {
        return $this->where(['user_id' => $userId], $limit, $offset);
    }
}
