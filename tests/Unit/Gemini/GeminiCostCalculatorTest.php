<?php

namespace Tests\Unit\Gemini;

use App\Gemini\Data\GeminiUsage;
use App\Gemini\GeminiCatalog;
use App\Gemini\GeminiCostCalculator;
use Tests\TestCase;

class GeminiCostCalculatorTest extends TestCase
{
    private GeminiCostCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new GeminiCostCalculator(new GeminiCatalog);
    }

    public function test_it_estimates_cost_from_llm_token_payload(): void
    {
        // Typical Neuron / Gemini usage shape from llm.log:
        // usage: { input_tokens: 22884, output_tokens: 290 }
        $estimate = $this->calculator->estimateFromTokenPayload(
            modelId: 'gemini-2.5-flash',
            usage: [
                'input_tokens' => 22_884,
                'output_tokens' => 290,
            ],
        );

        $this->assertSame('gemini-2.5-flash', $estimate->modelId);
        $this->assertSame('standard', $estimate->pricingMode);
        $this->assertSame('USD', $estimate->currency);
        $this->assertEqualsWithDelta(0.0068652, $estimate->inputCostUsd, 0.0000001);
        $this->assertEqualsWithDelta(0.000725, $estimate->outputCostUsd, 0.0000001);
        $this->assertEqualsWithDelta(0.0075902, $estimate->totalCostUsd, 0.0000001);
        $this->assertEqualsWithDelta(0.0075902, $estimate->breakdown()['total'], 0.0000001);
    }

    public function test_it_accepts_prompt_and_completion_token_aliases(): void
    {
        $estimate = $this->calculator->estimateFromTokenPayload(
            modelId: 'gemini-2.5-flash',
            usage: [
                'prompt_tokens' => 1_000,
                'completion_tokens' => 100,
            ],
        );

        $this->assertEqualsWithDelta(0.0003, $estimate->inputCostUsd, 0.0000001);
        $this->assertEqualsWithDelta(0.00025, $estimate->outputCostUsd, 0.0000001);
    }

    public function test_it_applies_context_threshold_pricing_for_pro_models(): void
    {
        // gemini-2.5-pro uses gt_200k prices when context exceeds 200k tokens.
        $estimate = $this->calculator->estimate(
            new GeminiUsage(
                modelId: 'gemini-2.5-pro',
                inputTokens: 250_000,
                outputTokens: 1_000,
                contextTokens: 250_000,
            ),
        );

        $this->assertEqualsWithDelta(2.50, $estimate->unitPricesUsd['input'], 0.0000001);
        $this->assertEqualsWithDelta(15.00, $estimate->unitPricesUsd['output'], 0.0000001);
        $this->assertEqualsWithDelta(0.625, $estimate->inputCostUsd, 0.0001);
        $this->assertEqualsWithDelta(0.015, $estimate->outputCostUsd, 0.0001);
        $this->assertEqualsWithDelta(0.64, $estimate->totalCostUsd, 0.0001);
    }

    public function test_it_supports_batch_pricing_mode(): void
    {
        $standard = $this->calculator->estimate(new GeminiUsage(
            modelId: 'gemini-2.5-flash',
            inputTokens: 1_000_000,
            outputTokens: 1_000_000,
            pricingMode: 'standard',
        ));

        $batch = $this->calculator->estimate(new GeminiUsage(
            modelId: 'gemini-2.5-flash',
            inputTokens: 1_000_000,
            outputTokens: 1_000_000,
            pricingMode: 'batch',
        ));

        $this->assertGreaterThan($batch->totalCostUsd, $standard->totalCostUsd);
        $this->assertEqualsWithDelta(0.15, $batch->unitPricesUsd['input'], 0.0000001);
        $this->assertEqualsWithDelta(1.25, $batch->unitPricesUsd['output'], 0.0000001);
    }

    public function test_it_includes_context_caching_costs_when_provided(): void
    {
        $estimate = $this->calculator->estimate(new GeminiUsage(
            modelId: 'gemini-2.5-flash',
            inputTokens: 0,
            outputTokens: 0,
            cachedReadTokens: 1_000_000,
            cachedStorageTokenHours: 2_000_000,
        ));

        $this->assertEqualsWithDelta(0.03, $estimate->cachedReadCostUsd, 0.0000001);
        $this->assertEqualsWithDelta(2.00, $estimate->cachedStorageCostUsd, 0.0000001);
        $this->assertEqualsWithDelta(2.03, $estimate->totalCostUsd, 0.0000001);
    }
}
