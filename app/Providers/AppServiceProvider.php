<?php

namespace App\Providers;

use App\Models\WatchedProduct;
use App\Observers\WatchedProductObserver;
use App\Services\Seo\DataForSeoClient;
use App\Services\Seo\SeoClientContract;
use App\Services\WatchingProducts\SerpApiClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SeoClientContract::class, function ($app) {
            return config('watching.seo.provider', 'dataforseo') === 'serpapi'
                ? $app->make(SerpApiClient::class)
                : $app->make(DataForSeoClient::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        WatchedProduct::observe(WatchedProductObserver::class);
    }
}
