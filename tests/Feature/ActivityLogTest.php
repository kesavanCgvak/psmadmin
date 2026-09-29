<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\Equipment;
use App\Models\Product;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Integrations\CompanyIntegrationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->createSchema();
    }

    public function test_equipment_changes_are_audited_and_soft_deleted(): void
    {
        $company = $this->createCompany('Stage Co');
        $user = $this->createUser($company, false);
        $admin = $this->createUser(null, true);
        $product = Product::create([
            'model' => 'LED Panel',
            'psm_code' => 'PSM-LED-1',
            'is_verified' => 1,
        ]);

        $this->actingAs($user);

        $equipment = Equipment::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity' => 4,
            'rentman_equipment_id' => '12345',
            'software_code' => 'LED-1',
        ]);

        $created = ActivityLog::query()
            ->where('entity_type', 'company_inventory')
            ->where('entity_id', $equipment->id)
            ->where('action', ActivityLog::ACTION_CREATED)
            ->first();

        $this->assertNotNull($created);
        $this->assertSame($company->id, (int) $created->company_id);
        $this->assertSame($user->id, (int) $created->user_id);
        $this->assertSame('LED Panel', $created->new_values['equipment_name'] ?? null);
        $this->assertSame(4, (int) ($created->new_values['quantity'] ?? 0));

        $equipment->update(['quantity' => 9]);

        $updated = ActivityLog::query()
            ->where('entity_id', $equipment->id)
            ->where('action', ActivityLog::ACTION_UPDATED)
            ->first();

        $this->assertNotNull($updated);
        $this->assertSame(4, (int) $updated->old_values['quantity']);
        $this->assertSame(9, (int) $updated->new_values['quantity']);

        $equipment->delete();

        $this->assertNotNull($equipment->fresh()->deleted_at);
        $this->assertNull(Equipment::query()->find($equipment->id));

        $deleted = ActivityLog::query()
            ->where('entity_id', $equipment->id)
            ->where('action', ActivityLog::ACTION_DELETED)
            ->first();

        $this->assertNotNull($deleted);
        $this->assertSame('LED Panel', $deleted->old_values['equipment_name'] ?? null);
        $this->assertSame('12345', (string) ($deleted->old_values['rentman_equipment_id'] ?? ''));
        $this->assertSame($product->id, (int) ($deleted->old_values['product_id'] ?? 0));
        $this->assertSame(9, (int) ($deleted->old_values['quantity'] ?? 0));

        $replacement = Equipment::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'rentman_equipment_id' => '12345',
        ]);
        $this->assertNull($replacement->deleted_at);
        $replacement->delete();

        $this->actingAs($admin)
            ->get(route('admin.activity-logs.show', $deleted))
            ->assertOk()
            ->assertSee('LED Panel')
            ->assertSee('12345')
            ->assertSee('Restore record');

        $this->actingAs($admin)
            ->post(route('admin.activity-logs.restore', $deleted))
            ->assertRedirect(route('admin.activity-logs.show', $deleted));

        $restoredEquipment = Equipment::query()->find($equipment->id);
        $this->assertNotNull($restoredEquipment);
        $this->assertNull($restoredEquipment->deleted_at);

        $this->assertTrue(
            ActivityLog::query()
                ->where('entity_id', $equipment->id)
                ->where('action', ActivityLog::ACTION_RESTORED)
                ->exists()
        );

        $this->actingAs($admin)
            ->get(route('admin.activity-logs.index', [
                'company_id' => $company->id,
                'action' => ActivityLog::ACTION_DELETED,
                'entity_type' => ActivityLog::ENTITY_COMPANY_INVENTORY,
            ]))
            ->assertOk()
            ->assertSee('Stage Co')
            ->assertSee((string) $equipment->id);
    }

    public function test_integration_replacement_soft_deletes_and_redacts_credentials(): void
    {
        $company = $this->createCompany('Flex Co');
        $user = $this->createUser($company, false);
        $this->actingAs($user);

        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $service = app(CompanyIntegrationService::class);
        $firstKey = 'super-secret-flex-key-12345';
        $secondKey = 'another-secret-key-999';

        $first = $service->upsert($company->id, [
            'integration_type' => 'flex',
            'api_base_url' => 'https://flex.example.test',
            'api_key' => $firstKey,
        ]);

        $created = ActivityLog::query()
            ->where('entity_type', 'company_integrations')
            ->where('entity_id', $first->id)
            ->where('action', ActivityLog::ACTION_CREATED)
            ->first();

        $this->assertNotNull($created);
        $this->assertSame(ActivityLogService::REDACTED, $created->new_values['api_key'] ?? null);

        $logCount = ActivityLog::count();
        $first->update(['last_synced_at' => now()]);
        $this->assertSame($logCount, ActivityLog::count());

        $first->update(['api_base_url' => 'https://flex-updated.example.test']);

        $updated = ActivityLog::query()
            ->where('entity_id', $first->id)
            ->where('action', ActivityLog::ACTION_UPDATED)
            ->latest('id')
            ->first();

        $this->assertNotNull($updated);
        $this->assertSame('https://flex.example.test', $updated->old_values['api_base_url'] ?? null);
        $this->assertSame('https://flex-updated.example.test', $updated->new_values['api_base_url'] ?? null);
        $this->assertArrayNotHasKey('api_key', $updated->old_values ?? []);

        $second = $service->upsert($company->id, [
            'integration_type' => 'flex',
            'api_base_url' => 'https://flex-new.example.test',
            'api_key' => $secondKey,
        ]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertNotNull(CompanyIntegration::withTrashed()->find($first->id)?->deleted_at);
        $this->assertNull(CompanyIntegration::query()->find($second->id)?->deleted_at);
        $this->assertSame(1, CompanyIntegration::query()->count());

        $deleted = ActivityLog::query()
            ->where('entity_id', $first->id)
            ->where('action', ActivityLog::ACTION_DELETED)
            ->first();

        $this->assertNotNull($deleted);
        $this->assertSame(ActivityLogService::REDACTED, $deleted->old_values['api_key'] ?? null);
        $this->assertSame('flex', $deleted->old_values['integration_type'] ?? null);

        $payload = ActivityLog::query()->get()->toJson();
        $this->assertStringNotContainsString($firstKey, $payload);
        $this->assertStringNotContainsString($secondKey, $payload);
        $this->assertStringNotContainsString('eyJpdiI6', $payload);

        $second->delete();
        $this->assertNotNull($second->fresh()->deleted_at);

        $second->restore();
        $this->assertNull(CompanyIntegration::query()->find($second->id)?->deleted_at);
        $this->assertTrue(
            ActivityLog::query()
                ->where('entity_id', $second->id)
                ->where('action', ActivityLog::ACTION_RESTORED)
                ->exists()
        );
    }

    public function test_activity_logs_are_limited_to_admins(): void
    {
        $company = $this->createCompany('Private Co');
        $user = $this->createUser($company, false);
        $admin = $this->createUser(null, true);

        $this->get(route('admin.activity-logs.index'))
            ->assertRedirect(route('login'));

        $this->actingAs($user)
            ->get(route('admin.activity-logs.index'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('Activity Logs');
    }

    private function createCompany(string $name): Company
    {
        return Company::create([
            'name' => $name,
            'account_type' => 'provider',
        ]);
    }

    private function createUser(?Company $company, bool $admin): User
    {
        return User::create([
            'account_type' => 'provider',
            'username' => ($admin ? 'admin_' : 'user_').uniqid(),
            'email' => uniqid($admin ? 'admin_' : 'user_', true).'@example.com',
            'password' => Hash::make('password'),
            'company_id' => $company?->id,
            'is_admin' => $admin ? 1 : 0,
            'role' => $admin ? 'super_admin' : 'user',
            'email_verified' => true,
        ]);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('company_integrations');
        Schema::dropIfExists('company_inventory');
        Schema::dropIfExists('inventory_master');
        Schema::dropIfExists('user_profiles');
        Schema::dropIfExists('users');
        Schema::dropIfExists('companies');

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('account_type')->nullable();
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

        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('full_name')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_master', function (Blueprint $table) {
            $table->id();
            $table->string('model');
            $table->string('psm_code')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->string('normalized_model')->nullable();
            $table->string('normalized_full_name')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->timestamps();
        });

        Schema::create('company_inventory', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('company_id');
            $table->integer('quantity')->default(1);
            $table->string('software_code')->nullable();
            $table->string('rentman_equipment_id', 100)->nullable();
            $table->string('flex_resource_id', 100)->nullable();
            $table->string('active_rentman_key', 191)->nullable()->unique('company_inventory_active_rentman_unique');
            $table->string('active_flex_key', 191)->nullable()->unique('company_inventory_active_flex_unique');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('company_integrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('integration_type', 50);
            $table->string('api_base_url', 500)->nullable();
            $table->text('api_key')->nullable();
            $table->string('client_id', 500)->nullable();
            $table->text('client_secret')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('active_integration_key', 191)->nullable()->unique('company_integrations_active_key_unique');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 32);
            $table->string('entity_type', 100);
            $table->unsignedBigInteger('entity_id');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }
}
