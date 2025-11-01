<?php
namespace App\Controllers;

use App\Models\User;
use App\Exceptions\ValidationException;
use App\Validators\UserValidator;

class UsersController extends BaseController
{
    // NOTE: Do NOT redeclare $userModel here — it's defined in BaseController as ?User
    // The parent constructor already creates $this->userModel = new User();

    public function __construct()
    {
        parent::__construct();
        // parent constructed $this->userModel
    }

    /**
     * GET /api/v1/users
     */
    public function index(): void
    {
        try {
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

            // optional filters (simple)
            $conditions = [];
            if (isset($_GET['wing_id'])) $conditions['wing_id'] = (int)$_GET['wing_id'];
            if (isset($_GET['subw_id'])) $conditions['subw_id'] = (int)$_GET['subw_id'];
            if (isset($_GET['role'])) $conditions['role'] = (int)$_GET['role'];
            if (isset($_GET['status'])) $conditions['status'] = $_GET['status'];

            if (!empty($conditions)) {
                $rows = $this->userModel->where($conditions, $limit, $offset, ['id' => 'DESC']);
            } else {
                $rows = $this->userModel->all($limit, $offset, ['id' => 'DESC']);
            }

            // hide passwords
            foreach ($rows as &$r) {
                if (isset($r['password'])) unset($r['password']);
            }

            $this->success($rows);
        } catch (\Throwable $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v1/users
     */
    public function store(): void
    {
        $data = $this->jsonInput();
        try {
            // validate (throws ValidationException on failure)
            UserValidator::validateCreate($data);

            // hash password
            $data['password'] = password_hash($data['password'], PASSWORD_BCRYPT);

            // set defaults
            if (!isset($data['status'])) $data['status'] = 'active';
            if (!isset($data['role'])) $data['role'] = 2;

            $newId = $this->userModel->create($data);
            $user = $this->userModel->find((int)$newId);
            if ($user && isset($user['password'])) unset($user['password']);

            $this->success($user, 'User created', 201);
        } catch (ValidationException $ve) {
            $this->error($ve->getMessage(), 422, $ve->getErrors());
        } catch (\Throwable $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v1/users/{id}
     */
    public function show($id = null): void
    {
        $id = (int)$id;
        if ($id <= 0) $this->error('Invalid user id', 400);

        $user = $this->userModel->find($id);
        if (!$user) $this->error('User not found', 404);

        if (isset($user['password'])) unset($user['password']);
        $this->success($user);
    }

    /**
     * PUT /api/v1/users/{id}
     */
    public function update($id = null): void
    {
        $id = (int)$id;
        if ($id <= 0) $this->error('Invalid user id', 400);

        $data = $this->jsonInput();
        try {
            UserValidator::validateUpdate($data);

            // if password present, hash it
            if (!empty($data['password'])) {
                $data['password'] = password_hash($data['password'], PASSWORD_BCRYPT);
            }

            $ok = $this->userModel->update($id, $data);
            if (!$ok) $this->error('Update failed', 500);

            $user = $this->userModel->find($id);
            if ($user && isset($user['password'])) unset($user['password']);
            $this->success($user, 'User updated');
        } catch (ValidationException $ve) {
            $this->error($ve->getMessage(), 422, $ve->getErrors());
        } catch (\Throwable $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/v1/users/{id}
     */
    public function destroy($id = null): void
    {
        $id = (int)$id;
        if ($id <= 0) $this->error('Invalid user id', 400);

        try {
            $exists = $this->userModel->find($id);
            if (!$exists) {
                $this->error('User not found', 404);
            }

            $ok = $this->userModel->delete($id);
            if (!$ok) $this->error('Delete failed', 500);

            $this->success(null, 'User deleted');
        } catch (\Throwable $e) {
            $this->error($e->getMessage(), 500);
        }
    }
}
