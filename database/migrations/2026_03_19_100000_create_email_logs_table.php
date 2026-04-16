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
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('from_email')->nullable();
            $table->string('to_email');
            $table->string('subject')->nullable();
            $table->string('email_type')->nullable();
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->text('failure_reason')->nullable();
            $table->unsignedBigInteger('related_user_id')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('mail_class')->nullable();
            $table->json('payload_snapshot')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('email_type');
            $table->index('created_at');
            $table->index('to_email');
            $table->foreign('related_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
