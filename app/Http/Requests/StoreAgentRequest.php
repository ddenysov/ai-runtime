<?php

namespace App\Http\Requests;

use App\Models\AiProviderModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAgentRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:agents,slug'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'ai_provider_model_id' => ['required', 'integer', 'exists:ai_provider_models,id'],
            'is_active' => ['sometimes', 'boolean'],
            'instructions' => ['required', 'array'],
            'instructions.background' => ['required', 'array', 'min:1'],
            'instructions.background.*' => ['required', 'string', 'max:2000'],
            'instructions.steps' => ['sometimes', 'array'],
            'instructions.steps.*' => ['required', 'string', 'max:2000'],
            'instructions.output' => ['sometimes', 'array'],
            'instructions.output.*' => ['required', 'string', 'max:2000'],
            'input_modes' => ['sometimes', 'array', 'min:1'],
            'input_modes.*' => ['required', 'string', 'max:100'],
            'output_modes' => ['sometimes', 'array', 'min:1'],
            'output_modes.*' => ['required', 'string', 'max:100'],
            'skills' => ['sometimes', 'array'],
            'skills.*.id' => ['required_with:skills', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/'],
            'skills.*.name' => ['required_with:skills', 'string', 'max:255'],
            'skills.*.description' => ['nullable', 'string'],
            'skills.*.tags' => ['sometimes', 'array'],
            'skills.*.tags.*' => ['required', 'string', 'max:100'],
            'skills.*.examples' => ['sometimes', 'array'],
            'skills.*.examples.*' => ['required', 'string', 'max:255'],
            'subagents' => ['sometimes', 'array'],
            'subagents.*' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', 'distinct'],
            'tools' => ['sometimes', 'array'],
            'tools.*.slug' => ['required', 'string', Rule::in(['remote_a2a_agent', 'get_agent_card']), 'distinct'],
            'tools.*.is_enabled' => ['sometimes', 'boolean'],
            'tools.*.config' => ['nullable', 'array'],
            'input_schema' => ['nullable', 'array'],
            'output_schema' => ['nullable', 'array'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:200000'],
            'timeout_seconds' => ['sometimes', 'integer', 'min:1', 'max:600'],
            'history_context_window' => ['sometimes', 'integer', 'min:1000', 'max:200000'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $providerModelId = $this->integer('ai_provider_model_id');

                if ($providerModelId === 0) {
                    return;
                }

                $isAvailable = AiProviderModel::query()
                    ->whereKey($providerModelId)
                    ->where('is_active', true)
                    ->whereHas('provider', fn ($query) => $query->where('is_active', true))
                    ->exists();

                if (! $isAvailable) {
                    $validator->errors()->add('ai_provider_model_id', 'The selected provider model is inactive or unavailable.');
                }
            },
        ];
    }
}
