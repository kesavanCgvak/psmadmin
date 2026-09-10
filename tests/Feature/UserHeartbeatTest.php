<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class UserHeartbeatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createMinimalSchema();
        config(['presence.online_status_timeout' => 120]);
    }

    public function test_authenticated_user_can_send_heartbeat(): void
    {
        [$user] = $this->createCompanyWithUser('Caller Company');

        $response = $this->withToken($this->tokenFor($user))
            ->postJson('/api/user/heartbeat');

        $response->assertOk()
            ->assertExactJson(['success' => true]);
    }

    public function test_heartbeat_updates_last_seen_at(): void
    {
        [$user] = $this->createCompanyWithUser('Caller Company');
        $this->assertNull($user->fresh()->last_seen_at);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/user/heartbeat')
            ->assertOk();

        $user->refresh();

        $this->assertNotNull($user->last_seen_at);
        $this->assertTrue($user->last_seen_at->diffInSeconds(now()) <= 2);
    }

    public function test_unauthenticated_heartbeat_is_rejected(): void
    {
        $this->postJson('/api/user/heartbeat')->assertStatus(401);
    }

    public function test_logout_clears_last_seen_at(): void
    {
        [$user] = $this->createCompanyWithUser('Caller Company');

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/user/heartbeat')
            ->assertOk();

        $this->assertNotNull($user->fresh()->last_seen_at);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/logout')
            ->assertOk();

        $this->assertNull($user->fresh()->last_seen_at);
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

    private function tokenFor(User $user): string
    {
        return JWTAuth::fromUser($user);
    }

    private function createMinimalSchema(): void
    {
        Schema::dropIfExists('user_auth_events');
        Schema::dropIfExists('users');
        Schema::dropIfExists('companies');

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('account_type')->nullable();
            $table->unsignedBigInteger('default_contact_id')->nullable();
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

        Schema::create('user_auth_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('event_type', 32);
            $table->string('channel', 16);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('identifier', 255)->nullable();
            $table->timestamps();
        });
    }
}
