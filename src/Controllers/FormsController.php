<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\FormModel;
use App\Models\FormVersion;

/**
 * Fresh FormsController — keeps existing routes & behaviours,
 * ensures versions are always loaded and version control works.
 *
 * NOTE: Minor, safe fixes only:
 * - make uploads work on typical shared hosting setups where web root is `public_html` (via $_SERVER['DOCUMENT_ROOT'])
 * - ensure deletion of uploaded files resolves to the correct filesystem path on hosting
 *
 * No other behaviour or public API changed.
 */
class FormsController extends BaseController
{
    protected FormModel $model;
    protected FormVersion $versionModel;

    public function __construct()
    {
        parent::__construct();
        $this->model = new FormModel();
        $this->versionModel = new FormVersion();
    }

    /** Try to obtain a PDO instance (prefer model/db if available) */
    private function getDb(): \PDO
    {
        // If BaseController or Model has $db, use it
        try {
            if (property_exists($this, 'db') && $this->db instanceof \PDO) {
                return $this->db;
            }

            if (property_exists($this->model, 'db')) {
                $prop = new \ReflectionProperty($this->model, 'db');
                $prop->setAccessible(true);
                $db = $prop->getValue($this->model);
                if ($db instanceof \PDO) return $db;
            }
        } catch (\Throwable $e) {
            // fallback to config
        }

        $config = require __DIR__ . '/../../config/database.php';
        $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";
        $pdo = new \PDO($dsn, $config['username'], $config['password']);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    /**
     * Determine the upload directory in a robust way.
     *
     * On shared/hosted environments the public web root is often at $_SERVER['DOCUMENT_ROOT']
     * (e.g. /home/username/public_html). We prefer to place uploaded files under that web root:
     *   {DOCUMENT_ROOT}/uploads/forms/
     *
     * If DOCUMENT_ROOT is not available or writable, fall back to the project `public/uploads/forms/`
     * location used in local/dev setups.
     *
     * Ensures the directory exists and is writable (attempts to create it).
     */
    private function getUploadDir(): string
    {
        $dir = '';

        // Preferred location: document root (typical on hosting: public_html)
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($docRoot) {
            $candidate = rtrim($docRoot, '/') . '/uploads/forms/';
            if (!is_dir($candidate)) {
                @mkdir($candidate, 0755, true);
            }
            if (is_dir($candidate)) {
                $dir = $candidate;
            }
        }

        // Fallback: project public directory (local / dev)
        if (empty($dir)) {
            $candidate = dirname(__DIR__, 2) . '/public/uploads/forms/';
            if (!is_dir($candidate)) {
                @mkdir($candidate, 0755, true);
            }
            $dir = $candidate;
        }

        return rtrim($dir, '/') . '/';
    }

    /**
     * Given a public-facing file URL (like "/uploads/forms/xyz.pdf"), resolve the filesystem path.
     * Returns empty string if cannot resolve.
     *
     * This mirrors getUploadDir() logic so deletion works on hosting and local.
     */
    private function resolveFilePathFromUrl(string $url): string
    {
        $url = (string)$url;
        // Only handle known uploads path pattern
        if (strpos($url, '/uploads/forms/') === 0) {
            $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
            if ($docRoot) {
                $path = rtrim($docRoot, '/') . $url;
                return $path;
            }
            // fallback to project public dir
            $path = dirname(__DIR__, 2) . '/public' . $url;
            return $path;
        }

        // If it's an absolute path (rare), try returning as-is
        if (strpos($url, '/') === 0) {
            $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
            if ($docRoot && file_exists(rtrim($docRoot, '/') . $url)) {
                return rtrim($docRoot, '/') . $url;
            }
        }

        return '';
    }

    /**
     * Handle uploaded file array (supports 'file' or 'form_file').
     * Returns array with keys: filename, path, url, size, mime
     */
    private function handleFileUpload(array $file): array
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('File upload error');
        }
        if (!isset($file['tmp_name']) || !file_exists($file['tmp_name'])) {
            throw new \RuntimeException('Uploaded file missing');
        }

        $max = 20 * 1024 * 1024; // 20MB
        if ($file['size'] > $max) {
            throw new \RuntimeException('File exceeds 20MB');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowed = [
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'text/plain' => 'txt',
        ];

        // If mime unknown, allow by extension fallback
        $extension = $allowed[$mime] ?? strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExt = ['pdf','doc','docx','jpg','jpeg','png','txt'];
        if (!in_array($extension, $allowedExt, true)) {
            throw new \RuntimeException('Invalid file type. Allowed: PDF, DOC, DOCX, JPG, PNG, TXT');
        }

        $unique = uniqid('form_', true) . '_' . time() . '.' . $extension;
        $destDir = $this->getUploadDir();
        $dest = $destDir . $unique;

        // move_uploaded_file requires an absolute path; getUploadDir returns absolute path
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            // Try a more permissive copy/unlink fallback for some hosting environments
            if (!@copy($file['tmp_name'], $dest)) {
                throw new \RuntimeException('Failed to move uploaded file');
            }
            @unlink($file['tmp_name']);
        }
        @chmod($dest, 0644);

        // Return a public relative URL (keeps same pattern as your previous code)
        $publicUrl = '/uploads/forms/' . $unique;

        return [
            'filename' => $unique,
            'path'     => $dest,
            'url'      => $publicUrl,
            'size'     => (int)$file['size'],
            'mime'     => $mime,
        ];
    }

    /** Parse JSON or multipart POST */
    private function getRequestData(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $data = json_decode((string)file_get_contents('php://input'), true);
            return is_array($data) ? $data : [];
        }
        // For form submissions, prefer $_POST
        return $_POST ?: [];
    }

    /** Clean & normalize version strings like "1" or "1.0" -> "1.0" */
    private function normalizeVersion(string $v): string
    {
        $v = trim(preg_replace('/[^0-9.]/', '', $v));
        if ($v === '') return '1.0';
        $parts = explode('.', $v);
        $major = (int)($parts[0] ?? 1);
        $minor = (int)($parts[1] ?? 0);
        return $major . '.' . $minor;
    }

    /** Increment major version: 1.0 -> 2.0 */
    private function incrementMajorVersion(?string $v): string
    {
        if (empty($v)) return '1.0';
        $parts = explode('.', $v);
        $major = (int)($parts[0] ?? 1);
        return ($major + 1) . '.0';
    }

    /********************** ROUTE HANDLERS **********************/

    /**
     * GET /api/v1/forms
     */
    public function index(): void
    {
        try {
            $user = $this->requireAuth();
            $db = $this->getDb();

            $sql = "SELECT f.*, u.name AS creator_name
                    FROM forms f
                    LEFT JOIN users u ON f.user_id = u.id";
            $params = [];
            if (empty($user['is_admin']) && empty($user['role'])) {
                $sql .= " WHERE f.user_id = :user_id";
                $params[':user_id'] = $user['id'];
            }
            $sql .= " ORDER BY f.last_update DESC";

            $stmt = $db->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $stmt->execute();
            $forms = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Attach versions to each form (ordered newest first by created_at)
            foreach ($forms as &$form) {
                $vstmt = $db->prepare("SELECT id, form_id, file_url, version, created_at
                                       FROM form_versions
                                       WHERE form_id = :form_id
                                       ORDER BY created_at DESC, id DESC");
                $vstmt->bindValue(':form_id', (int)$form['id'], \PDO::PARAM_INT);
                $vstmt->execute();
                $form['versions'] = $vstmt->fetchAll(\PDO::FETCH_ASSOC);
            }

            $this->success([
                'data' => $forms,
                'total' => count($forms),
            ]);
        } catch (\Throwable $e) {
            error_log('Forms#index error: ' . $e->getMessage());
            $this->error('Failed to fetch forms', 500);
        }
    }

    /**
     * GET /api/v1/forms/{id}
     */
    public function show($id): void
    {
        try {
            $user = $this->requireAuth();
            $db = $this->getDb();

            $stmt = $db->prepare("SELECT f.*, u.name AS creator_name
                                  FROM forms f
                                  LEFT JOIN users u ON f.user_id = u.id
                                  WHERE f.id = :id");
            $stmt->bindValue(':id', (int)$id, \PDO::PARAM_INT);
            $stmt->execute();
            $form = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$form) {
                $this->error('Form not found', 404);
                return;
            }

            if ($form['user_id'] != $user['id'] && empty($user['is_admin']) && empty($user['role'])) {
                $this->error('Unauthorized to view this form', 403);
                return;
            }

            $vstmt = $db->prepare("SELECT id, form_id, file_url, version, created_at
                                   FROM form_versions
                                   WHERE form_id = :form_id
                                   ORDER BY created_at DESC, id DESC");
            $vstmt->bindValue(':form_id', (int)$id, \PDO::PARAM_INT);
            $vstmt->execute();
            $form['versions'] = $vstmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->success(['data' => $form]);
        } catch (\Throwable $e) {
            error_log('Forms#show error: ' . $e->getMessage());
            $this->error('Failed to fetch form', 500);
        }
    }

    /**
     * POST /api/v1/forms
     * Accepts multipart file (`file` or `form_file`) or JSON with `file_url`.
     * Creates initial version (default 1.0 if none provided).
     */
    public function store(): void
    {
        $db = null;
        try {
            $user = $this->requireAuth();
            $data = $this->getRequestData();
            $db = $this->getDb();
            $db->beginTransaction();

            if (empty($data['title'])) {
                $this->error('title required', 422);
                return;
            }

            // Accept either uploaded file 'file' or 'form_file' OR a remote file_url in JSON
            $fileInfo = null;
            if (!empty($_FILES['file'])) {
                $fileInfo = $this->handleFileUpload($_FILES['file']);
            } elseif (!empty($_FILES['form_file'])) {
                $fileInfo = $this->handleFileUpload($_FILES['form_file']);
            } elseif (!empty($data['file_url'])) {
                $url = filter_var($data['file_url'], FILTER_VALIDATE_URL) ? $data['file_url'] : null;
                if ($url) {
                    $fileInfo = ['url' => $url, 'filename' => null, 'path' => null, 'size' => null, 'mime' => null];
                }
            }

            if (!$fileInfo) {
                $this->error('File is required (upload "file" or "form_file" or provide "file_url")', 422);
                return;
            }

            $now = date('Y-m-d H:i:s');
            $version = $this->normalizeVersion($data['version'] ?? '1.0');

            // Insert into forms
            $fstmt = $db->prepare("INSERT INTO forms
                (title, form_file, version, notes, last_update, user_id)
                VALUES (:title, :form_file, :version, :notes, :last_update, :user_id)");

            $fstmt->execute([
                ':title' => trim($data['title']),
                ':form_file' => $fileInfo['url'],
                ':version' => $version,
                ':notes' => !empty($data['notes']) ? trim($data['notes']) : null,
                ':last_update' => $now,
                ':user_id' => $user['id'],
            ]);

            $formId = (int)$db->lastInsertId();

            // Insert form_versions record with created_at timestamp
            $vstmt = $db->prepare("INSERT INTO form_versions
                (form_id, file_url, version, created_at)
                VALUES (:form_id, :file_url, :version, :created_at)");

            $vstmt->execute([
                ':form_id' => $formId,
                ':file_url' => $fileInfo['url'],
                ':version' => $version,
                ':created_at' => $now,
            ]);

            $db->commit();

            // Return inserted form (with versions)
            $stmt = $db->prepare("SELECT f.*, u.name AS creator_name FROM forms f LEFT JOIN users u ON f.user_id = u.id WHERE f.id = :id");
            $stmt->bindValue(':id', $formId, \PDO::PARAM_INT);
            $stmt->execute();
            $form = $stmt->fetch(\PDO::FETCH_ASSOC);

            $vstmt = $db->prepare("SELECT id, form_id, file_url, version, created_at FROM form_versions WHERE form_id = :form_id ORDER BY created_at DESC, id DESC");
            $vstmt->bindValue(':form_id', $formId, \PDO::PARAM_INT);
            $vstmt->execute();
            $form['versions'] = $vstmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->success(['data' => $form], 'Created', 201);
        } catch (\Throwable $e) {
            if ($db && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Forms#store error: ' . $e->getMessage());
            $this->error('Failed to create form: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/v1/forms/{id}
     * If a new file is provided, create a new version row and update forms.version and forms.form_file (do NOT modify old version rows).
     */
    public function update($id): void
    {
        $db = null;
        try {
            $user = $this->requireAuth();
            $data = $this->getRequestData();
            $db = $this->getDb();
            $db->beginTransaction();

            // Fetch existing form
            $stmt = $db->prepare("SELECT * FROM forms WHERE id = :id FOR UPDATE");
            $stmt->bindValue(':id', (int)$id, \PDO::PARAM_INT);
            $stmt->execute();
            $form = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$form) {
                $this->error('Form not found', 404);
                return;
            }

            if ($form['user_id'] != $user['id'] && empty($user['is_admin']) && empty($user['role'])) {
                $this->error('Unauthorized to update this form', 403);
                return;
            }

            $now = date('Y-m-d H:i:s');
            $updates = [];
            $params = [':id' => (int)$id];

            if (isset($data['title'])) {
                $updates[] = 'title = :title';
                $params[':title'] = trim($data['title']);
            }

            if (array_key_exists('notes', $data)) {
                $updates[] = 'notes = :notes';
                $params[':notes'] = !empty($data['notes']) ? trim($data['notes']) : null;
            }

            // Detect uploaded file or URL
            $newFileInfo = null;
            if (!empty($_FILES['file'])) {
                $newFileInfo = $this->handleFileUpload($_FILES['file']);
            } elseif (!empty($_FILES['form_file'])) {
                $newFileInfo = $this->handleFileUpload($_FILES['form_file']);
            } elseif (!empty($data['file_url'])) {
                $url = filter_var($data['file_url'], FILTER_VALIDATE_URL) ? $data['file_url'] : null;
                if ($url) {
                    $newFileInfo = ['url' => $url, 'filename' => null, 'path' => null, 'mime' => null, 'size' => null];
                }
            }

            // If there's a new file and it's different from existing form_file -> create new version
            if ($newFileInfo !== null && ($form['form_file'] !== $newFileInfo['url'])) {
                // Determine version: if provided explicitly use normalizeVersion, else increment major
                if (!empty($data['version'])) {
                    $newVersion = $this->normalizeVersion((string)$data['version']);
                } else {
                    $newVersion = $this->incrementMajorVersion($form['version'] ?? null);
                }

                // Insert version record
                $vstmt = $db->prepare("INSERT INTO form_versions (form_id, file_url, version, created_at) VALUES (:form_id, :file_url, :version, :created_at)");
                $vstmt->execute([
                    ':form_id' => (int)$id,
                    ':file_url' => $newFileInfo['url'],
                    ':version' => $newVersion,
                    ':created_at' => $now,
                ]);

                // Update forms table file + version + last_update
                $updates[] = 'form_file = :form_file';
                $updates[] = 'version = :version';
                $updates[] = 'last_update = :last_update';
                $params[':form_file'] = $newFileInfo['url'];
                $params[':version'] = $newVersion;
                $params[':last_update'] = $now;
            } elseif ($newFileInfo !== null && ($form['form_file'] === $newFileInfo['url'])) {
                // If the provided file/url equals existing, only update last_update and other fields
                $updates[] = 'last_update = :last_update';
                $params[':last_update'] = $now;
            } else {
                // no new file, but we still update last_update to show modification
                $updates[] = 'last_update = :last_update';
                $params[':last_update'] = $now;
            }

            if (!empty($updates)) {
                $sql = "UPDATE forms SET " . implode(', ', $updates) . " WHERE id = :id";
                $ustmt = $db->prepare($sql);
                foreach ($params as $k => $v) {
                    if ($v === null) {
                        $ustmt->bindValue($k, null, \PDO::PARAM_NULL);
                    } elseif (is_int($v)) {
                        $ustmt->bindValue($k, $v, \PDO::PARAM_INT);
                    } else {
                        $ustmt->bindValue($k, $v, \PDO::PARAM_STR);
                    }
                }
                $ustmt->execute();
            }

            $db->commit();

            // Return updated form with versions
            $stmt = $db->prepare("SELECT f.*, u.name AS creator_name FROM forms f LEFT JOIN users u ON f.user_id = u.id WHERE f.id = :id");
            $stmt->bindValue(':id', (int)$id, \PDO::PARAM_INT);
            $stmt->execute();
            $updated = $stmt->fetch(\PDO::FETCH_ASSOC);

            $vstmt = $db->prepare("SELECT id, form_id, file_url, version, created_at FROM form_versions WHERE form_id = :form_id ORDER BY created_at DESC, id DESC");
            $vstmt->bindValue(':form_id', (int)$id, \PDO::PARAM_INT);
            $vstmt->execute();
            $updated['versions'] = $vstmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->success(['data' => $updated], 'Updated');
        } catch (\Throwable $e) {
            if ($db && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Forms#update error: ' . $e->getMessage());
            $this->error('Failed to update form: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/v1/forms/{id}
     * Deletes all version records and tries to remove uploaded files (only those in /uploads/forms/ path).
     */
    public function destroy($id): void
    {
        $db = null;
        try {
            $user = $this->requireAuth();
            $db = $this->getDb();
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT * FROM forms WHERE id = :id FOR UPDATE");
            $stmt->bindValue(':id', (int)$id, \PDO::PARAM_INT);
            $stmt->execute();
            $form = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$form) {
                $this->error('Form not found', 404);
                return;
            }
            if ($form['user_id'] != $user['id'] && empty($user['is_admin']) && empty($user['role'])) {
                $this->error('Unauthorized to delete this form', 403);
                return;
            }

            // Fetch versions (to delete files if local)
            $vstmt = $db->prepare("SELECT file_url FROM form_versions WHERE form_id = :form_id");
            $vstmt->bindValue(':form_id', (int)$id, \PDO::PARAM_INT);
            $vstmt->execute();
            $versions = $vstmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($versions as $v) {
                if (!empty($v['file_url']) && strpos($v['file_url'], '/uploads/forms/') === 0) {
                    $filePath = $this->resolveFilePathFromUrl($v['file_url']);
                    if ($filePath && file_exists($filePath)) {
                        @unlink($filePath);
                    }
                }
            }

            // Delete version rows
            $dstmt = $db->prepare("DELETE FROM form_versions WHERE form_id = :form_id");
            $dstmt->bindValue(':form_id', (int)$id, \PDO::PARAM_INT);
            $dstmt->execute();

            // Delete form
            $fstmt = $db->prepare("DELETE FROM forms WHERE id = :id");
            $fstmt->bindValue(':id', (int)$id, \PDO::PARAM_INT);
            $fstmt->execute();

            $db->commit();
            $this->success(['message' => 'Deleted'], 'Deleted', 204);
        } catch (\Throwable $e) {
            if ($db && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Forms#destroy error: ' . $e->getMessage());
            $this->error('Failed to delete form', 500);
        }
    }

    /**
     * GET /api/v1/forms/my
     */
    public function myForms(): void
    {
        try {
            $user = $this->requireAuth();
            $db = $this->getDb();

            $stmt = $db->prepare("SELECT f.*, u.name AS creator_name
                                  FROM forms f
                                  LEFT JOIN users u ON f.user_id = u.id
                                  WHERE f.user_id = :user_id
                                  ORDER BY f.last_update DESC");
            $stmt->bindValue(':user_id', $user['id'], \PDO::PARAM_INT);
            $stmt->execute();
            $forms = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($forms as &$form) {
                $vstmt = $db->prepare("SELECT id, form_id, file_url, version, created_at FROM form_versions WHERE form_id = :form_id ORDER BY created_at DESC, id DESC");
                $vstmt->bindValue(':form_id', (int)$form['id'], \PDO::PARAM_INT);
                $vstmt->execute();
                $form['versions'] = $vstmt->fetchAll(\PDO::FETCH_ASSOC);
            }

            $this->success(['data' => $forms, 'total' => count($forms)]);
        } catch (\Throwable $e) {
            error_log('Forms#myForms error: ' . $e->getMessage());
            $this->error('Failed to fetch user forms', 500);
        }
    }

    /**
     * GET /api/v1/forms/search?q=...
     */
    public function search(): void
    {
        try {
            $user = $this->requireAuth();
            $q = $_GET['q'] ?? '';
            if (trim($q) === '') {
                $this->error('q (query) required', 422);
                return;
            }

            $db = $this->getDb();
            $sql = "SELECT f.*, u.name AS creator_name
                    FROM forms f
                    LEFT JOIN users u ON f.user_id = u.id
                    WHERE (f.title LIKE :q OR f.notes LIKE :q)";
            $params = [':q' => '%' . $q . '%'];

            if (empty($user['is_admin']) && empty($user['role'])) {
                $sql .= " AND f.user_id = :user_id";
                $params[':user_id'] = $user['id'];
            }

            $sql .= " ORDER BY f.last_update DESC";

            $stmt = $db->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $stmt->execute();
            $forms = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($forms as &$form) {
                $vstmt = $db->prepare("SELECT id, form_id, file_url, version, created_at FROM form_versions WHERE form_id = :form_id ORDER BY created_at DESC, id DESC");
                $vstmt->bindValue(':form_id', (int)$form['id'], \PDO::PARAM_INT);
                $vstmt->execute();
                $form['versions'] = $vstmt->fetchAll(\PDO::FETCH_ASSOC);
            }

            $this->success(['data' => $forms, 'total' => count($forms)]);
        } catch (\Throwable $e) {
            error_log('Forms#search error: ' . $e->getMessage());
            $this->error('Failed to search forms', 500);
        }
    }
}
