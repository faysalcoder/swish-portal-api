<?php
namespace App\Controllers;

use App\Models\User;
use App\Exceptions\ValidationException;
use App\Validators\UserValidator;

class UsersController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
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
            // normalize incoming values (prevent empty-string FKs)
            foreach (['wing_id', 'subw_id', 'location_id'] as $k) {
                if (isset($data[$k])) {
                    if ($data[$k] === '' || $data[$k] === null) {
                        $data[$k] = null;
                    } else {
                        $data[$k] = (int)$data[$k];
                        if ($data[$k] <= 0) $data[$k] = null;
                    }
                }
            }

            // normalize status if present (accept "2" or 2 as blocked)
            if (isset($data['status'])) {
                $s = strtolower(trim((string)$data['status']));
                if (in_array($s, ['active','1','true','yes'], true)) $data['status'] = 'active';
                elseif (in_array($s, ['deactive','inactive','0','false','no'], true)) $data['status'] = 'deactive';
                elseif (in_array($s, ['block','blocked','2'], true)) $data['status'] = 'block';
                else $data['status'] = $s;
            }

            // ensure role is numeric and within expected range (0..3)
            if (isset($data['role'])) {
                $data['role'] = (int)$data['role'];
                if (!in_array($data['role'], [0,1,2,3,4], true)) $data['role'] = 2;
            }

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

        // Robust body parsing:
        // - prefer JSON body (php://input)
        // - fallback to $_POST when multipart/form-data (possibly with _method override)
        $raw = @file_get_contents('php://input');
        $json = null;
        if ($raw !== false && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $json = $decoded;
            }
        }

        // Start with JSON body if present, otherwise fallback to $_POST
        $data = is_array($json) ? $json : (is_array($_POST) ? $_POST : []);
        if (!is_array($data)) $data = [];

        // If client used _method override remove it from data to avoid persisting it
        if (isset($data['_method'])) unset($data['_method']);

        try {
            // --------- 1) Handle base64 payload (JSON upload) ----------
            if (!empty($data['profile_img_base64'])) {
                $b64 = $data['profile_img_base64'];
                $originalName = isset($data['profile_img_name']) ? basename($data['profile_img_name']) : null;

                if (preg_match('/^data:(.*?);base64,/', $b64, $m)) {
                    $mime = trim($m[1]);
                    $b64 = substr($b64, strpos($b64, ',') + 1);
                } else {
                    $mime = null;
                }

                $b64 = str_replace(' ', '+', $b64);
                $decoded = base64_decode($b64, true);
                if ($decoded === false) {
                    $this->error('Invalid base64 image data', 422);
                    return;
                }

                $sizeBytes = strlen($decoded);
                $maxBytes = 2 * 1024 * 1024;
                if ($sizeBytes > $maxBytes) {
                    $this->error('Image too large (max 2MB)', 422);
                    return;
                }

                $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                $ext = null;
                if ($mime && array_key_exists($mime, $allowed)) {
                    $ext = $allowed[$mime];
                } elseif ($originalName) {
                    $pExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                    if (in_array($pExt, ['jpg', 'jpeg', 'png', 'webp'])) {
                        $ext = $pExt === 'jpeg' ? 'jpg' : $pExt;
                        $mime = $mime ?? ($ext === 'jpg' ? 'image/jpeg' : 'image/' . $ext);
                    }
                }

                if (!$ext) {
                    $this->error('Unsupported image type. Use JPG, PNG or WEBP.', 422);
                    return;
                }

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

                if (file_put_contents($dest, $decoded) === false) {
                    $this->error('Failed to save uploaded image', 500);
                    return;
                }

                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                $imageUrl = $scheme . '://' . $host . $uploadDirRelative . $filename;

                $data['profile_img'] = $imageUrl;
                unset($data['profile_img_base64'], $data['profile_img_name']);
            }
            // --------- 2) Handle regular file upload via $_FILES (multipart/form-data) ----------
            elseif (!empty($_FILES['profile_img']) && $_FILES['profile_img']['error'] !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES['profile_img'];
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $this->error('File upload error code: ' . $file['error'], 500);
                    return;
                }

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

            // ---- Normalization: ensure empty FK fields become null, normalize status/role ----
            foreach (['wing_id', 'subw_id', 'location_id'] as $k) {
                if (isset($data[$k])) {
                    if ($data[$k] === '' || $data[$k] === null) {
                        $data[$k] = null;
                    } else {
                        $data[$k] = (int)$data[$k];
                        if ($data[$k] <= 0) $data[$k] = null;
                    }
                }
            }

            // Normalize status carefully: accept numeric 0/1/2 or strings "0"/"1"/"2"
            if (isset($data['status'])) {
                // cast to string then trim/lower
                $s = strtolower(trim((string)$data['status']));
                if (in_array($s, ['active','1','true','yes'], true)) {
                    $data['status'] = 'active';
                } elseif (in_array($s, ['deactive','inactive','0','false','no'], true)) {
                    $data['status'] = 'deactive';
                } elseif (in_array($s, ['block','blocked','2'], true)) {
                    $data['status'] = 'block';
                } else {
                    // keep arbitrary string but trimmed
                    $data['status'] = $s;
                }
            }

            if (isset($data['role'])) {
                $data['role'] = (int)$data['role'];
                if (!in_array($data['role'], [0,1,2,3,4], true)) $data['role'] = 2;
            }

            // Validate data (validator should allow optional profile_img and these statuses/role)
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
