<?php

namespace App\Gemini;

use InvalidArgumentException;

class GeminiCatalog
{
    /** @var array<string, array<string, mixed>> */
    private array $resolvedModels = [];

    /**
     * @return list<string>
     */
    public function modelIds(): array
    {
        return array_keys((array) config('gemini.models', []));
    }

    public function hasModel(string $modelId): bool
    {
        return is_array($this->rawModels()[$modelId] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return (array) config('gemini.meta', []);
    }

    public function currency(): string
    {
        return (string) ($this->meta()['currency'] ?? 'USD');
    }

    public function defaultPricingMode(): string
    {
        return (string) ($this->meta()['default_pricing_mode'] ?? 'standard');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function rateLimitMetrics(): array
    {
        return (array) ($this->meta()['rate_limit_metrics'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function usageTier(string $tier): array
    {
        $definition = config('gemini.usage_tiers')[$tier] ?? null;

        if (! is_array($definition)) {
            throw new InvalidArgumentException("Unknown Gemini usage tier [{$tier}].");
        }

        return $definition;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $modelId): array
    {
        if (isset($this->resolvedModels[$modelId])) {
            return $this->resolvedModels[$modelId];
        }

        $this->resolvedModels[$modelId] = $this->resolveModel($modelId);

        return $this->resolvedModels[$modelId];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function models(): array
    {
        $models = [];

        foreach ($this->modelIds() as $modelId) {
            $models[$modelId] = $this->resolve($modelId);
        }

        return $models;
    }

    /**
     * @return array<string, int|null>
     */
    public function rateLimits(string $modelId, string $tier): array
    {
        $model = $this->resolve($modelId);
        $limits = $model['rate_limits'][$tier] ?? null;

        if (! is_array($limits)) {
            throw new InvalidArgumentException("Unknown rate limits for model [{$modelId}] tier [{$tier}].");
        }

        return $limits;
    }

    public function batchEnqueuedTokenLimit(string $modelId, string $tier): ?int
    {
        $model = $this->resolve($modelId);
        $limits = $model['batch_enqueued_tokens'] ?? null;

        if ($limits === null) {
            return null;
        }

        if (! is_array($limits)) {
            throw new InvalidArgumentException("Invalid batch enqueued token limits for model [{$modelId}].");
        }

        $limit = $limits[$tier] ?? null;

        return $limit === null ? null : (int) $limit;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function grounding(string $modelId): ?array
    {
        $grounding = $this->resolve($modelId)['grounding'] ?? null;

        return is_array($grounding) ? $grounding : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pricing(string $modelId, ?string $pricingMode = null): ?array
    {
        $mode = $pricingMode ?? $this->defaultPricingMode();
        $pricing = $this->resolve($modelId)['pricing'][$mode] ?? null;

        return is_array($pricing) ? $pricing : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveModel(string $modelId, array $stack = []): array
    {
        if (in_array($modelId, $stack, true)) {
            throw new InvalidArgumentException("Circular Gemini model inheritance detected for [{$modelId}].");
        }

        $model = $this->rawModels()[$modelId] ?? null;

        if (! is_array($model)) {
            throw new InvalidArgumentException("Unknown Gemini model [{$modelId}].");
        }

        if (isset($model['variant_of']) && is_string($model['variant_of'])) {
            $parent = $this->resolveModel($model['variant_of'], [...$stack, $modelId]);

            foreach ((array) ($model['inherits'] ?? []) as $key) {
                if (! array_key_exists($key, $model) && array_key_exists($key, $parent)) {
                    $model[$key] = $parent[$key];
                }
            }
        }

        if (isset($model['grounding']) && is_string($model['grounding'])) {
            $template = config('gemini.grounding_templates')[$model['grounding']] ?? null;

            if (! is_array($template)) {
                throw new InvalidArgumentException("Unknown Gemini grounding template [{$model['grounding']}].");
            }

            $model['grounding'] = $template;
        }

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function rawModels(): array
    {
        return (array) config('gemini.models', []);
    }
}
