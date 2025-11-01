<?php
namespace App\Controllers;

use App\Models\SopFile;

class SopFilesController extends BaseController
{
    protected SopFile $model;
    protected string $uploadDir;

    public function __construct()
    {
        parent::__construct();
        $this->model = new SopFile();
        $this->uploadDir = $_ENV['UPLOAD_DIR'] ?? __DIR__ . '/../../storage/uploads';
        if (!is_dir($this->uploadDir)) @mkdir($this->uploadDir, 0755, true);
    }

    // POST /api/v1/sops/{id}/files  (multipart/form-data: file)
    public function upload($sopId): void
    {
        $user = $this->requireAuth();

        if (!isset($_FILES['file'])) $this->error('File is required', 422);
        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) $this->error('Upload failed', 400);

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $basename = bin2hex(random_bytes(16)) . ($ext ? '.' . $ext : '');
        $dest = rtrim($this->uploadDir, '/') . '/' . $basename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) $this->error('Move failed', 500);

        $title = $_POST['title'] ?? $file['name'];
        $id = $this->model->create([
            'title' => $title,
            'file_url' => $dest,
            'sop_id' => (int)$sopId
        ]);
        $this->success($this->model->find($id), 'Uploaded', 201);
    }

    // GET /api/v1/sop-files/{id}
    public function download($id): void
    {
        $this->requireAuth();
        $row = $this->model->find((int)$id);
        if (!$row) $this->error('Not found', 404);

        $file = $row['file_url'] ?? null;
        if (!$file) $this->error('No file', 404);

        // if file is a URL, redirect
        if (filter_var($file, FILTER_VALIDATE_URL)) {
            header('Location: ' . $file);
            exit;
        }

        if (!file_exists($file)) $this->error('File missing', 404);
        $mime = mime_content_type($file) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        readfile($file);
        exit;
    }

    public function listBySop($sopId): void
    {
        $this->requireAuth();
        $rows = $this->model->bySop((int)$sopId);
        $this->success($rows);
    }

    public function destroy($id): void
    {
        $this->requireAuth();
        $row = $this->model->find((int)$id);
        if (!$row) $this->error('Not found', 404);
        // delete physical file if exists
        $file = $row['file_url'] ?? null;
        if ($file && file_exists($file)) @unlink($file);
        $ok = $this->model->delete((int)$id);
        if (!$ok) $this->error('Delete failed', 500);
        $this->success(null, 'Deleted', 204);
    }
}
