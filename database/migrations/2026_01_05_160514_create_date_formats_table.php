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
        Schema::create('date_formats', function (Blueprint $table) {
            $table->id();
            $table->string('format')->unique(); // e.g., "MM/DD/YYYY", "DD/MM/YYYY", "YYYY-MM-DD"
            $table->string('name'); // e.g., "US Format", "European Format", "ISO Format"
            $table->text('description')->nullable(); // Optional description
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('date_formats');
    }
};
