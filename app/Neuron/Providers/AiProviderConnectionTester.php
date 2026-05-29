<?php

namespace App\Neuron\Providers;

use App\Enums\AiProviderType;
use App\Models\AiProvider;
use App\Models\AiProviderModel;
use InvalidArgumentException;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Gemini\Gemini;

class AiProviderConnectionTester
{
    public function assertProviderValid(AiProvider $provider, string $model): void
    {
        $provider->assertSupportedCredentials();

        match ($provider->type) {
            AiProviderType::GEMINI => $this->assertGeminiValid($provider, $model),
        };
    }

    public function assertModelValid(AiProviderModel $providerModel): void
    {
        $providerModel->loadMissing('provider');

        if (! $providerModel->provider instanceof AiProvider) {
            throw new InvalidArgumentException('AI provider model must belong to a provider.');
        }

        $this->assertProviderValid($providerModel->provider, $providerModel->model);
    }

    private function assertGeminiValid(AiProvider $provider, string $model): void
    {
        if ($model === '') {
            throw new InvalidArgumentException('Gemini model is required to validate provider credentials.');
        }

        (new Gemini(
            key: (string) $provider->credential('key'),
            model: $model,
        ))->chat(new UserMessage('Reply exactly with: OK'));
    }
}
