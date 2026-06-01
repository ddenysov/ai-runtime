<script setup>
import { computed, ref } from 'vue';
import { CheckIcon, LoaderCircleIcon, PlusIcon, WrenchIcon, XIcon } from '@lucide/vue';
import { toast } from 'vue-sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import CreateMcpServerDialog from '@/features/mcp/CreateMcpServerDialog.vue';
import {
    buildAgentToolsDraft,
    buildMcpToolConfig,
    buildMcpToolSlug,
    runtimeTools,
    toolDisplayMeta,
} from '@/features/agents/agent-tools';
import {
    listMcpServers,
    listMcpServerTools,
    updateAgent,
} from '@/lib/api';

const props = defineProps({
    agentId: {
        type: [Number, String],
        required: true,
    },
    tools: {
        type: Array,
        default: () => [],
    },
});

const editing = defineModel('editing', {
    type: Boolean,
    default: false,
});

const emit = defineEmits(['saved']);
const saving = ref(false);
const saveError = ref('');
const draftTools = ref([]);
const discoveredDefinitions = ref([]);
const mcpServers = ref([]);
const loadingServers = ref(false);
const serversError = ref('');
const selectedServerUuid = ref('');
const loadingServerTools = ref(false);
const serverToolsError = ref('');
const createMcpOpen = ref(false);

const groupedTools = computed(() => {
    const groups = new Map();

    for (const tool of draftTools.value) {
        const meta = toolDisplayMeta(tool, discoveredDefinitions.value);
        const groupName = meta.group;

        if (!groups.has(groupName)) {
            groups.set(groupName, []);
        }

        groups.get(groupName).push({
            tool,
            label: meta.label,
            description: meta.description,
        });
    }

    return [...groups.entries()].map(([name, items]) => ({
        name,
        items,
    }));
});

const enabledCount = computed(() => draftTools.value.filter((tool) => tool.is_enabled).length);

function findDraftTool(slug) {
    return draftTools.value.find((tool) => tool.slug === slug);
}

function upsertDraftTool(entry) {
    const existing = findDraftTool(entry.slug);

    if (existing) {
        Object.assign(existing, entry);
        return;
    }

    draftTools.value.push(entry);
}

async function fetchMcpServers() {
    loadingServers.value = true;
    serversError.value = '';

    try {
        const response = await listMcpServers({
            enabled: true,
            perPage: 100,
        });

        mcpServers.value = response.data ?? [];
    } catch (error) {
        serversError.value = error.message;
    } finally {
        loadingServers.value = false;
    }
}

function startEditing() {
    draftTools.value = buildAgentToolsDraft(props.tools);
    discoveredDefinitions.value = (props.tools ?? [])
        .filter((tool) => String(tool.slug).startsWith('mcp:'))
        .map((tool) => ({
            slug: tool.slug,
            ...toolDisplayMeta(tool),
        }));
    saveError.value = '';
    selectedServerUuid.value = '';
    serverToolsError.value = '';
    editing.value = true;
    fetchMcpServers();
}

function cancelEditing() {
    editing.value = false;
    saveError.value = '';
    selectedServerUuid.value = '';
    serverToolsError.value = '';
}

async function discoverToolsForServer(serverUuid = selectedServerUuid.value) {
    if (!serverUuid) {
        serverToolsError.value = 'Select an MCP server first.';
        return;
    }

    const server = mcpServers.value.find((entry) => entry.uuid === serverUuid);
    if (!server) {
        serverToolsError.value = 'The selected MCP server is no longer available.';
        return;
    }

    loadingServerTools.value = true;
    serverToolsError.value = '';

    try {
        const response = await listMcpServerTools(serverUuid);
        const tools = response.data ?? [];

        for (const tool of tools) {
            const slug = buildMcpToolSlug(server.uuid, tool.name);
            const config = buildMcpToolConfig(server, tool);
            const definition = {
                slug,
                label: `${server.name}: ${tool.title || tool.name}`,
                description: tool.description || `Call ${tool.name} through ${server.name}.`,
                group: server.name,
            };

            discoveredDefinitions.value = [
                ...discoveredDefinitions.value.filter((item) => item.slug !== slug),
                definition,
            ];

            upsertDraftTool({
                slug,
                is_enabled: findDraftTool(slug)?.is_enabled ?? false,
                config,
            });
        }

        if (!tools.length) {
            toast.message('No tools found', {
                description: `${server.name} did not expose any MCP tools.`,
            });
            return;
        }

        toast.success('MCP tools loaded', {
            description: `${tools.length} tool${tools.length === 1 ? '' : 's'} from ${server.name}.`,
        });
    } catch (error) {
        serverToolsError.value = error.message;
    } finally {
        loadingServerTools.value = false;
    }
}

function onMcpServerCreated(server) {
    mcpServers.value = [
        server,
        ...mcpServers.value.filter((entry) => entry.uuid !== server.uuid),
    ];
    selectedServerUuid.value = server.uuid;
    discoverToolsForServer(server.uuid);
}

async function saveTools() {
    saving.value = true;
    saveError.value = '';

    try {
        const agent = await updateAgent(props.agentId, {
            tools: draftTools.value.map((tool) => ({
                slug: tool.slug,
                is_enabled: Boolean(tool.is_enabled),
                config: tool.config ?? null,
            })),
        });

        emit('saved', agent);
        editing.value = false;
        toast.success('Runtime tools updated');
    } catch (error) {
        const validationMessage = error.data?.errors?.tools?.[0]
            ?? Object.values(error.data?.errors ?? {})[0]?.[0];

        saveError.value = validationMessage ?? error.message;
    } finally {
        saving.value = false;
    }
}

</script>

<template>
    <div>
        <div v-if="!editing" class="flex justify-end">
            <Button
                variant="outline"
                size="sm"
                class="app-soft-control"
                @click="startEditing"
            >
                <WrenchIcon class="size-4" />
                Manage tools
            </Button>
        </div>

        <div v-else class="space-y-5">
            <div class="rounded-app-container border p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="font-medium">Add MCP server tools</p>
                        <p class="app-muted-text mt-1 text-sm">
                            Pick an existing server or create one, then load its tools into this agent.
                        </p>
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        class="app-soft-control"
                        @click="createMcpOpen = true"
                    >
                        <PlusIcon class="size-4" />
                        New MCP server
                    </Button>
                </div>

                <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div class="min-w-0 flex-1">
                        <p class="app-muted-text mb-2 text-sm">MCP server</p>
                        <Select
                            v-model="selectedServerUuid"
                            :disabled="loadingServers || saving"
                        >
                            <SelectTrigger class="w-full">
                                <SelectValue
                                    :placeholder="loadingServers ? 'Loading servers...' : 'Select MCP server'"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="server in mcpServers"
                                    :key="server.uuid"
                                    :value="server.uuid"
                                >
                                    {{ server.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p v-if="serversError" class="mt-2 text-sm text-destructive">
                            {{ serversError }}
                        </p>
                    </div>
                    <Button
                        type="button"
                        class="shrink-0"
                        :disabled="loadingServerTools || saving || !selectedServerUuid"
                        @click="discoverToolsForServer()"
                    >
                        <LoaderCircleIcon
                            v-if="loadingServerTools"
                            class="size-4 animate-spin"
                        />
                        Load tools
                    </Button>
                </div>
                <p v-if="serverToolsError" class="mt-2 text-sm text-destructive">
                    {{ serverToolsError }}
                </p>
            </div>

            <div class="space-y-4">
                <div
                    v-for="group in groupedTools"
                    :key="group.name"
                    class="space-y-3"
                >
                    <div class="flex items-center gap-2">
                        <p class="text-sm font-medium">{{ group.name }}</p>
                        <Badge variant="outline" class="rounded-full">
                            {{ group.items.length }}
                        </Badge>
                    </div>
                    <div class="grid gap-3 md:grid-cols-2">
                        <div
                            v-for="item in group.items"
                            :key="item.tool.slug"
                            class="rounded-app-container border p-4"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-medium">{{ item.label }}</p>
                                    <p class="app-muted-text mt-1 text-sm">
                                        {{ item.description }}
                                    </p>
                                </div>
                                <Switch
                                    v-model="item.tool.is_enabled"
                                    :disabled="saving"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <p v-if="saveError" class="text-sm text-destructive">
                {{ saveError }}
            </p>

            <div class="flex flex-wrap items-center gap-2">
                <Button
                    size="sm"
                    :disabled="saving"
                    @click="saveTools"
                >
                    <LoaderCircleIcon
                        v-if="saving"
                        class="size-4 animate-spin"
                    />
                    <CheckIcon v-else class="size-4" />
                    Save tools
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    class="app-soft-control"
                    :disabled="saving"
                    @click="cancelEditing"
                >
                    <XIcon class="size-4" />
                    Cancel
                </Button>
                <p class="app-muted-text text-sm">
                    {{ enabledCount }} of {{ draftTools.length }} enabled
                </p>
            </div>
        </div>

        <CreateMcpServerDialog
            v-model:open="createMcpOpen"
            @created="onMcpServerCreated"
        />
    </div>
</template>
