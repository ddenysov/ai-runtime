<?php

namespace App\Gemini;

use App\Gemini\Data\GeminiRateLimitSnapshot;

class GeminiRateLimitEstimator
{
    public function __construct(
        private readonly GeminiCatalog $catalog,
    ) {}

    /**
     * @param  array<string, int>  $used
     */
    public function snapshot(string $modelId, string $tier, array $used = []): GeminiRateLimitSnapshot
    {
        $limits = $this->catalog->rateLimits($modelId, $tier);
        $metrics = [];

        foreach ($this->catalog->rateLimitMetrics() as $metric => $definition) {
            $limit = array_key_exists($metric, $limits) ? $this->nullableInt($limits[$metric]) : null;
            $usedCount = max(0, (int) ($used[$metric] ?? 0));

            $metrics[$metric] = [
                'limit' => $limit,
                'used' => $usedCount,
                'remaining' => $limit === null ? null : max(0, $limit - $usedCount),
                'period' => (string) ($definition['period'] ?? ''),
                'label' => (string) ($definition['label'] ?? $metric),
                'unit' => (string) ($definition['unit'] ?? ''),
            ];
        }

        return new GeminiRateLimitSnapshot(
            modelId: $modelId,
            tier: $tier,
            metrics: $metrics,
        );
    }

    /**
     * @param  array<string, int>  $used
     * @return array{
     *     limit: ?int,
     *     used: int,
     *     remaining: ?int
     * }|null
     */
    public function batchEnqueuedTokens(string $modelId, string $tier, array $used = []): ?array
    {
        $limit = $this->catalog->batchEnqueuedTokenLimit($modelId, $tier);

        if ($limit === null && ($this->catalog->resolve($modelId)['batch_enqueued_tokens'] ?? null) === null) {
            return null;
        }

        $usedCount = max(0, (int) ($used['batch_enqueued_tokens'] ?? 0));

        return [
            'limit' => $limit,
            'used' => $usedCount,
            'remaining' => $limit === null ? null : max(0, $limit - $usedCount),
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return (int) $value;
    }
}
