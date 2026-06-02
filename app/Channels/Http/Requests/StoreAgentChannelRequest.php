<?php

namespace App\Channels\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAgentChannelRequest extends FormRequest
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
            'agent_id' => ['required', 'integer', 'exists:agents,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('agent_channels', 'name')->where('agent_id', (int) $this->input('agent_id')),
            ],
            'description' => ['nullable', 'string', 'max:10000'],
            'type' => ['required', 'string', 'max:64', Rule::in(['telegram', 'slack', 'webhook'])],
            'settings' => ['sometimes', 'nullable', 'array'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settingsArray(): array
    {
        $raw = $this->input('settings', []);

        return is_array($raw) ? $raw : [];
    }
}
