<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SubCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BrandDropdownCacheTest extends TestCase
{
    use DatabaseTransactions;

    public function test_new_brand_appears_in_edit_and_multi_edit_dropdowns_and_can_be_saved(): void
    {
        $admin = $this->admin();
        $existing = Brand::create(['name' => 'Existing Brand '.uniqid()]);
        $category = Category::create(['name' => 'Audio '.uniqid()]);
        $subCategory = SubCategory::create([
            'name' => 'Microphones '.uniqid(),
            'category_id' => $category->id,
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'sub_category_id' => $subCategory->id,
            'brand_id' => $existing->id,
            'model' => 'SM58 '.uniqid(),
            'psm_code' => 'BRND'.strtoupper(bin2hex(random_bytes(4))),
            'is_verified' => 1,
        ]);
        $untouched = Product::create([
            'category_id' => $category->id,
            'sub_category_id' => $subCategory->id,
            'brand_id' => $existing->id,
            'model' => 'SM57 '.uniqid(),
            'psm_code' => 'BRND'.strtoupper(bin2hex(random_bytes(4))),
            'is_verified' => 1,
        ]);

        $newName = 'Fresh Brand '.uniqid();

        $this->actingAs($admin)->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee($existing->name, false)
            ->assertDontSee($newName, false);

        $this->actingAs($admin)->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee($existing->name, false)
            ->assertSee('Not Set (-)', false)
            ->assertDontSee($newName, false);

        $this->assertTrue(Cache::has('brands_list'));

        $this->actingAs($admin)->post(route('admin.brands.store'), [
            'name' => $newName,
        ])->assertRedirect(route('admin.brands.index'));

        $newBrand = Brand::where('name', $newName)->first();
        $this->assertNotNull($newBrand);
        $this->assertFalse(Cache::has('brands_list'));

        $this->actingAs($admin)->get(route('admin.brands.index'))
            ->assertOk()
            ->assertSee($newName, false)
            ->assertSee($existing->name, false);

        $edit = $this->actingAs($admin)->get(route('admin.products.edit', $product));
        $edit->assertOk()
            ->assertSee('value="'.$newBrand->id.'"', false)
            ->assertSee($newName, false)
            ->assertSee('value="'.$existing->id.'"', false)
            ->assertSee($existing->name, false)
            ->assertSee($category->name, false)
            ->assertSee($subCategory->name, false);
        $edit->assertSee('selected', false);

        $index = $this->actingAs($admin)->get(route('admin.products.index'));
        $index->assertOk()
            ->assertSee($newName, false)
            ->assertSee($existing->name, false)
            ->assertSee('Not Set (-)', false)
            ->assertSee('id="bulkEditBrand"', false)
            ->assertSee('id="filterBrand"', false);

        $this->actingAs($admin)->put(route('admin.products.update', $product), [
            'category_id' => $category->id,
            'sub_category_id' => $subCategory->id,
            'brand_id' => $newBrand->id,
            'model' => $product->model,
            'psm_code' => $product->psm_code,
        ])->assertRedirect(route('admin.products.index'));

        $product->refresh();
        $this->assertSame((int) $newBrand->id, (int) $product->brand_id);
        $this->assertSame((int) $category->id, (int) $product->category_id);
        $this->assertSame((int) $subCategory->id, (int) $product->sub_category_id);

        $this->actingAs($admin)->postJson(route('admin.products.bulk-update'), [
            'product_ids' => [$untouched->id],
            'brand_id' => $newBrand->id,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('updated_count', 1);

        $untouched->refresh();
        $product->refresh();
        $this->assertSame((int) $newBrand->id, (int) $untouched->brand_id);
        $this->assertSame((int) $newBrand->id, (int) $product->brand_id);
        $this->assertSame((int) $category->id, (int) $untouched->category_id);
    }

    public function test_renamed_and_deleted_brands_refresh_the_dropdown_cache(): void
    {
        $admin = $this->admin();
        $brand = Brand::create(['name' => 'Rename Me '.uniqid()]);
        $category = Category::create(['name' => 'Cameras '.uniqid()]);
        $product = Product::create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'model' => 'Body '.uniqid(),
            'psm_code' => 'BRND'.strtoupper(bin2hex(random_bytes(4))),
            'is_verified' => 1,
        ]);

        $this->actingAs($admin)->get(route('admin.products.edit', $product))->assertOk();
        $this->assertTrue(Cache::has('brands_list'));

        $renamed = 'Renamed Brand '.uniqid();
        $this->actingAs($admin)->put(route('admin.brands.update', $brand), [
            'name' => $renamed,
        ])->assertRedirect(route('admin.brands.index'));

        $this->assertFalse(Cache::has('brands_list'));
        $this->actingAs($admin)->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('value="'.$brand->id.'"', false)
            ->assertSee($renamed, false)
            ->assertDontSee($brand->name, false);
        $this->actingAs($admin)->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee($renamed, false)
            ->assertDontSee($brand->name, false);

        $unused = Brand::create(['name' => 'Unused Brand '.uniqid()]);
        $this->actingAs($admin)->get(route('admin.products.edit', $product))->assertOk();
        $this->assertTrue(Cache::has('brands_list'));

        $this->actingAs($admin)->delete(route('admin.brands.destroy', $unused))
            ->assertRedirect(route('admin.brands.index'));

        $this->assertFalse(Cache::has('brands_list'));
        $this->actingAs($admin)->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertDontSee($unused->name, false)
            ->assertSee($renamed, false);
        $this->actingAs($admin)->get(route('admin.products.index'))
            ->assertOk()
            ->assertDontSee($unused->name, false)
            ->assertSee($renamed, false)
            ->assertSee('Not Set (-)', false);
    }

    private function admin(): User
    {
        return User::create([
            'account_type' => 'provider',
            'username' => 'brand_admin_'.uniqid(),
            'email' => uniqid('brand_admin_', true).'@example.com',
            'password' => 'password',
            'is_admin' => 1,
            'role' => 'super_admin',
            'email_verified' => true,
        ]);
    }
}
