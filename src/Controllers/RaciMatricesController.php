<?php
namespace App\Controllers;

use App\Models\RaciMatrix;

class RaciMatricesController extends BaseController
{
    protected RaciMatrix $model;

    public function __construct()
    {
        parent::__construct();
        $this->model = new RaciMatrix();
    }

    public function index(): void
    {
        $this->requireAuth();
        $wing = $_GET['wing_id'] ?? null;
        $month = $_GET['month'] ?? null; // expect YYYY-MM-01
        $conds = [];
        if ($wing) $conds['wing_id'] = (int)$wing;
        if ($month) $conds['month'] = $month;
        $rows = !empty($conds) ? $this->model->where($conds, 100, 0) : $this->model->all(100,0);
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
        $this->requireAuth();
        $data = $this->jsonInput();
        if (empty($data['title'])) $this->error('title required', 422);
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
