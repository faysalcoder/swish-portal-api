<?php
namespace App\Middlewares;

use App\Services\AuthService;

class RoleMiddleware
{
    /**
     * Accepts one or more allowed roles. Roles are numeric as in users.role (0,1,2).
     * Example: (new RoleMiddleware([0,1]))->handle($user);
     */
    protected array $allowed;

    public function __construct(array $allowed = [])
    {
        $this->allowed = $allowed;
    }

    /**
     * $user array must be provided (from AuthMiddleware->handle()).
     * Returns true on success; otherwise sends 403 and exits.
     */
    public function handle(array $user): bool
    {
        if (empty($this->allowed)) return true; // allow all if no roles specified
        $userRole = isset($user['role']) ? (int)$user['role'] : null;
        if ($userRole === null) {
            $this->forbidden('Role information missing');
        }
        if (!in_array($userRole, $this->allowed, true)) {
            $this->forbidden('Insufficient permissions');
        }
        return true;
    }

    protected function forbidden(string $message = 'Forbidden'): void
    {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
}
