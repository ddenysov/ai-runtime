<?php

namespace Tests\Unit\Gemini;

use App\Gemini\GeminiCatalog;
use Tests\TestCase;

class GeminiCatalogTest extends TestCase
{
    private GeminiCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalog = new GeminiCatalog;
    }

    public function test_it_lists_catalog_models(): void
    {
        $modelIds = $this->catalog->modelIds();

        $this->assertContains('gemini-2.5-flash', $modelIds);
        $this->assertContains('gemini-3.1-pro-preview-customtools', $modelIds);
        $this->assertTrue($this->catalog->hasModel('gemini-2.5-flash'));
    }

    public function test_it_resolves_model_with_grounding_template(): void
    {
        $model = $this->catalog->resolve('gemini-2.5-flash');

        $this->assertSame('gemini-2.5-flash', $model['id']);
        $this->assertArrayHasKey('google_search', $model['grounding']);
        $this->assertSame(1500, $model['grounding']['google_search']['free_quota']['amount']);
        $this->assertSame('day', $model['grounding']['google_search']['free_quota']['period']);
    }

    public function test_it_inherits_pricing_from_variant_parent(): void
    {
        $variant = $this->catalog->resolve('gemini-3.1-pro-preview-customtools');
        $parent = $this->catalog->resolve('gemini-3.1-pro-preview');

        $this->assertSame('gemini-3.1-pro-preview', $variant['variant_of']);
        $this->assertSame($parent['pricing'], $variant['pricing']);
        $this->assertSame($parent['rate_limits'], $variant['rate_limits']);
    }

    public function test_it_returns_rate_limits_for_model_and_tier(): void
    {
        $limits = $this->catalog->rateLimits('gemini-2.5-flash', 'tier_1');

        $this->assertSame(300, $limits['rpm']);
        $this->assertSame(2_000_000, $limits['tpm']);
        $this->assertSame(1_500, $limits['rpd']);
    }

    public function test_it_returns_pricing_mode_for_model(): void
    {
        $pricing = $this->catalog->pricing('gemini-2.5-flash', 'standard');

        $this->assertIsArray($pricing);
        $this->assertArrayHasKey('input', $pricing);
        $this->assertArrayHasKey('output', $pricing);
        $this->assertSame(2.50, $pricing['output']['price_usd']);
    }

    public function test_it_exposes_catalog_meta(): void
    {
        $this->assertSame('USD', $this->catalog->currency());
        $this->assertSame('standard', $this->catalog->defaultPricingMode());
        $this->assertArrayHasKey('rpm', $this->catalog->rateLimitMetrics());
    }
}
