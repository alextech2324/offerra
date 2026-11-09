<?php

use App\Http\Controllers\BackupServerController;
use App\Http\Controllers\CloudflareAccountController;
use App\Http\Controllers\CrmLeadController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainBalanceController;
use App\Http\Controllers\DomainPurchaseController;
use App\Http\Controllers\DomainSearchController;
use App\Http\Controllers\FunnelAlertController;
use App\Http\Controllers\GeoMinDepositController;
use App\Http\Controllers\MirrorDomainController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\OfferStatsController;
use App\Http\Controllers\OriginServerController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\TemplatePreviewController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/offers', [OfferController::class, 'index'])->name('offers.index');
    Route::get('/offers/stats', [OfferStatsController::class, 'index'])->name('offers.stats');
    Route::get('/offers/archive', [OfferController::class, 'archiveIndex'])->name('offers.archive.index');
    Route::get('/offers/create', [OfferController::class, 'create'])->name('offers.create');
    Route::get('/offers/brand-lookup', [OfferController::class, 'brandLookup'])->name('offers.brand-lookup');
    Route::post('/offers/stale-dead/archive', [OfferController::class, 'archiveStaleDead'])->name('offers.stale-dead.archive');
    Route::post('/offers', [OfferController::class, 'store'])->name('offers.store');
    Route::post('/offers/bulk', [OfferController::class, 'storeBulk'])->name('offers.bulk');
    Route::post('/offers/bulk-action', [OfferController::class, 'bulkAction'])->name('offers.bulk-action');
    Route::post('/offers/scan-availability', [OfferController::class, 'scanAvailability'])->name('offers.scan-availability');
    Route::post('/offers/{offer}/deploy', [OfferController::class, 'deploy'])->name('offers.deploy');
    Route::get('/offers/{offer}/zip', [OfferController::class, 'downloadZip'])->name('offers.download-zip');
    Route::post('/offers/{offer}/archive', [OfferController::class, 'archive'])->name('offers.archive');
    Route::delete('/offers/{offer}', [OfferController::class, 'destroy'])->name('offers.destroy');
    Route::post('/offers/{offer}/archive/retry', [OfferController::class, 'retryArchive'])->name('offers.archive.retry');
    Route::post('/offers/{offer}/restore', [OfferController::class, 'restore'])->name('offers.restore');
    Route::post('/offers/{offer}/provision', [OfferController::class, 'provision'])->name('offers.provision');
    Route::post('/offers/{offer}/recheck-dns', [OfferController::class, 'recheckDns'])->name('offers.recheck-dns');
    Route::patch('/offers/{offer}', [OfferController::class, 'update'])->name('offers.update');
    Route::patch('/offers/{offer}/indexing', [OfferController::class, 'updateIndexing'])->name('offers.indexing');
    Route::post('/offers/{offer}/check-availability', [OfferController::class, 'checkAvailability'])->name('offers.check-availability');
    Route::post('/offers/{offer}/inspect-index', [OfferController::class, 'inspectIndex'])->name('offers.inspect-index');
    Route::post('/offers/{offer}/gsc', [OfferController::class, 'submitGsc'])->name('offers.gsc');
    Route::get('/leads', [CrmLeadController::class, 'index'])->name('leads.index');
    Route::post('/leads', [CrmLeadController::class, 'store'])->name('leads.send');

    Route::get('/mirrors', [MirrorDomainController::class, 'index'])->name('mirrors.index');
    Route::post('/mirrors/prune', [MirrorDomainController::class, 'prune'])->name('mirrors.prune');
    Route::patch('/mirrors/{mirror}', [MirrorDomainController::class, 'update'])->name('mirrors.update');
    Route::delete('/mirrors/{mirror}', [MirrorDomainController::class, 'destroy'])->name('mirrors.destroy');

    Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
    Route::get('/preview/{template}/{path?}', [TemplatePreviewController::class, 'show'])
        ->where('path', '.*')
        ->name('templates.preview');
    Route::post('/domains/search', [DomainSearchController::class, 'store'])->name('domains.search');
    Route::post('/domains/purchase', [DomainPurchaseController::class, 'store'])->name('domains.purchase');
    Route::get('/domains/balance', [DomainBalanceController::class, 'show'])->name('domains.balance');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/test-cloudflare', [SettingsController::class, 'testCloudflare'])->name('settings.test-cloudflare');
    Route::post('/settings/gsc-verification', [SettingsController::class, 'storeGscVerification'])->name('settings.gsc-verification.store');
    Route::delete('/settings/gsc-verification', [SettingsController::class, 'destroyGscVerification'])->name('settings.gsc-verification.destroy');
    Route::get('/settings/google/redirect', [SettingsController::class, 'redirectGoogle'])->name('settings.google.redirect');
    Route::get('/settings/google/callback', [SettingsController::class, 'callbackGoogle'])->name('settings.google.callback');
    Route::post('/settings/google/{googleAccount}/primary', [SettingsController::class, 'setPrimaryGoogle'])->name('settings.google.primary');
    Route::delete('/settings/google/{googleAccount}', [SettingsController::class, 'disconnectGoogle'])->name('settings.google.disconnect');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::middleware('admin')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])
            ->name('users.reset-password');
        Route::patch('/users/{user}/email', [UserController::class, 'updateEmail'])
            ->name('users.email.update');
        Route::post('/users/{user}/logout', [UserController::class, 'logout'])
            ->name('users.logout');
        Route::delete('/users/{user}/sessions/{session}', [UserController::class, 'destroySession'])
            ->where('session', '[A-Za-z0-9._-]+')
            ->name('users.sessions.destroy');
        Route::patch('/users/{user}/access', [UserController::class, 'updateAccess'])
            ->name('users.access.update');
        Route::patch('/users/{user}/two-factor', [UserController::class, 'updateTwoFactor'])
            ->name('users.two-factor.update');

        Route::get('/cloudflare-accounts', [CloudflareAccountController::class, 'index'])->name('cloudflare-accounts.index');
        Route::post('/cloudflare-accounts', [CloudflareAccountController::class, 'store'])->name('cloudflare-accounts.store');
        Route::post('/cloudflare-accounts/{cloudflareAccount}/update', [CloudflareAccountController::class, 'update'])
            ->name('cloudflare-accounts.update');
        Route::delete('/cloudflare-accounts/{cloudflareAccount}', [CloudflareAccountController::class, 'destroy'])
            ->name('cloudflare-accounts.destroy');
        Route::post('/cloudflare-accounts/{cloudflareAccount}/check', [CloudflareAccountController::class, 'check'])
            ->name('cloudflare-accounts.check');

        Route::get('/backup', [BackupServerController::class, 'index'])->name('backup.index');
        Route::post('/backup', [BackupServerController::class, 'store'])->name('backup.store');
        Route::post('/backup/{backupServer}/update', [BackupServerController::class, 'update'])->name('backup.update');
        Route::post('/backup/{backupServer}/check', [BackupServerController::class, 'check'])->name('backup.check');

        Route::get('/origin-servers', [OriginServerController::class, 'index'])->name('origin-servers.index');
        Route::post('/origin-servers', [OriginServerController::class, 'store'])->name('origin-servers.store');
        Route::post('/origin-servers/{originServer}/update', [OriginServerController::class, 'update'])
            ->name('origin-servers.update');
        Route::delete('/origin-servers/{originServer}', [OriginServerController::class, 'destroy'])->name('origin-servers.destroy');
        Route::post('/origin-servers/{originServer}/check', [OriginServerController::class, 'check'])->name('origin-servers.check');
        Route::post('/origin-servers/{originServer}/evacuate', [OriginServerController::class, 'evacuate'])
            ->name('origin-servers.evacuate');
        Route::post('/origin-servers/rebalance', [OriginServerController::class, 'rebalance'])
            ->name('origin-servers.rebalance');
        Route::post('/origin-servers/sync', [OriginServerController::class, 'sync'])->name('origin-servers.sync');
        Route::post('/origin-servers/check-all', [OriginServerController::class, 'checkAll'])->name('origin-servers.check-all');

        Route::get('/min-deposits', [GeoMinDepositController::class, 'index'])->name('min-deposits.index');
        Route::post('/min-deposits', [GeoMinDepositController::class, 'store'])->name('min-deposits.store');
        Route::post('/min-deposits/{geoMinDeposit}/update', [GeoMinDepositController::class, 'update'])
            ->name('min-deposits.update');
        Route::delete('/min-deposits/{geoMinDeposit}', [GeoMinDepositController::class, 'destroy'])
            ->name('min-deposits.destroy');

        Route::get('/funnel-alerts', [FunnelAlertController::class, 'index'])->name('funnel-alerts.index');
        Route::patch('/funnel-alerts', [FunnelAlertController::class, 'update'])->name('funnel-alerts.update');
        Route::post('/funnel-alerts/retry-telegram', [FunnelAlertController::class, 'retryTelegram'])
            ->name('funnel-alerts.retry-telegram');
        Route::post('/funnel-alerts/clear-events', [FunnelAlertController::class, 'clearEvents'])
            ->name('funnel-alerts.clear-events');
        Route::post('/funnel-alerts/ignored-brands', [FunnelAlertController::class, 'ignoreBrand'])
            ->name('funnel-alerts.ignored-brands.store');
        Route::delete('/funnel-alerts/ignored-brands/{ignored}', [FunnelAlertController::class, 'unignoreBrand'])
            ->name('funnel-alerts.ignored-brands.destroy');
        Route::post('/funnel-alerts/ignored-geos', [FunnelAlertController::class, 'ignoreGeo'])
            ->name('funnel-alerts.ignored-geos.store');
        Route::delete('/funnel-alerts/ignored-geos/{ignored}', [FunnelAlertController::class, 'unignoreGeo'])
            ->name('funnel-alerts.ignored-geos.destroy');
        Route::post('/funnel-alerts/ignored-langs', [FunnelAlertController::class, 'ignoreLang'])
            ->name('funnel-alerts.ignored-langs.store');
        Route::delete('/funnel-alerts/ignored-langs/{ignored}', [FunnelAlertController::class, 'unignoreLang'])
            ->name('funnel-alerts.ignored-langs.destroy');
        Route::post('/funnel-alerts/regenerate-token', [FunnelAlertController::class, 'regenerateToken'])
            ->name('funnel-alerts.regenerate-token');
    });
});

require __DIR__.'/auth.php';
