<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_jobs')) {
            Schema::table('rental_jobs', function (Blueprint $table) {
                if (!Schema::hasColumn('rental_jobs', 'rentman_project_request_id')) {
                    $table->string('rentman_project_request_id', 100)->nullable()->after('flex_sync_status');
                }
                if (!Schema::hasColumn('rental_jobs', 'rentman_project_request_displayname')) {
                    $table->string('rentman_project_request_displayname', 100)->nullable()->after('rentman_project_request_id');
                }
                if (!Schema::hasColumn('rental_jobs', 'rentman_sync_status')) {
                    $table->string('rentman_sync_status', 32)->nullable()->after('rentman_project_request_displayname');
                }
            });
        }

        if (Schema::hasTable('supply_jobs')) {
            Schema::table('supply_jobs', function (Blueprint $table) {
                if (!Schema::hasColumn('supply_jobs', 'rentman_project_request_id')) {
                    $table->string('rentman_project_request_id', 100)->nullable()->after('flex_sync_status');
                }
                if (!Schema::hasColumn('supply_jobs', 'rentman_project_request_displayname')) {
                    $table->string('rentman_project_request_displayname', 100)->nullable()->after('rentman_project_request_id');
                }
                if (!Schema::hasColumn('supply_jobs', 'rentman_sync_status')) {
                    $table->string('rentman_sync_status', 32)->nullable()->after('rentman_project_request_displayname');
                }
            });
        }

        if (!Schema::hasTable('rentman_project_request_sync_logs')) {
            Schema::create('rentman_project_request_sync_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('rental_job_id');
                $table->unsignedBigInteger('supply_job_id')->nullable();
                $table->unsignedBigInteger('provider_company_id');
                $table->string('status', 32)->default('PENDING');
                $table->string('rentman_contact_id', 100)->nullable();
                $table->string('rentman_project_request_id', 100)->nullable();
                $table->string('rentman_project_request_displayname', 100)->nullable();
                $table->json('products_attached')->nullable();
                $table->json('products_missing')->nullable();
                $table->text('error_message')->nullable();
                $table->json('steps')->nullable();
                $table->timestamps();

                $table->foreign('rental_job_id')->references('id')->on('rental_jobs')->onDelete('cascade');
                $table->foreign('supply_job_id')->references('id')->on('supply_jobs')->onDelete('set null');
                $table->foreign('provider_company_id')->references('id')->on('companies')->onDelete('cascade');
                $table->index(['rental_job_id', 'supply_job_id'], 'rm_pr_sync_logs_job_idx');
            });
        } elseif (!$this->indexExists('rentman_project_request_sync_logs', 'rm_pr_sync_logs_job_idx')) {
            Schema::table('rentman_project_request_sync_logs', function (Blueprint $table) {
                $table->index(['rental_job_id', 'supply_job_id'], 'rm_pr_sync_logs_job_idx');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $database = Schema::getConnection()->getDatabaseName();
        $row = Schema::getConnection()->selectOne(
            'SELECT 1 AS ok FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $indexName]
        );

        return $row !== null;
    }

    public function down(): void
    {
        Schema::dropIfExists('rentman_project_request_sync_logs');

        if (Schema::hasTable('supply_jobs')) {
            Schema::table('supply_jobs', function (Blueprint $table) {
                foreach (['rentman_sync_status', 'rentman_project_request_displayname', 'rentman_project_request_id'] as $col) {
                    if (Schema::hasColumn('supply_jobs', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('rental_jobs')) {
            Schema::table('rental_jobs', function (Blueprint $table) {
                foreach (['rentman_sync_status', 'rentman_project_request_displayname', 'rentman_project_request_id'] as $col) {
                    if (Schema::hasColumn('rental_jobs', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
