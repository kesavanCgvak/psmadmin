<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rental_jobs') || !Schema::hasColumn('rental_jobs', 'delivery_address')) {
            return;
        }

        Schema::table('rental_jobs', function (Blueprint $table) {
            $table->string('delivery_address', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('rental_jobs') || !Schema::hasColumn('rental_jobs', 'delivery_address')) {
            return;
        }

        Schema::table('rental_jobs', function (Blueprint $table) {
            $table->string('delivery_address', 255)->nullable(false)->change();
        });
    }
};
