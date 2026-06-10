<?php

/**
 * Pricing helpers — produce normalized price components for the catalog schema.
 *
 * @see meta.pricing_schema in the returned config
 */
$flatPrice = static fn (float $priceUsd): array => ['price_usd' => $priceUsd];

$modalityPrice = static fn (array $pricesByModality): array => [
    'rules' => collect($pricesByModality)
        ->map(static fn (float $priceUsd, string $modality): array => [
            'when' => ['modality' => $modality],
            'price_usd' => $priceUsd,
        ])
        ->values()
        ->all(),
];

$contextThresholdPrice = static fn (float $ltePrice, float $gtPrice, int $threshold = 200_000): array => [
    'rules' => [
        ['when' => ['context_tokens_lte' => $threshold], 'price_usd' => $ltePrice],
        ['when' => ['context_tokens_gt' => $threshold], 'price_usd' => $gtPrice],
    ],
];

$cacheStoragePrice = static fn (float $priceUsd, string $unit = 'per_hour_per_1m_tokens'): array => [
    'price_usd' => $priceUsd,
    'unit' => $unit,
];

$contextCaching = static function (
    array|float $read,
    float $storagePerHourPer1mTokens,
) use ($flatPrice, $modalityPrice, $contextThresholdPrice, $cacheStoragePrice): array {
    if (is_float($read)) {
        $readComponent = $flatPrice($read);
    } elseif (isset($read['lte_200k'])) {
        $readComponent = $contextThresholdPrice($read['lte_200k'], $read['gt_200k']);
    } else {
        $readComponent = $modalityPrice($read);
    }

    return [
        'read' => $readComponent,
        'storage' => $cacheStoragePrice($storagePerHourPer1mTokens),
    ];
};

$flashLiteModalityInput = static fn (float $text, float $audio): array => $modalityPrice([
    'text' => $text,
    'image' => $text,
    'video' => $text,
    'audio' => $audio,
]);

return [

    /*
    |--------------------------------------------------------------------------
    | Gemini API reference catalog (paid tier, text models)
    |--------------------------------------------------------------------------
    |
    | Static reference config sourced from Google Gemini API documentation.
    | Structured for catalog lookup, cost estimation, and limit tracking.
    |
    | Sources:
    | - Pricing: https://ai.google.dev/gemini-api/docs/pricing
    | - Rate limits: https://ai.google.dev/gemini-api/docs/rate-limits
    | - Model specs: https://ai.google.dev/gemini-api/docs/models
    |
    | Last synced: 2026-06-09
    |
    */

    'meta' => [
        'billing_tier' => 'paid',
        'currency' => 'USD',
        'pricing_unit' => 'per_1m_tokens',
        'default_pricing_mode' => 'standard',
        'last_updated' => '2026-06-09',
        'sources' => [
            'pricing' => 'https://ai.google.dev/gemini-api/docs/pricing',
            'rate_limits' => 'https://ai.google.dev/gemini-api/docs/rate-limits',
            'models' => 'https://ai.google.dev/gemini-api/docs/models',
        ],
        'pricing_schema' => [
            'version' => 1,
            'components' => ['input', 'output', 'context_caching.read', 'context_caching.storage'],
            'pricing_modes' => ['standard', 'batch', 'flex', 'priority'],
            'modalities' => ['text', 'image', 'video', 'audio'],
            'rule_dimensions' => ['modality', 'context_tokens_lte', 'context_tokens_gt'],
            'flat_price_key' => 'price_usd',
            'rules_key' => 'rules',
        ],
        'rate_limit_metrics' => [
            'rpm' => ['period' => 'minute', 'label' => 'Requests per minute', 'unit' => 'requests'],
            'tpm' => ['period' => 'minute', 'label' => 'Tokens per minute', 'unit' => 'tokens'],
            'rpd' => ['period' => 'day', 'label' => 'Requests per day', 'unit' => 'requests'],
        ],
        'batch_limit_metric' => [
            'key' => 'batch_enqueued_tokens',
            'label' => 'Batch enqueued tokens',
            'unit' => 'tokens',
        ],
        'notes' => [
            'Free-tier pricing and limits are intentionally excluded.',
            'Deprecated and shut-down models are excluded.',
            'RPM/TPM/RPD values are reference defaults for paid usage tiers (tier_1–tier_3). Google does not publish guaranteed interactive limits; verify active quotas in Google AI Studio.',
            'Output prices include thinking tokens unless noted otherwise.',
            'Document (PDF) input tokens are billed at image token rates.',
            'Models with variant_of inherit listed keys from the parent unless overridden.',
            'Grounding references grounding_templates by key.',
        ],
    ],

    'usage_tiers' => [
        'tier_1' => [
            'label' => 'Tier 1',
            'qualification' => 'Active billing account linked to the project.',
            'billing_cap_usd' => 250,
        ],
        'tier_2' => [
            'label' => 'Tier 2',
            'qualification' => '$100 cumulative GCP spend + 3 days from first successful payment.',
            'billing_cap_usd' => 2000,
        ],
        'tier_3' => [
            'label' => 'Tier 3',
            'qualification' => '$1,000 cumulative GCP spend + 30 days from first successful payment.',
            'billing_cap_usd' => '20000-100000+',
        ],
    ],

    'grounding_templates' => [
        'gemini_3' => [
            'google_search' => [
                'free_quota' => ['amount' => 5000, 'period' => 'month', 'pool' => 'gemini_3'],
                'overage_per_1k_queries_usd' => 14.00,
            ],
            'google_maps' => [
                'free_quota' => ['amount' => 5000, 'period' => 'month', 'pool' => 'gemini_3'],
                'overage_per_1k_queries_usd' => 14.00,
            ],
        ],
        'gemini_2_5_pro' => [
            'google_search' => [
                'free_quota' => ['amount' => 1500, 'period' => 'day'],
                'overage_per_1k_grounded_prompts_usd' => 35.00,
            ],
            'google_maps' => [
                'free_quota' => ['amount' => 10000, 'period' => 'day'],
                'overage_per_1k_grounded_prompts_usd' => 25.00,
            ],
        ],
        'gemini_2_5_flash' => [
            'google_search' => [
                'free_quota' => ['amount' => 1500, 'period' => 'day', 'pool' => 'gemini_2_5_flash'],
                'overage_per_1k_grounded_prompts_usd' => 35.00,
            ],
            'google_maps' => [
                'free_quota' => ['amount' => 1500, 'period' => 'day'],
                'overage_per_1k_grounded_prompts_usd' => 25.00,
            ],
        ],
    ],

    'models' => [

        'gemini-3.5-flash' => [
            'id' => 'gemini-3.5-flash',
            'name' => 'Gemini 3.5 Flash',
            'family' => '3.5',
            'status' => 'stable',
            'description' => 'Frontier intelligence optimized for speed, agentic workflows, and coding.',
            'token_limits' => [
                'context_window' => 1_048_576,
                'max_output_tokens' => 65_536,
            ],
            'pricing' => [
                'standard' => [
                    'input' => $flatPrice(1.50),
                    'output' => $flatPrice(9.00),
                    'context_caching' => $contextCaching(0.15, 1.00),
                ],
                'batch' => [
                    'input' => $flatPrice(0.75),
                    'output' => $flatPrice(4.50),
                    'context_caching' => $contextCaching(0.075, 1.00),
                ],
                'flex' => [
                    'input' => $flatPrice(0.75),
                    'output' => $flatPrice(4.50),
                    'context_caching' => $contextCaching(0.08, 1.00),
                ],
                'priority' => [
                    'input' => $flatPrice(2.70),
                    'output' => $flatPrice(16.20),
                    'context_caching' => $contextCaching(0.27, 1.00),
                ],
            ],
            'grounding' => 'gemini_3',
            'rate_limits' => [
                'tier_1' => ['rpm' => 300, 'tpm' => 1_000_000, 'rpd' => 1_500],
                'tier_2' => ['rpm' => 1_000, 'tpm' => 4_000_000, 'rpd' => 10_000],
                'tier_3' => ['rpm' => 4_000, 'tpm' => 4_000_000, 'rpd' => null],
            ],
            'batch_enqueued_tokens' => [
                'tier_1' => 3_000_000,
                'tier_2' => 400_000_000,
                'tier_3' => 1_000_000_000,
            ],
        ],

        'gemini-3.1-flash-lite' => [
            'id' => 'gemini-3.1-flash-lite',
            'name' => 'Gemini 3.1 Flash-Lite',
            'family' => '3.1',
            'status' => 'stable',
            'description' => 'Cost-efficient model for high-volume agentic tasks, translation, and lightweight data processing.',
            'token_limits' => [
                'context_window' => 1_048_576,
                'max_output_tokens' => 65_536,
            ],
            'pricing' => [
                'standard' => [
                    'input' => $flashLiteModalityInput(0.25, 0.50),
                    'output' => $flatPrice(1.50),
                    'context_caching' => $contextCaching(
                        ['text' => 0.025, 'image' => 0.025, 'video' => 0.025, 'audio' => 0.05],
                        1.00,
                    ),
                ],
                'batch' => [
                    'input' => $flashLiteModalityInput(0.125, 0.25),
                    'output' => $flatPrice(0.75),
                    'context_caching' => $contextCaching(
                        ['text' => 0.0125, 'image' => 0.0125, 'video' => 0.0125, 'audio' => 0.025],
                        0.50,
                    ),
                ],
                'flex' => [
                    'input' => $flashLiteModalityInput(0.125, 0.25),
                    'output' => $flatPrice(0.75),
                    'context_caching' => $contextCaching(
                        ['text' => 0.0125, 'image' => 0.0125, 'video' => 0.0125, 'audio' => 0.025],
                        0.50,
                    ),
                ],
                'priority' => [
                    'input' => $flashLiteModalityInput(0.45, 0.90),
                    'output' => $flatPrice(2.70),
                    'context_caching' => $contextCaching(
                        ['text' => 0.045, 'image' => 0.045, 'video' => 0.045, 'audio' => 0.09],
                        1.80,
                    ),
                ],
            ],
            'grounding' => 'gemini_3',
            'rate_limits' => [
                'tier_1' => ['rpm' => 300, 'tpm' => 2_000_000, 'rpd' => 1_500],
                'tier_2' => ['rpm' => 1_000, 'tpm' => 4_000_000, 'rpd' => 10_000],
                'tier_3' => ['rpm' => 4_000, 'tpm' => 4_000_000, 'rpd' => null],
            ],
            'batch_enqueued_tokens' => [
                'tier_1' => 10_000_000,
                'tier_2' => 500_000_000,
                'tier_3' => 1_000_000_000,
            ],
        ],

        'gemini-3.1-pro-preview' => [
            'id' => 'gemini-3.1-pro-preview',
            'name' => 'Gemini 3.1 Pro Preview',
            'family' => '3.1',
            'status' => 'preview',
            'description' => 'Advanced reasoning, agentic workflows, and software engineering tasks.',
            'token_limits' => [
                'context_window' => 1_048_576,
                'max_output_tokens' => 65_536,
                'pricing_context_threshold' => 200_000,
            ],
            'pricing' => [
                'standard' => [
                    'input' => $contextThresholdPrice(2.00, 4.00),
                    'output' => $contextThresholdPrice(12.00, 18.00),
                    'context_caching' => $contextCaching(
                        ['lte_200k' => 0.20, 'gt_200k' => 0.40],
                        4.50,
                    ),
                ],
                'batch' => [
                    'input' => $contextThresholdPrice(1.00, 2.00),
                    'output' => $contextThresholdPrice(6.00, 9.00),
                    'context_caching' => $contextCaching(
                        ['lte_200k' => 0.20, 'gt_200k' => 0.40],
                        4.50,
                    ),
                ],
                'flex' => [
                    'input' => $contextThresholdPrice(1.00, 2.00),
                    'output' => $contextThresholdPrice(6.00, 9.00),
                    'context_caching' => $contextCaching(
                        ['lte_200k' => 0.20, 'gt_200k' => 0.40],
                        4.50,
                    ),
                ],
                'priority' => [
                    'input' => $contextThresholdPrice(3.60, 7.20),
                    'output' => $contextThresholdPrice(21.60, 32.40),
                    'context_caching' => $contextCaching(
                        ['lte_200k' => 0.36, 'gt_200k' => 0.72],
                        8.10,
                    ),
                ],
            ],
            'grounding' => 'gemini_3',
            'rate_limits' => [
                'tier_1' => ['rpm' => 150, 'tpm' => 1_000_000, 'rpd' => 1_000],
                'tier_2' => ['rpm' => 1_000, 'tpm' => 2_000_000, 'rpd' => 10_000],
                'tier_3' => ['rpm' => 4_000, 'tpm' => 4_000_000, 'rpd' => null],
            ],
            'batch_enqueued_tokens' => [
                'tier_1' => 5_000_000,
                'tier_2' => 500_000_000,
                'tier_3' => 1_000_000_000,
            ],
        ],

        'gemini-3.1-pro-preview-customtools' => [
            'id' => 'gemini-3.1-pro-preview-customtools',
            'name' => 'Gemini 3.1 Pro Preview (Custom Tools)',
            'family' => '3.1',
            'status' => 'preview',
            'variant_of' => 'gemini-3.1-pro-preview',
            'inherits' => [
                'token_limits',
                'pricing',
                'grounding',
                'rate_limits',
                'batch_enqueued_tokens',
            ],
            'description' => 'Same pricing and limits as Gemini 3.1 Pro Preview; optimized for custom tool + bash agentic workflows.',
        ],

        'gemini-3-flash-preview' => [
            'id' => 'gemini-3-flash-preview',
            'name' => 'Gemini 3 Flash Preview',
            'family' => '3',
            'status' => 'preview',
            'description' => 'High-speed frontier model with strong agentic and coding capabilities.',
            'token_limits' => [
                'context_window' => 1_048_576,
                'max_output_tokens' => 65_536,
            ],
            'pricing' => [
                'standard' => [
                    'input' => $flashLiteModalityInput(0.50, 1.00),
                    'output' => $flatPrice(3.00),
                    'context_caching' => $contextCaching(
                        ['text' => 0.05, 'image' => 0.05, 'video' => 0.05, 'audio' => 0.10],
                        1.00,
                    ),
                ],
                'batch' => [
                    'input' => $flashLiteModalityInput(0.25, 0.50),
                    'output' => $flatPrice(1.50),
                    'context_caching' => $contextCaching(
                        ['text' => 0.05, 'image' => 0.05, 'video' => 0.05, 'audio' => 0.10],
                        1.00,
                    ),
                ],
                'flex' => [
                    'input' => $flashLiteModalityInput(0.25, 0.50),
                    'output' => $flatPrice(1.50),
                    'context_caching' => $contextCaching(
                        ['text' => 0.05, 'image' => 0.05, 'video' => 0.05, 'audio' => 0.10],
                        1.00,
                    ),
                ],
                'priority' => [
                    'input' => $flashLiteModalityInput(0.90, 1.80),
                    'output' => $flatPrice(5.40),
                    'context_caching' => $contextCaching(
                        ['text' => 0.09, 'image' => 0.09, 'video' => 0.09, 'audio' => 0.18],
                        1.80,
                    ),
                ],
            ],
            'grounding' => 'gemini_3',
            'rate_limits' => [
                'tier_1' => ['rpm' => 300, 'tpm' => 1_000_000, 'rpd' => 1_500],
                'tier_2' => ['rpm' => 1_000, 'tpm' => 4_000_000, 'rpd' => 10_000],
                'tier_3' => ['rpm' => 4_000, 'tpm' => 4_000_000, 'rpd' => null],
            ],
            'batch_enqueued_tokens' => null,
        ],

        'gemini-2.5-pro' => [
            'id' => 'gemini-2.5-pro',
            'name' => 'Gemini 2.5 Pro',
            'family' => '2.5',
            'status' => 'stable',
            'description' => 'Flagship reasoning model for coding, STEM, and long-context analysis.',
            'token_limits' => [
                'context_window' => 1_048_576,
                'max_output_tokens' => 65_536,
                'pricing_context_threshold' => 200_000,
            ],
            'pricing' => [
                'standard' => [
                    'input' => $contextThresholdPrice(1.25, 2.50),
                    'output' => $contextThresholdPrice(10.00, 15.00),
                    'context_caching' => $contextCaching(
                        ['lte_200k' => 0.125, 'gt_200k' => 0.25],
                        4.50,
                    ),
                ],
                'batch' => [
                    'input' => $contextThresholdPrice(0.625, 1.25),
                    'output' => $contextThresholdPrice(5.00, 7.50),
                    'context_caching' => $contextCaching(
                        ['lte_200k' => 0.125, 'gt_200k' => 0.25],
                        4.50,
                    ),
                ],
                'flex' => [
                    'input' => $contextThresholdPrice(0.625, 1.25),
                    'output' => $contextThresholdPrice(5.00, 7.50),
                    'context_caching' => $contextCaching(
                        ['lte_200k' => 0.125, 'gt_200k' => 0.25],
                        4.50,
                    ),
                ],
                'priority' => [
                    'input' => $contextThresholdPrice(2.25, 4.50),
                    'output' => $contextThresholdPrice(18.00, 27.00),
                    'context_caching' => $contextCaching(
                        ['lte_200k' => 0.225, 'gt_200k' => 0.45],
                        8.10,
                    ),
                ],
            ],
            'grounding' => 'gemini_2_5_pro',
            'rate_limits' => [
                'tier_1' => ['rpm' => 150, 'tpm' => 1_000_000, 'rpd' => 1_000],
                'tier_2' => ['rpm' => 1_000, 'tpm' => 2_000_000, 'rpd' => 10_000],
                'tier_3' => ['rpm' => 4_000, 'tpm' => 4_000_000, 'rpd' => null],
            ],
            'batch_enqueued_tokens' => [
                'tier_1' => 5_000_000,
                'tier_2' => 500_000_000,
                'tier_3' => 1_000_000_000,
            ],
        ],

        'gemini-2.5-flash' => [
            'id' => 'gemini-2.5-flash',
            'name' => 'Gemini 2.5 Flash',
            'family' => '2.5',
            'status' => 'stable',
            'description' => 'Best price-performance hybrid reasoning model with 1M context and thinking budgets.',
            'token_limits' => [
                'context_window' => 1_048_576,
                'max_output_tokens' => 65_536,
            ],
            'pricing' => [
                'standard' => [
                    'input' => $flashLiteModalityInput(0.30, 1.00),
                    'output' => $flatPrice(2.50),
                    'context_caching' => $contextCaching(
                        ['text' => 0.03, 'image' => 0.03, 'video' => 0.03, 'audio' => 0.10],
                        1.00,
                    ),
                ],
                'batch' => [
                    'input' => $flashLiteModalityInput(0.15, 0.50),
                    'output' => $flatPrice(1.25),
                    'context_caching' => $contextCaching(
                        ['text' => 0.03, 'image' => 0.03, 'video' => 0.03, 'audio' => 0.10],
                        1.00,
                    ),
                ],
                'flex' => [
                    'input' => $flashLiteModalityInput(0.15, 0.50),
                    'output' => $flatPrice(1.25),
                    'context_caching' => $contextCaching(
                        ['text' => 0.03, 'image' => 0.03, 'video' => 0.03, 'audio' => 0.10],
                        1.00,
                    ),
                ],
                'priority' => [
                    'input' => $flashLiteModalityInput(0.54, 1.80),
                    'output' => $flatPrice(4.50),
                    'context_caching' => $contextCaching(
                        ['text' => 0.054, 'image' => 0.054, 'video' => 0.054, 'audio' => 0.18],
                        1.80,
                    ),
                ],
            ],
            'grounding' => 'gemini_2_5_flash',
            'rate_limits' => [
                'tier_1' => ['rpm' => 300, 'tpm' => 2_000_000, 'rpd' => 1_500],
                'tier_2' => ['rpm' => 1_000, 'tpm' => 4_000_000, 'rpd' => 10_000],
                'tier_3' => ['rpm' => 4_000, 'tpm' => 4_000_000, 'rpd' => null],
            ],
            'batch_enqueued_tokens' => [
                'tier_1' => 3_000_000,
                'tier_2' => 400_000_000,
                'tier_3' => 1_000_000_000,
            ],
        ],

        'gemini-2.5-flash-lite' => [
            'id' => 'gemini-2.5-flash-lite',
            'name' => 'Gemini 2.5 Flash-Lite',
            'family' => '2.5',
            'status' => 'stable',
            'description' => 'Smallest and most cost-effective 2.5 model for high-volume lightweight tasks.',
            'token_limits' => [
                'context_window' => 1_048_576,
                'max_output_tokens' => 65_536,
            ],
            'pricing' => [
                'standard' => [
                    'input' => $flashLiteModalityInput(0.10, 0.30),
                    'output' => $flatPrice(0.40),
                    'context_caching' => $contextCaching(
                        ['text' => 0.01, 'image' => 0.01, 'video' => 0.01, 'audio' => 0.03],
                        1.00,
                    ),
                ],
                'batch' => [
                    'input' => $flashLiteModalityInput(0.05, 0.15),
                    'output' => $flatPrice(0.20),
                    'context_caching' => $contextCaching(
                        ['text' => 0.01, 'image' => 0.01, 'video' => 0.01, 'audio' => 0.03],
                        1.00,
                    ),
                ],
                'flex' => [
                    'input' => $flashLiteModalityInput(0.05, 0.15),
                    'output' => $flatPrice(0.20),
                    'context_caching' => $contextCaching(
                        ['text' => 0.01, 'image' => 0.01, 'video' => 0.01, 'audio' => 0.03],
                        1.00,
                    ),
                ],
                'priority' => [
                    'input' => $flashLiteModalityInput(0.18, 0.54),
                    'output' => $flatPrice(0.72),
                    'context_caching' => $contextCaching(
                        ['text' => 0.018, 'image' => 0.018, 'video' => 0.018, 'audio' => 0.054],
                        1.80,
                    ),
                ],
            ],
            'grounding' => 'gemini_2_5_flash',
            'rate_limits' => [
                'tier_1' => ['rpm' => 300, 'tpm' => 2_000_000, 'rpd' => 1_500],
                'tier_2' => ['rpm' => 1_000, 'tpm' => 4_000_000, 'rpd' => 10_000],
                'tier_3' => ['rpm' => 4_000, 'tpm' => 4_000_000, 'rpd' => null],
            ],
            'batch_enqueued_tokens' => [
                'tier_1' => 10_000_000,
                'tier_2' => 500_000_000,
                'tier_3' => 1_000_000_000,
            ],
        ],

        'gemini-2.5-computer-use-preview-10-2025' => [
            'id' => 'gemini-2.5-computer-use-preview-10-2025',
            'name' => 'Gemini 2.5 Computer Use Preview',
            'family' => '2.5',
            'status' => 'preview',
            'description' => 'Browser control and UI automation model with visual reasoning.',
            'token_limits' => [
                'context_window' => 128_000,
                'max_output_tokens' => 64_000,
                'pricing_context_threshold' => 200_000,
            ],
            'pricing' => [
                'standard' => [
                    'input' => $contextThresholdPrice(1.25, 2.50),
                    'output' => $contextThresholdPrice(10.00, 15.00),
                    'context_caching' => null,
                ],
            ],
            'grounding' => null,
            'rate_limits' => [
                'tier_1' => ['rpm' => 150, 'tpm' => 1_000_000, 'rpd' => 1_000],
                'tier_2' => ['rpm' => 1_000, 'tpm' => 2_000_000, 'rpd' => 10_000],
                'tier_3' => ['rpm' => 4_000, 'tpm' => 4_000_000, 'rpd' => null],
            ],
            'batch_enqueued_tokens' => null,
        ],

    ],

];
