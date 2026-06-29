<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('logo_available_for_promotion')->default(false)->after('logo');
            $table->timestamp('logo_promotion_consent_at')->nullable()->after('logo_available_for_promotion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['logo_available_for_promotion', 'logo_promotion_consent_at']);
        });
    }
};
