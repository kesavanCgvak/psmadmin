<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user email/SMS chat preferences and a lightweight notification log.
     */
    public function up(): void
    {
        if (Schema::hasTable('chat_user_settings')) {
            Schema::table('chat_user_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('chat_user_settings', 'email_notifications_enabled')) {
                    $table->boolean('email_notifications_enabled')->default(true)->after('browser_notifications_enabled');
                }
                if (! Schema::hasColumn('chat_user_settings', 'sms_notifications_enabled')) {
                    $table->boolean('sms_notifications_enabled')->default(false)->after('email_notifications_enabled');
                }
                if (! Schema::hasColumn('chat_user_settings', 'sms_consented_at')) {
                    $table->timestamp('sms_consented_at')->nullable()->after('sms_notifications_enabled');
                }
            });
        }

        if (! Schema::hasTable('chat_notification_logs')) {
            Schema::create('chat_notification_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('message_id');
                $table->unsignedBigInteger('conversation_id');
                $table->string('channel', 16);
                $table->string('status', 16)->default('pending');
                $table->string('error_message', 500)->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'message_id', 'channel'], 'chat_notification_logs_identity_unique');
                $table->index(['conversation_id', 'user_id', 'channel', 'created_at'], 'chat_notification_logs_throttle_index');
                $table->index(['status', 'channel'], 'chat_notification_logs_status_channel_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_notification_logs');

        if (Schema::hasTable('chat_user_settings')) {
            Schema::table('chat_user_settings', function (Blueprint $table) {
                if (Schema::hasColumn('chat_user_settings', 'sms_consented_at')) {
                    $table->dropColumn('sms_consented_at');
                }
                if (Schema::hasColumn('chat_user_settings', 'sms_notifications_enabled')) {
                    $table->dropColumn('sms_notifications_enabled');
                }
                if (Schema::hasColumn('chat_user_settings', 'email_notifications_enabled')) {
                    $table->dropColumn('email_notifications_enabled');
                }
            });
        }
    }
};
