<?php

namespace Tests\Concerns;

use App\Models\Company;
use App\Models\User;
use App\Models\UserProfile;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tymon\JWTAuth\Facades\JWTAuth;

trait InteractsWithChat
{
    private function createMinimalChatSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('chat_user_settings');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversation_user_states');
        Schema::dropIfExists('chat_conversation_participants');
        Schema::dropIfExists('chat_conversations');
        Schema::dropIfExists('user_profiles');
        Schema::dropIfExists('users');
        Schema::dropIfExists('rental_jobs');
        Schema::dropIfExists('companies');

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('account_type')->nullable();
            $table->string('logo')->nullable();
            $table->unsignedBigInteger('default_contact_id')->nullable();
            $table->timestamps();
        });

        Schema::create('rental_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name')->nullable();
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

        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_a_id');
            $table->unsignedBigInteger('company_b_id');
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('rental_job_id')->nullable();
            $table->string('pair_key', 80)->unique();
            $table->timestamps();
        });

        Schema::create('chat_conversation_user_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'user_id']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('sender_user_id');
            $table->unsignedBigInteger('sender_company_id');
            $table->text('message');
            $table->string('message_type', 32)->default('text');
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedBigInteger('deleted_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('chat_user_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->boolean('browser_notifications_enabled')->default(false);
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * @param  list<string>  $fullNames
     * @return array{0: User, 1: Company, ...}
     */
    private function createCompanyUsers(string $companyName, string $accountType, array $fullNames): array
    {
        $company = Company::create([
            'name' => $companyName,
            'account_type' => $accountType,
        ]);

        $users = [];
        foreach ($fullNames as $index => $fullName) {
            $isDefault = $index === 0;
            $user = User::create([
                'account_type' => $accountType,
                'username' => 'user_'.uniqid(),
                'email' => uniqid('u_', true).'@example.com',
                'password' => Hash::make('password'),
                'company_id' => $company->id,
                'is_admin' => $isDefault ? 1 : 0,
                'is_company_default_contact' => $isDefault,
                'role' => $isDefault ? 'admin' : 'user',
                'email_verified' => true,
            ]);

            $parts = explode(' ', $fullName, 2);
            UserProfile::create([
                'user_id' => $user->id,
                'full_name' => $fullName,
                'first_name' => $parts[0],
                'last_name' => $parts[1] ?? '',
            ]);

            if ($isDefault) {
                $company->default_contact_id = $user->id;
                $company->save();
            }

            $users[] = $user->fresh(['profile', 'company']);
        }

        return [$users[0], $company->fresh(), ...array_slice($users, 1)];
    }

    private function openConversation(User $from, int $otherCompanyId, ?int $rentalJobId = null): int
    {
        $payload = ['company_id' => $otherCompanyId];
        if ($rentalJobId !== null) {
            $payload['rental_job_id'] = $rentalJobId;
        }

        $response = $this->withToken($this->tokenFor($from))
            ->postJson('/api/chat/conversations', $payload);

        $response->assertCreated();

        return (int) $response->json('data.id');
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
}
