<?php

namespace App\Providers;

use App\Services\CloudflareClient;
use App\Services\DeployConnection;
use App\Services\DeployService;
use App\Services\DynadotClient;
use App\Services\GoogleSearchConsoleClient;
use App\Services\OfferConfigBuilder;
use App\Services\OfferGenerator;
use App\Services\OfferScanner;
use App\Services\OfferStatusReconciler;
use App\Services\OfferTeardownService;
use App\Services\OfferVerificationFileService;
use App\Services\OfferZipExporter;
use App\Services\OriginHostService;
use App\Services\OriginPool;
use App\Services\TemplateCatalog;
use Illuminate\Support\ServiceProvider;

class OfferServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OfferScanner::class, function () {
            return new OfferScanner(config('offerra.offers_path'));
        });

        $this->app->singleton(TemplateCatalog::class, function () {
            return new TemplateCatalog(
                config('offerra.templates_path'),
            );
        });

        $this->app->singleton(OfferConfigBuilder::class);
        $this->app->singleton(DeployConnection::class);

        $this->app->singleton(OfferVerificationFileService::class, function () {
            return new OfferVerificationFileService(config('offerra.offers_path'));
        });

        $this->app->singleton(DeployService::class, function ($app) {
            return new DeployService(
                $app->make(OfferGenerator::class),
                $app->make(OriginHostService::class),
                $app->make(OriginPool::class),
                $app->make(OfferVerificationFileService::class),
                config('offerra.offers_path'),
            );
        });

        $this->app->singleton(OfferGenerator::class, function ($app) {
            return new OfferGenerator(
                $app->make(OfferConfigBuilder::class),
                $app->make(TemplateCatalog::class),
                $app->make(OfferVerificationFileService::class),
                config('offerra.offers_path'),
                config('offerra.templates_path'),
            );
        });

        $this->app->singleton(OfferZipExporter::class, function ($app) {
            return new OfferZipExporter(
                $app->make(OfferGenerator::class),
                config('offerra.offers_path'),
            );
        });

        $this->app->singleton(OfferStatusReconciler::class, function ($app) {
            return new OfferStatusReconciler(
                $app->make(DeployConnection::class),
                $app->make(OriginPool::class),
                config('offerra.offers_path'),
            );
        });

        $this->app->singleton(OfferTeardownService::class, function ($app) {
            return new OfferTeardownService(
                $app->make(CloudflareClient::class),
                $app->make(GoogleSearchConsoleClient::class),
                $app->make(OfferVerificationFileService::class),
                $app->make(OriginHostService::class),
                $app->make(DeployService::class),
                $app->make(OriginPool::class),
                $app->make(DynadotClient::class),
                config('offerra.offers_path'),
            );
        });
    }
}
