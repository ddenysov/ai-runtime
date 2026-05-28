<?php

namespace App\A2A;

use App\Models\A2AChildTask;
use App\Models\AgentRun;
use Illuminate\Support\Carbon;

class A2AInvocationGuard
{
    public function __construct(
        private readonly RuntimeAgentTaskRepository $tasks,
    ) {}

    public function authorize(
        ?string $parentTaskId,
        string $parentAgentRunId,
        string $parentAgentSlug,
        string $childAgentSlug,
    ): array {
        return $this->authorizeFromInvocation(
            parentInvocation: $this->parentInvocation($parentTaskId, $parentAgentRunId),
            parentTaskId: $parentTaskId,
            parentAgentRunId: $parentAgentRunId,
            parentAgentSlug: $parentAgentSlug,
            childAgentSlug: $childAgentSlug,
        );
    }

    public function authorizeFromInvocation(
        ?array $parentInvocation,
        ?string $parentTaskId,
        string $parentAgentRunId,
        string $parentAgentSlug,
        string $childAgentSlug,
    ): array {
        $invocation = $parentInvocation ?? $this->rootInvocation($parentTaskId, $parentAgentRunId, $parentAgentSlug);
        $deadlineAt = $this->deadlineAt($invocation);

        if ($deadlineAt !== null && $deadlineAt->isPast()) {
            $this->reject('deadline_exceeded', [
                'deadline_at' => $deadlineAt->toISOString(),
                'child_agent_slug' => $childAgentSlug,
            ]);
        }

        $path = $this->path($invocation, $parentAgentRunId, $parentAgentSlug);
        $depth = ((int) ($invocation['depth'] ?? max(0, count($path) - 1))) + 1;
        $maxDepth = $this->limit('max_depth');

        if ($maxDepth > 0 && $depth > $maxDepth) {
            $this->reject('max_depth', [
                'max_depth' => $maxDepth,
                'attempted_depth' => $depth,
                'child_agent_slug' => $childAgentSlug,
            ]);
        }

        $agentVisits = collect($path)
            ->where('agent_slug', $childAgentSlug)
            ->count();
        $maxRevisits = $this->limit('max_agent_revisits_per_path');

        if ($agentVisits > $maxRevisits) {
            $this->reject('agent_cycle', [
                'agent_slug' => $childAgentSlug,
                'path' => $path,
                'max_agent_revisits_per_path' => $maxRevisits,
            ]);
        }

        $maxChildrenPerRun = $this->limit('max_children_per_run');

        if ($maxChildrenPerRun > 0) {
            $childrenForRun = A2AChildTask::query()
                ->where('agent_run_id', $parentAgentRunId)
                ->count();

            if ($childrenForRun + 1 > $maxChildrenPerRun) {
                $this->reject('max_children_per_run', [
                    'agent_run_id' => $parentAgentRunId,
                    'max_children_per_run' => $maxChildrenPerRun,
                    'attempted_children' => $childrenForRun + 1,
                ]);
            }
        }

        $maxTotalChildren = $this->limit('max_total_child_tasks');

        if ($maxTotalChildren > 0) {
            $childrenInTree = $this->countChildrenInTree($invocation);

            if ($childrenInTree + 1 > $maxTotalChildren) {
                $this->reject('max_total_child_tasks', [
                    'root_task_id' => $invocation['root_task_id'] ?? null,
                    'root_agent_run_id' => $invocation['root_agent_run_id'] ?? null,
                    'max_total_child_tasks' => $maxTotalChildren,
                    'attempted_children' => $childrenInTree + 1,
                ]);
            }
        }

        return [
            ...$invocation,
            'depth' => $depth,
            'path' => [
                ...$path,
                [
                    'agent_slug' => $childAgentSlug,
                    'agent_run_id' => null,
                ],
            ],
            'deadline_at' => $deadlineAt?->toISOString(),
        ];
    }

    public function withAgentRun(array $invocation, string $agentRunId): array
    {
        $path = $invocation['path'] ?? [];

        if ($path !== []) {
            $last = array_key_last($path);
            $path[$last]['agent_run_id'] = $agentRunId;
        }

        return [
            ...$invocation,
            'path' => $path,
        ];
    }

    public function forFallback(array $invocation, string $fallbackAgentSlug): array
    {
        $deadlineAt = $this->deadlineAt($invocation);

        if ($deadlineAt !== null && $deadlineAt->isPast()) {
            $this->reject('deadline_exceeded', [
                'deadline_at' => $deadlineAt->toISOString(),
                'fallback_agent_slug' => $fallbackAgentSlug,
            ]);
        }

        $path = $invocation['path'] ?? [];

        if ($path === []) {
            return $invocation;
        }

        $last = array_key_last($path);
        $ancestorPath = array_slice($path, 0, -1);
        $agentVisits = collect($ancestorPath)
            ->where('agent_slug', $fallbackAgentSlug)
            ->count();
        $maxRevisits = $this->limit('max_agent_revisits_per_path');

        if ($agentVisits > $maxRevisits) {
            $this->reject('agent_cycle', [
                'agent_slug' => $fallbackAgentSlug,
                'path' => $path,
                'max_agent_revisits_per_path' => $maxRevisits,
            ]);
        }

        $path[$last]['agent_slug'] = $fallbackAgentSlug;

        return [
            ...$invocation,
            'path' => $path,
        ];
    }

    public function rootInvocation(?string $rootTaskId, string $rootAgentRunId, string $rootAgentSlug): array
    {
        $runtimeSeconds = $this->limit('max_runtime_seconds');

        return [
            'root_task_id' => $rootTaskId,
            'root_agent_run_id' => $rootAgentRunId,
            'depth' => 0,
            'path' => [
                [
                    'agent_slug' => $rootAgentSlug,
                    'agent_run_id' => $rootAgentRunId,
                ],
            ],
            'deadline_at' => $runtimeSeconds > 0 ? now()->addSeconds($runtimeSeconds)->toISOString() : null,
        ];
    }

    private function parentInvocation(?string $parentTaskId, string $parentAgentRunId): ?array
    {
        if ($parentTaskId !== null) {
            $task = $this->tasks->find($parentTaskId);
            $invocation = $task['metadata']['invocation'] ?? null;

            if (is_array($invocation)) {
                return $invocation;
            }
        }

        $run = AgentRun::query()->find($parentAgentRunId);
        $invocation = $run?->input['invocation'] ?? null;

        return is_array($invocation) ? $invocation : null;
    }

    private function countChildrenInTree(array $invocation): int
    {
        return A2AChildTask::query()
            ->get(['request_payload'])
            ->filter(function (A2AChildTask $childTask) use ($invocation): bool {
                $childInvocation = $childTask->request_payload['invocation'] ?? null;

                return is_array($childInvocation)
                    && $this->sameRoot($invocation, $childInvocation);
            })
            ->count();
    }

    private function sameRoot(array $left, array $right): bool
    {
        $leftTaskId = $left['root_task_id'] ?? null;
        $rightTaskId = $right['root_task_id'] ?? null;

        if (is_string($leftTaskId) && $leftTaskId !== '') {
            return $leftTaskId === $rightTaskId;
        }

        return ($left['root_agent_run_id'] ?? null) === ($right['root_agent_run_id'] ?? null);
    }

    private function path(array $invocation, string $parentAgentRunId, string $parentAgentSlug): array
    {
        $path = $invocation['path'] ?? [];
        $path = is_array($path) ? $path : [];

        if ($path === []) {
            return [
                [
                    'agent_slug' => $parentAgentSlug,
                    'agent_run_id' => $parentAgentRunId,
                ],
            ];
        }

        return $path;
    }

    private function deadlineAt(array $invocation): ?Carbon
    {
        $deadlineAt = $invocation['deadline_at'] ?? null;

        return is_string($deadlineAt) && $deadlineAt !== ''
            ? Carbon::parse($deadlineAt)
            : null;
    }

    private function limit(string $key): int
    {
        return max(0, (int) config("runtime-agents.invocation_limits.{$key}", 0));
    }

    private function reject(string $reason, array $details): never
    {
        throw new A2AInvocationLimitExceeded($reason, $details);
    }
}
