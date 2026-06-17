<?php

namespace App\Services\InventoryAi\Exceptions;

use RuntimeException;

class AiProviderException extends RuntimeException
{
    public const CATEGORY_INVALID_API_KEY = 'invalid_api_key';

    public const CATEGORY_QUOTA_EXCEEDED = 'quota_exceeded';

    public const CATEGORY_RATE_LIMIT = 'rate_limit_exceeded';

    public const CATEGORY_TIMEOUT = 'network_timeout';

    public const CATEGORY_INVALID_JSON = 'invalid_json_response';

    public const CATEGORY_UNAVAILABLE = 'provider_unavailable';

    public const CATEGORY_CONFIGURATION = 'configuration_error';

    public const CATEGORY_UNKNOWN = 'unknown_error';

    public function __construct(
        string $message,
        public readonly string $category,
        public readonly string $provider,
        public readonly ?int $httpStatus = null,
        public readonly bool $retryable = true,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public static function configuration(string $provider, string $message): self
    {
        return new self($message, self::CATEGORY_CONFIGURATION, $provider, null, false);
    }
}
