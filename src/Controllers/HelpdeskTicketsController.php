<?php
namespace App\Controllers;

use App\Models\HelpdeskTicket;
use DateTimeImmutable;
use DateTimeZone;

class HelpdeskTicketsController extends BaseController
{
    protected HelpdeskTicket $ticketModel;

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

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $input = [];
        $docPath = null;

        try {
            if (strpos($contentType, 'multipart/form-data') !== false) {
                // For multipart, PHP populates $_POST and $_FILES (only on POST)
                $input = $_POST;
                if (!empty($_FILES['doc']) && $_FILES['doc']['error'] === UPLOAD_ERR_OK) {
                    $docPath = $this->handleFileUpload($_FILES['doc']);
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
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $input = [];

        try {
            if (strpos($contentType, 'multipart/form-data') !== false) {
                // allow POST-with-_method=PUT to be parsed by PHP as multipart
                $input = $_POST;
                if (!empty($_FILES['doc']) && $_FILES['doc']['error'] === UPLOAD_ERR_OK) {
                    $docPath = $this->handleFileUpload($_FILES['doc']);
                    if ($docPath) $input['doc'] = $docPath;
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
                if (strpos($oldDoc, 'uploads/helpdesk/') === 0) {
                    $full = __DIR__ . '/../../public/' . $oldDoc;
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
     * Handle file upload (store in public/uploads/helpdesk)
     */
    private function handleFileUpload(array $file): ?string
    {
        $uploadDir = __DIR__ . '/../../public/uploads/helpdesk/';
        $publicPathPrefix = 'uploads/helpdesk/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $allowedMime = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];
        $maxSize = 5 * 1024 * 1024;

        if ($file['error'] !== UPLOAD_ERR_OK) {
            error_log('File upload error: ' . $file['error']);
            return null;
        }
        if ($file['size'] > $maxSize) {
            error_log('File too large: ' . $file['size']);
            return null;
        }
        if (!in_array($file['type'], $allowedMime)) {
            error_log('File type not allowed: ' . $file['type']);
            return null;
        }

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid('hd_') . '.' . $extension;
        $filepath = $uploadDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            return $publicPathPrefix . $filename;
        }

        error_log('Failed to move uploaded file');
        return null;
    }
}