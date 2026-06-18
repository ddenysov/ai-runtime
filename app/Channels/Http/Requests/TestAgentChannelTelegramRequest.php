<?php

namespace App\Channels\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TestAgentChannelTelegramRequest extends FormRequest
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
            'bot_token' => ['sometimes', 'nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
