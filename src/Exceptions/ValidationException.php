<?php
namespace App\Exceptions;

class ValidationException extends HttpException
{
    /**
     * $errors expected to be an associative array of field => message(s)
     */
    public function __construct(array $errors, string $message = "Validation failed", int $statusCode = 422)
    {
        parent::__construct($message, $statusCode, $errors);
    }
}
