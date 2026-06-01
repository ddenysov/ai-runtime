<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Dotenv\Dotenv;
use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Schema\Content\TextContent;

$projectRoot = dirname(__DIR__);

if (is_readable($projectRoot.'/.env')) {
    Dotenv::createImmutable($projectRoot)->safeLoad();
}

/**
 * @return array<string, string>
 */
function process_env_for_child(): array
{
    $env = [];
    foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
        if (! is_string($key) || ! is_scalar($value)) {
            continue;
        }
        if ($key === 'argv' || $key === 'argc') {
            continue;
        }
        $env[$key] = (string) $value;
    }

    return $env;
}

$childEnv = process_env_for_child();

$jiraUrl = $childEnv['JIRA_URL'] ?? '';
$jiraToken = $childEnv['JIRA_API_TOKEN'] ?? '';
$jiraPersonal = $childEnv['JIRA_PERSONAL_TOKEN'] ?? '';

$hasJiraCloudCreds = $jiraUrl !== '' && $jiraToken !== '' && ($childEnv['JIRA_USERNAME'] ?? '') !== '';
$hasJiraServerCreds = $jiraUrl !== '' && $jiraPersonal !== '';

if (! $hasJiraCloudCreds && ! $hasJiraServerCreds) {
    fwrite(STDERR, "Missing Jira env: set JIRA_URL and JIRA_API_TOKEN (and JIRA_USERNAME for Cloud), or JIRA_URL and JIRA_PERSONAL_TOKEN for Server/DC, in {$projectRoot}/.env or export them.\n");
    exit(1);
}

$client = Client::builder()
    ->setClientInfo('Jira MCP client', '1.0.0')
    ->setInitTimeout(120)
    ->setRequestTimeout(120)
    ->build();

$transport = new StdioTransport(
    command: 'uvx',
    args: ['mcp-atlassian'],
    cwd: null,
    env: $childEnv,
);

$client->connect($transport);

$client->listTools();

$result = $client->callTool('jira_get_all_projects', ['include_archived' => false]);

foreach ($result->content as $block) {
    if ($block instanceof TextContent) {
        echo (string) $block->text, "\n";
        break;
    }
}

$client->disconnect();
