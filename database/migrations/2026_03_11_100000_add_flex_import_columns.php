<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds source, replacement_price to inventory_master and flex_resource_id,
     * unique index to company_inventory for Flex import support.
     */
    public function up(): void
    {
        Schema::table('inventory_master', function (Blueprint $table) {
            if (!Schema::hasColumn('inventory_master', 'source')) {
                $table->string('source', 50)->nullable()->after('is_verified')
                    ->comment('flex, rentman, manual');
            }
            if (!Schema::hasColumn('inventory_master', 'replacement_price')) {
                $table->decimal('replacement_price', 12, 2)->nullable()->after('weight_unit_id');
            }
        });

        Schema::table('company_inventory', function (Blueprint $table) {
            if (!Schema::hasColumn('company_inventory', 'flex_resource_id')) {
                $table->string('flex_resource_id', 100)->nullable()->after('company_id')
                    ->comment('Flex SKU or Part Number for duplicate prevention');
            }
        });

        // Add unique index to prevent duplicate Flex imports per company
        // Only add if column exists (migration may run in different order)
        if (Schema::hasColumn('company_inventory', 'flex_resource_id')) {
            Schema::table('company_inventory', function (Blueprint $table) {
                $table->unique(['company_id', 'flex_resource_id'], 'company_inventory_company_flex_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('company_inventory', 'flex_resource_id')) {
            Schema::table('company_inventory', function (Blueprint $table) {
                $table->dropUnique('company_inventory_company_flex_unique');
            });
        }
        Schema::table('company_inventory', function (Blueprint $table) {
            if (Schema::hasColumn('company_inventory', 'flex_resource_id')) {
                $table->dropColumn('flex_resource_id');
            }
        });

        Schema::table('inventory_master', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_master', 'replacement_price')) {
                $table->dropColumn('replacement_price');
            }
            if (Schema::hasColumn('inventory_master', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};
