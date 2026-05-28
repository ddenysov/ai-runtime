<?php

namespace Tests\Feature;

use App\Jobs\ProcessA2ATask;
use App\Models\AgentChatMessage;
use App\Models\AgentRun;
use App\Models\AgentToolCall;
use App\Neuron\Nodes\RemoteA2AToolNode;
use App\Neuron\Providers\RuntimeAiProviderFactory;
use App\Neuron\RuntimeAgentContext;
use App\Neuron\RuntimeAgentFactory;
use App\Neuron\Tools\GetAgentCardTool;
use App\Neuron\Tools\PendingRemoteA2AToolCall;
use App\Neuron\Tools\RemoteA2AAgentTool;
use App\Neuron\Tools\RemoteA2AToolResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Workflow\Events\StartEvent;
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
            'state' => 'SUBMITTED',
        ]);
        $this->assertDatabaseHas('a2a_tasks', [
            'agent_slug' => 'docs_assistant',
            'state' => 'SUBMITTED',
        ]);
        Queue::assertPushed(ProcessA2ATask::class);
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

    private function createRun(string $agentSlug): AgentRun
    {
        return AgentRun::query()->create([
            'id' => (string) Str::uuid(),
            'agent_slug' => $agentSlug,
            'state' => 'submitted',
        ]);
    }
}
