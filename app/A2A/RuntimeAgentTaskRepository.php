<?php

namespace App\A2A;

use App\Models\A2ATask;

class RuntimeAgentTaskRepository
{
    public function save(array $task): array
    {
        $state = $task['status']['state'] ?? A2AState::SUBMITTED;
        $agentSlug = $task['metadata']['agent_slug'] ?? config('runtime-agents.default');

        A2ATask::query()->updateOrCreate(
            ['id' => $task['id']],
            [
                'context_id' => $task['contextId'],
                'agent_slug' => $agentSlug,
                'state' => $state,
                'payload' => $task,
            ],
        );

        return $task;
    }

    public function find(string $taskId): ?array
    {
        return A2ATask::query()->find($taskId)?->payload;
    }

    public function list(?string $agentSlug = null, int $limit = 50): array
    {
        return A2ATask::query()
            ->when($agentSlug, fn ($query) => $query->where('agent_slug', $agentSlug))
            ->latest()
            ->limit($limit)
            ->get()
            ->pluck('payload')
            ->all();
    }

    public function updateState(array $task, string $state, ?array $message = null): array
    {
        $task['status'] = [
            'state' => $state,
            ...($message === null ? [] : ['message' => $message]),
        ];

        return $this->save($task);
    }
}
