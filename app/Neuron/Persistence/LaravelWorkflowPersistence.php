<?php

namespace App\Neuron\Persistence;

use App\Models\WorkflowInterruptRecord;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Persistence\PersistenceInterface;

use function base64_decode;
use function base64_encode;
use function serialize;
use function unserialize;

class LaravelWorkflowPersistence implements PersistenceInterface
{
    public function save(string $workflowId, WorkflowInterrupt $interrupt): void
    {
        WorkflowInterruptRecord::query()->updateOrCreate(
            ['workflow_id' => $workflowId],
            ['interrupt' => base64_encode(serialize($interrupt))],
        );
    }

    public function load(string $workflowId): WorkflowInterrupt
    {
        $record = WorkflowInterruptRecord::query()->find($workflowId);

        if ($record === null) {
            throw new WorkflowException("No saved workflow found for ID: {$workflowId}.");
        }

        $serialized = base64_decode((string) $record->interrupt, true);

        if ($serialized === false) {
            $serialized = (string) $record->interrupt;
        }

        $interrupt = unserialize($serialized);

        if (! $interrupt instanceof WorkflowInterrupt) {
            throw new WorkflowException("Saved workflow [{$workflowId}] is invalid.");
        }

        return $interrupt;
    }

    public function delete(string $workflowId): void
    {
        WorkflowInterruptRecord::query()->whereKey($workflowId)->delete();
    }
}
