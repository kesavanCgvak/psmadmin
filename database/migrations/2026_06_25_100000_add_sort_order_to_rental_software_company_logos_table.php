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
        Schema::table('rental_software_company_logos', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('is_active');
            $table->index(['sort_order', 'id']);
        });

        $logos = DB::table('rental_software_company_logos')
            ->orderBy('company_name')
            ->orderBy('id')
            ->pluck('id');

        foreach ($logos as $index => $id) {
            DB::table('rental_software_company_logos')
                ->where('id', $id)
                ->update(['sort_order' => $index]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rental_software_company_logos', function (Blueprint $table) {
            $table->dropIndex(['sort_order', 'id']);
            $table->dropColumn('sort_order');
        });
    }
};
