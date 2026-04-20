<?php

use App\Http\Controllers\Admin\AdminUserManagementController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CityController;
use App\Http\Controllers\Admin\CmsPageController as AdminCmsPageController;
use App\Http\Controllers\Admin\CompanyManagementController;
use App\Http\Controllers\Admin\ContactSalesController;
use App\Http\Controllers\Admin\CountryController;
use App\Http\Controllers\Admin\CurrencyManagementController;
use App\Http\Controllers\Admin\DateFormatManagementController;
use App\Http\Controllers\Admin\EmailLogController;
use App\Http\Controllers\Admin\EmailTemplateController;
use App\Http\Controllers\Admin\EquipmentManagementController;
use App\Http\Controllers\Admin\IssueTypeController;
use App\Http\Controllers\Admin\JobRatingsController;
use App\Http\Controllers\Admin\LinearUnitController;
use App\Http\Controllers\Admin\PaymentSettingsController;
use App\Http\Controllers\Admin\PricingSchemeManagementController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\RegionController;
use App\Http\Controllers\Admin\RentalJobController;
use App\Http\Controllers\Admin\RentalSoftwareManagementController;
use App\Http\Controllers\Admin\StateProvinceController;
use App\Http\Controllers\Admin\SubCategoryController;
use App\Http\Controllers\Admin\SubscriptionManagementController;
use App\Http\Controllers\Admin\SupplyJobController;
use App\Http\Controllers\Admin\TermsAndConditionsController;
use App\Http\Controllers\Admin\UserAuthEventController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Admin\UserRestrictionsController;
use App\Http\Controllers\Admin\WeightUnitController;
use App\Http\Controllers\CmsPageController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return redirect()->route('login');
});

/** Public CMS pages (HTML from admin WYSIWYG; content is sanitized on save). */
Route::get('/page/{cmsPage:slug}', [CmsPageController::class, 'show'])->name('cms.page.show');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Geography Management Routes
Route::middleware(['auth', 'verified', 'admin.access'])->group(function () {
    // Regions
    Route::resource('regions', RegionController::class);
    Route::post('/regions/bulk-delete', [RegionController::class, 'bulkDelete'])->name('regions.bulk-delete');

    // Countries
    Route::resource('countries', CountryController::class);
    Route::post('/countries/bulk-delete', [CountryController::class, 'bulkDelete'])->name('countries.bulk-delete');

    // States/Provinces
    Route::resource('states', StateProvinceController::class);
    Route::post('/states/bulk-delete', [StateProvinceController::class, 'bulkDelete'])->name('states.bulk-delete');

    // State AJAX endpoints
    Route::get('/ajax/regions/{region}/countries-for-states', [StateProvinceController::class, 'getCountriesByRegion'])
        ->name('ajax.countries-by-region-states');

    // Cities
    Route::resource('cities', CityController::class);
    Route::post('/cities/bulk-delete', [CityController::class, 'bulkDelete'])->name('cities.bulk-delete');

    // City AJAX endpoints
    Route::get('/ajax/countries/{country}/states', [CityController::class, 'getStatesByCountry'])
        ->name('ajax.states-by-country');
    Route::get('/ajax/regions/{region}/countries-for-cities', [CityController::class, 'getCountriesByRegion'])
        ->name('ajax.countries-by-region-cities');
});

// Admin Routes (Product Catalog, Company Management, User Management)
Route::middleware(['auth', 'verified', 'admin.access'])->prefix('admin')->name('admin.')->group(function () {
    // Product Catalog Management
    // Categories
    Route::resource('categories', CategoryController::class);
    Route::post('/categories/bulk-delete', [CategoryController::class, 'bulkDelete'])
        ->name('categories.bulk-delete');

    // Sub-Categories
    Route::resource('subcategories', SubCategoryController::class);
    Route::post('/subcategories/bulk-delete', [SubCategoryController::class, 'bulkDelete'])
        ->name('subcategories.bulk-delete');
    Route::post('/subcategories/{subcategory}/move-products', [SubCategoryController::class, 'moveProducts'])
        ->name('subcategories.moveProducts');

    // Brands
    Route::resource('brands', BrandController::class);
    Route::post('/brands/bulk-delete', [BrandController::class, 'bulkDelete'])
        ->name('brands.bulk-delete');

    // Products - Specific routes MUST be before resource route
    Route::get('/products/data', [ProductController::class, 'getProductsData'])
        ->name('products.data');
    Route::get('/products/search', [ProductController::class, 'searchProducts'])
        ->name('products.search');
    Route::get('/products/{product}/clone', [ProductController::class, 'clone'])
        ->name('products.clone');
    Route::resource('products', ProductController::class);
    Route::post('/products/bulk-delete', [ProductController::class, 'bulkDelete'])
        ->name('products.bulk-delete');
    Route::post('/products/{product}/merge', [ProductController::class, 'merge'])
        ->name('products.merge');
    Route::post('/products/bulk-verify', [ProductController::class, 'bulkVerify'])
        ->name('products.bulk-verify');

    // AJAX endpoint for getting subcategories by category
    Route::get('/ajax/categories/{category}/subcategories', [ProductController::class, 'getSubCategoriesByCategory'])
        ->name('ajax.subcategories-by-category');

    // Companies
    Route::get('/companies/{company}/inventory/data', [CompanyManagementController::class, 'inventoryData'])
        ->name('companies.inventory.data');
    Route::get('/companies/{company}/inventory/search-master', [CompanyManagementController::class, 'searchInventoryMaster'])
        ->name('companies.inventory.search-master');
    Route::post('/companies/{company}/inventory', [CompanyManagementController::class, 'storeInventory'])
        ->name('companies.inventory.store');
    Route::delete('/companies/{company}/inventory/{equipment}', [CompanyManagementController::class, 'destroyInventory'])
        ->name('companies.inventory.destroy');

    Route::resource('companies', CompanyManagementController::class);
    Route::post('/companies/bulk-delete', [CompanyManagementController::class, 'bulkDelete'])
        ->name('companies.bulk-delete');
    Route::post('/companies/{company}/rating-override', [CompanyManagementController::class, 'updateRatingOverride'])
        ->name('companies.rating-override');

    // Company AJAX endpoints
    Route::get('/ajax/regions/{region}/countries', [CompanyManagementController::class, 'getCountriesByRegion'])
        ->name('ajax.countries-by-region');
    Route::get('/ajax/countries/{country}/states', [CompanyManagementController::class, 'getStatesByCountry'])
        ->name('ajax.states-by-country-admin');
    Route::get('/ajax/states/{state}/cities', [CompanyManagementController::class, 'getCitiesByState'])
        ->name('ajax.cities-by-state');
    Route::get('/ajax/cities/{city}/coordinates', [CompanyManagementController::class, 'getCityCoordinates'])
        ->name('ajax.city-coordinates');

    // Currencies
    Route::resource('currencies', CurrencyManagementController::class);
    Route::post('/currencies/bulk-delete', [CurrencyManagementController::class, 'bulkDelete'])
        ->name('admin.currencies.bulk-delete');

    // Date Formats
    Route::resource('date-formats', DateFormatManagementController::class);
    Route::post('/date-formats/bulk-delete', [DateFormatManagementController::class, 'bulkDelete'])
        ->name('date-formats.bulk-delete');

    // Pricing Schemes
    Route::resource('pricing-schemes', PricingSchemeManagementController::class);
    Route::post('/pricing-schemes/bulk-delete', [PricingSchemeManagementController::class, 'bulkDelete'])
        ->name('pricing-schemes.bulk-delete');

    // Rental Software
    Route::resource('rental-software', RentalSoftwareManagementController::class);
    Route::post('/rental-software/bulk-delete', [RentalSoftwareManagementController::class, 'bulkDelete'])
        ->name('admin.rental-software.bulk-delete');

    // Equipment
    Route::resource('equipment', EquipmentManagementController::class);
    Route::post('/equipment/bulk-delete', [EquipmentManagementController::class, 'bulkDelete'])
        ->name('admin.equipment.bulk-delete');

    // Users
    Route::resource('users', UserManagementController::class);
    Route::post('/users/bulk-delete', [UserManagementController::class, 'bulkDelete'])
        ->name('users.bulk-delete');
    Route::post('/users/{user}/toggle-verification', [UserManagementController::class, 'toggleVerification'])
        ->name('users.toggle-verification');
    Route::post('/users/{user}/toggle-admin', [UserManagementController::class, 'toggleAdmin'])
        ->name('users.toggle-admin');

    // Job Management (Read-only)
    Route::resource('rental-jobs', RentalJobController::class)->only(['index', 'show']);
    Route::resource('supply-jobs', SupplyJobController::class)->only(['index', 'show']);
    Route::get('/job-ratings', [JobRatingsController::class, 'index'])->name('job-ratings.index');
    Route::post('/job-ratings/block-company/{company}', [JobRatingsController::class, 'blockCompany'])->name('job-ratings.block-company');
    Route::post('/job-ratings/unblock-company/{company}', [JobRatingsController::class, 'unblockCompany'])->name('job-ratings.unblock-company');

    // Admin User Management
    Route::resource('admin-users', AdminUserManagementController::class);
    Route::post('/admin-users/{adminUser}/reactivate', [AdminUserManagementController::class, 'reactivate'])
        ->name('admin-users.reactivate');
    Route::post('/admin-users/{adminUser}/reset-password', [AdminUserManagementController::class, 'resetPassword'])
        ->name('admin-users.reset-password');

    // Payment Settings
    Route::get('/payment-settings', [PaymentSettingsController::class, 'index'])
        ->name('payment-settings.index');
    Route::put('/payment-settings', [PaymentSettingsController::class, 'update'])
        ->name('payment-settings.update');
    Route::post('/payment-settings/toggle', [PaymentSettingsController::class, 'toggle'])
        ->name('payment-settings.toggle');

    // User Restrictions
    Route::get('/user-restrictions', [UserRestrictionsController::class, 'index'])
        ->name('user-restrictions.index');
    Route::put('/user-restrictions', [UserRestrictionsController::class, 'update'])
        ->name('user-restrictions.update');

    // Subscription Management
    Route::get('/subscriptions', [SubscriptionManagementController::class, 'index'])
        ->name('subscriptions.index');
    Route::get('/subscriptions/{subscription}', [SubscriptionManagementController::class, 'show'])
        ->name('subscriptions.show');
    Route::post('/subscriptions/{subscription}/sync', [SubscriptionManagementController::class, 'sync'])
        ->name('subscriptions.sync');

    // AJAX endpoints
    Route::get('/ajax/companies/{company}/users', [EquipmentManagementController::class, 'getUsersByCompany'])
        ->name('ajax.users-by-company');
    Route::get('/ajax/check-username', [UserManagementController::class, 'checkUsername'])
        ->name('ajax.check-username');
    Route::get('/ajax/company/{company}/phone-format', [UserManagementController::class, 'getPhoneFormat'])
        ->name('ajax.phone-format');

    // Support Request Management
    Route::resource('issue-types', IssueTypeController::class);

    // Contact Sales Management
    Route::resource('contact-sales', ContactSalesController::class)
        ->only(['index', 'show', 'update', 'destroy'])
        ->parameters(['contact-sales' => 'contactSales']);

    // CMS pages (WordPress-like HTML content)
    Route::resource('cms-pages', AdminCmsPageController::class)->except(['show']);
    Route::post('/cms-pages/upload-image', [AdminCmsPageController::class, 'uploadImage'])
        ->name('cms-pages.upload-image');

    // Terms and Conditions Management
    Route::get('/terms-and-conditions', [TermsAndConditionsController::class, 'index'])
        ->name('terms-and-conditions.index');
    Route::get('/terms-and-conditions/edit', [TermsAndConditionsController::class, 'edit'])
        ->name('terms-and-conditions.edit');
    Route::put('/terms-and-conditions', [TermsAndConditionsController::class, 'update'])
        ->name('terms-and-conditions.update');

    // Measurement Units (Settings)
    Route::resource('linear-units', LinearUnitController::class)->except(['show']);
    Route::resource('weight-units', WeightUnitController::class)->except(['show']);

    // Email Templates Management
    Route::resource('email-templates', EmailTemplateController::class)->only(['index', 'edit', 'update']);
    Route::post('/email-templates/{emailTemplate}/toggle-status', [EmailTemplateController::class, 'toggleStatus'])
        ->name('email-templates.toggle-status');
    Route::get('/email-templates/{emailTemplate}/preview', [EmailTemplateController::class, 'preview'])
        ->name('email-templates.preview');

    // Email Logs (read-only)
    Route::get('/email-logs', [EmailLogController::class, 'index'])
        ->name('email-logs.index');
    Route::get('/email-logs/{emailLog}', [EmailLogController::class, 'show'])
        ->name('email-logs.show');

    // User login / logout / failed login history (read-only)
    Route::get('/user-auth-events', [UserAuthEventController::class, 'index'])
        ->name('user-auth-events.index');
});

require __DIR__.'/auth.php';
