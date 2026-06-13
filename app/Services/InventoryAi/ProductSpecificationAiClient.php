<?php

namespace App\Services\InventoryAi;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ProductSpecificationAiClient
{
    /**
     * @param  array<string, mixed>  $lookupContext
     * @return array<string, mixed>
     */
    public function enrich(array $lookupContext): array
    {
        $apiKey = config('inventory_ai.api_key');
        if (empty($apiKey)) {
            throw new RuntimeException('OPENAI_API_KEY is not configured for inventory AI enrichment.');
        }

        $baseUrl = rtrim((string) config('inventory_ai.base_url'), '/');
        $model = (string) config('inventory_ai.model');
        $timeout = (int) config('inventory_ai.timeout', 60);

        $systemPrompt = <<<'PROMPT'
You are a product specification research assistant for professional AV, lighting, and event equipment.
Given product identifying information, estimate physical dimensions and weight using manufacturer data, spec sheets, or reliable product listings when possible.

Respond with JSON only (no markdown) using this exact schema:
{
  "height": number or null,
  "width": number or null,
  "length": number or null,
  "weight": number or null,
  "linear_unit": string or null,
  "weight_unit": string or null,
  "confidence_score": integer 0-100,
  "source_url": string or null
}

Rules:
- height, width, length are the outer physical dimensions (length may also be called depth).
- Use positive numbers only when you have reasonable evidence.
- linear_unit examples: inch, foot, centimeter, meter
- weight_unit examples: pound, kilogram, gram
- confidence_score reflects how certain you are overall (0-100).
- source_url should be a manufacturer page, datasheet, or reputable retailer URL when available.
- If you cannot determine a value, use null for that field and lower the confidence_score accordingly.
PROMPT;

        $userPrompt = 'Find physical specifications for this product:' . "\n"
            . json_encode($lookupContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $response = Http::withToken($apiKey)
            ->timeout($timeout)
            ->acceptJson()
            ->post("{$baseUrl}/chat/completions", [
                'model' => $model,
                'temperature' => 0.2,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

        if (!$response->successful()) {
            Log::error('Inventory AI enrichment API request failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
                'lookup_context' => $lookupContext,
            ]);

            throw new RuntimeException(
                'AI enrichment request failed with HTTP ' . $response->status() . '.'
            );
        }

        $content = data_get($response->json(), 'choices.0.message.content');
        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('AI enrichment response did not contain message content.');
        }

        $parsed = json_decode($content, true);
        if (!is_array($parsed)) {
            throw new RuntimeException('AI enrichment response was not valid JSON.');
        }

        return [
            'parsed' => $parsed,
            'raw_response' => $response->json(),
        ];
    }
}
