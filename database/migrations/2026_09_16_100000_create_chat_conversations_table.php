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
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_a_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('company_b_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('rental_job_id')->nullable();
            $table->string('pair_key', 80)->unique();
            $table->timestamps();

            $table->index(['company_a_id', 'company_b_id']);
            $table->index('rental_job_id');
            $table->index('created_by_user_id');
        });

        if (Schema::hasTable('rental_jobs')) {
            Schema::table('chat_conversations', function (Blueprint $table) {
                $table->foreign('rental_job_id')->references('id')->on('rental_jobs')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
    }
};
