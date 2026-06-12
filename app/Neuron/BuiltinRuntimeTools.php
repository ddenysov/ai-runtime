<?php

namespace App\Neuron;

final class BuiltinRuntimeTools
{
    /**
     * @var list<string>
     */
    public const SLUGS = [
        'remote_a2a_agent',
        'get_agent_card',
        'roll_dice',
        'state_create',
        'state_update',
        'state_delete',
        'state_list',
        'state_get',
        'diary_write',
        'diary_read',
    ];

    public static function isBuiltin(string $slug): bool
    {
        return in_array($slug, self::SLUGS, true);
    }
}
