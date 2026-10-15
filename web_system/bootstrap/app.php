<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureAdminResponder;
use App\Http\Middleware\EnsureResponderAccount;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'api.token' => AuthenticateApiToken::class,
            'admin' => EnsureAdminResponder::class,
            'account.active' => EnsureAccountIsActive::class,
            'responder' => EnsureResponderAccount::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
