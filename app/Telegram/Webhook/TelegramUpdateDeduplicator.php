<?php

namespace App\Telegram\Webhook;

use Illuminate\Support\Facades\Cache;

final class TelegramUpdateDeduplicator
{
    public function isDuplicate(string $scope, int $updateId): bool
    {
        $key = "telegram:update:{$scope}:{$updateId}";

        return ! Cache::add($key, 1, now()->addDay());
    }
}
