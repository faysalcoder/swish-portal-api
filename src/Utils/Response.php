<?php
namespace App\Utils;

/**
 * Simple helper for JSON responses (controllers may use their own trait).
 */
class Response
{
    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success($data = null, string $message = '', int $status = 200): void
    {
        $payload = ['success' => true, 'message' => $message, 'data' => $data];
        self::json($payload, $status);
    }

    public static function error(string $message = 'Error', int $status = 400, $errors = null): void
    {
        $payload = ['success' => false, 'message' => $message, 'errors' => $errors];
        self::json($payload, $status);
    }

    /**
     * Send HttpException formatted response (useful when catching exceptions)
     */
    public static function fromException(\Throwable $e): void
    {
        if ($e instanceof \App\Exceptions\HttpException) {
            $status = $e->getStatusCode();
            $payload = $e->toArray();
            self::json($payload, $status);
        } else {
            $msg = ($_ENV['APP_ENV'] ?? 'production') !== 'production' ? $e->getMessage() : 'Server error';
            self::error($msg, 500);
        }
    }
}
