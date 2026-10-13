<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_api_keys', function (Blueprint $table) {
            if (! Schema::hasColumn('provider_api_keys', 'scopes')) {
                $table->json('scopes')->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('provider_api_keys', function (Blueprint $table) {
            if (Schema::hasColumn('provider_api_keys', 'scopes')) {
                $table->dropColumn('scopes');
            }
        });
    }
};
