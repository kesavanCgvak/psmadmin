<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add unique index (short name for MySQL identifier limit).
     */
    public function up(): void
    {
        Schema::table('supply_job_completion_reminders', function (Blueprint $table) {
            $table->unique(['supply_job_id', 'days_after_unpack'], 'sj_completion_reminders_job_days_unique');
        });
    }

    public function down(): void
    {
        Schema::table('supply_job_completion_reminders', function (Blueprint $table) {
            $table->dropUnique('sj_completion_reminders_job_days_unique');
        });
    }
};
