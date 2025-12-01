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
        $rows = $this->model->getActive(100, 0);
        $this->success($rows);
    }

    public function show($id): void
    {
        $this->requireAuth();
        $row = $this->model->find((int)$id);
        if (!$row) $this->error('Not found', 404);
        $this->success($row);
    }

    /**
     * Create a new notice
     * Supports: file upload or URL input
     */
    public function store(): void
    {
        $user = $this->requireAuth();

        // If content type is JSON (for URL uploads)
        if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
            $data = $this->jsonInput();
        } else {
            // For form-data (for file upload)
            $data = $_POST;
        }

        if (empty($data['title'])) $this->error('title required', 422);

        $fileUrl = null;

        // ✅ File upload handling
        if (!empty($_FILES['file']['name'])) {
            $uploadDir = __DIR__ . '/../../public/uploads/notices/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

            $filename = time() . '_' . basename($_FILES['file']['name']);
            $targetFile = $uploadDir . $filename;

            if (move_uploaded_file($_FILES['file']['tmp_name'], $targetFile)) {
                $fileUrl = '/uploads/notices/' . $filename;
            } else {
                $this->error('File upload failed', 500);
            }
        }
        // ✅ If no file but URL provided
        elseif (!empty($data['file_url'])) {
            $fileUrl = $data['file_url'];
        }

        $data['user_id'] = $user['id'];
        $data['file'] = $fileUrl; // save either uploaded file path or URL

        $id = $this->model->createNotice($data);
        $this->success($this->model->find($id), 'Created', 201);
    }

    /**
     * Update notice (can change file or URL)
     */
    public function update($id): void
    {
        $this->requireAuth();

        if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
            $data = $this->jsonInput();
        } else {
            $data = $_POST;
        }

        $row = $this->model->find((int)$id);
        if (!$row) $this->error('Not found', 404);

        $fileUrl = $row['file'] ?? null;

        if (!empty($_FILES['file']['name'])) {
            $uploadDir = __DIR__ . '/../../public/uploads/notices/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

            $filename = time() . '_' . basename($_FILES['file']['name']);
            $targetFile = $uploadDir . $filename;

            if (move_uploaded_file($_FILES['file']['tmp_name'], $targetFile)) {
                $fileUrl = '/uploads/notices/' . $filename;
            } else {
                $this->error('File upload failed', 500);
            }
        } elseif (!empty($data['file_url'])) {
            $fileUrl = $data['file_url'];
        }

        $data['file'] = $fileUrl;

        $ok = $this->model->updateNotice((int)$id, $data);
        if (!$ok) $this->error('Update failed', 500);

        $this->success($this->model->find((int)$id), 'Updated');
    }

    public function destroy($id): void
    {
        $this->requireAuth();
        $id = (int)$id;
        $row = $this->model->find($id);
        if (!$row) $this->error('Not found', 404);

        $ok = $this->model->delete($id);
        if (!$ok) $this->error('Delete failed', 500);

        $this->success(null, 'Deleted', 200);
    }
}
