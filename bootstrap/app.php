<?php

use App\Http\Middleware\EnsurePageIsEnabled;
use App\Http\Middleware\EnsureUserIsApproved;
use App\Http\Middleware\RequirePendingSurveys;
use App\Http\Middleware\RoleMiddleware;
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
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'approved' => EnsureUserIsApproved::class,
            'role' => RoleMiddleware::class,
            'page.enabled' => EnsurePageIsEnabled::class,
            'surveys.required' => RequirePendingSurveys::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
