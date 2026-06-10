<?php

namespace App\Gemini\Data;

readonly class GeminiCostEstimate
{
    /**
     * @param  array{
     *     input: float,
     *     output: float,
     *     cached_read: float,
     *     cached_storage: float
     * }  $unitPricesUsd
     */
    public function __construct(
        public string $modelId,
        public string $pricingMode,
        public string $currency,
        public float $inputCostUsd,
        public float $outputCostUsd,
        public float $cachedReadCostUsd,
        public float $cachedStorageCostUsd,
        public float $totalCostUsd,
        public array $unitPricesUsd,
    ) {}

    /**
     * @return array<string, float>
     */
    public function breakdown(): array
    {
        return [
            'input' => $this->inputCostUsd,
            'output' => $this->outputCostUsd,
            'cached_read' => $this->cachedReadCostUsd,
            'cached_storage' => $this->cachedStorageCostUsd,
            'total' => $this->totalCostUsd,
        ];
    }
}
