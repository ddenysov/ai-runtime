<?php

namespace App\Neuron\Diary\Storage;

use App\Neuron\Diary\Contracts\DiaryStorage;
use Illuminate\Contracts\Filesystem\Filesystem;

final class FilesystemDiaryStorage implements DiaryStorage
{
    public function __construct(
        private readonly Filesystem $disk,
        private readonly string $prefix = '',
    ) {}

    public function exists(string $path): bool
    {
        return $this->disk->exists($this->qualifyPath($path));
    }

    public function read(string $path): ?string
    {
        $qualifiedPath = $this->qualifyPath($path);

        if (! $this->disk->exists($qualifiedPath)) {
            return null;
        }

        return $this->disk->get($qualifiedPath);
    }

    public function put(string $path, string $content): void
    {
        $this->disk->put($this->qualifyPath($path), $content);
    }

    public function append(string $path, string $content): void
    {
        $qualifiedPath = $this->qualifyPath($path);

        if ($this->disk->exists($qualifiedPath)) {
            $existing = $this->disk->get($qualifiedPath);
            $this->disk->put($qualifiedPath, $existing.$content);

            return;
        }

        $this->disk->put($qualifiedPath, $content);
    }

    private function qualifyPath(string $path): string
    {
        $path = ltrim($path, '/');

        if ($this->prefix === '') {
            return $path;
        }

        return rtrim($this->prefix, '/').'/'.$path;
    }
}
