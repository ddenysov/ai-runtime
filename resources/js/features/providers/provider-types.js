export const providerTypes = [
    {
        value: 'gemini',
        label: 'Google Gemini',
        description: 'Gemini models via the Google AI API.',
        credentials: [
            {
                key: 'key',
                label: 'API key',
                type: 'password',
                placeholder: 'AIza...',
                description: 'Stored encrypted. Only masked values are shown after save.',
            },
        ],
    },
];

export function findProviderType(value) {
    return providerTypes.find((type) => type.value === value);
}

export function slugifyProviderName(value) {
    return value
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}
