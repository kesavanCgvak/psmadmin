<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('profile_picture')->nullable();
            $table->string('full_name');
            $table->date('birthday')->nullable();
            $table->string('email');
            $table->string('mobile')->nullable();
            $table->timestamps();

            $table->index(['full_name', 'email'], 'idx_user_profiles_name_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
