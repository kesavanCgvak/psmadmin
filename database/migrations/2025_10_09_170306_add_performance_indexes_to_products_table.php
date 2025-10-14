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
            // Add indexes for frequently queried columns
            $table->index('category_id');
            $table->index('brand_id');
            $table->index('sub_category_id');
            $table->index('created_at');
            $table->index('model');
            $table->index('psm_code');

            // Composite index for common query patterns
            $table->index(['category_id', 'brand_id']);
            $table->index(['category_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Drop the indexes
            $table->dropIndex(['category_id']);
            $table->dropIndex(['brand_id']);
            $table->dropIndex(['sub_category_id']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['model']);
            $table->dropIndex(['psm_code']);
            $table->dropIndex(['category_id', 'brand_id']);
            $table->dropIndex(['category_id', 'created_at']);
        });
    }
};
