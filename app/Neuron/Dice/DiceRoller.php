<?php

namespace App\Neuron\Dice;

final class DiceRoller
{
    private const MIN_REASON_LENGTH = 3;

    private const MAX_DICE_PER_GROUP = 100;

    private const MIN_DIFFICULTY = 1;

    private const MAX_DIFFICULTY = 30;

    /**
     * @param  (callable(int, int): int)|null  $randomInt
     */
    public function __construct(
        private $randomInt = null,
        private readonly DiceNotationParser $parser = new DiceNotationParser,
        private readonly int $maxDicePerGroup = self::MAX_DICE_PER_GROUP,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function roll(
        string $notation,
        string $reason,
        bool $advantage = false,
        bool $disadvantage = false,
        ?int $difficulty = null,
        ?RollKind $rollKind = null,
    ): array {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw new InvalidDiceRollException(
                'Invalid roll: reason must be at least '.self::MIN_REASON_LENGTH.' characters.',
                $reason,
            );
        }

        if ($advantage && $disadvantage) {
            throw new InvalidDiceRollException(
                'Invalid roll: advantage and disadvantage cannot be used together.',
                $reason,
            );
        }

        $this->validateDifficultyAndRollKind($difficulty, $rollKind, $reason);

        $parsed = $this->parser->parse($notation);

        if ($difficulty !== null && ! $this->notationIncludesD20($parsed['groups'])) {
            throw new InvalidDiceRollException(
                'Invalid roll: difficulty only applies to rolls that include a d20.',
                $reason,
            );
        }
        $advantageApplies = $this->advantageApplies($parsed['groups'], $advantage, $disadvantage);

        if (($advantage || $disadvantage) && ! $advantageApplies) {
            throw new InvalidDiceRollException(
                'Invalid roll: advantage and disadvantage only apply to a single 1d20 roll.',
                $reason,
            );
        }

        $groupResults = [];
        $diceTotal = 0;

        $primaryD20Index = $this->primaryD20GroupIndex($parsed['groups']);

        foreach ($parsed['groups'] as $index => $group) {
            if ($group['count'] > $this->maxDicePerGroup) {
                throw new InvalidDiceRollException(
                    "Invalid roll: cannot roll more than {$this->maxDicePerGroup} dice at once.",
                    $reason,
                );
            }

            $useAdvantage = $advantageApplies && $index === $primaryD20Index;
            $groupResult = $this->rollGroup($group, $advantage && $useAdvantage, $disadvantage && $useAdvantage);
            $groupResults[] = $groupResult;
            $diceTotal += $groupResult['subtotal'];
        }

        $result = $diceTotal + $parsed['flat_modifier'];
        $natural = $this->resolveNaturalFlags($groupResults);
        $effectiveRollKind = $rollKind ?? RollKind::Check;

        $details = [
            'summary' => $this->buildSummary(
                $notation,
                $groupResults,
                $parsed['flat_modifier'],
                $result,
                $advantage,
                $disadvantage,
            ),
            'modifier' => $parsed['flat_modifier'],
            'dice_total' => $diceTotal,
            'advantage' => $advantage,
            'disadvantage' => $disadvantage,
            'groups' => array_map(
                static fn (array $group): array => [
                    'expression' => $group['expression'],
                    'rolled' => $group['rolled'],
                    'kept' => $group['kept'],
                    'dropped' => $group['dropped'],
                    'subtotal' => $group['subtotal'],
                ],
                $groupResults,
            ),
        ];

        $payload = [
            'reason' => $reason,
            'notation' => $this->buildNotation($parsed),
            'result' => $result,
            'natural_success' => $natural['natural_success'],
            'natural_failure' => $natural['natural_failure'],
            'details' => $details,
        ];

        if ($difficulty !== null) {
            $payload['difficulty'] = $difficulty;
            $payload['roll_kind'] = $effectiveRollKind->value;
            $payload['success'] = $this->resolveSuccess(
                $result,
                $difficulty,
                $effectiveRollKind,
                $natural,
            );
        }

        return $payload;
    }

    private function validateDifficultyAndRollKind(?int $difficulty, ?RollKind $rollKind, string $reason): void
    {
        if ($difficulty === null) {
            return;
        }

        if ($difficulty < self::MIN_DIFFICULTY || $difficulty > self::MAX_DIFFICULTY) {
            throw new InvalidDiceRollException(
                'Invalid roll: difficulty must be between '.self::MIN_DIFFICULTY.' and '.self::MAX_DIFFICULTY.'.',
                $reason,
            );
        }

    }

    /**
     * @param  list<array{count: int, sides: int}>  $groups
     */
    private function notationIncludesD20(array $groups): bool
    {
        foreach ($groups as $group) {
            if ($group['sides'] === 20) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{natural_success: bool, natural_failure: bool}  $natural
     */
    private function resolveSuccess(int $result, int $difficulty, RollKind $rollKind, array $natural): bool
    {
        if ($rollKind === RollKind::Attack) {
            if ($natural['natural_failure']) {
                return false;
            }

            if ($natural['natural_success']) {
                return true;
            }
        }

        return $result >= $difficulty;
    }

    /**
     * @param  list<array{
     *     count: int,
     *     sides: int,
     *     drop_low: int,
     *     drop_high: int,
     *     keep_high: int,
     *     keep_low: int,
     *     expression: string
     * }>  $groups
     */
    private function advantageApplies(array $groups, bool $advantage, bool $disadvantage): bool
    {
        if (! $advantage && ! $disadvantage) {
            return false;
        }

        return $this->primaryD20GroupIndex($groups) !== null;
    }

    /**
     * @param  list<array{count: int, sides: int, drop_low: int, drop_high: int, keep_high: int, keep_low: int, expression: string}>  $groups
     */
    private function primaryD20GroupIndex(array $groups): ?int
    {
        foreach ($groups as $index => $group) {
            if ($group['sides'] === 20 && $group['count'] === 1 && ! $this->groupHasRerollModifiers($group)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array{count: int, sides: int, drop_low: int, drop_high: int, keep_high: int, keep_low: int, expression: string}  $group
     * @return array{expression: string, rolled: list<int>, kept: list<int>, dropped: list<int>, subtotal: int}
     */
    private function rollGroup(array $group, bool $advantage, bool $disadvantage): array
    {
        $diceToRoll = $group['count'];

        if ($advantage || $disadvantage) {
            $diceToRoll = 2;
        }

        $rolled = [];

        for ($i = 0; $i < $diceToRoll; $i++) {
            $rolled[] = $this->rollDie($group['sides']);
        }

        if ($advantage || $disadvantage) {
            $sorted = $rolled;

            if ($advantage) {
                rsort($sorted);
            } else {
                sort($sorted);
            }

            $kept = [array_shift($sorted)];
            $dropped = $sorted;

            return [
                'expression' => $group['expression'],
                'rolled' => $rolled,
                'kept' => $kept,
                'dropped' => $dropped,
                'subtotal' => array_sum($kept),
            ];
        }

        [$kept, $dropped] = $this->applyKeepDrop($rolled, $group);

        return [
            'expression' => $group['expression'],
            'rolled' => $rolled,
            'kept' => $kept,
            'dropped' => $dropped,
            'subtotal' => array_sum($kept),
        ];
    }

    /**
     * @param  list<int>  $rolled
     * @param  array{drop_low: int, drop_high: int, keep_high: int, keep_low: int}  $group
     * @return array{0: list<int>, 1: list<int>}
     */
    private function applyKeepDrop(array $rolled, array $group): array
    {
        $dropIndices = [];

        if ($group['drop_low'] > 0) {
            $dropIndices = array_merge(
                $dropIndices,
                $this->indicesToDrop($rolled, $group['drop_low'], ascending: true),
            );
        }

        if ($group['drop_high'] > 0) {
            $dropIndices = array_merge(
                $dropIndices,
                $this->indicesToDrop($rolled, $group['drop_high'], ascending: false),
            );
        }

        $keptIndices = array_values(array_diff(array_keys($rolled), array_unique($dropIndices)));
        $kept = array_map(static fn (int $index): int => $rolled[$index], $keptIndices);
        $dropped = array_map(static fn (int $index): int => $rolled[$index], array_unique($dropIndices));

        if ($group['keep_high'] > 0) {
            [$kept, $dropped] = $this->applyKeepFromPool($kept, $dropped, $group['keep_high'], keepHigh: true);
        }

        if ($group['keep_low'] > 0) {
            [$kept, $dropped] = $this->applyKeepFromPool($kept, $dropped, $group['keep_low'], keepHigh: false);
        }

        return [array_values($kept), array_values($dropped)];
    }

    /**
     * @param  list<int>  $rolled
     * @return list<int>
     */
    private function indicesToDrop(array $rolled, int $count, bool $ascending): array
    {
        $indices = array_keys($rolled);

        usort($indices, function (int $left, int $right) use ($rolled, $ascending): int {
            $comparison = $rolled[$left] <=> $rolled[$right];

            return $ascending ? $comparison : -$comparison;
        });

        return array_slice($indices, 0, $count);
    }

    /**
     * @param  list<int>  $kept
     * @param  list<int>  $dropped
     * @return array{0: list<int>, 1: list<int>}
     */
    private function applyKeepFromPool(array $kept, array $dropped, int $count, bool $keepHigh): array
    {
        $pool = $kept;
        $sorted = $pool;

        if ($keepHigh) {
            rsort($sorted);
        } else {
            sort($sorted);
        }

        $selected = array_slice($sorted, 0, $count);
        $remaining = $pool;

        foreach ($selected as $value) {
            $index = array_search($value, $remaining, true);

            if ($index !== false) {
                unset($remaining[$index]);
            }
        }

        return [
            array_values($selected),
            array_values([...$dropped, ...array_values($remaining)]),
        ];
    }

    /**
     * @param  list<array{expression: string, rolled: list<int>, kept: list<int>, dropped: list<int>, subtotal: int}>  $groups
     * @return array{natural_success: bool, natural_failure: bool}
     */
    private function resolveNaturalFlags(array $groups): array
    {
        foreach ($groups as $group) {
            if (! str_contains($group['expression'], 'd20')) {
                continue;
            }

            if (count($group['kept']) !== 1) {
                continue;
            }

            $value = $group['kept'][0];

            return [
                'natural_success' => $value === 20,
                'natural_failure' => $value === 1,
            ];
        }

        return [
            'natural_success' => false,
            'natural_failure' => false,
        ];
    }

    /**
     * @param  list<array{expression: string, rolled: list<int>, kept: list<int>, dropped: list<int>, subtotal: int}>  $groups
     */
    private function buildSummary(
        string $notation,
        array $groups,
        int $flatModifier,
        int $result,
        bool $advantage,
        bool $disadvantage,
    ): string {
        $parts = [];

        foreach ($groups as $group) {
            $segment = $group['expression'].': ['.implode(', ', $group['rolled']).']';

            if ($group['dropped'] !== []) {
                $segment .= ', kept ['.implode(', ', $group['kept']).'], dropped ['.implode(', ', $group['dropped']).']';
            }

            $parts[] = $segment;
        }

        $summary = trim($notation).': '.implode('; ', $parts);

        if ($advantage) {
            $summary .= ' (advantage)';
        } elseif ($disadvantage) {
            $summary .= ' (disadvantage)';
        }

        if ($flatModifier !== 0) {
            $summary .= ', modifier '.($flatModifier > 0 ? '+' : '').$flatModifier;
        }

        return $summary.' = '.$result;
    }

    /**
     * @param  array{count: int, sides: int, drop_low: int, drop_high: int, keep_high: int, keep_low: int}  $group
     */
    private function groupHasRerollModifiers(array $group): bool
    {
        return $group['drop_low'] > 0
            || $group['drop_high'] > 0
            || $group['keep_high'] > 0
            || $group['keep_low'] > 0;
    }

    private function rollDie(int $sides): int
    {
        if ($this->randomInt !== null) {
            return ($this->randomInt)(1, $sides);
        }

        return random_int(1, $sides);
    }

    /**
     * @param  array{groups: list<array{expression: string}>, flat_modifier: int}  $parsed
     */
    private function buildNotation(array $parsed): string
    {
        $notation = implode('+', array_map(
            static fn (array $group): string => $group['expression'],
            $parsed['groups'],
        ));

        $modifier = $parsed['flat_modifier'];

        if ($modifier > 0) {
            return $notation.'+'.$modifier;
        }

        if ($modifier < 0) {
            return $notation.$modifier;
        }

        return $notation;
    }
}
