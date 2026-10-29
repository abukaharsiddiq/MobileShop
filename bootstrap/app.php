<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // provider er webhook (signature diye jachai) - CSRF token thake na
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
        $middleware->alias([
         'role' => \App\Http\Middleware\RoleMiddleware::class,
          'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
          'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        // inactive kora user er session protiti request e bondho hoy
        $middleware->web(append: [
            \App\Http\Middleware\EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // validation fail hole egulo session e (database) ferot jabe na
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
            'new_password',
            'confirm_password',
        ]);
    })->create();
