<?php
namespace App\Controllers;

use App\Models\HelpdeskTicket;
use DateTimeImmutable;
use DateTimeZone;

class HelpdeskTicketsController extends BaseController
{
    protected HelpdeskTicket $ticketModel;

    // Allowed mime -> extension map (broad set)
    protected array $allowedMime = [
        // images
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
        // documents
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/csv' => 'csv',
        'text/plain' => 'txt',
        // archives
        'application/zip' => 'zip',
        'application/x-rar-compressed' => 'rar',
        'application/x-7z-compressed' => '7z',
        // presentations
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        // audio / video
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'video/mp4' => 'mp4',
        'video/x-msvideo' => 'avi',
        'video/x-matroska' => 'mkv',
        // fallback octet-stream permitted only if extension present on filename
        'application/octet-stream' => null,
    ];

    protected int $maxFileSize = 20 * 1024 * 1024; // 20 MB
    protected string $uploadRelativeDir = 'uploads/helpdesk';

    public function __construct()
    {
        parent::__construct();
        $this->ticketModel = new HelpdeskTicket();
    }

    /**
     * Return current datetime in Asia/Dhaka as Y-m-d H:i:s
     */
    protected function now(): string
    {
        $tz = new DateTimeZone('Asia/Dhaka');
        return (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
    }

    /**
     * GET /api/v1/helpdesk/tickets
     * Returns consistent JSON: { data: [...], total: N }
     */
    public function index(): void
    {
        $this->requireAuth();

        $params = $_GET;
        $limit = isset($params['limit']) ? max(1, (int)$params['limit']) : 100;
        $offset = isset($params['offset']) ? max(0, (int)$params['offset']) : 0;

        // Allowed filters
        $filters = [];
        foreach (['assigned_to', 'assigned_by', 'status', 'priority', 'user_id'] as $k) {
            if (isset($params[$k]) && $params[$k] !== '') {
                $filters[$k] = in_array($k, ['assigned_to', 'assigned_by', 'user_id']) ? (int)$params[$k] : $params[$k];
            }
        }

        $showTrashed = (isset($params['show_trashed']) && $params['show_trashed'] === '1');

        try {
            // Search overrides filters if provided
            if (!empty($params['q'])) {
                $q = trim((string)$params['q']);
                $res = $this->ticketModel->searchWithCount($q, $limit, $offset, $showTrashed);
                $this->success(['data' => $res['data'], 'total' => $res['total']]);
                return;
            }

            // Fetch using model public method
            $rows = $this->ticketModel->fetchWithFilters($filters, $limit, $offset, $showTrashed);
            $total = $this->ticketModel->countWithFilters($filters, $showTrashed);

            $this->success(['data' => $rows, 'total' => $total]);
        } catch (\Throwable $e) {
            error_log('HelpdeskTicketsController::index error: ' . $e->getMessage());
            $this->error('Server error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v1/helpdesk/tickets/{id}
     */
    public function show($id): void
    {
        $this->requireAuth();
        $id = (int)$id;
        $ticket = $this->ticketModel->findWithRelations($id);

        if (!$ticket) {
            $this->error('Ticket not found', 404);
        }
        $this->success($ticket);
    }

    /**
     * POST /api/v1/helpdesk/tickets
     */
    public function store(): void
    {
        $user = $this->requireAuth();

        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        $input = [];
        $docPath = null;

        try {
            if (strpos(strtolower($contentType), 'multipart/form-data') !== false) {
                // For multipart, PHP populates $_POST and $_FILES (only on POST)
                $input = $_POST;
                if (!empty($_FILES['doc']) && isset($_FILES['doc']['error']) && $_FILES['doc']['error'] === UPLOAD_ERR_OK) {
                    $docPath = $this->handleFileUpload($_FILES['doc']);
                } elseif (!empty($_FILES['doc']) && isset($_FILES['doc']['error'])) {
                    error_log('Upload failed with error code: ' . $_FILES['doc']['error']);
                }
            } else {
                $input = $this->jsonInput();
            }

            // If a doc_url field provided and no uploaded file, use it as doc path
            if (isset($input['doc_url']) && !$docPath) {
                $trim = trim((string)$input['doc_url']);
                if ($trim !== '') $docPath = $trim;
            }

            if (empty($input['title']) && !empty($input['subject'])) {
                $input['title'] = $input['subject'];
            }
            if (empty($input['title'])) {
                $this->error('Title is required', 422);
            }
            if (empty($input['details']) && !empty($input['description'])) {
                $input['details'] = $input['description'];
            }

            // Normalize assigned_to input:
            $assigned_to = null;
            if (isset($input['assigned_to'])) {
                if (is_array($input['assigned_to'])) {
                    // Filter out empty values and convert to integers
                    $assigned_to = array_filter(array_map('intval', $input['assigned_to']), function($value) {
                        return $value > 0;
                    });
                    // If array is empty after filtering, set to null
                    if (empty($assigned_to)) {
                        $assigned_to = null;
                    }
                } elseif (is_string($input['assigned_to'])) {
                    $trim = trim($input['assigned_to']);
                    if ($trim === '' || $trim === 'null' || $trim === 'undefined') {
                        $assigned_to = null;
                    } elseif (strpos($trim, ',') !== false) {
                        $parts = array_map('trim', explode(',', $trim));
                        $assigned_to = array_filter(array_map('intval', $parts), function($value) {
                            return $value > 0;
                        });
                        if (empty($assigned_to)) {
                            $assigned_to = null;
                        }
                    } else {
                        $val = (int)$trim;
                        $assigned_to = $val > 0 ? [$val] : null;
                    }
                } else {
                    $val = (int)$input['assigned_to'];
                    $assigned_to = $val > 0 ? [$val] : null;
                }
            }

            $now = $this->now();

            $data = [
                'title' => trim((string)$input['title']),
                'details' => $input['details'] ?? null,
                // store doc (either uploaded path returned by handleFileUpload OR provided doc_url)
                'doc' => $docPath,
                'request_category' => $input['request_category'] ?? null,
                'assigned_by' => (int)$user['id'],
                'assigned_to' => $assigned_to,
                'status' => $input['status'] ?? 'open',
                'priority' => $input['priority'] ?? 'medium',
                'request_time' => $input['request_time'] ?? $now,
                'last_update_time' => $now,
                'resolve_time' => $input['resolve_time'] ?? null,
                'user_id' => $input['user_id'] ?? $user['id']
            ];

            $newId = $this->ticketModel->create($data);
            $created = $this->ticketModel->findWithRelations((int)$newId);

            if (!$created) {
                $this->error('Failed to retrieve created ticket', 500);
            }

            $this->success($created, 'Ticket created successfully', 201);
        } catch (\Throwable $e) {
            error_log('HelpdeskTicketsController::store error: ' . $e->getMessage());
            $this->error('Failed to create ticket: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/v1/helpdesk/tickets/{id}
     */
    public function update($id): void
    {
        $user = $this->requireAuth();
        $id = (int)$id;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        $input = [];

        try {
            if (strpos(strtolower($contentType), 'multipart/form-data') !== false) {
                // allow POST-with-_method=PUT to be parsed by PHP as multipart
                $input = $_POST;
                if (!empty($_FILES['doc']) && isset($_FILES['doc']['error']) && $_FILES['doc']['error'] === UPLOAD_ERR_OK) {
                    $docPath = $this->handleFileUpload($_FILES['doc']);
                    if ($docPath) $input['doc'] = $docPath;
                } elseif (!empty($_FILES['doc']) && isset($_FILES['doc']['error'])) {
                    error_log('Upload failed with error code (update): ' . $_FILES['doc']['error']);
                }
            } else {
                $input = $this->jsonInput();
            }

            $ticket = $this->ticketModel->find($id);
            if (!$ticket) $this->error('Ticket not found', 404);

            if (isset($input['subject']) && !isset($input['title'])) $input['title'] = $input['subject'];
            if (isset($input['description']) && !isset($input['details'])) $input['details'] = $input['description'];

            // If doc_url provided (external link) and doc not already set from upload, use it
            if (isset($input['doc_url']) && !isset($input['doc'])) {
                $trim = trim((string)$input['doc_url']);
                if ($trim !== '') {
                    $input['doc'] = $trim;
                }
            }

            // normalize possible assigned_to / assigned_by / user_id
            foreach (['assigned_to', 'assigned_by', 'user_id'] as $intField) {
                if (array_key_exists($intField, $input)) {
                    if ($intField === 'assigned_to') {
                        if (is_array($input['assigned_to'])) {
                            // Filter out empty values and convert to integers
                            $input['assigned_to'] = array_filter(array_map('intval', $input['assigned_to']), function($value) {
                                return $value > 0;
                            });
                            // If array is empty after filtering, set to null
                            if (empty($input['assigned_to'])) {
                                $input['assigned_to'] = null;
                            }
                        } elseif (is_string($input['assigned_to'])) {
                            $trim = trim($input['assigned_to']);
                            if ($trim === '' || $trim === 'null' || $trim === 'undefined') {
                                $input['assigned_to'] = null;
                            } elseif (strpos($trim, ',') !== false) {
                                $parts = array_map('trim', explode(',', $trim));
                                $input['assigned_to'] = array_filter(array_map('intval', $parts), function($value) {
                                    return $value > 0;
                                });
                                if (empty($input['assigned_to'])) {
                                    $input['assigned_to'] = null;
                                }
                            } else {
                                $val = (int)$trim;
                                $input['assigned_to'] = $val > 0 ? [$val] : null;
                            }
                        } else {
                            $val = (int)$input['assigned_to'];
                            $input['assigned_to'] = $val > 0 ? [$val] : null;
                        }
                    } else {
                        if ($input[$intField] === '' || $input[$intField] === null) {
                            $input[$intField] = null;
                        } else {
                            $input[$intField] = (int)$input[$intField];
                        }
                    }
                }
            }

            // If the update includes assigned_to, set assigned_by to the current user
            if (array_key_exists('assigned_to', $input)) {
                $input['assigned_by'] = (int)$user['id'];
            }

            $now = $this->now();
            $input['last_update_time'] = $now;

            if (isset($input['status']) && $input['status'] === 'resolved' && empty($ticket['resolve_time'])) {
                $input['resolve_time'] = $now;
            }

            // Handle trashed_at field specifically for restore from trash
            if (array_key_exists('trashed_at', $input) && $input['trashed_at'] === null) {
                $input['trashed_at'] = null;
            }

            // If client provided a new doc (either via upload or doc_url), and ticket has an old local upload, remove it.
            if (isset($input['doc']) && !empty($ticket['doc'])) {
                // only unlink if the old doc looks like an internal uploads path (avoid deleting external links)
                $oldDoc = $ticket['doc'];
                if (strpos($oldDoc, trim($this->uploadRelativeDir, '/') . '/') === 0 || strpos($oldDoc, 'uploads/helpdesk/') === 0) {
                    // compute full path using upload dir
                    $full = rtrim($this->getUploadFullPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($oldDoc);
                    if (file_exists($full) && is_file($full)) {
                        @unlink($full);
                    }
                }
            }

            $ok = $this->ticketModel->update($id, $input);
            if (!$ok) $this->error('Update failed', 500);

            $updated = $this->ticketModel->findWithRelations($id);
            $this->success($updated, 'Ticket updated successfully');
        } catch (\Throwable $e) {
            error_log('HelpdeskTicketsController::update error: ' . $e->getMessage());
            $this->error('Server error during update: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/v1/helpdesk/tickets/{id} (soft delete)
     */
    public function destroy($id): void
    {
        $this->requireAuth();
        $id = (int)$id;
        $ticket = $this->ticketModel->find($id);
        if (!$ticket) $this->error('Ticket not found', 404);

        try {
            $ok = $this->ticketModel->delete($id);
            if (!$ok) $this->error('Delete failed', 500);
            $this->success(null, 'Ticket deleted successfully', 200);
        } catch (\Throwable $e) {
            error_log('HelpdeskTicketsController::destroy error: ' . $e->getMessage());
            $this->error('Server error during delete: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v1/helpdesk/tickets/{id}/trash
     */
    public function moveToTrash($id): void
    {
        $this->requireAuth();
        $id = (int)$id;
        $ticket = $this->ticketModel->find($id);
        if (!$ticket) $this->error('Ticket not found', 404);

        try {
            $ok = $this->ticketModel->moveToTrash($id);
            if (!$ok) $this->error('Move to trash failed', 500);
            $this->success(null, 'Ticket moved to trash successfully', 200);
        } catch (\Throwable $e) {
            error_log('HelpdeskTicketsController::moveToTrash error: ' . $e->getMessage());
            $this->error('Server error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v1/helpdesk/tickets/{id}/restore
     */
    public function restoreFromTrash($id): void
    {
        $this->requireAuth();
        $id = (int)$id;
        $ticket = $this->ticketModel->find($id);
        if (!$ticket) $this->error('Ticket not found', 404);

        try {
            $ok = $this->ticketModel->restoreFromTrash($id);
            if (!$ok) $this->error('Restore from trash failed', 500);
            $this->success(null, 'Ticket restored from trash successfully', 200);
        } catch (\Throwable $e) {
            error_log('HelpdeskTicketsController::restoreFromTrash error: ' . $e->getMessage());
            $this->error('Server error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v1/helpdesk/users
     */
    public function getUsers(): void
    {
        $this->requireAuth();
        try {
            $users = $this->ticketModel->getUsersForAssignment();
            $this->success($users);
        } catch (\Throwable $e) {
            error_log('HelpdeskTicketsController::getUsers error: ' . $e->getMessage());
            $this->error('Failed to fetch users: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v1/helpdesk/purge-trashed
     */
    public function purgeTrashed(): void
    {
        $this->requireAuth();
        try {
            $deleted = $this->ticketModel->purgeTrashedOlderThanDays(30);
            $this->success(['deleted' => $deleted], 'Purged old trashed tickets');
        } catch (\Throwable $e) {
            error_log('HelpdeskTicketsController::purgeTrashed error: ' . $e->getMessage());
            $this->error('Failed to purge: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Handle file upload (store in public_html/uploads/helpdesk)
     * Returns relative path (uploads/helpdesk/filename) on success or null on failure.
     */
    private function handleFileUpload(array $file): ?string
    {
        // Build upload dir preferring DOCUMENT_ROOT (public_html)
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if (!empty($docRoot)) {
            $uploadDir = rtrim(realpath($docRoot), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'helpdesk' . DIRECTORY_SEPARATOR;
            $publicPathPrefix = 'uploads/helpdesk/';
        } else {
            // fallback to project root + public_html/uploads/helpdesk
            $projectRoot = realpath(dirname(__DIR__, 2));
            $uploadDir = $projectRoot . DIRECTORY_SEPARATOR . 'public_html' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'helpdesk' . DIRECTORY_SEPARATOR;
            $publicPathPrefix = 'uploads/helpdesk/';
        }

        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            error_log('File upload error code: ' . ($file['error'] ?? 'n/a'));
            return null;
        }

        if (!isset($file['tmp_name']) || $file['tmp_name'] === '') {
            error_log('No tmp_name for uploaded file');
            return null;
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            // log and continue to try (some hosts behave differently)
            error_log('Uploaded file not recognized as HTTP upload: ' . $file['tmp_name']);
        }

        if (!isset($file['size']) || (int)$file['size'] > $this->maxFileSize) {
            error_log('Uploaded file too large: ' . ($file['size'] ?? 'n/a'));
            return null;
        }

        // Ensure upload dir exists
        if (!is_dir($uploadDir)) {
            if (!@mkdir($uploadDir, 0755, true)) {
                error_log('Failed to create upload directory: ' . $uploadDir);
                return null;
            }
        }

        // Use finfo to detect mime type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($file['tmp_name']);
        if ($detected === false) {
            error_log('finfo failed to detect mime-type for ' . $file['tmp_name']);
            return null;
        }

        $ext = null;
        if (isset($this->allowedMime[$detected]) && $this->allowedMime[$detected] !== null) {
            $ext = $this->allowedMime[$detected];
        } elseif ($detected === 'application/octet-stream') {
            // fallback: get extension from original filename
            $origExt = pathinfo($file['name'], PATHINFO_EXTENSION);
            $origExt = preg_replace('/[^a-z0-9]+/i', '', $origExt);
            if ($origExt !== '') {
                $ext = strtolower($origExt);
            } else {
                $ext = null;
            }
        } else {
            error_log('File type not allowed: ' . $detected . ' (reported: ' . ($file['type'] ?? 'n/a') . ')');
            return null;
        }

        if (empty($ext)) {
            error_log('Could not determine file extension for mime: ' . $detected);
            return null;
        }

        // generate safe unique filename
        try {
            $filename = uniqid('hd_', true) . '.' . $ext;
        } catch (\Throwable $e) {
            $filename = uniqid('hd_') . '.' . $ext;
        }

        $destination = $uploadDir . $filename;

        // move file into place
        if (@move_uploaded_file($file['tmp_name'], $destination) === false) {
            // try rename as fallback on some hosts
            if (!@rename($file['tmp_name'], $destination)) {
                error_log('Failed to move uploaded file to destination: ' . $destination . ' tmp: ' . $file['tmp_name']);
                return null;
            }
        }

        @chmod($destination, 0644);

        return $publicPathPrefix . $filename;
    }

    /**
     * Compute the full filesystem path to uploads/helpdesk directory (prefers public_html under DOCUMENT_ROOT).
     */
    protected function getUploadFullPath(): string
    {
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if (!empty($docRoot)) {
            $real = realpath($docRoot);
            if ($real !== false) {
                return rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'helpdesk';
            }
        }

        $projectRoot = realpath(dirname(__DIR__, 2));
        if ($projectRoot !== false) {
            // check public_html candidate
            $cand = $projectRoot . DIRECTORY_SEPARATOR . 'public_html' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'helpdesk';
            if (is_dir($cand) || @mkdir($cand, 0755, true)) {
                return rtrim($cand, DIRECTORY_SEPARATOR);
            }
            // fallback to projectRoot/uploads/helpdesk
            $cand2 = $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'helpdesk';
            if (is_dir($cand2) || @mkdir($cand2, 0755, true)) {
                return rtrim($cand2, DIRECTORY_SEPARATOR);
            }
        }

        // last fallback: relative to this file
        return rtrim(__DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . trim($this->uploadRelativeDir, '/');
    }
}
