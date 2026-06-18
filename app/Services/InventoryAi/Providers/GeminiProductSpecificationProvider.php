<?php

namespace App\Services\InventoryAi\Providers;

use App\Services\InventoryAi\AiEnrichmentResponseParser;
use App\Services\InventoryAi\Contracts\ProductSpecificationAiProvider;
use App\Services\InventoryAi\Exceptions\AiProviderException;
use App\Services\InventoryAi\ProductSpecificationPromptBuilder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class GeminiProductSpecificationProvider implements ProductSpecificationAiProvider
{
    use HandlesAiProviderHttpErrors;
    use PerformsRateLimitedAiRequests;

    private string $apiKey;

    private string $baseUrl;

    private string $apiVersion;

    private string $model;

    private int $timeout;

    public function __construct()
    {
        $config = config('ai.providers.gemini', []);

        $this->apiKey = (string) ($config['api_key'] ?? '');
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? 'https://generativelanguage.googleapis.com'), '/');
        $this->apiVersion = (string) ($config['api_version'] ?? 'v1beta');
        $this->model = (string) ($config['model'] ?? 'gemini-2.5-flash');
        $this->timeout = (int) config('ai.timeout', 60);
    }

    public function providerName(): string
    {
        return 'gemini';
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

        $inventoryMasterId = isset($lookupContext['inventory_master_id'])
            ? (int) $lookupContext['inventory_master_id']
            : null;

        $this->logRequestStart($this->providerName(), $this->model, $inventoryMasterId);

        $endpoint = $this->generateContentEndpoint();
        $payload = $this->buildGenerateContentPayload(
            ProductSpecificationPromptBuilder::systemPrompt(),
            ProductSpecificationPromptBuilder::userPrompt($lookupContext),
        );

        try {
            $response = $this->sendWithRateLimitHandling(
                fn () => Http::timeout($this->timeout)
                    ->acceptJson()
                    ->post($endpoint, $payload),
                $this->providerName(),
                $this->endpointWithoutKey($endpoint),
                ['model' => $this->model],
            );
        } catch (ConnectionException $e) {
            throw $this->wrapConnectionException($e, $this->providerName());
        }

        $this->logResponseReceived($this->providerName(), $this->model, $response->status());

        if (!$response->successful()) {
            throw $this->classifyHttpFailure($response, $this->providerName(), $this->endpointWithoutKey($endpoint), [
                'model' => $this->model,
            ]);
        }

        $content = $this->extractTextFromGeminiResponse($response->json());
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

        $endpoint = $this->generateContentEndpoint();
        $payload = $this->buildGenerateContentPayload(
            'Respond with JSON only.',
            ProductSpecificationPromptBuilder::diagnosticUserPrompt(),
        );

        try {
            $response = $this->sendWithRateLimitHandling(
                fn () => Http::timeout($this->timeout)
                    ->acceptJson()
                    ->post($endpoint, $payload),
                $this->providerName(),
                $this->endpointWithoutKey($endpoint),
                ['model' => $this->model],
            );
        } catch (ConnectionException $e) {
            throw $this->wrapConnectionException($e, $this->providerName());
        }

        if (!$response->successful()) {
            throw $this->classifyHttpFailure($response, $this->providerName(), $this->endpointWithoutKey($endpoint));
        }

        $content = $this->extractTextFromGeminiResponse($response->json());
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
                'GEMINI_API_KEY is not configured for inventory AI enrichment.',
            );
        }
    }

    private function generateContentEndpoint(): string
    {
        return "{$this->baseUrl}/{$this->apiVersion}/models/{$this->model}:generateContent?key={$this->apiKey}";
    }

    private function endpointWithoutKey(string $endpoint): string
    {
        return preg_replace('/\?key=[^&]+/', '?key=***', $endpoint) ?? $endpoint;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildGenerateContentPayload(string $systemPrompt, string $userPrompt): array
    {
        return [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $systemPrompt],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userPrompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => max(64, (int) config('ai.rate_limit.max_output_tokens', 512)),
                'responseMimeType' => 'application/json',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $response
     */
    private function extractTextFromGeminiResponse(?array $response): string
    {
        $parts = data_get($response, 'candidates.0.content.parts', []);

        if (!is_array($parts)) {
            throw new AiProviderException(
                'Gemini response did not contain candidates content.',
                AiProviderException::CATEGORY_INVALID_JSON,
                $this->providerName(),
                null,
                false,
            );
        }

        $text = '';
        foreach ($parts as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }

        if (trim($text) === '') {
            $blockReason = data_get($response, 'promptFeedback.blockReason');
            $message = $blockReason
                ? "Gemini blocked the response: {$blockReason}"
                : 'Gemini response did not contain text content.';

            throw new AiProviderException(
                $message,
                AiProviderException::CATEGORY_INVALID_JSON,
                $this->providerName(),
                null,
                false,
            );
        }

        return $text;
    }
}
