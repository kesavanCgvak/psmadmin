<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_master_ai_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('inventory_master_ai_logs', 'reviewed_by')) {
                $table->unsignedBigInteger('reviewed_by')->nullable()->after('updated_by');
                $table->foreign('reviewed_by', 'fk_inv_master_ai_logs_reviewed_by')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_master_ai_logs', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_master_ai_logs', 'reviewed_by')) {
                $table->dropForeign('fk_inv_master_ai_logs_reviewed_by');
                $table->dropColumn('reviewed_by');
            }
        });
    }
};
