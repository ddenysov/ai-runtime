import { createRouter, createWebHistory } from 'vue-router';
import AgentChat from '@/pages/AgentChat.vue';
import AgentChatHistory from '@/pages/AgentChatHistory.vue';
import AgentDetails from '@/pages/AgentDetails.vue';
import Agents from '@/pages/Agents.vue';
import Index from '@/pages/Index.vue';
import Login from '@/pages/Login.vue';
import McpServers from '@/pages/McpServers.vue';
import Settings from '@/pages/Settings.vue';
import StateProcessors from '@/pages/StateProcessors.vue';
import { loadCurrentUser } from '@/lib/auth';

const routes = [
    {
        path: '/login',
        name: 'login',
        component: Login,
        meta: { guestOnly: true },
    },
    {
        path: '/',
        name: 'index',
        component: Index,
    },
    {
        path: '/agents',
        name: 'agents',
        component: Agents,
    },
    {
        path: '/agents/:agentId',
        name: 'agent-details',
        component: AgentDetails,
        props: true,
    },
    {
        path: '/agents/:agentId/chats',
        name: 'agent-chat-history',
        component: AgentChatHistory,
        props: true,
    },
    {
        path: '/agents/:agentId/chat/:contextId?',
        name: 'agent-chat',
        component: AgentChat,
        props: true,
    },
    {
        path: '/mcp-servers',
        name: 'mcp-servers',
        component: McpServers,
    },
    {
        path: '/state-processors',
        name: 'state-processors',
        component: StateProcessors,
    },
    {
        path: '/settings',
        name: 'settings',
        component: Settings,
    },
];

export const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to) => {
    const user = await loadCurrentUser();

    if (to.meta.guestOnly) {
        return user ? { name: 'index' } : true;
    }

    if (!user) {
        return {
            name: 'login',
            query: { redirect: to.fullPath },
        };
    }

    return true;
});
