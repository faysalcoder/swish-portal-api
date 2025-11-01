<?php
namespace App\Validators;

class FileValidator extends BaseValidator
{
    /**
     * Validate uploaded file entry from $_FILES.
     *
     * $file = $_FILES['file'] possible shape.
     * $allowedMime array of mime types or null.
     */
    public static function validateUpload(array $file, ?array $allowedMime = null, int $maxSize = null): void
    {
        $errors = [];

        if (!isset($file) || !is_array($file)) {
            $errors['file'] = ['No file provided'];
            throw new \App\Exceptions\ValidationException($errors);
        }

        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $msg = 'Upload error';
            if (isset($file['error'])) {
                $msg .= ' code ' . $file['error'];
            }
            $errors['file'] = [$msg];
        } else {
            if ($maxSize !== null && isset($file['size'])) {
                if ($file['size'] > $maxSize) {
                    $errors['file'][] = 'File exceeds max allowed size';
                }
            }

            if ($allowedMime !== null) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
                if (!in_array($mime, $allowedMime, true)) {
                    $errors['file'][] = 'Invalid file type: ' . $mime;
                }
            }
        }

        if (!empty($errors)) {
            throw new \App\Exceptions\ValidationException($errors);
        }
    }
}
