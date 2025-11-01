<?php
namespace App\Middlewares;

class CorsMiddleware
{
    protected array $options;

    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    /**
     * Call early in bootstrap. Sets CORS headers. For preflight, returns and exits.
     */
    public function handle(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? ($_ENV['APP_CORS_ORIGIN'] ?? '*');
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
        header('Access-Control-Allow-Credentials: true');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
