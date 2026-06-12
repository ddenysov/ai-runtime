<?php

namespace Tests\Unit\Neuron\Tools;

use App\Neuron\Diary\DiaryService;
use App\Neuron\Diary\Storage\FilesystemDiaryStorage;
use App\Neuron\Tools\DiaryReadTool;
use App\Neuron\Tools\DiaryWriteTool;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DiaryToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('diary-test');
    }

    public function test_diary_write_tool_returns_success_payload(): void
    {
        $tool = new DiaryWriteTool($this->service());

        $this->travelTo('2026-06-12 11:00:00');

        $payload = json_decode($tool(text: 'Tool write test.'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['ok']);
        $this->assertSame('2026-06-12', $payload['date']);
        $this->assertSame('11:00', $payload['time']);
    }

    public function test_diary_read_tool_returns_content_for_requested_date(): void
    {
        $service = $this->service();
        $writeTool = new DiaryWriteTool($service);
        $readTool = new DiaryReadTool($service);

        $this->travelTo('2026-06-12 11:00:00');
        $writeTool(text: 'Readable entry.');

        $payload = json_decode($readTool(date: '2026-06-12'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['empty']);
        $this->assertStringContainsString('Readable entry.', (string) $payload['content']);
    }

    public function test_diary_read_tool_returns_error_for_invalid_date(): void
    {
        $readTool = new DiaryReadTool($this->service());

        $payload = json_decode($readTool(date: 'not-a-date'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('error', $payload);
    }

    private function service(): DiaryService
    {
        return new DiaryService(
            storage: new FilesystemDiaryStorage(Storage::disk('diary-test')),
            timezone: 'UTC',
        );
    }
}
