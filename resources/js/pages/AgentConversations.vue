<script setup>
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { MessageSquarePlusIcon, MessagesSquareIcon } from '@lucide/vue';
import AppShell from '@/components/app/AppShell.vue';
import PageBreadcrumbs from '@/components/app/PageBreadcrumbs.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { listAgentConversations } from '@/lib/api';
import {
    navigation,
    workspaces,
} from '@/features/providers/providers.mock';

const router = useRouter();
const selectedWorkspace = ref('acme-ai');
const loading = ref(false);
const error = ref('');
const conversations = ref([]);
const agentNavigation = computed(() => navigation.map((item) => ({
    ...item,
    active: item.label === 'Agents',
})));

async function fetchConversations() {
    loading.value = true;
    error.value = '';

    try {
        const response = await listAgentConversations({ perPage: 50 });
        conversations.value = response.data ?? [];
    } catch (fetchError) {
        error.value = fetchError.message;
        conversations.value = [];
    } finally {
        loading.value = false;
    }
}

function openNewConversation() {
    router.push({ name: 'agent-conversation-new' });
}

function openConversation(conversationId) {
    router.push({
        name: 'agent-conversation',
        params: { conversationId },
    });
}

function formatDate(value) {
    if (!value) {
        return '';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

fetchConversations();
</script>

<template>
    <AppShell
        v-model:workspace="selectedWorkspace"
        :workspaces="workspaces"
        :navigation="agentNavigation"
    >
        <PageHeader title="Agent conversations">
            <template #breadcrumbs>
                <PageBreadcrumbs :items="['Workspaces', 'Agents', 'Conversations']" />
            </template>

            <template #actions>
                <Button class="rounded-app-control" @click="openNewConversation">
                    <MessageSquarePlusIcon class="size-4" />
                    New conversation
                </Button>
            </template>
        </PageHeader>

        <div class="px-5 py-7 md:px-8 md:py-8">
            <Card class="app-surface">
                <CardHeader>
                    <CardTitle>Turn-based agent dialogues</CardTitle>
                    <CardDescription>
                        Two agents share a conversation with separate histories. You control each turn manually.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <p v-if="loading" class="app-muted-text text-sm">Loading conversations...</p>
                    <p v-else-if="error" class="text-destructive text-sm">{{ error }}</p>
                    <div
                        v-else-if="!conversations.length"
                        class="flex flex-col items-center gap-4 py-10 text-center"
                    >
                        <div class="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-2xl">
                            <MessagesSquareIcon class="size-7" />
                        </div>
                        <div>
                            <p class="font-medium">No conversations yet</p>
                            <p class="app-muted-text mt-1 max-w-md text-sm">
                                Pick two agents, provide a starter prompt, and let them talk one turn at a time.
                            </p>
                        </div>
                        <Button @click="openNewConversation">Start conversation</Button>
                    </div>
                    <div v-else class="space-y-3">
                        <button
                            v-for="conversation in conversations"
                            :key="conversation.id"
                            type="button"
                            class="hover:bg-muted/40 flex w-full items-start justify-between gap-4 rounded-app-container border p-4 text-left transition-colors"
                            @click="openConversation(conversation.id)"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="font-medium">
                                    {{ conversation.first_agent?.name }}
                                    <span class="app-muted-text font-normal">vs</span>
                                    {{ conversation.second_agent?.name }}
                                </p>
                                <p class="app-muted-text mt-1 line-clamp-2 text-sm">
                                    {{ conversation.preview }}
                                </p>
                            </div>
                            <div class="app-muted-text shrink-0 text-right text-xs">
                                <p>{{ conversation.messages_count }} messages</p>
                                <p class="mt-1">{{ formatDate(conversation.updated_at) }}</p>
                            </div>
                        </button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppShell>
</template>
