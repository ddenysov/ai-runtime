<?php
require __DIR__ . '/../vendor/autoload.php';

use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;

// Build the client
$client = Client::builder()
    ->setClientInfo('My Application', '1.0.0')
    ->setInitTimeout(30)
    ->setRequestTimeout(120)
    ->build();

// Connect to a server
$transport = new StdioTransport(
    command: 'php',
    args: ['server.php'],
);

$client->connect($transport);

// Discover and use capabilities
$tools = $client->listTools();



$result = $client->callTool('add', ['a' => 5, 'b' => 3]);


$resources = $client->listResources();
$content = $client->readResource('config://calculator/settings');

$client->disconnect();
