<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rentman_integration_logs')) {
            Schema::create('rentman_integration_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('rental_request_id');
                $table->unsignedBigInteger('provider_id');
                $table->string('action', 64);
                $table->string('status', 32);
                $table->text('request_url')->nullable();
                $table->json('request_payload')->nullable();
                $table->json('response_payload')->nullable();
                $table->text('error_message')->nullable();
                $table->string('rentman_project_request_id', 100)->nullable();
                $table->string('rentman_equipment_id', 100)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('rental_request_id')->references('id')->on('rental_jobs')->onDelete('cascade');
                $table->foreign('provider_id')->references('id')->on('companies')->onDelete('cascade');
                $table->index(['rental_request_id', 'provider_id']);
                $table->index(['provider_id', 'action']);
            });
        }

        if (Schema::hasTable('rental_request_provider_quotes')) {
            Schema::table('rental_request_provider_quotes', function (Blueprint $table) {
                if (!Schema::hasColumn('rental_request_provider_quotes', 'rentman_project_request_id')) {
                    $table->string('rentman_project_request_id', 100)->nullable()->after('flex_quote_number');
                }
                if (!Schema::hasColumn('rental_request_provider_quotes', 'rentman_project_request_displayname')) {
                    $table->string('rentman_project_request_displayname', 100)->nullable()->after('rentman_project_request_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rental_request_provider_quotes')) {
            Schema::table('rental_request_provider_quotes', function (Blueprint $table) {
                foreach (['rentman_project_request_displayname', 'rentman_project_request_id'] as $col) {
                    if (Schema::hasColumn('rental_request_provider_quotes', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        Schema::dropIfExists('rentman_integration_logs');
    }
};
