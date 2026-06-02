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
        $this->assertArrayNotHasKey('success', $payload);
    }

    public function test_invoke_includes_success_when_difficulty_is_set(): void
    {
        $tool = new RollDiceTool(new DiceRoller(
            randomInt: static fn (int $min, int $max): int => 12,
        ));

        $json = $tool(
            notation: '1d20+7',
            reason: 'Attack roll: longsword',
            difficulty: 15,
            roll_kind: 'attack',
        );
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(15, $payload['difficulty']);
        $this->assertSame('attack', $payload['roll_kind']);
        $this->assertTrue($payload['success']);
        $this->assertSame(19, $payload['result']);
    }

    public function test_invoke_returns_error_for_invalid_roll_kind(): void
    {
        $tool = new RollDiceTool(new DiceRoller(
            randomInt: static fn (int $min, int $max): int => 10,
        ));

        $json = $tool(
            notation: '1d20+5',
            reason: 'Attack roll: goblin',
            difficulty: 12,
            roll_kind: 'critical',
        );
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('error', $payload);
        $this->assertStringContainsString('roll_kind', $payload['error']);
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

    public function test_execute_treats_null_optional_flags_as_false(): void
    {
        $rolls = [14, 7];

        $tool = new RollDiceTool(new DiceRoller(
            randomInt: static function (int $min, int $max) use (&$rolls): int {
                return array_shift($rolls);
            },
        ));

        $tool->setInputs([
            'notation' => '1d20+4',
            'reason' => 'Ability check with advantage',
            'advantage' => true,
            'disadvantage' => null,
        ]);

        $tool->execute();

        $payload = json_decode($tool->getResult(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(18, $payload['result']);
        $this->assertTrue($payload['details']['advantage']);
        $this->assertFalse($payload['details']['disadvantage']);
    }

    public function test_tool_exposes_required_properties(): void
    {
        $tool = new RollDiceTool;

        $this->assertSame(['notation', 'reason'], $tool->getRequiredProperties());
    }
}
