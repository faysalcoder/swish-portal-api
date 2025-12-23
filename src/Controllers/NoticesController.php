<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Notice;

/**
 * Namespaced controller — primary implementation.
 */
class NoticesController extends BaseController
{
    protected Notice $model;

    public function __construct()
    {
        parent::__construct();
        $this->model = new Notice();
    }

    /**
     * GET /api/v1/notices
     * Return ALL active notices (no pagination). Unknown query params are ignored.
     */
    public function index(): void
    {
        try {
            $this->requireAuth();
            $rows = $this->model->getAllActive();
            $this->success($rows);
        } catch (\Throwable $e) {
            error_log('NoticesController@index error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $this->error('Server error', 500);
        }
    }

    /**
     * GET /api/v1/notices/{id}
     */
    public function show($id): void
    {
        try {
            $this->requireAuth();
            $row = $this->model->find((int)$id);
            if (!$row) {
                $this->error('Not found', 404);
            }
            $this->success($row);
        } catch (\Throwable $e) {
            error_log('NoticesController@show error: ' . $e->getMessage());
            $this->error('Server error', 500);
        }
    }

    /**
     * POST /api/v1/notices
     * Accepts multipart (file upload) OR JSON body with file_url for external URL notices.
     */
    public function store(): void
    {
        try {
            $user = $this->requireAuth();

            // Read request body: JSON or form-data
            if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
                $data = $this->jsonInput();
            } else {
                $data = $_POST;
            }

            // Basic validations
            if (empty($data['title'])) $this->error('title required', 422);
            if (empty($data['notice_type'])) $this->error('notice_type required (file or url)', 422);

            if ($data['notice_type'] === 'file' && empty($_FILES['file']['name'])) {
                $this->error('File is required for notice_type "file"', 422);
            }

            if ($data['notice_type'] === 'url' && empty($data['file_url'])) {
                $this->error('file_url is required for notice_type "url"', 422);
            }

            $fileUrl = null;

            // Handle file upload if provided
            if (!empty($_FILES['file']['name'])) {
                // Limits & allowed types
                $maxFileSize = 10 * 1024 * 1024; // 10 MB
                if ($_FILES['file']['size'] > $maxFileSize) {
                    $this->error('File size exceeds 10MB limit', 422);
                }

                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($_FILES['file']['tmp_name'] ?? '');
                $allowedMimes = [
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'image/jpeg',
                    'image/png'
                ];
                if (!in_array($mime, $allowedMimes, true)) {
                    $this->error('Invalid file type. Allowed: PDF, DOC/DOCX, JPEG, PNG', 422);
                }

                $fileExt = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
                $allowedExt = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
                if (!in_array($fileExt, $allowedExt, true)) {
                    $this->error('Invalid file extension', 422);
                }

                // Prepare upload directory
                $publicRoot = realpath(__DIR__ . '/../../public') ?: (__DIR__ . '/../../public');
                $uploadDir = rtrim($publicRoot, '/') . '/uploads/notices/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                // Generate safe filename
                $filename = time() . '_' . bin2hex(random_bytes(6)) . '.' . $fileExt;
                $targetFile = $uploadDir . $filename;

                if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetFile)) {
                    $this->error('File upload failed', 500);
                }

                $fileUrl = '/uploads/notices/' . $filename;
                $data['notice_type'] = 'file';
            }
            // Handle external URL if provided
            elseif (!empty($data['file_url'])) {
                if (!filter_var($data['file_url'], FILTER_VALIDATE_URL)) {
                    $this->error('Invalid URL format', 422);
                }
                $fileUrl = $data['file_url'];
                $data['notice_type'] = 'url';
            }

            $noticeData = [
                'title' => trim($data['title']),
                'notice_type' => $data['notice_type'],
                'notice_note' => $data['notice_note'] ?? null,
                'file_url' => $fileUrl,
                'valid_till' => !empty($data['valid_till']) ? $data['valid_till'] : null,
                'user_id' => $user['id'],
            ];

            $id = $this->model->createNotice($noticeData);
            $this->success($this->model->find($id), 'Created', 201);
        } catch (\Throwable $e) {
            error_log('NoticesController@store error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $this->error('Server error', 500);
        }
    }

    /**
     * PUT /api/v1/notices/{id}
     * Also supports POST + _method=PUT when using multipart/form-data for file uploads.
     */
    public function update($id): void
    {
        try {
            $user = $this->requireAuth();

            // Read input (JSON or form-data)
            if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
                $data = $this->jsonInput();
            } else {
                $data = $_POST;
            }

            $row = $this->model->find((int)$id);
            if (!$row) $this->error('Not found', 404);

            // Authorization: owner or admin
            if ($row['user_id'] != $user['id'] && empty($user['is_admin'])) {
                $this->error('Unauthorized to update this notice', 403);
            }

            $fileUrl = $row['file_url'] ?? null;

            // If new file uploaded -> validate and replace
            if (!empty($_FILES['file']['name'])) {
                $maxFileSize = 10 * 1024 * 1024; // 10 MB
                if ($_FILES['file']['size'] > $maxFileSize) {
                    $this->error('File size exceeds 10MB limit', 422);
                }

                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($_FILES['file']['tmp_name'] ?? '');
                $allowedMimes = [
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'image/jpeg',
                    'image/png'
                ];
                if (!in_array($mime, $allowedMimes, true)) {
                    $this->error('Invalid file type. Allowed: PDF, DOC/DOCX, JPEG, PNG', 422);
                }

                $fileExt = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
                $allowedExt = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
                if (!in_array($fileExt, $allowedExt, true)) {
                    $this->error('Invalid file extension', 422);
                }

                $publicRoot = realpath(__DIR__ . '/../../public') ?: (__DIR__ . '/../../public');
                $uploadDir = rtrim($publicRoot, '/') . '/uploads/notices/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

                // Delete old local file if present
                if (!empty($row['file_url']) && str_starts_with($row['file_url'], '/uploads/notices/')) {
                    $oldPath = $publicRoot . $row['file_url'];
                    if ($oldPath && file_exists($oldPath)) {
                        @unlink($oldPath);
                    }
                }

                $filename = time() . '_' . bin2hex(random_bytes(6)) . '.' . $fileExt;
                $targetFile = $uploadDir . $filename;

                if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetFile)) {
                    $this->error('File upload failed', 500);
                }

                $fileUrl = '/uploads/notices/' . $filename;
                $data['notice_type'] = 'file';
            }
            // If client explicitly provided file_url in payload (update or clear)
            elseif (array_key_exists('file_url', $data)) {
                if (!empty($data['file_url'])) {
                    if (!filter_var($data['file_url'], FILTER_VALIDATE_URL)) {
                        $this->error('Invalid URL format', 422);
                    }
                    $fileUrl = $data['file_url'];
                    $data['notice_type'] = 'url';
                } else {
                    // clear file_url: delete old local file if existed
                    if (!empty($row['file_url']) && str_starts_with($row['file_url'], '/uploads/notices/')) {
                        $publicRoot = realpath(__DIR__ . '/../../public') ?: (__DIR__ . '/../../public');
                        $oldPath = $publicRoot . $row['file_url'];
                        if ($oldPath && file_exists($oldPath)) {
                            @unlink($oldPath);
                        }
                    }
                    $fileUrl = null;
                }
            }

            // Build update payload
            $updateData = [];
            if (isset($data['title'])) $updateData['title'] = trim($data['title']);
            if (isset($data['notice_type'])) $updateData['notice_type'] = $data['notice_type'];
            if (isset($data['notice_note'])) $updateData['notice_note'] = $data['notice_note'];
            if (isset($data['valid_till'])) $updateData['valid_till'] = $data['valid_till'];

            // Always set file_url value (could be null)
            $updateData['file_url'] = $fileUrl;

            $ok = $this->model->updateNotice((int)$id, $updateData);
            if (!$ok) $this->error('Update failed', 500);

            $this->success($this->model->find((int)$id), 'Updated');
        } catch (\Throwable $e) {
            error_log('NoticesController@update error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $this->error('Server error', 500);
        }
    }

    /**
     * DELETE /api/v1/notices/{id}
     */
    public function destroy($id): void
    {
        try {
            $user = $this->requireAuth();
            $id = (int)$id;
            $row = $this->model->find($id);
            if (!$row) $this->error('Not found', 404);

            if ($row['user_id'] != $user['id'] && empty($user['is_admin'])) {
                $this->error('Unauthorized to delete this notice', 403);
            }

            if (!empty($row['file_url']) && str_starts_with($row['file_url'], '/uploads/notices/')) {
                $publicRoot = realpath(__DIR__ . '/../../public') ?: (__DIR__ . '/../../public');
                $filePath = $publicRoot . $row['file_url'];
                if ($filePath && file_exists($filePath)) {
                    @unlink($filePath);
                }
            }

            $ok = $this->model->delete($id);
            if (!$ok) $this->error('Delete failed', 500);

            $this->success(null, 'Deleted', 200);
        } catch (\Throwable $e) {
            error_log('NoticesController@destroy error: ' . $e->getMessage());
            $this->error('Server error', 500);
        }
    }

    /**
     * GET /api/v1/notices/my
     */
    public function myNotices(): void
    {
        try {
            $user = $this->requireAuth();
            $rows = $this->model->getByUserId((int)$user['id']);
            $this->success($rows);
        } catch (\Throwable $e) {
            error_log('NoticesController@myNotices error: ' . $e->getMessage());
            $this->error('Server error', 500);
        }
    }

    /**
     * GET /api/v1/notices/search?q=...
     * Simple search across title and notice_note (active notices only)
     */
    public function search(): void
    {
        try {
            $this->requireAuth();
            $searchTerm = $_GET['q'] ?? '';
            if (trim($searchTerm) === '') {
                $this->error('Search term required', 422);
            }

            $now = date('Y-m-d H:i:s');
            $sql = "SELECT * FROM `notices`
                    WHERE (`valid_till` IS NULL OR `valid_till` >= :now)
                      AND (title LIKE :search OR notice_note LIKE :search)
                    ORDER BY `created_at` DESC";
            $stmt = $this->model->db->prepare($sql);
            $stmt->bindValue(':now', $now);
            $stmt->bindValue(':search', '%' . $searchTerm . '%');
            $stmt->execute();

            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $this->success($rows);
        } catch (\Throwable $e) {
            error_log('NoticesController@search error: ' . $e->getMessage());
            $this->error('Server error', 500);
        }
    }
}


