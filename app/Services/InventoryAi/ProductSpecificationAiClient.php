<?php

namespace App\Services\InventoryAi;

use App\Services\InventoryAi\Contracts\ProductSpecificationAiProvider;

/**
 * Thin facade that delegates to the configured AI provider.
 * Preserves the existing service container binding used by enrichment workflows.
 */
class ProductSpecificationAiClient
{
    private ?ProductSpecificationAiProvider $provider = null;

    /**
     * @param  array<string, mixed>  $lookupContext
     * @return array{
     *     parsed: array<string, mixed>,
     *     raw_response: array<string, mixed>|null,
     *     provider?: string,
     *     model?: string
     * }
     */
    public function enrich(array $lookupContext): array
    {
        AiProviderFactory::assertConfigured();

        return $this->provider()->enrich($lookupContext);
    }

    public function provider(): ProductSpecificationAiProvider
    {
        return $this->provider ??= AiProviderFactory::make();
    }
}
