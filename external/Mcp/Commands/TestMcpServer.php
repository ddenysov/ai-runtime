<?php

namespace App\Mcp\Commands;

final readonly class TestMcpServer
{
    public function __construct(
        public string $serverUuid,
    ) {}
}
