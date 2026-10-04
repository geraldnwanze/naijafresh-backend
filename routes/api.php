<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AddressController;
use App\Http\Controllers\Api\V1\Admin\AccountingController;
use App\Http\Controllers\Api\V1\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\ExpenseController;
use App\Http\Controllers\Api\V1\Admin\InventoryController;
use App\Http\Controllers\Api\V1\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\V1\Admin\SettingsController;
use App\Http\Controllers\Api\V1\Admin\System\ActivityLogController;
use App\Http\Controllers\Api\V1\Admin\System\ApplicationLogController;
use App\Http\Controllers\Api\V1\Admin\System\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\System\OverviewController;
use App\Http\Controllers\Api\V1\Admin\System\UserController as SystemUserController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\DeliveryWindowController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaystackWebhookController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\StoreConfigController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {

    /*
    |----------------------------------------------------------------------
    | Public storefront
    |----------------------------------------------------------------------
    */
    Route::get('config', StoreConfigController::class)->name('config');
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::get('categories/{category}', [CategoryController::class, 'show'])->name('categories.show');
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
    Route::get('delivery-windows', [DeliveryWindowController::class, 'index'])->name('delivery-windows.index');
    Route::post('cart/price', [CartController::class, 'price'])->name('cart.price');

    /*
    |----------------------------------------------------------------------
    | Authentication
    |----------------------------------------------------------------------
    */
    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:10,1')->name('auth.register');
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')->name('auth.login');

    /*
    |----------------------------------------------------------------------
    | Payments – gateway callbacks (no session auth)
    |----------------------------------------------------------------------
    */
    Route::post('payments/webhook/paystack', PaystackWebhookController::class)->name('payments.webhook.paystack');

    /*
    |----------------------------------------------------------------------
    | Authenticated customer
    |----------------------------------------------------------------------
    */
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/user', [AuthController::class, 'user'])->name('auth.user');
        Route::put('account/profile', [AccountController::class, 'update'])->name('account.update');

        Route::apiResource('addresses', AddressController::class);

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');

        Route::post('payments/initialize', [PaymentController::class, 'initialize'])->name('payments.initialize');
        Route::post('payments/verify', [PaymentController::class, 'verify'])->name('payments.verify');
        Route::get('payments/{payment:reference}', [PaymentController::class, 'show'])->name('payments.show');
    });

    /*
    |----------------------------------------------------------------------
    | Admin
    |----------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('orders', [AdminOrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [AdminOrderController::class, 'show'])->name('orders.show');
        Route::put('orders/{order}/status', [AdminOrderController::class, 'updateStatus'])->name('orders.status');

        Route::apiResource('products', AdminProductController::class)
            ->parameters(['products' => 'product:id']);
        Route::apiResource('categories', AdminCategoryController::class)
            ->parameters(['categories' => 'category:id']);

        Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
        Route::put('inventory/{product:id}', [InventoryController::class, 'update'])->name('inventory.update');

        Route::get('accounting/profit-loss', [AccountingController::class, 'profitLoss'])->name('accounting.profit-loss');
        Route::apiResource('expenses', ExpenseController::class);

        Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');
        Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');

        /*
        |------------------------------------------------------------------
        | System (super admin only): audit trail, activity, logs, user roles
        |------------------------------------------------------------------
        */
        Route::middleware('super_admin')->prefix('system')->name('system.')->group(function (): void {
            Route::get('overview', OverviewController::class)->name('overview');
            Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
            Route::get('activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');
            Route::get('application-logs', [ApplicationLogController::class, 'index'])->name('application-logs.index');
            Route::get('users', [SystemUserController::class, 'index'])->name('users.index');
            Route::put('users/{user}/role', [SystemUserController::class, 'updateRole'])->name('users.role');
        });
    });
});
