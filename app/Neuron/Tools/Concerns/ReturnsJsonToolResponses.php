<?php

namespace App\Neuron\Tools\Concerns;

use Throwable;

trait ReturnsJsonToolResponses
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function success(array $payload): string
    {
        return $this->json($payload);
    }

    private function failure(Throwable $exception): string
    {
        return $this->json([
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
