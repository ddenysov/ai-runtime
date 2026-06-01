import { createRouter, createWebHistory } from 'vue-router';
import AgentChat from '@/pages/AgentChat.vue';
import AgentChatHistory from '@/pages/AgentChatHistory.vue';
import AgentDetails from '@/pages/AgentDetails.vue';
import Agents from '@/pages/Agents.vue';
import Index from '@/pages/Index.vue';
import McpServers from '@/pages/McpServers.vue';

const routes = [
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
];

export const router = createRouter({
    history: createWebHistory(),
    routes,
});
