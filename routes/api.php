<?php

use App\Http\Controllers\Api\AppSettingController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CalendarOrderController;
use App\Http\Controllers\Api\CoverAgendaController;
use App\Http\Controllers\Api\FiscalDocumentController;
use App\Http\Controllers\Api\FiscalSettingController;
use App\Http\Controllers\Api\FinancialController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MercadoLivreController;
use App\Http\Controllers\Api\MercadoLivreConfigController;
use App\Http\Controllers\Api\PricingMaterialController;
use App\Http\Controllers\Api\PricingProductController;
use App\Http\Controllers\Api\ShippingOrderController;
use App\Http\Controllers\Api\ShopeeOrderController;
use App\Http\Controllers\Api\FiscalProviderController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::post('/integrations/mercado-livre/notifications', [MercadoLivreController::class, 'notifications']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/settings', [AppSettingController::class, 'index']);
    Route::get('/settings/{setting}', [AppSettingController::class, 'show']);
    Route::put('/settings/{setting}', [AppSettingController::class, 'upsert']);
    Route::delete('/settings/{setting}', [AppSettingController::class, 'destroy']);

    Route::get('/financial/dashboard', [FinancialController::class, 'dashboard']);
    Route::apiResource('financial/categories', FinancialController::class)->parameter('categories', 'category');
    Route::post('/financial/accounts', [FinancialController::class, 'storeAccount']);
    Route::post('/financial/transactions', [FinancialController::class, 'storeTransaction']);

    Route::get('/shipping/orders', [ShippingOrderController::class, 'index']);
    Route::post('/shipping/orders/import', [ShippingOrderController::class, 'import']);
    Route::get('/shipping/orders/scan', [ShippingOrderController::class, 'scan']);
    Route::get('/shipping/orders/{order}/artwork', [ShippingOrderController::class, 'artwork']);
    Route::delete('/shipping/orders/by-date', [ShippingOrderController::class, 'destroyByDate']);
    Route::post('/shipping/orders/bulk-delete', [ShippingOrderController::class, 'bulkDelete']);
    Route::patch('/shipping/orders/{order}', [ShippingOrderController::class, 'update']);
    Route::delete('/shipping/orders/{order}', [ShippingOrderController::class, 'destroy']);

    Route::get('/shopee/orders', [ShopeeOrderController::class, 'index']);
    Route::post('/shopee/orders/import', [ShopeeOrderController::class, 'import']);
    Route::post('/shopee/analyze-listings', [ShopeeOrderController::class, 'analyzeListings']);
    Route::post('/shopee/orders/bulk-delete', [ShopeeOrderController::class, 'destroyOrders']);
    Route::delete('/shopee/orders/by-year', [ShopeeOrderController::class, 'destroyYear']);
    Route::patch('/shopee/products/{product}', [ShopeeOrderController::class, 'updateProduct']);
    Route::post('/shopee/products/bulk-delete', [ShopeeOrderController::class, 'destroyProducts']);

    Route::apiResource('cover-agenda', CoverAgendaController::class);
    Route::patch('/cover-agenda/{cover}/printed', [CoverAgendaController::class, 'markPrinted']);

    Route::apiResource('pricing/materials', PricingMaterialController::class)->parameter('materials', 'material');
    Route::apiResource('pricing/products', PricingProductController::class)->parameter('products', 'product');
    Route::apiResource('calendar/orders', CalendarOrderController::class)->parameter('orders', 'order');

    Route::get('/fiscal/settings', [FiscalSettingController::class, 'show']);
    Route::put('/fiscal/settings', [FiscalSettingController::class, 'upsert']);
    Route::get('/fiscal/documents', [FiscalDocumentController::class, 'index']);
    Route::post('/fiscal/documents', [FiscalDocumentController::class, 'store']);
    Route::patch('/fiscal/documents/{document}', [FiscalDocumentController::class, 'update']);
    Route::delete('/fiscal/documents/{document}', [FiscalDocumentController::class, 'destroy']);

    Route::prefix('integrations/mercado-livre')->group(function () {
        Route::get('/config', [MercadoLivreConfigController::class, 'show']);
        Route::put('/config', [MercadoLivreConfigController::class, 'update']);
        Route::get('/account', [MercadoLivreController::class, 'account']);
        Route::delete('/account', [MercadoLivreController::class, 'disconnect']);
        Route::post('/oauth/token', [MercadoLivreController::class, 'oauthToken']);
        Route::post('/sync', [MercadoLivreController::class, 'sync']);
        Route::post('/customization', [MercadoLivreController::class, 'sendCustomization']);
    });

    Route::prefix('integrations/fiscal')->group(function () {
        Route::post('/emit', [FiscalProviderController::class, 'emit']);
        Route::post('/status', [FiscalProviderController::class, 'status']);
    });
});
