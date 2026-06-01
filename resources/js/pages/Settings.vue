<script setup>
import { computed, onMounted, ref } from 'vue';
import { LoaderCircleIcon } from '@lucide/vue';
import { toast } from 'vue-sonner';
import AppShell from '@/components/app/AppShell.vue';
import PageBreadcrumbs from '@/components/app/PageBreadcrumbs.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { getSettings, listAgents, updateSettings } from '@/lib/api';
import {
    navigation,
    workspaces,
} from '@/features/providers/providers.mock';

const NONE_AGENT = 'none';

const selectedWorkspace = ref('acme-ai');
const loading = ref(false);
const saving = ref(false);
const agentsLoading = ref(false);
const agents = ref([]);
const promptGeneratorAgentId = ref(NONE_AGENT);
const savedPromptGeneratorAgentId = ref(NONE_AGENT);

const settingsNavigation = computed(() => navigation.map((item) => ({
    ...item,
    active: item.label === 'Settings',
})));

const promptGeneratorDirty = computed(() => (
    promptGeneratorAgentId.value !== savedPromptGeneratorAgentId.value
));

function agentSelectValue(agentId) {
    return agentId ? String(agentId) : NONE_AGENT;
}

function agentIdFromSelect(value) {
    return value === NONE_AGENT ? null : Number(value);
}

async function loadAgents() {
    agentsLoading.value = true;

    try {
        const response = await listAgents({
            perPage: 100,
            sort: 'name',
            includeProviderModel: false,
            includeToolsCount: false,
            includeVersionsCount: false,
        });

        agents.value = response.data ?? [];
    } catch (fetchError) {
        toast.error('Could not load agents', {
            description: fetchError.message,
        });
    } finally {
        agentsLoading.value = false;
    }
}

async function loadSettings() {
    loading.value = true;

    try {
        const response = await getSettings();
        const agentId = response.data?.prompts?.prompt_generator_agent_id ?? null;
        const selectValue = agentSelectValue(agentId);

        promptGeneratorAgentId.value = selectValue;
        savedPromptGeneratorAgentId.value = selectValue;
    } catch (fetchError) {
        toast.error('Could not load settings', {
            description: fetchError.message,
        });
    } finally {
        loading.value = false;
    }
}

async function savePromptGenerator() {
    saving.value = true;

    try {
        const response = await updateSettings({
            prompts: {
                prompt_generator_agent_id: agentIdFromSelect(promptGeneratorAgentId.value),
            },
        });

        const agentId = response.data?.prompts?.prompt_generator_agent_id ?? null;
        const selectValue = agentSelectValue(agentId);

        promptGeneratorAgentId.value = selectValue;
        savedPromptGeneratorAgentId.value = selectValue;

        toast.success('Settings saved');
    } catch (saveError) {
        toast.error('Could not save settings', {
            description: saveError.data?.message ?? saveError.message,
        });
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    await Promise.all([loadAgents(), loadSettings()]);
});
</script>

<template>
    <AppShell
        v-model:workspace="selectedWorkspace"
        :workspaces="workspaces"
        :navigation="settingsNavigation"
    >
        <PageHeader title="Settings">
            <template #breadcrumbs>
                <PageBreadcrumbs :items="['Platform', 'Settings']" />
            </template>
        </PageHeader>

        <div class="space-y-6 px-5 py-7 md:px-8 md:py-8">
            <section class="space-y-4">
                <div>
                    <h2 class="text-lg font-semibold tracking-tight">
                        AI &amp; prompts
                    </h2>
                    <p class="app-muted-text mt-1 text-sm">
                        Configure agents used across the platform for prompt-related workflows.
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Prompt generator</CardTitle>
                        <CardDescription>
                            Optional agent that generates or refines prompts for other agents and tools.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="max-w-xl space-y-2">
                            <Label for="prompt-generator-agent">Generator agent</Label>
                            <Select
                                id="prompt-generator-agent"
                                v-model="promptGeneratorAgentId"
                                :disabled="loading || agentsLoading || saving"
                            >
                                <SelectTrigger class="w-full">
                                    <SelectValue
                                        :placeholder="agentsLoading ? 'Loading agents...' : 'No agent selected'"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem :value="NONE_AGENT">
                                        No agent selected
                                    </SelectItem>
                                    <SelectItem
                                        v-for="agent in agents"
                                        :key="agent.id"
                                        :value="String(agent.id)"
                                    >
                                        {{ agent.name }} · {{ agent.slug }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="flex items-center gap-2">
                            <Button
                                size="sm"
                                :disabled="!promptGeneratorDirty || saving || loading"
                                @click="savePromptGenerator"
                            >
                                <LoaderCircleIcon
                                    v-if="saving"
                                    class="size-4 animate-spin"
                                />
                                Save
                            </Button>
                            <p
                                v-if="promptGeneratorDirty"
                                class="app-muted-text text-sm"
                            >
                                Unsaved changes
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </section>
        </div>
    </AppShell>
</template>
