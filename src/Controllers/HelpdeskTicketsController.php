<?php
namespace App\Controllers;

use App\Models\HelpdeskTicket;
use App\Models\HelpdeskRequest;

class HelpdeskTicketsController extends BaseController
{
    protected HelpdeskTicket $ticketModel;
    protected HelpdeskRequest $requestModel;

    public function __construct()
    {
        parent::__construct();
        $this->ticketModel = new HelpdeskTicket();
        $this->requestModel = new HelpdeskRequest();
    }

    public function index(): void
    {
        $this->requireAuth();
        $rows = $this->ticketModel->all(1000, 0);
        $this->success($rows);
    }

    public function show($id): void
    {
        $this->requireAuth();
        $row = $this->ticketModel->find((int)$id);
        if (!$row) $this->error('Not found', 404);
        $this->success($row);
    }

    // POST /api/v1/helpdesk/tickets
    public function store(): void
    {
        $user = $this->requireAuth();
        $data = $this->jsonInput();
        // Expected: hp_id (request id) OR create new request on the fly
        if (empty($data['hp_id']) && empty($data['title'])) $this->error('hp_id or title required', 422);

        if (empty($data['hp_id'])) {
            // create helpdesk request first
            $requestId = $this->requestModel->create([
                'title' => $data['title'],
                'user_id' => $user['id'],
                'note' => $data['note'] ?? null
            ]);
            $data['hp_id'] = $requestId;
        } else {
            // ensure request exists
            $req = $this->requestModel->find((int)$data['hp_id']);
            if (!$req) $this->error('Helpdesk request not found', 404);
        }

        $data['request_time'] = $data['request_time'] ?? date('Y-m-d H:i:s');
        $id = $this->ticketModel->create($data);
        $this->success($this->ticketModel->find($id), 'Created', 201);
    }

    // PUT /api/v1/helpdesk/tickets/{id}
    public function update($id): void
    {
        $this->requireAuth();
        $data = $this->jsonInput();
        $ok = $this->ticketModel->update((int)$id, $data);
        if (!$ok) $this->error('Update failed', 500);
        $this->success($this->ticketModel->find((int)$id), 'Updated');
    }

    // DELETE /api/v1/helpdesk/tickets/{id}
    public function destroy($id): void
    {
        $this->requireAuth();
        $ok = $this->ticketModel->delete((int)$id);
        if (!$ok) $this->error('Delete failed', 500);
        $this->success(null, 'Deleted', 204);
    }
}
