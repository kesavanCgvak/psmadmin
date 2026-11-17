<?php

namespace App\Exceptions;

use RuntimeException;

class HireHopApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 422,
        private readonly ?string $errorCode = null,
        private readonly bool $retryable = false,
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

    public function retryable(): bool
    {
        return $this->retryable;
    }
}
