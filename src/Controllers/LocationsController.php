<?php
namespace App\Controllers;

use App\Models\Location;
use PDOException;

class LocationsController extends BaseController
{
    protected Location $model;

    // Use DB enum values here
    protected array $allowedTypes = ['office', 'showroom', 'franchise', 'other'];
    protected array $allowedStatuses = ['active', 'close', 'permanentlyclose'];

    public function __construct()
    {
        parent::__construct();
        $this->model = new Location();
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
        $this->requireAuth();
        $data = $this->jsonInput();

        if (empty($data['country'])) $this->error('country required', 422);
        if (empty($data['address'])) $this->error('address required', 422);

        if (isset($data['type']) && !in_array($data['type'], $this->allowedTypes, true)) {
            $this->error('Invalid type', 422);
        }
        if (isset($data['status']) && !in_array($data['status'], $this->allowedStatuses, true)) {
            $this->error('Invalid status', 422);
        }

        try {
            $id = $this->model->create([
                'country' => $data['country'],
                'address' => $data['address'],
                'type'    => $data['type'] ?? null,
                'status'  => $data['status'] ?? null,
            ]);
        } catch (PDOException $e) {
            $this->error('Create failed: ' . $e->getMessage(), 500);
        }

        $this->success($this->model->find($id), 'Created', 201);
    }

    public function update($id): void
    {
        // safe PUT: merge incoming with existing record then update
        $this->requireAuth();
        $id = (int)$id;
        $incoming = $this->jsonInput();

        $existing = $this->model->find($id);
        if (!$existing) $this->error('Not found', 404);

        if (isset($incoming['type']) && !in_array($incoming['type'], $this->allowedTypes, true)) {
            $this->error('Invalid type', 422);
        }
        if (isset($incoming['status']) && !in_array($incoming['status'], $this->allowedStatuses, true)) {
            $this->error('Invalid status', 422);
        }

        $merged = [
            'country' => array_key_exists('country', $incoming) ? $incoming['country'] : ($existing['country'] ?? null),
            'address' => array_key_exists('address', $incoming) ? $incoming['address'] : ($existing['address'] ?? null),
            'type'    => array_key_exists('type', $incoming) ? $incoming['type'] : ($existing['type'] ?? null),
            'status'  => array_key_exists('status', $incoming) ? $incoming['status'] : ($existing['status'] ?? null),
        ];

        if (empty($merged['country'])) $this->error('country required', 422);
        if (empty($merged['address'])) $this->error('address required', 422);

        try {
            $ok = $this->model->update($id, $merged);
        } catch (PDOException $e) {
            $this->error('Update failed: ' . $e->getMessage(), 500);
        }

        if (!$ok) $this->error('Update failed', 500);
        $this->success($this->model->find($id), 'Updated');
    }

    /**
     * PATCH /api/v1/locations/{id}/status
     * Update only the status field to avoid accidental replacement of other fields.
     */
    public function updateStatus($id): void
    {
        $this->requireAuth();
        $id = (int)$id;
        $data = $this->jsonInput();

        if (!isset($data['status'])) {
            $this->error('status required', 422);
        }

        $status = $data['status'];
        if (!in_array($status, $this->allowedStatuses, true)) {
            $this->error('Invalid status', 422);
        }

        $existing = $this->model->find($id);
        if (!$existing) $this->error('Not found', 404);

        try {
            $ok = $this->model->update($id, ['status' => $status]);
        } catch (PDOException $e) {
            $this->error('Update status failed: ' . $e->getMessage(), 500);
        }

        if (!$ok) $this->error('Update status failed', 500);
        $this->success($this->model->find($id), 'Status updated');
    }

    public function destroy($id): void
    {
        $this->requireAuth();
        $ok = $this->model->delete((int)$id);
        if (!$ok) $this->error('Delete failed', 500);
        $this->success(null, 'Deleted', 204);
    }
}
