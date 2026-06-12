<?php

namespace App\Neuron\Diary\Contracts;

interface DiaryStorage
{
    public function exists(string $path): bool;

    public function read(string $path): ?string;

    public function put(string $path, string $content): void;

    public function append(string $path, string $content): void;
}
