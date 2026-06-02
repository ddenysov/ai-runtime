<?php

namespace App\Neuron\Dice;

final class DiceNotationParser
{
    private const ALLOWED_SIDES = [4, 6, 8, 10, 12, 20, 100];

    private const GROUP_PATTERN = '/^(\d+)d(\d+|%)(?:(dl|dh|kh|kl)(\d+))?$/';

    /**
     * @return array{
     *     groups: list<array{
     *         count: int,
     *         sides: int,
     *         drop_low: int,
     *         drop_high: int,
     *         keep_high: int,
     *         keep_low: int,
     *         expression: string
     *     }>,
     *     flat_modifier: int
     * }
     */
    public function parse(string $notation): array
    {
        $normalized = strtolower(str_replace(' ', '', trim($notation)));

        if ($normalized === '') {
            throw new InvalidDiceRollException('Invalid notation: notation is required.');
        }

        $flatModifier = 0;
        $dicePart = $normalized;

        if (preg_match('/^(.+?)([+-]\d+)$/', $normalized, $modifierMatch)) {
            $dicePart = $modifierMatch[1];
            $flatModifier = $this->parseSignedInteger($modifierMatch[2]);
        }

        if ($dicePart === '') {
            throw new InvalidDiceRollException('Invalid notation: no dice groups found.');
        }

        $segments = explode('+', $dicePart);
        $groups = [];

        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new InvalidDiceRollException('Invalid notation: unrecognized expression.');
            }

            if (! preg_match(self::GROUP_PATTERN, $segment, $match)) {
                throw new InvalidDiceRollException('Invalid notation: unrecognized expression.');
            }

            $sides = $match[2] === '%' ? 100 : (int) $match[2];

            if (! in_array($sides, self::ALLOWED_SIDES, true)) {
                throw new InvalidDiceRollException("Invalid notation: unsupported die d{$sides}.");
            }

            $groups[] = [
                'count' => (int) $match[1],
                'sides' => $sides,
                'drop_low' => ($match[3] ?? '') === 'dl' ? (int) ($match[4] ?? 0) : 0,
                'drop_high' => ($match[3] ?? '') === 'dh' ? (int) ($match[4] ?? 0) : 0,
                'keep_high' => ($match[3] ?? '') === 'kh' ? (int) ($match[4] ?? 0) : 0,
                'keep_low' => ($match[3] ?? '') === 'kl' ? (int) ($match[4] ?? 0) : 0,
                'expression' => $segment,
            ];
        }

        return [
            'groups' => $groups,
            'flat_modifier' => $flatModifier,
        ];
    }

    private function parseSignedInteger(string $value): int
    {
        if (! preg_match('/^([+-])(\d+)$/', $value, $match)) {
            throw new InvalidDiceRollException('Invalid notation: unrecognized expression.');
        }

        $number = (int) $match[2];

        return $match[1] === '-' ? -$number : $number;
    }
}
