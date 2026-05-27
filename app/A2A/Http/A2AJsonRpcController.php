<?php

namespace App\A2A\Http;

use App\A2A\A2AState;
use App\A2A\RuntimeAgentTaskRepository;
use App\A2A\SendMessageAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class A2AJsonRpcController
{
    public function __invoke(
        Request $request,
        string $agent,
        SendMessageAction $sendMessage,
        RuntimeAgentTaskRepository $tasks,
    ): JsonResponse {
        $payload = $request->all();
        $id = $payload['id'] ?? null;

        if (($payload['jsonrpc'] ?? null) !== '2.0') {
            return $this->error($id, -32600, 'Invalid JSON-RPC request.');
        }

        return match ($payload['method'] ?? null) {
            'SendMessage' => $this->sendMessage($id, $agent, $payload['params'] ?? [], $sendMessage),
            'GetTask' => $this->getTask($id, $payload['params'] ?? [], $tasks),
            'ListTasks' => $this->listTasks($id, $agent, $payload['params'] ?? [], $tasks),
            'CancelTask' => $this->cancelTask($id, $payload['params'] ?? [], $tasks),
            default => $this->error($id, -32601, 'Method not found.'),
        };
    }

    private function sendMessage(mixed $id, string $agent, array $params, SendMessageAction $sendMessage): JsonResponse
    {
        $message = $params['message'] ?? null;

        if (! is_array($message)) {
            return $this->error($id, -32602, 'Expected params.message.');
        }

        $task = $sendMessage->handle(
            agentSlug: $agent,
            message: $message,
            configuration: $params['configuration'] ?? null,
            metadata: ['jsonrpc_id' => $id],
        );

        return $this->result($id, $task);
    }

    private function getTask(mixed $id, array $params, RuntimeAgentTaskRepository $tasks): JsonResponse
    {
        $taskId = $params['id'] ?? $params['taskId'] ?? null;

        if (! is_string($taskId)) {
            return $this->error($id, -32602, 'Expected params.id.');
        }

        $task = $tasks->find($taskId);

        return $task === null
            ? $this->error($id, -32004, 'Task not found.')
            : $this->result($id, $task);
    }

    private function listTasks(mixed $id, string $agent, array $params, RuntimeAgentTaskRepository $tasks): JsonResponse
    {
        return $this->result($id, [
            'tasks' => $tasks->list($agent, (int) ($params['limit'] ?? 50)),
        ]);
    }

    private function cancelTask(mixed $id, array $params, RuntimeAgentTaskRepository $tasks): JsonResponse
    {
        $taskId = $params['id'] ?? $params['taskId'] ?? null;

        if (! is_string($taskId)) {
            return $this->error($id, -32602, 'Expected params.id.');
        }

        $task = $tasks->find($taskId);

        if ($task === null) {
            return $this->error($id, -32004, 'Task not found.');
        }

        if (A2AState::isTerminal($task['status']['state'])) {
            return $this->error($id, -32000, 'Terminal task cannot be canceled.');
        }

        return $this->result($id, $tasks->updateState($task, A2AState::CANCELED));
    }

    private function result(mixed $id, mixed $result): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    }

    private function error(mixed $id, int $code, string $message): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ]);
    }
}
