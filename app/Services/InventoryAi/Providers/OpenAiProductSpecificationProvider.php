<?php

namespace App\Services\InventoryAi\Providers;

use App\Services\InventoryAi\AiEnrichmentResponseParser;
use App\Services\InventoryAi\Contracts\ProductSpecificationAiProvider;
use App\Services\InventoryAi\Exceptions\AiProviderException;
use App\Services\InventoryAi\ProductSpecificationPromptBuilder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OpenAiProductSpecificationProvider implements ProductSpecificationAiProvider
{
    use HandlesAiProviderHttpErrors;
    use PerformsRateLimitedAiRequests;

    private string $apiKey;

    private string $baseUrl;

    private string $model;

    private int $timeout;

    public function __construct()
    {
        $config = config('ai.providers.openai', []);

        $this->apiKey = (string) ($config['api_key'] ?? '');
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.openai.com/v1'), '/');
        $this->model = (string) ($config['model'] ?? 'gpt-4o-mini');
        $this->timeout = (int) config('ai.timeout', 60);
    }

    public function providerName(): string
    {
        return 'openai';
    }

    public function modelName(): string
    {
        return $this->model;
    }

    /**
     * @param  array<string, mixed>  $lookupContext
     * @return array{parsed: array<string, mixed>, raw_response: array<string, mixed>|null, provider: string, model: string}
     */
    public function enrich(array $lookupContext): array
    {
        $this->ensureConfigured();

        $this->logRequestStart($this->providerName(), $this->model);

        $endpoint = "{$this->baseUrl}/chat/completions";
        $payload = $this->buildChatPayload(
            ProductSpecificationPromptBuilder::systemPrompt(),
            ProductSpecificationPromptBuilder::userPrompt($lookupContext),
        );

        try {
            $response = $this->sendWithRateLimitHandling(
                fn () => Http::withToken($this->apiKey)
                    ->timeout($this->timeout)
                    ->acceptJson()
                    ->post($endpoint, $payload),
                $this->providerName(),
                $endpoint,
                ['model' => $this->model],
            );
        } catch (ConnectionException $e) {
            throw $this->wrapConnectionException($e, $this->providerName());
        }

        $this->logResponseReceived($this->providerName(), $this->model, $response->status());

        if (!$response->successful()) {
            throw $this->classifyHttpFailure($response, $this->providerName(), $endpoint, [
                'model' => $this->model,
            ]);
        }

        $content = data_get($response->json(), 'choices.0.message.content');
        if (!is_string($content) || trim($content) === '') {
            throw new AiProviderException(
                'OpenAI response did not contain message content.',
                AiProviderException::CATEGORY_INVALID_JSON,
                $this->providerName(),
                $response->status(),
                false,
            );
        }

        $parsed = AiEnrichmentResponseParser::parseJsonContent($content, $this->providerName());

        return [
            'parsed' => $parsed,
            'raw_response' => $response->json(),
            'provider' => $this->providerName(),
            'model' => $this->model,
        ];
    }

    /**
     * @return array{parsed: array<string, mixed>, raw_response: mixed, provider: string, model: string}
     */
    public function diagnosticPing(): array
    {
        $this->ensureConfigured();

        $endpoint = "{$this->baseUrl}/chat/completions";
        $payload = $this->buildChatPayload(
            'Respond with JSON only.',
            ProductSpecificationPromptBuilder::diagnosticUserPrompt(),
        );

        try {
            $response = $this->sendWithRateLimitHandling(
                fn () => Http::withToken($this->apiKey)
                    ->timeout($this->timeout)
                    ->acceptJson()
                    ->post($endpoint, $payload),
                $this->providerName(),
                $endpoint,
                ['model' => $this->model],
            );
        } catch (ConnectionException $e) {
            throw $this->wrapConnectionException($e, $this->providerName());
        }

        if (!$response->successful()) {
            throw $this->classifyHttpFailure($response, $this->providerName(), $endpoint);
        }

        $content = (string) data_get($response->json(), 'choices.0.message.content', '');
        $parsed = AiEnrichmentResponseParser::parseJsonContent($content, $this->providerName());

        return [
            'parsed' => $parsed,
            'raw_response' => $response->json(),
            'provider' => $this->providerName(),
            'model' => $this->model,
        ];
    }

    private function ensureConfigured(): void
    {
        if ($this->apiKey === '') {
            throw AiProviderException::configuration(
                $this->providerName(),
                'OPENAI_API_KEY is not configured for inventory AI enrichment.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildChatPayload(string $systemPrompt, string $userPrompt): array
    {
        return [
            'model' => $this->model,
            'temperature' => 0.2,
            'max_tokens' => max(64, (int) config('ai.rate_limit.max_output_tokens', 512)),
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];
    }
}
