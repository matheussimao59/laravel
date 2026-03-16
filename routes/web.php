<?php

use App\Http\Controllers\MercadoLivreController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function () {
    Route::get('/mercado-livre', [MercadoLivreController::class, 'dashboard'])->name('mercado-livre.dashboard');
    Route::get('/mercado-livre-beta', [MercadoLivreController::class, 'betaDashboard'])->name('mercado-livre-beta.dashboard');
    Route::post('/mercado-livre/products', [MercadoLivreController::class, 'storeProduct'])->name('mercado-livre.store');
    Route::patch('/mercado-livre/products/{id}/price', [MercadoLivreController::class, 'updatePrice'])->name('mercado-livre.update-price');
});
