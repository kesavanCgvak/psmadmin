<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_master_ai_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inventory_master_id');
            $table->string('field_name', 50);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->unsignedTinyInteger('confidence_score')->nullable();
            $table->string('source_url', 1024)->nullable();
            $table->string('updated_by', 20)->default('AI');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('inventory_master_id', 'fk_inv_master_ai_logs_master')
                ->references('id')
                ->on('inventory_master')
                ->onDelete('cascade');

            $table->index('inventory_master_id', 'idx_inv_master_ai_logs_master_id');
            $table->index('field_name', 'idx_inv_master_ai_logs_field_name');
            $table->index('created_at', 'idx_inv_master_ai_logs_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_master_ai_logs');
    }
};
