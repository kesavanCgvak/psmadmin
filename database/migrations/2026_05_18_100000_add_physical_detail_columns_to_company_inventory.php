<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds physical/detail columns to company_inventory (aligned with inventory_master).
     */
    public function up(): void
    {
        Schema::table('company_inventory', function (Blueprint $table) {
            if (!Schema::hasColumn('company_inventory', 'height')) {
                $table->decimal('height', 10, 2)->nullable()->after('replacement_price');
            }
            if (!Schema::hasColumn('company_inventory', 'width')) {
                $table->decimal('width', 10, 2)->nullable()->after('height');
            }
            if (!Schema::hasColumn('company_inventory', 'length')) {
                $table->decimal('length', 10, 2)->nullable()->after('width');
            }
            if (!Schema::hasColumn('company_inventory', 'weight')) {
                $table->decimal('weight', 10, 2)->nullable()->after('length');
            }
            if (!Schema::hasColumn('company_inventory', 'linear_unit_id')) {
                $table->unsignedBigInteger('linear_unit_id')->nullable()->after('weight');
                $table->foreign('linear_unit_id', 'fk_company_inventory_linear_unit')
                    ->references('id')->on('linear_units')
                    ->nullOnDelete();
            }
            if (!Schema::hasColumn('company_inventory', 'weight_unit_id')) {
                $table->unsignedBigInteger('weight_unit_id')->nullable()->after('linear_unit_id');
                $table->foreign('weight_unit_id', 'fk_company_inventory_weight_unit')
                    ->references('id')->on('weight_units')
                    ->nullOnDelete();
            }
            if (!Schema::hasColumn('company_inventory', 'country_of_origin')) {
                $table->string('country_of_origin', 100)->nullable()->after('weight_unit_id');
            }
            if (!Schema::hasColumn('company_inventory', 'hsn_code')) {
                $table->string('hsn_code', 20)->nullable()->after('country_of_origin');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_inventory', function (Blueprint $table) {
            if (Schema::hasColumn('company_inventory', 'linear_unit_id')) {
                $table->dropForeign('fk_company_inventory_linear_unit');
            }
            if (Schema::hasColumn('company_inventory', 'weight_unit_id')) {
                $table->dropForeign('fk_company_inventory_weight_unit');
            }
        });

        Schema::table('company_inventory', function (Blueprint $table) {
            $columns = [
                'hsn_code',
                'country_of_origin',
                'weight_unit_id',
                'linear_unit_id',
                'weight',
                'length',
                'width',
                'height',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('company_inventory', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
