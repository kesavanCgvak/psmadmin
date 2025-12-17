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
        Schema::create('import_session_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_session_item_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->string('psm_code')->nullable();
            $table->decimal('confidence', 5, 4)->default(0.0000); // 0.0000 to 1.0000
            $table->string('match_type')->nullable(); // 'psm_code', 'exact_model', 'normalized_similarity', 'fuzzy'
            $table->timestamps();
            
            $table->index(['import_session_item_id']);
            $table->index(['product_id']);
            $table->index(['confidence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_session_matches');
    }
};
