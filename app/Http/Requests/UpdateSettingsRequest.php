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
        ];
    }
}
