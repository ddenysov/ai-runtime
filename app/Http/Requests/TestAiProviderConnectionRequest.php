<?php

namespace App\Http\Requests;

use App\Enums\AiProviderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TestAiProviderConnectionRequest extends FormRequest
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
            'type' => ['required', Rule::enum(AiProviderType::class)],
            'credentials' => ['required', 'array'],
            'credentials.key' => ['required', 'string'],
            'model' => ['required', 'string', 'max:255'],
        ];
    }
}
