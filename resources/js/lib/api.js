export async function apiFetch(url, options = {}) {
    const headers = {
        Accept: 'application/json',
        ...(options.body ? { 'Content-Type': 'application/json' } : {}),
        ...options.headers,
    };

    const response = await fetch(url, {
        ...options,
        headers,
    });

    const data = await response.json().catch(() => null);

    if (!response.ok) {
        const error = new Error(data?.message ?? 'Request failed');
        error.status = response.status;
        error.data = data;
        throw error;
    }

    return data;
}

function appendQueryParam(params, key, value) {
    if (value === undefined || value === null || value === '') {
        return;
    }

    params.set(key, value);
}

export function listAiProviders({
    search,
    type,
    isActive,
    sort,
    page,
    perPage,
    includeModelsCount = true,
    includeModels = false,
} = {}) {
    const params = new URLSearchParams();

    appendQueryParam(params, 'filter[search]', search);
    appendQueryParam(params, 'filter[type]', type);
    appendQueryParam(params, 'filter[is_active]', isActive);
    appendQueryParam(params, 'sort', sort);
    appendQueryParam(params, 'page', page);
    appendQueryParam(params, 'per_page', perPage);

    const includes = [];

    if (includeModelsCount) {
        includes.push('modelsCount');
    }

    if (includeModels) {
        includes.push('models');
    }

    if (includes.length) {
        params.set('include', includes.join(','));
    }

    const query = params.toString();

    return apiFetch(`/api/ai-providers${query ? `?${query}` : ''}`);
}

export function getAiProvider(id) {
    return apiFetch(`/api/ai-providers/${id}`);
}

export function createAiProvider(payload) {
    return apiFetch('/api/ai-providers', {
        method: 'POST',
        body: JSON.stringify(payload),
    });
}

export function updateAiProvider(id, payload) {
    return apiFetch(`/api/ai-providers/${id}`, {
        method: 'PUT',
        body: JSON.stringify(payload),
    });
}

export function deleteAiProvider(id) {
    return apiFetch(`/api/ai-providers/${id}`, {
        method: 'DELETE',
    });
}

export function testAiProviderConnection(payload) {
    return apiFetch('/api/ai-providers/test-connection', {
        method: 'POST',
        body: JSON.stringify(payload),
    });
}

export function listMcpServers({
    search,
    enabled,
    page,
    perPage,
} = {}) {
    const params = new URLSearchParams();

    appendQueryParam(params, 'filter[search]', search);
    appendQueryParam(params, 'filter[enabled]', enabled);
    appendQueryParam(params, 'page', page);
    appendQueryParam(params, 'per_page', perPage);

    const query = params.toString();

    return apiFetch(`/api/mcp-servers${query ? `?${query}` : ''}`);
}

export function createMcpServer(payload) {
    return apiFetch('/api/mcp-servers', {
        method: 'POST',
        body: JSON.stringify(payload),
    });
}

export function updateMcpServer(uuid, payload) {
    return apiFetch(`/api/mcp-servers/${uuid}`, {
        method: 'PUT',
        body: JSON.stringify(payload),
    });
}

export function deleteMcpServer(uuid, payload) {
    return apiFetch(`/api/mcp-servers/${uuid}`, {
        method: 'DELETE',
        body: JSON.stringify(payload),
    });
}

export function testMcpServer(uuid) {
    return apiFetch(`/api/mcp-servers/${uuid}/test`, {
        method: 'POST',
    });
}

export function listMcpServerTools(uuid) {
    return apiFetch(`/api/mcp-servers/${uuid}/tools`);
}

export function getSettings() {
    return apiFetch('/api/settings');
}

export function updateSettings(payload) {
    return apiFetch('/api/settings', {
        method: 'PATCH',
        body: JSON.stringify(payload),
    });
}

export function listAgents({
    search,
    isActive,
    aiProviderModelId,
    sort,
    page,
    perPage,
    includeProviderModel = true,
    includeToolsCount = true,
    includeVersionsCount = true,
} = {}) {
    const params = new URLSearchParams();

    appendQueryParam(params, 'filter[search]', search);
    appendQueryParam(params, 'filter[is_active]', isActive);
    appendQueryParam(params, 'filter[ai_provider_model_id]', aiProviderModelId);
    appendQueryParam(params, 'sort', sort);
    appendQueryParam(params, 'page', page);
    appendQueryParam(params, 'per_page', perPage);

    const includes = [];

    if (includeProviderModel) {
        includes.push('providerModel.provider');
    }

    if (includeToolsCount) {
        includes.push('toolsCount');
    }

    if (includeVersionsCount) {
        includes.push('versionsCount');
    }

    if (includes.length) {
        params.set('include', includes.join(','));
    }

    const query = params.toString();

    return apiFetch(`/api/agents${query ? `?${query}` : ''}`);
}

export function createAgent(payload) {
    return apiFetch('/api/agents', {
        method: 'POST',
        body: JSON.stringify(payload),
    });
}

export function getAgent(id) {
    return apiFetch(`/api/agents/${id}`);
}

export function updateAgent(id, payload) {
    return apiFetch(`/api/agents/${id}`, {
        method: 'PUT',
        body: JSON.stringify(payload),
    });
}

export function generateAgentInstructions(id, payload = {}) {
    return apiFetch(`/api/agents/${id}/generate-instructions`, {
        method: 'POST',
        body: JSON.stringify(payload),
    });
}

export function listAgentChats({
    id,
    search,
    sort,
    page,
    perPage,
} = {}) {
    const params = new URLSearchParams();

    appendQueryParam(params, 'filter[search]', search);
    appendQueryParam(params, 'sort', sort);
    appendQueryParam(params, 'page', page);
    appendQueryParam(params, 'per_page', perPage);

    const query = params.toString();

    return apiFetch(`/api/agents/${id}/chats${query ? `?${query}` : ''}`);
}

export function sendAgentChatMessage(id, message, contextId, options = {}) {
    return apiFetch(`/api/agents/${id}/chat`, {
        method: 'POST',
        body: JSON.stringify({
            message,
            context_id: contextId,
            replace_failed_last_message: options.replaceFailedLastMessage ?? false,
        }),
    });
}

export function getAgentChatHistory(id, contextId) {
    return apiFetch(`/api/agents/${id}/chat/${contextId}`);
}

export function agentChatEventsUrl(id, runId) {
    return `/api/agents/${id}/chat/${runId}/events`;
}

export function listAgentRuns({
    search,
    state,
    agentSlug,
    contextId,
    sort,
    page,
    perPage,
} = {}) {
    const params = new URLSearchParams();

    appendQueryParam(params, 'filter[search]', search);
    appendQueryParam(params, 'filter[state]', state);
    appendQueryParam(params, 'filter[agent_slug]', agentSlug);
    appendQueryParam(params, 'filter[context_id]', contextId);
    appendQueryParam(params, 'sort', sort);
    appendQueryParam(params, 'page', page);
    appendQueryParam(params, 'per_page', perPage);

    const query = params.toString();

    return apiFetch(`/api/agent-runs${query ? `?${query}` : ''}`);
}

export function getAgentRun(runId) {
    return apiFetch(`/api/agent-runs/${runId}`);
}

export function getAgentRunContext(agentId, contextId) {
    return apiFetch(`/api/agents/${agentId}/runs/${contextId}`);
}

export function deleteAgent(id) {
    return apiFetch(`/api/agents/${id}`, {
        method: 'DELETE',
    });
}

export function listAgentChannels({ agentId } = {}) {
    const params = new URLSearchParams();

    appendQueryParam(params, 'agent_id', agentId);

    const query = params.toString();

    return apiFetch(`/api/agent-channels${query ? `?${query}` : ''}`);
}

export function getAgentChannel(uuid) {
    return apiFetch(`/api/agent-channels/${encodeURIComponent(uuid)}`);
}

export function createAgentChannel(payload) {
    return apiFetch('/api/agent-channels', {
        method: 'POST',
        body: JSON.stringify(payload),
    });
}

export function updateAgentChannel(uuid, payload) {
    return apiFetch(`/api/agent-channels/${encodeURIComponent(uuid)}`, {
        method: 'PATCH',
        body: JSON.stringify(payload),
    });
}

export function deleteAgentChannel(uuid, expectedVersion) {
    return apiFetch(`/api/agent-channels/${encodeURIComponent(uuid)}`, {
        method: 'DELETE',
        body: JSON.stringify({ expected_version: expectedVersion }),
    });
}

export function setAgentChannelTelegramWebhook(uuid) {
    return apiFetch(`/api/agent-channels/${encodeURIComponent(uuid)}/telegram/webhook`, {
        method: 'POST',
    });
}

export function deleteAgentChannelTelegramWebhook(uuid) {
    return apiFetch(`/api/agent-channels/${encodeURIComponent(uuid)}/telegram/webhook`, {
        method: 'DELETE',
    });
}
