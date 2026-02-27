<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Insert company_user_limit setting with default value of 3
        DB::table('settings')->insert([
            'key' => 'company_user_limit',
            'value' => '3',
            'type' => 'integer',
            'description' => 'Maximum number of users allowed per company',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')->where('key', 'company_user_limit')->delete();
    }
};
