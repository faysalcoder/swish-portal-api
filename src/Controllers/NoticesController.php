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
    $now = date('Y-m-d H:i:s');

    // use public accessor db()
    $pdo = $this->model->db();
    $stmt = $pdo->prepare("SELECT * FROM notices WHERE valid_till IS NULL OR valid_till >= :now ORDER BY created_at DESC");
    $stmt->execute([':now' => $now]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

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
