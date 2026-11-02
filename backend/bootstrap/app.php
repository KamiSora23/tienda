<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Detrás del proxy de Docker (nginx): https, dominio e IP real del cliente vienen en las cabeceras X-Forwarded-*.
        $middleware->trustProxies(at: '*');
        $middleware->alias(['api.token' => \App\Http\Middleware\ApiTokenAuth::class, 'permission.gate' => \App\Http\Middleware\PermissionGate::class, 'portal.customer' => \App\Http\Middleware\PortalCustomerAuth::class, 'integration.api' => \App\Http\Middleware\IntegrationApiAuth::class, 'platform.admin' => \App\Http\Middleware\PlatformAdmin::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
