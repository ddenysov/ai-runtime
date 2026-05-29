import {
    BotIcon,
    DatabaseIcon,
    LayoutDashboardIcon,
    ServerIcon,
    SettingsIcon,
    ShieldCheckIcon,
    UsersIcon,
    WorkflowIcon,
} from '@lucide/vue';

export const workspaces = [
    { value: 'acme-ai', label: 'Acme AI Platform', plan: 'Enterprise' },
    { value: 'orbit-labs', label: 'Orbit Labs', plan: 'Scale' },
    { value: 'sandbox', label: 'Sandbox Workspace', plan: 'Developer' },
];

export const navigation = [
    { label: 'Overview', icon: LayoutDashboardIcon, active: false },
    { label: 'Providers', icon: ServerIcon, active: true },
    { label: 'Agents', icon: BotIcon, active: false },
    { label: 'MCP Workflows', icon: WorkflowIcon, active: false },
    { label: 'Knowledge Bases', icon: DatabaseIcon, active: false },
    { label: 'Tenants & Users', icon: UsersIcon, active: false },
    { label: 'Security', icon: ShieldCheckIcon, active: false },
    { label: 'Settings', icon: SettingsIcon, active: false },
];

export const metrics = [
    { label: 'Active providers', value: '12', change: '+3 this week', tone: 'text-emerald-600' },
    { label: 'Agent uptime', value: '99.94%', change: 'SLA healthy', tone: 'text-emerald-600' },
    { label: 'MCP runs today', value: '8.4k', change: '+18% vs yesterday', tone: 'text-sky-600' },
    { label: 'Open incidents', value: '2', change: 'Needs review', tone: 'text-amber-600' },
];

export const providerItems = [
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

export const providerColumns = [
    { key: 'name', label: 'Resource' },
    { key: 'status', label: 'Status' },
    { key: 'owner', label: 'Owner' },
    { key: 'region', label: 'Region' },
    { key: 'agents', label: 'Agents', align: 'right' },
    { key: 'requests', label: 'Requests', align: 'right' },
    { key: 'cost', label: 'Cost', align: 'right' },
];
