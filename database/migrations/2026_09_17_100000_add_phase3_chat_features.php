<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user archive state, message delivery/audit fields, notification prefs, and search indexes.
     */
    public function up(): void
    {
        if (Schema::hasTable('chat_conversation_user_states')) {
            Schema::table('chat_conversation_user_states', function (Blueprint $table) {
                if (! Schema::hasColumn('chat_conversation_user_states', 'archived_at')) {
                    $table->timestamp('archived_at')->nullable()->after('last_read_at');
                }
            });

            Schema::table('chat_conversation_user_states', function (Blueprint $table) {
                $table->index(['user_id', 'archived_at'], 'chat_user_states_user_archived_index');
                $table->index(['conversation_id', 'last_read_at'], 'chat_user_states_conversation_read_index');
            });
        }

        if (Schema::hasTable('chat_messages')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                if (! Schema::hasColumn('chat_messages', 'delivered_at')) {
                    $table->timestamp('delivered_at')->nullable()->after('message_type');
                }
                if (! Schema::hasColumn('chat_messages', 'deleted_by_user_id')) {
                    $table->unsignedBigInteger('deleted_by_user_id')->nullable()->after('delivered_at');
                }
            });

            Schema::table('chat_messages', function (Blueprint $table) {
                $table->index(['conversation_id', 'sender_company_id', 'created_at'], 'chat_messages_unread_lookup_index');
                $table->index('deleted_by_user_id', 'chat_messages_deleted_by_index');
            });

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                Schema::table('chat_messages', function (Blueprint $table) {
                    $table->fullText('message', 'chat_messages_message_fulltext');
                });
            }
        }

        if (! Schema::hasTable('chat_user_settings')) {
            Schema::create('chat_user_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->boolean('browser_notifications_enabled')->default(false);
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_user_settings');

        if (Schema::hasTable('chat_messages')) {
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                Schema::table('chat_messages', function (Blueprint $table) {
                    $table->dropFullText('chat_messages_message_fulltext');
                });
            }

            Schema::table('chat_messages', function (Blueprint $table) {
                $table->dropIndex('chat_messages_unread_lookup_index');
                $table->dropIndex('chat_messages_deleted_by_index');
                if (Schema::hasColumn('chat_messages', 'deleted_by_user_id')) {
                    $table->dropColumn('deleted_by_user_id');
                }
                if (Schema::hasColumn('chat_messages', 'delivered_at')) {
                    $table->dropColumn('delivered_at');
                }
            });
        }

        if (Schema::hasTable('chat_conversation_user_states')) {
            Schema::table('chat_conversation_user_states', function (Blueprint $table) {
                $table->dropIndex('chat_user_states_user_archived_index');
                $table->dropIndex('chat_user_states_conversation_read_index');
                if (Schema::hasColumn('chat_conversation_user_states', 'archived_at')) {
                    $table->dropColumn('archived_at');
                }
            });
        }
    }
};
