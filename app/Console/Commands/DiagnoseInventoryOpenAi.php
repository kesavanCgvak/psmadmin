<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class DiagnoseInventoryOpenAi extends Command
{
    protected $signature = 'inventory:diagnose-openai
                            {--minimal-chat : Send a minimal chat/completions request to reproduce quota errors}';

    protected $description = 'Diagnose OpenAI connectivity (legacy). Prefer: php artisan ai:test';

    public function handle(): int
    {
        $this->warn('This command is OpenAI-specific. Use `php artisan ai:test` for the configured provider.');
        $this->newLine();

        $apiKey = config('ai.providers.openai.api_key') ?: config('inventory_ai.api_key');
        $baseUrl = rtrim((string) (config('ai.providers.openai.base_url') ?: config('inventory_ai.base_url')), '/');
        $model = (string) (config('ai.providers.openai.model') ?: config('inventory_ai.model'));
        $timeout = (int) config('ai.timeout', 60);

        $this->info('Inventory AI OpenAI diagnostics');
        $this->newLine();

        $this->table(['Setting', 'Value'], [
            ['OPENAI_API_KEY configured', empty($apiKey) ? 'NO' : 'YES (prefix: ' . substr($apiKey, 0, 8) . '...)'],
            ['base_url', $baseUrl],
            ['model', $model],
            ['timeout (seconds)', (string) $timeout],
            ['enrichment endpoint', $baseUrl . '/chat/completions'],
        ]);

        if (empty($apiKey)) {
            $this->error('OPENAI_API_KEY is not set. Enrichment cannot run.');

            return Command::FAILURE;
        }

        $this->newLine();
        $this->info('Step 1: GET /models (authentication check)');
        $modelsResponse = Http::withToken($apiKey)->timeout($timeout)->get("{$baseUrl}/models");

        $this->line('HTTP status: ' . $modelsResponse->status());
        if ($modelsResponse->successful()) {
            $modelIds = collect(data_get($modelsResponse->json(), 'data', []))
                ->pluck('id')
                ->filter(fn ($id) => is_string($id))
                ->values();
            $modelAvailable = $modelIds->contains($model);
            $this->info('Models list succeeded — API key authentication is valid.');
            $this->line('Configured model "' . $model . '" in models list: ' . ($modelAvailable ? 'YES' : 'NO / not listed'));
            if (!$modelAvailable) {
                $similar = $modelIds->filter(fn ($id) => str_contains($id, 'gpt-4o') || str_contains($id, 'gpt-3.5'))->take(10)->all();
                if ($similar !== []) {
                    $this->warn('Similar available models: ' . implode(', ', $similar));
                }
            }
        } else {
            $this->error('Models list failed.');
            $this->line($modelsResponse->body());
        }

        if (!$this->option('minimal-chat')) {
            $this->newLine();
            $this->comment('Run with --minimal-chat to test POST /chat/completions (may consume quota).');

            return $modelsResponse->successful() ? Command::SUCCESS : Command::FAILURE;
        }

        $this->newLine();
        $this->info('Step 2: POST /chat/completions (billable enrichment path)');

        $payload = [
            'model' => $model,
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => 'Respond with JSON only: {"diagnostic": true}'],
                ['role' => 'user', 'content' => 'diagnostic ping'],
            ],
        ];

        $this->line('Request payload (representative of enrichment):');
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $chatResponse = Http::withToken($apiKey)
            ->timeout($timeout)
            ->acceptJson()
            ->post("{$baseUrl}/chat/completions", $payload);

        $this->newLine();
        $this->line('HTTP status: ' . $chatResponse->status());
        $this->line('Response body:');
        $this->line($chatResponse->body());

        $error = $chatResponse->json('error');
        if (is_array($error)) {
            $this->newLine();
            $this->table(['OpenAI error field', 'Value'], [
                ['type', (string) ($error['type'] ?? '—')],
                ['code', (string) ($error['code'] ?? '—')],
                ['message', (string) ($error['message'] ?? '—')],
            ]);

            if (($error['code'] ?? null) === 'insufficient_quota' || ($error['type'] ?? null) === 'insufficient_quota') {
                $this->newLine();
                $this->warn('Root cause indicator: insufficient_quota on chat/completions.');
                $this->line('GET /models can succeed while chat/completions fails when the account has');
                $this->line('valid credentials but no remaining prepaid credits or active billing for usage.');
            }
        }

        return $chatResponse->successful() ? Command::SUCCESS : Command::FAILURE;
    }
}
