<script setup>
import { computed, ref } from 'vue';
import { PlusIcon } from '@lucide/vue';
import AppShell from '@/components/app/AppShell.vue';
import PageBreadcrumbs from '@/components/app/PageBreadcrumbs.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { Button } from '@/components/ui/button';
import CreateAgentDialog from '@/features/agents/CreateAgentDialog.vue';
import AgentsRegistry from '@/features/agents/AgentsRegistry.vue';
import {
    navigation,
    workspaces,
} from '@/features/providers/providers.mock';

const selectedWorkspace = ref('acme-ai');
const createAgentOpen = ref(false);
const agentsRegistry = ref(null);
const agentNavigation = computed(() => navigation.map((item) => ({
    ...item,
    active: item.label === 'Agents',
})));

function refreshAgents() {
    agentsRegistry.value?.reload();
}
</script>

<template>
    <AppShell
        v-model:workspace="selectedWorkspace"
        :workspaces="workspaces"
        :navigation="agentNavigation"
    >
        <PageHeader title="AI Agents">
            <template #breadcrumbs>
                <PageBreadcrumbs :items="['Workspaces', 'Agents']" />
            </template>

            <template #actions>
                <Button
                    class="rounded-app-control"
                    @click="createAgentOpen = true"
                >
                    <PlusIcon class="size-4" />
                    New agent
                </Button>
            </template>
        </PageHeader>

        <div class="px-5 py-7 md:px-8 md:py-8">
            <AgentsRegistry ref="agentsRegistry" />
        </div>

        <CreateAgentDialog
            v-model:open="createAgentOpen"
            @created="refreshAgents"
        />
    </AppShell>
</template>
