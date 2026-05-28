<?php

namespace App\Neuron\Nodes;

use App\Neuron\Tools\PendingRemoteA2AToolCall;
use App\Neuron\Tools\RemoteA2AAgentTool;
use App\Neuron\Tools\RemoteA2AToolResult;
use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;

class RemoteA2AToolNode extends ToolNode
{
    use ChatHistoryHelper;

    /**
     * @throws WorkflowInterrupt
     */
    public function __invoke(ToolCallEvent $event, AgentState $state): AIInferenceEvent|Generator
    {
        if ($this->isResuming()) {
            return $this->resumeRemoteTool($event, $state);
        }

        $this->addToChatHistory($state, $event->toolCallMessage);

        try {
            $toolCallResult = yield from $this->executeTools($event->toolCallMessage, $state);
        } catch (PendingRemoteA2AToolCall $pending) {
            $this->interrupt($pending->interrupt);
        }

        $event->inferenceEvent->setMessages($toolCallResult);

        return $event->inferenceEvent;
    }

    private function resumeRemoteTool(ToolCallEvent $event, AgentState $state): AIInferenceEvent
    {
        $resume = $this->getResumeRequest();

        if (! $resume instanceof RemoteA2AToolResult) {
            throw new \InvalidArgumentException('Expected a remote A2A tool result resume request.');
        }

        foreach ($event->toolCallMessage->getTools() as $tool) {
            if (
                $tool instanceof RemoteA2AAgentTool
                && $tool->pendingToolCallId() === $resume->toolCallId
            ) {
                $tool->setResult($resume->resultAsText());
            }
        }

        $event->inferenceEvent->setMessages(new ToolResultMessage($event->toolCallMessage->getTools()));

        return $event->inferenceEvent;
    }
}
