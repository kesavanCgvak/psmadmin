<?php

/**
 * Rename company_inventory.price → company_inventory.rental_price
 *
 * The price column represents the rental price of equipment.
 * This migration renames it for clarity.
 *
 * Requirements:
 * - Uses renameColumn() (no drop/recreate)
 * - Preserves all existing data
 * - Rollback supported in down()
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
        Schema::table('company_inventory', function (Blueprint $table) {
            $table->renameColumn('price', 'rental_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_inventory', function (Blueprint $table) {
            $table->renameColumn('rental_price', 'price');
        });
    }
};
