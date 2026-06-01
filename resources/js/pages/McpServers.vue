<script setup>
import { computed, onMounted, ref } from 'vue';
import { LoaderCircleIcon, PlusIcon, RefreshCwIcon, WrenchIcon } from '@lucide/vue';
import { toast } from 'vue-sonner';
import AppShell from '@/components/app/AppShell.vue';
import PageBreadcrumbs from '@/components/app/PageBreadcrumbs.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import CreateMcpServerDialog from '@/features/mcp/CreateMcpServerDialog.vue';
import {
    listMcpServers,
    listMcpServerTools,
    testMcpServer,
} from '@/lib/api';
import {
    navigation,
    workspaces,
} from '@/features/providers/providers.mock';

const selectedWorkspace = ref('acme-ai');
const createOpen = ref(false);
const loading = ref(false);
const testingUuid = ref('');
const loadingToolsUuid = ref('');
const error = ref('');
const servers = ref([]);

const mcpNavigation = computed(() => navigation.map((item) => ({
    ...item,
    active: item.label === 'MCP Servers',
})));

async function fetchServers() {
    loading.value = true;
    error.value = '';

    try {
        const response = await listMcpServers({
            perPage: 100,
        });

        servers.value = response.data ?? [];
    } catch (fetchError) {
        error.value = fetchError.message;
    } finally {
        loading.value = false;
    }
}

async function runTest(server) {
    testingUuid.value = server.uuid;

    try {
        const response = await testMcpServer(server.uuid);
        toast.success('MCP server test passed', {
            description: `${server.name}: ${response.data.message}`,
        });
        await fetchServers();
    } catch (testError) {
        toast.error('MCP server test failed', {
            description: testError.data?.message ?? testError.message,
        });
        await fetchServers();
    } finally {
        testingUuid.value = '';
    }
}

async function loadTools(server) {
    loadingToolsUuid.value = server.uuid;

    try {
        const response = await listMcpServerTools(server.uuid);
        toast.success('MCP tools discovered', {
            description: `${server.name}: ${(response.data ?? []).length} tools available.`,
        });
    } catch (toolsError) {
        toast.error('Could not list MCP tools', {
            description: toolsError.data?.message ?? toolsError.message,
        });
    } finally {
        loadingToolsUuid.value = '';
    }
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

onMounted(fetchServers);
</script>

<template>
    <AppShell
        v-model:workspace="selectedWorkspace"
        :workspaces="workspaces"
        :navigation="mcpNavigation"
    >
        <PageHeader title="MCP Servers">
            <template #breadcrumbs>
                <PageBreadcrumbs :items="['Workspaces', 'MCP Servers']" />
            </template>

            <template #actions>
                <Button
                    variant="outline"
                    class="app-soft-control"
                    :disabled="loading"
                    @click="fetchServers"
                >
                    <RefreshCwIcon class="size-4" />
                    Refresh
                </Button>
                <Button class="rounded-app-control" @click="createOpen = true">
                    <PlusIcon class="size-4" />
                    New server
                </Button>
            </template>
        </PageHeader>

        <div class="px-5 py-7 md:px-8 md:py-8">
            <div v-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground">
                <LoaderCircleIcon class="size-4 animate-spin" />
                Loading MCP servers...
            </div>

            <p v-else-if="error" class="text-sm text-destructive">
                {{ error }}
            </p>

            <div v-else-if="!servers.length" class="rounded-xl border border-dashed p-8 text-center">
                <WrenchIcon class="mx-auto size-8 text-muted-foreground" />
                <h2 class="mt-3 text-lg font-semibold">
                    No MCP servers yet
                </h2>
                <p class="app-muted-text mt-1">
                    Add a stdio server to discover tools and attach them to runtime agents.
                </p>
            </div>

            <div v-else class="grid gap-4 lg:grid-cols-2">
                <Card v-for="server in servers" :key="server.uuid">
                    <CardHeader>
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <CardTitle>{{ server.name }}</CardTitle>
                                <CardDescription class="mt-1">
                                    {{ server.command }} {{ (server.args ?? []).join(' ') }}
                                </CardDescription>
                            </div>
                            <Badge :variant="server.enabled ? 'default' : 'secondary'">
                                {{ server.enabled ? 'Enabled' : 'Disabled' }}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="grid gap-2 text-sm text-muted-foreground sm:grid-cols-2">
                            <div>Transport: {{ server.transport }}</div>
                            <div>Version: {{ server.version }}</div>
                            <div>Env keys: {{ (server.env_keys ?? []).join(', ') || 'none' }}</div>
                            <div>Last test: {{ server.last_test?.status ?? 'never' }}</div>
                            <div class="sm:col-span-2">
                                Updated: {{ formatDate(server.updated_at) }}
                            </div>
                        </div>

                        <p
                            v-if="server.last_test?.message"
                            class="rounded-md bg-muted px-3 py-2 text-sm text-muted-foreground"
                        >
                            {{ server.last_test.message }}
                        </p>

                        <div class="flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                class="app-soft-control"
                                :disabled="testingUuid === server.uuid"
                                @click="runTest(server)"
                            >
                                <LoaderCircleIcon
                                    v-if="testingUuid === server.uuid"
                                    class="size-4 animate-spin"
                                />
                                Test
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                class="app-soft-control"
                                :disabled="loadingToolsUuid === server.uuid"
                                @click="loadTools(server)"
                            >
                                <LoaderCircleIcon
                                    v-if="loadingToolsUuid === server.uuid"
                                    class="size-4 animate-spin"
                                />
                                List tools
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>

        <CreateMcpServerDialog
            v-model:open="createOpen"
            @created="fetchServers"
        />
    </AppShell>
</template>
