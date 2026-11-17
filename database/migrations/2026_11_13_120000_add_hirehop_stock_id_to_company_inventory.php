<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store the HireHop rental product id (asset_assign_list MAIN_ID) on the
 * company inventory row. Active rows stay unique per company, including after
 * a soft delete releases the key.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('company_inventory')) {
            return;
        }

        Schema::table('company_inventory', function (Blueprint $table) {
            if (!Schema::hasColumn('company_inventory', 'hirehop_stock_id')) {
                $table->string('hirehop_stock_id', 100)->nullable()->after(
                    Schema::hasColumn('company_inventory', 'rentman_equipment_id')
                        ? 'rentman_equipment_id'
                        : 'company_id'
                );
            }
        });

        if (!Schema::hasColumn('company_inventory', 'active_hirehop_key')) {
            Schema::table('company_inventory', function (Blueprint $table) {
                $table->string('active_hirehop_key', 191)->nullable();
            });
        }

        if (Schema::hasColumn('company_inventory', 'deleted_at')) {
            DB::statement(
                "UPDATE `company_inventory` SET `active_hirehop_key` = IF(`deleted_at` IS NULL AND `hirehop_stock_id` IS NOT NULL AND `hirehop_stock_id` <> '', LEFT(CONCAT(`company_id`, ':', `hirehop_stock_id`), 191), NULL) WHERE `active_hirehop_key` IS NULL"
            );
        }

        if (!$this->indexExists('company_inventory_company_hirehop_index')) {
            Schema::table('company_inventory', function (Blueprint $table) {
                $table->index(['company_id', 'hirehop_stock_id'], 'company_inventory_company_hirehop_index');
            });
        }

        if (!$this->indexExists('company_inventory_active_hirehop_unique')) {
            Schema::table('company_inventory', function (Blueprint $table) {
                $table->unique('active_hirehop_key', 'company_inventory_active_hirehop_unique');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('company_inventory')) {
            return;
        }

        if ($this->indexExists('company_inventory_active_hirehop_unique')) {
            Schema::table('company_inventory', function (Blueprint $table) {
                $table->dropUnique('company_inventory_active_hirehop_unique');
            });
        }

        if ($this->indexExists('company_inventory_company_hirehop_index')) {
            Schema::table('company_inventory', function (Blueprint $table) {
                $table->dropIndex('company_inventory_company_hirehop_index');
            });
        }

        Schema::table('company_inventory', function (Blueprint $table) {
            if (Schema::hasColumn('company_inventory', 'active_hirehop_key')) {
                $table->dropColumn('active_hirehop_key');
            }
            if (Schema::hasColumn('company_inventory', 'hirehop_stock_id')) {
                $table->dropColumn('hirehop_stock_id');
            }
        });
    }

    private function indexExists(string $index): bool
    {
        $rows = DB::select(
            'SHOW INDEX FROM `company_inventory` WHERE Key_name = ?',
            [$index]
        );

        return $rows !== [];
    }
};
