<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import {
    ArrowLeftIcon,
    BotIcon,
    CheckCircle2Icon,
    CircleAlertIcon,
    ClockIcon,
    Code2Icon,
    FileJsonIcon,
    HistoryIcon,
    LoaderCircleIcon,
    RefreshCcwIcon,
    Settings2Icon,
    WrenchIcon,
    XCircleIcon,
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
import { Separator } from '@/components/ui/separator';
import { getAgent } from '@/lib/api';
import { findRuntimeTool } from '@/features/agents/agent-tools';
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
const loading = ref(false);
const error = ref('');
const agent = ref(null);
let requestSequence = 0;

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
const a2aCardUrl = computed(() => (agent.value?.slug
    ? `/api/a2a/${agent.value.slug}/.well-known/agent-card.json`
    : undefined));

const tools = computed(() => (agent.value?.tools ?? []).map((tool) => {
    const definition = findRuntimeTool(tool.slug);

    return {
        ...tool,
        label: definition?.label ?? titleize(tool.slug),
        description: definition?.description ?? 'Runtime tool configured for this agent.',
        status: tool.is_enabled ? 'Enabled' : 'Disabled',
    };
}));
const enabledTools = computed(() => tools.value.filter((tool) => tool.is_enabled));
const versions = computed(() => [...(agent.value?.versions ?? [])]
    .sort((current, next) => (next.version ?? 0) - (current.version ?? 0)));
const latestVersion = computed(() => versions.value[0]);
const instructionSections = computed(() => formatInstructionSections(agent.value?.instructions));
const inputModes = computed(() => normalizeList(agent.value?.input_modes));
const outputModes = computed(() => normalizeList(agent.value?.output_modes));
const subagents = computed(() => normalizeList(agent.value?.subagents));

const heroStats = computed(() => [
    {
        label: 'Model',
        value: modelLabel.value,
        detail: providerModel.value?.model ?? 'Provider model is not configured',
    },
    {
        label: 'Tools',
        value: `${enabledTools.value.length}/${tools.value.length}`,
        detail: tools.value.length ? 'enabled runtime tools' : 'No tools configured',
    },
    {
        label: 'Current version',
        value: latestVersion.value ? `v${latestVersion.value.version}` : 'No snapshot',
        detail: latestVersion.value ? `Published ${formatDate(latestVersion.value.published_at)}` : 'Create a version snapshot',
    },
    {
        label: 'Updated',
        value: formatDate(agent.value?.updated_at),
        detail: `Created ${formatDate(agent.value?.created_at)}`,
    },
]);

const runtimeSettings = computed(() => [
    {
        label: 'Temperature',
        value: valueOrDefault(agent.value?.temperature, 'Provider default'),
        hint: 'Controls creativity and variation.',
    },
    {
        label: 'Max tokens',
        value: formatNumber(agent.value?.max_tokens) ?? 'Provider default',
        hint: 'Upper limit for generated output.',
    },
    {
        label: 'Timeout',
        value: agent.value?.timeout_seconds ? `${agent.value.timeout_seconds}s` : 'Provider default',
        hint: 'Maximum runtime before cancellation.',
    },
    {
        label: 'History window',
        value: formatNumber(agent.value?.history_context_window) ?? 'Not set',
        hint: 'Conversation context retained for runs.',
    },
]);

const readinessItems = computed(() => [
    {
        label: 'Agent is active',
        complete: Boolean(agent.value?.is_active),
        detail: agent.value?.is_active ? 'Ready to resolve runtime traffic.' : 'Inactive agents are hidden from runtime resolution.',
    },
    {
        label: 'Provider model selected',
        complete: Boolean(providerModel.value),
        detail: providerModel.value?.model ?? 'Assign a provider model before production use.',
    },
    {
        label: 'Instructions are defined',
        complete: instructionSections.value.some((section) => section.items.length),
        detail: instructionSections.value.some((section) => section.items.length)
            ? 'Behavior guidance is available.'
            : 'Add background, steps, or output rules.',
    },
    {
        label: 'Version snapshot exists',
        complete: Boolean(latestVersion.value),
        detail: latestVersion.value ? `Latest snapshot is v${latestVersion.value.version}.` : 'Create a snapshot for auditability.',
    },
]);

const schemaBlocks = computed(() => [
    {
        label: 'Input schema',
        value: agent.value?.input_schema,
    },
    {
        label: 'Output schema',
        value: agent.value?.output_schema,
    },
    {
        label: 'Metadata',
        value: agent.value?.metadata,
    },
].map((block) => ({
    ...block,
    formatted: formatJson(block.value),
})));

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

function goBack() {
    router.push({ name: 'agents' });
}

function formatInstructionSections(instructions = {}) {
    const knownKeys = ['background', 'steps', 'output'];
    const extraKeys = Object.keys(instructions ?? {})
        .filter((key) => !knownKeys.includes(key));

    return [...knownKeys, ...extraKeys].map((key) => ({
        key,
        label: instructionLabel(key),
        items: normalizeList(instructions?.[key]),
    }));
}

function instructionLabel(key) {
    const labels = {
        background: 'Background',
        steps: 'Process',
        output: 'Output contract',
    };

    return labels[key] ?? titleize(key);
}

function normalizeList(value) {
    if (!value) {
        return [];
    }

    if (Array.isArray(value)) {
        return value
            .map((item) => formatListItem(item))
            .filter(Boolean);
    }

    if (typeof value === 'string') {
        return value
            .split('\n')
            .map((item) => item.trim())
            .filter(Boolean);
    }

    return [formatListItem(value)].filter(Boolean);
}

function formatListItem(item) {
    if (item === null || item === undefined || item === '') {
        return '';
    }

    if (typeof item === 'object') {
        return JSON.stringify(item);
    }

    return String(item);
}

function formatJson(value) {
    if (!value || (typeof value === 'object' && !Object.keys(value).length)) {
        return '';
    }

    return JSON.stringify(value, null, 2);
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

function formatNumber(value) {
    if (value === null || value === undefined || value === '') {
        return undefined;
    }

    return new Intl.NumberFormat().format(Number(value));
}

function valueOrDefault(value, fallback) {
    return value === null || value === undefined || value === '' ? fallback : value;
}

function titleize(value) {
    return String(value)
        .replace(/[_-]+/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

watch(() => props.agentId, fetchAgent);
onMounted(fetchAgent);
</script>

<template>
    <AppShell
        v-model:workspace="selectedWorkspace"
        :workspaces="workspaces"
        :navigation="agentNavigation"
    >
        <PageHeader :title="agent?.name ?? 'Agent details'">
            <template #breadcrumbs>
                <PageBreadcrumbs :items="['Workspaces', 'Agents', agent?.slug ?? 'Details']" />
            </template>

            <template #actions>
                <Button variant="outline" class="app-soft-control" @click="goBack">
                    <ArrowLeftIcon class="size-4" />
                    Agents
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

        <div class="px-5 py-7 md:px-8 md:py-8">
            <div v-if="loading && !agent" class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
                <Card class="app-surface min-h-72 animate-pulse" />
                <Card class="app-surface min-h-72 animate-pulse" />
            </div>

            <Card v-else-if="error" class="app-surface">
                <CardContent class="flex flex-col items-center gap-3 px-6 py-14 text-center">
                    <CircleAlertIcon class="text-destructive size-10" />
                    <div>
                        <p class="font-medium">Could not load agent</p>
                        <p class="app-muted-text mt-1 max-w-md text-sm">{{ error }}</p>
                    </div>
                    <div class="flex gap-2">
                        <Button variant="outline" class="app-soft-control" @click="goBack">
                            Back to registry
                        </Button>
                        <Button @click="fetchAgent">Try again</Button>
                    </div>
                </CardContent>
            </Card>

            <div v-else-if="agent" class="space-y-6">
                <Card class="app-surface overflow-hidden">
                    <CardContent class="p-0">
                        <div class="bg-card p-6 md:p-8">
                            <div class="flex flex-col gap-6 xl:flex-row xl:items-start xl:justify-between">
                                <div class="flex min-w-0 gap-4">
                                    <div
                                        class="bg-primary/10 text-primary flex size-14 shrink-0 items-center justify-center rounded-2xl"
                                    >
                                        <BotIcon class="size-7" />
                                    </div>
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <StatusBadge :status="statusLabel" />
                                            <Badge variant="outline" class="rounded-full">
                                                {{ agent.slug }}
                                            </Badge>
                                        </div>
                                        <h2 class="mt-3 text-2xl font-semibold tracking-tight md:text-3xl">
                                            {{ agent.name }}
                                        </h2>
                                        <p class="app-muted-text mt-2 max-w-3xl text-sm md:text-base">
                                            {{ agent.description || 'No description yet. Add one to make ownership, use case and escalation context clear.' }}
                                        </p>
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-2 xl:justify-end">
                                    <Button
                                        v-if="a2aCardUrl"
                                        as="a"
                                        variant="outline"
                                        class="app-soft-control"
                                        :href="a2aCardUrl"
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <Code2Icon class="size-4" />
                                        Agent card
                                    </Button>
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-px bg-border md:grid-cols-2 xl:grid-cols-4">
                            <div
                                v-for="stat in heroStats"
                                :key="stat.label"
                                class="bg-card p-5"
                            >
                                <p class="app-muted-text text-xs font-medium uppercase tracking-wide">
                                    {{ stat.label }}
                                </p>
                                <p class="mt-2 truncate text-base font-semibold">{{ stat.value }}</p>
                                <p class="app-muted-text mt-1 truncate text-sm">{{ stat.detail }}</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
                    <div class="space-y-6">
                        <Card class="app-surface">
                            <CardHeader>
                                <div class="flex items-center gap-2">
                                    <Settings2Icon class="app-muted-text size-4" />
                                    <CardTitle>Operating instructions</CardTitle>
                                </div>
                                <CardDescription>
                                    The behavior contract the runtime will package into agent runs.
                                </CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-5">
                                <div
                                    v-for="section in instructionSections"
                                    :key="section.key"
                                    class="rounded-app-container border p-4"
                                >
                                    <div class="flex items-center justify-between gap-3">
                                        <h3 class="font-medium">{{ section.label }}</h3>
                                        <Badge variant="outline" class="rounded-full">
                                            {{ section.items.length }} item{{ section.items.length === 1 ? '' : 's' }}
                                        </Badge>
                                    </div>

                                    <ol v-if="section.items.length" class="mt-3 space-y-2">
                                        <li
                                            v-for="(item, index) in section.items"
                                            :key="`${section.key}-${index}`"
                                            class="flex gap-3 text-sm leading-6"
                                        >
                                            <span
                                                class="app-surface-muted mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-medium"
                                            >
                                                {{ index + 1 }}
                                            </span>
                                            <span>{{ item }}</span>
                                        </li>
                                    </ol>
                                    <p v-else class="app-muted-text mt-3 text-sm">
                                        Nothing defined for this section yet.
                                    </p>
                                </div>
                            </CardContent>
                        </Card>

                        <Card class="app-surface">
                            <CardHeader>
                                <div class="flex items-center gap-2">
                                    <WrenchIcon class="app-muted-text size-4" />
                                    <CardTitle>Runtime tools</CardTitle>
                                </div>
                                <CardDescription>
                                    Tool access is explicit so reviewers can see what the agent may call.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div v-if="tools.length" class="grid gap-3 md:grid-cols-2">
                                    <div
                                        v-for="tool in tools"
                                        :key="tool.id ?? tool.slug"
                                        class="rounded-app-container border p-4"
                                    >
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <p class="font-medium">{{ tool.label }}</p>
                                                <p class="app-muted-text mt-1 text-sm">
                                                    {{ tool.description }}
                                                </p>
                                            </div>
                                            <Badge
                                                variant="outline"
                                                class="rounded-full"
                                                :class="tool.is_enabled ? 'border-emerald-500/30 text-emerald-600' : 'text-muted-foreground'"
                                            >
                                                {{ tool.status }}
                                            </Badge>
                                        </div>
                                        <pre
                                            v-if="formatJson(tool.config)"
                                            class="app-surface-muted mt-4 max-h-48 overflow-auto rounded-app-control p-3 text-xs"
                                        >{{ formatJson(tool.config) }}</pre>
                                    </div>
                                </div>
                                <div v-else class="rounded-app-container border border-dashed px-4 py-8 text-center">
                                    <p class="font-medium">No runtime tools configured</p>
                                    <p class="app-muted-text mt-1 text-sm">
                                        This agent can answer directly, but cannot delegate or inspect subagents.
                                    </p>
                                </div>
                            </CardContent>
                        </Card>

                        <Card class="app-surface">
                            <CardHeader>
                                <div class="flex items-center gap-2">
                                    <HistoryIcon class="app-muted-text size-4" />
                                    <CardTitle>Version history</CardTitle>
                                </div>
                                <CardDescription>
                                    Published snapshots make configuration changes auditable.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div v-if="versions.length" class="space-y-3">
                                    <div
                                        v-for="version in versions"
                                        :key="version.id"
                                        class="flex gap-3 rounded-app-container border p-4"
                                    >
                                        <div
                                            class="app-surface-muted flex size-10 shrink-0 items-center justify-center rounded-full"
                                        >
                                            v{{ version.version }}
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <p class="font-medium">Version {{ version.version }}</p>
                                                <Badge
                                                    v-if="version.id === latestVersion?.id"
                                                    variant="outline"
                                                    class="rounded-full border-primary/30 text-primary"
                                                >
                                                    Current
                                                </Badge>
                                            </div>
                                            <p class="app-muted-text mt-1 text-sm">
                                                Published {{ formatDate(version.published_at) }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <div v-else class="rounded-app-container border border-dashed px-4 py-8 text-center">
                                    <p class="font-medium">No versions yet</p>
                                    <p class="app-muted-text mt-1 text-sm">
                                        Create a snapshot to preserve this configuration.
                                    </p>
                                </div>
                            </CardContent>
                        </Card>

                        <Card class="app-surface">
                            <CardHeader>
                                <div class="flex items-center gap-2">
                                    <FileJsonIcon class="app-muted-text size-4" />
                                    <CardTitle>Schema and metadata</CardTitle>
                                </div>
                                <CardDescription>
                                    Runtime contracts and metadata are shown as JSON for review.
                                </CardDescription>
                            </CardHeader>
                            <CardContent class="grid gap-4 lg:grid-cols-3">
                                <div
                                    v-for="block in schemaBlocks"
                                    :key="block.label"
                                    class="min-w-0 rounded-app-container border p-4"
                                >
                                    <p class="font-medium">{{ block.label }}</p>
                                    <pre
                                        v-if="block.formatted"
                                        class="app-surface-muted mt-3 max-h-72 overflow-auto rounded-app-control p-3 text-xs"
                                    >{{ block.formatted }}</pre>
                                    <p v-else class="app-muted-text mt-3 text-sm">
                                        Not configured.
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <aside class="space-y-6">
                        <Card class="app-surface">
                            <CardHeader>
                                <CardTitle>Readiness</CardTitle>
                                <CardDescription>
                                    A quick review checklist before exposing the agent.
                                </CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-4">
                                <div
                                    v-for="item in readinessItems"
                                    :key="item.label"
                                    class="flex gap-3"
                                >
                                    <CheckCircle2Icon
                                        v-if="item.complete"
                                        class="mt-0.5 size-4 shrink-0 text-emerald-600"
                                    />
                                    <XCircleIcon
                                        v-else
                                        class="text-muted-foreground mt-0.5 size-4 shrink-0"
                                    />
                                    <div>
                                        <p class="font-medium">{{ item.label }}</p>
                                        <p class="app-muted-text mt-0.5 text-sm">{{ item.detail }}</p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <Card class="app-surface">
                            <CardHeader>
                                <CardTitle>Provider model</CardTitle>
                                <CardDescription>
                                    Where this agent sends completion requests.
                                </CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-4">
                                <div>
                                    <p class="app-muted-text text-sm">Provider</p>
                                    <p class="mt-1 font-medium">{{ provider?.name ?? 'Unassigned' }}</p>
                                </div>
                                <Separator />
                                <div>
                                    <p class="app-muted-text text-sm">Model name</p>
                                    <p class="mt-1 font-medium">{{ providerModel?.name ?? 'Unassigned' }}</p>
                                </div>
                                <Separator />
                                <div>
                                    <p class="app-muted-text text-sm">Model identifier</p>
                                    <p class="mt-1 break-all font-mono text-sm">
                                        {{ providerModel?.model ?? 'Not configured' }}
                                    </p>
                                </div>
                            </CardContent>
                        </Card>

                        <Card class="app-surface">
                            <CardHeader>
                                <div class="flex items-center gap-2">
                                    <ClockIcon class="app-muted-text size-4" />
                                    <CardTitle>Runtime settings</CardTitle>
                                </div>
                            </CardHeader>
                            <CardContent class="space-y-4">
                                <div
                                    v-for="setting in runtimeSettings"
                                    :key="setting.label"
                                >
                                    <div class="flex items-center justify-between gap-4">
                                        <p class="app-muted-text text-sm">{{ setting.label }}</p>
                                        <p class="font-medium">{{ setting.value }}</p>
                                    </div>
                                    <p class="app-muted-text mt-1 text-xs">{{ setting.hint }}</p>
                                </div>
                            </CardContent>
                        </Card>

                        <Card class="app-surface">
                            <CardHeader>
                                <CardTitle>I/O contract</CardTitle>
                                <CardDescription>
                                    Accepted request and response modes.
                                </CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-5">
                                <div>
                                    <p class="app-muted-text text-sm">Input modes</p>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <Badge
                                            v-for="mode in inputModes"
                                            :key="mode"
                                            variant="outline"
                                            class="rounded-full"
                                        >
                                            {{ mode }}
                                        </Badge>
                                        <p v-if="!inputModes.length" class="app-muted-text text-sm">
                                            Not configured.
                                        </p>
                                    </div>
                                </div>
                                <Separator />
                                <div>
                                    <p class="app-muted-text text-sm">Output modes</p>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <Badge
                                            v-for="mode in outputModes"
                                            :key="mode"
                                            variant="outline"
                                            class="rounded-full"
                                        >
                                            {{ mode }}
                                        </Badge>
                                        <p v-if="!outputModes.length" class="app-muted-text text-sm">
                                            Not configured.
                                        </p>
                                    </div>
                                </div>
                                <Separator />
                                <div>
                                    <p class="app-muted-text text-sm">Allowed subagents</p>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <Badge
                                            v-for="subagent in subagents"
                                            :key="subagent"
                                            variant="outline"
                                            class="rounded-full"
                                        >
                                            {{ subagent }}
                                        </Badge>
                                        <p v-if="!subagents.length" class="app-muted-text text-sm">
                                            No delegation allowlist.
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </aside>
                </div>
            </div>
        </div>
    </AppShell>
</template>
