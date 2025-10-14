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

    // Countries
    Route::resource('countries', CountryController::class);

    // States/Provinces
    Route::resource('states', StateProvinceController::class);

    // Cities
    Route::resource('cities', CityController::class);

    // AJAX endpoint for getting states by country
    Route::get('/ajax/countries/{country}/states', [CityController::class, 'getStatesByCountry'])
        ->name('ajax.states-by-country');
});

// Product Catalog Management Routes
Route::middleware(['auth', 'verified'])->group(function () {
    // Categories
    Route::resource('categories', \App\Http\Controllers\Admin\CategoryController::class);

    // Sub-Categories
    Route::resource('subcategories', \App\Http\Controllers\Admin\SubCategoryController::class);

    // Brands
    Route::resource('brands', \App\Http\Controllers\Admin\BrandController::class);

    // Products
    Route::resource('products', \App\Http\Controllers\Admin\ProductController::class);

    // AJAX endpoint for getting subcategories by category
    Route::get('/ajax/categories/{category}/subcategories', [\App\Http\Controllers\Admin\ProductController::class, 'getSubCategoriesByCategory'])
        ->name('ajax.subcategories-by-category');
});

// Company Management Routes
Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    // Companies
    Route::resource('companies', \App\Http\Controllers\Admin\CompanyManagementController::class);

    // Currencies
    Route::resource('currencies', \App\Http\Controllers\Admin\CurrencyManagementController::class);

    // Rental Software
    Route::resource('rental-software', \App\Http\Controllers\Admin\RentalSoftwareManagementController::class);

    // Equipment
    Route::resource('equipment', \App\Http\Controllers\Admin\EquipmentManagementController::class);

    // Users
    Route::resource('users', \App\Http\Controllers\Admin\UserManagementController::class);
    Route::post('/users/{user}/toggle-verification', [\App\Http\Controllers\Admin\UserManagementController::class, 'toggleVerification'])
        ->name('users.toggle-verification');
    Route::post('/users/{user}/toggle-admin', [\App\Http\Controllers\Admin\UserManagementController::class, 'toggleAdmin'])
        ->name('users.toggle-admin');

    // AJAX endpoints
    Route::get('/ajax/companies/{company}/users', [\App\Http\Controllers\Admin\EquipmentManagementController::class, 'getUsersByCompany'])
        ->name('ajax.users-by-company');
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
