<?php

namespace Tests\Unit\Neuron\Tools;

use App\Neuron\Dice\DiceRoller;
use App\Neuron\Tools\RollDiceTool;
use PHPUnit\Framework\TestCase;

class RollDiceToolTest extends TestCase
{
    public function test_invoke_returns_json_with_top_level_fields(): void
    {
        $tool = new RollDiceTool(new DiceRoller(
            randomInt: static fn (int $min, int $max): int => 12,
        ));

        $json = $tool(notation: '1d20+7', reason: 'Attack roll: longsword');
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('Attack roll: longsword', $payload['reason']);
        $this->assertSame('1d20+7', $payload['notation']);
        $this->assertSame(19, $payload['result']);
        $this->assertFalse($payload['natural_success']);
        $this->assertFalse($payload['natural_failure']);
        $this->assertArrayHasKey('details', $payload);
    }

    public function test_invoke_returns_error_json_instead_of_throwing(): void
    {
        $tool = new RollDiceTool(new DiceRoller(
            randomInt: static fn (int $min, int $max): int => 10,
        ));

        $json = $tool(notation: '1d20+5', reason: 'no');
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('error', $payload);
        $this->assertSame('no', $payload['reason']);
        $this->assertArrayNotHasKey('result', $payload);
    }

    public function test_tool_exposes_required_properties(): void
    {
        $tool = new RollDiceTool;

        $this->assertSame(['notation', 'reason'], $tool->getRequiredProperties());
    }
}
