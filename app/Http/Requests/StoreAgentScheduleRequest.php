<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAgentScheduleConfig;
use App\Models\Agent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAgentScheduleRequest extends FormRequest
{
    use ValidatesAgentScheduleConfig;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Agent $agent */
        $agent = $this->route('agent');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('agent_schedules', 'name')->where('agent_id', $agent->id),
            ],
            'enabled' => ['sometimes', 'boolean'],
            'deliver_to_channel' => ['sometimes', 'boolean'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'schedule_type' => ['required', 'string', Rule::in(['daily', 'weekly', 'interval', 'cron'])],
            'schedule_config' => ['required', 'array'],
            'message' => ['required', 'string', 'max:20000'],
            'context_id' => ['nullable', 'string', 'max:255'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateAgentScheduleConfig($validator);
        });
    }
}
