<?php

namespace App\Http\Requests;

use App\Enums\AiProviderType;
use App\Models\AiProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAiProviderRequest extends FormRequest
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
        /** @var AiProvider $provider */
        $provider = $this->route('aiProvider');

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', Rule::enum(AiProviderType::class)],
            'credentials' => ['sometimes', 'array'],
            'credentials.key' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'models' => ['required', 'array', 'min:1'],
            'models.*.id' => [
                'sometimes',
                'integer',
                Rule::exists('ai_provider_models', 'id')->where('ai_provider_id', $provider->id),
            ],
            'models.*.model' => ['required', 'string', 'max:255', 'distinct'],
            'models.*.name' => ['nullable', 'string', 'max:255'],
            'models.*.description' => ['nullable', 'string'],
            'models.*.is_active' => ['sometimes', 'boolean'],
        ];
    }
}
