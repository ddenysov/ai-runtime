<?php

namespace App\Neuron\Tools;

use App\Neuron\Dice\DiceRoller;
use App\Neuron\Dice\InvalidDiceRollException;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class RollDiceTool extends Tool
{
    public function __construct(
        private readonly ?DiceRoller $roller = null,
    ) {
        parent::__construct(
            name: 'roll_dice',
            description: 'Roll dice for D&D using standard notation. Always provide a clear reason. Never invent random numbers.',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'notation',
                type: PropertyType::STRING,
                description: 'Dice notation such as 1d20+7, 8d6, or 4d6dl1.',
                required: true,
            ),
            ToolProperty::make(
                name: 'reason',
                type: PropertyType::STRING,
                description: 'Short explanation of what this roll is for (attack, damage, save, etc.).',
                required: true,
            ),
            ToolProperty::make(
                name: 'advantage',
                type: PropertyType::BOOLEAN,
                description: 'Roll 2d20 and keep the highest for a single 1d20 attack/check.',
                required: false,
            ),
            ToolProperty::make(
                name: 'disadvantage',
                type: PropertyType::BOOLEAN,
                description: 'Roll 2d20 and keep the lowest for a single 1d20 attack/check.',
                required: false,
            ),
        ];
    }

    public function __invoke(
        string $notation,
        string $reason,
        bool $advantage = false,
        bool $disadvantage = false,
    ): string {
        try {
            $result = ($this->roller ?? new DiceRoller)->roll(
                notation: $notation,
                reason: $reason,
                advantage: $advantage,
                disadvantage: $disadvantage,
            );

            return json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (InvalidDiceRollException $exception) {
            return json_encode([
                'error' => $exception->getMessage(),
                'reason' => trim($reason) !== '' ? trim($reason) : $exception->reason,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
    }
}
