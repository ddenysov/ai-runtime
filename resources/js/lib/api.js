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

export function createAiProvider(payload) {
    return apiFetch('/api/ai-providers', {
        method: 'POST',
        body: JSON.stringify(payload),
    });
}
