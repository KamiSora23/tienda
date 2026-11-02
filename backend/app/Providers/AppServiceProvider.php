<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
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
        // Los identificadores de las rutas son numéricos: "/sales/abc" debe responder 404, no un error 500.
        foreach (['id', 'product', 'role', 'meter', 'session', 'supplier', 'related'] as $param) {
            Route::pattern($param, '[0-9]+');
        }
    }
}
