<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Fix: supply_jobs.status and rental_jobs.status did not allow
     * 'completed_pending_rating' / 'rated', causing 500 on complete API.
     */
    public function up(): void
    {
        // MySQL: convert status to VARCHAR so all app statuses are accepted.
        // App uses: pending, negotiating, accepted, cancelled, closed,
        // partially_accepted, completed, completed_pending_rating, rated.
        if (Schema::hasTable('supply_jobs') && Schema::hasColumn('supply_jobs', 'status')) {
            DB::statement("ALTER TABLE supply_jobs MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'pending'");
        }
        if (Schema::hasTable('rental_jobs') && Schema::hasColumn('rental_jobs', 'status')) {
            DB::statement("ALTER TABLE rental_jobs MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'open'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverting to ENUM would require knowing the original values; leave as VARCHAR.
        // If you need to revert, restore from backup or redefine the original ENUM here.
    }
};
