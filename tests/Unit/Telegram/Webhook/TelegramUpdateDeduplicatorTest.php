<?php

namespace Tests\Unit\Telegram\Webhook;

use App\Telegram\Webhook\TelegramUpdateDeduplicator;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TelegramUpdateDeduplicatorTest extends TestCase
{
    public function test_marks_first_update_as_unique(): void
    {
        Cache::flush();

        $deduplicator = new TelegramUpdateDeduplicator;

        $this->assertFalse($deduplicator->isDuplicate('channel-uuid', 1001));
    }

    public function test_marks_repeated_update_as_duplicate(): void
    {
        Cache::flush();

        $deduplicator = new TelegramUpdateDeduplicator;

        $this->assertFalse($deduplicator->isDuplicate('channel-uuid', 1001));
        $this->assertTrue($deduplicator->isDuplicate('channel-uuid', 1001));
    }
}
