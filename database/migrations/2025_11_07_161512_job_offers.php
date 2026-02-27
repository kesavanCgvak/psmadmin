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
        Schema::create('job_offers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rental_job_id');
            $table->unsignedBigInteger('supply_job_id')->nullable();
            $table->unsignedBigInteger('sender_company_id');    // who made this offer
            $table->unsignedBigInteger('receiver_company_id');  // who receives
            $table->unsignedInteger('version')->default(1);
            $table->decimal('total_price', 15, 2)->default(0);
            $table->enum('status', ['pending', 'accepted', 'rejected', 'cancelled'])->default('pending');
            $table->enum('last_offer_by', ['user', 'provider'])->nullable(); // who made the latest offer
            $table->timestamps();

            $table->foreign('rental_job_id')->references('id')->on('rental_jobs')->onDelete('cascade');
            $table->foreign('supply_job_id')->references('id')->on('supply_jobs')->onDelete('cascade');
            $table->foreign('sender_company_id')->references('id')->on('companies');
            $table->foreign('receiver_company_id')->references('id')->on('companies');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
