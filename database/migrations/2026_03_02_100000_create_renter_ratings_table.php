<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Provider rates renter (one per supply job).
     * Used for "reverse rating": provider → renter, symmetric to job_ratings (renter → provider).
     */
    public function up(): void
    {
        Schema::create('renter_ratings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supply_job_id')->unique();
            $table->tinyInteger('rating')->nullable()->comment('1-5 star rating; null when skipped');
            $table->text('comment')->nullable();
            $table->timestamp('rated_at')->nullable();
            $table->timestamp('skipped_at')->nullable()->comment('When provider skipped rating renter');
            $table->timestamps();

            $table->foreign('supply_job_id')->references('id')->on('supply_jobs')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('renter_ratings');
    }
};
