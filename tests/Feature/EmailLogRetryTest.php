<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\User;
use App\Services\EmailLogRetryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Tests\TestCase;

class EmailLogRetryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'array']);
        $this->app->forgetInstance('mail.manager');
        $this->app->forgetInstance('mailer');

        if (! Schema::hasTable('email_logs')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_03_19_100000_create_email_logs_table.php',
                '--force' => true,
            ]);
        }
    }

    public function test_failed_email_is_resent_with_the_original_content_and_marked_sent_only_after_acceptance(): void
    {
        $admin = $this->admin();

        Mail::send([], [], function ($message) {
            $message->to('provider@example.com')
                ->cc('ops@example.com')
                ->subject('Quote for stage lights')
                ->html('<p>secret-marker-xyz</p>')
                ->text('secret-marker-xyz');
            $message->attachData('hire-track-row', 'request.txt', ['mime' => 'text/plain']);
        });

        $log = EmailLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame(EmailLog::STATUS_SENT, $log->status);
        $this->assertSame('provider@example.com', $log->to_email);
        $this->assertStringContainsString('secret-marker-xyz', (string) $log->payload_snapshot['html']);
        $this->assertSame('request.txt', $log->payload_snapshot['attachments'][0]['filename']);
        $this->assertSame('hire-track-row', base64_decode((string) $log->payload_snapshot['attachments'][0]['content'], true));
        $this->assertCount(1, $this->transportMessages());

        $log->update([
            'status' => EmailLog::STATUS_FAILED,
            'failure_reason' => 'SMTP connection timed out',
        ]);

        $show = $this->actingAs($admin)->get(route('admin.email-logs.show', $log));
        $show->assertOk();
        $show->assertSee('Retry Email');
        $show->assertDontSee('secret-marker-xyz', false);

        $index = $this->actingAs($admin)->get(route('admin.email-logs.index'));
        $index->assertOk();
        $index->assertSee(route('admin.email-logs.retry', $log), false);

        $response = $this->actingAs($admin)
            ->from(route('admin.email-logs.show', $log))
            ->postJson(route('admin.email-logs.retry', $log));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', EmailLog::STATUS_SENT)
            ->assertJsonPath('retry_count', 1)
            ->assertJsonPath('message', EmailLogRetryService::SUBMITTED_MESSAGE);

        $log->refresh();
        $this->assertSame(1, EmailLog::query()->count());
        $this->assertSame(EmailLog::STATUS_SENT, $log->status);
        $this->assertNull($log->failure_reason);
        $this->assertFalse($log->retryMeta()['in_progress']);
        $this->assertSame(1, $log->retryCount());

        $messages = $this->transportMessages();
        $this->assertCount(2, $messages);
        $resent = $messages[1]->getOriginalMessage();
        $this->assertSame('provider@example.com', $resent->getTo()[0]->getAddress());
        $this->assertSame('ops@example.com', $resent->getCc()[0]->getAddress());
        $this->assertSame('Quote for stage lights', $resent->getSubject());
        $this->assertStringContainsString('secret-marker-xyz', (string) $resent->getHtmlBody());
        $this->assertSame('hire-track-row', $resent->getAttachments()[0]->getBody());

        $this->actingAs($admin)
            ->postJson(route('admin.email-logs.retry', $log))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Sent emails cannot be retried.');

        $this->assertCount(2, $this->transportMessages());
    }

    public function test_pending_email_can_be_retried_only_after_the_original_attempt_is_no_longer_in_flight(): void
    {
        $admin = $this->admin();
        $log = $this->logWithContent(EmailLog::STATUS_PENDING);

        $blocked = $this->actingAs($admin)->postJson(route('admin.email-logs.retry', $log));
        $blocked->assertStatus(409)
            ->assertJsonPath('message', 'This email is still being processed and cannot be retried yet.');

        $log->refresh();
        $this->assertSame(EmailLog::STATUS_PENDING, $log->status);
        $this->assertSame(0, $log->retryCount());
        $this->assertCount(0, $this->transportMessages());

        EmailLog::query()->whereKey($log->id)->update([
            'updated_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.email-logs.retry', $log))
            ->assertOk()
            ->assertJsonPath('status', EmailLog::STATUS_SENT);

        $log->refresh();
        $this->assertSame(EmailLog::STATUS_SENT, $log->status);
        $this->assertSame(1, $log->retryCount());
        $this->assertCount(1, $this->transportMessages());
    }

    public function test_retry_in_progress_and_stale_lock(): void
    {
        $admin = $this->admin();
        $log = $this->logWithContent(EmailLog::STATUS_FAILED, [
            'retry' => [
                'count' => 1,
                'in_progress' => true,
                'started_at' => now()->toIso8601String(),
                'last_attempt_at' => now()->toIso8601String(),
            ],
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.email-logs.retry', $log))
            ->assertStatus(409)
            ->assertJsonPath('message', 'A retry is already in progress for this email.');

        $this->assertCount(0, $this->transportMessages());
        $this->assertSame(1, $log->fresh()->retryCount());

        $snapshot = $log->payload_snapshot;
        $snapshot['retry']['started_at'] = now()->subMinutes(10)->toIso8601String();
        $log->update(['payload_snapshot' => $snapshot]);

        $this->actingAs($admin)
            ->postJson(route('admin.email-logs.retry', $log))
            ->assertOk()
            ->assertJsonPath('retry_count', 2);

        $log->refresh();
        $this->assertSame(EmailLog::STATUS_SENT, $log->status);
        $this->assertFalse($log->retryMeta()['in_progress']);
    }

    public function test_transport_error_keeps_the_log_failed_and_records_the_error(): void
    {
        $admin = $this->admin();
        $log = $this->logWithContent(EmailLog::STATUS_FAILED);

        Event::listen(MessageSending::class, function () {
            throw new RuntimeException('SMTP connection refused');
        });

        $response = $this->actingAs($admin)->postJson(route('admin.email-logs.retry', $log));

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', EmailLog::STATUS_FAILED);

        $log->refresh();
        $this->assertSame(EmailLog::STATUS_FAILED, $log->status);
        $this->assertStringContainsString('SMTP connection refused', (string) $log->failure_reason);
        $this->assertFalse($log->retryMeta()['in_progress']);
        $this->assertSame(1, $log->retryCount());
        $this->assertSame(1, EmailLog::query()->count());
    }

    public function test_log_without_stored_content_cannot_be_retried(): void
    {
        $admin = $this->admin();
        $log = EmailLog::create([
            'to_email' => 'old@example.com',
            'subject' => 'Legacy pending email',
            'status' => EmailLog::STATUS_FAILED,
            'failure_reason' => 'Marked as failed: delivery may have failed',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.email-logs.retry', $log))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This email cannot be retried because its message content was not stored.');

        $this->assertSame(EmailLog::STATUS_FAILED, $log->fresh()->status);
        $this->assertCount(0, $this->transportMessages());
    }

    public function test_sent_emails_do_not_show_a_retry_action_and_pending_in_flight_is_disabled(): void
    {
        $admin = $this->admin();
        $sent = $this->logWithContent(EmailLog::STATUS_SENT);
        $pending = $this->logWithContent(EmailLog::STATUS_PENDING);

        $index = $this->actingAs($admin)->get(route('admin.email-logs.index'));
        $index->assertOk();
        $index->assertDontSee(route('admin.email-logs.retry', $sent), false);
        $index->assertDontSee(route('admin.email-logs.retry', $pending), false);
        $index->assertSee('This email is still being processed and cannot be retried yet.', false);

        EmailLog::query()->whereKey($pending->id)->update([
            'status' => EmailLog::STATUS_FAILED,
            'updated_at' => now()->subHour(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.email-logs.index'))
            ->assertOk()
            ->assertSee(route('admin.email-logs.retry', $pending), false)
            ->assertDontSee(route('admin.email-logs.retry', $sent), false);
    }

    public function test_retry_is_limited_to_authenticated_admins(): void
    {
        $log = $this->logWithContent(EmailLog::STATUS_FAILED);

        $this->postJson(route('admin.email-logs.retry', $log))
            ->assertUnauthorized();

        $user = User::create([
            'account_type' => 'provider',
            'username' => 'member_'.uniqid(),
            'email' => uniqid('member_', true).'@example.com',
            'password' => 'password',
            'is_admin' => 0,
            'role' => 'user',
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson(route('admin.email-logs.retry', $log))
            ->assertForbidden();

        $this->assertSame(EmailLog::STATUS_FAILED, $log->fresh()->status);
        $this->assertCount(0, $this->transportMessages());
    }

    public function test_form_retry_refreshes_the_log_page_with_a_success_notification(): void
    {
        $admin = $this->admin();
        $log = $this->logWithContent(EmailLog::STATUS_FAILED);

        $this->actingAs($admin)
            ->from(route('admin.email-logs.show', $log))
            ->post(route('admin.email-logs.retry', $log))
            ->assertRedirect(route('admin.email-logs.show', $log))
            ->assertSessionHas('success', EmailLogRetryService::SUBMITTED_MESSAGE);

        $this->actingAs($admin)
            ->get(route('admin.email-logs.show', $log))
            ->assertOk()
            ->assertSee(EmailLogRetryService::SUBMITTED_MESSAGE, false)
            ->assertSee('Sent');
    }

    private function admin(): User
    {
        return User::create([
            'account_type' => 'provider',
            'username' => 'email_admin_'.uniqid(),
            'email' => uniqid('email_admin_', true).'@example.com',
            'password' => 'password',
            'is_admin' => 1,
            'role' => 'super_admin',
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extraSnapshot
     */
    private function logWithContent(string $status, array $extraSnapshot = []): EmailLog
    {
        return EmailLog::create([
            'from_email' => 'noreply@example.com',
            'to_email' => 'customer@example.com',
            'subject' => 'Rental request '.$status,
            'email_type' => 'rental request',
            'status' => $status,
            'payload_snapshot' => array_merge([
                'to' => ['customer@example.com'],
                'cc' => [],
                'bcc' => [],
                'reply_to' => [],
                'html' => '<p>Stored body for '.$status.'</p>',
                'text' => 'Stored body for '.$status,
                'attachments' => [],
            ], $extraSnapshot),
        ]);
    }

    /**
     * @return list<SentMessage>
     */
    private function transportMessages(): array
    {
        $transport = Mail::getSymfonyTransport();
        $this->assertTrue(method_exists($transport, 'messages'), 'The test mailer must use the array transport.');

        $messages = $transport->messages();

        if ($messages instanceof Collection) {
            return $messages->all();
        }

        return array_values($messages);
    }
}
