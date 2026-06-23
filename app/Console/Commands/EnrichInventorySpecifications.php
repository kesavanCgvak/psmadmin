<?php

namespace App\Console\Commands;

use App\Jobs\EnrichInventorySpecificationsBatchJob;
use App\Models\InventoryMasterAiSpec;
use App\Models\Product;
use App\Services\InventoryAi\AiRequestPacer;
use App\Support\InventoryMasterSpecEnrichment;
use App\Services\InventoryAi\AiProviderFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EnrichInventorySpecifications extends Command
{
    protected $signature = 'inventory:enrich-specifications
                            {--batch= : Number of products per batch (queued jobs or sync chunk size)}
                            {--limit= : Maximum number of products to process (sync max: see INVENTORY_AI_MAX_SYNC_CLI_LIMIT)}
                            {--sync : Process synchronously in this process instead of queueing}
                            {--retry-incomplete : Re-queue products that already have an approved AI spec}
                            {--requests-per-minute= : Override AI_REQUESTS_PER_MINUTE pacing for this run}
                            {--dry-run : Show counts without dispatching jobs}';

    protected $description = 'Queue or synchronously run AI enrichment for inventory_master products missing physical specifications';

    public function handle(): int
    {
        $batchSize = (int) ($this->option('batch') ?: config('inventory_ai.batch_size', 100));
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $dryRun = (bool) $this->option('dry-run');
        $sync = (bool) $this->option('sync');
        $retryIncomplete = (bool) $this->option('retry-incomplete');
        $requestsPerMinuteOverride = $this->option('requests-per-minute');

        if ($requestsPerMinuteOverride !== null) {
            config([
                'ai.rate_limit.requests_per_minute' => max(1, (int) $requestsPerMinuteOverride),
            ]);
        }

        if ($batchSize < 1) {
            $this->error('Batch size must be at least 1.');

            return Command::FAILURE;
        }

        $maxSyncCliLimit = (int) config('inventory_ai.max_sync_cli_limit', 1000);

        if ($sync && $limit !== null && $limit > $maxSyncCliLimit) {
            $this->error("Sync limit cannot exceed {$maxSyncCliLimit}. Set INVENTORY_AI_MAX_SYNC_CLI_LIMIT or use a lower --limit.");

            return Command::FAILURE;
        }

        if ($sync && $limit === null) {
            $limit = $maxSyncCliLimit;
            $this->comment("No --limit provided for sync mode; defaulting to {$maxSyncCliLimit} products.");
        }

        if (!AiProviderFactory::isConfigured() && !$dryRun) {
            $provider = AiProviderFactory::activeProviderName();
            $envKey = $provider === 'gemini' ? 'GEMINI_API_KEY' : 'OPENAI_API_KEY';
            $this->error("AI provider [{$provider}] is not configured. Set {$envKey} and AI_PROVIDER in .env before running enrichment.");

            return Command::FAILURE;
        }

        $this->info('Scanning inventory_master for products with missing physical specifications...');

        $reopened = 0;
        foreach (InventoryMasterSpecEnrichment::inventoryMasterIdsWithApprovedPartialEnrichment() as $productId) {
            if (InventoryMasterSpecEnrichment::reopenLatestApprovedSpecForReview($productId)) {
                $reopened++;
            }
        }

        if ($reopened > 0) {
            $this->warn("Re-opened {$reopened} partially approved AI spec(s) for manual review.");
        }

        $incompleteQuery = Product::query()
            ->tap(fn ($builder) => InventoryMasterSpecEnrichment::scopeMissingPhysicalSpecs($builder));

        $totalIncomplete = (clone $incompleteQuery)->count();

        $query = Product::query()
            ->select('inventory_master.id')
            ->tap(fn ($builder) => InventoryMasterSpecEnrichment::scopeEligibleForEnrichment($builder, $retryIncomplete))
            ->orderBy('inventory_master.id');

        $totalEligible = (clone $query)->count();

        $pendingBlocked = (clone $incompleteQuery)
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('inventory_master_ai_specs')
                    ->whereColumn('inventory_master_ai_specs.inventory_master_id', 'inventory_master.id')
                    ->where('inventory_master_ai_specs.status', InventoryMasterAiSpec::STATUS_PENDING);
            })
            ->count();

        $approvedBlocked = $retryIncomplete ? 0 : (clone $incompleteQuery)
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('inventory_master_ai_specs')
                    ->whereColumn('inventory_master_ai_specs.inventory_master_id', 'inventory_master.id')
                    ->where('inventory_master_ai_specs.status', InventoryMasterAiSpec::STATUS_APPROVED);
            })
            ->count();

        $rejectedBlocked = (clone $incompleteQuery)
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('inventory_master_ai_rejections')
                    ->whereColumn('inventory_master_ai_rejections.inventory_master_id', 'inventory_master.id');
            })
            ->count();

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $productIds = $query->pluck('id')->all();
        $toProcess = count($productIds);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Products with any missing spec field', $totalIncomplete],
                ['Queueable for this run', $totalEligible],
                ['Blocked by pending AI review', $pendingBlocked],
                ['Blocked by prior approved AI spec', $approvedBlocked],
                ['Blocked by prior AI rejection', $rejectedBlocked],
                ['Selected for this run', $toProcess],
                ['Batch size', $batchSize],
                ['AI requests per minute (pacing)', config('ai.rate_limit.requests_per_minute')],
                ['Mode', $dryRun ? 'dry-run' : ($sync ? 'sync' : 'queued')],
            ]
        );

        if ($toProcess === 0) {
            $this->info('No products to enrich.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->info('Dry run complete. No jobs were dispatched.');

            return Command::SUCCESS;
        }

        $batchRunId = (string) str()->uuid();

        if ($sync) {
            @set_time_limit(0);

            $secondsPerProduct = (int) ceil(AiRequestPacer::secondsBetweenRequests());
            $estimatedMinutes = (int) ceil(($toProcess * $secondsPerProduct) / 60);
            $chunkCount = (int) ceil($toProcess / $batchSize);

            $this->comment(sprintf(
                'Sync mode: %d product(s) in %d batch(es) of up to %d, paced at %d RPM (~%ds/call, ~%d min estimated).',
                $toProcess,
                $chunkCount,
                $batchSize,
                config('ai.rate_limit.requests_per_minute'),
                $secondsPerProduct,
                max(1, $estimatedMinutes),
            ));

            $service = app(\App\Services\InventoryAi\InventorySpecificationEnrichmentService::class);
            $stats = [
                'approved' => 0,
                'pending' => 0,
                'rejected' => 0,
                'insufficient_information' => 0,
                'skipped' => 0,
                'errors' => 0,
            ];

            $chunks = array_chunk($productIds, $batchSize);
            $processed = 0;

            foreach ($chunks as $chunkIndex => $chunk) {
                $batchNumber = $chunkIndex + 1;
                $this->newLine();
                $this->info("Batch {$batchNumber}/{$chunkCount} — processing " . count($chunk) . ' product(s)...');

                $syncBar = $this->output->createProgressBar(count($chunk));
                $syncBar->start();

                foreach ($chunk as $productId) {
                    try {
                        $result = $service->enrichProduct($productId, $retryIncomplete, batchRunId: $batchRunId);
                        $status = $result['status'];
                        if (isset($stats[$status])) {
                            $stats[$status]++;
                        } else {
                            $stats['skipped']++;
                        }
                    } catch (\Throwable $e) {
                        $stats['errors']++;
                        $service->recordProviderFailure($productId, $e, $batchRunId);
                        $this->newLine();
                        $this->warn("Product {$productId} failed: {$e->getMessage()}");
                    }
                    $syncBar->advance();
                    $processed++;
                }

                $syncBar->finish();
                $this->newLine();
                $this->line("Batch {$batchNumber} complete. Progress: {$processed}/{$toProcess} products.");
            }

            $this->newLine();
            $this->table(['Result', 'Count'], collect($stats)->map(fn ($count, $key) => [$key, $count])->values()->all());

            return Command::SUCCESS;
        }

        $chunks = array_chunk($productIds, $batchSize);
        $bar = $this->output->createProgressBar(count($chunks));
        $bar->start();

        foreach ($chunks as $chunk) {
            EnrichInventorySpecificationsBatchJob::dispatch($chunk, $retryIncomplete, $batchRunId);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Dispatched ' . count($chunks) . ' batch job(s) for ' . $toProcess . ' product(s).');
        $this->comment('Ensure a queue worker is running: php artisan queue:work');

        return Command::SUCCESS;
    }
}
