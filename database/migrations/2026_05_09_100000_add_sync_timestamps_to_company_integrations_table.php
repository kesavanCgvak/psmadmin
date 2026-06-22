<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_integrations', function (Blueprint $table) {
            if (!Schema::hasColumn('company_integrations', 'last_fetched_at')) {
                $table->timestamp('last_fetched_at')->nullable()->after('token_expires_at');
            }
            if (!Schema::hasColumn('company_integrations', 'last_synced_at')) {
                $table->timestamp('last_synced_at')->nullable()->after('last_fetched_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_integrations', function (Blueprint $table) {
            if (Schema::hasColumn('company_integrations', 'last_synced_at')) {
                $table->dropColumn('last_synced_at');
            }
            if (Schema::hasColumn('company_integrations', 'last_fetched_at')) {
                $table->dropColumn('last_fetched_at');
            }
        });
    }
};
