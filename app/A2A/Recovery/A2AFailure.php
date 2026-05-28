<?php

namespace App\A2A\Recovery;

use Throwable;

final readonly class A2AFailure
{
    public function __construct(
        public A2AFailureKind $kind,
        public string $message,
        public ?int $retryAfterSeconds = null,
        public ?Throwable $previous = null,
    ) {}

    /**
     * @return array{kind: string, message: string, retry_after_seconds: int|null}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'message' => $this->message,
            'retry_after_seconds' => $this->retryAfterSeconds,
        ];
    }
}
