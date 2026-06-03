<?php

namespace App\Neuron\State;

use App\Models\AgentStateEntry;
use App\Models\AgentStateGroup;
use App\Models\AgentStateTag;
use App\Neuron\RuntimeAgentContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AgentStateStore
{
    private const SCOPE_CONVERSATION = 'conversation';

    private const SCOPE_GLOBAL = 'global';

    /**
     * @param  array<int, string>|null  $tags
     * @return array<string, mixed>
     */
    public function create(
        RuntimeAgentContext $context,
        ?string $scope,
        string $title,
        mixed $content,
        ?string $entityType = null,
        ?string $group = null,
        ?array $tags = null,
        ?string $summary = null,
    ): array {
        $resolvedScope = $this->resolveScope($context, $scope);

        return DB::transaction(function () use ($context, $resolvedScope, $title, $content, $entityType, $group, $tags, $summary): array {
            $entry = AgentStateEntry::query()->create([
                'scope' => $resolvedScope['scope'],
                'conversation_id' => $resolvedScope['conversation_id'],
                'agent_slug' => $context->agentSlug,
                'group_id' => $this->resolveGroup($resolvedScope, $group)?->id,
                'entity_type' => $this->nullableTrim($entityType),
                'title' => $this->requiredTrim($title, 'title'),
                'summary' => $this->nullableTrim($summary),
                'content' => $this->normalizeContent($content),
            ]);

            $this->syncTags($entry, $tags);

            return $this->detail($entry->refresh()->load(['group', 'tags']));
        });
    }

    /**
     * @param  array<int, string>|null  $tags
     * @return array<string, mixed>
     */
    public function update(
        RuntimeAgentContext $context,
        string $id,
        ?string $title = null,
        mixed $content = null,
        ?string $entityType = null,
        ?string $group = null,
        ?array $tags = null,
        ?string $summary = null,
    ): array {
        return DB::transaction(function () use ($context, $id, $title, $content, $entityType, $group, $tags, $summary): array {
            $entry = $this->findVisibleEntry($context, $id);
            $resolvedScope = [
                'scope' => $entry->scope,
                'conversation_id' => $entry->conversation_id,
            ];

            $updates = [];

            if ($title !== null) {
                $updates['title'] = $this->requiredTrim($title, 'title');
            }

            if ($content !== null) {
                $updates['content'] = $this->normalizeContent($content);
            }

            if ($entityType !== null) {
                $updates['entity_type'] = $this->nullableTrim($entityType);
            }

            if ($summary !== null) {
                $updates['summary'] = $this->nullableTrim($summary);
            }

            if ($group !== null) {
                $updates['group_id'] = $this->resolveGroup($resolvedScope, $group)?->id;
            }

            if ($updates !== []) {
                $entry->update($updates);
            }

            if ($tags !== null) {
                $this->syncTags($entry, $tags);
            }

            return $this->detail($entry->refresh()->load(['group', 'tags']));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(RuntimeAgentContext $context, string $id): array
    {
        $entry = $this->findVisibleEntry($context, $id);
        $entry->delete();

        return [
            'deleted' => true,
            'id' => $id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(RuntimeAgentContext $context, string $id): array
    {
        return $this->detail($this->findVisibleEntry($context, $id)->load(['group', 'tags']));
    }

    /**
     * @return array<string, mixed>
     */
    public function list(
        RuntimeAgentContext $context,
        ?string $scope = null,
        ?string $group = null,
        ?string $tag = null,
        ?string $entityType = null,
        ?string $search = null,
        ?int $limit = null,
    ): array {
        $query = AgentStateEntry::query()
            ->with(['group', 'tags'])
            ->when(
                $scope === null || trim($scope) === '',
                fn (Builder $query): Builder => $this->visibleEntriesQuery($query, $context),
                fn (Builder $query): Builder => $this->scopedEntriesQuery($query, $context, $scope),
            )
            ->when($this->nullableTrim($entityType), function (Builder $query, string $entityType): void {
                $query->where('entity_type', $entityType);
            })
            ->when($this->nullableTrim($group), function (Builder $query, string $group): void {
                $slug = $this->slug($group);
                $query->whereHas('group', function (Builder $query) use ($group, $slug): void {
                    $query->where('slug', $slug)->orWhere('name', $group);
                });
            })
            ->when($this->nullableTrim($tag), function (Builder $query, string $tag): void {
                $slug = $this->slug($tag);
                $query->whereHas('tags', function (Builder $query) use ($tag, $slug): void {
                    $query->where('slug', $slug)->orWhere('name', $tag);
                });
            })
            ->when($this->nullableTrim($search), function (Builder $query, string $search): void {
                $term = '%'.$search.'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query
                        ->where('title', 'like', $term)
                        ->orWhere('summary', 'like', $term)
                        ->orWhere('entity_type', 'like', $term);
                });
            })
            ->latest('updated_at')
            ->limit(max(1, min($limit ?? 20, 50)));

        $entries = $query->get();

        return [
            'entries' => $entries->map(fn (AgentStateEntry $entry): array => $this->summary($entry))->all(),
            'count' => $entries->count(),
        ];
    }

    private function findVisibleEntry(RuntimeAgentContext $context, string $id): AgentStateEntry
    {
        $entry = $this->visibleEntriesQuery(AgentStateEntry::query(), $context)
            ->where('id', $id)
            ->first();

        if (! $entry instanceof AgentStateEntry) {
            throw new InvalidArgumentException('State entry was not found in the current scope.');
        }

        return $entry;
    }

    private function visibleEntriesQuery(Builder $query, RuntimeAgentContext $context): Builder
    {
        return $query->where(function (Builder $query) use ($context): void {
            $query->where('scope', self::SCOPE_GLOBAL);

            if ($context->conversationId !== null && trim($context->conversationId) !== '') {
                $query->orWhere(function (Builder $query) use ($context): void {
                    $query
                        ->where('scope', self::SCOPE_CONVERSATION)
                        ->where('conversation_id', $context->conversationId);
                });
            }
        });
    }

    private function scopedEntriesQuery(Builder $query, RuntimeAgentContext $context, string $scope): Builder
    {
        $resolvedScope = $this->resolveScope($context, $scope);

        return $query
            ->where('scope', $resolvedScope['scope'])
            ->where('conversation_id', $resolvedScope['conversation_id']);
    }

    /**
     * @return array{scope: string, conversation_id: string|null}
     */
    private function resolveScope(RuntimeAgentContext $context, ?string $scope): array
    {
        $scope = strtolower($this->nullableTrim($scope) ?? self::SCOPE_CONVERSATION);

        if (! in_array($scope, [self::SCOPE_CONVERSATION, self::SCOPE_GLOBAL], true)) {
            throw new InvalidArgumentException('State scope must be conversation or global.');
        }

        if ($scope === self::SCOPE_GLOBAL) {
            return [
                'scope' => self::SCOPE_GLOBAL,
                'conversation_id' => null,
            ];
        }

        if ($context->conversationId === null || trim($context->conversationId) === '') {
            throw new InvalidArgumentException('Conversation scoped state requires a conversation_id in the runtime context.');
        }

        return [
            'scope' => self::SCOPE_CONVERSATION,
            'conversation_id' => $context->conversationId,
        ];
    }

    /**
     * @param  array{scope: string, conversation_id: string|null}  $scope
     */
    private function resolveGroup(array $scope, ?string $group): ?AgentStateGroup
    {
        $name = $this->nullableTrim($group);

        if ($name === null) {
            return null;
        }

        return AgentStateGroup::query()->firstOrCreate(
            [
                'scope' => $scope['scope'],
                'conversation_id' => $scope['conversation_id'],
                'parent_id' => null,
                'slug' => $this->slug($name),
            ],
            [
                'name' => $name,
            ],
        );
    }

    /**
     * @param  array<int, string>|null  $tags
     */
    private function syncTags(AgentStateEntry $entry, ?array $tags): void
    {
        if ($tags === null) {
            return;
        }

        $tagIds = collect($tags)
            ->filter(fn (mixed $tag): bool => is_string($tag) && trim($tag) !== '')
            ->map(function (string $tag): int {
                $name = trim($tag);

                return AgentStateTag::query()->firstOrCreate(
                    ['slug' => $this->slug($name)],
                    ['name' => $name],
                )->id;
            })
            ->unique()
            ->values()
            ->all();

        $entry->tags()->sync($tagIds);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeContent(mixed $content): array
    {
        if (is_string($content)) {
            return ['text' => $content];
        }

        if (is_array($content)) {
            return $content;
        }

        return ['value' => $content];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(AgentStateEntry $entry): array
    {
        return $this->summary($entry) + [
            'content' => $entry->content,
            'created_at' => $entry->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(AgentStateEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'scope' => $entry->scope,
            'conversation_id' => $entry->conversation_id,
            'agent_slug' => $entry->agent_slug,
            'title' => $entry->title,
            'entity_type' => $entry->entity_type,
            'summary' => $entry->summary,
            'group' => $entry->group instanceof AgentStateGroup ? [
                'id' => $entry->group->id,
                'name' => $entry->group->name,
                'slug' => $entry->group->slug,
            ] : null,
            'tags' => $entry->tags
                ->map(fn (AgentStateTag $tag): array => [
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                ])
                ->values()
                ->all(),
            'updated_at' => $entry->updated_at?->toISOString(),
        ];
    }

    private function requiredTrim(string $value, string $field): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException("State {$field} cannot be empty.");
        }

        return $value;
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function slug(string $value): string
    {
        return Str::slug($value) ?: substr(sha1($value), 0, 12);
    }
}
