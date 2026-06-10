<?php

namespace App\Gemini\Data;

readonly class GeminiRateLimitSnapshot
{
    /**
     * @param  array<string, array{
     *     limit: ?int,
     *     used: int,
     *     remaining: ?int,
     *     period: string,
     *     label: string,
     *     unit: string
     * }>  $metrics
     */
    public function __construct(
        public string $modelId,
        public string $tier,
        public array $metrics,
    ) {}
}
