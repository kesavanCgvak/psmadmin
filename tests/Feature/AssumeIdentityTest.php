<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\SupportSession;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AssumeIdentityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);

        if (! Schema::hasTable('support_sessions')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_10_22_131500_create_support_sessions_table.php',
                '--force' => true,
            ]);
        }
    }

    public function test_authorized_admin_can_start_a_support_session_for_an_eligible_user(): void
    {
        $admin = $this->createPlatformAdmin();
        [$user, $company] = $this->createCompanyUser('Acme Staging');
        $logged = $this->captureLogs();

        $response = $this->beginSupportSession($admin, $user);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.read_only', true)
            ->assertJsonPath('data.assumed_user.id', $user->id)
            ->assertJsonPath('data.assumed_user.company', $company->name)
            ->assertJsonPath('data.assumed_user.account_type', $user->fresh()->account_type)
            ->assertJsonPath('data.assumed_user.company_profile.name', $company->name)
            ->assertJsonPath('data.assumed_user.company_profile.id', $company->id)
            ->assertJsonPath('data.administrator_id', $admin->id)
            ->assertJsonPath('data.token_type', 'Bearer');

        $token = $response->json('data.access_token');
        $this->assertIsString($token);
        $this->assertStringNotContainsString($user->password, $response->getContent());
        $this->assertStringNotContainsString($admin->password, $response->getContent());

        $payload = JWTAuth::setToken($token)->getPayload();
        $this->assertSame(SupportSession::TOKEN_USE, $payload->get(SupportSession::CLAIM_USE));
        $this->assertSame($user->id, (int) $payload->get('sub'));
        $this->assertSame($admin->id, (int) $payload->get(SupportSession::CLAIM_ADMIN));

        $session = SupportSession::query()->findOrFail($response->json('data.session_id'));
        $this->assertSame($admin->id, (int) $session->admin_user_id);
        $this->assertSame($user->id, (int) $session->assumed_user_id);
        $this->assertSame(SupportSession::STATUS_ACTIVE, $session->status);
        $this->assertSame((string) $payload->get('jti'), $session->jti);
        $this->assertTrue($session->expires_at->isFuture());

        if (Schema::hasTable('activity_logs')) {
            $log = ActivityLog::query()
                ->where('entity_type', ActivityLog::ENTITY_SUPPORT_SESSION)
                ->where('entity_id', $session->id)
                ->where('action', ActivityLog::ACTION_SUPPORT_STARTED)
                ->first();
            $this->assertNotNull($log);
            $this->assertSame($admin->id, (int) $log->user_id);
            $this->assertStringNotContainsString($token, json_encode($log->new_values));
        }

        foreach ($logged as $entry) {
            $this->assertStringNotContainsString($token, (string) $entry);
        }
    }

    public function test_users_listing_shows_assume_identity_only_to_platform_admins(): void
    {
        $admin = $this->createPlatformAdmin();
        [$user, $company] = $this->createCompanyUser('Visible Co');
        $companyAdmin = $this->createCompanyAdmin($company);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Assume Identity', false)
            ->assertSee('data-company-name="'.$company->name.'"', false)
            ->assertSee('assumeIdentityModal', false)
            ->assertSee('psm-support-ready', false)
            ->assertSee('assume-identity', false)
            ->assertSee('psm-support-grant', false)
            ->assertDontSee('access_token=', false);

        $this->actingAs($companyAdmin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertDontSee('js-assume-identity', false)
            ->assertDontSee('assumeIdentityModal', false);

        $this->assertNotSame($user->id, $companyAdmin->id);
    }

    public function test_unauthorized_admin_cannot_start_a_support_session(): void
    {
        $company = Company::create([
            'name' => 'Denied Co '.uniqid(),
            'account_type' => 'provider',
        ]);
        $companyAdmin = $this->createCompanyAdmin($company);
        [$user] = $this->createCompanyUser('Target Co');

        $this->beginSupportSession($companyAdmin, $user)
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs($this->createMarketplaceUser($company))
            ->postJson(route('admin.users.assume-identity', $user))
            ->assertForbidden();

        auth()->logout();

        $this->postJson(route('admin.users.assume-identity', $user))
            ->assertUnauthorized();
    }

    public function test_administrator_cannot_assume_their_own_identity(): void
    {
        $admin = $this->createPlatformAdmin();

        $this->beginSupportSession($admin, $admin)
            ->assertStatus(422)
            ->assertJsonPath('message', 'You cannot assume your own identity.');
    }

    public function test_missing_and_ineligible_users_are_rejected(): void
    {
        $admin = $this->createPlatformAdmin();
        $blocked = $this->createMarketplaceUser(
            Company::create(['name' => 'Blocked Co '.uniqid(), 'account_type' => 'user'])
        );
        $blocked->forceFill(['is_blocked' => true])->save();
        $otherAdmin = $this->createPlatformAdmin();

        $this->actingAs($admin)
            ->postJson(route('admin.users.assume-identity', 999999999))
            ->assertNotFound();

        $this->beginSupportSession($admin, $blocked)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This account is not eligible for support access.');

        $this->beginSupportSession($admin, $otherAdmin)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This account is not eligible for support access.');
    }

    public function test_support_token_is_not_an_unrestricted_user_credential(): void
    {
        $admin = $this->createPlatformAdmin();
        [$user] = $this->createCompanyUser('Restricted Co');
        $token = $this->tokenFrom($this->beginSupportSession($admin, $user));

        $this->withToken($token)
            ->postJson('/api/user/heartbeat')
            ->assertForbidden()
            ->assertJsonPath('message', 'This action is not permitted during a read-only support session.');

        $this->assertNull($user->fresh()->last_seen_at);

        $normal = JWTAuth::fromUser($user);
        $normalPayload = JWTAuth::setToken($normal)->getPayload();
        $this->assertNull($normalPayload->get(SupportSession::CLAIM_USE));
        $this->assertGreaterThan(45 * 60, $normalPayload->get('exp') - $normalPayload->get('iat'));

        $this->withToken($normal)->postJson('/api/user/heartbeat')->assertOk();
        $this->assertNotNull($user->fresh()->last_seen_at);

        $this->withToken($token)->postJson('/api/auth/refresh')->assertForbidden();
        $this->withToken($token)->getJson('/api/support-sessions/current')->assertOk();
    }

    public function test_read_requests_use_the_assumed_user_and_keep_company_boundaries(): void
    {
        $admin = $this->createPlatformAdmin();
        [$user, $company] = $this->createCompanyUser('Reader Co');
        [$otherUser, $otherCompany] = $this->createCompanyUser('Other Co');
        $token = $this->tokenFrom($this->beginSupportSession($admin, $user));

        $info = $this->withToken($token)->getJson('/api/company/info');
        $info->assertOk()
            ->assertJsonPath('data.name', $company->name);
        $this->assertStringNotContainsString($otherCompany->name, $info->getContent());

        $users = $this->withToken($token)->getJson('/api/company/users');
        $users->assertOk();
        $ids = collect($users->json('users'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($user->id, $ids);
        $this->assertNotContains($otherUser->id, $ids);

        $otherIds = collect(
            $this->withToken(JWTAuth::fromUser($otherUser))->getJson('/api/company/users')->assertOk()->json('users')
        )->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($otherUser->id, $otherIds);
        $this->assertNotContains($user->id, $otherIds);
    }

    public function test_prohibited_writes_and_side_effects_are_rejected(): void
    {
        $admin = $this->createPlatformAdmin();
        [$user, $company] = $this->createCompanyUser('Writer Co');
        $equipmentId = $this->createEquipment($company, $user, 5);
        $token = $this->tokenFrom($this->beginSupportSession($admin, $user));
        $before = User::query()->count();

        $this->withToken($token)
            ->putJson('/api/equipments/'.$equipmentId.'/quantity', ['quantity' => 1])
            ->assertForbidden();
        $this->assertSame(5, (int) DB::table('company_inventory')->where('id', $equipmentId)->value('quantity'));

        $this->withToken($token)
            ->postJson('/api/profile/change-password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertForbidden();
        $this->assertSame($user->password, $user->fresh()->password);

        $this->withToken($token)
            ->postJson('/api/company/users', [
                'username' => 'new_user_'.uniqid(),
                'email' => uniqid('new_', true).'@example.com',
                'first_name' => 'New',
                'last_name' => 'User',
                'mobile' => '555',
                'password' => 'secret12',
                'role' => 'user',
            ])
            ->assertForbidden();
        $this->assertSame($before, User::query()->count());

        $this->withToken($token)->postJson('/api/rental-requests', [])->assertForbidden();
        $this->withToken($token)->postJson('/api/subscriptions/cancel', [])->assertForbidden();
        $this->withToken($token)->postJson('/api/chat/conversations/1/messages', [
            'body' => 'hello',
        ])->assertForbidden();
        $this->withToken($token)->getJson('/api/provider/api-keys/1/reveal')->assertForbidden();
        $this->withToken($token)->getJson('/api/flex/search?keyword=speaker')->assertForbidden();
        $this->withToken($token)->postJson('/api/logout')->assertForbidden();
        $this->withToken($token)->getJson('/api/cache-clear')->assertForbidden();

        if (Schema::hasTable('activity_logs')) {
            $this->assertTrue(
                ActivityLog::query()
                    ->where('action', ActivityLog::ACTION_SUPPORT_BLOCKED)
                    ->where('entity_type', ActivityLog::ENTITY_SUPPORT_SESSION)
                    ->exists()
            );
        }
    }

    public function test_expired_revoked_and_reused_sessions_are_rejected(): void
    {
        $admin = $this->createPlatformAdmin();
        [$user] = $this->createCompanyUser('Expiry Co');
        $started = $this->beginSupportSession($admin, $user);
        $token = $this->tokenFrom($started);
        $session = SupportSession::query()->findOrFail($started->json('data.session_id'));

        $session->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->withToken($token)
            ->getJson('/api/support-sessions/current')
            ->assertUnauthorized();
        $this->assertSame(SupportSession::STATUS_EXPIRED, $session->fresh()->status);

        $replacement = $this->beginSupportSession($admin, $user);
        $replacementToken = $this->tokenFrom($replacement);
        $replacementSession = SupportSession::query()->findOrFail($replacement->json('data.session_id'));

        $this->actingAs($admin)
            ->postJson(route('admin.support-sessions.end', $replacementSession))
            ->assertOk()
            ->assertJsonPath('data.status', SupportSession::STATUS_ENDED);

        JWTAuth::setToken($replacementToken)->getPayload();
        $this->withToken($replacementToken)
            ->getJson('/api/company/info')
            ->assertUnauthorized();

        $third = $this->tokenFrom($this->beginSupportSession($admin, $user));
        $this->withToken($third)->postJson('/api/support-sessions/current/end')->assertOk();
        $this->withToken($third)->getJson('/api/support-sessions/current')->assertStatus(401);
    }

    public function test_original_admin_session_remains_valid_and_support_token_cannot_open_admin_routes(): void
    {
        $admin = $this->createPlatformAdmin();
        [$user] = $this->createCompanyUser('Session Co');
        $adminToken = JWTAuth::fromUser($admin);
        $supportToken = $this->tokenFrom($this->beginSupportSession($admin, $user));

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('User Management');

        $this->withToken($adminToken)->postJson('/api/user/heartbeat')->assertOk();

        auth()->logout();

        $this->withToken($supportToken)
            ->getJson('/api/admin/companies/1/referrals')
            ->assertUnauthorized();

        $otherAdmin = $this->createPlatformAdmin();
        $sessionId = SupportSession::query()->where('assumed_user_id', $user->id)->value('id');
        $this->actingAs($otherAdmin)
            ->postJson(route('admin.support-sessions.end', $sessionId))
            ->assertForbidden();
    }

    public function test_conflicting_session_for_the_same_user_is_rejected(): void
    {
        $first = $this->createPlatformAdmin();
        $second = $this->createPlatformAdmin();
        [$user] = $this->createCompanyUser('Conflict Co');

        $this->beginSupportSession($first, $user)->assertOk();

        $this->beginSupportSession($second, $user)
            ->assertStatus(409)
            ->assertJsonPath('message', 'This user already has an active support session.');
    }

    public function test_normal_token_refresh_does_not_become_a_support_token(): void
    {
        [$user] = $this->createCompanyUser('Refresh Co');
        $normal = JWTAuth::fromUser($user);

        $response = $this->withToken($normal)->postJson('/api/auth/refresh');
        $response->assertOk()->assertJsonStructure(['access_token']);

        $payload = JWTAuth::setToken($response->json('access_token'))->getPayload();
        $this->assertNull($payload->get(SupportSession::CLAIM_USE));
        $this->assertSame($user->id, (int) $payload->get('sub'));
    }

    public function test_support_session_start_is_rate_limited(): void
    {
        $admin = $this->createPlatformAdmin();
        [$user] = $this->createCompanyUser('Throttle Co');

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->beginSupportSession($admin, $user)->assertOk();
        }

        $this->beginSupportSession($admin, $user)->assertStatus(429);
    }

    private function beginSupportSession(User $admin, User $target)
    {
        return $this->actingAs($admin)->postJson(route('admin.users.assume-identity', $target));
    }

    private function tokenFrom($response): string
    {
        $token = $response->json('data.access_token');
        $this->assertIsString($token);

        return $token;
    }

    private function captureLogs(): \ArrayObject
    {
        $logged = new \ArrayObject();
        Log::listen(function ($message) use ($logged) {
            $logged->append($message->message.' '.json_encode($message->context));
        });

        return $logged;
    }

    private function createPlatformAdmin(): User
    {
        return User::create([
            'account_type' => 'provider',
            'username' => 'admin_'.uniqid(),
            'email' => uniqid('admin_', true).'@example.com',
            'password' => Hash::make('password'),
            'is_admin' => 1,
            'role' => 'super_admin',
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function createCompanyUser(string $companyName): array
    {
        $company = Company::create([
            'name' => $companyName.' '.uniqid(),
            'account_type' => 'provider',
        ]);

        return [$this->createMarketplaceUser($company), $company];
    }

    private function createCompanyAdmin(Company $company): User
    {
        return User::create([
            'account_type' => 'provider',
            'username' => 'coadmin_'.uniqid(),
            'email' => uniqid('coadmin_', true).'@example.com',
            'password' => Hash::make('password'),
            'company_id' => $company->id,
            'is_admin' => 1,
            'role' => 'admin',
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function createMarketplaceUser(Company $company): User
    {
        return User::create([
            'account_type' => 'provider',
            'username' => 'member_'.uniqid(),
            'email' => uniqid('member_', true).'@example.com',
            'password' => Hash::make('password'),
            'company_id' => $company->id,
            'is_admin' => 0,
            'role' => 'user',
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function createEquipment(Company $company, User $user, int $quantity): int
    {
        $productId = DB::table('inventory_master')->insertGetId([
            'model' => 'Model '.uniqid(),
            'is_verified' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('company_inventory')->insertGetId([
            'product_id' => $productId,
            'company_id' => $company->id,
            'user_id' => $user->id,
            'quantity' => $quantity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
