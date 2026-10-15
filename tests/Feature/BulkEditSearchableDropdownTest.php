<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BulkEditSearchableDropdownTest extends TestCase
{
    use DatabaseTransactions;

    public function test_multi_edit_dropdowns_use_select2_search_without_changing_filters(): void
    {
        $admin = User::create([
            'account_type' => 'provider',
            'username' => 'bulk_search_admin_'.uniqid(),
            'email' => uniqid('bulk_search_admin_', true).'@example.com',
            'password' => 'password',
            'is_admin' => 1,
            'role' => 'super_admin',
            'email_verified' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.products.index'));

        $response->assertOk()
            ->assertSee('id="bulkEditCategory"', false)
            ->assertSee('id="bulkEditSubCategory"', false)
            ->assertSee('id="bulkEditBrand"', false)
            ->assertSee('var bulkEditCategories = ', false)
            ->assertSee('var bulkEditSubCategories = ', false)
            ->assertSee('var bulkEditBrands = ', false)
            ->assertSee("initBulkEditSelect($('#bulkEditCategory'), '-- Select Category --');", false)
            ->assertSee("initBulkEditSelect($('#bulkEditSubCategory'), '-- Select Sub-Category --');", false)
            ->assertSee("initBulkEditSelect($('#bulkEditBrand'), '-- Select Brand --');", false)
            ->assertSee("dropdownParent: $('#bulkEditModal')", false)
            ->assertSee('minimumResultsForSearch: 0', false)
            ->assertSee('No matching options found', false)
            ->assertSee("initCatalogFilterSelect($('#filterBrand'), 'All Brands');", false)
            ->assertSee("initCatalogFilterSelect($('#filterCategory'), 'All Categories');", false)
            ->assertSee("initCatalogFilterSelect($('#filterSubCategory'), 'All Sub Categories');", false)
            ->assertSee('Not Set (-)', false);
    }
}
