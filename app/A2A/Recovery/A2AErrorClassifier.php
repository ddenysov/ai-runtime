<?php

namespace App\A2A\Recovery;

use App\A2A\A2AInvocationLimitExceeded;
use Throwable;

class A2AErrorClassifier
{
    public function classify(Throwable $exception): A2AFailure
    {
        $message = $exception->getMessage();
        $normalized = strtolower($message);
        $statusCode = $this->statusCode($exception);

        $kind = match (true) {
            $exception instanceof A2AInvocationLimitExceeded => A2AFailureKind::INVOCATION_LIMIT,

            $statusCode === 429,
            str_contains($normalized, 'rate limit'),
            str_contains($normalized, 'too many requests'),
            str_contains($normalized, 'throttle') => A2AFailureKind::RATE_LIMITED,

            str_contains($normalized, 'quota') || str_contains($normalized, 'insufficient_quota') => A2AFailureKind::QUOTA_EXHAUSTED,

            $statusCode === 401 || $statusCode === 403,
            str_contains($normalized, 'unauthorized'),
            str_contains($normalized, 'forbidden'),
            str_contains($normalized, 'permission denied'),
            str_contains($normalized, 'api key') => A2AFailureKind::AUTH,

            $statusCode !== null && $statusCode >= 500,
            str_contains($normalized, 'service unavailable'),
            str_contains($normalized, 'temporarily unavailable'),
            str_contains($normalized, 'overloaded'),
            str_contains($normalized, 'server error') => A2AFailureKind::PROVIDER_UNAVAILABLE,

            str_contains($normalized, 'timeout'),
            str_contains($normalized, 'timed out'),
            str_contains($normalized, 'deadline exceeded') => A2AFailureKind::TIMEOUT,

            str_contains($normalized, 'connection refused'),
            str_contains($normalized, 'connection reset'),
            str_contains($normalized, 'network'),
            str_contains($normalized, 'dns') => A2AFailureKind::NETWORK,

            str_contains($normalized, 'content policy'),
            str_contains($normalized, 'prohibited content'),
            str_contains($normalized, 'safety'),
            str_contains($normalized, 'moderation'),
            str_contains($normalized, 'blocked') => A2AFailureKind::CONTENT_POLICY,

            $statusCode !== null && $statusCode >= 400 && $statusCode < 500,
            str_contains($normalized, 'invalid request'),
            str_contains($normalized, 'bad request'),
            str_contains($normalized, 'validation') => A2AFailureKind::INVALID_REQUEST,

            default => A2AFailureKind::UNKNOWN,
        };

        return new A2AFailure(
            kind: $kind,
            message: $message !== '' ? $message : $exception::class,
            retryAfterSeconds: $this->retryAfterSeconds($exception),
            previous: $exception,
        );
    }

    private function statusCode(Throwable $exception): ?int
    {
        $code = $exception->getCode();

        if (is_int($code) && $code >= 400 && $code <= 599) {
            return $code;
        }

        if (method_exists($exception, 'getStatusCode')) {
            $statusCode = $exception->getStatusCode();

            return is_int($statusCode) ? $statusCode : null;
        }

        if (method_exists($exception, 'status')) {
            $status = $exception->status();

            return is_int($status) ? $status : null;
        }

        return null;
    }

    private function retryAfterSeconds(Throwable $exception): ?int
    {
        if (method_exists($exception, 'getRetryAfter')) {
            $retryAfter = $exception->getRetryAfter();

            if (is_int($retryAfter)) {
                return max(1, $retryAfter);
            }
        }

        if (! method_exists($exception, 'getResponse')) {
            return null;
        }

        $response = $exception->getResponse();

        if (! is_object($response) || ! method_exists($response, 'getHeaderLine')) {
            return null;
        }

        $header = $response->getHeaderLine('Retry-After');

        if (is_numeric($header)) {
            return max(1, (int) $header);
        }

        $timestamp = strtotime((string) $header);

        return $timestamp === false ? null : max(1, $timestamp - time());
    }
}
