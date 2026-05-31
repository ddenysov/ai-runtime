import { createRouter, createWebHistory } from 'vue-router';
import AgentDetails from '@/pages/AgentDetails.vue';
import Agents from '@/pages/Agents.vue';
import Index from '@/pages/Index.vue';

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
];

export const router = createRouter({
    history: createWebHistory(),
    routes,
});
