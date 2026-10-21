<?php

namespace App\Providers;

use App\Models\WatchedProduct;
use App\Observers\WatchedProductObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        WatchedProduct::observe(WatchedProductObserver::class);
    }
}
