<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_master_ai_specs', function (Blueprint $table) {
            if (!Schema::hasColumn('inventory_master_ai_specs', 'reviewed_by')) {
                $table->unsignedBigInteger('reviewed_by')->nullable()->after('status');
                $table->foreign('reviewed_by', 'fk_inv_master_ai_specs_reviewed_by')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
            if (!Schema::hasColumn('inventory_master_ai_specs', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            }
            if (!Schema::hasColumn('inventory_master_ai_specs', 'review_notes')) {
                $table->text('review_notes')->nullable()->after('reviewed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_master_ai_specs', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_master_ai_specs', 'reviewed_by')) {
                $table->dropForeign('fk_inv_master_ai_specs_reviewed_by');
            }
        });

        Schema::table('inventory_master_ai_specs', function (Blueprint $table) {
            foreach (['review_notes', 'reviewed_at', 'reviewed_by'] as $column) {
                if (Schema::hasColumn('inventory_master_ai_specs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
