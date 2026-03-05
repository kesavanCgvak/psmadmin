<?php

/**
 * ⚠️ PRODUCTION MIGRATION — RENAME equipments → company_inventory
 *
 * WARNINGS:
 * - Run on staging first
 * - Take full database backup before deploy
 * - Ensure no concurrent writes during migration
 * - Recommended: enable maintenance mode during execution
 *
 * This migration:
 * 1. Drops all foreign keys referencing equipments.id
 * 2. Renames equipments → company_inventory
 * 3. Recreates foreign keys pointing to company_inventory
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
        // Step 1: Drop foreign key referencing equipments
        Schema::table('equipment_images', function (Blueprint $table) {
            $table->dropForeign(['equipment_id']);
        });

        // Step 2: Rename table using Schema::rename()
        Schema::rename('equipments', 'company_inventory');

        // Step 3: Recreate foreign key pointing to company_inventory
        Schema::table('equipment_images', function (Blueprint $table) {
            $table->foreign('equipment_id')
                ->references('id')
                ->on('company_inventory')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations — full rollback to original state.
     */
    public function down(): void
    {
        // Step 1: Drop foreign key referencing company_inventory
        Schema::table('equipment_images', function (Blueprint $table) {
            $table->dropForeign(['equipment_id']);
        });

        // Step 2: Rename back to equipments
        Schema::rename('company_inventory', 'equipments');

        // Step 3: Recreate original foreign key pointing to equipments
        Schema::table('equipment_images', function (Blueprint $table) {
            $table->foreign('equipment_id')
                ->references('id')
                ->on('equipments')
                ->onDelete('cascade');
        });
    }
};
