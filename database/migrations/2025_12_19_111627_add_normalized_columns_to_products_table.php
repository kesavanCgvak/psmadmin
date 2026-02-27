<?php

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
        Schema::table('products', function (Blueprint $table) {
            // Add normalized_model if it doesn't exist
            if (!Schema::hasColumn('products', 'normalized_model')) {
                $table->string('normalized_model', 255)->nullable()->after('model');
            }
            
            // Add normalized_full_name if it doesn't exist
            if (!Schema::hasColumn('products', 'normalized_full_name')) {
                $table->string('normalized_full_name', 255)->nullable()->after('normalized_model');
            }
        });

        // Add indexes if they don't exist
        Schema::table('products', function (Blueprint $table) {
            if (!$this->indexExists('products', 'idx_products_normalized_model')) {
                $table->index('normalized_model', 'idx_products_normalized_model');
            }
            
            if (!$this->indexExists('products', 'idx_products_brand_normalized_full')) {
                $table->index(['brand_id', 'normalized_full_name'], 'idx_products_brand_normalized_full');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'normalized_full_name')) {
                $table->dropIndex('idx_products_brand_normalized_full');
                $table->dropColumn('normalized_full_name');
            }
            
            if (Schema::hasColumn('products', 'normalized_model')) {
                $table->dropIndex('idx_products_normalized_model');
                $table->dropColumn('normalized_model');
            }
        });
    }

    /**
     * Check if an index exists on a table
     */
    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $databaseName = $connection->getDatabaseName();
        
        $result = $connection->select(
            "SELECT COUNT(*) as count 
             FROM information_schema.statistics 
             WHERE table_schema = ? 
             AND table_name = ? 
             AND index_name = ?",
            [$databaseName, $table, $index]
        );
        
        return $result[0]->count > 0;
    }
};
