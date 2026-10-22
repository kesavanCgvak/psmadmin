<?php

namespace App\Exceptions;

use RuntimeException;

class SupportSessionException extends RuntimeException
{
    public function __construct(
        public string $errorCode,
        string $message,
        public int $status
    ) {
        parent::__construct($message);
    }
}
