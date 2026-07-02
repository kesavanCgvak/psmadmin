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
            $table->boolean('logo_promotion_admin_enabled')->default(true)->after('logo_promotion_consent_at');
        });

        DB::table('companies')
            ->whereNotNull('logo')
            ->where('logo', '!=', '')
            ->where('logo_available_for_promotion', true)
            ->update(['logo_promotion_admin_enabled' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('logo_promotion_admin_enabled');
        });
    }
};
