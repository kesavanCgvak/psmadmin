<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds replacement_price to company_inventory for Flex import sync.
     */
    public function up(): void
    {
        Schema::table('company_inventory', function (Blueprint $table) {
            if (!Schema::hasColumn('company_inventory', 'replacement_price')) {
                $table->decimal('replacement_price', 12, 2)->nullable()->after('rental_price')
                    ->comment('Replacement cost from Flex or manual entry');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_inventory', function (Blueprint $table) {
            if (Schema::hasColumn('company_inventory', 'replacement_price')) {
                $table->dropColumn('replacement_price');
            }
        });
    }
};
