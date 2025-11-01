<?php
namespace App\Exceptions;

use Exception;

class HttpException extends Exception
{
    protected int $statusCode;
    protected $errors;

    public function __construct(string $message = "HTTP Error", int $statusCode = 400, $errors = null, Exception $previous = null)
    {
        $this->statusCode = $statusCode;
        $this->errors = $errors;
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public function toArray(): array
    {
        return [
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => $this->errors,
        ];
    }
}
