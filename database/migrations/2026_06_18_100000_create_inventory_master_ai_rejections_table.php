<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_master_ai_rejections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inventory_master_id');
            $table->string('product_name', 512);
            $table->string('rejection_reason', 2000);
            $table->string('rejection_category', 64);
            $table->timestamp('rejected_at');
            $table->string('batch_run_id', 36)->nullable();
            $table->unsignedBigInteger('spec_id')->nullable();
            $table->timestamps();

            $table->foreign('inventory_master_id', 'fk_inv_master_ai_rejections_master')
                ->references('id')
                ->on('inventory_master')
                ->onDelete('cascade');

            $table->foreign('spec_id', 'fk_inv_master_ai_rejections_spec')
                ->references('id')
                ->on('inventory_master_ai_specs')
                ->nullOnDelete();

            $table->unique('inventory_master_id', 'uq_inv_master_ai_rejections_master');
            $table->index('rejected_at', 'idx_inv_master_ai_rejections_rejected_at');
            $table->index('rejection_category', 'idx_inv_master_ai_rejections_category');
            $table->index('batch_run_id', 'idx_inv_master_ai_rejections_batch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_master_ai_rejections');
    }
};
