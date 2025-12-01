<?php
namespace App\Validators;

use App\Models\User;

/**
 * Validate user payloads for create/update.
 */
class UserValidator extends BaseValidator
{
    /**
     * Validate data for user creation.
     * Throws ValidationException on failure.
     */
    public static function validateCreate(array $data): void
    {
        $errors = [];

        // required fields
        self::assert(!self::missing($data, 'name'), 'name', 'Name is required', $errors);
        self::assert(!self::missing($data, 'email'), 'email', 'Email is required', $errors);
        self::assert(!self::missing($data, 'password'), 'password', 'Password is required', $errors);

        // email format
        if (!self::missing($data, 'email')) {
            self::assert(self::isEmail($data['email']), 'email', 'Invalid email format', $errors);
        }

        // unique email check (non-strict; caller may handle race)
        if (!self::missing($data, 'email')) {
            $userModel = new User();
            $existing = $userModel->findByEmail($data['email']);
            self::assert($existing === null, 'email', 'Email already in use', $errors);
        }

        // role validation (if provided)
        if (isset($data['role'])) {
            $role = (int)$data['role'];
            self::assert(in_array($role, [0,1,2,3,4], true), 'role', 'Invalid role value', $errors);
        }

        // phone optional check length
        if (!empty($data['phone'])) {
            self::assert(is_string($data['phone']) && strlen($data['phone']) <= 50, 'phone', 'Phone must be <= 50 chars', $errors);
        }

        self::throwIfErrors($errors);
    }

    /**
     * Validate data for user update.
     * Throws ValidationException on failure.
     */
    public static function validateUpdate(array $data): void
    {
        $errors = [];

        if (isset($data['email'])) {
            self::assert(self::isEmail($data['email']), 'email', 'Invalid email', $errors);
            // email uniqueness check left to caller if needed
        }

        if (isset($data['role'])) {
            $role = (int)$data['role'];
            self::assert(in_array($role, [0,1,2,3,4], true), 'role', 'Invalid role', $errors);
        }

        if (isset($data['password'])) {
            self::assert(is_string($data['password']) && strlen($data['password']) >= 6, 'password', 'Password too short (min 6)', $errors);
        }

        self::throwIfErrors($errors);
    }
}
