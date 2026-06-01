<?php

namespace App\Mcp\Http\Requests;

use App\Mcp\Models\McpServer;
use App\Shared\Delivery\Http\Requests\Concerns\RequiresExpectedVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMcpServerRequest extends FormRequest
{
    use RequiresExpectedVersion;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var McpServer $server */
        $server = $this->route('mcpServer');

        return array_merge($this->expectedVersionRules(), [
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('mcp_servers', 'name')->ignore($server->id)],
            'transport' => ['sometimes', 'string', Rule::in(['stdio'])],
            'command' => ['sometimes', 'string', 'max:1024'],
            'args' => ['sometimes', 'nullable', 'array'],
            'args.*' => ['string', 'max:4096'],
            'cwd' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'env' => ['sometimes', 'nullable', 'array'],
            'env.*' => ['nullable', 'string', 'max:65535'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
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
            if (! is_string($key)) {
                continue;
            }
            if ($value === null) {
                continue;
            }
            if (! is_string($value)) {
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

        return array_values(array_map(static fn ($v): string => (string) $v, $args));
    }
}
