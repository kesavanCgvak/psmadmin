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
            $table->unsignedInteger('logo_promotion_sort_order')->default(0)->after('logo_promotion_admin_enabled');
        });

        $companies = DB::table('companies')
            ->whereNotNull('logo')
            ->where('logo', '!=', '')
            ->where('logo_available_for_promotion', true)
            ->orderBy('logo_promotion_consent_at')
            ->orderBy('id')
            ->get(['id']);

        $sortOrder = 1;
        foreach ($companies as $company) {
            DB::table('companies')
                ->where('id', $company->id)
                ->update(['logo_promotion_sort_order' => $sortOrder++]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('logo_promotion_sort_order');
        });
    }
};
