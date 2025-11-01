<?php
namespace App\Services;

use App\Models\User;

class PasswordChangeService
{
    protected User $userModel;

    public function __construct()
    {
        $this->userModel = new User();
    }

    /**
     * Change password for a user
     *
     * @param int $userId
     * @param string $currentPassword
     * @param string $newPassword
     * @return array
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): array
    {
        $user = $this->userModel->findById($userId);

        if (!$user) {
            return [
                'success' => false,
                'message' => 'User not found',
                'data' => null
            ];
        }

        // Verify current password
        if (!password_verify($currentPassword, $user['password'])) {
            return [
                'success' => false,
                'message' => 'Current password is incorrect',
                'data' => null
            ];
        }

        // Hash new password and update
        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
        $updated = $this->userModel->updatePassword($userId, $hashed);

        if (!$updated) {
            return [
                'success' => false,
                'message' => 'Failed to update password',
                'data' => null
            ];
        }

        return [
            'success' => true,
            'message' => 'Password changed successfully',
            'data' => null
        ];
    }
}
