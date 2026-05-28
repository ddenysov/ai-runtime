<?php

namespace Tests\Feature;

use App\A2A\A2AState;
use App\A2A\A2AInvocationLimitExceeded;
use App\A2A\RuntimeAgentTaskRepository;
use App\A2A\SendMessageAction;
use App\A2A\TaskPayloadFactory;
use App\Jobs\ProcessA2ATask;
use App\Jobs\ResumeParentAgentJob;
use App\Models\A2AChildTask;
use App\Models\A2ATask;
use App\Models\AgentChatMessage;
use App\Models\AgentRun;
use App\Models\AgentToolCall;
use App\Neuron\Nodes\RemoteA2AToolNode;
use App\Neuron\Providers\EchoProvider;
use App\Neuron\Providers\RuntimeAiProviderFactory;
use App\Neuron\RuntimeAgentContext;
use App\Neuron\RuntimeAgentFactory;
use App\Neuron\Tools\GetAgentCardTool;
use App\Neuron\Tools\PendingRemoteA2AToolCall;
use App\Neuron\Tools\RemoteA2AAgentTool;
use App\Neuron\Tools\RemoteA2AToolResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Workflow\Events\StartEvent;
use RuntimeException;
use Tests\TestCase;

class A2ARealRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_agent_can_use_fake_ai_provider_without_real_llm(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('Fake runtime response'),
        );

        $this->app->bind(
            RuntimeAiProviderFactory::class,
            fn () => new class($provider) implements RuntimeAiProviderFactory
            {
                public function __construct(private readonly AIProviderInterface $provider) {}

                public function make(array $definition): AIProviderInterface
                {
                    return $this->provider;
                }
            },
        );

        $run = $this->createRun('runtime_assistant');

        $message = app(RuntimeAgentFactory::class)
            ->make('runtime_assistant', new RuntimeAgentContext('runtime_assistant', $run->id))
            ->chat(new UserMessage('hello without a real llm'))
            ->getMessage();

        $this->assertSame('Fake runtime response', $message->getContent());
        $provider->assertCallCount(1);
    }

    public function test_runtime_agents_persist_history_per_run_thread(): void
    {
        config()->set('runtime-agents.agents.runtime_assistant.provider', 'echo');

        $firstRun = $this->createRun('runtime_assistant');
        $secondRun = $this->createRun('runtime_assistant');
        $factory = app(RuntimeAgentFactory::class);

        $factory
            ->make('runtime_assistant', new RuntimeAgentContext('runtime_assistant', $firstRun->id))
            ->chat(new UserMessage('hello from first run'))
            ->getMessage();

        $factory
            ->make('runtime_assistant', new RuntimeAgentContext('runtime_assistant', $secondRun->id))
            ->chat(new UserMessage('hello from second run'))
            ->getMessage();

        $this->assertDatabaseHas('agent_chat_messages', [
            'thread_id' => "runtime_assistant:{$firstRun->id}",
            'role' => 'user',
        ]);
        $this->assertDatabaseHas('agent_chat_messages', [
            'thread_id' => "runtime_assistant:{$secondRun->id}",
            'role' => 'user',
        ]);
        $this->assertSame(2, AgentChatMessage::query()->distinct('thread_id')->count('thread_id'));
    }

    public function test_get_agent_card_tool_is_limited_to_allowed_subagents(): void
    {
        $tool = new GetAgentCardTool(['docs_assistant']);

        $card = json_decode($tool('docs_assistant'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Docs Assistant', $card['name']);

        $this->expectException(InvalidArgumentException::class);

        $tool('runtime_assistant');
    }

    public function test_remote_a2a_tool_creates_child_task_and_interrupts_parent(): void
    {
        Queue::fake();

        $parentRun = $this->createRun('runtime_assistant');
        $tool = new RemoteA2AAgentTool(
            context: new RuntimeAgentContext('runtime_assistant', $parentRun->id),
            allowedSubagents: ['docs_assistant'],
        );
        $tool->setCallId('call_1');

        try {
            $tool('docs_assistant', 'answer from docs');
            $this->fail('Expected remote A2A tool to interrupt the workflow.');
        } catch (PendingRemoteA2AToolCall $pending) {
            $this->assertSame($parentRun->id, $pending->interrupt->agentRunId);
        }

        $toolCall = AgentToolCall::query()->sole();

        $this->assertSame('remote_a2a_agent', $toolCall->tool_name);
        $this->assertSame('waiting', $toolCall->state);
        $this->assertDatabaseHas('a2a_child_tasks', [
            'agent_run_id' => $parentRun->id,
            'tool_call_id' => $toolCall->id,
            'remote_agent_slug' => 'docs_assistant',
            'state' => A2AState::SUBMITTED->value,
        ]);
        $this->assertDatabaseHas('a2a_tasks', [
            'agent_slug' => 'docs_assistant',
            'state' => A2AState::SUBMITTED->value,
        ]);
        $childTask = A2AChildTask::query()->sole();
        $task = A2ATask::query()->sole();
        $this->assertSame(1, $childTask->request_payload['invocation']['depth']);
        $this->assertSame($task->payload['metadata']['invocation'], $childTask->request_payload['invocation']);
        $this->assertSame(['runtime_assistant', 'docs_assistant'], array_column($childTask->request_payload['invocation']['path'], 'agent_slug'));
        Queue::assertPushed(ProcessA2ATask::class);
    }

    public function test_remote_a2a_tool_rejects_agent_call_cycles(): void
    {
        Queue::fake();

        $rootRun = $this->createRun('runtime_assistant');
        $rootTask = app(SendMessageAction::class)->handle(
            agentSlug: 'runtime_assistant',
            message: app(TaskPayloadFactory::class)->userMessage('root'),
            metadata: ['agent_run_id' => $rootRun->id],
        );
        $childTask = app(SendMessageAction::class)->handle(
            agentSlug: 'docs_assistant',
            message: app(TaskPayloadFactory::class)->userMessage('child'),
            metadata: [
                'parent_agent_run_id' => $rootRun->id,
                'invocation' => [
                    ...$rootTask['metadata']['invocation'],
                    'depth' => 1,
                    'path' => [
                        ...$rootTask['metadata']['invocation']['path'],
                        [
                            'agent_slug' => 'docs_assistant',
                            'agent_run_id' => 'docs-run',
                        ],
                    ],
                ],
            ],
        );
        $tool = new RemoteA2AAgentTool(
            context: new RuntimeAgentContext('docs_assistant', $childTask['metadata']['agent_run_id'], $childTask['id']),
            allowedSubagents: ['runtime_assistant'],
        );

        $this->expectException(A2AInvocationLimitExceeded::class);
        $this->expectExceptionMessage('agent_cycle');

        $tool('runtime_assistant', 'call parent again');
    }

    public function test_remote_a2a_tool_rejects_invocation_depth_over_budget(): void
    {
        Queue::fake();
        config()->set('runtime-agents.invocation_limits.max_depth', 1);

        $rootRun = $this->createRun('runtime_assistant');
        $rootTask = app(SendMessageAction::class)->handle(
            agentSlug: 'runtime_assistant',
            message: app(TaskPayloadFactory::class)->userMessage('root'),
            metadata: ['agent_run_id' => $rootRun->id],
        );
        $childTask = app(SendMessageAction::class)->handle(
            agentSlug: 'docs_assistant',
            message: app(TaskPayloadFactory::class)->userMessage('child'),
            metadata: [
                'parent_agent_run_id' => $rootRun->id,
                'invocation' => [
                    ...$rootTask['metadata']['invocation'],
                    'depth' => 1,
                    'path' => [
                        ...$rootTask['metadata']['invocation']['path'],
                        [
                            'agent_slug' => 'docs_assistant',
                            'agent_run_id' => 'docs-run',
                        ],
                    ],
                ],
            ],
        );
        $tool = new RemoteA2AAgentTool(
            context: new RuntimeAgentContext('docs_assistant', $childTask['metadata']['agent_run_id'], $childTask['id']),
            allowedSubagents: ['topic_selector_assistant'],
        );

        $this->expectException(A2AInvocationLimitExceeded::class);
        $this->expectExceptionMessage('max_depth');

        $tool('topic_selector_assistant', 'too deep');
    }

    public function test_remote_a2a_tool_rejects_total_child_tree_over_budget(): void
    {
        Queue::fake();
        config()->set('runtime-agents.invocation_limits.max_total_child_tasks', 1);

        $parentRun = $this->createRun('runtime_assistant');
        $tool = new RemoteA2AAgentTool(
            context: new RuntimeAgentContext('runtime_assistant', $parentRun->id),
            allowedSubagents: ['docs_assistant'],
        );
        $tool->setCallId('call_1');

        try {
            $tool('docs_assistant', 'first child');
        } catch (PendingRemoteA2AToolCall) {
        }

        $this->expectException(A2AInvocationLimitExceeded::class);
        $this->expectExceptionMessage('max_total_child_tasks');

        $tool('docs_assistant', 'second child');
    }

    public function test_remote_a2a_tool_node_converts_resume_payload_to_tool_result(): void
    {
        $tool = new RemoteA2AAgentTool(
            context: new RuntimeAgentContext('runtime_assistant', (string) Str::uuid()),
            allowedSubagents: ['docs_assistant'],
        );
        $reflection = new \ReflectionClass($tool);
        $property = $reflection->getProperty('pendingToolCallId');
        $property->setAccessible(true);
        $property->setValue($tool, 'tool-call-1');

        $inference = new AIInferenceEvent('instructions', [$tool]);
        $event = new ToolCallEvent(new ToolCallMessage(tools: [$tool]), $inference);
        $state = new AgentState;
        $state->getChatHistory()->addMessage($event->toolCallMessage);
        $node = new RemoteA2AToolNode;
        $node->setWorkflowContext(
            $state,
            new StartEvent,
            new RemoteA2AToolResult('tool-call-1', ['artifact' => ['parts' => [['text' => 'done']]]]),
        );

        $generator = $node($event, $state);
        foreach ($generator as $_) {
        }

        $result = $generator->getReturn();

        $this->assertInstanceOf(AIInferenceEvent::class, $result);
        $toolResult = $result->getMessages()[0];

        $this->assertInstanceOf(ToolResultMessage::class, $toolResult);
        $this->assertStringContainsString('done', $toolResult->getTools()[0]->getResult());
        $this->assertCount(1, $state->getChatHistory()->getMessages());
    }

    public function test_a2a_task_retries_transient_provider_errors_before_completion(): void
    {
        Queue::fake();

        $provider = new SequenceProvider([
            new RuntimeException('rate limit exceeded', 429),
            'Recovered response',
        ]);
        $this->bindProvider($provider);

        $task = app(SendMessageAction::class)->handle(
            agentSlug: 'runtime_assistant',
            message: app(TaskPayloadFactory::class)->userMessage('retry me'),
        );
        $job = new ProcessA2ATask($task['id']);

        app()->call([$job, 'handle']);

        $record = A2ATask::query()->findOrFail($task['id']);
        $this->assertSame(A2AState::WORKING, $record->state);
        $this->assertSame(A2AState::WORKING->value, $record->payload['status']['state']);
        $this->assertSame(1, $record->attempts);
        $this->assertSame('rate_limited', $record->last_error_kind);
        $this->assertNotNull($record->next_attempt_at);

        app()->call([new ProcessA2ATask($task['id']), 'handle']);

        $record->refresh();
        $this->assertSame(2, $provider->calls);
        $this->assertSame(A2AState::COMPLETED, $record->state);
        $this->assertSame('Recovered response', $record->payload['artifacts'][0]['parts'][0]['text']);
    }

    public function test_content_policy_error_rejects_task_without_retrying(): void
    {
        Queue::fake();

        $provider = new SequenceProvider([
            new RuntimeException('prohibited content blocked by safety policy', 400),
        ]);
        $this->bindProvider($provider);

        $task = app(SendMessageAction::class)->handle(
            agentSlug: 'runtime_assistant',
            message: app(TaskPayloadFactory::class)->userMessage('blocked'),
        );

        app()->call([new ProcessA2ATask($task['id']), 'handle']);

        $record = A2ATask::query()->findOrFail($task['id']);
        $run = AgentRun::query()->findOrFail($task['metadata']['agent_run_id']);

        $this->assertSame(A2AState::REJECTED, $record->state);
        $this->assertSame(0, $record->attempts);
        $this->assertSame('content_policy', $record->last_error_kind);
        $this->assertSame('failed', $run->state);
    }

    public function test_canceled_task_is_not_recovered_or_processed(): void
    {
        Queue::fake();

        $provider = new SequenceProvider(['should not run']);
        $this->bindProvider($provider);

        $task = app(SendMessageAction::class)->handle(
            agentSlug: 'runtime_assistant',
            message: app(TaskPayloadFactory::class)->userMessage('cancel me'),
        );
        app(RuntimeAgentTaskRepository::class)->updateState($task, A2AState::CANCELED);

        app()->call([new ProcessA2ATask($task['id']), 'handle']);

        $this->assertSame(0, $provider->calls);
        $this->assertSame(A2AState::CANCELED, A2ATask::query()->findOrFail($task['id'])->state);
    }

    public function test_failed_subagent_can_switch_to_configured_fallback(): void
    {
        Queue::fake();
        config()->set('runtime-agents.recovery.max_attempts.rate_limited', 0);
        config()->set('runtime-agents.agents.docs_assistant.fallbacks', ['topic_selector_assistant']);
        config()->set('runtime-agents.agents.docs_assistant.max_fallbacks', 1);

        $this->bindProvider(new SequenceProvider([
            new RuntimeException('rate limit exceeded', 429),
        ]));

        $parentRun = $this->createRun('runtime_assistant');
        $toolCall = AgentToolCall::query()->create([
            'id' => (string) Str::uuid(),
            'agent_run_id' => $parentRun->id,
            'tool_name' => 'remote_a2a_agent',
            'state' => 'waiting',
            'arguments' => [
                'agent_slug' => 'docs_assistant',
                'message' => 'use docs',
            ],
        ]);
        $task = app(SendMessageAction::class)->handle(
            agentSlug: 'docs_assistant',
            message: app(TaskPayloadFactory::class)->userMessage('use docs'),
            metadata: [
                'parent_agent_run_id' => $parentRun->id,
                'parent_tool_call_id' => $toolCall->id,
            ],
        );
        $childTask = A2AChildTask::query()->create([
            'agent_run_id' => $parentRun->id,
            'tool_call_id' => $toolCall->id,
            'remote_agent_slug' => 'docs_assistant',
            'remote_task_id' => $task['id'],
            'remote_context_id' => $task['contextId'],
            'state' => A2AState::SUBMITTED,
            'request_payload' => [
                'message' => 'use docs',
                'a2a_task_id' => $task['id'],
            ],
        ]);

        app()->call([new ProcessA2ATask($task['id']), 'handle']);

        $childTask->refresh();
        $toolCall->refresh();

        $this->assertSame('topic_selector_assistant', $childTask->remote_agent_slug);
        $this->assertSame(A2AState::SUBMITTED, $childTask->state);
        $this->assertSame('waiting', $toolCall->state);
        $this->assertDatabaseHas('a2a_tasks', [
            'id' => $childTask->remote_task_id,
            'agent_slug' => 'topic_selector_assistant',
            'state' => A2AState::SUBMITTED->value,
        ]);
    }

    public function test_recover_stale_dispatches_ready_work_and_parent_resumes(): void
    {
        Queue::fake();

        $task = A2ATask::query()->create([
            'id' => (string) Str::uuid(),
            'context_id' => (string) Str::uuid(),
            'agent_slug' => 'runtime_assistant',
            'state' => A2AState::WORKING,
            'payload' => [
                'id' => 'task-for-recovery',
                'contextId' => 'context-for-recovery',
                'status' => ['state' => A2AState::WORKING->value],
                'metadata' => ['agent_slug' => 'runtime_assistant'],
            ],
            'next_attempt_at' => now()->subSecond(),
        ]);
        $run = AgentRun::query()->create([
            'id' => (string) Str::uuid(),
            'agent_slug' => 'runtime_assistant',
            'state' => 'waiting_for_tool',
            'workflow_resume_token' => 'workflow-token',
            'resumable_at' => now()->subMinutes(10),
        ]);
        AgentToolCall::query()->create([
            'id' => (string) Str::uuid(),
            'agent_run_id' => $run->id,
            'tool_name' => 'remote_a2a_agent',
            'state' => 'failed',
            'error' => 'subagent failed',
        ]);

        Artisan::call('a2a:recover-stale', ['--limit' => 10]);

        Queue::assertPushed(ProcessA2ATask::class, fn (ProcessA2ATask $job): bool => $job->taskId === $task->id);
        Queue::assertPushed(ResumeParentAgentJob::class, fn (ResumeParentAgentJob $job): bool => $job->agentRunId === $run->id);
    }

    public function test_smoke_command_checks_invocation_guard_limits_without_llm(): void
    {
        $exitCode = Artisan::call('a2a:smoke', ['--guard-limits' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Guard rejected agent_cycle: ok', $output);
        $this->assertStringContainsString('Guard rejected max_depth: ok', $output);
        $this->assertStringContainsString('Guard rejected max_children_per_run: ok', $output);
        $this->assertStringContainsString('Guard rejected max_total_child_tasks: ok', $output);
    }

    private function createRun(string $agentSlug): AgentRun
    {
        return AgentRun::query()->create([
            'id' => (string) Str::uuid(),
            'agent_slug' => $agentSlug,
            'state' => 'submitted',
        ]);
    }

    private function bindProvider(AIProviderInterface $provider): void
    {
        $this->app->bind(
            RuntimeAiProviderFactory::class,
            fn () => new class($provider) implements RuntimeAiProviderFactory
            {
                public function __construct(private readonly AIProviderInterface $provider) {}

                public function make(array $definition): AIProviderInterface
                {
                    return $this->provider;
                }
            },
        );
    }
}

class SequenceProvider extends EchoProvider
{
    public int $calls = 0;

    public function __construct(private array $outcomes) {}

    public function chat(Message ...$messages): Message
    {
        $this->calls++;
        $outcome = array_shift($this->outcomes);

        if ($outcome instanceof \Throwable) {
            throw $outcome;
        }

        return new AssistantMessage((string) $outcome);
    }
}
