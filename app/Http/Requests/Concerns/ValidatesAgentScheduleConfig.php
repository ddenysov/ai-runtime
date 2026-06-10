<?php

namespace App\Http\Requests\Concerns;

use Cron\CronExpression;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

trait ValidatesAgentScheduleConfig
{
    protected function validateAgentScheduleConfig(Validator $validator): void
    {
        $type = $this->input('schedule_type');
        $config = $this->input('schedule_config');

        if (! is_string($type) || ! is_array($config)) {
            return;
        }

        match ($type) {
            'daily' => $this->validateDailyConfig($validator, $config),
            'weekly' => $this->validateWeeklyConfig($validator, $config),
            'interval' => $this->validateIntervalConfig($validator, $config),
            'cron' => $this->validateCronConfig($validator, $config),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateDailyConfig(Validator $validator, array $config): void
    {
        if (! $this->isValidTime($config['time'] ?? null)) {
            $validator->errors()->add('schedule_config.time', 'A valid time in HH:MM format is required.');
        }

        if (! $this->hasValidDaysOfWeek($config['days_of_week'] ?? null)) {
            $validator->errors()->add('schedule_config.days_of_week', 'At least one valid day of week (0-7) is required.');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateWeeklyConfig(Validator $validator, array $config): void
    {
        if (! $this->isValidTime($config['time'] ?? null)) {
            $validator->errors()->add('schedule_config.time', 'A valid time in HH:MM format is required.');
        }

        $day = $config['day_of_week'] ?? null;

        if (! is_int($day) && ! is_string($day)) {
            $validator->errors()->add('schedule_config.day_of_week', 'A valid day of week (0-7) is required.');
        } elseif (! $this->hasValidDaysOfWeek([(int) $day])) {
            $validator->errors()->add('schedule_config.day_of_week', 'Day of week must be between 0 and 7.');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateIntervalConfig(Validator $validator, array $config): void
    {
        $minutes = $config['every_minutes'] ?? null;

        if (! is_int($minutes) && ! is_string($minutes)) {
            $validator->errors()->add('schedule_config.every_minutes', 'Interval minutes are required.');

            return;
        }

        $value = (int) $minutes;

        if ($value < 1 || $value > 525_600) {
            $validator->errors()->add('schedule_config.every_minutes', 'Interval must be between 1 and 525600 minutes.');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateCronConfig(Validator $validator, array $config): void
    {
        $expression = $config['expression'] ?? null;

        if (! is_string($expression) || trim($expression) === '') {
            $validator->errors()->add('schedule_config.expression', 'A cron expression is required.');

            return;
        }

        try {
            new CronExpression(trim($expression));
        } catch (InvalidArgumentException) {
            $validator->errors()->add('schedule_config.expression', 'The cron expression is invalid.');
        }
    }

    private function isValidTime(mixed $time): bool
    {
        if (! is_string($time) || ! preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches)) {
            return false;
        }

        return (int) $matches[1] <= 23 && (int) $matches[2] <= 59;
    }

    private function hasValidDaysOfWeek(mixed $rawDays): bool
    {
        if (! is_array($rawDays) || $rawDays === []) {
            return false;
        }

        foreach ($rawDays as $day) {
            if (! is_int($day) && ! is_string($day)) {
                return false;
            }

            $value = (int) $day;

            if ($value < 0 || $value > 7) {
                return false;
            }
        }

        return true;
    }
}
