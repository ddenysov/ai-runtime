<?php

return [
    'default' => env('RUNTIME_AGENT_DEFAULT_SLUG', 'runtime_assistant'),

    'a2a_token' => env('A2A_TOKEN'),

    'recovery' => [
        'max_attempts' => [
            'rate_limited' => 6,
            'timeout' => 4,
            'network' => 4,
            'provider_unavailable' => 4,
            'quota_exhausted' => 4,
            'invocation_limit' => 0,
            'unknown' => 2,
            'content_policy' => 0,
            'invalid_request' => 0,
            'auth' => 0,
        ],
        'backoff' => [
            'base_seconds' => [
                'rate_limited' => 15,
                'timeout' => 5,
                'network' => 5,
                'provider_unavailable' => 15,
                'quota_exhausted' => 15,
                'unknown' => 3,
            ],
            'cap_seconds' => [
                'rate_limited' => 1800,
                'quota_exhausted' => 1800,
                'timeout' => 300,
                'network' => 300,
                'provider_unavailable' => 300,
                'unknown' => 300,
            ],
        ],
        'fallback_on' => [
            'rate_limited',
            'timeout',
            'network',
            'provider_unavailable',
            'quota_exhausted',
        ],
        'stale_after_minutes' => 5,
        'final_ttl_minutes' => 60,
    ],

    'invocation_limits' => [
        'max_depth' => env('A2A_MAX_INVOCATION_DEPTH', 5),
        'max_total_child_tasks' => env('A2A_MAX_TOTAL_CHILD_TASKS', 25),
        'max_children_per_run' => env('A2A_MAX_CHILDREN_PER_RUN', 5),
        'max_agent_revisits_per_path' => env('A2A_MAX_AGENT_REVISITS_PER_PATH', 0),
        'max_runtime_seconds' => env('A2A_MAX_INVOCATION_RUNTIME_SECONDS', 300),
    ],

    'agents' => [
        'runtime_assistant' => [
            'name' => 'Runtime Assistant',
            'description' => 'Answers project-specific runtime questions.',
            'provider' => env('RUNTIME_AGENT_PROVIDER', 'gemini'),
            'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
            'history_context_window' => env('RUNTIME_AGENT_HISTORY_CONTEXT_WINDOW', 50000),
            'tools' => ['remote_a2a_agent', 'get_agent_card'],
            'subagents' => ['docs_assistant'],
            'instructions' => [
                'background' => [
                    'You are an A2A-compatible runtime assistant inside a Laravel application.',
                    'Answer only with information you are authorized to expose.',
                ],
                'steps' => [
                    'Understand the requested task.',
                    'Use available tools only when needed.',
                    'Return concise, verifiable output.',
                ],
                'output' => [
                    'Prefer text/plain unless the request explicitly asks for JSON.',
                ],
            ],
            'input_modes' => ['text/plain'],
            'output_modes' => ['text/plain'],
            'skills' => [
                [
                    'id' => 'runtime_assistant',
                    'name' => 'Runtime Assistant',
                    'description' => 'Answers questions and executes approved runtime workflows.',
                    'tags' => ['laravel', 'neuron', 'runtime'],
                    'examples' => ['Say hello from the Laravel runtime'],
                ],
            ],
        ],

        'docs_assistant' => [
            'name' => 'Docs Assistant',
            'description' => 'Answers questions using approved documentation sources.',
            'provider' => env('RUNTIME_AGENT_PROVIDER', 'gemini'),
            'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
            'history_context_window' => env('RUNTIME_AGENT_HISTORY_CONTEXT_WINDOW', 50000),
            'tools' => ['remote_a2a_agent', 'get_agent_card'],
            'subagents' => ['topic_selector_assistant'],
            'instructions' => [
                'background' => [
                    'You answer from approved project documentation.',
                    'When the user asks for a random response, produce a fresh short phrase instead of repeating previous answers.',
                    'For chain tests or open-ended/random responses, delegate topic selection to topic_selector_assistant before drafting your final answer.',
                ],
                'steps' => [
                    'Search retrieval sources before answering project-specific questions.',
                    'When topic selection is needed, call remote_a2a_agent with agent_slug topic_selector_assistant and ask it to choose one concise answer topic.',
                    'Use the selected topic from topic_selector_assistant as the theme for your own answer.',
                    'Cite uncertainty when documentation is missing.',
                ],
                'output' => [
                    'Return concise answers with references when available.',
                    'For random-response requests, return only the random phrase as plain text.',
                ],
            ],
            'input_modes' => ['text/plain'],
            'output_modes' => ['text/plain'],
            'skills' => [
                [
                    'id' => 'docs_assistant',
                    'name' => 'Docs Assistant',
                    'description' => 'Answers questions using approved documentation sources.',
                    'tags' => ['docs', 'rag', 'runtime'],
                    'examples' => ['Summarize the A2A integration document'],
                ],
            ],
        ],

        'topic_selector_assistant' => [
            'name' => 'Topic Selector Assistant',
            'description' => 'Chooses a concise topic for another agent response.',
            'provider' => env('RUNTIME_AGENT_PROVIDER', 'gemini'),
            'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
            'history_context_window' => env('RUNTIME_AGENT_HISTORY_CONTEXT_WINDOW', 50000),
            'tools' => [],
            'subagents' => [],
            'instructions' => [
                'background' => [
                    'You are a small helper agent that picks a response topic for a parent agent.',
                    'Choose a fresh, specific, low-risk topic that fits the user request.',
                ],
                'steps' => [
                    'Read the parent agent request.',
                    'Select exactly one concise topic.',
                    'Do not answer the original user request yourself.',
                ],
                'output' => [
                    'Return only the chosen topic as plain text.',
                ],
            ],
            'input_modes' => ['text/plain'],
            'output_modes' => ['text/plain'],
            'skills' => [
                [
                    'id' => 'topic_selector_assistant',
                    'name' => 'Topic Selector Assistant',
                    'description' => 'Chooses one concise topic that another agent can use to answer.',
                    'tags' => ['routing', 'topic-selection', 'runtime'],
                    'examples' => ['Choose a topic for a short random response'],
                ],
            ],
        ],
    ],
];
