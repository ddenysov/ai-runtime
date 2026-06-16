<?php

namespace App\Providers;

use App\Channels\Services\AgentChannelDeliveryResolver;
use App\Channels\Services\TelegramChannelDeliveryDestinationResolver;
use App\Gate\GateConfigPublisher;
use App\Neuron\Diary\Contracts\DiaryStorage;
use App\Neuron\Diary\DiaryService;
use App\Neuron\Diary\DiaryStorageManager;
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

        $this->app->singleton(GateConfigPublisher::class, function (): GateConfigPublisher {
            return new GateConfigPublisher((string) config('gate.storage_path'));
        });

        $this->app->singleton(DiaryStorageManager::class);

        $this->app->singleton(DiaryStorage::class, function ($app): DiaryStorage {
            return $app->make(DiaryStorageManager::class)->driver();
        });

        $this->app->singleton(DiaryService::class, function ($app): DiaryService {
            $timezone = config('diary.timezone');

            return new DiaryService(
                storage: $app->make(DiaryStorage::class),
                timezone: is_string($timezone) && $timezone !== '' ? $timezone : null,
            );
        });

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
