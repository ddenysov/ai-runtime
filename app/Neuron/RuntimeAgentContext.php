<?php

namespace App\Neuron;

class RuntimeAgentContext
{
    public function __construct(
        public readonly string $agentSlug,
        public readonly string $agentRunId,
        public readonly ?string $a2aTaskId = null,
        public readonly ?string $resumeToken = null,
        public readonly ?string $conversationId = null,
    ) {}

    public function historyThreadId(): string
    {
        return "{$this->agentSlug}:".($this->conversationId ?? $this->agentRunId);
    }
}
