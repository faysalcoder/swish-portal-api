<?php
namespace App\Controllers;

use App\Models\RaciRole;

class RaciRolesController extends BaseController
{
    protected RaciRole $model;

    public function __construct()
    {
        parent::__construct();
        $this->model = new RaciRole(); // if you have DI for PDO, pass it here: new RaciRole($this->db)
    }

    /**
     * GET /api/v1/raci-roles
     * List all roles (paginated).
     */
    public function index(): void
    {
        $this->requireAuth();

        try {
            $rows = $this->model->all(1000, 0, ['id' => 'ASC']);
            $this->success($rows);
        } catch (\Throwable $e) {
            $this->error('Failed to load roles', 500);
        }
    }

    /**
     * GET /api/v1/raci/{id}/roles
     * Return only roles for the requested RACI id.
     */
    public function byRaci($raciId): void
    {
        $this->requireAuth();

        if (!is_numeric($raciId)) {
            $this->error('Invalid raci id', 422);
            return;
        }

        $raci_id = (int)$raciId;

        try {
            $rows = $this->model->byRaci($raci_id);
            // ensure we return array (model::byRaci should already return array)
            if ($rows === null) $rows = [];
            $this->success($rows);
        } catch (\Throwable $e) {
            $this->error('Server error while fetching roles', 500);
        }
    }

    /**
     * POST /api/v1/raci/roles
     * Body: { raci_id, role, user_id }
     */
    public function store(): void
    {
        $this->requireAuth();

        $data = $this->jsonInput();
        if (!is_array($data)) {
            $this->error('Invalid request body', 400);
            return;
        }

        // Required fields
        foreach (['raci_id', 'role', 'user_id'] as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                $this->error("Missing field: {$field}", 422);
                return;
            }
        }

        // normalize and validate
        if (!is_numeric($data['raci_id']) || !is_numeric($data['user_id'])) {
            $this->error('raci_id and user_id must be numeric', 422);
            return;
        }

        $raci_id = (int)$data['raci_id'];
        $user_id = (int)$data['user_id'];
        $role = strtoupper(substr(trim((string)$data['role']), 0, 1)); // R/A/C/I

        if (!in_array($role, ['R','A','C','I'])) {
            $this->error('Invalid role value (expect R/A/C/I)', 422);
            return;
        }

        $payload = [
            'raci_id' => $raci_id,
            'role' => $role,
            'user_id' => $user_id,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        try {
            // createUnique returns existing id if duplicate, or inserted id
            $id = $this->model->createUnique($payload);
            if (!$id) {
                $this->error('Failed to create role', 500);
                return;
            }

            $created = $this->model->find((int)$id);
            $this->success($created, 'Created', 201);
        } catch (\Throwable $e) {
            $this->error('Server error while creating role', 500);
        }
    }

    /**
     * DELETE /api/v1/raci/roles/{id}
     */
    public function destroy($id): void
    {
        $this->requireAuth();

        if (!is_numeric($id)) {
            $this->error('Invalid id', 422);
            return;
        }

        try {
            $ok = $this->model->delete((int)$id);
            if (!$ok) {
                $this->error('Delete failed', 500);
                return;
            }

            // 204 No Content is semantically correct; your success helper outputs status too
            $this->success(null, 'Deleted', 204);
        } catch (\Throwable $e) {
            $this->error('Server error while deleting', 500);
        }
    }
}
