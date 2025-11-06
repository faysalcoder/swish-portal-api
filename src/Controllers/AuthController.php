<?php
// src/Controllers/AuthController.php
namespace App\Controllers;

use App\Models\User;
use App\Services\PasswordResetService;
use App\Services\PasswordChangeService;
use App\Utils\Mailer;
use App\Validators\UserValidator;

class AuthController extends BaseController
{
    protected PasswordResetService $passwordResetService;
    protected PasswordChangeService $passwordChangeService;

    public function __construct()
    {
        parent::__construct();

        // Ensure $this->userModel exists (BaseController may set it)
        if (!isset($this->userModel) || !($this->userModel instanceof User)) {
            $this->userModel = new User();
        }

        $this->passwordResetService = new PasswordResetService();
        $this->passwordChangeService = new PasswordChangeService();
    }

    /**
     * Register
     * POST /api/v1/auth/register
     */
    public function register(): void
    {
        $data = $this->jsonInput();

        try {
            UserValidator::validateCreate($data);

            $payload = [
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => password_hash($data['password'], PASSWORD_BCRYPT),
                'role' => isset($data['role']) ? (int)$data['role'] : 2,
                'status' => $data['status'] ?? 'active',
                'wing_id' => $data['wing_id'] ?? null,
                'subw_id' => $data['subw_id'] ?? null,
                'employee_id' => $data['employee_id'] ?? null,
                'location_id' => $data['location_id'] ?? null,
                'designation' => $data['designation'] ?? null,
                'profile_img' => $data['profile_img'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            $newId = $this->userModel->create($payload);
            $user = $this->userModel->find((int)$newId);
            if ($user && isset($user['password'])) unset($user['password']);

            $this->success($user, 'User registered', 201);
        } catch (\App\Exceptions\ValidationException $ve) {
            $this->error($ve->getMessage(), 422, $ve->getErrors());
        } catch (\Throwable $e) {
            error_log('Register error: ' . $e->getMessage());
            $this->error('Server error', 500);
        }
    }

    /**
     * Login
     * POST /api/v1/auth/login
     *
     * Accepts:
     *  { "email": "...", "password": "..." }
     * or
     *  { "employee_id": "...", "password": "..." }
     * or
     *  { "identifier": "...", "password": "..." }  // identifier will be treated as email if contains '@'
     */
    public function login(): void
    {
        $data = $this->jsonInput();
        $identifier = $data['email'] ?? $data['employee_id'] ?? $data['identifier'] ?? null;
        $password = $data['password'] ?? null;

        if (!$identifier || !$password) {
            $this->error('Identifier (email or employee_id) and password are required', 422);
            return;
        }

        try {
            $user = null;

            // Treat as email if contains @; otherwise treat as employee_id
            if (strpos($identifier, '@') !== false) {
                // email
                if (method_exists($this->userModel, 'findByEmail')) {
                    $user = $this->userModel->findByEmail((string)$identifier);
                } else {
                    // fallback to where helper
                    $rows = $this->userModel->where(['email' => $identifier], 1, 0);
                    $user = $rows[0] ?? null;
                }
            } else {
                // employee id path
                // Prefer a model helper if available, otherwise use where()
                if (method_exists($this->userModel, 'findByEmployeeId')) {
                    $user = $this->userModel->findByEmployeeId((string)$identifier);
                } else {
                    $rows = $this->userModel->where(['employee_id' => $identifier], 1, 0);
                    $user = $rows[0] ?? null;
                }
            }

            if (!$user) {
                $this->error('Invalid credentials', 401);
                return;
            }

            if (!isset($user['password']) || !password_verify($password, $user['password'])) {
                $this->error('Invalid credentials', 401);
                return;
            }

            if (($user['status'] ?? '') !== 'active') {
                $this->error('User is not active', 403);
                return;
            }

            // best-effort update last_login
            try {
                $this->userModel->update((int)$user['id'], ['last_login' => date('Y-m-d H:i:s')]);
            } catch (\Throwable $inner) {
                error_log('Failed to update last_login: ' . $inner->getMessage());
            }

            if (isset($user['password'])) unset($user['password']);

            $ttl = (int)($_ENV['JWT_TTL'] ?? 86400); // default 1 day
            $token = $this->generateJwtForUser($user, $ttl);

            $this->success(['token' => $token, 'user' => $user], 'Login success');
        } catch (\Throwable $e) {
            error_log('Login error: ' . $e->getMessage());
            $this->error('Server error', 500);
        }
    }

    /**
     * Forgot password
     * POST /api/v1/auth/forgot-password
     * Body: { "email": "..." }
     */
    public function forgotPassword(): void
    {
        $data = $this->jsonInput();
        $email = trim($data['email'] ?? '');

        if ($email === '') {
            $this->error('Email is required', 422);
            return;
        }

        try {
            // Create token and a reset URL (createResetUrlForEmail throws RuntimeException if user/secret missing)
            $reset = $this->passwordResetService->createResetUrlForEmail($email, '/reset_password.php');
            $resetUrl = $reset['reset_url'] ?? null;

            // Compose email
            $subject = 'Password reset request';
            $ttlMinutes = (int)((int)($_ENV['PASSWORD_RESET_TTL'] ?? 3600) / 60);
            $fromName = htmlspecialchars($_ENV['MAIL_FROM_NAME'] ?? 'Support');

            $html = "<p>Hello,</p>
                     <p>Click the link below to reset your password. This link expires in {$ttlMinutes} minutes.</p>
                     <p><a href=\"{$resetUrl}\">Reset your password</a></p>
                     <p>If you didn't request this, ignore this message.</p>
                     <p>Regards,<br/>{$fromName}</p>";

            // Send email best-effort
            try {
                $mailer = new Mailer();
                $mailer->send($email, $subject, $html);
            } catch (\Throwable $mailErr) {
                error_log('Password reset email failed: ' . $mailErr->getMessage());
            }

            // Generic response to avoid enumeration
            $this->success(null, 'If that email is registered, a password reset link has been sent.');
        } catch (\RuntimeException $e) {
            // still respond generically
            $this->success(null, 'If that email is registered, a password reset link has been sent.');
        } catch (\Throwable $e) {
            error_log('forgotPassword error: ' . $e->getMessage());
            $this->error('Server error', 500);
        }
    }

    /**
     * Reset password
     * POST /api/v1/auth/reset-password
     * Body: { "token":"...", "password":"..." }
     */
    public function resetPassword(): void
    {
        // Ensure JSON header
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

        try {
            $data = $this->jsonInput();
            $token = $data['token'] ?? null;
            $password = $data['password'] ?? null;

            if (!$token || !$password) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Token and password are required', 'data' => null]);
                return;
            }

            $this->passwordResetService->resetPasswordWithToken($token, $password);

            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Password updated', 'data' => null]);
            return;
        } catch (\RuntimeException $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage(), 'data' => null]);
            return;
        } catch (\Throwable $e) {
            error_log('resetPassword error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()]);
            return;
        }
    }

    /**
     * Change password
     * POST /api/v1/auth/change-password
     * Body: { "current_password":"...", "new_password":"..." }
     * Requires Authorization: Bearer <token>
     */
    public function changePassword(): void
    {
        $user = $this->requireAuth();
        if (!$user || !isset($user['id'])) {
            $this->error('Unauthorized', 401);
            return;
        }

        $data = $this->jsonInput();
        $current = $data['current_password'] ?? '';
        $new = $data['new_password'] ?? '';

        if (!$current || !$new) {
            $this->error('Both current and new password are required', 422);
            return;
        }

        try {
            $result = $this->passwordChangeService->changePassword((int)$user['id'], $current, $new);
            if (!empty($result['success'])) {
                $this->success(null, $result['message'] ?? 'Password changed');
            } else {
                $this->error($result['message'] ?? 'Change failed', 400);
            }
        } catch (\Throwable $e) {
            error_log('changePassword error: ' . $e->getMessage());
            $this->error('Server error', 500);
        }
    }

    /**
     * GET /api/v1/auth/me
     */
    public function me(): void
    {
        try {
            $user = $this->requireAuth();
            if (!$user) {
                $this->error('Unauthorized', 401);
                return;
            }
            if (isset($user['password'])) unset($user['password']);
            $this->success($user);
        } catch (\Throwable $e) {
            error_log('me error: ' . $e->getMessage());
            $this->error('Server error', 500);
        }
    }
}
