<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MarketplaceController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\RfqController;
use App\Http\Controllers\Api\TicketController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| EduShopify Mobile Buyer API Engine Routes
|--------------------------------------------------------------------------
|
| Dedicated endpoints strictly scoped for the Flutter Mobile Buyer App.
| Existing web controllers and routes remain completely untouched.
|
*/

Route::get('/health', function () {
    return response()->json([
        'status'  => 'ok',
        'service' => 'EduShopify API Engine',
        'time'    => now()->toIso8601String(),
    ]);
});

// 1. Authentication & Account Context
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::get('/accounts', [AuthController::class, 'accounts']);
    Route::post('/switch-account', [AuthController::class, 'switchAccount']);
});

// 2. Buyer Portal API Group
Route::prefix('buyer')->group(function () {

    // Dashboard KPIs
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Marketplace & Suppliers
    Route::prefix('marketplace')->group(function () {
        Route::get('/products', [MarketplaceController::class, 'products']);
        Route::get('/products/{id}', [MarketplaceController::class, 'productDetail']);
        Route::get('/services', [MarketplaceController::class, 'services']);
        Route::get('/categories', [MarketplaceController::class, 'categories']);
    });
    Route::get('/suppliers', [MarketplaceController::class, 'suppliers']);
    Route::get('/suppliers/{id}', [MarketplaceController::class, 'supplierDetail']);
    Route::get('/blog/posts', [MarketplaceController::class, 'blogPosts']);
    Route::get('/blog/posts/{id}', [MarketplaceController::class, 'blogPostDetail']);
    Route::get('/resources', [MarketplaceController::class, 'resources']);
    Route::get('/privacy-policy', [MarketplaceController::class, 'privacyPolicy']);
    Route::get('/terms-policy', [MarketplaceController::class, 'termsPolicy']);
    Route::get('/faqs', [MarketplaceController::class, 'faqs']);
    Route::get('/about', [MarketplaceController::class, 'aboutUs']);
    Route::get('/contact-info', [MarketplaceController::class, 'contactInfo']);
    Route::post('/contact', [MarketplaceController::class, 'submitContact']);

    // Saved Items & Bookmarks
    Route::get('/saved-items', [MarketplaceController::class, 'savedItems']);
    Route::post('/saved-items/toggle', [MarketplaceController::class, 'toggleSavedItem']);

    // RFQs (Request For Quotations)
    Route::prefix('rfqs')->group(function () {
        Route::get('/', [RfqController::class, 'index']);
        Route::post('/', [RfqController::class, 'store']);
        Route::get('/supplier-search', [RfqController::class, 'supplierSearch']);
        Route::get('/{id}', [RfqController::class, 'show']);
        Route::post('/{id}/publish', [RfqController::class, 'publish']);
        Route::post('/{id}/cancel', [RfqController::class, 'cancel']);
        Route::post('/{id}/extend-deadline', [RfqController::class, 'extendDeadline']);
    });

    // Quotations
    Route::prefix('quotations')->group(function () {
        Route::get('/', [QuotationController::class, 'index']);
        Route::get('/compare/{rfqId}', [QuotationController::class, 'compare']);
        Route::get('/{id}', [QuotationController::class, 'show']);
        Route::post('/{id}/shortlist', [QuotationController::class, 'shortlist']);
        Route::post('/{id}/request-revision', [QuotationController::class, 'requestRevision']);
        Route::post('/{id}/reject', [QuotationController::class, 'reject']);
        Route::post('/{id}/award', [QuotationController::class, 'award']);
    });

    // Awards
    Route::prefix('awards')->group(function () {
        Route::get('/', [PurchaseOrderController::class, 'awards']);
        Route::get('/{id}', [PurchaseOrderController::class, 'awards']);
    });

    // Purchase Orders
    Route::prefix('purchase-orders')->group(function () {
        Route::get('/', [PurchaseOrderController::class, 'index']);
        Route::get('/{id}', [PurchaseOrderController::class, 'show']);
        Route::post('/{id}/complete', [PurchaseOrderController::class, 'complete']);
    });

    // Direct Messaging & Communication
    Route::prefix('messages')->group(function () {
        Route::get('/', [MessageController::class, 'index']);
        Route::post('/start', [MessageController::class, 'index']);
        Route::get('/{id}', [MessageController::class, 'show']);
        Route::post('/{id}', [MessageController::class, 'store']);
        Route::get('/{id}/poll', [MessageController::class, 'poll']);
    });

    // Notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
    });

    // Support Tickets
    Route::prefix('tickets')->group(function () {
        Route::get('/', [TicketController::class, 'index']);
        Route::post('/', [TicketController::class, 'store']);
        Route::get('/{id}', [TicketController::class, 'show']);
        Route::post('/{id}/reply', [TicketController::class, 'reply']);
    });

    // Buyer Profile & Delivery Locations
    Route::get('/profile', [ProfileController::class, 'getProfile']);
    Route::put('/profile', [ProfileController::class, 'updateProfile']);
    Route::post('/gallery', [ProfileController::class, 'storeGalleryImage']);
    Route::delete('/gallery/{id}', [ProfileController::class, 'deleteGalleryImage']);
    Route::get('/settings/security', [ProfileController::class, 'getSecurity']);
    Route::put('/settings/security', [ProfileController::class, 'updateSecurity']);
    Route::get('/account/closure-check', [ProfileController::class, 'closureCheck']);
    Route::post('/account/close', [ProfileController::class, 'requestClosure']);
    Route::prefix('locations')->group(function () {
        Route::get('/', [ProfileController::class, 'locations']);
        Route::post('/', [ProfileController::class, 'storeLocation']);
        Route::delete('/{id}', [ProfileController::class, 'deleteLocation']);
        Route::post('/{id}/primary', [ProfileController::class, 'makePrimaryLocation']);
    });

    // Reviews
    Route::prefix('reviews')->group(function () {
        Route::get('/', [ReviewController::class, 'index']);
        Route::put('/{id}', [ReviewController::class, 'update']);
        Route::post('/purchase-order/{id}', [ReviewController::class, 'storePurchaseOrderReview']);
        Route::post('/quotation/{id}', [ReviewController::class, 'storeQuotationReview']);
    });

    // Organization Management
    Route::prefix('organization')->group(function () {
        Route::get('/members', [OrganizationController::class, 'members']);
        Route::post('/members/{id}/suspend', [OrganizationController::class, 'suspendMember']);
        Route::post('/members/{id}/activate', [OrganizationController::class, 'activateMember']);
        Route::delete('/members/{id}', [OrganizationController::class, 'deleteMember']);

        Route::get('/invitations', [OrganizationController::class, 'invitations']);
        Route::post('/invitations', [OrganizationController::class, 'storeInvitation']);
        Route::post('/invitations/{id}/resend', [OrganizationController::class, 'resendInvitation']);
        Route::delete('/invitations/{id}', [OrganizationController::class, 'cancelInvitation']);

        Route::get('/roles', [OrganizationController::class, 'roles']);

        Route::get('/role-requests', [OrganizationController::class, 'roleRequests']);
        Route::post('/role-requests', [OrganizationController::class, 'storeRoleRequest']);
        Route::delete('/role-requests/{id}', [OrganizationController::class, 'cancelRoleRequest']);

        Route::get('/ownership', [OrganizationController::class, 'ownership']);
        Route::post('/ownership/transfer', [OrganizationController::class, 'transferOwnership']);
    });

    // Organization Members & Invitations (compatible shortcuts)
    Route::get('/members', [OrganizationController::class, 'members']);
    Route::get('/invitations', [OrganizationController::class, 'invitations']);
});
