import { createRouter, createWebHistory } from 'vue-router';
import Index from '@/pages/Index.vue';

const routes = [
    {
        path: '/',
        name: 'index',
        component: Index,
    },
];

export const router = createRouter({
    history: createWebHistory(),
    routes,
});
