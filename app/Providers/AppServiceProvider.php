<?php

namespace App\Providers;

use App\Models\Product;
use App\Observers\ProductObserver;
use App\Support\StructuredPerformanceLogger;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(StructuredPerformanceLogger::class, function () {
            return new StructuredPerformanceLogger();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Product::observe(ProductObserver::class);
        DB::listen(function (QueryExecuted $query) {
            app(StructuredPerformanceLogger::class)->addQuery(
                $query->sql,
                $query->bindings,
                $query->time
            );
        });
    }
}
