<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $ids = DB::table('rental_software_company_logos')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $index => $id) {
            DB::table('rental_software_company_logos')
                ->where('id', $id)
                ->update(['sort_order' => $index + 1]);
        }

        Schema::table('rental_software_company_logos', function (Blueprint $table) {
            $table->dropIndex(['sort_order', 'id']);
            $table->unique('sort_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rental_software_company_logos', function (Blueprint $table) {
            $table->dropUnique(['sort_order']);
            $table->index(['sort_order', 'id']);
        });
    }
};
