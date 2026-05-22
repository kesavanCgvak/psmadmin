<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_images', function (Blueprint $table) {
            if (!Schema::hasColumn('equipment_images', 'is_primary')) {
                $table->boolean('is_primary')->default(false)->after('image_path');
            }
            if (!Schema::hasColumn('equipment_images', 'sort_order')) {
                $table->unsignedInteger('sort_order')->nullable()->after('is_primary');
            }
        });

        if (Schema::hasColumn('equipment_images', 'is_primary')) {
            $equipmentIds = DB::table('equipment_images')
                ->select('equipment_id')
                ->distinct()
                ->pluck('equipment_id');

            foreach ($equipmentIds as $equipmentId) {
                $hasPrimary = DB::table('equipment_images')
                    ->where('equipment_id', $equipmentId)
                    ->where('is_primary', true)
                    ->exists();

                if ($hasPrimary) {
                    continue;
                }

                $firstId = DB::table('equipment_images')
                    ->where('equipment_id', $equipmentId)
                    ->orderBy('id')
                    ->value('id');

                if ($firstId) {
                    DB::table('equipment_images')
                        ->where('id', $firstId)
                        ->update(['is_primary' => true]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('equipment_images', function (Blueprint $table) {
            if (Schema::hasColumn('equipment_images', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
            if (Schema::hasColumn('equipment_images', 'is_primary')) {
                $table->dropColumn('is_primary');
            }
        });
    }
};
