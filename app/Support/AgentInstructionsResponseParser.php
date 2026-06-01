<?php

namespace App\Support;

class AgentInstructionsResponseParser
{
    /**
     * @return array{background: list<string>, steps: list<string>, output: list<string>}
     */
    public function parse(string $response): array
    {
        $decoded = $this->decodeJson($response);

        if ($decoded !== null) {
            return $this->normalizeInstructions($decoded);
        }

        $lines = collect(preg_split('/\r\n|\r|\n/', trim($response)) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->values()
            ->all();

        return [
            'background' => $lines !== [] ? [$lines[0]] : [],
            'steps' => array_slice($lines, 1, max(count($lines) - 2, 0)),
            'output' => $lines !== [] ? [end($lines)] : [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $response): ?array
    {
        $candidates = [
            trim($response),
        ];

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $response, $matches) === 1) {
            $candidates[] = trim($matches[1]);
        }

        if (preg_match('/\{[\s\S]*\}/', $response, $matches) === 1) {
            $candidates[] = trim($matches[0]);
        }

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            try {
                $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{background: list<string>, steps: list<string>, output: list<string>}
     */
    private function normalizeInstructions(array $payload): array
    {
        return [
            'background' => $this->normalizeSection($payload['background'] ?? []),
            'steps' => $this->normalizeSection($payload['steps'] ?? []),
            'output' => $this->normalizeSection($payload['output'] ?? []),
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeSection(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\r\n|\r|\n/', trim($value)) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->values()
            ->all();
    }
}
