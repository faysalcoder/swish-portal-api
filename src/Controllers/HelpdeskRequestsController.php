<?php
namespace App\Controllers;

use App\Models\HelpdeskRequest;

class HelpdeskRequestsController extends BaseController
{
    protected HelpdeskRequest $model;

    public function __construct()
    {
        parent::__construct();
        $this->model = new HelpdeskRequest();
    }

    public function index(): void
    {
        $this->requireAuth();
        $rows = $this->model->all(1000, 0);
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
        $id = $this->model->create($data);
        $this->success($this->model->find($id), 'Created', 201);
    }

    public function destroy($id): void
    {
        $this->requireAuth();
        $ok = $this->model->delete((int)$id);
        if (!$ok) $this->error('Delete failed', 500);
        $this->success(null, 'Deleted', 204);
    }
}
