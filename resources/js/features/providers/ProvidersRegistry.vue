<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { LoaderCircleIcon } from '@lucide/vue';
import DataCardGrid from '@/components/data/DataCardGrid.vue';
import DataPanel from '@/components/data/DataPanel.vue';
import DataTable from '@/components/data/DataTable.vue';
import ResourceActionsMenu from '@/components/data/ResourceActionsMenu.vue';
import ResourceCard from '@/components/data/ResourceCard.vue';
import SearchField from '@/components/data/SearchField.vue';
import StatusBadge from '@/components/data/StatusBadge.vue';
import ViewModeToggle from '@/components/data/ViewModeToggle.vue';
import { Button } from '@/components/ui/button';
import {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationFirst,
    PaginationItem,
    PaginationLast,
    PaginationNext,
    PaginationPrevious,
} from '@/components/ui/pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { listAiProviders } from '@/lib/api';
import { findProviderType, providerTypes } from '@/features/providers/provider-types';

const viewMode = ref('list');
const searchQuery = ref('');
const typeFilter = ref('all');
const statusFilter = ref('all');
const sort = ref('-updated_at');
const page = ref(1);
const perPage = ref('10');
const loading = ref(false);
const error = ref('');
const providers = ref([]);
const pagination = ref({});
let searchTimer;
let requestSequence = 0;

const providerColumns = [
    { key: 'name', label: 'Provider', sortable: true },
    { key: 'status', label: 'Status', sortable: true, sortKey: 'is_active' },
    { key: 'type', label: 'Type', sortable: true },
    { key: 'models_count', label: 'Models', align: 'right' },
    { key: 'updated', label: 'Updated', sortable: true, sortKey: 'updated_at' },
];

const providerCardStats = [
    { key: 'models_count', label: 'Models' },
    { key: 'status', label: 'Status' },
    { key: 'updated', label: 'Updated' },
];

const total = computed(() => pagination.value.total ?? providers.value.length);
const displayItems = computed(() => providers.value.map(formatProvider));

function formatProvider(provider) {
    const updated = formatDate(provider.updated_at);

    return {
        ...provider,
        type: findProviderType(provider.type)?.label ?? provider.type,
        type_value: provider.type,
        status: provider.is_active ? 'Active' : 'Inactive',
        models_count: provider.models_count ?? 0,
        updated,
        owner: provider.slug,
        region: `Updated ${updated}`,
    };
}

function formatDate(value) {
    if (!value) {
        return 'never';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function selectedStatusValue() {
    if (statusFilter.value === 'active') {
        return true;
    }

    if (statusFilter.value === 'inactive') {
        return false;
    }

    return undefined;
}

function normalizePagination(response) {
    return {
        current_page: response.current_page ?? 1,
        from: response.from ?? 0,
        last_page: response.last_page ?? 1,
        per_page: response.per_page ?? Number(perPage.value),
        to: response.to ?? 0,
        total: response.total ?? response.data?.length ?? 0,
    };
}

async function fetchProviders() {
    const sequence = ++requestSequence;
    loading.value = true;
    error.value = '';

    try {
        const response = await listAiProviders({
            search: searchQuery.value,
            type: typeFilter.value === 'all' ? undefined : typeFilter.value,
            isActive: selectedStatusValue(),
            sort: sort.value,
            page: page.value,
            perPage: Number(perPage.value),
        });

        if (sequence !== requestSequence) {
            return;
        }

        providers.value = response.data ?? [];
        pagination.value = normalizePagination(response);
    } catch (fetchError) {
        if (sequence !== requestSequence) {
            return;
        }

        error.value = fetchError.message;
    } finally {
        if (sequence === requestSequence) {
            loading.value = false;
        }
    }
}

function resetPageAndFetch() {
    if (page.value === 1) {
        fetchProviders();
        return;
    }

    page.value = 1;
}

function handleSort(nextSort) {
    sort.value = nextSort;
}

function retry() {
    fetchProviders();
}

function openResource(item) {
    console.info('Open resource', item.name);
}

function editResource(item) {
    console.info('Edit resource', item.name);
}

function deleteResource(item) {
    console.info('Delete resource', item.name);
}

watch([typeFilter, statusFilter, sort, perPage], resetPageAndFetch);

watch(searchQuery, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(resetPageAndFetch, 300);
});

watch(page, fetchProviders);

onMounted(fetchProviders);

onUnmounted(() => {
    clearTimeout(searchTimer);
});

defineExpose({
    reload: fetchProviders,
});
</script>

<template>
    <DataPanel
        title="Providers registry"
        description="Real AI providers connected to the runtime backend."
        :count="total"
    >
        <template #toolbar>
            <SearchField
                v-model="searchQuery"
                placeholder="Search by name or slug..."
            />
            <Select v-model="typeFilter">
                <SelectTrigger class="app-soft-control h-10 min-w-40">
                    <SelectValue placeholder="Provider type" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All types</SelectItem>
                    <SelectItem
                        v-for="type in providerTypes"
                        :key="type.value"
                        :value="type.value"
                    >
                        {{ type.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Select v-model="statusFilter">
                <SelectTrigger class="app-soft-control h-10 min-w-36">
                    <SelectValue placeholder="Status" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All statuses</SelectItem>
                    <SelectItem value="active">Active</SelectItem>
                    <SelectItem value="inactive">Inactive</SelectItem>
                </SelectContent>
            </Select>
            <ViewModeToggle v-model="viewMode" />
        </template>

        <div v-if="error" class="flex flex-col items-center gap-3 px-6 py-12 text-center">
            <p class="font-medium">Could not load providers</p>
            <p class="app-muted-text max-w-md text-sm">{{ error }}</p>
            <Button variant="outline" class="app-soft-control" @click="retry">
                Try again
            </Button>
        </div>

        <div v-else>
            <div v-if="loading" class="flex items-center justify-center gap-2 px-6 py-8 text-sm">
                <LoaderCircleIcon class="size-4 animate-spin" />
                Loading providers...
            </div>

            <template v-else-if="displayItems.length">
                <DataTable
                    v-if="viewMode === 'list'"
                    clickable
                    row-key="id"
                    :columns="providerColumns"
                    :items="displayItems"
                    :sort="sort"
                    @sort="handleSort"
                    @row-click="openResource"
                >
                    <template #cell-name="{ item }">
                        <div>
                            <p class="font-medium">{{ item.name }}</p>
                            <p class="app-muted-text text-sm">{{ item.slug }}</p>
                        </div>
                    </template>

                    <template #cell-status="{ item }">
                        <StatusBadge :status="item.status" />
                    </template>

                    <template #cell-models_count="{ item }">
                        <span class="font-medium">{{ item.models_count }}</span>
                    </template>

                    <template #row-actions="{ item }">
                        <ResourceActionsMenu
                            @open="openResource(item)"
                            @edit="editResource(item)"
                            @delete="deleteResource(item)"
                        />
                    </template>
                </DataTable>

                <DataCardGrid v-else :items="displayItems" row-key="id">
                    <template #default="{ item }">
                        <ResourceCard
                            :item="item"
                            :stats="providerCardStats"
                            @open="openResource(item)"
                            @edit="editResource(item)"
                            @delete="deleteResource(item)"
                        />
                    </template>
                </DataCardGrid>
            </template>

            <div v-else class="px-6 py-12 text-center">
                <p class="font-medium">No providers found</p>
                <p class="app-muted-text mt-1 text-sm">
                    Adjust the search or filters, or create a new provider.
                </p>
            </div>

            <div
                v-if="total > 0"
                class="flex flex-col gap-4 border-t px-4 py-4 md:flex-row md:items-center md:justify-between"
            >
                <p class="app-muted-text text-sm">
                    Showing {{ pagination.from ?? 0 }}-{{ pagination.to ?? 0 }} of {{ total }}
                </p>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <Select v-model="perPage">
                        <SelectTrigger class="app-soft-control h-9 min-w-28">
                            <SelectValue placeholder="Per page" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="10">10 / page</SelectItem>
                            <SelectItem value="25">25 / page</SelectItem>
                            <SelectItem value="50">50 / page</SelectItem>
                        </SelectContent>
                    </Select>
                    <Pagination
                        v-model:page="page"
                        :items-per-page="Number(perPage)"
                        :total="total"
                        :sibling-count="1"
                        show-edges
                    >
                        <PaginationContent v-slot="{ items }">
                            <PaginationFirst />
                            <PaginationPrevious />
                            <template v-for="(item, index) in items" :key="index">
                                <PaginationItem
                                    v-if="item.type === 'page'"
                                    :value="item.value"
                                    :is-active="item.value === page"
                                >
                                    {{ item.value }}
                                </PaginationItem>
                                <PaginationEllipsis v-else />
                            </template>
                            <PaginationNext />
                            <PaginationLast />
                        </PaginationContent>
                    </Pagination>
                </div>
            </div>
        </div>
    </DataPanel>
</template>
