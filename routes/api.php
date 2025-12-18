<?php

use App\Http\Controllers\Api\AuthController;
// Duplicate import removed during formatting cleanup
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CityController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CompanyUserController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\ForgotPasswordController;
use App\Http\Controllers\Api\GeoController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\RegistrationCheckController;
use App\Http\Controllers\Api\RentalJobActionsController;
use App\Http\Controllers\Api\RentalJobController;
use App\Http\Controllers\Api\RentalRequestController;
use App\Http\Controllers\Api\RentalSoftwareController;
use App\Http\Controllers\Api\StateController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\SubCategoryController;
use App\Http\Controllers\Api\SupplyJobActionsController;
use App\Http\Controllers\Api\SupplyJobController;
use App\Http\Controllers\Api\UserProfileController;
use App\Http\Controllers\Api\EquipmentController;
use App\Http\Controllers\Api\UserOfferController;
use App\Http\Controllers\Api\JobNegotiationController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);
Route::get('/products/search', [ProductController::class, 'search']);
Route::post('/auth/verify-account', [AuthController::class, 'verifyAccount']);
Route::post('/password/forgot', [ForgotPasswordController::class, 'sendResetLink']);
Route::post('/reset-password/{token}', [ForgotPasswordController::class, 'reset']);
Route::post('/auth/refresh', [AuthController::class, 'refresh']);

// Registration availability checks (public, JSON body)
Route::post('/registration/availability', [RegistrationCheckController::class, 'checkAvailability']);

// Payment status check (public endpoint for frontend)
Route::get('/payment/status', [\App\Http\Controllers\Api\PaymentStatusController::class, 'status']);



// ------------------------------
// 🌐 Geography APIs
// ------------------------------
// These should be public (no auth middleware)
Route::get('/regions', [GeoController::class, 'getRegions']);
Route::get('/countries', [GeoController::class, 'getCountries']);
Route::get('/regions/{region_id}/countries', [GeoController::class, 'getCountriesByRegion']);
Route::get('/countries/{country_id}/cities', [GeoController::class, 'getCitiesByCountry']);

Route::get('countries/{country}/states', [StateController::class, 'index']);
Route::get('states/{state}', [StateController::class, 'show']);
Route::get('states/{state}/cities', [CityController::class, 'indexByState']);
// Route::get('countries/{country}/cities', [CityController::class, 'indexByCountry']);
// Route::get('locations/hierarchy', [LocationController::class, 'hierarchy']);

// ------------------------------
// 📦 Brands, Categories & Subcategories (Auth Required)
// ------------------------------
Route::middleware('jwt.verify')->group(function () {
    Route::get('/brands', [BrandController::class, 'index']);
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/sub-categories', [SubCategoryController::class, 'index']);
    Route::get('/categories/{id}/sub-categories', [SubCategoryController::class, 'getByCategory'])
        ->whereNumber('id');
});

// ------------------------------
// 👤 Profile APIs
// ------------------------------
Route::middleware('jwt.verify')->group(function () {
    Route::get('/user/profile', [UserProfileController::class, 'getProfile']);
    Route::post('/profile/upload-picture', [UserProfileController::class, 'uploadPicture']);
    Route::post('/profile/change-password', [UserProfileController::class, 'changePassword']);

    // Individual profile field updates
    Route::patch('/profile/update-username', [UserProfileController::class, 'updateUsername']);
    Route::patch('/profile/update-name', [UserProfileController::class, 'updateName']);
    Route::patch('/profile/update-email', [UserProfileController::class, 'updateEmail']);
    Route::patch('/profile/update-mobile', [UserProfileController::class, 'updateMobile']);
    Route::get('/rental-softwares', [RentalSoftwareController::class, 'index']);
    Route::get('/currencies', [CurrencyController::class, 'index']);
});

// ------------------------------
// 🏢 Company Management
// ------------------------------
Route::middleware('jwt.verify')->group(function () {
    Route::post('/company/users', [CompanyUserController::class, 'store']);
    Route::get('/company/users', [CompanyUserController::class, 'getCompanyUsers']);
    Route::put('/company/users/{id}', [CompanyUserController::class, 'updateCompanyUser']);
    Route::delete('/company/users/{id}', [CompanyUserController::class, 'deleteUser']);
    Route::put('/company/users/{id}/make-admin', [CompanyUserController::class, 'makeAdmin']);

    Route::get('/company/info', [CompanyController::class, 'getInfo']);
    Route::put('/company/info/update', [CompanyController::class, 'updateCompanyInfo']);
    Route::get('/company/default-contact', [CompanyController::class, 'getDefaultContact']);
    Route::put('/company/default-contact', [CompanyController::class, 'updateDefaultContact']);

    // Route::put('/company/info', [CompanyController::class, 'updateInfo']);
    Route::get('/company/preferences', [CompanyController::class, 'getPreferences']);
    Route::put('/company/preferences', [CompanyController::class, 'updatePreferences']);
    Route::put('/companies/{id}/gear-finder-visibility', [CompanyController::class, 'updateGearFinderVisibility']);


    Route::get('/company/images', [CompanyController::class, 'getImages']);
    Route::post('/company/images', [CompanyController::class, 'uploadImage']);
    Route::delete('/company/images', [CompanyController::class, 'deleteImage']);

    Route::get('/company/address', [CompanyController::class, 'getAddress']);
    Route::patch('/company/address', [CompanyController::class, 'updateAddress']);

    Route::get('/company/search-priority', [CompanyController::class, 'getSearchPriority']);
    Route::patch('/company/search-priority', [CompanyController::class, 'updateSearchPriority']);
});

Route::middleware('jwt.verify')->group(function () {
    Route::post('/companies/search', [CompanyController::class, 'searchCompanies']);
    Route::post('/company/search-priority', [CompanyController::class, 'searchPriority']);
    // Company listing
    Route::get('/companies', [CompanyController::class, 'listCompanies']);

    // Add/Update rating
    Route::post('/companies/{company}/rate', [CompanyController::class, 'rateCompany']);

    // Block/Unblock company
    Route::post('/companies/{company}/block', [CompanyController::class, 'blockCompany']);
    Route::post('/companies/{company}/unblock', [CompanyController::class, 'unblockCompany']);

});


Route::middleware('jwt.verify')->post('/products/create-or-attach', [ProductController::class, 'createOrAttach']);
Route::middleware('jwt.verify')->post('/products/import', [ProductController::class, 'importProducts']); // Legacy endpoint

// New Import Workflow with Preview and Persistent Draft State
Route::middleware('jwt.verify')->prefix('import')->group(function () {
    Route::get('/sessions', [ImportController::class, 'index']); // List active sessions
    Route::post('/sessions', [ImportController::class, 'start']); // Start new session
    Route::get('/sessions/{session}', [ImportController::class, 'show']); // Get session with items
    Route::post('/sessions/{session}/upload', [ImportController::class, 'upload']); // Upload Excel
    Route::post('/sessions/{session}/analyze', [ImportController::class, 'analyze']); // Run matching
    Route::put('/sessions/{session}/selections', [ImportController::class, 'updateSelections']); // Save draft selections
    Route::post('/sessions/{session}/confirm', [ImportController::class, 'confirm']); // Confirm and import
    Route::post('/sessions/{session}/cancel', [ImportController::class, 'cancel']); // Cancel session
});


Route::middleware(['jwt.verify'])->group(function () {
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

Route::middleware(['jwt.verify'])->group(function () {
    Route::post('/rental-requests', [RentalRequestController::class, 'store']);
});

Route::middleware(['jwt.verify'])->group(function () {
    Route::get('profile', [AuthController::class, 'profile']);
    Route::post('logout', [AuthController::class, 'logout']);
});

Route::middleware(['jwt.verify'])->group(function () {
    Route::get('/rental-jobs', [RentalJobController::class, 'index']); // List jobs
    Route::get('/rental-jobs/{id}', [RentalJobController::class, 'show']); // Job detail
    Route::get('/rental-jobs/{rentalJobId}/suppliers/{supplyJobId}', [RentalJobController::class, 'supplierDetails']); // Supplier detail
});

Route::middleware(['jwt.verify'])->group(function () {
    // Update rental job basics
    Route::put('/rental-jobs/{id}/basics', [RentalJobActionsController::class, 'updateBasics']);

    // Update requested product quantities
    Route::put('/rental-jobs/{id}/quantities', [RentalJobActionsController::class, 'updateRequestedQuantities']);

    Route::post('/rental-jobs/{id}/cancel', [RentalJobActionsController::class, 'cancelRentalJob']);

});


Route::group(['middleware' => ['jwt.verify']], function () {

    //uopdate supply job name
    Route::put('/supply-jobs/{id}/name', [SupplyJobActionsController::class, 'updateName']);

    // Update milestone dates
    Route::put('/supply-jobs/{id}/milestones', [SupplyJobActionsController::class, 'updateMilestoneDates']);

    // Update supply quantities
    Route::put('/supply-jobs/{id}/quantities', [SupplyJobActionsController::class, 'updateSupplyQuantities']);

    // Send offer
    Route::post('/supply-jobs/{id}/offer', [SupplyJobActionsController::class, 'sendNewOffer']);

    // Handshake (accept offer)
    // Route::post('/supply-jobs/{id}/handshake', [SupplyJobActionsController::class, 'handshake']);

    // Cancel negotiation
    // Route::post('/supply-jobs/{id}/cancel', [SupplyJobActionsController::class, 'cancelNegotiation']);

});

Route::middleware(['jwt.verify'])->group(function () {
    Route::get('/supply-jobs', [SupplyJobController::class, 'index']); // ?company_id=123
    Route::get('/supply-jobs/{id}', [SupplyJobController::class, 'show']); // ?company_id=123
    Route::post('/supply-jobs/{supply_job_id}/cancel', [SupplyJobController::class, 'cancelSupplyJob']);
});

Route::prefix('jobs')->middleware(['jwt.verify'])->group(function () {
    Route::post('{rental_job}/offer', [JobNegotiationController::class, 'sendOffer']);
});

Route::prefix('offers')->middleware(['jwt.verify'])->group(function () {
    Route::post('{offer_id}/handshake', [JobNegotiationController::class, 'handshake']);
    Route::post('{offer_id}/cancel-negotiation', [JobNegotiationController::class, 'cancelNegotiation']);
});


Route::middleware('jwt.verify')->group(function () {
    // Comments on supply jobs
    Route::get('/supply-jobs/{supplyJobId}/comments', [CommentController::class, 'index']);
    Route::post('/supply-jobs/{supplyJobId}/comments', [CommentController::class, 'store']);

    // Comment management
    Route::put('/comments/{commentId}', [CommentController::class, 'update']);
    Route::delete('/comments/{commentId}', [CommentController::class, 'destroy']);
});



// ------------------------------
// 💳 Stripe Webhook (No auth required)
// ------------------------------
Route::post('/webhooks/stripe', [\App\Http\Controllers\Api\StripeWebhookController::class, 'handleWebhook']);

// ------------------------------
// 📅 Subscription Management
// ------------------------------
Route::middleware('jwt.verify')->group(function () {
    Route::get('/subscriptions/current', [\App\Http\Controllers\Api\SubscriptionController::class, 'getCurrent']);
    Route::post('/subscriptions/cancel', [\App\Http\Controllers\Api\SubscriptionController::class, 'cancel']);
    Route::post('/subscriptions/update-payment', [\App\Http\Controllers\Api\SubscriptionController::class, 'updatePaymentMethod']);
    Route::get('/subscription/payment-method', [\App\Http\Controllers\Api\SubscriptionController::class, 'getPaymentMethod']);
    Route::post('/subscriptions/create', [\App\Http\Controllers\Api\SubscriptionController::class, 'create']);
    
    // Billing History APIs
    Route::get('/subscription/billing-history', [\App\Http\Controllers\Api\SubscriptionController::class, 'billingHistory']);
    Route::get('/subscription/invoice/{invoiceId}', [\App\Http\Controllers\Api\SubscriptionController::class, 'downloadInvoice']);
});
// Route::middleware(['jwt.verify'])->group(function () {
//     Route::post('/rental-jobs/{jobId}/offers', [UserOfferController::class, 'sendOfferToProvider']);
// });
