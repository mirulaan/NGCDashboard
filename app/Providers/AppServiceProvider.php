<?php

namespace App\Providers;

use App\Services\PlantService;
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
        try {
            $configuredPlant = (string) config('telemetry.configured_plant', env('TELEMETRY_PLANT', 'auto'));
            if ($configuredPlant !== 'auto') {
                PlantService::syncConfig($configuredPlant);
            }
        } catch (\Throwable $e) {
            // Failsafe during low-level CLI commands or unmigrated databases
        }
    }
}
