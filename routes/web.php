<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\RegionController;
use App\Http\Controllers\Admin\CountryController;
use App\Http\Controllers\Admin\StateProvinceController;
use App\Http\Controllers\Admin\CityController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }
    return redirect()->route('login');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Geography Management Routes
Route::middleware(['auth', 'verified'])->group(function () {
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
    Route::get('/ajax/regions/{region}/countries-for-states', [\App\Http\Controllers\Admin\StateProvinceController::class, 'getCountriesByRegion'])
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
Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    // Product Catalog Management
    // Categories
    Route::resource('categories', \App\Http\Controllers\Admin\CategoryController::class);
    Route::post('/categories/bulk-delete', [\App\Http\Controllers\Admin\CategoryController::class, 'bulkDelete'])
        ->name('categories.bulk-delete');

    // Sub-Categories
    Route::resource('subcategories', \App\Http\Controllers\Admin\SubCategoryController::class);
    Route::post('/subcategories/bulk-delete', [\App\Http\Controllers\Admin\SubCategoryController::class, 'bulkDelete'])
        ->name('subcategories.bulk-delete');
    Route::post('/subcategories/{subcategory}/move-products', [\App\Http\Controllers\Admin\SubCategoryController::class, 'moveProducts'])
        ->name('subcategories.moveProducts');

    // Brands
    Route::resource('brands', \App\Http\Controllers\Admin\BrandController::class);
    Route::post('/brands/bulk-delete', [\App\Http\Controllers\Admin\BrandController::class, 'bulkDelete'])
        ->name('brands.bulk-delete');

    // Products - Specific routes MUST be before resource route
    Route::get('/products/data', [\App\Http\Controllers\Admin\ProductController::class, 'getProductsData'])
        ->name('products.data');
    Route::get('/products/search', [\App\Http\Controllers\Admin\ProductController::class, 'searchProducts'])
        ->name('products.search');
    Route::get('/products/{product}/clone', [\App\Http\Controllers\Admin\ProductController::class, 'clone'])
        ->name('products.clone');
    Route::resource('products', \App\Http\Controllers\Admin\ProductController::class);
    Route::post('/products/bulk-delete', [\App\Http\Controllers\Admin\ProductController::class, 'bulkDelete'])
        ->name('products.bulk-delete');
    Route::post('/products/{product}/merge', [\App\Http\Controllers\Admin\ProductController::class, 'merge'])
        ->name('products.merge');
    Route::post('/products/bulk-verify', [\App\Http\Controllers\Admin\ProductController::class, 'bulkVerify'])
        ->name('products.bulk-verify');

    // AJAX endpoint for getting subcategories by category
    Route::get('/ajax/categories/{category}/subcategories', [\App\Http\Controllers\Admin\ProductController::class, 'getSubCategoriesByCategory'])
        ->name('ajax.subcategories-by-category');

    // Companies
    Route::resource('companies', \App\Http\Controllers\Admin\CompanyManagementController::class);
    Route::post('/companies/bulk-delete', [\App\Http\Controllers\Admin\CompanyManagementController::class, 'bulkDelete'])
        ->name('companies.bulk-delete');

    // Company AJAX endpoints
    Route::get('/ajax/regions/{region}/countries', [\App\Http\Controllers\Admin\CompanyManagementController::class, 'getCountriesByRegion'])
        ->name('ajax.countries-by-region');
    Route::get('/ajax/countries/{country}/states', [\App\Http\Controllers\Admin\CompanyManagementController::class, 'getStatesByCountry'])
        ->name('ajax.states-by-country-admin');
    Route::get('/ajax/states/{state}/cities', [\App\Http\Controllers\Admin\CompanyManagementController::class, 'getCitiesByState'])
        ->name('ajax.cities-by-state');
    Route::get('/ajax/cities/{city}/coordinates', [\App\Http\Controllers\Admin\CompanyManagementController::class, 'getCityCoordinates'])
        ->name('ajax.city-coordinates');

    // Currencies
    Route::resource('currencies', \App\Http\Controllers\Admin\CurrencyManagementController::class);
    Route::post('/currencies/bulk-delete', [\App\Http\Controllers\Admin\CurrencyManagementController::class, 'bulkDelete'])
        ->name('admin.currencies.bulk-delete');

    // Rental Software
    Route::resource('rental-software', \App\Http\Controllers\Admin\RentalSoftwareManagementController::class);
    Route::post('/rental-software/bulk-delete', [\App\Http\Controllers\Admin\RentalSoftwareManagementController::class, 'bulkDelete'])
        ->name('admin.rental-software.bulk-delete');

    // Equipment
    Route::resource('equipment', \App\Http\Controllers\Admin\EquipmentManagementController::class);
    Route::post('/equipment/bulk-delete', [\App\Http\Controllers\Admin\EquipmentManagementController::class, 'bulkDelete'])
        ->name('admin.equipment.bulk-delete');

    // Users
    Route::resource('users', \App\Http\Controllers\Admin\UserManagementController::class);
    Route::post('/users/bulk-delete', [\App\Http\Controllers\Admin\UserManagementController::class, 'bulkDelete'])
        ->name('users.bulk-delete');
    Route::post('/users/{user}/toggle-verification', [\App\Http\Controllers\Admin\UserManagementController::class, 'toggleVerification'])
        ->name('users.toggle-verification');
    Route::post('/users/{user}/toggle-admin', [\App\Http\Controllers\Admin\UserManagementController::class, 'toggleAdmin'])
        ->name('users.toggle-admin');

    // Job Management (Read-only)
    Route::resource('rental-jobs', \App\Http\Controllers\Admin\RentalJobController::class)->only(['index', 'show']);
    Route::resource('supply-jobs', \App\Http\Controllers\Admin\SupplyJobController::class)->only(['index', 'show']);

    // Admin User Management
    Route::resource('admin-users', \App\Http\Controllers\Admin\AdminUserManagementController::class);
    Route::post('/admin-users/{adminUser}/reactivate', [\App\Http\Controllers\Admin\AdminUserManagementController::class, 'reactivate'])
        ->name('admin-users.reactivate');
    Route::post('/admin-users/{adminUser}/reset-password', [\App\Http\Controllers\Admin\AdminUserManagementController::class, 'resetPassword'])
        ->name('admin-users.reset-password');

    // Payment Settings
    Route::get('/payment-settings', [\App\Http\Controllers\Admin\PaymentSettingsController::class, 'index'])
        ->name('payment-settings.index');
    Route::put('/payment-settings', [\App\Http\Controllers\Admin\PaymentSettingsController::class, 'update'])
        ->name('payment-settings.update');
    Route::post('/payment-settings/toggle', [\App\Http\Controllers\Admin\PaymentSettingsController::class, 'toggle'])
        ->name('payment-settings.toggle');

    // Subscription Management
    Route::get('/subscriptions', [\App\Http\Controllers\Admin\SubscriptionManagementController::class, 'index'])
        ->name('subscriptions.index');
    Route::get('/subscriptions/{subscription}', [\App\Http\Controllers\Admin\SubscriptionManagementController::class, 'show'])
        ->name('subscriptions.show');
    Route::post('/subscriptions/{subscription}/sync', [\App\Http\Controllers\Admin\SubscriptionManagementController::class, 'sync'])
        ->name('subscriptions.sync');

    // AJAX endpoints
    Route::get('/ajax/companies/{company}/users', [\App\Http\Controllers\Admin\EquipmentManagementController::class, 'getUsersByCompany'])
        ->name('ajax.users-by-company');
    Route::get('/ajax/check-username', [\App\Http\Controllers\Admin\UserManagementController::class, 'checkUsername'])
        ->name('ajax.check-username');
    Route::get('/ajax/company/{company}/phone-format', [\App\Http\Controllers\Admin\UserManagementController::class, 'getPhoneFormat'])
        ->name('ajax.phone-format');
});

// Clear application cache
Route::get('/clear-cache', function () {
    Artisan::call('cache:clear');
    return 'Application cache has been cleared';
});

// Clear route cache
Route::get('/route-cache', function () {
    Artisan::call('route:cache');
    return 'Routes cache has been cleared';
});

// Clear config cache
Route::get('/config-cache', function () {
    Artisan::call('config:cache');
    return 'Config cache has been cleared';
});

// Clear view cache
Route::get('/view-clear', function () {
    Artisan::call('view:clear');
    return 'View cache has been cleared';
});

// Optimize application
Route::get('/optimize-clear', function () {
    Artisan::call('optimize:clear');
    return 'Optimization has been cleared';
});

require __DIR__.'/auth.php';
