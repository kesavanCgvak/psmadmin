<?php

namespace App\Services\InventoryAi\Providers;

use App\Services\InventoryAi\AiEnrichmentResponseParser;
use App\Services\InventoryAi\Contracts\ProductSpecificationAiProvider;
use App\Services\InventoryAi\Exceptions\AiProviderException;
use App\Services\InventoryAi\ProductSpecificationPromptBuilder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        return $this->requestCompletion(
            ProductSpecificationPromptBuilder::systemPrompt(),
            ProductSpecificationPromptBuilder::userPrompt($lookupContext),
        );
    }

    /**
     * @return array{parsed: array<string, mixed>, raw_response: mixed, provider: string, model: string}
     */
    public function diagnosticPing(): array
    {
        $this->ensureConfigured();

        return $this->requestCompletion(
            'Respond with JSON only.',
            ProductSpecificationPromptBuilder::diagnosticUserPrompt(),
        );
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
     * @return array{parsed: array<string, mixed>, raw_response: array<string, mixed>|null, provider: string, model: string}
     */
    private function requestCompletion(string $systemPrompt, string $userPrompt): array
    {
        $limit = $this->configuredOutputTokenLimit();
        $response = $this->postChat($systemPrompt, $userPrompt, $limit);

        try {
            return $this->resultFromResponse($response);
        } catch (AiProviderException $e) {
            if ($e->category !== AiProviderException::CATEGORY_OUTPUT_TOKEN_LIMIT) {
                throw $e;
            }

            $retryLimit = $this->outputTokenRetryLimit();
            if ($retryLimit <= $limit) {
                throw $this->tokenLimitFailure($response, false);
            }

            $this->logOutcome($response, 'Inventory AI enrichment output token limit reached; retrying once.', [
                'retry_max_completion_tokens' => $retryLimit,
            ]);

            $retryResponse = $this->postChat($systemPrompt, $userPrompt, $retryLimit);

            try {
                return $this->resultFromResponse($retryResponse);
            } catch (AiProviderException $retryException) {
                if ($retryException->category !== AiProviderException::CATEGORY_OUTPUT_TOKEN_LIMIT) {
                    throw $retryException;
                }

                throw $this->tokenLimitFailure($retryResponse, true);
            }
        }
    }

    private function postChat(string $systemPrompt, string $userPrompt, int $maxCompletionTokens): Response
    {
        $endpoint = "{$this->baseUrl}/chat/completions";
        $payload = $this->buildChatPayload($systemPrompt, $userPrompt, $maxCompletionTokens);

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

        return $response;
    }

    /**
     * @return array{parsed: array<string, mixed>, raw_response: array<string, mixed>|null, provider: string, model: string}
     */
    private function resultFromResponse(Response $response): array
    {
        $body = $this->responseBody($response);
        $finishReason = data_get($body, 'choices.0.finish_reason');
        $message = data_get($body, 'choices.0.message');
        $message = is_array($message) ? $message : null;

        if ($this->refusalText($message) !== null) {
            $this->logOutcome($response, 'Inventory AI enrichment response was refused.');

            throw new AiProviderException(
                'OpenAI refused the enrichment request. Response ' . $this->responseId($body) . '.',
                AiProviderException::CATEGORY_REFUSAL,
                $this->providerName(),
                $response->status(),
                false,
            );
        }

        if ($finishReason === 'content_filter') {
            $this->logOutcome($response, 'Inventory AI enrichment response was content filtered.');

            throw new AiProviderException(
                'OpenAI filtered the enrichment response. Response ' . $this->responseId($body) . ', finish_reason content_filter.',
                AiProviderException::CATEGORY_CONTENT_FILTER,
                $this->providerName(),
                $response->status(),
                false,
            );
        }

        $content = $this->extractMessageContent($message);
        if ($content === null) {
            if ($finishReason === 'length') {
                throw new AiProviderException(
                    'OpenAI output token limit reached.',
                    AiProviderException::CATEGORY_OUTPUT_TOKEN_LIMIT,
                    $this->providerName(),
                    $response->status(),
                    false,
                );
            }

            $this->logOutcome($response, 'Inventory AI enrichment response had no usable message content.');

            throw new AiProviderException(
                'OpenAI response did not contain message content. Response ' . $this->responseId($body)
                . ', finish_reason ' . $this->finishReasonLabel($finishReason) . '.',
                AiProviderException::CATEGORY_EMPTY_RESPONSE,
                $this->providerName(),
                $response->status(),
                false,
            );
        }

        try {
            $parsed = AiEnrichmentResponseParser::parseJsonContent($content, $this->providerName());
        } catch (AiProviderException $e) {
            if ($finishReason === 'length') {
                throw new AiProviderException(
                    $e->getMessage(),
                    AiProviderException::CATEGORY_OUTPUT_TOKEN_LIMIT,
                    $this->providerName(),
                    $response->status(),
                    false,
                    $e,
                );
            }

            throw $e;
        }

        return [
            'parsed' => $parsed,
            'raw_response' => $body,
            'provider' => $this->providerName(),
            'model' => $this->model,
        ];
    }

    private function tokenLimitFailure(Response $response, bool $retried): AiProviderException
    {
        $body = $this->responseBody($response);
        $this->logOutcome($response, 'Inventory AI enrichment response hit the output token limit.', [
            'retried' => $retried,
        ]);

        $detail = $retried
            ? ' A single retry with a higher token limit also stopped early.'
            : '';

        return new AiProviderException(
            'OpenAI stopped before the product specification was complete because the output token limit was reached. Response '
            . $this->responseId($body)
            . ', finish_reason length, completion_tokens '
            . $this->tokenCount(data_get($body, 'usage.completion_tokens'))
            . ', reasoning_tokens '
            . $this->tokenCount(data_get($body, 'usage.completion_tokens_details.reasoning_tokens'))
            . '.'
            . $detail,
            AiProviderException::CATEGORY_OUTPUT_TOKEN_LIMIT,
            $this->providerName(),
            $response->status(),
            false,
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function logOutcome(Response $response, string $message, array $extra = []): void
    {
        $body = $this->responseBody($response);
        $choiceMessage = data_get($body, 'choices.0.message');
        $choiceMessage = is_array($choiceMessage) ? $choiceMessage : null;
        $choices = data_get($body, 'choices');

        Log::warning($message, array_merge([
            'provider' => $this->providerName(),
            'model' => $this->model,
            'response_id' => $this->responseId($body),
            'http_status' => $response->status(),
            'finish_reason' => data_get($body, 'choices.0.finish_reason'),
            'choices_present' => is_array($choices) && $choices !== [],
            'prompt_tokens' => data_get($body, 'usage.prompt_tokens'),
            'completion_tokens' => data_get($body, 'usage.completion_tokens'),
            'reasoning_tokens' => data_get($body, 'usage.completion_tokens_details.reasoning_tokens'),
            'refusal_returned' => $this->refusalText($choiceMessage) !== null,
            'message_content_missing' => $this->extractMessageContent($choiceMessage) === null,
        ], $extra));
    }

    /**
     * @param  array<string, mixed>|null  $message
     */
    private function extractMessageContent(?array $message): ?string
    {
        if ($message === null || !array_key_exists('content', $message)) {
            return null;
        }

        $content = $message['content'];
        if (is_string($content)) {
            $trimmed = trim($content);

            return $trimmed === '' ? null : $trimmed;
        }

        if (!is_array($content)) {
            return null;
        }

        $text = '';
        foreach ($content as $part) {
            if (!is_array($part)) {
                continue;
            }

            $type = $part['type'] ?? null;
            if ($type === 'reasoning' || $type === 'reasoning_text') {
                continue;
            }

            if (isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }

        $text = trim($text);

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<string, mixed>|null  $message
     */
    private function refusalText(?array $message): ?string
    {
        if ($message === null || !isset($message['refusal']) || !is_string($message['refusal'])) {
            return null;
        }

        $refusal = trim($message['refusal']);

        return $refusal === '' ? null : $refusal;
    }

    /**
     * @return array<string, mixed>
     */
    private function responseBody(Response $response): array
    {
        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function responseId(array $body): string
    {
        $id = $body['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : 'unknown';
    }

    private function finishReasonLabel(mixed $finishReason): string
    {
        return is_string($finishReason) && $finishReason !== '' ? $finishReason : 'none';
    }

    private function tokenCount(mixed $value): string
    {
        return is_numeric($value) ? (string) $value : 'unavailable';
    }

    private function configuredOutputTokenLimit(): int
    {
        return max(64, (int) config('ai.rate_limit.max_output_tokens', 512));
    }

    private function outputTokenRetryLimit(): int
    {
        $configured = (int) config('ai.rate_limit.max_output_token_retry_limit', 2048);
        if ($configured < 1) {
            return 0;
        }

        return max(64, $configured);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildChatPayload(string $systemPrompt, string $userPrompt, int $maxCompletionTokens): array
    {
        return [
            'model' => $this->model,
            'max_completion_tokens' => $maxCompletionTokens,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];
    }
}
