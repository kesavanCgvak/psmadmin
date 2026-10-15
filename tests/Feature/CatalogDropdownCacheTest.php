<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SubCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogDropdownCacheTest extends TestCase
{
    use DatabaseTransactions;

    public function test_renamed_category_replaces_the_old_name_in_product_dropdowns(): void
    {
        $admin = $this->admin();
        $suffix = uniqid();
        $originalName = 'NETWORK '.$suffix;
        $renamed = 'NETWORKING '.$suffix;
        $category = Category::create(['name' => $originalName]);
        $other = Category::create(['name' => 'Audio '.$suffix]);
        $subCategory = SubCategory::create([
            'name' => 'Switches '.$suffix,
            'category_id' => $category->id,
        ]);
        $product = $this->product($category, $subCategory, 'Switch '.$suffix);

        $this->actingAs($admin)->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee($originalName, false)
            ->assertDontSee($renamed, false);

        $this->actingAs($admin)->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee($originalName, false)
            ->assertSee('Not Set (-)', false);

        $this->assertTrue(Cache::has('categories_list'));

        $this->actingAs($admin)->put(route('admin.categories.update', $category), [
            'name' => $renamed,
        ])->assertRedirect(route('admin.categories.index'));

        $this->assertFalse(Cache::has('categories_list'));
        $category->refresh();
        $product->refresh();
        $this->assertSame($renamed, $category->name);
        $this->assertSame((int) $category->id, (int) $product->category_id);

        $this->actingAs($admin)->get(route('admin.categories.index'))
            ->assertOk()
            ->assertSee($renamed, false)
            ->assertDontSee($originalName, false);

        $edit = $this->actingAs($admin)->get(route('admin.products.edit', $product));
        $edit->assertOk()
            ->assertSee('value="'.$category->id.'"', false)
            ->assertSee($renamed, false)
            ->assertSee($other->name, false)
            ->assertSee($subCategory->name, false)
            ->assertDontSee($originalName, false);

        $this->actingAs($admin)->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee($renamed, false)
            ->assertSee($other->name, false)
            ->assertSee($subCategory->name, false)
            ->assertDontSee($originalName, false)
            ->assertSee('id="bulkEditCategory"', false)
            ->assertSee('Not Set (-)', false);
    }

    public function test_new_category_appears_in_edit_and_multi_edit_and_deleted_category_does_not(): void
    {
        $admin = $this->admin();
        $suffix = uniqid();
        $existing = Category::create(['name' => 'Lighting '.$suffix]);
        $subCategory = SubCategory::create([
            'name' => 'Par Cans '.$suffix,
            'category_id' => $existing->id,
        ]);
        $product = $this->product($existing, $subCategory, 'Par '.$suffix);
        $newName = 'Video '.$suffix;

        $this->actingAs($admin)->get(route('admin.products.edit', $product))->assertOk();
        $this->assertTrue(Cache::has('categories_list'));

        $this->actingAs($admin)->post(route('admin.categories.store'), [
            'name' => $newName,
        ])->assertRedirect(route('admin.categories.index'));

        $created = Category::where('name', $newName)->first();
        $this->assertNotNull($created);
        $this->assertFalse(Cache::has('categories_list'));

        $this->actingAs($admin)->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee($newName, false)
            ->assertSee($existing->name, false);

        $this->actingAs($admin)->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee($newName, false)
            ->assertSee($existing->name, false);

        $this->actingAs($admin)->delete(route('admin.categories.destroy', $created))
            ->assertRedirect(route('admin.categories.index'));

        $this->assertFalse(Cache::has('categories_list'));
        $this->actingAs($admin)->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertDontSee($newName, false)
            ->assertSee($existing->name, false)
            ->assertSee($subCategory->name, false);
        $this->actingAs($admin)->get(route('admin.products.index'))
            ->assertOk()
            ->assertDontSee($newName, false)
            ->assertSee($existing->name, false);

        $product->refresh();
        $this->assertSame((int) $existing->id, (int) $product->category_id);
        $this->assertSame((int) $subCategory->id, (int) $product->sub_category_id);
    }

    public function test_category_cache_is_cleared_only_after_commit(): void
    {
        Cache::put('categories_list', 'stale', 3600);

        DB::beginTransaction();
        $category = Category::create(['name' => 'Rolled Back '.uniqid()]);
        $this->assertTrue(Cache::has('categories_list'));
        DB::rollBack();

        $this->assertTrue(Cache::has('categories_list'));
        $this->assertNull(Category::find($category->id));

        Cache::put('categories_list', 'stale', 3600);
        DB::beginTransaction();
        Category::create(['name' => 'Committed '.uniqid()]);
        $this->assertTrue(Cache::has('categories_list'));
        DB::commit();

        $this->assertFalse(Cache::has('categories_list'));
    }

    public function test_created_and_renamed_subcategories_show_in_dependent_dropdowns(): void
    {
        $admin = $this->admin();
        $suffix = uniqid();
        $networking = Category::create(['name' => 'Networking '.$suffix]);
        $audio = Category::create(['name' => 'Audio '.$suffix]);
        $switches = SubCategory::create([
            'name' => 'Switches '.$suffix,
            'category_id' => $networking->id,
        ]);
        $product = $this->product($networking, $switches, 'Core Switch '.$suffix);
        $createdName = 'Routers '.$suffix;
        $renamed = 'Gateways '.$suffix;

        $this->actingAs($admin)->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee($switches->name, false)
            ->assertDontSee($createdName, false);

        $this->assertFalse(Cache::has('subcategories_list'));
        Cache::put('subcategories_list', 'stale', 3600);

        $this->actingAs($admin)->post(route('admin.subcategories.store'), [
            'category_id' => $networking->id,
            'name' => $createdName,
        ])->assertRedirect(route('admin.subcategories.index'));

        $created = SubCategory::where('name', $createdName)->first();
        $this->assertNotNull($created);
        $this->assertFalse(Cache::has('subcategories_list'));

        $other = SubCategory::create([
            'name' => 'Microphones '.$suffix,
            'category_id' => $audio->id,
        ]);

        $edit = $this->actingAs($admin)->get(route('admin.products.edit', $product));
        $edit->assertOk()
            ->assertSee($createdName, false)
            ->assertSee($switches->name, false)
            ->assertDontSee($other->name, false);

        $forNetworking = $this->actingAs($admin)->getJson(
            route('admin.ajax.subcategories-by-category', ['category' => $networking->id])
        );
        $forNetworking->assertOk();
        $networkingNames = collect($forNetworking->json())->pluck('name')->all();
        $this->assertContains($createdName, $networkingNames);
        $this->assertContains($switches->name, $networkingNames);
        $this->assertNotContains($other->name, $networkingNames);

        $forAudio = $this->actingAs($admin)->getJson(
            route('admin.ajax.subcategories-by-category', ['category' => $audio->id])
        );
        $forAudio->assertOk();
        $audioNames = collect($forAudio->json())->pluck('name')->all();
        $this->assertContains($other->name, $audioNames);
        $this->assertNotContains($createdName, $audioNames);

        $this->actingAs($admin)->put(route('admin.subcategories.update', $created), [
            'category_id' => $networking->id,
            'name' => $renamed,
        ])->assertRedirect(route('admin.subcategories.index'));

        $this->actingAs($admin)->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee($renamed, false)
            ->assertSee($networking->name, false)
            ->assertDontSee($createdName, false)
            ->assertSee('id="bulkEditSubCategory"', false);

        $product->refresh();
        $this->assertSame((int) $switches->id, (int) $product->sub_category_id);
        $this->assertSame((int) $networking->id, (int) $product->category_id);

        Cache::put('subcategories_list', 'stale', 3600);
        $this->actingAs($admin)->delete(route('admin.subcategories.destroy', $created))
            ->assertRedirect(route('admin.subcategories.index'));
        $this->assertFalse(Cache::has('subcategories_list'));

        $this->actingAs($admin)->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertDontSee($renamed, false)
            ->assertSee($switches->name, false);
    }

    private function admin(): User
    {
        return User::create([
            'account_type' => 'provider',
            'username' => 'catalog_admin_'.uniqid(),
            'email' => uniqid('catalog_admin_', true).'@example.com',
            'password' => 'password',
            'is_admin' => 1,
            'role' => 'super_admin',
            'email_verified' => true,
        ]);
    }

    private function product(Category $category, SubCategory $subCategory, string $model): Product
    {
        $brand = Brand::create(['name' => 'Catalog Brand '.uniqid()]);

        return Product::create([
            'category_id' => $category->id,
            'sub_category_id' => $subCategory->id,
            'brand_id' => $brand->id,
            'model' => $model,
            'psm_code' => 'CTLG'.strtoupper(bin2hex(random_bytes(4))),
            'is_verified' => 1,
        ]);
    }
}
