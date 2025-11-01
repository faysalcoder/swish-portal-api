<?php
namespace App\Services;

use App\Models\Meeting;
use App\Models\MeetingStatus;
use App\Database\Connection;
use PDO;
use Throwable;

class MeetingService
{
    protected Meeting $meetingModel;
    protected MeetingStatus $statusModel;

    public function __construct()
    {
        $this->meetingModel = new Meeting();
        $this->statusModel = new MeetingStatus();
    }

    /**
     * Create meeting with overlap check. Returns created meeting array.
     * $data must include title, start_time, end_time, room_id; wing/subw optional.
     */
    public function createMeeting(array $data, int $creatorUserId): array
    {
        $required = ['title','start_time','end_time','room_id'];
        foreach ($required as $r) {
            if (empty($data[$r])) {
                throw new \InvalidArgumentException("Missing field: {$r}");
            }
        }

        // Check overlap
        $overlap = $this->meetingModel->findOverlapping((int)$data['room_id'], $data['start_time'], $data['end_time']);
        if (!empty($overlap)) {
            throw new \RuntimeException('Room already booked for this time');
        }

        $pdo = Connection::get();
        try {
            $pdo->beginTransaction();
            $data['user_id'] = $creatorUserId;
            $id = $this->meetingModel->create($data);

            // initial status pending
            $this->statusModel->create([
                'meeting_id' => $id,
                'status' => 'pending',
                'changed_at' => date('Y-m-d H:i:s')
            ]);

            $pdo->commit();
            return $this->meetingModel->find((int)$id);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Update meeting with overlap check (ignoring the same meeting id).
     */
    public function updateMeeting(int $meetingId, array $data): array
    {
        $meeting = $this->meetingModel->find($meetingId);
        if (!$meeting) throw new \RuntimeException('Meeting not found');

        $roomId = $data['room_id'] ?? $meeting['room_id'];
        $start = $data['start_time'] ?? $meeting['start_time'];
        $end = $data['end_time'] ?? $meeting['end_time'];

        $overlap = $this->meetingModel->findOverlapping((int)$roomId, $start, $end);
        // remove self
        foreach ($overlap as $k => $r) {
            if ((int)$r['id'] === (int)$meetingId) unset($overlap[$k]);
        }
        if (!empty($overlap)) throw new \RuntimeException('Room booking conflict');

        $ok = $this->meetingModel->update($meetingId, $data);
        if (!$ok) throw new \RuntimeException('Update failed');

        return $this->meetingModel->find($meetingId);
    }

    /**
     * Create a meeting status change (approve/decline/cancel).
     */
    public function changeStatus(int $meetingId, string $status, ?int $byUserId = null, ?string $declineReason = null): array
    {
        $meeting = $this->meetingModel->find($meetingId);
        if (!$meeting) throw new \RuntimeException('Meeting not found');

        $payload = [
            'meeting_id' => $meetingId,
            'status' => $status,
            'approved_by' => $status === 'approved' ? $byUserId : null,
            'declined_by' => $status === 'declined' ? $byUserId : null,
            'decline_reason' => $declineReason,
            'changed_at' => date('Y-m-d H:i:s')
        ];
        $id = $this->statusModel->create($payload);
        return $this->statusModel->find($id);
    }
}
