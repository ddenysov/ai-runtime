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
} = {}) {
    const params = new URLSearchParams();

    appendQueryParam(params, 'filter[search]', search);
    appendQueryParam(params, 'filter[type]', type);
    appendQueryParam(params, 'filter[is_active]', isActive);
    appendQueryParam(params, 'sort', sort);
    appendQueryParam(params, 'page', page);
    appendQueryParam(params, 'per_page', perPage);

    if (includeModelsCount) {
        params.set('include', 'modelsCount');
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
