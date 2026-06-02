<?php

namespace App\Neuron\Tools;

use NeuronAI\Workflow\Interrupt\InterruptRequest;

class RemoteA2AToolResult extends InterruptRequest
{
    public function __construct(
        public readonly string $toolCallId,
        public readonly array $result,
        public readonly ?string $error = null,
    ) {
        parent::__construct("Remote A2A tool [{$toolCallId}] completed.");
    }

    public function resultAsText(): string
    {
        return json_encode([
            'result' => $this->result,
            'error' => $this->error,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'remote_a2a_tool_result',
            'message' => $this->getMessage(),
            'tool_call_id' => $this->toolCallId,
            'result' => $this->result,
            'error' => $this->error,
        ];
    }
}
