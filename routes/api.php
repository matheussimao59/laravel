<?php

use App\Http\Controllers\Api\AppSettingController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CalendarOrderController;
use App\Http\Controllers\Api\CoverAgendaController;
use App\Http\Controllers\Api\FiscalDocumentController;
use App\Http\Controllers\Api\FiscalSettingController;
use App\Http\Controllers\Api\FinancialController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ManualPrintOrderController;
use App\Http\Controllers\Api\LocalPrintJobController;
use App\Http\Controllers\Api\MercadoLivreController;
use App\Http\Controllers\Api\MercadoLivreConfigController;
use App\Http\Controllers\Api\ModeloController;
use App\Http\Controllers\Api\PricingMaterialController;
use App\Http\Controllers\Api\PricingProductController;
use App\Http\Controllers\Api\ProductMatrixController;
use App\Http\Controllers\Api\ShippingOrderController;
use App\Http\Controllers\Api\ShopeeOrderController;
use App\Http\Controllers\Api\FiscalProviderController;
use App\Http\Controllers\Api\GpClientController;
use App\Http\Controllers\Api\GpSupplierController;
use App\Http\Controllers\Api\GpProductController;
use App\Http\Controllers\Api\GpOrderController;
use App\Http\Controllers\Api\GpQuoteController;
use App\Http\Controllers\Api\GpProductionController;
use App\Http\Controllers\Api\GpDeliveryController;
use App\Http\Controllers\Api\GpFinancialController;
use App\Http\Controllers\Api\GpProductTemplateController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::post('/integrations/mercado-livre/notifications', [MercadoLivreController::class, 'notifications']);
Route::get('/print-agent/jobs/next', [LocalPrintJobController::class, 'next']);
Route::post('/print-agent/jobs/{job}/complete', [LocalPrintJobController::class, 'complete']);
Route::post('/print-agent/printers', [LocalPrintJobController::class, 'syncPrinters']);
Route::get('/print-agent/commands/next', [LocalPrintJobController::class, 'nextCommand']);
Route::post('/print-agent/commands/{command}/complete', [LocalPrintJobController::class, 'completeCommand']);

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
    Route::patch('/financial/transactions/{transaction}', [FinancialController::class, 'updateTransaction']);
    Route::delete('/financial/transactions/{transaction}', [FinancialController::class, 'destroyTransaction']);

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
    Route::apiResource('product-matrices', ProductMatrixController::class)->parameter('product-matrices', 'matrix');
    Route::apiResource('calendar/orders', CalendarOrderController::class)->parameter('orders', 'order');
    Route::apiResource('impressao/orders', ManualPrintOrderController::class)->parameter('orders', 'order')->only(['index', 'store', 'update', 'destroy']);
    Route::get('/print/jobs', [LocalPrintJobController::class, 'index']);
    Route::post('/print/jobs', [LocalPrintJobController::class, 'store']);
    Route::post('/print/jobs/clear-history', [LocalPrintJobController::class, 'clearCompleted']);
    Route::post('/print/jobs/{job}/retry', [LocalPrintJobController::class, 'retry']);
    Route::post('/print/jobs/{job}/cancel', [LocalPrintJobController::class, 'cancel']);
    Route::post('/print-agent/profiles/{profile}/capture', [LocalPrintJobController::class, 'captureProfile']);

    Route::get('/fiscal/settings', [FiscalSettingController::class, 'show']);
    Route::put('/fiscal/settings', [FiscalSettingController::class, 'upsert']);
    Route::get('/fiscal/documents', [FiscalDocumentController::class, 'index']);
    Route::post('/fiscal/documents', [FiscalDocumentController::class, 'store']);
    Route::patch('/fiscal/documents/{document}', [FiscalDocumentController::class, 'update']);
    Route::delete('/fiscal/documents/{document}', [FiscalDocumentController::class, 'destroy']);

    Route::get('/modelos', [ModeloController::class, 'index']);
    Route::post('/modelos', [ModeloController::class, 'store']);
    Route::get('/modelos/access-users', [ModeloController::class, 'accessUsers']);
    Route::post('/modelos/access-users', [ModeloController::class, 'updateBulkAccessUsers']);
    Route::get('/modelos/{modelo}', [ModeloController::class, 'show']);
    Route::post('/modelos/{modelo}/share', [ModeloController::class, 'share']);
    Route::post('/modelos/{modelo}/access-users', [ModeloController::class, 'updateAccessUsers']);
    Route::post('/modelos/{modelo}', [ModeloController::class, 'update']);
    Route::put('/modelos/{modelo}', [ModeloController::class, 'update']);
    Route::delete('/modelos/{modelo}', [ModeloController::class, 'destroy']);

    Route::prefix('integrations/mercado-livre')->group(function () {
        Route::get('/config', [MercadoLivreConfigController::class, 'show']);
        Route::put('/config', [MercadoLivreConfigController::class, 'update']);
        Route::get('/account', [MercadoLivreController::class, 'account']);
        Route::delete('/account', [MercadoLivreController::class, 'disconnect']);
        Route::post('/oauth/token', [MercadoLivreController::class, 'oauthToken']);
        Route::post('/sync', [MercadoLivreController::class, 'sync']);
        Route::get('/products', [MercadoLivreController::class, 'products']);
        Route::post('/products/sync', [MercadoLivreController::class, 'syncProducts']);
        Route::patch('/products/{itemId}', [MercadoLivreController::class, 'updateProduct']);
        Route::post('/customization', [MercadoLivreController::class, 'sendCustomization']);
    });

    Route::prefix('integrations/fiscal')->group(function () {
        Route::post('/emit', [FiscalProviderController::class, 'emit']);
        Route::post('/status', [FiscalProviderController::class, 'status']);
    });

    // ─── Gráfica Plus ───
    Route::prefix('grafica-plus')->group(function () {
        Route::get('/clients', [GpClientController::class, 'index']);
        Route::post('/clients', [GpClientController::class, 'store']);
        Route::put('/clients/{client}', [GpClientController::class, 'update']);
        Route::delete('/clients/{client}', [GpClientController::class, 'destroy']);

        Route::get('/suppliers', [GpSupplierController::class, 'index']);
        Route::post('/suppliers', [GpSupplierController::class, 'store']);
        Route::put('/suppliers/{supplier}', [GpSupplierController::class, 'update']);
        Route::delete('/suppliers/{supplier}', [GpSupplierController::class, 'destroy']);

        Route::get('/products', [GpProductController::class, 'index']);
        Route::post('/products', [GpProductController::class, 'store']);
        Route::put('/products/{product}', [GpProductController::class, 'update']);
        Route::delete('/products/{product}', [GpProductController::class, 'destroy']);

        Route::get('/quotes', [GpQuoteController::class, 'index']);
        Route::post('/quotes', [GpQuoteController::class, 'store']);
        Route::put('/quotes/{quote}', [GpQuoteController::class, 'update']);
        Route::delete('/quotes/{quote}', [GpQuoteController::class, 'destroy']);
        Route::post('/quotes/{quote}/approve', [GpQuoteController::class, 'approve']);

        Route::get('/orders', [GpOrderController::class, 'index']);
        Route::post('/orders', [GpOrderController::class, 'store']);
        Route::patch('/orders/{order}', [GpOrderController::class, 'update']);
        Route::delete('/orders/{order}', [GpOrderController::class, 'destroy']);

        Route::get('/production', [GpProductionController::class, 'index']);
        Route::patch('/production/{productionOrder}', [GpProductionController::class, 'update']);

        Route::get('/deliveries', [GpDeliveryController::class, 'index']);
        Route::patch('/deliveries/{delivery}', [GpDeliveryController::class, 'update']);

        Route::get('/cash-flow', [GpFinancialController::class, 'indexCashFlow']);
        Route::post('/cash-flow', [GpFinancialController::class, 'storeCashFlow']);
        Route::delete('/cash-flow/{entry}', [GpFinancialController::class, 'destroyCashFlow']);

        Route::get('/bills', [GpFinancialController::class, 'indexBills']);
        Route::post('/bills', [GpFinancialController::class, 'storeBill']);
        Route::patch('/bills/{bill}', [GpFinancialController::class, 'updateBill']);
        Route::delete('/bills/{bill}', [GpFinancialController::class, 'destroyBill']);

        Route::get('/expenses', [GpFinancialController::class, 'indexExpenses']);
        Route::post('/expenses', [GpFinancialController::class, 'storeExpense']);
        Route::delete('/expenses/{expense}', [GpFinancialController::class, 'destroyExpense']);

        Route::get('/product-templates', [GpProductTemplateController::class, 'index']);
        Route::post('/product-templates', [GpProductTemplateController::class, 'store']);
        Route::put('/product-templates/{template}', [GpProductTemplateController::class, 'update']);
        Route::delete('/product-templates/{template}', [GpProductTemplateController::class, 'destroy']);
    });
});
