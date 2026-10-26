<?php

use App\Http\Controllers\AffiliateNetworkAccountController;
use App\Http\Controllers\CopyStudioController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GoogleAdsController;
use App\Http\Controllers\GoogleLoginAccountController;
use App\Http\Controllers\HookController;
use App\Http\Controllers\InternalGoogleAdsController;
use App\Http\Controllers\IpBlockController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\TrackController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\TrackScriptController;
use App\Http\Controllers\WatchedProductController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

Route::post('/theme', [ThemeController::class, 'update'])->name('theme.update');
Route::redirect('/welcome', '/')->name('welcome');

Route::get('/terms-of-service', [LegalController::class, 'terms'])->name('terms.show');
Route::redirect('/terms', '/terms-of-service');
Route::get('/privacy-policy', [LegalController::class, 'privacy'])->name('privacy.show');
Route::redirect('/privacy', '/privacy-policy');

Route::post('/hooks/{platform}/{secret}', HookController::class)->middleware('throttle:60,1');
Route::post('/t/collect', TrackingController::class)->middleware('throttle:120,1');
Route::get('/t.js', TrackScriptController::class);

/**
 * External cron trigger for cron-job.org:
 * Calling URL: https://bottomfunnel.app/cron/run?token=hgytrsd543T4564FGHf7tgfs635GGf
 *
 * Runs Artisan::call('schedule:run') which evaluates all scheduled tasks.
 * Excluded from session/auth middleware and protected by timing-safe token verification.
 */
Route::get('/cron/run', function () {
    $token = (string) request()->query('token');
    $expected = (string) (config('app.cron_token') ?: config('flowhub.cron_token') ?: 'hgytrsd543T4564FGHf7tgfs635GGf');

    if ($token === '' || ! hash_equals($expected, $token)) {
        abort(403, 'Invalid cron token');
    }

    Artisan::call('schedule:run');

    return response()->json([
        'ok' => true,
        'message' => 'Scheduler executed successfully.',
        'output' => trim(Artisan::output()),
        'time' => now()->toIso8601String(),
    ]);
})->name('cron.run');

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
    Route::post('/pages', [PageController::class, 'store'])->name('pages.store');
    Route::post('/pages/crawl-and-clone', [PageController::class, 'crawlAndClone'])->name('pages.crawl-and-clone');
    Route::get('/pages/crawl-and-clone/status/{jobId}', [PageController::class, 'crawlStatus'])->name('pages.crawl-status');
    Route::post('/pages/extract-metadata', [PageController::class, 'extractMetadata'])->name('pages.extract-metadata');
    Route::post('/pages/generate-variations', [PageController::class, 'generateVariations'])->name('pages.generate-variations');
    Route::get('/pages/generate-variations/status/{jobId}', [PageController::class, 'variationsStatus'])->name('pages.variations-status');
    Route::post('/pages/compile', [PageController::class, 'compile'])->name('pages.compile');
    Route::post('/pages/export-direct', [PageController::class, 'exportDirect'])->name('pages.export-direct');
    Route::post('/pages/generate', [PageController::class, 'generate'])->name('pages.generate');
    Route::get('/pages/{page}/edit', [PageController::class, 'edit'])->name('pages.edit');
    Route::put('/pages/{page}', [PageController::class, 'update'])->name('pages.update');
    Route::post('/pages/{page}/preview', [PageController::class, 'preview'])->name('pages.preview');
    Route::get('/pages/{page}/export', [PageController::class, 'export'])->name('pages.export');
    Route::post('/pages/{page}/assign-campaign', [PageController::class, 'assignCampaign'])->name('pages.assign-campaign');
    Route::post('/pages/{page}/retry', [PageController::class, 'retry'])->name('pages.retry');
    Route::delete('/pages/{page}', [PageController::class, 'destroy'])->name('pages.destroy');

    Route::get('/google-ads', [GoogleAdsController::class, 'index'])->name('google.index');
    Route::get('/google-ads/connect', [GoogleAdsController::class, 'connect'])->name('google.connect');
    Route::get('/google-ads/callback', [GoogleAdsController::class, 'callback'])->name('google.callback');
    Route::post('/google-ads/accounts', [GoogleAdsController::class, 'store'])->name('google.store');
    Route::post('/google-ads/accounts/{account}/default', [GoogleAdsController::class, 'setDefault'])->name('google.accounts.default');
    Route::delete('/google-ads/accounts/{account}', [GoogleAdsController::class, 'destroy'])->name('google.destroy');
    Route::get('/google-ads/accounts/{account}/options', [GoogleAdsController::class, 'options'])->name('google.options');
    Route::get('/google-ads/accounts/{account}/conversion-actions/{action}/tag', [GoogleAdsController::class, 'conversionTag'])->name('google.conversion-actions.tag');

    // Credentials Vault: Google Accounts & Affiliate Accounts
    Route::resource('google-login-accounts', GoogleLoginAccountController::class)->except(['create', 'show', 'edit']);
    Route::post('/google-login-accounts/{googleLoginAccount}/reveal-password', [GoogleLoginAccountController::class, 'revealPassword'])
        ->middleware('throttle:30,1')
        ->name('google-login-accounts.reveal-password');

    Route::resource('affiliate-network-accounts', AffiliateNetworkAccountController::class)->except(['create', 'show', 'edit']);
    Route::post('/affiliate-network-accounts/{affiliateNetworkAccount}/reveal-password', [AffiliateNetworkAccountController::class, 'revealPassword'])
        ->middleware('throttle:30,1')
        ->name('affiliate-network-accounts.reveal-password');

    // Internal & Bot API for Google Ads management
    Route::get('/internal/google-ads/campaigns', [InternalGoogleAdsController::class, 'listCampaigns'])->name('internal.google.campaigns.index');
    Route::post('/internal/google-ads/campaigns', [InternalGoogleAdsController::class, 'createCampaign'])->name('internal.google.campaigns.store');
    Route::post('/internal/google-ads/campaigns/{campaign}/ad-groups', [InternalGoogleAdsController::class, 'createAdGroup'])->name('internal.google.ad-groups.store');
    Route::post('/internal/google-ads/ad-groups/{adGroup}/keywords', [InternalGoogleAdsController::class, 'addKeywords'])->name('internal.google.keywords.store');
    Route::post('/internal/google-ads/campaigns/{campaign}/negative-keywords', [InternalGoogleAdsController::class, 'addNegativeKeywords'])->name('internal.google.negatives.store');
    Route::post('/internal/google-ads/ad-groups/{adGroup}/responsive-search-ads', [InternalGoogleAdsController::class, 'createResponsiveSearchAd'])->name('internal.google.rsa.store');
    Route::patch('/internal/google-ads/campaigns/{campaign}/status', [InternalGoogleAdsController::class, 'updateCampaignStatus'])->name('internal.google.campaigns.status');
    Route::patch('/internal/google-ads/budgets/{budget}', [InternalGoogleAdsController::class, 'updateCampaignBudget'])->name('internal.google.budgets.update');
    Route::get('/internal/google-ads/campaigns/{campaign}/ad-groups', [InternalGoogleAdsController::class, 'listAdGroups'])->name('internal.google.ad-groups.index');
    Route::get('/internal/google-ads/ad-groups/{adGroup}/keywords', [InternalGoogleAdsController::class, 'listKeywords'])->name('internal.google.keywords.index');
    Route::get('/internal/google-ads/ad-groups/{adGroup}/ads', [InternalGoogleAdsController::class, 'listAds'])->name('internal.google.ads.index');

    Route::get('/ip-blocks', [IpBlockController::class, 'index'])->name('blocks.index');
    Route::post('/ip-blocks/block', [IpBlockController::class, 'block'])->name('blocks.block');
    Route::post('/ip-blocks/{ipBlock}/unblock', [IpBlockController::class, 'unblock'])->name('blocks.unblock');
    Route::post('/ip-blocks/{ipBlock}/whitelist', [IpBlockController::class, 'whitelist'])->name('blocks.whitelist');

    Route::get('/watching-products', [WatchedProductController::class, 'index'])->name('watching-products.index');
    Route::post('/watching-products', [WatchedProductController::class, 'store'])->name('watching-products.store');
    Route::get('/watching-products/{watchedProduct}', [WatchedProductController::class, 'show'])->name('watching-products.show');
    Route::put('/watching-products/{watchedProduct}', [WatchedProductController::class, 'update'])->name('watching-products.update');
    Route::delete('/watching-products/{watchedProduct}', [WatchedProductController::class, 'destroy'])->name('watching-products.destroy');
    Route::post('/watching-products/{watchedProduct}/review', [WatchedProductController::class, 'review'])->name('watching-products.review');
    Route::patch('/watching-products/{watchedProduct}/status', [WatchedProductController::class, 'updateStatus'])->name('watching-products.status');
    Route::post('/watching-products/{watchedProduct}/refresh-now', [WatchedProductController::class, 'refreshNow'])->name('watching-products.refresh-now');
    Route::post('/watching-products/{watchedProduct}/refresh-volume', [WatchedProductController::class, 'refreshVolume'])->name('watching-products.refresh-volume');
    Route::post('/watching-products/{watchedProduct}/strategy', [WatchedProductController::class, 'updateStrategy'])->name('watching-products.strategy.update');
    Route::post('/watching-products/{watchedProduct}/negatives', [WatchedProductController::class, 'storeNegative'])->name('watching-products.negatives.store');
    Route::patch('/watching-products/{watchedProduct}/negatives/{negativeKeyword}', [WatchedProductController::class, 'updateNegative'])->name('watching-products.negatives.update');
    Route::delete('/watching-products/{watchedProduct}/negatives/{negativeKeyword}', [WatchedProductController::class, 'destroyNegative'])->name('watching-products.negatives.destroy');
    Route::get('/watching-products/{watchedProduct}/negatives/export', [WatchedProductController::class, 'exportNegatives'])->name('watching-products.negatives.export');
    Route::post('/watching-products/{watchedProduct}/negatives/generate-campaign', [WatchedProductController::class, 'generateCampaignNegatives'])->name('watching-products.negatives.generate-campaign');

    Route::get('/copy', [CopyStudioController::class, 'index'])->name('copy.index');
    Route::post('/api/copy/generate-base', [CopyStudioController::class, 'generateBase'])->name('copy.generate-base');
    Route::post('/api/copy/shorten', [CopyStudioController::class, 'shorten'])->name('copy.shorten');
    Route::post('/api/copy/refine-with-screenshot', [CopyStudioController::class, 'refineWithScreenshot'])->name('copy.refine-with-screenshot');
    Route::get('/api/copy/status/{jobId}', [CopyStudioController::class, 'copyStatus'])->name('copy.status');
});
