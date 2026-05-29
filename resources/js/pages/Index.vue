<script setup>
import { computed, ref } from 'vue';
import {
    ActivityIcon,
    BellIcon,
    BotIcon,
    Building2Icon,
    ChevronDownIcon,
    CircleCheckIcon,
    DatabaseIcon,
    LayoutDashboardIcon,
    LayoutGridIcon,
    ListIcon,
    MoreHorizontalIcon,
    PlusIcon,
    SearchIcon,
    ServerIcon,
    SettingsIcon,
    ShieldCheckIcon,
    TriangleAlertIcon,
    UsersIcon,
    WorkflowIcon,
} from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

const viewMode = ref('list');
const selectedWorkspace = ref('acme-ai');
const searchQuery = ref('');

const workspaces = [
    { value: 'acme-ai', label: 'Acme AI Platform', plan: 'Enterprise' },
    { value: 'orbit-labs', label: 'Orbit Labs', plan: 'Scale' },
    { value: 'sandbox', label: 'Sandbox Workspace', plan: 'Developer' },
];

const navigation = [
    { label: 'Overview', icon: LayoutDashboardIcon, active: false },
    { label: 'Providers', icon: ServerIcon, active: true },
    { label: 'Agents', icon: BotIcon, active: false },
    { label: 'MCP Workflows', icon: WorkflowIcon, active: false },
    { label: 'Knowledge Bases', icon: DatabaseIcon, active: false },
    { label: 'Tenants & Users', icon: UsersIcon, active: false },
    { label: 'Security', icon: ShieldCheckIcon, active: false },
    { label: 'Settings', icon: SettingsIcon, active: false },
];

const metrics = [
    { label: 'Active providers', value: '12', change: '+3 this week', tone: 'text-emerald-600' },
    { label: 'Agent uptime', value: '99.94%', change: 'SLA healthy', tone: 'text-emerald-600' },
    { label: 'MCP runs today', value: '8.4k', change: '+18% vs yesterday', tone: 'text-sky-600' },
    { label: 'Open incidents', value: '2', change: 'Needs review', tone: 'text-amber-600' },
];

const infrastructureItems = [
    {
        name: 'OpenAI Production',
        type: 'LLM Provider',
        status: 'Healthy',
        owner: 'Platform Team',
        region: 'EU West',
        agents: 18,
        requests: '2.4M',
        cost: '$12.8k',
        updated: '4 min ago',
    },
    {
        name: 'Claude Support Cluster',
        type: 'Agent Runtime',
        status: 'Healthy',
        owner: 'CX Automation',
        region: 'US East',
        agents: 9,
        requests: '812k',
        cost: '$5.1k',
        updated: '11 min ago',
    },
    {
        name: 'MCP Data Warehouse Sync',
        type: 'MCP Workflow',
        status: 'Warning',
        owner: 'Data Ops',
        region: 'Global',
        agents: 4,
        requests: '148k',
        cost: '$940',
        updated: '22 min ago',
    },
    {
        name: 'Vector Search Gateway',
        type: 'Knowledge Infra',
        status: 'Healthy',
        owner: 'AI Enablement',
        region: 'EU Central',
        agents: 7,
        requests: '510k',
        cost: '$2.2k',
        updated: '35 min ago',
    },
    {
        name: 'Legacy Prompt Router',
        type: 'Routing Service',
        status: 'Paused',
        owner: 'Core AI',
        region: 'US West',
        agents: 2,
        requests: '42k',
        cost: '$180',
        updated: '1 hour ago',
    },
];

const filteredItems = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();

    if (!query) {
        return infrastructureItems;
    }

    return infrastructureItems.filter((item) =>
        [item.name, item.type, item.status, item.owner, item.region].some((value) =>
            value.toLowerCase().includes(query),
        ),
    );
});

const currentWorkspace = computed(() =>
    workspaces.find((workspace) => workspace.value === selectedWorkspace.value) ?? workspaces[0],
);

const statusClasses = {
    Healthy: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    Warning: 'border-amber-200 bg-amber-50 text-amber-700',
    Paused: 'border-slate-200 bg-slate-100 text-slate-600',
};

const statusIcons = {
    Healthy: CircleCheckIcon,
    Warning: TriangleAlertIcon,
    Paused: ActivityIcon,
};
</script>

<template>
    <main class="min-h-screen bg-slate-50 text-slate-950">
        <div class="grid min-h-screen lg:grid-cols-[280px_1fr]">
            <aside class="hidden border-r border-slate-200 bg-white/90 px-4 py-5 lg:block">
                <div class="mb-8 flex items-center gap-3 px-2">
                    <div class="flex size-10 items-center justify-center rounded-2xl bg-slate-950 text-white shadow-sm">
                        <BotIcon class="size-5" />
                    </div>
                    <div>
                        <p class="text-sm font-semibold">InfraPilot</p>
                        <p class="text-xs text-slate-500">AI infrastructure admin</p>
                    </div>
                </div>

                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button
                            class="mb-6 flex h-14 w-full cursor-pointer items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-left transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                            type="button"
                        >
                            <span class="flex min-w-0 flex-1 items-center gap-3">
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-white/80 text-slate-700 ring-1 ring-slate-200">
                                    <Building2Icon class="size-4" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold leading-5 text-slate-950">
                                        {{ currentWorkspace.label }}
                                    </span>
                                    <span class="block truncate text-xs leading-4 text-slate-500">
                                        {{ currentWorkspace.plan }} plan · Workspace context
                                    </span>
                                </span>
                            </span>
                            <span class="flex shrink-0 items-center gap-2">
                                <span class="hidden rounded-lg bg-white px-2 py-1 text-xs font-medium text-slate-500 ring-1 ring-slate-200 xl:inline-flex">
                                    Switch
                                </span>
                                <ChevronDownIcon class="size-4 text-slate-500" />
                            </span>
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent class="w-64">
                        <DropdownMenuItem
                            v-for="workspace in workspaces"
                            :key="workspace.value"
                            class="cursor-pointer p-2"
                            @click="selectedWorkspace = workspace.value"
                        >
                            <div class="flex min-w-0 flex-1 items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate font-medium">{{ workspace.label }}</p>
                                    <p class="text-xs text-slate-500">{{ workspace.plan }} plan</p>
                                </div>
                                <CircleCheckIcon
                                    v-if="workspace.value === selectedWorkspace"
                                    class="size-4 shrink-0 text-emerald-600"
                                />
                            </div>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>

                <nav class="space-y-1">
                    <button
                        v-for="item in navigation"
                        :key="item.label"
                        class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition"
                        :class="item.active
                            ? 'bg-slate-950 text-white shadow-sm'
                            : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950'"
                    >
                        <component :is="item.icon" class="size-4" />
                        {{ item.label }}
                    </button>
                </nav>

                <Card class="mt-8 border-sky-100 bg-sky-50/70 shadow-none">
                    <CardHeader class="pb-2">
                        <CardTitle class="text-sm">Usage guardrails</CardTitle>
                        <CardDescription>Budget, rate limits and model policies are inherited per workspace.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Button variant="outline" size="sm" class="w-full bg-white">
                            Review policies
                        </Button>
                    </CardContent>
                </Card>
            </aside>

            <section class="flex min-w-0 flex-col">
                <header class="sticky top-0 z-10 border-b border-slate-200 bg-white/85 px-4 py-3 backdrop-blur md:px-8">
                    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div>
                            <div class="flex items-center gap-2 text-sm text-slate-500">
                                <span>Workspaces</span>
                                <ChevronDownIcon class="size-3 rotate-[-90deg]" />
                                <span class="font-medium text-slate-700">Providers</span>
                            </div>
                            <h1 class="mt-1 text-2xl font-semibold tracking-tight">AI Infrastructure</h1>
                        </div>

                        <div class="flex items-center gap-2">
                            <Button variant="outline" size="icon" class="rounded-xl bg-white">
                                <BellIcon class="size-4" />
                            </Button>
                            <Button variant="outline" class="rounded-xl bg-white">
                                Export
                            </Button>
                            <Button class="rounded-xl">
                                <PlusIcon class="size-4" />
                                New provider
                            </Button>
                        </div>
                    </div>
                </header>

                <div class="space-y-6 px-4 py-6 md:px-8">
                    <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <Card
                            v-for="metric in metrics"
                            :key="metric.label"
                            class="border-slate-200 bg-white shadow-sm"
                        >
                            <CardHeader class="pb-2">
                                <CardDescription>{{ metric.label }}</CardDescription>
                                <CardTitle class="text-2xl">{{ metric.value }}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p class="text-sm font-medium" :class="metric.tone">{{ metric.change }}</p>
                            </CardContent>
                        </Card>
                    </section>

                    <Card class="overflow-hidden border-slate-200 bg-white shadow-sm">
                        <CardHeader class="border-b border-slate-100">
                            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <CardTitle>Providers registry</CardTitle>
                                        <Badge variant="outline" class="rounded-full">
                                            {{ filteredItems.length }} resources
                                        </Badge>
                                    </div>
                                    <CardDescription class="mt-1 max-w-2xl">
                                        One reusable CRUD pattern for providers, agents, MCP workflows and shared tenant infrastructure.
                                    </CardDescription>
                                </div>

                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                    <div class="relative min-w-64">
                                        <SearchIcon class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                                        <Input
                                            v-model="searchQuery"
                                            class="h-10 rounded-2xl pl-9"
                                            placeholder="Search by name, owner, region..."
                                        />
                                    </div>
                                    <div class="flex h-10 rounded-2xl bg-slate-100 p-1">
                                        <button
                                            class="inline-flex h-8 items-center gap-2 rounded-xl px-3 text-sm font-medium transition"
                                            :class="viewMode === 'list' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-500 hover:text-slate-900'"
                                            @click="viewMode = 'list'"
                                        >
                                            <ListIcon class="size-4" />
                                            Listing
                                        </button>
                                        <button
                                            class="inline-flex h-8 items-center gap-2 rounded-xl px-3 text-sm font-medium transition"
                                            :class="viewMode === 'cards' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-500 hover:text-slate-900'"
                                            @click="viewMode = 'cards'"
                                        >
                                            <LayoutGridIcon class="size-4" />
                                            Cards
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </CardHeader>

                        <CardContent class="p-0">
                            <div v-if="viewMode === 'list'" class="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow class="bg-slate-50/80">
                                            <TableHead>Resource</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Owner</TableHead>
                                            <TableHead>Region</TableHead>
                                            <TableHead class="text-right">Agents</TableHead>
                                            <TableHead class="text-right">Requests</TableHead>
                                            <TableHead class="text-right">Cost</TableHead>
                                            <TableHead class="w-12" />
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow
                                            v-for="item in filteredItems"
                                            :key="item.name"
                                            class="hover:bg-slate-50"
                                        >
                                            <TableCell>
                                                <div>
                                                    <p class="font-medium">{{ item.name }}</p>
                                                    <p class="text-sm text-slate-500">{{ item.type }} · Updated {{ item.updated }}</p>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant="outline"
                                                    class="rounded-full"
                                                    :class="statusClasses[item.status]"
                                                >
                                                    <component :is="statusIcons[item.status]" class="size-3" />
                                                    {{ item.status }}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>{{ item.owner }}</TableCell>
                                            <TableCell>{{ item.region }}</TableCell>
                                            <TableCell class="text-right">{{ item.agents }}</TableCell>
                                            <TableCell class="text-right">{{ item.requests }}</TableCell>
                                            <TableCell class="text-right font-medium">{{ item.cost }}</TableCell>
                                            <TableCell>
                                                <Button variant="ghost" size="icon-sm">
                                                    <MoreHorizontalIcon class="size-4" />
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </div>

                            <div v-else class="grid gap-4 p-4 md:grid-cols-2 xl:grid-cols-3">
                                <Card
                                    v-for="item in filteredItems"
                                    :key="item.name"
                                    class="border-slate-200 shadow-none transition hover:-translate-y-0.5 hover:shadow-md"
                                >
                                    <CardHeader>
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <CardTitle class="text-base">{{ item.name }}</CardTitle>
                                                <CardDescription>{{ item.type }}</CardDescription>
                                            </div>
                                            <Badge
                                                variant="outline"
                                                class="rounded-full"
                                                :class="statusClasses[item.status]"
                                            >
                                                {{ item.status }}
                                            </Badge>
                                        </div>
                                    </CardHeader>
                                    <CardContent class="space-y-4">
                                        <div class="grid grid-cols-3 gap-3 rounded-2xl bg-slate-50 p-3 text-sm">
                                            <div>
                                                <p class="text-slate-500">Agents</p>
                                                <p class="font-semibold">{{ item.agents }}</p>
                                            </div>
                                            <div>
                                                <p class="text-slate-500">Requests</p>
                                                <p class="font-semibold">{{ item.requests }}</p>
                                            </div>
                                            <div>
                                                <p class="text-slate-500">Cost</p>
                                                <p class="font-semibold">{{ item.cost }}</p>
                                            </div>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="text-slate-500">{{ item.owner }} · {{ item.region }}</span>
                                            <Button variant="outline" size="sm">Open</Button>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </section>
        </div>
    </main>
</template>
