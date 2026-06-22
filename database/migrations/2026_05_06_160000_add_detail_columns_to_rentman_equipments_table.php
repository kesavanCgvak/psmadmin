<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentman_equipments', function (Blueprint $table) {
            if (!Schema::hasColumn('rentman_equipments', 'subrental_costs')) {
                $table->decimal('subrental_costs', 12, 2)->nullable()->after('code');
            }
            if (!Schema::hasColumn('rentman_equipments', 'rental_sales')) {
                $table->string('rental_sales', 50)->nullable()->after('subrental_costs');
            }
            if (!Schema::hasColumn('rentman_equipments', 'shop_description_long')) {
                $table->text('shop_description_long')->nullable()->after('rental_sales');
            }
            if (!Schema::hasColumn('rentman_equipments', 'height')) {
                $table->decimal('height', 12, 3)->nullable()->after('shop_description_long');
            }
            if (!Schema::hasColumn('rentman_equipments', 'width')) {
                $table->decimal('width', 12, 3)->nullable()->after('height');
            }
            if (!Schema::hasColumn('rentman_equipments', 'length')) {
                $table->decimal('length', 12, 3)->nullable()->after('width');
            }
            if (!Schema::hasColumn('rentman_equipments', 'weight')) {
                $table->decimal('weight', 12, 3)->nullable()->after('length');
            }
            if (!Schema::hasColumn('rentman_equipments', 'country_of_origin')) {
                $table->string('country_of_origin', 120)->nullable()->after('weight');
            }
            if (!Schema::hasColumn('rentman_equipments', 'current_quantity')) {
                $table->integer('current_quantity')->nullable()->after('country_of_origin');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rentman_equipments', function (Blueprint $table) {
            if (Schema::hasColumn('rentman_equipments', 'current_quantity')) {
                $table->dropColumn('current_quantity');
            }
            if (Schema::hasColumn('rentman_equipments', 'country_of_origin')) {
                $table->dropColumn('country_of_origin');
            }
            if (Schema::hasColumn('rentman_equipments', 'weight')) {
                $table->dropColumn('weight');
            }
            if (Schema::hasColumn('rentman_equipments', 'length')) {
                $table->dropColumn('length');
            }
            if (Schema::hasColumn('rentman_equipments', 'width')) {
                $table->dropColumn('width');
            }
            if (Schema::hasColumn('rentman_equipments', 'height')) {
                $table->dropColumn('height');
            }
            if (Schema::hasColumn('rentman_equipments', 'shop_description_long')) {
                $table->dropColumn('shop_description_long');
            }
            if (Schema::hasColumn('rentman_equipments', 'rental_sales')) {
                $table->dropColumn('rental_sales');
            }
            if (Schema::hasColumn('rentman_equipments', 'subrental_costs')) {
                $table->dropColumn('subrental_costs');
            }
        });
    }
};
