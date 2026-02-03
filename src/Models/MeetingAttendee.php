<?php
namespace App\Models;

class MeetingAttendee extends BaseModel
{
    protected string $table = 'meeting_attendees';
    protected array $fillable = ['meeting_id', 'attendant_id', 'created_at', 'updated_at'];

    /**
     * Find a specific attendee for a meeting
     */
    public function findByMeetingAndUser(int $meetingId, int $userId): ?array
    {
        $rows = $this->where(['meeting_id' => $meetingId, 'attendant_id' => $userId], 1, 0);
        return $rows[0] ?? null;
    }

    /**
     * Return the table name
     */
    public function getTableName(): string
    {
        return $this->table;
    }
}
