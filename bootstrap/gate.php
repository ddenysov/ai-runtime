<?php

use App\Gate\GateFrontDoor;
use Dotenv\Dotenv;

/**
 * @param  array<string, mixed>  $server
 */
function gateShouldBootstrapApplication(string $basePath, array $server): bool
{
    if (is_file($basePath.'/.env')) {
        Dotenv::createImmutable($basePath)->safeLoad();
    }

    $envEnabled = filter_var(
        $_ENV['GATE_ENABLED'] ?? getenv('GATE_ENABLED') ?: false,
        FILTER_VALIDATE_BOOL,
    );

    $gate = GateFrontDoor::fromDefaults(
        storagePath: $basePath.'/storage/app/gate',
        envEnabled: $envEnabled,
    );

    if ($gate->shouldBootstrapApplication($server)) {
        return true;
    }

    $gate->handleBlockedRequest($server);
}
