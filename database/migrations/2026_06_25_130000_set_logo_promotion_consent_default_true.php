<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('logo_available_for_promotion')->default(true)->change();
        });

        DB::table('companies')
            ->whereNotNull('logo')
            ->where('logo', '!=', '')
            ->update([
                'logo_available_for_promotion' => true,
                'logo_promotion_consent_at' => DB::raw('COALESCE(logo_promotion_consent_at, NOW())'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('logo_available_for_promotion')->default(false)->change();
        });
    }
};
