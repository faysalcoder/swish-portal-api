<?php
namespace App\Controllers;

use App\Models\Meeting;
use App\Models\MeetingStatus;

class MeetingsController extends BaseController
{
    protected Meeting $model;
    protected MeetingStatus $statusModel;

    public function __construct()
    {
        parent::__construct();
        $this->model = new Meeting();
        $this->statusModel = new MeetingStatus();
    }

    public function index(): void
    {
        $user = $this->requireAuth();
        $limit = (int)($_GET['limit'] ?? 100);
        $offset = (int)($_GET['offset'] ?? 0);
        // optional filters: user_id, room_id, date
        $conds = [];
        if (!empty($_GET['user_id'])) $conds['user_id'] = (int)$_GET['user_id'];
        if (!empty($_GET['room_id'])) $conds['room_id'] = (int)$_GET['room_id'];
        if (!empty($_GET['date'])) {
            $date = $_GET['date'];
            $conds['start_time'] = $date; // simple, but better to use date range in production
        }

        if (!empty($conds)) {
            // only implement simple field equality
            $rows = $this->model->where($conds, $limit, $offset);
        } else {
            $rows = $this->model->all($limit, $offset);
        }
        $this->success($rows);
    }

    public function show($id): void
    {
        $this->requireAuth();
        $row = $this->model->find((int)$id);
        if (!$row) $this->error('Not found', 404);
        $this->success($row);
    }

    // POST /api/v1/meetings
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

        $this->success($this->model->find($id), 'Created', 201);
    }

    public function update($id): void
    {
        $user = $this->requireAuth();
        $data = $this->jsonInput();

        // if room or time updated, check overlap
        $meeting = $this->model->find((int)$id);
        if (!$meeting) $this->error('Not found', 404);
        $roomId = $data['room_id'] ?? $meeting['room_id'];
        $start = $data['start_time'] ?? $meeting['start_time'];
        $end = $data['end_time'] ?? $meeting['end_time'];

        $overlap = $this->model->findOverlapping((int)$roomId, $start, $end);
        // ignore self in overlap
        foreach ($overlap as $k => $r) if ($r['id'] == $id) unset($overlap[$k]);
        if (!empty($overlap)) $this->error('Room booking conflict', 409, $overlap);

        $ok = $this->model->update((int)$id, $data);
        if (!$ok) $this->error('Update failed', 500);
        $this->success($this->model->find((int)$id), 'Updated');
    }

    public function destroy($id): void
    {
        $this->requireAuth();
        $ok = $this->model->delete((int)$id);
        if (!$ok) $this->error('Delete failed', 500);
        $this->success(null, 'Deleted', 204);
    }
}
