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

export function createAiProvider(payload) {
    return apiFetch('/api/ai-providers', {
        method: 'POST',
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

export function deleteAgent(id) {
    return apiFetch(`/api/agents/${id}`, {
        method: 'DELETE',
    });
}
