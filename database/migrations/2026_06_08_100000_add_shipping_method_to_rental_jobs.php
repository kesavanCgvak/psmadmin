<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rental_jobs')) {
            return;
        }

        Schema::table('rental_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('rental_jobs', 'shipping_method')) {
                $table->string('shipping_method', 50)
                    ->default('deliver_to_me')
                    ->after('delivery_address');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('rental_jobs')) {
            return;
        }

        Schema::table('rental_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('rental_jobs', 'shipping_method')) {
                $table->dropColumn('shipping_method');
            }
        });
    }
};
