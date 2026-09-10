<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Models\UserProfile;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CompanyListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createMinimalSchema();
        config(['presence.online_status_timeout' => 120]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_list_companies_includes_default_contact_email_and_mobile(): void
    {
        [$user] = $this->createCompanyWithUser('Caller Company');
        $listed = $this->createCompanyWithDefaultContact(
            'ABC Rental Company',
            'contact@example.com',
            '+1 615 555 1234'
        );

        $response = $this->withToken($this->tokenFor($user))
            ->getJson('/api/companies');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $company = collect($response->json('companies'))->firstWhere('id', $listed->id);

        $this->assertNotNull($company);
        $this->assertSame('ABC Rental Company', $company['name']);
        $this->assertSame('contact@example.com', $company['default_contact_email']);
        $this->assertSame('+1 615 555 1234', $company['default_contact_mobile']);
        $this->assertArrayHasKey('company_logo', $company);
        $this->assertArrayHasKey('city', $company);
        $this->assertArrayHasKey('state', $company);
        $this->assertArrayHasKey('country', $company);
        $this->assertArrayHasKey('average_rating', $company);
        $this->assertArrayHasKey('rating_count', $company);
        $this->assertArrayHasKey('rating_breakdown', $company);
        $this->assertArrayHasKey('user_rating', $company);
        $this->assertArrayHasKey('is_blocked', $company);
        $this->assertArrayHasKey('is_online', $company);
        $this->assertFalse($company['is_online']);
    }

    public function test_list_companies_returns_null_default_contact_fields_when_missing(): void
    {
        [$user] = $this->createCompanyWithUser('Caller Company');
        $listed = Company::create([
            'name' => 'No Contact Co',
            'account_type' => 'provider',
        ]);

        $response = $this->withToken($this->tokenFor($user))
            ->getJson('/api/companies');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $company = collect($response->json('companies'))->firstWhere('id', $listed->id);

        $this->assertNotNull($company);
        $this->assertNull($company['default_contact_email']);
        $this->assertNull($company['default_contact_mobile']);
    }

    public function test_list_companies_excludes_authenticated_users_company(): void
    {
        [$user, $ownCompany] = $this->createCompanyWithUser('Caller Company');
        $other = $this->createCompanyWithDefaultContact(
            'Other Company',
            'other@example.com',
            '555-0000'
        );

        $response = $this->withToken($this->tokenFor($user))
            ->getJson('/api/companies');

        $response->assertOk();

        $ids = collect($response->json('companies'))->pluck('id')->all();

        $this->assertContains($other->id, $ids);
        $this->assertNotContains($ownCompany->id, $ids);
    }

    public function test_list_companies_is_online_when_associated_user_was_seen_recently(): void
    {
        Carbon::setTestNow('2026-09-09 12:00:00');

        [$caller] = $this->createCompanyWithUser('Caller Company');
        [$providerUser, $listed] = $this->createCompanyWithUser('ABC Rental Company');
        $this->setLastSeen($providerUser, now()->subSeconds(119));

        $response = $this->withToken($this->tokenFor($caller))
            ->getJson('/api/companies');

        $company = collect($response->json('companies'))->firstWhere('id', $listed->id);

        $this->assertNotNull($company);
        $this->assertTrue($company['is_online']);
        $this->assertIsBool($company['is_online']);
    }

    public function test_list_companies_is_offline_when_last_seen_is_older_than_timeout(): void
    {
        Carbon::setTestNow('2026-09-09 12:00:00');

        [$caller] = $this->createCompanyWithUser('Caller Company');
        [$providerUser, $listed] = $this->createCompanyWithUser('ABC Rental Company');
        $this->setLastSeen($providerUser, now()->subSeconds(121));

        $response = $this->withToken($this->tokenFor($caller))
            ->getJson('/api/companies');

        $company = collect($response->json('companies'))->firstWhere('id', $listed->id);

        $this->assertNotNull($company);
        $this->assertFalse($company['is_online']);
        $this->assertIsBool($company['is_online']);
    }

    public function test_list_companies_is_offline_when_last_seen_at_is_null(): void
    {
        [$caller] = $this->createCompanyWithUser('Caller Company');
        [, $listed] = $this->createCompanyWithUser('ABC Rental Company');

        $response = $this->withToken($this->tokenFor($caller))
            ->getJson('/api/companies');

        $company = collect($response->json('companies'))->firstWhere('id', $listed->id);

        $this->assertNotNull($company);
        $this->assertFalse($company['is_online']);
    }

    public function test_list_companies_is_offline_when_provider_has_no_associated_user(): void
    {
        [$caller] = $this->createCompanyWithUser('Caller Company');
        $listed = Company::create([
            'name' => 'No Users Co',
            'account_type' => 'provider',
        ]);

        $response = $this->withToken($this->tokenFor($caller))
            ->getJson('/api/companies');

        $company = collect($response->json('companies'))->firstWhere('id', $listed->id);

        $this->assertNotNull($company);
        $this->assertFalse($company['is_online']);
    }

    public function test_list_companies_does_not_n_plus_one_for_online_status(): void
    {
        [$caller] = $this->createCompanyWithUser('Caller Company');

        for ($i = 1; $i <= 5; $i++) {
            [$providerUser] = $this->createCompanyWithUser("Provider {$i}");
            $this->setLastSeen($providerUser, now());
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->withToken($this->tokenFor($caller))
            ->getJson('/api/companies')
            ->assertOk();

        $presenceQueries = collect(DB::getQueryLog())->filter(
            fn (array $query) => str_contains($query['query'], 'last_seen_at')
        );

        $this->assertCount(1, $presenceQueries);
    }

    public function test_list_companies_orders_online_first_then_newest_registered(): void
    {
        Carbon::setTestNow('2026-09-09 12:00:00');

        [$caller] = $this->createCompanyWithUser('Caller Company');

        [$oldOfflineUser, $oldOffline] = $this->createCompanyWithUser('Old Offline');
        $this->setCreatedAt($oldOffline, now()->subDays(10));
        $this->setLastSeen($oldOfflineUser, now()->subMinutes(10));

        [, $newOffline] = $this->createCompanyWithUser('New Offline');
        $this->setCreatedAt($newOffline, now()->subDays(1));

        [$oldOnlineUser, $oldOnline] = $this->createCompanyWithUser('Old Online');
        $this->setCreatedAt($oldOnline, now()->subDays(8));
        $this->setLastSeen($oldOnlineUser, now()->subSeconds(30));

        [$newOnlineUser, $newOnline] = $this->createCompanyWithUser('New Online');
        $this->setCreatedAt($newOnline, now()->subHours(2));
        $this->setLastSeen($newOnlineUser, now()->subSeconds(10));

        $response = $this->withToken($this->tokenFor($caller))
            ->getJson('/api/companies');

        $response->assertOk();

        $this->assertSame(
            [$newOnline->id, $oldOnline->id, $newOffline->id, $oldOffline->id],
            collect($response->json('companies'))->pluck('id')->all()
        );
        $this->assertTrue($response->json('companies.0.is_online'));
        $this->assertTrue($response->json('companies.1.is_online'));
        $this->assertFalse($response->json('companies.2.is_online'));
        $this->assertFalse($response->json('companies.3.is_online'));
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function createCompanyWithUser(string $companyName): array
    {
        $company = Company::create([
            'name' => $companyName,
            'account_type' => 'provider',
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

    private function createCompanyWithDefaultContact(string $name, string $email, string $mobile): Company
    {
        [$user, $company] = $this->createCompanyWithUser($name);

        UserProfile::create([
            'user_id' => $user->id,
            'full_name' => 'Default Contact',
            'first_name' => 'Default',
            'last_name' => 'Contact',
            'email' => $email,
            'mobile' => $mobile,
        ]);

        return $company->fresh();
    }

    private function tokenFor(User $user): string
    {
        return JWTAuth::fromUser($user);
    }

    private function setLastSeen(User $user, ?Carbon $at): void
    {
        $user->last_seen_at = $at;
        $user->save();
    }

    private function setCreatedAt(Company $company, Carbon $at): void
    {
        $company->created_at = $at;
        $company->save();
    }

    private function createMinimalSchema(): void
    {
        Schema::dropIfExists('company_blocks');
        Schema::dropIfExists('company_ratings');
        Schema::dropIfExists('job_ratings');
        Schema::dropIfExists('supply_jobs');
        Schema::dropIfExists('user_profiles');
        Schema::dropIfExists('users');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('states_provinces');
        Schema::dropIfExists('countries');

        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('states_provinces', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('account_type')->nullable();
            $table->string('logo')->nullable();
            $table->unsignedBigInteger('default_contact_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->timestamp('blocked_by_admin_at')->nullable();
            $table->decimal('rating_override', 8, 2)->nullable();
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
            $table->timestamp('last_seen_at')->nullable();
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

        Schema::create('supply_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('provider_id')->nullable();
            $table->timestamps();
        });

        Schema::create('job_ratings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supply_job_id')->nullable();
            $table->tinyInteger('rating')->nullable();
            $table->timestamp('rated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('company_ratings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->tinyInteger('rating');
            $table->timestamps();
        });

        Schema::create('company_blocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });
    }
}
