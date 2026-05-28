<?php

namespace App\A2A\Recovery;

use App\A2A\A2AState;
use App\A2A\A2AInvocationGuard;
use App\A2A\SendMessageAction;
use App\A2A\TaskPayloadFactory;
use App\Models\A2AChildTask;

class A2AFallbackService
{
    public function __construct(
        private readonly SendMessageAction $sendMessage,
        private readonly TaskPayloadFactory $payloads,
        private readonly A2AInvocationGuard $invocations,
    ) {}

    public function switchRemoteChildTask(A2AChildTask $childTask, A2AFailure $failure): bool
    {
        $requestPayload = $childTask->request_payload ?? [];
        $fallbackSlug = $this->nextFallback($childTask->remote_agent_slug, $failure, $requestPayload);

        if ($fallbackSlug === null) {
            return false;
        }

        $message = (string) ($requestPayload['message'] ?? '');
        $invocation = $requestPayload['invocation'] ?? null;
        $invocation = is_array($invocation)
            ? $this->invocations->forFallback($invocation, $fallbackSlug)
            : null;
        $task = $this->sendMessage->handle(
            agentSlug: $fallbackSlug,
            message: $this->payloads->userMessage($message),
            metadata: [
                'parent_agent_run_id' => $childTask->agent_run_id,
                'parent_tool_call_id' => $childTask->tool_call_id,
                'fallback_for_task_id' => $childTask->remote_task_id,
                'fallback_for_agent_slug' => $childTask->remote_agent_slug,
                'fallback_error_kind' => $failure->kind->value,
                ...($invocation === null ? [] : ['invocation' => $invocation]),
            ],
        );

        $this->updateChildTask(
            childTask: $childTask,
            fallbackSlug: $fallbackSlug,
            remoteTaskId: $task['id'],
            remoteContextId: $task['contextId'],
            requestPayload: [
                ...$requestPayload,
                ...($invocation === null ? [] : ['invocation' => $invocation]),
            ],
            failure: $failure,
        );

        return true;
    }

    public function switchLocalChildTask(A2AChildTask $childTask, A2AFailure $failure): bool
    {
        $requestPayload = $childTask->request_payload ?? [];
        $fallbackSlug = $this->nextFallback($childTask->remote_agent_slug, $failure, $requestPayload);

        if ($fallbackSlug === null) {
            return false;
        }

        $this->updateChildTask(
            childTask: $childTask,
            fallbackSlug: $fallbackSlug,
            remoteTaskId: $childTask->remote_task_id,
            remoteContextId: $childTask->remote_context_id,
            requestPayload: $this->requestPayloadWithFallbackInvocation($requestPayload, $fallbackSlug),
            failure: $failure,
        );

        return true;
    }

    private function nextFallback(string $agentSlug, A2AFailure $failure, array $requestPayload): ?string
    {
        $fallbacks = config("runtime-agents.agents.{$agentSlug}.fallbacks", []);

        if (! is_array($fallbacks) || $fallbacks === []) {
            return null;
        }

        $fallbackOn = config("runtime-agents.agents.{$agentSlug}.fallback_on", config('runtime-agents.recovery.fallback_on', []));

        if (is_array($fallbackOn) && $fallbackOn !== [] && ! in_array($failure->kind->value, $fallbackOn, true)) {
            return null;
        }

        $tried = $requestPayload['fallbacks_tried'] ?? [];
        $tried = is_array($tried) ? $tried : [];
        $maxFallbacks = (int) config("runtime-agents.agents.{$agentSlug}.max_fallbacks", count($fallbacks));

        if (count($tried) >= $maxFallbacks) {
            return null;
        }

        foreach ($fallbacks as $fallback) {
            if (is_string($fallback) && $fallback !== '' && ! in_array($fallback, $tried, true)) {
                return $fallback;
            }
        }

        return null;
    }

    private function updateChildTask(
        A2AChildTask $childTask,
        string $fallbackSlug,
        string $remoteTaskId,
        ?string $remoteContextId,
        array $requestPayload,
        A2AFailure $failure,
    ): void {
        $tried = $requestPayload['fallbacks_tried'] ?? [];
        $tried = is_array($tried) ? $tried : [];
        $tried[] = $fallbackSlug;

        $childTask->update([
            'remote_agent_slug' => $fallbackSlug,
            'remote_task_id' => $remoteTaskId,
            'remote_context_id' => $remoteContextId,
            'state' => A2AState::SUBMITTED,
            'attempts' => 0,
            'last_error_kind' => null,
            'last_error_message' => null,
            'next_attempt_at' => null,
            'request_payload' => [
                ...$requestPayload,
                'fallbacks_tried' => $tried,
                'fallback_from_error' => $failure->toArray(),
                'a2a_task_id' => $remoteTaskId,
            ],
            'last_notification' => [
                'kind' => 'statusUpdate',
                'taskId' => $remoteTaskId,
                'contextId' => $remoteContextId,
                'status' => [
                    'state' => A2AState::SUBMITTED->value,
                    'message' => "Retrying with fallback subagent [{$fallbackSlug}].",
                ],
            ],
        ]);
    }

    private function requestPayloadWithFallbackInvocation(array $requestPayload, string $fallbackSlug): array
    {
        $invocation = $requestPayload['invocation'] ?? null;

        if (! is_array($invocation)) {
            return $requestPayload;
        }

        return [
            ...$requestPayload,
            'invocation' => $this->invocations->forFallback($invocation, $fallbackSlug),
        ];
    }
}
