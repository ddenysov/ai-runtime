import { createRouter, createWebHistory } from 'vue-router';
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
];

export const router = createRouter({
    history: createWebHistory(),
    routes,
});
