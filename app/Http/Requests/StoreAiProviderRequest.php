<?php

namespace App\Http\Requests;

use App\Enums\AiProviderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAiProviderRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:ai_providers,slug'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', Rule::enum(AiProviderType::class)],
            'credentials' => ['required', 'array'],
            'credentials.key' => ['required', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'models' => ['required', 'array', 'min:1'],
            'models.*.model' => ['required', 'string', 'max:255', 'distinct'],
            'models.*.name' => ['nullable', 'string', 'max:255'],
            'models.*.description' => ['nullable', 'string'],
            'models.*.is_active' => ['sometimes', 'boolean'],
        ];
    }
}
