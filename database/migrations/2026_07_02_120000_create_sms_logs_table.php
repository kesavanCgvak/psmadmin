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
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();

            // Provider details
            $table->string('provider')->nullable();
            $table->string('provider_message_id')->nullable();

            // Delivery lifecycle
            $table->enum('status', ['pending', 'sent', 'delivered', 'failed'])->default('pending');

            // Message + recipient
            $table->text('message');
            $table->string('recipient_name')->nullable();
            $table->string('phone_number')->nullable();

            // Company / contact context (denormalized for fast audit + resilience if source is deleted)
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('company_name')->nullable();
            $table->string('contact_person_name')->nullable();
            $table->string('contact_person_mobile')->nullable();

            // Polymorphic-style linkage to the originating module (kept as plain columns for portability)
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();

            // Origin + diagnostics
            $table->string('sent_by')->nullable()->default('System');
            $table->text('error_message')->nullable();
            $table->json('provider_response')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            // Indexes on frequently searched / filtered fields
            $table->index('provider');
            $table->index('provider_message_id');
            $table->index('status');
            $table->index('phone_number');
            $table->index('company_id');
            $table->index('company_name');
            $table->index('contact_person_name');
            $table->index('created_at');
            $table->index(['related_type', 'related_id']);

            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};
