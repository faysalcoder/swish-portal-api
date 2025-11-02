<?php
namespace App\Controllers;

use App\Models\Wing;
use PDOException;

class WingsController extends BaseController
{
    protected Wing $model;

    public function __construct()
    {
        parent::__construct();
        $this->model = new Wing();
    }

    public function index(): void
    {
        $this->requireAuth();
        $rows = $this->model->all(1000, 0, ['id' => 'ASC']);
        $this->success($rows);
    }

    public function show($id): void
    {
        $this->requireAuth();
        $row = $this->model->find((int)$id);
        if (!$row) {
            $this->error('Not found', 404);
            return;
        }
        $this->success($row);
    }

    public function store(): void
    {
        $this->requireAuth();
        $data = $this->jsonInput();
        if (empty($data['name'])) {
            $this->error('Name required', 422);
            return;
        }

        // sanitize/normalize minimal fields
        $payload = [
            'name' => trim($data['name']),
            'icon' => $data['icon'] ?? null,
            'created_at' => $data['created_at'] ?? date('Y-m-d H:i:s')
        ];

        try {
            $id = $this->model->create($payload);
            $record = $this->model->find($id);
            $this->success($record, 'Created', 201);
        } catch (PDOException $e) {
            // log error server-side and show friendly message
            error_log('Wing create error: ' . $e->getMessage());
            $this->error('Database error while creating wing', 500);
        }
    }

    public function update($id): void
    {
        $this->requireAuth();
        $id = (int)$id;
        $data = $this->jsonInput();

        $existing = $this->model->find($id);
        if (!$existing) {
            $this->error('Not found', 404);
            return;
        }

        $payload = [];
        if (isset($data['name'])) $payload['name'] = trim($data['name']);
        if (array_key_exists('icon', $data)) $payload['icon'] = $data['icon'];
        $payload['updated_at'] = date('Y-m-d H:i:s');

        try {
            $ok = $this->model->update($id, $payload);
            if (!$ok) {
                $this->error('Update failed', 500);
                return;
            }
            $this->success($this->model->find($id), 'Updated');
        } catch (PDOException $e) {
            error_log('Wing update error: ' . $e->getMessage());
            $this->error('Database error while updating wing', 500);
        }
    }

    public function destroy($id): void
    {
        $this->requireAuth();
        $id = (int)$id;

        $existing = $this->model->find($id);
        if (!$existing) {
            $this->error('Not found', 404);
            return;
        }

        try {
            $ok = $this->model->deleteById($id);
            if (!$ok) {
                $this->error('Delete failed', 500);
                return;
            }

            // Return 204 No Content semantics — some clients expect no body.
            // Your success helper may return JSON; here we send a minimal success.
            $this->success(null, 'Deleted', 204);
        } catch (PDOException $e) {
            // Most common reason: FK constraint prevents delete.
            error_log('Wing delete error: ' . $e->getMessage());
            // If it's a foreign key constraint, return 409 Conflict with message.
            if (stripos($e->getMessage(), 'foreign key') !== false ||
                stripos($e->getMessage(), 'constraint') !== false) {
                $this->error('Cannot delete wing: related records exist (subwings/users). Remove or reassign them first.', 409);
            } else {
                $this->error('Database error while deleting wing', 500);
            }
        }
    }
}
