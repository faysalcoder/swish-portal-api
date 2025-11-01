<?php
namespace App\Controllers;

use App\Models\RaciRole;

class RaciRolesController extends BaseController
{
    protected RaciRole $model;

    public function __construct()
    {
        parent::__construct();
        $this->model = new RaciRole();
    }

    public function index(): void
    {
        $this->requireAuth();
        $rows = $this->model->all(1000, 0);
        $this->success($rows);
    }

    public function byRaci($raciId): void
    {
        $this->requireAuth();
        $rows = $this->model->byRaci((int)$raciId);
        $this->success($rows);
    }

    public function store(): void
    {
        $this->requireAuth();
        $data = $this->jsonInput();
        $required = ['raci_id','role','user_id'];
        foreach ($required as $f) if (empty($data[$f])) $this->error("Missing: $f", 422);
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
