<?php

namespace App\A2A\Recovery;

enum A2AFailureKind: string
{
    case RATE_LIMITED = 'rate_limited';
    case TIMEOUT = 'timeout';
    case NETWORK = 'network';
    case PROVIDER_UNAVAILABLE = 'provider_unavailable';
    case CONTENT_POLICY = 'content_policy';
    case INVALID_REQUEST = 'invalid_request';
    case AUTH = 'auth';
    case QUOTA_EXHAUSTED = 'quota_exhausted';
    case UNKNOWN = 'unknown';

    public function transient(): bool
    {
        return match ($this) {
            self::RATE_LIMITED,
            self::TIMEOUT,
            self::NETWORK,
            self::PROVIDER_UNAVAILABLE,
            self::QUOTA_EXHAUSTED,
            self::UNKNOWN => true,
            self::CONTENT_POLICY,
            self::INVALID_REQUEST,
            self::AUTH => false,
        };
    }
}
