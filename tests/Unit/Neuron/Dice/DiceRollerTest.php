<?php

namespace Tests\Unit\Neuron\Dice;

use App\Neuron\Dice\DiceRoller;
use App\Neuron\Dice\InvalidDiceRollException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DiceRollerTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $expected
     */
    #[DataProvider('successfulRollProvider')]
    public function test_roll_returns_model_friendly_shape(
        string $notation,
        string $reason,
        array $rolls,
        bool $advantage,
        bool $disadvantage,
        array $expected,
    ): void {
        $result = $this->rollerWithRolls(...$rolls)->roll(
            notation: $notation,
            reason: $reason,
            advantage: $advantage,
            disadvantage: $disadvantage,
        );

        $this->assertSame($reason, $result['reason']);
        $expectedNotation = $expected['notation'] ?? $notation;
        $this->assertSame($expectedNotation, $result['notation']);
        $this->assertSame($expected['result'], $result['result']);
        $this->assertSame($expected['natural_success'], $result['natural_success']);
        $this->assertSame($expected['natural_failure'], $result['natural_failure']);
        $this->assertArrayHasKey('details', $result);
        $this->assertArrayNotHasKey('error', $result);

        foreach ($expected['details'] as $key => $value) {
            $this->assertSame($value, $result['details'][$key], "details.{$key} mismatch");
        }
    }

    /**
     * @return iterable<string, array{string, string, list<int>, bool, bool, array<string, mixed>}>
     */
    public static function successfulRollProvider(): iterable
    {
        yield 'simple d20 attack with modifier' => [
            '1d20+7',
            'Attack roll: longsword vs goblin',
            [12],
            false,
            false,
            [
                'result' => 19,
                'natural_success' => false,
                'natural_failure' => false,
                'details' => [
                    'modifier' => 7,
                    'dice_total' => 12,
                    'advantage' => false,
                    'disadvantage' => false,
                ],
            ],
        ];

        yield 'natural 20 on d20' => [
            '1d20',
            'Attack roll: critical check',
            [20],
            false,
            false,
            [
                'result' => 20,
                'natural_success' => true,
                'natural_failure' => false,
                'details' => [
                    'modifier' => 0,
                    'dice_total' => 20,
                ],
            ],
        ];

        yield 'natural 1 on d20' => [
            '1d20+5',
            'Saving throw: Dexterity',
            [1],
            false,
            false,
            [
                'result' => 6,
                'natural_success' => false,
                'natural_failure' => true,
                'details' => [
                    'modifier' => 5,
                    'dice_total' => 1,
                ],
            ],
        ];

        yield 'damage roll without natural flags' => [
            '2d6+3',
            'Damage: greatsword',
            [4, 2],
            false,
            false,
            [
                'result' => 9,
                'natural_success' => false,
                'natural_failure' => false,
                'details' => [
                    'modifier' => 3,
                    'dice_total' => 6,
                ],
            ],
        ];

        yield 'many dice fireball' => [
            '8d6',
            'Damage: fireball',
            [4, 6, 3, 5, 2, 6, 1, 1],
            false,
            false,
            [
                'result' => 28,
                'natural_success' => false,
                'natural_failure' => false,
                'details' => [
                    'modifier' => 0,
                    'dice_total' => 28,
                ],
            ],
        ];

        yield '4d6 drop lowest for ability score' => [
            '4d6dl1',
            'Ability score: Strength',
            [4, 3, 2, 1],
            false,
            false,
            [
                'result' => 9,
                'natural_success' => false,
                'natural_failure' => false,
                'details' => [
                    'modifier' => 0,
                    'dice_total' => 9,
                ],
            ],
        ];

        yield 'advantage keeps higher d20' => [
            '1d20+7',
            'Attack roll: longsword with advantage',
            [20, 11],
            true,
            false,
            [
                'result' => 27,
                'natural_success' => true,
                'natural_failure' => false,
                'details' => [
                    'modifier' => 7,
                    'dice_total' => 20,
                    'advantage' => true,
                    'disadvantage' => false,
                ],
            ],
        ];

        yield 'disadvantage keeps lower d20' => [
            '1d20+7',
            'Attack roll: longsword with disadvantage',
            [18, 1],
            false,
            true,
            [
                'result' => 8,
                'natural_success' => false,
                'natural_failure' => true,
                'details' => [
                    'modifier' => 7,
                    'dice_total' => 1,
                    'advantage' => false,
                    'disadvantage' => true,
                ],
            ],
        ];

        yield 'negative flat modifier' => [
            '1d20-1',
            'Attack roll: cursed blade',
            [15],
            false,
            false,
            [
                'result' => 14,
                'natural_success' => false,
                'natural_failure' => false,
                'details' => [
                    'modifier' => -1,
                    'dice_total' => 15,
                ],
            ],
        ];

        yield 'percent die alias' => [
            '1d%',
            'Random table: encounter',
            [42],
            false,
            false,
            [
                'result' => 42,
                'natural_success' => false,
                'natural_failure' => false,
                'details' => [
                    'modifier' => 0,
                    'dice_total' => 42,
                ],
            ],
        ];

        yield 'multiple dice groups in one notation' => [
            '1d20+1d4+2',
            'Attack roll: flame tongue',
            [14, 3],
            false,
            false,
            [
                'result' => 19,
                'natural_success' => false,
                'natural_failure' => false,
                'details' => [
                    'modifier' => 2,
                    'dice_total' => 17,
                ],
            ],
        ];

        yield 'keep highest in notation' => [
            '2d20kh1+5',
            'Attack roll: elven accuracy style',
            [15, 8],
            false,
            false,
            [
                'result' => 20,
                'natural_success' => false,
                'natural_failure' => false,
                'details' => [
                    'modifier' => 5,
                    'dice_total' => 15,
                ],
            ],
        ];

        yield 'whitespace and uppercase notation' => [
            ' 1D20 + 7 ',
            'Attack roll: normalized input',
            [10],
            false,
            false,
            [
                'result' => 17,
                'natural_success' => false,
                'natural_failure' => false,
                'notation' => '1d20+7',
                'details' => [
                    'modifier' => 7,
                    'dice_total' => 10,
                ],
            ],
        ];
    }

    public function test_roll_includes_groups_and_summary_in_details(): void
    {
        $result = $this->rollerWithRolls(12)->roll(
            notation: '1d20+7',
            reason: 'Attack roll: longsword',
        );

        $this->assertArrayHasKey('groups', $result['details']);
        $this->assertCount(1, $result['details']['groups']);
        $this->assertSame('1d20', $result['details']['groups'][0]['expression']);
        $this->assertSame([12], $result['details']['groups'][0]['rolled']);
        $this->assertSame([12], $result['details']['groups'][0]['kept']);
        $this->assertSame([], $result['details']['groups'][0]['dropped']);
        $this->assertStringContainsString('1d20+7', $result['details']['summary']);
        $this->assertStringContainsString('19', $result['details']['summary']);
    }

    public function test_advantage_details_show_both_rolls(): void
    {
        $result = $this->rollerWithRolls(14, 7)->roll(
            notation: '1d20+7',
            reason: 'Attack roll: advantage',
            advantage: true,
        );

        $group = $result['details']['groups'][0];
        $this->assertSame([14, 7], $group['rolled']);
        $this->assertSame([14], $group['kept']);
        $this->assertSame([7], $group['dropped']);
    }

    public function test_drop_lowest_details_show_dropped_values(): void
    {
        $result = $this->rollerWithRolls(4, 3, 2, 1)->roll(
            notation: '4d6dl1',
            reason: 'Ability score: Strength',
        );

        $group = $result['details']['groups'][0];
        $this->assertSame([4, 3, 2, 1], $group['rolled']);
        $this->assertSame([4, 3, 2], $group['kept']);
        $this->assertSame([1], $group['dropped']);
    }

    #[DataProvider('invalidRollProvider')]
    public function test_roll_throws_on_invalid_input(
        string $notation,
        string $reason,
        bool $advantage,
        bool $disadvantage,
        string $expectedMessage,
    ): void {
        $this->expectException(InvalidDiceRollException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->rollerWithRolls(10)->roll(
            notation: $notation,
            reason: $reason,
            advantage: $advantage,
            disadvantage: $disadvantage,
        );
    }

    /**
     * @return iterable<string, array{string, string, bool, bool, string}>
     */
    public static function invalidRollProvider(): iterable
    {
        yield 'reason too short' => ['1d20+5', 'ab', false, false, 'reason'];
        yield 'reason empty' => ['1d20+5', '   ', false, false, 'reason'];
        yield 'empty notation' => ['', 'Attack roll', false, false, 'notation'];
        yield 'invalid notation' => ['not-dice', 'Attack roll', false, false, 'notation'];
        yield 'unsupported die' => ['1d7', 'Attack roll', false, false, 'd7'];
        yield 'advantage and disadvantage together' => ['1d20+5', 'Attack roll', true, true, 'advantage'];
        yield 'advantage on non d20' => ['2d6+3', 'Damage roll', true, false, 'advantage'];
        yield 'too many dice' => ['101d6', 'Damage roll', false, false, 'dice'];
    }

    /**
     * @param  list<int>  $rolls
     */
    private function rollerWithRolls(int ...$rolls): DiceRoller
    {
        $queue = $rolls;

        return new DiceRoller(
            randomInt: static function (int $min, int $max) use (&$queue): int {
                self::assertNotEmpty($queue, 'Unexpected random_int call');

                $value = array_shift($queue);

                self::assertGreaterThanOrEqual($min, $value);
                self::assertLessThanOrEqual($max, $value);

                return $value;
            },
        );
    }
}
