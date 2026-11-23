<?php

namespace Tests\Feature;

use App\Contracts\SmsProvider;
use App\Jobs\SendSupplierSmsJob;
use App\Models\Company;
use App\Models\RentalJob;
use App\Models\SmsLog;
use App\Models\SupplyJob;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\SupplierSmsNotifier;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupplierSmsNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
        Carbon::setTestNow(Carbon::parse('2026-11-20 15:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::disconnect('sms_lock');
        parent::tearDown();
    }

    public function test_rental_request_created_within_the_seven_day_window_sends_sms(): void
    {
        $sms = $this->mockSms();
        $sms->shouldReceive('sendSms')->once()->andReturn($this->accepted('SM-window'));

        $scenario = $this->scenario('2026-11-27', '+16155551234');

        $this->assertTrue(SupplierSmsNotifier::notifyIfNeeded(
            $scenario['supply'],
            $scenario['rental'],
            $scenario['renter']
        ));

        $log = SmsLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame(SmsLog::STATUS_SENT, $log->status);
        $this->assertSame('SM-window', $log->provider_message_id);
        $this->assertSame('queued', $log->provider_response['status'] ?? null);
        $this->assertStringContainsString(
            'from "'.$scenario['renter']->company->name.'" starting on "11/27/2026" and titled "Festival Weekend"',
            $log->message
        );
    }

    public function test_rental_request_more_than_seven_days_out_is_logged_as_skipped(): void
    {
        $sms = $this->mockSms();
        $sms->shouldReceive('sendSms')->never();

        $scenario = $this->scenario('2026-11-28', '+16155551234');

        $this->assertFalse(SupplierSmsNotifier::notifyIfNeeded(
            $scenario['supply'],
            $scenario['rental'],
            $scenario['renter']
        ));
        SupplierSmsNotifier::notifyIfNeeded($scenario['supply'], $scenario['rental'], $scenario['renter']);

        $this->assertSame(1, SmsLog::query()->count());
        $log = SmsLog::query()->first();
        $this->assertSame(SmsLog::STATUS_FAILED, $log->status);
        $this->assertSame(SmsLog::SKIP_OUTSIDE_WINDOW, $log->error_message);
        $this->assertTrue($log->isOutsideWindowSkip());
    }

    public function test_previously_skipped_rental_is_sent_by_the_scheduled_command(): void
    {
        $sms = $this->mockSms();
        $sms->shouldReceive('sendSms')->once()->andReturn($this->accepted('SM-later'));

        Carbon::setTestNow(Carbon::parse('2026-10-30 09:00:00', 'UTC'));
        $scenario = $this->scenario('2026-11-26', '+16155551234');
        $this->assertFalse(SupplierSmsNotifier::notifyIfNeeded(
            $scenario['supply'],
            $scenario['rental'],
            $scenario['renter']
        ));
        $this->assertTrue(SmsLog::query()->first()->isOutsideWindowSkip());

        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'UTC'));

        $this->artisan('sms:send-eligible-supplier-notifications')->assertSuccessful();

        $this->assertSame(1, SmsLog::query()->where('status', SmsLog::STATUS_SENT)->count());
        $this->assertSame(1, SmsLog::query()->where('error_message', SmsLog::SKIP_OUTSIDE_WINDOW)->count());
        $this->assertSame('SM-later', SmsLog::query()->where('status', SmsLog::STATUS_SENT)->value('provider_message_id'));
    }

    public function test_past_start_date_does_not_receive_sms(): void
    {
        $sms = $this->mockSms();
        $sms->shouldReceive('sendSms')->never();

        $scenario = $this->scenario('2026-11-19', '+16155551234');
        $this->assertFalse(SupplierSmsNotifier::notifyIfNeeded(
            $scenario['supply'],
            $scenario['rental'],
            $scenario['renter']
        ));

        $this->artisan('sms:send-eligible-supplier-notifications')->assertSuccessful();

        $this->assertSame(1, SmsLog::query()->count());
        $this->assertSame(SmsLog::SKIP_PAST, SmsLog::query()->value('error_message'));
    }

    public function test_missing_supplier_mobile_is_logged_as_skipped(): void
    {
        $sms = $this->mockSms();
        $sms->shouldReceive('sendSms')->never();

        $scenario = $this->scenario('2026-11-22', '');
        SupplierSmsNotifier::notifyIfNeeded($scenario['supply'], $scenario['rental'], $scenario['renter']);
        SupplierSmsNotifier::notifyIfNeeded($scenario['supply'], $scenario['rental'], $scenario['renter']);

        $this->assertSame(1, SmsLog::query()->count());
        $this->assertSame(SmsLog::SKIP_MOBILE_MISSING, SmsLog::query()->value('error_message'));
    }

    public function test_missing_supplier_contact_is_logged_as_skipped(): void
    {
        $sms = $this->mockSms();
        $sms->shouldReceive('sendSms')->never();

        $scenario = $this->scenario('2026-11-22', null, false);
        SupplierSmsNotifier::notifyIfNeeded($scenario['supply'], $scenario['rental'], $scenario['renter']);

        $this->assertSame(SmsLog::SKIP_CONTACT_MISSING, SmsLog::query()->value('error_message'));
    }

    public function test_invalid_mobile_number_is_logged_as_skipped(): void
    {
        $sms = $this->mockSms();
        $sms->shouldReceive('sendSms')->never();

        $scenario = $this->scenario('2026-11-22', '12345');
        SupplierSmsNotifier::notifyIfNeeded($scenario['supply'], $scenario['rental'], $scenario['renter']);

        $this->assertSame(SmsLog::SKIP_MOBILE_INVALID, SmsLog::query()->value('error_message'));
        $this->assertSame('12345', SmsLog::query()->value('phone_number'));
    }

    public function test_missing_supplier_company_is_logged_once(): void
    {
        $sms = $this->mockSms();
        $sms->shouldReceive('sendSms')->never();

        $scenario = $this->scenario('2026-11-22', '+16155551234');
        $scenario['supply']->provider_id = 999999;
        $scenario['supply']->save();

        SupplierSmsNotifier::notifyIfNeeded($scenario['supply'], $scenario['rental'], $scenario['renter']);
        SupplierSmsNotifier::notifyIfNeeded($scenario['supply'], $scenario['rental'], $scenario['renter']);

        $this->assertSame(1, SmsLog::query()->count());
        $this->assertStringContainsString(SmsLog::SKIP_COMPANY_MISSING, (string) SmsLog::query()->value('error_message'));
    }

    public function test_existing_successful_sms_prevents_duplicate_sending(): void
    {
        $sms = $this->mockSms();
        $sms->shouldReceive('sendSms')->once()->andReturn($this->accepted('SM-once'));

        $scenario = $this->scenario('2026-11-22', '+16155551234');
        SupplierSmsNotifier::notifyIfNeeded($scenario['supply'], $scenario['rental'], $scenario['renter']);
        $this->assertFalse(SupplierSmsNotifier::notifyIfNeeded(
            $scenario['supply'],
            $scenario['rental'],
            $scenario['renter']
        ));

        $this->assertSame(1, SmsLog::query()->where('status', SmsLog::STATUS_SENT)->count());
    }

    public function test_failed_twilio_request_is_retried(): void
    {
        $this->useDatabaseQueue();

        $calls = 0;
        $sms = $this->mockSms();
        $sms->shouldReceive('sendSms')->twice()->andReturnUsing(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return [
                    'success' => false,
                    'error' => 'cURL error 6: Could not resolve host: api.twilio.com for https://api.twilio.com/2010-04-01/Accounts/AC0123456789abcdef0123456789abcdef/Messages.json',
                    'response' => ['status' => 503, 'body' => ['message' => 'unavailable']],
                ];
            }

            return $this->accepted('SM-retry');
        });

        $scenario = $this->scenario('2026-11-22', '+16155551234');
        $this->assertTrue(SupplierSmsNotifier::notifyIfNeeded(
            $scenario['supply'],
            $scenario['rental'],
            $scenario['renter']
        ));
        $this->assertSame(SmsLog::STATUS_PENDING, SmsLog::query()->value('status'));
        $this->assertSame(1, DB::table('jobs')->count());

        $this->artisan('queue:work', ['--once' => true, '--queue' => 'default', '--tries' => 3]);

        $failedAttempt = SmsLog::query()->first();
        $this->assertSame(SmsLog::STATUS_PENDING, $failedAttempt->status);
        $this->assertSame(1, $failedAttempt->attempts);
        $this->assertStringNotContainsString('AC0123456789abcdef0123456789abcdef', (string) $failedAttempt->error_message);
        $this->assertStringContainsString('Accounts/[redacted]', (string) $failedAttempt->error_message);
        $this->assertSame(1, DB::table('jobs')->count());

        $this->artisan('queue:work', ['--once' => true, '--queue' => 'default', '--tries' => 3]);

        $sent = SmsLog::query()->first();
        $this->assertSame(SmsLog::STATUS_SENT, $sent->status);
        $this->assertSame('SM-retry', $sent->provider_message_id);
        $this->assertNull($sent->error_message);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, SmsLog::query()->count());
    }

    public function test_repeated_scheduled_command_does_not_send_duplicate_sms(): void
    {
        $sms = $this->mockSms();
        $sms->shouldReceive('sendSms')->once()->andReturn($this->accepted('SM-daily'));

        $scenario = $this->scenario('2026-11-22', '+16155551234');

        $this->artisan('sms:send-eligible-supplier-notifications')->assertSuccessful();
        $this->artisan('sms:send-eligible-supplier-notifications')->assertSuccessful();
        SupplierSmsNotifier::notifyIfNeeded($scenario['supply'], $scenario['rental'], $scenario['renter']);

        $this->assertSame(1, SmsLog::query()->where('status', SmsLog::STATUS_SENT)->count());
    }

    public function test_concurrent_execution_does_not_send_duplicate_sms(): void
    {
        config(['services.sms.lock_wait_seconds' => 1]);
        config(['database.connections.sms_lock' => config('database.connections.mysql')]);
        DB::purge('sms_lock');

        $calls = 0;
        $sms = $this->mockSms();
        $sms->shouldReceive('sendSms')->andReturnUsing(function () use (&$calls) {
            $calls++;

            return $this->accepted('SM-lock-'.$calls);
        });

        $scenario = $this->scenario('2026-11-22', '+16155551234');

        $holder = DB::connection('sms_lock');
        $holder->beginTransaction();
        $holder->table('supply_jobs')->where('id', $scenario['supply']->id)->lockForUpdate()->first();

        $started = microtime(true);
        $this->assertFalse(SupplierSmsNotifier::notifyIfNeeded(
            $scenario['supply'],
            $scenario['rental'],
            $scenario['renter']
        ));
        $this->assertGreaterThanOrEqual(0.8, microtime(true) - $started);
        $this->assertSame(0, $calls);
        $this->assertSame(0, SmsLog::query()->where('status', SmsLog::STATUS_SENT)->count());

        $holder->rollBack();

        $this->assertTrue(SupplierSmsNotifier::notifyIfNeeded(
            $scenario['supply'],
            $scenario['rental'],
            $scenario['renter']
        ));
        $this->assertSame(1, $calls);
        $this->assertSame(1, SmsLog::query()->where('status', SmsLog::STATUS_SENT)->count());
    }

    public function test_supplier_sms_does_not_change_email_delivery(): void
    {
        Mail::fake();
        $sms = $this->mockSms();
        $sms->shouldReceive('sendSms')->once()->andReturn($this->accepted('SM-mail'));

        $emailCount = Schema::hasTable('email_logs') ? DB::table('email_logs')->count() : null;
        $scenario = $this->scenario('2026-11-22', '+16155551234');
        SupplierSmsNotifier::notifyIfNeeded($scenario['supply'], $scenario['rental'], $scenario['renter']);

        Mail::assertNothingSent();
        if ($emailCount !== null) {
            $this->assertSame($emailCount, DB::table('email_logs')->count());
        }

        Mail::to('ops@example.com')->send(new SupplierSmsMailProbe());
        Mail::assertSent(SupplierSmsMailProbe::class, function (SupplierSmsMailProbe $mail) {
            return $mail->hasTo('ops@example.com')
                && $mail->envelope()->subject === 'Quote mail still works';
        });
    }

    public function test_chat_sms_notification_behaviour_remains_unchanged(): void
    {
        $job = (string) file_get_contents(app_path('Jobs/SendChatSmsNotificationJob.php'));
        $service = (string) file_get_contents(app_path('Services/ChatNotificationService.php'));

        $this->assertStringContainsString('sms_notifications_enabled', $job);
        $this->assertStringContainsString('sms_consented_at', $job);
        $this->assertStringContainsString("'sms_disabled'", $service);
        $this->assertStringNotContainsString('SupplierSmsNotifier', $job);
        $this->assertStringNotContainsString('SendSupplierSmsJob', $service);
    }

    public function test_twilio_21610_is_marked_permanently_failed(): void
    {
        $sms = $this->mockSms();
        $sms->shouldReceive('sendSms')->once()->andReturn($this->unsubscribedResponse());

        $scenario = $this->scenario('2026-11-22', '+16155551234');
        SupplierSmsNotifier::notifyIfNeeded($scenario['supply'], $scenario['rental'], $scenario['renter']);

        $log = SmsLog::query()->first();
        $this->assertSame(SmsLog::STATUS_FAILED, $log->status);
        $this->assertTrue($log->isRecipientUnsubscribed());
        $this->assertSame(1, $log->attempts);
        $this->assertStringContainsString('Permanent: '.SmsLog::FAILURE_RECIPIENT_UNSUBSCRIBED, $log->error_message);
    }

    public function test_twilio_21610_is_not_retried(): void
    {
        $this->useDatabaseQueue();
        $sms = $this->mockSms();
        $sms->shouldReceive('sendSms')->once()->andReturn($this->unsubscribedResponse());

        $scenario = $this->scenario('2026-11-22', '+16155551234');
        $this->assertTrue(SupplierSmsNotifier::notifyIfNeeded(
            $scenario['supply'],
            $scenario['rental'],
            $scenario['renter']
        ));
        $this->assertSame(1, DB::table('jobs')->count());

        $this->artisan('queue:work', ['--once' => true, '--queue' => 'default', '--tries' => 3]);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(SmsLog::STATUS_FAILED, SmsLog::query()->value('status'));
        $this->assertSame(1, SmsLog::query()->value('attempts'));
    }

    public function test_twilio_21610_stores_the_error_code_and_message(): void
    {
        $sms = $this->mockSms();
        $sms->shouldReceive('sendSms')->once()->andReturn($this->unsubscribedResponse());

        $scenario = $this->scenario('2026-11-22', '+16155551234');
        SupplierSmsNotifier::notifyIfNeeded($scenario['supply'], $scenario['rental'], $scenario['renter']);

        $log = SmsLog::query()->first();
        $this->assertSame(21610, $log->provider_response['body']['code'] ?? null);
        $this->assertSame('Attempt to send to unsubscribed recipient', $log->provider_response['body']['message'] ?? null);
        $this->assertStringContainsString('Twilio 21610: Attempt to send to unsubscribed recipient', $log->error_message);
    }

    public function test_scheduled_command_does_not_retry_an_unsubscribed_recipient(): void
    {
        $sms = $this->mockSms();
        $sms->shouldReceive('sendSms')->once()->andReturn($this->unsubscribedResponse());

        $scenario = $this->scenario('2026-11-22', '+16155551234');
        SupplierSmsNotifier::notifyIfNeeded($scenario['supply'], $scenario['rental'], $scenario['renter']);

        $this->artisan('sms:send-eligible-supplier-notifications')->assertSuccessful();
        $this->artisan('sms:send-eligible-supplier-notifications')->assertSuccessful();

        $this->assertSame(1, SmsLog::query()->count());
        $this->assertTrue(SmsLog::query()->first()->isRecipientUnsubscribed());
    }

    public function test_admin_sms_log_shows_the_unsubscribed_failure_reason(): void
    {
        $sms = $this->mockSms();
        $sms->shouldReceive('sendSms')->once()->andReturn($this->unsubscribedResponse());

        $scenario = $this->scenario('2026-11-22', '+16155551234');
        SupplierSmsNotifier::notifyIfNeeded($scenario['supply'], $scenario['rental'], $scenario['renter']);

        $html = view('admin.sms-logs.partials.unsubscribed', [
            'smsLog' => SmsLog::query()->first(),
        ])->render();

        $this->assertStringContainsString('Failure reason: Recipient unsubscribed', $html);
        $this->assertStringContainsString('Twilio error code: 21610', $html);
        $this->assertStringContainsString('Twilio error message: Attempt to send to unsubscribed recipient', $html);
    }

    public function test_scheduler_registers_the_supplier_sms_command(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'sms:send-eligible-supplier-notifications'));

        $this->assertNotNull($event);
        $this->assertSame('15 0 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(3, (new SendSupplierSmsJob(1, 'Request', '2026-11-22', 1, null))->tries());
        $this->assertSame(0, (new SendSupplierSmsJob(1, 'Request', '2026-11-22', 1, null))->backoff());
    }

    /**
     * @return array{renter: User, rental: RentalJob, supply: SupplyJob}
     */
    private function scenario(string $fromDate, ?string $mobile, bool $withContact = true): array
    {
        $renterCompany = Company::query()->create([
            'name' => 'North Star Rentals '.uniqid(),
            'account_type' => 'user',
        ]);
        $renter = User::query()->create([
            'account_type' => 'user',
            'username' => 'renter_'.uniqid(),
            'email' => uniqid('renter_', true).'@example.com',
            'password' => Hash::make('password'),
            'company_id' => $renterCompany->id,
            'role' => 'user',
            'email_verified' => true,
        ]);
        UserProfile::query()->create([
            'user_id' => $renter->id,
            'first_name' => 'North',
            'last_name' => 'Star',
            'email' => $renter->email,
        ]);

        $supplier = Company::query()->create([
            'name' => 'South Gear '.uniqid(),
            'account_type' => 'provider',
        ]);

        if ($withContact) {
            $contact = User::query()->create([
                'account_type' => 'provider',
                'username' => 'supplier_'.uniqid(),
                'email' => uniqid('supplier_', true).'@example.com',
                'password' => Hash::make('password'),
                'company_id' => $supplier->id,
                'role' => 'user',
                'email_verified' => true,
                'is_company_default_contact' => true,
            ]);
            UserProfile::query()->create([
                'user_id' => $contact->id,
                'first_name' => 'Sam',
                'last_name' => 'Supplier',
                'email' => $contact->email,
                'mobile' => $mobile,
            ]);
            $supplier->default_contact_id = $contact->id;
            $supplier->save();
        }

        $rental = RentalJob::query()->create([
            'user_id' => $renter->id,
            'name' => 'Festival Weekend',
            'from_date' => $fromDate,
            'to_date' => Carbon::parse($fromDate)->addDay()->toDateString(),
            'status' => 'open',
        ]);
        $supply = SupplyJob::query()->create([
            'rental_job_id' => $rental->id,
            'provider_id' => $supplier->id,
            'status' => 'open',
        ]);

        return [
            'renter' => $renter->fresh(['company', 'profile']),
            'rental' => $rental->fresh(),
            'supply' => $supply->fresh(),
        ];
    }

    private function mockSms(): SmsProvider
    {
        $sms = \Mockery::mock(SmsProvider::class);
        $sms->shouldReceive('isConfigured')->andReturn(true)->byDefault();
        $sms->shouldReceive('isValidMobile')->andReturnUsing(function (?string $mobile) {
            return strlen((string) preg_replace('/\D/', '', (string) $mobile)) >= 10;
        })->byDefault();

        $this->app->forgetInstance(SmsProvider::class);
        $this->app->instance(SmsProvider::class, $sms);

        return $sms;
    }

    /**
     * @return array{success: bool, error: string, response: array{status: int, body: array{code: int, message: string, status: int}}}
     */
    private function unsubscribedResponse(): array
    {
        $body = [
            'code' => 21610,
            'message' => 'Attempt to send to unsubscribed recipient',
            'status' => 400,
        ];

        return [
            'success' => false,
            'error' => json_encode($body),
            'response' => ['status' => 400, 'body' => $body],
        ];
    }

    /**
     * @return array{success: bool, message_id: string, response: array{sid: string, status: string}}
     */
    private function accepted(string $sid): array
    {
        return [
            'success' => true,
            'message_id' => $sid,
            'response' => ['sid' => $sid, 'status' => 'queued'],
        ];
    }

    private function useDatabaseQueue(): void
    {
        config(['queue.default' => 'database']);
        $this->app->forgetInstance('queue');

        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        DB::table('jobs')->delete();
    }

    private function createSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('sms_logs');
        Schema::dropIfExists('supply_jobs');
        Schema::dropIfExists('rental_jobs');
        Schema::dropIfExists('user_profiles');
        Schema::dropIfExists('users');
        Schema::dropIfExists('companies');
        Schema::enableForeignKeyConstraints();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('account_type')->nullable();
            $table->unsignedBigInteger('default_contact_id')->nullable();
            $table->unsignedBigInteger('date_format_id')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('account_type')->nullable();
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->boolean('email_verified')->default(false);
            $table->string('password')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->boolean('is_company_default_contact')->default(false);
            $table->string('role')->nullable();
            $table->timestamps();
        });

        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('full_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile')->nullable();
            $table->timestamps();
        });

        Schema::create('rental_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name')->nullable();
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('supply_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rental_job_id')->nullable();
            $table->unsignedBigInteger('provider_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('status')->default('pending');
            $table->text('message');
            $table->string('recipient_name')->nullable();
            $table->string('phone_number')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('company_name')->nullable();
            $table->string('contact_person_name')->nullable();
            $table->string('contact_person_mobile')->nullable();
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('sent_by')->nullable();
            $table->text('error_message')->nullable();
            $table->json('provider_response')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }
}

class SupplierSmsMailProbe extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Quote mail still works');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Quote mail still works</p>');
    }
}
