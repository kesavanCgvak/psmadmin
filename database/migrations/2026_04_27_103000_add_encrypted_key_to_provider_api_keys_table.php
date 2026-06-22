<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_api_keys', function (Blueprint $table) {
            $table->text('encrypted_key')->nullable()->after('key_hash');
        });
    }

    public function down(): void
    {
        Schema::table('provider_api_keys', function (Blueprint $table) {
            $table->dropColumn('encrypted_key');
        });
    }
};

