<?php

namespace App\Channels\Http\Requests;

use App\Channels\Models\AgentChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAgentChannelRequest extends FormRequest
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
        /** @var AgentChannel $channel */
        $channel = $this->route('agentChannel');

        return [
            'expected_version' => ['required', 'integer', 'min:0'],
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('agent_channels', 'name')
                    ->where('agent_id', $channel->agent_id)
                    ->ignore($channel->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'type' => ['sometimes', 'string', 'max:64', Rule::in(['telegram', 'slack', 'webhook'])],
            'settings' => ['sometimes', 'nullable', 'array'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function expectedVersion(): int
    {
        return (int) $this->validated('expected_version');
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
