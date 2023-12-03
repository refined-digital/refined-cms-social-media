<?php

namespace RefinedDigital\ShopifyHydrogen\Module\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Storage;
use Illuminate\Console\Scheduling\Schedule;
use League\Flysystem\Filesystem;
use RefinedDigital\CMS\Commands\CreateModule;
use RefinedDigital\ShopifyHydrogen\Commands\Install;
use RefinedDigital\ShopifyHydrogen\Commands\SyncShopifyHydrogenMedia;
use RefinedDigital\ShopifyHydrogen\Module\FlySystem\ShopifyHydrogenAdapter;
use RefinedDigital\CMS\Modules\Core\Aggregates\PackageAggregate;
use RefinedDigital\CMS\Modules\Core\Aggregates\ModuleAggregate;
use RefinedDigital\CMS\Modules\Core\Aggregates\RouteAggregate;

class ShopifyHydrogenServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        try {
            if ($this->app->runningInConsole()) {
                if (\DB::connection()->getDatabaseName() && !file_exists(config_path('shopify-hydrogen.php'))) {
                    $this->commands([
                        Install::class
                    ]);
                }
            }
        } catch (\Exception $e) {}


        $this->publishes([
            __DIR__.'/../../../config/shopify-hydrogen.php' => config_path('shopify-hydrogen.php'),
        ], 'shopify-hydrogen');
        
        Storage::extend('shopify_hydrogen', function ($app, $config) {
            return new Filesystem(new ShopifyHydrogenAdapter($config));
        });


        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncShopifyHydrogenMedia::class,
            ]);
        }

        $this->app->booted(function() {
            $schedule = app(Schedule::class);
            $schedule->command('refinedCMS:sync-shopify-hydrogen-media')->everyMinute();
        });
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {

        $this->mergeConfigFrom(__DIR__.'/../../../config/shopify-hydrogen.php', 'shopify-hydrogen');
    }
}
