<?php

namespace App\Exceptions;

use RuntimeException;

class PartnerChatException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
    ) {
        parent::__construct((string) ($body['message'] ?? 'Partner chat error'));
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function toResult(): array
    {
        return [
            'status' => $this->status,
            'body' => $this->body,
        ];
    }

    public static function notFound(string $message): self
    {
        return new self(404, [
            'success' => false,
            'message' => $message,
        ]);
    }

    public static function forbidden(): self
    {
        return new self(403, [
            'success' => false,
            'message' => 'Unauthorized.',
        ]);
    }

    public static function conflict(string $message): self
    {
        return new self(409, [
            'success' => false,
            'message' => $message,
        ]);
    }

    public static function senderMissing(): self
    {
        return new self(422, [
            'success' => false,
            'message' => 'Validation error',
            'errors' => [
                'sender' => ['This company does not have a valid default contact to send messages.'],
            ],
        ]);
    }
}
