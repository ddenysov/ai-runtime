import { computed, ref } from 'vue';

export function useFilteredList(items, searchKeys) {
    const query = ref('');

    const filtered = computed(() => {
        const normalizedQuery = query.value.trim().toLowerCase();

        if (!normalizedQuery) {
            return items;
        }

        return items.filter((item) =>
            searchKeys.some((key) =>
                String(item[key]).toLowerCase().includes(normalizedQuery),
            ),
        );
    });

    return { query, filtered };
}
