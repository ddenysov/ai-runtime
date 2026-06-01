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
