<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Country;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->string('normalized_name')->nullable()->after('name');
            $table->index(['region_id', 'normalized_name']);
        });

        // Backfill existing records
        Country::chunk(100, function ($countries) {
            foreach ($countries as $country) {
                $country->normalized_name = Country::normalizeName($country->name);
                $country->saveQuietly(); // Use saveQuietly to avoid triggering events
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->dropIndex(['region_id', 'normalized_name']);
            $table->dropColumn('normalized_name');
        });
    }
};
