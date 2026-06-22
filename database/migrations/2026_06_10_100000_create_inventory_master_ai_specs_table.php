<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_master_ai_specs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inventory_master_id');
            $table->decimal('height', 10, 2)->nullable();
            $table->decimal('width', 10, 2)->nullable();
            $table->decimal('length', 10, 2)->nullable();
            $table->decimal('weight', 10, 2)->nullable();
            $table->unsignedBigInteger('linear_unit_id')->nullable();
            $table->unsignedBigInteger('weight_unit_id')->nullable();
            $table->unsignedTinyInteger('confidence_score')->nullable();
            $table->string('source_url', 1024)->nullable();
            $table->json('ai_response')->nullable();
            $table->enum('status', [
                'pending',
                'approved',
                'rejected',
                'insufficient_information',
            ])->default('pending');
            $table->timestamps();

            $table->foreign('inventory_master_id', 'fk_inv_master_ai_specs_master')
                ->references('id')
                ->on('inventory_master')
                ->onDelete('cascade');

            $table->foreign('linear_unit_id', 'fk_inv_master_ai_specs_linear_unit')
                ->references('id')
                ->on('linear_units')
                ->nullOnDelete();

            $table->foreign('weight_unit_id', 'fk_inv_master_ai_specs_weight_unit')
                ->references('id')
                ->on('weight_units')
                ->nullOnDelete();

            $table->index('inventory_master_id', 'idx_inv_master_ai_specs_master_id');
            $table->index('status', 'idx_inv_master_ai_specs_status');
            $table->index(['inventory_master_id', 'status'], 'idx_inv_master_ai_specs_master_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_master_ai_specs');
    }
};
