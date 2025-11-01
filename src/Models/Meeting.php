<?php
namespace App\Models;

class Meeting extends BaseModel
{
    protected string $table = 'meetings';
    protected array $fillable = [
        'wing_id','subw_id','user_id','title','room_id','start_time','end_time','created_at','updated_at'
    ];

    /**
     * Return meetings for a room overlapping given time range
     */
    public function findOverlapping(int $roomId, string $startTime, string $endTime): array
    {
        $sql = "SELECT * FROM `{$this->table}`
                WHERE `room_id` = :room_id
                  AND NOT (end_time <= :start_time OR start_time >= :end_time)";
        $stmt = $this->db()->prepare($sql);
        $stmt->execute([
            ':room_id' => $roomId,
            ':start_time' => $startTime,
            ':end_time' => $endTime
        ]);
        return $stmt->fetchAll();
    }

    public function byUser(int $userId, int $limit = 100, int $offset = 0): array
    {
        return $this->where(['user_id' => $userId], $limit, $offset, ['start_time' => 'DESC']);
    }
}
