<?php
namespace App\Controllers;

use App\Models\Sop;
use App\Models\SopFile;
use App\Models\Wing;
use App\Models\SubWing;

/**
 * SopsController
 *
 * Versioning rules:
 *  - Creating a SOP with a file: SOP.version = provided || '1.0'; create one sop_files row with same version.
 *  - Uploading a new file for SOP: newVersion = client-provided || bumpMajor(latest sop_file.version || sop.version || '0.0')
 *    -> update SOP.version and file_url, then create exactly one sop_files row with newVersion and timestamp matching SOP.updated_at.
 *  - Never mass-update existing sop_files rows.
 *  - Timestamps are integer epoch seconds.
 */
class SopsController extends BaseController
{
    protected Sop $model;
    protected SopFile $fileModel;
    protected Wing $wingModel;
    protected SubWing $subwingModel;
    protected string $uploadDir;

    public function __construct()
    {
        parent::__construct();
        $this->model = new Sop();
        $this->fileModel = new SopFile();
        $this->wingModel = new Wing();
        $this->subwingModel = new SubWing();

        // Resolve the correct public webroot path in a robust order:
        // 1. DOCUMENT_ROOT (most shared hostings / cPanel setups)
        // 2. public_html directory relative to project root
        // 3. public directory relative to project root
        // 4. fallback to relative public path (last resort)
        $basePublic = null;

        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
        if ($docRoot && is_dir($docRoot)) {
            $basePublic = $docRoot;
        } else {
            $publicHtmlPath = realpath(__DIR__ . '/../../public_html');
            if ($publicHtmlPath && is_dir($publicHtmlPath)) {
                $basePublic = $publicHtmlPath;
            } else {
                $publicPath = realpath(__DIR__ . '/../../public');
                if ($publicPath && is_dir($publicPath)) {
                    $basePublic = $publicPath;
                } else {
                    // last resort: keep original behaviour (attempt to use public even if realpath failed)
                    $basePublic = __DIR__ . '/../../public';
                }
            }
        }

        // Final uploads directory path (absolute)
        $this->uploadDir = rtrim($basePublic, '/\\') . '/uploads/sop/';
        if (!is_dir($this->uploadDir)) {
            // Attempt to create directory with proper permissions
            if (!@mkdir($this->uploadDir, 0755, true)) {
                // If creation failed, log an error so deployer can inspect permissions
                error_log("SopsController: failed to create upload directory {$this->uploadDir}");
            }
        }
    }

    /* -------------------------
     * Public endpoints
     * ------------------------- */

    public function index()
    {
        $params = $_GET ?? [];
        $limit = isset($params['limit']) ? (int)$params['limit'] : 100;
        $offset = isset($params['offset']) ? (int)$params['offset'] : 0;

        $where = [];
        if (!empty($params['wing_id'])) $where['wing_id'] = (int)$params['wing_id'];
        if (!empty($params['subw_id'])) $where['subw_id'] = (int)$params['subw_id'];
        if (!empty($params['visibility'])) $where['visibility'] = $params['visibility'];

        $rows = $this->model->where($where, $limit, $offset, ['updated_at' => 'DESC']);

        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->attachRelations($r);
        }

        $this->jsonResponse(200, $out);
    }

    public function show($id)
    {
        $sop = $this->model->find((int)$id);
        if (!$sop) {
            return $this->jsonResponse(404, ['error' => 'SOP not found']);
        }
        $this->jsonResponse(200, $this->attachRelations($sop));
    }

    /**
     * Create SOP (with optional initial file)
     * POST /api/v1/sops
     */
    public function store()
    {
        $now = date('Y-m-d H:i:s');
        $input = $this->getInputData();

        if (empty($input['title']) || empty($input['wing_id']) || empty($input['subw_id']) || empty($input['visibility'])) {
            return $this->jsonResponse(422, ['error' => 'Missing required fields: title, wing_id, subw_id, visibility']);
        }

        // handle optional file
        $uploadedFilePath = null;
        if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $uploadedFilePath = $this->handleFileUpload($_FILES['file']);
            if ($uploadedFilePath === false) {
                return $this->jsonResponse(500, ['error' => 'Failed to store uploaded file']);
            }
        } elseif (!empty($input['file_url'])) {
            $uploadedFilePath = $input['file_url'];
        }

        $version = !empty($input['version']) ? (string)$input['version'] : '1.0';

        $sopData = [
            'title'      => $input['title'],
            'version'    => $version,
            'file_url'   => $uploadedFilePath ?? null,
            'wing_id'    => (int)$input['wing_id'],
            'subw_id'    => (int)$input['subw_id'],
            'visibility' => $input['visibility'],
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $created = $this->model->create($sopData);
        if (!$created) {
            return $this->jsonResponse(500, ['error' => 'Failed to create SOP']);
        }

        $sopId = $this->extractIdFromCreateResult($created);
        if ($sopId <= 0) {
            // fallback attempt
            $maybe = $this->model->where(['title' => $sopData['title'], 'created_at' => $sopData['created_at']], 1, 0);
            if (!empty($maybe) && !empty($maybe[0]['id'])) $sopId = (int)$maybe[0]['id'];
        }

        // If file present, create exactly one sop_files row with same version and timestamp matching updated_at
        if ($uploadedFilePath && $sopId > 0) {
            $timestamp = (int)strtotime($now);

            // create a fresh row — IMPORTANT: use create() to insert only this new row.
            // This guarantees we do NOT update any existing sop_files rows.
            $fileRow = [
                'title'      => $sopData['title'],
                'file_url'   => $uploadedFilePath,
                'sop_id'     => $sopId,
                'version'    => $version,
                'timestamp'   => $timestamp,
                // storing readable datetime matching SOP.updated_at
                'created_at' => date('Y-m-d H:i:s', $timestamp),
            ];

            // Direct insert of new file row (will not modify any historical rows)
            $this->fileModel->create($fileRow);
        }

        $newSop = $this->model->find($sopId);
        return $this->jsonResponse(201, $this->attachRelations($newSop));
    }

    /**
     * Update SOP metadata and/or upload new file.
     * PUT/PATCH /api/v1/sops/{id}
     *
     * IMPORTANT: only new sop_files row gets the new version; no historical rows are changed.
     */
    public function update($id)
    {
        $sop = $this->model->find((int)$id);
        if (!$sop) {
            return $this->jsonResponse(404, ['error' => 'SOP not found']);
        }

        $input = $this->getInputData();
        $now = date('Y-m-d H:i:s');

        // Collect metadata fields
        $updateData = [];
        foreach (['title', 'wing_id', 'subw_id', 'visibility'] as $f) {
            if (isset($input[$f])) {
                $updateData[$f] = in_array($f, ['wing_id', 'subw_id']) ? (int)$input[$f] : $input[$f];
            }
        }

        // Check if a new file is being uploaded
        $isNewFile = (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) || !empty($input['file_url']);

        if ($isNewFile) {
            // Save uploaded file first
            if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $fileUrl = $this->handleFileUpload($_FILES['file']);
                if ($fileUrl === false) {
                    return $this->jsonResponse(500, ['error' => 'Failed to store uploaded file']);
                }
            } else {
                $fileUrl = $input['file_url'];
            }

            // Compute newVersion:
            // If client provided version param, use it. Otherwise, derive from latest sop_file or sop.version
            if (!empty($input['version'])) {
                $newVersion = (string)$input['version'];
            } else {
                $latestFile = $this->fileModel->getLatestForSop((int)$id);
                if ($latestFile && !empty($latestFile['version'])) {
                    $newVersion = $this->bumpMajorManual((string)$latestFile['version']);
                } else {
                    // If no sop_files exist, base from sop['version'] (which might be '1.0') and bump
                    $baseVersion = $sop['version'] ?? '0.0';
                    $newVersion = $this->bumpMajorManual($baseVersion);
                }
            }

            // Save previous sop state for rollback attempt
            $prevSop = $sop;

            // Update SOP row first (this updates the sop table only)
            $updateData['version'] = $newVersion;
            $updateData['file_url'] = $fileUrl;
            $updateData['updated_at'] = $now;

            $updated = $this->model->update((int)$id, $updateData);
            if (!$updated) {
                return $this->jsonResponse(500, ['error' => 'Failed to update SOP']);
            }

            // Create the new sop_files row with timestamp exactly matching SOP.updated_at
            $timestamp = (int)strtotime($now);

            // IMPORTANT: insert only this new file row (do NOT alter existing sop_files rows)
            $sopFileRow = [
                'title'      => $updateData['title'] ?? $prevSop['title'],
                'file_url'   => $fileUrl,
                'sop_id'     => (int)$id,
                'version'    => $newVersion,
                'timestamp'   => $timestamp,
                // readable created_at matching SOP.updated_at
                'created_at' => date('Y-m-d H:i:s', $timestamp),
            ];

            // Direct insert of new file row (ensures historical rows remain unchanged)
            $createdFile = $this->fileModel->create($sopFileRow);

            if (!$createdFile) {
                // best-effort rollback: restore previous SOP record
                try {
                    $this->model->update((int)$id, [
                        'version'  => $prevSop['version'] ?? null,
                        'file_url' => $prevSop['file_url'] ?? null,
                        'updated_at' => $prevSop['updated_at'] ?? $now,
                    ]);
                } catch (\Throwable $e) {
                    // ignore rollback failure
                }
                return $this->jsonResponse(500, ['error' => 'Failed to create sop_files record']);
            }

            $newSop = $this->model->find((int)$id);
            return $this->jsonResponse(200, $this->attachRelations($newSop));
        } else {
            // No new file: only update metadata (do NOT touch version or sop_files)
            if (!empty($updateData)) {
                $updateData['updated_at'] = $now;
                $ok = $this->model->update((int)$id, $updateData);
                if (!$ok) return $this->jsonResponse(500, ['error' => 'Failed to update SOP']);
            }
            $newSop = $this->model->find((int)$id);
            return $this->jsonResponse(200, $this->attachRelations($newSop));
        }
    }

    public function delete($id)
    {
        $sop = $this->model->find((int)$id);
        if (!$sop) {
            return $this->jsonResponse(404, ['error' => 'SOP not found']);
        }

        // Delete sop_files rows and local files (best-effort)
        try {
            $files = $this->fileModel->bySop((int)$id, 1000, 0);
            foreach ($files as $f) {
                if (!empty($f['file_url'])) $this->unlinkLocalFile($f['file_url']);
                if (!empty($f['id'])) $this->fileModel->delete((int)$f['id']);
            }
        } catch (\Throwable $e) {
            // ignore
        }

        if (!empty($sop['file_url'])) $this->unlinkLocalFile($sop['file_url']);

        $deleted = $this->model->delete((int)$id);
        if (!$deleted) {
            return $this->jsonResponse(500, ['error' => 'Failed to delete SOP']);
        }

        return $this->jsonResponse(200, ['message' => 'SOP deleted']);
    }

    /**
     * List files for a SOP (returns SOP with files)
     */
    public function files($id)
    {
        $sop = $this->model->find((int)$id);
        if (!$sop) return $this->jsonResponse(404, ['error' => 'SOP not found']);

        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 1000;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $files = $this->fileModel->bySop((int)$id, $limit, $offset);

        $sopWithRelations = $this->attachRelations($sop);
        $sopWithRelations['files'] = $files;

        return $this->jsonResponse(200, $sopWithRelations);
    }

    public function latestFile($id)
    {
        $sop = $this->model->find((int)$id);
        if (!$sop) return $this->jsonResponse(404, ['error' => 'SOP not found']);

        $latest = $this->fileModel->getLatestForSop((int)$id);
        $out = $this->attachRelations($sop);
        $out['latest_file'] = $latest ?: null;
        return $this->jsonResponse(200, $out);
    }

    /* -------------------------
     * Helpers
     * ------------------------- */

    protected function jsonResponse(int $status, $data)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }

    /**
     * Attach wing, subwing, files (all) and latest_file to a SOP row
     */
    protected function attachRelations(array $sop): array
    {
        $sopId = (int)($sop['id'] ?? 0);

        $wing = null;
        if (!empty($sop['wing_id'])) $wing = $this->wingModel->find((int)$sop['wing_id']);

        $subwing = null;
        if (!empty($sop['subw_id'])) $subwing = $this->subwingModel->find((int)$sop['subw_id']);

        $files = [];
        if ($sopId > 0) $files = $this->fileModel->bySop($sopId, 1000, 0);

        $latest = null;
        if ($sopId > 0) $latest = $this->fileModel->getLatestForSop($sopId);

        $sop['wing'] = $wing;
        $sop['subwing'] = $subwing;
        $sop['files'] = $files;
        $sop['latest_file'] = $latest;

        return $sop;
    }

    /**
     * Robust file upload handler:
     *  - ensures target directory exists
     *  - supports move_uploaded_file and falls back to copy if necessary
     *  - sets file permissions
     *  - returns relative path usable by the app (e.g. 'uploads/sop/abc.pdf') or false on failure
     */
    protected function handleFileUpload(array $file)
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            error_log("SopsController: upload error code " . ($file['error'] ?? 'n/a'));
            return false;
        }

        // Ensure tmp file is valid
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            // Some environments may not mark as is_uploaded_file; still try to proceed but log.
            error_log("SopsController: tmp file invalid or not an uploaded file: " . ($file['tmp_name'] ?? 'n/a'));
            // continue — we'll attempt to copy anyway, because some hostings behave differently
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $ext = $ext ? '.' . strtolower($ext) : '';

        // create a reasonably unique filename
        try {
            $random = bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            $random = substr(md5(uniqid((string)time(), true)), 0, 16);
        }
        $filename = time() . '_' . $random . $ext;

        // Ensure uploadDir ends with slash
        $uploadDir = rtrim($this->uploadDir, '/\\') . '/';
        if (!is_dir($uploadDir)) {
            if (!@mkdir($uploadDir, 0755, true)) {
                error_log("SopsController: failed to create directory {$uploadDir}");
                return false;
            }
        }

        $dest = $uploadDir . $filename;

        // Try move_uploaded_file first
        if (isset($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
            if (!@move_uploaded_file($file['tmp_name'], $dest)) {
                error_log("SopsController: move_uploaded_file failed for tmp_name {$file['tmp_name']} to {$dest}");
                // fallback to copy
                if (!@copy($file['tmp_name'], $dest)) {
                    error_log("SopsController: fallback copy also failed for {$file['tmp_name']} to {$dest}");
                    return false;
                } else {
                    // attempt to unlink tmp if possible
                    if (file_exists($file['tmp_name'])) {
                        @unlink($file['tmp_name']);
                    }
                }
            }
        } else {
            // Not recognized as uploaded file; try to copy (some environments use different flow)
            if (!@copy($file['tmp_name'], $dest)) {
                error_log("SopsController: copy failed for tmp_name {$file['tmp_name']} to {$dest}");
                return false;
            }
        }

        // Set permissions so web server can serve the file
        @chmod($dest, 0644);

        // Return relative path used by application
        return 'uploads/sop/' . $filename;
    }

    protected function unlinkLocalFile(string $relativePath)
    {
        $relativePath = ltrim($relativePath, '/');

        // Try to locate file in DOCUMENT_ROOT first (most reliable on hosting)
        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
        if ($docRoot) {
            $full = $docRoot . '/' . $relativePath;
            if ($full && file_exists($full)) {
                @unlink($full);
                return;
            }
        }

        // Next try public_html relative to project
        $publicHtmlFull = realpath(__DIR__ . '/../../public_html');
        if ($publicHtmlFull && is_dir($publicHtmlFull)) {
            $full = rtrim($publicHtmlFull, '/\\') . '/' . $relativePath;
            if ($full && file_exists($full)) {
                @unlink($full);
                return;
            }
        }

        // Next try public relative to project
        $publicFull = realpath(__DIR__ . '/../../public');
        if ($publicFull && is_dir($publicFull)) {
            $full = rtrim($publicFull, '/\\') . '/' . $relativePath;
            if ($full && file_exists($full)) {
                @unlink($full);
                return;
            }
        }

        // Last-resort: try the uploadDir path we computed earlier
        $uploadFull = rtrim($this->uploadDir, '/\\') . '/' . basename($relativePath);
        if (file_exists($uploadFull)) {
            @unlink($uploadFull);
        }
    }

    protected function getInputData(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $data = [];

        if (stripos($contentType, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true) ?: [];
        } else {
            $data = $_POST ?? [];
        }

        if (isset($data['wing_id'])) $data['wing_id'] = (int)$data['wing_id'];
        if (isset($data['subw_id'])) $data['subw_id'] = (int)$data['subw_id'];

        return $data;
    }

    /**
     * Major bump: '1.0' -> '2.0'
     */
    protected function bumpMajorManual(string $currentVersion): string
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $currentVersion, $m)) {
            $major = (int)($m[1] ?? 0);
            $major++;
            return "{$major}.0";
        }
        return '1.0';
    }

    protected function extractIdFromCreateResult($created)
    {
        if (is_int($created) || (is_string($created) && ctype_digit($created))) return (int)$created;
        if (is_array($created) && !empty($created['id'])) return (int)$created['id'];
        if (is_object($created) && property_exists($created, 'id')) return (int)$created->id;
        return 0;
    }
}
