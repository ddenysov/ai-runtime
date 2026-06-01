<?php

require __DIR__ . '/../vendor/autoload.php';

use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\McpResource;

// Define capabilities using PHP attributes
class CalculatorCapabilities
{
    #[McpTool]
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    #[McpResource(uri: 'config://calculator/settings')]
    public function getSettings(): array
    {
        return ['precision' => 2];
    }
}

// Build and run the server
$server = Server::builder()
    ->setServerInfo('Calculator Server', '1.0.0')
    ->setDiscovery(__DIR__, ['.'])  // Auto-discover attributes
    ->build();

$transport = new StdioTransport();
$server->run($transport);
