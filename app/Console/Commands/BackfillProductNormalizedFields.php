<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\ProductNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillProductNormalizedFields extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'products:backfill-normalized 
                            {--chunk=100 : Number of products to process at a time}
                            {--dry-run : Show what would be updated without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill normalized_model and normalized_full_name columns for existing products';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $chunkSize = (int) $this->option('chunk');

        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
        }

        $totalProducts = Product::count();
        $this->info("Found {$totalProducts} products to process");

        $bar = $this->output->createProgressBar($totalProducts);
        $bar->start();

        $updated = 0;
        $skipped = 0;

        Product::with('brand')->chunk($chunkSize, function ($products) use (&$updated, &$skipped, $dryRun, $bar) {
            foreach ($products as $product) {
                $brandName = $product->brand->name ?? null;
                
                $normalizedModel = ProductNormalizer::normalizeCode($product->model);
                $normalizedFullName = ProductNormalizer::normalizeFullName($brandName, $product->model);

                // Check if update is needed
                $needsUpdate = ($product->normalized_model !== $normalizedModel) || 
                               ($product->normalized_full_name !== $normalizedFullName);

                if ($needsUpdate) {
                    if (!$dryRun) {
                        $product->update([
                            'normalized_model' => $normalizedModel,
                            'normalized_full_name' => $normalizedFullName,
                        ]);
                    }
                    $updated++;
                } else {
                    $skipped++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        if ($dryRun) {
            $this->info("✅ Would update: {$updated} products");
            $this->info("⏭️  Would skip: {$skipped} products (already normalized)");
        } else {
            $this->info("✅ Updated: {$updated} products");
            $this->info("⏭️  Skipped: {$skipped} products (already normalized)");
        }

        return Command::SUCCESS;
    }
}
