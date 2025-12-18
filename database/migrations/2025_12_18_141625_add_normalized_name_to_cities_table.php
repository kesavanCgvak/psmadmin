<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\City;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->string('normalized_name')->nullable()->after('name');
            $table->index(['country_id', 'state_id', 'normalized_name']);
        });

        // Backfill existing records
        City::chunk(100, function ($cities) {
            foreach ($cities as $city) {
                $city->normalized_name = City::normalizeName($city->name);
                $city->saveQuietly(); // Use saveQuietly to avoid triggering events
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropIndex(['country_id', 'state_id', 'normalized_name']);
            $table->dropColumn('normalized_name');
        });
    }
};
