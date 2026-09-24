<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Company;
use App\Models\Equipment;
use App\Models\InventoryMasterImage;
use App\Models\Product;
use App\Models\PsmProductSubmission;
use App\Models\PsmProductSubmissionImage;
use App\Models\User;
use App\Notifications\PsmProductSubmitted;
use App\Services\PsmProductSubmissionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class PsmProductSubmissionWorkflowTest extends TestCase
{
    private PsmProductSubmissionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->createMinimalSchema();
        $this->service = app(PsmProductSubmissionService::class);
    }

    public function test_provider_can_submit_product_without_writing_inventory_master(): void
    {
        Notification::fake();
        [$user, $company] = $this->createProviderUser('Provider One');
        $image = UploadedFile::fake()->image('mic.jpg', 80, 80);

        $response = $this->withToken($this->tokenFor($user))
            ->post('/api/psm-product-submissions', [
                'name' => 'Shure SM58',
                'description' => 'Dynamic vocal microphone',
                'replacement_price' => 149.99,
                'height' => 6.5,
                'country_of_origin' => 'CN',
                'hsn_code' => '85181000',
                'images' => [$image],
            ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.name', 'Shure SM58');

        $this->assertStringContainsString('not been added to PSM inventory', $response->json('message'));
        $this->assertSame(1, PsmProductSubmission::count());
        $this->assertSame(0, Product::count());
        $this->assertSame(0, Equipment::count());
        $this->assertSame((int) $company->id, (int) PsmProductSubmission::first()->company_id);
        $this->assertSame((int) $user->id, (int) PsmProductSubmission::first()->submitted_by_user_id);
        $this->assertSame(1, PsmProductSubmissionImage::count());
        Notification::assertSentOnDemand(PsmProductSubmitted::class);
    }

    public function test_invalid_submission_and_invalid_image_are_rejected(): void
    {
        [$user] = $this->createProviderUser('Provider One');

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/psm-product-submissions', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->withToken($this->tokenFor($user))
            ->post('/api/psm-product-submissions', [
                'name' => 'Bad Image Product',
                'images' => [UploadedFile::fake()->create('notes.txt', 20, 'text/plain')],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(0, PsmProductSubmission::count());
    }

    public function test_user_cannot_list_another_companys_submissions(): void
    {
        [$userA] = $this->createProviderUser('Provider A');
        [$userB] = $this->createProviderUser('Provider B');

        $this->service->submit($userA, ['name' => 'Secret Product']);
        $this->service->submit($userB, ['name' => 'Visible Product']);

        $response = $this->withToken($this->tokenFor($userB))
            ->getJson('/api/psm-product-submissions');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Visible Product', $response->json('data.0.name'));
    }

    public function test_admin_can_approve_into_inventory_master_and_submission_is_soft_deleted(): void
    {
        [$user, $company] = $this->createProviderUser('Provider One');
        $brand = Brand::create(['name' => 'Shure']);
        $submission = $this->service->submit($user, [
            'name' => 'SM58',
            'description' => 'Kept on submission only',
            'brand_id' => $brand->id,
            'replacement_price' => 100,
            'height' => 4,
            'country_of_origin' => 'US',
            'hsn_code' => '1234',
        ], [UploadedFile::fake()->image('primary.jpg')]);

        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)
            ->post(route('admin.psm-product-submissions.approve', $submission), [
                'name' => 'SM58',
                'country_of_origin' => 'US',
                'hsn_code' => '1234',
                'replacement_price' => 100,
                'height' => 4,
                'brand_id' => $brand->id,
            ]);

        $response->assertRedirect(route('admin.psm-product-submissions.index'));

        $this->assertSame(1, Product::count());
        $product = Product::first();
        $this->assertSame('SM58', $product->model);
        $this->assertSame('US', $product->country_of_origin);
        $this->assertSame('1234', $product->hsn_code);
        $this->assertSame(PsmProductSubmission::SOURCE, $product->source);
        $this->assertNotNull($product->psm_code);
        $this->assertSame(1, InventoryMasterImage::where('inventory_master_id', $product->id)->count());
        $this->assertTrue(InventoryMasterImage::where('inventory_master_id', $product->id)->where('is_primary', true)->exists());
        $this->assertSame(0, Equipment::count());

        $this->assertSame(0, PsmProductSubmission::count());
        $archived = PsmProductSubmission::withTrashed()->first();
        $this->assertNotNull($archived->deleted_at);
        $this->assertSame(PsmProductSubmission::STATUS_APPROVED, $archived->status);
        $this->assertSame($product->id, $archived->inventory_master_id);
        $this->assertSame($company->id, $archived->company_id);
    }

    public function test_duplicate_approval_does_not_create_a_second_product(): void
    {
        [$user] = $this->createProviderUser('Provider One');
        $submission = $this->service->submit($user, ['name' => 'SM58']);
        $admin = $this->createAdminUser();

        $this->actingAs($admin)->post(route('admin.psm-product-submissions.approve', $submission), [
            'name' => 'SM58',
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('admin.psm-product-submissions.approve', $submission), [
            'name' => 'SM58',
        ]);

        $this->assertSame(1, Product::count());
    }

    public function test_admin_can_reject_without_creating_inventory_master(): void
    {
        [$user] = $this->createProviderUser('Provider One');
        $submission = $this->service->submit($user, ['name' => 'Reject Me']);
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->post(route('admin.psm-product-submissions.reject', $submission), [
                'admin_notes' => 'Duplicate of an existing catalog item.',
            ])
            ->assertRedirect(route('admin.psm-product-submissions.index'));

        $submission->refresh();
        $this->assertSame(PsmProductSubmission::STATUS_REJECTED, $submission->status);
        $this->assertSame('Duplicate of an existing catalog item.', $submission->admin_notes);
        $this->assertNull($submission->deleted_at);
        $this->assertSame(0, Product::count());
    }

    public function test_non_admin_cannot_access_admin_review(): void
    {
        [$user] = $this->createProviderUser('Provider One');
        $submission = $this->service->submit($user, ['name' => 'SM58']);

        $this->actingAs($user)
            ->get(route('admin.psm-product-submissions.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.psm-product-submissions.approve', $submission), ['name' => 'SM58'])
            ->assertForbidden();
    }

    public function test_unauthenticated_api_submit_is_rejected(): void
    {
        $this->postJson('/api/psm-product-submissions', ['name' => 'SM58'])
            ->assertStatus(401);
    }

    public function test_email_failure_does_not_remove_the_submission(): void
    {
        Notification::shouldReceive('route')->andThrow(new \RuntimeException('mail down'));

        [$user] = $this->createProviderUser('Provider One');

        $submission = app(PsmProductSubmissionService::class)->submit($user, ['name' => 'Still Saved']);

        $this->assertSame('pending', $submission->status);
        $this->assertSame(1, PsmProductSubmission::count());
        $this->assertSame(0, Product::count());
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function createProviderUser(string $companyName, string $accountType = 'provider'): array
    {
        $company = Company::create([
            'name' => $companyName,
            'account_type' => $accountType,
        ]);

        $user = User::create([
            'account_type' => $accountType,
            'username' => 'user_'.uniqid(),
            'email' => uniqid('u_', true).'@example.com',
            'password' => Hash::make('password'),
            'company_id' => $company->id,
            'is_admin' => 0,
            'is_company_default_contact' => 1,
            'role' => 'user',
            'email_verified' => true,
        ]);

        return [$user, $company];
    }

    private function createAdminUser(): User
    {
        return User::create([
            'account_type' => 'provider',
            'username' => 'admin_'.uniqid(),
            'email' => uniqid('admin_', true).'@example.com',
            'password' => Hash::make('password'),
            'is_admin' => 1,
            'role' => 'super_admin',
            'email_verified' => true,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return JWTAuth::fromUser($user);
    }

    private function createMinimalSchema(): void
    {
        Schema::dropIfExists('psm_product_submission_images');
        Schema::dropIfExists('psm_product_submissions');
        Schema::dropIfExists('equipment_images');
        Schema::dropIfExists('company_inventory');
        Schema::dropIfExists('inventory_master_images');
        Schema::dropIfExists('inventory_master');
        Schema::dropIfExists('sub_categories');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('linear_units');
        Schema::dropIfExists('weight_units');
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
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('sub_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('linear_units', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->string('system')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('weight_units', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->string('system')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_master', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('sub_category_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('model');
            $table->string('psm_code')->nullable()->unique();
            $table->boolean('is_verified')->default(false);
            $table->string('webpage_url')->nullable();
            $table->string('normalized_model')->nullable();
            $table->string('normalized_full_name')->nullable();
            $table->decimal('height', 12, 2)->nullable();
            $table->decimal('width', 12, 2)->nullable();
            $table->decimal('length', 12, 2)->nullable();
            $table->decimal('weight', 12, 2)->nullable();
            $table->unsignedBigInteger('linear_unit_id')->nullable();
            $table->unsignedBigInteger('weight_unit_id')->nullable();
            $table->decimal('replacement_price', 12, 2)->nullable();
            $table->string('source')->nullable();
            $table->string('country_of_origin')->nullable();
            $table->string('iso_code_2')->nullable();
            $table->string('iso_code_3')->nullable();
            $table->string('hsn_code')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_master_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inventory_master_id');
            $table->string('image_path', 512);
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('sort_order')->nullable();
            $table->string('source', 50)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('company_inventory', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('company_id');
            $table->integer('quantity')->default(1);
            $table->decimal('rental_price', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('equipment_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('equipment_id');
            $table->string('image_path', 512);
            $table->timestamps();
        });

        Schema::create('psm_product_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('submitted_by_user_id');
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('psm_code')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('sub_category_id')->nullable();
            $table->string('webpage_url', 2048)->nullable();
            $table->decimal('replacement_price', 12, 2)->nullable();
            $table->decimal('height', 12, 2)->nullable();
            $table->decimal('width', 12, 2)->nullable();
            $table->decimal('length', 12, 2)->nullable();
            $table->decimal('weight', 12, 2)->nullable();
            $table->unsignedBigInteger('linear_unit_id')->nullable();
            $table->unsignedBigInteger('weight_unit_id')->nullable();
            $table->string('country_of_origin', 100)->nullable();
            $table->string('iso_code_2', 2)->nullable();
            $table->string('iso_code_3', 3)->nullable();
            $table->string('hsn_code', 20)->nullable();
            $table->string('status')->default('pending');
            $table->text('admin_notes')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedBigInteger('inventory_master_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('psm_product_submission_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('psm_product_submission_id');
            $table->string('image_path', 512);
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('sort_order')->nullable();
            $table->timestamps();
        });
    }
}
