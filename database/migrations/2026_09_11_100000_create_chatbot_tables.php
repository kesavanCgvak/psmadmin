<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_knowledge', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('category')->nullable()->index();
            $table->string('question')->nullable();
            $table->text('content');
            $table->text('keywords')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('chatbot_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 32)->default('api')->index();
            $table->string('title')->nullable();
            $table->string('status', 32)->default('open')->index();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('chatbot_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chatbot_conversation_id')
                ->constrained('chatbot_conversations')
                ->cascadeOnDelete();
            $table->string('role', 16);
            $table->text('content');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['chatbot_conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_messages');
        Schema::dropIfExists('chatbot_conversations');
        Schema::dropIfExists('chatbot_knowledge');
    }
};
