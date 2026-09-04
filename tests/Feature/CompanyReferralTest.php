<?php

namespace Tests\Feature;

use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Models\CompanyReferral;
use App\Models\ReferralLink;
use App\Models\User;
use App\Services\ReferralService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CompanyReferralTest extends TestCase
{
    private ReferralService $referralService;

    protected function setUp(): void
    {
        parent::setUp();

        // Full app migrations are not reliable in sqlite testing for this project.
        // Build only the schema needed for referral behavior.
        $this->createMinimalSchema();
        $this->referralService = app(ReferralService::class);

        config(['app.url' => 'https://psm.com']);
        putenv('APP_FRONTEND_URL=https://psm.com');
        $_ENV['APP_FRONTEND_URL'] = 'https://psm.com';
        $_SERVER['APP_FRONTEND_URL'] = 'https://psm.com';
    }

    public function test_generate_referral_link_for_company(): void
    {
        [$user, $company] = $this->createCompanyWithUser('ABC Productions');

        $response = $this->withToken($this->tokenFor($user))
            ->postJson('/api/referrals');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => ['referral_code', 'referral_url'],
            ]);

        $code = $response->json('data.referral_code');
        $this->assertNotEmpty($code);
        $this->assertSame('https://psm.com/register?ref='.$code, $response->json('data.referral_url'));
        $this->assertDatabaseHas('referral_links', [
            'company_id' => $company->id,
            'referral_code' => $code,
            'status' => ReferralLink::STATUS_ACTIVE,
        ]);
    }

    public function test_existing_company_receives_same_referral_link_when_requested_again(): void
    {
        [$user, $company] = $this->createCompanyWithUser('ABC Productions');

        $first = $this->withToken($this->tokenFor($user))->postJson('/api/referrals');
        $second = $this->withToken($this->tokenFor($user))->postJson('/api/referrals');

        $first->assertOk();
        $second->assertOk();
        $this->assertSame($first->json('data.referral_code'), $second->json('data.referral_code'));
        $this->assertSame($first->json('data.referral_url'), $second->json('data.referral_url'));
        $this->assertSame(1, ReferralLink::where('company_id', $company->id)->count());
    }

    public function test_referral_code_is_unique(): void
    {
        [, $companyA] = $this->createCompanyWithUser('Company A');
        [, $companyB] = $this->createCompanyWithUser('Company B');

        $linkA = $this->referralService->getOrCreateActiveLink($companyA);
        $linkB = $this->referralService->getOrCreateActiveLink($companyB);

        $this->assertNotSame($linkA->referral_code, $linkB->referral_code);
        $this->assertSame(2, ReferralLink::query()->distinct('referral_code')->count('referral_code'));
    }

    public function test_multiple_companies_can_use_same_referral_code(): void
    {
        [, $referrer] = $this->createCompanyWithUser('ABC Productions');
        $link = $this->referralService->getOrCreateActiveLink($referrer);

        [, $companyA] = $this->createCompanyWithUser('Company A');
        [, $companyB] = $this->createCompanyWithUser('Company B');
        [, $companyC] = $this->createCompanyWithUser('Company C');

        $this->referralService->createReferralForRegistration($link->referral_code, $companyA);
        $this->referralService->createReferralForRegistration($link->referral_code, $companyB);
        $this->referralService->createReferralForRegistration($link->referral_code, $companyC);

        $this->assertSame(1, ReferralLink::where('referral_code', $link->referral_code)->count());
        $this->assertSame(3, CompanyReferral::where('referral_link_id', $link->id)->count());
        $this->assertTrue($link->fresh()->isActive());
    }

    public function test_each_registration_creates_separate_referral_record(): void
    {
        [, $referrer] = $this->createCompanyWithUser('ABC Productions');
        $link = $this->referralService->getOrCreateActiveLink($referrer);

        [, $companyA] = $this->createCompanyWithUser('Company A');
        [, $companyB] = $this->createCompanyWithUser('Company B');

        $referralA = $this->referralService->createReferralForRegistration($link->referral_code, $companyA);
        $referralB = $this->referralService->createReferralForRegistration($link->referral_code, $companyB);

        $this->assertNotSame($referralA->id, $referralB->id);
        $this->assertSame(CompanyReferral::STATUS_REGISTERED, $referralA->status);
        $this->assertSame(CompanyReferral::STATUS_REGISTERED, $referralB->status);
        $this->assertSame($referrer->id, $referralA->referrer_company_id);
        $this->assertSame($referrer->id, $referralB->referrer_company_id);
    }

    public function test_registration_without_referral_still_works(): void
    {
        [, $company] = $this->createCompanyWithUser('Independent Co');

        $this->assertDatabaseHas('companies', ['id' => $company->id, 'name' => 'Independent Co']);
        $this->assertDatabaseMissing('company_referrals', ['referred_company_id' => $company->id]);
    }

    public function test_invalid_referral_code_is_handled(): void
    {
        $response = $this->getJson('/api/referrals/INVALID1');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.message', 'Invalid or inactive referral code.');

        $this->expectException(\InvalidArgumentException::class);
        [, $company] = $this->createCompanyWithUser('New Co');
        $this->referralService->createReferralForRegistration('INVALID1', $company);
    }

    public function test_validate_referral_code_returns_referring_company_basics(): void
    {
        [, $company] = $this->createCompanyWithUser('ABC Productions');
        $link = $this->referralService->getOrCreateActiveLink($company);

        $response = $this->getJson('/api/referrals/'.$link->referral_code);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.company.id', $company->id)
            ->assertJsonPath('data.company.name', 'ABC Productions')
            ->assertJsonMissingPath('data.company.email')
            ->assertJsonMissingPath('data.company.address_line_1');
    }

    public function test_self_referral_is_rejected(): void
    {
        [, $company] = $this->createCompanyWithUser('ABC Productions');
        $link = $this->referralService->getOrCreateActiveLink($company);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A company cannot refer itself.');

        $this->referralService->createReferralForRegistration($link->referral_code, $company);
    }

    public function test_company_cannot_have_multiple_referral_relationships(): void
    {
        [, $referrerA] = $this->createCompanyWithUser('ABC Productions');
        [, $referrerB] = $this->createCompanyWithUser('XYZ Productions');
        [, $referred] = $this->createCompanyWithUser('Company B');

        $linkA = $this->referralService->getOrCreateActiveLink($referrerA);
        $linkB = $this->referralService->getOrCreateActiveLink($referrerB);

        $this->referralService->createReferralForRegistration($linkA->referral_code, $referred);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This company already has a referral relationship.');

        $this->referralService->createReferralForRegistration($linkB->referral_code, $referred);
    }

    public function test_referral_information_is_available_to_admin_via_company_relationship(): void
    {
        [, $referrer] = $this->createCompanyWithUser('ABC Productions');
        [, $referred] = $this->createCompanyWithUser('XYZ Productions');
        $link = $this->referralService->getOrCreateActiveLink($referrer);
        $this->referralService->createReferralForRegistration($link->referral_code, $referred);

        $referred->load('referralReceived.referrerCompany');

        $this->assertNotNull($referred->referralReceived);
        $this->assertSame('ABC Productions', $referred->referralReceived->referrerCompany->name);
        $this->assertSame('Registered', $referred->referralReceived->statusLabel());
    }

    public function test_referral_information_is_not_exposed_publicly(): void
    {
        [, $referrer] = $this->createCompanyWithUser('ABC Productions');
        [, $referred] = $this->createCompanyWithUser('XYZ Productions');
        $link = $this->referralService->getOrCreateActiveLink($referrer);
        $this->referralService->createReferralForRegistration($link->referral_code, $referred);

        $payload = (new CompanyResource($referred->fresh()))->toArray(Request::create('/'));

        $this->assertArrayNotHasKey('referral', $payload);
        $this->assertArrayNotHasKey('referral_received', $payload);
        $this->assertArrayNotHasKey('referred_by', $payload);
        $this->assertArrayNotHasKey('referral_status', $payload);
        $this->assertArrayNotHasKey('referral_code', $payload);

        $user = User::where('company_id', $referred->id)->first();
        $response = $this->withToken($this->tokenFor($user))->getJson('/api/company/info');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertArrayNotHasKey('referral', $data);
        $this->assertArrayNotHasKey('referred_by', $data);
        $this->assertArrayNotHasKey('referral_status', $data);
        $this->assertArrayNotHasKey('referral_code', $data);
    }

    public function test_public_referred_by_company_search_requires_minimum_length(): void
    {
        $response = $this->getJson('/api/registration/companies/search?q=A');

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_public_referred_by_company_search_returns_limited_id_and_name(): void
    {
        $this->createCompanyWithUser('Alpha Rentals');
        $this->createCompanyWithUser('Alpine Gear Co');
        $this->createCompanyWithUser('Beta Productions');

        $blocked = Company::create([
            'name' => 'Alpha Blocked Co',
            'account_type' => 'provider',
            'subscription_mode' => 'free',
            'blocked_by_admin_at' => now(),
        ]);

        $response = $this->getJson('/api/registration/companies/search?q=Alp');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(2, $data);
        $this->assertSame(['id', 'name'], array_keys($data[0]));
        $names = collect($data)->pluck('name')->all();
        $this->assertContains('Alpha Rentals', $names);
        $this->assertContains('Alpine Gear Co', $names);
        $this->assertNotContains('Beta Productions', $names);
        $this->assertNotContains($blocked->name, $names);
    }

    public function test_public_referred_by_company_search_respects_result_limit(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            Company::create([
                'name' => sprintf('Searchable Company %02d', $i),
                'account_type' => 'provider',
                'subscription_mode' => 'free',
            ]);
        }

        $response = $this->getJson('/api/registration/companies/search?q=Searchable');

        $response->assertOk();
        $this->assertCount(10, $response->json('data'));
    }

    public function test_public_referred_by_search_ranks_prefix_matches_before_alphabetical_contains(): void
    {
        // Alphabetically earlier "contains" matches used to fill the limit of 10
        // and push out prefix matches like "Sound Engineers".
        Company::create(['name' => 'A-Live Sound LTD', 'account_type' => 'provider', 'subscription_mode' => 'free']);
        Company::create(['name' => 'AlexSound', 'account_type' => 'provider', 'subscription_mode' => 'free']);
        Company::create(['name' => 'Art & Sounds Orlando Inc.', 'account_type' => 'provider', 'subscription_mode' => 'free']);
        Company::create(['name' => 'Autograph Sound', 'account_type' => 'provider', 'subscription_mode' => 'free']);
        Company::create(['name' => 'Becker Sound Services LLC', 'account_type' => 'provider', 'subscription_mode' => 'free']);
        Company::create(['name' => 'Brown Bear Sound', 'account_type' => 'provider', 'subscription_mode' => 'free']);
        Company::create(['name' => 'Cape Town Lighting & Sound', 'account_type' => 'provider', 'subscription_mode' => 'free']);
        Company::create(['name' => 'Capital Sound Hire Limited', 'account_type' => 'provider', 'subscription_mode' => 'free']);
        Company::create(['name' => 'Clearsound Productions Ltd', 'account_type' => 'provider', 'subscription_mode' => 'free']);
        Company::create(['name' => 'Concert Sound Consultants', 'account_type' => 'provider', 'subscription_mode' => 'free']);
        Company::create(['name' => 'Sound Engineers', 'account_type' => 'provider', 'subscription_mode' => 'free']);
        Company::create(['name' => 'Sound City', 'account_type' => 'provider', 'subscription_mode' => 'free']);

        $bySound = $this->getJson('/api/registration/companies/search?q=Sound');
        $bySound->assertOk();
        $soundNames = collect($bySound->json('data'))->pluck('name');
        $this->assertTrue($soundNames->contains('Sound Engineers'));
        $this->assertTrue($soundNames->contains('Sound City'));
        $this->assertSame('Sound City', $soundNames->first());

        $byEngineers = $this->getJson('/api/registration/companies/search?q=Engineers');
        $byEngineers->assertOk();
        $this->assertTrue(collect($byEngineers->json('data'))->pluck('name')->contains('Sound Engineers'));

        $byEng = $this->getJson('/api/registration/companies/search?q=Eng');
        $byEng->assertOk();
        $this->assertTrue(collect($byEng->json('data'))->pluck('name')->contains('Sound Engineers'));

        $byLower = $this->getJson('/api/registration/companies/search?q=sound');
        $byLower->assertOk();
        $this->assertTrue(collect($byLower->json('data'))->pluck('name')->contains('Sound Engineers'));
    }

    public function test_admin_can_list_companies_referred_by_a_company(): void
    {
        [, $referrer] = $this->createCompanyWithUser('ABC Productions');
        [, $referredA] = $this->createCompanyWithUser('XYZ Productions');
        [, $referredB] = $this->createCompanyWithUser('ABC Event Rentals');
        $link = $this->referralService->getOrCreateActiveLink($referrer);
        $this->referralService->createReferralForRegistration($link->referral_code, $referredA);
        $this->referralService->createReferralForRegistration($link->referral_code, $referredB);

        $admin = User::create([
            'account_type' => 'provider',
            'username' => 'superadmin_'.uniqid(),
            'email' => uniqid('admin_', true).'@example.com',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
            'is_admin' => 1,
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/companies/'.$referrer->id.'/referrals');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('XYZ Productions', $names);
        $this->assertContains('ABC Event Rentals', $names);
        $this->assertSame(['id', 'name', 'status', 'created_at'], array_keys($response->json('data.0')));
        $this->assertSame('registered', $response->json('data.0.status'));
    }

    public function test_admin_referrals_endpoint_returns_empty_list_when_none(): void
    {
        [, $company] = $this->createCompanyWithUser('No Referrals Co');

        $admin = User::create([
            'account_type' => 'provider',
            'username' => 'superadmin_'.uniqid(),
            'email' => uniqid('admin_', true).'@example.com',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
            'is_admin' => 1,
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/companies/'.$company->id.'/referrals');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', []);
    }

    public function test_manual_referred_by_company_id_creates_referral_without_code(): void
    {
        [, $referrer] = $this->createCompanyWithUser('ABC Productions');
        [, $referred] = $this->createCompanyWithUser('Sound Engineers Manual');

        $referral = $this->referralService->createReferralForCompanyId($referrer->id, $referred);

        $this->assertSame($referrer->id, $referral->referrer_company_id);
        $this->assertSame($referred->id, $referral->referred_company_id);
        $this->assertSame(CompanyReferral::STATUS_REGISTERED, $referral->status);
        $this->assertNotNull($referral->referral_link_id);

        $referred->load('referralReceived.referrerCompany');
        $referrer->load('referralsMade.referredCompany');

        $this->assertSame('ABC Productions', $referred->referralReceived->referrerCompany->name);
        $this->assertTrue(
            $referrer->referralsMade->pluck('referredCompany.name')->contains('Sound Engineers Manual')
        );
    }

    public function test_manual_referred_by_rejects_self_and_duplicates(): void
    {
        [, $company] = $this->createCompanyWithUser('Self Refer Co');
        [, $other] = $this->createCompanyWithUser('Other Co');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A company cannot refer itself.');
        $this->referralService->createReferralForCompanyId($company->id, $company);
    }

    public function test_manual_referred_by_rejects_duplicate_relationship(): void
    {
        [, $referrerA] = $this->createCompanyWithUser('Referrer A');
        [, $referrerB] = $this->createCompanyWithUser('Referrer B');
        [, $referred] = $this->createCompanyWithUser('Referred Once');

        $this->referralService->createReferralForCompanyId($referrerA->id, $referred);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This company already has a referral relationship.');
        $this->referralService->createReferralForCompanyId($referrerB->id, $referred);
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function createCompanyWithUser(string $companyName): array
    {
        $company = Company::create([
            'name' => $companyName,
            'account_type' => 'provider',
            'subscription_mode' => 'free',
        ]);

        $user = User::create([
            'account_type' => 'provider',
            'username' => 'user_'.uniqid(),
            'email' => uniqid('u_', true).'@example.com',
            'password' => Hash::make('password'),
            'company_id' => $company->id,
            'is_admin' => 1,
            'is_company_default_contact' => 1,
            'role' => 'admin',
            'email_verified' => true,
        ]);

        $company->default_contact_id = $user->id;
        $company->save();

        return [$user, $company];
    }

    private function tokenFor(User $user): string
    {
        return JWTAuth::fromUser($user);
    }

    private function createMinimalSchema(): void
    {
        Schema::dropIfExists('company_referrals');
        Schema::dropIfExists('referral_links');
        Schema::dropIfExists('users');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('settings');

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('account_type')->nullable();
            $table->string('subscription_mode')->nullable();
            $table->string('logo')->nullable();
            $table->text('description')->nullable();
            $table->string('image1')->nullable();
            $table->string('image2')->nullable();
            $table->string('image3')->nullable();
            $table->boolean('logo_available_for_promotion')->default(true);
            $table->timestamp('logo_promotion_consent_at')->nullable();
            $table->boolean('logo_promotion_admin_enabled')->default(true);
            $table->unsignedInteger('logo_promotion_sort_order')->default(0);
            $table->unsignedBigInteger('default_contact_id')->nullable();
            $table->boolean('is_open_api_enabled')->default(false);
            $table->timestamp('blocked_by_admin_at')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('account_type')->nullable();
            $table->string('username')->unique();
            $table->string('email')->nullable();
            $table->boolean('email_verified')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->boolean('is_company_default_contact')->default(false);
            $table->boolean('is_admin')->default(false);
            $table->string('role')->default('user');
            $table->boolean('is_blocked')->default(false);
            $table->timestamps();
        });

        Schema::create('referral_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('referral_code', 32)->unique();
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });

        Schema::create('company_referrals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('referral_link_id');
            $table->unsignedBigInteger('referrer_company_id');
            $table->unsignedBigInteger('referred_company_id')->unique();
            $table->unsignedBigInteger('referrer_user_id')->nullable();
            $table->string('status', 32)->default('registered');
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }
}
