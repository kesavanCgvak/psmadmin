<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->decimal('rating_override', 3, 1)->nullable()->after('rating');
            $table->unsignedBigInteger('rating_override_set_by')->nullable()->after('rating_override');
            $table->text('rating_override_reason')->nullable()->after('rating_override_set_by');
            $table->timestamp('rating_override_set_at')->nullable()->after('rating_override_reason');

            $table->foreign('rating_override_set_by')
                ->references('id')
                ->on('users')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['rating_override_set_by']);
            $table->dropColumn([
                'rating_override',
                'rating_override_set_by',
                'rating_override_reason',
                'rating_override_set_at',
            ]);
        });
    }
};

