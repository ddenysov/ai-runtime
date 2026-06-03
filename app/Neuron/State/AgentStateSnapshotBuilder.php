<?php

namespace App\Neuron\State;

use App\Models\AgentStateEntry;
use App\Neuron\RuntimeAgentContext;
use Illuminate\Database\Eloquent\Builder;

class AgentStateSnapshotBuilder
{
    /**
     * @param  array<int, array<string, mixed>>  $assignments
     * @return array<int, array<string, mixed>>
     */
    public function snapshots(RuntimeAgentContext $context, array $assignments): array
    {
        return collect($assignments)
            ->filter(fn (array $assignment): bool => ($assignment['trigger'] ?? null) === 'after_response')
            ->map(fn (array $assignment): array => [
                'title' => $assignment['injection_title'] ?? 'Runtime State',
                'instructions' => $assignment['injection_instructions'] ?? null,
                'entries' => $this->entries($context, $assignment),
            ])
            ->filter(fn (array $snapshot): bool => $snapshot['entries'] !== [])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $assignments
     */
    public function promptBlock(RuntimeAgentContext $context, array $assignments): ?string
    {
        $snapshots = $this->snapshots($context, $assignments);

        if ($snapshots === []) {
            return null;
        }

        return implode("\n", [
            'Authoritative runtime state is provided below. Use it as the current source of truth for this conversation unless the user explicitly changes it.',
            json_encode($snapshots, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @param  array<string, mixed>  $assignment
     * @return array<int, array<string, mixed>>
     */
    private function entries(RuntimeAgentContext $context, array $assignment): array
    {
        $filters = is_array($assignment['state_filters'] ?? null) ? $assignment['state_filters'] : [];
        $limit = max(1, min((int) ($filters['limit'] ?? 50), 100));

        return AgentStateEntry::query()
            ->with(['group', 'tags'])
            ->where('agent_slug', $context->agentSlug)
            ->where(function (Builder $query) use ($context): void {
                $query->where('scope', 'global');

                if ($context->conversationId !== null && trim($context->conversationId) !== '') {
                    $query->orWhere(function (Builder $query) use ($context): void {
                        $query
                            ->where('scope', 'conversation')
                            ->where('conversation_id', $context->conversationId);
                    });
                }
            })
            ->when($this->stringList($filters['entity_types'] ?? null), function (Builder $query, array $entityTypes): void {
                $query->whereIn('entity_type', $entityTypes);
            })
            ->when($this->stringList($filters['tags'] ?? null), function (Builder $query, array $tags): void {
                $query->whereHas('tags', fn (Builder $query) => $query->whereIn('slug', $tags)->orWhereIn('name', $tags));
            })
            ->when($this->stringList($filters['groups'] ?? null), function (Builder $query, array $groups): void {
                $query->whereHas('group', fn (Builder $query) => $query->whereIn('slug', $groups)->orWhereIn('name', $groups));
            })
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (AgentStateEntry $entry): array => [
                'entity_type' => $entry->entity_type,
                'source_key' => $entry->source_key,
                'title' => $entry->title,
                'summary' => $entry->summary,
                'content' => $entry->content,
                'group' => $entry->group?->name,
                'tags' => $entry->tags->pluck('name')->values()->all(),
                'updated_at' => $entry->updated_at?->toISOString(),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ->map(fn (string $item): string => trim($item))
            ->values()
            ->all();
    }
}
