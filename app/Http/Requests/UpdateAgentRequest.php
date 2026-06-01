<?php

namespace App\Http\Requests;

use App\Models\AiProviderModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAgentRequest extends FormRequest
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
            'ai_provider_model_id' => ['sometimes', 'integer', 'exists:ai_provider_models,id'],
            'instructions' => ['sometimes', 'array'],
            'instructions.background' => ['required_with:instructions', 'array', 'min:1'],
            'instructions.background.*' => ['required', 'string', 'max:2000'],
            'instructions.steps' => ['sometimes', 'array'],
            'instructions.steps.*' => ['required', 'string', 'max:2000'],
            'instructions.output' => ['sometimes', 'array'],
            'instructions.output.*' => ['required', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->has('ai_provider_model_id')) {
                    return;
                }

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
