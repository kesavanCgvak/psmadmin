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
        Schema::create('import_session_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_session_id')->constrained()->onDelete('cascade');
            $table->integer('excel_row_number'); // Original row number from Excel
            $table->text('original_description'); // Original product description from Excel
            $table->string('detected_model')->nullable(); // Extracted model number (e.g., "DN360")
            $table->string('normalized_model')->nullable(); // Normalized for matching
            $table->integer('quantity')->default(1);
            $table->string('software_code')->nullable(); // Rental software code
            $table->string('status')->default('pending'); // pending, analyzed, rejected, confirmed
            $table->text('rejection_reason')->nullable();
            $table->string('action')->nullable(); // 'attach' or 'create' (user's choice)
            $table->foreignId('selected_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->timestamps();
            
            $table->index(['import_session_id', 'status']);
            $table->index(['excel_row_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_session_items');
    }
};
