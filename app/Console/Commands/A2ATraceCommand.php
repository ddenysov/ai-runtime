<?php

namespace App\Console\Commands;

use App\Models\A2AChildTask;
use App\Models\A2ATask;
use App\Models\AgentChatMessage;
use App\Models\AgentRun;
use App\Models\AgentToolCall;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;

class A2ATraceCommand extends Command
{
    protected $signature = 'a2a:trace
                            {id : Agent run id or A2A task id}
                            {--max-text=300 : Maximum characters for prompt/result excerpts}
                            {--no-prompts : Hide prompt and chat excerpts}';

    protected $description = 'Render an ASCII trace for an A2A agent run tree';

    public function handle(): int
    {
        $run = $this->resolveRootRun((string) $this->argument('id'));

        if (! $run instanceof AgentRun) {
            $this->error('A2A trace root not found. Pass an agent_run_id or A2A task id.');

            return SymfonyCommand::FAILURE;
        }

        $this->line($this->style('A2A TRACE', 'magenta', true).' '.$this->muted("root={$run->id}"));
        $this->line($this->muted('All timings use persisted created_at/updated_at timestamps.'));
        $this->newLine();

        $visited = [];
        $this->renderRun($run, '', true, $visited, true);

        return SymfonyCommand::SUCCESS;
    }

    private function resolveRootRun(string $id): ?AgentRun
    {
        $run = AgentRun::query()->find($id);

        if ($run instanceof AgentRun) {
            return $run;
        }

        $task = A2ATask::query()->find($id);
        $runId = $task?->payload['metadata']['agent_run_id'] ?? null;

        return is_string($runId) ? AgentRun::query()->find($runId) : null;
    }

    /**
     * @param  array<string, bool>  $visited
     */
    private function renderRun(AgentRun $run, string $prefix, bool $last, array &$visited, bool $root = false): void
    {
        $connector = $root ? '' : ($last ? '`- ' : '+- ');
        $childPrefix = $root ? '' : $prefix.($last ? '   ' : '|  ');
        $tokens = $this->formatTokens($this->tokenUsage($run));

        $this->line(sprintf(
            '%s%s %s %s %s %s %s',
            $prefix.$connector,
            $this->style('agent', 'cyan', true),
            $this->style($run->agent_slug, 'cyan', true),
            $this->muted("run={$run->id}"),
            $this->stateBadge($run->state),
            $this->muted('duration='.$this->duration($run->created_at, $run->updated_at)),
            $this->muted("attempts={$run->attempts} tokens={$tokens}"),
        ));

        if (isset($visited[$run->id])) {
            $this->line($childPrefix.'`- '.$this->style('cycle: run already rendered', 'yellow', true));

            return;
        }

        $visited[$run->id] = true;
        $this->renderRunMetadata($run, $childPrefix);

        $toolCalls = AgentToolCall::query()
            ->where('agent_run_id', $run->id)
            ->oldest()
            ->get();

        foreach ($toolCalls as $index => $toolCall) {
            /** @var AgentToolCall $toolCall */
            $this->renderToolCall(
                toolCall: $toolCall,
                prefix: $childPrefix,
                last: $index === $toolCalls->count() - 1,
                visited: $visited,
            );
        }
    }

    private function renderRunMetadata(AgentRun $run, string $prefix): void
    {
        $taskId = $run->input['a2a_task_id'] ?? null;

        if (is_string($taskId)) {
            $task = A2ATask::query()->find($taskId);
            $state = $task?->state?->value ?? 'missing';
            $this->line($prefix.'|  '.$this->muted("a2a_task={$taskId}").' '.$this->stateBadge($state));
        }

        $parentRunId = $run->input['parent_agent_run_id'] ?? null;

        if (is_string($parentRunId)) {
            $this->line($prefix.'|  '.$this->muted("parent_run={$parentRunId}"));
        }

        if ($run->last_error_kind !== null || $run->last_error_message !== null) {
            $this->line($prefix.'|  '.$this->errorLine('error', $run->last_error_kind, $run->last_error_message));
        }

        if ((bool) $this->option('no-prompts')) {
            return;
        }

        $prompt = $this->messageText($run->input['message'] ?? $run->input['prompt'] ?? null);

        if ($prompt !== '') {
            $this->line($prefix.'|  '.$this->muted('input:').' '.$this->excerpt($prompt));
        }

        $messages = AgentChatMessage::query()
            ->where('thread_id', "{$run->agent_slug}:{$run->id}")
            ->oldest()
            ->get();

        foreach ($messages as $message) {
            $text = $this->messageText($message->content);

            if ($text === '') {
                continue;
            }

            $messageTokens = $this->formatTokens($this->tokenUsage($message->meta));
            $this->line($prefix.'|  '.$this->style("chat {$message->role}", 'blue').' '.$this->muted("tokens={$messageTokens}:").' '.$this->excerpt($text));
        }

        $output = $this->messageText($run->output['message'] ?? $run->output['error'] ?? null);

        if ($output !== '') {
            $this->line($prefix.'|  '.$this->muted('output:').' '.$this->style($this->excerpt($output), 'green'));
        }
    }

    /**
     * @param  array<string, bool>  $visited
     */
    private function renderToolCall(AgentToolCall $toolCall, string $prefix, bool $last, array &$visited): void
    {
        $connector = $last ? '`- ' : '+- ';
        $childPrefix = $prefix.($last ? '   ' : '|  ');

        $this->line(sprintf(
            '%s%s %s %s %s %s',
            $prefix.$connector,
            $this->style('tool', 'yellow', true),
            $this->style($toolCall->tool_name, 'yellow', true),
            $this->muted("call={$toolCall->id}"),
            $this->stateBadge($toolCall->state),
            $this->muted('duration='.$this->duration($toolCall->created_at, $toolCall->updated_at).' tokens='.$this->formatTokens($this->tokenUsage([$toolCall->arguments, $toolCall->result]))),
        ));

        if (! (bool) $this->option('no-prompts')) {
            $this->renderToolPayload($toolCall, $childPrefix);
        }

        if ($toolCall->error !== null || $toolCall->error_kind !== null) {
            $this->line($childPrefix.'|  '.$this->errorLine('error', $toolCall->error_kind, $toolCall->error));
        }

        $childTask = A2AChildTask::query()
            ->where('tool_call_id', $toolCall->id)
            ->first();

        if (! $childTask instanceof A2AChildTask) {
            return;
        }

        $this->line(sprintf(
            '%s|  %s %s %s %s %s %s',
            $childPrefix,
            $this->style("child_task={$childTask->id}", 'blue', true),
            $this->muted("remote_task={$childTask->remote_task_id}"),
            $this->style("remote_agent={$childTask->remote_agent_slug}", 'blue'),
            $this->stateBadge($childTask->state->value),
            $this->muted('duration='.$this->duration($childTask->created_at, $childTask->updated_at)),
            $this->muted("attempts={$childTask->attempts}"),
        ));

        if ($childTask->last_error_kind !== null || $childTask->last_error_message !== null) {
            $this->line($childPrefix.'|  '.$this->errorLine(
                'child_error',
                $childTask->last_error_kind,
                $childTask->last_error_message,
            ));
        }

        $childRun = $this->resolveChildRun($childTask);

        if ($childRun instanceof AgentRun) {
            $this->renderRun($childRun, $childPrefix, true, $visited);
        }
    }

    private function renderToolPayload(AgentToolCall $toolCall, string $prefix): void
    {
        $agentSlug = $toolCall->arguments['agent_slug'] ?? null;
        $message = $this->messageText($toolCall->arguments['message'] ?? null);

        if (is_string($agentSlug)) {
            $this->line($prefix.'|  '.$this->muted('arg agent_slug=').$this->style($agentSlug, 'blue'));
        }

        if ($message !== '') {
            $this->line($prefix.'|  '.$this->muted('arg message:').' '.$this->excerpt($message));
        }

        $result = $this->messageText(
            $toolCall->result['artifact'] ?? $toolCall->result['error'] ?? $toolCall->result ?? null,
        );

        if ($result !== '') {
            $this->line($prefix.'|  '.$this->muted('result:').' '.$this->style($this->excerpt($result), 'green'));
        }
    }

    private function resolveChildRun(A2AChildTask $childTask): ?AgentRun
    {
        $runId = $childTask->request_payload['child_agent_run_id'] ?? null;

        if (! is_string($runId)) {
            $task = A2ATask::query()->find($childTask->remote_task_id);
            $runId = $task?->payload['metadata']['agent_run_id'] ?? null;
        }

        return is_string($runId) ? AgentRun::query()->find($runId) : null;
    }

    private function duration(?CarbonInterface $start, ?CarbonInterface $end): string
    {
        if (! $start instanceof CarbonInterface || ! $end instanceof CarbonInterface) {
            return 'n/a';
        }

        $milliseconds = (int) round($start->diffInMilliseconds($end, true));

        if ($milliseconds < 1000) {
            return "{$milliseconds}ms";
        }

        return number_format($milliseconds / 1000, 2).'s';
    }

    /**
     * @return array<string, int>
     */
    private function tokenUsage(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $tokens = [];
        $aliases = [
            'input' => ['input_tokens', 'prompt_tokens'],
            'output' => ['output_tokens', 'completion_tokens'],
            'total' => ['total_tokens'],
        ];

        foreach ($aliases as $label => $keys) {
            foreach ($keys as $key) {
                if (isset($value[$key]) && is_numeric($value[$key])) {
                    $tokens[$label] = (int) $value[$key];
                }
            }
        }

        foreach ($value as $nested) {
            foreach ($this->tokenUsage($nested) as $label => $count) {
                $tokens[$label] = ($tokens[$label] ?? 0) + $count;
            }
        }

        return $tokens;
    }

    /**
     * @param  array<string, int>  $tokens
     */
    private function formatTokens(array $tokens): string
    {
        if ($tokens === []) {
            return 'n/a';
        }

        return collect(['input', 'output', 'total'])
            ->filter(fn (string $label): bool => isset($tokens[$label]))
            ->map(fn (string $label): string => "{$label}:{$tokens[$label]}")
            ->implode(',');
    }

    private function messageText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value) || is_numeric($value) || is_bool($value)) {
            return trim((string) $value);
        }

        if (! is_array($value)) {
            return '';
        }

        if (isset($value['text'])) {
            return $this->messageText($value['text']);
        }

        if (isset($value['content'])) {
            return $this->messageText($value['content']);
        }

        if (isset($value['parts']) && is_array($value['parts'])) {
            return collect($value['parts'])
                ->map(fn (mixed $part): string => $this->messageText($part))
                ->filter()
                ->implode("\n");
        }

        if (array_is_list($value)) {
            return collect($value)
                ->map(fn (mixed $part): string => $this->messageText($part))
                ->filter()
                ->implode("\n");
        }

        return trim(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function excerpt(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? '';
        $limit = max(20, (int) $this->option('max-text'));

        return Str::limit($text, $limit);
    }

    private function errorText(?string $kind, ?string $message): string
    {
        return trim(($kind ?? 'unknown').($message === null ? '' : " {$message}"));
    }

    private function stateBadge(string $state): string
    {
        $color = match ($state) {
            'completed' => 'green',
            'working', 'waiting', 'waiting_for_tool', 'submitted' => 'yellow',
            'failed', 'rejected', 'canceled' => 'red',
            default => 'white',
        };

        return $this->style("state={$state}", $color, true);
    }

    private function errorLine(string $label, ?string $kind, ?string $message): string
    {
        return $this->style("{$label}=".$this->errorText($kind, $message), 'red', true);
    }

    private function muted(string $text): string
    {
        return $this->style($text, 'white');
    }

    private function style(string $text, string $color, bool $bold = false): string
    {
        $options = $bold ? ';options=bold' : '';

        return "<fg={$color}{$options}>".OutputFormatter::escape($text).'</>';
    }
}
