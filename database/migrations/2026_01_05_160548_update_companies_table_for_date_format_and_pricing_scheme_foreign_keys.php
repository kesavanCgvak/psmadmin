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
            // Add foreign key columns (nullable for backward compatibility)
            $table->foreignId('date_format_id')->nullable()->after('date_format')->constrained('date_formats')->nullOnDelete();
            $table->foreignId('pricing_scheme_id')->nullable()->after('pricing_scheme')->constrained('pricing_schemes')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['date_format_id']);
            $table->dropForeign(['pricing_scheme_id']);
            $table->dropColumn(['date_format_id', 'pricing_scheme_id']);
        });
    }
};
