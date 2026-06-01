<?php

namespace App\Mcp\Commands;

final readonly class DeleteMcpServer
{
    public function __construct(
        public string $uuid,
        public int $expectedVersion,
    ) {}
}
