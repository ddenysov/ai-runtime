export const runtimeTools = [
    {
        slug: 'remote_a2a_agent',
        label: 'Remote A2A agent',
        description: 'Delegate work to allowed subagents through the A2A runtime.',
    },
    {
        slug: 'get_agent_card',
        label: 'Get agent card',
        description: 'Inspect a configured subagent card before delegating.',
    },
    {
        slug: 'roll_dice',
        label: 'Roll dice',
        description: 'Roll D&D dice with notation such as 1d20+7, 8d6, or 4d6dl1.',
    },
];

export function findRuntimeTool(slug) {
    return runtimeTools.find((tool) => tool.slug === slug);
}

export function slugifyAgentName(value) {
    return value
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .replace(/-{2,}/g, '-');
}

export function linesToList(value) {
    return value
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean);
}

export function listToLines(value) {
    if (!value) {
        return '';
    }

    if (Array.isArray(value)) {
        return value
            .map((item) => (typeof item === 'object' ? JSON.stringify(item) : String(item)))
            .join('\n');
    }

    if (typeof value === 'string') {
        return value;
    }

    return String(value);
}

export function commaList(value) {
    return value
        .split(',')
        .map((item) => item.trim())
        .filter(Boolean);
}

export function buildMcpToolSlug(serverUuid, toolName) {
    return `mcp:${serverUuid}:${toolName}`;
}

export function buildMcpToolConfig(server, tool) {
    return {
        server_uuid: server.uuid,
        server_name: server.name,
        tool_name: tool.name,
        title: tool.title,
        description: tool.description,
        input_schema: tool.input_schema ?? {},
    };
}

export function mcpToolLabelFromConfig(config) {
    if (!config || typeof config !== 'object') {
        return null;
    }

    const serverName = config.server_name ?? config.server_uuid ?? 'MCP server';
    const toolName = config.title || config.tool_name;

    return toolName ? `${serverName}: ${toolName}` : null;
}

export function buildAgentToolsDraft(agentTools = []) {
    const bySlug = new Map(
        (agentTools ?? []).map((tool) => [tool.slug, {
            slug: tool.slug,
            is_enabled: Boolean(tool.is_enabled),
            config: tool.config ?? null,
        }]),
    );

    return runtimeTools.map((definition) => {
        const existing = bySlug.get(definition.slug);

        return {
            slug: definition.slug,
            is_enabled: existing?.is_enabled ?? false,
            config: existing?.config ?? null,
        };
    }).concat(
        [...bySlug.values()].filter((tool) => !runtimeTools.some((definition) => definition.slug === tool.slug)),
    );
}

export function toolDisplayMeta(tool, definitions = []) {
    const runtime = findRuntimeTool(tool.slug);
    if (runtime) {
        return {
            label: runtime.label,
            description: runtime.description,
            group: 'Built-in',
        };
    }

    const discovered = definitions.find((item) => item.slug === tool.slug);
    if (discovered) {
        return {
            label: discovered.label,
            description: discovered.description,
            group: discovered.group ?? 'MCP',
        };
    }

    const mcpLabel = mcpToolLabelFromConfig(tool.config);

    return {
        label: mcpLabel ?? tool.slug.replace(/^mcp:[^:]+:/, ''),
        description: tool.config?.description ?? 'MCP tool configured for this agent.',
        group: tool.config?.server_name ?? 'MCP',
    };
}
