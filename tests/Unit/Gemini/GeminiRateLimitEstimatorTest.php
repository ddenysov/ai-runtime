<?php

namespace Tests\Unit\Gemini;

use App\Gemini\GeminiCatalog;
use App\Gemini\GeminiRateLimitEstimator;
use Tests\TestCase;

class GeminiRateLimitEstimatorTest extends TestCase
{
    private GeminiRateLimitEstimator $estimator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->estimator = new GeminiRateLimitEstimator(new GeminiCatalog);
    }

    public function test_it_builds_rate_limit_snapshot_from_used_counters(): void
    {
        // Imagine you tracked usage for the current minute/day in Redis or DB.
        $snapshot = $this->estimator->snapshot(
            modelId: 'gemini-2.5-flash',
            tier: 'tier_1',
            used: [
                'rpm' => 10,
                'tpm' => 50_000,
                'rpd' => 100,
            ],
        );

        $this->assertSame('gemini-2.5-flash', $snapshot->modelId);
        $this->assertSame('tier_1', $snapshot->tier);
        $this->assertSame(300, $snapshot->metrics['rpm']['limit']);
        $this->assertSame(10, $snapshot->metrics['rpm']['used']);
        $this->assertSame(290, $snapshot->metrics['rpm']['remaining']);
        $this->assertSame(2_000_000, $snapshot->metrics['tpm']['limit']);
        $this->assertSame(1_950_000, $snapshot->metrics['tpm']['remaining']);
        $this->assertSame('minute', $snapshot->metrics['tpm']['period']);
    }

    public function test_it_returns_null_remaining_for_unlimited_tier_three_rpd(): void
    {
        $snapshot = $this->estimator->snapshot(
            modelId: 'gemini-2.5-flash',
            tier: 'tier_3',
            used: ['rpd' => 1_000],
        );

        $this->assertNull($snapshot->metrics['rpd']['limit']);
        $this->assertNull($snapshot->metrics['rpd']['remaining']);
        $this->assertSame(1_000, $snapshot->metrics['rpd']['used']);
    }

    public function test_it_estimates_batch_enqueued_token_remaining(): void
    {
        $batch = $this->estimator->batchEnqueuedTokens(
            modelId: 'gemini-2.5-flash',
            tier: 'tier_1',
            used: ['batch_enqueued_tokens' => 500_000],
        );

        $this->assertNotNull($batch);
        $this->assertSame(3_000_000, $batch['limit']);
        $this->assertSame(500_000, $batch['used']);
        $this->assertSame(2_500_000, $batch['remaining']);
    }

    public function test_it_returns_null_batch_limits_for_models_without_batch_support(): void
    {
        $batch = $this->estimator->batchEnqueuedTokens(
            modelId: 'gemini-3-flash-preview',
            tier: 'tier_1',
        );

        $this->assertNull($batch);
    }
}
