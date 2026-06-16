<?php

namespace App\Gate;

final class GateState
{
    public function __construct(
        private readonly string $storagePath,
    ) {}

    public static function make(string $storagePath): self
    {
        return new self(rtrim($storagePath, '/'));
    }

    public function isOpen(): bool
    {
        $openUntil = $this->readTimestamp('open_until');

        return $openUntil !== null && $openUntil > time();
    }

    public function openForSeconds(int $seconds): void
    {
        $this->ensureStorageDirectory();

        file_put_contents(
            $this->path('open_until'),
            (string) (time() + max(1, $seconds)),
            LOCK_EX,
        );
    }

    public function close(): void
    {
        $path = $this->path('open_until');

        if (is_file($path)) {
            unlink($path);
        }
    }

    public function shouldNotify(int $cooldownSeconds): bool
    {
        $lastNotifiedAt = $this->readTimestamp('last_notified_at');

        if ($lastNotifiedAt === null) {
            return true;
        }

        return (time() - $lastNotifiedAt) >= max(1, $cooldownSeconds);
    }

    public function markNotified(): void
    {
        $this->ensureStorageDirectory();

        file_put_contents(
            $this->path('last_notified_at'),
            (string) time(),
            LOCK_EX,
        );
    }

    private function readTimestamp(string $filename): ?int
    {
        $path = $this->path($filename);

        if (! is_file($path)) {
            return null;
        }

        $value = trim((string) file_get_contents($path));

        if ($value === '' || ! ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    private function path(string $filename): string
    {
        return $this->storagePath.'/'.$filename;
    }

    private function ensureStorageDirectory(): void
    {
        if (! is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
    }
}
