<?php

namespace App\Console\Commands;

use App\Services\InventoryAi\AiProviderFactory;
use App\Services\InventoryAi\Exceptions\AiProviderException;
use Illuminate\Console\Command;

class TestAiProvider extends Command
{
    protected $signature = 'ai:test
                            {--provider= : Override configured AI provider (openai|gemini)}';

    protected $description = 'Test the configured AI provider with a minimal diagnostic request';

    public function handle(): int
    {
        $providerName = strtolower((string) ($this->option('provider') ?: AiProviderFactory::activeProviderName()));

        $this->info('AI Provider Test');
        $this->newLine();

        try {
            $provider = AiProviderFactory::make($providerName);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->table(['Setting', 'Value'], [
            ['Provider', $provider->providerName()],
            ['Model', $provider->modelName()],
            ['API key configured', AiProviderFactory::isConfigured($providerName) ? 'YES' : 'NO'],
        ]);

        if (!AiProviderFactory::isConfigured($providerName)) {
            $envKey = $providerName === 'gemini' ? 'GEMINI_API_KEY' : 'OPENAI_API_KEY';
            $this->error("{$envKey} is not set.");

            return Command::FAILURE;
        }

        $this->newLine();
        $this->info('Sending diagnostic request...');

        try {
            $result = $provider->diagnosticPing();
        } catch (AiProviderException $e) {
            $this->error('Status: FAILED');
            $this->table(['Field', 'Value'], [
                ['Category', $e->category],
                ['HTTP status', $e->httpStatus ?? '—'],
                ['Retryable', $e->isRetryable() ? 'yes' : 'no'],
                ['Message', $e->getMessage()],
            ]);

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Status: FAILED');
            $this->line($e->getMessage());

            return Command::FAILURE;
        }

        $this->info('Status: SUCCESS');
        $this->newLine();
        $this->line('Parsed response:');
        $this->line(json_encode($result['parsed'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }
}
