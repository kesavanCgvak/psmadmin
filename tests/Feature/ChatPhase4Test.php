<?php

namespace Tests\Feature;

use App\Contracts\SmsProvider;
use App\Events\ChatMessageSent;
use App\Jobs\ProcessChatMessageNotificationsJob;
use App\Jobs\SendChatEmailNotificationJob;
use App\Jobs\SendChatSmsNotificationJob;
use App\Mail\ChatMessageReceivedMail;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatNotificationLog;
use App\Services\ChatNotificationService;
use App\Support\ChatUrls;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\InteractsWithChat;
use Tests\TestCase;

class ChatPhase4Test extends TestCase
{
    use InteractsWithChat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMinimalChatSchema();
        $this->createSmsLogsTable();
        config([
            'presence.online_status_timeout' => 120,
            'chat.notification_throttle_seconds' => 300,
            'app.frontend_url' => 'https://psm.com',
            'chat.conversation_path' => '#/chat?conversation={id}',
        ]);
    }

    public function test_online_user_does_not_receive_offline_email_or_sms(): void
    {
        Mail::fake();
        $this->mockSmsProvider();

        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $this->setLastSeen($user1, now());
        $this->setChatNotificationPrefs($user1, [
            'email_notifications_enabled' => true,
            'sms_notifications_enabled' => true,
            'mobile' => '4155552671',
        ]);

        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Are the speakers still available?',
            ])
            ->assertCreated();

        $this->assertSame(1, ChatMessage::count());
        Mail::assertNothingSent();
        $this->assertSame(0, ChatNotificationLog::query()->where('status', ChatNotificationLog::STATUS_SENT)->count());
    }

    public function test_offline_user_is_queued_email_when_enabled(): void
    {
        Queue::fake([SendChatEmailNotificationJob::class, SendChatSmsNotificationJob::class]);

        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $this->setChatNotificationPrefs($user1, ['email_notifications_enabled' => true]);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Need 4 JBL speakers tomorrow',
            ])
            ->assertCreated();

        Queue::assertPushed(SendChatEmailNotificationJob::class);
        Queue::assertNotPushed(SendChatSmsNotificationJob::class);
        $this->assertTrue(
            ChatNotificationLog::query()
                ->where('user_id', $user1->id)
                ->where('channel', ChatNotificationLog::CHANNEL_EMAIL)
                ->where('status', ChatNotificationLog::STATUS_PENDING)
                ->exists()
        );
    }

    public function test_offline_user_receives_email_with_conversation_link_and_preview(): void
    {
        Mail::fake();

        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Do you have 4 JBL speakers available?',
            ])
            ->assertCreated();

        Mail::assertSent(ChatMessageReceivedMail::class, function (ChatMessageReceivedMail $mail) use ($user1, $conversationId) {
            return $mail->hasTo($user1->email)
                && $mail->senderName === 'Alice A'
                && $mail->senderCompanyName === 'Company A'
                && $mail->preview === 'Do you have 4 JBL speakers available?'
                && $mail->conversationUrl === 'https://psm.com/#/chat?conversation='.$conversationId
                && $mail->envelope()->subject === 'New message from Alice A at Company A';
        });

        $this->assertSame(
            ChatNotificationLog::STATUS_SENT,
            ChatNotificationLog::query()
                ->where('user_id', $user1->id)
                ->where('channel', ChatNotificationLog::CHANNEL_EMAIL)
                ->value('status')
        );
    }

    public function test_sms_is_queued_only_when_enabled_and_consented(): void
    {
        Queue::fake([SendChatEmailNotificationJob::class, SendChatSmsNotificationJob::class]);
        $this->mockSmsProvider();

        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $this->setChatNotificationPrefs($user1, [
            'email_notifications_enabled' => false,
            'sms_notifications_enabled' => true,
            'mobile' => '4155552671',
        ]);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Can you confirm pickup?',
            ])
            ->assertCreated();

        Queue::assertPushed(SendChatSmsNotificationJob::class);
        Queue::assertNotPushed(SendChatEmailNotificationJob::class);
    }

    public function test_sms_is_not_sent_without_consent_or_valid_mobile(): void
    {
        $this->mockSmsProvider(expectSend: false);

        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB, $user2] = $this->createCompanyUsers('Company B', 'provider', ['User 1', 'User 2']);
        $this->setChatNotificationPrefs($user1, [
            'email_notifications_enabled' => false,
            'sms_notifications_enabled' => true,
            'sms_consented' => false,
            'mobile' => '4155552671',
        ]);
        $this->setChatNotificationPrefs($user2, [
            'email_notifications_enabled' => false,
            'sms_notifications_enabled' => true,
            'mobile' => '123',
        ]);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Following up',
            ])
            ->assertCreated();

        $this->assertSame(
            0,
            ChatNotificationLog::query()
                ->where('channel', ChatNotificationLog::CHANNEL_SMS)
                ->where('status', ChatNotificationLog::STATUS_SENT)
                ->count()
        );
    }

    public function test_mixed_online_and_offline_company_users_are_notified_independently(): void
    {
        Mail::fake();
        Queue::fake([SendChatSmsNotificationJob::class]);

        [$userA, $companyA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB, $user2] = $this->createCompanyUsers('Company B', 'provider', ['User 1', 'User 2']);
        $this->setLastSeen($user1, now());
        $this->setLastSeen($user2, null);
        $this->setChatNotificationPrefs($user1, ['email_notifications_enabled' => true]);
        $this->setChatNotificationPrefs($user2, ['email_notifications_enabled' => true]);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Quote for Friday',
            ])
            ->assertCreated();

        Mail::assertNotSent(ChatMessageReceivedMail::class, fn (ChatMessageReceivedMail $mail) => $mail->hasTo($user1->email));
        Mail::assertSent(ChatMessageReceivedMail::class, fn (ChatMessageReceivedMail $mail) => $mail->hasTo($user2->email));
        Mail::assertNotSent(ChatMessageReceivedMail::class, fn (ChatMessageReceivedMail $mail) => $mail->hasTo($userA->email));
        $this->assertSame((int) $companyA->id, (int) $userA->company_id);
    }

    public function test_preferences_and_missing_contact_details_are_respected(): void
    {
        Mail::fake();
        $this->mockSmsProvider(expectSend: false);

        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB, $user2, $user3] = $this->createCompanyUsers(
            'Company B',
            'provider',
            ['User 1', 'User 2', 'User 3']
        );

        $this->setChatNotificationPrefs($user1, ['email_notifications_enabled' => false]);
        $this->setChatNotificationPrefs($user2, [
            'email_notifications_enabled' => true,
            'email' => null,
        ]);
        $this->setChatNotificationPrefs($user3, [
            'email_notifications_enabled' => false,
            'sms_notifications_enabled' => false,
        ]);

        $conversationId = $this->openConversation($userA, $companyB->id);
        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Checking availability',
            ])
            ->assertCreated();

        Mail::assertNothingSent();

        $this->withToken($this->tokenFor($user1))
            ->getJson('/api/chat/notification-settings')
            ->assertOk()
            ->assertJsonPath('data.browser_notifications_enabled', false)
            ->assertJsonPath('data.email_notifications_enabled', false)
            ->assertJsonPath('data.sms_notifications_enabled', false)
            ->assertJsonPath('data.sms_consented', false);

        $this->withToken($this->tokenFor($user1))
            ->putJson('/api/chat/notification-settings', [
                'email_notifications_enabled' => true,
                'sms_notifications_enabled' => true,
                'browser_notifications_enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.email_notifications_enabled', true)
            ->assertJsonPath('data.sms_notifications_enabled', true)
            ->assertJsonPath('data.sms_consented', true)
            ->assertJsonPath('data.browser_notifications_enabled', true);

        $this->withToken($this->tokenFor($user1))
            ->getJson('/api/chat/realtime-config')
            ->assertOk()
            ->assertJsonPath('data.browser_notifications.enabled', true)
            ->assertJsonPath('data.email_notifications.enabled', true)
            ->assertJsonPath('data.sms_notifications.enabled', true)
            ->assertJsonPath('data.sms_notifications.consented', true);
    }

    public function test_sender_is_never_notified_about_own_message(): void
    {
        Mail::fake();

        [$userA, , $userB] = $this->createCompanyUsers('Company A', 'user', ['Alice A', 'Bob A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Sent from Alice',
            ])
            ->assertCreated();

        Mail::assertNotSent(ChatMessageReceivedMail::class, fn (ChatMessageReceivedMail $mail) => $mail->hasTo($userA->email));
        Mail::assertNotSent(ChatMessageReceivedMail::class, fn (ChatMessageReceivedMail $mail) => $mail->hasTo($userB->email));
        Mail::assertSent(ChatMessageReceivedMail::class, fn (ChatMessageReceivedMail $mail) => $mail->hasTo($user1->email));
        $this->assertFalse(
            ChatNotificationLog::query()->where('user_id', $userA->id)->exists()
        );
        $this->assertFalse(
            ChatNotificationLog::query()->where('user_id', $userB->id)->exists()
        );
    }

    public function test_unauthorized_user_cannot_generate_notifications_for_foreign_conversation(): void
    {
        Mail::fake();

        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        [$userC] = $this->createCompanyUsers('Company C', 'user', ['Carol C']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userC))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'I should not be here',
            ])
            ->assertStatus(403);

        $this->assertSame(0, ChatMessage::count());
        $this->assertSame(0, ChatNotificationLog::count());
        Mail::assertNothingSent();
    }

    public function test_notification_failure_does_not_fail_message_creation(): void
    {
        $this->mock(ChatNotificationService::class, function ($mock) {
            $mock->shouldReceive('processMessage')->andThrow(new \RuntimeException('SMTP down'));
        });

        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'This must still persist',
            ])
            ->assertCreated()
            ->assertJsonPath('data.message', 'This must still persist');

        $this->assertSame(1, ChatMessage::count());
        $this->assertSame('This must still persist', ChatMessage::first()->message);
    }

    public function test_sms_failure_does_not_fail_message_creation(): void
    {
        $this->mockSmsProvider(success: false);

        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $this->setChatNotificationPrefs($user1, [
            'email_notifications_enabled' => false,
            'sms_notifications_enabled' => true,
            'mobile' => '4155552671',
        ]);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'SMS will fail',
            ])
            ->assertCreated();

        $this->assertSame(1, ChatMessage::count());
    }

    public function test_duplicate_processing_does_not_send_a_second_email(): void
    {
        Mail::fake();

        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $response = $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Only one email please',
            ]);
        $response->assertCreated();
        $messageId = (int) $response->json('data.id');

        ProcessChatMessageNotificationsJob::dispatchSync($messageId, $conversationId, (int) $userA->id);

        Mail::assertSent(ChatMessageReceivedMail::class, 1);
        $this->assertSame(
            1,
            ChatNotificationLog::query()
                ->where('message_id', $messageId)
                ->where('user_id', $user1->id)
                ->where('channel', ChatNotificationLog::CHANNEL_EMAIL)
                ->count()
        );
    }

    public function test_rapid_messages_in_the_same_conversation_are_throttled(): void
    {
        Mail::fake();

        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'First',
            ])
            ->assertCreated();

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Second immediately after',
            ])
            ->assertCreated();

        Mail::assertSent(ChatMessageReceivedMail::class, 1);
        $this->assertTrue(
            ChatNotificationLog::query()
                ->where('user_id', $user1->id)
                ->where('channel', ChatNotificationLog::CHANNEL_EMAIL)
                ->where('status', ChatNotificationLog::STATUS_THROTTLED)
                ->exists()
        );
    }

    public function test_email_and_sms_jobs_retry_a_limited_number_of_times(): void
    {
        $emailJob = new SendChatEmailNotificationJob(1);
        $smsJob = new SendChatSmsNotificationJob(1);
        $fanout = new ProcessChatMessageNotificationsJob(1, 1, 1);

        $this->assertSame(3, $emailJob->tries);
        $this->assertSame(3, $smsJob->tries);
        $this->assertSame(3, $fanout->tries);
        $this->assertSame(0, $emailJob->backoff());
        $this->assertSame(0, $smsJob->backoff());
    }

    public function test_failed_email_job_marks_log_failed_without_retrying_forever(): void
    {
        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $conversationId = $this->openConversation($userA, $companyB->id);
        $message = ChatMessage::create([
            'conversation_id' => $conversationId,
            'sender_user_id' => $userA->id,
            'sender_company_id' => $userA->company_id,
            'message' => 'Will fail permanently',
            'message_type' => ChatMessage::TYPE_TEXT,
        ]);

        $log = ChatNotificationLog::create([
            'user_id' => $user1->id,
            'message_id' => $message->id,
            'conversation_id' => $conversationId,
            'channel' => ChatNotificationLog::CHANNEL_EMAIL,
            'status' => ChatNotificationLog::STATUS_PENDING,
        ]);

        $job = new SendChatEmailNotificationJob((int) $log->id);
        $job->failed(new \RuntimeException('permanent SMTP failure'));

        $this->assertSame(ChatNotificationLog::STATUS_FAILED, $log->fresh()->status);
        $this->assertSame('permanent SMTP failure', $log->fresh()->error_message);
    }

    public function test_message_broadcast_still_includes_browser_notification_payload(): void
    {
        Mail::fake();

        [$userA, $companyA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $conversationId = $this->openConversation($userA, $companyB->id);
        $message = ChatMessage::create([
            'conversation_id' => $conversationId,
            'sender_user_id' => $userA->id,
            'sender_company_id' => $companyA->id,
            'message' => 'Browser preview text',
            'message_type' => ChatMessage::TYPE_TEXT,
        ]);

        $event = new ChatMessageSent(
            message: $message,
            conversation: ChatConversation::find($conversationId)->load(['companyA', 'companyB']),
            sender: $userA->fresh(['profile']),
            senderCompanyId: (int) $companyA->id,
            recipientCompanyId: (int) $companyB->id,
            recipientUserIds: [(int) $user1->id],
        );

        $payload = $event->broadcastWith();
        $this->assertSame('Alice A', $payload['notification']['sender_name']);
        $this->assertSame('Company A', $payload['notification']['sender_company_name']);
        $this->assertSame('Browser preview text', $payload['notification']['body']);
        $this->assertSame('chat-'.$conversationId, $payload['notification']['tag']);
        $this->assertArrayNotHasKey('recipient_user_ids', $payload['notification']);
        $this->assertSame(
            'https://psm.com/#/chat?conversation='.$conversationId,
            ChatUrls::conversation((int) $conversationId)
        );
    }

    public function test_sms_body_does_not_include_the_chat_message(): void
    {
        $captured = null;
        $this->mockSmsProvider(onSend: function (string $to, string $text) use (&$captured) {
            $captured = $text;

            return ['success' => true, 'message_id' => 'SM123'];
        });

        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $this->setChatNotificationPrefs($user1, [
            'email_notifications_enabled' => false,
            'sms_notifications_enabled' => true,
            'mobile' => '4155552671',
        ]);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Secret quote amount is 12345',
            ])
            ->assertCreated();

        $this->assertNotNull($captured);
        $this->assertStringContainsString('Alice A', $captured);
        $this->assertStringContainsString('Company A', $captured);
        $this->assertStringNotContainsString('12345', $captured);
        $this->assertStringNotContainsString('Secret quote', $captured);
    }

    /**
     * @param  callable(string, string): array<string, mixed>|null  $onSend
     */
    private function mockSmsProvider(bool $expectSend = true, bool $success = true, ?callable $onSend = null): SmsProvider
    {
        $sms = Mockery::mock(SmsProvider::class);
        $sms->shouldReceive('isConfigured')->andReturn(true);
        $sms->shouldReceive('isValidMobile')->andReturnUsing(function (?string $mobile) {
            $digits = preg_replace('/\D/', '', (string) $mobile);

            return strlen($digits) >= 10;
        });

        if ($onSend) {
            $sms->shouldReceive('sendSms')->andReturnUsing($onSend);
        } elseif ($expectSend) {
            $sms->shouldReceive('sendSms')->andReturn(
                $success
                    ? ['success' => true, 'message_id' => 'SM123']
                    : ['success' => false, 'error' => 'Twilio 500']
            );
        } else {
            $sms->shouldReceive('sendSms')->never();
        }

        $this->app->instance(SmsProvider::class, $sms);

        return $sms;
    }
}
