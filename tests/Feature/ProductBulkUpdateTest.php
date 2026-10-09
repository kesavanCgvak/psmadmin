<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SubCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PDO;
use Tests\TestCase;

class ProductBulkUpdateTest extends TestCase
{
    use DatabaseTransactions;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=psmadmin_testing', 'root', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $columns = $pdo->query('SHOW COLUMNS FROM inventory_master')->fetchAll(PDO::FETCH_COLUMN);

        if (! in_array('category_id', $columns, true)) {
            $pdo->exec('ALTER TABLE inventory_master ADD COLUMN category_id BIGINT UNSIGNED NULL');
        }
        if (! in_array('sub_category_id', $columns, true)) {
            $pdo->exec('ALTER TABLE inventory_master ADD COLUMN sub_category_id BIGINT UNSIGNED NULL');
        }
    }

    public function test_admin_can_bulk_update_category_for_one_product(): void
    {
        $admin = $this->admin();
        $catalog = $this->catalog();
        $product = $this->product($catalog['audio'], $catalog['mics'], $catalog['canon'], 'Mic');

        $response = $this->actingAs($admin)->postJson(route('admin.products.bulk-update'), [
            'product_ids' => [$product->id],
            'category_id' => $catalog['cameras']->id,
            'sub_category_id' => $catalog['cinema']->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('updated_count', 1)
            ->assertJsonPath('message', '1 product updated successfully.');

        $product->refresh();
        $this->assertSame((int) $catalog['cameras']->id, (int) $product->category_id);
        $this->assertSame((int) $catalog['cinema']->id, (int) $product->sub_category_id);
        $this->assertSame((int) $catalog['canon']->id, (int) $product->brand_id);
    }

    public function test_admin_can_bulk_update_category_for_multiple_products(): void
    {
        $admin = $this->admin();
        $catalog = $this->catalog();
        $first = $this->product($catalog['audio'], $catalog['mics'], $catalog['canon'], 'Mic A');
        $second = $this->product($catalog['audio'], $catalog['mics'], $catalog['sony'], 'Mic B');
        $untouched = $this->product($catalog['audio'], $catalog['mics'], $catalog['canon'], 'Mic C');

        $response = $this->actingAs($admin)->postJson(route('admin.products.bulk-update'), [
            'product_ids' => [$first->id, $second->id],
            'category_id' => $catalog['cameras']->id,
            'sub_category_id' => $catalog['cinema']->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('updated_count', 2)
            ->assertJsonPath('message', '2 products updated successfully.');

        $first->refresh();
        $second->refresh();
        $untouched->refresh();

        $this->assertSame((int) $catalog['cameras']->id, (int) $first->category_id);
        $this->assertSame((int) $catalog['cinema']->id, (int) $second->sub_category_id);
        $this->assertSame((int) $catalog['audio']->id, (int) $untouched->category_id);
        $this->assertSame((int) $catalog['mics']->id, (int) $untouched->sub_category_id);
    }

    public function test_subcategory_only_update_keeps_category_and_brand(): void
    {
        $admin = $this->admin();
        $catalog = $this->catalog();
        $broadcast = SubCategory::create([
            'name' => 'Broadcast '.uniqid(),
            'category_id' => $catalog['cameras']->id,
        ]);
        $product = $this->product($catalog['cameras'], $catalog['cinema'], $catalog['canon'], 'FX9');

        $this->actingAs($admin)->postJson(route('admin.products.bulk-update'), [
            'product_ids' => [$product->id],
            'sub_category_id' => $broadcast->id,
        ])->assertOk()->assertJsonPath('success', true);

        $product->refresh();
        $this->assertSame((int) $catalog['cameras']->id, (int) $product->category_id);
        $this->assertSame((int) $broadcast->id, (int) $product->sub_category_id);
        $this->assertSame((int) $catalog['canon']->id, (int) $product->brand_id);
    }

    public function test_brand_only_update_keeps_category_and_subcategory(): void
    {
        $admin = $this->admin();
        $catalog = $this->catalog();
        $product = $this->product($catalog['cameras'], $catalog['cinema'], $catalog['canon'], 'FX6');
        $originalCategory = (int) $product->category_id;
        $originalSubCategory = (int) $product->sub_category_id;

        $this->actingAs($admin)->postJson(route('admin.products.bulk-update'), [
            'product_ids' => [$product->id],
            'brand_id' => $catalog['sony']->id,
        ])->assertOk()->assertJsonPath('updated_count', 1);

        $product->refresh();
        $this->assertSame((int) $catalog['sony']->id, (int) $product->brand_id);
        $this->assertSame($originalCategory, (int) $product->category_id);
        $this->assertSame($originalSubCategory, (int) $product->sub_category_id);
        $this->assertStringContainsString('sony', (string) $product->normalized_full_name);
    }

    public function test_category_subcategory_and_brand_can_be_updated_together(): void
    {
        $admin = $this->admin();
        $catalog = $this->catalog();
        $first = $this->product($catalog['audio'], $catalog['mics'], $catalog['canon'], 'Body A');
        $second = $this->product($catalog['audio'], $catalog['mics'], $catalog['canon'], 'Body B');

        $this->actingAs($admin)->postJson(route('admin.products.bulk-update'), [
            'product_ids' => [$first->id, $second->id],
            'category_id' => $catalog['cameras']->id,
            'sub_category_id' => $catalog['cinema']->id,
            'brand_id' => $catalog['sony']->id,
        ])->assertOk()->assertJsonPath('updated_count', 2);

        foreach ([$first, $second] as $product) {
            $product->refresh();
            $this->assertSame((int) $catalog['cameras']->id, (int) $product->category_id);
            $this->assertSame((int) $catalog['cinema']->id, (int) $product->sub_category_id);
            $this->assertSame((int) $catalog['sony']->id, (int) $product->brand_id);
        }
    }

    public function test_invalid_category_relationship_does_not_change_products(): void
    {
        $admin = $this->admin();
        $catalog = $this->catalog();
        $product = $this->product($catalog['cameras'], $catalog['cinema'], $catalog['canon'], 'FX3');

        $this->actingAs($admin)->postJson(route('admin.products.bulk-update'), [
            'product_ids' => [$product->id],
            'sub_category_id' => $catalog['mics']->id,
        ])->assertStatus(422)->assertJsonPath('success', false);

        $this->actingAs($admin)->postJson(route('admin.products.bulk-update'), [
            'product_ids' => [$product->id],
            'category_id' => $catalog['audio']->id,
        ])->assertStatus(422)->assertJsonPath('success', false);

        $this->actingAs($admin)->postJson(route('admin.products.bulk-update'), [
            'product_ids' => [$product->id],
            'category_id' => $catalog['cameras']->id,
            'sub_category_id' => $catalog['mics']->id,
        ])->assertStatus(422);

        $product->refresh();
        $this->assertSame((int) $catalog['cameras']->id, (int) $product->category_id);
        $this->assertSame((int) $catalog['cinema']->id, (int) $product->sub_category_id);
        $this->assertSame((int) $catalog['canon']->id, (int) $product->brand_id);
    }

    public function test_validation_rejects_missing_products_and_unknown_ids(): void
    {
        $admin = $this->admin();
        $catalog = $this->catalog();

        $this->actingAs($admin)->postJson(route('admin.products.bulk-update'), [
            'product_ids' => [],
            'brand_id' => $catalog['sony']->id,
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson(route('admin.products.bulk-update'), [
            'product_ids' => [99999999],
            'brand_id' => $catalog['sony']->id,
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson(route('admin.products.bulk-update'), [
            'product_ids' => [$this->product($catalog['cameras'], $catalog['cinema'], $catalog['canon'], 'A7')->id],
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson(route('admin.products.bulk-update'), [
            'product_ids' => [$this->product($catalog['cameras'], $catalog['cinema'], $catalog['canon'], 'A7S')->id],
            'category_id' => 99999999,
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson(route('admin.products.bulk-update'), [
            'product_ids' => [$this->product($catalog['cameras'], $catalog['cinema'], $catalog['canon'], 'A1')->id],
            'brand_id' => 99999999,
        ])->assertStatus(422);
    }

    public function test_guest_and_non_admin_cannot_bulk_update(): void
    {
        $catalog = $this->catalog();
        $product = $this->product($catalog['cameras'], $catalog['cinema'], $catalog['canon'], 'Locked');

        $this->postJson(route('admin.products.bulk-update'), [
            'product_ids' => [$product->id],
            'brand_id' => $catalog['sony']->id,
        ])->assertUnauthorized();

        $user = User::create([
            'account_type' => 'provider',
            'username' => 'bulk_user_'.uniqid(),
            'email' => uniqid('bulk_user_', true).'@example.com',
            'password' => 'password',
            'is_admin' => 0,
            'role' => 'user',
            'email_verified' => true,
        ]);

        $this->actingAs($user)->postJson(route('admin.products.bulk-update'), [
            'product_ids' => [$product->id],
            'brand_id' => $catalog['sony']->id,
        ])->assertForbidden();

        $product->refresh();
        $this->assertSame((int) $catalog['canon']->id, (int) $product->brand_id);
    }

    public function test_products_index_includes_multi_edit_controls(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get(route('admin.products.index'));

        $response->assertOk();
        $response->assertSee('id="bulkEditBtn"', false);
        $response->assertSee('id="selectAll"', false);
        $response->assertSee('id="bulkEditModal"', false);
        $response->assertSee('id="selectedProductsSummary"', false);
        $response->assertSee('Apply Changes', false);
    }

    private function admin(): User
    {
        return User::create([
            'account_type' => 'provider',
            'username' => 'bulk_admin_'.uniqid(),
            'email' => uniqid('bulk_admin_', true).'@example.com',
            'password' => 'password',
            'is_admin' => 1,
            'role' => 'super_admin',
            'email_verified' => true,
        ]);
    }

    private function catalog(): array
    {
        $cameras = Category::create(['name' => 'Cameras '.uniqid()]);
        $audio = Category::create(['name' => 'Audio '.uniqid()]);
        $cinema = SubCategory::create([
            'name' => 'Cinema Cameras '.uniqid(),
            'category_id' => $cameras->id,
        ]);
        $mics = SubCategory::create([
            'name' => 'Microphones '.uniqid(),
            'category_id' => $audio->id,
        ]);
        $sony = Brand::create(['name' => 'Sony '.uniqid()]);
        $canon = Brand::create(['name' => 'Canon '.uniqid()]);

        return compact('cameras', 'audio', 'cinema', 'mics', 'sony', 'canon');
    }

    private function product(Category $category, SubCategory $subCategory, Brand $brand, string $model): Product
    {
        return Product::create([
            'category_id' => $category->id,
            'sub_category_id' => $subCategory->id,
            'brand_id' => $brand->id,
            'model' => $model.' '.uniqid(),
            'psm_code' => 'BULK'.strtoupper(bin2hex(random_bytes(4))),
            'is_verified' => 0,
        ]);
    }
}
