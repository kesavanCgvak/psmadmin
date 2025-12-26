<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Region;
use App\Models\Country;
use App\Models\StateProvince;
use App\Models\City;

class BackfillNormalizedNames extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'geography:backfill-normalized-names 
                            {--table= : Specific table to backfill (regions, countries, states, cities)}
                            {--chunk=100 : Number of records to process at a time}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill normalized_name column for geography tables using exact PHP normalization';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $table = $this->option('table');
        $chunkSize = (int) $this->option('chunk');

        if ($table) {
            $this->backfillTable($table, $chunkSize);
        } else {
            $this->info('Backfilling normalized_name for all geography tables...');
            $this->backfillTable('regions', $chunkSize);
            $this->backfillTable('countries', $chunkSize);
            $this->backfillTable('states', $chunkSize);
            $this->backfillTable('cities', $chunkSize);
            $this->info('✓ All tables backfilled successfully!');
        }

        return Command::SUCCESS;
    }

    /**
     * Backfill a specific table
     */
    private function backfillTable(string $table, int $chunkSize)
    {
        $this->info("Backfilling {$table}...");

        switch ($table) {
            case 'regions':
                $this->backfillRegions($chunkSize);
                break;
            case 'countries':
                $this->backfillCountries($chunkSize);
                break;
            case 'states':
                $this->backfillStates($chunkSize);
                break;
            case 'cities':
                $this->backfillCities($chunkSize);
                break;
            default:
                $this->error("Unknown table: {$table}");
                return;
        }
    }

    /**
     * Backfill regions
     */
    private function backfillRegions(int $chunkSize)
    {
        $total = Region::count();
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        Region::chunk($chunkSize, function ($regions) use ($bar) {
            foreach ($regions as $region) {
                $region->normalized_name = Region::normalizeName($region->name);
                $region->saveQuietly();
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("✓ Regions backfilled: {$total} records");
    }

    /**
     * Backfill countries
     */
    private function backfillCountries(int $chunkSize)
    {
        $total = Country::count();
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        Country::chunk($chunkSize, function ($countries) use ($bar) {
            foreach ($countries as $country) {
                $country->normalized_name = Country::normalizeName($country->name);
                $country->saveQuietly();
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("✓ Countries backfilled: {$total} records");
    }

    /**
     * Backfill states/provinces
     */
    private function backfillStates(int $chunkSize)
    {
        $total = StateProvince::count();
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        StateProvince::chunk($chunkSize, function ($states) use ($bar) {
            foreach ($states as $state) {
                $state->normalized_name = StateProvince::normalizeName($state->name);
                $state->saveQuietly();
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("✓ States/Provinces backfilled: {$total} records");
    }

    /**
     * Backfill cities
     */
    private function backfillCities(int $chunkSize)
    {
        $total = City::count();
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        City::chunk($chunkSize, function ($cities) use ($bar) {
            foreach ($cities as $city) {
                $city->normalized_name = City::normalizeName($city->name);
                $city->saveQuietly();
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("✓ Cities backfilled: {$total} records");
    }
}

