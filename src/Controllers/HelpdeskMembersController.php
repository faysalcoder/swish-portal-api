<?php
namespace App\Controllers;

use App\Models\HelpdeskMember;
use App\Models\User;
use Throwable;

class HelpdeskMembersController extends BaseController
{
    protected HelpdeskMember $model;
    // Keep same nullable signature as BaseController to avoid property conflicts
    protected ?User $userModel = null;

    public function __construct()
    {
        parent::__construct();
        $this->model = new HelpdeskMember();

        // BaseController may have already set $this->userModel; ensure it's available
        if ($this->userModel === null) {
            $this->userModel = new User();
        }
    }

    /**
     * GET /api/v1/helpdesk/members
     * Optional query params: limit, offset, category
     */
    public function index(): void
    {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

        if (!empty($_GET['category'])) {
            $members = $this->model->listByCategory($_GET['category'], $limit, $offset);
        } else {
            $members = $this->model->all($limit, $offset, ['created_at' => 'DESC']);
        }

        // BaseController::success signature: success($data = null, string $message = '', int $status = 200)
        $this->success(['data' => $members], 'ok', 200);
    }

    /**
     * POST /api/v1/helpdesk/members
     * Body: { "user_id": 1, "category": "network" }
     */
    public function store(): void
    {
        // require auth for create
        $this->requireAuth();

        $data = $this->jsonInput();

        $userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
        $category = isset($data['category']) ? trim($data['category']) : '';

        if ($userId <= 0 || $category === '') {
            $this->error('Validation failed: user_id and category are required', 422);
        }

        // ensure user exists
        $user = $this->userModel->find($userId);
        if (!$user) {
            $this->error('User not found', 404);
        }

        // optional: prevent duplicate user entries (one entry per user)
        $existing = $this->model->findByUserId($userId);
        if ($existing) {
            $this->error('Helpdesk member already exists for this user', 409);
        }

        try {
            $id = $this->model->create([
                'user_id' => $userId,
                'category' => $category
            ]);
            $created = $this->model->find($id);
            $this->success(['data' => $created], 'Helpdesk member created', 201);
        } catch (Throwable $e) {
            $this->error('Failed to create helpdesk member: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v1/helpdesk/members/{id}
     */
    public function show($id): void
    {
        $id = (int)$id;
        $row = $this->model->find($id);
        if (!$row) {
            $this->error('Not found', 404);
        }
        $this->success(['data' => $row], 'ok', 200);
    }

    /**
     * PUT /api/v1/helpdesk/members/{id}
     * Body: { "user_id": 1, "category": "new" }
     */
    public function update($id): void
    {
        $this->requireAuth();

        $id = (int)$id;
        $existing = $this->model->find($id);
        if (!$existing) {
            $this->error('Not found', 404);
        }

        $data = $this->jsonInput();
        $payload = [];

        if (isset($data['user_id'])) {
            $uid = (int)$data['user_id'];
            if ($uid <= 0) $this->error('Invalid user_id', 422);
            $user = $this->userModel->find($uid);
            if (!$user) $this->error('User not found', 404);
            $payload['user_id'] = $uid;
        }

        if (isset($data['category'])) {
            $cat = trim($data['category']);
            if ($cat === '') $this->error('Invalid category', 422);
            $payload['category'] = $cat;
        }

        if (empty($payload)) {
            $this->error('No updatable fields provided', 422);
        }

        try {
            $ok = $this->model->update($id, $payload);
            if (!$ok) {
                $this->error('Update failed', 500);
            }
            $row = $this->model->find($id);
            $this->success(['data' => $row], 'Updated', 200);
        } catch (Throwable $e) {
            $this->error('Failed to update: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/v1/helpdesk/members/{id}
     */
    public function destroy($id): void
    {
        $this->requireAuth();

        $id = (int)$id;
        $existing = $this->model->find($id);
        if (!$existing) {
            $this->error('Not found', 404);
        }

        try {
            $ok = $this->model->delete($id);
            if (!$ok) {
                $this->error('Delete failed', 500);
            }
            $this->success(null, 'Deleted', 200);
        } catch (Throwable $e) {
            $this->error('Failed to delete: ' . $e->getMessage(), 500);
        }
    }
}
