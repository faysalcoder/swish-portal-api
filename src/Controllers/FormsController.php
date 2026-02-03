<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\FormModel;
use App\Models\FormVersion;

/**
 * FormsController
 *
 * Manage forms + form_versions. Uses a db accessor helper to avoid
 * "Cannot access protected property" errors when model::$db isn't public.
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

    /**
     * Obtain a PDO instance for a model safely.
     *  - If model has public ->db, use it.
     *  - Else if model has getDb/getPDO/pdo method, call it.
     *  - Else attempt Reflection to read protected/private 'db' property.
     *
     * @param object $model
     * @return \PDO
     * @throws \RuntimeException
     */
    private function dbForModel(object $model): \PDO
    {
        // 1) public property
        if (property_exists($model, 'db')) {
            $rp = new \ReflectionProperty($model, 'db');
            if ($rp->isPublic()) {
                $val = $model->db;
                if ($val instanceof \PDO) return $val;
            }
        }

        // 2) getter methods
        $candidates = ['getDb', 'getPDO', 'pdo', 'db'];
        foreach ($candidates as $m) {
            if (method_exists($model, $m)) {
                $val = $model->{$m}();
                if ($val instanceof \PDO) return $val;
            }
        }

        // 3) reflection fallback (read protected/private property)
        if (property_exists($model, 'db')) {
            try {
                $rp = new \ReflectionProperty($model, 'db');
                // setAccessible may be restricted in some PHP versions, but attempt it
                if (method_exists($rp, 'setAccessible')) {
                    $rp->setAccessible(true);
                }
                $val = $rp->getValue($model);
                if ($val instanceof \PDO) return $val;
            } catch (\Throwable $e) {
                // fall through
            }
        }

        throw new \RuntimeException('Unable to obtain PDO from model: ' . get_class($model));
    }

    /**
     * Resolve public root directory used for filesystem operations.
     */
    private function getPublicRoot(): string
    {
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $doc = realpath(rtrim($_SERVER['DOCUMENT_ROOT'], '/\\'));
            if ($doc !== false && is_dir($doc)) {
                return rtrim($doc, '/');
            }
        }

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

        return rtrim(__DIR__ . '/../../public', '/');
    }

    private function buildWebFileUrl(string $filename): string
    {
        return '/uploads/forms/' . ltrim($filename, '/');
    }

    private function resolveFilesystemPathFromFileUrl(?string $fileUrl): ?string
    {
        if (empty($fileUrl)) return null;

        if (str_starts_with($fileUrl, '/uploads/forms/')) {
            $publicRoot = $this->getPublicRoot();
            return $publicRoot . $fileUrl;
        }

        if (DIRECTORY_SEPARATOR === '/' && str_starts_with($fileUrl, '/')) {
            return $fileUrl;
        }

        if (DIRECTORY_SEPARATOR === '\\' && preg_match('#^[A-Za-z]:\\\\#', $fileUrl)) {
            return $fileUrl;
        }

        $publicRoot = $this->getPublicRoot();
        return $publicRoot . '/' . ltrim($fileUrl, '/');
    }

    private function bumpMajorVersion(string $v): string
    {
        $parts = explode('.', $v);
        $major = (int)($parts[0] ?? 0);
        $major++;
        return $major . '.0';
    }

    private function validateAndSaveUploadedFile(array $fileInput, string &$outWebUrl): void
    {
        $maxFileSize = 20 * 1024 * 1024; // 20 MB
        if (empty($fileInput['name'])) {
            $this->error('File not provided', 422);
        }
        if ($fileInput['size'] > $maxFileSize) {
            $this->error('File size exceeds 20MB limit', 422);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($fileInput['tmp_name'] ?? '');
        $allowedMimes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'image/jpeg',
            'image/png',
            'text/plain',
        ];
        if (!in_array($mime, $allowedMimes, true)) {
            $this->error('Invalid file type. Allowed: PDF, DOC/DOCX, JPEG, PNG, TXT', 422);
        }

        $fileExt = strtolower(pathinfo($fileInput['name'], PATHINFO_EXTENSION));
        $allowedExt = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'txt'];
        if (!in_array($fileExt, $allowedExt, true)) {
            $this->error('Invalid file extension', 422);
        }

        $publicRoot = $this->getPublicRoot();
        $uploadDir = rtrim($publicRoot, '/') . '/uploads/forms/';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
                $this->error('Failed to create upload directory', 500);
            }
        }

        $filename = time() . '_' . bin2hex(random_bytes(6)) . '.' . $fileExt;
        $targetFile = $uploadDir . $filename;

        $moved = false;
        if (@move_uploaded_file($fileInput['tmp_name'], $targetFile)) {
            $moved = true;
        } else {
            if (@copy($fileInput['tmp_name'], $targetFile)) {
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
        $outWebUrl = $this->buildWebFileUrl($filename);
    }

    /**
     * GET /api/v1/forms
     */
    public function index(): void
    {
        try {
            $this->requireAuth();
            $db = $this->dbForModel($this->model);

            $sql = "SELECT f.*, u.id AS creator_id, u.name AS creator_name
                    FROM `forms` f
                    LEFT JOIN `users` u ON u.id = f.user_id
                    ORDER BY f.last_update DESC, f.id DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute();
            $forms = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // fetch versions using versionModel's DB
            $verDb = $this->dbForModel($this->versionModel);
            foreach ($forms as &$f) {
                $stmtV = $verDb->prepare("SELECT * FROM `form_versions` WHERE form_id = :fid ORDER BY created_at DESC");
                $stmtV->bindValue(':fid', (int)$f['id'], \PDO::PARAM_INT);
                $stmtV->execute();
                $f['versions'] = $stmtV->fetchAll(\PDO::FETCH_ASSOC);
            }

            $this->success($forms);
        } catch (\Throwable $e) {
            error_log('FormsController@index error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $this->error('Server error', 500);
        }
    }

    /**
     * GET /api/v1/forms/{id}
     */
    public function show($id): void
    {
        try {
            $this->requireAuth();
            $id = (int)$id;
            $db = $this->dbForModel($this->model);

            $stmt = $db->prepare("SELECT f.*, u.id AS creator_id, u.name AS creator_name
                                  FROM `forms` f
                                  LEFT JOIN `users` u ON u.id = f.user_id
                                  WHERE f.id = :id LIMIT 1");
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
            $stmt->execute();
            $form = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$form) $this->error('Not found', 404);

            $verDb = $this->dbForModel($this->versionModel);
            $stmtV = $verDb->prepare("SELECT * FROM `form_versions` WHERE form_id = :fid ORDER BY created_at DESC");
            $stmtV->bindValue(':fid', $id, \PDO::PARAM_INT);
            $stmtV->execute();
            $form['versions'] = $stmtV->fetchAll(\PDO::FETCH_ASSOC);

            $this->success($form);
        } catch (\Throwable $e) {
            error_log('FormsController@show error: ' . $e->getMessage());
            $this->error('Server error', 500);
        }
    }

    /**
     * POST /api/v1/forms
     */
    public function store(): void
    {
        try {
            $user = $this->requireAuth();
            if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
                $data = $this->jsonInput();
            } else {
                $data = $_POST;
            }

            if (empty($data['title'])) $this->error('title required', 422);

            $fileUrl = null;
            $version = !empty($data['version']) ? trim($data['version']) : '1.0';

            if (!empty($_FILES['file']['name'])) {
                $this->validateAndSaveUploadedFile($_FILES['file'], $fileUrl);
            } elseif (!empty($data['file_url'])) {
                if (!filter_var($data['file_url'], FILTER_VALIDATE_URL)) {
                    $this->error('Invalid URL format', 422);
                }
                $fileUrl = $data['file_url'];
            } else {
                $this->error('File is required (upload or file_url)', 422);
            }

            $db = $this->dbForModel($this->model);
            $verDb = $this->dbForModel($this->versionModel);

            $db->beginTransaction();

            $now = date('Y-m-d H:i:s');

            $insertSql = "INSERT INTO `forms` (title, form_file, version, notes, last_update, user_id)
                          VALUES (:title, :form_file, :version, :notes, :last_update, :user_id)";
            $stmt = $db->prepare($insertSql);
            $stmt->bindValue(':title', trim($data['title']));
            $stmt->bindValue(':form_file', $fileUrl);
            $stmt->bindValue(':version', $version);
            $stmt->bindValue(':notes', $data['notes'] ?? null);
            $stmt->bindValue(':last_update', $now);
            $stmt->bindValue(':user_id', (int)$user['id'], \PDO::PARAM_INT);
            if (!$stmt->execute()) {
                $db->rollBack();
                $this->error('Insert failed', 500);
            }
            $formId = (int)$db->lastInsertId();

            $stmtV = $verDb->prepare("INSERT INTO `form_versions` (form_id, file_url, version, created_at) VALUES (:form_id, :file_url, :version, :created_at)");
            $stmtV->bindValue(':form_id', $formId, \PDO::PARAM_INT);
            $stmtV->bindValue(':file_url', $fileUrl);
            $stmtV->bindValue(':version', $version);
            $stmtV->bindValue(':created_at', $now);
            if (!$stmtV->execute()) {
                $db->rollBack();
                $this->error('Version insert failed', 500);
            }

            $db->commit();

            $this->success($this->model->find($formId), 'Created', 201);
        } catch (\Throwable $e) {
            try {
                $db ?? null;
                if (isset($db) && $db instanceof \PDO && $db->inTransaction()) $db->rollBack();
            } catch (\Throwable $ex) {}
            error_log('FormsController@store error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $this->error('Server error', 500);
        }
    }

    /**
     * PUT /api/v1/forms/{id}
     */
    public function update($id): void
    {
        try {
            $user = $this->requireAuth();
            if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
                $data = $this->jsonInput();
            } else {
                $data = $_POST;
            }

            $id = (int)$id;
            $row = $this->model->find($id);
            if (!$row) $this->error('Not found', 404);

            if ($row['user_id'] != $user['id'] && empty($user['is_admin'])) {
                $this->error('Unauthorized to update this form', 403);
            }

            $fileUrl = $row['form_file'] ?? null;
            $newVersion = null;
            $now = date('Y-m-d H:i:s');

            $db = $this->dbForModel($this->model);
            $verDb = $this->dbForModel($this->versionModel);

            if (!empty($_FILES['file']['name'])) {
                $this->validateAndSaveUploadedFile($_FILES['file'], $fileUrl);

                if (!empty($data['version'])) {
                    $newVersion = trim($data['version']);
                } else {
                    $current = !empty($row['version']) ? $row['version'] : '0.0';
                    $newVersion = $this->bumpMajorVersion($current);
                }

                // Use transaction spanning both DB handles (they may be same connection)
                $db->beginTransaction();

                $stmtV = $verDb->prepare("INSERT INTO `form_versions` (form_id, file_url, version, created_at) VALUES (:form_id, :file_url, :version, :created_at)");
                $stmtV->bindValue(':form_id', $id, \PDO::PARAM_INT);
                $stmtV->bindValue(':file_url', $fileUrl);
                $stmtV->bindValue(':version', $newVersion);
                $stmtV->bindValue(':created_at', $now);
                if (!$stmtV->execute()) {
                    $db->rollBack();
                    $this->error('Failed to insert new version', 500);
                }

                $updateSql = "UPDATE `forms` SET version = :version, form_file = :form_file, last_update = :last_update, notes = :notes WHERE id = :id";
                $stmtU = $db->prepare($updateSql);
                $stmtU->bindValue(':version', $newVersion);
                $stmtU->bindValue(':form_file', $fileUrl);
                $stmtU->bindValue(':last_update', $now);
                $stmtU->bindValue(':notes', $data['notes'] ?? $row['notes']);
                $stmtU->bindValue(':id', $id, \PDO::PARAM_INT);
                if (!$stmtU->execute()) {
                    $db->rollBack();
                    $this->error('Failed to update form record', 500);
                }

                $db->commit();

                $this->success($this->model->find($id), 'Updated');
                return;
            }

            // metadata update only
            $updateData = [];
            if (isset($data['title'])) $updateData['title'] = trim($data['title']);
            if (array_key_exists('notes', $data)) $updateData['notes'] = $data['notes'];
            if (!empty($updateData)) {
                $updateData['last_update'] = $now;
                $sets = [];
                foreach ($updateData as $k => $v) {
                    $sets[] = "`$k` = :$k";
                }
                $sql = "UPDATE `forms` SET " . implode(', ', $sets) . " WHERE id = :id";
                $stmt = $db->prepare($sql);
                foreach ($updateData as $k => $v) {
                    $stmt->bindValue(':' . $k, $v);
                }
                $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
                if (!$stmt->execute()) $this->error('Update failed', 500);
            }

            $this->success($this->model->find($id), 'Updated');
        } catch (\Throwable $e) {
            try {
                if (isset($db) && $db instanceof \PDO && $db->inTransaction()) $db->rollBack();
            } catch (\Throwable $ex) {}
            error_log('FormsController@update error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $this->error('Server error', 500);
        }
    }

    /**
     * DELETE /api/v1/forms/{id}
     */
    public function destroy($id): void
    {
        try {
            $user = $this->requireAuth();
            $id = (int)$id;
            $row = $this->model->find($id);
            if (!$row) $this->error('Not found', 404);

            if ($row['user_id'] != $user['id'] && empty($user['is_admin'])) {
                $this->error('Unauthorized to delete this form', 403);
            }

            $verDb = $this->dbForModel($this->versionModel);
            $stmtV = $verDb->prepare("SELECT * FROM `form_versions` WHERE form_id = :fid");
            $stmtV->bindValue(':fid', $id, \PDO::PARAM_INT);
            $stmtV->execute();
            $versions = $stmtV->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($versions as $v) {
                if (!empty($v['file_url'])) {
                    $path = $this->resolveFilesystemPathFromFileUrl($v['file_url']);
                    if ($path && file_exists($path)) {
                        @unlink($path);
                    }
                }
            }

            $db = $this->dbForModel($this->model);
            $db->beginTransaction();

            $delV = $verDb->prepare("DELETE FROM `form_versions` WHERE form_id = :fid");
            $delV->bindValue(':fid', $id, \PDO::PARAM_INT);
            if (!$delV->execute()) {
                $db->rollBack();
                $this->error('Failed to delete versions', 500);
            }

            $del = $db->prepare("DELETE FROM `forms` WHERE id = :id");
            $del->bindValue(':id', $id, \PDO::PARAM_INT);
            if (!$del->execute()) {
                $db->rollBack();
                $this->error('Delete failed', 500);
            }

            $db->commit();
            $this->success(null, 'Deleted', 200);
        } catch (\Throwable $e) {
            try {
                if (isset($db) && $db instanceof \PDO && $db->inTransaction()) $db->rollBack();
            } catch (\Throwable $ex) {}
            error_log('FormsController@destroy error: ' . $e->getMessage());
            $this->error('Server error', 500);
        }
    }

    /**
     * GET /api/v1/forms/my
     */
    public function myForms(): void
    {
        try {
            $user = $this->requireAuth();
            $db = $this->dbForModel($this->model);

            $stmt = $db->prepare("SELECT f.*, u.id AS creator_id, u.name AS creator_name
                                  FROM `forms` f
                                  LEFT JOIN `users` u ON u.id = f.user_id
                                  WHERE f.user_id = :uid
                                  ORDER BY f.last_update DESC");
            $stmt->bindValue(':uid', (int)$user['id'], \PDO::PARAM_INT);
            $stmt->execute();
            $forms = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $verDb = $this->dbForModel($this->versionModel);
            foreach ($forms as &$f) {
                $stmtV = $verDb->prepare("SELECT * FROM `form_versions` WHERE form_id = :fid ORDER BY created_at DESC");
                $stmtV->bindValue(':fid', (int)$f['id'], \PDO::PARAM_INT);
                $stmtV->execute();
                $f['versions'] = $stmtV->fetchAll(\PDO::FETCH_ASSOC);
            }

            $this->success($forms);
        } catch (\Throwable $e) {
            error_log('FormsController@myForms error: ' . $e->getMessage());
            $this->error('Server error', 500);
        }
    }

    /**
     * GET /api/v1/forms/search?q=...
     */
    public function search(): void
    {
        try {
            $this->requireAuth();
            $q = $_GET['q'] ?? '';
            if (trim($q) === '') $this->error('Search term required', 422);

            $db = $this->dbForModel($this->model);
            $sql = "SELECT f.*, u.id AS creator_id, u.name AS creator_name
                    FROM `forms` f
                    LEFT JOIN `users` u ON u.id = f.user_id
                    WHERE title LIKE :s OR notes LIKE :s
                    ORDER BY last_update DESC";
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':s', '%' . $q . '%');
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $verDb = $this->dbForModel($this->versionModel);
            foreach ($rows as &$r) {
                $stmtV = $verDb->prepare("SELECT * FROM `form_versions` WHERE form_id = :fid ORDER BY created_at DESC");
                $stmtV->bindValue(':fid', (int)$r['id'], \PDO::PARAM_INT);
                $stmtV->execute();
                $r['versions'] = $stmtV->fetchAll(\PDO::FETCH_ASSOC);
            }

            $this->success($rows);
        } catch (\Throwable $e) {
            error_log('FormsController@search error: ' . $e->getMessage());
            $this->error('Server error', 500);
        }
    }
}
