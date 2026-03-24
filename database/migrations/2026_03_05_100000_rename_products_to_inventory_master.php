<?php

/**
 * ⚠️ PRODUCTION MIGRATION — RENAME products → inventory_master
 *
 * WARNINGS:
 * - Run on staging first
 * - Take full database backup before deploy
 * - Ensure no concurrent writes during migration
 * - Recommended: enable maintenance mode during execution
 *
 * This migration:
 * 1. Drops all foreign keys referencing products.id
 * 2. Renames products → inventory_master
 * 3. Recreates foreign keys pointing to inventory_master
 */

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
        // Step 1: Drop foreign keys referencing products
        Schema::table('equipments', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });

        Schema::table('import_session_items', function (Blueprint $table) {
            $table->dropForeign(['selected_product_id']);
        });

        // Step 2: Rename table using Schema::rename()
        Schema::rename('products', 'inventory_master');

        // Step 3: Recreate foreign keys pointing to inventory_master
        Schema::table('equipments', function (Blueprint $table) {
            $table->foreign('product_id')
                ->references('id')
                ->on('inventory_master')
                ->onDelete('cascade');
        });

        Schema::table('import_session_items', function (Blueprint $table) {
            $table->foreign('selected_product_id')
                ->references('id')
                ->on('inventory_master')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations — full rollback to original state.
     */
    public function down(): void
    {
        // Step 1: Drop foreign keys referencing inventory_master
        Schema::table('equipments', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });

        Schema::table('import_session_items', function (Blueprint $table) {
            $table->dropForeign(['selected_product_id']);
        });

        // Step 2: Rename back to products
        Schema::rename('inventory_master', 'products');

        // Step 3: Recreate original foreign keys pointing to products
        Schema::table('equipments', function (Blueprint $table) {
            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->onDelete('cascade');
        });

        Schema::table('import_session_items', function (Blueprint $table) {
            $table->foreign('selected_product_id')
                ->references('id')
                ->on('products')
                ->onDelete('set null');
        });
    }
};
