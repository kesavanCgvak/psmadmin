<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cms_pages', function (Blueprint $table) {
            $table->string('section', 32)->default('general')->after('slug');
            $table->unsignedInteger('sort_order')->default(0)->after('section');
            $table->index(['section', 'is_published', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cms_pages', function (Blueprint $table) {
            $table->dropIndex(['section', 'is_published', 'sort_order']);
            $table->dropColumn(['section', 'sort_order']);
        });
    }
};
