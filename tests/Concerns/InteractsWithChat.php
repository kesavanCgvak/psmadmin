<?php

namespace Tests\Concerns;

use App\Models\ChatUserSetting;
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

        Schema::dropIfExists('chat_notification_logs');
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
            $table->boolean('email_notifications_enabled')->default(true);
            $table->boolean('sms_notifications_enabled')->default(false);
            $table->timestamp('sms_consented_at')->nullable();
            $table->timestamps();
        });

        Schema::create('chat_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('message_id');
            $table->unsignedBigInteger('conversation_id');
            $table->string('channel', 16);
            $table->string('status', 16)->default('pending');
            $table->string('error_message', 500)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'message_id', 'channel']);
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

    /**
     * @param  array{browser_notifications_enabled?: bool, email_notifications_enabled?: bool, sms_notifications_enabled?: bool, mobile?: string|null, email?: string|null}  $prefs
     */
    private function setChatNotificationPrefs(User $user, array $prefs): void
    {
        if (array_key_exists('email', $prefs)) {
            $user->email = $prefs['email'];
            $user->save();
            if ($user->profile) {
                $user->profile->email = $prefs['email'];
                $user->profile->save();
            }
        }

        if (array_key_exists('mobile', $prefs) && $user->profile) {
            $user->profile->mobile = $prefs['mobile'];
            $user->profile->save();
        }

        $settings = ChatUserSetting::query()->firstOrNew(['user_id' => $user->id]);
        if (! $settings->exists) {
            $settings->browser_notifications_enabled = false;
            $settings->email_notifications_enabled = true;
            $settings->sms_notifications_enabled = false;
        }

        if (array_key_exists('browser_notifications_enabled', $prefs)) {
            $settings->browser_notifications_enabled = (bool) $prefs['browser_notifications_enabled'];
        }
        if (array_key_exists('email_notifications_enabled', $prefs)) {
            $settings->email_notifications_enabled = (bool) $prefs['email_notifications_enabled'];
        }
        if (array_key_exists('sms_notifications_enabled', $prefs)) {
            $settings->sms_notifications_enabled = (bool) $prefs['sms_notifications_enabled'];
            if ($settings->sms_notifications_enabled && $settings->sms_consented_at === null) {
                $settings->sms_consented_at = now();
            }
        }
        if (array_key_exists('sms_consented', $prefs)) {
            $settings->sms_consented_at = ! empty($prefs['sms_consented']) ? now() : null;
        }

        $settings->save();
        $user->unsetRelation('chatUserSetting');
        $user->unsetRelation('profile');
    }

    private function createSmsLogsTable(): void
    {
        Schema::dropIfExists('sms_logs');
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
