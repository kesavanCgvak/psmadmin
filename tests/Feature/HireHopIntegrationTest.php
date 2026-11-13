<?php

namespace Tests\Feature;

use App\Jobs\SyncRentmanEquipmentJob;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\User;
use App\Services\Integrations\CompanyIntegrationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class HireHopIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private const TOKEN = 'hirehop-live-token-9f3c1a7e-DO-NOT-LEAK';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'hirehop.base_url' => 'https://myhirehop.com',
            'hirehop.timeout' => 15,
            'hirehop.user_info_path' => '/php_functions/get_user_info.php',
        ]);
    }

    public function test_valid_hirehop_token_is_stored_encrypted_and_masked(): void
    {
        [$user, $company] = $this->createCompanyUser();
        Http::preventStrayRequests();
        Http::fake([
            'https://myhirehop.com/php_functions/get_user_info.php' => Http::response($this->hireHopUser(), 200),
        ]);

        $response = $this->withToken(JWTAuth::fromUser($user))->postJson('/api/integrations/store', [
            'integration_type' => 'hirehop',
            'api_key' => self::TOKEN,
            'api_base_url' => 'https://evil.example.test',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.integration_type', 'hirehop')
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.has_api_key', true)
            ->assertJsonPath('data.api_base_url', 'https://myhirehop.com')
            ->assertJsonPath('data.api_key_masked', str_repeat('*', strlen(self::TOKEN) - 4).'LEAK')
            ->assertJsonPath('data.hirehop_account.name', 'Greg Smith')
            ->assertJsonPath('data.hirehop_account.email', 'gsmith@secondwarehouse.com')
            ->assertJsonPath('data.hirehop_account.id', 1)
            ->assertJsonPath('data.hirehop_account.company_id', 13012)
            ->assertJsonPath('data.hirehop_account.default_depot', 1)
            ->assertJsonPath('data.hirehop_account.currency_code', 'USD')
            ->assertJsonPath('data.hirehop_account.currency_symbol', '$');

        $body = $response->getContent();
        $this->assertStringNotContainsString(self::TOKEN, $body);
        $this->assertStringNotContainsString('SUPERUSER', $body);
        $this->assertStringNotContainsString('IS_SUBSCRIBED', $body);
        $this->assertStringNotContainsString('EXTRA_SETTING', $body);
        $this->assertStringNotContainsString('US Dollar', $body);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && $request->url() === 'https://myhirehop.com/php_functions/get_user_info.php'
                && $request->hasHeader('X-TOKEN', self::TOKEN)
                && $request->body() === '';
        });

        $integration = CompanyIntegration::query()->where('company_id', $company->id)->first();
        $this->assertNotNull($integration);
        $this->assertSame('hirehop', $integration->integration_type);
        $this->assertTrue($integration->isConnected());
        $this->assertSame(self::TOKEN, $integration->api_key);

        $rawKey = (string) DB::table('company_integrations')
            ->where('id', $integration->id)
            ->value('api_key');
        $this->assertNotSame(self::TOKEN, $rawKey);
        $this->assertStringNotContainsString(self::TOKEN, $rawKey);

        $show = $this->withToken(JWTAuth::fromUser($user))->getJson('/api/integrations/hirehop');
        $show->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.has_api_key', true)
            ->assertJsonPath('data.api_key_masked', str_repeat('*', strlen(self::TOKEN) - 4).'LEAK');
        $this->assertArrayNotHasKey('hirehop_account', $show->json('data'));
        $this->assertStringNotContainsString(self::TOKEN, $show->getContent());

        if (Schema::hasTable('activity_logs')) {
            $audit = ActivityLog::query()->where('entity_id', $integration->id)->get()->toJson();
            $this->assertStringNotContainsString(self::TOKEN, $audit);
        }
    }

    public function test_invalid_hirehop_token_is_rejected_and_not_stored(): void
    {
        [$user, $company] = $this->createCompanyUser();
        $logged = $this->captureLogs();
        Http::preventStrayRequests();
        Http::fake([
            'https://myhirehop.com/*' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        $response = $this->withToken(JWTAuth::fromUser($user))->postJson('/api/integrations/store', [
            'integration_type' => 'hirehop',
            'api_key' => self::TOKEN,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid HireHop API token.')
            ->assertJsonPath('error_code', 'INVALID_API_TOKEN');

        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertSame(0, CompanyIntegration::query()->where('company_id', $company->id)->count());
        $this->assertTokenIsAbsent($logged, self::TOKEN);
    }

    public function test_numeric_hirehop_error_is_returned_without_saving(): void
    {
        [$user, $company] = $this->createCompanyUser();
        Http::preventStrayRequests();
        Http::fake([
            'https://myhirehop.com/*' => Http::response(['error' => 3], 200),
        ]);

        $response = $this->withToken(JWTAuth::fromUser($user))->postJson('/api/integrations/store', [
            'integration_type' => 'hirehop',
            'api_key' => self::TOKEN,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'HIREHOP_ERROR')
            ->assertJsonPath('message', 'HireHop rejected the API token (error 3: Missing parameters).');

        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertSame(0, CompanyIntegration::query()->where('company_id', $company->id)->count());
    }

    public function test_unknown_numeric_hirehop_error_has_no_invented_description(): void
    {
        [$user] = $this->createCompanyUser();
        Http::preventStrayRequests();
        Http::fake([
            'https://myhirehop.com/*' => Http::response(['error' => 4242], 200),
        ]);

        $this->withToken(JWTAuth::fromUser($user))->postJson('/api/integrations/store', [
            'integration_type' => 'hirehop',
            'api_key' => self::TOKEN,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'HireHop rejected the API token (error 4242).')
            ->assertJsonPath('error_code', 'HIREHOP_ERROR');
    }

    public function test_text_hirehop_error_is_returned_without_the_token(): void
    {
        [$user, $company] = $this->createCompanyUser();
        $logged = $this->captureLogs();
        Http::preventStrayRequests();
        Http::fake([
            'https://myhirehop.com/*' => Http::response([
                'error' => 'Token expired <b>'.self::TOKEN.'</b>',
            ], 200),
        ]);

        $response = $this->withToken(JWTAuth::fromUser($user))->postJson('/api/integrations/store', [
            'integration_type' => 'hirehop',
            'api_key' => self::TOKEN,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'HIREHOP_ERROR')
            ->assertJsonPath('message', 'HireHop rejected the API token: Token expired [redacted]');

        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertSame(0, CompanyIntegration::query()->where('company_id', $company->id)->count());
        $this->assertTokenIsAbsent($logged, self::TOKEN);
    }

    public function test_hirehop_http_error_does_not_save_credentials(): void
    {
        [$user, $company] = $this->createCompanyUser();
        Http::preventStrayRequests();
        Http::fake([
            'https://myhirehop.com/*' => Http::response('unavailable '.self::TOKEN, 503),
        ]);

        $response = $this->withToken(JWTAuth::fromUser($user))->postJson('/api/integrations/store', [
            'integration_type' => 'hirehop',
            'api_key' => self::TOKEN,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Unable to validate HireHop integration credentials.')
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');

        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertSame(0, CompanyIntegration::query()->where('company_id', $company->id)->count());
    }

    public function test_invalid_hirehop_json_is_rejected(): void
    {
        [$user, $company] = $this->createCompanyUser();
        Http::preventStrayRequests();
        Http::fake([
            'https://myhirehop.com/*' => Http::response('not-json '.self::TOKEN, 200),
        ]);

        $response = $this->withToken(JWTAuth::fromUser($user))->postJson('/api/integrations/store', [
            'integration_type' => 'hirehop',
            'api_key' => self::TOKEN,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'HireHop returned an unexpected response.')
            ->assertJsonPath('error_code', 'INVALID_RESPONSE');

        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertSame(0, CompanyIntegration::query()->where('company_id', $company->id)->count());
    }

    public function test_hirehop_timeout_does_not_save_credentials_or_log_the_token(): void
    {
        [$user, $company] = $this->createCompanyUser();
        $logged = $this->captureLogs();
        Http::preventStrayRequests();
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: timed out for '.self::TOKEN);
        });

        $response = $this->withToken(JWTAuth::fromUser($user))->postJson('/api/integrations/store', [
            'integration_type' => 'hirehop',
            'api_key' => self::TOKEN,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'HireHop validation failed due to network/timeout issue.')
            ->assertJsonPath('error_code', 'NETWORK_TIMEOUT');

        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertSame(0, CompanyIntegration::query()->where('company_id', $company->id)->count());
        $this->assertTokenIsAbsent($logged, self::TOKEN);
    }

    public function test_replacing_a_hirehop_token_keeps_one_active_integration(): void
    {
        [$user, $company] = $this->createCompanyUser();
        Http::preventStrayRequests();
        Http::fake([
            'https://myhirehop.com/*' => Http::response($this->hireHopUser(), 200),
            'https://flex.example.test/*' => Http::response(['ok' => true], 200),
        ]);

        $this->withToken(JWTAuth::fromUser($user))->postJson('/api/integrations/store', [
            'integration_type' => 'flex',
            'api_base_url' => 'https://flex.example.test',
            'api_key' => 'flex-key-unchanged-1111',
        ])->assertOk();

        $first = $this->withToken(JWTAuth::fromUser($user))->postJson('/api/integrations/store', [
            'integration_type' => 'hirehop',
            'api_key' => self::TOKEN,
        ]);
        $first->assertOk()->assertJsonPath('data.integration_type', 'hirehop');

        $replacement = 'hirehop-replacement-token-9999';
        $second = $this->withToken(JWTAuth::fromUser($user))->postJson('/api/integrations/store', [
            'integration_type' => 'hirehop',
            'api_key' => $replacement,
        ]);
        $second->assertOk()
            ->assertJsonPath('data.api_key_masked', str_repeat('*', strlen($replacement) - 4).'9999');

        $this->assertSame(1, CompanyIntegration::query()->where('company_id', $company->id)->count());
        $active = CompanyIntegration::query()->where('company_id', $company->id)->first();
        $this->assertSame('hirehop', $active->integration_type);
        $this->assertSame($replacement, $active->api_key);
        $this->assertSame(1, CompanyIntegration::withTrashed()->where('company_id', $company->id)->where('integration_type', 'flex')->count());
        $this->assertNotNull(CompanyIntegration::withTrashed()->where('company_id', $company->id)->where('integration_type', 'flex')->first()?->deleted_at);
        $this->assertStringNotContainsString(self::TOKEN, $second->getContent());
        $this->assertStringNotContainsString($replacement, $second->getContent());
    }

    public function test_existing_flex_and_rentman_integration_behavior_is_unchanged(): void
    {
        $flexOnly = new CompanyIntegration([
            'integration_type' => 'flex',
            'api_key' => 'flex-key',
        ]);
        $this->assertFalse($flexOnly->isConnected());
        $flexOnly->api_base_url = 'https://flex.example.test';
        $this->assertTrue($flexOnly->isConnected());

        $rentman = new CompanyIntegration([
            'integration_type' => 'rentman',
            'api_key' => 'rentman-key',
        ]);
        $this->assertTrue($rentman->isConnected());

        $hirehop = new CompanyIntegration([
            'integration_type' => 'hirehop',
            'api_key' => 'hirehop-key',
        ]);
        $this->assertTrue($hirehop->isConnected());

        $other = new CompanyIntegration([
            'integration_type' => 'qb',
            'api_key' => 'qb-key',
        ]);
        $this->assertFalse($other->isConnected());

        $this->assertSame(['flex', 'rentman', 'hirehop'], CompanyIntegrationService::supportedTypes());

        [$user] = $this->createCompanyUser();
        config(['services.rentman.base_url' => 'https://rentman.example.test']);
        Http::preventStrayRequests();
        Http::fake([
            'https://flex.example.test/*' => Http::sequence()
                ->push(['ok' => true], 401)
                ->push(['ok' => true], 200),
            'https://rentman.example.test/*' => Http::sequence()
                ->push(['ok' => true], 401)
                ->push(['data' => []], 200),
        ]);

        $flex = $this->withToken(JWTAuth::fromUser($user))->postJson('/api/integrations/store', [
            'integration_type' => 'flex',
            'api_base_url' => 'https://flex.example.test',
            'api_key' => 'flex-secret-key-2222',
        ]);
        $flex->assertStatus(422)
            ->assertJsonPath('message', 'Invalid Flex API key.')
            ->assertJsonPath('error_code', 'INVALID_API_KEY');
        $this->assertArrayNotHasKey('hirehop_account', $flex->json());

        $flexSaved = $this->withToken(JWTAuth::fromUser($user))->postJson('/api/integrations/store', [
            'integration_type' => 'flex',
            'api_base_url' => 'https://flex.example.test',
            'api_key' => 'flex-secret-key-2222',
        ]);
        $flexSaved->assertOk();
        $this->assertSame([
            'integration_type',
            'api_base_url',
            'connected',
            'has_api_key',
            'api_key_masked',
            'last_fetched_at',
            'last_synced_at',
        ], array_keys($flexSaved->json('data')));
        $this->assertSame('flex', $flexSaved->json('data.integration_type'));
        $this->assertStringNotContainsString('flex-secret-key-2222', $flexSaved->getContent());

        $rentmanResponse = $this->withToken(JWTAuth::fromUser($user))->postJson('/api/integrations/store', [
            'integration_type' => 'rentman',
            'api_key' => 'rentman-secret-key-3333',
        ]);
        $rentmanResponse->assertStatus(422)
            ->assertJsonPath('message', 'Invalid Rentman auth token.')
            ->assertJsonPath('error_code', 'INVALID_AUTH_TOKEN');

        Bus::fake();
        $rentmanSaved = $this->withToken(JWTAuth::fromUser($user))->postJson('/api/integrations/store', [
            'integration_type' => 'rentman',
            'api_key' => 'rentman-secret-key-3333',
            'api_base_url' => 'https://ignored.example.test',
        ]);
        $rentmanSaved->assertOk();
        $this->assertSame([
            'integration_type',
            'api_base_url',
            'connected',
            'has_api_key',
            'api_key_masked',
            'last_fetched_at',
            'last_synced_at',
        ], array_keys($rentmanSaved->json('data')));
        $this->assertSame('rentman', $rentmanSaved->json('data.integration_type'));
        $this->assertSame('https://rentman.example.test', $rentmanSaved->json('data.api_base_url'));
        $this->assertStringNotContainsString('rentman-secret-key-3333', $rentmanSaved->getContent());
        Bus::assertDispatched(SyncRentmanEquipmentJob::class);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'https://rentman.example.test/equipment')
                && $request->hasHeader('Authorization', 'Bearer rentman-secret-key-3333');
        });
    }

    public function test_missing_hirehop_base_url_fails_before_a_request(): void
    {
        [$user, $company] = $this->createCompanyUser();
        config(['hirehop.base_url' => '']);
        Http::preventStrayRequests();
        Http::fake();

        $this->withToken(JWTAuth::fromUser($user))->postJson('/api/integrations/store', [
            'integration_type' => 'hirehop',
            'api_key' => self::TOKEN,
        ])->assertStatus(500)
            ->assertJsonPath('error_code', 'MISSING_BASE_URL');

        Http::assertNothingSent();
        $this->assertSame(0, CompanyIntegration::query()->where('company_id', $company->id)->count());
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function createCompanyUser(): array
    {
        $company = Company::create([
            'name' => 'HireHop Co '.uniqid(),
            'account_type' => 'provider',
        ]);

        $user = User::create([
            'account_type' => 'provider',
            'username' => 'hirehop_'.uniqid(),
            'email' => uniqid('hirehop_', true).'@example.com',
            'password' => 'password',
            'company_id' => $company->id,
            'is_admin' => 0,
            'role' => 'user',
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);

        return [$user, $company];
    }

    /**
     * @return array<string, mixed>
     */
    private function hireHopUser(): array
    {
        return [
            'NAME' => 'Greg Smith',
            'EMAIL' => 'gsmith@secondwarehouse.com',
            'ID' => 1,
            'DEFAULT_DEPOT' => 1,
            'COMPANY_ID' => 13012,
            'CURRENCY' => [
                'CODE' => 'USD',
                'NAME' => 'US Dollar',
                'SYMBOL' => '$',
                'DECIMALS' => 2,
            ],
            'SUPERUSER' => 1,
            'IS_SUBSCRIBED' => 1,
            'EXTRA_SETTING' => 'internal',
        ];
    }

    private function captureLogs(): \ArrayObject
    {
        $logged = new \ArrayObject;
        Log::listen(function (MessageLogged $event) use ($logged) {
            $logged->append($event->message.' '.json_encode($event->context));
        });

        return $logged;
    }

    private function assertTokenIsAbsent(\ArrayObject $logged, string $token): void
    {
        foreach ($logged as $entry) {
            $this->assertStringNotContainsString($token, $entry);
        }
    }
}
