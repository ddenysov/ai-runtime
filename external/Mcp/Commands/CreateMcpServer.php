<?php

namespace App\Mcp\Commands;

final readonly class CreateMcpServer
{
    /**
     * @param  array<int, string>  $args
     * @param  array<string, string>  $env
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public string $uuid,
        public string $name,
        public string $transport,
        public string $command,
        public array $args,
        public ?string $cwd,
        public array $env,
        public ?array $metadata,
        public bool $enabled,
    ) {}
}
