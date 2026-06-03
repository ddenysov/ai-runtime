<?php

namespace App\Neuron\State;

use App\Models\AgentChatMessage;
use App\Neuron\Agents\ConfigurableRuntimeAgent;
use App\Neuron\RuntimeAgentContext;
use App\Neuron\RuntimeAgentFactory;
use Illuminate\Support\Facades\Log;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

class AgentStateProcessorRunner
{
    public function __construct(
        private readonly RuntimeAgentFactory $agents,
        private readonly AgentStateMutationApplier $mutations,
        private readonly AgentStateSnapshotBuilder $snapshots,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $assignments
     */
    public function run(
        RuntimeAgentContext $context,
        array $assignments,
        string $userMessage,
        string $assistantMessage,
    ): void {
        foreach ($assignments as $assignment) {
            if (($assignment['trigger'] ?? null) !== 'after_response') {
                continue;
            }

            $extractorAgentSlug = $assignment['extractor_agent_slug'] ?? null;

            if (! is_string($extractorAgentSlug) || trim($extractorAgentSlug) === '') {
                continue;
            }

            try {
                $extractor = $this->agents->make($extractorAgentSlug);
                $message = new UserMessage($this->prompt($context, $assignment, $userMessage, $assistantMessage));
                $response = $this->runStructuredExtractor($extractor, $message, $assignment);

                $payload = $this->parseJson($response);
                $result = $this->mutations->apply($context, $assignment, $payload);

                Log::info('Agent state processor completed.', [
                    'agent_slug' => $context->agentSlug,
                    'conversation_id' => $context->conversationId,
                    'processor_slug' => $assignment['processor_slug'] ?? null,
                    'applied' => $result['applied'],
                    'skipped' => $result['skipped'],
                ]);
            } catch (Throwable $exception) {
                Log::warning('Agent state processor failed.', [
                    'agent_slug' => $context->agentSlug,
                    'conversation_id' => $context->conversationId,
                    'processor_slug' => $assignment['processor_slug'] ?? null,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $assignment
     */
    private function prompt(RuntimeAgentContext $context, array $assignment, string $userMessage, string $assistantMessage): string
    {
        $recentHistory = AgentChatMessage::query()
            ->where('thread_id', $context->historyThreadId())
            ->latest('id')
            ->limit(8)
            ->get()
            ->reverse()
            ->map(fn (AgentChatMessage $message): array => [
                'role' => $message->role,
                'content' => $this->messageText($message->content),
            ])
            ->values()
            ->all();

        $payload = [
            'processor' => [
                'slug' => $assignment['processor_slug'] ?? null,
                'instructions' => $assignment['instructions'] ?? '',
                'allowed_entity_types' => $assignment['entity_types'] ?? [],
                'required_response_contract' => [
                    'mutations' => [
                        [
                            'operation' => 'create | upsert | delete',
                            'scope' => 'conversation | global',
                            'entity_type' => 'string',
                            'source_key' => 'stable string required for upsert/delete',
                            'title' => 'string required for create/upsert',
                            'summary' => 'optional string',
                            'content' => 'object required for create/upsert',
                            'group' => 'optional string',
                            'tags' => ['optional', 'strings'],
                            'confidence' => 'number 0..1',
                            'evidence' => 'short quote or rationale',
                        ],
                    ],
                ],
            ],
            'current_state' => $this->snapshots->snapshots($context, [$assignment]),
            'recent_history' => $recentHistory,
            'latest_turn' => [
                'user' => $userMessage,
                'assistant' => $assistantMessage,
            ],
        ];

        return implode("\n", [
            'Extract persistent runtime state changes from the conversation turn.',
            'Return ONLY valid JSON. Do not use markdown. Do not call tools.',
            'If nothing changed, return {"mutations":[]}.',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @param  array<string, mixed>  $assignment
     */
    private function runStructuredExtractor(Agent $extractor, UserMessage $message, array $assignment): string
    {
        if (! $extractor instanceof ConfigurableRuntimeAgent) {
            return $extractor
                ->chat($message)
                ->getMessage()
                ->getContent() ?? '';
        }

        return $extractor
            ->structuredWithSchema(
                messages: $message,
                schemaName: 'AgentStateProcessorResponse',
                responseSchema: $this->responseSchema($assignment),
            )
            ->getContent() ?? '';
    }

    /**
     * @param  array<string, mixed>  $assignment
     * @return array<string, mixed>
     */
    private function responseSchema(array $assignment): array
    {
        $schema = $assignment['response_schema'] ?? null;

        if (is_array($schema) && $schema !== []) {
            return $schema;
        }

        $entityTypes = collect($assignment['entity_types'] ?? [])
            ->filter(fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ->map(fn (string $item): string => trim($item))
            ->values()
            ->all();

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'mutations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'operation' => [
                                'type' => 'string',
                                'enum' => ['create', 'upsert', 'delete'],
                            ],
                            'scope' => [
                                'type' => 'string',
                                'enum' => ['conversation', 'global'],
                            ],
                            'entity_type' => [
                                'type' => 'string',
                                ...($entityTypes !== [] ? ['enum' => $entityTypes] : []),
                            ],
                            'source_key' => [
                                'type' => ['string', 'null'],
                            ],
                            'title' => [
                                'type' => ['string', 'null'],
                            ],
                            'summary' => [
                                'type' => ['string', 'null'],
                            ],
                            'content' => [
                                'type' => 'object',
                                'additionalProperties' => true,
                            ],
                            'group' => [
                                'type' => ['string', 'null'],
                            ],
                            'tags' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'confidence' => [
                                'type' => 'number',
                                'minimum' => 0,
                                'maximum' => 1,
                            ],
                            'evidence' => [
                                'type' => ['string', 'null'],
                            ],
                        ],
                        'required' => ['operation', 'entity_type'],
                    ],
                ],
            ],
            'required' => ['mutations'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJson(string $response): array
    {
        $response = trim($response);

        if (preg_match('/```(?:json)?\s*(.*?)```/is', $response, $matches) === 1) {
            $response = trim($matches[1]);
        }

        $decoded = json_decode($response, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('Processor did not return a JSON object.');
        }

        return $decoded;
    }

    private function messageText(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (! is_array($content)) {
            return '';
        }

        return collect($content)
            ->map(function (mixed $part): ?string {
                if (is_string($part)) {
                    return $part;
                }

                if (is_array($part)) {
                    $text = $part['content'] ?? $part['text'] ?? null;

                    return is_string($text) ? $text : null;
                }

                return null;
            })
            ->filter()
            ->implode("\n");
    }
}
