<?php
namespace App\Controllers;

use App\Models\MeetingStatus;
use App\Models\Meeting;

class MeetingStatusesController extends BaseController
{
    protected MeetingStatus $model;
    protected Meeting $meetingModel;

    public function __construct()
    {
        parent::__construct();
        $this->model = new MeetingStatus();
        $this->meetingModel = new Meeting();
    }

    // POST /api/v1/meetings/{id}/status
    public function create($meetingId): void
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
        $id = $this->model->create($payload);

        // optionally update meetings table or take action in services (left to app)
        $this->success($this->model->find($id), 'Status updated', 201);
    }

    // GET /api/v1/meetings/{id}/status
    public function index($meetingId): void
    {
        $this->requireAuth();
        $rows = $this->model->historyForMeeting((int)$meetingId);
        $this->success($rows);
    }
}
