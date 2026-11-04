<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureManagesAdvertisement;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1'));
        $middleware->append(SecurityHeaders::class);
        // While a database restore runs the portal is in maintenance mode; the administrator's
        // page keeps polling the restore's progress.
        $middleware->preventRequestsDuringMaintenance(except: ['api/admin/maintenance/restores/*']);
        $middleware->api(append: [EnsureActiveUser::class]);
        $middleware->alias([
            'role' => EnsureRole::class,
            'permission' => EnsurePermission::class,
            'manages-advertisement' => EnsureManagesAdvertisement::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage() ?: 'This action is unauthorized.'], 403);
            }
        });
        // While a restore replaces the tables, the progress poll can briefly find one missing:
        // answer "try again" instead of logging an error.
        $restoring = fn (Throwable $e) => $e instanceof QueryException && app()->isDownForMaintenance() && request()->is('api/admin/maintenance/restores/*');
        $exceptions->dontReportWhen($restoring);
        $exceptions->render(fn (QueryException $e) => $restoring($e)
            ? response()->json(['message' => 'The database is being restored. Try again in a few seconds.'], 503, ['Retry-After' => '5'])
            : null);
    })->create();
