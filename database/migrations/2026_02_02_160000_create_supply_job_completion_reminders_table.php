<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks which completion-reminder emails were sent for accepted supply jobs
     * (e.g. 2, 7, 14, 21, 30 days after unpack date).
     */
    public function up(): void
    {
        Schema::create('supply_job_completion_reminders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supply_job_id');
            $table->unsignedTinyInteger('days_after_unpack'); // 2, 7, 14, 21, 30
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->foreign('supply_job_id')->references('id')->on('supply_jobs')->onDelete('cascade');
            $table->unique(['supply_job_id', 'days_after_unpack'], 'sj_completion_reminders_job_days_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_job_completion_reminders');
    }
};
