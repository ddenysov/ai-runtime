<?php

namespace App\A2A\Recovery;

class A2ARetryPolicy
{
    public function shouldRetry(A2AFailure $failure, int $attempt): bool
    {
        return $failure->kind->transient()
            && $attempt <= $this->maxAttempts($failure->kind);
    }

    public function delaySeconds(A2AFailure $failure, int $attempt): int
    {
        if ($failure->retryAfterSeconds !== null) {
            return min($failure->retryAfterSeconds, $this->capSeconds($failure->kind));
        }

        $base = $this->baseSeconds($failure->kind);
        $cap = $this->capSeconds($failure->kind);
        $exponential = min($cap, $base * (2 ** max(0, $attempt - 1)));

        return max(1, random_int($base, max($base, $exponential)));
    }

    public function maxAttempts(A2AFailureKind $kind): int
    {
        return (int) config(
            "runtime-agents.recovery.max_attempts.{$kind->value}",
            match ($kind) {
                A2AFailureKind::RATE_LIMITED => 6,
                A2AFailureKind::TIMEOUT,
                A2AFailureKind::NETWORK,
                A2AFailureKind::PROVIDER_UNAVAILABLE,
                A2AFailureKind::QUOTA_EXHAUSTED => 4,
                A2AFailureKind::UNKNOWN => 2,
                default => 0,
            },
        );
    }

    private function baseSeconds(A2AFailureKind $kind): int
    {
        return (int) config(
            "runtime-agents.recovery.backoff.base_seconds.{$kind->value}",
            match ($kind) {
                A2AFailureKind::RATE_LIMITED,
                A2AFailureKind::QUOTA_EXHAUSTED,
                A2AFailureKind::PROVIDER_UNAVAILABLE => 15,
                A2AFailureKind::TIMEOUT,
                A2AFailureKind::NETWORK => 5,
                default => 3,
            },
        );
    }

    private function capSeconds(A2AFailureKind $kind): int
    {
        return (int) config(
            "runtime-agents.recovery.backoff.cap_seconds.{$kind->value}",
            match ($kind) {
                A2AFailureKind::RATE_LIMITED,
                A2AFailureKind::QUOTA_EXHAUSTED => 1800,
                default => 300,
            },
        );
    }
}
