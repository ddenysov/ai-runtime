<?php

namespace App\Scheduling;

use App\Models\AgentSchedule;
use Cron\CronExpression;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class AgentScheduleCalculator
{
    public function nextRunAt(AgentSchedule $schedule, ?Carbon $from = null): ?Carbon
    {
        $from = ($from ?? now())->copy();
        $timezone = $schedule->timezone ?: (string) config('app.timezone');

        if ($schedule->schedule_type === 'interval') {
            return $this->nextIntervalRunAt($schedule, $from, $timezone);
        }

        $expression = $this->cronExpression($schedule);

        if ($expression === null) {
            return null;
        }

        try {
            $cron = new CronExpression($expression);
        } catch (InvalidArgumentException) {
            return null;
        }

        $next = $cron->getNextRunDate(
            $from->copy()->timezone($timezone)->toDateTime(),
            0,
            false,
            $timezone,
        );

        return Carbon::instance($next)->utc();
    }

    public function cronExpression(AgentSchedule $schedule): ?string
    {
        $config = is_array($schedule->schedule_config) ? $schedule->schedule_config : [];

        return match ($schedule->schedule_type) {
            'cron' => $this->stringConfigValue($config, 'expression'),
            'daily' => $this->dailyCronExpression($config),
            'weekly' => $this->weeklyCronExpression($config),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function dailyCronExpression(array $config): ?string
    {
        $time = $this->parseTime($this->stringConfigValue($config, 'time'));

        if ($time === null) {
            return null;
        }

        $days = $this->normalizeDaysOfWeek($config['days_of_week'] ?? [1, 2, 3, 4, 5, 6, 7]);

        if ($days === []) {
            return null;
        }

        return sprintf('%d %d * * %s', $time['minute'], $time['hour'], $this->daysOfWeekExpression($days));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function weeklyCronExpression(array $config): ?string
    {
        $time = $this->parseTime($this->stringConfigValue($config, 'time'));

        if ($time === null) {
            return null;
        }

        $day = $config['day_of_week'] ?? null;

        if (! is_int($day) && ! is_string($day)) {
            return null;
        }

        $days = $this->normalizeDaysOfWeek([(int) $day]);

        if ($days === []) {
            return null;
        }

        return sprintf('%d %d * * %s', $time['minute'], $time['hour'], $this->daysOfWeekExpression($days));
    }

    private function nextIntervalRunAt(AgentSchedule $schedule, Carbon $from, string $timezone): ?Carbon
    {
        $config = is_array($schedule->schedule_config) ? $schedule->schedule_config : [];
        $minutes = (int) ($config['every_minutes'] ?? 0);

        if ($minutes < 1) {
            return null;
        }

        return $from->copy()->timezone($timezone)->addMinutes($minutes)->utc();
    }

    /**
     * @param  list<int>  $days
     */
    private function daysOfWeekExpression(array $days): string
    {
        sort($days);

        if ($days === [0, 1, 2, 3, 4, 5, 6]) {
            return '*';
        }

        return implode(',', $days);
    }

    /**
     * @return list<int>
     */
    private function normalizeDaysOfWeek(mixed $rawDays): array
    {
        if (! is_array($rawDays)) {
            return [];
        }

        $days = [];

        foreach ($rawDays as $day) {
            if (! is_int($day) && ! is_string($day)) {
                continue;
            }

            $value = (int) $day;

            if ($value < 0 || $value > 7) {
                continue;
            }

            if ($value === 7) {
                $value = 0;
            }

            $days[] = $value;
        }

        return array_values(array_unique($days));
    }

    /**
     * @return array{hour: int, minute: int}|null
     */
    private function parseTime(?string $time): ?array
    {
        if ($time === null || ! preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches)) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return [
            'hour' => $hour,
            'minute' => $minute,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function stringConfigValue(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
