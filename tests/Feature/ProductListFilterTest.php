<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SubCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use PDO;
use Tests\TestCase;

class ProductListFilterTest extends TestCase
{
    use DatabaseTransactions;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=psmadmin_testing', 'root', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $columns = $pdo->query('SHOW COLUMNS FROM inventory_master')->fetchAll(PDO::FETCH_COLUMN);
        $optionalColumns = [
            'category_id' => 'BIGINT UNSIGNED NULL',
            'sub_category_id' => 'BIGINT UNSIGNED NULL',
            'height' => 'DECIMAL(10,2) NULL',
            'width' => 'DECIMAL(10,2) NULL',
            'length' => 'DECIMAL(10,2) NULL',
            'weight' => 'DECIMAL(10,2) NULL',
            'linear_unit_id' => 'BIGINT UNSIGNED NULL',
            'weight_unit_id' => 'BIGINT UNSIGNED NULL',
            'replacement_price' => 'DECIMAL(12,2) NULL',
        ];

        foreach ($optionalColumns as $column => $definition) {
            if (! in_array($column, $columns, true)) {
                $pdo->exec("ALTER TABLE inventory_master ADD COLUMN {$column} {$definition}");
            }
        }
    }

    public function test_products_index_renders_catalog_filters_beside_existing_controls(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get(route('admin.products.index'));

        $response->assertOk();
        $response->assertSee('id="filterBrand"', false);
        $response->assertSee('id="filterCategory"', false);
        $response->assertSee('id="filterSubCategory"', false);
        $response->assertSee('id="clearProductFilters"', false);
        $response->assertSee('Clear Filters', false);
        $response->assertSee('id="filterUnverified"', false);
        $response->assertSee('id="bulkEditBtn"', false);
        $response->assertSee('d.brand_id = brandId;', false);
        $response->assertSee('d.category_id = categoryId;', false);
        $response->assertSee('d.sub_category_id = subCategoryId;', false);
        $response->assertSee('populateSubCategoryFilter', false);
        $response->assertSee("localStorage.setItem('products_filter_page', '0');", false);
        $response->assertSee('Not Set (-)', false);
        $response->assertSee("var unsetCatalogValue = 'none';", false);
        $response->assertSee('categoryId !== unsetCatalogValue', false);
        $response->assertSee('Equipments Management', false);
        $response->assertSee('All Equipments', false);
        $response->assertSee('Companies Inventory', false);
        $response->assertSee('id="productsAllBrowser"', false);
        $response->assertSee('id="productsListRetry"', false);
        $response->assertSee('Number(data.length) === -1', false);
        $response->assertSee('data.length = PRODUCT_LIST_BATCH', false);
        $response->assertSee('total <= PRODUCT_LIST_BATCH', false);
        $response->assertSee('skipLargeAllMode', false);
        $response->assertSee('[[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]]', false);
        $response->assertSee('currentBulkSelection', false);
        $response->assertDontSee('>Products<', false);
        $response->assertDontSee('All Equipment<', false);
    }

    public function test_page_sizes_stay_bounded_and_all_is_rejected_as_one_request(): void
    {
        $admin = $this->admin();
        $catalog = $this->catalog();
        $token = 'PageSize'.uniqid();
        $created = [];

        for ($i = 0; $i < 30; $i++) {
            $created[] = $this->catalogProduct(
                $catalog['cameras'],
                $catalog['cinema'],
                $catalog['sony'],
                $token.'-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)
            );
        }

        $this->actingAs($admin);

        $filters = [
            'search' => ['value' => $token],
            'order' => [['column' => 3, 'dir' => 'asc']],
        ];

        foreach ([10 => 10, 25 => 25, 50 => 30, 100 => 30, 101 => 30, 500 => 30] as $length => $expectedCount) {
            $page = $this->fetchProducts($filters, 0, $length);
            $page->assertOk();
            $page->assertJsonMissingPath('error');
            $this->assertSame(30, $page->json('recordsFiltered'));
            $this->assertCount($expectedCount, $page->json('data'));
        }

        $batches = [];
        for ($start = 0; $start < 30; $start += 10) {
            $batch = $this->fetchProducts($filters, $start, 10);
            $batch->assertOk();
            $this->assertSame(30, $batch->json('recordsFiltered'));
            $batches[] = array_map('intval', array_column($batch->json('data'), 'id'));
        }

        $this->assertSame(
            array_map(fn (Product $product) => (int) $product->id, $created),
            array_merge(...$batches)
        );
        $this->assertSame((int) $created[0]->id, $batches[0][0]);
        $this->assertSame((int) $created[29]->id, $batches[2][9]);

        foreach ([-1, 0, \App\Support\AdminListLength::MAX_PAGE_SIZE + 1] as $length) {
            $rejected = $this->fetchProducts($filters, 0, $length);
            $rejected->assertStatus(422);
            $rejected->assertJsonPath('data', []);
            $this->assertNotSame('', (string) $rejected->json('error'));
        }
    }

    public function test_admin_navigation_labels_for_equipments_and_company_inventory(): void
    {
        $menu = collect(config('adminlte.menu'));

        $this->assertTrue($menu->contains(fn ($item) => ($item['text'] ?? null) === 'Equipments'
            && ($item['route'] ?? null) === 'admin.products.index'));
        $this->assertTrue($menu->contains(fn ($item) => ($item['text'] ?? null) === 'Companies Inventory'
            && ($item['route'] ?? null) === 'admin.equipment.index'));
        $this->assertFalse($menu->contains(fn ($item) => ($item['text'] ?? null) === 'Products'
            && ($item['route'] ?? null) === 'admin.products.index'));
        $this->assertFalse($menu->contains(fn ($item) => ($item['text'] ?? null) === 'All Equipment'));

        $equipmentIndex = file_get_contents(resource_path('views/admin/companies/equipment/index.blade.php'));
        $this->assertIsString($equipmentIndex);
        $this->assertStringContainsString('Companies Inventory', $equipmentIndex);
        $this->assertStringNotContainsString('All Equipment', $equipmentIndex);

        $usersIndex = file_get_contents(resource_path('views/admin/users/index.blade.php'));
        $this->assertIsString($usersIndex);
        $this->assertStringContainsString('[[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]]', $usersIndex);
        $this->assertStringNotContainsString('productsViewAll', $usersIndex);
        $this->assertStringContainsString('attachAdminAllBatches(table)', $usersIndex);

        $batches = file_get_contents(resource_path('views/partials/admin-all-batches-js.blade.php'));
        $this->assertIsString($batches);
        $this->assertStringContainsString('var ADMIN_LIST_BATCH = 500;', $batches);
        $responsiveJs = file_get_contents(resource_path('views/partials/responsive-js.blade.php'));
        $this->assertIsString($responsiveJs);
        $this->assertStringContainsString('attachAdminAllBatches(table)', $responsiveJs);
    }

    public function test_not_set_filters_match_null_and_unassigned_relations(): void
    {
        $admin = $this->admin();
        $catalog = $this->catalog();
        $token = 'Unset'.uniqid();

        $assigned = $this->catalogProduct($catalog['cameras'], $catalog['cinema'], $catalog['sony'], $token.'Assigned');
        $noBrand = $this->catalogProduct($catalog['cameras'], $catalog['cinema'], null, $token.'NoBrand');
        $noCategory = $this->catalogProduct(null, null, $catalog['sony'], $token.'NoCategory');
        $noSub = $this->catalogProduct($catalog['cameras'], null, $catalog['canon'], $token.'NoSub');
        $combo = $this->catalogProduct($catalog['cameras'], null, null, $token.'Combo');
        $comboAudio = $this->catalogProduct($catalog['audio'], null, null, $token.'ComboAudio');
        $comboHasSub = $this->catalogProduct($catalog['cameras'], $catalog['cinema'], null, $token.'ComboSub');

        $removedBrand = Brand::create(['name' => 'Removed '.uniqid()]);
        $orphanBrand = $this->catalogProduct($catalog['cameras'], $catalog['cinema'], $removedBrand, $token.'OrphanBrand');
        $removedBrand->delete();

        $removedSub = SubCategory::create([
            'name' => 'Removed Sub '.uniqid(),
            'category_id' => $catalog['cameras']->id,
        ]);
        $orphanSub = $this->catalogProduct($catalog['cameras'], $removedSub, $catalog['canon'], $token.'OrphanSub');
        $removedSub->delete();

        $noBrand->is_verified = 1;
        $noBrand->save();

        $this->actingAs($admin);

        $missingBrand = $this->fetchProducts([
            'brand_id' => 'none',
            'search' => ['value' => $token],
        ]);
        $this->assertFilteredProducts($missingBrand, [$noBrand, $orphanBrand, $combo, $comboAudio, $comboHasSub]);
        foreach ($missingBrand->json('data') as $row) {
            $this->assertSame("\u{2014}", $row['brand']);
        }

        $this->assertFilteredProducts(
            $this->fetchProducts([
                'brand_id' => $catalog['sony']->id,
                'search' => ['value' => $token],
            ]),
            [$assigned, $noCategory]
        );

        $missingCategory = $this->fetchProducts([
            'category_id' => 'none',
            'search' => ['value' => $token],
        ]);
        $this->assertFilteredProducts($missingCategory, [$noCategory]);
        $this->assertSame("\u{2014}", $missingCategory->json('data.0.category'));

        $this->assertFilteredProducts(
            $this->fetchProducts([
                'category_id' => $catalog['cameras']->id,
                'search' => ['value' => $token],
            ]),
            [$assigned, $noBrand, $noSub, $combo, $comboHasSub, $orphanBrand, $orphanSub]
        );

        $missingSub = $this->fetchProducts([
            'sub_category_id' => 'none',
            'search' => ['value' => $token],
        ]);
        $this->assertFilteredProducts($missingSub, [$noCategory, $noSub, $combo, $comboAudio, $orphanSub]);
        foreach ($missingSub->json('data') as $row) {
            $this->assertSame("\u{2014}", $row['sub_category']);
        }

        $this->assertFilteredProducts(
            $this->fetchProducts([
                'sub_category_id' => $catalog['cinema']->id,
                'search' => ['value' => $token],
            ]),
            [$assigned, $noBrand, $orphanBrand, $comboHasSub]
        );

        $this->assertFilteredProducts(
            $this->fetchProducts([
                'brand_id' => 'none',
                'category_id' => $catalog['cameras']->id,
                'sub_category_id' => 'none',
                'search' => ['value' => $token],
            ]),
            [$combo]
        );

        $this->assertFilteredProducts(
            $this->fetchProducts([
                'brand_id' => $catalog['sony']->id,
                'category_id' => $catalog['cameras']->id,
                'sub_category_id' => $catalog['cinema']->id,
                'search' => ['value' => $token],
            ]),
            [$assigned]
        );

        $this->assertFilteredProducts(
            $this->fetchProducts([
                'brand_id' => 'none',
                'unverified_only' => '1',
                'search' => ['value' => $token],
            ]),
            [$orphanBrand, $combo, $comboAudio, $comboHasSub]
        );

        $this->assertFilteredProducts(
            $this->fetchProducts([
                'search' => ['value' => $token.'NoBrand'],
                'brand_id' => 'none',
            ]),
            [$noBrand]
        );

        $this->assertFilteredProducts(
            $this->fetchProducts(['search' => ['value' => $token]]),
            [$assigned, $noBrand, $noCategory, $noSub, $combo, $comboAudio, $comboHasSub, $orphanBrand, $orphanSub]
        );
    }

    public function test_not_set_filters_paginate_the_full_dataset_and_keep_multi_edit(): void
    {
        $admin = $this->admin();
        $catalog = $this->catalog();
        $token = 'Page'.uniqid();
        $category = Category::create(['name' => 'Paged '.uniqid()]);
        $keptSub = SubCategory::create([
            'name' => 'Kept '.uniqid(),
            'category_id' => $category->id,
        ]);

        $first = $this->catalogProduct($category, null, null, $token.'A');
        $second = $this->catalogProduct($category, null, null, $token.'B');
        $third = $this->catalogProduct($category, null, $catalog['sony'], $token.'C');
        $withSub = $this->catalogProduct($category, $keptSub, null, $token.'Kept');

        $this->actingAs($admin);

        $filters = [
            'category_id' => $category->id,
            'sub_category_id' => 'none',
            'search' => ['value' => $token],
            'order' => [['column' => 1, 'dir' => 'asc']],
        ];

        $pageOne = $this->fetchProducts($filters, 0, 1);
        $pageTwo = $this->fetchProducts($filters, 1, 1);
        $pageThree = $this->fetchProducts($filters, 2, 1);

        foreach ([$pageOne, $pageTwo, $pageThree] as $page) {
            $page->assertOk();
            $this->assertSame(3, $page->json('recordsFiltered'));
            $this->assertCount(1, $page->json('data'));
        }

        $pagedIds = [
            (int) $pageOne->json('data.0.id'),
            (int) $pageTwo->json('data.0.id'),
            (int) $pageThree->json('data.0.id'),
        ];
        sort($pagedIds);
        $expectedIds = [(int) $first->id, (int) $second->id, (int) $third->id];
        sort($expectedIds);
        $this->assertSame($expectedIds, $pagedIds);
        $this->assertNotContains((int) $withSub->id, $pagedIds);

        $listed = $this->fetchProducts([
            'brand_id' => 'none',
            'category_id' => $category->id,
            'sub_category_id' => 'none',
            'search' => ['value' => $token],
        ]);
        $this->assertFilteredProducts($listed, [$first, $second]);

        $this->postJson(route('admin.products.bulk-update'), [
            'product_ids' => [(int) $first->id],
            'brand_id' => $catalog['canon']->id,
            'category_id' => $category->id,
            'sub_category_id' => $keptSub->id,
        ])->assertOk()->assertJsonPath('updated_count', 1);

        $first->refresh();
        $this->assertSame((int) $catalog['canon']->id, (int) $first->brand_id);
        $this->assertSame((int) $keptSub->id, (int) $first->sub_category_id);

        $this->assertFilteredProducts(
            $this->fetchProducts([
                'brand_id' => 'none',
                'category_id' => $category->id,
                'sub_category_id' => 'none',
                'search' => ['value' => $token],
            ]),
            [$second]
        );
        $this->assertFilteredProducts(
            $this->fetchProducts([
                'brand_id' => $catalog['canon']->id,
                'category_id' => $category->id,
                'sub_category_id' => $keptSub->id,
                'search' => ['value' => $token],
            ]),
            [$first]
        );
    }

    public function test_catalog_filters_can_be_combined(): void
    {
        $admin = $this->admin();
        $catalog = $this->catalog();
        $sonyCinema = $this->product($catalog['cameras'], $catalog['cinema'], $catalog['sony'], 'FX9');
        $sonyMic = $this->product($catalog['audio'], $catalog['mics'], $catalog['sony'], 'Mic');
        $canonCinema = $this->product($catalog['cameras'], $catalog['cinema'], $catalog['canon'], 'C300');

        $this->actingAs($admin);

        $this->assertFilteredProducts(
            $this->fetchProducts(['brand_id' => $catalog['sony']->id]),
            [$sonyCinema, $sonyMic]
        );
        $this->assertFilteredProducts(
            $this->fetchProducts(['category_id' => $catalog['cameras']->id]),
            [$sonyCinema, $canonCinema]
        );
        $this->assertFilteredProducts(
            $this->fetchProducts(['sub_category_id' => $catalog['mics']->id]),
            [$sonyMic]
        );
        $this->assertFilteredProducts(
            $this->fetchProducts([
                'brand_id' => $catalog['sony']->id,
                'category_id' => $catalog['cameras']->id,
            ]),
            [$sonyCinema]
        );
        $this->assertFilteredProducts(
            $this->fetchProducts([
                'brand_id' => $catalog['canon']->id,
                'sub_category_id' => $catalog['cinema']->id,
            ]),
            [$canonCinema]
        );
        $this->assertFilteredProducts(
            $this->fetchProducts([
                'category_id' => $catalog['cameras']->id,
                'sub_category_id' => $catalog['cinema']->id,
            ]),
            [$sonyCinema, $canonCinema]
        );
        $this->assertFilteredProducts(
            $this->fetchProducts([
                'brand_id' => $catalog['sony']->id,
                'category_id' => $catalog['cameras']->id,
                'sub_category_id' => $catalog['cinema']->id,
            ]),
            [$sonyCinema]
        );
        $this->assertFilteredProducts(
            $this->fetchProducts([
                'brand_id' => $catalog['sony']->id,
                'category_id' => $catalog['audio']->id,
                'sub_category_id' => $catalog['cinema']->id,
            ]),
            []
        );
    }

    public function test_catalog_filters_keep_search_sort_pagination_and_unverified_filter(): void
    {
        $admin = $this->admin();
        $catalog = $this->catalog();
        $token = 'Filt'.uniqid();
        $alpha = $this->product($catalog['cameras'], $catalog['cinema'], $catalog['sony'], $token.'Alpha');
        $beta = $this->product($catalog['cameras'], $catalog['cinema'], $catalog['sony'], $token.'Beta');
        $verified = $this->product($catalog['cameras'], $catalog['cinema'], $catalog['canon'], $token.'Verified');
        $verified->is_verified = 1;
        $verified->save();

        $this->actingAs($admin);

        $this->assertFilteredProducts(
            $this->fetchProducts([
                'brand_id' => $catalog['sony']->id,
                'search' => ['value' => $token.'Alpha'],
                'order' => [['column' => 3, 'dir' => 'asc']],
            ]),
            [$alpha]
        );

        $this->assertFilteredProducts(
            $this->fetchProducts([
                'search' => ['value' => $token],
                'brand_id' => 'not-a-number',
            ]),
            [$alpha, $beta, $verified]
        );

        $firstPage = $this->fetchProducts([
            'category_id' => $catalog['cameras']->id,
            'search' => ['value' => $token],
            'order' => [['column' => 1, 'dir' => 'asc']],
        ], 0, 1);
        $secondPage = $this->fetchProducts([
            'category_id' => $catalog['cameras']->id,
            'search' => ['value' => $token],
            'order' => [['column' => 1, 'dir' => 'asc']],
        ], 1, 1);

        $firstPage->assertOk();
        $secondPage->assertOk();
        $this->assertSame(3, $firstPage->json('recordsFiltered'));
        $this->assertSame(3, $secondPage->json('recordsFiltered'));
        $this->assertGreaterThanOrEqual(3, $firstPage->json('recordsTotal'));
        $this->assertCount(1, $firstPage->json('data'));
        $this->assertCount(1, $secondPage->json('data'));
        $this->assertNotSame($firstPage->json('data.0.id'), $secondPage->json('data.0.id'));

        $this->assertFilteredProducts(
            $this->fetchProducts([
                'brand_id' => $catalog['canon']->id,
                'unverified_only' => '1',
                'search' => ['value' => $token],
            ]),
            []
        );
        $this->assertFilteredProducts(
            $this->fetchProducts([
                'brand_id' => $catalog['sony']->id,
                'unverified_only' => '1',
                'search' => ['value' => $token],
            ]),
            [$alpha, $beta]
        );
    }

    public function test_multi_edit_still_updates_products_found_by_the_list_filters(): void
    {
        $admin = $this->admin();
        $catalog = $this->catalog();
        $product = $this->product($catalog['audio'], $catalog['mics'], $catalog['sony'], 'Mic');

        $this->actingAs($admin);

        $listed = $this->fetchProducts(['brand_id' => $catalog['sony']->id, 'category_id' => $catalog['audio']->id]);
        $this->assertFilteredProducts($listed, [$product]);

        $this->postJson(route('admin.products.bulk-update'), [
            'product_ids' => [(int) $listed->json('data.0.id')],
            'category_id' => $catalog['cameras']->id,
            'sub_category_id' => $catalog['cinema']->id,
        ])->assertOk()->assertJsonPath('updated_count', 1);

        $product->refresh();
        $this->assertSame((int) $catalog['cameras']->id, (int) $product->category_id);
        $this->assertSame((int) $catalog['cinema']->id, (int) $product->sub_category_id);
        $this->assertSame((int) $catalog['sony']->id, (int) $product->brand_id);

        $this->assertFilteredProducts(
            $this->fetchProducts([
                'brand_id' => $catalog['sony']->id,
                'category_id' => $catalog['cameras']->id,
                'sub_category_id' => $catalog['cinema']->id,
            ]),
            [$product]
        );
    }

    private function fetchProducts(array $query, int $start = 0, int $length = 25): TestResponse
    {
        return $this->getJson(route('admin.products.data', array_merge([
            'draw' => 1,
            'start' => $start,
            'length' => $length,
        ], $query)));
    }

    private function assertFilteredProducts(TestResponse $response, array $products): void
    {
        $response->assertOk();
        $response->assertJsonMissingPath('error');

        $ids = collect($response->json('data'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
        $expected = collect($products)
            ->map(fn (Product $product) => (int) $product->id)
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expected, $ids);
        $this->assertSame(count($expected), $response->json('recordsFiltered'));
    }

    private function admin(): User
    {
        return User::create([
            'account_type' => 'provider',
            'username' => 'filter_admin_'.uniqid(),
            'email' => uniqid('filter_admin_', true).'@example.com',
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
        return $this->catalogProduct($category, $subCategory, $brand, $model);
    }

    private function catalogProduct(?Category $category, ?SubCategory $subCategory, ?Brand $brand, string $model): Product
    {
        return Product::create([
            'category_id' => $category?->id,
            'sub_category_id' => $subCategory?->id,
            'brand_id' => $brand?->id,
            'model' => $model.' '.uniqid(),
            'psm_code' => 'FILT'.strtoupper(bin2hex(random_bytes(4))),
            'is_verified' => 0,
        ]);
    }
}
