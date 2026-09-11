<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Company;
use App\Models\Equipment;
use App\Models\EquipmentImage;
use App\Models\InventoryMasterImage;
use App\Models\LinearUnit;
use App\Models\Product;
use App\Models\User;
use App\Models\WeightUnit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class PsmEquipmentApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->createMinimalSchema();
    }

    public function test_listing_is_paginated_and_returns_minimal_fields(): void
    {
        [$user] = $this->createProviderUser('Provider One');
        $brand = Brand::create(['name' => 'Shure']);
        $this->createCatalogProduct('SM58', 'PSM10001', $brand->id);
        $this->createCatalogProduct('SM57', 'PSM10002', $brand->id);
        $this->createCatalogProduct('Beta 58A', 'PSM10003', $brand->id);

        $response = $this->withToken($this->tokenFor($user))
            ->getJson('/api/psm-equipments?per_page=2&page=1');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.total_pages', 2);

        $this->assertCount(2, $response->json('data'));
        $this->assertSame('Shure Beta 58A', $response->json('data.0.name'));
        $this->assertArrayHasKey('primary_image', $response->json('data.0'));
        $this->assertArrayNotHasKey('height', $response->json('data.0'));
        $this->assertArrayNotHasKey('images', $response->json('data.0'));
    }

    public function test_listing_search_filters_by_model_and_psm_code(): void
    {
        [$user] = $this->createProviderUser('Provider One');
        $brand = Brand::create(['name' => 'Shure']);
        $this->createCatalogProduct('SM58', 'PSM10001', $brand->id);
        $this->createCatalogProduct('Wireless Handheld', 'PSM19999', $brand->id);

        $response = $this->withToken($this->tokenFor($user))
            ->getJson('/api/psm-equipments?search=SM58');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('PSM10001', $response->json('data.0.psm_code'));
    }

    public function test_details_include_specs_and_catalog_images(): void
    {
        [$user] = $this->createProviderUser('Provider One');
        $brand = Brand::create(['name' => 'Shure']);
        $linear = LinearUnit::create(['name' => 'Inches', 'code' => 'in', 'system' => 'imperial', 'is_active' => true]);
        $weight = WeightUnit::create(['name' => 'Pounds', 'code' => 'lb', 'system' => 'imperial', 'is_active' => true]);
        $product = $this->createCatalogProduct('SM58', 'PSM10001', $brand->id, [
            'replacement_price' => 149.99,
            'height' => 6.5,
            'width' => 2,
            'length' => 6.5,
            'weight' => 0.66,
            'linear_unit_id' => $linear->id,
            'weight_unit_id' => $weight->id,
            'country_of_origin' => 'CN',
            'hsn_code' => '85181000',
        ]);

        InventoryMasterImage::create([
            'inventory_master_id' => $product->id,
            'image_path' => 'images/inventory_master/primary.jpg',
            'is_primary' => true,
            'sort_order' => 1,
            'source' => 'admin',
        ]);
        InventoryMasterImage::create([
            'inventory_master_id' => $product->id,
            'image_path' => 'images/inventory_master/side.jpg',
            'is_primary' => false,
            'sort_order' => 2,
            'source' => 'admin',
        ]);

        $response = $this->withToken($this->tokenFor($user))
            ->getJson('/api/psm-equipments/'.$product->id);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.name', 'Shure SM58')
            ->assertJsonPath('data.psm_code', 'PSM10001')
            ->assertJsonPath('data.country_of_origin', 'CN')
            ->assertJsonPath('data.hsn_code', '85181000')
            ->assertJsonPath('data.already_in_company_inventory', false)
            ->assertJsonPath('data.primary_image.is_primary', true);

        $this->assertCount(2, $response->json('data.images'));
        $this->assertArrayNotHasKey('description', $response->json('data'));
        $this->assertArrayNotHasKey('rental_price', $response->json('data'));
    }

    public function test_valid_import_copies_specs_and_images_into_authenticated_company_inventory(): void
    {
        [$user, $company] = $this->createProviderUser('Provider One');
        $brand = Brand::create(['name' => 'Shure']);
        $product = $this->createCatalogProduct('SM58', 'PSM10001', $brand->id, [
            'replacement_price' => 149.99,
            'height' => 6.5,
            'country_of_origin' => 'CN',
            'hsn_code' => '85181000',
        ]);
        InventoryMasterImage::create([
            'inventory_master_id' => $product->id,
            'image_path' => 'images/inventory_master/primary.jpg',
            'is_primary' => true,
            'sort_order' => 1,
            'source' => 'admin',
        ]);
        InventoryMasterImage::create([
            'inventory_master_id' => $product->id,
            'image_path' => 'images/inventory_master/side.jpg',
            'is_primary' => false,
            'sort_order' => 2,
            'source' => 'admin',
        ]);

        $response = $this->withToken($this->tokenFor($user))
            ->postJson('/api/psm-equipments/'.$product->id.'/import');

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.product_id', $product->id)
            ->assertJsonPath('data.company_id', $company->id);

        $equipment = Equipment::query()
            ->where('company_id', $company->id)
            ->where('product_id', $product->id)
            ->first();

        $this->assertNotNull($equipment);
        $this->assertSame(1, (int) $equipment->quantity);
        $this->assertNull($equipment->rental_price);
        $this->assertNull($equipment->flex_resource_id);
        $this->assertNull($equipment->rentman_equipment_id);
        $this->assertSame('CN', $equipment->country_of_origin);
        $this->assertSame('85181000', $equipment->hsn_code);
        $this->assertEquals(149.99, (float) $equipment->replacement_price);
        $this->assertEquals(6.5, (float) $equipment->height);
        $this->assertSame(2, EquipmentImage::where('equipment_id', $equipment->id)->count());
        $this->assertTrue(
            EquipmentImage::where('equipment_id', $equipment->id)->where('is_primary', true)->exists()
        );
    }

    public function test_duplicate_import_returns_conflict_and_does_not_create_another_row(): void
    {
        [$user, $company] = $this->createProviderUser('Provider One');
        $product = $this->createCatalogProduct('SM58', 'PSM10001');
        Equipment::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'product_id' => $product->id,
            'quantity' => 4,
            'rental_price' => 25,
        ]);

        $response = $this->withToken($this->tokenFor($user))
            ->postJson('/api/psm-equipments/'.$product->id.'/import');

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.product_id', $product->id);

        $this->assertSame(1, Equipment::where('company_id', $company->id)->where('product_id', $product->id)->count());
    }

    public function test_invalid_equipment_id_returns_not_found(): void
    {
        [$user] = $this->createProviderUser('Provider One');

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/psm-equipments/999999')
            ->assertStatus(404)
            ->assertJsonPath('success', false);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/psm-equipments/999999/import')
            ->assertStatus(404);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/psm-equipments')->assertStatus(401);
        $this->getJson('/api/psm-equipments/1')->assertStatus(401);
        $this->postJson('/api/psm-equipments/1/import')->assertStatus(401);
    }

    public function test_import_is_isolated_to_authenticated_user_company(): void
    {
        [$userA, $companyA] = $this->createProviderUser('Provider A');
        [$userB, $companyB] = $this->createProviderUser('Provider B');
        $product = $this->createCatalogProduct('SM58', 'PSM10001');

        Equipment::create([
            'user_id' => $userB->id,
            'company_id' => $companyB->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $this->withToken($this->tokenFor($userA))
            ->postJson('/api/psm-equipments/'.$product->id.'/import', [
                'company_id' => $companyB->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $companyA->id);

        $this->assertSame(1, Equipment::where('company_id', $companyA->id)->where('product_id', $product->id)->count());
        $this->assertSame(1, Equipment::where('company_id', $companyB->id)->where('product_id', $product->id)->count());
        $this->assertNotEquals(
            Equipment::where('company_id', $companyA->id)->value('id'),
            Equipment::where('company_id', $companyB->id)->value('id')
        );
    }

    public function test_non_provider_cannot_import(): void
    {
        [$user] = $this->createProviderUser('Renter Co', 'renter');
        $product = $this->createCatalogProduct('SM58', 'PSM10001');

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/psm-equipments/'.$product->id.'/import')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_NOT_PROVIDER');

        $this->assertSame(0, Equipment::count());
    }

    public function test_existing_product_details_and_company_inventory_endpoints_still_work(): void
    {
        [$user, $company] = $this->createProviderUser('Provider One');
        $product = $this->createCatalogProduct('SM58', 'PSM10001', null, [
            'replacement_price' => 100,
            'height' => 4,
            'country_of_origin' => 'US',
            'hsn_code' => '1234',
        ]);

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.product_id', $product->id)
            ->assertJsonPath('data.psm_code', 'PSM10001');

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/equipments', [
                'product_id' => $product->id,
                'quantity' => 3,
                'rental_price' => 12.5,
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/equipments')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('total', 1);

        $this->assertSame(1, Equipment::where('company_id', $company->id)->count());
        $this->assertEquals(12.5, (float) Equipment::first()->rental_price);
        $this->assertSame(3, (int) Equipment::first()->quantity);
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
            'is_admin' => 1,
            'is_company_default_contact' => 1,
            'role' => 'admin',
            'email_verified' => true,
        ]);

        return [$user, $company];
    }

    private function createCatalogProduct(
        string $model,
        string $psmCode,
        ?int $brandId = null,
        array $overrides = []
    ): Product {
        return Product::create(array_merge([
            'model' => $model,
            'psm_code' => $psmCode,
            'brand_id' => $brandId,
            'is_verified' => 1,
        ], $overrides));
    }

    private function tokenFor(User $user): string
    {
        return JWTAuth::fromUser($user);
    }

    private function createMinimalSchema(): void
    {
        Schema::dropIfExists('equipment_images');
        Schema::dropIfExists('company_inventory');
        Schema::dropIfExists('inventory_master_images');
        Schema::dropIfExists('inventory_master');
        Schema::dropIfExists('sub_categories');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('linear_units');
        Schema::dropIfExists('weight_units');
        Schema::dropIfExists('company_blocks');
        Schema::dropIfExists('users');
        Schema::dropIfExists('companies');

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('account_type')->nullable();
            $table->string('logo')->nullable();
            $table->boolean('hide_from_gear_finder')->default(false);
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
            $table->timestamp('last_seen_at')->nullable();
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
            $table->string('psm_code')->nullable();
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
            $table->decimal('replacement_price', 12, 2)->nullable();
            $table->text('description')->nullable();
            $table->string('software_code')->nullable();
            $table->string('flex_resource_id')->nullable();
            $table->string('rentman_equipment_id')->nullable();
            $table->decimal('height', 12, 2)->nullable();
            $table->decimal('width', 12, 2)->nullable();
            $table->decimal('length', 12, 2)->nullable();
            $table->decimal('weight', 12, 2)->nullable();
            $table->unsignedBigInteger('linear_unit_id')->nullable();
            $table->unsignedBigInteger('weight_unit_id')->nullable();
            $table->string('country_of_origin')->nullable();
            $table->string('hsn_code')->nullable();
            $table->timestamps();
        });

        Schema::create('equipment_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('equipment_id');
            $table->string('image_path', 512);
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('sort_order')->nullable();
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
