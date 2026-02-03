<?php
namespace App\Controllers;

use App\Models\Sop;
use App\Models\SopFile;
use DateTimeImmutable;
use DateTimeZone;

class SopFilesController extends BaseController
{
    protected Sop $sopModel;
    protected SopFile $sopFileModel;

    // Allowed mime mapping (reuse / extend from Helpdesk)
    protected array $allowedMime = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/csv' => 'csv',
        'text/plain' => 'txt',
        'application/zip' => 'zip',
        'application/x-rar-compressed' => 'rar',
        'application/x-7z-compressed' => '7z',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'video/mp4' => 'mp4',
        'video/x-msvideo' => 'avi',
        'video/x-matroska' => 'mkv',
        'application/octet-stream' => null,
    ];

    protected int $maxFileSize = 30 * 1024 * 1024; // 30 MB
    protected string $uploadRelativeDir = 'uploads/sops';

    public function __construct()
    {
        parent::__construct();
        $this->sopModel = new Sop();
        $this->sopFileModel = new SopFile();
    }

    protected function now(): string
    {
        $tz = new DateTimeZone('Asia/Dhaka');
        return (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
    }

    /**
     * POST /api/v1/sops/{id}/files
     * Upload a new file for SOP $id OR accept JSON with file_url to create a SopFile that references an external link.
     * Creates sop_files record, updates parent sop.file_url and sop.version to next major version.
     */
    public function upload($sopId): void
    {
        $this->requireAuth();
        $sopId = (int)$sopId;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        $input = [];
        $filePath = null;

        try {
            $sop = $this->sopModel->find($sopId);
            if (!$sop) $this->error('SOP not found', 404);

            if (strpos(strtolower($contentType), 'multipart/form-data') !== false) {
                $input = $_POST;
                if (!empty($_FILES['file']) && isset($_FILES['file']['error']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                    $filePath = $this->handleFileUpload($_FILES['file']);
                    if (!$filePath) $this->error('File upload failed', 422);
                } elseif (!empty($_FILES['file']) && isset($_FILES['file']['error'])) {
                    error_log('SOP file upload error: ' . $_FILES['file']['error']);
                }
            } else {
                $input = $this->jsonInput();
            }

            // If client provided external file_url
            if (isset($input['file_url']) && !$filePath) {
                $trim = trim((string)$input['file_url']);
                if ($trim !== '') $filePath = $trim;
            }

            if (!$filePath) {
                $this->error('No file uploaded and no file_url provided', 422);
            }

            // compute next major version
            $currentVersion = $sop['version'] ?? '0.0';
            $nextVersion = $this->computeNextMajorVersion($currentVersion);

            // create sop_file record
            $fileData = [
                'title' => $input['title'] ?? ($sop['title'] . ' (v' . $nextVersion . ')'),
                'file_url' => $filePath,
                'sop_id' => $sopId,
                'timestamp' => time(),
                'version' => $nextVersion,
            ];

            $created = $this->sopFileModel->createWithVersion($fileData, $nextVersion);
            if (!$created) {
                error_log("SopFilesController::upload failed to create sop_file for sop_id={$sopId}");
                $this->error('Failed to create sop file record', 500);
            }

            // remove old internal file if necessary
            if (!empty($sop['file_url'])) {
                $old = $sop['file_url'];
                if (strpos($old, trim($this->uploadRelativeDir, '/') . '/') === 0 || strpos($old, 'uploads/sops/') === 0) {
                    $fullOld = rtrim($this->getUploadFullPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($old);
                    if (file_exists($fullOld) && is_file($fullOld)) {
                        @unlink($fullOld);
                    }
                }
            }

            // update SOP record with new file_url and version
            $this->sopModel->update($sopId, ['file_url' => $filePath, 'version' => $nextVersion, 'updated_at' => $this->now()]);

            $created['sop'] = $this->sopModel->find($sopId);

            $this->success($created, 'SOP file uploaded and SOP updated with new version', 201);
        } catch (\Throwable $e) {
            error_log('SopFilesController::upload error: ' . $e->getMessage());
            $this->error('Server error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v1/sops/{id}/files
     * List files for a given SOP
     */
    public function listBySop($sopId): void
    {
        $this->requireAuth();
        $sopId = (int)$sopId;

        try {
            $sop = $this->sopModel->find($sopId);
            if (!$sop) $this->error('SOP not found', 404);

            $files = $this->sopFileModel->bySop($sopId, 1000, 0);
            $this->success(['data' => $files, 'total' => count($files)]);
        } catch (\Throwable $e) {
            error_log('SopFilesController::listBySop error: ' . $e->getMessage());
            $this->error('Failed to list SOP files: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v1/sop-files/{id}
     * If sop_file.file_url is external -> redirect to URL
     * If internal -> stream the file with appropriate headers
     */
    public function download($id): void
    {
        $this->requireAuth();
        $id = (int)$id;

        try {
            $file = $this->sopFileModel->find($id);
            if (!$file) $this->error('SOP file not found', 404);

            $url = $file['file_url'] ?? null;
            if (!$url) $this->error('File URL not set', 404);

            // External link -> redirect
            if (preg_match('#^https?://#i', $url) || preg_match('#^//#', $url)) {
                header('Location: ' . $url);
                exit;
            }

            // Otherwise assume internal uploads path like 'uploads/sops/filename.ext'
            $fullDir = rtrim($this->getUploadFullPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            $filename = basename($url);
            $fullPath = $fullDir . $filename;

            if (!file_exists($fullPath) || !is_file($fullPath)) {
                $this->error('File not found on server', 404);
            }

            // Get mime type
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($fullPath) ?: 'application/octet-stream';
            // Send headers and stream
            header('Content-Description: File Transfer');
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . basename($fullPath) . '"');
            header('Content-Length: ' . filesize($fullPath));
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');
            header('Expires: 0');

            // Clear output buffer and stream
            if (ob_get_level()) ob_end_clean();
            readfile($fullPath);
            exit;
        } catch (\Throwable $e) {
            error_log('SopFilesController::download error: ' . $e->getMessage());
            $this->error('Failed to download file: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/v1/sop-files/{id}
     * Delete a sop_file record and optionally its internal file.
     * If deleted file was parent's sop.file_url, update parent SOP to next-latest file (or null).
     */
    public function destroy($id): void
    {
        $this->requireAuth();
        $id = (int)$id;

        try {
            $file = $this->sopFileModel->find($id);
            if (!$file) $this->error('SOP file not found', 404);

            $sopId = (int)$file['sop_id'];
            $sop = $this->sopModel->find($sopId);

            // delete internal file if it is an internal uploads path
            $fileUrl = $file['file_url'] ?? null;
            if (!empty($fileUrl) && (strpos($fileUrl, trim($this->uploadRelativeDir, '/') . '/') === 0 || strpos($fileUrl, 'uploads/sops/') === 0)) {
                $full = rtrim($this->getUploadFullPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($fileUrl);
                if (file_exists($full) && is_file($full)) {
                    @unlink($full);
                }
            }

            $ok = $this->sopFileModel->delete($id);
            if (!$ok) $this->error('Failed to delete sop file', 500);

            // If this was the current SOP file_url, set SOP file_url+version to next latest or null
            if ($sop && isset($sop['file_url']) && $sop['file_url'] === $fileUrl) {
                // get all files for sop, excluding deleted id (we already deleted it)
                $all = $this->sopFileModel->bySop($sopId, 1000, 0);
                $next = null;
                if (!empty($all)) {
                    // bySop returns newest-first, so first item is the replacement
                    $next = $all[0];
                }

                $update = [
                    'file_url' => $next ? $next['file_url'] : null,
                    'version' => $next ? $next['version'] : null,
                    'updated_at' => $this->now(),
                ];
                $this->sopModel->update($sopId, $update);
            }

            $this->success(null, 'SOP file deleted successfully', 200);
        } catch (\Throwable $e) {
            error_log('SopFilesController::destroy error: ' . $e->getMessage());
            $this->error('Server error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Compute next major version: e.g. 1.0 -> 2.0
     */
    protected function computeNextMajorVersion(string $currentVersion): string
    {
        $currentVersion = $currentVersion ?: '0.0';
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $currentVersion, $m)) {
            $major = (int)($m[1] ?? 0);
            $major++;
            return "{$major}.0";
        }
        return '1.0';
    }

    /**
     * Handle file upload (store in public_html/uploads/sops)
     * Returns relative path (uploads/sops/filename) on success or null on failure.
     */
    private function handleFileUpload(array $file): ?string
    {
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if (!empty($docRoot)) {
            $uploadDir = rtrim(realpath($docRoot), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'sops' . DIRECTORY_SEPARATOR;
            $publicPathPrefix = 'uploads/sops/';
        } else {
            $projectRoot = realpath(dirname(__DIR__, 2));
            $uploadDir = $projectRoot . DIRECTORY_SEPARATOR . 'public_html' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'sops' . DIRECTORY_SEPARATOR;
            $publicPathPrefix = 'uploads/sops/';
        }

        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            error_log('SOP file upload error code: ' . ($file['error'] ?? 'n/a'));
            return null;
        }

        if (!isset($file['tmp_name']) || $file['tmp_name'] === '') {
            error_log('No tmp_name for uploaded SOP file');
            return null;
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            error_log('Uploaded SOP file not recognized as HTTP upload: ' . $file['tmp_name']);
        }

        if (!isset($file['size']) || (int)$file['size'] > $this->maxFileSize) {
            error_log('Uploaded SOP file too large: ' . ($file['size'] ?? 'n/a'));
            return null;
        }

        if (!is_dir($uploadDir)) {
            if (!@mkdir($uploadDir, 0755, true)) {
                error_log('Failed to create SOP upload directory: ' . $uploadDir);
                return null;
            }
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($file['tmp_name']);
        if ($detected === false) {
            error_log('finfo failed to detect mime-type for SOP file ' . $file['tmp_name']);
            return null;
        }

        $ext = null;
        if (isset($this->allowedMime[$detected]) && $this->allowedMime[$detected] !== null) {
            $ext = $this->allowedMime[$detected];
        } elseif ($detected === 'application/octet-stream') {
            $origExt = pathinfo($file['name'], PATHINFO_EXTENSION);
            $origExt = preg_replace('/[^a-z0-9]+/i', '', $origExt);
            if ($origExt !== '') $ext = strtolower($origExt);
            else $ext = null;
        } else {
            error_log('SOP file type not allowed: ' . $detected . ' (reported: ' . ($file['type'] ?? 'n/a') . ')');
            return null;
        }

        if (empty($ext)) {
            error_log('Could not determine file extension for SOP mime: ' . $detected);
            return null;
        }

        try {
            $filename = uniqid('sop_', true) . '.' . $ext;
        } catch (\Throwable $e) {
            $filename = uniqid('sop_') . '.' . $ext;
        }

        $destination = $uploadDir . $filename;

        if (@move_uploaded_file($file['tmp_name'], $destination) === false) {
            if (!@rename($file['tmp_name'], $destination)) {
                error_log('Failed to move uploaded SOP file to destination: ' . $destination . ' tmp: ' . $file['tmp_name']);
                return null;
            }
        }

        @chmod($destination, 0644);
        return $publicPathPrefix . $filename;
    }

    /**
     * Return filesystem path for uploads/sops (prefers DOCUMENT_ROOT/public_html)
     */
    protected function getUploadFullPath(): string
    {
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if (!empty($docRoot)) {
            $real = realpath($docRoot);
            if ($real !== false) {
                return rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'sops';
            }
        }

        $projectRoot = realpath(dirname(__DIR__, 2));
        if ($projectRoot !== false) {
            $cand = $projectRoot . DIRECTORY_SEPARATOR . 'public_html' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'sops';
            if (is_dir($cand) || @mkdir($cand, 0755, true)) return rtrim($cand, DIRECTORY_SEPARATOR);
            $cand2 = $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'sops';
            if (is_dir($cand2) || @mkdir($cand2, 0755, true)) return rtrim($cand2, DIRECTORY_SEPARATOR);
        }

        return rtrim(__DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . trim($this->uploadRelativeDir, '/');
    }
}
