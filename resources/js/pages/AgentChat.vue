<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import {
    ArrowLeftIcon,
    BotIcon,
    CircleAlertIcon,
    LoaderCircleIcon,
    MessageCircleIcon,
    RefreshCcwIcon,
    SendIcon,
    UserIcon,
    WrenchIcon,
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
import { Textarea } from '@/components/ui/textarea';
import { agentChatEventsUrl, getAgent, getAgentChatHistory, sendAgentChatMessage } from '@/lib/api';
import { randomUuid } from '@/lib/utils';
import {
    navigation,
    workspaces,
} from '@/features/providers/providers.mock';

const props = defineProps({
    agentId: {
        type: [Number, String],
        required: true,
    },
    contextId: {
        type: String,
        default: '',
    },
});

const router = useRouter();
const selectedWorkspace = ref('acme-ai');
const loading = ref(false);
const sending = ref(false);
const error = ref('');
const agent = ref(null);
const draft = ref('');
const messages = ref([]);
const activeRunId = ref('');
const activeRunActivity = ref([]);
const chatContextId = ref(props.contextId || randomUuid());
const streamStatus = ref('Idle');
const messagesPanel = ref(null);
const lastFailedUserMessageId = ref('');
const editingRetryMessageId = ref('');
const retryDraft = ref('');
let requestSequence = 0;
let historyRequestSequence = 0;
let eventSource = null;

const agentNavigation = computed(() => navigation.map((item) => ({
    ...item,
    active: item.label === 'Agents',
})));
const statusLabel = computed(() => (agent.value?.is_active ? 'Active' : 'Inactive'));
const providerModel = computed(() => agent.value?.provider_model);
const provider = computed(() => providerModel.value?.provider);
const modelLabel = computed(() => {
    if (!providerModel.value) {
        return 'No model assigned';
    }

    return `${provider.value?.name ?? 'Provider'} / ${providerModel.value.name}`;
});
const canSend = computed(() => draft.value.trim().length > 0 && !sending.value && agent.value?.is_active);
const latestUserMessageId = computed(() => {
    const latestUserMessage = [...messages.value].reverse().find((message) => message.role === 'user');

    return latestUserMessage?.id ?? '';
});

async function fetchAgent() {
    const sequence = ++requestSequence;
    loading.value = true;
    error.value = '';

    try {
        const response = await getAgent(props.agentId);

        if (sequence !== requestSequence) {
            return;
        }

        agent.value = response;
    } catch (fetchError) {
        if (sequence !== requestSequence) {
            return;
        }

        error.value = fetchError.message;
        agent.value = null;
    } finally {
        if (sequence === requestSequence) {
            loading.value = false;
        }
    }
}

async function fetchChatHistory(contextId = props.contextId || '') {
    if (!contextId) {
        return;
    }

    const sequence = ++historyRequestSequence;

    try {
        const response = await getAgentChatHistory(props.agentId, contextId);

        if (sequence !== historyRequestSequence || contextId !== chatContextId.value) {
            return;
        }

        messages.value = (response.messages ?? []).map((message) => ({
            id: `history-${message.id}`,
            persistedMessageId: String(message.id),
            role: message.role,
            content: message.content,
            status: message.status,
            createdAt: message.created_at ? new Date(message.created_at) : null,
            pending: false,
            persisted: true,
        }));
        resumeLatestRun(response.latest_run);
        scrollToBottom();
    } catch (fetchError) {
        if (sequence === historyRequestSequence) {
            error.value = fetchError.message;
        }
    }
}

async function submitMessage() {
    const content = draft.value.trim();

    if (!content || sending.value) {
        return;
    }

    draft.value = '';
    await sendChatContent(content);
}

async function sendChatContent(content, existingUserMessage = null, options = {}) {
    if (!content || sending.value) {
        return;
    }

    const userMessageId = existingUserMessage?.id ?? randomUuid();
    const assistantMessageId = randomUuid();
    const previousUserMessageContent = existingUserMessage?.content;
    const previousUserMessageCreatedAt = existingUserMessage?.createdAt;
    sending.value = true;
    lastFailedUserMessageId.value = '';
    editingRetryMessageId.value = '';
    retryDraft.value = '';
    activeRunId.value = '';
    activeRunActivity.value = [];
    streamStatus.value = 'Submitting';
    closeStream();
    ensureContextRoute();

    if (existingUserMessage) {
        messages.value = messages.value.filter((message) => !(
            message.role === 'assistant'
            && message.replyToMessageId === userMessageId
            && message.failed
        ));
        existingUserMessage.content = content;
        existingUserMessage.createdAt = new Date();
    } else {
        messages.value.push({
            id: userMessageId,
            role: 'user',
            content,
            createdAt: new Date(),
            persisted: false,
        });
    }

    messages.value.push({
        id: assistantMessageId,
        role: 'assistant',
        content: 'Agent run is being queued...',
        status: 'Submitted',
        createdAt: new Date(),
        pending: true,
        replyToMessageId: userMessageId,
    });
    scrollToBottom();

    try {
        const response = await sendAgentChatMessage(props.agentId, content, chatContextId.value, {
            replaceFailedLastMessage: options.replaceFailedLastMessage ?? false,
        });
        chatContextId.value = response.context_id ?? chatContextId.value;
        ensureContextRoute();
        activeRunId.value = response.run_id;
        applySnapshot(assistantMessageId, response.snapshot);
        openStream(
            assistantMessageId,
            response.stream_url ?? agentChatEventsUrl(props.agentId, response.run_id),
        );
    } catch (submitError) {
        if (existingUserMessage) {
            existingUserMessage.content = previousUserMessageContent;
            existingUserMessage.createdAt = previousUserMessageCreatedAt;
        }

        markAssistantFailed(assistantMessageId, submitError.message);
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
    });

    eventSource.addEventListener('failure', (event) => {
        if (event.data) {
            const payload = JSON.parse(event.data);
            markAssistantFailed(messageId, payload.message ?? 'Agent stream failed.');
        }
    });

    eventSource.onerror = () => {
        markAssistantFailed(messageId, 'Connection to the agent stream was interrupted.');
    };
}

function applySnapshot(messageId, snapshot) {
    const message = messages.value.find((item) => item.id === messageId);

    if (!message || !snapshot) {
        return;
    }

    const state = snapshot.task?.state ?? snapshot.run?.state ?? 'working';
    const artifact = snapshot.task?.artifact;
    const statusMessage = snapshot.task?.message;

    message.state = state;
    message.status = formatState(state);
    message.runId = snapshot.run?.id;
    message.activity = normalizeRunActivity(snapshot.activity);
    activeRunActivity.value = message.activity;
    const hasPersistedMessages = mergeSnapshotMessages(snapshot.messages, messageId);
    message.content = hasPersistedMessages
        ? pendingText(state)
        : artifact
        ?? statusMessage
        ?? pendingText(state);
    message.pending = !snapshot.terminal;

    if (snapshot.run?.last_error_message && snapshot.terminal) {
        message.content = snapshot.run.last_error_message;
        message.failed = true;
        lastFailedUserMessageId.value = message.replyToMessageId ?? latestUserMessageId.value;
    } else if (snapshot.terminal) {
        lastFailedUserMessageId.value = '';
    }

    if (snapshot.terminal) {
        streamStatus.value = message.failed ? 'Failed' : 'Complete';
        sending.value = false;
        closeStream();

        if (hasPersistedMessages && !message.failed) {
            removeMessage(messageId);
        }

        if (!message.failed) {
            void fetchChatHistory(chatContextId.value);
        }
    }

    scrollToBottom();
}

function mergeSnapshotMessages(snapshotMessages = [], runMessageId = '') {
    if (!Array.isArray(snapshotMessages) || snapshotMessages.length === 0) {
        return false;
    }

    const incomingMessages = snapshotMessages
        .map(normalizeSnapshotMessage)
        .filter(Boolean);

    incomingMessages.forEach((incomingMessage) => {
        const existingMessage = messages.value.find((item) => (
            item.persistedMessageId
            && item.persistedMessageId === incomingMessage.persistedMessageId
        ));

        if (existingMessage) {
            Object.assign(existingMessage, incomingMessage);

            return;
        }

        const runMessageIndex = messages.value.findIndex((item) => item.id === runMessageId);

        if (runMessageIndex === -1) {
            messages.value.push(incomingMessage);

            return;
        }

        messages.value.splice(runMessageIndex, 0, incomingMessage);
    });

    return incomingMessages.length > 0;
}

function normalizeSnapshotMessage(message) {
    if (!message?.id || !message.content) {
        return null;
    }

    return {
        id: `stream-${message.id}`,
        persistedMessageId: String(message.id),
        role: message.role,
        content: message.content,
        status: message.status,
        createdAt: message.created_at ? new Date(message.created_at) : null,
        pending: false,
        persisted: true,
    };
}

function removeMessage(messageId) {
    messages.value = messages.value.filter((message) => message.id !== messageId);
}

function canRetryMessage(message) {
    return message.role === 'user'
        && message.id === lastFailedUserMessageId.value
        && message.id === latestUserMessageId.value
        && !sending.value
        && agent.value?.is_active;
}

function retryMessage(message) {
    sendChatContent(message.content, message, { replaceFailedLastMessage: true });
}

function startEditingRetryMessage(message) {
    editingRetryMessageId.value = message.id;
    retryDraft.value = message.content;
}

function cancelEditingRetryMessage() {
    editingRetryMessageId.value = '';
    retryDraft.value = '';
}

function retryEditedMessage(message) {
    const content = retryDraft.value.trim();

    if (!content) {
        return;
    }

    sendChatContent(content, message, { replaceFailedLastMessage: true });
}

function resumeLatestRun(latestRun) {
    if (!latestRun?.run_id) {
        activeRunId.value = '';
        activeRunActivity.value = [];

        return;
    }

    activeRunId.value = latestRun.run_id;
    activeRunActivity.value = normalizeRunActivity(latestRun.snapshot?.activity);

    if (latestRun.snapshot?.terminal) {
        if (latestRun.snapshot.run?.last_error_message) {
            streamStatus.value = 'Failed';
            lastFailedUserMessageId.value = latestUserMessageId.value;
        } else {
            streamStatus.value = 'Complete';
            lastFailedUserMessageId.value = '';
        }

        return;
    }

    const assistantMessageId = `run-${latestRun.run_id}-assistant`;

    if (!messages.value.some((message) => message.id === assistantMessageId)) {
        messages.value.push({
            id: assistantMessageId,
            role: 'assistant',
            content: 'Agent is working...',
            status: 'Working',
            createdAt: new Date(),
            pending: true,
        });
    }

    sending.value = true;
    applySnapshot(assistantMessageId, latestRun.snapshot);
    openStream(
        assistantMessageId,
        latestRun.stream_url ?? agentChatEventsUrl(props.agentId, latestRun.run_id),
    );
}

function markAssistantFailed(messageId, message) {
    const assistantMessage = messages.value.find((item) => item.id === messageId);

    if (assistantMessage) {
        assistantMessage.content = message;
        assistantMessage.status = 'Failed';
        assistantMessage.pending = false;
        assistantMessage.failed = true;
        lastFailedUserMessageId.value = assistantMessage.replyToMessageId ?? latestUserMessageId.value;
    }

    streamStatus.value = 'Failed';
    sending.value = false;
    closeStream();
    scrollToBottom();
}

function normalizeRunActivity(activity = []) {
    if (!Array.isArray(activity)) {
        return [];
    }

    return activity
        .map((item) => ({
            id: item.id ?? randomUuid(),
            type: item.type ?? 'event',
            title: item.title ?? 'Activity updated',
            status: item.status ? formatState(item.status) : '',
            detail: item.detail ?? '',
            timestamp: item.timestamp ? new Date(item.timestamp) : null,
        }))
        .filter((item) => item.title || item.detail);
}

function activityTone(type) {
    if (type === 'subagent' || type === 'subagent_tool') {
        return 'border-blue-500/30 bg-blue-500/5 text-blue-700 dark:text-blue-300';
    }

    if (type === 'tool') {
        return 'border-amber-500/30 bg-amber-500/5 text-amber-700 dark:text-amber-300';
    }

    if (type === 'task') {
        return 'border-emerald-500/30 bg-emerald-500/5 text-emerald-700 dark:text-emerald-300';
    }

    return 'border-muted bg-muted/30 text-muted-foreground';
}

function isUserMessage(message) {
    return message.role === 'user';
}

function messageLabel(message) {
    if (message.role === 'tool') {
        return 'Tool';
    }

    return isUserMessage(message) ? 'You' : 'Agent';
}

function closeStream() {
    if (eventSource) {
        eventSource.close();
        eventSource = null;
    }
}

function ensureContextRoute() {
    if (props.contextId === chatContextId.value) {
        return;
    }

    router.replace({
        name: 'agent-chat',
        params: {
            agentId: props.agentId,
            contextId: chatContextId.value,
        },
    });
}

function goBack() {
    router.push({ name: 'agent-details', params: { agentId: props.agentId } });
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

function handleComposerKeydown(event) {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
        submitMessage();
    }
}

function scrollToBottom() {
    nextTick(() => {
        if (messagesPanel.value) {
            messagesPanel.value.scrollTop = messagesPanel.value.scrollHeight;
        }
    });
}

watch(() => props.agentId, () => {
    closeStream();
    messages.value = [];
    activeRunId.value = '';
    activeRunActivity.value = [];
    lastFailedUserMessageId.value = '';
    editingRetryMessageId.value = '';
    retryDraft.value = '';
    chatContextId.value = props.contextId || randomUuid();
    streamStatus.value = 'Idle';
    fetchAgent();
    fetchChatHistory();
});
watch(() => props.contextId, (contextId) => {
    if (!contextId || contextId === chatContextId.value) {
        return;
    }

    closeStream();
    messages.value = [];
    activeRunId.value = '';
    activeRunActivity.value = [];
    lastFailedUserMessageId.value = '';
    editingRetryMessageId.value = '';
    retryDraft.value = '';
    chatContextId.value = contextId;
    streamStatus.value = 'Idle';
    fetchChatHistory();
});
onMounted(() => {
    fetchAgent();
    fetchChatHistory();
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
        <PageHeader :title="agent?.name ? `Chat with ${agent.name}` : 'Agent chat'">
            <template #breadcrumbs>
                <PageBreadcrumbs :items="['Workspaces', 'Agents', agent?.slug ?? 'Agent', 'Chat']" />
            </template>

            <template #actions>
                <Button variant="outline" class="app-soft-control" @click="goBack">
                    <ArrowLeftIcon class="size-4" />
                    Details
                </Button>
                <Button
                    variant="outline"
                    class="app-soft-control"
                    :disabled="loading"
                    @click="fetchAgent"
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
                                <CardTitle>Runtime chat</CardTitle>
                            </div>
                            <CardDescription>
                                Messages are processed by a queued agent job and updated over SSE.
                            </CardDescription>
                        </div>
                        <Badge variant="outline" class="rounded-full">
                            {{ streamStatus }}
                        </Badge>
                    </div>
                </CardHeader>

                <CardContent class="flex min-h-0 flex-1 flex-col p-0">
                    <div
                        ref="messagesPanel"
                        class="min-h-0 flex-1 space-y-4 overflow-y-auto p-5 md:p-6"
                    >
                        <div
                            v-if="loading && !agent"
                            class="app-muted-text flex h-full items-center justify-center gap-2 text-sm"
                        >
                            <LoaderCircleIcon class="size-4 animate-spin" />
                            Loading agent...
                        </div>

                        <div
                            v-else-if="error"
                            class="flex h-full flex-col items-center justify-center gap-3 text-center"
                        >
                            <CircleAlertIcon class="text-destructive size-10" />
                            <div>
                                <p class="font-medium">Could not load agent</p>
                                <p class="app-muted-text mt-1 max-w-md text-sm">{{ error }}</p>
                            </div>
                            <Button @click="fetchAgent">Try again</Button>
                        </div>

                        <div
                            v-else-if="!messages.length"
                            class="flex h-full flex-col items-center justify-center text-center"
                        >
                            <div class="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-2xl">
                                <BotIcon class="size-7" />
                            </div>
                            <h2 class="mt-4 text-xl font-semibold">Start a run</h2>
                            <p class="app-muted-text mt-2 max-w-md text-sm">
                                Ask {{ agent?.name ?? 'this agent' }} a question. The response will appear here as the background job progresses.
                            </p>
                        </div>

                        <template v-else>
                            <div
                                v-for="message in messages"
                                :key="message.id"
                                class="flex gap-3"
                                :class="isUserMessage(message) ? 'justify-end' : 'justify-start'"
                            >
                                <div
                                    v-if="!isUserMessage(message)"
                                    class="app-surface-muted flex size-9 shrink-0 items-center justify-center rounded-full"
                                >
                                    <WrenchIcon v-if="message.role === 'tool'" class="size-4" />
                                    <BotIcon v-else class="size-4" />
                                </div>

                                <div
                                    class="flex max-w-[min(760px,85%)] flex-col gap-2"
                                    :class="isUserMessage(message) ? 'items-end' : 'items-start'"
                                >
                                    <div
                                        class="w-full rounded-app-container border px-4 py-3 shadow-sm"
                                        :class="isUserMessage(message)
                                            ? 'bg-primary text-primary-foreground'
                                            : message.role === 'tool'
                                                ? 'border-amber-500/30 bg-amber-500/5'
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
                                        <div
                                            v-if="message.role === 'assistant' && message.activity?.length"
                                            class="mt-3 space-y-2 border-t pt-3"
                                        >
                                            <div
                                                v-for="activity in message.activity.slice(-4)"
                                                :key="activity.id"
                                                class="rounded-app-control border px-3 py-2 text-xs"
                                                :class="activityTone(activity.type)"
                                            >
                                                <div class="flex flex-wrap items-center justify-between gap-2">
                                                    <span class="font-medium">{{ activity.title }}</span>
                                                    <Badge
                                                        v-if="activity.status"
                                                        variant="outline"
                                                        class="rounded-full text-[10px]"
                                                    >
                                                        {{ activity.status }}
                                                    </Badge>
                                                </div>
                                                <p
                                                    v-if="activity.detail"
                                                    class="mt-1 line-clamp-2 opacity-80"
                                                >
                                                    {{ activity.detail }}
                                                </p>
                                            </div>
                                        </div>
                                        <p class="mt-2 text-xs opacity-60">{{ formatDate(message.createdAt) }}</p>
                                    </div>

                                    <div
                                        v-if="canRetryMessage(message) && editingRetryMessageId !== message.id"
                                        class="flex items-center gap-2"
                                    >
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="xs"
                                            @click="retryMessage(message)"
                                        >
                                            <RefreshCcwIcon class="size-3" />
                                            Retry
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="xs"
                                            @click="startEditingRetryMessage(message)"
                                        >
                                            Edit
                                        </Button>
                                    </div>

                                    <div
                                        v-else-if="canRetryMessage(message)"
                                        class="w-full rounded-app-container border bg-card p-3 text-foreground shadow-sm"
                                    >
                                        <Textarea
                                            v-model="retryDraft"
                                            class="field-sizing-fixed min-h-20 max-h-48 resize-none overflow-y-auto rounded-app-control"
                                            :disabled="sending"
                                        />
                                        <div class="mt-2 flex justify-end gap-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="xs"
                                                @click="cancelEditingRetryMessage"
                                            >
                                                Cancel
                                            </Button>
                                            <Button
                                                type="button"
                                                size="xs"
                                                :disabled="!retryDraft.trim() || sending"
                                                @click="retryEditedMessage(message)"
                                            >
                                                Retry edited
                                            </Button>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    v-if="isUserMessage(message)"
                                    class="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-full"
                                >
                                    <UserIcon class="size-4" />
                                </div>
                            </div>
                        </template>
                    </div>

                    <form class="shrink-0 border-t p-4 md:p-5" @submit.prevent="submitMessage">
                        <Textarea
                            v-model="draft"
                            class="field-sizing-fixed min-h-24 max-h-48 resize-none overflow-y-auto rounded-app-control"
                            placeholder="Ask the agent..."
                            :disabled="!agent || sending"
                            @keydown="handleComposerKeydown"
                        />
                        <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <p class="app-muted-text text-xs">
                                Press Cmd/Ctrl + Enter to send. Each message starts a queued agent run.
                            </p>
                            <Button type="submit" :disabled="!canSend">
                                <LoaderCircleIcon v-if="sending" class="size-4 animate-spin" />
                                <SendIcon v-else class="size-4" />
                                Send
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <aside class="shrink-0 space-y-6 xl:min-h-0 xl:overflow-y-auto">
                <Card class="app-surface">
                    <CardHeader>
                        <CardTitle>Agent</CardTitle>
                        <CardDescription>Runtime configuration used for this chat.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="flex items-start gap-3">
                            <div class="bg-primary/10 text-primary flex size-10 shrink-0 items-center justify-center rounded-xl">
                                <BotIcon class="size-5" />
                            </div>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <StatusBadge :status="statusLabel" />
                                    <Badge variant="outline" class="rounded-full">
                                        {{ agent?.slug ?? 'loading' }}
                                    </Badge>
                                </div>
                                <p class="mt-2 font-medium">{{ agent?.name ?? 'Loading agent...' }}</p>
                                <p class="app-muted-text mt-1 text-sm">
                                    {{ agent?.description || 'No description configured.' }}
                                </p>
                            </div>
                        </div>

                        <div class="rounded-app-container border p-4">
                            <p class="app-muted-text text-sm">Model</p>
                            <p class="mt-1 font-medium">{{ modelLabel }}</p>
                            <p class="app-muted-text mt-1 break-all text-xs">
                                {{ providerModel?.model ?? 'Provider model is not configured' }}
                            </p>
                        </div>

                        <div class="rounded-app-container border p-4">
                            <div class="flex items-center justify-between gap-3">
                                <p class="app-muted-text text-sm">Active run</p>
                                <Badge
                                    v-if="activeRunActivity.length"
                                    variant="outline"
                                    class="rounded-full text-[11px]"
                                >
                                    {{ activeRunActivity.length }} events
                                </Badge>
                            </div>
                            <p class="mt-1 break-all font-mono text-xs">
                                {{ activeRunId || 'No active run' }}
                            </p>
                            <div
                                v-if="activeRunActivity.length"
                                class="mt-4 space-y-3"
                            >
                                <div
                                    v-for="activity in activeRunActivity.slice(-8)"
                                    :key="activity.id"
                                    class="rounded-app-control border px-3 py-2 text-xs"
                                    :class="activityTone(activity.type)"
                                >
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="font-medium">{{ activity.title }}</span>
                                        <Badge
                                            v-if="activity.status"
                                            variant="outline"
                                            class="rounded-full text-[10px]"
                                        >
                                            {{ activity.status }}
                                        </Badge>
                                    </div>
                                    <p
                                        v-if="activity.detail"
                                        class="mt-1 line-clamp-2 opacity-80"
                                    >
                                        {{ activity.detail }}
                                    </p>
                                    <p
                                        v-if="activity.timestamp"
                                        class="mt-1 opacity-60"
                                    >
                                        {{ formatDate(activity.timestamp) }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </aside>
        </div>
        </div>
    </AppShell>
</template>
