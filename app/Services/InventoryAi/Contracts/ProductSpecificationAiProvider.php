<?php

namespace App\Services\InventoryAi\Contracts;

interface ProductSpecificationAiProvider
{
    /**
     * @param  array<string, mixed>  $lookupContext
     * @return array{
     *     parsed: array<string, mixed>,
     *     raw_response: array<string, mixed>|null,
     *     provider: string,
     *     model: string
     * }
     */
    public function enrich(array $lookupContext): array;

    public function providerName(): string;

    public function modelName(): string;

    /**
     * Send a minimal diagnostic request (used by ai:test).
     *
     * @return array{parsed: array<string, mixed>, raw_response: mixed, provider: string, model: string}
     */
    public function diagnosticPing(): array;
}
