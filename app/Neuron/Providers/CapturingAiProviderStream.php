<?php

namespace App\Neuron\Providers;

use Closure;
use NeuronAI\HttpClient\StreamInterface;

class CapturingAiProviderStream implements StreamInterface
{
    public function __construct(
        private readonly StreamInterface $inner,
        private readonly Closure $capture,
    ) {}

    public function eof(): bool
    {
        return $this->inner->eof();
    }

    public function read(int $length): string
    {
        return $this->captureChunk($this->inner->read($length));
    }

    public function readLine(): string
    {
        return $this->captureChunk($this->inner->readLine());
    }

    public function close(): void
    {
        $this->inner->close();
    }

    private function captureChunk(string $chunk): string
    {
        if ($chunk !== '') {
            ($this->capture)($chunk);
        }

        return $chunk;
    }
}
