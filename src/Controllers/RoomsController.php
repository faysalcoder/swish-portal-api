<?php
namespace App\Controllers;

use App\Models\Room;
use PDOException;

class RoomsController extends BaseController
{
    protected Room $model;

    // allowed extensions (normalized to use these values)
    protected array $allowedExtensions = ['png', 'jpg', 'jpeg'];
    protected int $maxFileSize = 2 * 1024 * 1024; // 2 MB
    protected string $uploadRelativeDir = 'uploads/rooms';
    // Use DB column name
    protected string $imageField = 'room_img';

    public function __construct()
    {
        parent::__construct();
        $this->model = new Room();
    }

    public function index(): void
    {
        $this->requireAuth();
        $rows = $this->model->all(1000, 0);
        $rows = array_map([$this, 'normalizeImageUrlForRecord'], $rows);
        $this->success($rows);
    }

    public function show($id): void
    {
        $this->requireAuth();
        $row = $this->model->find((int)$id);
        if (!$row) {
            $this->error('Not found', 404);
            return;
        }
        $row = $this->normalizeImageUrlForRecord($row);
        $this->success($row);
    }

    public function store(): void
    {
        $this->requireAuth();

        $data = $this->jsonInput();

        $roomData = [];

        // name required
        $name = isset($_POST['name']) ? trim($_POST['name']) : (isset($data['name']) ? trim($data['name']) : null);
        if (empty($name)) {
            $this->error('Name required', 422);
            return;
        }
        $roomData['name'] = $name;

        // optional fields
        $optionalFields = ['capacity', 'sitting', 'presentation'];
        foreach ($optionalFields as $f) {
            if (isset($_POST[$f])) {
                $roomData[$f] = $_POST[$f];
            } elseif (isset($data[$f])) {
                $roomData[$f] = $data[$f];
            }
        }

        // Image handling (simple)
        $roomData[$this->imageField] = null;
        if (isset($_FILES[$this->imageField]) && $_FILES[$this->imageField]['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES[$this->imageField];

            // size check
            if (!isset($file['size']) || (int)$file['size'] > $this->maxFileSize) {
                $this->error('File too large. Max ' . ($this->maxFileSize / 1024 / 1024) . ' MB.', 422);
                return;
            }

            // detect mime type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            $ext = null;
            if ($mime === 'image/png') $ext = 'png';
            elseif ($mime === 'image/jpeg') $ext = 'jpg';

            // fallback to original extension if allowed
            if ($ext === null) {
                $origExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (in_array($origExt, $this->allowedExtensions, true)) {
                    $ext = $origExt === 'jpeg' ? 'jpg' : $origExt;
                }
            }

            if ($ext === null || !in_array($ext, $this->allowedExtensions, true)) {
                $this->error('Unsupported file type. Allowed: png, jpg, jpeg.', 422);
                return;
            }

            $this->ensureUploadDirectoryExists();

            // generate safe filename
            try {
                $filename = sprintf('room_%s_%s.%s', time(), bin2hex(random_bytes(4)), $ext);
            } catch (\Exception $e) {
                $filename = sprintf('room_%s_%s.%s', time(), uniqid(), $ext);
            }

            $destDir = $this->getUploadFullPath();
            $destinationPath = rtrim($destDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

            // Attempt to move the uploaded file to the correct target (with fallbacks)
            $moved = false;
            if (is_uploaded_file($file['tmp_name'])) {
                if (@move_uploaded_file($file['tmp_name'], $destinationPath)) {
                    $moved = true;
                } elseif (@copy($file['tmp_name'], $destinationPath)) {
                    // some hosts restrict move_uploaded_file but copy works
                    $moved = true;
                }
            } else {
                // In rare hosts tmp file isn't available via is_uploaded_file; try direct move/copy
                if (@move_uploaded_file($file['tmp_name'], $destinationPath)) {
                    $moved = true;
                } elseif (@copy($file['tmp_name'], $destinationPath)) {
                    $moved = true;
                }
            }

            // Extra safety: some hosts accidentally put the uploaded file into the public root (e.g. public/<filename>)
            // Try to detect and relocate it if present there (check both generated filename and original filename).
            if (!$moved) {
                $publicRoot = $this->resolvePublicRoot();
                $altCandidates = [
                    $publicRoot . DIRECTORY_SEPARATOR . $filename,
                    $publicRoot . DIRECTORY_SEPARATOR . basename($file['name']),
                ];
                foreach ($altCandidates as $alt) {
                    if ($alt && file_exists($alt)) {
                        // attempt to rename into the proper folder
                        if (@rename($alt, $destinationPath)) {
                            $moved = true;
                            break;
                        } else {
                            // try copy then unlink
                            if (@copy($alt, $destinationPath)) {
                                @unlink($alt);
                                $moved = true;
                                break;
                            }
                        }
                    }
                }
            }

            if (!$moved || !file_exists($destinationPath)) {
                error_log('Failed to move/copy uploaded file to expected destination: ' . $destinationPath);
                $this->error('Failed saving uploaded file.', 500);
                return;
            }

            @chmod($destinationPath, 0644);

            // store web-relative path with leading slash (so DB becomes '/uploads/rooms/filename.ext')
            $relativePath = '/' . trim($this->uploadRelativeDir, '/') . '/' . $filename;
            $roomData[$this->imageField] = $relativePath;
        }

        // optional: set created_at (DB has default), but harmless if you set it
        $roomData['created_at'] = date('Y-m-d H:i:s');

        // sanitize payload
        $roomData = $this->sanitizeRoomPayload($roomData);

        try {
            $id = $this->model->create($roomData);
            $record = $this->model->find($id);
            $record = $this->normalizeImageUrlForRecord($record);
            $this->success($record, 'Room created successfully', 201);
        } catch (PDOException $e) {
            error_log('Room create error: ' . $e->getMessage());
            if (!empty($roomData[$this->imageField])) {
                $this->deleteLocalFileFromUrl($roomData[$this->imageField]);
            }
            $this->error('Database error while creating room', 500);
        }
    }

    public function update($id): void
    {
        $this->requireAuth();
        $id = (int)$id;

        $existing = $this->model->find($id);
        if (!$existing) {
            $this->error('Not found', 404);
            return;
        }

        $data = $this->jsonInput();
        $payload = [];

        // name
        if (isset($_POST['name'])) {
            $payload['name'] = trim($_POST['name']);
        } elseif (isset($data['name'])) {
            $payload['name'] = trim($data['name']);
        }

        // other fields
        $optionalFields = ['capacity', 'sitting', 'presentation'];
        foreach ($optionalFields as $f) {
            if (isset($_POST[$f])) {
                $payload[$f] = $_POST[$f];
            } elseif (isset($data[$f])) {
                $payload[$f] = $data[$f];
            }
        }

        $oldImage = $existing[$this->imageField] ?? null;
        $newImageRelative = null;

        // If new file uploaded, save it
        if (isset($_FILES[$this->imageField]) && $_FILES[$this->imageField]['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES[$this->imageField];

            if (!isset($file['size']) || (int)$file['size'] > $this->maxFileSize) {
                $this->error('File too large. Max ' . ($this->maxFileSize / 1024 / 1024) . ' MB.', 422);
                return;
            }

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            $ext = null;
            if ($mime === 'image/png') $ext = 'png';
            elseif ($mime === 'image/jpeg') $ext = 'jpg';

            if ($ext === null) {
                $origExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (in_array($origExt, $this->allowedExtensions, true)) {
                    $ext = $origExt === 'jpeg' ? 'jpg' : $origExt;
                }
            }

            if ($ext === null || !in_array($ext, $this->allowedExtensions, true)) {
                $this->error('Unsupported file type. Allowed: png, jpg, jpeg.', 422);
                return;
            }

            $this->ensureUploadDirectoryExists();

            try {
                $filename = sprintf('room_%s_%s.%s', time(), bin2hex(random_bytes(4)), $ext);
            } catch (\Exception $e) {
                $filename = sprintf('room_%s_%s.%s', time(), uniqid(), $ext);
            }

            $destDir = $this->getUploadFullPath();
            $destinationPath = rtrim($destDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

            $moved = false;
            if (is_uploaded_file($file['tmp_name'])) {
                if (@move_uploaded_file($file['tmp_name'], $destinationPath)) {
                    $moved = true;
                } elseif (@copy($file['tmp_name'], $destinationPath)) {
                    $moved = true;
                }
            } else {
                if (@move_uploaded_file($file['tmp_name'], $destinationPath)) {
                    $moved = true;
                } elseif (@copy($file['tmp_name'], $destinationPath)) {
                    $moved = true;
                }
            }

            // extra fallback: relocated in public root by host
            if (!$moved) {
                $publicRoot = $this->resolvePublicRoot();
                $altCandidates = [
                    $publicRoot . DIRECTORY_SEPARATOR . $filename,
                    $publicRoot . DIRECTORY_SEPARATOR . basename($file['name']),
                ];
                foreach ($altCandidates as $alt) {
                    if ($alt && file_exists($alt)) {
                        if (@rename($alt, $destinationPath)) {
                            $moved = true;
                            break;
                        } else {
                            if (@copy($alt, $destinationPath)) {
                                @unlink($alt);
                                $moved = true;
                                break;
                            }
                        }
                    }
                }
            }

            if (!$moved || !file_exists($destinationPath)) {
                error_log('Failed to move/copy uploaded file to expected destination: ' . $destinationPath);
                $this->error('Failed saving uploaded file.', 500);
                return;
            }

            @chmod($destinationPath, 0644);

            $newImageRelative = '/' . trim($this->uploadRelativeDir, '/') . '/' . $filename;
            $payload[$this->imageField] = $newImageRelative;
        } elseif (isset($data[$this->imageField]) && $data[$this->imageField] === null) {
            // explicit remove requested via JSON body { "room_img": null }
            $payload[$this->imageField] = null;
        } elseif (isset($_POST[$this->imageField]) && $_POST[$this->imageField] === 'null') {
            // clients may send 'null' as string
            $payload[$this->imageField] = null;
        }

        $payload['updated_at'] = date('Y-m-d H:i:s');

        // sanitize payload
        $payload = $this->sanitizeRoomPayload($payload);

        // ensure we have fields besides updated_at
        $nonTimestampPayload = $payload;
        unset($nonTimestampPayload['updated_at']);
        if (empty($nonTimestampPayload)) {
            if (!empty($newImageRelative)) {
                $this->deleteLocalFileFromUrl($newImageRelative);
            }
            $this->error('No fields provided to update', 422);
            return;
        }

        try {
            $ok = $this->model->update($id, $payload);
            if (!$ok) {
                if (!empty($newImageRelative)) {
                    $this->deleteLocalFileFromUrl($newImageRelative);
                }
                $this->error('Update failed', 500);
                return;
            }

            // delete old file if replaced or explicitly removed
            if (array_key_exists($this->imageField, $payload)) {
                if ($payload[$this->imageField] === null && $this->isRelativeUploadPath($oldImage)) {
                    $this->deleteLocalFileFromUrl($oldImage);
                } elseif (!empty($newImageRelative) && $this->isRelativeUploadPath($oldImage) && $oldImage !== $newImageRelative) {
                    $this->deleteLocalFileFromUrl($oldImage);
                }
            }

            $record = $this->model->find($id);
            $record = $this->normalizeImageUrlForRecord($record);
            $this->success($record, 'Room updated successfully');
        } catch (PDOException $e) {
            error_log('Room update error: ' . $e->getMessage());
            if (!empty($newImageRelative)) {
                $this->deleteLocalFileFromUrl($newImageRelative);
            }
            $this->error('Database error while updating room', 500);
        }
    }

    public function destroy($id): void
    {
        $this->requireAuth();
        $id = (int)$id;

        $existing = $this->model->find($id);
        if (!$existing) {
            $this->error('Not found', 404);
            return;
        }

        try {
            $ok = $this->model->delete($id);
            if (!$ok) {
                $this->error('Delete failed', 500);
                return;
            }

            $img = $existing[$this->imageField] ?? null;
            if ($this->isRelativeUploadPath($img)) {
                $this->deleteLocalFileFromUrl($img);
            }

            $this->success(null, 'Room deleted successfully', 200);
        } catch (PDOException $e) {
            error_log('Room delete error: ' . $e->getMessage());
            if (stripos($e->getMessage(), 'foreign key') !== false || stripos($e->getMessage(), 'constraint') !== false) {
                $this->error('Cannot delete room: related records exist.', 409);
            } else {
                $this->error('Database error while deleting room', 500);
            }
        }
    }

    /* --------------------- Helpers --------------------- */

    protected function ensureUploadDirectoryExists(): void
    {
        $dir = $this->getUploadFullPath();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    /**
     * Resolve the public root (tries DOCUMENT_ROOT first, then common candidates).
     */
    protected function resolvePublicRoot(): string
    {
        // DOCUMENT_ROOT preferred
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $doc = realpath(rtrim($_SERVER['DOCUMENT_ROOT'], '/\\'));
            if ($doc !== false && is_dir($doc)) {
                return rtrim($doc, DIRECTORY_SEPARATOR);
            }
        }

        // project root candidates
        $projectRoot = realpath(__DIR__ . '/../../');
        $candidates = [];
        if ($projectRoot !== false) {
            $candidates[] = $projectRoot . DIRECTORY_SEPARATOR . 'public';
            $candidates[] = $projectRoot . DIRECTORY_SEPARATOR . 'public_html';
            $candidates[] = $projectRoot;
        }

        foreach ($candidates as $c) {
            if ($c && is_dir($c)) {
                return rtrim($c, DIRECTORY_SEPARATOR);
            }
        }

        // fallback to directory of this file's parent public
        $fallback = realpath(__DIR__ . '/../../public');
        if ($fallback !== false && is_dir($fallback)) {
            return rtrim($fallback, DIRECTORY_SEPARATOR);
        }

        // worst-case fallback
        return rtrim(__DIR__, DIRECTORY_SEPARATOR);
    }

    /**
     * Return full filesystem path to the upload folder (no trailing slash).
     * Example: /var/www/html/uploads/rooms
     */
    protected function getUploadFullPath(): string
    {
        $publicRoot = $this->resolvePublicRoot();
        return rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . trim($this->uploadRelativeDir, '/');
    }

    /**
     * Build base url that maps to the public folder.
     *
     * This tries to use DOCUMENT_ROOT and the resolved public folder to compute the correct web path.
     * If the resolved public folder ends with "/public" or "/public_html" that segment is removed from the URL path.
     */
    protected function getBaseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

        $publicRootFs = $this->resolvePublicRoot();

        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $docRoot = realpath(rtrim($_SERVER['DOCUMENT_ROOT'], '/\\'));
            if ($docRoot !== false && is_dir($docRoot) && str_starts_with($publicRootFs, $docRoot)) {
                $webPath = substr($publicRootFs, strlen($docRoot)); // may be '' or '/api_project/public'
                // remove trailing /public or /public_html from webPath
                $webPath = preg_replace('#/(public|public_html)$#', '', $webPath);
                $webPath = rtrim($webPath, '/');
                if ($webPath === '' || $webPath === false) {
                    return $scheme . '://' . $host;
                }
                return $scheme . '://' . $host . $webPath;
            }
        }

        // Fallback: use script location but strip trailing '/public' if present
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        $scriptDir = preg_replace('#/public$#', '', $scriptDir);
        $basePath = rtrim($scriptDir, '/');
        if ($basePath === '' || $basePath === '/') {
            return $scheme . '://' . $host;
        }
        return $scheme . '://' . $host . $basePath;
    }

    protected function normalizeImageUrlForRecord($row)
    {
        if (is_array($row)) {
            $img = $row[$this->imageField] ?? null;
            if (!empty($img) && !$this->isAbsoluteUrl($img) && $this->isRelativeUploadPath($img)) {
                // Use computed base url that maps to the public folder (not to /public in path)
                $row[$this->imageField] = $this->getBaseUrl() . '/' . ltrim($img, '/');
            }
            return $row;
        } elseif (is_object($row)) {
            $img = $row->{$this->imageField} ?? null;
            if (!empty($img) && !$this->isAbsoluteUrl($img) && $this->isRelativeUploadPath($img)) {
                $row->{$this->imageField} = $this->getBaseUrl() . '/' . ltrim($img, '/');
            }
            return $row;
        }
        return $row;
    }

    protected function isAbsoluteUrl(string $s): bool
    {
        return (bool)preg_match('#^https?://#i', $s);
    }

    protected function isRelativeUploadPath(?string $path): bool
    {
        if (empty($path)) return false;
        $normalized = ltrim($path, '/');
        return strpos($normalized, trim($this->uploadRelativeDir, '/') . '/') === 0;
    }

    /**
     * Delete a locally stored upload referenced by a db file_url.
     * Accepts either '/uploads/rooms/...' or full url or 'uploads/rooms/...' and resolves to filesystem path.
     */
    protected function deleteLocalFileFromUrl(string $url): bool
    {
        if (empty($url)) return true;

        // If full URL, strip base
        $base = $this->getBaseUrl() . '/';
        if (stripos($url, $base) === 0) {
            $relative = ltrim(substr($url, strlen($base)), '/');
        } else {
            $relative = ltrim($url, '/');
        }

        // If relative path contains the upload dir, use basename to avoid directory traversal issues
        $basename = basename($relative);
        $fullPath = rtrim($this->getUploadFullPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $basename;

        if (file_exists($fullPath)) {
            return @unlink($fullPath);
        }

        // try alternative: maybe stored without leading slash in DB or in slightly different candidate location
        $publicRoot = $this->resolvePublicRoot();
        $candidate = rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . trim($relative, '/');
        if (file_exists($candidate)) {
            return @unlink($candidate);
        }

        return true;
    }

    /**
     * Ensure payload fields are safe for DB:
     * - cast numeric fields to int|null
     * - convert presentation (yes/no/1/0/true/false) to 1 or 0
     * - convert empty strings to null (except presentation should default to 0 if explicitly provided)
     */
    protected function sanitizeRoomPayload(array $payload): array
    {
        // capacity (smallint unsigned)
        if (array_key_exists('capacity', $payload)) {
            if ($payload['capacity'] === '' || $payload['capacity'] === null) {
                $payload['capacity'] = null;
            } elseif (is_numeric($payload['capacity'])) {
                $val = (int)$payload['capacity'];
                $payload['capacity'] = $val < 0 ? 0 : $val;
            } else {
                $payload['capacity'] = null;
            }
        }

        // sitting (text) - keep as string or null
        if (array_key_exists('sitting', $payload)) {
            if ($payload['sitting'] === '') {
                $payload['sitting'] = null;
            } else {
                $payload['sitting'] = (string)$payload['sitting'];
            }
        }

        // presentation: accept 1/0, 'yes'/'no', true/false
        if (array_key_exists('presentation', $payload)) {
            $val = $payload['presentation'];
            if ($val === '' || $val === null) {
                $payload['presentation'] = 0;
            } elseif (is_int($val) || is_numeric($val)) {
                $payload['presentation'] = (int)$val ? 1 : 0;
            } elseif (is_string($val)) {
                $v = strtolower(trim($val));
                if (in_array($v, ['1', 'true', 'yes', 'y', 'on'], true)) {
                    $payload['presentation'] = 1;
                } else {
                    $payload['presentation'] = 0;
                }
            } elseif (is_bool($val)) {
                $payload['presentation'] = $val ? 1 : 0;
            } else {
                $payload['presentation'] = 0;
            }
        }

        // room_img: allow null (explicit remove) or keep relative path
        if (array_key_exists($this->imageField, $payload)) {
            if ($payload[$this->imageField] === '' || $payload[$this->imageField] === 'null') {
                $payload[$this->imageField] = null;
            } else {
                // keep as-is (relative path set earlier)
            }
        }

        // Convert remaining empty strings to null
        foreach ($payload as $k => $v) {
            if ($v === '') {
                $payload[$k] = null;
            }
        }

        return $payload;
    }
}
