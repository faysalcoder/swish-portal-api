<?php
namespace App\Models;

class MeetingStatus extends BaseModel
{
    protected string $table = 'meeting_statuses';
    protected array $fillable = [
        'meeting_id','status','approved_by','declined_by','decline_reason','changed_at'
    ];

    public function historyForMeeting(int $meetingId, int $limit = 100, int $offset = 0): array
    {
        return $this->where(['meeting_id' => $meetingId], $limit, $offset, ['changed_at' => 'DESC']);
    }
}
    