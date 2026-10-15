<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chat_external_conversations')) {
            Schema::create('chat_external_conversations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->string('external_system', 32);
                $table->string('external_conversation_id', 191);
                $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(
                    ['company_id', 'external_system', 'external_conversation_id'],
                    'chat_ext_conversations_identity_unique'
                );
                $table->index(['company_id', 'conversation_id'], 'chat_ext_conversations_company_conversation_index');
            });
        }

        if (! Schema::hasTable('chat_external_messages')) {
            Schema::create('chat_external_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->string('external_system', 32);
                $table->string('external_message_id', 191);
                $table->string('external_conversation_id', 191);
                $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
                $table->foreignId('message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
                $table->string('body_hash', 64);
                $table->timestamp('external_created_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['company_id', 'external_system', 'external_message_id'],
                    'chat_ext_messages_identity_unique'
                );
                $table->index('message_id', 'chat_ext_messages_message_index');
                $table->index(['company_id', 'conversation_id'], 'chat_ext_messages_company_conversation_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_external_messages');
        Schema::dropIfExists('chat_external_conversations');
    }
};
