<?php

namespace App\Neuron\Diary;

use App\Neuron\Diary\Contracts\DiaryStorage;
use App\Neuron\Diary\Exceptions\InvalidDiaryDateException;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class DiaryService
{
    public function __construct(
        private readonly DiaryStorage $storage,
        private readonly ?string $timezone = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function write(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            throw new InvalidArgumentException('Diary entry text must not be empty.');
        }

        $now = $this->now();
        $date = $now->format('Y-m-d');
        $time = $now->format('H:i');
        $path = $this->pathForDate($date);
        $section = "## {$time}\n\n{$text}\n";
        $created = ! $this->storage->exists($path);

        if ($created) {
            $this->storage->put($path, "# {$date}\n\n{$section}");
        } else {
            $this->storage->append($path, "\n{$section}");
        }

        return [
            'ok' => true,
            'date' => $date,
            'time' => $time,
            'file' => $path,
            'created' => $created,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function read(?string $date = null): array
    {
        $resolvedDate = $this->resolveDate($date);
        $path = $this->pathForDate($resolvedDate);

        if (! $this->storage->exists($path)) {
            return [
                'ok' => true,
                'date' => $resolvedDate,
                'file' => $path,
                'content' => null,
                'empty' => true,
            ];
        }

        return [
            'ok' => true,
            'date' => $resolvedDate,
            'file' => $path,
            'content' => $this->storage->read($path),
            'empty' => false,
        ];
    }

    private function now(): CarbonImmutable
    {
        $timezone = $this->timezone ?? (string) config('app.timezone', 'UTC');

        return CarbonImmutable::now($timezone);
    }

    private function resolveDate(?string $date): string
    {
        if ($date === null || trim($date) === '') {
            return $this->now()->format('Y-m-d');
        }

        $date = trim($date);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidDiaryDateException('Diary date must use YYYY-MM-DD format.');
        }

        [$year, $month, $day] = array_map(intval(...), explode('-', $date));

        if (! checkdate($month, $day, $year)) {
            throw new InvalidDiaryDateException("Diary date [{$date}] is not a valid calendar date.");
        }

        return $date;
    }

    private function pathForDate(string $date): string
    {
        return "{$date}.md";
    }
}
