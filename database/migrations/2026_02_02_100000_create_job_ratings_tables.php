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
        Schema::create('job_ratings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rental_job_id')->unique();
            $table->tinyInteger('rating')->nullable()->comment('1-5 star rating');
            $table->text('comment')->nullable();
            $table->timestamp('rated_at')->nullable();
            $table->timestamp('skipped_at')->nullable()->comment('When renter skipped rating');
            $table->timestamps();

            $table->foreign('rental_job_id')->references('id')->on('rental_jobs')->onDelete('cascade');
        });

        Schema::create('job_rating_replies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supply_job_id')->unique();
            $table->unsignedBigInteger('job_rating_id');
            $table->text('reply');
            $table->timestamp('replied_at');
            $table->timestamps();

            $table->foreign('supply_job_id')->references('id')->on('supply_jobs')->onDelete('cascade');
            $table->foreign('job_rating_id')->references('id')->on('job_ratings')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_rating_replies');
        Schema::dropIfExists('job_ratings');
    }
};
