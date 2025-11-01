<?php
namespace App\Services;

class FileService
{
    protected string $uploadDir;
    protected int $maxSize;

    /**
     * $uploadDir absolute dir path or relative to project root.
     */
    public function __construct(?string $uploadDir = null)
    {
        $root = __DIR__ . '/../../';
        $this->uploadDir = $uploadDir ?? ($_ENV['UPLOAD_DIR'] ?? $root . 'storage/uploads');
        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0755, true);
        }
        $this->uploadDir = rtrim($this->uploadDir, '/');
        $this->maxSize = (int)($_ENV['MAX_UPLOAD_SIZE'] ?? 10 * 1024 * 1024);
    }

    /**
     * Save a $_FILES item. Returns absolute path saved.
     * $file should be like $_FILES['file'].
     * $allowedMimeTypes: array or null to skip mime check.
     */
    public function saveUploadedFile(array $file, ?array $allowedMimeTypes = null, ?int $maxSize = null): string
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload error code: ' . ($file['error'] ?? 'unknown'));
        }

        $max = $maxSize ?? $this->maxSize;
        if ($file['size'] > $max) {
            throw new \RuntimeException('Upload exceeds max size of ' . $max . ' bytes');
        }

        // detect mime type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if ($allowedMimeTypes && !in_array($mime, $allowedMimeTypes, true)) {
            throw new \RuntimeException('Invalid file type: ' . $mime);
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $basename = bin2hex(random_bytes(16)) . ($ext ? '.' . $ext : '');
        $dest = $this->uploadDir . '/' . $basename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new \RuntimeException('Failed to move uploaded file');
        }

        // set safe permissions
        @chmod($dest, 0640);
        return $dest;
    }

    /**
     * Save an already-existing local file to uploads (copy/rename).
     */
    public function saveLocalFile(string $sourcePath, ?string $destName = null): string
    {
        if (!file_exists($sourcePath)) throw new \RuntimeException('Source file not found');
        $ext = pathinfo($sourcePath, PATHINFO_EXTENSION);
        $basename = $destName ?? (bin2hex(random_bytes(16)) . ($ext ? '.' . $ext : ''));
        $dest = $this->uploadDir . '/' . $basename;
        if (!copy($sourcePath, $dest)) throw new \RuntimeException('Failed to copy file');
        @chmod($dest, 0640);
        return $dest;
    }

    /**
     * Delete file path (if exists). Returns true if deleted or not exists.
     */
    public function deleteFile(?string $path): bool
    {
        if (!$path) return true;
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            // external URL nothing to delete
            return true;
        }
        if (!file_exists($path)) return true;
        return @unlink($path);
    }
}
