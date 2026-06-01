<?php

namespace App\Mcp\Http\Requests;

use App\Mcp\Models\McpServer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMcpServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var McpServer|null $server */
        $server = $this->route('mcpServer');

        return [
            'expected_version' => ['required', 'integer', 'min:0'],
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('mcp_servers', 'name')->ignore($server?->id),
            ],
            'transport' => ['sometimes', 'string', Rule::in(['stdio'])],
            'command' => ['sometimes', 'string', 'max:1024'],
            'args' => ['sometimes', 'nullable', 'array'],
            'args.*' => ['string', 'max:4096'],
            'cwd' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'env' => ['sometimes', 'nullable', 'array'],
            'env.*' => ['nullable', 'string', 'max:65535'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function expectedVersion(): int
    {
        return (int) $this->validated('expected_version');
    }

    /**
     * @return array<string, string>
     */
    public function stringEnvironment(): array
    {
        $raw = $this->input('env', []);
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $value) {
            if (! is_string($key) || ! is_string($value) || $value === '') {
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @return array<int, string>|null
     */
    public function argumentList(): ?array
    {
        if (! $this->has('args')) {
            return null;
        }

        $args = $this->validated('args');

        if (! is_array($args)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $value): string => (string) $value, $args));
    }
}
