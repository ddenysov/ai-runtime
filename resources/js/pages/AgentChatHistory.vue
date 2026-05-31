<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import {
    ArrowLeftIcon,
    LoaderCircleIcon,
    MessageCircleIcon,
    RefreshCcwIcon,
} from '@lucide/vue';
import AppShell from '@/components/app/AppShell.vue';
import PageBreadcrumbs from '@/components/app/PageBreadcrumbs.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import DataPanel from '@/components/data/DataPanel.vue';
import DataTable from '@/components/data/DataTable.vue';
import SearchField from '@/components/data/SearchField.vue';
import { Badge } from '@/components/ui/badge';
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
import { getAgent, listAgentChats } from '@/lib/api';
import {
    navigation,
    workspaces,
} from '@/features/providers/providers.mock';

const props = defineProps({
    agentId: {
        type: [Number, String],
        required: true,
    },
});

const router = useRouter();
const selectedWorkspace = ref('acme-ai');
const searchQuery = ref('');
const sort = ref('-last_message_at');
const page = ref(1);
const perPage = ref('10');
const loading = ref(false);
const agentLoading = ref(false);
const error = ref('');
const agentError = ref('');
const agent = ref(null);
const chats = ref([]);
const pagination = ref({});
let searchTimer;
let requestSequence = 0;
let agentRequestSequence = 0;

const agentNavigation = computed(() => navigation.map((item) => ({
    ...item,
    active: item.label === 'Agents',
})));
const total = computed(() => pagination.value.total ?? chats.value.length);
const displayItems = computed(() => chats.value.map(formatChat));

const chatColumns = [
    { key: 'preview', label: 'Chat' },
    { key: 'latest_run_state', label: 'Latest run' },
    { key: 'messages_count', label: 'Messages', align: 'right', sortable: true },
    { key: 'last_message_at', label: 'Updated', sortable: true },
    { key: 'started_at', label: 'Started', sortable: true },
    { key: 'context_id', label: 'Context', sortable: true },
];

async function fetchAgent() {
    const sequence = ++agentRequestSequence;
    agentLoading.value = true;
    agentError.value = '';

    try {
        const response = await getAgent(props.agentId);

        if (sequence !== agentRequestSequence) {
            return;
        }

        agent.value = response;
    } catch (fetchError) {
        if (sequence !== agentRequestSequence) {
            return;
        }

        agentError.value = fetchError.message;
        agent.value = null;
    } finally {
        if (sequence === agentRequestSequence) {
            agentLoading.value = false;
        }
    }
}

async function fetchChats() {
    const sequence = ++requestSequence;
    loading.value = true;
    error.value = '';

    try {
        const response = await listAgentChats({
            id: props.agentId,
            search: searchQuery.value,
            sort: sort.value,
            page: page.value,
            perPage: Number(perPage.value),
        });

        if (sequence !== requestSequence) {
            return;
        }

        chats.value = response.data ?? [];
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

function formatChat(chat) {
    const latestRunState = formatState(chat.latest_run?.state);

    return {
        ...chat,
        preview: chat.preview || 'No message preview available.',
        latest_run_state: latestRunState,
        latest_run_id: chat.latest_run?.id ?? '',
        messages_count: chat.messages_count ?? 0,
        started_at: formatDate(chat.started_at),
        last_message_at: formatDate(chat.last_message_at),
    };
}

function formatState(state) {
    if (!state) {
        return 'No run';
    }

    return String(state)
        .toLowerCase()
        .replace(/[_-]+/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
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

function resetPageAndFetch() {
    if (page.value === 1) {
        fetchChats();
        return;
    }

    page.value = 1;
}

function handleSort(nextSort) {
    sort.value = nextSort;
}

function refresh() {
    fetchAgent();
    fetchChats();
}

function goBack() {
    router.push({ name: 'agent-details', params: { agentId: props.agentId } });
}

function openNewChat() {
    router.push({ name: 'agent-chat', params: { agentId: props.agentId } });
}

function openChat(item) {
    router.push({
        name: 'agent-chat',
        params: {
            agentId: props.agentId,
            contextId: item.context_id,
        },
    });
}

watch([sort, perPage], resetPageAndFetch);

watch(searchQuery, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(resetPageAndFetch, 300);
});

watch(page, fetchChats);

watch(() => props.agentId, () => {
    page.value = 1;
    chats.value = [];
    pagination.value = {};
    fetchAgent();
    fetchChats();
});

onMounted(() => {
    fetchAgent();
    fetchChats();
});

onUnmounted(() => {
    clearTimeout(searchTimer);
});
</script>

<template>
    <AppShell
        v-model:workspace="selectedWorkspace"
        :workspaces="workspaces"
        :navigation="agentNavigation"
    >
        <PageHeader :title="agent?.name ? `${agent.name} chat history` : 'Agent chat history'">
            <template #breadcrumbs>
                <PageBreadcrumbs :items="['Workspaces', 'Agents', agent?.slug ?? 'Agent', 'Chat history']" />
            </template>

            <template #actions>
                <Button variant="outline" class="app-soft-control" @click="goBack">
                    <ArrowLeftIcon class="size-4" />
                    Details
                </Button>
                <Button
                    variant="outline"
                    class="app-soft-control"
                    :disabled="loading || agentLoading"
                    @click="refresh"
                >
                    <LoaderCircleIcon v-if="loading || agentLoading" class="size-4 animate-spin" />
                    <RefreshCcwIcon v-else class="size-4" />
                    Refresh
                </Button>
                <Button class="rounded-app-control" @click="openNewChat">
                    <MessageCircleIcon class="size-4" />
                    New chat
                </Button>
            </template>
        </PageHeader>

        <div class="px-5 py-7 md:px-8 md:py-8">
            <DataPanel
                title="Chat history"
                description="Paged conversations for this agent, grouped by runtime context."
                :count="total"
                count-label="chats"
            >
                <template #toolbar>
                    <SearchField
                        v-model="searchQuery"
                        placeholder="Search by message or context..."
                    />
                </template>

                <div v-if="error || agentError" class="flex flex-col items-center gap-3 px-6 py-12 text-center">
                    <p class="font-medium">Could not load chat history</p>
                    <p class="app-muted-text max-w-md text-sm">{{ error || agentError }}</p>
                    <Button variant="outline" class="app-soft-control" @click="refresh">
                        Try again
                    </Button>
                </div>

                <div v-else>
                    <div v-if="loading" class="flex items-center justify-center gap-2 px-6 py-8 text-sm">
                        <LoaderCircleIcon class="size-4 animate-spin" />
                        Loading chat history...
                    </div>

                    <DataTable
                        v-else-if="displayItems.length"
                        clickable
                        row-key="context_id"
                        :columns="chatColumns"
                        :items="displayItems"
                        :sort="sort"
                        @sort="handleSort"
                        @row-click="openChat"
                    >
                        <template #cell-preview="{ item }">
                            <div class="max-w-xl">
                                <p class="line-clamp-2 font-medium">{{ item.preview }}</p>
                                <p class="app-muted-text mt-1 text-xs">
                                    {{ item.thread_id }}
                                </p>
                            </div>
                        </template>

                        <template #cell-latest_run_state="{ item }">
                            <div class="space-y-1">
                                <Badge variant="outline" class="rounded-full">
                                    {{ item.latest_run_state }}
                                </Badge>
                                <p v-if="item.latest_run_id" class="app-muted-text font-mono text-xs">
                                    {{ item.latest_run_id }}
                                </p>
                            </div>
                        </template>

                        <template #cell-messages_count="{ item }">
                            <span class="font-medium">{{ item.messages_count }}</span>
                        </template>

                        <template #cell-context_id="{ item }">
                            <span class="font-mono text-xs">{{ item.context_id }}</span>
                        </template>

                        <template #row-actions="{ item }">
                            <Button
                                variant="outline"
                                size="sm"
                                class="app-soft-control"
                                @click="openChat(item)"
                            >
                                Open
                            </Button>
                        </template>
                    </DataTable>

                    <div v-else class="px-6 py-12 text-center">
                        <p class="font-medium">No chats found</p>
                        <p class="app-muted-text mt-1 text-sm">
                            Start a new chat to create the first conversation for this agent.
                        </p>
                        <Button class="mt-4 rounded-app-control" @click="openNewChat">
                            <MessageCircleIcon class="size-4" />
                            New chat
                        </Button>
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
        </div>
    </AppShell>
</template>
