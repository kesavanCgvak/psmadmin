<?php

namespace App\Services\InventoryAi;

use Illuminate\Support\Facades\Log;

final class AiEnrichmentResponseParser
{
    /**
     * @return array<string, mixed>
     */
    public static function parseJsonContent(string $content, string $provider): array
    {
        $trimmed = trim($content);

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $trimmed, $matches)) {
            $trimmed = trim($matches[1]);
        }

        $parsed = json_decode($trimmed, true);

        if (!is_array($parsed)) {
            Log::warning('Inventory AI response JSON parse failed.', [
                'provider' => $provider,
                'json_error' => json_last_error_msg(),
                'content_preview' => mb_substr($content, 0, 500),
            ]);

            throw new \App\Services\InventoryAi\Exceptions\AiProviderException(
                'AI response was not valid JSON: ' . json_last_error_msg(),
                \App\Services\InventoryAi\Exceptions\AiProviderException::CATEGORY_INVALID_JSON,
                $provider,
                null,
                false,
            );
        }

        self::validateSchema($parsed, $provider);

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private static function validateSchema(array $parsed, string $provider): void
    {
        if (!array_key_exists('confidence_score', $parsed)) {
            Log::warning('Inventory AI response missing confidence_score.', [
                'provider' => $provider,
                'keys' => array_keys($parsed),
            ]);
        } elseif ($parsed['confidence_score'] !== null && $parsed['confidence_score'] !== '') {
            $score = (int) $parsed['confidence_score'];
            if ($score < 0 || $score > 100) {
                Log::warning('Inventory AI response has out-of-range confidence_score.', [
                    'provider' => $provider,
                    'confidence_score' => $parsed['confidence_score'],
                ]);
            }
        }

        foreach (['height', 'width', 'length', 'weight'] as $field) {
            if (!array_key_exists($field, $parsed) || $parsed[$field] === null || $parsed[$field] === '') {
                continue;
            }

            if (!is_numeric($parsed[$field])) {
                Log::warning('Inventory AI response has non-numeric dimension/weight.', [
                    'provider' => $provider,
                    'field' => $field,
                    'value' => $parsed[$field],
                ]);
            }
        }
    }
}
