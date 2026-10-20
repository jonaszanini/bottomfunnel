<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GoogleAdsController;
use App\Http\Controllers\HookController;
use App\Http\Controllers\IpBlockController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\TrackController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\TrackScriptController;
use App\Http\Controllers\WatchedProductController;
use Illuminate\Support\Facades\Route;

Route::post('/theme', [ThemeController::class, 'update'])->name('theme.update');
Route::view('/welcome', 'welcome')->name('welcome');

Route::post('/hooks/{platform}/{secret}', HookController::class)->middleware('throttle:60,1');
Route::post('/t/collect', TrackingController::class)->middleware('throttle:120,1');
Route::get('/t.js', TrackScriptController::class);

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::resource('tracks', TrackController::class);
    Route::post('/tracks/{track}/spend', [TrackController::class, 'spend'])->name('tracks.spend');
    Route::post('/conversions/{conversion}/match', [TrackController::class, 'match'])->name('conversions.match');
    Route::post('/conversions/{conversion}/retry', [TrackController::class, 'retry'])->name('conversions.retry');

    Route::get('/pages', [PageController::class, 'index'])->name('pages.index');
    Route::post('/pages/generate', [PageController::class, 'generate'])->name('pages.generate');
    Route::get('/pages/{page}/edit', [PageController::class, 'edit'])->name('pages.edit');
    Route::put('/pages/{page}', [PageController::class, 'update'])->name('pages.update');
    Route::post('/pages/{page}/preview', [PageController::class, 'preview'])->name('pages.preview');
    Route::get('/pages/{page}/export', [PageController::class, 'export'])->name('pages.export');
    Route::delete('/pages/{page}', [PageController::class, 'destroy'])->name('pages.destroy');

    Route::get('/google-ads', [GoogleAdsController::class, 'index'])->name('google.index');
    Route::get('/google-ads/connect', [GoogleAdsController::class, 'connect'])->name('google.connect');
    Route::get('/google-ads/callback', [GoogleAdsController::class, 'callback'])->name('google.callback');
    Route::post('/google-ads/accounts', [GoogleAdsController::class, 'store'])->name('google.store');
    Route::delete('/google-ads/accounts/{account}', [GoogleAdsController::class, 'destroy'])->name('google.destroy');
    Route::get('/google-ads/accounts/{account}/options', [GoogleAdsController::class, 'options'])->name('google.options');

    Route::get('/ip-blocks', [IpBlockController::class, 'index'])->name('blocks.index');
    Route::post('/ip-blocks/{ipBlock}/unblock', [IpBlockController::class, 'unblock'])->name('blocks.unblock');
    Route::post('/ip-blocks/{ipBlock}/whitelist', [IpBlockController::class, 'whitelist'])->name('blocks.whitelist');

    Route::get('/watching-products', [WatchedProductController::class, 'index'])->name('watching-products.index');
    Route::post('/watching-products', [WatchedProductController::class, 'store'])->name('watching-products.store');
    Route::put('/watching-products/{watchedProduct}', [WatchedProductController::class, 'update'])->name('watching-products.update');
    Route::delete('/watching-products/{watchedProduct}', [WatchedProductController::class, 'destroy'])->name('watching-products.destroy');
    Route::post('/watching-products/{watchedProduct}/review', [WatchedProductController::class, 'review'])->name('watching-products.review');
    Route::patch('/watching-products/{watchedProduct}/status', [WatchedProductController::class, 'updateStatus'])->name('watching-products.status');
});
