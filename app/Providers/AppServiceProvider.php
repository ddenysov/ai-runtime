<?php

namespace App\Providers;

use App\Channels\Services\AgentChannelDeliveryResolver;
use App\Channels\Services\TelegramChannelDeliveryDestinationResolver;
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

        $this->app->singleton(AgentChannelDeliveryResolver::class, function (): AgentChannelDeliveryResolver {
            return new AgentChannelDeliveryResolver([
                $this->app->make(TelegramChannelDeliveryDestinationResolver::class),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
