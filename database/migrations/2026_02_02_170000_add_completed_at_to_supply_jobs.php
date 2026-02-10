<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the provider marks the job as completed (completed_pending_rating).
     * Used for renter rating reminder schedule (2, 7, 14, 21, 30 days after).
     */
    public function up(): void
    {
        Schema::table('supply_jobs', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('unpacking_date');
        });
    }

    public function down(): void
    {
        Schema::table('supply_jobs', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });
    }
};
