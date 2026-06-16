<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
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
            'prompts' => ['sometimes', 'array'],
            'prompts.prompt_generator_agent_id' => ['sometimes', 'nullable', 'integer', 'exists:agents,id'],
            'gatekeeper' => ['sometimes', 'array'],
            'gatekeeper.enabled' => ['sometimes', 'boolean'],
            'gatekeeper.bot_token' => ['sometimes', 'nullable', 'string', 'max:255'],
            'gatekeeper.telegram_chat_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
