<?php

namespace App\Mcp\Commands;

final readonly class UpdateMcpServer
{
    /**
     * @param  array<int, string>|null  $args
     * @param  array<string, string>|null  $env
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public string $uuid,
        public int $expectedVersion,
        public bool $hasName,
        public ?string $name,
        public bool $hasTransport,
        public ?string $transport,
        public bool $hasCommand,
        public ?string $command,
        public bool $hasArgs,
        public ?array $args,
        public bool $hasCwd,
        public ?string $cwd,
        public bool $hasEnv,
        public ?array $env,
        public bool $hasMetadata,
        public ?array $metadata,
        public bool $hasEnabled,
        public ?bool $enabled,
    ) {}
}
