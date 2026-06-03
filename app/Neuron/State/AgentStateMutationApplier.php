<?php

namespace App\Neuron\State;

use App\Neuron\RuntimeAgentContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AgentStateMutationApplier
{
    public function __construct(
        private readonly AgentStateStore $store,
    ) {}

    /**
     * @param  array<string, mixed>  $assignment
     * @param  array<string, mixed>  $payload
     * @return array{applied: int, skipped: int, results: array<int, array<string, mixed>>}
     */
    public function apply(RuntimeAgentContext $context, array $assignment, array $payload): array
    {
        $mutations = $payload['mutations'] ?? $payload['updates'] ?? [];

        if (! is_array($mutations)) {
            throw ValidationException::withMessages([
                'mutations' => 'Processor response must include a mutations array.',
            ]);
        }

        $allowedEntityTypes = $this->stringList($assignment['entity_types'] ?? null);
        $minConfidence = (float) ($assignment['min_confidence'] ?? 0);
        $defaultScope = $this->scope($assignment['scope'] ?? $assignment['default_scope'] ?? 'conversation');
        $applied = 0;
        $skipped = 0;
        $results = [];

        foreach ($mutations as $index => $mutation) {
            if (! is_array($mutation)) {
                $skipped++;

                continue;
            }

            $validated = Validator::make($mutation, [
                'operation' => ['required', 'string', 'in:create,upsert,delete'],
                'scope' => ['sometimes', 'string', 'in:conversation,global'],
                'entity_type' => ['required', 'string', 'max:100'],
                'source_key' => ['required_unless:operation,create', 'nullable', 'string', 'max:255'],
                'title' => ['required_unless:operation,delete', 'nullable', 'string', 'max:255'],
                'summary' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'content' => ['required_unless:operation,delete', 'nullable', 'array'],
                'group' => ['sometimes', 'nullable', 'string', 'max:255'],
                'tags' => ['sometimes', 'array'],
                'tags.*' => ['required', 'string', 'max:100'],
                'confidence' => ['sometimes', 'numeric', 'min:0', 'max:1'],
                'evidence' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ])->validate();

            if ($allowedEntityTypes !== [] && ! in_array($validated['entity_type'], $allowedEntityTypes, true)) {
                $skipped++;

                continue;
            }

            if ((float) ($validated['confidence'] ?? 1) < $minConfidence) {
                $skipped++;

                continue;
            }

            $scope = $this->scope($validated['scope'] ?? $defaultScope);
            $operation = $validated['operation'];

            if ($operation === 'delete') {
                $results[] = $this->store->deleteBySourceKey(
                    context: $context,
                    scope: $scope,
                    sourceKey: $validated['source_key'],
                    entityType: $validated['entity_type'],
                );
            } elseif ($operation === 'upsert') {
                $results[] = $this->store->upsertBySourceKey(
                    context: $context,
                    scope: $scope,
                    sourceKey: $validated['source_key'],
                    title: $validated['title'],
                    content: $this->contentWithMetadata($validated),
                    entityType: $validated['entity_type'],
                    group: $validated['group'] ?? null,
                    tags: $validated['tags'] ?? null,
                    summary: $validated['summary'] ?? null,
                );
            } else {
                $results[] = $this->store->create(
                    context: $context,
                    scope: $scope,
                    title: $validated['title'],
                    content: $this->contentWithMetadata($validated),
                    entityType: $validated['entity_type'],
                    sourceKey: $validated['source_key'] ?? null,
                    group: $validated['group'] ?? null,
                    tags: $validated['tags'] ?? null,
                    summary: $validated['summary'] ?? null,
                );
            }

            $applied++;
        }

        return [
            'applied' => $applied,
            'skipped' => $skipped,
            'results' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $mutation
     * @return array<string, mixed>
     */
    private function contentWithMetadata(array $mutation): array
    {
        $content = is_array($mutation['content'] ?? null) ? $mutation['content'] : [];

        return [
            ...$content,
            '_processor' => array_filter([
                'confidence' => $mutation['confidence'] ?? null,
                'evidence' => $mutation['evidence'] ?? null,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
        ];
    }

    private function scope(mixed $scope): string
    {
        return $scope === 'global' ? 'global' : 'conversation';
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
