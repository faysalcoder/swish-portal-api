<?php
namespace App\Controllers;

use App\Models\Wing;
use PDOException;

class WingsController extends BaseController
{
    protected Wing $model;

    protected array $allowedMimes = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/svg+xml' => 'svg',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];

    protected int $maxFileSize = 2 * 1024 * 1024; // 2 MB
    protected string $uploadRelativeDir = 'uploads/wings';

    public function __construct()
    {
        parent::__construct();
        $this->model = new Wing();
    }

    public function index(): void
    {
        $this->requireAuth();
        $rows = $this->model->all(1000, 0, ['id' => 'ASC']);
        $rows = array_map([$this, 'normalizeIconUrlForRecord'], $rows);
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
        $row = $this->normalizeIconUrlForRecord($row);
        $this->success($row);
    }

    public function store(): void
    {
        $this->requireAuth();

        $data = $this->jsonInput();

        // Prefer form POST name if present
        $name = isset($_POST['name']) ? trim($_POST['name']) : (isset($data['name']) ? trim($data['name']) : null);

        if (empty($name)) {
            $this->error('Name required', 422);
            return;
        }

        $iconDbValue = null;

        // If file was uploaded, process and save relative path
        if (isset($_FILES['icon_file']) && $_FILES['icon_file']['error'] === UPLOAD_ERR_OK) {
            $result = $this->handleFileUpload($_FILES['icon_file']);
            if ($result['ok'] === false) {
                $this->error($result['message'], 422);
                return;
            }
            $iconDbValue = $result['path'];
        }

        $payload = [
            'name' => $name,
            'icon' => $iconDbValue,
            'created_at' => $data['created_at'] ?? date('Y-m-d H:i:s'),
        ];

        try {
            $id = $this->model->create($payload);
            $record = $this->model->find($id);
            $record = $this->normalizeIconUrlForRecord($record);
            $this->success($record, 'Created', 201);
        } catch (PDOException $e) {
            error_log('Wing create error: ' . $e->getMessage());
            if (isset($result) && !empty($iconDbValue)) {
                $this->deleteLocalFileFromUrl($iconDbValue);
            }
            $this->error('Database error while creating wing', 500);
        }
    }

    public function update($id): void
    {
        $this->requireAuth();
        $id = (int)$id;

        $existing = $this->model->find($id);
        if (!$existing) {
            $this->error('Not found', 404);
            return;
        }

        $fileUploaded = isset($_FILES['icon_file']) && $_FILES['icon_file']['error'] === UPLOAD_ERR_OK;
        $data = $this->jsonInput();

        $payload = [];
        if (isset($_POST['name'])) {
            $payload['name'] = trim($_POST['name']);
        } elseif (isset($data['name'])) {
            $payload['name'] = trim($data['name']);
        }

        $oldIconValue = $existing['icon'] ?? null;
        $newIconDbValue = null;

        if ($fileUploaded) {
            $result = $this->handleFileUpload($_FILES['icon_file']);
            if ($result['ok'] === false) {
                $this->error($result['message'], 422);
                return;
            }
            $newIconDbValue = $result['path'];
            $payload['icon'] = $newIconDbValue;
        } elseif (array_key_exists('icon', $data) && $data['icon'] === null) {
            // explicit request to remove icon
            $payload['icon'] = null;
            $newIconDbValue = null;
        }
        // else: no icon change

        $payload['updated_at'] = date('Y-m-d H:i:s');

        try {
            $ok = $this->model->update($id, $payload);
            if (!$ok) {
                if ($fileUploaded && !empty($newIconDbValue)) $this->deleteLocalFileFromUrl($newIconDbValue);
                $this->error('Update failed', 500);
                return;
            }

            // If we replaced a local file with a new file, delete the old local file
            if ($newIconDbValue !== null && $this->isLocalIcon($oldIconValue) && $oldIconValue !== $newIconDbValue) {
                $this->deleteLocalFileFromUrl($oldIconValue);
            } elseif (array_key_exists('icon', $payload) && $payload['icon'] === null && $this->isLocalIcon($oldIconValue)) {
                // explicitly removed icon -> delete old local file
                $this->deleteLocalFileFromUrl($oldIconValue);
            }

            $this->success($this->normalizeIconUrlForRecord($this->model->find($id)), 'Updated');
        } catch (PDOException $e) {
            error_log('Wing update error: ' . $e->getMessage());
            if ($fileUploaded && !empty($newIconDbValue)) $this->deleteLocalFileFromUrl($newIconDbValue);
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

            $icon = $existing['icon'] ?? null;
            if ($this->isLocalIcon($icon)) {
                $this->deleteLocalFileFromUrl($icon);
            }

            $this->success(null, 'Deleted', 204);
        } catch (PDOException $e) {
            error_log('Wing delete error: ' . $e->getMessage());
            if (stripos($e->getMessage(), 'foreign key') !== false || stripos($e->getMessage(), 'constraint') !== false) {
                $this->error('Cannot delete wing: related records exist (subwings/users). Remove or reassign them first.', 409);
            } else {
                $this->error('Database error while deleting wing', 500);
            }
        }
    }

    /* --------------------- helpers --------------------- */

    protected function handleFileUpload(array $file): array
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'File upload error.'];
        }

        if (!isset($file['size']) || (int)$file['size'] > $this->maxFileSize) {
            return ['ok' => false, 'message' => 'File too large. Maximum allowed is ' . ($this->maxFileSize / 1024 / 1024) . ' MB.'];
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'message' => 'Invalid uploaded file.'];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (empty($mime) || !array_key_exists($mime, $this->allowedMimes)) {
            return ['ok' => false, 'message' => 'Unsupported file type. Allowed: png, jpg, webp, gif, svg, ico.'];
        }

        $ext = $this->allowedMimes[$mime];
        $this->ensureUploadDirectoryExists();

        try {
            $filename = sprintf('wing_%s.%s', bin2hex(random_bytes(8)), $ext);
        } catch (\Exception $e) {
            $filename = sprintf('wing_%s.%s', uniqid(), $ext);
        }

        $destDir = $this->getUploadFullPath();
        $destinationPath = rtrim($destDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destinationPath)) {
            error_log('Failed to move uploaded file to ' . $destinationPath);
            return ['ok' => false, 'message' => 'Failed saving uploaded file.'];
        }

        @chmod($destinationPath, 0644);

        $relativePath = trim($this->uploadRelativeDir, '/') . '/' . $filename;
        $url = $this->getBaseUrl() . '/' . $relativePath;

        return ['ok' => true, 'path' => $relativePath, 'url' => $url, 'filename' => $filename];
    }

    protected function ensureUploadDirectoryExists(): void
    {
        $dir = $this->getUploadFullPath();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    protected function getUploadFullPath(): string
    {
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $publicRoot = rtrim(realpath($_SERVER['DOCUMENT_ROOT']), DIRECTORY_SEPARATOR);
            if ($publicRoot !== false) {
                return $publicRoot . DIRECTORY_SEPARATOR . trim($this->uploadRelativeDir, '/');
            }
        }

        $projectRoot = realpath(__DIR__ . '/../../');
        $publicDirCandidates = [
            $projectRoot . DIRECTORY_SEPARATOR . 'public',
            $projectRoot,
        ];
        foreach ($publicDirCandidates as $candidate) {
            if ($candidate && is_dir($candidate)) {
                return rtrim($candidate, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . trim($this->uploadRelativeDir, '/');
            }
        }

        return rtrim(__DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . trim($this->uploadRelativeDir, '/');
    }

    protected function getBaseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        return $scheme . '://' . $host;
    }

    protected function normalizeIconUrlForRecord($row)
    {
        if (is_array($row)) {
            if (!empty($row['icon']) && !$this->isAbsoluteUrl($row['icon'])) {
                if ($this->isRelativeUploadPath($row['icon'])) {
                    $row['icon'] = $this->getBaseUrl() . '/' . ltrim($row['icon'], '/');
                }
            }
            return $row;
        } elseif (is_object($row)) {
            if (!empty($row->icon) && !$this->isAbsoluteUrl($row->icon)) {
                if ($this->isRelativeUploadPath($row->icon)) {
                    $row->icon = $this->getBaseUrl() . '/' . ltrim($row->icon, '/');
                }
            }
            return $row;
        }
        return $row;
    }

    protected function isAbsoluteUrl(string $s): bool
    {
        return (bool)preg_match('#^https?://#i', $s);
    }

    protected function isLocalIcon(?string $icon): bool
    {
        if (empty($icon)) return false;
        $base = $this->getBaseUrl() . '/';
        if (stripos($icon, $base) === 0 && strpos($icon, '/' . trim($this->uploadRelativeDir, '/') . '/') !== false) {
            return true;
        }
        if ($this->isRelativeUploadPath($icon)) {
            return true;
        }
        return false;
    }

    protected function isRelativeUploadPath(string $path): bool
    {
        $normalized = ltrim($path, '/');
        return strpos($normalized, trim($this->uploadRelativeDir, '/') . '/') === 0;
    }

    protected function deleteLocalFileFromUrl(string $url): bool
    {
        if (empty($url)) return true;
        $base = $this->getBaseUrl() . '/';
        if (stripos($url, $base) === 0) {
            $relative = ltrim(substr($url, strlen($base)), '/');
        } else {
            if (preg_match('#^https?://#i', $url) && stripos($url, $base) !== 0) {
                return false;
            }
            $relative = ltrim($url, '/');
        }

        $fullPath = rtrim($this->getUploadFullPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($relative);

        if (file_exists($fullPath)) {
            return @unlink($fullPath);
        }

        $filename = basename($relative);
        $dir = rtrim($this->getUploadFullPath(), DIRECTORY_SEPARATOR);
        $candidate = $dir . DIRECTORY_SEPARATOR . $filename;
        if (file_exists($candidate)) {
            return @unlink($candidate);
        }

        return true;
    }
}
