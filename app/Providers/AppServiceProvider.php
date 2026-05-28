<?php

namespace App\Providers;

use App\Neuron\Providers\ConfiguredRuntimeAiProviderFactory;
use App\Neuron\Providers\RuntimeAiProviderFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(RuntimeAiProviderFactory::class, ConfiguredRuntimeAiProviderFactory::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
