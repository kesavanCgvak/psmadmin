<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('flex_integration_logs')) {
            Schema::create('flex_integration_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('rental_request_id');
                $table->unsignedBigInteger('provider_id');
                $table->string('action', 64);
                $table->string('status', 32);
                $table->text('request_url')->nullable();
                $table->json('request_payload')->nullable();
                $table->json('response_payload')->nullable();
                $table->text('error_message')->nullable();
                $table->string('flex_quote_id', 100)->nullable();
                $table->string('flex_product_id', 100)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('rental_request_id')->references('id')->on('rental_jobs')->onDelete('cascade');
                $table->foreign('provider_id')->references('id')->on('companies')->onDelete('cascade');
                $table->index(['rental_request_id', 'provider_id']);
                $table->index(['provider_id', 'action']);
            });
        }

        if (!Schema::hasTable('rental_request_provider_quotes')) {
            Schema::create('rental_request_provider_quotes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('rental_request_id');
                $table->unsignedBigInteger('provider_id');
                $table->unsignedBigInteger('supply_job_id')->nullable();
                $table->string('flex_quote_id', 100)->nullable();
                $table->string('flex_quote_number', 100)->nullable();
                $table->string('status', 32)->nullable();
                $table->timestamps();

                $table->foreign('rental_request_id')->references('id')->on('rental_jobs')->onDelete('cascade');
                $table->foreign('provider_id')->references('id')->on('companies')->onDelete('cascade');
                $table->foreign('supply_job_id')->references('id')->on('supply_jobs')->onDelete('set null');
                $table->unique(['rental_request_id', 'provider_id'], 'rr_provider_quotes_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_request_provider_quotes');
        Schema::dropIfExists('flex_integration_logs');
    }
};
