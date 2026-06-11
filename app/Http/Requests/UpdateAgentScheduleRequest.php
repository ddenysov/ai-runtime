<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAgentScheduleConfig;
use App\Models\AgentSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAgentScheduleRequest extends FormRequest
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
        /** @var AgentSchedule $schedule */
        $schedule = $this->route('agentSchedule');

        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('agent_schedules', 'name')
                    ->where('agent_id', $schedule->agent_id)
                    ->ignore($schedule->id),
            ],
            'enabled' => ['sometimes', 'boolean'],
            'deliver_to_channel' => ['sometimes', 'boolean'],
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
            'schedule_type' => ['sometimes', 'string', Rule::in(['daily', 'weekly', 'interval', 'cron'])],
            'schedule_config' => ['sometimes', 'array'],
            'message' => ['sometimes', 'string', 'max:20000'],
            'context_id' => ['nullable', 'string', 'max:255'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('schedule_type') && ! $this->has('schedule_config')) {
                return;
            }

            /** @var AgentSchedule $schedule */
            $schedule = $this->route('agentSchedule');

            $this->merge([
                'schedule_type' => $this->input('schedule_type', $schedule->schedule_type),
                'schedule_config' => $this->input('schedule_config', $schedule->schedule_config),
            ]);

            $this->validateAgentScheduleConfig($validator);
        });
    }
}
