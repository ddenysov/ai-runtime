<?php

namespace App\Neuron\Diary;

use App\Neuron\Diary\Contracts\DiaryStorage;
use App\Neuron\Diary\Storage\FilesystemDiaryStorage;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

final class DiaryStorageManager
{
    public function driver(?string $driver = null): DiaryStorage
    {
        $driver ??= (string) config('diary.driver', 'filesystem');

        return match ($driver) {
            'filesystem' => new FilesystemDiaryStorage(
                disk: Storage::disk((string) config('diary.filesystem.disk', 'diary')),
                prefix: (string) config('diary.filesystem.prefix', ''),
            ),
            default => throw new InvalidArgumentException("Unsupported diary storage driver [{$driver}]."),
        };
    }
}
