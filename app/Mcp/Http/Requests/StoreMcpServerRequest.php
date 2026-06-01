<?php

namespace App\Mcp\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMcpServerRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255', 'unique:mcp_servers,name'],
            'transport' => ['required', 'string', Rule::in(['stdio'])],
            'command' => ['required', 'string', 'max:1024'],
            'args' => ['sometimes', 'nullable', 'array'],
            'args.*' => ['string', 'max:4096'],
            'cwd' => ['nullable', 'string', 'max:2048'],
            'env' => ['nullable', 'array'],
            'env.*' => ['nullable', 'string', 'max:65535'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'enabled' => ['sometimes', 'boolean'],
        ];
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
     * @return array<int, string>
     */
    public function argumentList(): array
    {
        $args = $this->validated('args', []);

        if (! is_array($args)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $value): string => (string) $value, $args));
    }
}
