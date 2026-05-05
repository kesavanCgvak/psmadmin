<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_inventory', function (Blueprint $table) {
            if (!Schema::hasColumn('company_inventory', 'rentman_equipment_id')) {
                $table->string('rentman_equipment_id', 100)->nullable()->after('flex_resource_id')
                    ->comment('Rentman equipment id for duplicate prevention');
            }
        });

        if (Schema::hasColumn('company_inventory', 'rentman_equipment_id')) {
            Schema::table('company_inventory', function (Blueprint $table) {
                $table->unique(['company_id', 'rentman_equipment_id'], 'company_inventory_company_rentman_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('company_inventory', 'rentman_equipment_id')) {
            Schema::table('company_inventory', function (Blueprint $table) {
                $table->dropUnique('company_inventory_company_rentman_unique');
            });
            Schema::table('company_inventory', function (Blueprint $table) {
                $table->dropColumn('rentman_equipment_id');
            });
        }
    }
};
