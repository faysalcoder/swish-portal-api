<?php
namespace App\Controllers;

use App\Models\Notice;

class NoticesController extends BaseController
{
    protected Notice $model;

    public function __construct()
    {
        parent::__construct();
        $this->model = new Notice();
    }

    public function index(): void
    {
        $this->requireAuth();
        $rows = $this->model->getActive(100, 0);
        $this->success($rows);
    }

    public function show($id): void
    {
        $this->requireAuth();
        $row = $this->model->find((int)$id);
        if (!$row) $this->error('Not found', 404);
        $this->success($row);
    }

    public function store(): void
    {
        $user = $this->requireAuth();
        $data = $this->jsonInput();
        if (empty($data['title'])) $this->error('title required', 422);

        $data['user_id'] = $user['id'];
        $id = $this->model->createNotice($data);
        $this->success($this->model->find($id), 'Created', 201);
    }

    public function update($id): void
    {
        $this->requireAuth();
        $data = $this->jsonInput();
        $row = $this->model->find((int)$id);
        if (!$row) $this->error('Not found', 404);

        $ok = $this->model->updateNotice((int)$id, $data);
        if (!$ok) $this->error('Update failed', 500);
        $this->success($this->model->find((int)$id), 'Updated');
    }

    public function destroy($id): void
    {
        $this->requireAuth();
        $id = (int)$id;
        $row = $this->model->find($id);
        if (!$row) {
            $this->error('Not found', 404);
            return;
        }

        $ok = $this->model->delete($id);
        if (!$ok) {
            $this->error('Delete failed', 500);
            return;
        }

        // deleted successfully
        // Some clients expect 204 NO CONTENT; your success helper may send JSON. We'll send 200 with message.
        $this->success(null, 'Deleted', 200);
    }
}
