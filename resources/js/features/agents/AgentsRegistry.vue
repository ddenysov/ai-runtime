<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { LoaderCircleIcon } from '@lucide/vue';
import { toast } from 'vue-sonner';
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
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
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
import { deleteAgent, listAgents } from '@/lib/api';

const viewMode = ref('list');
const searchQuery = ref('');
const statusFilter = ref('all');
const sort = ref('-updated_at');
const page = ref(1);
const perPage = ref('10');
const loading = ref(false);
const deleting = ref(false);
const error = ref('');
const agents = ref([]);
const pagination = ref({});
const deleteDialogOpen = ref(false);
const agentToDelete = ref(null);
let searchTimer;
let requestSequence = 0;

const agentColumns = [
    { key: 'name', label: 'Agent', sortable: true },
    { key: 'status', label: 'Status', sortable: true, sortKey: 'is_active' },
    { key: 'model', label: 'Model' },
    { key: 'tools_count', label: 'Tools', align: 'right' },
    { key: 'versions_count', label: 'Versions', align: 'right' },
    { key: 'updated', label: 'Updated', sortable: true, sortKey: 'updated_at' },
];

const agentCardStats = [
    { key: 'tools_count', label: 'Tools' },
    { key: 'versions_count', label: 'Versions' },
    { key: 'status', label: 'Status' },
];

const total = computed(() => pagination.value.total ?? agents.value.length);
const displayItems = computed(() => agents.value.map(formatAgent));

function formatAgent(agent) {
    const updated = formatDate(agent.updated_at);
    const providerModel = agent.provider_model;
    const provider = providerModel?.provider;
    const model = providerModel
        ? `${provider?.name ?? 'Provider'} / ${providerModel.name}`
        : 'Unassigned';

    return {
        ...agent,
        status: agent.is_active ? 'Active' : 'Inactive',
        model,
        tools_count: agent.tools_count ?? 0,
        versions_count: agent.versions_count ?? 0,
        updated,
        type: model,
        owner: agent.slug,
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

async function fetchAgents() {
    const sequence = ++requestSequence;
    loading.value = true;
    error.value = '';

    try {
        const response = await listAgents({
            search: searchQuery.value,
            isActive: selectedStatusValue(),
            sort: sort.value,
            page: page.value,
            perPage: Number(perPage.value),
        });

        if (sequence !== requestSequence) {
            return;
        }

        agents.value = response.data ?? [];
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
        fetchAgents();
        return;
    }

    page.value = 1;
}

function handleSort(nextSort) {
    sort.value = nextSort;
}

function retry() {
    fetchAgents();
}

function openResource(item) {
    console.info('Open agent', item.name);
}

function editResource(item) {
    console.info('Edit agent', item.name);
}

function deleteResource(item) {
    agentToDelete.value = item;
    deleteDialogOpen.value = true;
}

async function confirmDelete() {
    if (!agentToDelete.value) {
        return;
    }

    deleting.value = true;

    try {
        await deleteAgent(agentToDelete.value.id);
        toast.success('Agent deleted', {
            description: `${agentToDelete.value.name} was removed.`,
        });
        deleteDialogOpen.value = false;
        agentToDelete.value = null;
        await fetchAgents();
    } catch (deleteError) {
        toast.error('Could not delete agent', {
            description: deleteError.message,
        });
    } finally {
        deleting.value = false;
    }
}

watch([statusFilter, sort, perPage], resetPageAndFetch);

watch(searchQuery, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(resetPageAndFetch, 300);
});

watch(page, fetchAgents);

onMounted(fetchAgents);

onUnmounted(() => {
    clearTimeout(searchTimer);
});

defineExpose({
    reload: fetchAgents,
});
</script>

<template>
    <DataPanel
        title="Agents registry"
        description="Deployable runtime agent configurations backed by provider models, prompts and tools."
        :count="total"
    >
        <template #toolbar>
            <SearchField
                v-model="searchQuery"
                placeholder="Search by name, slug or description..."
            />
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
            <p class="font-medium">Could not load agents</p>
            <p class="app-muted-text max-w-md text-sm">{{ error }}</p>
            <Button variant="outline" class="app-soft-control" @click="retry">
                Try again
            </Button>
        </div>

        <div v-else>
            <div v-if="loading" class="flex items-center justify-center gap-2 px-6 py-8 text-sm">
                <LoaderCircleIcon class="size-4 animate-spin" />
                Loading agents...
            </div>

            <template v-else-if="displayItems.length">
                <DataTable
                    v-if="viewMode === 'list'"
                    clickable
                    row-key="id"
                    :columns="agentColumns"
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

                    <template #cell-tools_count="{ item }">
                        <span class="font-medium">{{ item.tools_count }}</span>
                    </template>

                    <template #cell-versions_count="{ item }">
                        <span class="font-medium">{{ item.versions_count }}</span>
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
                            :stats="agentCardStats"
                            @open="openResource(item)"
                            @edit="editResource(item)"
                            @delete="deleteResource(item)"
                        />
                    </template>
                </DataCardGrid>
            </template>

            <div v-else class="px-6 py-12 text-center">
                <p class="font-medium">No agents found</p>
                <p class="app-muted-text mt-1 text-sm">
                    Create an agent to expose a runtime-ready configuration.
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

    <AlertDialog v-model:open="deleteDialogOpen">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Delete agent?</AlertDialogTitle>
                <AlertDialogDescription>
                    <template v-if="agentToDelete">
                        This will permanently remove
                        <span class="text-foreground font-medium">{{ agentToDelete.name }}</span>
                        and its configuration versions. Existing runs keep their run records.
                    </template>
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel :disabled="deleting">Cancel</AlertDialogCancel>
                <AlertDialogAction
                    variant="destructive"
                    :disabled="deleting"
                    @click.prevent="confirmDelete"
                >
                    <LoaderCircleIcon v-if="deleting" class="size-4 animate-spin" />
                    Delete agent
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
