<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_integrations') && !Schema::hasColumn('company_integrations', 'settings')) {
            Schema::table('company_integrations', function (Blueprint $table) {
                $table->json('settings')->nullable()->after('api_key');
            });
        }

        if (Schema::hasTable('rental_jobs')) {
            Schema::table('rental_jobs', function (Blueprint $table) {
                if (!Schema::hasColumn('rental_jobs', 'flex_sales_quote_id')) {
                    $table->string('flex_sales_quote_id', 100)->nullable()->after('status');
                }
                if (!Schema::hasColumn('rental_jobs', 'flex_sales_quote_number')) {
                    $table->string('flex_sales_quote_number', 100)->nullable()->after('flex_sales_quote_id');
                }
                if (!Schema::hasColumn('rental_jobs', 'flex_sync_status')) {
                    $table->string('flex_sync_status', 32)->nullable()->after('flex_sales_quote_number');
                }
            });
        }

        if (Schema::hasTable('supply_jobs')) {
            Schema::table('supply_jobs', function (Blueprint $table) {
                if (!Schema::hasColumn('supply_jobs', 'flex_sales_quote_id')) {
                    $table->string('flex_sales_quote_id', 100)->nullable()->after('status');
                }
                if (!Schema::hasColumn('supply_jobs', 'flex_sales_quote_number')) {
                    $table->string('flex_sales_quote_number', 100)->nullable()->after('flex_sales_quote_id');
                }
                if (!Schema::hasColumn('supply_jobs', 'flex_sync_status')) {
                    $table->string('flex_sync_status', 32)->nullable()->after('flex_sales_quote_number');
                }
            });
        }

        if (!Schema::hasTable('flex_sales_quote_sync_logs')) {
            Schema::create('flex_sales_quote_sync_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('rental_job_id');
                $table->unsignedBigInteger('supply_job_id')->nullable();
                $table->unsignedBigInteger('provider_company_id');
                $table->string('status', 32)->default('PENDING');
                $table->string('flex_client_id', 100)->nullable();
                $table->string('flex_sales_quote_id', 100)->nullable();
                $table->string('flex_sales_quote_number', 100)->nullable();
                $table->json('products_attached')->nullable();
                $table->json('products_missing')->nullable();
                $table->text('error_message')->nullable();
                $table->json('steps')->nullable();
                $table->timestamps();

                $table->foreign('rental_job_id')->references('id')->on('rental_jobs')->onDelete('cascade');
                $table->foreign('supply_job_id')->references('id')->on('supply_jobs')->onDelete('set null');
                $table->foreign('provider_company_id')->references('id')->on('companies')->onDelete('cascade');
                $table->index(['rental_job_id', 'supply_job_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('flex_sales_quote_sync_logs');

        if (Schema::hasTable('supply_jobs')) {
            Schema::table('supply_jobs', function (Blueprint $table) {
                foreach (['flex_sync_status', 'flex_sales_quote_number', 'flex_sales_quote_id'] as $col) {
                    if (Schema::hasColumn('supply_jobs', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('rental_jobs')) {
            Schema::table('rental_jobs', function (Blueprint $table) {
                foreach (['flex_sync_status', 'flex_sales_quote_number', 'flex_sales_quote_id'] as $col) {
                    if (Schema::hasColumn('rental_jobs', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('company_integrations') && Schema::hasColumn('company_integrations', 'settings')) {
            Schema::table('company_integrations', function (Blueprint $table) {
                $table->dropColumn('settings');
            });
        }
    }
};
