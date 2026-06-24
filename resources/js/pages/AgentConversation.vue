<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import {
    ArrowLeftIcon,
    ArrowRightIcon,
    BotIcon,
    CircleAlertIcon,
    LoaderCircleIcon,
    MessageCircleIcon,
    RefreshCcwIcon,
    SparklesIcon,
    UserIcon,
} from '@lucide/vue';
import AppShell from '@/components/app/AppShell.vue';
import PageBreadcrumbs from '@/components/app/PageBreadcrumbs.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/data/StatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    advanceAgentConversation,
    agentChatEventsUrl,
    createAgentConversation,
    getAgentChatHistory,
    getAgentConversation,
    listAgents,
} from '@/lib/api';
import { randomUuid } from '@/lib/utils';
import {
    navigation,
    workspaces,
} from '@/features/providers/providers.mock';

const props = defineProps({
    conversationId: {
        type: String,
        default: '',
    },
});

const router = useRouter();
const isSetupMode = computed(() => !props.conversationId || props.conversationId === 'new');
const selectedWorkspace = ref('acme-ai');
const loading = ref(false);
const starting = ref(false);
const advancing = ref(false);
const sending = ref(false);
const error = ref('');
const agents = ref([]);
const agentsLoading = ref(false);
const conversation = ref(null);
const messages = ref([]);
const firstAgentId = ref('');
const secondAgentId = ref('');
const starterPrompt = ref('');
const streamStatus = ref('Idle');
const pendingMessage = ref(null);
const messagesPanel = ref(null);
let eventSource = null;

const agentNavigation = computed(() => navigation.map((item) => ({
    ...item,
    active: item.label === 'Agents',
})));
const activeAgents = computed(() => agents.value.filter((agent) => agent.is_active));
const firstAgent = computed(() => agents.value.find((agent) => String(agent.id) === firstAgentId.value) ?? conversation.value?.first_agent ?? null);
const secondAgent = computed(() => agents.value.find((agent) => String(agent.id) === secondAgentId.value) ?? conversation.value?.second_agent ?? null);
const nextAgent = computed(() => conversation.value?.next_agent ?? null);
const canStart = computed(() => (
    firstAgentId.value
    && secondAgentId.value
    && firstAgentId.value !== secondAgentId.value
    && starterPrompt.value.trim().length > 0
    && !starting.value
));
const canAdvance = computed(() => (
    !isSetupMode.value
    && conversation.value?.can_advance
    && !sending.value
    && !advancing.value
));
const pageTitle = computed(() => {
    if (isSetupMode.value) {
        return 'New conversation';
    }

    if (conversation.value?.first_agent && conversation.value?.second_agent) {
        return `${conversation.value.first_agent.name} ↔ ${conversation.value.second_agent.name}`;
    }

    return 'Agent conversation';
});

async function fetchAgents() {
    agentsLoading.value = true;

    try {
        const response = await listAgents({ perPage: 100, isActive: true });
        agents.value = response.data ?? [];
    } catch {
        agents.value = [];
    } finally {
        agentsLoading.value = false;
    }
}

async function fetchConversation() {
    if (isSetupMode.value) {
        return;
    }

    loading.value = true;
    error.value = '';

    try {
        const response = await getAgentConversation(props.conversationId);
        conversation.value = response;
        messages.value = (response.messages ?? []).map(normalizeTimelineMessage);
        await resumeActiveRun(response.active_run);
    } catch (fetchError) {
        error.value = fetchError.message;
        conversation.value = null;
        messages.value = [];
    } finally {
        loading.value = false;
        scrollToBottom();
    }
}

async function startConversation() {
    if (!canStart.value) {
        return;
    }

    starting.value = true;
    error.value = '';

    try {
        const response = await createAgentConversation({
            first_agent_id: Number(firstAgentId.value),
            second_agent_id: Number(secondAgentId.value),
            starter_prompt: starterPrompt.value.trim(),
        });

        await router.replace({
            name: 'agent-conversation',
            params: { conversationId: response.id },
        });
    } catch (startError) {
        error.value = startError.message;
        starting.value = false;
    }
}

async function advanceConversation() {
    if (!canAdvance.value || !conversation.value?.id) {
        return;
    }

    advancing.value = true;
    error.value = '';

    try {
        const response = await advanceAgentConversation(conversation.value.id);
        conversation.value = {
            ...response,
            can_advance: false,
        };
        messages.value = (response.messages ?? []).map(normalizeTimelineMessage);
        await handleRun(response.run);
    } catch (advanceError) {
        error.value = advanceError.message;
    } finally {
        advancing.value = false;
    }
}

async function resumeActiveRun(activeRun) {
    if (!activeRun?.run_id || ['completed', 'failed'].includes(activeRun.state)) {
        return;
    }

    await handleRun(activeRun, true);
}

async function handleRun(run, resume = false) {
    if (!run?.run_id || !run?.agent_id) {
        return;
    }

    const agent = [conversation.value?.first_agent, conversation.value?.second_agent]
        .find((item) => item?.id === run.agent_id);

    if (!agent) {
        return;
    }

    const pendingId = randomUuid();
    pendingMessage.value = {
        id: pendingId,
        kind: 'agent',
        agent_id: agent.id,
        agent_name: agent.name,
        content: 'Agent run is being queued...',
        status: 'Submitted',
        pending: true,
        createdAt: new Date(),
    };
    sending.value = true;
    streamStatus.value = 'Submitting';
    closeStream();
    scrollToBottom();

    let snapshot = null;

    if (resume) {
        const contextId = run.context_id
            ?? (agent.id === conversation.value?.first_agent?.id
                ? conversation.value.first_agent_context_id
                : conversation.value.second_agent_context_id);

        try {
            const history = await getAgentChatHistory(agent.id, contextId);
            snapshot = history.latest_run?.snapshot ?? null;
        } catch {
            snapshot = null;
        }
    }

    if (snapshot) {
        applySnapshot(pendingId, snapshot);
    }

    openStream(
        pendingId,
        run.stream_url ?? agentChatEventsUrl(agent.id, run.run_id),
    );

    if (!resume) {
        starting.value = false;
    }
}

function openStream(messageId, url) {
    streamStatus.value = 'Connecting';
    eventSource = new EventSource(url);

    eventSource.onopen = () => {
        streamStatus.value = 'Streaming';
    };

    eventSource.onmessage = (event) => {
        applySnapshot(messageId, JSON.parse(event.data));
    };

    eventSource.addEventListener('timeout', (event) => {
        const payload = JSON.parse(event.data);
        applySnapshot(messageId, payload.snapshot);
        streamStatus.value = 'Waiting';
        closeStream();
        sending.value = false;
        void refreshConversation();
    });

    eventSource.addEventListener('failure', (event) => {
        if (event.data) {
            const payload = JSON.parse(event.data);
            markPendingFailed(messageId, payload.message ?? 'Agent stream failed.');
        }
    });

    eventSource.onerror = () => {
        markPendingFailed(messageId, 'Connection to the agent stream was interrupted.');
    };
}

function applySnapshot(messageId, snapshot) {
    if (!pendingMessage.value || pendingMessage.value.id !== messageId || !snapshot) {
        return;
    }

    const state = snapshot.task?.state ?? snapshot.run?.state ?? 'working';
    const artifact = snapshot.task?.artifact;
    const statusMessage = snapshot.task?.message;

    pendingMessage.value.status = formatState(state);
    pendingMessage.value.content = artifact
        ?? statusMessage
        ?? pendingText(state);
    pendingMessage.value.pending = !snapshot.terminal;

    if (snapshot.run?.last_error_message && snapshot.terminal) {
        pendingMessage.value.content = snapshot.run.last_error_message;
        pendingMessage.value.failed = true;
    }

    if (snapshot.terminal) {
        streamStatus.value = pendingMessage.value.failed ? 'Failed' : 'Complete';
        sending.value = false;
        closeStream();
        void refreshConversation();
    }

    scrollToBottom();
}

function markPendingFailed(messageId, message) {
    if (!pendingMessage.value || pendingMessage.value.id !== messageId) {
        return;
    }

    pendingMessage.value.content = message;
    pendingMessage.value.status = 'Failed';
    pendingMessage.value.pending = false;
    pendingMessage.value.failed = true;
    streamStatus.value = 'Failed';
    sending.value = false;
    closeStream();
    scrollToBottom();
}

async function refreshConversation() {
    if (isSetupMode.value || !props.conversationId) {
        return;
    }

    try {
        const response = await getAgentConversation(props.conversationId);
        conversation.value = response;
        messages.value = (response.messages ?? []).map(normalizeTimelineMessage);
        pendingMessage.value = null;
    } catch (fetchError) {
        error.value = fetchError.message;
    }

    scrollToBottom();
}

function normalizeTimelineMessage(message) {
    return {
        id: message.id,
        kind: message.kind,
        agent_id: message.agent_id,
        agent_name: message.agent_name,
        content: message.content,
        createdAt: message.created_at ? new Date(message.created_at) : null,
        pending: false,
    };
}

function closeStream() {
    if (eventSource) {
        eventSource.close();
        eventSource = null;
    }
}

function goBack() {
    router.push({ name: 'agent-conversations' });
}

function formatState(state) {
    if (!state) {
        return 'Working';
    }

    return String(state)
        .toLowerCase()
        .replace(/[_-]+/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function pendingText(state) {
    if (String(state).toUpperCase() === 'SUBMITTED') {
        return 'Agent run is queued...';
    }

    return 'Agent is working...';
}

function formatDate(value) {
    if (!value) {
        return '';
    }

    return new Intl.DateTimeFormat(undefined, {
        timeStyle: 'short',
    }).format(value);
}

function messageLabel(message) {
    if (message.kind === 'starter') {
        return 'Starter prompt';
    }

    return message.agent_name ?? 'Agent';
}

function isStarterMessage(message) {
    return message.kind === 'starter';
}

function scrollToBottom() {
    nextTick(() => {
        if (messagesPanel.value) {
            messagesPanel.value.scrollTop = messagesPanel.value.scrollHeight;
        }
    });
}

const renderedMessages = computed(() => {
    if (!pendingMessage.value) {
        return messages.value;
    }

    return [...messages.value, pendingMessage.value];
});

watch(() => props.conversationId, () => {
    closeStream();
    pendingMessage.value = null;
    sending.value = false;
    starting.value = false;
    streamStatus.value = 'Idle';

    if (!isSetupMode.value) {
        void fetchConversation();
    }
}, { immediate: true });

onMounted(() => {
    void fetchAgents();
});

onBeforeUnmount(closeStream);
</script>

<template>
    <AppShell
        v-model:workspace="selectedWorkspace"
        :workspaces="workspaces"
        :navigation="agentNavigation"
        fixed-viewport
    >
        <div class="flex min-h-0 flex-1 flex-col overflow-hidden">
            <PageHeader :title="pageTitle">
                <template #breadcrumbs>
                    <PageBreadcrumbs :items="['Workspaces', 'Agents', 'Conversations', isSetupMode ? 'New' : 'Chat']" />
                </template>

                <template #actions>
                    <Button variant="outline" class="app-soft-control" @click="goBack">
                        <ArrowLeftIcon class="size-4" />
                        Conversations
                    </Button>
                    <Button
                        v-if="!isSetupMode"
                        variant="outline"
                        class="app-soft-control"
                        :disabled="loading"
                        @click="fetchConversation"
                    >
                        <LoaderCircleIcon v-if="loading" class="size-4 animate-spin" />
                        <RefreshCcwIcon v-else class="size-4" />
                        Refresh
                    </Button>
                </template>
            </PageHeader>

            <div class="grid min-h-0 flex-1 grid-rows-[minmax(0,1fr)_auto] gap-6 overflow-hidden px-5 py-4 md:px-8 md:py-5 xl:grid-cols-[minmax(0,1fr)_360px] xl:grid-rows-none">
                <Card class="app-surface flex min-h-0 flex-1 flex-col overflow-hidden">
                    <CardHeader class="shrink-0 border-b">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="flex items-center gap-2">
                                    <MessageCircleIcon class="app-muted-text size-4" />
                                    <CardTitle>{{ isSetupMode ? 'Setup' : 'Conversation' }}</CardTitle>
                                </div>
                                <CardDescription>
                                    {{ isSetupMode
                                        ? 'Choose two agents and provide the opening prompt for the first speaker.'
                                        : 'Each agent keeps its own chat history. Advance manually to pass the turn.' }}
                                </CardDescription>
                            </div>
                            <Badge v-if="!isSetupMode" variant="outline" class="rounded-full">
                                {{ streamStatus }}
                            </Badge>
                        </div>
                    </CardHeader>

                    <CardContent class="flex min-h-0 flex-1 flex-col p-0">
                        <div
                            v-if="isSetupMode"
                            class="space-y-5 p-5 md:p-6"
                        >
                            <div class="grid gap-5 md:grid-cols-2">
                                <div class="space-y-2">
                                    <Label for="first-agent">First agent</Label>
                                    <Select
                                        id="first-agent"
                                        v-model="firstAgentId"
                                        :disabled="agentsLoading || starting"
                                    >
                                        <SelectTrigger class="w-full">
                                            <SelectValue :placeholder="agentsLoading ? 'Loading agents...' : 'Select agent'" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem
                                                v-for="agent in activeAgents"
                                                :key="agent.id"
                                                :value="String(agent.id)"
                                                :disabled="String(agent.id) === secondAgentId"
                                            >
                                                {{ agent.name }} · {{ agent.slug }}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div class="space-y-2">
                                    <Label for="second-agent">Second agent</Label>
                                    <Select
                                        id="second-agent"
                                        v-model="secondAgentId"
                                        :disabled="agentsLoading || starting"
                                    >
                                        <SelectTrigger class="w-full">
                                            <SelectValue :placeholder="agentsLoading ? 'Loading agents...' : 'Select agent'" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem
                                                v-for="agent in activeAgents"
                                                :key="agent.id"
                                                :value="String(agent.id)"
                                                :disabled="String(agent.id) === firstAgentId"
                                            >
                                                {{ agent.name }} · {{ agent.slug }}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <Label for="starter-prompt">Starter prompt</Label>
                                <Textarea
                                    id="starter-prompt"
                                    v-model="starterPrompt"
                                    class="min-h-32 resize-none rounded-app-control"
                                    placeholder="Describe the scene, topic, or first message for the first agent..."
                                    :disabled="starting"
                                />
                            </div>

                            <p v-if="error" class="text-destructive text-sm">{{ error }}</p>

                            <div class="flex justify-end">
                                <Button :disabled="!canStart" @click="startConversation">
                                    <LoaderCircleIcon v-if="starting" class="size-4 animate-spin" />
                                    <SparklesIcon v-else class="size-4" />
                                    Start conversation
                                </Button>
                            </div>
                        </div>

                        <template v-else>
                            <div
                                ref="messagesPanel"
                                class="min-h-0 flex-1 space-y-4 overflow-y-auto p-5 md:p-6"
                            >
                                <div
                                    v-if="loading && !conversation"
                                    class="app-muted-text flex h-full items-center justify-center gap-2 text-sm"
                                >
                                    <LoaderCircleIcon class="size-4 animate-spin" />
                                    Loading conversation...
                                </div>

                                <div
                                    v-else-if="error && !conversation"
                                    class="flex h-full flex-col items-center justify-center gap-3 text-center"
                                >
                                    <CircleAlertIcon class="text-destructive size-10" />
                                    <div>
                                        <p class="font-medium">Could not load conversation</p>
                                        <p class="app-muted-text mt-1 max-w-md text-sm">{{ error }}</p>
                                    </div>
                                    <Button @click="fetchConversation">Try again</Button>
                                </div>

                                <div
                                    v-else-if="!renderedMessages.length"
                                    class="flex h-full flex-col items-center justify-center text-center"
                                >
                                    <div class="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-2xl">
                                        <BotIcon class="size-7" />
                                    </div>
                                    <h2 class="mt-4 text-xl font-semibold">Waiting for the first response</h2>
                                    <p class="app-muted-text mt-2 max-w-md text-sm">
                                        The first agent is preparing a reply to your starter prompt.
                                    </p>
                                </div>

                                <template v-else>
                                    <div
                                        v-for="message in renderedMessages"
                                        :key="message.id"
                                        class="flex gap-3"
                                        :class="isStarterMessage(message) ? 'justify-end' : 'justify-start'"
                                    >
                                        <div
                                            v-if="!isStarterMessage(message)"
                                            class="app-surface-muted flex size-9 shrink-0 items-center justify-center rounded-full"
                                        >
                                            <BotIcon class="size-4" />
                                        </div>

                                        <div
                                            class="flex max-w-[min(760px,85%)] flex-col gap-2"
                                            :class="isStarterMessage(message) ? 'items-end' : 'items-start'"
                                        >
                                            <div
                                                class="w-full rounded-app-container border px-4 py-3 shadow-sm"
                                                :class="isStarterMessage(message)
                                                    ? 'bg-primary text-primary-foreground'
                                                    : message.failed
                                                        ? 'border-destructive/30 bg-destructive/5'
                                                        : 'bg-card'"
                                            >
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <p class="text-xs font-medium uppercase tracking-wide opacity-75">
                                                        {{ messageLabel(message) }}
                                                    </p>
                                                    <Badge
                                                        v-if="message.status"
                                                        variant="outline"
                                                        class="rounded-full text-[11px]"
                                                    >
                                                        <LoaderCircleIcon
                                                            v-if="message.pending"
                                                            class="mr-1 size-3 animate-spin"
                                                        />
                                                        {{ message.status }}
                                                    </Badge>
                                                </div>
                                                <p class="mt-2 whitespace-pre-wrap text-sm leading-6">{{ message.content }}</p>
                                                <p class="mt-2 text-xs opacity-60">{{ formatDate(message.createdAt) }}</p>
                                            </div>
                                        </div>

                                        <div
                                            v-if="isStarterMessage(message)"
                                            class="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-full"
                                        >
                                            <UserIcon class="size-4" />
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <div class="shrink-0 border-t p-4 md:p-5">
                                <p v-if="error" class="text-destructive mb-3 text-sm">{{ error }}</p>
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <p class="app-muted-text text-xs">
                                        <template v-if="sending">
                                            Waiting for {{ pendingMessage?.agent_name ?? 'agent' }} to finish...
                                        </template>
                                        <template v-else-if="canAdvance && nextAgent">
                                            Ready to pass the turn to {{ nextAgent.name }}.
                                        </template>
                                        <template v-else-if="nextAgent">
                                            {{ nextAgent.name }} will speak on the next turn.
                                        </template>
                                    </p>
                                    <Button
                                        :disabled="!canAdvance"
                                        @click="advanceConversation"
                                    >
                                        <LoaderCircleIcon v-if="advancing || sending" class="size-4 animate-spin" />
                                        <ArrowRightIcon v-else class="size-4" />
                                        {{ nextAgent ? `Let ${nextAgent.name} respond` : 'Next turn' }}
                                    </Button>
                                </div>
                            </div>
                        </template>
                    </CardContent>
                </Card>

                <Card v-if="!isSetupMode" class="app-surface hidden min-h-0 flex-col overflow-hidden xl:flex">
                    <CardHeader class="shrink-0 border-b">
                        <CardTitle>Participants</CardTitle>
                        <CardDescription>Each agent keeps an isolated runtime context.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4 p-5">
                        <div
                            v-for="agent in [conversation?.first_agent, conversation?.second_agent]"
                            :key="agent?.id"
                            class="rounded-app-container border p-4"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="font-medium">{{ agent?.name }}</p>
                                    <p class="app-muted-text text-sm">{{ agent?.slug }}</p>
                                </div>
                                <StatusBadge :status="agent?.is_active ? 'Active' : 'Inactive'" />
                            </div>
                            <p
                                v-if="nextAgent?.id === agent?.id && canAdvance"
                                class="mt-3 text-sm text-emerald-600"
                            >
                                Next speaker
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppShell>
</template>
