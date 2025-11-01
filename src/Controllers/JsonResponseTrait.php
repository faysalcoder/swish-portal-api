<?php
namespace App\Controllers;

trait JsonResponseTrait
{
    protected function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    protected function success($data = null, string $message = '', int $status = 200): void
    {
        $payload = ['success' => true, 'message' => $message, 'data' => $data];
        $this->json($payload, $status);
    }

    protected function error(string $message = 'Error', int $status = 400, $data = null): void
    {
        $payload = ['success' => false, 'message' => $message, 'data' => $data];
        $this->json($payload, $status);
    }
}
