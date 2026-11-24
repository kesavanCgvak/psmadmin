<?php

namespace Tests\Unit;

use App\Services\InventoryAi\Exceptions\AiProviderException;
use App\Services\InventoryAi\Providers\GeminiProductSpecificationProvider;
use App\Services\InventoryAi\Providers\OpenAiProductSpecificationProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class OpenAiProductSpecificationProviderTest extends TestCase
{
    private const TOKEN_LIMIT = 768;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'ai.provider' => 'openai',
            'ai.timeout' => 5,
            'ai.rate_limit.requests_per_minute' => 100000,
            'ai.rate_limit.max_retries' => 1,
            'ai.rate_limit.initial_backoff_seconds' => 0,
            'ai.rate_limit.max_backoff_seconds' => 0,
            'ai.rate_limit.max_output_tokens' => self::TOKEN_LIMIT,
            'ai.rate_limit.max_output_token_retry_limit' => 2048,
            'ai.providers.openai.api_key' => 'test-openai-key',
            'ai.providers.openai.base_url' => 'https://api.openai.com/v1',
            'ai.providers.openai.model' => 'gpt-5.6-luna',
            'ai.providers.gemini.api_key' => 'test-gemini-key',
            'ai.providers.gemini.base_url' => 'https://generativelanguage.googleapis.com',
            'ai.providers.gemini.api_version' => 'v1beta',
            'ai.providers.gemini.model' => 'gemini-2.5-flash',
        ]);
    }

    public function test_openai_enrichment_uses_max_completion_tokens_and_parses_the_response(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->openAiChatResponse($this->specificationJson()),
                200,
            ),
        ]);

        $result = (new OpenAiProductSpecificationProvider())->enrich([
            'inventory_master_id' => 42,
            'model' => 'SM58',
        ]);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.openai.com/v1/chat/completions'
                && $request->method() === 'POST'
                && ($payload['model'] ?? null) === 'gpt-5.6-luna'
                && ($payload['max_completion_tokens'] ?? null) === self::TOKEN_LIMIT
                && ! array_key_exists('max_tokens', $payload)
                && ! array_key_exists('temperature', $payload)
                && ($payload['response_format']['type'] ?? null) === 'json_object'
                && ($payload['messages'][0]['role'] ?? null) === 'system'
                && str_contains((string) ($payload['messages'][0]['content'] ?? ''), 'product specification research assistant')
                && ($payload['messages'][1]['role'] ?? null) === 'user'
                && str_contains((string) ($payload['messages'][1]['content'] ?? ''), 'SM58');
        });

        $this->assertSame('openai', $result['provider']);
        $this->assertSame('gpt-5.6-luna', $result['model']);
        $this->assertSame(12.5, $result['parsed']['height']);
        $this->assertSame(8, $result['parsed']['width']);
        $this->assertSame(8, $result['parsed']['length']);
        $this->assertSame(0.72, $result['parsed']['weight']);
        $this->assertSame('inch', $result['parsed']['linear_unit']);
        $this->assertSame('pound', $result['parsed']['weight_unit']);
        $this->assertSame(86, $result['parsed']['confidence_score']);
        $this->assertSame('https://example.test/sm58', $result['parsed']['source_url']);
    }

    public function test_openai_diagnostic_request_uses_the_same_token_parameter(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->openAiChatResponse('{"diagnostic":true,"provider_test":"ok","confidence_score":100}'),
                200,
            ),
        ]);

        $result = (new OpenAiProductSpecificationProvider())->diagnosticPing();

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return ($payload['max_completion_tokens'] ?? null) === self::TOKEN_LIMIT
                && ! array_key_exists('max_tokens', $payload)
                && ! array_key_exists('temperature', $payload)
                && ($payload['model'] ?? null) === 'gpt-5.6-luna';
        });

        $this->assertTrue($result['parsed']['diagnostic']);
        $this->assertSame('ok', $result['parsed']['provider_test']);
        $this->assertSame('openai', $result['provider']);
    }

    public function test_openai_rate_limit_retry_keeps_max_completion_tokens(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::sequence()
                ->push([
                    'error' => [
                        'message' => 'Rate limit reached for requests',
                        'type' => 'requests',
                        'code' => 'rate_limit_exceeded',
                    ],
                ], 429, ['Retry-After' => '0'])
                ->push($this->openAiChatResponse($this->specificationJson()), 200),
        ]);

        $result = (new OpenAiProductSpecificationProvider())->enrich([
            'model' => 'SM58',
        ]);

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return ($payload['max_completion_tokens'] ?? null) === self::TOKEN_LIMIT
                && ! array_key_exists('max_tokens', $payload)
                && ! array_key_exists('temperature', $payload);
        });
        $this->assertSame(86, $result['parsed']['confidence_score']);
    }

    public function test_openai_provider_failure_is_logged_and_not_retried(): void
    {
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'error' => [
                    'message' => "Unsupported parameter: 'max_tokens' is not supported with this model. Use 'max_completion_tokens' instead.",
                    'type' => 'invalid_request_error',
                    'param' => 'max_tokens',
                    'code' => 'unsupported_parameter',
                ],
            ], 400),
        ]);

        try {
            (new OpenAiProductSpecificationProvider())->enrich([
                'model' => 'SM58',
            ]);
            $this->fail('Expected the OpenAI 400 response to raise AiProviderException.');
        } catch (AiProviderException $e) {
            $this->assertSame('openai', $e->provider);
            $this->assertSame(400, $e->httpStatus);
            $this->assertSame(AiProviderException::CATEGORY_UNKNOWN, $e->category);
            $this->assertFalse($e->isRetryable());
            $this->assertStringContainsString('HTTP 400 (openai)', $e->getMessage());
        }

        Http::assertSentCount(1);

        $failure = collect($logged)->first(function (MessageLogged $event): bool {
            return $event->level === 'error'
                && $event->message === 'Inventory AI enrichment API request failed.';
        });

        $this->assertInstanceOf(MessageLogged::class, $failure);
        $this->assertSame('openai', $failure->context['provider'] ?? null);
        $this->assertSame(400, $failure->context['http_status'] ?? null);
        $this->assertSame('gpt-5.6-luna', $failure->context['model'] ?? null);
        $this->assertStringContainsString('max_completion_tokens', (string) ($failure->context['error_message'] ?? ''));
    }

    public function test_empty_openai_message_logs_token_usage_and_is_not_saved_as_success(): void
    {
        $logged = $this->captureLogs();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->openAiChatResponse(null, 'stop', [], null, 'chatcmpl-empty'),
                200,
            ),
        ]);

        try {
            (new OpenAiProductSpecificationProvider())->enrich(['model' => 'SM58']);
            $this->fail('Expected an empty OpenAI message to fail enrichment.');
        } catch (AiProviderException $e) {
            $this->assertSame(AiProviderException::CATEGORY_EMPTY_RESPONSE, $e->category);
            $this->assertFalse($e->isRetryable());
            $this->assertStringContainsString('did not contain message content', $e->getMessage());
            $this->assertStringContainsString('chatcmpl-empty', $e->getMessage());
            $this->assertStringContainsString('finish_reason stop', $e->getMessage());
        }

        Http::assertSentCount(1);
        $this->assertLoggedDiagnostics($logged, 'Inventory AI enrichment response had no usable message content.', [
            'response_id' => 'chatcmpl-empty',
            'http_status' => 200,
            'finish_reason' => 'stop',
            'choices_present' => true,
            'prompt_tokens' => 308,
            'completion_tokens' => 356,
            'reasoning_tokens' => 204,
            'refusal_returned' => false,
            'message_content_missing' => true,
        ]);
    }

    public function test_missing_openai_choices_are_reported_as_empty_content(): void
    {
        $logged = $this->captureLogs();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-no-choices',
                'model' => 'gpt-5.6-luna',
                'choices' => [],
                'usage' => [
                    'prompt_tokens' => 308,
                    'completion_tokens' => 0,
                    'completion_tokens_details' => ['reasoning_tokens' => 0],
                ],
            ], 200),
        ]);

        try {
            (new OpenAiProductSpecificationProvider())->diagnosticPing();
            $this->fail('Expected a response without choices to fail.');
        } catch (AiProviderException $e) {
            $this->assertSame(AiProviderException::CATEGORY_EMPTY_RESPONSE, $e->category);
            $this->assertStringContainsString('chatcmpl-no-choices', $e->getMessage());
            $this->assertStringContainsString('finish_reason none', $e->getMessage());
        }

        Http::assertSentCount(1);
        $this->assertLoggedDiagnostics($logged, 'Inventory AI enrichment response had no usable message content.', [
            'choices_present' => false,
            'message_content_missing' => true,
            'response_id' => 'chatcmpl-no-choices',
        ]);
    }

    public function test_length_finish_retries_once_with_the_configured_retry_limit(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::sequence()
                ->push($this->openAiChatResponse(null, 'length', [
                    'completion_tokens' => self::TOKEN_LIMIT,
                    'completion_tokens_details' => ['reasoning_tokens' => self::TOKEN_LIMIT],
                ], null, 'chatcmpl-length'), 200)
                ->push($this->openAiChatResponse($this->specificationJson()), 200),
        ]);

        $result = (new OpenAiProductSpecificationProvider())->enrich(['model' => 'SM58']);

        $this->assertSame(86, $result['parsed']['confidence_score']);
        $this->assertSame([self::TOKEN_LIMIT, 2048], $this->sentCompletionTokenLimits());
    }

    public function test_truncated_json_on_length_retries_once(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::sequence()
                ->push($this->openAiChatResponse('{"height":', 'length'), 200)
                ->push($this->openAiChatResponse($this->specificationJson()), 200),
        ]);

        $result = (new OpenAiProductSpecificationProvider())->enrich(['model' => 'SM58']);

        $this->assertSame(12.5, $result['parsed']['height']);
        Http::assertSentCount(2);
    }

    public function test_length_finish_after_retry_records_token_limit_failure(): void
    {
        $logged = $this->captureLogs();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->openAiChatResponse(null, 'length', [
                    'completion_tokens' => 2048,
                    'completion_tokens_details' => ['reasoning_tokens' => 2048],
                ], null, 'chatcmpl-still-length'),
                200,
            ),
        ]);

        try {
            (new OpenAiProductSpecificationProvider())->enrich(['model' => 'SM58']);
            $this->fail('Expected token exhaustion to fail after one retry.');
        } catch (AiProviderException $e) {
            $this->assertSame(AiProviderException::CATEGORY_OUTPUT_TOKEN_LIMIT, $e->category);
            $this->assertFalse($e->isRetryable());
            $this->assertStringContainsString('output token limit', $e->getMessage());
            $this->assertStringContainsString('chatcmpl-still-length', $e->getMessage());
            $this->assertStringContainsString('reasoning_tokens 2048', $e->getMessage());
            $this->assertStringContainsString('also stopped early', $e->getMessage());
        }

        $this->assertSame([self::TOKEN_LIMIT, 2048], $this->sentCompletionTokenLimits());
        $this->assertLoggedDiagnostics($logged, 'Inventory AI enrichment response hit the output token limit.', [
            'response_id' => 'chatcmpl-still-length',
            'finish_reason' => 'length',
            'completion_tokens' => 2048,
            'reasoning_tokens' => 2048,
            'message_content_missing' => true,
            'retried' => true,
        ]);
    }

    public function test_token_limit_is_not_retried_when_the_retry_ceiling_is_not_higher(): void
    {
        config(['ai.rate_limit.max_output_token_retry_limit' => self::TOKEN_LIMIT]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->openAiChatResponse(null, 'length', [], null, 'chatcmpl-capped'),
                200,
            ),
        ]);

        try {
            (new OpenAiProductSpecificationProvider())->enrich(['model' => 'SM58']);
            $this->fail('Expected a capped token limit to fail without a second request.');
        } catch (AiProviderException $e) {
            $this->assertSame(AiProviderException::CATEGORY_OUTPUT_TOKEN_LIMIT, $e->category);
            $this->assertStringNotContainsString('also stopped early', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_openai_refusal_is_not_treated_as_an_empty_response(): void
    {
        $logged = $this->captureLogs();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->openAiChatResponse(null, 'stop', [], 'I cannot help with that request.', 'chatcmpl-refusal'),
                200,
            ),
        ]);

        try {
            (new OpenAiProductSpecificationProvider())->enrich(['model' => 'SM58']);
            $this->fail('Expected a refusal to fail enrichment.');
        } catch (AiProviderException $e) {
            $this->assertSame(AiProviderException::CATEGORY_REFUSAL, $e->category);
            $this->assertFalse($e->isRetryable());
            $this->assertStringContainsString('refused', $e->getMessage());
            $this->assertStringNotContainsString('did not contain message content', $e->getMessage());
        }

        Http::assertSentCount(1);
        $this->assertLoggedDiagnostics($logged, 'Inventory AI enrichment response was refused.', [
            'response_id' => 'chatcmpl-refusal',
            'refusal_returned' => true,
            'message_content_missing' => true,
        ]);
    }

    public function test_openai_content_filter_is_recorded_separately(): void
    {
        $logged = $this->captureLogs();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->openAiChatResponse(null, 'content_filter', [], null, 'chatcmpl-filtered'),
                200,
            ),
        ]);

        try {
            (new OpenAiProductSpecificationProvider())->enrich(['model' => 'SM58']);
            $this->fail('Expected a content filter to fail enrichment.');
        } catch (AiProviderException $e) {
            $this->assertSame(AiProviderException::CATEGORY_CONTENT_FILTER, $e->category);
            $this->assertFalse($e->isRetryable());
            $this->assertStringContainsString('finish_reason content_filter', $e->getMessage());
        }

        Http::assertSentCount(1);
        $this->assertLoggedDiagnostics($logged, 'Inventory AI enrichment response was content filtered.', [
            'finish_reason' => 'content_filter',
            'response_id' => 'chatcmpl-filtered',
        ]);
    }

    public function test_invalid_json_is_not_retried(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->openAiChatResponse('not-json', 'stop'),
                200,
            ),
        ]);

        try {
            (new OpenAiProductSpecificationProvider())->enrich(['model' => 'SM58']);
            $this->fail('Expected invalid JSON to fail enrichment.');
        } catch (AiProviderException $e) {
            $this->assertSame(AiProviderException::CATEGORY_INVALID_JSON, $e->category);
            $this->assertFalse($e->isRetryable());
            $this->assertStringContainsString('not valid JSON', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_gemini_2_5_flash_provider_still_enriches_with_its_own_token_limit(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $this->assertStringContainsString(
                '/v1beta/models/gemini-2.5-flash:generateContent',
                $request->url(),
            );

            $payload = $request->data();
            $this->assertSame(self::TOKEN_LIMIT, $payload['generationConfig']['maxOutputTokens'] ?? null);
            $this->assertSame('application/json', $payload['generationConfig']['responseMimeType'] ?? null);
            $this->assertArrayNotHasKey('max_tokens', $payload);
            $this->assertArrayNotHasKey('max_completion_tokens', $payload);

            return Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => $this->specificationJson()],
                            ],
                        ],
                    ],
                ],
            ], 200);
        });

        $result = (new GeminiProductSpecificationProvider())->enrich([
            'model' => 'SM58',
        ]);

        $this->assertSame('gemini', $result['provider']);
        $this->assertSame('gemini-2.5-flash', $result['model']);
        $this->assertSame(12.5, $result['parsed']['height']);
        $this->assertSame(86, $result['parsed']['confidence_score']);
        $this->assertSame('inch', $result['parsed']['linear_unit']);
    }

    private function specificationJson(): string
    {
        return json_encode([
            'height' => 12.5,
            'width' => 8,
            'length' => 8,
            'weight' => 0.72,
            'linear_unit' => 'inch',
            'weight_unit' => 'pound',
            'confidence_score' => 86,
            'source_url' => 'https://example.test/sm58',
            'reasoning' => 'Manufacturer listing.',
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $usageOverrides
     * @return array<string, mixed>
     */
    private function openAiChatResponse(
        string|array|null $content,
        string $finishReason = 'stop',
        array $usageOverrides = [],
        ?string $refusal = null,
        string $id = 'chatcmpl-test',
    ): array {
        $usage = array_replace_recursive([
            'prompt_tokens' => 308,
            'completion_tokens' => 356,
            'completion_tokens_details' => [
                'reasoning_tokens' => 204,
            ],
        ], $usageOverrides);

        return [
            'id' => $id,
            'object' => 'chat.completion',
            'model' => 'gpt-5.6-luna',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $content,
                        'refusal' => $refusal,
                    ],
                    'finish_reason' => $finishReason,
                ],
            ],
            'usage' => $usage,
        ];
    }

    private function captureLogs(): \ArrayObject
    {
        $logged = new \ArrayObject();
        Log::listen(function (MessageLogged $event) use ($logged): void {
            $logged->append($event);
        });

        return $logged;
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function assertLoggedDiagnostics(\ArrayObject $logged, string $message, array $expected): void
    {
        $event = collect($logged)->first(function (MessageLogged $entry) use ($message, $expected): bool {
            if ($entry->level !== 'warning' || $entry->message !== $message) {
                return false;
            }

            foreach ($expected as $key => $value) {
                if (($entry->context[$key] ?? null) !== $value) {
                    return false;
                }
            }

            return true;
        });

        $this->assertInstanceOf(MessageLogged::class, $event);
        $this->assertSame('openai', $event->context['provider'] ?? null);
        $this->assertSame('gpt-5.6-luna', $event->context['model'] ?? null);
        $this->assertArrayNotHasKey('content', $event->context);
        $this->assertArrayNotHasKey('authorization', $event->context);
    }

    /**
     * @return list<int>
     */
    private function sentCompletionTokenLimits(): array
    {
        $limits = [];
        Http::assertSent(function (Request $request) use (&$limits): bool {
            $limits[] = $request->data()['max_completion_tokens'];

            return true;
        });

        return $limits;
    }
}
