<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\GeoController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\SubCategoryController;
use App\Http\Controllers\Api\UserProfileController;
use App\Http\Controllers\Api\RentalSoftwareController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CompanyUserController;
use App\Http\Controllers\Api\EquipmentController;
use App\Http\Controllers\Api\RentalRequestController;
use Illuminate\Http\Request;

Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);
Route::get('/products/search', [ProductController::class, 'search']);

// ------------------------------
// 🌐 Geography APIs
// ------------------------------
// These should be public (no auth middleware)
Route::get('/regions', [GeoController::class, 'getRegions']);
Route::get('/countries', [GeoController::class, 'getCountries']);
Route::get('/regions/{region_id}/countries', [GeoController::class, 'getCountriesByRegion']);
Route::get('/countries/{country_id}/cities', [GeoController::class, 'getCitiesByCountry']);

// ------------------------------
// 📦 Brands, Categories & Subcategories (Auth Required)
// ------------------------------
Route::middleware('jwt.auth')->group(function () {
    Route::get('/brands', [BrandController::class, 'index']);
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/sub-categories', [SubCategoryController::class, 'index']);
    Route::get('/categories/{id}/sub-categories', [SubCategoryController::class, 'getByCategory'])
        ->whereNumber('id');
});

// ------------------------------
// 👤 Profile APIs
// ------------------------------
Route::middleware('jwt.auth')->group(function () {
    Route::get('/user/profile', [UserProfileController::class, 'getProfile']);
    Route::post('/profile/upload-picture', [UserProfileController::class, 'uploadPicture']);
    Route::post('/profile/change-password', [UserProfileController::class, 'changePassword']);

    // Individual profile field updates
    Route::patch('/profile/update-name', [UserProfileController::class, 'updateName']);
    Route::patch('/profile/update-email', [UserProfileController::class, 'updateEmail']);
    Route::patch('/profile/update-mobile', [UserProfileController::class, 'updateMobile']);
    Route::get('/rental-softwares', [RentalSoftwareController::class, 'index']);
    Route::get('/currencies', [CurrencyController::class, 'index']);
});

// ------------------------------
// 🏢 Company Management
// ------------------------------
Route::middleware('jwt.auth')->group(function () {
    Route::post('/company/users', [CompanyUserController::class, 'store']);
    Route::get('/company/users', [CompanyUserController::class, 'getCompanyUsers']);
    Route::put('/company/users/{id}', [CompanyUserController::class, 'updateCompanyUser']);
    Route::delete('/company/users/{id}', [CompanyUserController::class, 'deleteUser']);
    Route::put('/company/users/{id}/make-admin', [CompanyUserController::class, 'makeAdmin']);

    Route::get('/company/info', [CompanyController::class, 'getInfo']);
    Route::put('/company/info/update', [CompanyController::class, 'updateCompanyInfo']);
    Route::get('/company/default-contact', [CompanyController::class, 'getDefaultContact']);

    // Route::put('/company/info', [CompanyController::class, 'updateInfo']);
    Route::get('/company/preferences', [CompanyController::class, 'getPreferences']);
    Route::put('/company/preferences', [CompanyController::class, 'updatePreferences']);

    Route::get('/company/images', [CompanyController::class, 'getImages']);
    Route::post('/company/images', [CompanyController::class, 'uploadImage']);
    Route::delete('/company/images', [CompanyController::class, 'deleteImage']);

    Route::get('/company/address', [CompanyController::class, 'getAddress']);
    Route::patch('/company/address', [CompanyController::class, 'updateAddress']);

    Route::get('/company/search-priority', [CompanyController::class, 'getSearchPriority']);
    Route::patch('/company/search-priority', [CompanyController::class, 'updateSearchPriority']);
});

Route::middleware('jwt.auth')->post('/products/create-or-attach', [ProductController::class, 'createOrAttach']);


Route::middleware(['jwt.auth'])->group(function () {
    // Equipment CRUD
    Route::post('/equipments', [EquipmentController::class, 'store']);
    Route::get('/equipments', [EquipmentController::class, 'getCompanyEquipments']);
    Route::delete('/equipments/{equipment}', [EquipmentController::class, 'destroy']);

    // Updates
    Route::put('/equipments/{equipment}/quantity', [EquipmentController::class, 'updateQuantity']);
    Route::put('/equipments/{equipment}/price', [EquipmentController::class, 'updatePrice']);
    Route::put('/equipments/{equipment}/software-code', [EquipmentController::class, 'updateSoftwareCode']);
    Route::put('/equipments/{equipment}/description', [EquipmentController::class, 'updateDescription']);

    // Images
    Route::post('/equipments/{equipment}/images', [EquipmentController::class, 'addImages']);
    Route::delete('/equipments/images/{id}', [EquipmentController::class, 'deleteImage']);
});

Route::middleware(['jwt.auth'])->group(function () {
    Route::post('/rental-requests', [RentalRequestController::class, 'store']);
});

Route::middleware(['jwt.auth'])->group(function () {
    Route::get('profile', [AuthController::class, 'profile']);
    Route::post('logout', [AuthController::class, 'logout']);
});
