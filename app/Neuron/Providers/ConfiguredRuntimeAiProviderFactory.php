<?php

namespace App\Neuron\Providers;

use App\Enums\AiProviderType;
use App\Models\AiProvider;
use App\Models\AiProviderModel;
use App\Neuron\Providers\Gemini\GeminiThoughtSignatureProvider;
use InvalidArgumentException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Gemini\Gemini;

class ConfiguredRuntimeAiProviderFactory implements RuntimeAiProviderFactory
{
    public function make(array $definition): AIProviderInterface
    {
        $providerModel = $this->resolveProviderModel($definition);
        $provider = $providerModel->provider;
        $httpCapture = new CapturingAiProviderHttpClient();

        $providerInstance = match ($provider->type) {
            AiProviderType::GEMINI => new GeminiThoughtSignatureProvider(
                new Gemini(
                    key: (string) $provider->credential('key'),
                    model: $providerModel->model,
                    httpClient: $httpCapture,
                ),
                $httpCapture,
            ),
        };

        return new LoggingAiProvider($providerInstance, $httpCapture);
    }

    private function resolveProviderModel(array $definition): AiProviderModel
    {
        $modelId = $definition['ai_provider_model_id'] ?? null;
        $modelSlug = $definition['ai_provider_model_slug'] ?? null;

        if ($modelId === null && $modelSlug === null) {
            throw new InvalidArgumentException('Runtime agent must define ai_provider_model_id or ai_provider_model_slug.');
        }

        $query = AiProviderModel::query()
            ->with('provider')
            ->where('is_active', true)
            ->whereHas('provider', fn ($query) => $query->where('is_active', true));

        $providerModel = $modelId !== null
            ? $query->whereKey($modelId)->first()
            : $query->where('slug', $modelSlug)->first();

        if (! $providerModel instanceof AiProviderModel || ! $providerModel->provider instanceof AiProvider) {
            throw new InvalidArgumentException('Configured AI provider model was not found or is inactive.');
        }

        if ($providerModel->model === '') {
            throw new InvalidArgumentException('Configured AI provider model value is required.');
        }

        return $providerModel;
    }
}
