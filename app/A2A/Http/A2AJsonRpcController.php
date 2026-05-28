<?php

namespace App\A2A\Http;

use App\A2A\A2AState;
use App\A2A\RuntimeAgentTaskRepository;
use App\A2A\SendMessageAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Обрабатывает HTTP POST на /api/a2a/{agent} по протоколу A2A (JSON-RPC 2.0).
 *
 * Клиент шлёт JSON вида:
 *   { "jsonrpc": "2.0", "id": 1, "method": "SendMessage", "params": { ... } }
 * В ответ приходит тот же id и либо result (успех), либо error (ошибка).
 *
 * {agent} в URL — slug агента (какой runtime-агент должен выполнить работу).
 * Поле method в теле — какое действие нужно: SendMessage, GetTask, ListTasks, CancelTask.
 */
class A2AJsonRpcController
{
    /**
     * Единственный публичный метод контроллера — его вызывает Laravel на каждый POST.
     *
     * 1. Читает тело запроса ($payload).
     * 2. Проверяет, что это JSON-RPC 2.0 (поле jsonrpc === "2.0").
     * 3. Смотрит на method и вызывает соответствующий приватный метод ниже.
     * 4. Возвращает JSON-ответ клиенту.
     */
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

    /**
     * method: SendMessage — «отправить сообщение агенту и начать работу».
     *
     * Ожидает params.message (текст/части сообщения в формате A2A).
     * Опционально: params.configuration (например, push-уведомления о смене статуса).
     *
     * Что происходит:
     * - создаётся запись «задача» (task) со статусом submitted;
     * - задача сохраняется в БД;
     * - в очередь ставится джоб ProcessA2ATask — агент обработает её асинхронно.
     *
     * В result сразу возвращается объект задачи (можно потом опрашивать GetTask).
     */
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

    /**
     * method: GetTask — «что сейчас с этой задачей?».
     *
     * Ожидает params.id или params.taskId (uuid задачи, который вернул SendMessage).
     * Читает задачу из БД и отдаёт полный payload: статус (working/completed/…),
     * историю сообщений, metadata и т.д.
     *
     * Удобно для polling: клиент периодически дергает GetTask, пока status не станет финальным.
     */
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

    /**
     * method: ListTasks — «покажи недавние задачи этого агента».
     *
     * Агент берётся из URL ({agent}), не из params.
     * params.limit — сколько записей вернуть (по умолчанию 50), сортировка от новых к старым.
     *
     * В result: { "tasks": [ ...массив объектов задач... ] }.
     */
    private function listTasks(mixed $id, string $agent, array $params, RuntimeAgentTaskRepository $tasks): JsonResponse
    {
        return $this->result($id, [
            'tasks' => $tasks->list($agent, (int) ($params['limit'] ?? 50)),
        ]);
    }

    /**
     * method: CancelTask — «останови задачу, если ещё можно».
     *
     * Ожидает params.id или params.taskId.
     * Если задачи нет — ошибка «Task not found».
     * Если уже завершена, упала или отменена — ошибка (повторно отменить нельзя).
     * Иначе статус меняется на canceled и обновлённая задача возвращается в result.
     */
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

    /**
     * Собирает успешный ответ JSON-RPC: jsonrpc, id (тот же, что прислал клиент), result (данные).
     */
    private function result(mixed $id, mixed $result): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    }

    /**
     * Собирает ответ с ошибкой: jsonrpc, id, error { code, message }.
     * Коды: -32600 неверный запрос, -32601 неизвестный method, -32602 неверные params,
     * -32004 задача не найдена, -32000 бизнес-ошибка (например, отмена завершённой задачи).
     */
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
