<?php

namespace Tests\Feature;

use App\Mail\ChatMessageReceivedMail;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatNotificationLog;
use App\Models\EmailLog;
use App\Services\ChatNotificationService;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\Concerns\InteractsWithChat;
use Tests\TestCase;

class ChatDirectEmailNotificationTest extends TestCase
{
    use InteractsWithChat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMinimalChatSchema();
        $this->ensureEmailLogsTable();
        config([
            'mail.default' => 'array',
            'mail.from.address' => 'noreply@psm.test',
            'mail.from.name' => 'PSM',
            'presence.online_status_timeout' => 120,
            'chat.notification_throttle_seconds' => 300,
            'app.frontend_url' => 'https://psm.com',
            'chat.conversation_path' => '#/chat?conversation={id}',
            'queue.default' => 'database',
        ]);
        $this->app->forgetInstance('mail.manager');
        $this->app->forgetInstance('mailer');
        $this->cleanupEmailLogs();
    }

    protected function tearDown(): void
    {
        $this->cleanupEmailLogs();

        parent::tearDown();
    }

    public function test_offline_user_email_is_sent_directly_and_logged_as_sent(): void
    {
        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $user1->profile->email = 'preferred-offline@example.com';
        $user1->profile->save();
        $this->setLastSeen($user1, null);
        $this->setChatNotificationPrefs($user1, ['email_notifications_enabled' => true]);

        EmailLog::create([
            'from_email' => 'noreply@psm.test',
            'to_email' => 'rental-customer@example.com',
            'subject' => 'Rental request received',
            'email_type' => 'rental request',
            'status' => EmailLog::STATUS_SENT,
        ]);

        $jobsBefore = Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0;
        $conversationId = $this->openConversation($userA, $companyB->id);

        $response = $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Speakers are ready for pickup',
            ]);

        $response->assertCreated();
        $messageId = (int) $response->json('data.id');

        $log = EmailLog::query()->where('to_email', 'preferred-offline@example.com')->first();
        $this->assertNotNull($log);
        $this->assertSame(1, EmailLog::query()->where('to_email', 'preferred-offline@example.com')->count());
        $this->assertSame(EmailLog::STATUS_SENT, $log->status);
        $this->assertNull($log->failure_reason);
        $this->assertSame('noreply@psm.test', $log->from_email);
        $this->assertSame('New message from Alice A at Company A', $log->subject);
        $this->assertSame(EmailLog::TYPE_CHAT_NOTIFICATION, $log->email_type);
        $this->assertSame(ChatMessageReceivedMail::class, $log->mail_class);
        $this->assertSame($messageId, (int) $log->reference_id);
        $this->assertSame((int) $user1->id, (int) $log->related_user_id);
        $this->assertNotNull($log->created_at);
        $this->assertSame('chat_message_received', $log->payload_snapshot['template'] ?? null);
        $this->assertSame($conversationId, (int) ($log->payload_snapshot['data']['conversation_id'] ?? 0));
        $this->assertStringContainsString('Speakers are ready for pickup', (string) ($log->payload_snapshot['html'] ?? ''));
        $this->assertCount(1, $this->transportMessages());
        $this->assertSame(
            ChatNotificationLog::STATUS_SENT,
            ChatNotificationLog::query()
                ->where('user_id', $user1->id)
                ->where('message_id', $messageId)
                ->where('channel', ChatNotificationLog::CHANNEL_EMAIL)
                ->value('status')
        );
        if (Schema::hasTable('jobs')) {
            $this->assertSame($jobsBefore, (int) DB::table('jobs')->count());
        }

        $this->actingAs($userA)
            ->get(route('admin.email-logs.index', ['search' => 'preferred-offline@example.com']))
            ->assertOk()
            ->assertSee('preferred-offline@example.com')
            ->assertSee('Chat Notification')
            ->assertSee('Sent');

        $this->actingAs($userA)
            ->get(route('admin.email-logs.index', ['email_type' => 'rental request']))
            ->assertOk()
            ->assertSee('rental-customer@example.com')
            ->assertSee('Rental request');

        $this->actingAs($userA)
            ->get(route('admin.email-logs.show', $log))
            ->assertOk()
            ->assertSee('preferred-offline@example.com')
            ->assertSee('noreply@psm.test')
            ->assertSee('New message from Alice A at Company A')
            ->assertSee('Chat Notification')
            ->assertSee('Sent')
            ->assertSee('chat_message_received')
            ->assertSee((string) $messageId);
    }

    public function test_smtp_failure_is_logged_as_failed_and_the_chat_message_stays_saved(): void
    {
        $this->failAllMail();

        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $this->setChatNotificationPrefs($user1, ['email_notifications_enabled' => true]);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'This email will be rejected',
            ])
            ->assertCreated()
            ->assertJsonPath('data.message', 'This email will be rejected');

        $this->assertSame(1, ChatMessage::query()->count());
        $this->assertSame('This email will be rejected', ChatMessage::query()->value('message'));

        $log = EmailLog::query()->where('to_email', $user1->email)->first();
        $this->assertNotNull($log);
        $this->assertSame(1, EmailLog::query()->where('to_email', $user1->email)->count());
        $this->assertSame(EmailLog::STATUS_FAILED, $log->status);
        $this->assertStringContainsString('SMTP connection refused', (string) $log->failure_reason);
        $this->assertSame(EmailLog::TYPE_CHAT_NOTIFICATION, $log->email_type);
        $this->assertSame(
            ChatNotificationLog::STATUS_FAILED,
            ChatNotificationLog::query()
                ->where('user_id', $user1->id)
                ->where('channel', ChatNotificationLog::CHANNEL_EMAIL)
                ->value('status')
        );
        $this->assertStringContainsString(
            'SMTP connection refused',
            (string) ChatNotificationLog::query()
                ->where('user_id', $user1->id)
                ->where('channel', ChatNotificationLog::CHANNEL_EMAIL)
                ->value('error_message')
        );

        $this->actingAs($userA)
            ->get(route('admin.email-logs.show', $log))
            ->assertOk()
            ->assertSee('Failed')
            ->assertSee('SMTP connection refused');
    }

    public function test_online_user_does_not_receive_an_email_or_an_email_log(): void
    {
        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $this->setLastSeen($user1, now());
        $this->setChatNotificationPrefs($user1, ['email_notifications_enabled' => true]);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'You are online',
            ])
            ->assertCreated();

        $this->assertSame(0, EmailLog::query()->where('to_email', $user1->email)->count());
        $this->assertSame(
            0,
            ChatNotificationLog::query()
                ->where('user_id', $user1->id)
                ->where('channel', ChatNotificationLog::CHANNEL_EMAIL)
                ->count()
        );
        $this->assertCount(0, $this->transportMessages());
    }

    public function test_disabled_invalid_and_missing_emails_are_not_sent(): void
    {
        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$disabled, $companyB, $invalid, $missing] = $this->createCompanyUsers(
            'Company B',
            'provider',
            ['Disabled User', 'Invalid User', 'Missing User']
        );
        $this->setChatNotificationPrefs($disabled, ['email_notifications_enabled' => false]);
        $this->setChatNotificationPrefs($invalid, [
            'email_notifications_enabled' => true,
            'email' => 'not-an-email',
        ]);
        $this->setChatNotificationPrefs($missing, [
            'email_notifications_enabled' => true,
            'email' => null,
        ]);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Nobody should be mailed',
            ])
            ->assertCreated();

        $this->assertSame(
            0,
            EmailLog::query()->whereIn('related_user_id', [$disabled->id, $invalid->id, $missing->id])->count()
        );
        $this->assertCount(0, $this->transportMessages());
        $this->assertSame(
            'email_disabled',
            ChatNotificationLog::query()->where('user_id', $disabled->id)->value('error_message')
        );
        $this->assertSame(
            ChatNotificationLog::STATUS_SKIPPED,
            ChatNotificationLog::query()->where('user_id', $invalid->id)->value('status')
        );
        $this->assertSame(
            'no_email',
            ChatNotificationLog::query()->where('user_id', $invalid->id)->value('error_message')
        );
        $this->assertSame(
            'no_email',
            ChatNotificationLog::query()->where('user_id', $missing->id)->value('error_message')
        );
    }

    public function test_blocked_user_is_excluded_and_another_offline_user_still_receives_email(): void
    {
        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$blocked, $companyB, $allowed] = $this->createCompanyUsers(
            'Company B',
            'provider',
            ['Blocked User', 'Allowed User']
        );
        $blocked->is_blocked = true;
        $blocked->save();
        $this->setChatNotificationPrefs($blocked, ['email_notifications_enabled' => true]);
        $this->setChatNotificationPrefs($allowed, ['email_notifications_enabled' => true]);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Only the allowed user',
            ])
            ->assertCreated();

        $this->assertSame(0, EmailLog::query()->where('to_email', $blocked->email)->count());
        $this->assertFalse(ChatNotificationLog::query()->where('user_id', $blocked->id)->exists());
        $this->assertSame(
            EmailLog::STATUS_SENT,
            EmailLog::query()->where('to_email', $allowed->email)->value('status')
        );
        $this->assertSame(1, EmailLog::query()->where('to_email', $allowed->email)->count());
    }

    public function test_throttle_and_duplicate_processing_send_one_email(): void
    {
        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $this->setChatNotificationPrefs($user1, ['email_notifications_enabled' => true]);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'First notice',
            ])
            ->assertCreated();

        $message = ChatMessage::query()->first();
        $conversation = ChatConversation::query()->findOrFail($conversationId);
        app(ChatNotificationService::class)->processMessage($message, $conversation, $userA->fresh(['profile']));

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Second notice immediately',
            ])
            ->assertCreated();

        $this->assertSame(1, EmailLog::query()->where('to_email', $user1->email)->count());
        $this->assertSame(EmailLog::STATUS_SENT, EmailLog::query()->where('to_email', $user1->email)->value('status'));
        $this->assertCount(1, $this->transportMessages());
        $this->assertTrue(
            ChatNotificationLog::query()
                ->where('user_id', $user1->id)
                ->where('channel', ChatNotificationLog::CHANNEL_EMAIL)
                ->where('status', ChatNotificationLog::STATUS_THROTTLED)
                ->exists()
        );
        $this->assertSame(
            1,
            ChatNotificationLog::query()
                ->where('user_id', $user1->id)
                ->where('channel', ChatNotificationLog::CHANNEL_EMAIL)
                ->where('status', ChatNotificationLog::STATUS_SENT)
                ->count()
        );
    }

    public function test_broadcast_failure_still_sends_and_logs_the_email(): void
    {
        $this->broadcastFailuresThrow(new BroadcastException('Pusher error: Reverb connection refused.'));

        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $this->setLastSeen($user1, null);
        $this->setChatNotificationPrefs($user1, ['email_notifications_enabled' => true]);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Broadcast failed but email should send',
            ])
            ->assertCreated();

        $this->assertGreaterThan(0, (int) $this->app->make('chat.test.broadcast_attempts'));
        $this->assertSame(1, ChatMessage::query()->count());
        $log = EmailLog::query()->where('to_email', $user1->email)->first();
        $this->assertNotNull($log);
        $this->assertSame(EmailLog::STATUS_SENT, $log->status);
        $this->assertStringContainsString(
            'Broadcast failed but email should send',
            (string) ($log->payload_snapshot['html'] ?? '')
        );
    }

    public function test_one_recipient_failure_does_not_block_the_other_recipient(): void
    {
        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB, $user2] = $this->createCompanyUsers(
            'Company B',
            'provider',
            ['User 1', 'User 2']
        );
        $this->setChatNotificationPrefs($user1, ['email_notifications_enabled' => true]);
        $this->setChatNotificationPrefs($user2, ['email_notifications_enabled' => true]);
        $this->failTransportFor($user1->email);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'One inbox is down',
            ])
            ->assertCreated();

        $this->assertSame(1, ChatMessage::query()->count());
        $failed = EmailLog::query()->where('to_email', $user1->email)->first();
        $sent = EmailLog::query()->where('to_email', $user2->email)->first();
        $this->assertNotNull($failed);
        $this->assertNotNull($sent);
        $this->assertSame(1, EmailLog::query()->where('to_email', $user1->email)->count());
        $this->assertSame(1, EmailLog::query()->where('to_email', $user2->email)->count());
        $this->assertSame(EmailLog::STATUS_FAILED, $failed->status);
        $this->assertStringContainsString('SMTP rejected', (string) $failed->failure_reason);
        $this->assertSame(EmailLog::STATUS_SENT, $sent->status);
        $this->assertNull($sent->failure_reason);
        $this->assertSame(
            ChatNotificationLog::STATUS_FAILED,
            ChatNotificationLog::query()->where('user_id', $user1->id)->where('channel', ChatNotificationLog::CHANNEL_EMAIL)->value('status')
        );
        $this->assertSame(
            ChatNotificationLog::STATUS_SENT,
            ChatNotificationLog::query()->where('user_id', $user2->id)->where('channel', ChatNotificationLog::CHANNEL_EMAIL)->value('status')
        );
    }

    private function cleanupEmailLogs(): void
    {
        if (! Schema::hasTable('email_logs')) {
            return;
        }

        EmailLog::query()->where('email_type', EmailLog::TYPE_CHAT_NOTIFICATION)->delete();
        EmailLog::query()->where('to_email', 'rental-customer@example.com')->delete();
    }

    private function ensureEmailLogsTable(): void
    {
        if (! Schema::hasTable('email_logs')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_03_19_100000_create_email_logs_table.php',
                '--force' => true,
            ]);
        }
    }

    private function failAllMail(): void
    {
        Mail::mailer()->setSymfonyTransport(new class implements TransportInterface
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException('SMTP connection refused');
            }

            public function __toString(): string
            {
                return 'always-fail';
            }
        });
    }

    private function failTransportFor(string $address): void
    {
        $inner = Mail::mailer()->getSymfonyTransport();

        Mail::mailer()->setSymfonyTransport(new class($inner, $address) implements TransportInterface
        {
            public function __construct(private TransportInterface $inner, private string $address) {}

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $envelope ??= Envelope::create($message);
                foreach ($envelope->getRecipients() as $recipient) {
                    if (strcasecmp($recipient->getAddress(), $this->address) === 0) {
                        throw new TransportException('SMTP rejected '.$this->address);
                    }
                }

                return $this->inner->send($message, $envelope);
            }

            public function __toString(): string
            {
                return 'selective-fail';
            }
        });
    }

    /**
     * @return list<SentMessage>
     */
    private function transportMessages(): array
    {
        $transport = Mail::mailer()->getSymfonyTransport();
        if (! method_exists($transport, 'messages')) {
            return [];
        }

        $messages = $transport->messages();

        return is_array($messages) ? $messages : $messages->all();
    }

    private function broadcastFailuresThrow(BroadcastException $exception): void
    {
        $this->app->instance('chat.test.broadcast_attempts', 0);
        config([
            'broadcasting.default' => 'failing-reverb',
            'broadcasting.connections.failing-reverb' => [
                'driver' => 'failing-reverb',
            ],
        ]);

        Broadcast::extend('failing-reverb', function () use ($exception) {
            return new class($exception) implements Broadcaster
            {
                public function __construct(private BroadcastException $exception) {}

                public function auth($request) {}

                public function validAuthenticationResponse($request, $result) {}

                public function broadcast(array $channels, $event, array $payload = [])
                {
                    app()->instance(
                        'chat.test.broadcast_attempts',
                        (int) app('chat.test.broadcast_attempts') + 1
                    );

                    throw $this->exception;
                }
            };
        });

        Broadcast::purge('failing-reverb');
        Broadcast::forgetDrivers();
    }
}
