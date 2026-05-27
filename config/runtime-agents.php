<?php

return [
    'default' => env('RUNTIME_AGENT_DEFAULT_SLUG', 'runtime_assistant'),

    'a2a_token' => env('A2A_TOKEN'),

    'agents' => [
        'runtime_assistant' => [
            'name' => 'Runtime Assistant',
            'description' => 'Answers project-specific runtime questions.',
            'provider' => env('RUNTIME_AGENT_PROVIDER', 'echo'),
            'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
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
            'provider' => env('RUNTIME_AGENT_PROVIDER', 'echo'),
            'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
            'instructions' => [
                'background' => [
                    'You answer from approved project documentation.',
                ],
                'steps' => [
                    'Search retrieval sources before answering project-specific questions.',
                    'Cite uncertainty when documentation is missing.',
                ],
                'output' => [
                    'Return concise answers with references when available.',
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
    ],
];
