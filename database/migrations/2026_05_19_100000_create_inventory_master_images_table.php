<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_master_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inventory_master_id');
            $table->string('image_path', 512);
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('sort_order')->nullable();
            $table->string('source', 50)->nullable()->comment('flex, rentman, admin, import');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('inventory_master_id', 'fk_inventory_master_images_master')
                ->references('id')
                ->on('inventory_master')
                ->onDelete('cascade');

            $table->index('inventory_master_id', 'idx_inventory_master_images_master_id');
            $table->index('is_primary', 'idx_inventory_master_images_is_primary');
            $table->unique(['inventory_master_id', 'image_path'], 'inventory_master_images_master_path_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_master_images');
    }
};
