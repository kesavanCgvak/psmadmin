<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100)->nullable();
            $table->string('key_prefix', 20);
            $table->string('key_hash', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['provider_user_id', 'is_active']);
            $table->index('key_prefix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_api_keys');
    }
};

