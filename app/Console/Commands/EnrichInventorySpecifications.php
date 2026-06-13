<?php

namespace App\Console\Commands;

use App\Jobs\EnrichInventorySpecificationsBatchJob;
use App\Models\InventoryMasterAiSpec;
use App\Models\Product;
use App\Support\InventoryMasterSpecEnrichment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EnrichInventorySpecifications extends Command
{
    protected $signature = 'inventory:enrich-specifications
                            {--batch= : Number of products per queued batch job}
                            {--limit= : Maximum number of products to queue}
                            {--sync : Process synchronously in this process instead of queueing}
                            {--dry-run : Show counts without dispatching jobs}';

    protected $description = 'Queue AI enrichment for inventory_master products missing physical specifications';

    public function handle(): int
    {
        $batchSize = (int) ($this->option('batch') ?: config('inventory_ai.batch_size', 100));
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $dryRun = (bool) $this->option('dry-run');
        $sync = (bool) $this->option('sync');

        if ($batchSize < 1) {
            $this->error('Batch size must be at least 1.');

            return Command::FAILURE;
        }

        if (empty(config('inventory_ai.api_key')) && !$dryRun) {
            $this->error('OPENAI_API_KEY is not configured. Set it in .env before running enrichment.');

            return Command::FAILURE;
        }

        $this->info('Scanning inventory_master for products with missing physical specifications...');

        $query = Product::query()
            ->select('inventory_master.id')
            ->tap(fn ($builder) => InventoryMasterSpecEnrichment::scopeMissingPhysicalSpecs($builder))
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('inventory_master_ai_specs')
                    ->whereColumn('inventory_master_ai_specs.inventory_master_id', 'inventory_master.id')
                    ->where('inventory_master_ai_specs.status', InventoryMasterAiSpec::STATUS_PENDING);
            })
            ->orderBy('inventory_master.id');

        $totalEligible = (clone $query)->count();

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $productIds = $query->pluck('id')->all();
        $toProcess = count($productIds);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Eligible (missing specs, no pending request)', $totalEligible],
                ['Selected for this run', $toProcess],
                ['Batch size', $batchSize],
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

        if ($sync) {
            $service = app(\App\Services\InventoryAi\InventorySpecificationEnrichmentService::class);
            $stats = [
                'approved' => 0,
                'pending' => 0,
                'rejected' => 0,
                'insufficient_information' => 0,
                'skipped' => 0,
                'errors' => 0,
            ];

            $syncBar = $this->output->createProgressBar($toProcess);
            $syncBar->start();

            foreach ($productIds as $productId) {
                try {
                    $result = $service->enrichProduct($productId);
                    $status = $result['status'];
                    if (isset($stats[$status])) {
                        $stats[$status]++;
                    } else {
                        $stats['skipped']++;
                    }
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    $this->newLine();
                    $this->warn("Product {$productId} failed: {$e->getMessage()}");
                }
                $syncBar->advance();
            }

            $syncBar->finish();
            $this->newLine(2);
            $this->table(['Result', 'Count'], collect($stats)->map(fn ($count, $key) => [$key, $count])->values()->all());

            return Command::SUCCESS;
        }

        $chunks = array_chunk($productIds, $batchSize);
        $bar = $this->output->createProgressBar(count($chunks));
        $bar->start();

        foreach ($chunks as $chunk) {
            EnrichInventorySpecificationsBatchJob::dispatch($chunk);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Dispatched ' . count($chunks) . ' batch job(s) for ' . $toProcess . ' product(s).');
        $this->comment('Ensure a queue worker is running: php artisan queue:work');

        return Command::SUCCESS;
    }
}
