<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A temporary SMS provider failure. The queue worker retries the job.
 */
class RetryableSmsException extends RuntimeException
{
}
