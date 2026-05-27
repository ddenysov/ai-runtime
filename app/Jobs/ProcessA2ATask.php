<?php

namespace App\Jobs;

use App\A2A\A2AState;
use App\A2A\RuntimeAgentPushNotifier;
use App\A2A\RuntimeAgentTaskRepository;
use App\A2A\TaskPayloadFactory;
use App\Neuron\RuntimeAgentFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

class ProcessA2ATask implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(
        public string $taskId,
    ) {}

    public function handle(
        RuntimeAgentTaskRepository $tasks,
        RuntimeAgentPushNotifier $notifier,
        RuntimeAgentFactory $agents,
        TaskPayloadFactory $payloads,
    ): void {
        $task = $tasks->find($this->taskId);

        if ($task === null || A2AState::isTerminal($task['status']['state'])) {
            return;
        }

        $task = $tasks->updateState($task, A2AState::WORKING);
        $notifier->sendStatusUpdate($task);

        try {
            $input = $this->extractText($task['history'] ?? []);
            $agent = $agents->make($task['metadata']['agent_slug'] ?? null);
            $response = $agent->chat(new UserMessage($input))->getMessage()->getContent() ?? '';

            $agentMessage = $payloads->agentMessage($response);
            $artifact = $payloads->artifact($response);

            $completed = [
                ...$task,
                'status' => [
                    'state' => A2AState::COMPLETED,
                    'message' => $agentMessage,
                ],
                'history' => [
                    ...($task['history'] ?? []),
                    $agentMessage,
                ],
                'artifacts' => [
                    ...($task['artifacts'] ?? []),
                    $artifact,
                ],
            ];

            $tasks->save($completed);
            $notifier->sendArtifactUpdate($completed, $artifact);
            $notifier->sendStatusUpdate($completed);
        } catch (Throwable $exception) {
            $failedMessage = $payloads->agentMessage('Task failed while processing.');
            $failed = $tasks->updateState($task, A2AState::FAILED, $failedMessage);
            $notifier->sendStatusUpdate($failed);

            report($exception);

            throw $exception;
        }
    }

    private function extractText(array $messages): string
    {
        $chunks = [];

        foreach ($messages as $message) {
            foreach (($message['parts'] ?? []) as $part) {
                if (isset($part['text'])) {
                    $chunks[] = $part['text'];
                } elseif (isset($part['data'])) {
                    $chunks[] = json_encode($part['data'], JSON_THROW_ON_ERROR);
                }
            }
        }

        return trim(implode("\n", $chunks));
    }
}
