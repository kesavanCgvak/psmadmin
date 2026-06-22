<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'is_open_api_enabled')) {
                $table->boolean('is_open_api_enabled')
                    ->default(false)
                    ->after('subscription_mode')
                    ->comment('Controls company-wise access to partner open APIs');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'is_open_api_enabled')) {
                $table->dropColumn('is_open_api_enabled');
            }
        });
    }
};
