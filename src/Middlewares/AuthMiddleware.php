<?php
namespace App\Middlewares;

use App\Services\AuthService;

class AuthMiddleware
{
    protected AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    /**
     * Run middleware: returns user array on success (so caller can use it), or sends 401 JSON and exits.
     * Example usage:
     *   $user = (new AuthMiddleware())->handle();
     */
    public function handle(): array
    {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
        $token = AuthService::extractTokenFromHeader($hdr);
        if (!$token) {
            $this->unauthorized('Authorization token required');
        }

        $user = $this->auth->getUserFromToken($token);
        if (!$user) {
            $this->unauthorized('Invalid or expired token');
        }

        return $user;
    }

    protected function unauthorized(string $message = 'Unauthorized'): void
    {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
}
