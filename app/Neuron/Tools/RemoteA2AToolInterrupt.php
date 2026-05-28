<?php

namespace App\Neuron\Tools;

use NeuronAI\Workflow\Interrupt\InterruptRequest;

class RemoteA2AToolInterrupt extends InterruptRequest
{
    public function __construct(
        public readonly string $agentRunId,
        public readonly string $toolCallId,
        public readonly int $childTaskId,
        public readonly string $remoteTaskId,
    ) {
        parent::__construct("Waiting for remote A2A task [{$remoteTaskId}].");
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'remote_a2a_tool_interrupt',
            'message' => $this->getMessage(),
            'agent_run_id' => $this->agentRunId,
            'tool_call_id' => $this->toolCallId,
            'child_task_id' => $this->childTaskId,
            'remote_task_id' => $this->remoteTaskId,
        ];
    }
}
