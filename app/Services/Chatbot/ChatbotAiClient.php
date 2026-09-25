<?php

namespace App\Services\Chatbot;

use App\Services\InventoryAi\AiProviderFactory;
use App\Services\InventoryAi\Exceptions\AiProviderException;
use App\Services\InventoryAi\Providers\HandlesAiProviderHttpErrors;
use App\Services\InventoryAi\Providers\PerformsRateLimitedAiRequests;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ChatbotAiClient
{
    use HandlesAiProviderHttpErrors;
    use PerformsRateLimitedAiRequests;

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{content: string, provider: string, model: string, raw_response: array<string, mixed>|null}
     */
    public function chat(string $systemPrompt, array $messages): array
    {
        AiProviderFactory::assertConfigured();

        $provider = AiProviderFactory::activeProviderName();

        return match ($provider) {
            'openai' => $this->chatOpenAi($systemPrompt, $messages),
            'gemini' => $this->chatGemini($systemPrompt, $messages),
            default => throw new AiProviderException(
                "Unsupported AI provider [{$provider}] for chatbot.",
                AiProviderException::CATEGORY_CONFIGURATION,
                $provider,
                null,
                false,
            ),
        };
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{content: string, provider: string, model: string, raw_response: array<string, mixed>|null}
     */
    private function chatOpenAi(string $systemPrompt, array $messages): array
    {
        $config = config('ai.providers.openai', []);
        $apiKey = (string) ($config['api_key'] ?? '');
        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.openai.com/v1'), '/');
        $model = (string) ($config['model'] ?? 'gpt-4.1-nano');
        $timeout = (int) config('ai.timeout', 60);
        $endpoint = "{$baseUrl}/chat/completions";

        $payloadMessages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $messages,
        );

        $payload = [
            'model' => $model,
            'temperature' => (float) config('chatbot.temperature', 0.3),
            'max_tokens' => max(64, (int) config('chatbot.max_output_tokens', 1024)),
            'messages' => $payloadMessages,
        ];

        try {
            $response = $this->sendWithRateLimitHandling(
                fn () => Http::withToken($apiKey)
                    ->timeout($timeout)
                    ->acceptJson()
                    ->post($endpoint, $payload),
                'openai',
                $endpoint,
                ['model' => $model],
            );
        } catch (ConnectionException $e) {
            throw $this->wrapConnectionException($e, 'openai');
        }

        if (!$response->successful()) {
            throw $this->classifyHttpFailure($response, 'openai', $endpoint, ['model' => $model]);
        }

        $content = data_get($response->json(), 'choices.0.message.content');
        if (!is_string($content) || trim($content) === '') {
            throw new AiProviderException(
                'OpenAI chatbot response did not contain message content.',
                AiProviderException::CATEGORY_INVALID_JSON,
                'openai',
                $response->status(),
                false,
            );
        }

        return [
            'content' => trim($content),
            'provider' => 'openai',
            'model' => $model,
            'raw_response' => $response->json(),
        ];
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{content: string, provider: string, model: string, raw_response: array<string, mixed>|null}
     */
    private function chatGemini(string $systemPrompt, array $messages): array
    {
        $config = config('ai.providers.gemini', []);
        $apiKey = (string) ($config['api_key'] ?? '');
        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://generativelanguage.googleapis.com'), '/');
        $apiVersion = (string) ($config['api_version'] ?? 'v1beta');
        $model = (string) ($config['model'] ?? 'gemini-3.6-flash');
        $timeout = (int) config('ai.timeout', 60);
        $endpoint = "{$baseUrl}/{$apiVersion}/models/{$model}:generateContent?key={$apiKey}";
        $safeEndpoint = preg_replace('/\?key=[^&]+/', '?key=***', $endpoint) ?? $endpoint;

        $contents = [];
        foreach ($messages as $message) {
            $role = $message['role'] === 'assistant' ? 'model' : 'user';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $message['content']]],
            ];
        }

        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => (float) config('chatbot.temperature', 0.3),
                'maxOutputTokens' => max(64, (int) config('chatbot.max_output_tokens', 1024)),
            ],
        ];

        try {
            $response = $this->sendWithRateLimitHandling(
                fn () => Http::timeout($timeout)
                    ->acceptJson()
                    ->post($endpoint, $payload),
                'gemini',
                $safeEndpoint,
                ['model' => $model],
            );
        } catch (ConnectionException $e) {
            throw $this->wrapConnectionException($e, 'gemini');
        }

        if (!$response->successful()) {
            throw $this->classifyHttpFailure($response, 'gemini', $safeEndpoint, ['model' => $model]);
        }

        $content = $this->extractGeminiText($response->json());

        return [
            'content' => $content,
            'provider' => 'gemini',
            'model' => $model,
            'raw_response' => $response->json(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $response
     */
    private function extractGeminiText(?array $response): string
    {
        $parts = data_get($response, 'candidates.0.content.parts', []);

        if (!is_array($parts)) {
            throw new AiProviderException(
                'Gemini chatbot response did not contain candidates content.',
                AiProviderException::CATEGORY_INVALID_JSON,
                'gemini',
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

        $text = trim($text);
        if ($text === '') {
            throw new AiProviderException(
                'Gemini chatbot response did not contain text content.',
                AiProviderException::CATEGORY_INVALID_JSON,
                'gemini',
                null,
                false,
            );
        }

        return $text;
    }
}
