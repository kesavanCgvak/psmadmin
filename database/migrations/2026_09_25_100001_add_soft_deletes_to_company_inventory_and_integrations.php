<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-delete company inventory and company integrations.
 *
 * Active external ids stay unique through nullable key columns. The application
 * clears those keys when a row is soft-deleted, so the same rental-software item
 * can be imported again. Audit rows live in activity_logs and are not tied to
 * these foreign keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addDeletedAt('company_inventory');
        $this->addDeletedAt('company_integrations');

        $this->dropUniqueOnColumns('company_inventory', ['company_id', 'rentman_equipment_id']);
        $this->dropUniqueOnColumns('company_inventory', ['company_id', 'flex_resource_id']);
        $this->dropUniqueOnColumns('company_integrations', ['company_id', 'integration_type']);

        $this->addActiveKey(
            'company_inventory',
            'active_rentman_key',
            'company_inventory_active_rentman_unique',
            "IF(`deleted_at` IS NULL AND `rentman_equipment_id` IS NOT NULL AND `rentman_equipment_id` <> '', LEFT(CONCAT(`company_id`, ':', `rentman_equipment_id`), 191), NULL)"
        );
        $this->addActiveKey(
            'company_inventory',
            'active_flex_key',
            'company_inventory_active_flex_unique',
            "IF(`deleted_at` IS NULL AND `flex_resource_id` IS NOT NULL AND `flex_resource_id` <> '', LEFT(CONCAT(`company_id`, ':', `flex_resource_id`), 191), NULL)"
        );
        $this->addActiveKey(
            'company_integrations',
            'active_integration_key',
            'company_integrations_active_key_unique',
            "IF(`deleted_at` IS NULL, LEFT(CONCAT(`company_id`, ':', `integration_type`), 191), NULL)"
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('company_inventory') && Schema::hasColumn('company_inventory', 'deleted_at')) {
            DB::table('company_inventory')->whereNotNull('deleted_at')->delete();
        }
        if (Schema::hasTable('company_integrations') && Schema::hasColumn('company_integrations', 'deleted_at')) {
            DB::table('company_integrations')->whereNotNull('deleted_at')->delete();
        }

        $this->dropActiveKey('company_inventory', 'active_rentman_key', 'company_inventory_active_rentman_unique');
        $this->dropActiveKey('company_inventory', 'active_flex_key', 'company_inventory_active_flex_unique');
        $this->dropActiveKey('company_integrations', 'active_integration_key', 'company_integrations_active_key_unique');

        $this->dropDeletedAt('company_inventory');
        $this->dropDeletedAt('company_integrations');

        if (Schema::hasColumn('company_inventory', 'rentman_equipment_id')) {
            $this->restoreUnique('company_inventory', ['company_id', 'rentman_equipment_id'], 'company_inventory_company_rentman_unique');
        }
        if (Schema::hasColumn('company_inventory', 'flex_resource_id')) {
            $this->restoreUnique('company_inventory', ['company_id', 'flex_resource_id'], 'company_inventory_company_flex_unique');
        }
        if (Schema::hasTable('company_integrations')) {
            $this->restoreUnique('company_integrations', ['company_id', 'integration_type'], 'company_integrations_company_id_integration_type_unique');
        }
    }

    private function addDeletedAt(string $table): void
    {
        if (!Schema::hasTable($table) || Schema::hasColumn($table, 'deleted_at')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->softDeletes();
        });
    }

    private function dropDeletedAt(string $table): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'deleted_at')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropSoftDeletes();
        });
    }

    private function addActiveKey(string $table, string $column, string $index, string $expression): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        if (!Schema::hasColumn($table, $column)) {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->string($column, 191)->nullable();
            });
        }

        DB::statement(sprintf(
            'UPDATE `%s` SET `%s` = %s WHERE `%s` IS NULL',
            $table,
            $column,
            $expression,
            $column
        ));

        if (!$this->indexExists($table, $index)) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $index) {
                $blueprint->unique($column, $index);
            });
        }
    }

    private function dropActiveKey(string $table, string $column, string $index): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }

        $this->dropUniqueByName($table, $index);

        Schema::table($table, function (Blueprint $blueprint) use ($column) {
            $blueprint->dropColumn($column);
        });
    }

    private function restoreUnique(string $table, array $columns, string $index): void
    {
        if ($this->uniqueExists($table, $columns)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $index) {
            $blueprint->unique($columns, $index);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropUniqueOnColumns(string $table, array $columns): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        foreach ($this->uniqueIndexNames($table, $columns) as $name) {
            $this->dropUniqueByName($table, $name);
        }
    }

    private function dropUniqueByName(string $table, string $index): void
    {
        if (!$this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($index) {
            $blueprint->dropUnique($index);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function uniqueExists(string $table, array $columns): bool
    {
        return $this->uniqueIndexNames($table, $columns) !== [];
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function uniqueIndexNames(string $table, array $columns): array
    {
        $rows = DB::select('SHOW INDEX FROM `'.$table.'` WHERE Non_unique = 0');
        $grouped = [];

        foreach ($rows as $row) {
            $name = (string) $row->Key_name;
            if ($name === 'PRIMARY') {
                continue;
            }
            $grouped[$name][(int) $row->Seq_in_index] = (string) $row->Column_name;
        }

        $names = [];
        foreach ($grouped as $name => $indexedColumns) {
            ksort($indexedColumns);
            if (array_values($indexedColumns) === array_values($columns)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function indexExists(string $table, string $index): bool
    {
        $rows = DB::select(
            'SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?',
            [$index]
        );

        return $rows !== [];
    }
};
