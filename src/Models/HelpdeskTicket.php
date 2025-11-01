<?php
namespace App\Models;

class HelpdeskTicket extends BaseModel
{
    protected string $table = 'helpdesk_tickets';
    protected array $fillable = [
        'hp_id','request_category','assigned_by','assigned_to','status','maintenance_type',
        'request_time','resolve_time','created_at','updated_at'
    ];

    public function byAssignedTo(int $userId, int $limit = 100, int $offset = 0): array
    {
        return $this->where(['assigned_to' => $userId], $limit, $offset);
    }
}
