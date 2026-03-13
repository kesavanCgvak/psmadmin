<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Allows brand_id to be NULL for Flex imports when brand is not provided.
     */
    public function up(): void
    {
        $fkName = $this->getBrandIdForeignKeyName();
        if ($fkName) {
            DB::statement("ALTER TABLE inventory_master DROP FOREIGN KEY `{$fkName}`");
        }

        DB::statement('ALTER TABLE inventory_master MODIFY brand_id BIGINT UNSIGNED NULL');

        if ($fkName) {
            DB::statement('ALTER TABLE inventory_master ADD CONSTRAINT inventory_master_brand_id_foreign FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $fkName = $this->getBrandIdForeignKeyName();
        if ($fkName) {
            DB::statement("ALTER TABLE inventory_master DROP FOREIGN KEY `{$fkName}`");
        }

        DB::statement('ALTER TABLE inventory_master MODIFY brand_id BIGINT UNSIGNED NOT NULL');

        if ($fkName) {
            DB::statement('ALTER TABLE inventory_master ADD CONSTRAINT inventory_master_brand_id_foreign FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE');
        }
    }

    protected function getBrandIdForeignKeyName(): ?string
    {
        $result = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'inventory_master'
            AND COLUMN_NAME = 'brand_id'
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        return $result[0]->CONSTRAINT_NAME ?? null;
    }
};
