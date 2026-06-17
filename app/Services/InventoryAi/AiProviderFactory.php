<?php

namespace App\Services\InventoryAi;

use App\Services\InventoryAi\Contracts\ProductSpecificationAiProvider;
use App\Services\InventoryAi\Exceptions\AiProviderException;
use App\Services\InventoryAi\Providers\GeminiProductSpecificationProvider;
use App\Services\InventoryAi\Providers\OpenAiProductSpecificationProvider;
use InvalidArgumentException;

final class AiProviderFactory
{
    public static function make(?string $provider = null): ProductSpecificationAiProvider
    {
        $provider = strtolower(trim((string) ($provider ?? config('ai.provider', 'gemini'))));

        return match ($provider) {
            'openai' => new OpenAiProductSpecificationProvider(),
            'gemini' => new GeminiProductSpecificationProvider(),
            default => throw new InvalidArgumentException(
                "Unsupported AI provider [{$provider}]. Supported providers: openai, gemini."
            ),
        };
    }

    public static function activeProviderName(): string
    {
        return strtolower((string) config('ai.provider', 'gemini'));
    }

    public static function isConfigured(?string $provider = null): bool
    {
        $provider = strtolower(trim((string) ($provider ?? self::activeProviderName())));
        $config = config("ai.providers.{$provider}", []);

        return !empty($config['api_key']);
    }

    /**
     * @return array{provider: string, model: string, api_key_configured: bool}
     */
    public static function activeProviderSummary(): array
    {
        $provider = self::activeProviderName();

        try {
            $instance = self::make($provider);
            $model = $instance->modelName();
        } catch (InvalidArgumentException) {
            $model = (string) config("ai.providers.{$provider}.model", '—');
        }

        return [
            'provider' => $provider,
            'model' => $model,
            'api_key_configured' => self::isConfigured($provider),
        ];
    }

    public static function assertConfigured(): void
    {
        $provider = self::activeProviderName();

        if (!self::isConfigured($provider)) {
            $envKey = match ($provider) {
                'openai' => 'OPENAI_API_KEY',
                'gemini' => 'GEMINI_API_KEY',
                default => 'API key',
            };

            throw AiProviderException::configuration(
                $provider,
                "{$envKey} is not configured for AI provider [{$provider}].",
            );
        }
    }
}
