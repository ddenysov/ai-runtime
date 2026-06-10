<?php

namespace App\Gemini;

use App\Gemini\Data\GeminiCostEstimate;
use App\Gemini\Data\GeminiUsage;
use InvalidArgumentException;

class GeminiCostCalculator
{
    private const TOKENS_PER_UNIT = 1_000_000;

    public function __construct(
        private readonly GeminiCatalog $catalog,
    ) {}

    public function estimate(GeminiUsage $usage): GeminiCostEstimate
    {
        $pricing = $this->catalog->pricing($usage->modelId, $usage->pricingMode);

        if ($pricing === null) {
            throw new InvalidArgumentException(
                "Pricing mode [{$usage->pricingMode}] is not available for model [{$usage->modelId}].",
            );
        }

        $inputUnitPrice = $this->resolveUnitPrice($usage, (array) ($pricing['input'] ?? []));
        $outputUnitPrice = $this->resolveUnitPrice($usage, (array) ($pricing['output'] ?? []));

        $cachedReadUnitPrice = 0.0;
        $cachedStorageUnitPrice = 0.0;

        if (is_array($pricing['context_caching'] ?? null)) {
            $cachedReadUnitPrice = $this->resolveUnitPrice(
                $usage,
                (array) ($pricing['context_caching']['read'] ?? []),
            );
            $cachedStorageUnitPrice = (float) ($pricing['context_caching']['storage']['price_usd'] ?? 0);
        }

        $inputCostUsd = $this->tokenCost($usage->inputTokens, $inputUnitPrice);
        $outputCostUsd = $this->tokenCost($usage->outputTokens, $outputUnitPrice);
        $cachedReadCostUsd = $this->tokenCost($usage->cachedReadTokens, $cachedReadUnitPrice);
        $cachedStorageCostUsd = ($usage->cachedStorageTokenHours / self::TOKENS_PER_UNIT)
            * $cachedStorageUnitPrice;

        return new GeminiCostEstimate(
            modelId: $usage->modelId,
            pricingMode: $usage->pricingMode,
            currency: $this->catalog->currency(),
            inputCostUsd: $inputCostUsd,
            outputCostUsd: $outputCostUsd,
            cachedReadCostUsd: $cachedReadCostUsd,
            cachedStorageCostUsd: $cachedStorageCostUsd,
            totalCostUsd: $inputCostUsd + $outputCostUsd + $cachedReadCostUsd + $cachedStorageCostUsd,
            unitPricesUsd: [
                'input' => $inputUnitPrice,
                'output' => $outputUnitPrice,
                'cached_read' => $cachedReadUnitPrice,
                'cached_storage' => $cachedStorageUnitPrice,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    public function estimateFromTokenPayload(
        string $modelId,
        array $usage,
        string $pricingMode = 'standard',
        string $inputModality = 'text',
        ?int $contextTokens = null,
    ): GeminiCostEstimate {
        return $this->estimate(GeminiUsage::fromTokenPayload(
            modelId: $modelId,
            usage: $usage,
            pricingMode: $pricingMode,
            inputModality: $inputModality,
            contextTokens: $contextTokens,
        ));
    }

    /**
     * @param  array<string, mixed>  $component
     */
    private function resolveUnitPrice(GeminiUsage $usage, array $component): float
    {
        if ($component === []) {
            return 0.0;
        }

        if (isset($component['price_usd'])) {
            return (float) $component['price_usd'];
        }

        $rules = $component['rules'] ?? null;

        if (! is_array($rules)) {
            throw new InvalidArgumentException("Invalid Gemini pricing component for model [{$usage->modelId}].");
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $conditions = $rule['when'] ?? null;

            if (! is_array($conditions) || ! $this->ruleMatches($usage, $conditions)) {
                continue;
            }

            return (float) ($rule['price_usd'] ?? 0);
        }

        throw new InvalidArgumentException(
            "No matching Gemini pricing rule for model [{$usage->modelId}] and usage context.",
        );
    }

    /**
     * @param  array<string, mixed>  $conditions
     */
    private function ruleMatches(GeminiUsage $usage, array $conditions): bool
    {
        foreach ($conditions as $dimension => $expected) {
            $matches = match ($dimension) {
                'modality' => $usage->inputModality === (string) $expected,
                'context_tokens_lte' => $usage->effectiveContextTokens() <= (int) $expected,
                'context_tokens_gt' => $usage->effectiveContextTokens() > (int) $expected,
                default => false,
            };

            if (! $matches) {
                return false;
            }
        }

        return true;
    }

    private function tokenCost(int $tokens, float $unitPriceUsd): float
    {
        if ($tokens <= 0 || $unitPriceUsd <= 0) {
            return 0.0;
        }

        return ($tokens / self::TOKENS_PER_UNIT) * $unitPriceUsd;
    }
}
