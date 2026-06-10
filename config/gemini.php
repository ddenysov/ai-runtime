<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Gemini API reference catalog (paid tier, text models)
    |--------------------------------------------------------------------------
    |
    | Static reference config sourced from Google Gemini API documentation.
    | Not wired into runtime yet — verify values before production billing logic.
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
        'notes' => [
            'Free-tier pricing and limits are intentionally excluded.',
            'Deprecated and shut-down models are excluded.',
            'RPM/TPM/RPD values are reference defaults for paid usage tiers (tier_1–tier_3). Google does not publish guaranteed interactive limits; verify active quotas in Google AI Studio.',
            'Output prices include thinking tokens unless noted otherwise.',
            'Document (PDF) input tokens are billed at image token rates.',
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
                    'input' => ['text' => 1.50, 'image' => 1.50, 'video' => 1.50, 'audio' => 1.50],
                    'output' => 9.00,
                    'context_caching' => [
                        'read' => 0.15,
                        'storage_per_hour_per_1m_tokens' => 1.00,
                    ],
                ],
                'batch' => [
                    'input' => 0.75,
                    'output' => 4.50,
                    'context_caching' => [
                        'read' => 0.075,
                        'storage_per_hour_per_1m_tokens' => 1.00,
                    ],
                ],
                'flex' => [
                    'input' => 0.75,
                    'output' => 4.50,
                    'context_caching' => [
                        'read' => 0.08,
                        'storage_per_hour_per_1m_tokens' => 1.00,
                    ],
                ],
                'priority' => [
                    'input' => 2.70,
                    'output' => 16.20,
                    'context_caching' => [
                        'read' => 0.27,
                        'storage_per_hour_per_1m_tokens' => 1.00,
                    ],
                ],
            ],
            'grounding' => [
                'google_search' => [
                    'free_quota' => ['amount' => 5000, 'period' => 'month', 'shared_with' => 'gemini_3'],
                    'overage_per_1k_queries_usd' => 14.00,
                ],
                'google_maps' => [
                    'free_quota' => ['amount' => 5000, 'period' => 'month', 'shared_with' => 'gemini_3'],
                    'overage_per_1k_queries_usd' => 14.00,
                ],
            ],
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
                    'input' => ['text' => 0.25, 'image' => 0.25, 'video' => 0.25, 'audio' => 0.50],
                    'output' => 1.50,
                    'context_caching' => [
                        'read' => ['text' => 0.025, 'image' => 0.025, 'video' => 0.025, 'audio' => 0.05],
                        'storage_per_hour_per_1m_tokens' => 1.00,
                    ],
                ],
                'batch' => [
                    'input' => ['text' => 0.125, 'image' => 0.125, 'video' => 0.125, 'audio' => 0.25],
                    'output' => 0.75,
                    'context_caching' => [
                        'read' => ['text' => 0.0125, 'image' => 0.0125, 'video' => 0.0125, 'audio' => 0.025],
                        'storage_per_hour_per_1m_tokens' => 0.50,
                    ],
                ],
                'flex' => [
                    'input' => ['text' => 0.125, 'image' => 0.125, 'video' => 0.125, 'audio' => 0.25],
                    'output' => 0.75,
                    'context_caching' => [
                        'read' => ['text' => 0.0125, 'image' => 0.0125, 'video' => 0.0125, 'audio' => 0.025],
                        'storage_per_hour_per_1m_tokens' => 0.50,
                    ],
                ],
                'priority' => [
                    'input' => ['text' => 0.45, 'image' => 0.45, 'video' => 0.45, 'audio' => 0.90],
                    'output' => 2.70,
                    'context_caching' => [
                        'read' => ['text' => 0.045, 'image' => 0.045, 'video' => 0.045, 'audio' => 0.09],
                        'storage_per_hour_per_1m_tokens' => 1.80,
                    ],
                ],
            ],
            'grounding' => [
                'google_search' => [
                    'free_quota' => ['amount' => 5000, 'period' => 'month', 'shared_with' => 'gemini_3'],
                    'overage_per_1k_queries_usd' => 14.00,
                ],
                'google_maps' => [
                    'free_quota' => ['amount' => 5000, 'period' => 'month', 'shared_with' => 'gemini_3'],
                    'overage_per_1k_queries_usd' => 14.00,
                ],
            ],
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
                    'input' => ['lte_200k' => 2.00, 'gt_200k' => 4.00],
                    'output' => ['lte_200k' => 12.00, 'gt_200k' => 18.00],
                    'context_caching' => [
                        'read' => ['lte_200k' => 0.20, 'gt_200k' => 0.40],
                        'storage_per_hour_per_1m_tokens' => 4.50,
                    ],
                ],
                'batch' => [
                    'input' => ['lte_200k' => 1.00, 'gt_200k' => 2.00],
                    'output' => ['lte_200k' => 6.00, 'gt_200k' => 9.00],
                    'context_caching' => [
                        'read' => ['lte_200k' => 0.20, 'gt_200k' => 0.40],
                        'storage_per_hour_per_1m_tokens' => 4.50,
                    ],
                ],
                'flex' => [
                    'input' => ['lte_200k' => 1.00, 'gt_200k' => 2.00],
                    'output' => ['lte_200k' => 6.00, 'gt_200k' => 9.00],
                    'context_caching' => [
                        'read' => ['lte_200k' => 0.20, 'gt_200k' => 0.40],
                        'storage_per_hour_per_1m_tokens' => 4.50,
                    ],
                ],
                'priority' => [
                    'input' => ['lte_200k' => 3.60, 'gt_200k' => 7.20],
                    'output' => ['lte_200k' => 21.60, 'gt_200k' => 32.40],
                    'context_caching' => [
                        'read' => ['lte_200k' => 0.36, 'gt_200k' => 0.72],
                        'storage_per_hour_per_1m_tokens' => 8.10,
                    ],
                ],
            ],
            'grounding' => [
                'google_search' => [
                    'free_quota' => ['amount' => 5000, 'period' => 'month', 'shared_with' => 'gemini_3'],
                    'overage_per_1k_queries_usd' => 14.00,
                ],
                'google_maps' => [
                    'free_quota' => ['amount' => 5000, 'period' => 'month', 'shared_with' => 'gemini_3'],
                    'overage_per_1k_queries_usd' => 14.00,
                ],
            ],
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
            'description' => 'Same pricing and limits as Gemini 3.1 Pro Preview; optimized for custom tool + bash agentic workflows.',
            'token_limits' => [
                'context_window' => 1_048_576,
                'max_output_tokens' => 65_536,
                'pricing_context_threshold' => 200_000,
            ],
            'pricing' => [
                'standard' => [
                    'input' => ['lte_200k' => 2.00, 'gt_200k' => 4.00],
                    'output' => ['lte_200k' => 12.00, 'gt_200k' => 18.00],
                    'context_caching' => [
                        'read' => ['lte_200k' => 0.20, 'gt_200k' => 0.40],
                        'storage_per_hour_per_1m_tokens' => 4.50,
                    ],
                ],
                'batch' => [
                    'input' => ['lte_200k' => 1.00, 'gt_200k' => 2.00],
                    'output' => ['lte_200k' => 6.00, 'gt_200k' => 9.00],
                    'context_caching' => [
                        'read' => ['lte_200k' => 0.20, 'gt_200k' => 0.40],
                        'storage_per_hour_per_1m_tokens' => 4.50,
                    ],
                ],
                'flex' => [
                    'input' => ['lte_200k' => 1.00, 'gt_200k' => 2.00],
                    'output' => ['lte_200k' => 6.00, 'gt_200k' => 9.00],
                    'context_caching' => [
                        'read' => ['lte_200k' => 0.20, 'gt_200k' => 0.40],
                        'storage_per_hour_per_1m_tokens' => 4.50,
                    ],
                ],
                'priority' => [
                    'input' => ['lte_200k' => 3.60, 'gt_200k' => 7.20],
                    'output' => ['lte_200k' => 21.60, 'gt_200k' => 32.40],
                    'context_caching' => [
                        'read' => ['lte_200k' => 0.36, 'gt_200k' => 0.72],
                        'storage_per_hour_per_1m_tokens' => 8.10,
                    ],
                ],
            ],
            'grounding' => [
                'google_search' => [
                    'free_quota' => ['amount' => 5000, 'period' => 'month', 'shared_with' => 'gemini_3'],
                    'overage_per_1k_queries_usd' => 14.00,
                ],
                'google_maps' => [
                    'free_quota' => ['amount' => 5000, 'period' => 'month', 'shared_with' => 'gemini_3'],
                    'overage_per_1k_queries_usd' => 14.00,
                ],
            ],
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
                    'input' => ['text' => 0.50, 'image' => 0.50, 'video' => 0.50, 'audio' => 1.00],
                    'output' => 3.00,
                    'context_caching' => [
                        'read' => ['text' => 0.05, 'image' => 0.05, 'video' => 0.05, 'audio' => 0.10],
                        'storage_per_hour_per_1m_tokens' => 1.00,
                    ],
                ],
                'batch' => [
                    'input' => ['text' => 0.25, 'image' => 0.25, 'video' => 0.25, 'audio' => 0.50],
                    'output' => 1.50,
                    'context_caching' => [
                        'read' => ['text' => 0.05, 'image' => 0.05, 'video' => 0.05, 'audio' => 0.10],
                        'storage_per_hour_per_1m_tokens' => 1.00,
                    ],
                ],
                'flex' => [
                    'input' => ['text' => 0.25, 'image' => 0.25, 'video' => 0.25, 'audio' => 0.50],
                    'output' => 1.50,
                    'context_caching' => [
                        'read' => ['text' => 0.05, 'image' => 0.05, 'video' => 0.05, 'audio' => 0.10],
                        'storage_per_hour_per_1m_tokens' => 1.00,
                    ],
                ],
                'priority' => [
                    'input' => ['text' => 0.90, 'image' => 0.90, 'video' => 0.90, 'audio' => 1.80],
                    'output' => 5.40,
                    'context_caching' => [
                        'read' => ['text' => 0.09, 'image' => 0.09, 'video' => 0.09, 'audio' => 0.18],
                        'storage_per_hour_per_1m_tokens' => 1.80,
                    ],
                ],
            ],
            'grounding' => [
                'google_search' => [
                    'free_quota' => ['amount' => 5000, 'period' => 'month', 'shared_with' => 'gemini_3'],
                    'overage_per_1k_queries_usd' => 14.00,
                ],
                'google_maps' => [
                    'free_quota' => ['amount' => 5000, 'period' => 'month', 'shared_with' => 'gemini_3'],
                    'overage_per_1k_queries_usd' => 14.00,
                ],
            ],
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
                    'input' => ['lte_200k' => 1.25, 'gt_200k' => 2.50],
                    'output' => ['lte_200k' => 10.00, 'gt_200k' => 15.00],
                    'context_caching' => [
                        'read' => ['lte_200k' => 0.125, 'gt_200k' => 0.25],
                        'storage_per_hour_per_1m_tokens' => 4.50,
                    ],
                ],
                'batch' => [
                    'input' => ['lte_200k' => 0.625, 'gt_200k' => 1.25],
                    'output' => ['lte_200k' => 5.00, 'gt_200k' => 7.50],
                    'context_caching' => [
                        'read' => ['lte_200k' => 0.125, 'gt_200k' => 0.25],
                        'storage_per_hour_per_1m_tokens' => 4.50,
                    ],
                ],
                'flex' => [
                    'input' => ['lte_200k' => 0.625, 'gt_200k' => 1.25],
                    'output' => ['lte_200k' => 5.00, 'gt_200k' => 7.50],
                    'context_caching' => [
                        'read' => ['lte_200k' => 0.125, 'gt_200k' => 0.25],
                        'storage_per_hour_per_1m_tokens' => 4.50,
                    ],
                ],
                'priority' => [
                    'input' => ['lte_200k' => 2.25, 'gt_200k' => 4.50],
                    'output' => ['lte_200k' => 18.00, 'gt_200k' => 27.00],
                    'context_caching' => [
                        'read' => ['lte_200k' => 0.225, 'gt_200k' => 0.45],
                        'storage_per_hour_per_1m_tokens' => 8.10,
                    ],
                ],
            ],
            'grounding' => [
                'google_search' => [
                    'free_quota' => ['amount' => 1500, 'period' => 'day'],
                    'overage_per_1k_grounded_prompts_usd' => 35.00,
                ],
                'google_maps' => [
                    'free_quota' => ['amount' => 10000, 'period' => 'day'],
                    'overage_per_1k_grounded_prompts_usd' => 25.00,
                ],
            ],
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
                    'input' => ['text' => 0.30, 'image' => 0.30, 'video' => 0.30, 'audio' => 1.00],
                    'output' => 2.50,
                    'context_caching' => [
                        'read' => ['text' => 0.03, 'image' => 0.03, 'video' => 0.03, 'audio' => 0.10],
                        'storage_per_hour_per_1m_tokens' => 1.00,
                    ],
                ],
                'batch' => [
                    'input' => ['text' => 0.15, 'image' => 0.15, 'video' => 0.15, 'audio' => 0.50],
                    'output' => 1.25,
                    'context_caching' => [
                        'read' => ['text' => 0.03, 'image' => 0.03, 'video' => 0.03, 'audio' => 0.10],
                        'storage_per_hour_per_1m_tokens' => 1.00,
                    ],
                ],
                'flex' => [
                    'input' => ['text' => 0.15, 'image' => 0.15, 'video' => 0.15, 'audio' => 0.50],
                    'output' => 1.25,
                    'context_caching' => [
                        'read' => ['text' => 0.03, 'image' => 0.03, 'video' => 0.03, 'audio' => 0.10],
                        'storage_per_hour_per_1m_tokens' => 1.00,
                    ],
                ],
                'priority' => [
                    'input' => ['text' => 0.54, 'image' => 0.54, 'video' => 0.54, 'audio' => 1.80],
                    'output' => 4.50,
                    'context_caching' => [
                        'read' => ['text' => 0.054, 'image' => 0.054, 'video' => 0.054, 'audio' => 0.18],
                        'storage_per_hour_per_1m_tokens' => 1.80,
                    ],
                ],
            ],
            'grounding' => [
                'google_search' => [
                    'free_quota' => ['amount' => 1500, 'period' => 'day', 'shared_with' => 'gemini_2.5_flash_lite'],
                    'overage_per_1k_grounded_prompts_usd' => 35.00,
                ],
                'google_maps' => [
                    'free_quota' => ['amount' => 1500, 'period' => 'day'],
                    'overage_per_1k_grounded_prompts_usd' => 25.00,
                ],
            ],
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
                    'input' => ['text' => 0.10, 'image' => 0.10, 'video' => 0.10, 'audio' => 0.30],
                    'output' => 0.40,
                    'context_caching' => [
                        'read' => ['text' => 0.01, 'image' => 0.01, 'video' => 0.01, 'audio' => 0.03],
                        'storage_per_hour_per_1m_tokens' => 1.00,
                    ],
                ],
                'batch' => [
                    'input' => ['text' => 0.05, 'image' => 0.05, 'video' => 0.05, 'audio' => 0.15],
                    'output' => 0.20,
                    'context_caching' => [
                        'read' => ['text' => 0.01, 'image' => 0.01, 'video' => 0.01, 'audio' => 0.03],
                        'storage_per_hour_per_1m_tokens' => 1.00,
                    ],
                ],
                'flex' => [
                    'input' => ['text' => 0.05, 'image' => 0.05, 'video' => 0.05, 'audio' => 0.15],
                    'output' => 0.20,
                    'context_caching' => [
                        'read' => ['text' => 0.01, 'image' => 0.01, 'video' => 0.01, 'audio' => 0.03],
                        'storage_per_hour_per_1m_tokens' => 1.00,
                    ],
                ],
                'priority' => [
                    'input' => ['text' => 0.18, 'image' => 0.18, 'video' => 0.18, 'audio' => 0.54],
                    'output' => 0.72,
                    'context_caching' => [
                        'read' => ['text' => 0.018, 'image' => 0.018, 'video' => 0.018, 'audio' => 0.054],
                        'storage_per_hour_per_1m_tokens' => 1.80,
                    ],
                ],
            ],
            'grounding' => [
                'google_search' => [
                    'free_quota' => ['amount' => 1500, 'period' => 'day', 'shared_with' => 'gemini_2.5_flash'],
                    'overage_per_1k_grounded_prompts_usd' => 35.00,
                ],
                'google_maps' => [
                    'free_quota' => ['amount' => 1500, 'period' => 'day'],
                    'overage_per_1k_grounded_prompts_usd' => 25.00,
                ],
            ],
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
                    'input' => ['lte_200k' => 1.25, 'gt_200k' => 2.50],
                    'output' => ['lte_200k' => 10.00, 'gt_200k' => 15.00],
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
