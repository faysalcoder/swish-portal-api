<?php
namespace App\Controllers;

use App\Models\Sop;

class SopsController extends BaseController
{
    protected Sop $model;

    public function __construct()
    {
        parent::__construct();
        $this->model = new Sop();
    }

    public function index(): void
    {
        $this->requireAuth();
        $wing = $_GET['wing_id'] ?? null;
        $subw = $_GET['subw_id'] ?? null;
        $limit = (int)($_GET['limit'] ?? 100);
        $offset = (int)($_GET['offset'] ?? 0);

        $conds = [];
        if ($wing) $conds['wing_id'] = (int)$wing;
        if ($subw) $conds['subw_id'] = (int)$subw;

        if (!empty($conds)) {
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

    public function store(): void
    {
        $user = $this->requireAuth();
        $data = $this->jsonInput();
        if (empty($data['title'])) $this->error('title required', 422);
        $data['created_by'] = $user['id'] ?? null; // optional tracking
        $id = $this->model->create($data);
        $this->success($this->model->find($id), 'Created', 201);
    }

    public function update($id): void
    {
        $this->requireAuth();
        $data = $this->jsonInput();
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
