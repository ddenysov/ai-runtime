<?php

namespace App\Neuron\Tools;

use App\A2A\A2AState;
use App\A2A\RuntimeAgentTaskRepository;
use App\A2A\SendMessageAction;
use App\A2A\TaskPayloadFactory;
use App\Models\A2AChildTask;
use App\Models\AgentToolCall;
use App\Neuron\RuntimeAgentContext;
use Illuminate\Support\Str;
use InvalidArgumentException;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class RemoteA2AAgentTool extends Tool
{
    private ?string $pendingToolCallId = null;

    /**
     * @param  string[]  $allowedSubagents
     */
    public function __construct(
        private readonly RuntimeAgentContext $context,
        private readonly array $allowedSubagents,
    ) {
        parent::__construct(
            name: 'remote_a2a_agent',
            description: 'Delegate work to an allowed A2A subagent. The parent agent will resume after the subagent returns its artifact.',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'agent_slug',
                type: PropertyType::STRING,
                description: 'The slug of the subagent to call.',
                required: true,
                enum: $this->allowedSubagents,
            ),
            ToolProperty::make(
                name: 'message',
                type: PropertyType::STRING,
                description: 'The exact task or question to send to the subagent.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $agent_slug, string $message): string
    {
        if (! in_array($agent_slug, $this->allowedSubagents, true)) {
            throw new InvalidArgumentException("Subagent [{$agent_slug}] is not allowed.");
        }

        $toolCall = AgentToolCall::query()->create([
            'id' => (string) Str::uuid(),
            'agent_run_id' => $this->context->agentRunId,
            'tool_name' => $this->getName(),
            'state' => 'waiting',
            'arguments' => [
                'agent_slug' => $agent_slug,
                'message' => $message,
                'llm_call_id' => $this->getCallId(),
            ],
        ]);

        $task = app(SendMessageAction::class)->handle(
            agentSlug: $agent_slug,
            message: app(TaskPayloadFactory::class)->userMessage($message),
            metadata: [
                'parent_agent_run_id' => $this->context->agentRunId,
                'parent_tool_call_id' => $toolCall->id,
                ...$this->smokeFailureMetadata(),
            ],
        );

        $childTask = A2AChildTask::query()->create([
            'agent_run_id' => $this->context->agentRunId,
            'tool_call_id' => $toolCall->id,
            'remote_agent_slug' => $agent_slug,
            'remote_task_id' => $task['id'],
            'remote_context_id' => $task['contextId'],
            'state' => A2AState::SUBMITTED,
            'request_payload' => [
                'message' => $message,
                'a2a_task_id' => $task['id'],
                'child_agent_run_id' => $task['metadata']['agent_run_id'] ?? null,
            ],
        ]);

        $this->pendingToolCallId = $toolCall->id;

        throw new PendingRemoteA2AToolCall(new RemoteA2AToolInterrupt(
            agentRunId: $this->context->agentRunId,
            toolCallId: $toolCall->id,
            childTaskId: $childTask->id,
            remoteTaskId: $task['id'],
        ));
    }

    public function pendingToolCallId(): ?string
    {
        return $this->pendingToolCallId;
    }

    private function smokeFailureMetadata(): array
    {
        if ($this->context->a2aTaskId === null) {
            return [];
        }

        $parentTask = app(RuntimeAgentTaskRepository::class)->find($this->context->a2aTaskId);
        $metadata = $parentTask['metadata'] ?? [];

        return collect($metadata)
            ->only([
                'smoke',
                'smoke_fail_once_agent_slug',
                'smoke_fail_once_injected',
            ])
            ->all();
    }
}
