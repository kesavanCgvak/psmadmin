<?php

namespace Tests\Feature;

use App\Jobs\ProcessChatMessageNotificationsJob;
use App\Jobs\SendChatEmailNotificationJob;
use App\Mail\ChatMessageReceivedMail;
use App\Models\ChatNotificationLog;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithChat;
use Tests\TestCase;

class ChatDatabaseQueueTest extends TestCase
{
    use InteractsWithChat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMinimalChatSchema();
        config([
            'presence.online_status_timeout' => 120,
            'chat.notification_throttle_seconds' => 300,
            'app.frontend_url' => 'https://psm.com',
            'chat.conversation_path' => '#/chat?conversation={id}',
            'queue.default' => 'database',
        ]);
        $this->ensureJobsTable();
        DB::table('jobs')->delete();
    }

    public function test_scheduler_registers_a_bounded_database_queue_worker(): void
    {
        $events = collect(app(Schedule::class)->events());
        $worker = $events->first(fn ($event) => str_contains((string) $event->command, 'queue:work'));

        $this->assertNotNull($worker);
        $this->assertStringContainsString('--queue=default', $worker->command);
        $this->assertStringContainsString('--stop-when-empty', $worker->command);
        $this->assertStringContainsString('--tries=3', $worker->command);
        $this->assertStringContainsString('--max-time=50', $worker->command);
        $this->assertTrue($worker->withoutOverlapping);
        $this->assertSame('* * * * *', $worker->expression);
        $this->assertFalse($worker->runInBackground);

        $commands = $events->map(fn ($event) => (string) $event->command)->implode("\n");
        $this->assertStringContainsString('supply-jobs:send-completion-reminders', $commands);
        $this->assertStringContainsString('supply-jobs:send-renter-rating-reminders', $commands);
        $this->assertStringContainsString('email-logs:mark-stale-failed', $commands);
        $this->assertStringContainsString('subscriptions:evaluate-trial-incentives', $commands);
    }

    public function test_database_cache_lock_supports_without_overlapping(): void
    {
        $this->ensureCacheLockTable();

        $store = Cache::store('database')->getStore();
        $this->assertTrue(method_exists($store, 'lock'));

        $first = $store->lock('chat-schedule-overlap', 10);
        $second = $store->lock('chat-schedule-overlap', 10);

        $this->assertTrue($first->get());
        $this->assertFalse($second->get());

        $first->release();
        $this->assertTrue($second->get());
        $second->release();
    }

    public function test_database_queue_sends_offline_email_after_reverb_broadcast_fails(): void
    {
        Mail::fake();
        $this->broadcastFailuresThrow(new BroadcastException('Pusher error: Reverb connection refused.'));

        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $this->setLastSeen($user1, null);
        $this->setChatNotificationPrefs($user1, ['email_notifications_enabled' => true]);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Offline email must still send',
            ])
            ->assertCreated();

        $this->assertGreaterThan(0, (int) $this->app->make('chat.test.broadcast_attempts'));
        Mail::assertSent(ChatMessageReceivedMail::class, function (ChatMessageReceivedMail $mail) use ($user1) {
            return $mail->hasTo($user1->email)
                && $mail->preview === 'Offline email must still send';
        });
        $this->assertSame(0, $this->queuedJobCount(ProcessChatMessageNotificationsJob::class));
        $this->assertSame(0, $this->queuedJobCount(SendChatEmailNotificationJob::class));
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(
            ChatNotificationLog::STATUS_SENT,
            ChatNotificationLog::query()
                ->where('user_id', $user1->id)
                ->where('channel', ChatNotificationLog::CHANNEL_EMAIL)
                ->value('status')
        );
    }

    public function test_database_queue_does_not_email_an_online_user(): void
    {
        Mail::fake();

        [$userA] = $this->createCompanyUsers('Company A', 'user', ['Alice A']);
        [$user1, $companyB] = $this->createCompanyUsers('Company B', 'provider', ['User 1']);
        $this->setLastSeen($user1, now());
        $this->setChatNotificationPrefs($user1, ['email_notifications_enabled' => true]);
        $conversationId = $this->openConversation($userA, $companyB->id);

        $this->withToken($this->tokenFor($userA))
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'message' => 'Online users stay on Reverb',
            ])
            ->assertCreated();

        $this->assertSame(0, $this->queuedJobCount(ProcessChatMessageNotificationsJob::class));
        $this->assertSame(0, $this->queuedJobCount(SendChatEmailNotificationJob::class));

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(
            0,
            ChatNotificationLog::query()
                ->where('user_id', $user1->id)
                ->where('channel', ChatNotificationLog::CHANNEL_EMAIL)
                ->where('status', ChatNotificationLog::STATUS_SENT)
                ->count()
        );
    }

    private function queuedJobCount(string $jobClass): int
    {
        return DB::table('jobs')
            ->where('payload', 'like', '%'.class_basename($jobClass).'%')
            ->count();
    }

    private function ensureJobsTable(): void
    {
        if (Schema::hasTable('jobs')) {
            return;
        }

        Schema::create('jobs', function ($table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    private function ensureCacheLockTable(): void
    {
        if (! Schema::hasTable('cache')) {
            Schema::create('cache', function ($table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }

        if (! Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function ($table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration');
            });
        }
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
