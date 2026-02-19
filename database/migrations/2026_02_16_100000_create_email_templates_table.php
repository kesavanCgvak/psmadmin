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
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique()->comment('Unique identifier (e.g., registrationSuccess, forgotPassword)');
            $table->string('subject')->comment('Email subject line');
            $table->longText('body')->comment('HTML email template content');
            $table->json('variables')->nullable()->comment('Available variables for this template');
            $table->text('description')->nullable()->comment('Description of what this email is used for');
            $table->boolean('is_active')->default(true)->comment('Enable/disable this template');
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('name');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
