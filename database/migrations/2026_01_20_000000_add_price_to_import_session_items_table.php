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
        Schema::table('import_session_items', function (Blueprint $table) {
            // Price imported from Excel (Column D). Optional and nullable for backward compatibility.
            $table->decimal('price', 10, 2)->nullable()->after('quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('import_session_items', function (Blueprint $table) {
            $table->dropColumn('price');
        });
    }
};

