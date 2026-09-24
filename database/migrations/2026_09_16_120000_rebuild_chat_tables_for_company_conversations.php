<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Convert the Phase 1 user-to-user chat tables to company-to-company.
     * No production chat data is migrated; conversations are rebuilt empty.
     */
    public function up(): void
    {
        $needsRebuild = Schema::hasTable('chat_conversation_participants')
            || (Schema::hasTable('chat_conversations') && ! Schema::hasColumn('chat_conversations', 'company_a_id'));

        if (! $needsRebuild) {
            return;
        }

        Schema::dropIfExists('chat_conversation_participants');
        Schema::dropIfExists('chat_conversation_user_states');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');

        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_a_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('company_b_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('rental_job_id')->nullable();
            $table->string('pair_key', 80)->unique();
            $table->timestamps();

            $table->index(['company_a_id', 'company_b_id']);
            $table->index('rental_job_id');
            $table->index('created_by_user_id');
        });

        if (Schema::hasTable('rental_jobs')) {
            Schema::table('chat_conversations', function (Blueprint $table) {
                $table->foreign('rental_job_id')->references('id')->on('rental_jobs')->nullOnDelete();
            });
        }

        Schema::create('chat_conversation_user_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('sender_company_id')->constrained('companies')->restrictOnDelete();
            $table->text('message');
            $table->string('message_type', 32)->default('text');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['conversation_id', 'created_at']);
            $table->index('sender_user_id');
            $table->index('sender_company_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Irreversible on purpose: previous user-to-user chat had no production data.
    }
};
