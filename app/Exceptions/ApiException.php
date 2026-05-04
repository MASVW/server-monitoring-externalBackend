<?php

namespace App\Exceptions;

use Exception;

class ApiException extends Exception
{
    public function __construct(
        string $message,
        private readonly string $safeError = 'Bad Request',
        private readonly int $statusCode = 400
    ) {
        parent::__construct($message);
    }

    public function safeError(): string
    {
        return $this->safeError;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
