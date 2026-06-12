<?php

namespace Tests\Unit\Neuron\Diary;

use App\Neuron\Diary\DiaryService;
use App\Neuron\Diary\Exceptions\InvalidDiaryDateException;
use App\Neuron\Diary\Storage\FilesystemDiaryStorage;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class DiaryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('diary-test');
        config([
            'app.timezone' => 'UTC',
            'diary.timezone' => 'UTC',
        ]);
    }

    public function test_write_creates_daily_markdown_file(): void
    {
        $service = $this->service();

        $this->travelTo('2026-06-12 09:15:00');

        $result = $service->write('First entry for the day.');

        $this->assertTrue($result['ok']);
        $this->assertSame('2026-06-12', $result['date']);
        $this->assertSame('09:15', $result['time']);
        $this->assertSame('2026-06-12.md', $result['file']);
        $this->assertTrue($result['created']);

        Storage::disk('diary-test')->assertExists('2026-06-12.md');
        $this->assertSame(
            "# 2026-06-12\n\n## 09:15\n\nFirst entry for the day.\n",
            Storage::disk('diary-test')->get('2026-06-12.md'),
        );
    }

    public function test_write_appends_additional_entries_on_the_same_day(): void
    {
        $service = $this->service();

        $this->travelTo('2026-06-12 09:15:00');
        $service->write('Morning note.');

        $this->travelTo('2026-06-12 14:30:00');
        $result = $service->write('Afternoon note.');

        $this->assertFalse($result['created']);
        $this->assertSame('14:30', $result['time']);

        $this->assertSame(
            "# 2026-06-12\n\n## 09:15\n\nMorning note.\n\n## 14:30\n\nAfternoon note.\n",
            Storage::disk('diary-test')->get('2026-06-12.md'),
        );
    }

    public function test_write_rejects_empty_text(): void
    {
        $service = $this->service();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Diary entry text must not be empty.');

        $service->write('   ');
    }

    public function test_read_returns_today_by_default(): void
    {
        $service = $this->service();

        $this->travelTo('2026-06-12 10:00:00');
        $service->write('Today only.');

        $result = $service->read();

        $this->assertSame('2026-06-12', $result['date']);
        $this->assertFalse($result['empty']);
        $this->assertStringContainsString('Today only.', (string) $result['content']);
    }

    public function test_read_returns_empty_payload_when_file_is_missing(): void
    {
        $service = $this->service();

        $result = $service->read('2026-01-01');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['empty']);
        $this->assertNull($result['content']);
    }

    public function test_read_rejects_invalid_date_format(): void
    {
        $service = $this->service();

        $this->expectException(InvalidDiaryDateException::class);

        $service->read('12-06-2026');
    }

    public function test_storage_prefix_is_applied_for_remote_like_paths(): void
    {
        $service = new DiaryService(
            storage: new FilesystemDiaryStorage(
                disk: Storage::disk('diary-test'),
                prefix: 'users/me',
            ),
            timezone: 'UTC',
        );

        $this->travelTo('2026-06-12 09:00:00');
        $service->write('Prefixed entry.');

        Storage::disk('diary-test')->assertExists('users/me/2026-06-12.md');
    }

    private function service(): DiaryService
    {
        return new DiaryService(
            storage: new FilesystemDiaryStorage(Storage::disk('diary-test')),
            timezone: 'UTC',
        );
    }
}
