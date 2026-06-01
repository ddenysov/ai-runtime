<?php

namespace App\Mcp\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'uuid',
    'name',
    'transport',
    'command',
    'args',
    'cwd',
    'env',
    'metadata',
    'enabled',
    'aggregate_version',
    'last_tested_at',
    'last_test_status',
    'last_test_message',
])]
class McpServer extends Model
{
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'args' => 'array',
            'env' => 'encrypted:array',
            'metadata' => 'array',
            'enabled' => 'boolean',
            'aggregate_version' => 'integer',
            'last_tested_at' => 'datetime',
        ];
    }
}
