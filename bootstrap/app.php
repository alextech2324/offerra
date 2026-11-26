<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware([])
                ->group(base_path('routes/cdn.php'));
        },
    )
    ->withSchedule(function (Schedule $schedule): void {
        // Daily 10:00 snapshot for Archive banner. First so the slot is not
        // eaten by hourly availability. No live recount, no auto-archive.
        $schedule->command('offers:scan-stale-dead')
            ->dailyAt('10:00')
            ->timezone(config('app.timezone'))
            ->withoutOverlapping(30);

        $schedule->command('offers:recheck-infra-dns')->everyMinute()->withoutOverlapping(10);
        $schedule->command('origin:sync-servers')->hourly()->withoutOverlapping(10);
        $schedule->command('origin:check-health')->everyFiveMinutes()->withoutOverlapping(5);
        // Dead origin: wait 10 min, then 3 SSH confirms 1 min apart, then evacuate.
        $schedule->command('origin:auto-evacuate')->everyMinute()->withoutOverlapping(5);
        $schedule->command('origin:billing-remind')->hourly()->withoutOverlapping(5);
        $schedule->command('backup:check')->hourly()->withoutOverlapping(5);
        // Self-heal any lander that lost required template functions (returns HTTP 500)
        // by redeploying it. Non-destructive: stale webroots are only reported here,
        // prune them manually with `offers:heal-landers --prune-orphans`.
        $schedule->command('offers:heal-landers')->everyFifteenMinutes()->withoutOverlapping(20);
        $schedule->command('offers:queue-gsc-pending')->everyFiveMinutes()->withoutOverlapping(4);
        // HTTPS green/red dots. Full sweep of ~750 offers is a couple of minutes
        // (DoH + HEAD); 15 min leaves headroom. Two consecutive fails to go red.
        $schedule->command('offers:check-availability --stale-minutes=14')
            ->everyFifteenMinutes()
            ->withoutOverlapping(14);

        // Incoming CF Trust and Safety reports. Isolated artisan — not deploy/gsc/dns/purge queues.
        $schedule->command('cloudflare:poll-abuse')
            ->everyThreeMinutes()
            ->withoutOverlapping(3);

        // GSC URL inspection at 03:00 Kyiv — 24h after sitemap submit, then daily until indexed / duplicate / 7d unseen.
        $schedule->command('offers:inspect-google-index')
            ->dailyAt('03:00')
            ->timezone(config('app.timezone'))
            ->withoutOverlapping(180);

        $schedule->command('clicks:prune')
            ->dailyAt('03:40')
            ->timezone(config('app.timezone'))
            ->withoutOverlapping(30);
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'admin' => EnsureAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
