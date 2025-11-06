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
     *
     * Supports:
     *  - JSON body updates (via jsonInput())
     *  - JSON body containing profile_img_base64 + profile_img_name (decodes and saves file)
     *  - multipart/form-data file upload (when $_FILES['profile_img'] exists; works with POST multipart or method-override POST)
     */
    public function update($id = null): void
    {
        $id = (int)$id;
        if ($id <= 0) $this->error('Invalid user id', 400);

        // Read JSON input first
        $data = $this->jsonInput();

        // If JSON is empty and $_POST exists (multipart via POST), merge it
        if ((empty($data) || !is_array($data)) && !empty($_POST)) {
            $data = $_POST;
        }
        if (!is_array($data)) $data = [];

        try {
            // --------- 1) Handle base64 payload (JSON upload) ----------
            // Expect fields: profile_img_base64, profile_img_name
            if (!empty($data['profile_img_base64'])) {
                $b64 = $data['profile_img_base64'];
                $originalName = isset($data['profile_img_name']) ? basename($data['profile_img_name']) : null;

                // If data URI (data:<mime>;base64,xxxxx) -> extract mime and base64 part
                $mime = null;
                if (preg_match('/^data:(.*?);base64,/', $b64, $m)) {
                    $mime = trim($m[1]);
                    $b64 = substr($b64, strpos($b64, ',') + 1);
                }

                // basic sanity
                $b64 = str_replace(' ', '+', $b64);
                $decoded = base64_decode($b64, true);
                if ($decoded === false) {
                    $this->error('Invalid base64 image data', 422);
                    return;
                }

                // compute size (bytes)
                $sizeBytes = strlen($decoded);
                $maxBytes = 2 * 1024 * 1024;
                if ($sizeBytes > $maxBytes) {
                    $this->error('Image too large (max 2MB)', 422);
                    return;
                }

                // determine extension from mime or original filename
                $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                $ext = null;
                if ($mime && array_key_exists($mime, $allowed)) {
                    $ext = $allowed[$mime];
                } elseif ($originalName) {
                    $pExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                    if (in_array($pExt, ['jpg', 'jpeg', 'png', 'webp'])) {
                        $ext = $pExt === 'jpeg' ? 'jpg' : $pExt;
                        // set a mime guess
                        $mime = $mime ?? ($ext === 'jpg' ? 'image/jpeg' : 'image/' . $ext);
                    }
                }

                if (!$ext) {
                    $this->error('Unsupported image type. Use JPG, PNG or WEBP.', 422);
                    return;
                }

                // ensure upload dir exists
                $uploadDirRelative = '/uploads/profiles/';
                $uploadDir = rtrim($_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR) . $uploadDirRelative;
                if (!is_dir($uploadDir)) {
                    if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                        $this->error('Failed to create upload directory', 500);
                        return;
                    }
                }

                // create filename
                $filename = 'profile_' . $id . '_' . time() . '.' . $ext;
                $dest = $uploadDir . $filename;

                // write file
                if (file_put_contents($dest, $decoded) === false) {
                    $this->error('Failed to save uploaded image', 500);
                    return;
                }

                // build accessible URL
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                $imageUrl = $scheme . '://' . $host . $uploadDirRelative . $filename;

                // populate data so it gets saved to DB
                $data['profile_img'] = $imageUrl;

                // optionally unset the base64 keys so validator doesn't choke if not expected
                unset($data['profile_img_base64'], $data['profile_img_name']);
            }
            // --------- 2) Handle regular file upload via $_FILES (multipart/form-data) ----------
            elseif (!empty($_FILES['profile_img']) && $_FILES['profile_img']['error'] !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES['profile_img'];
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $this->error('File upload error code: ' . $file['error'], 500);
                    return;
                }

                // validate size
                $maxBytes = 2 * 1024 * 1024; // 2MB
                if ($file['size'] > $maxBytes) {
                    $this->error('Image too large (max 2MB)', 422);
                    return;
                }

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!array_key_exists($mime, $allowed)) {
                    $this->error('Unsupported image type. Use JPG, PNG or WEBP.', 422);
                    return;
                }

                $ext = $allowed[$mime];
                $uploadDirRelative = '/uploads/profiles/';
                $uploadDir = rtrim($_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR) . $uploadDirRelative;
                if (!is_dir($uploadDir)) {
                    if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                        $this->error('Failed to create upload directory', 500);
                        return;
                    }
                }

                $filename = 'profile_' . $id . '_' . time() . '.' . $ext;
                $dest = $uploadDir . $filename;

                if (!move_uploaded_file($file['tmp_name'], $dest)) {
                    $this->error('Failed to move uploaded file', 500);
                    return;
                }

                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                $imageUrl = $scheme . '://' . $host . $uploadDirRelative . $filename;

                $data['profile_img'] = $imageUrl;
            }

            // Validate data (validator should allow optional profile_img)
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
