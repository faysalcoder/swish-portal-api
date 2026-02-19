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
     * Resolve public root directory used for filesystem operations.
     * Priority:
     *  1. $_SERVER['DOCUMENT_ROOT'] if set and valid
     *  2. common candidates: public, public_html, parent public
     *  3. fallback to relative path
     */
    private function getPublicRoot(): string
    {
        // 1) DOCUMENT_ROOT (most reliable on shared hosts)
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $doc = realpath(rtrim($_SERVER['DOCUMENT_ROOT'], '/\\'));
            if ($doc !== false && is_dir($doc)) {
                return rtrim($doc, '/');
            }
        }

        // 2) common candidate directories relative to this file
        $candidates = [
            __DIR__ . '/../../public',
            __DIR__ . '/../../public_html',
            __DIR__ . '/../../../public',
            __DIR__ . '/../../../public_html',
        ];

        foreach ($candidates as $cand) {
            $real = realpath($cand);
            if ($real !== false && is_dir($real)) {
                return rtrim($real, '/');
            }
        }

        // 3) last resort: return the default relative path (might be wrong)
        return rtrim(__DIR__ . '/../../public', '/');
    }

    /**
     * Build web-relative file url (what will be stored in DB): '/uploads/notices/<filename>'
     */
    private function buildWebFileUrl(string $filename): string
    {
        return '/uploads/notices/' . ltrim($filename, '/');
    }

    /**
     * Resolve a filesystem path from a stored file_url.
     */
    private function resolveFilesystemPathFromFileUrl(?string $fileUrl): ?string
    {
        if (empty($fileUrl)) return null;

        // Web-relative uploaded path
        if (str_starts_with($fileUrl, '/uploads/notices/')) {
            $publicRoot = $this->getPublicRoot();
            return $publicRoot . $fileUrl;
        }

        // Absolute unix path
        if (DIRECTORY_SEPARATOR === '/' && str_starts_with($fileUrl, '/')) {
            return $fileUrl;
        }

        // Windows absolute path
        if (DIRECTORY_SEPARATOR === '\\' && preg_match('#^[A-Za-z]:\\\\#', $fileUrl)) {
            return $fileUrl;
        }

        // Fallback: treat as relative to public root
        $publicRoot = $this->getPublicRoot();
        return $publicRoot . '/' . ltrim($fileUrl, '/');
    }

    /**
     * GET /api/v1/notices
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

            // Basic validation
            if (empty($data['title'])) $this->error('title required', 422);

            // Determine notice type based on actual input
            $hasFile = !empty($_FILES['file']['name']);
            $hasUrl = !empty($data['file_url']);
            $hasNote = !empty($data['notice_note']);

            if ($hasFile) {
                $noticeType = 'file';
            } elseif ($hasUrl) {
                $noticeType = 'url';
            } elseif ($hasNote) {
                $noticeType = 'text';
            } else {
                $this->error('Either a file, a URL, or a notice note is required', 422);
            }

            // Validate according to the determined type
            if ($noticeType === 'file') {
                // File upload validation
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
                $publicRoot = $this->getPublicRoot();
                $uploadDir = rtrim($publicRoot, '/') . '/uploads/notices/';
                if (!is_dir($uploadDir)) {
                    if (!mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
                        $this->error('Failed to create upload directory', 500);
                    }
                }

                // Generate safe filename
                $filename = time() . '_' . bin2hex(random_bytes(6)) . '.' . $fileExt;
                $targetFile = $uploadDir . $filename;

                // Try moving uploaded file
                $moved = false;
                if (@move_uploaded_file($_FILES['file']['tmp_name'], $targetFile)) {
                    $moved = true;
                } else {
                    if (@copy($_FILES['file']['tmp_name'], $targetFile)) {
                        $moved = true;
                    }
                }

                // Extra safety fallback
                if (!$moved) {
                    $altLocation = $publicRoot . '/' . $filename;
                    if (file_exists($altLocation)) {
                        if (@rename($altLocation, $targetFile)) {
                            $moved = true;
                        }
                    }
                }

                if (!$moved || !file_exists($targetFile)) {
                    $this->error('File upload failed', 500);
                }

                @chmod($targetFile, 0644);

                $fileUrl = $this->buildWebFileUrl($filename);
            } elseif ($noticeType === 'url') {
                // Validate URL
                if (!filter_var($data['file_url'], FILTER_VALIDATE_URL)) {
                    $this->error('Invalid URL format', 422);
                }
                $fileUrl = $data['file_url'];
            } else { // text
                $fileUrl = null; // no file attached
                // Notice note is already present, no extra validation needed
            }

            $noticeData = [
                'title' => trim($data['title']),
                'notice_type' => $noticeType,
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

            // Determine new notice type based on input
            $hasFile = !empty($_FILES['file']['name']);
            $hasUrl = !empty($data['file_url']);
            $hasNote = isset($data['notice_note']) && $data['notice_note'] !== ''; // allow empty to clear

            // If a new file is uploaded -> file type
            if ($hasFile) {
                $noticeType = 'file';
            } elseif ($hasUrl) {
                $noticeType = 'url';
            } elseif ($hasNote) {
                $noticeType = 'text';
            } else {
                // No file, no url, no note – we might be keeping existing type,
                // but we need at least one of them eventually. We'll check after processing.
                $noticeType = null; // will be resolved later
            }

            $fileUrl = $row['file_url']; // keep old by default

            // Handle file upload if present
            if ($hasFile) {
                // Validate file
                $maxFileSize = 10 * 1024 * 1024;
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

                $publicRoot = $this->getPublicRoot();
                $uploadDir = rtrim($publicRoot, '/') . '/uploads/notices/';
                if (!is_dir($uploadDir)) {
                    if (!mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
                        $this->error('Failed to create upload directory', 500);
                    }
                }

                // Delete old local file if present
                if (!empty($row['file_url'])) {
                    $oldPath = $this->resolveFilesystemPathFromFileUrl($row['file_url']);
                    if ($oldPath && file_exists($oldPath)) {
                        @unlink($oldPath);
                    }
                }

                $filename = time() . '_' . bin2hex(random_bytes(6)) . '.' . $fileExt;
                $targetFile = $uploadDir . $filename;

                $moved = false;
                if (@move_uploaded_file($_FILES['file']['tmp_name'], $targetFile)) {
                    $moved = true;
                } else {
                    if (@copy($_FILES['file']['tmp_name'], $targetFile)) {
                        $moved = true;
                    }
                }

                if (!$moved) {
                    $altLocation = $publicRoot . '/' . $filename;
                    if (file_exists($altLocation)) {
                        if (@rename($altLocation, $targetFile)) $moved = true;
                    }
                }

                if (!$moved || !file_exists($targetFile)) {
                    $this->error('File upload failed', 500);
                }

                @chmod($targetFile, 0644);

                $fileUrl = $this->buildWebFileUrl($filename);
                $noticeType = 'file';
            }
            // Handle URL update/clear
            elseif (array_key_exists('file_url', $data)) {
                if (!empty($data['file_url'])) {
                    if (!filter_var($data['file_url'], FILTER_VALIDATE_URL)) {
                        $this->error('Invalid URL format', 422);
                    }
                    $fileUrl = $data['file_url'];
                    $noticeType = 'url';
                } else {
                    // clearing file_url: delete old local file if existed
                    if (!empty($row['file_url'])) {
                        $oldPath = $this->resolveFilesystemPathFromFileUrl($row['file_url']);
                        if ($oldPath && file_exists($oldPath)) {
                            @unlink($oldPath);
                        }
                    }
                    $fileUrl = null;
                    // If we cleared file_url but note is present, type becomes text
                    if ($hasNote) {
                        $noticeType = 'text';
                    } else {
                        // No file, no url, no note – invalid, will be caught later
                    }
                }
            }

            // If no file and no url were provided, but notice_note is set (and maybe we want to keep old file_url?)
            // We need to decide the final notice_type.
            // If we haven't set a noticeType yet, we need to determine from existing data + changes.
            if ($noticeType === null) {
                // No new file, no new url, but we may have a note.
                if ($hasNote) {
                    $noticeType = 'text';
                    $fileUrl = null; // text type has no attachment
                    // If there was an old file, delete it because we're switching to text
                    if (!empty($row['file_url'])) {
                        $oldPath = $this->resolveFilesystemPathFromFileUrl($row['file_url']);
                        if ($oldPath && file_exists($oldPath)) {
                            @unlink($oldPath);
                        }
                    }
                } else {
                    // No changes to file/url/note – keep existing type and file_url
                    $noticeType = $row['notice_type'];
                    $fileUrl = $row['file_url']; // already set
                }
            }

            // Final validation: we must have at least one of file_url or notice_note depending on type
            if ($noticeType === 'file' && empty($fileUrl)) {
                $this->error('File type requires a file', 422);
            }
            if ($noticeType === 'url' && empty($fileUrl)) {
                $this->error('URL type requires a URL', 422);
            }
            if ($noticeType === 'text' && empty($data['notice_note']) && empty($row['notice_note'])) {
                // If we're setting text but no note provided (and old note is also empty) -> error
                $this->error('Text type requires a notice note', 422);
            }

            // Build update payload
            $updateData = [];
            if (isset($data['title'])) $updateData['title'] = trim($data['title']);
            if (isset($data['notice_type'])) $updateData['notice_type'] = $noticeType; // use computed type
            if (isset($data['notice_note'])) $updateData['notice_note'] = $data['notice_note'];
            if (isset($data['valid_till'])) $updateData['valid_till'] = $data['valid_till'];

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

            if (!empty($row['file_url'])) {
                $filePath = $this->resolveFilesystemPathFromFileUrl($row['file_url']);
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