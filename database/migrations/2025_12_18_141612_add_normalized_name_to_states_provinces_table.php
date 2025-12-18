<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\StateProvince;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('states_provinces', function (Blueprint $table) {
            $table->string('normalized_name')->nullable()->after('name');
            $table->index(['country_id', 'normalized_name']);
        });

        // Backfill existing records
        StateProvince::chunk(100, function ($states) {
            foreach ($states as $state) {
                $state->normalized_name = StateProvince::normalizeName($state->name);
                $state->saveQuietly(); // Use saveQuietly to avoid triggering events
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('states_provinces', function (Blueprint $table) {
            $table->dropIndex(['country_id', 'normalized_name']);
            $table->dropColumn('normalized_name');
        });
    }
};
