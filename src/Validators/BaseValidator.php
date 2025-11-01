<?php
namespace App\Validators;

use App\Exceptions\ValidationException;

/**
 * Simple base validator providing helpers to assert rules and throw ValidationException.
 * Concrete validators should call static methods and throw on failure.
 */
abstract class BaseValidator
{
    protected static function missing(array $data, string $field): bool
    {
        return !isset($data[$field]) || $data[$field] === '' || $data[$field] === null;
    }

    protected static function isEmail(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    protected static function isIntegerish($value): bool
    {
        return is_int($value) || (is_string($value) && ctype_digit($value));
    }

    protected static function assert(bool $cond, string $field, string $message, array &$errors): void
    {
        if (!$cond) {
            if (!isset($errors[$field])) $errors[$field] = [];
            $errors[$field][] = $message;
        }
    }

    protected static function throwIfErrors(array $errors): void
    {
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }
}
