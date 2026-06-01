<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateAgentInstructionsRequest extends FormRequest
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
            'brief' => ['nullable', 'string', 'max:10000'],
            'feedback' => ['nullable', 'string', 'max:10000'],
            'draft_instructions' => ['nullable', 'array'],
            'draft_instructions.background' => ['sometimes', 'array'],
            'draft_instructions.background.*' => ['required', 'string', 'max:2000'],
            'draft_instructions.steps' => ['sometimes', 'array'],
            'draft_instructions.steps.*' => ['required', 'string', 'max:2000'],
            'draft_instructions.output' => ['sometimes', 'array'],
            'draft_instructions.output.*' => ['required', 'string', 'max:2000'],
        ];
    }
}
