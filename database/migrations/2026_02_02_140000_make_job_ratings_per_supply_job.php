<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Change job_ratings from one-per-rental-job to one-per-supply-job.
     * Rating from POST /api/supply-jobs/101/rate appears only on supplier 101.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('job_ratings', 'supply_job_id')) {
            Schema::table('job_ratings', function (Blueprint $table) {
                $table->unsignedBigInteger('supply_job_id')->nullable()->after('rental_job_id');
            });
        }

        Schema::table('job_ratings', function (Blueprint $table) {
            $table->dropForeign(['rental_job_id']);
        });
        Schema::table('job_ratings', function (Blueprint $table) {
            $table->dropUnique(['rental_job_id']);
        });
        Schema::table('job_ratings', function (Blueprint $table) {
            $table->foreign('rental_job_id')->references('id')->on('rental_jobs')->onDelete('cascade');
        });

        $this->backfillPerSupplyJob();

        DB::statement('ALTER TABLE job_ratings MODIFY supply_job_id BIGINT UNSIGNED NOT NULL');
        Schema::table('job_ratings', function (Blueprint $table) {
            $table->unique('supply_job_id');
            $table->foreign('supply_job_id')->references('id')->on('supply_jobs')->onDelete('cascade');
        });
    }

    private function backfillPerSupplyJob(): void
    {
        $oldRatings = DB::table('job_ratings')->whereNull('supply_job_id')->get();
        foreach ($oldRatings as $old) {
            $replies = DB::table('job_rating_replies')->where('job_rating_id', $old->id)->get();
            $supplyJobIds = $replies->pluck('supply_job_id')->unique()->values();
            if ($supplyJobIds->isEmpty()) {
                $supplyJobIds = DB::table('supply_jobs')
                    ->where('rental_job_id', $old->rental_job_id)
                    ->where('status', 'rated')
                    ->pluck('id');
            }
            if ($supplyJobIds->isEmpty()) {
                $supplyJobIds = DB::table('supply_jobs')->where('rental_job_id', $old->rental_job_id)->pluck('id');
            }
            $newIdsBySupplyJob = [];
            foreach ($supplyJobIds as $supplyJobId) {
                $newId = DB::table('job_ratings')->insertGetId([
                    'rental_job_id' => $old->rental_job_id,
                    'supply_job_id' => $supplyJobId,
                    'rating' => $old->rating,
                    'comment' => $old->comment,
                    'rated_at' => $old->rated_at,
                    'skipped_at' => $old->skipped_at,
                    'created_at' => $old->created_at,
                    'updated_at' => now(),
                ]);
                $newIdsBySupplyJob[$supplyJobId] = $newId;
            }
            foreach ($replies as $reply) {
                $newId = $newIdsBySupplyJob[$reply->supply_job_id] ?? null;
                if ($newId !== null) {
                    DB::table('job_rating_replies')->where('id', $reply->id)->update(['job_rating_id' => $newId]);
                }
            }
            DB::table('job_ratings')->where('id', $old->id)->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_ratings', function (Blueprint $table) {
            $table->dropForeign(['supply_job_id']);
            $table->dropUnique(['supply_job_id']);
        });
        Schema::table('job_ratings', function (Blueprint $table) {
            $table->unsignedBigInteger('supply_job_id')->nullable()->change();
        });
        Schema::table('job_ratings', function (Blueprint $table) {
            $table->dropForeign(['rental_job_id']);
            $table->unique('rental_job_id');
            $table->foreign('rental_job_id')->references('id')->on('rental_jobs')->onDelete('cascade');
        });
    }
};
