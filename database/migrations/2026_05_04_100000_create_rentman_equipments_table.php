<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rentman_equipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('rentman_id', 64);
            $table->string('name')->nullable();
            $table->string('displayname')->nullable();
            $table->string('code', 191)->nullable();
            $table->string('update_hash', 128)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->boolean('is_imported')->default(false);
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'rentman_id']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rentman_equipments');
    }
};
