<?php
namespace App\Controllers;

use App\Models\Meeting;
use App\Models\MeetingStatus;
use App\Models\Room;
use App\Models\MeetingAttendee;
use App\Models\User;
use App\Models\Wing;
use App\Models\SubWing;

class MeetingsController extends BaseController
{
    protected Meeting $model;
    protected MeetingStatus $statusModel;
    protected Room $roomModel;
    protected MeetingAttendee $attendeeModel;
    protected ?User $userModel;
    protected Wing $wingModel;
    protected Subwing $subwingModel;

    public function __construct()
    {
        parent::__construct();
        $this->model = new Meeting();
        $this->statusModel = new MeetingStatus();
        $this->roomModel = new Room();
        $this->attendeeModel = new MeetingAttendee();
        $this->userModel = new User();
        $this->wingModel = new Wing();
        $this->subwingModel = new Subwing();
    }

    /**
     * GET /api/v1/meetings
     * Returns meetings (with room, latest status, attendees(user info), created_by user, wing, sub_wings)
     */
    public function index(): void
    {
        $this->requireAuth();
        $limit = (int)($_GET['limit'] ?? 100);
        $offset = (int)($_GET['offset'] ?? 0);

        // optional filters: user_id, room_id, date
        $conds = [];
        if (!empty($_GET['user_id'])) $conds['user_id'] = (int)$_GET['user_id'];
        if (!empty($_GET['room_id'])) $conds['room_id'] = (int)$_GET['room_id'];
        if (!empty($_GET['date'])) {
            // keep same simple behaviour as before
            $conds['start_time'] = $_GET['date'];
        }

        if (!empty($conds)) {
            $rows = $this->model->where($conds, $limit, $offset);
        } else {
            $rows = $this->model->all($limit, $offset);
        }

        // Attach relations to each meeting
        $out = [];
        foreach ($rows as $m) {
            $out[] = $this->attachRelationsToMeeting($m);
        }

        $this->success($out);
    }

    /**
     * GET /api/v1/meetings/{id}
     */
    public function show($id): void
    {
        $this->requireAuth();
        $meeting = $this->model->find((int)$id);
        if (!$meeting) $this->error('Not found', 404);

        $this->success($this->attachRelationsToMeeting($meeting));
    }

    /**
     * POST /api/v1/meetings
     * Accepts optional "attendees": [userId, userId, ...]
     */
    public function store(): void
    {
        $user = $this->requireAuth();
        $data = $this->jsonInput();
        $req = ['title','start_time','end_time','room_id'];
        foreach ($req as $f) if (empty($data[$f])) $this->error("Missing field: $f", 422);

        // check overlapping for same room
        $overlap = $this->model->findOverlapping((int)$data['room_id'], $data['start_time'], $data['end_time']);
        if (!empty($overlap)) {
            $this->error('Room is already booked for that time range', 409, $overlap);
        }

        $data['user_id'] = $user['id'];
        $id = $this->model->create($data);

        // add initial meeting status = pending
        $this->statusModel->create([
            'meeting_id' => $id,
            'status' => 'pending',
            'changed_at' => date('Y-m-d H:i:s')
        ]);

        // optional attendees array
        if (!empty($data['attendees']) && is_array($data['attendees'])) {
            foreach ($data['attendees'] as $uid) {
                $this->createAttendeeRow((int)$id, (int)$uid);
            }
        }

        $this->success($this->attachRelationsToMeeting($this->model->find($id)), 'Created', 201);
    }

    /**
     * PUT /api/v1/meetings/{id}
     * If "attendees" present (array of user ids) we replace attendees list with provided list.
     */
    public function update($id): void
    {
        $user = $this->requireAuth();
        $data = $this->jsonInput();

        $meeting = $this->model->find((int)$id);
        if (!$meeting) $this->error('Not found', 404);

        // if room or time updated, check overlap
        $roomId = $data['room_id'] ?? $meeting['room_id'];
        $start = $data['start_time'] ?? $meeting['start_time'];
        $end = $data['end_time'] ?? $meeting['end_time'];

        $overlap = $this->model->findOverlapping((int)$roomId, $start, $end);
        // ignore self in overlap
        foreach ($overlap as $k => $r) if ($r['id'] == $id) unset($overlap[$k]);
        if (!empty($overlap)) $this->error('Room booking conflict', 409, $overlap);

        $ok = $this->model->update((int)$id, $data);
        if (!$ok) $this->error('Update failed', 500);

        // If attendees provided, replace attendees for meeting
        if (array_key_exists('attendees', $data) && is_array($data['attendees'])) {
            // delete existing attendees for meeting
            $this->deleteAttendeesForMeeting((int)$id);

            // insert new ones
            foreach ($data['attendees'] as $uid) {
                $this->createAttendeeRow((int)$id, (int)$uid);
            }
        }

        $this->success($this->attachRelationsToMeeting($this->model->find((int)$id)), 'Updated');
    }

    /**
     * DELETE /api/v1/meetings/{id}
     */
    public function destroy($id): void
    {
        $this->requireAuth();
        $ok = $this->model->delete((int)$id);
        if (!$ok) $this->error('Delete failed', 500);
        $this->success(null, 'Deleted', 204);
    }

    //
    // Attendees management endpoints
    //

    /**
     * POST /api/v1/meetings/{id}/attendees
     * body: { "attendant_id": 123 }
     */
    public function addAttendee($meetingId): void
    {
        $this->requireAuth();
        $meeting = $this->model->find((int)$meetingId);
        if (!$meeting) $this->error('Meeting not found', 404);

        $data = $this->jsonInput();
        if (empty($data['attendant_id'])) $this->error('attendant_id required', 422);

        $uid = (int)$data['attendant_id'];
        $this->createAttendeeRow((int)$meetingId, $uid);

        // return full meeting (with relations)
        $this->success($this->attachRelationsToMeeting($meeting), 'Attendee added', 201);
    }

    /**
     * DELETE /api/v1/meetings/{id}/attendees/{userId}
     */
    public function removeAttendee($meetingId, $userId): void
    {
        $this->requireAuth();
        $meeting = $this->model->find((int)$meetingId);
        if (!$meeting) $this->error('Meeting not found', 404);

        $ok = $this->deleteAttendeeRow((int)$meetingId, (int)$userId);
        if (!$ok) $this->error('Remove attendee failed', 500);

        $this->success($this->attachRelationsToMeeting($meeting), 'Attendee removed', 200);
    }

    //
    // Status management (moved here)
    //

    /**
     * POST /api/v1/meetings/{id}/status
     * body: { "status": "approved" | "declined" | "pending", "decline_reason": "..." }
     */
    public function createStatus($meetingId): void
    {
        $user = $this->requireAuth();
        $data = $this->jsonInput();
        if (empty($data['status'])) $this->error('status required', 422);

        $payload = [
            'meeting_id' => (int)$meetingId,
            'status' => $data['status'],
            'approved_by' => $data['status'] === 'approved' ? $user['id'] : null,
            'declined_by' => $data['status'] === 'declined' ? $user['id'] : null,
            'decline_reason' => $data['decline_reason'] ?? null,
            'changed_at' => date('Y-m-d H:i:s')
        ];
        $id = $this->statusModel->create($payload);

        // retrieve & enrich status with user objects
        $row = $this->statusModel->find($id);
        if ($row) {
            $row = $this->enrichStatusWithUsers($row);
        }

        $this->success($row, 'Status updated', 201);
    }

    /**
     * GET /api/v1/meetings/{id}/status
     * Returns history for meeting (enriched with user objects)
     */
    public function statusIndex($meetingId): void
    {
        $this->requireAuth();
        $rows = $this->statusModel->historyForMeeting((int)$meetingId);
        // enrich each row
        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->enrichStatusWithUsers($r);
        }
        $this->success($out);
    }

    //
    // Helpers
    //

    /**
     * Attach room, latest status, attendees (with user info) to meeting row
     * Also attaches creator user under 'created_by', enriches status with user objects,
     * and attaches 'wing' and 'sub_wings' objects (if meeting has ids).
     */
    protected function attachRelationsToMeeting(array $meeting): array
    {
        // room
        $meeting['room'] = null;
        if (!empty($meeting['room_id'])) {
            $meeting['room'] = $this->roomModel->find((int)$meeting['room_id']);
        }

        // creator user (full user object) -> created_by
        $meeting['created_by'] = null;
        if (!empty($meeting['user_id'])) {
            $meeting['created_by'] = $this->userModel->find((int)$meeting['user_id']);
        }

        // wing (try common keys: wing_id or wing)
        $meeting['wing'] = null;
        $wingId = null;
        if (isset($meeting['wing_id']) && $meeting['wing_id'] !== '') $wingId = $meeting['wing_id'];
        elseif (isset($meeting['wing']) && is_numeric($meeting['wing'])) $wingId = $meeting['wing'];
        if (!empty($wingId) && is_numeric($wingId)) {
            $meeting['wing'] = $this->wingModel->find((int)$wingId);
        }

        // sub_wings - using subw_id from your data structure
        $meeting['sub_wings'] = null;
        $subId = null;
        
        // Check for subw_id first (as shown in your data)
        if (isset($meeting['subw_id']) && !empty($meeting['subw_id']) && $meeting['subw_id'] !== '') {
            $subId = $meeting['subw_id'];
        }
        // Fallback to other possible column names
        elseif (isset($meeting['sub_wings']) && !empty($meeting['sub_wings']) && $meeting['sub_wings'] !== '') {
            $subId = $meeting['sub_wings'];
        }
        elseif (isset($meeting['subwing']) && !empty($meeting['subwing']) && $meeting['subwing'] !== '') {
            $subId = $meeting['subwing'];
        }
        elseif (isset($meeting['subwing_id']) && !empty($meeting['subwing_id']) && $meeting['subwing_id'] !== '') {
            $subId = $meeting['subwing_id'];
        }
        
        if (!empty($subId) && is_numeric($subId) && (int)$subId > 0) {
            $meeting['sub_wings'] = $this->subwingModel->find((int)$subId);
        }

        // latest status
        $meeting['status'] = null;
        $hist = $this->statusModel->historyForMeeting((int)$meeting['id'], 1, 0);
        if (!empty($hist)) {
            $meeting['status'] = $this->enrichStatusWithUsers($hist[0]);
        }

        // attendees rows for meeting
        $rawAtt = $this->attendeeModel->where(['meeting_id' => (int)$meeting['id']], 1000, 0, ['created_at' => 'DESC']);
        $attendees = [];
        foreach ($rawAtt as $r) {
            $user = $this->userModel->find((int)$r['attendant_id']);
            $attendees[] = [
                'meeting_id' => (int)$r['meeting_id'],
                'attendant_id' => (int)$r['attendant_id'],
                'created_at' => $r['created_at'],
                'updated_at' => $r['updated_at'],
                'user' => $user
            ];
        }
        $meeting['attendees'] = $attendees;

        return $meeting;
    }

    /**
     * Create attendee row if not exists (uses INSERT ... ON DUPLICATE KEY UPDATE)
     */
    protected function createAttendeeRow(int $meetingId, int $userId): bool
    {
        // Using the attendee model's DB connection directly to insert composite PK row.
        $sql = "INSERT INTO `{$this->attendeeModel->getTableName()}` (meeting_id, attendant_id, created_at, updated_at)
                VALUES (:meeting_id, :attendant_id, NOW(), NOW())
                ON DUPLICATE KEY UPDATE updated_at = NOW()";
        $stmt = $this->attendeeModel->db()->prepare($sql);
        return (bool)$stmt->execute([
            ':meeting_id' => $meetingId,
            ':attendant_id' => $userId
        ]);
    }

    /**
     * Delete a single attendee row
     */
    protected function deleteAttendeeRow(int $meetingId, int $userId): bool
    {
        $sql = "DELETE FROM `{$this->attendeeModel->getTableName()}` WHERE meeting_id = :meeting_id AND attendant_id = :attendant_id";
        $stmt = $this->attendeeModel->db()->prepare($sql);
        return (bool)$stmt->execute([':meeting_id' => $meetingId, ':attendant_id' => $userId]);
    }

    /**
     * Delete all attendees for a meeting
     */
    protected function deleteAttendeesForMeeting(int $meetingId): bool
    {
        $sql = "DELETE FROM `{$this->attendeeModel->getTableName()}` WHERE meeting_id = :meeting_id";
        $stmt = $this->attendeeModel->db()->prepare($sql);
        return (bool)$stmt->execute([':meeting_id' => $meetingId]);
    }

    /**
     * Enrich a status row with full user objects for approved_by and declined_by.
     * Returns the modified status row.
     */
    protected function enrichStatusWithUsers(array $status): array
    {
        // ensure presence of fields
        $status['approved_by_user'] = null;
        $status['declined_by_user'] = null;

        if (!empty($status['approved_by'])) {
            $status['approved_by_user'] = $this->userModel->find((int)$status['approved_by']);
        }

        if (!empty($status['declined_by'])) {
            $status['declined_by_user'] = $this->userModel->find((int)$status['declined_by']);
        }

        return $status;
    }
}