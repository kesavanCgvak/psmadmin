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
        Schema::create('pricing_schemes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // e.g., "DAY", "WEEK", "MONTH", "CUSTOM"
            $table->string('name'); // e.g., "Daily", "Weekly", "Monthly", "Custom"
            $table->text('description')->nullable(); // Optional description
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing_schemes');
    }
};
