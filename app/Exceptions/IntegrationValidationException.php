<?php

namespace App\Exceptions;

use Exception;

class IntegrationValidationException extends Exception
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 422,
        private readonly ?string $errorCode = null
    ) {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }
}

