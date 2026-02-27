<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Region;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('regions', function (Blueprint $table) {
            $table->string('normalized_name')->nullable()->after('name');
            $table->index('normalized_name');
        });

        // Backfill existing records
        Region::chunk(100, function ($regions) {
            foreach ($regions as $region) {
                $region->normalized_name = Region::normalizeName($region->name);
                $region->saveQuietly(); // Use saveQuietly to avoid triggering events
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('regions', function (Blueprint $table) {
            $table->dropIndex(['normalized_name']);
            $table->dropColumn('normalized_name');
        });
    }
};
